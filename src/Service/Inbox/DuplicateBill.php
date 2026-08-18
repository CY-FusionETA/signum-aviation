<?php
declare(strict_types=1);

namespace App\Service\Inbox;

use App\Db;
use App\Settings;
use App\Service\Xero\XeroClientFactory;

/**
 * Automatic recovery from the processor's most common refusal: "⚠️ Already exists
 * in Xero" — that invoice number is already on a bill, so this send produced
 * nothing. The bill sitting in Xero is almost always the leftover of an earlier,
 * half-finished send of the same invoice.
 *
 * The moment such a reply lands in the Inbox, Unidash clears it by itself — no
 * button, no second email from the operator:
 *   1. find the bill in Xero under that invoice number,
 *   2. delete it if it is still a DRAFT (see the guard below),
 *   3. send the SAME file to the processor again over WhatsApp (the file-drop
 *      copy is still on disk — see DropStore), logging that re-send as its own
 *      Inbox row, so the processor's answer lands there and the bill is created
 *      fresh with the number now free.
 *
 * GUARDS, because this deletes in Xero off the back of an inbound WhatsApp text:
 *   - DRAFT only. SUBMITTED (awaiting approval), AUTHORISED and PAID bills are
 *     real work someone did, not leftovers: they are left alone and nothing is
 *     re-sent (re-sending would only collide again).
 *   - One attempt per invoice. The re-send is marked retry_of, and a retry that
 *     comes back "already exists" again is left for a human — no loop.
 *   - Every attempt is claimed and then stamped on the row (inbox_events
 *     .dup_action), so a repeated webhook can't run it twice and the Inbox shows
 *     exactly what was done.
 *   - Killable without a deploy: php cli/auto-clear-duplicates.php off
 */
final class DuplicateBill
{
    /** Automatic clearing on? Default on; toggle with cli/auto-clear-duplicates.php. */
    public static function enabled(): bool
    {
        return (string)Settings::get('inbox.auto_clear_duplicates', '1') !== '0';
    }

    /** A duplicate that has not been dealt with yet — the trigger for autoResolve(). */
    public static function isPending(array $row): bool
    {
        return (string)($row['ocr_status'] ?? '') === 'failed'
            && trim((string)($row['dup_action'] ?? '')) === ''
            && InboxLog::isDuplicate((string)($row['ocr_message'] ?? ''))
            && self::numberFor($row) !== '';
    }

    /** The Xero invoice number the duplicate sits under ('' if the reply never said). */
    public static function numberFor(array $row): string
    {
        $num = trim((string)($row['bill_number'] ?? ''));
        return $num !== '' ? $num : InboxLog::extractBillNumber((string)($row['ocr_message'] ?? ''));
    }

    /** What was done about this row's duplicate, for the Inbox ('' = nothing yet). */
    public static function note(array $row): string
    {
        return trim((string)($row['dup_action'] ?? ''));
    }

    /**
     * True when the duplicate was dealt with end to end — old copy gone from Xero
     * and the invoice on its way through the processor again. The send itself
     * failed, but nothing is left for anyone to do, so the Inbox shows the row as
     * a success rather than an error.
     */
    public static function wasCleared(array $row): bool
    {
        return (int)($row['dup_ok'] ?? 0) === 1;
    }

    /** What such a row says in the Message column. */
    public const CLEARED_MESSAGE = 'Duplicate invoice detected, auto deleted old copy.';

