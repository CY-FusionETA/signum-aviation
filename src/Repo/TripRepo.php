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

    /**
     * Insert or update the trip's metadata (never touches the xero_* columns).
     * Returns [$row, $wasNew].
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
            $set = implode(',', array_map(fn($c) => "{$c}=:{$c}", array_keys($fields)));
            $fields['__id'] = (int)$existing['id'];
            Db::q("UPDATE leon_trips SET {$set}, updated_at=CURRENT_TIMESTAMP WHERE id=:__id", $fields);
            return [self::find((string)$trip['trip_number'], $entity), false];
        }
        Db::insert('leon_trips', $fields);
        return [self::find((string)$trip['trip_number'], $entity), true];
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
