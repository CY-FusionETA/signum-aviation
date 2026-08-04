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

    /**
     * Copy each supplier bill's attachments onto a client sales invoice, so the
     * client sees the third-party backup on their invoice. Best-effort.
     * @param array<string,string> $bills  [bill Xero InvoiceID => bill number]
     * @return array{ok:bool, copied:int, failed:int, stubbed:bool, error?:string}
     */
    public function copyBillAttachmentsToInvoice(string $invoiceId, array $bills): array;

    /**
     * Record billable-expense links (Xero LinkedTransactions) marking each bill's
     * cost as recovered on the client invoice. Best-effort, idempotent. Internal
     * accounting flag only — does not change the invoice the client sees.
     * @param array<int,array{bill_id:string,target_line_id:string}> $links
     * @return array{ok:bool, linked:int, skipped:int, failed:int, stubbed:bool, error?:string}
     */
    public function linkBillCostsToInvoice(string $invoiceId, string $contactId, array $links): array;
}
