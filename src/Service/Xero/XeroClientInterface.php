<?php
declare(strict_types=1);

namespace App\Service\Xero;

/** The seam every Xero call goes through. Stubbed when disconnected, live otherwise. */
interface XeroClientInterface
{
    /**
     * List ACTIVE supplier bills (ACCPAY: draft/submitted/authorised) — Module 3 input.
     * @return array{ok:bool, bills:array, tenant_id?:string, error?:string}
     */
    public function listActiveBills(): array;

    /**
     * Tag a draft bill with the matched trip number (into its Reference).
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function tagBill(string $invoiceId, string $reference): array;

    /**
     * Approve a supplier bill (DRAFT → AUTHORISED).
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function approveBill(string $invoiceId): array;

    /** Current Xero status of an invoice (e.g. AUTHORISED, VOIDED); '' if gone. */
    public function invoiceStatus(string $invoiceId): string;

    /**
     * Create a DRAFT client sales invoice (ACCREC) — Module 5.
     * @return array{ok:bool, invoice_id:?string, invoice_number:?string, stubbed:bool, error?:string}
     */
    public function createSalesInvoice(string $clientName, string $currency, string $reference, array $lines): array;
}
