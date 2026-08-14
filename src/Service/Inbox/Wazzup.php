<?php
declare(strict_types=1);

namespace App\Service\Inbox;

use App\Settings;

/**
 * Outbound leg of the WhatsApp relay: send one invoice file to the processor
 * (WazzOCR) the same way the Gmail script does — the WABA line posts the file's
 * public drop URL to the processor's number, and the processor answers on the
 * same line, which lands back in the Inbox through /wazzup/webhook.
 *
 * Credentials come from config.php ('wazzup' block) and can be overridden at
 * runtime in app_settings. They must name the SAME channel the Gmail script sends
 * from (Code.gs CHANNEL_ID / CHANNEL_API_KEY): the processor only accepts invoices
 * from numbers on its allow-list, so a re-send from any other line is ignored.
 * It is a regular WhatsApp channel, not WhatsApp Business API, so there is no
 * 24-hour service window to keep open and no opener message to send. Unset
 * credentials = no automatic re-sending, which is reported rather than silently
 * skipped.
 */
final class Wazzup
{
    private const ENDPOINT = 'https://api.wazzup24.com/v3/message';

    /**
     * Test seam: a callable(array $payload): array{ok:bool, error?:string} that
     * stands in for the relay, so the suite can drive a full send without one
     * leaving the machine. Never set in production.
     */
    public static $transport = null;

    public static function apiKey(): string       { return self::setting('wazzup.api_key'); }
    public static function channelId(): string    { return self::setting('wazzup.channel_id'); }
    /** The processor's WhatsApp number (WazzOCR's intake line). */
    public static function processorNumber(): string { return self::setting('wazzup.wazzocr_number'); }

    public static function isConfigured(): bool
    {
        return self::apiKey() !== '' && self::channelId() !== '' && self::processorNumber() !== '';
    }

    /**
     * Send a file (by public URL) to the processor.
     * @return array{ok:bool, error?:string}
     */
    public static function sendFile(string $fileUrl): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'WhatsApp sending is not configured (wazzup api_key / channel_id / wazzocr_number).'];
        }
        if (!preg_match('#^https?://#i', $fileUrl)) {
            return ['ok' => false, 'error' => 'The file has no public URL to send.'];
        }

        return self::post([
            'channelId'  => self::channelId(),
            'chatType'   => 'whatsapp',
            'chatId'     => self::processorNumber(),
            'contentUri' => $fileUrl,
        ]);
    }

    /** POST one Wazzup message. Never throws — a relay outage is reported, not fatal. */
    private static function post(array $payload): array
    {
        if (is_callable(self::$transport)) return (array)(self::$transport)($payload);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . self::apiKey(), 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = (string)curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '')                return ['ok' => false, 'error' => 'WhatsApp relay unreachable: ' . $err];
        if ($code < 200 || $code >= 300) return ['ok' => false, 'error' => "WhatsApp relay HTTP {$code} — " . trim(substr($body, 0, 200))];
        return ['ok' => true];
    }

    /** app_settings wins over config.php, so credentials can be rotated live. */
    private static function setting(string $key): string
    {
        return trim((string)Settings::get($key, (string)cfg($key, '')));
    }
}
