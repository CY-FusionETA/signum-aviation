<?php
declare(strict_types=1);

namespace App\Service\Xero;

use App\Db;
use App\Settings;

/**
 * Xero OAuth2 (authorization-code + refresh) and token storage.
 * Ported from Starship. One connected tenant is kept in oauth_tokens
 * (provider='xero'). Access tokens live ~30 min; we refresh transparently and
 * persist the rotated refresh token. Reconnecting to a DIFFERENT org clears
 * every trip's xero_po_id so those trips re-create in the new org.
 */
final class XeroOAuth
{
    private const AUTHORIZE   = 'https://login.xero.com/identity/connect/authorize';
    private const TOKEN       = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS = 'https://api.xero.com/connections';

    // Purchase Orders are reached through accounting.invoices, NOT
    // accounting.transactions: the Xero apps we connect with do not grant
    // accounting.transactions, and asking for it makes login.xero.com bounce
    // the user to its own error page before the consent screen ever renders.
    // This is the same set Starship pushes POs with.
    public const DEFAULT_SCOPES =
        'openid profile email accounting.invoices accounting.contacts accounting.settings accounting.attachments offline_access';

    /** Scopes every connection needs; never bisected when diagnosing a refusal. */
    private const CORE_SCOPES = ['openid', 'profile', 'email', 'offline_access'];

    // Connection params come from the DB (Settings) so the module can be
    // reconnected to a new org without editing config files.
    public static function clientId(): string     { return trim((string)Settings::get('xero.client_id', '')); }
    public static function clientSecret(): string { return trim((string)Settings::get('xero.client_secret', '')); }
    public static function scopes(): string
    {
        $s = trim((string)Settings::get('xero.scopes', ''));
        return $s !== '' ? $s : self::DEFAULT_SCOPES;
    }

