<?php
/**
 * Re-sends the command menu of the operators group right away (normally it follows the LHC commands by itself,
 * see providers/TelegramTopicCommands.php):
 * php cron.php -s site_admin -e lhctelegram -c cron/sync_commands
 */

use LiveHelperChatExtension\lhctelegram\providers\TelegramTopicCommands;

$menu = implode(' ', array_map(function ($item) { return '/' . $item['command']; }, TelegramTopicCommands::buildMenu()));

foreach (erLhcoreClassModelTelegramBot::getList(array('filter' => array('bot_client' => 1))) as $bot) {
    if (!TelegramTopicCommands::isMenuBot($bot)) {
        continue;
    }
    $error = TelegramTopicCommands::sync($bot, true);
    echo '@' . $bot->bot_username . ': ' . ($error === '' ? 'menu set — ' . $menu : 'failed: ' . $error) . "\n";
}

?>
