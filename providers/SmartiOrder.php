<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/** Purchase context for support. Format checks never confirm ownership or payment. */
class SmartiOrder
{
    const RULES = [
        'site' => '(?i:SD)-[0-9]{8}-[A-Fa-f0-9]{6}',
        'ozon' => '[0-9]{8,10}-[0-9]{4}(?:-[0-9]{1,3})?',
        'market' => '[0-9]{7,12}',
        'serial' => '[0-9]{12}',
    ];
    const LABELS = ['site' => 'Сайт СМАРТДИАГ', 'ozon' => 'Ozon', 'market' => 'Яндекс Маркет',
        'wb' => 'Wildberries', 'screenshot' => 'Скриншот покупки', 'serial' => 'Покупал у СМАРТДИАГ, без номера заказа'];

    public static function normalize($source, $value)
    {
        if (!is_string($source) || !is_string($value) || strlen($value) > 160 || !isset(self::RULES[$source])) return null;
        $value = trim(str_replace(["\xc2\xa0", '–', '—', '−'], [' ', '-', '-', '-'], $value));
        $value = preg_replace('/^(?:номер\s+заказа\s*[:№#]?|заказ\s*[:№#]?|[№#])\s*/iu', '', $value);
        $value = preg_replace('/\s*-\s*/u', '-', trim($value));
        if (!preg_match('~\A(?:' . self::RULES[$source] . ')\z~u', $value)) return null;
        if ($source === 'site') {
            $date = substr($value, 3, 8);
            if (!checkdate((int)substr($date, 4, 2), (int)substr($date, 6, 2), (int)substr($date, 0, 4))) return null;
            $value = strtoupper($value);
        }
        // A phone or known scanner serial is not a Yandex Market order.
        if ($source === 'market' && (preg_match('/^(?:7|8)[0-9]{10}$/', $value) ||
            preg_match('/^(?:968|965|979|988|989)[0-9]{9}$/', $value))) return null;
        if (preg_match('/^([0-9])\1+$/', $value)) return null;
        return $value;
    }

    private static function save($chat, array $data)
    {
        $attrs = $chat->additional_data ? (array)json_decode($chat->additional_data, true) : [];
        $attrs = array_values(array_filter($attrs, function ($a) { return ($a['identifier'] ?? '') !== 'sd_support_purchase'; }));
        $attrs[] = ['key' => 'Покупка: со слов посетителя, без проверки заказа', 'identifier' => 'sd_support_purchase',
            'value' => self::LABELS[$data['source']] . ': ' . (in_array($data['source'], ['wb', 'screenshot'], true) ? 'скриншот в переписке' : $data['number'])];
        $vars = $chat->chat_variables_array;
        $vars['sd_support_purchase'] = $data;
        $chat->chat_variables_array = $vars;
        $chat->chat_variables = json_encode($vars);
        $chat->additional_data = json_encode($attrs, JSON_UNESCAPED_UNICODE);
        $chat->updateThis(['update' => ['additional_data', 'chat_variables']]);
    }

    public static function purchase($chat)
    {
        $p = $chat->chat_variables_array['sd_support_purchase'] ?? null;
        if (is_array($p)) {
                if (isset(self::RULES[$p['source'] ?? '']) && self::normalize($p['source'], $p['number'] ?? '') !== null) return $p;
                if (in_array($p['source'] ?? '', ['wb', 'screenshot'], true) &&
                    self::ownedFile($chat, $p['number'] ?? '') !== null) return $p;
        }
        return null;
    }

    private static function ownedFile($chat, $payload)
    {
        if (!is_string($payload) || strlen($payload) > 1500 || substr_count($payload, '[file=') !== 1 ||
            !preg_match('/\[file="?([0-9]+)_([a-f0-9]+)"?\]/i', $payload, $m)) return null;
        try {
            $file = \erLhcoreClassModelChatFile::fetch((int)$m[1]);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_object($file) || (int)$file->chat_id !== (int)$chat->id || (int)$file->user_id !== 0 ||
            !hash_equals((string)$file->security_hash, $m[2]) ||
            !in_array(strtolower($file->extension), ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true)) return null;
        return '[file=' . $file->id . '_' . $file->security_hash . ']';
    }

