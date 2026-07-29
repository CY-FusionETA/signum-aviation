<?php
/** Create the SQLite database and all tables. Run once: php db/migrate.php */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;

$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) { fwrite(STDERR, "Cannot read schema.sql\n"); exit(1); }

Db::conn()->exec($sql);
echo "Migrated: " . (cfg('db.path') ?: (STORAGE_ROOT . '/skyledger.sqlite')) . "\n";
