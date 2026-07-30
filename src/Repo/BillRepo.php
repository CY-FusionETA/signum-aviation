<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

/** Persistence for reconciled Xero bills. Keyed by (tenant_id, xero_invoice_id). */
final class BillRepo
{
    public static function find(string $tenantId, string $invoiceId): ?array
    {
        return Db::one("SELECT * FROM xero_bills WHERE tenant_id = ? AND xero_invoice_id = ?", [$tenantId, $invoiceId]);
    }

    public static function findById(int $id): ?array
    {
        return Db::one("SELECT * FROM xero_bills WHERE id = ?", [$id]);
    }

    /**
     * Insert/update a pulled bill + its match. Never clobbers a bill already
     * tagged (keeps the tagged state + timestamp).
     */
    public static function upsert(string $tenantId, array $bill, array $match): array
    {
        $existing = self::find($tenantId, (string)$bill['invoice_id']);
        $trip = $match['trip'] ?? null;
        $status = $existing && ($existing['match_status'] ?? '') === 'tagged' ? 'tagged' : $match['status'];

        $fields = [
            'tenant_id'           => $tenantId,
            'xero_invoice_id'     => (string)$bill['invoice_id'],
            'invoice_number'      => (string)($bill['invoice_number'] ?? ''),
            'supplier'            => (string)($bill['supplier'] ?? ''),
            'bill_date'           => (string)($bill['bill_date'] ?? ''),
            'reference'           => (string)($bill['reference'] ?? ''),
            'total'               => $bill['total'] ?? null,
            'currency'            => (string)($bill['currency'] ?? ''),
            'description'         => (string)($bill['description'] ?? ''),
            'ex_airport'          => (string)($match['ex_airport'] ?? ''),
            'ex_date'             => (string)($match['ex_date'] ?? ''),
            'ex_tail'             => (string)($match['ex_tail'] ?? ''),
            'match_status'        => $status,
            'matched_trip_id'     => $trip ? (int)$trip['id'] : null,
            'matched_trip_number' => $trip ? (string)$trip['trip_number'] : null,
            'matched_client'      => $trip ? (string)$trip['client_name'] : null,
        ];

        if ($existing) {
            $set = implode(',', array_map(fn($c) => "{$c}=:{$c}", array_keys($fields)));
            $fields['__id'] = (int)$existing['id'];
            Db::q("UPDATE xero_bills SET {$set}, updated_at=CURRENT_TIMESTAMP WHERE id=:__id", $fields);
            return self::find($tenantId, (string)$bill['invoice_id']);
        }
        Db::insert('xero_bills', $fields);
        return self::find($tenantId, (string)$bill['invoice_id']);
    }

    public static function markTagged(int $id): void
    {
        Db::q("UPDATE xero_bills SET match_status='tagged', tagged_at=CURRENT_TIMESTAMP, xero_last_error=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
    }

    public static function markError(int $id, string $error): void
    {
        Db::q("UPDATE xero_bills SET xero_last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$error, $id]);
    }

    public static function allForTenant(string $tenantId): array
    {
        return Db::all("SELECT * FROM xero_bills WHERE tenant_id = ? ORDER BY bill_date DESC, id DESC", [$tenantId]);
    }

    public static function matchedUntagged(string $tenantId): array
    {
        return Db::all("SELECT * FROM xero_bills WHERE tenant_id = ? AND match_status = 'matched'", [$tenantId]);
    }
}
