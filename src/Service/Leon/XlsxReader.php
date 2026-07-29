<?php
declare(strict_types=1);

namespace App\Service\Leon;

/**
 * Minimal, dependency-free .xlsx reader — just enough to turn a LEON Flight
 * Count spreadsheet into an array of rows (each row = cell values in column
 * order), which LeonParser then maps exactly like a CSV.
 *
 * Handles: shared strings, inline strings, formula-string results, booleans,
 * numbers, and DATE-formatted cells (Excel serials are converted to Y-m-d so
 * LeonParser::isoDate accepts them whether LEON stores dates as text or as
 * real Excel dates). Reads the first worksheet only (LEON exports are one sheet).
 *
 * Needs the `zip` + `xml`/`dom` PHP extensions.
 */
final class XlsxReader
{
    /** @return array<int,array<int,string>> rows of cell strings, column-aligned */
    public static function rows(string $path): array
    {
        if (!is_file($path)) throw new \RuntimeException("XLSX not found: {$path}");
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('XLSX import needs the PHP "zip" extension. Install php-zip, or upload as CSV/PDF.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Not a valid .xlsx file: ' . basename($path));
        }
        try {
            $shared  = self::sharedStrings($zip);
            $dateXf  = self::dateStyleFlags($zip);
            $sheet   = self::firstSheetXml($zip);
            return self::parseSheet($sheet, $shared, $dateXf);
        } finally {
            $zip->close();
        }
    }

    private static function dom(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }

    /** @return array<int,string> shared-string index -> text */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $doc = self::dom($xml);
        $out = [];
        foreach ($doc->getElementsByTagName('si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagName('t') as $t) $text .= $t->textContent;
            $out[] = $text;
        }
        return $out;
    }

    /**
     * Which cell-style (s="…") indices are date/time formats — so we know to
     * convert their numeric value from an Excel serial to a date.
     * @return array<int,bool> cellXf index -> isDate
     */
    private static function dateStyleFlags(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) return [];
        $doc = self::dom($xml);

        // Built-in date/time numFmtIds.
        $dateFmt = array_fill_keys([14,15,16,17,18,19,20,21,22,45,46,47], true);
        // Custom numFmts whose code looks like a date/time.
        foreach ($doc->getElementsByTagName('numFmt') as $nf) {
            $code = strtolower((string)$nf->getAttribute('formatCode'));
            if (preg_match('/[dy]/', $code) || (str_contains($code, 'm') && str_contains($code, ':')) ) {
                $dateFmt[(int)$nf->getAttribute('numFmtId')] = true;
            }
        }

        $flags = [];
        $cellXfs = $doc->getElementsByTagName('cellXfs')->item(0);
        if ($cellXfs) {
            $i = 0;
            foreach ($cellXfs->getElementsByTagName('xf') as $xf) {
                $flags[$i++] = isset($dateFmt[(int)$xf->getAttribute('numFmtId')]);
            }
        }
        return $flags;
    }

    private static function firstSheetXml(\ZipArchive $zip): string
    {
        // Resolve the first sheet via workbook rels; fall back to sheet1.xml.
        $target = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $doc = self::dom($wb);
            $sheet = $doc->getElementsByTagName('sheet')->item(0);
            $rid = $sheet ? $sheet->getAttribute('r:id') : '';
            if ($rid !== '') {
                $rdoc = self::dom($rels);
                foreach ($rdoc->getElementsByTagName('Relationship') as $r) {
                    if ($r->getAttribute('Id') === $rid) {
                        $t = ltrim((string)$r->getAttribute('Target'), '/');
                        $target = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                        break;
                    }
                }
            }
        }
        $xml = $zip->getFromName($target);
        if ($xml === false) $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($xml === false) throw new \RuntimeException('XLSX has no readable worksheet.');
        return $xml;
    }

    /** @return array<int,array<int,string>> */
    private static function parseSheet(string $xml, array $shared, array $dateXf): array
    {
        $doc = self::dom($xml);
        $rows = [];
        foreach ($doc->getElementsByTagName('row') as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->getElementsByTagName('c') as $c) {
                $col = self::colIndex((string)$c->getAttribute('r'));
                $val = self::cellValue($c, $shared, $dateXf);
                $cells[$col] = $val;
                if ($col > $maxCol) $maxCol = $col;
            }
            $out = [];
            for ($i = 0; $i <= $maxCol; $i++) $out[$i] = $cells[$i] ?? '';
            $rows[] = $out;
        }
        return $rows;
    }

    private static function cellValue(\DOMElement $c, array $shared, array $dateXf): string
    {
        $t = (string)$c->getAttribute('t');
        $vNode = $c->getElementsByTagName('v')->item(0);
        $v = $vNode ? $vNode->textContent : '';

        if ($t === 's') return $shared[(int)$v] ?? '';
        if ($t === 'inlineStr') {
            $text = '';
            $is = $c->getElementsByTagName('is')->item(0);
            if ($is) foreach ($is->getElementsByTagName('t') as $tt) $text .= $tt->textContent;
            return $text;
        }
        if ($t === 'str') return $v;             // formula string result
        if ($t === 'b')   return $v === '1' ? 'TRUE' : 'FALSE';

        // number (or date). Convert Excel serial -> Y-m-d when styled as a date.
        if ($v !== '' && is_numeric($v)) {
            $s = (int)$c->getAttribute('s');
            if (!empty($dateXf[$s])) return self::serialToDate((float)$v);
        }
        return $v;
    }

    /** "AB12" -> 27 (zero-based column index). */
    private static function colIndex(string $ref): int
    {
        if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return 0;
        $letters = strtoupper($m[1]);
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    /** Excel serial (1900 date system) -> Y-m-d. */
    private static function serialToDate(float $serial): string
    {
        $days = (int)floor($serial);
        if ($days <= 0) return (string)$serial;
        $base = new \DateTimeImmutable('1899-12-30');     // Excel's day 0
        return $base->modify("+{$days} days")->format('Y-m-d');
    }
}
