<?php
declare(strict_types=1);

namespace App\Service\Invoices;

/**
 * Module 5 core: turn a trip's tagged supplier bills into the line items of a
 * client sales invoice — recharge each cost (× markup), add an admin % charge,
 * and an optional flat trip-support fee. Pure + testable.
 *
 * Multi-currency safety: if the tagged bills aren't all one currency we can't
 * total them without an FX rate, so the invoice is flagged NOT buildable and
 * left for a human to raise manually in Xero (matches the manual ×1.02 step).
 */
final class InvoiceBuilder
{
    /**
     * @param array $bills tagged xero_bills rows (need total, currency, description, supplier)
     * @param array $cfg   markup, admin_pct, support_fee, account_code
     * @return array{buildable:bool, reason:string, currency:string, lines:array, subtotal:float, admin:float, support:float, total:float}
     */
    public static function build(array $bills, array $cfg): array
    {
        $markup   = (float)($cfg['markup'] ?? 1.02);
        $adminPct = (float)($cfg['admin_pct'] ?? 11);
        $support  = round((float)($cfg['support_fee'] ?? 0), 2);
        $account  = trim((string)($cfg['account_code'] ?? ''));

        $bills = array_values(array_filter($bills, fn($b) => ($b['total'] ?? null) !== null));
        if (!$bills) {
            return self::empty('This trip has no tagged bills with an amount yet.');
        }

        $currencies = array_values(array_unique(array_map(fn($b) => strtoupper((string)($b['currency'] ?? '')), $bills)));
        if (count($currencies) > 1) {
            return self::empty('Bills are in multiple currencies (' . implode(', ', $currencies) . ') — raise this invoice manually in Xero (needs FX conversion).');
        }
        $currency = $currencies[0];

        $line = fn(string $desc, float $amt) => array_filter([
            'Description' => $desc,
            'Quantity'    => 1,
            'UnitAmount'  => round($amt, 2),
            'AccountCode' => $account !== '' ? $account : null,
        ], fn($v) => $v !== null);

        $lines = [];
        $subtotal = 0.0;
        foreach ($bills as $b) {
            $amt = round((float)$b['total'] * $markup, 2);
            $subtotal += $amt;
            $lines[] = $line(self::lineDescription($b), $amt);
        }
        $subtotal = round($subtotal, 2);

        $admin = round($subtotal * $adminPct / 100, 2);
        if ($admin > 0) $lines[] = $line('Admin charge (' . rtrim(rtrim(number_format($adminPct, 2), '0'), '.') . '%)', $admin);
        if ($support > 0) $lines[] = $line('Trip support fee', $support);

        return [
            'buildable' => true, 'reason' => '', 'currency' => $currency,
            'lines' => $lines, 'subtotal' => $subtotal, 'admin' => $admin,
            'support' => $support, 'total' => round($subtotal + $admin + $support, 2),
        ];
    }

    /**
     * A concise, client-facing recharge line for one supplier bill: the service
     * station, date and tail — NOT the supplier's name or their itemised charges
     * (that's the cost behind the markup, which the client shouldn't see). Falls
     * back to a generic label if the airport/date weren't extracted.
     *   "Ground handling & services at VHHH on 26/03/2026 (MAL191)"
     */
    private static function lineDescription(array $b): string
    {
        $airport = strtoupper(trim((string)($b['ex_airport'] ?? '')));
        $tail    = strtoupper(trim((string)($b['ex_tail'] ?? '')));
        $date    = self::displayDate((string)($b['ex_date'] ?? ''));

        $desc = $airport !== '' ? "Ground handling & services at {$airport}" : 'Ground handling & associated services';
        if ($date !== '') $desc .= " on {$date}";
        if ($tail !== '') $desc .= " ({$tail})";
        return $desc;
    }

    /** ISO yyyy-mm-dd → dd/mm/yyyy; anything else passes through unchanged. */
    private static function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : trim($iso);
    }

    private static function empty(string $reason): array
    {
        return ['buildable' => false, 'reason' => $reason, 'currency' => '', 'lines' => [], 'subtotal' => 0.0, 'admin' => 0.0, 'support' => 0.0, 'total' => 0.0];
    }

    /** Reference convention (from the AR walkthrough): registration + end date + trip number. */
    public static function reference(array $trip): string
    {
        return trim(((string)($trip['aircraft'] ?? '')) . ' ' . ((string)($trip['end_date'] ?? $trip['start_date'] ?? '')) . ' ' . ((string)($trip['trip_number'] ?? '')));
    }
}
