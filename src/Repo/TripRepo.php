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

    public static function markSynced(int $id, string $tenantId, string $xeroPoId, string $xeroPoNumber): void
    {
        Db::q(
            "UPDATE leon_trips
                SET tenant_id=?, xero_po_id=?, xero_po_number=?, xero_synced_at=CURRENT_TIMESTAMP,
                    xero_last_error=NULL, updated_at=CURRENT_TIMESTAMP
              WHERE id=?",
            [$tenantId, $xeroPoId, $xeroPoNumber, $id]
        );
    }

    public static function markError(int $id, string $error): void
    {
        Db::q("UPDATE leon_trips SET xero_last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$error, $id]);
    }

    /** Full master list, newest trips first. */
    public static function all(): array
    {
        return Db::all("SELECT * FROM leon_trips ORDER BY start_date DESC, trip_number DESC");
    }

    /**
     * Remove trips from the master list by id. Local only — a draft PO already
     * created in Xero is NOT deleted (void it in Xero if you want it gone).
     * Returns the number of rows removed.
     */
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

    /** Does this trip already have a PO in the given (current) tenant? */
    public static function hasPoInTenant(array $trip, string $tenantId): bool
    {
        return $tenantId !== '' && !empty($trip['xero_po_id']) && (string)$trip['tenant_id'] === $tenantId;
    }
}
