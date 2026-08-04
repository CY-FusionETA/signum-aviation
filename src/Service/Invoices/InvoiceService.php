<?php
declare(strict_types=1);

namespace App\Service\Invoices;

use App\Settings;
use App\Repo\BillRepo;
use App\Repo\TripRepo;
use App\Repo\InvoiceRepo;
use App\Service\Xero\XeroClientFactory;
use App\Service\Xero\XeroOAuth;

/**
 * Module 5 orchestration:
 *   readyTrips()    trips that have linked bills → each with its costs, a built
 *                   invoice preview, whether every bill is approved, and whether
 *                   it's already invoiced.
 *   createForTrip() raise the DRAFT client sales invoice in Xero. Invoices are
 *                   only created once a trip's bills are ALL approved (via the
 *                   Approve button) — there is no background auto-create.
 */
final class InvoiceService
{
    public static function config(): array
    {
        return [
            'markup'       => (float)Settings::get('invoice.markup', 1.02),
            'admin_pct'    => (float)Settings::get('invoice.admin_pct', 11),
            'support_fee'  => (float)Settings::get('invoice.support_fee', 0),
            'account_code' => (string)Settings::get('invoice.account_code', ''),
        ];
    }

    /** @return array<int,array{trip:array, bills:array, build:array, approved:bool, invoice:?array}> */
    public static function readyTrips(string $tenantId): array
    {
        $cfg = self::config();
        $byTrip = [];
        foreach (BillRepo::allForTenant($tenantId) as $b) {
            if (!in_array((string)$b['match_status'], ['tagged', 'approved'], true)) continue;
            $byTrip[(int)$b['matched_trip_id']][] = $b;
        }
        $out = [];
        foreach ($byTrip as $tripId => $bills) {
            $trip = TripRepo::findById((int)$tripId);
            if (!$trip) continue;
            $approved = array_reduce($bills, fn($c, $b) => $c && (string)$b['match_status'] === 'approved', true);
            $out[] = [
                'trip'     => $trip,
                'bills'    => $bills,
                'build'    => InvoiceBuilder::build($bills, $cfg),
                'complete' => CompletenessChecker::check($trip, $bills),
                'approved' => $approved,
                'invoice'  => InvoiceRepo::findByTrip($tenantId, (int)$tripId),
            ];
        }
        // Un-invoiced first, then by trip.
        usort($out, fn($a, $b) => ((int)!empty($a['invoice'])) <=> ((int)!empty($b['invoice'])));
        return $out;
    }

    /**
     * Raise the DRAFT client sales invoice for a trip. Requires every linked bill
     * to be approved and every route leg to be costed — the Approve flow enforces
     * this before calling here. @return array{ok:bool, invoice_number?:string, error?:string}
     */
    public static function createForTrip(int $tripId): array
    {
        if (!XeroOAuth::isConnected()) return ['ok' => false, 'error' => 'Connect Xero first.'];
        $tenantId = (string)(XeroOAuth::token()['tenant_id'] ?? '');

        $trip = TripRepo::findById($tripId);
        if (!$trip) return ['ok' => false, 'error' => 'Trip not found.'];
        if (InvoiceRepo::findByTrip($tenantId, $tripId)) return ['ok' => false, 'error' => 'This trip is already invoiced.'];
        if (trim((string)$trip['client_name']) === '') return ['ok' => false, 'error' => 'Trip has no client to invoice.'];

        $bills = BillRepo::forTrip($tenantId, $tripId);
        if (!$bills) return ['ok' => false, 'error' => 'This trip has no linked bills to invoice.'];
        foreach ($bills as $b) {
            if ((string)$b['match_status'] !== 'approved') return ['ok' => false, 'error' => 'Approve every bill on this trip first.'];
        }
        $build = InvoiceBuilder::build($bills, self::config());
        if (empty($build['buildable'])) return ['ok' => false, 'error' => $build['reason']];

        $client = XeroClientFactory::make();
        $res = $client->createSalesInvoice(
            (string)$trip['client_name'],
            (string)$build['currency'],
            InvoiceBuilder::reference($trip),
            $build['lines']
        );
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string)($res['error'] ?? 'Xero rejected the invoice.')];

        InvoiceRepo::store($tenantId, $trip, $build, (string)$res['invoice_id'], (string)$res['invoice_number']);

        // Copy each supplier bill's backup docs onto the client invoice (visible
        // online). Best-effort — the invoice stands even if a copy fails.
        $billMap = [];
        foreach ($bills as $b) {
            $bid = (string)($b['xero_invoice_id'] ?? '');
            if ($bid !== '') $billMap[$bid] = (string)($b['invoice_number'] ?? '');
        }
        $att = $billMap ? $client->copyBillAttachmentsToInvoice((string)$res['invoice_id'], $billMap) : ['copied' => 0, 'failed' => 0];

        return ['ok' => true, 'invoice_number' => (string)$res['invoice_number'],
                'attachments_copied' => (int)($att['copied'] ?? 0), 'attachments_failed' => (int)($att['failed'] ?? 0)];
    }
}
