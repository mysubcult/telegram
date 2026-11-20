<?php

use LiveHelperChatExtension\lhctelegram\providers\TelegramOrderBridge as Bridge;
require __DIR__ . '/../providers/TelegramOrderBridge.php';

function expect(bool $result, string $case) {
    if (!$result) throw new RuntimeException($case);
}
$actor = ['id' => 123];
$card = ['chat' => ['id' => -100], 'message_thread_id' => 4, 'message_id' => 42];
$cb = ['id' => 'q', 'data' => 'ord:r:act:SD-20261120-ABCDEF', 'message' => $card, 'from' => $actor];
expect(Bridge::identify(['callback_query' => $cb], 11, 'fixture_bot') === ['command' => false, 'chat' => -100, 'actor' => 123, 'callback' => 'q'], 'order button');
$cb['data'] = 'take_over_chat||123';
expect(Bridge::identify(['callback_query' => $cb], 11, 'fixture_bot') === null, 'LHC callback');
$message = $card + ['from' => $actor, 'text' => 'reply', 'reply_to_message' => ['from' => ['id' => 11], 'text' => 'Заказ СМАРТДИАГ · SD-20261120-ABCDEF']];
expect(Bridge::identify(['message' => $message], 11, 'fixture_bot')['actor'] === 123, 'order reply');
expect(Bridge::identify(['message' => $message], 12, 'another_bot') === null, 'other bot');
$message['reply_to_message']['text'] = 'Вопрос покупателя';
expect(Bridge::identify(['message' => $message], 11, 'fixture_bot') === null, 'other conversation');
expect(Bridge::identify(['message' => ['text' => '/orders 2', 'from' => $actor, 'chat' => ['id' => -100]]], 11, 'fixture_bot')['command'], 'order list');
expect(Bridge::identify(['message' => ['text' => '/fix checking', 'from' => $actor, 'chat' => ['id' => -100]]], 11, 'fixture_bot') === null, 'existing operator command');
expect(Bridge::identify(['message' => ['text' => '/orders@fixture_bot 2', 'from' => $actor, 'chat' => ['id' => -100]]], 11,'fixture_bot')['command'], 'command mentioning this bot');
expect(Bridge::identify(['message' => ['text' => '/orders@other_bot', 'from' => $actor, 'chat' => ['id' => -100]]], 11,'fixture_bot') === null, 'command for a different bot');
echo "PASS Telegram order bridge routing\n";
