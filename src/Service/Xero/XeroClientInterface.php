<?php
declare(strict_types=1);

namespace App\Service\Xero;

/** The seam every Xero call goes through. Stubbed when disconnected, live otherwise. */
interface XeroClientInterface
{
    /**
     * Push one LEON trip to Xero as a DRAFT Purchase Order.
     * @param array $trip A leon_trips row (trip_number, client_name, aircraft, route, dates, currency, ...)
     * @return array{xero_po_id: ?string, xero_po_number: ?string, stubbed: bool, error?: string, payload?: array}
     */
    public function createPurchaseOrder(array $trip): array;

    /**
     * List DRAFT supplier bills (ACCPAY) from the connected org — Module 3 input.
     * @return array{ok:bool, bills:array, tenant_id?:string, error?:string}
     */
    public function listDraftBills(): array;

    /**
     * Tag a draft bill with the matched trip number (into its Reference).
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function tagBill(string $invoiceId, string $reference): array;

    /**
     * Create a DRAFT client sales invoice (ACCREC) — Module 5.
     * @return array{ok:bool, invoice_id:?string, invoice_number:?string, stubbed:bool, error?:string}
     */
    public function createSalesInvoice(string $clientName, string $currency, string $reference, array $lines): array;
}