    /**
     * Clear one duplicate and get the invoice processed again. Safe to call on any
     * row: anything that isn't an unhandled duplicate is a no-op. Never throws —
     * it runs off a webhook, and every outcome is written to the row instead.
     * $xero is only passed by the tests; production always uses the connected org.
     * @return array{ok:bool, message:string}
     */
    public static function autoResolve(int $rowId, ?\App\Service\Xero\XeroClientInterface $xero = null): array
    {
        try {
            $row = Db::one("SELECT * FROM inbox_events WHERE id = ?", [$rowId]);
            if (!$row || !self::isPending($row)) {
                return ['ok' => false, 'message' => 'Nothing to clear on that entry.'];
            }
            $number = self::numberFor($row);

            if (!self::enabled()) {
                return self::stamp($rowId, false, "Automatic clearing is off — bill {$number} left in Xero.");
            }
            // The re-send hit the same bill again: stop rather than loop.
            if ((int)($row['retry_of'] ?? 0) > 0) {
                return self::stamp($rowId, false, "Still already in Xero after re-sending — bill {$number} needs a look.");
            }

            // Claim it first, so a webhook delivered twice can't run this twice.
            Db::q("UPDATE inbox_events SET dup_action = ? WHERE id = ? AND COALESCE(dup_action,'') = ''",
                  ['Clearing the duplicate…', $rowId]);

            $xero  = $xero ?: XeroClientFactory::make();
            $found = $xero->findBillByNumber($number);
            if (empty($found['ok'])) {
                return self::stamp($rowId, false, "Could not look up bill {$number} in Xero: " . ($found['error'] ?? 'unknown error'));
            }

            $cleared = "Bill {$number} was already gone from Xero";
            if (!empty($found['found'])) {
                $status = (string)($found['status'] ?? '');
                if ($status !== 'DRAFT') {
                    return self::stamp($rowId, false,
                        "Bill {$number} is " . strtolower($status) . " in Xero, not a draft — left alone, nothing re-sent.");
                }
                $del = $xero->deleteDraftBill((string)$found['invoice_id']);
                if (empty($del['ok'])) {
                    return self::stamp($rowId, false, "Could not delete bill {$number} in Xero: " . ($del['error'] ?? 'unknown error'));
                }
                $cleared = "Deleted bill {$number} in Xero";
            }

            $sent = self::resend($row);
            return empty($sent['ok'])
                ? self::stamp($rowId, false, "{$cleared}, but could not re-send the invoice: " . ($sent['error'] ?? 'unknown error'))
                : self::stamp($rowId, true,  "{$cleared} and sent the invoice for processing again");
        } catch (\Throwable $e) {
            return self::stamp($rowId, false, 'Clearing the duplicate failed: ' . $e->getMessage());
        }
    }

    /**
     * Send this row's file to the processor again, logged as its own Inbox row so
     * the reply attaches to the re-send and not to some other attachment. The row
     * is written before the send, so an instant reply can never arrive with
     * nothing waiting for it; a failed send is marked on it.
     * @return array{ok:bool, error?:string}
     */
    private static function resend(array $row): array
    {
        $token = (string)($row['drop_token'] ?? '');
        if ($token === '' || !DropStore::has($token)) {
            return ['ok' => false, 'error' => 'the file is no longer on the server (send the invoice email again)'];
        }
        $url = DropStore::url($token);
        if ($url === '') return ['ok' => false, 'error' => 'the app has no public base URL to serve the file from'];

        $retryId = InboxLog::recordDelivery([
            'event_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'sender'     => (string)($row['sender'] ?? ''),
            'subject'    => (string)($row['subject'] ?? ''),
            'attachment' => (string)($row['attachment'] ?? ''),
            'att_size'   => (int)($row['att_size'] ?? 0),
            'delivery'   => 'sent',
            'drop_token' => $token,
            'retry_of'   => (int)$row['id'],
        ]);

        $send = Wazzup::sendFile($url);
        if (empty($send['ok'])) {
            Db::q("UPDATE inbox_events SET delivery='failed', delivery_error=?, ocr_status='' WHERE id=?",
                  [(string)($send['error'] ?? 'send failed'), $retryId]);
            return ['ok' => false, 'error' => (string)($send['error'] ?? 'send failed')];
        }
        return ['ok' => true];
    }

