<?php
declare(strict_types=1);

namespace App\Service\Xero;

use App\Db;

/**
 * Queryable Xero API call log — the store behind the "API log" view.
 *
 * XeroOAuth::logCall() already appends one line per call to
 * storage/logs/xero-calls.log; that file stays as the raw append-only trail
 * (and is what the awk/grep recipes in XeroOAuth read). This class puts the
 * same facts in SQLite so the UI can count them: calls per day against Xero's
 * 5000/org/day allowance, which endpoint is spending it, and the remaining
 * budget Xero reported on the most recent call.
 *
 * Writing is best-effort throughout — instrumentation must never break a call.
 */
final class XeroCallLog
{
    /**
     * Per-minute ceilings, per organisation unless noted. These two match Xero's
     * published numbers in the observed data (X-MinLimit-Remaining tops out at
     * 60, X-AppMinLimit-Remaining at 9999).
     */
    public const CAP_MIN = 60;
    public const CAP_APP = 10000;    // across every org this app is connected to

    /**
     * Fallback daily ceiling, used only until a real header has been seen.
     * The real one is derived — see dayCap(). Xero documents 5000/org/day, but
     * this connection is demonstrably capped at 1000: across thousands of logged
     * calls X-DayLimit-Remaining has never once exceeded 999, and it refills to
     * exactly 999 when the window rolls over. Trust the header, not the doc.
     */
    private const CAP_DAY_FALLBACK = 5000;

    /** Rows older than this are pruned occasionally; the file log keeps everything. */
    private const RETAIN_DAYS = 60;

    /**
     * Record one call. $headers is the response header map, lower-cased keys.
     * $ts lets the backfill replay historic lines; live calls leave it null.
     */
    public static function record(
        string $method,
        string $url,
        int $httpCode,
        array $headers,
        int $durationMs = 0,
        ?string $ts = null
    ): void {
        try {
            $keep = [];
            foreach ($headers as $k => $v) {
                if (str_starts_with((string)$k, 'x-') || $k === 'retry-after') $keep[$k] = $v;
            }

            Db::insert('xero_api_calls', [
                'ts'                      => $ts ?: gmdate('Y-m-d H:i:s'),
                'method'                  => strtoupper($method),
                'endpoint'                => self::label($url),
                'path'                    => self::pathOf($url),
                'http_code'               => $httpCode,
                'ok'                      => ($httpCode >= 200 && $httpCode < 300) ? 1 : 0,
                'duration_ms'             => $durationMs,
                'day_limit_remaining'     => self::num($headers, 'x-daylimit-remaining'),
                'min_limit_remaining'     => self::num($headers, 'x-minlimit-remaining'),
                'app_min_limit_remaining' => self::num($headers, 'x-appminlimit-remaining'),
                'retry_after'             => self::num($headers, 'retry-after'),
                'limit_problem'           => ($headers['x-rate-limit-problem'] ?? '') ?: null,
                'request_id'              => ($headers['x-request-id'] ?? '') ?: null,
                'headers_json'            => $keep ? json_encode($keep) : null,
            ]);

            if (random_int(1, 100) === 1) {
                Db::q("DELETE FROM xero_api_calls WHERE ts < ?",
                    [gmdate('Y-m-d H:i:s', time() - self::RETAIN_DAYS * 86400)]);
            }
        } catch (\Throwable $e) {
            // Never let instrumentation break a Xero call.
        }
    }

    /** @param array<string,string> $h */
    private static function num(array $h, string $key): ?int
    {
        $v = trim((string)($h[$key] ?? ''));
        return ($v === '' || !is_numeric($v)) ? null : (int)$v;
    }

    private static function pathOf(string $url): string
    {
        $p = (string)parse_url($url, PHP_URL_PATH);
        return mb_substr($p !== '' ? $p : $url, 0, 300);
    }

