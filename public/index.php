<?php
/**
 * Signum Unidash — front controller.
 * One dashboard over the trip master list, its supplier bills and billing status.
 * Import LEON exports, create draft Xero POs, and (soon) reconcile bills + invoice.
 * Email + password gate everything (OAuth + PO creation must not be public).
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;
use App\Repo\TripRepo;
use App\Repo\BillRepo;
use App\Service\Leon\LeonProcessor;
use App\Service\Bills\BillReconciler;
use App\Service\Invoices\InvoiceService;
use App\Service\Invoices\CompletenessChecker;
use App\Service\Xero\XeroOAuth;

session_start();

$base = rtrim((string)parse_url((string)cfg('app.base_url', ''), PHP_URL_PATH), '/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
$path = '/' . ltrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function redirect(string $to): void { header('Location: ' . base() . $to); exit; }
function base(): string { return rtrim((string)parse_url((string)cfg('app.base_url',''), PHP_URL_PATH), '/'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Bad CSRF token.'); } }
function is_authed(): bool { return !empty($_SESSION['authed']); }

/** Admin identity: DB (app_settings) wins, else config.php. */
function admin_email(): string { return strtolower(trim((string)Settings::get('auth.email', cfg('app.admin_email', '')))); }
function admin_hash(): string  { return (string)Settings::get('auth.password_hash', cfg('app.admin_password_hash', '')); }
function admin_configured(): bool { return admin_email() !== '' && admin_hash() !== ''; }
/** One-click admin sign-in on the login page. Bypasses the password — see /login/quick. */
function quick_login_enabled(): bool { return (string)Settings::get('auth.quick_login', '0') === '1'; }
/** Short display name for the quick-login button, e.g. "Simon" from simon@fusioneta.com. */
function admin_label(): string {
    $n = (string)Settings::get('auth.display_name', '');
    if ($n !== '') return $n;
    $local = explode('@', admin_email())[0] ?? '';
    return $local === '' ? 'Admin' : ucfirst(preg_replace('/[._-].*$/', '', $local));
}
function admin_check(string $email, string $pass): bool {
    if (!admin_configured()) return false;
    return hash_equals(admin_email(), strtolower(trim($email))) && password_verify($pass, admin_hash());
}

/** Flatten PHP's $_FILES structure (single or multiple) into a list of file rows. */
function normalize_files($f): array {
    if (!$f || !isset($f['name'])) return [];
    if (is_array($f['name'])) {
        $out = [];
        foreach ($f['name'] as $i => $n) {
            if ((string)$n === '') continue;
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
        }
        return $out;
    }
    return (string)$f['name'] === '' ? [] : [$f];
}

// --- auth -----------------------------------------------------------
if ($path === '/login' && $method === 'POST') {
    if (admin_check($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        $_SESSION['authed'] = true; $_SESSION['email'] = admin_email(); redirect('/');
    }
    $_SESSION['flash_err'] = 'Wrong email or password.'; redirect('/login');
}
// One-click sign-in for the admin, for convenience on a trusted machine.
// NOTE: while this is on, ANYONE who loads /login can click through and get in —
// it is a deliberate bypass of the password. Turn it off with:
//   php cli/quick-login.php off
if ($path === '/login/quick' && $method === 'POST') {
    csrf_check();
    if (quick_login_enabled() && admin_configured()) {
        $_SESSION['authed'] = true; $_SESSION['email'] = admin_email(); redirect('/');
    }
    $_SESSION['flash_err'] = 'Quick sign-in is disabled.'; redirect('/login');
}
if ($path === '/logout') { session_destroy(); redirect('/login'); }
if ($path === '/login') { render_login(); exit; }
if (!is_authed()) redirect('/login');

// --- settings -------------------------------------------------------
if ($path === '/settings' && $method === 'POST') {
    csrf_check();
    if (trim($_POST['client_id'] ?? '') !== '')     Settings::set('xero.client_id', trim($_POST['client_id']));
    if (trim($_POST['client_secret'] ?? '') !== '') Settings::set('xero.client_secret', trim($_POST['client_secret']));
    Settings::set('xero.redirect_uri', trim($_POST['redirect_uri'] ?? ''));
    Settings::set('xero.scopes', trim($_POST['scopes'] ?? '') ?: XeroOAuth::DEFAULT_SCOPES);
    Settings::set('xero.enabled', isset($_POST['enabled']) ? '1' : '0');
    Settings::set('currency.inc', strtoupper(trim($_POST['currency_inc'] ?? '')));
    Settings::set('currency.ltd', strtoupper(trim($_POST['currency_ltd'] ?? '')));
    if (isset($_POST['inv_markup']))    Settings::set('invoice.markup', (string)(float)$_POST['inv_markup']);
    if (isset($_POST['inv_admin']))     Settings::set('invoice.admin_pct', (string)(float)$_POST['inv_admin']);
    if (isset($_POST['inv_support']))   Settings::set('invoice.support_fee', (string)(float)$_POST['inv_support']);
    Settings::set('invoice.account_code', trim($_POST['inv_account'] ?? ''));
    $_SESSION['flash_ok'] = 'Settings saved.'; redirect('/?view=settings');
}

// --- Xero OAuth -----------------------------------------------------
if ($path === '/xero/connect') {
    if (!XeroOAuth::isConfigured()) { $_SESSION['flash_err'] = 'Enter Client ID and Secret first, then Save.'; redirect('/?view=settings'); }
    if ($why = XeroOAuth::authorizeProblem()) { $_SESSION['flash_err'] = $why; redirect('/?view=settings'); }
    $state = bin2hex(random_bytes(16)); $_SESSION['xero_state'] = $state;
    header('Location: ' . XeroOAuth::authorizeUrl($state)); exit;
}
if ($path === '/xero/callback') {
    if (!empty($_GET['error'])) { $_SESSION['flash_err'] = 'Xero: ' . $_GET['error']; redirect('/?view=settings'); }
    if (!hash_equals($_SESSION['xero_state'] ?? '', (string)($_GET['state'] ?? ''))) { $_SESSION['flash_err'] = 'Security check failed (state mismatch). Try again.'; redirect('/?view=settings'); }
    unset($_SESSION['xero_state']);
    try {
        $info = XeroOAuth::completeConnection((string)($_GET['code'] ?? ''));
        Settings::set('xero.enabled', '1');
        $_SESSION['flash_ok'] = 'Connected to ' . ($info['tenant_name'] ?: 'Xero') . '. POs will create in this org.';
    } catch (\Throwable $e) { $_SESSION['flash_err'] = $e->getMessage(); }
    redirect('/?view=settings');
}
if ($path === '/xero/disconnect' && $method === 'POST') {
    csrf_check(); XeroOAuth::disconnect(); $_SESSION['flash_ok'] = 'Disconnected from Xero.'; redirect('/?view=settings');
}

// --- import one or more LEON exports into the master list -----------
if ($path === '/import' && $method === 'POST') {
    csrf_check();
    $entitySel = strtolower($_POST['entity'] ?? 'auto');
    $files = normalize_files($_FILES['leon'] ?? null);
    if (!$files) { $_SESSION['flash_err'] = 'Choose one or more LEON files (CSV, XLSX or PDF).'; redirect('/?view=trips'); }

    $dir = STORAGE_ROOT . '/uploads'; @mkdir($dir, 0770, true);
    $tot = ['files' => 0, 'parsed' => 0, 'new' => 0, 'updated' => 0];
    $errs = [];
    foreach ($files as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) continue;
        $name = basename((string)$f['name']);
        $entity = in_array($entitySel, ['inc', 'ltd'], true)
            ? $entitySel
            : (stripos($name, 'ltd') !== false ? 'ltd' : 'inc');
        $dest = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        move_uploaded_file($f['tmp_name'], $dest);
        try {
            $imp = LeonProcessor::import($dest, $entity)['summary'];
            $tot['files']++; $tot['parsed'] += $imp['parsed']; $tot['new'] += $imp['new']; $tot['updated'] += $imp['updated'];
        } catch (\Throwable $e) {
            $errs[] = $name . ': ' . $e->getMessage();
        }
    }
    if ($tot['files']) {
        $_SESSION['flash_ok'] = "Imported {$tot['files']} file(s) · {$tot['parsed']} trips ({$tot['new']} new, {$tot['updated']} updated) into the master list.";
    }
    if ($errs)                       $_SESSION['flash_err'] = implode(' · ', $errs);
    if (!$tot['files'] && !$errs)    $_SESSION['flash_err'] = 'No valid files were uploaded.';
    redirect('/?view=trips');
}