    /**
     * External-API path: clear the leftover bill under $number so the same invoice
     * can be sent to WazzOCR again and created fresh. Unlike autoResolve() this does
     * NOT re-send and touches no Inbox row — the caller (the Apps Script) still holds
     * the PDF, re-sends it, and logs the fresh result carrying CLEARED_MESSAGE. Only
     * a live DRAFT is deleted; SUBMITTED/AUTHORISED/PAID work is left alone. When the
     * only copy is voided/deleted there is nothing to delete (WazzOCR still treats
     * the number as taken — that needs a WazzOCR-side change), so 'not-live' is
     * returned and the caller does not bother re-sending.
     * Every result carries 'retryable': true means Xero said "not now" (rate limit,
     * outage) and the SAME send should be tried again later — the caller must not
     * record the invoice as handled, or it is dropped for good. false means the
     * answer will not change on its own (nothing live to delete, or a bill someone
     * has already approved).
     * @return array{ok:bool, cleared:bool, retryable:bool, status:string, message:string}
     */
    public static function clearByNumber(string $number, ?\App\Service\Xero\XeroClientInterface $xero = null): array
    {
        $number = trim($number);
        if ($number === '') return ['ok' => false, 'cleared' => false, 'retryable' => false, 'status' => '', 'message' => 'No invoice number given.'];
        if (!self::enabled()) return ['ok' => false, 'cleared' => false, 'retryable' => false, 'status' => '', 'message' => "Automatic clearing is off — bill {$number} left in Xero."];

        // Xero already told us it will refuse until a given time — don't spend a
        // call (or a WazzOCR upload) to be told again, just say when to come back.
        if ($xero === null && ($wait = \App\Service\Xero\XeroOAuth::cooldownLeft()) > 0) {
            return ['ok' => false, 'cleared' => false, 'retryable' => true, 'retry_after' => $wait, 'status' => 'cooldown',
                    'message' => "Xero is still rate-limiting; bill {$number} will be cleared automatically once it frees up."];
        }

        try {
            $xero  = $xero ?: XeroClientFactory::make();
            $found = $xero->findBillByNumber($number);
            if (empty($found['ok'])) {
                // A lookup that failed tells us nothing about the bill: retry unless
                // Xero gave a definite answer.
                return ['ok' => false, 'cleared' => false, 'retryable' => (bool)($found['retryable'] ?? true), 'status' => '',
                        'retry_after' => \App\Service\Xero\XeroOAuth::cooldownLeft(),
                        'message' => "Could not look up bill {$number} in Xero: " . ($found['error'] ?? 'unknown error')];
            }
            if (empty($found['found'])) {
                return ['ok' => false, 'cleared' => false, 'retryable' => false, 'status' => 'not-live',
                        'message' => "No live bill {$number} in Xero to delete — the existing copy is voided or deleted, which WazzOCR still treats as taken."];
            }
            $status = strtoupper((string)($found['status'] ?? ''));
            if ($status !== 'DRAFT') {
                return ['ok' => false, 'cleared' => false, 'retryable' => false, 'status' => $status,
                        'message' => "Bill {$number} is " . strtolower($status) . " in Xero, not a draft — left alone, nothing recreated."];
            }
            $del = $xero->deleteDraftBill((string)$found['invoice_id']);
            if (empty($del['ok'])) {
                return ['ok' => false, 'cleared' => false, 'retryable' => (bool)($del['retryable'] ?? true), 'status' => $status,
                        'message' => "Could not delete bill {$number} in Xero: " . ($del['error'] ?? 'unknown error')];
            }
            return ['ok' => true, 'cleared' => true, 'retryable' => false, 'status' => 'deleted', 'message' => "Deleted bill {$number} in Xero."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'cleared' => false, 'retryable' => true, 'status' => '', 'message' => 'Clearing the duplicate failed: ' . $e->getMessage()];
        }
    }

    /**
     * Record the outcome on the row and hand it back as the call's result. The
     * time is the app's own (Malaysia, UTC+8), like every other time in the Inbox —
     * stored timestamps stay UTC, but nothing an operator reads should be.
     */
    private static function stamp(int $rowId, bool $ok, string $note): array
    {
        Db::q("UPDATE inbox_events SET dup_action = ?, dup_ok = ? WHERE id = ?",
              [$note . ' · ' . date('d M Y H:i') . ' MYT', $ok ? 1 : 0, $rowId]);
        return ['ok' => $ok, 'message' => $note];
    }
}
