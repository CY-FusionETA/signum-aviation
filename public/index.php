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
use App\Service\Auth\AccessLog;
use App\Service\Auth\Users;
use App\Repo\InvoiceRepo;
use App\Service\Leon\LeonProcessor;
use App\Service\Bills\BillReconciler;
use App\Service\Invoices\InvoiceService;
use App\Service\Invoices\CompletenessChecker;
use App\Service\Xero\XeroOAuth;
use App\Service\Inbox\InboxLog;
use App\Service\Inbox\DropStore;
use App\Service\Inbox\DuplicateBill;

session_start();

$base = rtrim((string)parse_url((string)cfg('app.base_url', ''), PHP_URL_PATH), '/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
$path = '/' . ltrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function redirect(string $to): void { header('Location: ' . base() . $to); exit; }
function base(): string { return rtrim((string)parse_url((string)cfg('app.base_url',''), PHP_URL_PATH), '/'); }
/** Absolute origin (https://host…) for building public URLs like the file-drop link. */
function abs_base(): string {
    $b = (string)cfg('app.base_url', '');
    if (preg_match('#^https?://#', $b)) return rtrim($b, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base();
}
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Bad CSRF token.'); } }
function is_authed(): bool { return !empty($_SESSION['authed']); }

/** Legacy single-admin identity, kept as a fallback for un-migrated installs. */
function admin_email(): string { return strtolower(trim((string)Settings::get('auth.email', cfg('app.admin_email', '')))); }
function admin_configured(): bool { return Users::count() > 0 || (admin_email() !== '' && (string)Settings::get('auth.password_hash', cfg('app.admin_password_hash', '')) !== ''); }
/** One-click sign-in on the login page. Bypasses the password — see /login/quick. */
function quick_login_enabled(): bool { return (string)Settings::get('auth.quick_login', '0') === '1'; }

/**
 * Deep link to a bill in Xero. Routing through organisationlogin with the org's
 * short code opens it in the org the bill actually lives in, rather than whichever
 * one that browser last had open. Without a short code (never refreshed since the
 * upgrade) fall back to the plain link — Xero still resolves it, just in the
 * current org. Returns '' when there is nothing to link to.
 */
function xero_bill_url(string $invoiceId): string
{
    if ($invoiceId === '') return '';
    $view  = '/AccountsPayable/View.aspx?InvoiceID=' . rawurlencode($invoiceId);
    $short = trim((string)Settings::get('xero.short_code', ''));
    return $short === ''
        ? 'https://go.xero.com' . $view
        : 'https://go.xero.com/organisationlogin/default.aspx?shortcode=' . rawurlencode($short)
          . '&redirecturl=' . rawurlencode($view);
}

/** A stored UTC timestamp shown in the app's own timezone; '' stays em-dash-able. */
function local_dt(?string $utc, string $fmt = 'd M Y H:i'): string
{
    $utc = trim((string)$utc);
    if ($utc === '') return '';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($fmt);
    } catch (\Throwable $e) {
        return '';
    }
}

/** Who is signed in right now, and what they may see. */
function current_email(): string { return (string)($_SESSION['email'] ?? ''); }
function current_role(): string  { return (string)($_SESSION['role'] ?? Users::USER); }
function can_view_access_log(): bool { return is_authed() && Users::canViewAccessLog(current_role()); }

/** Start the session for a verified user row. */
function sign_in(array $user): void {
    session_regenerate_id(true);          // new session id on privilege change
    $_SESSION['authed'] = true;
    $_SESSION['email']  = (string)$user['email'];
    $_SESSION['role']   = (string)$user['role'];
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
    $user = Users::check((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($user) {
        AccessLog::record((string)$user['email'], 'success');
        sign_in($user);
        redirect('/');
    }
    AccessLog::record((string)($_POST['email'] ?? ''), 'failed');
    $_SESSION['flash_err'] = 'Wrong email or password.'; redirect('/login');
}
// One-click sign-in for the demo account, for convenience on a trusted machine.
// NOTE: while this is on, ANYONE who loads /login can click through and get in —
// it is a deliberate bypass of the password. Turn it off with:
//   php cli/quick-login.php off
// It can only ever sign in a non-superadmin: a public password bypass must not
// reach the access log. See Users::canQuickLogin().
if ($path === '/login/quick' && $method === 'POST') {
    csrf_check();
    $demo = Users::quickLoginUser();
    if (quick_login_enabled() && Users::canQuickLogin($demo)) {
        AccessLog::record((string)$demo['email'], 'success');
        sign_in($demo);
        redirect('/');
    }
    AccessLog::record((string)($demo['email'] ?? ''), 'failed');
    $_SESSION['flash_err'] = 'Quick sign-in is disabled.'; redirect('/login');
}
if ($path === '/logout') { session_destroy(); redirect('/login'); }
if ($path === '/login') { render_login(); exit; }

// --- public file-drop (Gmail intake → Wazzup) -----------------------
// The Gmail script POSTs an attachment here (guarded by a shared key) and gets a
// short-lived public URL back; Wazzup then fetches that URL to send the file to
// the OCR service over WhatsApp. No session — these run before the auth gate.
if ($path === '/drop' && $method === 'POST') {
    $key = (string)(Settings::get('drop.key', '') ?: cfg('drop.key', ''));
    if ($key === '' || !hash_equals($key, (string)($_POST['key'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
    $f = $_FILES['file'] ?? null;
    if (!$f || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK) { http_response_code(400); exit('No file'); }
    if ((int)($f['size'] ?? 0) > 10 * 1024 * 1024) { http_response_code(413); exit('File too large (max 10MB).'); }
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','png','jpg','jpeg','webp','tif','tiff'], true)) { http_response_code(415); exit('Unsupported file type.'); }
    DropStore::purge();                                   // expire anything past its window
    $token = DropStore::newToken($ext);
    if (!move_uploaded_file((string)$f['tmp_name'], DropStore::path($token))) { http_response_code(500); exit('Could not store file.'); }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'url' => abs_base() . '/drop/' . $token]);
    exit;
}
if ($method === 'GET' && preg_match('#^/drop/([a-f0-9]{32}\.(?:pdf|png|jpe?g|webp|tif|tiff))$#', $path, $dm)) {
    $file = STORAGE_ROOT . '/drop/' . $dm[1];
    if (!is_file($file)) { http_response_code(404); exit('Not found'); }
    $types = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','tif'=>'image/tiff','tiff'=>'image/tiff'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// --- Module 1: Gmail-intake execution log ("Inbox") -----------------
// Both endpoints are called by external services (the Apps Script poller and
// Wazzup), so they sit before the sign-in gate and authenticate with the same
// drop.key shared secret the file-drop already uses.
if ($path === '/inbox/log' && $method === 'POST') {
    $key   = (string)(Settings::get('drop.key', '') ?: cfg('drop.key', ''));
    $given = (string)($_POST['key'] ?? ($_GET['key'] ?? ''));
    if ($key === '' || !hash_equals($key, $given)) { http_response_code(403); exit('Forbidden'); }
    // The poller ran — and says which Code.gs it is running, so the Inbox can
    // flag an Apps Script project still on an older paste.
    InboxLog::heartbeat((string)($_POST['version'] ?? ''));
    if (empty($_POST['heartbeat'])) {                       // …and it sent an attachment
        $billId = (string)($_POST['bill_id'] ?? '');
        InboxLog::recordDelivery([
            'event_at'       => (string)($_POST['event_at'] ?? ''),
            'sender'         => (string)($_POST['sender'] ?? ''),
            'subject'        => (string)($_POST['subject'] ?? ''),
            'attachment'     => (string)($_POST['attachment'] ?? ''),
            'att_size'       => (int)($_POST['size'] ?? 0),
            'delivery'       => (string)($_POST['status'] ?? ''),
            'delivery_error' => (string)($_POST['error'] ?? ''),
            // Which file-drop copy this was, so Unidash can send it again itself.
            'drop_url'       => (string)($_POST['drop_url'] ?? ''),
            // Synchronous WazzOCR result (External API path): the outcome + the
            // Xero bill it created, recorded straight away — no reply webhook needed.
            'result'         => (string)($_POST['result'] ?? ''),
            'ocr_message'    => (string)($_POST['message'] ?? ''),
            'bill_url'       => $billId !== '' ? xero_bill_url($billId) : '',
            'bill_number'    => (string)($_POST['bill_number'] ?? ''),
            // Set on the fresh bill made after a duplicate was auto-cleared, so the
            // row shows "Duplicate invoice detected, auto deleted old copy."
            'dup_note'       => (string)($_POST['dup_note'] ?? ''),
        ]);
    }
    header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
}
// External-API duplicate recovery. WazzOCR reports the invoice number is already on
// a Xero bill (bills[0].status = "duplicate"), so it made nothing. Only Unidash holds
// the Xero connection, so the Apps Script asks it here to delete that leftover DRAFT;
// on success the Script re-sends the same PDF — a fresh bill under the now-free number —
// and logs it via /inbox/log with dup_note set. drop.key auth, before the sign-in gate.
if ($path === '/inbox/clear-duplicate' && $method === 'POST') {
    $key   = (string)(Settings::get('drop.key', '') ?: cfg('drop.key', ''));
    $given = (string)($_POST['key'] ?? ($_GET['key'] ?? ''));
    if ($key === '' || !hash_equals($key, $given)) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: application/json');
    echo json_encode(DuplicateBill::clearByNumber((string)($_POST['bill_number'] ?? '')));
    exit;
}
// AI prompt add-on. The poller fetches the operator's enabled prompt blocks here
// and sends them to WazzOCR as the per-upload `aiPrompt` field on every request.
// drop.key auth, before the sign-in gate (the Apps Script is unauthenticated).
if ($path === '/inbox/ai-prompt' && $method === 'GET') {
    $key   = (string)(Settings::get('drop.key', '') ?: cfg('drop.key', ''));
    $given = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals($key, $given)) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: application/json');
    echo json_encode(['prompt' => \App\Service\Wazz\AiPrompt::combined()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/wazzup/webhook' && $method === 'POST') {
    $key   = (string)(Settings::get('drop.key', '') ?: cfg('drop.key', ''));
    $given = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals($key, $given)) { http_response_code(403); exit('Forbidden'); }
    $data = json_decode((string)file_get_contents('php://input'), true);
    // Wazzup verifies the URL with a {"test":true} POST — it must get HTTP 200.
    if (is_array($data) && !empty($data['test'])) { http_response_code(200); echo 'ok'; exit; }
    // Capture is content-based: recordReply() only acts on an actual bill result
    // (created / already-exists / error) and ignores progress pings and chatter,
    // so it doesn't depend on which number the processor happens to reply from.
    $duplicates = [];
    foreach ((array)($data['messages'] ?? []) as $m) {
        if (!is_array($m) || !empty($m['isEcho'])) continue;             // skip our own/outgoing
        if (strtolower((string)($m['status'] ?? '')) !== 'inbound') continue; // received only
        $chatId = preg_replace('/\D+/', '', (string)($m['chatId'] ?? ''));
        $res = InboxLog::recordReply(
            (string)($m['messageId'] ?? ''),
            (string)($m['text'] ?? ''),
            (string)($m['contact']['name'] ?? $chatId),
            (string)($m['dateTime'] ?? '')
        );
        if (!empty($res['duplicate'])) $duplicates[] = (int)$res['row_id'];
    }
    // Answer Wazzup first: clearing a duplicate calls Xero and sends the file back
    // over WhatsApp, which takes far longer than a webhook should hold the line.
    http_response_code(200); echo 'ok';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach ($duplicates as $rowId) DuplicateBill::autoResolve($rowId);
    exit;
}

if (!is_authed()) redirect('/login');

// --- "AI at work" live feed (dashboard) -----------------------------
// Real activity — Inbox deliveries and LEON trips — reasoned into steps the
// dashboard animates. Read-only JSON, polled by the panel every few seconds.
if ($path === '/activity/feed' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'pulse'  => \App\Service\Activity\ActivityFeed::pulse(),
        'events' => \App\Service\Activity\ActivityFeed::recent(30),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
// Save the AI prompt add-on blocks (Settings → AI prompt add-on). Rows arrive as
// parallel arrays keyed by row index; a checkbox is only present when ticked.
if ($path === '/settings/ai-prompt' && $method === 'POST') {
    csrf_check();
    $titles  = (array)($_POST['p_title'] ?? []);
    $bodies  = (array)($_POST['p_body'] ?? []);
    $enabled = (array)($_POST['p_enabled'] ?? []);
    $blocks = [];
    foreach ($bodies as $i => $body) {
        $blocks[] = [
            'title'   => (string)($titles[$i] ?? ''),
            'body'    => (string)$body,
            'enabled' => isset($enabled[$i]),
        ];
    }
    \App\Service\Wazz\AiPrompt::save($blocks);
    $_SESSION['flash_ok'] = 'AI prompt saved — it will be sent with every invoice from now on.';
    redirect('/?view=settings');
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

// --- blank LEON import template (same columns the parser reads) -----
if ($path === '/import/template') {
    // Header names match the LEON "Flight Count" export; the parser maps by
    // header name, so the column order here is a convenience, not a contract.
    // Header only — no sample row, so an unedited template imports nothing.
    $rows = [
        ['Start date', 'End date', 'Trip number', 'Client name', 'Aircraft', 'Route ICAO', 'Flights count'],
    ];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leon-import-template.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                 // BOM so Excel keeps the dd-mm-yyyy text
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
    fclose($out);
    exit;
}

// --- import one or more LEON exports into the master list -----------
if ($path === '/import' && $method === 'POST') {
    csrf_check();
    $entitySel = strtolower($_POST['entity'] ?? 'auto');
    $files = normalize_files($_FILES['leon'] ?? null);
    if (!$files) { $_SESSION['flash_err'] = 'Choose one or more LEON files (CSV, XLSX or PDF).'; redirect('/?view=trips'); }

    $dir = STORAGE_ROOT . '/uploads'; @mkdir($dir, 0770, true);
    $tot = ['files' => 0, 'parsed' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0];
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
            $tot['files']++; $tot['parsed'] += $imp['parsed'];
            $tot['new'] += $imp['new']; $tot['updated'] += $imp['updated']; $tot['unchanged'] += $imp['unchanged'];
        } catch (\Throwable $e) {
            $errs[] = $name . ': ' . $e->getMessage();
        }
    }
    if ($tot['files']) {
        // Only the rows that needed it are re-filed; unchanged trips are left as they were.
        $touched = $tot['new'] + $tot['updated'];
        $_SESSION['flash_ok'] = $touched > 0
            ? "Imported {$tot['files']} file(s) · {$tot['new']} new, {$tot['updated']} updated"
              . ($tot['unchanged'] ? ", {$tot['unchanged']} unchanged (left as they were)" : '') . '.'
            : "Imported {$tot['files']} file(s) · nothing changed — all {$tot['unchanged']} trips already up to date.";
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
        $_SESSION['flash_ok'] = "Pulled {$s['pulled']} bill(s): {$s['auto_tagged']} auto-tagged, "
            . ($s['ambiguous'] + $s['review']) . " need a trip keyed in by hand.";
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
    $billId  = (int)($_POST['id'] ?? 0);
    $tripId  = (int)($_POST['trip_id'] ?? 0);
    $tripNum = trim((string)($_POST['trip_number'] ?? ''));
    // Manual key-in: resolve the typed trip number against the LEON master list.
    if ($tripId === 0 && $tripNum !== '') {
        $t = TripRepo::findByNumber($tripNum);
        if (!$t) {
            $_SESSION['flash_err'] = "No trip “{$tripNum}” in the LEON master list — check the number.";
            redirect('/?view=bills');
        }
        $tripId = (int)$t['id'];
    }
    $res = BillReconciler::assign($billId, $tripId);
    $_SESSION[!empty($res['ok']) ? 'flash_ok' : 'flash_err'] = !empty($res['ok'])
        ? 'Trip number keyed in and pushed to the bill in Xero.'
        : 'Manual tag failed: ' . ($res['error'] ?? 'unknown');
    redirect('/?view=bills');
}
if ($path === '/trips/approve' && $method === 'POST') {
    csrf_check();
    $force = !empty($_POST['force']);
    $res = BillReconciler::approveTrip((int)($_POST['trip_id'] ?? 0), $force);
    if (empty($res['ok']) && empty($res['approved'])) {
        $_SESSION['flash_err'] = 'Approve failed: ' . ($res['error'] ?? 'unknown');
    } elseif (!empty($res['invoiced'])) {
        $_SESSION['flash_ok'] = "Approved {$res['approved']} bill(s) — draft client invoice " . ($res['invoice_number'] ?: '')
            . ($force ? ' raised for the partial trip' : ' created') . ' in Xero.';
    } else {
        $msg = "Approved {$res['approved']} bill(s).";
        if (!empty($res['failed'])) $msg .= " {$res['failed']} failed: " . ($res['error'] ?? '');
        elseif (!empty($res['reason'])) $msg .= ' Invoice held — ' . $res['reason'] . '.';
        $_SESSION[!empty($res['failed']) ? 'flash_err' : 'flash_ok'] = $msg;
    }
    redirect('/?view=trips');
}
if ($path === '/trips/waive' && $method === 'POST') {
    csrf_check();
    $legs = TripRepo::toggleWaivedLeg((int)($_POST['trip_id'] ?? 0), (string)($_POST['airport'] ?? ''));
    $_SESSION['flash_ok'] = $legs ? 'Legs with no bill expected: ' . implode(', ', $legs) . '.' : 'No legs waived for this trip.';
    redirect('/?view=trips');
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

$view = in_array($_GET['view'] ?? '', ['dashboard', 'trips', 'bills', 'invoices', 'inbox', 'settings', 'access'], true) ? $_GET['view'] : 'dashboard';
// The access log is superadmin-only. Anyone else asking for it by URL gets the
// dashboard — the link is hidden for them, so a hand-typed ?view=access is the
// only way to land here.
if ($view === 'access' && !can_view_access_log()) $view = 'dashboard';
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
        'lock'      => '<rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
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
      <?php $demo = Users::quickLoginUser(); if (quick_login_enabled() && Users::canQuickLogin($demo)): ?>
        <div class="quickrow"><span>or</span></div>
        <form method="post" action="<?= e(base()) ?>/login/quick" id="quickForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn block quick" type="submit" id="quickBtn">
            <span class="av">D</span>
            Continue as Demo
          </button>
        </form>
        <script>
          // Show the credentials as "filled in" for feedback, then sign in server-side.
          // The real password is never sent to the browser.
          document.getElementById('quickBtn').addEventListener('click', function () {
            document.getElementById('f_email').value = <?= json_encode((string)$demo['email']) ?>;
            document.getElementById('f_pass').value  = '••••••••••••';
          });
        </script>
      <?php endif; ?>
      <a href="<?= e(base()) ?>/training.html" style="display:block;text-align:center;margin-top:18px;padding:11px;border:1px solid var(--line,#e2e8f0);border-radius:10px;color:#5b53f0;text-decoration:none;font-weight:600;font-size:13.5px">✨ First time here? Take the guided tour →</a>
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
    $invByTrip   = [];
    if ($connected) {
        foreach (BillRepo::allForTenant($tenantId) as $b) {
            if (!in_array((string)$b['match_status'], ['matched', 'tagged', 'approved'], true)) continue;
            $tid = (int)$b['matched_trip_id'];
            if ($tid) $billsByTrip[$tid][] = $b;
        }
        foreach (InvoiceRepo::allForTenant($tenantId) as $iv) {
            $invByTrip[(int)$iv['trip_id']] = $iv;
        }
    }

    $js = [];
    $stat = ['total' => count($trips), 'matched' => 0, 'ready' => 0, 'inc' => 0, 'ltd' => 0];
    foreach ($trips as $t) {
        $tbills = $billsByTrip[(int)$t['id']] ?? [];
        $count  = count($tbills);
        $cp     = CompletenessChecker::check($t, $tbills);
        $cost   = $count === 0 ? 'none' : ($cp['status'] === 'complete' ? 'ready' : 'partial');
        $approved = $count > 0 && array_reduce($tbills, fn($c, $b) => $c && (string)$b['match_status'] === 'approved', true);
        $inv    = $invByTrip[(int)$t['id']] ?? null;
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
            'legs' => (int)$cp['legs'], 'covered' => count($cp['covered']) + count($cp['waived'] ?? []),
            'missing' => implode(', ', $cp['missing']),
            'missing_legs' => array_values($cp['missing']), 'waived_legs' => array_values($cp['waived'] ?? []),
            'approved' => $approved, 'invoiced' => $inv ? true : false,
            'invoice_number' => $inv ? (string)$inv['xero_invoice_number'] : '',
            'created' => (string)($t['created_at'] ?? ''), 'updated' => (string)($t['updated_at'] ?? ''),
        ];
    }
    $titles = ['dashboard' => 'Dashboard', 'trips' => 'Trips', 'bills' => 'Bills', 'invoices' => 'Invoices', 'inbox' => 'Inbox', 'settings' => 'Settings', 'access' => 'Access log'];
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
        <a href="<?= e(base()) ?>/?view=inbox" class="<?= $view==='inbox'?'active':'' ?>"><?= icon('inbox') ?><span class="lbl">Inbox</span></a>
        <a href="<?= e(base()) ?>/?view=settings" class="<?= $view==='settings'?'active':'' ?>"><?= icon('settings') ?><span class="lbl">Settings</span></a>
        <?php if (can_view_access_log()): ?>
        <a href="<?= e(base()) ?>/?view=access" class="<?= $view==='access'?'active':'' ?>"><?= icon('lock') ?><span class="lbl">Access log</span></a>
        <?php endif; ?>
      </nav>
      <div class="suser">
        <span class="av"><?= e($initial) ?></span>
        <span class="uinfo"><b>Signum Aviation</b><small><?= e($email) ?></small></span>
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
          elseif ($view === 'inbox')     render_inbox();
          elseif ($view === 'settings')  render_settings($connected, $tenant);
          elseif ($view === 'access')    render_access_log();
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
    <script><?= ai_js() ?></script>
    <script><?= dash_js() ?></script>
    </body></html><?php
}

function render_access_log(): void {
    $s    = AccessLog::stats();
    $rows = AccessLog::rows(200);
    ?>
    <section class="tiles" style="grid-template-columns:repeat(5,1fr)">
      <div class="tile"><div class="tnum"><?= $s['day'] ?></div><div class="tlbl">Sign-ins (24h)</div></div>
      <div class="tile"><div class="tnum"><?= $s['total'] ?></div><div class="tlbl">Total recorded</div></div>
      <div class="tile"><div class="tnum"><?= $s['accounts'] ?></div><div class="tlbl">Distinct accounts</div></div>
      <div class="tile"><div class="tnum"><?= $s['ips'] ?></div><div class="tlbl">Distinct IPs</div></div>
      <div class="tile"><div class="tnum"<?= $s['failed'] ? ' style="color:#b42318"' : '' ?>><?= $s['failed'] ?></div><div class="tlbl">Failed attempts</div></div>
    </section>
    <div class="card">
      <p class="muted" style="margin:0 0 12px">Every sign-in — account, place, device. Visible to you only · Malaysia time (UTC+8).</p>
      <table class="grid"><thead><tr><th>When</th><th>Account</th><th>Result</th><th>IP address</th><th>Location</th><th>Device</th></tr></thead><tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted">No sign-ins recorded yet — they appear here as people sign in.</td></tr>
      <?php else: foreach ($rows as $r):
        $d   = AccessLog::device((string)$r['user_agent']);
        $loc = trim(((string)$r['city']) . ((($r['city'] ?? '') !== '' && ($r['country'] ?? '') !== '') ? ', ' : '') . (string)$r['country']);
      ?>
        <tr>
          <td class="nowrap mono"><?= e(local_dt($r['ts'] ?? '', 'd M Y') ?: '—') ?><div class="muted small"><?= e(local_dt($r['ts'] ?? '', 'H:i:s')) ?></div></td>
          <td><?= ($r['email'] ?? '') !== '' ? e((string)$r['email']) : '<span class="muted">—</span>' ?></td>
          <td><span class="pill <?= (string)$r['result'] === 'success' ? 'green' : 'amber' ?>"><?= e((string)$r['result']) ?></span></td>
          <td class="mono"><?= e((string)$r['ip']) ?></td>
          <td><?= $loc !== '' ? e($loc) : '<span class="muted">—</span>' ?><?= ($r['isp'] ?? '') !== '' ? '<div class="muted small">' . e((string)$r['isp']) . '</div>' : '' ?></td>
          <td class="nowrap"><?= e($d['browser'] . ' · ' . $d['os']) ?><div class="muted small"><?= e($d['kind']) ?></div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div>
    <?php
}

function render_inbox(): void {
    $s    = InboxLog::stats();
    $rows = InboxLog::rows(200);
    $last = local_dt(InboxLog::lastRun(), 'd M Y H:i');
    $script = InboxLog::scriptStatus();
    $dpill = function (string $d): string {
        if ($d === 'sent')    return '<span class="pill green">Sent</span>';
        if ($d === 'failed')  return '<span class="pill red">Failed</span>';
        // Nothing was sent and nothing went wrong with the mailbox — the email
        // just had no PDF on it. The Message column says which.
        if ($d === 'skipped') return '<span class="pill amber" title="This email arrived but carried nothing the processor could read">Not sent</span>';
        return '<span class="muted">—</span>';
    };
    // The bill a cleared duplicate ended up producing lives on its re-send row, so
    // the original can still link to it.
    $retryBill = [];
    foreach ($rows as $rr) {
        $of = (int)($rr['retry_of'] ?? 0);
        if ($of && (string)($rr['ocr_status'] ?? '') === 'success') {
            $retryBill[$of] = ['url' => (string)($rr['bill_url'] ?? ''), 'num' => (string)($rr['bill_number'] ?? '')];
        }
    }
    // Bill-created cell: Success (+ link to the bill in Xero) / Failed / still waiting.
    $ocell = function(array $r) use ($retryBill): string {
        $st = (string)($r['ocr_status'] ?? '');
        // A duplicate the app cleared on its own is a success: the stale copy is
        // gone and the invoice went back through the processor by itself.
        $cleared = $st === 'failed' && DuplicateBill::wasCleared($r);
        if ($st === 'success' || $cleared) {
            $bill = $cleared ? ($retryBill[(int)$r['id']] ?? ['url' => '', 'num' => '']) : ['url' => (string)($r['bill_url'] ?? ''), 'num' => (string)($r['bill_number'] ?? '')];
            $out  = '<span class="pill green" title="' . ($cleared
                ? 'The old copy was deleted in Xero and the invoice sent for processing again, automatically'
                : 'The processor created the draft bill in Xero') . '">Success</span>';
            $url = trim($bill['url']);
            $num = trim($bill['num']);
            if ($url !== '' && preg_match('~^https?://~i', $url)) {
                $out .= ' <a class="link" href="'.e($url).'" target="_blank" rel="noopener noreferrer" title="Open this bill in Xero">View <span class="extlink">↗</span></a>';
            } elseif ($num !== '') {
                $out .= ' <span class="muted small mono">'.e($num).'</span>';
            }
            return $out;
        }
        if ($st === 'failed')  return '<span class="pill red">Failed</span>';
        if ($st === 'pending') {
            // Past the match window the reply is never coming — say so rather than
            // leaving the row spinning on "waiting…" forever.
            return InboxLog::isStale((string)($r['event_at'] ?: ($r['ts'] ?? '')), (string)($r['ocr_message'] ?? ''))
                ? '<span class="pill gray" title="No result came back from the processor for this attachment">No reply</span>'
                : '<span class="muted small">waiting…</span>';
        }
        return '<span class="muted">—</span>';
    };
    ?>
    <section class="tiles" style="grid-template-columns:repeat(4,1fr)">
      <div class="tile"><div class="tnum"><?= $s['day'] ?></div><div class="tlbl">Sent (24h)</div></div>
      <div class="tile"><div class="tnum green"><?= $s['created'] ?></div><div class="tlbl">Bills created</div></div>
      <div class="tile"><div class="tnum"<?= $s['errors'] ? ' style="color:#b42318"' : '' ?>><?= $s['errors'] ?></div><div class="tlbl">Errors</div></div>
      <div class="tile"><div class="tnum"><?= $s['pending'] ?></div><div class="tlbl">Awaiting result</div></div>
    </section>
    <div class="card">
      <div class="chead">
        <h2>Mailbox activity</h2>
        <a class="btn ghost sm" href="<?= e(base()) ?>/?view=inbox" title="Reload the log — new sends and processor replies arrive continuously">Refresh</a>
      </div>
      <p class="muted" style="margin:0 0 12px">
        Every email that reaches the invoice mailbox — including the ones with no PDF to process, which say so in Message.
        Hover a message to read the full reply. Duplicates are cleared and re-sent automatically.
        <?= $last !== '' ? ' · <b>Last checked</b> ' . e($last) : '' ?> · times are Malaysia time (UTC+8).
      </p>
      <?php // The Gmail script lives in Apps Script, not in this repo — a deploy here does
            // not update it. It reports its own version on every heartbeat, so a project
            // still running an older paste can be named instead of guessed at.
            if ($last !== '' && !$script['current']): ?>
        <div class="empty" style="border-color:#fedf89;background:#fffaeb;color:#b54708;margin:0 0 12px">
          <b>The Gmail script is out of date.</b>
          The mailbox is being checked, but by
          <?= $script['known'] ? 'version ' . e($script['version']) : 'a Code.gs old enough that it does not report its version' ?>,
          not <?= e(InboxLog::EXPECTED_SCRIPT_VERSION) ?>. Newer behaviour — including logging emails that
          arrive with no PDF attached — stays off until <code>integrations/gmail-intake/Code.gs</code>
          is pasted into the Apps Script project and saved.
        </div>
      <?php endif; ?>
      <table class="grid"><thead><tr>
        <th>When</th><th>Sender</th><th>Attachment</th><th>Processed</th><th>Bill created</th><th>Message</th>
      </tr></thead><tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted">Nothing yet — every email that arrives in the invoice mailbox appears here.</td></tr>
      <?php else: foreach ($rows as $r):
        $when = local_dt($r['event_at'] ?: ($r['ts'] ?? ''), 'd M Y');
        $tm   = local_dt($r['event_at'] ?: ($r['ts'] ?? ''), 'H:i');
        // Message shows the problem, never the happy path: one plain-English line
        // saying what went wrong (the processor answers in long WhatsApp blocks),
        // with its whole reply on hover. A successful create leaves it blank.
        $err   = trim((string)($r['delivery_error'] ?? ''));
        $reply = trim((string)($r['ocr_message'] ?? ''));
        $msg   = InboxLog::plainMessage($r);
        $tip   = $err !== '' && $reply !== '' ? $err . "\n\n" . $reply : ($err !== '' ? $err : $reply);
        // What Unidash did about an "already in Xero" duplicate, in its own words.
        // Once it is cleared that detail is hover-only — the row reads as a success
        // and the one-line message is all there is to act on.
        $dupDone = DuplicateBill::note($r);
        $retryOf = (int)($r['retry_of'] ?? 0);
        if (DuplicateBill::wasCleared($r)) { $tip = $dupDone . ($tip !== '' ? "\n\n" . $tip : ''); $dupDone = ''; }
      ?>
        <tr>
          <td class="nowrap mono"><?= e($when ?: '—') ?><div class="muted small"><?= e($tm) ?></div></td>
          <td><?php $sn = trim((string)($r['sender'] ?? '')); if ($sn !== ''): ?><span class="trunc" style="max-width:210px" title="<?= e($sn) ?>"><?= e($sn) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td>
            <?php if (($r['attachment'] ?? '') !== ''): ?><span class="mono trunc" style="font-size:12px;max-width:230px" title="<?= e((string)$r['attachment']) ?>"><?= e((string)$r['attachment']) ?></span>
            <?php elseif ((string)($r['delivery'] ?? '') === 'skipped'): ?><span class="muted small">no attachment</span>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
            <?php if ($retryOf): ?><div class="muted small" title="Unidash cleared the duplicate bill in Xero and sent this file again by itself">↻ sent again automatically</div><?php endif; ?>
          </td>
          <td><?= $dpill((string)($r['delivery'] ?? '')) ?></td>
          <td><?= $ocell($r) ?></td>
          <td>
            <?php if ($msg !== ''): ?><span class="billdesc" style="max-width:340px" title="<?= e($tip !== '' ? $tip : $msg) ?>"><?= e(mb_strimwidth($msg, 0, 90, '…')) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?>
            <?php if ($dupDone !== ''): ?><div class="muted small" style="max-width:340px" title="<?= e($dupDone) ?>"><?= e(mb_strimwidth($dupDone, 0, 80, '…')) ?><?php
              // Repeat sends fold onto this row; say how many so a stuck retry is
              // visible without it filling the Inbox one line at a time.
              $tries = (int)($r['dup_attempts'] ?? 0);
              if ($tries > 1): ?> <span class="pill gray" title="Sent again automatically <?= $tries ?> times — the newest attempt is the one shown">×<?= $tries ?></span><?php endif; ?></div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div>
    <?php
}

function render_dashboard(array $stat, array $rows, bool $connected, string $tenant): void {
    $recent = array_slice($rows, 0, 8);
    $costPill = fn(string $c) => $c === 'ready'
        ? '<span class="pill green">Ready</span>'
        : ($c === 'partial' ? '<span class="pill amber">Partial</span>' : '<span class="pill gray">None</span>');

    // Share-of-total for the deck bars and the pipeline conversion rates.
    $total  = max(0, (int)$stat['total']);
    $share  = fn(int $n) => $total > 0 ? (int)round($n * 100 / $total) : 0;
    $mPct   = $share((int)$stat['matched']);
    $rPct   = $share((int)$stat['ready']);
    $incPct = $share((int)$stat['inc']);
    ?>
    <div class="dash">

    <!-- Command deck: the headline numbers, read as instrument telemetry. -->
    <section class="deck">
      <span class="deckgrid" aria-hidden="true"></span>
      <span class="deckglow" aria-hidden="true"></span>
      <span class="deckscan" aria-hidden="true"></span>
      <div class="deckbody">
        <div class="deckintro">
          <span class="eyebrow"><span class="ebdot"></span>Autonomous billing loop · live</span>
          <h2>Every trip, every supplier bill, every client invoice — reconciled without re-keying.</h2>
          <p>LEON files the trips. Gmail feeds the supplier invoices. Xero receives the bills and the recharge. Unidash runs the loop and shows its work.</p>
          <div class="deckchips">
            <span class="dchip on"><i></i>LEON</span>
            <span class="dchip on"><i></i>Gmail</span>
            <span class="dchip <?= $connected ? 'on' : 'off' ?>"><i></i>Xero<?= $connected ? ' · '.e($tenant) : ' · offline' ?></span>
          </div>
        </div>
        <div class="readouts">
          <div class="ro cy">
            <div class="rolbl">Trips in master list</div>
            <div class="ronum" data-count="<?= $total ?>">0</div>
            <div class="robar"><i style="width:100%"></i></div>
            <div class="rofoot">from LEON Flight Count</div>
          </div>
          <div class="ro vi">
            <div class="rolbl">Matched to bills</div>
            <div class="ronum" data-count="<?= (int)$stat['matched'] ?>">0</div>
            <div class="robar"><i style="width:<?= $mPct ?>%"></i></div>
            <div class="rofoot"><?= $mPct ?>% of trips<?= $connected ? ' · '.e($tenant) : '' ?></div>
          </div>
          <div class="ro em">
            <div class="rolbl">Ready to invoice</div>
            <div class="ronum" data-count="<?= (int)$stat['ready'] ?>">0</div>
            <div class="robar"><i style="width:<?= $rPct ?>%"></i></div>
            <div class="rofoot"><?= $rPct ?>% fully costed</div>
          </div>
          <div class="ro am">
            <div class="rolbl">By entity</div>
            <div class="ronum split"><span data-count="<?= (int)$stat['inc'] ?>">0</span><small>INC</small><b>/</b><span data-count="<?= (int)$stat['ltd'] ?>">0</span><small>LTD</small></div>
            <div class="robar dual"><i style="width:<?= $incPct ?>%"></i></div>
            <div class="rofoot">Inc <?= $incPct ?>% · Ltd <?= 100 - $incPct ?>%</div>
          </div>
        </div>
      </div>
    </section>

    <!-- The assistant's own console: reactor, phase rail, reasoning stream. -->
    <section class="card aiwork console" id="aiwork">
      <div class="chead">
        <h2><span class="aidot" id="aidot"></span> AI at work</h2>
        <div class="aiactions">
          <span class="aistate watching" id="aistate">Watching</span>
          <button class="btn ghost sm simbtn" type="button" id="aisim" title="Play a demo of how the assistant reads, reasons and acts on an invoice">▷ Simulate</button>
        </div>
      </div>
      <p class="ailede">What the assistant is doing right now — invoices read, calls made, trips filed. Press <b>Simulate</b> to watch one run end to end.</p>

      <div class="aicounts" id="aicounts">
        <div class="aic"><b data-pulse="sent">—</b><em>delivered · 24h</em></div>
        <div class="aic ok"><b data-pulse="bills">—</b><em>bills created</em></div>
        <div class="aic tr"><b data-pulse="trips">—</b><em>trips filed</em></div>
        <div class="aic er"><b data-pulse="errors">—</b><em>need review</em></div>
      </div>

      <div class="aigrid">
        <div class="aibrain" aria-hidden="true">
          <div class="reactor">
            <span class="rring r1"></span><span class="rring r2"></span><span class="rring r3"></span>
            <div class="aiorb" id="aiorb"><span></span><span></span><span></span><i>AI</i></div>
          </div>
          <div class="aiphases" id="aiphases">
            <span data-phase="read">Read</span>
            <span data-phase="think">Think</span>
            <span data-phase="decide">Decide</span>
            <span data-phase="do">Do</span>
          </div>
        </div>
        <ol class="aistream" id="aistream" aria-live="polite">
          <li class="aiempty">Waiting for activity…</li>
        </ol>
      </div>
    </section>

    <section class="card flowcard">
      <div class="chead"><h2>Billing pipeline</h2><span class="dim mono">trip → supplier bill → client invoice</span></div>
      <div class="flow">
        <div class="fnode">
          <span class="fic cy"><?= icon('trips') ?></span>
          <div class="fn" data-count="<?= $total ?>">0</div><div class="fl">Trips</div>
        </div>
        <div class="fwire"><i></i></div>
        <div class="fnode">
          <span class="fic vi"><?= icon('bills') ?></span>
          <div class="fn" data-count="<?= (int)$stat['matched'] ?>">0</div><div class="fl">Matched to bills</div>
        </div>
        <div class="fwire"><i></i></div>
        <div class="fnode">
          <span class="fic em"><?= icon('invoice') ?></span>
          <div class="fn" data-count="<?= (int)$stat['ready'] ?>">0</div><div class="fl">Ready to invoice</div>
        </div>
      </div>
      <div class="frates">
        <div class="frate"><span>Matched</span><div class="fbar"><i class="vi" style="width:<?= $mPct ?>%"></i></div><b><?= $mPct ?>%</b></div>
        <div class="frate"><span>Ready to invoice</span><div class="fbar"><i class="em" style="width:<?= $rPct ?>%"></i></div><b><?= $rPct ?>%</b></div>
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
          <div class="src"><span class="sig on"><i></i><i></i><i></i></span><b>LEON</b><span class="dim">Flight Count → trip master list</span></div>
          <div class="src"><span class="sig on"><i></i><i></i><i></i></span><b>Gmail</b><span class="dim">supplier invoices → Xero</span></div>
          <div class="src"><span class="sig <?= $connected?'on':'off' ?>"><i></i><i></i><i></i></span><b>Xero</b><span class="dim"><?= $connected ? e($tenant) : 'not connected' ?></span></div>
        </section>
      </div>
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
            <div class="muted small">CSV, XLSX or PDF</div>
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
          <span class="spacer"></span>
          <a class="btn ghost" href="<?= e(base()) ?>/import/template" download>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download template
          </a>
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
          <select id="fApprove" class="fsel"><option value="">Any approval</option><option value="notapproved">Not approved</option><option value="approved">Approved</option><option value="invoiced">Invoiced</option></select>
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
            <th>Approve</th>
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
    <p class="lede">Tagged trips become a draft <b>client invoice</b> — costs recharged ×<?= e(rtrim(rtrim(number_format((float)$cfg['markup'],2),'0'),'.')) ?>, plus <?= e(rtrim(rtrim(number_format((float)$cfg['admin_pct'],2),'0'),'.')) ?>% admin<?= $cfg['support_fee']>0 ? ' and a support fee' : '' ?>. Rates in <a class="link" href="<?= e(base()) ?>/?view=settings">Settings</a>.</p>

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
          // A leg marked "no bill expected" is accounted for just like a billed one —
          // count both, the same way the Trips tab does, or a fully waived trip reads 0/3.
          $nwv  = count($cp['waived'] ?? []);
          $done = count($cp['covered']) + $nwv;
          $wtip = $nwv ? ' ('.$nwv.' marked no bill expected)' : '';
          $legs = $cp['status']==='complete'
            ? '<span class="pill green" title="Every leg is accounted for'.e($wtip).'">'.$done.'/'.(int)$cp['legs'].'</span>'
            : ($cp['status']==='gaps'
                ? '<span class="pill amber" title="Missing a bill at: '.e(implode(', ', $cp['missing'])).e($wtip).'">'.$done.'/'.(int)$cp['legs'].'</span>'
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
    // AI prompt add-on: extraction rules sent to WazzOCR on every upload as the
    // per-request aiPrompt field. Folded list; Edit / Add opens a pop-out modal.
    $apBlocks = \App\Service\Wazz\AiPrompt::blocks();
    ?>
    <section class="card aiprompt">
      <div class="chead">
        <h2>AI prompt add-on</h2>
        <button type="button" class="btn primary sm" id="apadd">+ Add prompt</button>
      </div>
      <div id="aplist" class="aplist"></div>
    </section>

    <!-- Hidden form: Save/Delete in the modal persist the whole set through it. -->
    <form id="apform" method="post" action="<?= e(base()) ?>/settings/ai-prompt" hidden>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div id="apfields"></div>
    </form>

    <!-- Edit / add modal -->
    <div id="apmodal" class="modal" hidden>
      <div class="mback" data-apclose></div>
      <div class="mcard" role="dialog" aria-modal="true">
        <div class="mhead"><h3 id="apmtitle">Edit prompt block</h3><button class="mx" type="button" data-apclose aria-label="Close">×</button></div>
        <div class="mbody">
          <div class="apfield">
            <label class="aplbl" for="ap_title">TITLE</label>
            <input id="ap_title" class="apinput" placeholder="e.g. Currency">
          </div>
          <div class="apfield">
            <label class="aplbl" for="ap_body">PROMPT TEXT</label>
            <textarea id="ap_body" class="apinput" rows="9" placeholder="e.g. Always read the currency from the invoice header."></textarea>
          </div>
          <div class="apenable">
            <label class="chk"><input type="checkbox" id="ap_enabled"> Enabled</label>
          </div>
        </div>
        <div class="mfoot">
          <button type="button" class="btn danger" id="ap_delete">Delete</button>
          <button type="button" class="btn primary" id="ap_save">Save</button>
        </div>
      </div>
    </div>
    <style>
      .aiprompt .chead{display:flex;justify-content:space-between;align-items:center}
      .aplist{display:flex;flex-direction:column}
      .aprow2{display:grid;grid-template-columns:minmax(150px,230px) auto 1fr auto;align-items:center;gap:14px;padding:12px 2px;border-top:1px solid var(--line)}
      .aprow2:first-child{border-top:0}
      .aptitle{font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      /* Track: recessed, so the knob reads as sitting on top of it. */
      .apsw{position:relative;width:40px;height:23px;flex:none;padding:0;border:0;border-radius:999px;cursor:pointer;
background:linear-gradient(180deg,#d5dbe4,#c4ccd8);box-shadow:inset 0 1px 2.5px rgba(16,24,40,.22);
-webkit-tap-highlight-color:transparent;transition:background .22s cubic-bezier(.4,.2,.2,1),box-shadow .22s}
      .apsw:hover{background:linear-gradient(180deg,#c8cfda,#b6bfcd)}
      .apsw::after{content:"";position:absolute;top:2.5px;left:2.5px;width:18px;height:18px;border-radius:50%;background:#fff;
box-shadow:0 1px 1px rgba(16,24,40,.16),0 2px 4px rgba(16,24,40,.22);
transition:transform .22s cubic-bezier(.4,.2,.2,1),width .18s cubic-bezier(.4,.2,.2,1)}
      .apsw[aria-checked="true"]{background:linear-gradient(180deg,#22c55e,#16a34a);
box-shadow:inset 0 1px 2px rgba(6,78,36,.28),0 0 0 3px rgba(22,163,74,.10)}
      .apsw[aria-checked="true"]:hover{background:linear-gradient(180deg,#1eb455,#15803d)}
      .apsw[aria-checked="true"]::after{transform:translateX(17px)}
      /* Press squishes the knob toward the direction of travel. */
      .apsw:active::after{width:21px}
      .apsw[aria-checked="true"]:active::after{transform:translateX(14px)}
      .apsw:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
      .aprow2.off .aptitle,.aprow2.off .apprev{opacity:.55}
      .apprev{color:var(--mut);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .aplink{background:none;border:0;color:var(--accent);font-weight:600;cursor:pointer;font-size:13px;padding:4px 6px}
      .aplink:hover{text-decoration:underline}
      .apempty{padding:14px 2px;font-size:13px}
      .aplbl{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--mut)}
      .aplbl+.apinput{margin-top:6px}
      .apfield+.apfield{margin-top:14px}
      .apinput{width:100%;font:inherit;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink);transition:border-color .12s,box-shadow .12s}
      .apinput:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.14)}
      .apinput::placeholder{color:#9aa3b2}
      textarea.apinput{resize:vertical;line-height:1.5;min-height:120px}
      .apenable{margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
      .apenable .chk{white-space:normal}
      @media(max-width:640px){.aprow2{grid-template-columns:1fr auto;gap:6px 10px}.apprev{grid-column:1/-1;order:3}}
    </style>
    <script>
    (function(){
      var listEl = document.getElementById('aplist'); if(!listEl) return;
      var blocks = (<?= json_encode(array_values($apBlocks), JSON_UNESCAPED_UNICODE) ?>).map(function(b){
        return { title:String(b.title||''), body:String(b.body||''), enabled:!!b.enabled };
      });
      var modal=document.getElementById('apmodal');
      var tI=document.getElementById('ap_title'), bI=document.getElementById('ap_body'), eI=document.getElementById('ap_enabled');
      var delBtn=document.getElementById('ap_delete'), titleEl=document.getElementById('apmtitle');
      var editing=-1;
      function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
      function preview(s){s=String(s||'').replace(/\s+/g,' ').trim();return s.length>90?s.slice(0,90)+'…':s;}
      function render(){
        if(!blocks.length){ listEl.innerHTML='<div class="apempty muted">No prompts yet — click “Add prompt” to create one.</div>'; return; }
        listEl.innerHTML = blocks.map(function(b,i){
          return '<div class="aprow2'+(b.enabled?'':' off')+'">'
            + '<div class="aptitle">'+esc(b.title||'(untitled)')+'</div>'
            + '<button type="button" class="apsw" role="switch" data-toggle="'+i+'"'
            +   ' aria-checked="'+(b.enabled?'true':'false')+'"'
            +   ' title="'+(b.enabled?'Included in the prompt — click to turn off':'Not included — click to turn on')+'"'
            +   ' aria-label="Enable '+esc(b.title||'untitled')+'"></button>'
            + '<div class="apprev">'+esc(preview(b.body))+'</div>'
            + '<button type="button" class="aplink" data-edit="'+i+'">Edit</button>'
            + '</div>';
        }).join('');
      }
      function open(i){
        editing=i;
        var b = i>=0 ? blocks[i] : {title:'',body:'',enabled:true};
        titleEl.textContent = i>=0 ? 'Edit prompt block' : 'New prompt block';
        tI.value=b.title; bI.value=b.body; eI.checked=!!b.enabled;
        delBtn.style.display = i>=0 ? '' : 'none';
        modal.hidden=false; tI.focus();
      }
      function close(){ modal.hidden=true; editing=-1; }
      function persist(){
        var wrap=document.getElementById('apfields'); wrap.innerHTML='';
        blocks.forEach(function(b,i){
          var t=document.createElement('input'); t.type='hidden'; t.name='p_title['+i+']'; t.value=b.title; wrap.appendChild(t);
          var ta=document.createElement('textarea'); ta.name='p_body['+i+']'; ta.value=b.body; ta.hidden=true; wrap.appendChild(ta);
          if(b.enabled){ var en=document.createElement('input'); en.type='hidden'; en.name='p_enabled['+i+']'; en.value='1'; wrap.appendChild(en); }
        });
        document.getElementById('apform').submit();
      }
      document.getElementById('apadd').addEventListener('click', function(){ open(-1); });
      listEl.addEventListener('click', function(e){
        var t=e.target; if(!t||!t.dataset) return;
        if(t.dataset.edit!==undefined){ open(parseInt(t.dataset.edit,10)); return; }
        // Toggle straight from the list — flip it on screen, then save.
        if(t.dataset.toggle!==undefined){
          var i=parseInt(t.dataset.toggle,10); if(!blocks[i]) return;
          blocks[i].enabled=!blocks[i].enabled;
          render(); persist();
        }
      });
      document.getElementById('ap_save').addEventListener('click', function(){
        var b={ title:tI.value.trim(), body:bI.value, enabled:eI.checked };
        if(b.body.trim()===''){ if(editing<0){ close(); return; } blocks.splice(editing,1); persist(); return; }
        if(editing>=0) blocks[editing]=b; else blocks.push(b);
        persist();
      });
      delBtn.addEventListener('click', function(){ if(editing>=0){ blocks.splice(editing,1); persist(); } });
      Array.prototype.forEach.call(modal.querySelectorAll('[data-apclose]'), function(x){ x.addEventListener('click', close); });
      document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !modal.hidden) close(); });
      render();
    })();
    </script>
    <?php
}

function render_bills(bool $connected, string $tenant, string $tenantId): void {
    $bills = $connected ? BillRepo::allForTenant($tenantId) : [];
    $allTrips = TripRepo::all();
    $c = ['pulled'=>count($bills),'matched'=>0,'tagged'=>0,'approved'=>0,'ambiguous'=>0,'review'=>0];
    foreach ($bills as $b) { $s=(string)$b['match_status']; if(isset($c[$s]))$c[$s]++; }
    $bpill = function(string $s, string $why = ''): string {
        $m = ['approved'=>['green','Approved'],'tagged'=>['blue','Tagged'],'matched'=>['blue','Matched'],'ambiguous'=>['amber','Ambiguous'],'review'=>['gray','Review']];
        [$cl,$t] = $m[$s] ?? ['gray', ucfirst($s)];
        // A bill the matcher could not place needs someone to key a trip number in.
        // Gray "Review" reads as "nothing to do here", so say it in red instead.
        if ($why !== '' && $s === 'review') { $cl = 'red'; $t = "Can't match"; }
        return '<span class="pill '.$cl.'">'.e($t).'</span>';
    };
    // The bill's own status in Xero (distinct from the tag/approve workflow above).
    $xpill = function(string $s): string {
        $m = ['DRAFT'=>['gray','Draft'],'SUBMITTED'=>['amber','Awaiting approval'],'AUTHORISED'=>['blue','Awaiting payment'],'PAID'=>['green','Paid']];
        [$cl,$t] = $m[strtoupper($s)] ?? ['gray', $s !== '' ? ucfirst(strtolower($s)) : '—'];
        return '<span class="pill '.$cl.'">'.e($t).'</span>';
    };
    ?>
    <div class="banner <?= $connected ? 'on' : 'off' ?>">
      <div class="binfo"><span class="dot"></span>
        <div><?= $connected ? '<b>Reading '.e($tenant).'</b>' : '<b>Not connected to Xero</b> <span class="muted">· connect an org to pull bills</span>' ?></div>
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
          <span class="chip red"><?= $c['ambiguous'] + $c['review'] ?> can't match</span>
        </div>
      </div>

      <?php if (!$connected): ?>
        <div class="empty">Connect Xero in <a href="<?= e(base()) ?>/?view=settings">Settings</a>, then <b>Refresh from Xero</b> to pull draft bills.</div>
      <?php elseif (!$bills): ?>
        <div class="empty">No bills pulled yet. Click <b>Refresh from Xero</b> to fetch draft supplier bills and match them to trips.</div>
      <?php else: ?>
        <div class="toolbar">
          <select id="fBillStatus" class="fsel">
            <option value="">All statuses</option>
            <option value="DRAFT">Draft</option>
            <option value="SUBMITTED">Awaiting approval</option>
            <option value="AUTHORISED">Awaiting payment</option>
            <option value="PAID">Paid</option>
          </select>
          <span class="spacer"></span>
          <?php if ($c['matched']): ?>
          <span class="muted"><?= $c['matched'] ?> matched bill(s) ready to tag</span>
          <form method="post" action="<?= e(base()) ?>/bills/tag-all" onsubmit="return confirm('Tag all <?= $c['matched'] ?> matched bill(s) with their trip number in Xero?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn primary sm">Tag all matched</button></form>
          <?php endif; ?>
        </div>
        <datalist id="tripnums"><?php foreach ($allTrips as $t): ?><option value="<?= e($t['trip_number']) ?>"><?= e(($t['client_name'] ?: 'no client').' · '.$t['aircraft']) ?></option><?php endforeach; ?></datalist>
        <div class="tablewrap">
        <table class="grid"><thead><tr>
          <th>Created in Xero</th><th>Supplier</th><th>Bill</th><th>Invoice date</th><th class="num">Amount</th>
          <th>Extracted</th><th>Matched trip → client</th><th>In Xero</th><th>Status</th><th>Remarks</th><th></th>
        </tr></thead><tbody>
        <?php foreach ($bills as $b):
            $ex      = trim(implode(' · ', array_filter([$b['ex_tail'], $b['ex_airport'], $b['ex_date']])));
            $xeroUrl = xero_bill_url((string)$b['xero_invoice_id']);
            $created = local_dt($b['xero_created_at'] ?? '');
        ?>
          <tr data-xstatus="<?= e(strtoupper((string)($b['xero_status'] ?? ''))) ?>">
            <td class="nowrap"><?php if ($created !== ''): ?>
                <?= e(local_dt($b['xero_created_at'], 'd M Y')) ?>
                <div class="muted small"><?= e(local_dt($b['xero_created_at'], 'H:i')) ?></div>
              <?php else: ?><span class="muted" title="Filled in on the next refresh from Xero">—</span><?php endif; ?></td>
            <td><?= e($b['supplier'] ?: '—') ?><?php if ($b['description']): ?><div class="billdesc" title="<?= e($b['description']) ?>"><?= e(mb_strimwidth((string)$b['description'], 0, 64, '…')) ?></div><?php endif; ?></td>
            <td class="mono" title="<?= e($b['description']) ?>">
              <?php $label = $b['invoice_number'] ?: substr((string)$b['xero_invoice_id'], 0, 8);
              if ($xeroUrl !== ''): ?>
                <a class="link" href="<?= e($xeroUrl) ?>" target="_blank" rel="noopener noreferrer"
                   title="Open this bill in Xero"><?= e($label) ?> <span class="extlink">↗</span></a>
              <?php else: ?><?= e($label) ?><?php endif; ?>
            </td>
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
            <td><?php if ($b['matched_trip_number']): ?><span class="mono"><?= e($b['matched_trip_number']) ?></span> <span class="muted">→ <?= e($b['matched_client'] ?: 'no client') ?></span>
              <?php else: $why = trim((string)($b['match_reason'] ?? '')); ?>
                <span class="muted">—</span>
                <?php // Why it did not match, in the matcher's own words — hover for the whole sentence. ?>
                <?php if ($why !== ''): ?><div class="billdesc" style="max-width:320px" title="<?= e($why) ?>"><?= e(mb_strimwidth($why, 0, 80, '…')) ?></div><?php endif; ?>
              <?php endif; ?></td>
            <td><?= $xpill((string)($b['xero_status'] ?? '')) ?></td>
            <?php // An approval hold is shown only on hover — it is an internal switch, not a bill state.
              $why = trim((string)($b['match_reason'] ?? '')); ?>
            <td<?= !empty($b['approval_hold']) ? ' title="Approval hold — refreshing will not mark this bill approved from Xero; approve it from the Trips tab"' : '' ?>><?= $bpill((string)$b['match_status'], $why) ?><?php
              // Two different failures, so two separate marks: why it did not match,
              // and why the last push to Xero was rejected.
              if ($why !== ''): ?> <span class="warnmark" title="<?= e($why) ?>">!</span><?php endif;
              ?><?= !empty($b['xero_last_error']) ? ' <span class="warnmark" title="'.e($b['xero_last_error']).'">!</span>' : '' ?></td>
            <td><?php $rm = trim((string)($b['remarks'] ?? '')); if ($rm !== ''): ?><span class="billdesc" title="<?= e($rm) ?>"><?= e(mb_strimwidth($rm, 0, 40, '…')) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="nowrap">
              <?php if ($b['match_status']==='approved'): ?>
                <span class="muted">✓ Approved</span>
              <?php elseif ($b['match_status']==='tagged'): ?>
                <span class="muted">✓ Ref set · approve in <a class="link" href="<?= e(base()) ?>/?view=trips">Trips</a></span>
              <?php elseif ($b['match_status']==='matched'): ?>
                <form method="post" action="<?= e(base()) ?>/bills/tag" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn sm">Tag <?= e($b['matched_trip_number']) ?></button></form>
              <?php else: ?>
                <form method="post" action="<?= e(base()) ?>/bills/assign" class="assignform"
                      onsubmit="return this.trip_number.value.trim() !== '';"
                      title="<?= e(trim((string)($b['match_reason'] ?? '')) ?: 'No trip found') ?> — key in the LEON trip number and it's pushed to the bill in Xero">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <input type="text" name="trip_number" list="tripnums" placeholder="Key in trip no…" autocomplete="off" required>
                  <button class="btn sm">Tag</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
      <?php endif; ?>
    </section>
    <script>
    (function(){
      var sel = document.getElementById('fBillStatus'); if (!sel) return;
      function apply(){ var v = sel.value;
        document.querySelectorAll('tr[data-xstatus]').forEach(function(r){
          r.style.display = (!v || r.getAttribute('data-xstatus') === v) ? '' : 'none';
        });
      }
      sel.addEventListener('change', apply);
    })();
    </script>
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
    const fe = $('#fEntity').value, fs = $('#fStatus').value, fa = $('#fApprove').value;
    if (fe && t.entity !== fe) return false;
    if (fs === 'matched'   && !(t.bills > 0)) return false;
    if (fs === 'unmatched' && t.bills > 0)    return false;
    if (fs === 'ready'     && t.cost !== 'ready')   return false;
    if (fs === 'partial'   && t.cost !== 'partial') return false;
    if (fa === 'invoiced'    && !t.invoiced) return false;
    if (fa === 'approved'    && !(t.approved && !t.invoiced)) return false;
    if (fa === 'notapproved' && !(t.bills > 0 && !t.approved && !t.invoiced)) return false;
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
  function approveCell(t){
    if (t.invoiced) return `<span class="pill green" title="Client invoice ${esc(t.invoice_number)}">Invoiced</span>`;
    if (t.bills>0 && !t.approved) return `<button type="button" class="btn primary sm approve" title="Approve this trip's ${t.bills} bill(s) in Xero">Approve</button>`;
    if (t.bills>0 && t.approved)  return `<span class="muted small" title="All bills approved — waiting on the remaining route legs">Approved</span>`;
    return '';
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
        <td class="nowrap">${approveCell(t)}</td>
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

  function approveTrip(id){
    const t = TRIPS.find(x=>x.id===id); if(!t) return;
    if (!confirm(`Approve ${t.bills} bill(s) for trip ${t.trip} in Xero?\n\nEach bill is authorised, and once every leg is costed the client invoice is raised.`)) return;
    const f=document.createElement('form'); f.method='post'; f.action=BASE+'/trips/approve';
    f.innerHTML = `<input type="hidden" name="csrf" value="${esc(CSRF)}"><input type="hidden" name="trip_id" value="${id}">`;
    document.body.appendChild(f); f.submit();
  }
  function invoiceAnyway(id){
    const t = TRIPS.find(x=>x.id===id); if(!t) return;
    if (!confirm(`Invoice trip ${t.trip} now, before every leg has a bill?\n\nMissing: ${t.missing||'—'}\n\nAny un-approved bills are approved first, then the client invoice is raised from whatever costs are in. You can't easily add the missing costs to this invoice afterwards.`)) return;
    const f=document.createElement('form'); f.method='post'; f.action=BASE+'/trips/approve';
    f.innerHTML = `<input type="hidden" name="csrf" value="${esc(CSRF)}"><input type="hidden" name="trip_id" value="${id}"><input type="hidden" name="force" value="1">`;
    document.body.appendChild(f); f.submit();
  }
  function waiveLeg(id, airport){
    const f=document.createElement('form'); f.method='post'; f.action=BASE+'/trips/waive';
    f.innerHTML = `<input type="hidden" name="csrf" value="${esc(CSRF)}"><input type="hidden" name="trip_id" value="${id}"><input type="hidden" name="airport" value="${esc(airport)}">`;
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
    if (e.target.closest('.approve')) { approveTrip(+tr.dataset.id); return; }
    if (e.target.closest('.del')) { deleteTrip(+tr.dataset.id); return; }
    openModal(+tr.dataset.id);
  });
  $('#all').addEventListener('change', e => {
    TRIPS.filter(matches).forEach(t=> e.target.checked ? selected.add(t.id) : selected.delete(t.id));
    render();
  });
  ['input','change'].forEach(ev=>{ $('#q').addEventListener(ev,render); });
  $('#fEntity').addEventListener('change',render); $('#fStatus').addEventListener('change',render);
  $('#fApprove').addEventListener('change',render);
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
    const miss = t.missing_legs||[], wv = t.waived_legs||[];
    const chips = (arr,cls) => arr.map(a=>`<button type="button" class="chipbtn ${cls}" data-waive="${esc(a)}" title="${cls==='miss'?'Mark this leg ‘no bill expected’':'Un-waive — require a bill again'}">${esc(a)}${cls==='wv'?' ✕':''}</button>`).join(' ');
    const missBlock = miss.length ? `<div class="drow"><span>No bill at</span><b>${chips(miss,'miss')}</b></div>` : '';
    const wvBlock   = wv.length   ? `<div class="drow"><span>No bill expected</span><b>${chips(wv,'wv')}</b></div>` : '';
    const waiveHint = (miss.length||wv.length) ? `<div class="mhint">Click a leg to toggle whether a supplier bill is expected there.</div>` : '';
    $('#mbody').innerHTML =
      `<div class="legs">${legs||'—'}</div>` +
      row('Client', t.client||'—') + row('Aircraft', t.aircraft) +
      row('Dates', dates(t)) + row('Flights', t.flights) +
      `<div class="drow"><span>Bills matched</span><b>${t.bills>0 ? t.bills : '—'}</b></div>` +
      `<div class="drow"><span>Costing</span><b><span class="pill ${c}">${esc(l)}</span>${cover}</b></div>` +
      missBlock + wvBlock + waiveHint +
      row('Source file', t.source) + row('Imported', t.created) + row('Updated', t.updated) +
      `<div class="mfoot">` +
        (!t.invoiced && t.cost==='partial' ? `<button type="button" class="btn sm" id="minv" title="Raise the client invoice now, before every leg has a bill">Invoice anyway</button>` : '') +
        `<button type="button" class="btn danger sm" id="mdel">Delete from master list</button></div>`;
    $('#mbody').querySelectorAll('[data-waive]').forEach(b => b.addEventListener('click', () => waiveLeg(t.id, b.dataset.waive)));
    const minv = $('#minv'); if (minv) minv.addEventListener('click', () => { $('#modal').hidden = true; invoiceAnyway(t.id); });
    $('#mdel').addEventListener('click', () => { $('#modal').hidden = true; deleteTrip(t.id); });
    $('#modal').hidden = false;
  }
  document.querySelectorAll('[data-close]').forEach(x=>x.addEventListener('click',()=>$('#modal').hidden=true));
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') $('#modal').hidden=true; });

  render();
}
JS;
}

function ai_js(): string {
    return <<<'JS'
(function(){
  const root = document.getElementById('aiwork');
  if(!root) return;
  const streamEl = document.getElementById('aistream');
  const stateEl  = document.getElementById('aistate');
  const phasesEl = document.getElementById('aiphases');
  const orbEl    = document.getElementById('aiorb');
  const simBtn   = document.getElementById('aisim');
  const countsBox = document.getElementById('aicounts');
  const counts = {};
  document.querySelectorAll('#aicounts [data-pulse]').forEach(el => { counts[el.dataset.pulse] = el; });
  const ICON = {ok:'✓', err:'!', pending:'…'};
  let seen = null, busy = false;

  const key = ev => (ev.at||'')+'|'+(ev.title||'')+'|'+((ev.steps&&ev.steps[3]&&ev.steps[3].text)||'');
  const esc = s => { const d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; };
  const wait = ms => new Promise(r=>setTimeout(r,ms));

  function ago(iso){
    const t = Date.parse(iso||''); if(isNaN(t)) return '';
    const s = Math.max(0,(Date.now()-t)/1000);
    if(s<45) return 'just now';
    if(s<3600) return Math.round(s/60)+'m ago';
    if(s<86400) return Math.round(s/3600)+'h ago';
    return Math.round(s/86400)+'d ago';
  }

  function card(ev, fresh){
    const li = document.createElement('li');
    li.className = 'aiev '+(ev.status||'ok')+(fresh?' fresh':'');
    const link = ev.link ? '<div class="aiev-f"><a class="link" href="'+esc(ev.link)+'" target="_blank" rel="noopener">View ↗</a></div>' : '';
    li.innerHTML =
      '<div class="aiev-h"><span class="aibadge '+(ev.status||'ok')+'">'+(ICON[ev.status]||'•')+'</span>'
      + '<b>'+esc(ev.title||'')+'</b><time>'+ago(ev.at)+'</time></div>'
      + '<div class="aiev-steps">'
      + (ev.steps||[]).map(s=>'<div class="aistep" data-phase="'+esc(s.phase)+'"><i></i><span>'+esc(s.text)+'</span></div>').join('')
      + '</div>' + link;
    return li;
  }

  // Today's tallies, straight from the pulse — the panel's live scoreboard.
  function setCounts(pulse){
    const t = pulse && pulse.today; if(!t) return;
    for(const k in counts){
      if(t[k] == null) continue;
      const el = counts[k], v = String(t[k]);
      if(el.textContent !== v){
        el.textContent = v;
        el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump');
      }
      if(k === 'errors') el.parentElement.classList.toggle('zero', Number(t[k]) === 0);
    }
    if(countsBox) countsBox.classList.add('live');
  }

  function setWorking(on){
    root.classList.toggle('working', on);
    stateEl.textContent = on?'Working':'Watching';
    stateEl.className = 'aistate '+(on?'working':'watching');
  }
  function litePhase(p){
    phasesEl.querySelectorAll('span').forEach(x=>x.classList.toggle('on', x.dataset.phase===p));
    orbEl.className = 'aiorb spin phase-'+(p||'');
  }
  function restOrb(){ orbEl.className='aiorb'; phasesEl.querySelectorAll('span').forEach(x=>x.classList.remove('on')); }

  // Reveal one event's four beats (read→think→decide→do), lighting the brain.
  function play(li){
    return new Promise(res=>{
      const steps = [...li.querySelectorAll('.aistep')];
      setWorking(true);
      let i=0;
      (function beat(){
        if(i>=steps.length){ restOrb(); li.classList.remove('running'); res(); return; }
        litePhase(steps[i].dataset.phase);
        steps[i].classList.add('show');
        i++; setTimeout(beat, 560);
      })();
    });
  }

  function renderList(events){
    streamEl.innerHTML = '';
    if(!events.length){ streamEl.innerHTML = '<li class="aiempty">Waiting for activity…</li>'; return; }
    events.slice(0,12).forEach(ev=>{
      const li = card(ev, false);
      li.querySelectorAll('.aistep').forEach(s=>s.classList.add('show'));
      streamEl.appendChild(li);
    });
  }

  async function animateIn(ev){
    const empty = streamEl.querySelector('.aiempty'); if(empty) empty.remove();
    const li = card(ev, true); li.classList.add('running');
    streamEl.prepend(li);
    [...streamEl.children].slice(24).forEach(n=>n.remove());
    await play(li);
  }

  async function poll(){
    if(busy) return;
    let data;
    try {
      const r = await fetch(BASE+'/activity/feed', {headers:{'Accept':'application/json'}});
      if(!r.ok) return;
      data = await r.json();
    } catch(e){ return; }
    const events = data.events || [];
    setCounts(data.pulse);
    if(seen === null){ renderList(events); seen = events.length?key(events[0]):''; setWorking(!!(data.pulse&&data.pulse.active)); return; }
    let idx = events.findIndex(e=>key(e)===seen);
    if(idx === -1) idx = Math.min(events.length, 3);
    const fresh = events.slice(0, idx).reverse();   // oldest-new first, so newest lands on top
    if(fresh.length){
      busy = true;
      for(const ev of fresh){ await animateIn(ev); }
      seen = key(events[0]); busy = false;
    }
    if(!busy) setWorking(!!(data.pulse && data.pulse.active));
  }

  // A scripted run for demos — reads, reasons, acts. Touches no data.
  const DEMOS = [
    {kind:'invoice',status:'ok',title:'Supplier invoice SN030492',link:'',steps:[
      {phase:'read',text:'Read SN030492.pdf from Signature Flight Support'},
      {phase:'think',text:'Extracted supplier, €1,012.72 and invoice SN030492'},
      {phase:'decide',text:'Invoice SN030492 is new — create the draft bill'},
      {phase:'do',text:'Created the draft bill in Xero'}]},
    {kind:'invoice',status:'ok',title:'Supplier invoice HKG-GH-037',link:'',steps:[
      {phase:'read',text:'Read HKG-GH-037.pdf from ASA South China'},
      {phase:'think',text:'Seen this invoice number before — check Xero'},
      {phase:'decide',text:'Already in Xero — clear the stale copy and redo it'},
      {phase:'do',text:'Deleted the old bill and re-created it fresh'}]},
    {kind:'trip',status:'ok',title:'Trip 35507',link:'',steps:[
      {phase:'read',text:'Read Flight Count Inc.xlsx'},
      {phase:'think',text:'Parsed the Flight Count row — 2 flight legs'},
      {phase:'decide',text:'Polaris Aviation · KSWF - KHPN - EGGW'},
      {phase:'do',text:'Added to the trip master list'}]},
  ];
  async function simulate(){
    if(busy) return; busy = true; simBtn.disabled = true;
    for(const d of DEMOS){ await animateIn(Object.assign({at:new Date().toISOString()}, d)); await wait(320); }
    busy = false; simBtn.disabled = false; setWorking(false);
  }

  simBtn.addEventListener('click', simulate);
  poll();
  setInterval(poll, 4000);
})();
JS;
}

function dash_js(): string {
    return <<<'JS'
(function(){
  // Roll the deck and pipeline figures up from zero — the numbers are already
  // in the markup, so this is decoration only and degrades to the final value.
  const root = document.querySelector('.dash'); if(!root) return;
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  root.querySelectorAll('[data-count]').forEach(el => {
    const to = parseInt(el.dataset.count, 10) || 0;
    if(still || to === 0){ el.textContent = String(to); return; }
    const t0 = performance.now(), dur = 850;
    requestAnimationFrame(function step(t){
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = String(Math.round(to * (1 - Math.pow(1 - p, 3))));
      if(p < 1) requestAnimationFrame(step);
    });
  });
})();
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
.extlink{font-size:11px;opacity:.55}
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
.importrow{display:flex;gap:12px;align-items:end}.importrow .field{min-width:220px}.importrow .spacer{flex:1 1 auto}

.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.search{flex:1 1 240px;min-width:180px}
.fsel{flex:0 0 auto;width:auto;min-width:140px}.toolbar .spacer{flex:1 1 auto}

.tablewrap{overflow:auto;border:1px solid var(--line);border-radius:10px;max-height:560px}
.tablewrap.short{max-height:none}
table.grid{width:100%;border-collapse:collapse;font-size:13px}
table.grid th,table.grid td{text-align:left;padding:9px 11px;border-bottom:1px solid var(--line);vertical-align:middle}
.trunc{display:inline-block;vertical-align:top;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
table.grid thead th{position:sticky;top:0;background:#f8fafc;z-index:1;font-size:12px;color:var(--mut);font-weight:600;white-space:nowrap}
table.grid tbody tr:hover{background:#f8faff}
table.grid tbody#rows tr:hover{cursor:pointer}
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
.mfoot{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}
.mcard>.mfoot{justify-content:flex-start;align-items:center;margin-top:0;padding:14px 18px;position:sticky;bottom:0;background:#fff;border-radius:0 0 14px 14px}
/* Save anchors right whether or not Delete is shown (hidden when adding). */
.mcard>.mfoot .btn.primary{margin-left:auto}
.chipbtn{font-family:ui-monospace,monospace;font-size:12px;padding:2px 8px;border-radius:6px;border:1px solid transparent;cursor:pointer}
.chipbtn.miss{background:#fef3c7;color:#92400e;border-color:#fde68a}
.chipbtn.miss:hover{background:#fde68a}
.chipbtn.wv{background:#eef2ff;color:#3538cd;border-color:#c7d2fe}
.chipbtn.wv:hover{background:#e0e7ff}
.mhint{font-size:11.5px;color:var(--mut);padding:7px 0 2px}

.reschips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.chip{font-size:12px;padding:3px 10px;border-radius:20px;background:#f2f4f7;color:#475467}
.chip.green{background:#ecfdf3;color:#067647}.chip.amber{background:#fffaeb;color:#b54708}
.chip.red{background:#fef3f2;color:#b42318}.chip.blue{background:#eff4ff;color:#1d4ed8}
.empty{padding:26px;text-align:center;color:var(--mut);border:1px dashed var(--line);border-radius:10px}
.billdesc{display:inline-block;vertical-align:top;max-width:230px;font-size:11.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
div.billdesc{display:block}
.assignform{display:flex;gap:6px;align-items:center}
.assignform select,.assignform input[type=text]{width:auto;min-width:130px;max-width:200px;padding:5px 8px;font-size:12px}

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

/* --- "AI at work" panel --------------------------------------------- */
.aiwork{--read:#2563eb;--think:#7c3aed;--decide:#c2820a;--do:#16a34a}
.aiwork .chead h2{display:flex;align-items:center;gap:9px}
.aidot{width:10px;height:10px;border-radius:50%;background:var(--gray);box-shadow:0 0 0 0 rgba(148,163,184,.5)}
.aiwork.working .aidot{background:var(--green);animation:aipulse 1.4s ease-out infinite}
@keyframes aipulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.45)}70%{box-shadow:0 0 0 8px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}
.aiactions{display:flex;align-items:center;gap:10px}
.aistate{font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px}
.aistate.watching{color:var(--mut);background:#f1f5f9}
.aistate.working{color:#0f7a34;background:#dcfce7}
.ailede{margin:2px 0 14px}
.aigrid{display:grid;grid-template-columns:120px 1fr;gap:18px;align-items:start}
@media(max-width:640px){.aigrid{grid-template-columns:1fr}.aibrain{flex-direction:row!important;justify-content:flex-start;gap:16px}}
.aibrain{display:flex;flex-direction:column;align-items:center;gap:12px;position:sticky;top:8px}
.aiorb{position:relative;width:92px;height:92px;border-radius:50%;display:grid;place-items:center;
  background:radial-gradient(circle at 50% 38%,#eef2ff,#fff);border:1px solid var(--line);transition:box-shadow .3s}
.aiorb i{font-style:normal;font-weight:800;color:var(--nav);font-size:14px;letter-spacing:.06em;z-index:2}
.aiorb span{position:absolute;border-radius:50%;border:2px solid transparent}
.aiorb span:nth-child(1){inset:7px;border-top-color:var(--read)}
.aiorb span:nth-child(2){inset:15px;border-top-color:var(--think)}
.aiorb span:nth-child(3){inset:23px;border-top-color:var(--do)}
.aiorb.spin span{animation:aispin 1s linear infinite}
.aiorb.spin span:nth-child(2){animation-direction:reverse;animation-duration:1.3s}
.aiorb.phase-read{box-shadow:0 0 0 4px rgba(37,99,235,.15)}
.aiorb.phase-think{box-shadow:0 0 0 4px rgba(124,58,237,.15)}
.aiorb.phase-decide{box-shadow:0 0 0 4px rgba(194,130,10,.15)}
.aiorb.phase-do{box-shadow:0 0 0 4px rgba(22,163,74,.15)}
@keyframes aispin{to{transform:rotate(360deg)}}
.aiphases{display:flex;flex-wrap:wrap;gap:5px;justify-content:center}
.aiphases span{font-size:10.5px;font-weight:600;color:var(--mut);background:#f1f5f9;border-radius:999px;padding:2px 8px;transition:.2s}
.aiphases span[data-phase="read"].on{color:#fff;background:var(--read)}
.aiphases span[data-phase="think"].on{color:#fff;background:var(--think)}
.aiphases span[data-phase="decide"].on{color:#fff;background:var(--decide)}
.aiphases span[data-phase="do"].on{color:#fff;background:var(--do)}
.aistream{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px;max-height:440px;overflow:auto}
.aiempty{color:var(--mut);font-size:13px;padding:18px 0;text-align:center}
.aiev{border:1px solid var(--line);border-left:3px solid var(--gray);border-radius:10px;padding:10px 12px;background:#fff}
.aiev.ok{border-left-color:var(--do)}.aiev.err{border-left-color:var(--red)}.aiev.pending{border-left-color:var(--amber)}
.aiev.fresh{animation:aiflash .9s ease-out}
@keyframes aiflash{0%{background:#eff6ff;transform:translateY(-4px)}100%{background:#fff;transform:none}}
.aiev-h{display:flex;align-items:center;gap:8px;font-size:13.5px}
.aiev-h b{flex:1;font-weight:650}
.aiev-h time{color:var(--mut);font-size:11.5px;white-space:nowrap}
.aibadge{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;font-size:11px;font-weight:800;color:#fff;background:var(--gray)}
.aibadge.ok{background:var(--do)}.aibadge.err{background:var(--red)}.aibadge.pending{background:var(--amber);color:#3a2b06}
.aiev-steps{margin-top:8px;display:flex;flex-direction:column;gap:4px;padding-left:2px}
.aistep{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--ink);opacity:0;transform:translateY(3px);transition:opacity .35s,transform .35s}
.aistep.show{opacity:1;transform:none}
.aistep i{margin-top:5px;width:7px;height:7px;border-radius:50%;background:var(--gray);flex:none}
.aistep[data-phase="read"] i{background:var(--read)}
.aistep[data-phase="think"] i{background:var(--think)}
.aistep[data-phase="decide"] i{background:var(--decide)}
.aistep[data-phase="do"] i{background:var(--do)}
.aiev-f{margin-top:6px;padding-left:15px}

/* ==== Dashboard: flight-deck skin ===================================
   Scoped to .dash so the rest of the app is untouched. Light instrument
   panels: the tech language is carried by the grid, the glow, the scan
   sweep, the reactor and the monospace telemetry — not by a dark canvas. */
.dash{--cy:#0891b2;--vi:#7c3aed;--em:#059669;--am:#b45309;--deep:#fff;
  --edge:#e0e7f5;--ink:#0f172a;--soft:#5c6a8a;--micro:#8290b0}
.dash .dim{color:var(--mut);font-size:12px}
.dash .card>.chead h2{font-size:14px}

/* --- command deck --- */
.deck{position:relative;overflow:hidden;border-radius:16px;padding:26px 28px;margin-bottom:16px;color:var(--ink);
  background:linear-gradient(135deg,#fff 0%,#f4f8ff 46%,#e9f1ff 100%);
  border:1px solid var(--edge);
  box-shadow:0 14px 34px -22px rgba(37,71,150,.4),inset 0 1px 0 #fff}
.deckgrid{position:absolute;inset:-2px;pointer-events:none;
  background-image:linear-gradient(rgba(37,99,235,.075) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,.075) 1px,transparent 1px);
  background-size:44px 44px;
  -webkit-mask-image:radial-gradient(125% 115% at 10% -5%,#000 30%,transparent 74%);
  mask-image:radial-gradient(125% 115% at 10% -5%,#000 30%,transparent 74%);
  animation:gridflow 20s linear infinite}
@keyframes gridflow{to{background-position:44px 44px,44px 44px}}
.deckglow{position:absolute;width:540px;height:540px;right:-150px;top:-270px;border-radius:50%;pointer-events:none;
  background:radial-gradient(circle,rgba(34,211,238,.3),rgba(139,92,246,.16) 46%,transparent 70%);
  animation:glowdrift 13s ease-in-out infinite alternate}
@keyframes glowdrift{to{transform:translate3d(-46px,34px,0) scale(1.14)}}
.deckscan{position:absolute;left:0;right:0;top:0;height:140px;pointer-events:none;
  background:linear-gradient(180deg,transparent,rgba(37,99,235,.07),transparent);
  animation:deckscan 7.5s linear infinite}
@keyframes deckscan{0%{transform:translateY(-160px)}100%{transform:translateY(560px)}}
.deckbody{position:relative;display:grid;grid-template-columns:minmax(270px,1fr) minmax(330px,1.3fr);gap:26px;align-items:center}
@media(max-width:1040px){.deckbody{grid-template-columns:1fr;gap:22px}}

.eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 11px;border-radius:999px;
  font:600 10.5px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.16em;text-transform:uppercase;
  color:#0e7490;background:rgba(6,182,212,.09);border:1px solid rgba(6,182,212,.32)}
.ebdot{width:6px;height:6px;border-radius:50%;background:#06b6d4;animation:ebpulse 2s ease-out infinite}
@keyframes ebpulse{0%{box-shadow:0 0 0 0 rgba(6,182,212,.55)}70%{box-shadow:0 0 0 7px rgba(6,182,212,0)}100%{box-shadow:0 0 0 0 rgba(6,182,212,0)}}
.deckintro h2{margin:14px 0 9px;font-size:23px;line-height:1.3;letter-spacing:-.3px;font-weight:700;color:var(--ink);max-width:26ch}
.deckintro p{margin:0;font-size:13.5px;line-height:1.6;color:var(--soft);max-width:48ch}
.deckchips{display:flex;flex-wrap:wrap;gap:8px;margin-top:17px}
.dchip{display:inline-flex;align-items:center;gap:7px;padding:6px 11px;border-radius:8px;
  font:600 11.5px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.03em;color:#41506f;
  background:rgba(255,255,255,.75);border:1px solid var(--edge);box-shadow:0 1px 2px rgba(16,24,40,.04)}
.dchip i{width:6px;height:6px;border-radius:50%;background:#94a3b8;flex:none}
.dchip.on i{background:#10b981;box-shadow:0 0 7px rgba(16,185,129,.85)}
.dchip.off i{background:#f59e0b;box-shadow:0 0 7px rgba(245,158,11,.8)}

.readouts{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:1040px){.readouts{grid-template-columns:repeat(4,1fr)}}
@media(max-width:820px){.readouts{grid-template-columns:repeat(2,1fr)}}
@media(max-width:460px){.readouts{grid-template-columns:1fr}}
.ro{position:relative;overflow:hidden;padding:13px 14px 12px;border-radius:12px;
  background:rgba(255,255,255,.86);border:1px solid var(--edge);
  box-shadow:0 2px 8px -3px rgba(37,71,150,.16);backdrop-filter:blur(3px)}
.ro:before{content:"";position:absolute;left:0;top:0;bottom:0;width:2px;background:var(--rc);box-shadow:0 0 10px var(--rc)}
.ro.cy{--rc:#06b6d4}.ro.vi{--rc:#8b5cf6}.ro.em{--rc:#10b981}.ro.am{--rc:#f59e0b}
.rolbl{min-height:2.5em;font:600 10px/1.25 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.13em;text-transform:uppercase;color:var(--micro)}
.ronum{margin:8px 0 10px;font:700 30px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:-1px;color:var(--ink)}
.ronum.split{display:flex;align-items:baseline;gap:5px;font-size:20px}
.ronum.split small{font:600 9.5px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.1em;color:var(--micro)}
.ronum.split b{font-weight:400;color:#c3cde0}
.robar{height:3px;border-radius:3px;background:#e8edf8;overflow:hidden}
.robar.dual{background:rgba(139,92,246,.42)}
.robar i{display:block;height:100%;border-radius:3px;background:var(--rc);box-shadow:0 0 8px var(--rc);
  transform-origin:left;animation:bargrow 1.1s cubic-bezier(.2,.8,.25,1) both}
@keyframes bargrow{from{transform:scaleX(0)}to{transform:scaleX(1)}}
.rofoot{margin-top:8px;font-size:11px;color:var(--micro)}

/* --- AI console --- */
.dash .console{--read:#0284c7;--think:#7c3aed;--decide:#b45309;--do:#059669;
  position:relative;overflow:hidden;color:var(--ink);padding:18px;
  border:1px solid var(--edge);border-radius:14px;
  background:linear-gradient(170deg,#f4f8fe 0%,#eef4fd 58%,#e9f1fc 100%);
  box-shadow:0 12px 30px -24px rgba(37,71,150,.45)}
.dash .console:before{content:"";position:absolute;inset:0;pointer-events:none;
  background-image:linear-gradient(rgba(37,99,235,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,.05) 1px,transparent 1px);
  background-size:36px 36px;
  -webkit-mask-image:radial-gradient(95% 85% at 50% -10%,#000 26%,transparent 72%);
  mask-image:radial-gradient(95% 85% at 50% -10%,#000 26%,transparent 72%)}
.dash .console>*{position:relative}
.dash .console .chead{align-items:center;flex-wrap:wrap;gap:10px}
.dash .console .chead h2{color:var(--ink);white-space:nowrap}
.dash .console .aidot{background:#cbd5e1;box-shadow:none}
.dash .console.working .aidot{background:#10b981}
.dash .console .ailede{margin:2px 0 15px;font-size:12.8px;line-height:1.6;color:var(--soft);max-width:82ch}
.dash .console .aistate{padding:6px 11px;font:600 10.5px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;text-transform:uppercase;border:1px solid transparent}
.dash .console .aistate.watching{color:#64748b;background:#f1f5fb;border-color:var(--edge)}
.dash .console .aistate.working{color:#047857;background:#ecfdf5;border-color:#a7f3d0}
.dash .console .simbtn{padding:7px 13px;border-radius:9px;font-weight:600;
  color:#41506f;background:#fff;border:1px solid var(--edge);box-shadow:0 1px 2px rgba(16,24,40,.05)}
.dash .console .simbtn:hover{color:#0369a1;background:#f0f9ff;border-color:#7dd3fc}
.dash .console .simbtn:disabled{opacity:.5;cursor:default}

.aicounts{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:0 0 16px}
@media(max-width:640px){.aicounts{grid-template-columns:repeat(2,1fr)}}
.aic{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:10px;
  background:#fff;border:1px solid var(--edge);box-shadow:0 1px 2px rgba(16,24,40,.04)}
.aic b{display:inline-block;font:700 19px/1 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--ink)}
.aic em{font:600 9.5px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;font-style:normal;letter-spacing:.12em;text-transform:uppercase;color:var(--micro)}
.aic.ok b{color:var(--do)}.aic.tr b{color:var(--read)}.aic.er b{color:#dc2626}
.aic.er.zero b{color:#a3adc4}
.aicounts:not(.live) b{color:#b6c2da}   /* the "—" placeholder, until the first poll lands */
.aic b.bump{animation:aicbump .55s ease-out}
@keyframes aicbump{0%{transform:scale(1)}35%{transform:scale(1.24)}100%{transform:scale(1)}}

.dash .console .aigrid{grid-template-columns:152px 1fr;gap:22px}
@media(max-width:640px){.dash .console .aigrid{grid-template-columns:1fr}}
.dash .console .aibrain{gap:16px}
.reactor{position:relative;width:118px;height:118px;display:grid;place-items:center;flex:none}
.rring{position:absolute;border-radius:50%;border:1px solid rgba(99,130,190,.3)}
.rring.r1{inset:0;border-width:1.5px;border-top-color:#0284c7;animation:rspin 9s linear infinite}
.rring.r1:after{content:"";position:absolute;top:-3px;left:50%;width:5px;height:5px;margin-left:-2.5px;border-radius:50%;background:var(--read);box-shadow:0 0 8px rgba(2,132,199,.8)}
.rring.r2{inset:11px;border-width:1.5px;border-right-color:#7c3aed;animation:rspin 6.5s linear infinite reverse}
.rring.r3{inset:22px;border-width:1.5px;border-bottom-color:#059669;animation:rspin 12s linear infinite}
@keyframes rspin{to{transform:rotate(360deg)}}
.dash .console .aiorb{width:72px;height:72px;border:1px solid #c6d8f0;
  background:radial-gradient(circle at 50% 34%,#e6f4ff,#fff 68%);
  box-shadow:0 2px 10px -4px rgba(37,71,150,.35)}
.dash .console .aiorb i{color:#0b3a63;font-size:13px;text-shadow:0 0 10px rgba(2,132,199,.35)}
.dash .console .aiorb:after{content:"";position:absolute;inset:-7px;border-radius:50%;
  border:1px solid rgba(2,132,199,.3);animation:orbbreath 3.2s ease-in-out infinite}
@keyframes orbbreath{0%,100%{transform:scale(1);opacity:.55}50%{transform:scale(1.1);opacity:.1}}
.dash .console .aiorb.phase-read{box-shadow:0 0 0 4px rgba(2,132,199,.12),0 0 22px rgba(2,132,199,.3)}
.dash .console .aiorb.phase-think{box-shadow:0 0 0 4px rgba(124,58,237,.12),0 0 22px rgba(124,58,237,.3)}
.dash .console .aiorb.phase-decide{box-shadow:0 0 0 4px rgba(217,119,6,.14),0 0 22px rgba(217,119,6,.3)}
.dash .console .aiorb.phase-do{box-shadow:0 0 0 4px rgba(5,150,105,.12),0 0 22px rgba(5,150,105,.3)}

/* phase rail — four beats on a lit thread */
.dash .console .aiphases{position:relative;flex-direction:column;align-items:stretch;gap:7px;width:100%;padding-left:15px}
.dash .console .aiphases:before{content:"";position:absolute;left:4px;top:9px;bottom:9px;width:1px;
  background:linear-gradient(180deg,rgba(2,132,199,.55),rgba(124,58,237,.55),rgba(217,119,6,.55),rgba(5,150,105,.55))}
.dash .console .aiphases span{position:relative;text-align:left;padding:6px 9px;border-radius:7px;
  font:600 10px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.13em;text-transform:uppercase;
  color:#5a6a8a;background:#fff;border:1px solid #d8e2f2;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.dash .console .aiphases span:before{content:"";position:absolute;left:-15px;top:50%;margin-top:-3px;width:6px;height:6px;border-radius:50%;background:#b9c6dd}
.dash .console .aiphases span[data-phase="read"].on{color:#fff;background:var(--read);border-color:var(--read);box-shadow:0 0 14px rgba(2,132,199,.4)}
.dash .console .aiphases span[data-phase="think"].on{color:#fff;background:var(--think);border-color:var(--think);box-shadow:0 0 14px rgba(124,58,237,.4)}
.dash .console .aiphases span[data-phase="decide"].on{color:#fff;background:var(--decide);border-color:var(--decide);box-shadow:0 0 14px rgba(180,83,9,.4)}
.dash .console .aiphases span[data-phase="do"].on{color:#fff;background:var(--do);border-color:var(--do);box-shadow:0 0 14px rgba(5,150,105,.4)}
.dash .console .aiphases span.on:before{background:currentColor}
@media(max-width:640px){
  .dash .console .aibrain{flex-direction:row!important;align-items:center;gap:18px}
  .dash .console .aiphases{width:auto;flex:1;padding-left:15px}
}

/* reasoning stream — terminal cards */
.dash .console .aistream{max-height:470px;gap:9px;padding-right:4px}
.dash .console .aistream::-webkit-scrollbar{width:6px}
.dash .console .aistream::-webkit-scrollbar-thumb{background:#d3ddf0;border-radius:6px}
.dash .console .aiempty{color:#8290b0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;letter-spacing:.06em}
.dash .console .aiev{padding:11px 13px;border-radius:10px;background:#fff;
  border:1px solid var(--edge);border-left:2px solid #cbd5e1;
  box-shadow:0 1px 2px rgba(16,24,40,.05);
  transition:background .2s,border-color .2s,transform .2s,box-shadow .2s}
.dash .console .aiev:hover{border-color:#c9d6ee;box-shadow:0 4px 12px -6px rgba(37,71,150,.4);transform:translateX(2px)}
.dash .console .aiev.ok{border-left-color:var(--do)}
.dash .console .aiev.err{border-left-color:#dc2626}
.dash .console .aiev.pending{border-left-color:#d97706}
.dash .console .aiev-h b{color:var(--ink);font-weight:600}
.dash .console .aiev-h time{color:#94a3b8;font:600 10.5px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.06em;text-transform:uppercase}
.dash .console .aibadge{background:#cbd5e1;font-size:10.5px;color:#fff}
.dash .console .aibadge.ok{background:var(--do)}
.dash .console .aibadge.err{background:#dc2626}
.dash .console .aibadge.pending{background:#d97706;color:#fff}
.dash .console .aiev-steps{position:relative;padding-left:0;margin-top:9px;gap:6px}
.dash .console .aiev-steps:before{content:"";position:absolute;left:3px;top:10px;bottom:10px;width:1px;background:#dbe3f2}
.dash .console .aistep{color:#48546f;font-size:12.4px}
.dash .console .aistep i{position:relative;z-index:1;background:#cbd5e1;box-shadow:0 0 0 3px var(--deep)}
.dash .console .aistep[data-phase="read"] i{background:var(--read);box-shadow:0 0 0 3px var(--deep),0 0 7px rgba(2,132,199,.6)}
.dash .console .aistep[data-phase="think"] i{background:var(--think);box-shadow:0 0 0 3px var(--deep),0 0 7px rgba(124,58,237,.55)}
.dash .console .aistep[data-phase="decide"] i{background:#d97706;box-shadow:0 0 0 3px var(--deep),0 0 7px rgba(217,119,6,.55)}
.dash .console .aistep[data-phase="do"] i{background:var(--do);box-shadow:0 0 0 3px var(--deep),0 0 7px rgba(5,150,105,.55)}
.dash .console .aiev-f{padding-left:15px}
.dash .console .aiev-f a{color:#0369a1}
.dash .console .aiev.fresh{animation:aiflashd 1s ease-out}
@keyframes aiflashd{
  0%{background:#eaf6ff;transform:translateY(-6px);box-shadow:0 0 0 3px rgba(2,132,199,.16)}
  100%{background:#fff;transform:none;box-shadow:0 1px 2px rgba(16,24,40,.05)}}

/* --- billing pipeline --- */
.flowcard .flow{display:flex;align-items:stretch}
.fnode{position:relative;flex:1;overflow:hidden;padding:15px 12px;text-align:center;border-radius:12px;
  background:linear-gradient(180deg,#fcfdff,#f2f5fc);border:1px solid var(--line)}
.fnode:before{content:"";position:absolute;left:0;right:0;top:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--nc,#2563eb),transparent)}
.fnode:nth-child(1){--nc:#06b6d4}.fnode:nth-child(3){--nc:#7c3aed}.fnode:nth-child(5){--nc:#059669}
.fic{display:inline-grid;place-items:center;width:32px;height:32px;border-radius:9px;margin-bottom:9px}
.fic.cy{color:#0891b2;background:#ecfeff;border:1px solid #a5f3fc}
.fic.vi{color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe}
.fic.em{color:#059669;background:#ecfdf5;border:1px solid #a7f3d0}
.fn{font:700 24px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:-.6px}
.fl{margin-top:5px;font:600 10px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.11em;text-transform:uppercase;color:var(--mut)}
.fwire{position:relative;flex:0 0 64px;align-self:center;height:2px;
  background:repeating-linear-gradient(90deg,#cbd5e1 0 6px,transparent 6px 12px)}
.fwire i{position:absolute;top:-3px;left:0;width:8px;height:8px;border-radius:50%;
  background:#2563eb;box-shadow:0 0 10px rgba(37,99,235,.85);animation:fwtravel 2.8s ease-in-out infinite}
.flow>div:nth-child(4) i{animation-delay:1.4s}
@keyframes fwtravel{0%{left:-4px;opacity:0}14%{opacity:1}86%{opacity:1}100%{left:calc(100% - 4px);opacity:0}}
@media(max-width:700px){
  .flowcard .flow{flex-direction:column}
  .fwire{flex:0 0 26px;width:2px;height:26px;margin:0 auto;
    background:repeating-linear-gradient(180deg,#cbd5e1 0 6px,transparent 6px 12px)}
  .fwire i{display:none}
}
.frates{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:15px;padding-top:14px;border-top:1px dashed var(--line)}
@media(max-width:640px){.frates{grid-template-columns:1fr}}
.frate{display:flex;align-items:center;gap:10px}
.frate>span{flex:0 0 120px;font:600 10px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--mut)}
.fbar{flex:1;height:6px;border-radius:6px;background:#eef1f8;overflow:hidden}
.fbar i{display:block;height:100%;border-radius:6px;transform-origin:left;animation:bargrow 1.2s cubic-bezier(.2,.8,.25,1) both}
.fbar i.vi{background:linear-gradient(90deg,#a78bfa,#7c3aed)}
.fbar i.em{background:linear-gradient(90deg,#34d399,#059669)}
.frate b{flex:0 0 42px;text-align:right;font:700 13px/1 ui-monospace,SFMono-Regular,Menlo,monospace}

/* --- bottom cards: light, with tech accents --- */
.dash table.grid thead th{font:600 10px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.11em;text-transform:uppercase}
.dash .src{transition:background .15s;border-radius:8px;padding-left:8px;padding-right:8px;margin:0 -8px}
.dash .src:hover{background:#f7f9ff}
.dash .src .dim{margin-left:auto}
.sig{display:inline-flex;align-items:flex-end;gap:2px;height:12px;flex:none}
.sig i{width:3px;border-radius:1px;background:#cbd5e1}
.sig i:nth-child(1){height:5px}.sig i:nth-child(2){height:8px}.sig i:nth-child(3){height:12px}
.sig.on i{background:var(--green)}
.sig.on i:nth-child(1){animation:sigpulse 1.7s ease-in-out infinite}
.sig.on i:nth-child(2){animation:sigpulse 1.7s ease-in-out .22s infinite}
.sig.on i:nth-child(3){animation:sigpulse 1.7s ease-in-out .44s infinite}
@keyframes sigpulse{0%,100%{opacity:.4}50%{opacity:1}}

@media(prefers-reduced-motion:reduce){
  .dash *,.dash *:before,.dash *:after{animation:none!important;transition:none!important}
}
</style><?php }
