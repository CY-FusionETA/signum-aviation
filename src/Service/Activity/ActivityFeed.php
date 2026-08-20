<?php
declare(strict_types=1);

namespace App\Service\Activity;

use App\Db;
use App\Service\Inbox\InboxLog;

/**
 * "AI at work" feed for the dashboard.
 *
 * Turns what Unidash actually did — supplier invoices coming through the Inbox,
 * LEON trips landing in the master list — into a stream of short reasoning steps
 * (read → think → decide → do) that the dashboard animates. Nothing here is
 * invented: every step is built from a real row. The scripted "Simulate" demo
 * lives in the browser, not here, so this only ever reflects real activity.
 */
final class ActivityFeed
{
    /** Activity newer than this counts the assistant as "working" right now. */
    public const ACTIVE_WINDOW_MIN = 3;

    /**
     * The most recent events, newest first, each already shaped into reasoning
     * steps. @return list<array{at:string,kind:string,status:string,title:string,steps:list<array{phase:string,text:string}>,link:string}>
     */
    public static function recent(int $limit = 30): array
    {
        $limit  = max(1, min(100, $limit));
        $events = [];

        // source='gmail' matches InboxLog::rows() — a processor-only reply row is a
        // fragment of someone else's delivery, not an event in its own right, and
        // showing it here but not in the Inbox is exactly the kind of drift this
        // feed must not have.
        foreach (Db::all("SELECT * FROM inbox_events WHERE source = 'gmail' ORDER BY " . self::AT . " DESC LIMIT ?", [$limit]) as $r) {
            $events[] = self::invoiceEvent($r);
        }
        foreach (Db::all("SELECT * FROM leon_trips ORDER BY updated_at DESC, id DESC LIMIT ?", [$limit]) as $t) {
            $events[] = self::tripEvent($t);
        }

        usort($events, fn($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($events, 0, $limit);
    }

    /** Live summary for the panel header: is it working, and today's tallies. */
    public static function pulse(): array
    {
        $lastInbox = (string)(Db::scalar("SELECT MAX(" . self::AT . ") FROM inbox_events") ?? '');
        $lastTrip  = (string)(Db::scalar("SELECT MAX(updated_at) FROM leon_trips") ?? '');
        $last      = max($lastInbox, $lastTrip);

        $active = $last !== '' && strtotime($last . ' UTC') >= time() - self::ACTIVE_WINDOW_MIN * 60;
        $since  = gmdate('Y-m-d H:i:s', time() - 86400);

        return [
            'active'  => $active,
            'state'   => $active ? 'working' : 'watching',
            'last_at' => $last !== '' ? self::iso($last) : '',
            // Counted the same way the Inbox counts — see InboxLog::stats(). An
            // email we deliberately sent nothing for is not "delivered", and a
            // failed delivery needs review even though no processor ever answered.
            'today'   => [
                'sent'   => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events
                                              WHERE source='gmail' AND COALESCE(delivery,'') <> 'skipped'
                                                AND " . self::AT . " >= ?", [$since]),
                'bills'  => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events
                                              WHERE source='gmail' AND ocr_status IN ('created','success')
                                                AND " . self::AT . " >= ?", [$since]),
                'errors' => (int)Db::scalar("SELECT COUNT(*) FROM inbox_events
                                              WHERE source='gmail'
                                                AND ((ocr_status IN ('failed','error') AND COALESCE(dup_ok,0) <> 1)
                                                     OR delivery='failed')
                                                AND " . self::AT . " >= ?", [$since]),
                'trips'  => (int)Db::scalar("SELECT COUNT(*) FROM leon_trips WHERE updated_at >= ?", [$since]),
            ],
        ];
    }

    /** Best available timestamp for an inbox row: source event time, else record time. */
    private const AT = "COALESCE(NULLIF(event_at,''), ts)";

    /** One supplier-invoice delivery, reasoned out. */
    private static function invoiceEvent(array $r): array
    {
        $status = self::inboxStatus($r);
        $who    = self::senderName((string)($r['sender'] ?? ''));
        $file   = (string)($r['attachment'] ?? '') ?: 'an attachment';
        $num    = trim((string)($r['bill_number'] ?? ''));

        $read  = $who !== '' ? "Read {$file} from {$who}" : "Read {$file}";
        $think = 'Extracted the supplier, amount and invoice number';

        // Nothing was ever sent, so none of the reading/extracting steps below
        // happened. Say what the Inbox says instead of narrating work that did
        // not occur.
        if ($status === 'skipped') {
            $msg = InboxLog::plainMessage($r);
            return self::event($r, 'skip', $who !== '' ? "Email from {$who}" : 'Email with no attachment', [
                ['read',   $who !== '' ? "Read an email from {$who}" : 'Read an email'],
                ['think',  'It carries no attachment — there is nothing to extract'],
                ['decide', 'Nothing to send to the processor'],
                ['do',     $msg !== '' ? $msg : 'Nothing sent — this email has no attachment.'],
            ]);
        }

        // The attachment never reached the processor, so it was never read.
        if ($status === 'undelivered') {
            $msg = InboxLog::plainMessage($r);
            return self::event($r, 'err', $num !== '' ? "Supplier invoice {$num}" : 'Supplier invoice', [
                ['read',   $who !== '' ? "Took {$file} from {$who}" : "Took {$file}"],
                ['think',  'Prepared it for the processor'],
                ['decide', 'Could not deliver it to the processor'],
                ['do',     $msg !== '' ? "Not sent: {$msg}" : 'Not sent — flagged for review'],
            ]);
        }

        if ($status === 'cleared') {
            $decide = ($num !== '' ? "Invoice {$num} " : 'That invoice ') . 'was already in Xero — clear the stale copy';
            $do     = 'Deleted the old bill and re-created it fresh';
        } elseif ($status === 'ok') {
            $decide = ($num !== '' ? "Invoice {$num} is new" : 'This invoice is new') . ' — create the draft bill';
            $do     = 'Created the draft bill in Xero';
        } elseif ($status === 'pending') {
            $decide = 'Handed the invoice to the processor';
            $do     = 'Waiting for the result…';
        } elseif ($status === 'silent') {
            // Past its reply window: the result is never coming, and the Inbox
            // has already stopped calling this one pending.
            $decide = 'Handed the invoice to the processor';
            $do     = 'No reply came back — flagged for review';
        } else { // err
            $decide = 'Could not post the bill to Xero';
            $msg    = InboxLog::plainMessage($r);
            $do     = 'Flagged for review' . ($msg !== '' ? ": {$msg}" : '');
        }

        $sev = $status === 'ok' || $status === 'cleared' ? 'ok' : ($status === 'pending' ? 'pending' : 'err');
        return self::event($r, $sev, $num !== '' ? "Supplier invoice {$num}" : 'Supplier invoice', [
            ['read', $read], ['think', $think], ['decide', $decide], ['do', $do],
        ]);
    }

    /**
     * Shape one inbox row into a feed event.
     * @param list<array{0:string,1:string}> $steps [phase, text] pairs
     */
    private static function event(array $r, string $status, string $title, array $steps): array
    {
        return [
            'at'     => self::iso((string)($r['event_at'] ?: ($r['ts'] ?? ''))),
            'kind'   => 'invoice',
            'status' => $status,
            'title'  => $title,
            'steps'  => array_map(fn($s) => ['phase' => $s[0], 'text' => $s[1]], $steps),
            // Only a row that actually produced a bill has somewhere to link to.
            'link'   => $status === 'skip' ? '' : (string)($r['bill_url'] ?? ''),
        ];
    }

    /** One LEON trip landing in the master list, reasoned out. */
    private static function tripEvent(array $t): array
    {
        $trip   = (string)($t['trip_number'] ?? '');
        $client = (string)($t['client_name'] ?? '');
        $route  = (string)($t['route'] ?? '');
        $source = (string)($t['source_file'] ?? '') ?: 'a LEON file';
        $fresh  = (string)($t['created_at'] ?? '') === (string)($t['updated_at'] ?? '');

        $legs = self::legCount($route);
        return [
            'at'     => self::iso((string)($t['updated_at'] ?: ($t['created_at'] ?? ''))),
            'kind'   => 'trip',
            'status' => 'ok',
            'title'  => $trip !== '' ? "Trip {$trip}" : 'LEON trip',
            'steps'  => [
                ['phase' => 'read',   'text' => "Read {$source}"],
                ['phase' => 'think',  'text' => 'Parsed the Flight Count row' . ($legs ? " — {$legs} flight leg" . ($legs === 1 ? '' : 's') : '')],
                ['phase' => 'decide', 'text' => trim(($client !== '' ? "{$client} · " : '') . ($route !== '' ? $route : 'trip details'))],
                ['phase' => 'do',     'text' => $fresh ? 'Added to the trip master list' : 'Updated in the trip master list'],
            ],
            'link'   => '',
        ];
    }

    /**
     * The row's outcome: skipped | undelivered | ok | cleared | pending | silent | err.
     *
     * Delivery is checked BEFORE the processor's answer. A row that was never
     * sent has no answer and never will — reading ocr_status first is what made
     * a no-attachment email report as "waiting for the result".
     * Mirrors the Inbox: see InboxLog::stats() and the pills in render_inbox().
     */
    private static function inboxStatus(array $r): string
    {
        $delivery = strtolower(trim((string)($r['delivery'] ?? '')));
        if ($delivery === 'skipped') return 'skipped';
        if ($delivery === 'failed')  return 'undelivered';

        $s = strtolower(trim((string)($r['ocr_status'] ?? '')));
        if (($s === 'failed' || $s === 'error') && (int)($r['dup_ok'] ?? 0) === 1) return 'cleared';
        if ($s === 'created' || $s === 'success') return 'ok';
        if ($s === 'pending' || $s === '') {
            return InboxLog::isStale((string)($r['event_at'] ?: ($r['ts'] ?? '')), (string)($r['ocr_message'] ?? ''))
                ? 'silent' : 'pending';
        }
        return 'err';
    }

    /** "CY Weng <a@b.com>" → "CY Weng"; a bare address → the part before @. */
    private static function senderName(string $from): string
    {
        $from = trim($from);
        if ($from === '') return '';
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>/', $from, $m)) return trim($m[1]);
        if (str_contains($from, '@')) return trim(substr($from, 0, strpos($from, '@')));
        return $from;
    }

    /** Number of flight legs a route string implies ("A - B - C" → 2). */
    private static function legCount(string $route): int
    {
        $stops = array_values(array_filter(array_map('trim', preg_split('/\s*-\s*/', trim($route)) ?: [])));
        return count($stops) > 1 ? count($stops) - 1 : 0;
    }

    /** A stored UTC timestamp ("Y-m-d H:i:s") as ISO-8601 Z, for the browser. */
    private static function iso(string $ts): string
    {
        $ts = trim($ts);
        if ($ts === '') return '';
        $t = strtotime($ts . ' UTC');
        return $t === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
