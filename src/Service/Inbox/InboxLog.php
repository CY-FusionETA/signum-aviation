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
 * to deliveries still inside their window (see the constants below) — without that,
 * one attachment that never got an answer sits at the head of the queue and hands
 * every later reply to the wrong row.
 *
 * A per-run heartbeat (Settings 'inbox.last_run') lets the UI show the poller is
 * alive even on idle minutes that send no attachment.
 */
final class InboxLog
{
    /**
     * How long a delivery stays matchable. Once the processor has answered it at all
     * (a note — it is mid-conversation, e.g. waiting for the operator to pick an
     * organisation) the result can take a while, so it stays open for hours. A
     * delivery that got NO answer at all is only held briefly: replies normally come
     * back within seconds, so a silent one is dead and must stop competing, or it
     * absorbs the next attachment's result and shifts every later row.
     */
    public const MATCH_WINDOW_HOURS    = 6;
    public const SILENT_WINDOW_MINUTES = 10;

    /**
     * Record one attachment sent for processing — by the Gmail poller, or by
     * Unidash itself when it re-sends a file after clearing a duplicate bill
     * ('retry_of' = the row being retried).
     *
     * The file-drop token is kept so the same file can be sent again: the poller
     * passes 'drop_url', and an older Apps Script that doesn't is matched to its
     * upload by type + size (see DropStore::claimFor).
     */
    public static function recordDelivery(array $d): int
    {
        $sent  = (string)($d['delivery'] ?? '') === 'sent';
        $name  = (string)($d['attachment'] ?? '');
        $size  = (int)($d['att_size'] ?? 0);
        $token = isset($d['drop_token'])
            ? (string)$d['drop_token']
            : (DropStore::tokenFromUrl((string)($d['drop_url'] ?? '')) ?: ($sent ? DropStore::claimFor($name, $size) : ''));

        return Db::insert('inbox_events', [
            'event_at'       => self::utc((string)($d['event_at'] ?? '')),
            'source'         => 'gmail',
            'sender'         => (string)($d['sender'] ?? ''),
            'subject'        => (string)($d['subject'] ?? ''),
            'attachment'     => $name,
            'att_size'       => $size,
            'delivery'       => (string)($d['delivery'] ?? ''),
            'delivery_error' => (string)($d['delivery_error'] ?? ''),
            // Only a successfully sent file can get a processor result back.
            'ocr_status'     => $sent ? 'pending' : '',
            'ocr_message'    => '',
            'drop_token'     => $token,
            'retry_of'       => (int)($d['retry_of'] ?? 0) ?: null,
        ]);
    }

    /**
     * Record a processor message from the Wazzup webhook. Progress pings and other
     * non-result messages classify as 'ignore' and are skipped. Anything else is
     * deduped by message id and attached to the oldest delivery still pending
     * within the match window: a 'note' only appends to that row's message thread,
     * a success/failure closes the row and stores the Xero link + invoice number.
     * If nothing is waiting it's dropped (no standalone rows).
     *
     * 'row_id' is the row the reply closed and 'duplicate' says the processor
     * refused because the bill is already in Xero — the caller acts on that by
     * clearing the bill and re-sending (see DuplicateBill::autoResolve).
     * @return array{ok:bool, matched:bool, status:string, row_id?:int, duplicate?:bool}
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
        return [
            'ok'        => true,
            'matched'   => true,
            'status'    => $status,
            'row_id'    => (int)$pending['id'],
            'duplicate' => $status === 'failed' && self::isDuplicate($text),
        ];
    }

    /** The delivery a reply timestamped $at belongs to: oldest pending, still in window. */
    private static function pendingFor(string $at): ?array
    {
        $row = Db::one(
            "SELECT id, ocr_message FROM inbox_events
              WHERE source='gmail' AND ocr_status='pending'
                AND " . self::SENT_AT . " >= (CASE WHEN COALESCE(ocr_message,'') = '' THEN ? ELSE ? END)
              ORDER BY id ASC LIMIT 1",
            [self::cutoff($at, self::SILENT_WINDOW_MINUTES * 60), self::cutoff($at, self::MATCH_WINDOW_HOURS * 3600)]
        );
        return $row ?: null;
    }

    /** When the poller sent the attachment (event_at, falling back to the row's own ts). */
    private const SENT_AT = "COALESCE(NULLIF(event_at,''), ts)";

    /** Oldest delivery time a reply at $at (UTC, may be '') can still be matched to. */
    private static function cutoff(string $at, int $seconds): string
    {
        $base = $at !== '' ? strtotime($at . ' UTC') : time();
        if ($base === false) $base = time();
        return gmdate('Y-m-d H:i:s', $base - $seconds);
    }

    /**
     * True when a pending delivery is past its window — its result is never coming.
     * $reply is the row's message so far: an answered-but-unfinished delivery gets
     * the long window, a silent one only the short one.
     */
    public static function isStale(string $eventAt, string $reply = ''): bool
    {
        $t = strtotime(trim($eventAt) . ' UTC');
        $limit = trim($reply) === '' ? self::SILENT_WINDOW_MINUTES * 60 : self::MATCH_WINDOW_HOURS * 3600;
        return $t !== false && $t < time() - $limit;
    }

