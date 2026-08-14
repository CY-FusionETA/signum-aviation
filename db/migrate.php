<?php
/** Create the SQLite database and all tables. Run once: php db/migrate.php */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Service\Auth\Users;

$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) { fwrite(STDERR, "Cannot read schema.sql\n"); exit(1); }

Db::conn()->exec($sql);

// Add columns to tables that already exist (CREATE TABLE IF NOT EXISTS won't).
// Safe to re-run: only columns that are missing get added.
$add = [
    'xero_bills' => [
        'currency_rate'   => 'REAL',
        'base_currency'   => 'TEXT',
        'base_total'      => 'REAL',
        'xero_created_at' => 'TEXT',
        'xero_status'     => 'TEXT',
        'remarks'         => 'TEXT',
        'approval_hold'   => 'INTEGER NOT NULL DEFAULT 0',
    ],
    'leon_trips' => [
        'waived_legs' => 'TEXT',
    ],
    'inbox_events' => [
        'bill_url'    => 'TEXT',
        'bill_number' => 'TEXT',
    ],
];
foreach ($add as $table => $cols) {
    $have = array_column(Db::all("PRAGMA table_info({$table})"), 'name');
    foreach ($cols as $name => $type) {
        if (!in_array($name, $have, true)) {
            Db::conn()->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$type}");
            echo "  + {$table}.{$name}\n";
        }
    }
}

// Carry the old single admin (app_settings) into the users table, once.
$seeded = Users::seedFromLegacy();
if ($seeded !== null) echo "  + users: migrated legacy admin {$seeded} (superadmin)\n";

echo "Migrated: " . (cfg('db.path') ?: (STORAGE_ROOT . '/skyledger.sqlite')) . "\n";
