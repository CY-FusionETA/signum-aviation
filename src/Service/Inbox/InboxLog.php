<?php
declare(strict_types=1);

namespace App\Service\Inbox;

use App\Db;
use App\Settings;

/**
 * Module 1 execution log ("Inbox").
 *
 * Two feeds land here:
 *   - recordDelivery(): the Gmail poller reports each attachment it sent for
 *     processing (who/when/what + whether the hand-off succeeded). A delivered
 *     attachment starts as ocr_status='pending'.
 *   - recordReply(): the processor's WhatsApp result arrives via the Wazzup
 *     webhook. Its text is classified success|failed (progress pings and other
 *     chatter classify as 'ignore' and are dropped), the Xero bill link + invoice
 *     number are pulled out, and it's attached to the oldest still-pending
 *     delivery. A result with no delivery waiting is dropped — the Inbox only
 *     ever shows one row per invoice email, never stray processor rows.
 *
 * A per-run heartbeat (Settings 'inbox.last_run') lets the UI show the poller is
 * alive even on idle minutes that send no attachment.
 */
final class InboxLog
{
    /** Record one attachment the poller sent for processing. */
    public static function recordDelivery(array $d): int
    {
        $sent = (string)($d['delivery'] ?? '') === 'sent';
        return Db::insert('inbox_events', [
            'event_at'       => self::utc((string)($d['event_at'] ?? '')),
            'source'         => 'gmail',
            'sender'         => (string)($d['sender'] ?? ''),
            'subject'        => (string)($d['subject'] ?? ''),
            'attachment'     => (string)($d['attachment'] ?? ''),
            'att_size'       => (int)($d['att_size'] ?? 0),
            'delivery'       => (string)($d['delivery'] ?? ''),
            'delivery_error' => (string)($d['delivery_error'] ?? ''),
            // Only a successfully sent file can get a processor result back.
            'ocr_status'     => $sent ? 'pending' : '',
            'ocr_message'    => '',
        ]);
    }

    /**
     * Record a processor result from the Wazzup webhook. Progress pings and other
     * non-result messages classify as 'ignore' and are skipped. A real result is
     * deduped by message id, its Xero link + invoice number are extracted, and it
     * updates the oldest still-pending delivery. If nothing is waiting it's
     * dropped (no standalone rows).
     * @return array{ok:bool, matched:bool, status:string}
     */
    public static function recordReply(string $messageId, string $text, string $from, string $eventAt): array
    {
        $status = self::classify($text);
        if ($status === 'ignore') return ['ok' => true, 'matched' => false, 'status' => 'ignore'];

        $messageId = trim($messageId);
        if ($messageId !== '' && Db::one("SELECT id FROM inbox_events WHERE wazzup_message_id = ?", [$messageId])) {
            return ['ok' => true, 'matched' => false, 'status' => 'duplicate'];
        }

        $pending = Db::one("SELECT id FROM inbox_events WHERE source='gmail' AND ocr_status='pending' ORDER BY id ASC LIMIT 1");
        if (!$pending) return ['ok' => true, 'matched' => false, 'status' => $status]; // nothing waiting → drop

        Db::q(
            "UPDATE inbox_events SET ocr_status=?, ocr_message=?, ocr_at=?, wazzup_message_id=?, bill_url=?, bill_number=? WHERE id=?",
            [$status, $text, self::utc($eventAt), $messageId, self::extractBillUrl($text), self::extractBillNumber($text), (int)$pending['id']]
        );
        return ['ok' => true, 'matched' => true, 'status' => $status];
    }

    /**
     * Classify a processor WhatsApp message into success | failed | ignore.
     *  - success: the bill was created ("Xero draft bill created" / ✅) or it was
     *    already in Xero ("Already exists in Xero" — the bill is there either way).
     *  - failed: a result message (carries the "AI bill analysis" block or an
     *    explicit error) that isn't a success.
     *  - ignore: progress pings ("Reading your image…") and unrelated chatter —
     *    these must never consume a pending delivery or create a row.
     */
    public static function classify(string $text): string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return 'ignore';

        // Success first — "already exists" carries a ⚠️ but still means the bill is in Xero.
        if (strpos($t, 'draft bill created') !== false
            || strpos($t, 'already exists') !== false
            || strpos($text, '✅') !== false) {
            return 'success';
        }
        // Explicit failure wording, or the analysis block present with no create confirmation.
        if (preg_match('/\b(fail|failed|failure|error|unable|invalid|reject|rejected|unsupported|missing|not\s+create|no\s+bill|problem|couldn.?t|could\s+not|cannot|can.?t)\b/u', $t)
            || strpos($text, '❌') !== false
            || strpos($t, 'ai bill analysis') !== false) {
            return 'failed';
        }
        return 'ignore';
    }

    /** First Xero URL in a processor result (the "View: …" link), or ''. */
    public static function extractBillUrl(string $text): string
    {
        if (preg_match('~https?://\S*xero\S*~i', $text, $m)) return rtrim($m[0], '.,);]');
        return '';
    }

    /** The invoice number a processor result reports ("Invoice No: …"), or ''. */
    public static function extractBillNumber(string $text): string
    {
        if (preg_match('/invoice(?:\s*no)?\s*[:#]\s*(\S+)/i', $text, $m)) return trim($m[1]);
        return '';
    }

    /** Note that the poller ran (even an idle minute), for a liveness indicator. */
    public static function heartbeat(): void
    {
        Settings::set('inbox.last_run', gmdate('Y-m-d H:i:s'));
    }

    public static function lastRun(): string
    {
        return (string)Settings::get('inbox.last_run', '');
    }

    /** Recent invoice-email rows, newest first. (Processor-only rows are never shown.) */
    public static function rows(int $limit = 200): array
    {
        return Db::all("SELECT * FROM inbox_events WHERE source='gmail' ORDER BY id DESC LIMIT " . max(1, $limit));
    }

    public static function stats(): array
    {
        return [
            'day'     => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE source='gmail' AND ts >= datetime('now','-1 day')"),
            'created' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='success'"),
            'errors'  => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='failed' OR delivery='failed'"),
            'pending' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='pending'"),
        ];
    }

    /** Normalise a source timestamp to UTC "Y-m-d H:i:s"; '' if unparseable. */
    private static function utc(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        try {
            return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
