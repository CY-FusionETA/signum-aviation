<?php
declare(strict_types=1);

namespace App\Service\Invoices;

/**
 * Module 5 completeness gate: compare a trip's LEON route legs against the
 * airports that appear on its tagged bills. Every route airport that has a bill
 * → "complete"; any route airport with no bill → a flagged gap (a leg that may
 * still be missing a supplier charge before you invoice the client).
 *
 * This is the automated version of the manual "check every leg" AR step. It's
 * deterministic on purpose — the arithmetic and the gate stay reliable; an LLM
 * judgment of whether a gap is benign (positioning/fuel/non-applicable) can be
 * layered on later.
 */
final class CompletenessChecker
{
    /** @return array{status:string, legs:int, covered:array, missing:array} */
    public static function check(array $trip, array $bills): array
    {
        $route = self::airports((string)($trip['route'] ?? ''));

        // One uppercase blob of everything on the tagged bills (extracted airport + description).
        $blob = '';
        foreach ($bills as $b) $blob .= ' ' . strtoupper((string)($b['ex_airport'] ?? '') . ' ' . (string)($b['description'] ?? ''));

        $covered = $missing = [];
        foreach ($route as $icao) {
            (strpos($blob, $icao) !== false) ? $covered[] = $icao : $missing[] = $icao;
        }

        return [
            'status'  => $route && !$missing ? 'complete' : ($route ? 'gaps' : 'unknown'),
            'legs'    => count($route),
            'covered' => $covered,
            'missing' => $missing,
        ];
    }

    /** Distinct 4-letter ICAOs on a route (drops ZZZZ placeholders), in order. */
    private static function airports(string $route): array
    {
        $out = [];
        foreach (preg_split('/\s*-\s*/', trim($route)) ?: [] as $tok) {
            $tok = strtoupper(trim($tok));
            if (strlen($tok) === 4 && $tok !== 'ZZZZ' && !in_array($tok, $out, true)) $out[] = $tok;
        }
        return $out;
    }
}
