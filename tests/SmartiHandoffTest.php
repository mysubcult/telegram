<?php

require_once __DIR__.'/../providers/SmartiHandoff.php';

use LiveHelperChatExtension\lhctelegram\providers\SmartiHandoff;

function handoffNotification($function, $summary, $task = '', $extra = [])
{
    $vars = [
        'sd_summary_ready' => '1',
        'ai_function_name' => $function,
        'ai_action_args' => ['summary' => $summary, 'task' => $task],
    ];
    $chat = (object)['gbot_id' => 121, 'chat_variables_array' => array_merge($vars, $extra)];
    $msg = 'Уведомление.' . SmartiHandoff::MARKER;
    SmartiHandoff::replaceSummary(['chat' => $chat, 'msg' => &$msg]);
    return $msg;
}

function checkHandoff($result, $message)
{
    if (!$result) {
        throw new RuntimeException($message);
    }
}

$safe = 'Вы сообщили о неисправности.';
foreach ([
    'Пароль: synthetic-1 и synthetic-2.',
    'Для входа указан логин и пароль: synthetic-1 / synthetic-2.',
    'Название <b>пароля</b> и значение: synthetic-1. synthetic-2.',
    "access_token:\nsynthetic-1\nsynthetic-2",
    'API key = synthetic-1. synthetic-2',
    'Система показала [данные скрыты] [данные скрыты].',
] as $sensitive) {
    $text = handoffNotification('call_service', "$safe $sensitive");
    checkHandoff(strpos($text, $safe) !== false, 'Safe service sentence lost');
    foreach (['synthetic', 'данные скрыты', 'тестовый доступ', '<b>', '{'] as $unexpected) {
        checkHandoff(strpos($text, $unexpected) === false, 'Unsafe or unrelated text: ' . $unexpected);
    }
}

$summary = 'Вы подтвердили подготовку прибора. Осталось выдать логин и пароль тестового профиля.';
$text = handoffNotification('handoff_serial_operator', $summary, 'test_access');
checkHandoff(strpos($text, 'Вы подтвердили подготовку прибора. Для проверки прибора нужен тестовый доступ по EOBD.') !== false, 'EOBD context lost');
checkHandoff(strpos($text, 'логин') === false && strpos($text, 'пароль') === false, 'Credential labels left in notification');
$manual = handoffNotification('handoff_serial_operator', $summary, 'test_access', ['sd_activation_task' => 'activation_after_payment']);
checkHandoff(strpos($manual, 'тестовый доступ') === false, 'Button task did not take precedence');
$text = handoffNotification('handoff_serial_operator', 'Вы запросили тестовый доступ. Логин и пароль: synthetic.', 'test_access');
checkHandoff(substr_count($text, 'тестовый доступ') === 1, 'Repeated EOBD statement');

checkHandoff(handoffNotification('unknown', $safe) === 'Уведомление.', 'Unknown function exposed a summary');
checkHandoff(handoffNotification('call_service', '') === 'Уведомление.', 'Empty summary produced a block');
checkHandoff(handoffNotification('call_operator', 'Login: synthetic') === 'Уведомление.', 'All-sensitive summary must disappear');
checkHandoff(handoffNotification('call_sales', $safe, '', ['sd_summary_ready' => '0']) === 'Уведомление.', 'Stale summary shown');
$clean = SmartiHandoff::cleanSummary('<b>Готово.</b> https://example.invalid [file=10]private[/file] {служебное}');
checkHandoff($clean === 'Готово. служебное', 'Public markup/link filter failed');
echo "Smarti handoff summary checks passed\n";