    /**
     * A short, groupable name: 'Invoices', 'PurchaseOrders/{id}/Attachments',
     * 'OAuth token', 'Connections'. GUIDs and attachment filenames collapse so
     * per-endpoint counts group instead of splintering one row per call.
     */
    public static function label(string $url): string
    {
        $path = self::pathOf($url);
        if (str_contains($path, '/connect/token')) return 'OAuth token';
        if ($path === '/connections')              return 'Connections';

        $rel = preg_replace('#^/?api\.xro/2\.0/#', '', ltrim($path, '/')) ?? $path;
        $rel = trim($rel, '/');
        if ($rel === '') return 'Xero';

        $parts = explode('/', $rel);
        $out = [];
        foreach ($parts as $i => $seg) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $seg)) $out[] = '{id}';
            elseif ($i > 0 && strcasecmp($parts[$i - 1], 'Attachments') === 0)                        $out[] = '{file}';
            else                                                                                      $out[] = $seg;
        }
        return mb_substr(implode('/', $out), 0, 120);
    }

    // --- the daily quota window ---------------------------------------

    /**
     * The organisation's actual daily ceiling, read off the headers rather than
     * assumed. Xero decrements before reporting — the first call of a window
     * comes back with (cap - 1) — so the highest value ever seen is one below
     * the ceiling.
     */
    public static function dayCap(): int
    {
        $max = (int)Db::scalar("SELECT MAX(day_limit_remaining) FROM xero_api_calls");
        return $max > 0 ? $max + 1 : self::CAP_DAY_FALLBACK;
    }

    /**
     * The current quota window: when Xero last refilled the daily budget, and
     * how many calls we have made since.
     *
     * The window is NOT the UTC calendar day. In the observed data the counter
     * ran straight through midnight UTC and jumped 0 -> 999 at ~15:03 UTC on
     * consecutive days — the exact moment a preceding 429's Retry-After pointed
     * at. So the boundary is found the only reliable way: the most recent point
     * where the remaining budget went UP.
     *
     * @return array{start:?string,resets_at:?string,calls:int,used:?int,left:?int,cap:int}
     */
    public static function window(): array
    {
        $cap  = self::dayCap();
        $rows = Db::all(
            "SELECT ts, day_limit_remaining v FROM xero_api_calls
              WHERE day_limit_remaining IS NOT NULL ORDER BY id DESC LIMIT 5000"
        );

        $start = null;
        for ($i = 0, $n = count($rows) - 1; $i < $n; $i++) {
            // Newest-first, so a row worth MORE than the one before it in time
            // is the refill that opened the window we are in.
            if ((int)$rows[$i]['v'] > (int)$rows[$i + 1]['v']) { $start = (string)$rows[$i]['ts']; break; }
        }
        if ($start === null && $rows) $start = (string)end($rows)['ts'];

        $left = $rows ? (int)$rows[0]['v'] : null;
        $ts   = $start ? strtotime($start . ' UTC') : null;

        return [
            'start'     => $start,
            'resets_at' => $ts ? gmdate('Y-m-d H:i:s', $ts + 86400) : null,
            'calls'     => $start ? (int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls WHERE ts >= ?", [$start]) : 0,
            'used'      => $left === null ? null : $cap - $left,
            'left'      => $left,
            'cap'       => $cap,
        ];
    }

    // --- reads --------------------------------------------------------

    public static function recent(int $limit = 200): array
    {
        return Db::all("SELECT * FROM xero_api_calls ORDER BY id DESC LIMIT ?", [max(1, min(1000, $limit))]);
    }

    /** The most recent call that actually carried a rate-limit budget. */
    public static function latestLimits(): ?array
    {
        return Db::one(
            "SELECT * FROM xero_api_calls
              WHERE day_limit_remaining IS NOT NULL
                 OR min_limit_remaining IS NOT NULL
                 OR app_min_limit_remaining IS NOT NULL
              ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Headline counters for the CURRENT QUOTA WINDOW (see window()), not for the
     * calendar day — the two are not the same thing, and only the window is what
     * Xero is counting against.
     */
    public static function stats(?string $windowStart = null): array
    {
        $from = $windowStart ?: (gmdate('Y-m-d') . ' 00:00:00');
        $hour = gmdate('Y-m-d H:i:s', time() - 3600);
        return [
            'window'    => (int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls WHERE ts >= ?", [$from]),
            'hour'      => (int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls WHERE ts >= ?", [$hour]),
            'failed'    => (int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls WHERE ok = 0 AND ts >= ?", [$from]),
            'throttled' => (int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls WHERE http_code = 429 AND ts >= ?", [$from]),
            'total'     => (int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls"),
            'from'      => $from,
        ];
    }

    /** Calls per endpoint in the current quota window — what is burning it. */
    public static function byEndpoint(int $limit = 12, ?string $windowStart = null): array
    {
        return Db::all(
            "SELECT endpoint,
                    COUNT(*)                                AS calls,
                    SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failures,
                    MAX(ts)                                 AS last_at
               FROM xero_api_calls
              WHERE ts >= ?
           GROUP BY endpoint
           ORDER BY calls DESC
              LIMIT ?",
            [$windowStart ?: (gmdate('Y-m-d') . ' 00:00:00'), max(1, $limit)]
        );
    }

    /** Calls per UTC day, oldest first, with the lowest day-budget seen that day. */
    public static function daily(int $days = 14): array
    {
        return Db::all(
            "SELECT substr(ts, 1, 10)                            AS day,
                    COUNT(*)                                     AS calls,
                    MIN(day_limit_remaining)                     AS low_day_left,
                    SUM(CASE WHEN http_code = 429 THEN 1 ELSE 0 END) AS throttled
               FROM xero_api_calls
              WHERE ts >= ?
           GROUP BY day
           ORDER BY day ASC",
            [gmdate('Y-m-d', time() - max(1, $days) * 86400) . ' 00:00:00']
        );
    }

    public static function clear(): int
    {
        return Db::q("DELETE FROM xero_api_calls")->rowCount();
    }

    // --- backfill -----------------------------------------------------

    /**
     * Replay storage/logs/xero-calls.log into the table. The file predates this
     * table by weeks — including the 2026-08-18 day-limit burn — and that
     * history is the whole point of a quota view, so it is imported once.
     * Only runs when the table is empty; returns the number of rows added.
     *
     * A line reads:
     *   2026-08-19T10:22:02Z GET /api.xro/2.0/Invoices 429 day-left=0 min-left=57 \
     *   appmin-left=9987 problem=day retry-after=12345
     */
    public static function importLogFile(?string $file = null): int
    {
        $file ??= dirname(__DIR__, 3) . '/storage/logs/xero-calls.log';
        if (!is_readable($file)) return 0;
        if ((int)Db::scalar("SELECT COUNT(*) FROM xero_api_calls") > 0) return 0;

        $fh = fopen($file, 'r');
        if (!$fh) return 0;

        $map = [
            'day-left'    => 'x-daylimit-remaining',
            'min-left'    => 'x-minlimit-remaining',
            'appmin-left' => 'x-appminlimit-remaining',
            'problem'     => 'x-rate-limit-problem',
            'retry-after' => 'retry-after',
        ];

        $n = 0;
        Db::conn()->beginTransaction();
        try {
            while (($line = fgets($fh)) !== false) {
                $parts = preg_split('/\s+/', trim($line)) ?: [];
                if (count($parts) < 4) continue;
                [$iso, $method, $path, $code] = $parts;
                $ts = strtotime($iso);
                if (!$ts || !ctype_digit($code)) continue;

                $headers = [];
                foreach (array_slice($parts, 4) as $bit) {
                    [$k, $v] = array_pad(explode('=', $bit, 2), 2, '');
                    if (isset($map[$k]) && $v !== '') $headers[$map[$k]] = $v;
                }
                self::record($method, $path, (int)$code, $headers, 0, gmdate('Y-m-d H:i:s', $ts));
                $n++;
            }
            Db::conn()->commit();
        } catch (\Throwable $e) {
            Db::conn()->rollBack();
            return 0;
        } finally {
            fclose($fh);
        }
        return $n;
    }
}
