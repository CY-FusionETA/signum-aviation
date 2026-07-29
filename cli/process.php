<?php
/**
 * Skyledger — Module 4 headless runner (import LEON + create draft POs for all).
 *
 *   php cli/process.php --file=path/to/leon.(csv|pdf) --entity=inc [--dry-run]
 *
 * --entity  inc | ltd   (defaults to inference from the filename, then 'inc')
 * --dry-run             import + print the Xero payloads, create nothing
 *
 * With no live Xero connection the run is ALWAYS a dry run (the stub client),
 * so this is safe to run before connecting an org. The web UI (public/) lets
 * you pick individual trips instead of creating a PO for every one.
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Service\Leon\LeonProcessor;
use App\Service\Xero\XeroOAuth;

$opts = getopt('', ['file:', 'entity::', 'dry-run']);
$file = $opts['file'] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php cli/process.php --file=leon.(csv|pdf) [--entity=inc|ltd] [--dry-run]\n");
    exit(1);
}

$entity = strtolower((string)($opts['entity'] ?? ''));
if ($entity === '') $entity = stripos(basename($file), 'ltd') !== false ? 'ltd' : 'inc';
$dryRun = array_key_exists('dry-run', $opts) || !XeroOAuth::isConnected();

$res = LeonProcessor::process($file, $entity, $dryRun);
$imp = $res['import']; $s = $res['summary'];

echo "LEON file:  {$file}  (parsed as {$imp['source']})\n";
echo "Entity:     {$entity}\n";
echo "Xero org:   " . ($res['tenant'] !== '' ? $res['tenant'] : '(not connected — dry run)') . "\n";
echo "Imported:   parsed={$imp['parsed']} new={$imp['new']} updated={$imp['updated']}\n";
echo str_repeat('-', 72) . "\n";
foreach ($res['rows'] as $r) {
    printf("[%-8s] %-10s %-24s %s\n", strtoupper($r['status']), $r['trip_number'],
        mb_strimwidth((string)$r['client_name'], 0, 24), $r['message']);
}
echo str_repeat('-', 72) . "\n";
printf("selected=%d created=%d skipped=%d failed=%d dry_run=%d\n",
    $s['selected'], $s['created'], $s['skipped'], $s['failed'], $s['dry_run']);

if ($s['dry_run'] > 0) {
    foreach ($res['rows'] as $r) {
        if (!empty($r['payload'])) {
            echo "\nExample Xero PurchaseOrders payload (trip {$r['trip_number']}):\n";
            echo json_encode(['PurchaseOrders' => [$r['payload']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            break;
        }
    }
}