    /**
     * Classify a processor WhatsApp message into success | failed | note | ignore.
     *  - success: this send created the bill ("Xero draft bill created" / ✅).
     *  - failed: an explicit error (❌ / failure wording), or "Already exists in Xero"
     *    — no bill was created from this send and the operator should see why.
     *  - note: the AI analysis block or an "⚠️ action needed" prompt with no outcome
     *    yet — attached to the waiting row, which stays pending.
     *  - ignore: progress pings ("Reading your image…") and unrelated chatter —
     *    these must never consume a pending delivery or create a row.
     */
    public static function classify(string $text): string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') return 'ignore';

        // A duplicate the processor refused to re-create: nothing came of this send,
        // so it is a failure and the Inbox offers to clear the bill already in Xero.
        if (self::isDuplicate($text)) return 'failed';

        if (strpos($t, 'draft bill created') !== false || strpos($text, '✅') !== false) {
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

    /**
     * True when the processor refused to create the bill because that invoice
     * number is already on a bill in Xero ("⚠️ Already exists in Xero"). This is
     * the one failure an operator can clear from the Inbox — see DuplicateBill.
     */
    public static function isDuplicate(string $text): bool
    {
        $t = mb_strtolower($text);
        return strpos($t, 'already exists') !== false || strpos($t, 'already in xero') !== false;
    }

    /**
     * The Message column in short, plain English. The processor answers in long
     * WhatsApp blocks ("⚠️ *Already exists in Xero* … no action needed"); the table
     * shows one sentence saying what happened and what to do, and keeps the whole
     * reply on hover. '' when nothing went wrong (a created bill says nothing).
     */
    public static function plainMessage(array $row): string
    {
        $err = trim((string)($row['delivery_error'] ?? ''));
        if ($err !== '') return $err;                       // our own send errors are already short
        if ((string)($row['ocr_status'] ?? '') !== 'failed') return '';

        $reply = trim((string)($row['ocr_message'] ?? ''));
        if ($reply === '') return 'The bill was not created.';

        if (self::isDuplicate($reply)) {
            // Handled end to end (old copy deleted, invoice re-sent): the operator
            // only needs to know it happened — the detail is on the row's note.
            if (DuplicateBill::wasCleared($row)) return DuplicateBill::CLEARED_MESSAGE;

            $num = trim((string)($row['bill_number'] ?? '')) ?: self::extractBillNumber($reply);
            return $num !== ''
                ? "Already in Xero — bill {$num} exists, so no new bill was made."
                : 'Already in Xero — this invoice is on a bill already, so no new bill was made.';
        }

        $t = mb_strtolower($reply);
        // The handful of processor failures with a fixed shape get fixed wording;
        // anything else falls back to the processor's own headline.
        if (strpos($t, 'organisation') !== false || strpos($t, 'organization') !== false) {
            return 'Xero could not tell which company this bill is for.';
        }
        if (strpos($t, 'unsupported') !== false || strpos($t, 'could not read') !== false
            || strpos($t, "couldn't read") !== false || strpos($t, 'unreadable') !== false) {
            return 'The invoice could not be read — send a clearer PDF or photo.';
        }
        return self::headline($reply);
    }

    /**
     * The processor's own one-line summary of a failure: the ⚠️/❌ marker line
     * (its heading, e.g. "Could not create the bill — missing account code"),
     * stripped of WhatsApp bold/underscore markup.
     */
    private static function headline(string $reply): string
    {
        $tail = preg_match('/(?:⚠️|⚠|❌)[\s\S]*$/u', $reply, $m) ? $m[0] : $reply;
        $tail = trim(str_replace(['⚠️', '⚠', '❌', '*', '_'], '', $tail));
        $line = '';
        foreach (explode("\n", $tail) as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l));
            if ($l !== '') { $line = $l; break; }
        }
        return mb_strimwidth($line !== '' ? $line : 'The bill was not created.', 0, 90, '…');
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
        // A delivery still pending past its window will never be answered, so it
        // counts as an error, not as something we are still waiting for.
        $live = self::SENT_AT . " >= (CASE WHEN COALESCE(ocr_message,'') = ''"
              . " THEN datetime('now','-" . self::SILENT_WINDOW_MINUTES . " minutes')"
              . " ELSE datetime('now','-" . self::MATCH_WINDOW_HOURS . " hours') END)";
        return [
            'day'     => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE source='gmail' AND ts >= datetime('now','-1 day')"),
            'created' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE ocr_status='success'"),
            // A duplicate the app cleared by itself is not an error: the old copy is
            // gone and the invoice went back through the processor on its own.
            'errors'  => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events
                                           WHERE (ocr_status='failed' AND COALESCE(dup_ok,0) <> 1)
                                              OR delivery='failed' OR (ocr_status='pending' AND NOT $live)"),
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
