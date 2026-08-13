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
 *   - recordReply(): the processor's WhatsApp reply arrives via the Wazzup
 *     webhook. Its text is classified (created / error / unknown) and attached to
 *     the oldest still-pending delivery (FIFO); if none is waiting it is kept as
 *     its own 'processor' row so nothing is lost.
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
            // Only a successfully sent file can get a processor reply back.
            'ocr_status'     => $sent ? 'pending' : '',
            'ocr_message'    => '',
        ]);
    }

    /**
     * Record a processor reply from the Wazzup webhook. Deduped by message id.
     * Attaches to the oldest pending delivery, else stands alone.
     * @return array{ok:bool, matched:bool, status:string}
     */
    public static function recordReply(string $messageId, string $text, string $from, string $eventAt): array
    {
        $messageId = trim($messageId);
        if ($messageId !== '' && Db::one("SELECT id FROM inbox_events WHERE wazzup_message_id = ?", [$messageId])) {
            return ['ok' => true, 'matched' => false, 'status' => 'duplicate'];
        }
        $status = self::classify($text);
        $at     = self::utc($eventAt);

        $pending = Db::one("SELECT id FROM inbox_events WHERE source='gmail' AND ocr_status='pending' ORDER BY id ASC LIMIT 1");
        if ($pending) {
            Db::q(
                "UPDATE inbox_events SET ocr_status=?, ocr_message=?, ocr_at=?, wazzup_message_id=? WHERE id=?",
                [$status, $text, $at, $messageId, (int)$pending['id']]
            );
            return ['ok' => true, 'matched' => true, 'status' => $status];
        }
        // No delivery waiting — keep the reply on its own so the error is not lost.
        Db::insert('inbox_events', [
            'event_at'          => $at,
            'source'            => 'processor',
            'sender'            => $from,
            'ocr_status'        => $status,
            'ocr_message'       => $text,
            'ocr_at'            => $at,
            'wazzup_message_id' => $messageId,
        ]);
        return ['ok' => true, 'matched' => false, 'status' => $status];
    }

    /**
     * Classify a processor reply. Error wins over created (a "couldn't create the
     * bill" message mentions "bill" too), so error keywords are checked first.
     */
    public static function classify(string $text): string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return 'unknown';
        if (preg_match('/\b(fail|failed|failure|error|unable|invalid|reject|rejected|unsupported|missing|not\s+create|no\s+bill|problem|couldn.?t|could\s+not|cannot|can.?t)\b/u', $t)
            || preg_match('/[❌⚠️]/u', $text)) {
            return 'error';
        }
        if (preg_match('/\b(creat|created|draft|bill|added|success|successfully|done|recorded|posted|imported)\b/u', $t)
            || preg_match('/[✅]/u', $text)) {
            return 'created';
        }
        return 'unknown';
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

    /** Recent events, newest first. */
    public static function rows(int $limit = 200): array
    {
        return Db::all("SELECT * FROM inbox_events ORDER BY id DESC LIMIT " . max(1, $limit));
    }

    public static function stats(): array
    {
        return [
            'day'     => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE source='gmail' AND ts >= datetime('now','-1 day')"),
            'created' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='created'"),
            'errors'  => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='error' OR delivery='failed'"),
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
