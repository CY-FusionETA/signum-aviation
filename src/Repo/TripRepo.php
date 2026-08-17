<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

/** Persistence for the LEON trip master list. Keyed by (trip_number, entity). */
final class TripRepo
{
    public static function find(string $tripNumber, string $entity): ?array
    {
        return Db::one("SELECT * FROM leon_trips WHERE trip_number = ? AND entity = ?", [$tripNumber, $entity]);
    }

    public static function findById(int $id): ?array
    {
        return Db::one("SELECT * FROM leon_trips WHERE id = ?", [$id]);
    }

    /** First trip in the master list with this trip number (any entity), or null. */
    public static function findByNumber(string $tripNumber): ?array
    {
        $tripNumber = trim($tripNumber);
        if ($tripNumber === '') return null;
        return Db::one("SELECT * FROM leon_trips WHERE trip_number = ? ORDER BY start_date DESC LIMIT 1", [$tripNumber]);
    }

    /** The trip fields a re-import compares to decide if a row actually changed. */
    private const CONTENT_FIELDS = ['client_name', 'aircraft', 'route', 'start_date', 'end_date', 'flights_count', 'currency'];

    /**
     * Insert or update the trip's metadata (never touches the xero_* columns).
     * Re-importing a file only re-files the rows that actually changed: an existing
     * trip whose content matches is left untouched (its updated_at does not move),
     * so uploading a fresh LEON export "moves" only the records that need it.
     * Returns [$row, $status] where $status is 'new' | 'updated' | 'unchanged'.
     */
    public static function upsert(array $trip, string $entity, string $sourceFile): array
    {
        $existing = self::find((string)$trip['trip_number'], $entity);
        $fields = [
            'entity'        => $entity,
            'trip_number'   => (string)$trip['trip_number'],
            'client_name'   => (string)($trip['client_name'] ?? ''),
            'aircraft'      => (string)($trip['aircraft'] ?? ''),
            'route'         => (string)($trip['route'] ?? ''),
            'start_date'    => (string)($trip['start_date'] ?? ''),
            'end_date'      => (string)($trip['end_date'] ?? ''),
            'flights_count' => $trip['flights_count'] ?? null,
            'currency'      => (string)($trip['currency'] ?? ''),
            'source_file'   => $sourceFile,
        ];

        if ($existing) {
            if (!self::contentChanged($existing, $fields)) {
                return [$existing, 'unchanged'];         // same data → don't re-file it
            }
            $set = implode(',', array_map(fn($c) => "{$c}=:{$c}", array_keys($fields)));
            $fields['__id'] = (int)$existing['id'];
            Db::q("UPDATE leon_trips SET {$set}, updated_at=CURRENT_TIMESTAMP WHERE id=:__id", $fields);
            return [self::find((string)$trip['trip_number'], $entity), 'updated'];
        }
        Db::insert('leon_trips', $fields);
        return [self::find((string)$trip['trip_number'], $entity), 'new'];
    }

    /** True if any content field differs between the stored row and the incoming one. */
    private static function contentChanged(array $existing, array $incoming): bool
    {
        foreach (self::CONTENT_FIELDS as $f) {
            if ((string)($existing[$f] ?? '') !== (string)($incoming[$f] ?? '')) return true;
        }
        return false;
    }

    /** Full master list, newest trips first. */
    public static function all(): array
    {
        return Db::all("SELECT * FROM leon_trips ORDER BY start_date DESC, trip_number DESC");
    }

    /** Route legs (ICAOs) a user has marked "no bill expected" on this trip. @return string[] */
    public static function waivedLegs($trip): array
    {
        $raw = is_array($trip) ? ($trip['waived_legs'] ?? '') : $trip;
        if (is_array($raw)) $list = $raw;
        else {
            $raw = trim((string)$raw);
            if ($raw === '') return [];
            $list = json_decode($raw, true);
            if (!is_array($list)) $list = preg_split('/\s*,\s*/', $raw) ?: [];
        }
        $out = [];
        foreach ($list as $a) {
            $a = strtoupper(trim((string)$a));
            if ($a !== '' && !in_array($a, $out, true)) $out[] = $a;
        }
        return $out;
    }

    /**
     * Add or remove one route leg from a trip's "no bill expected" set.
     * @return string[] the new waived-leg list.
     */
    public static function toggleWaivedLeg(int $id, string $icao): array
    {
        $trip = self::findById($id);
        if (!$trip) return [];
        $icao = strtoupper(trim($icao));
        $legs = self::waivedLegs($trip);
        $legs = in_array($icao, $legs, true)
            ? array_values(array_filter($legs, fn($l) => $l !== $icao))
            : array_merge($legs, [$icao]);
        Db::q("UPDATE leon_trips SET waived_legs = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [json_encode(array_values($legs)), $id]);
        return $legs;
    }

    /** Remove trips from the master list by id (local only). Returns rows removed. */
    public static function deleteIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Db::q("DELETE FROM leon_trips WHERE id IN ({$ph})", $ids)->rowCount();
    }

    /** Empty the whole master list. Returns the number of rows removed. */
    public static function deleteAll(): int
    {
        return Db::q("DELETE FROM leon_trips")->rowCount();
    }
}
