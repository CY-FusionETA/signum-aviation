<?php
declare(strict_types=1);

namespace App\Service\Bills;

/**
 * Module 3 core: match a supplier bill to a trip in the LEON master list.
 *
 * Best case the description follows WazzOCR's standard convention
 * "<Charge> at <ICAO> on <DD/MM/YYYY> for <Tail>". But descriptions vary, so we
 * ALSO scan the whole bill text for any trip's aircraft tail and any airport on
 * its route — matching works as long as the tail (and ideally the airport/date)
 * appear anywhere. Exactly one candidate → matched; several → ambiguous; none →
 * review (resolve by hand in the UI).
 */
final class BillMatcher
{
    /** @return array{status:string, trip:?array, ex_airport:string, ex_date:string, ex_tail:string} */
    public static function match(array $bill, array $trips): array
    {
        $text = trim(((string)($bill['description'] ?? '')) . ' ' . ((string)($bill['reference'] ?? '')));
        $UT = strtoupper($text);
        $NT = preg_replace('/[^A-Z0-9]/', '', $UT) ?? '';   // for tail substring hits

        // Strict standard-format extraction (used for the "Extracted" display).
        $exAirport = ''; $exDate = ''; $exTail = '';
        if (preg_match('/\bat\s+([A-Za-z]{4})\s+on\s+(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})\s+for\s+([A-Za-z0-9-]+)/i', $text, $m)) {
            $exAirport = strtoupper($m[1]);
            $exDate    = self::iso($m[2]);
            $exTail    = strtoupper($m[3]);
        } else {
            if (preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})/', $text, $mm)) $exDate = self::iso($mm[1]);
        }

        $base = ['status' => 'review', 'trip' => null, 'ex_airport' => $exAirport, 'ex_date' => $exDate, 'ex_tail' => $exTail];

        // 0) An explicit trip number in the text wins outright.
        foreach ($trips as $t) {
            $tn = (string)$t['trip_number'];
            if ($tn !== '' && preg_match('/(?<![A-Za-z0-9])' . preg_quote($tn, '/') . '(?![A-Za-z0-9])/', $text)) {
                return ['status' => 'matched', 'trip' => $t] + $base;
            }
        }

        // 1) Candidates = trips whose aircraft tail appears anywhere in the text.
        $cands = [];
        foreach ($trips as $t) {
            $nAir = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$t['aircraft'])) ?? '';
            if ($nAir === '' || strpos($NT, $nAir) === false) continue;
            $icao = self::routeIcaoInText((string)$t['route'], $UT);
            $cands[] = [
                'trip'    => $t,
                'airport' => $icao,
                'date'    => self::dateInWindow($exDate, (string)$t['start_date'], (string)$t['end_date']),
            ];
        }

        if ($cands) {
            $pick = null;
            if (count($cands) === 1)                                 $pick = $cands[0];
            elseif ($one = self::only($cands, fn($c) => $c['airport'] !== '' && $c['date'])) $pick = $one;
            elseif ($one = self::only($cands, fn($c) => $c['airport'] !== ''))               $pick = $one;
            elseif ($one = self::only($cands, fn($c) => $c['date']))                          $pick = $one;

            if ($pick) {
                $t = $pick['trip'];
                return [
                    'status'     => 'matched', 'trip' => $t,
                    'ex_airport' => $exAirport ?: $pick['airport'],
                    'ex_date'    => $exDate,
                    'ex_tail'    => $exTail ?: strtoupper((string)$t['aircraft']),
                ];
            }
            return ['status' => 'ambiguous', 'trip' => null] + $base;
        }

        // 2) No tail hit — last resort: a unique trip whose airport + date both fit.
        $ad = [];
        foreach ($trips as $t) {
            $icao = self::routeIcaoInText((string)$t['route'], $UT);
            if ($icao !== '' && self::dateInWindow($exDate, (string)$t['start_date'], (string)$t['end_date'])) $ad[] = $t;
        }
        if (count($ad) === 1) return ['status' => 'matched', 'trip' => $ad[0], 'ex_airport' => $exAirport ?: self::routeIcaoInText((string)$ad[0]['route'], $UT), 'ex_date' => $exDate, 'ex_tail' => $exTail];
        if (count($ad) > 1)  return ['status' => 'ambiguous', 'trip' => null] + $base;

        return $base; // review
    }

    /** @return array|null the sole element matching $pred, else null */
    private static function only(array $items, callable $pred): ?array
    {
        $hit = array_values(array_filter($items, $pred));
        return count($hit) === 1 ? $hit[0] : null;
    }

    /** First ICAO on the trip route that appears in the (uppercased) bill text, or ''. */
    private static function routeIcaoInText(string $route, string $UT): string
    {
        foreach (preg_split('/\s*-\s*/', trim($route)) ?: [] as $tok) {
            $tok = strtoupper(trim($tok));
            if (strlen($tok) === 4 && $tok !== 'ZZZZ' && strpos($UT, $tok) !== false) return $tok;
        }
        return '';
    }

    private static function dateInWindow(string $exDate, string $start, string $end): bool
    {
        if ($exDate === '' || $start === '') return false;
        $end = $end !== '' ? $end : $start;
        return $exDate >= $start && $exDate <= $end;
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
