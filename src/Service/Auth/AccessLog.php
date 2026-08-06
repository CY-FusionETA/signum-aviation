<?php
declare(strict_types=1);

namespace App\Service\Auth;

use App\Db;

/**
 * Records every sign-in attempt (who, from where, on what device) and serves the
 * Access log page. Geo-IP is best-effort: looked up once per IP via a free
 * service and cached in the table, so a failed lookup only leaves that row's
 * location blank — it never blocks the sign-in.
 */
final class AccessLog
{
    /** Record one sign-in attempt. Never throws — logging must not block login. */
    public static function record(string $email, string $result): void
    {
        try {
            $ip  = self::clientIp();
            $geo = self::geoip($ip);
            Db::insert('access_log', [
                'email'      => strtolower(trim($email)),
                'result'     => $result === 'success' ? 'success' : 'failed',
                'ip'         => $ip,
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
                'city'       => $geo['city'],
                'country'    => $geo['country'],
                'isp'        => $geo['isp'],
            ]);
        } catch (\Throwable $e) {
            // best-effort only — never surface a logging failure to the user
        }
    }

    /** Best client IP: first public entry in X-Forwarded-For, else REMOTE_ADDR. */
    public static function clientIp(): string
    {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        foreach (explode(',', $xff) as $part) {
            $ip = trim($part);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * City/country/ISP for a public IP. Cached: if this IP already has a resolved
     * location in the log, reuse it (no repeat call). Otherwise one short HTTP
     * lookup to a free geo service; blanks on any failure or private IP.
     * @return array{city:string,country:string,isp:string}
     */
    public static function geoip(string $ip): array
    {
        $blank = ['city' => '', 'country' => '', 'isp' => ''];
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $blank;
        }

        $cached = Db::one("SELECT city, country, isp FROM access_log WHERE ip = ? AND (city <> '' OR country <> '') ORDER BY id DESC LIMIT 1", [$ip]);
        if ($cached) {
            return ['city' => (string)$cached['city'], 'country' => (string)$cached['country'], 'isp' => (string)$cached['isp']];
        }

        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,city,isp', false, $ctx);
        if ($raw === false) return $blank;
        $j = json_decode((string)$raw, true);
        if (!is_array($j) || ($j['status'] ?? '') !== 'success') return $blank;

        return [
            'city'    => (string)($j['city'] ?? ''),
            'country' => (string)($j['country'] ?? ''),
            'isp'     => (string)($j['isp'] ?? ''),
        ];
    }

    /**
     * Browser · OS and Desktop/Mobile/Tablet from a user-agent string.
     * @return array{browser:string, os:string, kind:string}
     */
    public static function device(string $ua): array
    {
        $browser = 'Unknown';
        if     (preg_match('#Edg(?:e|A|iOS)?/#', $ua))                     $browser = 'Edge';
        elseif (preg_match('#OPR/|Opera#', $ua))                           $browser = 'Opera';
        elseif (preg_match('#(Chrome|CriOS)/#', $ua))                      $browser = 'Chrome';
        elseif (preg_match('#(Firefox|FxiOS)/#', $ua))                     $browser = 'Firefox';
        elseif (preg_match('#Safari/#', $ua))                              $browser = 'Safari';

        $os = 'Unknown';
        if     (preg_match('#iPhone|iPad|iPod#', $ua))                     $os = 'iOS';
        elseif (preg_match('#Android#', $ua))                              $os = 'Android';
        elseif (preg_match('#Mac OS X|Macintosh#', $ua))                   $os = 'macOS';
        elseif (preg_match('#Windows#', $ua))                              $os = 'Windows';
        elseif (preg_match('#Linux#', $ua))                                $os = 'Linux';

        $kind = preg_match('#iPad|Tablet#', $ua) ? 'Tablet'
              : (preg_match('#Mobi|Android|iPhone|iPod#', $ua) ? 'Mobile' : 'Desktop');

        return ['browser' => $browser, 'os' => $os, 'kind' => $kind];
    }

    /** Most recent sign-in rows, newest first. */
    public static function rows(int $limit = 200): array
    {
        return Db::all("SELECT * FROM access_log ORDER BY id DESC LIMIT " . max(1, $limit));
    }

    /** Headline counts for the stat cards. @return array{day:int,total:int,accounts:int,ips:int,failed:int} */
    public static function stats(): array
    {
        return [
            'day'      => (int)Db::scalar("SELECT COUNT(*) FROM access_log WHERE result='success' AND ts >= datetime('now','-1 day')"),
            'total'    => (int)Db::scalar("SELECT COUNT(*) FROM access_log"),
            'accounts' => (int)Db::scalar("SELECT COUNT(DISTINCT email) FROM access_log WHERE email <> ''"),
            'ips'      => (int)Db::scalar("SELECT COUNT(DISTINCT ip) FROM access_log WHERE ip <> ''"),
            'failed'   => (int)Db::scalar("SELECT COUNT(*) FROM access_log WHERE result='failed'"),
        ];
    }
}
