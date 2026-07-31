<?php
declare(strict_types=1);

namespace App\Service\Xero;

/**
 * Live Xero client for the connected organisation: read DRAFT supplier bills
 * (ACCPAY), tag them with a trip number, and raise DRAFT client sales invoices
 * (ACCREC). Never throws: on failure returns a null id + 'error' string so a
 * Xero outage never blocks the rest of the batch.
 */
final class XeroApiClient implements XeroClientInterface
{
    private const API = 'https://api.xero.com/api.xro/2.0/';

    /**
     * Resolve a Xero ContactID by name, creating the contact if needed. Xero's
     * Invoices endpoint rejects a bare {Name}; it needs a ContactID.
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

    // --- Module 3: read + tag supplier bills (ACCPAY) ----------------

    /**
     * List DRAFT supplier bills (ACCPAY invoices) from the connected org — the
     * ones WazzOCR just created. Returns a flat shape for the reconciler.
     * @return array{ok:bool, bills:array, error?:string}
     */
    public function listDraftBills(): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'bills' => [], 'error' => 'Xero is not connected.'];

            $where = rawurlencode('Type=="ACCPAY" AND Status=="DRAFT"');
            [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices?where=' . $where, [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
            ]);
            $json = json_decode($body, true);
            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'bills' => [], 'error' => self::extractError($json, $body)];
            }
            // The org's base currency — every foreign bill is converted into it.
            $baseCurrency = self::orgBaseCurrency($auth);

            $bills = [];
            foreach ($json['Invoices'] ?? [] as $inv) {
                $descs = array_map(fn($l) => (string)($l['Description'] ?? ''), $inv['LineItems'] ?? []);
                // The Invoices *list* endpoint omits line items — fetch the bill by
                // ID to get the "…at VHHH…for MAL191" lines the matcher/legs need.
                if (!array_filter($descs) && ($id = (string)($inv['InvoiceID'] ?? '')) !== '') {
                    $descs = self::billLineDescriptions($auth, $id);
                }
                $currency = (string)($inv['CurrencyCode'] ?? '');
                $total    = isset($inv['Total']) ? (float)$inv['Total'] : null;
                // Xero's CurrencyRate on a bill = base-currency units per 1 unit of
                // the bill's currency, so base amount = Total × rate (rate is 1 when
                // the bill is already in the org's currency).
                $rate     = isset($inv['CurrencyRate']) ? (float)$inv['CurrencyRate'] : 1.0;
                if ($rate <= 0) $rate = 1.0;
                $bills[] = [
                    'invoice_id'     => (string)($inv['InvoiceID'] ?? ''),
                    'invoice_number' => (string)($inv['InvoiceNumber'] ?? ''),
                    'supplier'       => (string)($inv['Contact']['Name'] ?? ''),
                    'bill_date'      => self::xeroDate($inv['DateString'] ?? ($inv['Date'] ?? '')),
                    'reference'      => (string)($inv['Reference'] ?? ''),
                    'total'          => $total,
                    'currency'       => $currency,
                    'currency_rate'  => $rate,
                    'base_currency'  => $baseCurrency !== '' ? $baseCurrency : $currency,
                    'base_total'     => $total === null ? null : round($total * $rate, 2),
                    'description'    => trim(implode(' | ', array_filter($descs))),
                ];
            }
            return ['ok' => true, 'bills' => $bills, 'tenant_id' => $auth['tenant_id']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'bills' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Line-item descriptions for a single bill. The list endpoint returns no line
     * items, so we fetch the invoice by ID (which does). Returns [] on any error —
     * a bill with no readable lines just stays in review, it never blocks the pull.
     * @return string[]
     */
    /**
     * The connected org's base currency (e.g. MYR). Foreign bills are converted
     * into this. Returns '' if it can't be read — the caller then falls back to
     * each bill's own currency so nothing breaks.
     */
    private static function orgBaseCurrency(array $auth): string
    {
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Organisation', [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ]);
        if ($code < 200 || $code >= 300) return '';
        return strtoupper((string)(json_decode($body, true)['Organisations'][0]['BaseCurrency'] ?? ''));
    }

    private static function billLineDescriptions(array $auth, string $invoiceId): array
    {
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($invoiceId), [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ]);
        if ($code < 200 || $code >= 300) return [];
        $lines = json_decode($body, true)['Invoices'][0]['LineItems'] ?? [];
        return array_map(fn($l) => (string)($l['Description'] ?? ''), $lines);
    }

    /**
     * Tag a draft bill with the matched trip number (written to its Reference),
     * linking the supplier cost to the trip for later client recharge.
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function tagBill(string $invoiceId, string $reference): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            [$code, $body] = XeroOAuth::http('POST', self::API . 'Invoices', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['Invoices' => [['InvoiceID' => $invoiceId, 'Reference' => $reference]]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $ok = $code >= 200 && $code < 300 && !empty($json['Invoices'][0]['InvoiceID']);
            return $ok ? ['ok' => true, 'stubbed' => false]
                       : ['ok' => false, 'stubbed' => false, 'error' => self::extractError($json, $body)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    // --- Module 5: raise a client sales invoice (ACCREC) -------------

    /**
     * Create a DRAFT sales invoice (ACCREC) to a client.
     * @param array $lines  each ['Description','Quantity','UnitAmount', optional 'AccountCode','TaxType']
     * @return array{ok:bool, invoice_id:?string, invoice_number:?string, stubbed:bool, error?:string}
     */
    public function createSalesInvoice(string $clientName, string $currency, string $reference, array $lines): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            $contactId = $this->ensureContactId($auth, $clientName);
            if (!$contactId) return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => false, 'error' => 'Could not resolve a Xero contact for the client.'];

            $invoice = array_filter([
                'Type'         => 'ACCREC',
                'Contact'      => ['ContactID' => $contactId],
                'Reference'    => $reference,
                'CurrencyCode' => $currency !== '' ? $currency : null,
                'Status'       => 'DRAFT',
                'LineItems'    => $lines,
            ], fn($v) => $v !== null && $v !== '');

            [$code, $body] = XeroOAuth::http('POST', self::API . 'Invoices', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['Invoices' => [$invoice]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $inv  = $json['Invoices'][0] ?? null;
            $id   = $inv['InvoiceID'] ?? null;
            if ($code < 200 || $code >= 300 || !$id) {
                return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => false, 'error' => self::extractError($json, $body)];
            }
            return ['ok' => true, 'invoice_id' => (string)$id, 'invoice_number' => (string)($inv['InvoiceNumber'] ?? ''), 'stubbed' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /** Xero dates arrive as "2026-03-26T00:00:00" (DateString) or "/Date(ms+0000)/". */
    private static function xeroDate(string $d): string
    {
        $d = trim($d);
        if ($d === '') return '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $d, $m)) return $m[1];
        if (preg_match('#/Date\((-?\d+)#', $d, $m)) return gmdate('Y-m-d', (int)round(((int)$m[1]) / 1000));
        return '';
    }
}
