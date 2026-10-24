<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/**
 * Command menu ("/" button) of the operators group, built from the LHC bot commands (Bot → Commands):
 * a command marked "Enabled for display" is shown as "/name — description", ordered by "Position".
 * "/name" in a topic then works as "!name" (classes/Commands/GenericCommand.php).
 *
 * The menu follows the LHC commands by itself: on a message in the operators group the menu is compared
 * with the one sent last time and re-sent to Telegram only when it changed.
 *
 * settings/settings.ini.php: 'topic_commands_bot_ids' => array(1) — operator bots that get the menu; empty = all.
 */
class TelegramTopicCommands
{
    public static function buildMenu()
    {
        $menu = array(array('command' => 'hint', 'description' => 'Подсказка ответа от ИИ (как «!?»)'));
        $seen = array('hint' => true, 'endchat' => true);

        foreach (\erLhcoreClassModelGenericBotCommand::getList(array('filter' => array('enabled_display' => 1), 'sort' => 'position ASC, id ASC', 'limit' => false)) as $command) {
            $name = strtolower(trim((string)$command->command));
            $description = trim((string)($command->info_msg != '' ? $command->info_msg : $command->name));
            // Telegram: command 1-32 chars a-z, 0-9, _; description 1-256 chars
            if (preg_match('/^[a-z0-9_]{1,32}$/', $name) && $description !== '' && !isset($seen[$name])) {
                $menu[] = array('command' => $name, 'description' => mb_substr($description, 0, 256));
                $seen[$name] = true;
            }
        }

        $menu[] = array('command' => 'endchat', 'description' => 'Закрыть чат');

        return $menu;
    }

    public static function isMenuBot($bot)
    {
        $settings = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhctelegram')->settings;
        $botIds = array_map('intval', (array)($settings['topic_commands_bot_ids'] ?? array()));

        return (int)$bot->bot_client === 1 && (int)$bot->group_chat_id !== 0 && (empty($botIds) || in_array((int)$bot->id, $botIds, true));
    }

    private static function stateFile($bot)
    {
        return 'cache/telegram_topic_commands_' . (int)$bot->id . '.md5';
    }

    // Sends the menu when it differs from the last one sent (or always with $force). Returns '' or an error.
    public static function sync($bot, $force = false)
    {
        if (!self::isMenuBot($bot)) {
            return '';
        }

        $menu = self::buildMenu();
        $hash = md5(json_encode(array($menu, (string)$bot->group_chat_id)));
        $stateFile = self::stateFile($bot);

        if ($force === false && is_file($stateFile) && trim((string)file_get_contents($stateFile)) === $hash) {
            return '';
        }

        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        $response = \Longman\TelegramBot\Request::setMyCommands(array(
            'commands' => $menu,
            'scope' => array('type' => 'chat', 'chat_id' => $bot->group_chat_id)
        ));

        if (!$response->isOk()) {
            return (string)$response->getDescription();
        }

        file_put_contents($stateFile, $hash);
        return '';
    }
}
