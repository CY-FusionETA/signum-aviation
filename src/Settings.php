<?php
declare(strict_types=1);

namespace App;

/**
 * Runtime settings: an app_settings key/value overlay on top of config.php.
 * A non-empty value saved in the DB wins; otherwise fall back to config.php.
 * Keys use the same dotted paths as config, e.g. "xero.client_id".
 * Ported from Starship.
 */
final class Settings
{
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all("SELECT key, value FROM app_settings") as $row) {
                self::$cache[$row['key']] = $row['value'];
            }
        }
        return self::$cache;
    }

    /** DB override if present & non-empty, else config.php, else $default. */
    public static function get(string $key, $default = null)
    {
        $all = self::load();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }
        return cfg($key, $default);
    }

    /** Raw DB value only (no config fallback) — for edit forms. */
    public static function raw(string $key, $default = null)
    {
        $all = self::load();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default ? '1' : '0');
        return in_array((string)$v, ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, ?string $value): void
    {
        Db::q(
            "INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
        self::$cache = null;
    }

    public static function forget(string $key): void
    {
        Db::q("DELETE FROM app_settings WHERE key = ?", [$key]);
        self::$cache = null;
    }
}