// --- delete trips from the master list ------------------------------
if ($path === '/trips/delete' && $method === 'POST') {
    csrf_check();
    if (!empty($_POST['all'])) {
        $n = TripRepo::deleteAll();
        $_SESSION['flash_ok'] = "Cleared the master list — {$n} trip(s) removed.";
        redirect('/?view=trips');
    }
    $ids = array_map('intval', (array)($_POST['trip_ids'] ?? []));
    if (!$ids) { $_SESSION['flash_err'] = 'Tick at least one trip to delete.'; redirect('/?view=trips'); }
    $n = TripRepo::deleteIds($ids);
    $_SESSION['flash_ok'] = $n === 1 ? '1 trip removed from the master list.' : "{$n} trips removed from the master list.";
    redirect('/?view=trips');
}

// --- Module 3: reconcile supplier bills against trips ---------------
if ($path === '/bills/refresh' && $method === 'POST') {
    csrf_check();
    $res = BillReconciler::refresh();
    if (!empty($res['ok'])) {
        $s = $res['summary'];
        $_SESSION['flash_ok'] = "Pulled {$s['pulled']} draft bill(s): {$s['matched']} matched, {$s['ambiguous']} ambiguous, {$s['review']} need review.";
    } else {
        $_SESSION['flash_err'] = 'Refresh failed: ' . ($res['error'] ?? 'unknown');
    }
    redirect('/?view=bills');
}
if ($path === '/bills/tag' && $method === 'POST') {
    csrf_check();
    $res = BillReconciler::tag((int)($_POST['id'] ?? 0));
    $_SESSION[!empty($res['ok']) ? 'flash_ok' : 'flash_err'] = !empty($res['ok'])
        ? 'Bill tagged with its trip number in Xero.'
        : 'Tag failed: ' . ($res['error'] ?? 'unknown');
    redirect('/?view=bills');
}
if ($path === '/bills/assign' && $method === 'POST') {
    csrf_check();
    $res = BillReconciler::assign((int)($_POST['id'] ?? 0), (int)($_POST['trip_id'] ?? 0));
    $_SESSION[!empty($res['ok']) ? 'flash_ok' : 'flash_err'] = !empty($res['ok'])
        ? 'Bill matched to the trip — you can tag it now.'
        : 'Assign failed: ' . ($res['error'] ?? 'unknown');
    redirect('/?view=bills');
}
if ($path === '/bills/approve' && $method === 'POST') {
    csrf_check();
    $res = BillReconciler::approve((int)($_POST['id'] ?? 0));
    if (empty($res['ok'])) {
        $_SESSION['flash_err'] = 'Approve failed: ' . ($res['error'] ?? 'unknown');
    } elseif (!empty($res['invoiced'])) {
        $_SESSION['flash_ok'] = 'Bill approved — trip fully approved, draft client invoice ' . ($res['invoice_number'] ?: '') . ' created in Xero.';
    } else {
        $_SESSION['flash_ok'] = 'Bill approved in Xero' . (!empty($res['reason']) ? ' — ' . $res['reason'] . '.' : '.');
    }
    redirect('/?view=bills');
}
if ($path === '/bills/tag-all' && $method === 'POST') {
    csrf_check();
    $r = BillReconciler::tagAllMatched();
    $_SESSION['flash_ok'] = "Tagged {$r['tagged']} bill(s)" . ($r['failed'] ? ", {$r['failed']} failed" : '') . '.';
    redirect('/?view=bills');
}

// --- Module 5: raise a client sales invoice for a trip --------------
if ($path === '/invoices/create' && $method === 'POST') {
    csrf_check();
    $res = InvoiceService::createForTrip((int)($_POST['trip_id'] ?? 0));
    $_SESSION[!empty($res['ok']) ? 'flash_ok' : 'flash_err'] = !empty($res['ok'])
        ? 'Draft client invoice ' . ($res['invoice_number'] ?: '') . ' created in Xero.'
        : 'Invoice failed: ' . ($res['error'] ?? 'unknown');
    redirect('/?view=invoices');
}

$view = in_array($_GET['view'] ?? '', ['dashboard', 'trips', 'bills', 'invoices', 'settings'], true) ? $_GET['view'] : 'dashboard';
render_home($view);

// ====================================================================
//  Views
// ====================================================================
function logo(): string {
    return '<svg class="logo" viewBox="0 0 28 28" width="24" height="24" aria-hidden="true">'
        . '<rect x="1" y="1" width="26" height="26" rx="7" fill="#2563eb"/>'
        . '<path d="M6 19.5 L22 8 L15.5 22 L13.5 15.5 Z" fill="#fff"/>'
        . '<circle cx="13.5" cy="15.5" r="1.4" fill="#2563eb"/></svg>';
}

function icon(string $n): string {
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'trips'     => '<path d="M21 15.5 12 20l-9-4.5"/><path d="M21 9.5 12 4 3 9.5l9 4.5 9-4.5z"/>',
        'bills'     => '<path d="M6 2h9l3 3v17l-3-2-3 2-3-2-3 2V2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'invoice'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H1a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 2.6 7a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H7a1.6 1.6 0 0 0 1-1.5V1a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V7a1.6 1.6 0 0 0 1.5 1H23a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
    ][$n] ?? '';
    return '<svg class="nicon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

function render_login(): void {
    $err = $_SESSION['flash_err'] ?? ''; unset($_SESSION['flash_err']);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Signum Unidash</title><?php styles(); ?></head>
    <body class="login"><div class="loginbox">
      <div class="brand"><?= logo() ?><span>Signum Unidash</span></div>
      <p class="sub">Trips · supplier bills · billing status, in one place</p>
      <?php if (!admin_configured()): ?><div class="alert warn">No admin user set yet. On the server run <code>php cli/set-admin.php &lt;email&gt; &lt;password&gt;</code>.</div><?php endif; ?>
      <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
      <form method="post" action="<?= e(base()) ?>/login" id="loginForm">
        <label>Email</label>
        <input type="email" name="email" id="f_email" autofocus autocomplete="username" placeholder="you@company.com">
        <label>Password</label>
        <input type="password" name="password" id="f_pass" autocomplete="current-password">
        <button class="btn primary block">Sign in</button>
      </form>
      <?php if (quick_login_enabled() && admin_configured()): ?>
        <div class="quickrow"><span>or</span></div>
        <form method="post" action="<?= e(base()) ?>/login/quick" id="quickForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn block quick" type="submit" id="quickBtn">
            <span class="av"><?= e(mb_substr(admin_label(), 0, 1)) ?></span>
            Continue as <?= e(admin_label()) ?>
          </button>
        </form>
        <script>
          // Show the credentials as "filled in" for feedback, then sign in server-side.
          // The real password is never sent to the browser.
          document.getElementById('quickBtn').addEventListener('click', function () {
            document.getElementById('f_email').value = <?= json_encode(admin_email()) ?>;
            document.getElementById('f_pass').value  = '••••••••••••';
          });
        </script>
      <?php endif; ?>
    </div></body></html><?php
}

