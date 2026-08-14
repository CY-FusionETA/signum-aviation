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
 *     webhook. Its text is classified success|failed|note (progress pings and
 *     other chatter classify as 'ignore' and are dropped), the Xero bill link +
 *     invoice number are pulled out, and it's attached to the oldest still-pending
 *     delivery. A result with no delivery waiting is dropped — the Inbox only
 *     ever shows one row per invoice email, never stray processor rows.
 *
 * The processor usually sends TWO messages per attachment: the AI analysis block
 * (sometimes "⚠️ Action needed: pick the organisation") and then the "✅ draft bill
 * created" confirmation. Only the second one is a result: a 'note' is appended to
 * the row's message thread and leaves it PENDING, so the confirmation lands on the
 * same row instead of being handed to the next attachment. Matching is also limited
 * to deliveries sent within MATCH_WINDOW_HOURS of the reply — without that, one
 * attachment that never got an answer sits at the head of the queue forever and
 * shifts every later reply onto the wrong row.
 *
 * A per-run heartbeat (Settings 'inbox.last_run') lets the UI show the poller is
 * alive even on idle minutes that send no attachment.
 */
final class InboxLog
{
    /** How long after a delivery a processor reply can still belong to it. */
    public const MATCH_WINDOW_HOURS = 6;

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
     * Record a processor message from the Wazzup webhook. Progress pings and other
     * non-result messages classify as 'ignore' and are skipped. Anything else is
     * deduped by message id and attached to the oldest delivery still pending
     * within the match window: a 'note' only appends to that row's message thread,
     * a success/failure closes the row and stores the Xero link + invoice number.
     * If nothing is waiting it's dropped (no standalone rows).
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

        $at      = self::utc($eventAt);
        $pending = self::pendingFor($at);
        if (!$pending) return ['ok' => true, 'matched' => false, 'status' => $status]; // nothing waiting → drop

        // Keep the whole WhatsApp thread for this attachment (analysis, then the
        // confirmation) — the Inbox shows it in full on hover.
        $thread = trim((string)($pending['ocr_message'] ?? ''));
        $text   = trim($text);
        $full   = ($thread !== '' && strpos($thread, $text) === false) ? $thread . "\n\n" . $text : ($thread !== '' ? $thread : $text);

        // A note is not an outcome: record it but leave the row pending so the real
        // result lands here and not on the next attachment's row.
        if ($status === 'note') {
            Db::q("UPDATE inbox_events SET ocr_message=?, wazzup_message_id=? WHERE id=?", [$full, $messageId, (int)$pending['id']]);
            return ['ok' => true, 'matched' => true, 'status' => 'note'];
        }

        Db::q(
            "UPDATE inbox_events SET ocr_status=?, ocr_message=?, ocr_at=?, wazzup_message_id=?, bill_url=?, bill_number=? WHERE id=?",
            [$status, $full, $at, $messageId, self::extractBillUrl($full), self::extractBillNumber($full), (int)$pending['id']]
        );
        return ['ok' => true, 'matched' => true, 'status' => $status];
    }

    /** The delivery a reply timestamped $at belongs to: oldest pending, still in window. */
    private static function pendingFor(string $at): ?array
    {
        $row = Db::one(
            "SELECT id, ocr_message FROM inbox_events
              WHERE source='gmail' AND ocr_status='pending'
                AND COALESCE(NULLIF(event_at,''), ts) >= ?
              ORDER BY id ASC LIMIT 1",
            [self::cutoff($at)]
        );
        return $row ?: null;
    }

    /** Oldest delivery time a reply at $at (UTC, may be '') can still be matched to. */
    private static function cutoff(string $at): string
    {
        $base = $at !== '' ? $at : gmdate('Y-m-d H:i:s');
        try {
            return (new \DateTimeImmutable($base, new \DateTimeZone('UTC')))
                ->modify('-' . self::MATCH_WINDOW_HOURS . ' hours')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return gmdate('Y-m-d H:i:s', time() - self::MATCH_WINDOW_HOURS * 3600);
        }
    }

    /** True when a pending delivery is past the window — its reply is never coming. */
    public static function isStale(string $eventAt): bool
    {
        $t = strtotime(trim($eventAt) . ' UTC');
        return $t !== false && $t < time() - self::MATCH_WINDOW_HOURS * 3600;
    }

    /**
     * Classify a processor WhatsApp message into success | failed | note | ignore.
     *  - success: the bill was created ("Xero draft bill created" / ✅) or it was
     *    already in Xero ("Already exists in Xero" — the bill is there either way).
     *  - failed: an explicit error (❌ / failure wording).
     *  - note: the AI analysis block or an "⚠️ action needed" prompt with no outcome
     *    yet — attached to the waiting row, which stays pending.
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
        // Explicit failure wording.
        if (preg_match('/\b(fail|failed|failure|error|unable|invalid|reject|rejected|unsupported|missing|not\s+create|no\s+bill|problem|couldn.?t|could\s+not|cannot|can.?t)\b/u', $t)
            || strpos($text, '❌') !== false) {
            return 'failed';
        }
        // The analysis block on its own, or a prompt for the operator: work in progress.
        if (strpos($t, 'ai bill analysis') !== false
            || strpos($t, 'action needed') !== false
            || strpos($text, '⚠️') !== false) {
            return 'note';
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
        // A delivery still pending past the match window will never be answered, so it
        // counts as an error, not as something we are still waiting for.
        $live = "COALESCE(NULLIF(event_at,''), ts) >= datetime('now','-" . self::MATCH_WINDOW_HOURS . " hours')";
        return [
            'day'     => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE source='gmail' AND ts >= datetime('now','-1 day')"),
            'created' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='success'"),
            'errors'  => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='failed' OR delivery='failed' OR (ocr_status='pending' AND NOT $live)"),
            'pending' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='pending' AND $live"),
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
