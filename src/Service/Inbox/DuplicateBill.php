<?php
declare(strict_types=1);

namespace App\Service\Inbox;

use App\Db;
use App\Service\Xero\XeroClientFactory;

/**
 * The one Inbox failure an operator can fix from the dashboard: the processor
 * refused to create a bill because that invoice number is already on a bill in
 * Xero ("⚠️ Already exists in Xero"), so this send produced nothing.
 *
 * Usually the bill sitting in Xero is the leftover of an earlier, half-finished
 * send. Clearing it here deletes that bill and frees the invoice number, and the
 * operator then emails the supplier invoice in again so the processor creates a
 * fresh, complete draft bill.
 *
 * Only DRAFT/SUBMITTED bills are removed. An approved or paid bill is a real one
 * someone worked on, not leftover — those are refused and left for Xero.
 *
 * What was done is stamped on the Inbox row (inbox_events.dup_action) so the
 * button disappears once it has been cleared and the row says what happened.
 */
final class DuplicateBill
{
    /** Should this row offer the "delete the duplicate in Xero" button? */
    public static function offerFor(array $row): bool
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

    /**
     * Delete the bill in Xero this send collided with, so the invoice can be
     * emailed in again and processed from scratch.
     * @return array{ok:bool, message:string}
     */
    public static function remove(int $rowId, string $who = ''): array
    {
        $row = Db::one("SELECT * FROM inbox_events WHERE id = ?", [$rowId]);
        if (!$row)                 return ['ok' => false, 'message' => 'That Inbox entry no longer exists.'];
        if (!self::offerFor($row)) return ['ok' => false, 'message' => 'That entry is not a duplicate waiting to be cleared.'];

        $number = self::numberFor($row);
        $xero   = XeroClientFactory::make();

        $found = $xero->findBillByNumber($number);
        if (empty($found['ok'])) {
            return ['ok' => false, 'message' => "Could not look up {$number} in Xero: " . ($found['error'] ?? 'unknown error')];
        }
        // Someone already removed it in Xero — the number is free, so this is a
        // success from the operator's point of view: just resend the email.
        if (empty($found['found'])) {
            self::stamp($rowId, "{$number} was already gone from Xero", $who);
            return ['ok' => true, 'message' => "Bill {$number} is no longer in Xero. " . self::RESEND];
        }

        $del = $xero->deleteDraftBill((string)$found['invoice_id']);
        if (empty($del['ok'])) {
            return ['ok' => false, 'message' => "Could not delete {$number} in Xero: " . ($del['error'] ?? 'unknown error')];
        }

        self::stamp($rowId, "deleted bill {$number} in Xero", $who);
        return ['ok' => true, 'message' => "Deleted bill {$number} in Xero. " . self::RESEND];
    }

    /** What the operator has to do next — the delete alone creates no bill. */
    public const RESEND = 'Now email the supplier invoice in again and a fresh draft bill will be created.';

    /** One line for the row once the duplicate is cleared (blank if it is not). */
    public static function clearedNote(array $row): string
    {
        return trim((string)($row['dup_action'] ?? ''));
    }

    /** Record on the row what was done, so the button is a one-shot. */
    private static function stamp(int $rowId, string $what, string $who): void
    {
        $note = ucfirst($what) . ($who !== '' ? " by {$who}" : '') . ' on ' . gmdate('Y-m-d H:i') . ' UTC';
        Db::q("UPDATE inbox_events SET dup_action = ? WHERE id = ?", [$note, $rowId]);
    }
}