    public static function handle($params)
    {
        $render = $params['render'] ?? '';
        if (!in_array($render, ['smartdiag.order_ready', 'smartdiag.order_validate', 'smartdiag.activation_requires_order'], true)) return;
        $chat = $params['chat'];
        if ((int)$chat->gbot_id !== 121 || ($chat->chat_variables_array['pd_consent'] ?? '') !== '1') {
            return ['status' => \erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'validation_result' => '0'];
        }
        if ($render === 'smartdiag.activation_requires_order') {
            $vars = $chat->chat_variables_array;
            $task = ($vars['sd_activation_task'] ?? '') ?: ($vars['ai_action_args']['task'] ?? '');
            return ['status' => \erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'validation_result' => $task === 'activation_after_payment' ? '1' : '0'];
        }
        if ($render === 'smartdiag.order_ready') {
            // Only real visitor messages can supply an already mentioned order; never model arguments.
            if (self::purchase($chat) === null && empty($chat->chat_variables_array['sd_order_source'])) {
                $messages = \erLhcoreClassModelmsg::getList(['filter' => ['chat_id' => $chat->id, 'user_id' => 0], 'sort' => 'id DESC', 'limit' => 100]);
                foreach ($messages as $msg) {
                    foreach (['site', 'ozon'] as $source) {
                        if ($source === 'ozon' && !preg_match('/(?:заказ|ozon|озон)/iu', $msg->msg)) continue;
                        if (preg_match('~(?<![A-Za-z0-9-])(' . self::RULES[$source] . ')(?![A-Za-z0-9-])~u', $msg->msg, $m) &&
                            self::normalize($source, $m[1]) !== null) {
                            self::save($chat, ['source' => $source, 'number' => self::normalize($source, $m[1]), 'check' => 'format_only']);
                            break 2;
                        }
                    }
                }
            }
            return ['status' => \erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'validation_result' => self::purchase($chat) === null ? '0' : '1'];
        }
        return ['status' => \erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'render' => [self::class, 'validate'], 'render_args' => [$params]];
    }

    public static function validate($params)
    {
        $chat = $params['chat'];
        $vars = $chat->chat_variables_array;
        $source = $vars['sd_order_source'] ?? '';
        $value = in_array($source, ['wb', 'screenshot'], true) ? self::ownedFile($chat, $params['payload']) : self::normalize($source, $params['payload']);
        if ($value !== null) {
            self::save($chat, ['source' => $source, 'number' => $value,
                'check' => in_array($source, ['wb', 'screenshot'], true) ? 'attachment_for_operator_review' : 'format_only']);
            $vars = $chat->chat_variables_array;
            $vars['sd_order_attempts'] = '0';
            $vars['sd_order_pending'] = '';
            $chat->chat_variables_array = $vars;
            $chat->chat_variables = json_encode($vars);
            $chat->updateThis(['update' => ['chat_variables']]);
            return ['valid' => true];
        }
        $attempt = min(3, 1 + (int)($vars['sd_order_attempts'] ?? 0));
        $vars['sd_order_attempts'] = (string)$attempt;
        $chat->chat_variables_array = $vars;
        $chat->chat_variables = json_encode($vars);
        $chat->updateThis(['update' => ['chat_variables']]);
        if ($attempt >= 3) {
            throw new \erLhcoreClassGenericBotException('Не удалось получить данные покупки.', (int)($params['event_data']['content']['event_args']['exhausted_trigger'] ?? 0));
        }
        $hint = in_array($source, ['wb', 'screenshot'], true)
            ? '📷 Прикрепите скриншот покупки: товар и продавец СМАРТДИАГ. QR-код получения, данные карты и паспорт не нужны.'
            : '🔎 Формат не подошёл. Скопируйте номер целиком из заказа; серийный номер прибора и код получения — другие данные.';
        if ($source === 'serial') $hint = '🔢 Нужны ровно 12 цифр серийного номера с корпуса прибора, купленного у СМАРТДИАГ.';
        return ['valid' => false, 'message' => $hint . "\n\nОсталось попыток: " . (3 - $attempt) . '. Можно нажать «Вернуться к ассистенту».'];
    }

    public static function replaceContext($params)
    {
        $marker = '{smartdiag.order_context}';
        if (!isset($params['msg']) || strpos($params['msg'], $marker) === false) return;
        $p = (int)$params['chat']->gbot_id === 121 ? self::purchase($params['chat']) : null;
        $text = '';
        if ($p) {
            $text = in_array($p['source'], ['wb', 'screenshot'], true)
                ? "\n\n📷 Скриншот покупки получен. Специалист посмотрит его в переписке."
                : "\n\n📦 " . ($p['source'] === 'serial' ? 'Серийный номер' : 'Номер заказа') . ': ' . $p['number'] . ' · ' . self::LABELS[$p['source']] . '.';
        }
        $params['msg'] = str_replace($marker, $text, $params['msg']);
    }
}
