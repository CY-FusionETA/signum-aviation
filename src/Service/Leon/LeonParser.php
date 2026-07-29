<?php
declare(strict_types=1);

namespace App\Service\Leon;

/**
 * Parses a LEON "Flight Count" export (CSV or PDF) into normalised trip rows.
 *
 * Header-driven, NOT positional: the Inc and Ltd reports carry the same columns
 * in a DIFFERENT order (Ltd puts Aircraft before Client, Inc the reverse). We
 * locate the header (the row/line containing "Trip number"), map every column
 * by its normalised name, then read data rows until the total (∑) row.
 *
 * CSV: rows come from fgetcsv. PDF: text is extracted with `pdftotext -layout`
 * and each row is sliced at the header labels' character offsets — so the same
 * canonical mapping handles both, and both column orders, unchanged.
 *
 * Tolerant of: a title + date-range preamble before the header; blank client
 * names; non-numeric trip numbers ("KZ2OS4", "07-2026/76"); dd-mm-yyyy dates.
 */
final class LeonParser
{
    /** Header aliases -> canonical field. Keys are normalised (see normalizeHeader). */
    private const HEADER_MAP = [
        'startdate'    => 'start_date',
        'enddate'      => 'end_date',
        'tripnumber'   => 'trip_number',
        'trip'         => 'trip_number',
        'clientname'   => 'client_name',
        'client'       => 'client_name',
        'aircraft'     => 'aircraft',
        'registration' => 'aircraft',
        'reg'          => 'aircraft',
        'routeicao'    => 'route',
        'route'        => 'route',
        'flightscount' => 'flights_count',
        'flightcount'  => 'flights_count',
        'flights'      => 'flights_count',
    ];

    /** Anchor labels used to find column start offsets in a PDF header line. */
    private const PDF_ANCHORS = [
        'Start date'    => 'start_date',
        'End date'      => 'end_date',
        'Trip number'   => 'trip_number',
        'Client name'   => 'client_name',
        'Aircraft'      => 'aircraft',
        'Route'         => 'route',
        'Flights count' => 'flights_count',
        'Flight count'  => 'flights_count',
    ];

    /** Dispatch by extension. */
    public static function parse(string $absPath): array
    {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        return $ext === 'pdf' ? self::parsePdf($absPath) : self::parseCsv($absPath);
    }

