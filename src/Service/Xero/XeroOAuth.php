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

    public const DEFAULT_SCOPES =
        'openid profile email accounting.transactions accounting.contacts accounting.settings offline_access';

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

    /** Build the consent URL; $state ties the callback back to this session. */
    public static function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => self::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'scope'         => self::scopes(),
            'state'         => $state,
        ]);
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \RuntimeException('Network error calling Xero: ' . $err);
        return [$code, (string)$resp];
    }
}