    /** The redirect URI Xero calls back. Must be registered verbatim in the Xero app. */
    public static function redirectUri(): string
    {
        $cfg = trim((string)Settings::get('xero.redirect_uri', ''));
        if ($cfg !== '') return $cfg;
        $base = rtrim((string)cfg('app.base_url', ''), '/');
        return $base . '/xero/callback';
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function token(): ?array
    {
        return Db::one("SELECT * FROM oauth_tokens WHERE provider = 'xero' ORDER BY updated_at DESC LIMIT 1");
    }

    public static function isConnected(): bool
    {
        $t = self::token();
        return $t !== null && !empty($t['refresh_token']) && !empty($t['tenant_id']);
    }

    public static function tenantName(): string
    {
        return (string)(self::token()['tenant_name'] ?? '');
    }

    /**
     * Build the consent URL; $state ties the callback back to this session.
     * $scopes overrides the configured set (used when diagnosing a refusal).
     */
    public static function authorizeUrl(string $state, ?string $scopes = null): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => self::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'scope'         => $scopes ?? self::scopes(),
            'state'         => $state,
        ]);
    }

    /**
     * Would Xero refuse this consent request? login.xero.com answers a 302 to
     * /identity/error — an opaque page with only an errorId — when a parameter
     * is bad, most often a scope the Xero app does not grant. Checking first
     * lets us say what is wrong instead of dumping the user on that page.
     *
     * Returns null when the request is good, else a human-readable reason.
     * Fails OPEN: if the check itself cannot run (network, timeout), we return
     * null and let the browser go to Xero as before.
     */
    public static function authorizeProblem(string $state = 'preflight'): ?string
    {
        if (!self::refused(self::authorizeUrl($state))) return null;

        // Refused. Is it the scopes, or the client id / redirect URI?
        $core = implode(' ', self::CORE_SCOPES);
        if (self::refused(self::authorizeUrl($state, $core))) {
            return 'Xero refused the connection before the consent screen. The Client ID or the '
                 . 'redirect URI is not accepted — check the Client ID below, and that '
                 . self::redirectUri() . ' is registered verbatim in your Xero app.';
        }

        // Core scopes are fine, so bisect the extras to name the offenders.
        $bad = [];
        foreach (array_diff(explode(' ', preg_replace('/\s+/', ' ', trim(self::scopes()))), self::CORE_SCOPES) as $s) {
            if ($s !== '' && self::refused(self::authorizeUrl($state, $core . ' ' . $s))) $bad[] = $s;
        }
        if (!$bad) return 'Xero refused the connection request. Check the scopes and redirect URI below.';

        return 'Your Xero app does not grant ' . implode(', ', $bad) . '. Remove '
             . (count($bad) === 1 ? 'it' : 'them') . ' in Xero settings below (purchase orders work through '
             . 'accounting.invoices), or enable the scope on the app at developer.xero.com.';
    }

    /** True if login.xero.com bounces this consent URL to its error page. */
    private static function refused(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $ok  = curl_exec($ch) !== false;
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $ok && str_contains($loc, '/identity/error');
    }

    /** Exchange an authorization code, discover the tenant, and persist tokens. */
    public static function completeConnection(string $code): array
    {
        $tok = self::postToken([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri(),
        ]);
        $conns = self::fetchConnections($tok['access_token']);
        if (!$conns) throw new \RuntimeException('Xero returned no organisations for this login.');
        $tenant = $conns[0];
        self::store($tok, (string)$tenant['tenantId'], (string)($tenant['tenantName'] ?? ''));
        return ['tenant_name' => $tenant['tenantName'] ?? '', 'tenant_id' => $tenant['tenantId']];
    }

    /** Return a valid (refreshed if needed) access token + tenant id, or null if not connected. */
    public static function accessToken(): ?array
    {
        $t = self::token();
        if (!$t) return null;
        $expiresAt = strtotime((string)$t['expires_at']) ?: 0;
        if ($expiresAt - 30 <= time()) {           // expired or about to
            $t = self::refresh($t);
            if (!$t) return null;
        }
        return ['access_token' => $t['access_token'], 'tenant_id' => $t['tenant_id']];
    }

    private static function refresh(array $t): ?array
    {
        $tok = self::postToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $t['refresh_token'],
        ]);
        self::store($tok, (string)$t['tenant_id'], (string)($t['tenant_name'] ?? ''));
        return self::token();
    }

    public static function disconnect(): void
    {
        Db::q("DELETE FROM oauth_tokens WHERE provider = 'xero'");
    }

    // --- internals ---------------------------------------------------

    private static function store(array $tok, string $tenantId, string $tenantName): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 1800));
        // Remember the tenant we were connected to before overwriting the token.
        $prev = Db::one("SELECT tenant_id FROM oauth_tokens WHERE provider = 'xero' ORDER BY updated_at DESC LIMIT 1");
        $prevTenant = $prev['tenant_id'] ?? null;

        Db::q("DELETE FROM oauth_tokens WHERE provider = 'xero'");
        Db::insert('oauth_tokens', [
            'provider'      => 'xero',
            'tenant_id'     => $tenantId,
            'tenant_name'   => $tenantName,
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'] ?? '',
            'expires_at'    => $expiresAt,
            'scope'         => $tok['scope'] ?? self::scopes(),
        ]);

        // Reconnected to a DIFFERENT Xero org? Every already-synced trip holds an
        // xero_po_id that exists only in the old tenant, and the processor treats
        // a non-empty xero_po_id as "already created" — so without clearing them
        // those trips would silently never push to the new org. A token refresh
        // keeps the same tenant, so this only fires on a genuine tenant switch.
        if ($prevTenant !== null && $prevTenant !== '' && $prevTenant !== $tenantId) {
            Db::q(
                "UPDATE leon_trips
                    SET xero_po_id = NULL, xero_po_number = NULL, xero_synced_at = NULL, xero_last_error = NULL
                  WHERE xero_po_id IS NOT NULL"
            );
        }
    }

    private static function postToken(array $fields): array
    {
        [$code, $body] = self::http('POST', self::TOKEN, [
            'Authorization: Basic ' . base64_encode(self::clientId() . ':' . self::clientSecret()),
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($fields));
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !isset($json['access_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? $body;
            throw new \RuntimeException('Xero token request failed (HTTP ' . $code . '): ' . $msg);
        }
        return $json;
    }

    private static function fetchConnections(string $accessToken): array
    {
        [$code, $body] = self::http('GET', self::CONNECTIONS, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Xero /connections failed (HTTP ' . $code . '): ' . $body);
        }
        return json_decode($body, true) ?: [];
    }

    /** @return array{0:int,1:string} [http_code, body] */
    public static function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        self::$lastHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            // Keep the response headers: a 429 says WHICH limit was hit and for
            // how long only in 'x-rate-limit-problem' / 'retry-after' — its body
            // is empty, so without these a rate limit is indistinguishable from
            // an unknown failure.
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $len = strlen($line);
                $bits = explode(':', $line, 2);
                if (count($bits) === 2) self::$lastHeaders[strtolower(trim($bits[0]))] = trim($bits[1]);
                return $len;
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        self::logCall($method, $url, $code);
        self::noteRateLimit($code);
        if ($resp === false) throw new \RuntimeException('Network error calling Xero: ' . $err);
        return [$code, (string)$resp];
    }

    /** Response headers of the last http() call, lower-cased keys. */
    private static array $lastHeaders = [];

    /** One header from the last http() call ('' when absent). */
    public static function lastHeader(string $name): string
    {
        return (string)(self::$lastHeaders[strtolower($name)] ?? '');
    }

    /**
     * Remember when Xero will accept calls again. A 429 for the DAY limit lasts
     * hours, and every call made inside that window is refused anyway — so
     * recording it lets callers skip the round trip instead of hammering.
     */
    private static function noteRateLimit(int $code): void
    {
        if ($code !== 429) return;
        $after = (int)self::lastHeader('retry-after');
        if ($after > 0) Settings::set('xero.cooldown_until', (string)(time() + $after));
    }

    /** Seconds until Xero will take calls again (0 when it is not rate-limiting). */
    public static function cooldownLeft(): int
    {
        $until = (int)Settings::get('xero.cooldown_until', '0');
        return $until > time() ? $until - time() : 0;
    }

    /**
     * Append one line per Xero call. Xero allows 5000 calls per organisation per
     * day and gives no breakdown of who spent them, so when the quota runs out
     * (429, x-rate-limit-problem: day) this file is the only way to see what did
     * it. Endpoint only — no query strings, no bodies, nothing sensitive.
     *   sort/count a day:  awk '{print $3, $4}' storage/logs/xero-calls.log | sort | uniq -c | sort -rn
     */
    private static function logCall(string $method, string $url, int $code): void
    {
        try {
            $path = (string)parse_url($url, PHP_URL_PATH);
            $left = self::lastHeader('x-daylimit-remaining');
            $line = sprintf("%s %s %s %d%s\n", gmdate('Y-m-d\TH:i:s\Z'), $method, $path, $code,
                            $left !== '' ? ' day-left=' . $left : '');
            $dir  = dirname(__DIR__, 3) . '/storage/logs';
            if (is_dir($dir) && is_writable($dir)) @file_put_contents($dir . '/xero-calls.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Never let instrumentation break a Xero call.
        }
    }
}