    /** @return array{trips: array, headers: array, skipped: int, source: string} */
    public static function parseCsv(string $absPath): array
    {
        if (!is_file($absPath)) throw new \RuntimeException("LEON file not found: {$absPath}");
        $fh = fopen($absPath, 'r');
        if ($fh === false) throw new \RuntimeException("Could not open LEON file: {$absPath}");

        $headerCells = null;
        $dataRows = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) continue; // blank line
            if ($headerCells === null) {
                if (self::looksLikeHeader($row)) $headerCells = $row;
                continue;
            }
            $dataRows[] = $row;
        }
        fclose($fh);
        if ($headerCells === null) {
            throw new \RuntimeException('No LEON header row found (expected a "Trip number" column).');
        }
        return self::finalize($headerCells, $dataRows, 'csv');
    }

    /** @return array{trips: array, headers: array, skipped: int, source: string} */
    public static function parsePdf(string $absPath): array
    {
        if (!is_file($absPath)) throw new \RuntimeException("LEON file not found: {$absPath}");
        $text = self::pdfToText($absPath);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        // Find the header line (has a Trip + Route column).
        $hi = -1;
        foreach ($lines as $i => $ln) {
            if (stripos($ln, 'Trip') !== false && stripos($ln, 'Route') !== false) { $hi = $i; break; }
        }
        if ($hi < 0) throw new \RuntimeException('No LEON header line found in the PDF (expected "Trip number" / "Route").');

        // Locate each known column's start offset in the header line, in order.
        $cols = [];
        foreach (self::PDF_ANCHORS as $label => $field) {
            $pos = stripos($lines[$hi], $label);
            if ($pos !== false && !isset($cols[$field])) $cols[$field] = $pos;
        }
        asort($cols);                              // field => offset, ascending
        $fields  = array_keys($cols);              // canonical fields, left-to-right
        $offsets = array_values($cols);

        // Slice every subsequent line at those offsets into aligned cells.
        $dataRows = [];
        for ($i = $hi + 1; $i < count($lines); $i++) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '') continue;
            $cells = [];
            foreach ($offsets as $k => $start) {
                $end = $offsets[$k + 1] ?? null;
                $cells[] = trim($end === null ? mb_substr($line, $start) : mb_substr($line, $start, $end - $start));
            }
            $dataRows[] = $cells;
        }
        return self::finalize($fields, $dataRows, 'pdf');
    }

    /** Shared: map header cells -> canonical fields, then extract each data row. */
    private static function finalize(array $headerCells, array $dataRows, string $source): array
    {
        $fieldToIndex = [];
        foreach ($headerCells as $i => $cell) {
            $key = self::normalizeHeader((string)$cell);
            if ($key !== '' && isset(self::HEADER_MAP[$key]) && !isset($fieldToIndex[self::HEADER_MAP[$key]])) {
                $fieldToIndex[self::HEADER_MAP[$key]] = $i;
            }
        }
        if (!isset($fieldToIndex['trip_number'])) {
            throw new \RuntimeException('LEON header has no "Trip number" column.');
        }

        $trips = [];
        $skipped = 0;
        foreach ($dataRows as $row) {
            $trip = self::extractRow($row, $fieldToIndex);
            if ($trip === null) { $skipped++; continue; }
            $trips[] = $trip;
        }
        return ['trips' => $trips, 'headers' => $fieldToIndex, 'skipped' => $skipped, 'source' => $source];
    }

    private static function looksLikeHeader(array $row): bool
    {
        foreach ($row as $cell) {
            if (self::normalizeHeader((string)$cell) === 'tripnumber') return true;
        }
        return false;
    }

    /** Lowercase, drop bracketed annotations like [UTC]/[JL] and all non-letters. */
    private static function normalizeHeader(string $h): string
    {
        $h = strtolower($h);
        $h = preg_replace('/\[[^\]]*\]/', '', $h) ?? $h;
        $h = preg_replace('/[^a-z]/', '', $h) ?? $h;
        return $h;
    }

    /** @return array<string,mixed>|null null = skip (e.g. the ∑ total row) */
    private static function extractRow(array $row, array $fieldToIndex): ?array
    {
        $get = fn(string $field) => isset($fieldToIndex[$field]) ? trim((string)($row[$fieldToIndex[$field]] ?? '')) : '';

        $tripNo = $get('trip_number');
        if ($tripNo === '' || str_starts_with($tripNo, '∑') || strtolower($tripNo) === 'sum') return null;

        return [
            'trip_number'   => $tripNo,
            'client_name'   => $get('client_name'),
            'aircraft'      => $get('aircraft'),
            'route'         => self::cleanRoute($get('route')),
            'start_date'    => self::isoDate($get('start_date')),
            'end_date'      => self::isoDate($get('end_date')),
            'flights_count' => self::intOrNull($get('flights_count')),
        ];
    }

    private static function cleanRoute(string $r): string
    {
        $r = preg_replace('/\s*-\s*/', ' - ', trim($r)) ?? $r;
        return preg_replace('/\s+/', ' ', $r) ?? $r;
    }

    /** LEON dates are dd-mm-yyyy (or dd/mm/yyyy). Return ISO yyyy-mm-dd, or '' if unparseable. */
    public static function isoDate(string $d): string
    {
        $d = trim($d);
        if ($d === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $d, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return '';
    }

    private static function intOrNull(string $v): ?int
    {
        $v = trim($v);
        return $v === '' ? null : (int)$v;
    }

    /** Extract PDF text with pdftotext -layout (preserves column alignment). */
    private static function pdfToText(string $absPath): string
    {
        $bin = self::pdftotextBin();
        if ($bin === null) {
            throw new \RuntimeException('PDF import needs the "pdftotext" tool (poppler-utils) on the server. Install it, or upload the LEON export as CSV.');
        }
        $cmd = escapeshellarg($bin) . ' -layout -nopgbrk ' . escapeshellarg($absPath) . ' - 2>/dev/null';
        $out = shell_exec($cmd);
        if ($out === null || trim((string)$out) === '') {
            throw new \RuntimeException('Could not read any text from the PDF (is it a scanned image rather than a LEON export?).');
        }
        return (string)$out;
    }

    private static function pdftotextBin(): ?string
    {
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', 'pdftotext'] as $c) {
            $which = @shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null');
            if ($which && trim($which) !== '') return trim($which);
        }
        return null;
    }
}
