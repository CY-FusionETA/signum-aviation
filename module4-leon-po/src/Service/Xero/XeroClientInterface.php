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
}
