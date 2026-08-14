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
     * Tag a draft bill with the matched trip number by writing it into the
     * "Trip No:" line of the bill's line-item description (Reference untouched).
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function tagBill(string $invoiceId, string $tripNumber): array;

    /**
     * Approve a supplier bill (DRAFT → AUTHORISED).
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function approveBill(string $invoiceId): array;

    /** Current Xero status of an invoice (e.g. AUTHORISED, VOIDED); '' if gone. */
    public function invoiceStatus(string $invoiceId): string;

    /**
     * Find a live supplier bill (ACCPAY) by its invoice number — used to clear the
     * bill a duplicate send collided with. VOIDED/DELETED bills no longer hold the
     * number and are skipped, so found=false means the number is free.
     * @return array{ok:bool, found:bool, invoice_id:string, invoice_number:string, status:string, supplier:string, total:?float, currency:string, error?:string}
     */
    public function findBillByNumber(string $invoiceNumber): array;

    /**
     * Delete a supplier bill in Xero (DRAFT/SUBMITTED → DELETED). An approved,
     * paid or otherwise live bill is refused — only an unfinished one is leftover.
     * Already deleted/voided counts as done.
     * @return array{ok:bool, status:string, stubbed:bool, error?:string}
     */
    public function deleteDraftBill(string $invoiceId): array;

    /**
     * The bill's Xero History in one call: its creation timestamp (UTC
     * "yyyy-mm-dd hh:mm:ss") and its latest manual note ("Add Note"). Either is ''
     * if unknown. The Invoices payload carries no created date (only
     * UpdatedDateUTC, which moves every time we tag or approve) and no notes.
     * @return array{created:string, note:string}
     */
    public function billHistory(string $invoiceId): array;

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
