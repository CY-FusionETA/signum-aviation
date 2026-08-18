<?php
declare(strict_types=1);

namespace App\Service\Wazz;

use App\Settings;

/**
 * "AI prompt add-on" for WazzOCR extraction.
 *
 * Extra, account-specific instructions the operator manages here in Unidash
 * (Settings → AI prompt add-on). The enabled blocks are combined into one string
 * and sent to WazzOCR on every upload as the External API's per-request `aiPrompt`
 * field (layer 3 in WazzOCR's prompt order) — applied to that upload only, never
 * saved on the WazzOCR account. Stored as a JSON array in one setting so there is
 * no schema/migration to carry.
 */
final class AiPrompt
{
    private const KEY = 'wazzocr.ai_prompts';

    /**
     * The saved prompt blocks, in order.
     * @return list<array{title:string, body:string, enabled:bool}>
     */
    public static function blocks(): array
    {
        $raw = (string)Settings::get(self::KEY, '');
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $b) {
            if (!is_array($b)) continue;
            $out[] = [
                'title'   => trim((string)($b['title'] ?? '')),
                'body'    => rtrim((string)($b['body'] ?? '')),
                'enabled' => !empty($b['enabled']),
            ];
        }
        return $out;
    }

    /**
     * Replace all blocks. Blocks with an empty body are dropped, so a stray blank
     * row the operator added and left empty never reaches WazzOCR.
     * @param list<array{title?:string, body?:string, enabled?:bool}> $blocks
     */
    public static function save(array $blocks): void
    {
        $clean = [];
        foreach ($blocks as $b) {
            $body = rtrim((string)($b['body'] ?? ''));
            if (trim($body) === '') continue;
            $clean[] = [
                'title'   => trim((string)($b['title'] ?? '')),
                'body'    => $body,
                'enabled' => !empty($b['enabled']),
            ];
        }
        Settings::set(self::KEY, $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
    }

    /**
     * The enabled blocks as one prompt string for the WazzOCR `aiPrompt` field,
     * or '' when nothing is enabled (send no aiPrompt at all in that case). Each
     * block is prefixed with its title so the rules read clearly to the model.
     */
    public static function combined(): string
    {
        $parts = [];
        foreach (self::blocks() as $b) {
            if (!$b['enabled'] || trim($b['body']) === '') continue;
            $parts[] = $b['title'] !== '' ? $b['title'] . ":\n" . $b['body'] : $b['body'];
        }
        return implode("\n\n", $parts);
    }
}
