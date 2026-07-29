<?php
declare(strict_types=1);

namespace App\Service\Leon;

use App\Settings;
use App\Repo\TripRepo;
use App\Service\Xero\XeroClientFactory;
use App\Service\Xero\XeroOAuth;

/**
 * Module 4 orchestration, in two stages so the UI can put a human in the middle:
 *
 *   import()            parse a LEON CSV/PDF -> upsert into the trip MASTER LIST.
 *                       Creates nothing in Xero. This is the Starship "catalogue".
 *   createPosForIds()   create ONE draft Xero Purchase Order per selected trip.
 *
 * A trip already carrying an xero_po_id for the CURRENT tenant is skipped
 * (idempotent). Reconnecting to a new org clears those ids (see XeroOAuth::store)
 * so the same trips can be re-created there. The invoice-driven path (Modules
 * 2/3) reuses createPosForIds() to raise the PO once an invoice is matched.
 */
final class LeonProcessor
{
    /** Parse a LEON file into the master list. @return array{summary:array, trips:array, source:string} */
    public static function import(string $absPath, string $entity): array
    {
        $entity   = strtolower(trim($entity)) ?: 'inc';
        $parsed   = LeonParser::parse($absPath);
        $currency = (string)Settings::get("currency.{$entity}", '');
        $source   = basename($absPath);

        $trips = [];
        $summary = ['parsed' => count($parsed['trips']), 'new' => 0, 'updated' => 0, 'source' => $parsed['source']];
        foreach ($parsed['trips'] as $t) {
            $t['currency'] = $currency;
            [$row, $wasNew] = TripRepo::upsert($t, $entity, $source);
            $summary[$wasNew ? 'new' : 'updated']++;
            $trips[] = $row;
        }
        return ['summary' => $summary, 'trips' => $trips, 'source' => $parsed['source']];
    }

    /** Create draft POs for the given master-list trip ids. */
    public static function createPosForIds(array $ids, bool $dryRun = false): array
    {
        $trips = [];
        foreach ($ids as $id) {
            $row = TripRepo::findById((int)$id);
            if ($row) $trips[] = $row;
        }
        return self::createPos($trips, $dryRun);
    }

    /** @param array $trips master-list rows. */
    public static function createPos(array $trips, bool $dryRun = false): array
    {
        $client   = XeroClientFactory::make();
        $tenant   = XeroOAuth::isConnected() ? XeroOAuth::tenantName() : '';
        $tenantId = XeroOAuth::isConnected() ? (string)(XeroOAuth::token()['tenant_id'] ?? '') : '';

        $rows = [];
        $summary = ['selected' => count($trips), 'created' => 0, 'skipped' => 0, 'failed' => 0, 'dry_run' => 0];

        foreach ($trips as $trip) {
            if (!$dryRun && TripRepo::hasPoInTenant($trip, $tenantId)) {
                $summary['skipped']++;
                $rows[] = self::row($trip, 'skipped', 'Already created: PO ' . ($trip['xero_po_number'] ?: $trip['xero_po_id']));
                continue;
            }

            $res = $client->createPurchaseOrder($trip);

            if (!empty($res['stubbed'])) {
                $summary['dry_run']++;
                $rows[] = self::row($trip, 'dry_run', 'Would create draft PO', $res['payload'] ?? null);
            } elseif (!empty($res['xero_po_id'])) {
                TripRepo::markSynced((int)$trip['id'], $tenantId, (string)$res['xero_po_id'], (string)($res['xero_po_number'] ?? ''));
                $summary['created']++;
                $rows[] = self::row($trip, 'created', 'Draft PO ' . ($res['xero_po_number'] ?: $res['xero_po_id']));
            } else {
                $err = (string)($res['error'] ?? 'Unknown error');
                TripRepo::markError((int)$trip['id'], $err);
                $summary['failed']++;
                $rows[] = self::row($trip, 'failed', $err);
            }
        }
        return ['summary' => $summary, 'rows' => $rows, 'tenant' => $tenant];
    }

    /** Convenience for the CLI: import then create for every imported trip. */
    public static function process(string $absPath, string $entity, bool $dryRun = false): array
    {
        $imp = self::import($absPath, $entity);
        $out = self::createPos($imp['trips'], $dryRun);
        $out['import'] = $imp['summary'];
        return $out;
    }

    private static function row(array $trip, string $status, string $message, ?array $payload = null): array
    {
        return [
            'trip_number' => $trip['trip_number'],
            'client_name' => $trip['client_name'],
            'aircraft'    => $trip['aircraft'],
            'route'       => $trip['route'],
            'start_date'  => $trip['start_date'],
            'end_date'    => $trip['end_date'],
            'status'      => $status,
            'message'     => $message,
            'payload'     => $payload,
        ];
    }
}
