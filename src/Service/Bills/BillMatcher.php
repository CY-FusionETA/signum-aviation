<?php
declare(strict_types=1);

namespace App\Service\Bills;

/**
 * Module 3 core: match a supplier bill to a trip in the LEON master list.
 *
 * The bill's line description follows the WazzOCR standard convention
 * "<Charge> at <ICAO> on <DD/MM/YYYY> for <Tail>", so we pull the airport,
 * service date and aircraft tail from it (and any trip number in the reference/
 * text), then find the trip: same aircraft, service date inside the trip window,
 * airport on the route. Exactly one → matched; several → ambiguous; none → review.
 */
final class BillMatcher
{
    /** @return array{status:string, trip:?array, ex_airport:string, ex_date:string, ex_tail:string} */
    public static function match(array $bill, array $trips): array
    {
        $text = trim(((string)($bill['description'] ?? '')) . ' ' . ((string)($bill['reference'] ?? '')));

        $exAirport = ''; $exDate = ''; $exTail = '';
        if (preg_match('/\bat\s+([A-Za-z]{4})\s+on\s+(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})\s+for\s+([A-Za-z0-9-]+)/i', $text, $m)) {
            $exAirport = strtoupper($m[1]);
            $exDate    = self::iso($m[2]);
            $exTail    = strtoupper($m[3]);
        } else {
            // Looser fallbacks if the standard phrasing isn't present.
            if (preg_match('/\b([A-Z]{4})\b/', strtoupper($text), $mm)) $exAirport = $mm[1];
            if (preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})/', $text, $mm)) $exDate = self::iso($mm[1]);
        }

        $out = ['status' => 'review', 'trip' => null, 'ex_airport' => $exAirport, 'ex_date' => $exDate, 'ex_tail' => $exTail];

        // 0) A trip number explicitly present in the text wins.
        foreach ($trips as $t) {
            $tn = (string)$t['trip_number'];
            if ($tn !== '' && preg_match('/(?<![A-Za-z0-9])' . preg_quote($tn, '/') . '(?![A-Za-z0-9])/', $text)) {
                return ['status' => 'matched', 'trip' => $t] + $out;
            }
        }

        // Score each trip on tail / date-in-window / airport-on-route.
        $strong = [];  // tail && date && airport
        $weak   = [];  // tail && (date || airport)
        foreach ($trips as $t) {
            $tail  = self::tailMatch($exTail, (string)$t['aircraft']);
            $date  = self::dateInWindow($exDate, (string)$t['start_date'], (string)$t['end_date']);
            $air   = $exAirport !== '' && stripos((string)$t['route'], $exAirport) !== false;
            if ($tail && $date && $air) $strong[] = $t;
            elseif ($tail && ($date || $air)) $weak[] = $t;
        }

        if (count($strong) === 1) return ['status' => 'matched', 'trip' => $strong[0]] + $out;
        if (count($strong) > 1)   return ['status' => 'ambiguous', 'trip' => null] + $out;
        if (count($weak) === 1)   return ['status' => 'matched', 'trip' => $weak[0]] + $out;
        if (count($weak) > 1)     return ['status' => 'ambiguous', 'trip' => null] + $out;

        return $out; // review
    }

    private static function tailMatch(string $exTail, string $aircraft): bool
    {
        $norm = fn($s) => preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
        $a = $norm($exTail); $b = $norm($aircraft);
        return $a !== '' && $a === $b;
    }

    private static function dateInWindow(string $exDate, string $start, string $end): bool
    {
        if ($exDate === '' || $start === '') return false;
        $end = $end !== '' ? $end : $start;
        return $exDate >= $start && $exDate <= $end;   // ISO strings compare correctly
    }

    /** dd/mm/yyyy or dd-mm-yyyy (or already-ISO) → yyyy-mm-dd. */
    private static function iso(string $d): string
    {
        $d = trim($d);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('#^(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})$#', $d, $m)) {
            $y = (int)$m[3]; if ($y < 100) $y += 2000;
            return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
        }
        return '';
    }
}
