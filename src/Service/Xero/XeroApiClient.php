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
    /**
     * Why a call failed, in words an operator can act on. A 429 carries an EMPTY
     * body, so without the headers it degrades to 'Unknown Xero error' and reads
     * like a bug rather than a quota that refills on its own.
     */
    private static function errorFor(int $code, ?array $json, string $raw): string
    {
        if ($code === 429) {
            $after = (int)XeroOAuth::lastHeader('retry-after');
            $when  = $after > 0 ? ' Try again in about ' . self::humanWait($after) . '.' : '';
            switch (strtolower(XeroOAuth::lastHeader('x-rate-limit-problem'))) {
                case 'day':    return "Xero's daily API limit for this organisation is used up." . $when;
                case 'minute': return 'Too many Xero requests in the last minute.' . $when;
                default:       return 'Xero is rate-limiting requests.' . $when;
            }
        }
        return self::extractError($json, $raw);
    }

    /** True when the failure is Xero saying "not now" rather than "no". */
    public static function isRetryableCode(int $code): bool
    {
        return $code === 429 || $code >= 500;
    }

    private static function humanWait(int $seconds): string
    {
        if ($seconds < 90) return $seconds . 's';
        $m = (int)round($seconds / 60);
        if ($m < 90) return $m . ' minutes';
        $h = floor($m / 60); $r = $m % 60;
        return $r ? "{$h}h {$r}m" : "{$h}h";
    }

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
     * List ACTIVE supplier bills (ACCPAY) from the connected org — draft ones
     * just created plus ones already approved (authorised). Excludes
     * VOIDED/DELETED, so bills that vanish from this list have been retired in
     * Xero. Each bill carries its Xero 'status'. @return array{ok:bool, bills:array, error?:string}
     */
    public function listActiveBills(): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'bills' => [], 'error' => 'Xero is not connected.'];

            $where = rawurlencode('Type=="ACCPAY" AND (Status=="DRAFT" OR Status=="SUBMITTED" OR Status=="AUTHORISED" OR Status=="PAID")');
            [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices?where=' . $where, [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
            ]);
            $json = json_decode($body, true);
            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'bills' => [], 'error' => self::errorFor($code, $json, $body)];
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
                $rate     = isset($inv['CurrencyRate']) ? (float)$inv['CurrencyRate'] : 1.0;
                if ($rate <= 0) $rate = 1.0;
                $bills[] = [
                    'invoice_id'     => (string)($inv['InvoiceID'] ?? ''),
                    'invoice_number' => (string)($inv['InvoiceNumber'] ?? ''),
                    'supplier'       => (string)($inv['Contact']['Name'] ?? ''),
                    'bill_date'      => self::xeroDate($inv['DateString'] ?? ($inv['Date'] ?? '')),
                    'reference'      => (string)($inv['Reference'] ?? ''),
                    'status'         => strtoupper((string)($inv['Status'] ?? '')),
                    'total'          => $total,
                    'currency'       => $currency,
                    'currency_rate'  => $rate,
                    'base_currency'  => $baseCurrency !== '' ? $baseCurrency : $currency,
                    'base_total'     => self::baseAmount($total, $rate),
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
     * Convert a bill's own-currency total into the org base currency. Xero quotes
     * CurrencyRate as units of the BILL'S currency per 1 unit of the base currency
     * (e.g. a USD bill in an MYR org has rate ≈ 0.25 = USD per MYR), so the base
     * amount is total / rate. Rate 1 (bill already in base) leaves it unchanged.
     */
    public static function baseAmount(?float $total, float $rate): ?float
    {
        if ($total === null) return null;
        if ($rate <= 0) $rate = 1.0;
        return round($total / $rate, 2);
    }

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
        $org = json_decode($body, true)['Organisations'][0] ?? [];
        // Stash the short code while we're here: "View in Xero" links need it to
        // land in the right org rather than whichever one the browser last used.
        $short = (string)($org['ShortCode'] ?? '');
        if ($short !== '' && $short !== (string)\App\Settings::raw('xero.short_code', '')) {
            \App\Settings::set('xero.short_code', $short);
        }
        return strtoupper((string)($org['BaseCurrency'] ?? ''));
    }

    /**
     * Read the bill's Xero History once and return both its creation timestamp and
     * its latest manual note ("Add Note"). The Invoices endpoint has no created
     * date, and UpdatedDateUTC shifts whenever we tag or approve — so creation is
     * read from the "Created" history record. Notes appear as history records with
     * Changes "Note", their text in Details; the newest one is the bill's remark.
     * Either field is '' if absent; any error yields both '' and never blocks a pull.
     * @return array{created:string, note:string}
     */
    public function billHistory(string $invoiceId): array
    {
        $auth = XeroOAuth::token();
        if (!$auth || $invoiceId === '') return ['created' => '', 'note' => ''];
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($invoiceId) . '/History', [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ]);
        if ($code < 200 || $code >= 300) return ['created' => '', 'note' => ''];
        $records = json_decode($body, true)['HistoryRecords'] ?? [];
        if (!is_array($records)) return ['created' => '', 'note' => ''];
        $created = ''; $earliest = '';
        $note = ''; $noteWhen = '';
        foreach ($records as $r) {
            $when    = self::xeroDateTime((string)($r['DateUTCString'] ?? ($r['DateUTC'] ?? '')));
            $changes = trim((string)($r['Changes'] ?? ''));
            if ($when !== '') {
                // Prefer the explicit "Created" record; else the oldest entry.
                if (strcasecmp($changes, 'Created') === 0 && $created === '') $created = $when;
                if ($earliest === '' || $when < $earliest) $earliest = $when;
            }
            if (strcasecmp($changes, 'Note') === 0) {
                $detail = trim((string)($r['Details'] ?? ''));
                if ($detail !== '' && ($noteWhen === '' || $when >= $noteWhen)) { $noteWhen = $when; $note = $detail; }
            }
        }
        return ['created' => $created !== '' ? $created : $earliest, 'note' => $note];
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
     * Tag a draft bill with the matched trip number by writing it into the
     * "Trip No:" line of the bill's line-item description (the Reference is left
     * as the supplier invoice number). Links the supplier cost to the trip for
     * later client recharge.
     * @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function tagBill(string $invoiceId, string $tripNumber): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            // Read the bill's current lines, then write the trip number into the
            // "Trip No:" line of each description. Reference is not touched.
            [$gc, $gb] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($invoiceId), [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
            ]);
            if ($gc < 200 || $gc >= 300) return ['ok' => false, 'stubbed' => false, 'error' => 'Could not read the bill from Xero.'];
            $lines = json_decode($gb, true)['Invoices'][0]['LineItems'] ?? [];
            if (!$lines) return ['ok' => false, 'stubbed' => false, 'error' => 'Bill has no line items to tag.'];

            $out = self::embedTripInLines($lines, $tripNumber);

            [$code, $body] = XeroOAuth::http('POST', self::API . 'Invoices', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['Invoices' => [['InvoiceID' => $invoiceId, 'LineItems' => $out]]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $ok = $code >= 200 && $code < 300 && !empty($json['Invoices'][0]['InvoiceID']);
            return $ok ? ['ok' => true, 'stubbed' => false]
                       : ['ok' => false, 'stubbed' => false, 'error' => self::extractError($json, $body)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Write the trip number into the "Trip No:" line of each line-item
     * description, preserving LineItemID + amounts so Xero updates in place. If a
     * line has a "Trip No:" placeholder its value is replaced; if no line has one,
     * the trip number is appended to the first line. Only Description changes.
     * Pure + testable.
     * @param array $lines raw Xero LineItems
     * @return array lines ready to POST back
     */
    public static function embedTripInLines(array $lines, string $tripNumber): array
    {
        $anyHadTripLine = false;
        $out = [];
        foreach ($lines as $l) {
            $desc = (string)($l['Description'] ?? '');
            if (stripos($desc, 'Trip No:') !== false) {
                $desc = preg_replace_callback('/Trip No:[^\r\n]*/i', fn() => 'Trip No: ' . $tripNumber, $desc);
                $anyHadTripLine = true;
            }
            $out[] = array_filter([
                'LineItemID'  => $l['LineItemID'] ?? null,
                'Description' => $desc,
                'Quantity'    => $l['Quantity'] ?? null,
                'UnitAmount'  => $l['UnitAmount'] ?? null,
                'AccountCode' => $l['AccountCode'] ?? null,
                'ItemCode'    => $l['ItemCode'] ?? null,
                'TaxType'     => $l['TaxType'] ?? null,
            ], fn($v) => $v !== null);
        }
        if (!$anyHadTripLine && $out) {
            $out[0]['Description'] = rtrim((string)($out[0]['Description'] ?? '')) . "\nTrip No: " . $tripNumber;
        }
        return $out;
    }

    /**
     * Approve a supplier bill in Xero: move it DRAFT → AUTHORISED (awaiting
     * payment). @return array{ok:bool, stubbed:bool, error?:string}
     */
    public function approveBill(string $invoiceId): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            [$code, $body] = XeroOAuth::http('POST', self::API . 'Invoices', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['Invoices' => [['InvoiceID' => $invoiceId, 'Status' => 'AUTHORISED']]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $ok = $code >= 200 && $code < 300 && strtoupper((string)($json['Invoices'][0]['Status'] ?? '')) === 'AUTHORISED';
            return $ok ? ['ok' => true, 'stubbed' => false]
                       : ['ok' => false, 'stubbed' => false, 'error' => self::extractError($json, $body)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Current Xero status of one invoice (ACCPAY or ACCREC), e.g. AUTHORISED,
     * VOIDED, DELETED. Returns '' if it can't be read or no longer exists — used
     * to retire voided/deleted client invoices from Unidash.
     */
    public function invoiceStatus(string $invoiceId): string
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return '';
            [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($invoiceId), [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
            ]);
            if ($code < 200 || $code >= 300) return '';
            return strtoupper((string)(json_decode($body, true)['Invoices'][0]['Status'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Find a live supplier bill by invoice number. VOIDED/DELETED bills keep the
     * number in Xero's index but no longer reserve it, so they are skipped: found
     * = false means a new bill can carry that number again.
     */
    public function findBillByNumber(string $invoiceNumber): array
    {
        $invoiceNumber = trim($invoiceNumber);
        $none = [
            'ok' => false, 'found' => false, 'invoice_id' => '', 'invoice_number' => $invoiceNumber,
            'status' => '', 'supplier' => '', 'total' => null, 'currency' => '',
        ];
        if ($invoiceNumber === '') return $none + ['error' => 'No invoice number to look up.'];

        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return $none + ['error' => 'Xero is not connected.'];

            $where = rawurlencode('Type=="ACCPAY" AND InvoiceNumber=="' . str_replace('"', '\"', $invoiceNumber) . '"');
            [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices?where=' . $where, [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
            ]);
            $json = json_decode($body, true);
            if ($code < 200 || $code >= 300) {
                return $none + ['error' => self::errorFor($code, $json, $body), 'retryable' => self::isRetryableCode($code)];
            }

            foreach ($json['Invoices'] ?? [] as $inv) {
                $status = strtoupper((string)($inv['Status'] ?? ''));
                if ($status === 'VOIDED' || $status === 'DELETED') continue;
                return [
                    'ok'             => true,
                    'found'          => true,
                    'invoice_id'     => (string)($inv['InvoiceID'] ?? ''),
                    'invoice_number' => (string)($inv['InvoiceNumber'] ?? $invoiceNumber),
                    'status'         => $status,
                    'supplier'       => (string)($inv['Contact']['Name'] ?? ''),
                    'total'          => isset($inv['Total']) ? (float)$inv['Total'] : null,
                    'currency'       => (string)($inv['CurrencyCode'] ?? ''),
                ];
            }
            return ['ok' => true] + $none;   // looked up fine, nothing live under that number
        } catch (\Throwable $e) {
            return $none + ['error' => $e->getMessage()];
        }
    }

    /**
     * Delete a leftover supplier bill (DRAFT/SUBMITTED → DELETED), freeing its
     * invoice number so the invoice can be emailed in again. Xero only accepts
     * DELETED for those two statuses; an approved or paid bill is refused here
     * with wording the operator can act on rather than a raw Xero validation error.
     */
    public function deleteDraftBill(string $invoiceId): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'status' => '', 'stubbed' => false, 'error' => 'Xero is not connected.'];

            $status = $this->invoiceStatus($invoiceId);
            if ($status === '') {
                return ['ok' => false, 'status' => '', 'stubbed' => false, 'error' => 'That bill could not be read in Xero.'];
            }
            if ($status === 'DELETED' || $status === 'VOIDED') {
                return ['ok' => true, 'status' => $status, 'stubbed' => false];   // already gone
            }
            if ($status !== 'DRAFT' && $status !== 'SUBMITTED') {
                return ['ok' => false, 'status' => $status, 'stubbed' => false,
                        'error' => 'that bill is ' . strtolower($status) . ' in Xero, not a draft — open it in Xero and check it before removing it.'];
            }

            [$code, $body] = XeroOAuth::http('POST', self::API . 'Invoices', [
                'Authorization: Bearer ' . $auth['access_token'],
                'Xero-tenant-id: ' . $auth['tenant_id'],
                'Accept: application/json',
                'Content-Type: application/json',
            ], json_encode(['Invoices' => [['InvoiceID' => $invoiceId, 'Status' => 'DELETED']]], JSON_UNESCAPED_UNICODE));

            $json = json_decode($body, true);
            $now  = strtoupper((string)($json['Invoices'][0]['Status'] ?? ''));
            return ($code >= 200 && $code < 300 && $now === 'DELETED')
                ? ['ok' => true, 'status' => 'DELETED', 'stubbed' => false]
                : ['ok' => false, 'status' => $now, 'stubbed' => false, 'retryable' => self::isRetryableCode($code), 'error' => self::errorFor($code, $json, $body)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => '', 'stubbed' => false, 'error' => $e->getMessage()];
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
            // Line-item ids come back in the order we sent them — the caller pairs
            // them with the bills to record billable-expense links.
            $lineIds = array_values(array_filter(array_map(fn($l) => (string)($l['LineItemID'] ?? ''), $inv['LineItems'] ?? [])));
            return ['ok' => true, 'invoice_id' => (string)$id, 'invoice_number' => (string)($inv['InvoiceNumber'] ?? ''),
                    'contact_id' => (string)$contactId, 'line_item_ids' => $lineIds, 'stubbed' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'invoice_id' => null, 'invoice_number' => null, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    // --- Module 5: copy supplier-bill attachments onto the client invoice ---

    /**
     * Copy every attachment from each supplier bill onto the client sales invoice
     * so the client sees the third-party backup. Filenames are prefixed with the
     * bill number (and de-collided) so two bills' "invoice.pdf" don't overwrite
     * each other. IncludeOnline=true makes each file visible on the online invoice.
     * Best-effort: a copy failure never rolls back the already-created invoice.
     * @param array<string,string> $bills  [bill Xero InvoiceID => bill number]
     * @return array{ok:bool, copied:int, failed:int, stubbed:bool, error?:string}
     */
    public function copyBillAttachmentsToInvoice(string $invoiceId, array $bills): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'copied' => 0, 'failed' => 0, 'stubbed' => false, 'error' => 'Xero is not connected.'];

            $copied = 0; $failed = 0; $used = [];
            foreach ($bills as $billId => $billNumber) {
                $billId = (string)$billId;
                if ($billId === '') continue;
                foreach (self::listAttachments($auth, $billId) as $att) {
                    $name = (string)($att['FileName'] ?? '');
                    $mime = (string)($att['MimeType'] ?? 'application/octet-stream');
                    if ($name === '') continue;
                    $bytes = self::downloadAttachment($auth, $billId, $name, $mime);
                    if ($bytes === null || $bytes === '') { $failed++; continue; }
                    $target = self::attachmentName((string)$billNumber, $name, $used);
                    self::uploadAttachment($auth, $invoiceId, $target, $bytes, $mime) ? $copied++ : $failed++;
                }
            }
            return ['ok' => $failed === 0, 'copied' => $copied, 'failed' => $failed, 'stubbed' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'copied' => 0, 'failed' => 0, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /** List one invoice's attachments. @return array<int,array> [] on any error. */
    private static function listAttachments(array $auth, string $invoiceId): array
    {
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($invoiceId) . '/Attachments', [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ]);
        if ($code < 200 || $code >= 300) return [];
        $json = json_decode($body, true);
        return is_array($json['Attachments'] ?? null) ? $json['Attachments'] : [];
    }

    /** Download one attachment's raw bytes. Returns null on any error. */
    private static function downloadAttachment(array $auth, string $invoiceId, string $fileName, string $mime): ?string
    {
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($invoiceId) . '/Attachments/' . rawurlencode($fileName), [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: ' . ($mime !== '' ? $mime : '*/*'),
        ]);
        return ($code >= 200 && $code < 300) ? $body : null;
    }

    /** Upload raw bytes as an attachment on an invoice. IncludeOnline for viewable types. */
    private static function uploadAttachment(array $auth, string $invoiceId, string $fileName, string $bytes, string $mime): bool
    {
        // IncludeOnline is only accepted for browser-viewable types on ACCREC invoices.
        $online = in_array(strtolower($mime), ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/gif'], true);
        $url = self::API . 'Invoices/' . rawurlencode($invoiceId) . '/Attachments/' . rawurlencode($fileName)
             . ($online ? '?IncludeOnline=true' : '');
        [$code] = XeroOAuth::http('PUT', $url, [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
            'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'),
            'Content-Length: ' . strlen($bytes),
        ], $bytes);
        return $code >= 200 && $code < 300;
    }

    // --- Module 5: billable-expense links (LinkedTransactions) --------------

    /**
     * Record that each supplier bill's cost has been recovered on the client
     * invoice, via Xero LinkedTransactions (billable expenses). Every source line
     * on a bill is linked to that bill's recharge line on the invoice. Best-effort
     * and idempotent: source lines already linked are skipped, so a re-run adds
     * nothing. Purely an internal cost-recovery flag — the client sees no change.
     * @param array<int,array{bill_id:string,target_line_id:string}> $links
     * @return array{ok:bool, linked:int, skipped:int, failed:int, stubbed:bool, error?:string}
     */
    public function linkBillCostsToInvoice(string $invoiceId, string $contactId, array $links): array
    {
        try {
            $auth = XeroOAuth::accessToken();
            if (!$auth) return ['ok' => false, 'linked' => 0, 'skipped' => 0, 'failed' => 0, 'stubbed' => false, 'error' => 'Xero is not connected.'];
            if ($invoiceId === '' || $contactId === '') return ['ok' => false, 'linked' => 0, 'skipped' => 0, 'failed' => 0, 'stubbed' => false, 'error' => 'Missing invoice or contact id.'];

            $linked = 0; $skipped = 0; $failed = 0;
            foreach ($links as $lk) {
                $billId     = (string)($lk['bill_id'] ?? '');
                $targetLine = (string)($lk['target_line_id'] ?? '');
                if ($billId === '' || $targetLine === '') { $failed++; continue; }

                $already = self::linkedSourceLineIds($auth, $billId);   // don't double-link
                foreach (self::billLineItemIds($auth, $billId) as $srcLine) {
                    if ($srcLine === '') continue;
                    if (isset($already[$srcLine])) { $skipped++; continue; }
                    self::putLinkedTransaction($auth, [
                        'SourceTransactionID' => $billId,
                        'SourceLineItemID'    => $srcLine,
                        'ContactID'           => $contactId,
                        'TargetTransactionID' => $invoiceId,
                        'TargetLineItemID'    => $targetLine,
                    ]) ? $linked++ : $failed++;
                }
            }
            return ['ok' => $failed === 0, 'linked' => $linked, 'skipped' => $skipped, 'failed' => $failed, 'stubbed' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'linked' => 0, 'skipped' => 0, 'failed' => 0, 'stubbed' => false, 'error' => $e->getMessage()];
        }
    }

    /** Line-item ids on one bill (the source lines to link). [] on any error. @return string[] */
    private static function billLineItemIds(array $auth, string $billId): array
    {
        [$code, $body] = XeroOAuth::http('GET', self::API . 'Invoices/' . rawurlencode($billId), [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ]);
        if ($code < 200 || $code >= 300) return [];
        $json  = json_decode($body, true);
        $items = $json['Invoices'][0]['LineItems'] ?? [];
        return array_values(array_filter(array_map(fn($l) => (string)($l['LineItemID'] ?? ''), $items)));
    }

    /** Source line ids already linked for this bill, so we never double-link. @return array<string,true> */
    private static function linkedSourceLineIds(array $auth, string $billId): array
    {
        [$code, $body] = XeroOAuth::http('GET', self::API . 'LinkedTransactions?SourceTransactionID=' . rawurlencode($billId), [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ]);
        if ($code < 200 || $code >= 300) return [];
        $json = json_decode($body, true);
        $out  = [];
        foreach ($json['LinkedTransactions'] ?? [] as $lt) {
            $sid = (string)($lt['SourceLineItemID'] ?? '');
            if ($sid !== '') $out[$sid] = true;
        }
        return $out;
    }

    /** Create one LinkedTransaction (PUT = create). Returns success. */
    private static function putLinkedTransaction(array $auth, array $payload): bool
    {
        [$code] = XeroOAuth::http('PUT', self::API . 'LinkedTransactions', [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
            'Content-Type: application/json',
        ], json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $code >= 200 && $code < 300;
    }

    /**
     * Build a unique, Xero-safe attachment filename: "<bill no> - <original>",
     * de-collided with " (2)", " (3)"… when the same name recurs. $used is a
     * lowercase-name set carried across a copy run. Pure — unit tested.
     */
    public static function attachmentName(string $billNumber, string $fileName, array &$used): string
    {
        $billNumber = trim($billNumber);
        $base = ($billNumber !== '' ? $billNumber . ' - ' : '') . $fileName;
        $base = preg_replace('#[/\\\\:*?"<>|]+#', '_', $base);          // strip path/illegal chars
        $base = trim((string)$base) !== '' ? (string)$base : 'attachment';

        $candidate = $base; $i = 2;
        while (isset($used[strtolower($candidate)])) {
            $dot = strrpos($base, '.');
            $candidate = $dot !== false && $dot > 0
                ? substr($base, 0, $dot) . " ($i)" . substr($base, $dot)
                : $base . " ($i)";
            $i++;
        }
        $used[strtolower($candidate)] = true;
        return $candidate;
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

    /** Same shapes as xeroDate(), but keeping the time — UTC "yyyy-mm-dd hh:mm:ss". */
    private static function xeroDateTime(string $d): string
    {
        $d = trim($d);
        if ($d === '') return '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $d, $m)) return $m[1] . ' ' . $m[2];
        if (preg_match('#/Date\((-?\d+)#', $d, $m)) return gmdate('Y-m-d H:i:s', (int)round(((int)$m[1]) / 1000));
        return '';
    }
}
