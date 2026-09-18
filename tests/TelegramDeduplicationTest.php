<?php

namespace LiveHelperChatExtension\lhctelegram\tests;

require_once __DIR__ . '/../../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../../ezcomponents/Base/src/base.php';
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
\erLhcoreClassSystem::init();
\ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');

require_once __DIR__ . '/../bootstrap/bootstrap.php';
require_once __DIR__ . '/../classes/erlhcoreclassmodeltelegrambot.php';
require_once __DIR__ . '/../classes/erlhcoreclassmodeltelegramchat.php';
require_once __DIR__ . '/../providers/TelegramLiveHelperChatOperator.php';

use LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator;

class TelegramDeduplicationTest
{
    public static function run()
    {
        echo "Running TelegramDeduplicationTest...\n";

        self::testLocking();
        self::testIsTopicMessageAlreadySent();
        self::testOperatorResetOfflineNotified();
        self::testTrigger1791Logic();

        echo "All TelegramDeduplicationTest passed successfully! [OK]\n";
    }

    private static function testLocking()
    {
        $namespace = 'bot_1_chat_test';
        $msgId = 99999991;

        $locked1 = TelegramLiveHelperChatOperator::acquireTelegramMessageLock($msgId, $namespace, 2);
        if (!$locked1) {
            throw new \RuntimeException("Failed to acquire lock for message {$msgId}");
        }

        TelegramLiveHelperChatOperator::releaseTelegramMessageLock($msgId, $namespace);
        echo "  [PASS] acquireTelegramMessageLock & releaseTelegramMessageLock\n";
    }

    private static function testIsTopicMessageAlreadySent()
    {
        $topicContext = array(
            'bot_id' => 1,
            'group_chat_id' => '-1001946886605'
        );

        $unsentMsg = new \erLhcoreClassModelmsg();
        $unsentMsg->id = 99999992;
        $unsentMsg->meta_msg = '';
        $unsentMsg->meta_msg_array = array();

        if (TelegramLiveHelperChatOperator::isTopicMessageAlreadySent($unsentMsg, $topicContext) !== false) {
            throw new \RuntimeException("Unsent message should return false");
        }

        $sentMsg = new \erLhcoreClassModelmsg();
        $sentMsg->id = 99999993;
        $sentMsg->meta_msg_array = array(
            'tg_topic_msg_id' => 183545,
            'tg_topic_msg_ids' => array(183545),
            'tg_topic_msg_contexts' => array(
                'bot_1_chat_n_1001946886605' => array(
                    'ids' => array(183545),
                    'latest_id' => 183545
                )
            )
        );
        $sentMsg->meta_msg = json_encode($sentMsg->meta_msg_array);

        if (TelegramLiveHelperChatOperator::isTopicMessageAlreadySent($sentMsg, $topicContext) !== true) {
            throw new \RuntimeException("Sent message should return true");
        }

        echo "  [PASS] isTopicMessageAlreadySent\n";
    }

    private static function testOperatorResetOfflineNotified()
    {
        $chat = new \erLhcoreClassModelChat();
        $chat->id = 24889;
        $chatVariables = array('specialist_offline_notified' => '1', 'other_var' => '123');
        $chat->chat_variables = json_encode($chatVariables);
        $chat->chat_variables_array = $chatVariables;

        $opMsg = new \erLhcoreClassModelmsg();
        $opMsg->user_id = 1;

        TelegramLiveHelperChatOperator::messageAddedAdmin(array(
            'chat' => $chat,
            'msg' => $opMsg,
            'lhc_caller' => array('class' => 'Longman\\TelegramBot\\Commands\\SystemCommands\\GenericmessageCommand')
        ));

        $freshVars = $chat->chat_variables_array;
        if (isset($freshVars['specialist_offline_notified'])) {
            throw new \RuntimeException("specialist_offline_notified should be unset after operator message");
        }
        if (!isset($freshVars['other_var']) || $freshVars['other_var'] !== '123') {
            throw new \RuntimeException("other variables should be preserved");
        }

        echo "  [PASS] testOperatorResetOfflineNotified\n";
    }

    private static function testTrigger1791Logic()
    {
        $chat = \erLhcoreClassModelChat::fetch(24889);
        $trigger = \erLhcoreClassModelGenericBotTrigger::fetch(1791);
        $action = json_decode($trigger->actions, true)[0];

        // 1. Operator active (sent message 45 seconds ago): trigger should NOT match
        $chat->chat_variables_array = array();
        $chat->last_op_msg_time = time() - 45;
        $resActive = \erLhcoreClassGenericBotActionConditions::process($chat, $action, $trigger, array('chat' => $chat));
        if ($resActive !== null) {
            throw new \RuntimeException("Trigger 1791 should NOT fire when operator spoke 45 seconds ago! Got: " . json_encode($resActive));
        }

        // 2. Operator inactive (sent message 40 minutes ago): trigger should match
        $chat->chat_variables_array = array();
        $chat->last_op_msg_time = time() - 2400;
        $resInactive = \erLhcoreClassGenericBotActionConditions::process($chat, $action, $trigger, array('chat' => $chat));
        if (!is_array($resInactive) || !isset($resInactive['trigger_id']) || $resInactive['trigger_id'] != 1792) {
            throw new \RuntimeException("Trigger 1791 SHOULD fire when operator inactive for 40 min! Got: " . json_encode($resInactive));
        }

        echo "  [PASS] testTrigger1791Logic (active operator blocked, inactive operator permitted)\n";
    }
}

TelegramDeduplicationTest::run();
