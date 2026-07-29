<?php
declare(strict_types=1);

namespace App\Service\Xero;

/**
 * Live Xero client. Pushes one LEON trip to the connected organisation as a
 * DRAFT Purchase Order. Ported from Starship's XeroApiClient; adapted for
 * trip-metadata-only POs (no priced lines) with the trip's client as the
 * Xero contact. Never throws: on failure returns a null id + 'error' string
 * so a Xero outage never blocks the rest of the batch.
 */
final class XeroApiClient implements XeroClientInterface
{
    private const API = 'https://api.xero.com/api.xro/2.0/';

    public function createPurchaseOrder(array $trip): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) {
                return ['xero_po_id' => null, 'xero_po_number' => null, 'stubbed' => false, 'error' => 'Xero is not connected.'];
            }

            $contactId = $this->ensureContactId($auth, self::contactName($trip));
            if (!$contactId) {
                return ['xero_po_id' => null, 'xero_po_number' => null, 'stubbed' => false, 'error' => 'Could not resolve a Xero contact for this trip.'];
            }

            $order = self::buildOrderPayload($trip);
            $order['Contact'] = ['ContactID' => $contactId];

            [$code, $body] = XeroOAuth::http('POST', self::API . 'PurchaseOrders', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['PurchaseOrders' => [$order]], JSON_UNESCAPED_UNICODE));

            $json    = json_decode($body, true);
            $created = $json['PurchaseOrders'][0] ?? null;
            $xeroId  = $created['PurchaseOrderID'] ?? null;

            if ($code < 200 || $code >= 300 || !$xeroId) {
                return ['xero_po_id' => null, 'xero_po_number' => null, 'stubbed' => false, 'error' => self::extractError($json, $body)];
            }

            return [
                'xero_po_id'     => (string)$xeroId,
                'xero_po_number' => (string)($created['PurchaseOrderNumber'] ?? $order['PurchaseOrderNumber'] ?? ''),
                'stubbed'        => false,
            ];
        } catch (\Throwable $e) {
            return ['xero_po_id' => null, 'xero_po_number' => null, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the Xero PurchaseOrders payload for a trip (minus the Contact, which
     * the live client resolves to a ContactID). Kept public + pure so the stub
     * client can show the exact payload in a dry run.
     */
    public static function buildOrderPayload(array $trip): array
    {
        $route  = (string)($trip['route'] ?? '');
        $legs   = (int)($trip['flights_count'] ?? 0);
        $desc   = sprintf(
            'Trip %s | %s | %s | %s to %s | %d flight(s)',
            (string)($trip['trip_number'] ?? ''),
            (string)($trip['aircraft'] ?? '-'),
            $route !== '' ? $route : '-',
            (string)($trip['start_date'] ?? '-'),
            (string)($trip['end_date'] ?? '-'),
            $legs
        );

        // A description-only line (no Quantity/UnitAmount) — this is a trip
        // anchor PO, not a priced order. Xero accepts description-only lines.
        $lineItems = [['Description' => $desc]];

        $currency = trim((string)($trip['currency'] ?? ''));

        return array_filter([
            'PurchaseOrderNumber' => (string)($trip['trip_number'] ?? ''),
            'Date'                => self::iso($trip['start_date'] ?? null),
            'DeliveryDate'        => self::iso($trip['end_date'] ?? null),
            'Reference'           => trim(((string)($trip['aircraft'] ?? '')) . ' ' . $route),
            'CurrencyCode'        => $currency !== '' ? $currency : null,
            'Status'              => 'DRAFT',
            'LineItems'           => $lineItems,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Client is who the trip belongs to; blank client falls back to a placeholder. */
    public static function contactName(array $trip): string
    {
        $c = trim((string)($trip['client_name'] ?? ''));
        return $c !== '' ? $c : 'Unknown Client (LEON)';
    }

    private static function iso($d): ?string
    {
        $d = trim((string)$d);
        return $d !== '' ? substr($d, 0, 10) : null;
    }

    /**
     * Resolve a Xero ContactID by name, creating the contact if needed. Xero's
     * PurchaseOrders endpoint rejects a bare {Name}; it needs a ContactID.
     */
    private function ensureContactId(array $auth, string $name): ?string
    {
        $name = trim($name);
        if ($name === '') return null;

        $headers = [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        // 1) Look for an existing contact by exact name.
        $where = 'Name=="' . str_replace('"', '\"', $name) . '"';
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Contacts?where=' . rawurlencode($where), $headers);
        if ($code >= 200 && $code < 300) {
            $json = json_decode($body, true);
            $id = $json['Contacts'][0]['ContactID'] ?? null;
            if ($id) return (string)$id;
        }

        // 2) Otherwise create it.
        [$code, $body] = XeroOAuth::http('POST', self::API . 'Contacts', $headers,
            json_encode(['Contacts' => [['Name' => $name]]], JSON_UNESCAPED_UNICODE));
        if ($code >= 200 && $code < 300) {
            $json = json_decode($body, true);
            $id = $json['Contacts'][0]['ContactID'] ?? null;
            if ($id) return (string)$id;
        }
        return null;
    }

    /** Pull a human-readable message out of a Xero error/validation response. */
    private static function extractError(?array $json, string $raw): string
    {
        if (is_array($json)) {
            $els = $json['Elements'][0]['ValidationErrors'] ?? $json['ValidationErrors'] ?? null;
            if ($els) return implode('; ', array_map(fn($e) => $e['Message'] ?? '', $els));
            if (!empty($json['Message'])) return (string)$json['Message'];
            if (!empty($json['detail'])) return (string)$json['detail'];
        }
        return trim(substr($raw, 0, 300)) ?: 'Unknown Xero error.';
    }
}
