<?php
declare(strict_types=1);

namespace App\Service\Xero;

/**
 * Stub used whenever Xero is not connected. It reads/creates nothing in Xero and
 * fails cleanly, so the app never errors when no org is linked.
 */
final class XeroStubClient implements XeroClientInterface
{
    public function listActiveBills(): array
    {
        return ['ok' => false, 'bills' => [], 'error' => 'Xero is not connected — connect an org to reconcile bills.'];
    }

    public function billHistory(string $invoiceId): array
    {
        return ['created' => '', 'note' => ''];
    }

    public function tagBill(string $invoiceId, string $reference): array
    {
        return ['ok' => false, 'stubbed' => true, 'error' => 'Xero is not connected.'];
    }

    public function approveBill(string $invoiceId): array
    {
        return ['ok' => false, 'stubbed' => true, 'error' => 'Xero is not connected.'];
    }

    public function invoiceStatus(string $invoiceId): string
    {
        return '';
    }

    public function createSalesInvoice(string $clientName, string $currency, string $reference, array $lines): array
    {
        return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => true, 'error' => 'Xero is not connected.'];
    }

    public function copyBillAttachmentsToInvoice(string $invoiceId, array $bills): array
    {
        return ['ok' => true, 'copied' => 0, 'failed' => 0, 'stubbed' => true];
    }

    public function linkBillCostsToInvoice(string $invoiceId, string $contactId, array $links): array
    {
        return ['ok' => true, 'linked' => 0, 'skipped' => 0, 'failed' => 0, 'stubbed' => true];
    }
}
