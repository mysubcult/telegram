<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/** Public handoff context, rendered inside the existing LHC notification. No API calls. */
class SmartiHandoff
{
    const MARKER = '{smartdiag.handoff_summary}';

    public static function cleanSummary($value)
    {
        if (!is_string($value)) {
            return '';
        }
        // Do not allow LHC placeholders, BBCode/files, HTML, links or credentials in a public summary.
        $value = preg_replace('/\b(?:парол[ьяе]|логин|password|login|token|токен|api[_ -]?key)\s*[:=—-]?\s*\S+/iu', '[данные скрыты]', $value);
        $value = preg_replace('~https?://\S+|\[(?:file|quote|img|url)[^\]]*\].*?(?:\[/[^\]]+\]|$)~isu', '', $value);
        $value = strip_tags($value);
        $value = str_replace(['{', '}', '[', ']', '<', '>'], '', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return mb_strlen($value) > 700 ? rtrim(mb_substr($value, 0, 699)) . '…' : $value;
    }

    public static function replaceSummary($params)
    {
        if (!isset($params['msg']) || strpos($params['msg'], self::MARKER) === false) {
            return;
        }
        $chat = $params['chat'];
        $vars = $chat->chat_variables_array;
        $summary = '';
        if ((int)$chat->gbot_id === 121 && ($vars['sd_summary_ready'] ?? '') === '1' &&
            in_array($vars['ai_function_name'] ?? '', ['call_operator', 'call_sales', 'handoff_serial_operator'], true)) {
            $summary = self::cleanSummary($vars['ai_action_args']['summary'] ?? '');
        }
        $block = $summary === '' ? '' : "\n\nКратко по обращению: " . $summary . "\nЕсли я что-то упустил, уточните здесь.";
        $params['msg'] = str_replace(self::MARKER, $block, $params['msg']);
    }
}
