<?php
/**
 * Command menu ("/" button) of the operators group: /hint, the LHC bot commands ("!sbp" → "/sbp") and /endchat.
 * Run after adding or renaming commands in LHC (Bot → Commands):
 * php cron.php -s site_admin -e lhctelegram -c cron/sync_commands
 *
 * settings/settings.ini.php: 'topic_commands' => array('done', 'setup', 'sbp') — which LHC commands go to the menu
 * and in what order; empty = every LHC command that has a description.
 * 'topic_commands_bot_ids' => array(1) — operator bots that get the menu; empty = all of them.
 */

$settings = erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhctelegram')->settings;
$wanted = array_values(array_map('strtolower', (array)($settings['topic_commands'] ?? array())));
$botIds = array_map('intval', (array)($settings['topic_commands_bot_ids'] ?? array()));

$lhcCommands = array();
foreach (erLhcoreClassModelGenericBotCommand::getList(array('limit' => false)) as $command) {
    $name = strtolower(trim((string)$command->command));
    $description = trim((string)($command->info_msg != '' ? $command->info_msg : $command->name));
    // Telegram: 1-32 chars a-z, 0-9, _ ; description 1-256 chars
    if (preg_match('/^[a-z0-9_]{1,32}$/', $name) && $description !== '' && !isset($lhcCommands[$name])) {
        $lhcCommands[$name] = mb_substr($description, 0, 256);
    }
}

$menu = array(array('command' => 'hint', 'description' => 'Подсказка ответа от ИИ (как «!?»)'));

foreach (empty($wanted) ? array_keys($lhcCommands) : $wanted as $name) {
    if (isset($lhcCommands[$name])) {
        $menu[] = array('command' => $name, 'description' => $lhcCommands[$name]);
    } else {
        echo "LHC command !{$name} not found, skipped\n";
    }
}

$menu[] = array('command' => 'endchat', 'description' => 'Закрыть чат');

foreach (erLhcoreClassModelTelegramBot::getList(array('filter' => array('bot_client' => 1))) as $bot) {
    if ((int)$bot->group_chat_id === 0 || (!empty($botIds) && !in_array((int)$bot->id, $botIds, true))) {
        continue;
    }

    new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
    $response = \Longman\TelegramBot\Request::setMyCommands(array(
        'commands' => $menu,
        'scope' => array('type' => 'chat', 'chat_id' => $bot->group_chat_id)
    ));

    echo '@' . $bot->bot_username . ': ' . ($response->isOk() ? 'menu set — ' . implode(' ', array_map(function ($item) { return '/' . $item['command']; }, $menu)) : 'failed: ' . $response->getDescription()) . "\n";
}

?>
