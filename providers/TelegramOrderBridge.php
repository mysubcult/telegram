<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/** A narrow branch of the existing, secret-token protected operator webhook. */
class TelegramOrderBridge
{
    private const ENDPOINT = 'http://127.0.0.1:3000/api/telegram/orders';
    private const PROMPT_PREFIX = 'Заказ СМАРТДИАГ · ';

    /** Null leaves the update to LHC's normal Longman handlers. */
    public static function identify(array $update, int $botId, string $username): ?array
    {
        $callback = $update['callback_query'] ?? [];
        $isCallback = is_string($callback['data'] ?? null) && str_starts_with($callback['data'], 'ord:');
        $message = $isCallback ? ($callback['message'] ?? []) : ($update['message'] ?? []);
        $command = !$isCallback && is_string($message['text'] ?? null)
            && preg_match('~^/orders?(?:@([A-Za-z0-9_]+))?(?:\s|$)~D', $message['text'], $match) === 1
            && (empty($match[1]) || strcasecmp($match[1], $username) === 0);
        if (!$isCallback && !$command) {
            $parent = $message['reply_to_message'] ?? [];
            if (($parent['from']['id'] ?? null) !== $botId ||
                !is_string($parent['text'] ?? null) ||
                !str_starts_with($parent['text'], self::PROMPT_PREFIX)) {
                return null;
            }
        }
        return [
            'command' => $command,
            'chat' => $message['chat']['id'] ?? null,
            'actor' => ($isCallback ? $callback : $message)['from']['id'] ?? null,
            'callback' => $isCallback ? ($callback['id'] ?? null) : null,
        ];
    }

    public static function handle($bot): bool
    {
        if ((int)$bot->id !== 1 || (int)$bot->bot_client !== 1) {
            return false;
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (!is_string($raw) || strlen($raw) > 65536) {
            return false;
        }
        $update = json_decode($raw, true, 64);
        $botId = (int)explode(':', $bot->bot_api, 2)[0];
        $source = is_array($update) ? self::identify($update, $botId, (string)$bot->bot_username) : null;
        if ($source === null) {
            return false;
        }
        // Consume even rejected order replies so they can never reach the chat/mail handlers.
        $registered = is_int($source['actor']) && $source['actor'] > 0
            && (int)$bot->webhook_set === 2 && $bot->webhook_secret !== ''
            && is_int($source['chat']) && (string)$source['chat'] === (string)$bot->group_chat_id
            && hash_equals($bot->webhook_secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
        $operator = $registered ? \erLhcoreClassModelTelegramOperator::findOne(['filter' => [
            'bot_id' => $bot->id, 'confirmed' => 1, 'tuser_id' => $source['actor']
        ]]) : null;
        if (!$operator || !is_object($operator->user) || (int)$operator->user->disabled !== 0) {
            if ($source['command']) {
                return false;
            }
            if (is_string($source['callback'])) {
                header('Content-Type: application/json');
                echo json_encode(['method' => 'answerCallbackQuery', 'callback_query_id' => $source['callback'],
                    'text' => 'Нет доступа к управлению заказом.']);
            }
            return true;
        }
        $ts = (string)time();
        $sig = hash_hmac('sha256', "order-bridge-v1\n" . $ts . "\n" . $raw, $bot->bot_api);
        try {
            $response = (new \GuzzleHttp\Client())->post(self::ENDPOINT, [
                'headers' => ['Content-Type' => 'application/json', 'X-Order-Time' => $ts, 'X-Order-Signature' => $sig],
                'body' => $raw, 'http_errors' => false, 'allow_redirects' => false, 'proxy' => '',
                'connect_timeout' => 2, 'timeout' => 45,
            ]);
            if ($source['command'] && $response->getStatusCode() === 204) {
                return false;
            }
            if ($response->getStatusCode() !== 200) {
                http_response_code(503);
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $error) {
            http_response_code(503);
        }
        return true;
    }
}
