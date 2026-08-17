<?php
declare(strict_types=1);

namespace App\Service\Leon;

use App\Settings;
use App\Repo\TripRepo;

/**
 * LEON import: parse a Flight Count export (CSV/XLSX/PDF) and upsert it into the
 * trip MASTER LIST. Creates nothing in Xero — the master list is what supplier
 * bills are matched against (Module 3) and invoiced from (Module 5).
 */
final class LeonProcessor
{
    /** Parse a LEON file into the master list. @return array{summary:array, trips:array, source:string} */
    public static function import(string $absPath, string $entity): array
    {
        $entity   = strtolower(trim($entity)) ?: 'inc';
        $parsed   = LeonParser::parse($absPath);
        $currency = (string)Settings::get("currency.{$entity}", '');
        $source   = basename($absPath);

        $trips = [];
        $summary = ['parsed' => count($parsed['trips']), 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'source' => $parsed['source']];
        foreach ($parsed['trips'] as $t) {
            $t['currency'] = $currency;
            [$row, $status] = TripRepo::upsert($t, $entity, $source);
            $summary[$status]++;                          // new | updated | unchanged
            $trips[] = $row;
        }
        return ['summary' => $summary, 'trips' => $trips, 'source' => $parsed['source']];
    }
}
