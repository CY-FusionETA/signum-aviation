<?php
declare(strict_types=1);

namespace App\Service\Inbox;

use App\Db;

/**
 * The file-drop: every attachment the Gmail poller relays is parked here under an
 * unguessable token and served once at <base_url>/drop/<token>, because Wazzup can
 * only send a file it can fetch by URL.
 *
 * Unidash keeps the token on the Inbox row, so it can send the SAME file to the
 * processor again by itself — that is what lets an "already exists in Xero"
 * duplicate be cleared and re-created without the operator emailing the invoice
 * in a second time. Files are purged after RETAIN_MINUTES; the window only has to
 * outlast the processor's reply, which comes back in seconds.
 */
final class DropStore
{
    /**
     * How long a dropped file stays fetchable — and re-sendable. Wazzup fetches it
     * within seconds and an automatic re-send follows the processor's reply by
     * about as long, so this only has to outlast one round trip; keeping it short
     * is what stops the drop folder growing without bound.
     */
    public const RETAIN_MINUTES = 15;

    /** Where dropped files live (config 'drop.dir' lets the tests point elsewhere). */
    public static function dir(): string
    {
        $dir = (string)cfg('drop.dir', '') ?: STORAGE_ROOT . '/drop';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        return $dir;
    }

    /** A fresh token for an upload of this type, e.g. "a1b2….pdf". */
    public static function newToken(string $ext): string
    {
        return bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    }

    public static function path(string $token): string
    {
        $token = basename(trim($token));
        return $token === '' ? '' : self::dir() . '/' . $token;
    }

    /** Is this token's file still on disk (i.e. still re-sendable)? */
    public static function has(string $token): bool
    {
        $p = self::path($token);
        return $p !== '' && is_file($p);
    }

    /** The public URL Wazzup fetches. '' when the app has no absolute base URL. */
    public static function url(string $token, string $base = ''): string
    {
        $base = rtrim($base !== '' ? $base : (string)cfg('app.base_url', ''), '/');
        if ($token === '' || !preg_match('#^https?://#i', $base)) return '';
        return $base . '/drop/' . basename($token);
    }

    /** The token inside a drop URL ('' if it is not one of ours). */
    public static function tokenFromUrl(string $url): string
    {
        return preg_match('#/drop/([a-f0-9]{32}\.[a-z0-9]{2,5})$#i', trim($url), $m) ? strtolower($m[1]) : '';
    }

    /** Delete anything past its retention window. Called on every new upload. */
    public static function purge(): void
    {
        $cutoff = time() - self::RETAIN_MINUTES * 60;
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $cutoff) @unlink($f);
        }
    }

    /**
     * Work out which dropped file an intake log line is about, for callers that
     * do not say (an older Apps Script posts no drop_url): the newest file no
     * Inbox row has claimed yet, of the same type and byte size as the attachment.
     * The poller drops a file and logs it moments later, one attachment at a time,
     * so the newest unclaimed match is that attachment. '' when nothing fits —
     * the send is still logged, it just cannot be re-sent automatically.
     */
    public static function claimFor(string $fileName, int $size): string
    {
        $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '' || $size <= 0) return '';

        $used = array_flip(array_column(
            Db::all("SELECT drop_token FROM inbox_events WHERE COALESCE(drop_token,'') <> ''"), 'drop_token'
        ));

        $best = ['token' => '', 'at' => 0];
        foreach (glob(self::dir() . '/*.' . $ext) ?: [] as $f) {
            $token = basename($f);
            if (isset($used[$token]) || !is_file($f) || filesize($f) !== $size) continue;
            $at = (int)filemtime($f);
            if ($at >= $best['at']) $best = ['token' => $token, 'at' => $at];
        }
        return $best['token'];
    }
}