function render_home(string $view): void {
    $ok = $_SESSION['flash_ok'] ?? ''; $err = $_SESSION['flash_err'] ?? '';
    unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
    $connected = XeroOAuth::isConnected();
    $tenant   = $connected ? XeroOAuth::tenantName() : '';
    $tenantId = $connected ? (string)(XeroOAuth::token()['tenant_id'] ?? '') : '';

    $trips = TripRepo::all();

    // Group linked supplier bills by trip so each row can show whether it's
    // matched to a bill and whether every route leg is costed.
    $billsByTrip = [];
    if ($connected) {
        foreach (BillRepo::allForTenant($tenantId) as $b) {
            if (!in_array((string)$b['match_status'], ['matched', 'tagged', 'approved'], true)) continue;
            $tid = (int)$b['matched_trip_id'];
            if ($tid) $billsByTrip[$tid][] = $b;
        }
    }

    $js = [];
    $stat = ['total' => count($trips), 'matched' => 0, 'ready' => 0, 'inc' => 0, 'ltd' => 0];
    foreach ($trips as $t) {
        $tbills = $billsByTrip[(int)$t['id']] ?? [];
        $count  = count($tbills);
        $cp     = CompletenessChecker::check($t, $tbills);
        $cost   = $count === 0 ? 'none' : ($cp['status'] === 'complete' ? 'ready' : 'partial');
        if ($count > 0)      $stat['matched']++;
        if ($cost === 'ready') $stat['ready']++;
        if (($t['entity'] ?? '') === 'inc') $stat['inc']++; else $stat['ltd']++;
        $js[] = [
            'id' => (int)$t['id'], 'entity' => strtoupper((string)$t['entity']),
            'trip' => (string)$t['trip_number'], 'client' => (string)$t['client_name'],
            'aircraft' => (string)$t['aircraft'], 'route' => (string)$t['route'],
            'start' => (string)$t['start_date'], 'end' => (string)$t['end_date'],
            'flights' => $t['flights_count'] === null ? '' : (int)$t['flights_count'],
            'currency' => (string)$t['currency'], 'source' => (string)$t['source_file'],
            'bills' => $count, 'cost' => $cost,
            'legs' => (int)$cp['legs'], 'covered' => count($cp['covered']),
            'missing' => implode(', ', $cp['missing']),
            'created' => (string)($t['created_at'] ?? ''), 'updated' => (string)($t['updated_at'] ?? ''),
        ];
    }
    $titles = ['dashboard' => 'Dashboard', 'trips' => 'Trips', 'bills' => 'Bills', 'invoices' => 'Invoices', 'settings' => 'Settings'];
    $email = (string)($_SESSION['email'] ?? admin_email());
    $initial = strtoupper(substr($email, 0, 1) ?: 'S');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Signum Unidash · <?= e($titles[$view] ?? '') ?></title><?php styles(); ?></head>
    <body class="app">
    <aside class="sidebar">
      <div class="sbrand"><?= logo() ?><span>Signum Unidash</span></div>
      <nav class="snav">
        <a href="<?= e(base()) ?>/?view=dashboard" class="<?= $view==='dashboard'?'active':'' ?>"><?= icon('dashboard') ?><span class="lbl">Dashboard</span></a>
        <a href="<?= e(base()) ?>/?view=trips" class="<?= $view==='trips'?'active':'' ?>"><?= icon('trips') ?><span class="lbl">Trips</span></a>
        <a href="<?= e(base()) ?>/?view=bills" class="<?= $view==='bills'?'active':'' ?>"><?= icon('bills') ?><span class="lbl">Bills</span></a>
        <a href="<?= e(base()) ?>/?view=invoices" class="<?= $view==='invoices'?'active':'' ?>"><?= icon('invoice') ?><span class="lbl">Invoices</span></a>
        <a href="<?= e(base()) ?>/?view=settings" class="<?= $view==='settings'?'active':'' ?>"><?= icon('settings') ?><span class="lbl">Settings</span></a>
      </nav>
      <div class="suser">
        <span class="av"><?= e($initial) ?></span>
        <span class="uinfo"><b><?= e($email ?: 'admin') ?></b><small>Signum Aviation</small></span>
        <a class="logout" href="<?= e(base()) ?>/logout" title="Sign out">⏻</a>
      </div>
    </aside>
    <div class="content">
      <header class="topbar">
        <h1><?= e($titles[$view] ?? 'Signum Unidash') ?></h1>
        <a class="connpill <?= $connected?'on':'off' ?>" href="<?= e(base()) ?>/?view=settings">
          <span class="cdot"></span><?= $connected ? 'Xero · '.e($tenant) : 'Xero not connected' ?>
        </a>
      </header>
      <main class="view">
        <?php if ($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
        <?php
          if ($view === 'trips')         render_trips($trips, $connected, $tenant);
          elseif ($view === 'bills')     render_bills($connected, $tenant, $tenantId);
          elseif ($view === 'invoices')  render_invoices($connected, $tenant, $tenantId);
          elseif ($view === 'settings')  render_settings($connected, $tenant);
          else                           render_dashboard($stat, $js, $connected, $tenant);
        ?>
      </main>
    </div>

    <!-- Trip detail modal -->
    <div id="modal" class="modal" hidden><div class="mback" data-close></div>
      <div class="mcard" role="dialog" aria-modal="true">
        <div class="mhead"><h3 id="mtitle"></h3><button class="mx" data-close aria-label="Close">×</button></div>
        <div id="mbody" class="mbody"></div>
      </div>
    </div>

    <script>
      const TRIPS = <?= json_encode($js, JSON_UNESCAPED_UNICODE) ?>;
      const BASE  = <?= json_encode(base()) ?>;
      const CSRF  = <?= json_encode(csrf_token()) ?>;
    </script>
    <script><?= app_js() ?></script>
    <script><?= dz_js() ?></script>
    </body></html><?php
}

function render_dashboard(array $stat, array $rows, bool $connected, string $tenant): void {
    $recent = array_slice($rows, 0, 8);
    $costPill = fn(string $c) => $c === 'ready'
        ? '<span class="pill green">Ready</span>'
        : ($c === 'partial' ? '<span class="pill amber">Partial</span>' : '<span class="pill gray">None</span>');
    ?>
    <p class="lede">Every trip, its supplier bills and its billing status — in one place. Data flows in from <b>LEON</b>, <b>Gmail</b> and <b>Xero</b>; nothing re-keyed.</p>

    <section class="tiles">
      <div class="tile"><div class="tnum"><?= $stat['total'] ?></div><div class="tlbl">Trips in master list</div></div>
      <div class="tile"><div class="tnum blue"><?= $stat['matched'] ?></div><div class="tlbl">Matched to bills<?= $connected ? ' · '.e($tenant) : '' ?></div></div>
      <div class="tile"><div class="tnum green"><?= $stat['ready'] ?></div><div class="tlbl">Ready to invoice</div></div>
      <div class="tile"><div class="tnum"><?= $stat['inc'] ?> <span class="tmini">Inc</span> / <?= $stat['ltd'] ?> <span class="tmini">Ltd</span></div><div class="tlbl">By entity</div></div>
    </section>

    <section class="card">
      <div class="chead"><h2>Billing pipeline</h2><span class="muted">trip → supplier bill → client invoice</span></div>
      <div class="pipe">
        <div class="pstep"><div class="pn"><?= $stat['total'] ?></div><div class="pl">Trips</div></div>
        <div class="parrow">→</div>
        <div class="pstep"><div class="pn blue"><?= $stat['matched'] ?></div><div class="pl">Matched to bills</div></div>
        <div class="parrow">→</div>
        <div class="pstep"><div class="pn green"><?= $stat['ready'] ?></div><div class="pl">Ready to invoice</div></div>
      </div>
    </section>

    <div class="panels">
      <section class="card">
        <div class="chead"><h2>Recent trips</h2><a class="muted link" href="<?= e(base()) ?>/?view=trips">View all →</a></div>
        <?php if (!$recent): ?>
          <div class="empty">No trips yet. Go to <a href="<?= e(base()) ?>/?view=trips">Trips</a> to import a LEON export.</div>
        <?php else: ?>
        <div class="tablewrap short">
          <table class="grid"><thead><tr><th>Trip</th><th>Client</th><th>Aircraft</th><th>Bills</th><th>Costing</th></tr></thead><tbody>
          <?php foreach ($recent as $t): ?>
            <tr><td class="mono"><?= e($t['trip']) ?></td><td><?= e($t['client'] ?: '—') ?></td>
                <td class="mono"><?= e($t['aircraft']) ?></td>
                <td><?= $t['bills'] > 0 ? '<span class="pill blue">'.(int)$t['bills'].'</span>' : '<span class="muted">—</span>' ?></td>
                <td><?= $costPill((string)$t['cost']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>
        <?php endif; ?>
      </section>

      <div class="stack">
        <section class="card">
          <div class="chead"><h2>Xero connection</h2></div>
          <div class="banner <?= $connected ? 'on' : 'off' ?>" style="margin:0">
            <div class="binfo"><span class="dot"></span>
              <div><?= $connected ? '<b>Connected to '.e($tenant).'</b>' : '<b>Not connected</b><span class="muted"> · connect to match bills</span>' ?></div>
            </div>
            <div class="bactions">
              <?php if ($connected): ?><a class="btn ghost sm" href="<?= e(base()) ?>/?view=settings">Manage</a>
              <?php else: ?><a class="btn primary sm" href="<?= e(base()) ?>/xero/connect">Connect</a><?php endif; ?>
            </div>
          </div>
        </section>
        <section class="card">
          <div class="chead"><h2>Data sources</h2></div>
          <div class="src"><span class="sdot on"></span><b>LEON</b><span class="muted">Flight Count → trip master list</span></div>
          <div class="src"><span class="sdot on"></span><b>Gmail</b><span class="muted">supplier invoices → WazzOCR</span></div>
          <div class="src"><span class="sdot <?= $connected?'on':'off' ?>"></span><b>Xero</b><span class="muted"><?= $connected ? e($tenant) : 'not connected' ?></span></div>
        </section>
      </div>
    </div>
    <?php
}

function render_trips(array $trips, bool $connected, string $tenant): void {
    ?>
    <div class="banner <?= $connected ? 'on' : 'off' ?>">
      <div class="binfo"><span class="dot"></span>
        <?php if ($connected): ?><div><b>Connected to <?= e($tenant) ?></b><span class="muted"> · bills read from this org</span></div>
        <?php else: ?><div><b>Not connected to Xero</b><span class="muted"> · connect an org to match bills</span></div><?php endif; ?>
      </div>
      <div class="bactions">
        <?php if ($connected): ?><a class="btn ghost sm" href="<?= e(base()) ?>/xero/connect">Switch org</a>
        <?php else: ?><a class="btn primary sm" href="<?= e(base()) ?>/xero/connect">Connect to Xero</a><?php endif; ?>
      </div>
    </div>

    <section class="card">
      <div class="chead"><h2>Import LEON export</h2><span class="muted">Flight Count · CSV, XLSX or PDF · multiple files</span></div>
      <form method="post" action="<?= e(base()) ?>/import" enctype="multipart/form-data" class="importform" id="importForm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="dropzone" id="dz">
          <input type="file" id="leonInput" name="leon[]" class="sronly" multiple
                 accept=".csv,.xlsx,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
          <div class="dzinner">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 9 12 4 17 9"/><line x1="12" y1="4" x2="12" y2="16"/></svg>
            <div class="dztext"><b>Choose files</b> or drag &amp; drop</div>
            <div class="muted small">LEON Flight Count · CSV, XLSX or PDF · multiple files allowed</div>
          </div>
          <ul class="filelist" id="fileList"></ul>
        </label>
        <div class="importrow">
          <div class="field"><label>Entity</label><select name="entity">
            <option value="auto">Auto-detect (by filename)</option>
            <option value="inc">Signum Aviation Inc</option>
            <option value="ltd">Signum Aviation Ltd</option>
          </select></div>
          <button class="btn primary" type="submit">Import</button>
        </div>
      </form>
    </section>

    <section class="card">
      <div class="chead"><h2>Trip master list</h2><span class="muted" id="count"><?= count($trips) ?> trips</span></div>
      <?php if (!$trips): ?>
        <div class="empty">No trips yet. Import a LEON CSV, XLSX or PDF above to build the master list.</div>
      <?php else: ?>
      <form method="post" action="<?= e(base()) ?>/trips/delete" id="poform">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="toolbar">
          <input type="search" id="q" class="search" placeholder="Search trip, client, aircraft, route…">
          <select id="fEntity" class="fsel"><option value="">All entities</option><option value="INC">Inc</option><option value="LTD">Ltd</option></select>
          <select id="fStatus" class="fsel"><option value="">All trips</option><option value="matched">Matched</option><option value="unmatched">Not matched</option><option value="ready">Ready to invoice</option><option value="partial">Partly costed</option></select>
          <span class="spacer"></span>
          <button class="btn danger" type="submit" id="delsel" formnovalidate><span id="selcount">0</span> selected · Delete</button>
        </div>
        <div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th class="cbcol"><input type="checkbox" id="all" title="Select all shown"></th>
            <th data-key="entity" class="sortable">Entity</th>
            <th data-key="trip" class="sortable">Trip</th>
            <th data-key="client" class="sortable">Client</th>
            <th data-key="aircraft" class="sortable">Aircraft</th>
            <th data-key="route">Route</th>
            <th data-key="start" class="sortable">Dates</th>
            <th data-key="flights" class="sortable num">Flts</th>
            <th data-key="bills" class="sortable">Bills</th>
            <th data-key="cost" class="sortable">Costing</th>
            <th class="actcol"></th>
          </tr></thead>
          <tbody id="rows"></tbody>
        </table>
        </div>
      </form>
      <?php endif; ?>
    </section>
    <?php
}

function render_invoices(bool $connected, string $tenant, string $tenantId): void {
    $cfg = InvoiceService::config();
    $ready = $connected ? InvoiceService::readyTrips($tenantId) : [];
    $pending = array_values(array_filter($ready, fn($r) => empty($r['invoice'])));
    $money = fn($cur, $n) => e(($cur !== '' ? $cur . ' ' : '') . number_format((float)$n, 2));
    ?>
    <p class="lede">Trips whose supplier bills are tagged, turned into a draft <b>client sales invoice</b>: each cost is recharged (×<?= e(rtrim(rtrim(number_format((float)$cfg['markup'],2),'0'),'.')) ?>), plus a <?= e(rtrim(rtrim(number_format((float)$cfg['admin_pct'],2),'0'),'.')) ?>% admin charge<?= $cfg['support_fee']>0 ? ' and a trip-support fee' : '' ?>. Rates are set in <a class="link" href="<?= e(base()) ?>/?view=settings">Settings</a>.</p>

    <div class="banner <?= $connected ? 'on' : 'off' ?>">
      <div class="binfo"><span class="dot"></span>
        <div><?= $connected ? '<b>Invoicing into '.e($tenant).'</b> <span class="muted">· drafts only — review + send in Xero</span>' : '<b>Not connected to Xero</b> <span class="muted">· connect an org first</span>' ?></div>
      </div>
    </div>

    <section class="card">
      <div class="chead"><h2>Ready to invoice</h2><span class="muted"><?= count($pending) ?> trip(s) pending · <?= count($ready)-count($pending) ?> invoiced</span></div>
      <?php if (!$connected): ?>
        <div class="empty">Connect Xero in <a href="<?= e(base()) ?>/?view=settings">Settings</a> first.</div>
      <?php elseif (!$ready): ?>
        <div class="empty">No trips ready yet. Tag and <b>approve</b> bills in <a href="<?= e(base()) ?>/?view=bills">Bills</a> — approving a trip's bills raises its invoice here.</div>
      <?php else: ?>
        <div class="tablewrap">
        <table class="grid"><thead><tr>
          <th>Trip</th><th>Client</th><th class="num">Bills</th><th>Legs</th><th class="num">Recharge</th><th class="num">Admin</th><th class="num">Total</th><th>Status</th><th></th>
        </tr></thead><tbody>
        <?php foreach ($ready as $r): $t=$r['trip']; $bd=$r['build']; $inv=$r['invoice']; $cp=$r['complete'];
          $legs = $cp['status']==='complete'
            ? '<span class="pill green" title="All legs have a bill">'.count($cp['covered']).'/'.(int)$cp['legs'].'</span>'
            : ($cp['status']==='gaps'
                ? '<span class="pill amber" title="Missing a bill at: '.e(implode(', ', $cp['missing'])).'">'.count($cp['covered']).'/'.(int)$cp['legs'].'</span>'
                : '<span class="muted">—</span>');
        ?>
          <tr>
            <td class="mono"><?= e($t['trip_number']) ?></td>
            <td><?= e($t['client_name'] ?: '—') ?></td>
            <td class="num"><?= count($r['bills']) ?></td>
            <td class="nowrap"><?= $legs ?></td>
            <?php if ($inv): ?>
              <td class="num muted">—</td><td class="num muted">—</td>
              <td class="num"><b><?= $money($inv['currency'], $inv['total']) ?></b></td>
              <td><span class="pill green">Invoiced</span> <span class="mono"><?= e($inv['xero_invoice_number']) ?></span></td>
              <td class="nowrap"><span class="muted">✓ in Xero</span></td>
            <?php elseif (!empty($bd['buildable'])): $ready = !empty($r['approved']) && $cp['status']==='complete'; ?>
              <td class="num"><?= $money($bd['currency'], $bd['subtotal']) ?></td>
              <td class="num"><?= $money($bd['currency'], $bd['admin']) ?></td>
              <td class="num"><b><?= $money($bd['currency'], $bd['total']) ?></b></td>
              <?php if ($ready): ?>
                <td><span class="pill blue">Ready</span></td>
                <td class="nowrap">
                  <?php $conf = 'Create a draft '.$bd['currency'].' invoice to '.addslashes($t['client_name'] ?: 'the client').' for '.($bd['currency'].' '.number_format((float)$bd['total'],2)).'?'; ?>
                  <form method="post" action="<?= e(base()) ?>/invoices/create" onsubmit="return confirm('<?= e($conf) ?>')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
                    <button class="btn primary sm">Create draft invoice</button>
                  </form>
                </td>
              <?php elseif ($cp['status']!=='complete'): ?>
                <td><span class="pill amber" title="Missing a bill at: <?= e(implode(', ', $cp['missing'])) ?>">Incomplete</span></td>
                <td class="nowrap"><span class="muted">awaiting bills</span></td>
              <?php else: ?>
                <td><span class="pill amber">Awaiting approval</span></td>
                <td class="nowrap"><a class="link" href="<?= e(base()) ?>/?view=bills">Approve in Bills →</a></td>
              <?php endif; ?>
            <?php else: ?>
              <td class="num muted" colspan="3"><?= e($bd['reason']) ?></td>
              <td><span class="pill amber">Manual</span></td>
              <td class="nowrap"><span class="muted">raise in Xero</span></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function render_settings(bool $connected, string $tenant): void {
    ?>
    <section class="card">
      <div class="chead"><h2>Xero connection</h2></div>
      <div class="banner <?= $connected ? 'on' : 'off' ?>" style="margin-bottom:14px">
        <div class="binfo"><span class="dot"></span>
          <div><?= $connected ? '<b>Connected to '.e($tenant).'</b> <span class="muted">· POs create here; reconnect to switch org</span>' : '<b>Not connected</b> <span class="muted">· creating POs will dry-run only</span>' ?></div>
        </div>
        <div class="bactions">
          <?php if ($connected): ?>
            <a class="btn ghost sm" href="<?= e(base()) ?>/xero/connect">Reconnect / switch org</a>
            <form method="post" action="<?= e(base()) ?>/xero/disconnect" onsubmit="return confirm('Disconnect from Xero?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn ghost sm">Disconnect</button></form>
          <?php else: ?>
            <a class="btn primary sm" href="<?= e(base()) ?>/xero/connect">Connect to Xero</a>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" action="<?= e(base()) ?>/settings" class="settings">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="field grow"><label>Client ID</label><input name="client_id" placeholder="<?= XeroOAuth::clientId() !== '' ? '•••• saved' : '' ?>"></div>
        <div class="field grow"><label>Client Secret</label><input name="client_secret" type="password" placeholder="<?= XeroOAuth::clientSecret() !== '' ? '•••• saved' : '' ?>"></div>
        <div class="field grow"><label>Redirect URI (register this in your Xero app)</label><input name="redirect_uri" value="<?= e(XeroOAuth::redirectUri()) ?>"></div>
        <div class="field grow"><label>Scopes</label><input name="scopes" value="<?= e(XeroOAuth::scopes()) ?>"></div>
        <div class="field"><label>Inc currency</label><input name="currency_inc" value="<?= e((string)Settings::get('currency.inc','')) ?>" placeholder="org base"></div>
        <div class="field"><label>Ltd currency</label><input name="currency_ltd" value="<?= e((string)Settings::get('currency.ltd','')) ?>" placeholder="org base"></div>
        <div class="field"><label class="chk"><input type="checkbox" name="enabled" <?= Settings::bool('xero.enabled') ? 'checked' : '' ?>> Enable live pushes</label></div>
        <div class="field" style="flex:1 1 100%"><label style="font-weight:600;color:var(--ink);font-size:13px">Invoice rules (Module 5)</label></div>
        <div class="field"><label>Recharge markup (×)</label><input name="inv_markup" value="<?= e((string)Settings::get('invoice.markup','1.02')) ?>"></div>
        <div class="field"><label>Admin charge (%)</label><input name="inv_admin" value="<?= e((string)Settings::get('invoice.admin_pct','11')) ?>"></div>
        <div class="field"><label>Trip support fee</label><input name="inv_support" value="<?= e((string)Settings::get('invoice.support_fee','0')) ?>"></div>
        <div class="field"><label>Revenue account code</label><input name="inv_account" value="<?= e((string)Settings::get('invoice.account_code','')) ?>" placeholder="e.g. 200"></div>
        <div class="field"><label>&nbsp;</label><button class="btn primary">Save settings</button></div>
      </form>
    </section>
    <?php
}

function render_bills(bool $connected, string $tenant, string $tenantId): void {
    $bills = $connected ? BillRepo::allForTenant($tenantId) : [];
    $allTrips = TripRepo::all();
    $c = ['pulled'=>count($bills),'matched'=>0,'tagged'=>0,'approved'=>0,'ambiguous'=>0,'review'=>0];
    foreach ($bills as $b) { $s=(string)$b['match_status']; if(isset($c[$s]))$c[$s]++; }
    $bpill = function(string $s): string {
        $m = ['approved'=>['green','Approved'],'tagged'=>['blue','Tagged'],'matched'=>['blue','Matched'],'ambiguous'=>['amber','Ambiguous'],'review'=>['gray','Review']];
        [$cl,$t] = $m[$s] ?? ['gray', ucfirst($s)];
        return '<span class="pill '.$cl.'">'.e($t).'</span>';
    };
    ?>
    <p class="lede">Supplier bills WazzOCR created in Xero, matched to a trip in your master list. Tag writes the <b>trip number</b> onto the bill's Reference; <b>Approve</b> authorises the bill in Xero, and once every bill on a trip is approved and all legs are costed, the client invoice is raised automatically.</p>

    <div class="banner <?= $connected ? 'on' : 'off' ?>">
      <div class="binfo"><span class="dot"></span>
        <div><?= $connected ? '<b>Reading '.e($tenant).'</b> <span class="muted">· must be the same org WazzOCR bills into</span>' : '<b>Not connected to Xero</b> <span class="muted">· connect an org to pull bills</span>' ?></div>
      </div>
      <div class="bactions">
        <form method="post" action="<?= e(base()) ?>/bills/refresh"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn primary sm" <?= $connected?'':'disabled' ?>>Refresh from Xero</button></form>
      </div>
    </div>

    <section class="card">
      <div class="chead">
        <h2>Supplier bills</h2>
        <div class="reschips">
          <span class="chip"><?= $c['pulled'] ?> pulled</span>
          <span class="chip blue"><?= $c['matched'] + $c['tagged'] ?> to approve</span>
          <span class="chip green"><?= $c['approved'] ?> approved</span>
          <span class="chip amber"><?= $c['ambiguous'] + $c['review'] ?> need review</span>
        </div>
      </div>

      <?php if (!$connected): ?>
        <div class="empty">Connect Xero in <a href="<?= e(base()) ?>/?view=settings">Settings</a>, then <b>Refresh from Xero</b> to pull draft bills.</div>
      <?php elseif (!$bills): ?>
        <div class="empty">No bills pulled yet. Click <b>Refresh from Xero</b> to fetch draft supplier bills and match them to trips.</div>
      <?php else: ?>
        <?php if ($c['matched']): ?>
        <div class="toolbar">
          <span class="muted"><?= $c['matched'] ?> matched bill(s) ready to tag</span><span class="spacer"></span>
          <form method="post" action="<?= e(base()) ?>/bills/tag-all" onsubmit="return confirm('Tag all <?= $c['matched'] ?> matched bill(s) with their trip number in Xero?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn primary">Tag all matched</button></form>
        </div>
        <?php endif; ?>
        <div class="tablewrap">
        <table class="grid"><thead><tr>
          <th>Supplier</th><th>Bill</th><th>Date</th><th class="num">Amount</th>
          <th>Extracted</th><th>Matched trip → client</th><th>Status</th><th></th>
        </tr></thead><tbody>
        <?php foreach ($bills as $b):
            $ex = trim(implode(' · ', array_filter([$b['ex_tail'], $b['ex_airport'], $b['ex_date']])));
        ?>
          <tr>
            <td><?= e($b['supplier'] ?: '—') ?><?php if ($b['description']): ?><div class="billdesc" title="<?= e($b['description']) ?>"><?= e(mb_strimwidth((string)$b['description'], 0, 64, '…')) ?></div><?php endif; ?></td>
            <td class="mono" title="<?= e($b['description']) ?>"><?= e($b['invoice_number'] ?: substr((string)$b['xero_invoice_id'],0,8)) ?></td>
            <td class="nowrap"><?= e($b['bill_date']) ?></td>
            <td class="num"><?php if ($b['total']!==null):
                echo e($b['currency'].' '.number_format((float)$b['total'],2));
                $bc = (string)($b['base_currency'] ?? '');
                if ($bc !== '' && $bc !== (string)$b['currency'] && ($b['base_total'] ?? null) !== null):
                    $eff = (float)$b['total'] != 0.0 ? (float)$b['base_total'] / (float)$b['total'] : 0.0;
                    $effr = rtrim(rtrim(number_format($eff, 4), '0'), '.'); ?>
                    <div class="muted small" title="1 <?= e((string)$b['currency']) ?> = <?= e($effr) ?> <?= e($bc) ?>">→ <?= e($bc.' '.number_format((float)$b['base_total'],2)) ?></div>
                <?php endif;
              endif; ?></td>
            <td class="mono" style="font-size:12px"><?= e($ex ?: '—') ?></td>
            <td><?php if ($b['matched_trip_number']): ?><span class="mono"><?= e($b['matched_trip_number']) ?></span> <span class="muted">→ <?= e($b['matched_client'] ?: 'no client') ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?= $bpill((string)$b['match_status']) ?><?= !empty($b['xero_last_error']) ? ' <span class="warnmark" title="'.e($b['xero_last_error']).'">!</span>' : '' ?></td>
            <td class="nowrap">
              <?php if ($b['match_status']==='approved'): ?>
                <span class="muted">✓ Approved</span>
              <?php elseif ($b['match_status']==='matched' || $b['match_status']==='tagged'): ?>
                <form method="post" action="<?= e(base()) ?>/bills/approve" style="display:inline" onsubmit="return confirm('Approve this bill in Xero (trip <?= e($b['matched_trip_number']) ?>)? Once every bill on the trip is approved and all legs are costed, the client invoice is raised.')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button class="btn primary sm">Approve</button>
                </form>
                <?php if ($b['match_status']==='tagged'): ?><span class="muted small">✓ tagged</span><?php endif; ?>
              <?php else: ?>
                <form method="post" action="<?= e(base()) ?>/bills/assign" class="assignform">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <select name="trip_id" required><option value="">Assign trip…</option>
                    <?php foreach ($allTrips as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['trip_number'].' — '.($t['client_name'] ?: 'no client').' · '.$t['aircraft']) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn sm">Assign</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function dz_js(): string {
    return <<<'JS'
(function(){
  const dz=document.getElementById('dz'), inp=document.getElementById('leonInput'),
        list=document.getElementById('fileList'), form=document.getElementById('importForm');
  if(!dz||!inp) return;
  const fmt=b=> b>=1048576 ? (b/1048576).toFixed(1)+' MB' : Math.max(1,Math.round(b/1024))+' KB';
  const kind=n=> /\.pdf$/i.test(n)?'PDF' : /\.xlsm?$/i.test(n)||/\.xlsx$/i.test(n)?'XLSX' : 'CSV';
  const esc=s=>String(s).replace(/[<>&]/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
  function render(){
    const fs=[...inp.files];
    list.innerHTML=fs.map(f=>`<li class="filechip"><span class="fx ${kind(f.name).toLowerCase()}">${kind(f.name)}</span><span class="fn">${esc(f.name)}</span><em>${fmt(f.size)}</em></li>`).join('');
    dz.classList.toggle('has', fs.length>0);
  }
  inp.addEventListener('change', render);
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();dz.classList.add('drag');}));
  ['dragleave','dragend'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag');}));
  dz.addEventListener('drop',e=>{e.preventDefault();e.stopPropagation();dz.classList.remove('drag');
    if(e.dataTransfer&&e.dataTransfer.files.length){ inp.files=e.dataTransfer.files; render(); }});
  form.addEventListener('submit',e=>{ if(inp.files.length===0){ e.preventDefault(); alert('Choose at least one LEON file first.'); }});
})();
JS;
}

function app_js(): string {
    return <<<'JS'
const $ = s => document.querySelector(s);
const rowsEl = $('#rows'); if (!rowsEl) { /* not on trips view */ } else {
  const COST = {ready:['green','Ready'], partial:['amber','Partial'], none:['gray','None']};
  const COSTRANK = {none:0, partial:1, ready:2};
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const selected = new Set();
  let sortKey = 'start', sortDir = -1;

  function dates(t){ return t.end && t.end !== t.start ? `${t.start} → ${t.end}` : (t.start || ''); }
  function matches(t){
    const q = $('#q').value.trim().toLowerCase();
    const fe = $('#fEntity').value, fs = $('#fStatus').value;
    if (fe && t.entity !== fe) return false;
    if (fs === 'matched'   && !(t.bills > 0)) return false;
    if (fs === 'unmatched' && t.bills > 0)    return false;
    if (fs === 'ready'     && t.cost !== 'ready')   return false;
    if (fs === 'partial'   && t.cost !== 'partial') return false;
    if (q){ const hay = `${t.trip} ${t.client} ${t.aircraft} ${t.route} ${t.entity}`.toLowerCase(); if (!hay.includes(q)) return false; }
    return true;
  }
  function sortVal(t){
    if (sortKey==='flights') return t.flights===''?-1:+t.flights;
    if (sortKey==='bills')   return +t.bills;
    if (sortKey==='cost')    return COSTRANK[t.cost] ?? -1;
    return String(t[sortKey]).toLowerCase();
  }

  function billsCell(t){
    return t.bills > 0
      ? `<span class="pill blue" title="${t.bills} supplier bill(s) tagged to this trip">${t.bills} bill${t.bills>1?'s':''}</span>`
      : `<span class="muted">Not matched</span>`;
  }
  function costCell(t){
    const [c,l] = COST[t.cost] || ['gray','None'];
    const tip = t.cost==='partial' && t.missing ? ` title="No bill yet at: ${esc(t.missing)}"` : (t.cost==='ready' ? ' title="Every route leg has a bill"' : '');
    const legs = t.legs>0 ? ` <span class="muted small">${t.covered}/${t.legs}</span>` : '';
    return `<span class="pill ${c}"${tip}>${esc(l)}</span>${legs}`;
  }

  function render(){
    const list = TRIPS.filter(matches).sort((a,b)=>{
      const x=sortVal(a), y=sortVal(b); return (x<y?-1:x>y?1:0)*sortDir;
    });
    rowsEl.innerHTML = list.map(t => {
      const ck = selected.has(t.id) ? 'checked' : '';
      return `<tr data-id="${t.id}">
        <td class="cbcol"><input type="checkbox" class="pick" ${ck}></td>
        <td><span class="tagpill">${esc(t.entity)}</span></td>
        <td class="mono">${esc(t.trip)}</td>
        <td>${esc(t.client||'—')}</td>
        <td class="mono">${esc(t.aircraft)}</td>
        <td class="route" title="${esc(t.route)}">${esc(t.route)}</td>
        <td class="nowrap">${esc(dates(t))}</td>
        <td class="num">${t.flights===''?'':t.flights}</td>
        <td class="nowrap">${billsCell(t)}</td>
        <td class="nowrap">${costCell(t)}</td>
        <td class="actcol"><button type="button" class="del" title="Delete this trip from the master list" aria-label="Delete trip ${esc(t.trip)}">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button></td>
      </tr>`;
    }).join('');
    $('#count').textContent = `${list.length} of ${TRIPS.length} trips`;
    updateSel();
    const allBox = $('#all');
    allBox.checked = list.length>0 && list.every(t=>selected.has(t.id));
  }
  function updateSel(){ $('#selcount').textContent = selected.size; }

  $('#poform').addEventListener('submit', e => {
    $('#poform').querySelectorAll('input[data-sel]').forEach(n=>n.remove());
    selected.forEach(id => {
      const i=document.createElement('input'); i.type='hidden'; i.name='trip_ids[]'; i.value=id; i.dataset.sel='1';
      $('#poform').appendChild(i);
    });
    if (selected.size===0){ e.preventDefault(); alert('Tick at least one trip to delete.'); return; }
    if (!confirm(`Delete ${selected.size} trip(s) from the master list?\n\nThis only removes them here — nothing in Xero is touched.`)) e.preventDefault();
  });

  function deleteTrip(id){
    const t = TRIPS.find(x=>x.id===id); if(!t) return;
    if (!confirm(`Delete trip ${t.trip} (${t.client||'no client'}) from the master list?\n\nThis only removes it here — nothing in Xero is touched.`)) return;
    const f=document.createElement('form'); f.method='post'; f.action=BASE+'/trips/delete';
    f.innerHTML = `<input type="hidden" name="csrf" value="${esc(CSRF)}"><input type="hidden" name="trip_ids[]" value="${id}">`;
    document.body.appendChild(f); f.submit();
  }

  rowsEl.addEventListener('change', e => {
    if (!e.target.classList.contains('pick')) return;
    const id = +e.target.closest('tr').dataset.id;
    e.target.checked ? selected.add(id) : selected.delete(id);
    updateSel(); render();
  });
  rowsEl.addEventListener('click', e => {
    if (e.target.classList.contains('pick')) return;
    const tr = e.target.closest('tr'); if (!tr) return;
    if (e.target.closest('.del')) { deleteTrip(+tr.dataset.id); return; }
    openModal(+tr.dataset.id);
  });
  $('#all').addEventListener('change', e => {
    TRIPS.filter(matches).forEach(t=> e.target.checked ? selected.add(t.id) : selected.delete(t.id));
    render();
  });
  ['input','change'].forEach(ev=>{ $('#q').addEventListener(ev,render); });
  $('#fEntity').addEventListener('change',render); $('#fStatus').addEventListener('change',render);
  document.querySelectorAll('th.sortable').forEach(th=>th.addEventListener('click',()=>{
    const k=th.dataset.key; if(sortKey===k) sortDir*=-1; else {sortKey=k; sortDir=1;}
    document.querySelectorAll('th.sortable').forEach(x=>x.classList.remove('asc','desc'));
    th.classList.add(sortDir>0?'asc':'desc'); render();
  }));

  function row(k,v){ return v ? `<div class="drow"><span>${k}</span><b>${esc(v)}</b></div>` : ''; }
  function openModal(id){
    const t = TRIPS.find(x=>x.id===id); if(!t) return;
    $('#mtitle').innerHTML = `Trip ${esc(t.trip)} <span class="tagpill">${esc(t.entity)}</span>`;
    const legs = (t.route||'').split(' - ').filter(Boolean).map(a=>`<span class="leg">${esc(a)}</span>`).join('<span class="arr">→</span>');
    const [c,l] = COST[t.cost] || ['gray','None'];
    const cover = t.legs>0 ? ` <span class="mono">${t.covered}/${t.legs} legs</span>` : '';
    $('#mbody').innerHTML =
      `<div class="legs">${legs||'—'}</div>` +
      row('Client', t.client||'—') + row('Aircraft', t.aircraft) +
      row('Dates', dates(t)) + row('Flights', t.flights) +
      `<div class="drow"><span>Bills matched</span><b>${t.bills>0 ? t.bills : '—'}</b></div>` +
      `<div class="drow"><span>Costing</span><b><span class="pill ${c}">${esc(l)}</span>${cover}</b></div>` +
      (t.cost==='partial' && t.missing ? `<div class="drow"><span>Missing a bill at</span><b>${esc(t.missing)}</b></div>` : '') +
      row('Source file', t.source) + row('Imported', t.created) + row('Updated', t.updated) +
      `<div class="mfoot"><button type="button" class="btn danger sm" id="mdel">Delete from master list</button></div>`;
    $('#mdel').addEventListener('click', () => { $('#modal').hidden = true; deleteTrip(t.id); });
    $('#modal').hidden = false;
  }
  document.querySelectorAll('[data-close]').forEach(x=>x.addEventListener('click',()=>$('#modal').hidden=true));
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') $('#modal').hidden=true; });

  render();
}
JS;
}

function styles(): void { ?><style>
:root{
  --bg:#f4f6fb; --card:#ffffff; --ink:#1f2430; --mut:#6b7280; --line:#e6e8f0;
  --accent:#2563eb; --accent-d:#1d4ed8; --green:#16a34a; --amber:#c2820a; --red:#dc2626; --gray:#94a3b8;
  --nav:#0b1f47; --radius:12px; --shadow:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.05);
}
*{box-sizing:border-box}
body{margin:0;font:14.5px/1.55 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
a.link{text-decoration:none;color:var(--accent)} a.link:hover{text-decoration:underline}
.muted{color:var(--mut)} .green{color:var(--green)} .amber{color:var(--amber)} .red{color:var(--red)}

/* app shell */
body.app{display:flex;min-height:100vh}
.sidebar{width:238px;flex:none;background:var(--nav);color:#c7d2fe;display:flex;flex-direction:column;padding:16px 12px;position:sticky;top:0;height:100vh}
.sbrand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:800;font-size:16px;padding:6px 8px 18px}
.snav{display:flex;flex-direction:column;gap:3px;flex:1}
.snav a{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:9px;color:#aab6e6;text-decoration:none;font-size:14px;font-weight:500}
.snav a:hover{background:rgba(255,255,255,.07);color:#fff}
.snav a.active{background:var(--accent);color:#fff}
.snav a.soon{opacity:.5;cursor:default}
.snav .nicon{flex:none}
.snav .soontag{margin-left:auto;font-size:9.5px;font-weight:700;background:rgba(255,255,255,.16);padding:1px 6px;border-radius:10px;letter-spacing:.3px}
.suser{display:flex;align-items:center;gap:9px;padding:12px 8px 2px;border-top:1px solid rgba(255,255,255,.1);margin-top:8px}
.suser .av{width:30px;height:30px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:700;font-size:12px;flex:none}
.suser .uinfo{display:flex;flex-direction:column;min-width:0;line-height:1.25}
.suser .uinfo b{color:#eef2ff;font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px}
.suser .uinfo small{color:#8ea0d6;font-size:11px}
.suser .logout{margin-left:auto;color:#aab6e6;text-decoration:none;font-size:16px}
.suser .logout:hover{color:#fff}

.content{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{position:sticky;top:0;z-index:10;background:rgba(244,246,251,.82);backdrop-filter:blur(6px);border-bottom:1px solid var(--line);padding:13px 26px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.topbar h1{font-size:19px;margin:0}
.connpill{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;padding:5px 11px;border-radius:20px;text-decoration:none;border:1px solid transparent}
.connpill .cdot{width:8px;height:8px;border-radius:50%}
.connpill.on{background:#ecfdf3;color:#067647;border-color:#abefc6}.connpill.on .cdot{background:var(--green)}
.connpill.off{background:#fffaeb;color:#b54708;border-color:#fedf89}.connpill.off .cdot{background:var(--amber)}
.view{padding:22px 26px;max-width:1200px}
.lede{color:var(--mut);margin:0 0 18px;font-size:14px;max-width:760px}
@media(max-width:820px){
  .sidebar{width:60px;padding:14px 8px} .sbrand span,.snav .lbl,.snav .soontag,.suser .uinfo{display:none}
  .sbrand{justify-content:center} .snav a{justify-content:center} .suser{justify-content:center}
}

.alert{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;border:1px solid transparent}
.alert.ok{background:#ecfdf3;border-color:#abefc6;color:#067647}
.alert.err{background:#fef3f2;border-color:#fecdca;color:#b42318}
.alert.warn{background:#fffaeb;border-color:#fedf89;color:#b54708}

.banner{display:flex;justify-content:space-between;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;box-shadow:var(--shadow)}
.banner .binfo{display:flex;align-items:center;gap:10px}
.banner .dot{width:9px;height:9px;border-radius:50%;flex:none}
.banner.on .dot{background:var(--green);box-shadow:0 0 0 3px #dcfce7}
.banner.off .dot{background:var(--amber);box-shadow:0 0 0 3px #fef3c7}
.bactions{display:flex;gap:8px;align-items:center}

.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow)}
.tnum{font-size:26px;font-weight:700;letter-spacing:-.5px}
.tmini{font-size:12px;font-weight:600;color:var(--mut)}
.tlbl{font-size:12px;color:var(--mut);margin-top:2px}
@media(max-width:820px){.tiles{grid-template-columns:repeat(2,1fr)}}

.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:16px;box-shadow:var(--shadow)}
.chead{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:12px}
.chead h2{font-size:15px;margin:0}

.panels{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;align-items:start}
@media(max-width:900px){.panels{grid-template-columns:1fr}}
.stack{display:flex;flex-direction:column}
.pipe{display:flex;align-items:stretch;gap:8px}
.pstep{flex:1;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:12px 10px;text-align:center}
.pstep .pn{font-size:22px;font-weight:700}
.pstep .pl{font-size:11px;color:var(--mut);margin-top:2px}
.pstep.soon{opacity:.65}
.parrow{align-self:center;color:var(--gray)}
.pstep .soontag{display:inline-block;font-size:9px;font-weight:700;background:#eef2ff;color:#3538cd;padding:0 5px;border-radius:8px;margin-left:3px}
.src{display:flex;align-items:center;gap:9px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13px}
.src:last-child{border:0}.src .sdot{width:8px;height:8px;border-radius:50%;flex:none}
.src .sdot.on{background:var(--green)}.src .sdot.off{background:var(--gray)}
.src .muted{margin-left:auto}

label{display:block;font-size:12px;color:var(--mut);margin:0 0 5px}
input,select{width:100%;padding:9px 11px;background:#fff;border:1px solid #d5d9e4;border-radius:9px;color:var(--ink);font-size:14px}
input:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px #dbe4ff}
input[type=checkbox]{width:auto}
.chk{display:inline-flex;gap:7px;align-items:center;color:var(--ink);font-size:13px;margin:0;white-space:nowrap}

.btn{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #d5d9e4;color:var(--ink);padding:9px 14px;border-radius:9px;cursor:pointer;text-decoration:none;font-size:14px;font-weight:500;white-space:nowrap}
.btn:hover{background:#f7f8fc}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.primary:hover{background:var(--accent-d)}
.btn.ghost{background:#fff}.btn.sm{padding:6px 11px;font-size:13px}
.btn.danger{border-color:#f3c2c2;color:var(--red)}.btn.danger:hover{background:#fef2f2;border-color:var(--red)}
.btn.block{width:100%;justify-content:center;margin-top:6px}

.settings{display:flex;gap:12px;flex-wrap:wrap;align-items:end}
.field{flex:0 0 auto;min-width:150px}.field.grow{flex:1 1 260px}
.small{font-size:12px}

.importform{display:flex;flex-direction:column;gap:14px}
.sronly{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);border:0}
.dropzone{display:block;border:1.5px dashed #c7cde0;border-radius:12px;background:#fafbff;padding:20px;cursor:pointer;transition:border-color .15s,background .15s}
.dropzone:hover{border-color:var(--accent);background:#f5f8ff}
.dropzone.drag{border-color:var(--accent);background:#eef4ff;border-style:solid}
.dropzone.has{border-style:solid;border-color:#d5d9e4;background:#fff}
.dzinner{display:flex;flex-direction:column;align-items:center;gap:5px;text-align:center;pointer-events:none}
.dztext{font-size:14px}.dztext b{color:var(--accent)}
.filelist{list-style:none;margin:14px 0 0;padding:0;display:flex;flex-direction:column;gap:6px}
.filechip{display:flex;align-items:center;gap:10px;background:#f7f8fc;border:1px solid var(--line);border-radius:9px;padding:7px 11px;font-size:13px}
.filechip .fn{flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.filechip em{color:var(--mut);font-style:normal;font-size:12px}
.fx{font-size:10.5px;font-weight:700;letter-spacing:.4px;padding:2px 7px;border-radius:6px;color:#fff;flex:none}
.fx.csv{background:#16a34a}.fx.xlsx{background:#0f766e}.fx.pdf{background:#dc2626}
.importrow{display:flex;gap:12px;align-items:end}.importrow .field{min-width:220px}

.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.search{flex:1 1 240px;min-width:180px}
.fsel{flex:0 0 auto;width:auto;min-width:140px}.toolbar .spacer{flex:1 1 auto}

.tablewrap{overflow:auto;border:1px solid var(--line);border-radius:10px;max-height:560px}
.tablewrap.short{max-height:none}
table.grid{width:100%;border-collapse:collapse;font-size:13px}
table.grid th,table.grid td{text-align:left;padding:9px 11px;border-bottom:1px solid var(--line);vertical-align:middle}
table.grid thead th{position:sticky;top:0;background:#f8fafc;z-index:1;font-size:12px;color:var(--mut);font-weight:600;white-space:nowrap}
table.grid tbody tr:hover{background:#f8faff;cursor:pointer}
table.grid tbody tr:last-child td{border-bottom:0}
th.sortable{cursor:pointer;user-select:none}th.sortable:hover{color:var(--ink)}
th.sortable.asc::after{content:" ▲";font-size:9px}th.sortable.desc::after{content:" ▼";font-size:9px}
.cbcol{width:34px;text-align:center}
td.num,th.num{text-align:right}
td.route{max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:ui-monospace,monospace;color:#475069;font-size:12px}
td.nowrap{white-space:nowrap;color:var(--mut)}

.pill{display:inline-block;font-size:11.5px;font-weight:600;padding:2px 9px;border-radius:20px;border:1px solid transparent;white-space:nowrap}
.pill.green{background:#ecfdf3;color:#067647;border-color:#abefc6}
.pill.amber{background:#fffaeb;color:#b54708;border-color:#fedf89}
.pill.red{background:#fef3f2;color:#b42318;border-color:#fecdca}
.pill.blue{background:#eff4ff;color:#1d4ed8;border-color:#c7d7fe}
.pill.gray{background:#f2f4f7;color:#475467;border-color:#e4e7ec}
.tagpill{display:inline-block;font-size:11px;font-weight:600;color:#3538cd;background:#eef2ff;border-radius:6px;padding:1px 7px}
.warnmark{color:var(--red);font-weight:700;cursor:help}
th.actcol,td.actcol{width:38px;padding-left:0;padding-right:8px;text-align:right}
.del{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;padding:0;background:transparent;border:1px solid transparent;border-radius:7px;color:var(--gray);cursor:pointer}
.del:hover{color:var(--red);background:#fef2f2;border-color:#f3c2c2}
.mfoot{display:flex;justify-content:flex-end;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}

.reschips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.chip{font-size:12px;padding:3px 10px;border-radius:20px;background:#f2f4f7;color:#475467}
.chip.green{background:#ecfdf3;color:#067647}.chip.amber{background:#fffaeb;color:#b54708}
.chip.red{background:#fef3f2;color:#b42318}.chip.blue{background:#eff4ff;color:#1d4ed8}
.empty{padding:26px;text-align:center;color:var(--mut);border:1px dashed var(--line);border-radius:10px}
.billdesc{font-size:11px;color:var(--mut);max-width:230px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.assignform{display:flex;gap:6px;align-items:center}
.assignform select{width:auto;min-width:150px;max-width:220px;padding:5px 8px;font-size:12px}

.modal{position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;padding:20px}
.modal[hidden]{display:none}
.mback{position:absolute;inset:0;background:rgba(16,24,40,.45)}
.mcard{position:relative;background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:85vh;overflow:auto;box-shadow:0 20px 40px rgba(16,24,40,.2)}
.mhead{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid var(--line);position:sticky;top:0;background:#fff}
.mhead h3{margin:0;font-size:16px;display:flex;gap:8px;align-items:center}
.mx{background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:var(--mut)}
.mbody{padding:16px 18px}
.legs{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:14px}
.leg{font-family:ui-monospace,monospace;font-size:12px;background:#eef2ff;color:#3538cd;padding:2px 8px;border-radius:6px}
.arr{color:var(--mut);font-size:12px}
.drow{display:flex;justify-content:space-between;gap:16px;padding:7px 0;border-bottom:1px solid var(--line);font-size:13px}
.drow:last-child{border-bottom:0}.drow span{color:var(--mut)}.drow b{font-weight:600;text-align:right;word-break:break-word}
.drow.err b{color:var(--red)}

/* login */
body.login{display:flex;align-items:center;justify-content:center;min-height:100vh}
.loginbox{background:#fff;border:1px solid var(--line);border-radius:14px;padding:28px;width:370px;box-shadow:var(--shadow)}
.loginbox .brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:20px}
.loginbox .sub{color:var(--mut);margin:6px 0 18px;font-size:13px}
.loginbox label{margin-top:10px}
.quickrow{display:flex;align-items:center;gap:10px;margin:16px 0 10px;color:var(--mut);font-size:12px}
.quickrow:before,.quickrow:after{content:"";flex:1;height:1px;background:var(--line)}
.btn.quick{gap:9px;font-weight:600}
.btn.quick .av{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:var(--accent);color:#fff;font-size:11px;font-weight:700}
</style><?php }
