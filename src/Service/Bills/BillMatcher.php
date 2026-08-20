<?php
declare(strict_types=1);

namespace App\Service\Bills;

/**
 * Module 3 core: match a supplier bill to a trip in the LEON master list.
 *
 * Two ways in, and only two:
 *   1. The bill carries a trip number → that trip, full stop. The tail, flight
 *      date and ICAO are not needed, and are never copied onto the bill from the
 *      trip — what the handler wrote is what the bill keeps saying.
 *   2. No trip number → the bill must carry ALL THREE of tail, flight date and
 *      ICAO. Any one of them missing and the bill cannot be matched at all;
 *      partial detail is exactly how a bill ends up on the wrong trip.
 *
 * Every outcome that is not a match carries a `reason` written for the person
 * looking at the Bills tab: what was missing, or which of the three did not line
 * up with LEON. Statuses: matched | ambiguous | review.
 */
final class BillMatcher
{
    /** Trips named in a reason before it falls back to a count. */
    private const NAME_LIMIT = 3;

    /** @return array{status:string, trip:?array, ex_airport:string, ex_date:string, ex_tail:string, reason:string} */
    public static function match(array $bill, array $trips): array
    {
        $text = trim(((string)($bill['description'] ?? '')) . ' ' . ((string)($bill['reference'] ?? '')));
        $f    = BillFields::extract($text, $trips);

        // 1) A trip number on the bill decides it outright.
        if ($f['trip_number'] !== '') {
            foreach ($trips as $t) {
                if (strcasecmp(trim((string)$t['trip_number']), $f['trip_number']) === 0) {
                    return self::out('matched', $t, $f, '');
                }
            }
            return self::out('review', null, $f, "Trip {$f['trip_number']} is on the bill, but there is no such trip in the LEON master list — import the LEON file that has it.");
        }

        // 2) No trip number: all three details are required before we look.
        $missing = [];
        if ($f['tail'] === '')    $missing[] = 'aircraft tail';
        if ($f['date'] === '')    $missing[] = 'flight date';
        if ($f['airport'] === '') $missing[] = 'ICAO code';
        if ($missing) {
            $have = array_values(array_diff(['aircraft tail', 'flight date', 'ICAO code'], $missing));
            if (!$have) {
                return self::out('review', null, $f, 'Nothing on this bill identifies a trip — no trip number, aircraft tail, flight date or ICAO code. Key in the trip number.');
            }
            return self::out('review', null, $f, 'No trip number on the bill, and no ' . self::andList($missing)
                . ' — only the ' . self::andList($have) . '. All three are needed when the trip number is absent, so key in the trip number.');
        }

        $shown = self::dmy($f['date']);

        // Narrow by tail, then date, then ICAO — reporting whichever step empties out.
        $byTail = array_values(array_filter($trips, fn($t) => self::sameTail((string)($t['aircraft'] ?? ''), $f['tail'])));
        if (!$byTail) {
            return self::out('review', null, $f, "No trip in the LEON master list flies {$f['tail']} — check the tail on the bill, or import the LEON file for this trip.");
        }

        $onDate = array_values(array_filter($byTail, fn($t) => self::dateInWindow($f['date'], (string)$t['start_date'], (string)$t['end_date'])));
        if (!$onDate) {
            return self::out('review', null, $f, "{$f['tail']} is in LEON, but none of its trips covers {$shown} — " . self::windows($byTail) . '.');
        }

        $onRoute = array_values(array_filter($onDate, fn($t) => in_array($f['airport'], BillFields::routeIcaos((string)$t['route']), true)));
        if (!$onRoute) {
            return self::out('review', null, $f, "{$f['tail']} on {$shown} is " . self::tripList($onDate) . ", but {$f['airport']} is not on " . (count($onDate) === 1 ? 'its route' : 'their routes') . ' — ' . self::routes($onDate) . '.');
        }
        if (count($onRoute) > 1) {
            return self::out('ambiguous', null, $f, count($onRoute) . " LEON trips fit {$f['tail']} / {$f['airport']} / {$shown} (" . self::tripList($onRoute) . ') — key in the right trip number.');
        }

        return self::out('matched', $onRoute[0], $f, '');
    }

    /** @return array{status:string, trip:?array, ex_airport:string, ex_date:string, ex_tail:string, reason:string} */
    private static function out(string $status, ?array $trip, array $f, string $reason): array
    {
        return [
            'status'     => $status,
            'trip'       => $trip,
            'ex_airport' => $f['airport'],
            'ex_date'    => $f['date'],
            'ex_tail'    => $f['tail'],
            'reason'     => $reason,
        ];
    }

    /** Tails compare on letters+digits only, so "M-ABCD" and "MABCD" are one aircraft. */
    private static function sameTail(string $a, string $b): bool
    {
        $n = fn(string $s) => preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($s))) ?? '';
        return $n($a) !== '' && $n($a) === $n($b);
    }

    private static function dateInWindow(string $date, string $start, string $end): bool
    {
        if ($date === '' || $start === '') return false;
        $end = $end !== '' ? $end : $start;
        return $date >= $start && $date <= $end;
    }

    /** "trip 99751" / "trips 99751, 35528" — capped, so a reason stays readable. */
    private static function tripList(array $trips): string
    {
        $nums = array_map(fn($t) => (string)$t['trip_number'], array_slice($trips, 0, self::NAME_LIMIT));
        $more = count($trips) - count($nums);
        return (count($trips) === 1 ? 'trip ' : 'trips ') . implode(', ', $nums) . ($more > 0 ? " and {$more} more" : '');
    }

    /** "trip 99751 runs 26/03–30/03/2026" for each candidate. */
    private static function windows(array $trips): string
    {
        $out = [];
        foreach (array_slice($trips, 0, self::NAME_LIMIT) as $t) {
            $s = self::dmy((string)$t['start_date']);
            $e = self::dmy((string)$t['end_date']);
            $out[] = 'trip ' . $t['trip_number'] . ' runs ' . ($e !== '' && $e !== $s ? "{$s}–{$e}" : $s);
        }
        $more = count($trips) - count($out);
        return implode('; ', $out) . ($more > 0 ? "; and {$more} more" : '');
    }

    /** "trip 99751 flies VHHH - WMKK" for each candidate. */
    private static function routes(array $trips): string
    {
        $out = [];
        foreach (array_slice($trips, 0, self::NAME_LIMIT) as $t) {
            $out[] = 'trip ' . $t['trip_number'] . ' flies ' . ((string)$t['route'] !== '' ? $t['route'] : 'no route in LEON');
        }
        return implode('; ', $out);
    }

    /** "a, b and c" */
    private static function andList(array $items): string
    {
        if (count($items) <= 1) return (string)($items[0] ?? '');
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    /** ISO yyyy-mm-dd → dd/mm/yyyy for reading. */
    private static function dmy(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : trim($iso);
    }
}
