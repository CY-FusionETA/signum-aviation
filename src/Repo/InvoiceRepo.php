<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

/** Persistence for client sales invoices raised per trip. */
final class InvoiceRepo
{
    public static function findByTrip(string $tenantId, int $tripId): ?array
    {
        return Db::one("SELECT * FROM trip_invoices WHERE tenant_id = ? AND trip_id = ?", [$tenantId, $tripId]);
    }

    public static function store(string $tenantId, array $trip, array $build, string $invoiceId, string $invoiceNumber): void
    {
        Db::q("DELETE FROM trip_invoices WHERE tenant_id = ? AND trip_id = ?", [$tenantId, (int)$trip['id']]);
        Db::insert('trip_invoices', [
            'tenant_id'           => $tenantId,
            'trip_id'             => (int)$trip['id'],
            'trip_number'         => (string)$trip['trip_number'],
            'client'              => (string)$trip['client_name'],
            'currency'            => (string)$build['currency'],
            'subtotal'            => $build['subtotal'],
            'admin'               => $build['admin'],
            'support'             => $build['support'],
            'total'               => $build['total'],
            'xero_invoice_id'     => $invoiceId,
            'xero_invoice_number' => $invoiceNumber,
            'invoiced_at'         => date('Y-m-d H:i:s'),
        ]);
    }
}
