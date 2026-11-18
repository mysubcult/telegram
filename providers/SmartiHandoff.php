<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/** Public handoff context, rendered inside the existing LHC notification. No API calls. */
class SmartiHandoff
{
    const MARKER = '{smartdiag.handoff_summary}';

    public static function cleanSummary($value, $testAccess = false)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('~https?://\S+|\[(?:file|quote|img|url)[^\]]*\].*?(?:\[/[^\]]+\]|$)~isu', '', $value);

        // Values can follow a label across several words or sentences. Keep only
        // complete sentences before it and describe test access from the task.
        $sensitive = '/\b(?:парол\p{L}*|логин\p{L}*|токен\p{L}*|passwords?|logins?|(?:access[_ -]?|refresh[_ -]?)?tokens?|api[_ -]?keys?|данные\s+скрыты)\b/iu';
        if (preg_match($sensitive, $value, $match, PREG_OFFSET_CAPTURE)) {
            $prefix = substr($value, 0, $match[0][1]);
            $sentences = preg_split('/(?<=[.!?…])\s+/u', $prefix);
            array_pop($sentences);
            $value = implode(' ', $sentences);
            if ($testAccess && !preg_match('/тестов\p{L}*\s+доступ/iu', $value)) {
                $value = rtrim($value) . ' Для проверки прибора нужен тестовый доступ по EOBD.';
            }
        }
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
            in_array($vars['ai_function_name'] ?? '', ['call_operator', 'call_sales', 'call_service', 'handoff_serial_operator'], true)) {
            $args = $vars['ai_action_args'] ?? [];
            $task = ($vars['sd_activation_task'] ?? '') ?: ($args['task'] ?? '');
            $testAccess = $vars['ai_function_name'] === 'handoff_serial_operator' && $task === 'test_access';
            $summary = self::cleanSummary($args['summary'] ?? '', $testAccess);
        }
        $block = $summary === '' ? '' : "\n\nПодведу итог нашего разговора:\n\n" . $summary . "\n\nЕсли я что-то упустил, дополните меня здесь.";
        $params['msg'] = str_replace(self::MARKER, $block, $params['msg']);
    }
}
