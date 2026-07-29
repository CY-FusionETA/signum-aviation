<?php
declare(strict_types=1);

namespace App\Service\Xero;

/**
 * Stub used whenever Xero is not connected. It creates nothing in Xero — it
 * returns the exact payload the live client WOULD send, so you can dry-run the
 * whole pipeline (parse → map → "push") before connecting an org.
 */
final class XeroStubClient implements XeroClientInterface
{
    public function createPurchaseOrder(array $trip): array
    {
        $payload = XeroApiClient::buildOrderPayload($trip);
        $payload['Contact'] = ['Name' => XeroApiClient::contactName($trip)];

        return [
            'xero_po_id'     => null,
            'xero_po_number' => (string)($payload['PurchaseOrderNumber'] ?? ''),
            'stubbed'        => true,
            'payload'        => $payload,
        ];
    }
}
