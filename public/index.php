<?php
/**
 * Skyledger — Module 4 front controller.
 * Import a LEON export (CSV/PDF) into the trip master list, pick trips, and
 * create one DRAFT Xero Purchase Order per selected trip. A single shared
 * password gates everything (OAuth + PO creation must not be public).
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;
use App\Repo\TripRepo;
use App\Service\Leon\LeonProcessor;
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

// --- auth -----------------------------------------------------------
if ($path === '/login' && $method === 'POST') {
    $hash = (string)cfg('app.admin_password_hash', '');
    if ($hash !== '' && password_verify($_POST['password'] ?? '', $hash)) { $_SESSION['authed'] = true; redirect('/'); }
    $_SESSION['flash_err'] = 'Wrong password.'; redirect('/login');
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
    $_SESSION['flash_ok'] = 'Settings saved.'; redirect('/');
}

// --- Xero OAuth -----------------------------------------------------
if ($path === '/xero/connect') {
    if (!XeroOAuth::isConfigured()) { $_SESSION['flash_err'] = 'Enter Client ID and Secret first, then Save.'; redirect('/'); }
    $state = bin2hex(random_bytes(16)); $_SESSION['xero_state'] = $state;
    header('Location: ' . XeroOAuth::authorizeUrl($state)); exit;
}
if ($path === '/xero/callback') {
    if (!empty($_GET['error'])) { $_SESSION['flash_err'] = 'Xero: ' . $_GET['error']; redirect('/'); }
    if (!hash_equals($_SESSION['xero_state'] ?? '', (string)($_GET['state'] ?? ''))) { $_SESSION['flash_err'] = 'Security check failed (state mismatch). Try again.'; redirect('/'); }
    unset($_SESSION['xero_state']);
    try {
        $info = XeroOAuth::completeConnection((string)($_GET['code'] ?? ''));
        Settings::set('xero.enabled', '1');
        $_SESSION['flash_ok'] = 'Connected to ' . ($info['tenant_name'] ?: 'Xero') . '. POs will create in this org.';
    } catch (\Throwable $e) { $_SESSION['flash_err'] = $e->getMessage(); }
    redirect('/');
}
if ($path === '/xero/disconnect' && $method === 'POST') {
    csrf_check(); XeroOAuth::disconnect(); $_SESSION['flash_ok'] = 'Disconnected from Xero.'; redirect('/');
}

// --- import a LEON export into the master list -----------------------
if ($path === '/import' && $method === 'POST') {
    csrf_check();
    $entity = strtolower($_POST['entity'] ?? 'inc') === 'ltd' ? 'ltd' : 'inc';
    if (empty($_FILES['leon']['tmp_name']) || !is_uploaded_file($_FILES['leon']['tmp_name'])) {
        $_SESSION['flash_err'] = 'Choose a LEON CSV or PDF to import.'; redirect('/');
    }
    $dir = STORAGE_ROOT . '/uploads'; @mkdir($dir, 0770, true);
    $dest = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($_FILES['leon']['name']));
    move_uploaded_file($_FILES['leon']['tmp_name'], $dest);
    try {
        $imp = LeonProcessor::import($dest, $entity)['summary'];
        $_SESSION['flash_ok'] = "Imported {$imp['parsed']} trips from " . strtoupper($imp['source']) . " ({$imp['new']} new, {$imp['updated']} updated). Select trips below and create draft POs.";
    } catch (\Throwable $e) { $_SESSION['flash_err'] = 'Import failed: ' . $e->getMessage(); }
    redirect('/');
}

// --- create draft POs for selected trips ----------------------------
$result = null;
if ($path === '/create-pos' && $method === 'POST') {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['trip_ids'] ?? []));
    if (!$ids) { $_SESSION['flash_err'] = 'Tick at least one trip first.'; redirect('/'); }
    $dryRun = isset($_POST['dry_run']) || !XeroOAuth::isConnected();
    $result = LeonProcessor::createPosForIds($ids, $dryRun);
}

render_home($result);

// ====================================================================
function render_login(): void {
    $err = $_SESSION['flash_err'] ?? ''; unset($_SESSION['flash_err']);
    $noPass = cfg('app.admin_password_hash', '') === '';
    ?><!doctype html><html><head><meta charset="utf-8"><title>Sign in · Skyledger</title><?php styles(); ?></head>
    <body><div class="wrap narrow"><h1>Skyledger</h1><p class="muted">LEON → Xero PO</p>
    <?php if ($noPass): ?><p class="err">No admin password is set. Add <code>app.admin_password_hash</code> to config.php.</p><?php endif; ?>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" action="<?= e(base()) ?>/login"><label>Password</label>
    <input type="password" name="password" autofocus><button class="btn">Sign in</button></form></div></body></html><?php
}

function render_home(?array $result): void {
    $ok = $_SESSION['flash_ok'] ?? ''; $err = $_SESSION['flash_err'] ?? '';
    unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
    $connected = XeroOAuth::isConnected();
    $tenant   = $connected ? XeroOAuth::tenantName() : '';
    $tenantId = $connected ? (string)(XeroOAuth::token()['tenant_id'] ?? '') : '';
    $trips = TripRepo::all();
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Skyledger · LEON → Xero PO</title><?php styles(); ?></head><body><div class="wrap">
    <div class="topbar"><h1>Skyledger <span class="tag">Module 4 · LEON → PO</span></h1>
    <a class="muted" href="<?= e(base()) ?>/logout">Sign out</a></div>
    <?php if ($ok): ?><p class="ok"><?= e($ok) ?></p><?php endif; ?>
    <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

    <div class="grid">
      <section class="card">
        <h2>Xero connection</h2>
        <?php if ($connected): ?>
          <p class="ok">Connected: <strong><?= e($tenant) ?></strong></p>
          <p class="muted">POs create here. Connect a different org to switch — trips created in the old org re-create in the new one.</p>
          <div class="row">
            <a class="btn ghost" href="<?= e(base()) ?>/xero/connect">Reconnect / switch org</a>
            <form method="post" action="<?= e(base()) ?>/xero/disconnect" onsubmit="return confirm('Disconnect from Xero?')">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn ghost">Disconnect</button></form>
          </div>
        <?php else: ?>
          <p class="warn">Not connected — creating POs will dry-run only.</p>
          <a class="btn" href="<?= e(base()) ?>/xero/connect">Connect to Xero</a>
        <?php endif; ?>
      </section>

      <section class="card">
        <h2>Import LEON export</h2>
        <form method="post" action="<?= e(base()) ?>/import" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label>Flight Count file (CSV or PDF)</label>
          <input type="file" name="leon" accept=".csv,.pdf,text/csv,application/pdf" required>
          <label>Entity</label>
          <select name="entity"><option value="inc">Signum Aviation Inc</option><option value="ltd">Signum Aviation Ltd</option></select>
          <button class="btn">Import to master list</button>
        </form>
      </section>
    </div>

    <section class="card">
      <div class="topbar"><h2>Trip master list <span class="muted">(<?= count($trips) ?>)</span></h2></div>
      <?php if (!$trips): ?>
        <p class="muted">No trips yet. Import a LEON CSV or PDF above.</p>
      <?php else: ?>
      <form method="post" action="<?= e(base()) ?>/create-pos">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="bar">
          <label class="check"><input type="checkbox" id="all"> Select all</label>
          <label class="check"><input type="checkbox" name="dry_run" <?= $connected ? '' : 'checked disabled' ?>> Dry run</label>
          <button class="btn">Create draft POs for selected</button>
        </div>
        <table><thead><tr><th></th><th>Entity</th><th>Trip</th><th>Client</th><th>Aircraft</th><th>Route</th><th>Dates</th><th>Flights</th><th>PO status</th></tr></thead><tbody>
        <?php foreach ($trips as $t):
            $has = TripRepo::hasPoInTenant($t, $tenantId);
            $other = !$has && !empty($t['xero_po_id']);
            $st = $has ? ('PO ' . ($t['xero_po_number'] ?: 'created')) : ($other ? 'PO in other org' : '—');
            $cls = $has ? 'ok' : ($other ? 'warn' : 'muted');
        ?>
          <tr>
            <td><input type="checkbox" class="pick" name="trip_ids[]" value="<?= (int)$t['id'] ?>" <?= $has ? 'disabled' : '' ?>></td>
            <td><?= e(strtoupper($t['entity'])) ?></td><td><?= e($t['trip_number']) ?></td>
            <td><?= e($t['client_name'] ?: '—') ?></td><td><?= e($t['aircraft']) ?></td>
            <td class="rt"><?= e($t['route']) ?></td>
            <td class="nw"><?= e($t['start_date']) ?><?= $t['end_date'] && $t['end_date']!==$t['start_date'] ? ' → '.e($t['end_date']) : '' ?></td>
            <td><?= $t['flights_count'] === null ? '' : (int)$t['flights_count'] ?></td>
            <td class="<?= $cls ?>"><?= e($st) ?><?= !empty($t['xero_last_error']) ? ' <span class="err" title="'.e($t['xero_last_error']).'">!</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </form>
      <script>
        document.getElementById('all').addEventListener('change', function(){
          document.querySelectorAll('.pick:not([disabled])').forEach(c => c.checked = this.checked);
        });
      </script>
      <?php endif; ?>
    </section>

    <details class="card"><summary><b>Xero settings</b></summary>
      <form method="post" action="<?= e(base()) ?>/settings">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Client ID</label><input name="client_id" placeholder="<?= XeroOAuth::clientId() !== '' ? '•••• saved' : '' ?>">
        <label>Client Secret</label><input name="client_secret" type="password" placeholder="<?= XeroOAuth::clientSecret() !== '' ? '•••• saved' : '' ?>">
        <label>Redirect URI (register this in your Xero app)</label><input name="redirect_uri" value="<?= e(XeroOAuth::redirectUri()) ?>">
        <label>Scopes</label><input name="scopes" value="<?= e(XeroOAuth::scopes()) ?>">
        <div class="row2">
          <div><label>Inc currency (blank = org base)</label><input name="currency_inc" value="<?= e((string)Settings::get('currency.inc','')) ?>" placeholder="USD"></div>
          <div><label>Ltd currency (blank = org base)</label><input name="currency_ltd" value="<?= e((string)Settings::get('currency.ltd','')) ?>" placeholder="GBP"></div>
        </div>
        <label class="check"><input type="checkbox" name="enabled" <?= Settings::bool('xero.enabled') ? 'checked' : '' ?>> Enable live Xero pushes</label>
        <button class="btn">Save settings</button>
      </form>
    </details>

    <?php if ($result !== null) render_result($result); ?>
    </div></body></html><?php
}

function render_result(array $result): void {
    $s = $result['summary'];
    echo '<section class="card"><h2>Result</h2>';
    echo '<p class="muted">Org: ' . e($result['tenant'] !== '' ? $result['tenant'] : '(dry run — not connected)') . '</p>';
    echo '<p>selected <b>' . $s['selected'] . '</b> · created <b>' . $s['created'] . '</b> · skipped <b>' . $s['skipped']
       . '</b> · failed <b>' . $s['failed'] . '</b> · dry-run <b>' . $s['dry_run'] . '</b></p>';
    echo '<table><thead><tr><th>Status</th><th>Trip</th><th>Client</th><th>Message</th></tr></thead><tbody>';
    foreach ($result['rows'] as $r) {
        echo '<tr class="s-' . e($r['status']) . '"><td>' . e(strtoupper($r['status'])) . '</td><td>' . e($r['trip_number'])
           . '</td><td>' . e($r['client_name']) . '</td><td>' . e($r['message']) . '</td></tr>';
    }
    echo '</tbody></table></section>';
}

function styles(): void { ?><style>
:root{--bg:#0f1226;--card:#171a34;--ink:#e7e9f5;--mut:#9aa0c0;--acc:#5b7cff;}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--ink)}
.wrap{max-width:1100px;margin:32px auto;padding:0 18px}.narrow{max-width:380px}
h1{font-size:20px;margin:0}h2{font-size:16px;margin:0 0 10px}.tag{font-size:11px;background:#2a2f57;color:#aeb6ff;padding:2px 8px;border-radius:20px;margin-left:6px;font-weight:400}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:760px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid #262a4d;border-radius:12px;padding:16px;margin-bottom:16px}
label{display:block;font-size:12px;color:var(--mut);margin:10px 0 4px}.check{display:inline-flex;gap:8px;align-items:center;margin:0}
input,select{width:100%;padding:9px 10px;background:#0e1024;border:1px solid #2b3059;border-radius:8px;color:var(--ink)}
input[type=checkbox]{width:auto}.row{display:flex;gap:8px;margin-top:12px}.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.bar{display:flex;gap:18px;align-items:center;margin:6px 0 12px;flex-wrap:wrap}
.btn{display:inline-block;margin-top:0;background:var(--acc);color:#fff;border:0;padding:9px 14px;border-radius:8px;cursor:pointer;text-decoration:none;font-size:14px}
.btn.ghost{background:transparent;border:1px solid #3a3f6b;color:var(--ink)}
.muted{color:var(--mut)}.ok{color:#7ff0b0}.err{color:#ff9a9d}.warn{color:#f0cf7a}a.muted{text-decoration:none}
table{width:100%;border-collapse:collapse;margin-top:6px;font-size:13px}th,td{text-align:left;padding:7px 8px;border-bottom:1px solid #262a4d;vertical-align:top}
td.rt{font-family:ui-monospace,monospace;font-size:12px;color:#b9c0e6;max-width:360px}td.nw{white-space:nowrap}
tr.s-created td:first-child{color:#7ff0b0}tr.s-failed td:first-child{color:#ff9a9d}tr.s-skipped td:first-child{color:#f0cf7a}tr.s-dry_run td:first-child{color:#aeb6ff}
summary{cursor:pointer}
</style><?php }
