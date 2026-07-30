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

    public function listDraftBills(): array
    {
        return ['ok' => false, 'bills' => [], 'error' => 'Xero is not connected — connect an org to reconcile bills.'];
    }

    public function tagBill(string $invoiceId, string $reference): array
    {
        return ['ok' => false, 'stubbed' => true, 'error' => 'Xero is not connected.'];
    }

    public function createSalesInvoice(string $clientName, string $currency, string $reference, array $lines): array
    {
        return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => true, 'error' => 'Xero is not connected.'];
    }
}
