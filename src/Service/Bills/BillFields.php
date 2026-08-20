<?php
declare(strict_types=1);

namespace App\Service\Bills;

/**
 * Pull the four identifying fields out of a supplier bill's text.
 *
 * A bill can be matched by its trip number, or by tail + flight date + ICAO —
 * and that second route needs ALL THREE. So "is it on the bill?" has to be
 * answered field by field, rather than by one strict "at X on Y for Z" phrase
 * that fails as a whole. Each field is looked for three ways: the standard
 * phrasing, a labelled line ("Tail: M-ABCD"), and — for the tail and the ICAO —
 * a scan for anything the LEON master list already knows.
 *
 * Nothing is ever inferred from a trip: a field is either on the bill or it is
 * reported missing, so the reason shown to the user is always the truth.
 */
final class BillFields
{
    /**
     * @param  array $trips the LEON master list — used only as a vocabulary of
     *                      known tails / ICAOs / trip numbers, never as a source
     *                      of values to fill in.
     * @return array{trip_number:string, tail:string, airport:string, date:string}
     */
    public static function extract(string $text, array $trips): array
    {
        $UT = strtoupper($text);
        $NT = preg_replace('/[^A-Z0-9]/', '', $UT) ?? '';   // for tail substring hits

        $tail = ''; $airport = ''; $date = '';

        // 1) The standard OCR phrasing carries all three at once.
        if (preg_match('/\bAT\s+([A-Z]{4})\s+ON\s+(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})\s+FOR\s+([A-Z0-9-]+)/', $UT, $m)) {
            $airport = $m[1];
            $date    = self::iso($m[2]);
            $tail    = $m[3];
        }

        // 2) Labelled lines, e.g. a "Tail: M-ABCD" the handler typed in.
        if ($tail === '')    $tail    = self::labelled($UT, ['TAIL', 'REGISTRATION', 'REG', 'AIRCRAFT'], '[A-Z0-9][A-Z0-9-]{2,9}');
        if ($airport === '') $airport = self::labelled($UT, ['ICAO', 'AIRPORT', 'STATION'], '[A-Z]{4}');
        if ($date === '') {
            // Skip the bill's own dates — "Invoice date" / "Due date" are not the
            // flight date, and picking one up would match the wrong trip.
            $d = self::labelled($UT, ['FLIGHT DATE', 'SERVICE DATE', '(?<!INVOICE )(?<!DUE )DATE'], '[0-9][0-9\/-]{5,9}');
            if ($d !== '') $date = self::iso($d);
        }

        // 3) Anything the master list already knows, anywhere in the text.
        if ($tail === '')    $tail    = self::knownTail($NT, $trips);
        if ($airport === '') $airport = self::knownIcao($UT, $trips);
        if ($date === '' && preg_match('/(\d{4}-\d{2}-\d{2})/', $UT, $mm))                    $date = self::iso($mm[1]);
        if ($date === '' && preg_match('/(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})/', $UT, $mm))      $date = self::iso($mm[1]);

        return [
            'trip_number' => self::tripNumber($UT, $trips),
            'tail'        => $tail,
            'airport'     => $airport,
            'date'        => $date,
        ];
    }

    /**
     * The trip number written on the bill: one from the master list if it is
     * there, else whatever follows a "Trip No:" label — so a trip number for a
     * LEON file nobody has imported yet is still reported, not silently ignored.
     */
    private static function tripNumber(string $UT, array $trips): string
    {
        foreach ($trips as $t) {
            $tn = strtoupper(trim((string)($t['trip_number'] ?? '')));
            if ($tn !== '' && preg_match('/(?<![A-Z0-9])' . preg_quote($tn, '/') . '(?![A-Z0-9])/', $UT)) return $tn;
        }
        if (preg_match('/\bTRIP\s*(?:NO|NUMBER|#)?\s*[:.#]?\s*([A-Z0-9][A-Z0-9\/-]{2,})/', $UT, $m)) {
            return trim($m[1], '-/');
        }
        return '';
    }

    /** The longest master-list tail appearing anywhere in the text (as LEON writes it). */
    private static function knownTail(string $NT, array $trips): string
    {
        $hit = ''; $hitLen = 0;
        foreach ($trips as $t) {
            $raw = strtoupper(trim((string)($t['aircraft'] ?? '')));
            $n   = preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
            if ($n === '' || strpos($NT, $n) === false) continue;
            if (strlen($n) > $hitLen) { $hit = $raw; $hitLen = strlen($n); }
        }
        return $hit;
    }

    /** The first ICAO from any trip's route that appears in the text. */
    private static function knownIcao(string $UT, array $trips): string
    {
        foreach ($trips as $t) {
            foreach (self::routeIcaos((string)($t['route'] ?? '')) as $tok) {
                if (preg_match('/(?<![A-Z0-9])' . $tok . '(?![A-Z0-9])/', $UT)) return $tok;
            }
        }
        return '';
    }

    /** The 4-letter ICAOs on a LEON route string ("EGGW - LFMN - EGGW"). */
    public static function routeIcaos(string $route): array
    {
        $out = [];
        foreach (preg_split('/\s*-\s*/', trim($route)) ?: [] as $tok) {
            $tok = strtoupper(trim($tok));
            if (strlen($tok) === 4 && ctype_alpha($tok) && $tok !== 'ZZZZ') $out[] = $tok;
        }
        return array_values(array_unique($out));
    }

    /** First "<label>: <value>" hit. Labels are raw regex, so they can carry lookbehinds. */
    private static function labelled(string $UT, array $labels, string $value): string
    {
        foreach ($labels as $lab) {
            if (preg_match('/\b' . $lab . '\s*[:#-]\s*(' . $value . ')\b/', $UT, $m)) return $m[1];
        }
        return '';
    }

    /** dd/mm/yyyy or dd-mm-yyyy (or already-ISO) → yyyy-mm-dd, '' if unparseable. */
    public static function iso(string $d): string
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
