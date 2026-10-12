<?php

#[\AllowDynamicProperties]
class erLhcoreClassExtensionLhctelegram
{

    public function __construct()
    {
        $this->registerAutoload();
    }

    public function run()
    {
        $this->registerAutoload();

        $dispatcher = erLhcoreClassChatEventDispatcher::getInstance();

        $dispatcher->listen('instance.extensions_structure', array(
            $this,
            'checkStructure'
        ));

        $dispatcher->listen('instance.registered.created', array(
            $this,
            'instanceCreated'
        ));

        $dispatcher->listen('chat.incoming_dynamic_array', array(
            $this,'incomingChatDynamicArray')
        );

        $dispatcher->listen('chat.webhook_incoming_chat_started', array(
            $this,'incommingChatStarted')
        );

        // Operators handling chats from their telegram account
        $settings = $this->settings;
        if (!isset($settings['disable_op_flow']) || $settings['disable_op_flow'] !== true) {
            if (!class_exists('\LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator')) {
                $operatorFile = dirname(__DIR__) . '/providers/TelegramLiveHelperChatOperator.php';
                if (file_exists($operatorFile)) {
                    require_once $operatorFile;
                }
            }
            \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::registerListeners($dispatcher);
        }
    }

    /*
     * erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.webhook_incoming_chat_started', array(
            'webhook' => & $incomingWebhook,
            'data' => & $payloadAll,
            'chat' => & $chat
        ));*/
    public static function incommingChatStarted($params)
    {
        if ($params['webhook']->scope == 'telegram') {

            $telegramBot = null;

            if (isset($_GET['telegram_bot_id'])) {
                $telegramBot = erLhcoreClassModelTelegramBot::fetch((int)$_GET['telegram_bot_id']);
            }

            if (!is_object($telegramBot) && isset($params['chat']->chat_variables_array['iwh_field_2']) && $params['chat']->chat_variables_array['iwh_field_2'] != '') {
                $telegramBot = erLhcoreClassModelTelegramBot::fetch($params['chat']->chat_variables_array['iwh_field_2']);
            }

            if (is_object($telegramBot)) {
                $params['chat']->dep_id = $telegramBot->dep_id;
                $params['chat']->updateThis(['update' => ['dep_id']]);

                $chatId = null;
                $messageData = [];
                if (isset($params['data']['message']['chat']['id'])) {
                    $chatId = $params['data']['message']['chat']['id'];
                    $messageData = $params['data']['message']['from'];
                } elseif (isset($params['data']['callback_query']['message']['chat']['id'])) {
                    $chatId = $params['data']['callback_query']['message']['chat']['id'];
                    $messageData = $params['data']['callback_query']['from'];
                }

                if (is_numeric($chatId)){
                    $settings = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhctelegram')->settings;
                    if (!isset($settings['disable_leads']) || $settings['disable_leads'] !== true) {
                        $lead = \erLhcoreClassModelTelegramLead::findOne(array('filter' => array('tchat_id' => $chatId)));
                        if (!($lead instanceof \erLhcoreClassModelTelegramLead)) {
                            $lead = new \erLhcoreClassModelTelegramLead();
                            $lead->language_code = isset($messageData['language_code']) ? $messageData['language_code'] : '';
                            $lead->first_name = isset($messageData['first_name']) ? $messageData['first_name'] : '';
                            $lead->last_name = isset($messageData['last_name']) ? $messageData['last_name'] : '';
                            $lead->utime = time();
                            $lead->ctime = time();
                            $lead->tchat_id = $chatId;
                            $lead->tbot_id = $telegramBot->id;
                            $lead->dep_id = $telegramBot->dep_id;
                            $lead->username = isset($messageData['username']) ? $messageData['username'] : '';
                            $lead->saveThis();
                        }
                    }
                }
            }
        }
    }

    
    /*
     * erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.incoming_dynamic_array', array('incoming_chat' => $this, 'dynamic_array' => & $chat_dynamic_array));
    */
    public function incomingChatDynamicArray($params)
    {
        /*
             {{args.chat.incoming_chat.incoming.attributes.bot_username}}
             {{args.chat.incoming_chat.incoming_dynamic_array.bot_username}}
             {{args.chat.incoming_chat.incoming.attributes.access_token}}
             {{args.chat.incoming_chat.incoming_dynamic_array.access_token}}
        */
        if ($params['incoming_chat']->incoming->scope == 'telegram')
        {
            $telegramBot = null;

            if (isset($_GET['telegram_bot_id'])) {
                $telegramBot = erLhcoreClassModelTelegramBot::fetch((int)$_GET['telegram_bot_id']);
            }

            if (!is_object($telegramBot) && isset($params['incoming_chat']->chat->chat_variables_array['iwh_field_2']) && $params['incoming_chat']->chat->chat_variables_array['iwh_field_2'] != '') {
                $telegramBot = erLhcoreClassModelTelegramBot::fetch($params['incoming_chat']->chat->chat_variables_array['iwh_field_2']);
            }

            if (is_object($telegramBot)) {
                $params['dynamic_array']['access_token'] = $telegramBot->bot_api;
                $params['dynamic_array']['bot_username'] = $telegramBot->bot_username;
            }

            if (!isset($params['dynamic_array']['access_token'])) {
                $params['dynamic_array']['access_token'] = $params['incoming_chat']->incoming->attributes['access_token'];
                $params['dynamic_array']['bot_username'] = $params['incoming_chat']->incoming->attributes['bot_username'];
            }
        }
    }

    /**
     * Checks automated hosting structure
     *
     * This part is executed once in manager is run this cronjob.
     * php cron.php -s site_admin -e instance -c cron/extensions_update
     *
     * */
    public function checkStructure()
    {
        erLhcoreClassUpdate::doTablesUpdate(json_decode(file_get_contents('extension/lhctelegram/doc/structure.json'), true));
    }

    /**
     * Used only in automated hosting enviroment
     */
    public function instanceCreated($params)
    {
        try {
            // Just do table updates
            erLhcoreClassUpdate::doTablesUpdate(json_decode(file_get_contents('extension/lhctelegram/doc/structure.json'), true));
        } catch (Exception $e) {
            erLhcoreClassLog::write(print_r($e, true));
        }
    }

    public function registerAutoload()
    {
        spl_autoload_register(array(
            $this,
            'autoload'
        ), true, false);
    }

    public function autoload($className)
    {
        $classesArray = array(
            'erLhcoreClassModelTelegramBot' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegrambot.php',
            'erLhcoreClassModelTelegramBotDep' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegrambotdep.php',
            'erLhcoreClassModelTelegramOperator' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegramoperator.php',
            'erLhcoreClassModelTelegramChat' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegramchat.php',
            'erLhcoreClassModelTelegramLead' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegramlead.php',
            'erLhcoreClassTelegramValidator' => 'extension/lhctelegram/classes/erlhcoreclasstelegramvalidator.php'
        );

        if (key_exists($className, $classesArray)) {
            include_once $classesArray [$className];
            return;
        }

        if (strpos($className, "LiveHelperChatExtension\\lhctelegram\\") === 0) {
            $relPath = str_replace("\\", "/", substr($className, strlen("LiveHelperChatExtension\\lhctelegram\\"))) . ".php";
            $file = dirname(__DIR__) . "/" . $relPath;
            if (file_exists($file)) {
                include_once $file;
            }
        }
    }

    public static function getSession()
    {
        if (!isset (self::$persistentSession)) {
            self::$persistentSession = new ezcPersistentSession (ezcDbInstance::get(), new ezcPersistentCodeManager ('./extension/lhctelegram/pos'));
        }
        return self::$persistentSession;
    }

    public function __get($var)
    {
        switch ($var) {
            case 'is_active' :
                return true;;
                break;

            case 'settings' :
                $this->settings = include('extension/lhctelegram/settings/settings.ini.php');
                return $this->settings;
                break;

            default :
                ;
                break;
        }
    }

    public function setBot($tbot)
    {
        $this->tbot = $tbot;
    }

    public function getBot()
    {
        return $this->tbot;
    }

    private static $persistentSession;

    private $tbot = null;

    private $configData = false;

    // --- Helper methods for Telegram quotes, replies and parsing ---

    public static function stripTelegramFileEmbedsText($text)
    {
        return trim(preg_replace('/\[file=\d+_[a-f0-9]{32}\]/i', '', (string)$text));
    }

    public static function escapeTelegramHtmlText($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    public static function getTelegramRawMessageData($message)
    {
        if (is_array($message)) {
            return $message;
        }

        if (!is_object($message)) {
            return array();
        }

        if (isset($message->raw_data) && is_array($message->raw_data)) {
            return $message->raw_data;
        }

        if (method_exists($message, 'getRawData')) {
            try {
                $raw = $message->getRawData();
                return is_array($raw) ? $raw : array();
            } catch (\Throwable $e) {
                return array();
            }
        }

        return array();
    }

    public static function getTelegramEntityProperty($entity, $property)
    {
        if (!is_object($entity)) {
            return null;
        }

        if (method_exists($entity, 'getProperty')) {
            try {
                return $entity->getProperty($property);
            } catch (\Throwable $e) {
                // Fall through to the dynamic getter below.
            }
        }

        try {
            $getter = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $property)));
            return $entity->$getter();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getTelegramQuoteText($quote)
    {
        if (is_array($quote)) {
            return trim((string)($quote['text'] ?? ''));
        }

        if (is_object($quote)) {
            $text = self::getTelegramEntityProperty($quote, 'text');
            if ($text !== null) {
                return trim((string)$text);
            }

            if (isset($quote->raw_data) && is_array($quote->raw_data)) {
                return trim((string)($quote->raw_data['text'] ?? ''));
            }
        }

        return trim((string)$quote);
    }

    /**
     * Normalize reply/quote information without relying on Quote or ReplyParameters
     * classes that are absent in the installed telegram-core 79e5e3a.
     *
     * @return array{message_id:int,thread_id:int,reply_message_id:int,is_explicit_reply:bool,quote_text:string}
     */
    public static function extractTelegramReplyData($message)
    {
        $raw = self::getTelegramRawMessageData($message);
        $replyRaw = isset($raw['reply_to_message']) && is_array($raw['reply_to_message']) ? $raw['reply_to_message'] : array();

        $messageId = (int)($raw['message_id'] ?? self::getTelegramEntityProperty($message, 'message_id'));
        $threadId = (int)($raw['message_thread_id'] ?? self::getTelegramEntityProperty($message, 'message_thread_id'));
        $replyMessageId = (int)($replyRaw['message_id'] ?? 0);

        $replyObject = self::getTelegramEntityProperty($message, 'reply_to_message');
        if ($replyMessageId <= 0 && is_object($replyObject)) {
            $replyMessageId = (int)self::getTelegramEntityProperty($replyObject, 'message_id');
        }

        $quote = $raw['quote'] ?? null;
        if ($quote === null && isset($replyRaw['quote'])) {
            $quote = $replyRaw['quote'];
        }
        if ($quote === null && is_object($replyObject)) {
            $quote = self::getTelegramEntityProperty($replyObject, 'quote');
        } elseif ($quote === null && is_array($replyObject) && isset($replyObject['quote'])) {
            $quote = $replyObject['quote'];
        }

        $quoteObject = self::getTelegramEntityProperty($message, 'quote');
        $quoteText = self::getTelegramQuoteText($quoteObject);
        if ($quoteText === '') {
            $quoteText = self::getTelegramQuoteText($quote);
        }

        $forumTopicCreated = isset($raw['forum_topic_created']) || isset($replyRaw['forum_topic_created']);
        if (!$forumTopicCreated && is_object($replyObject)) {
            $forumTopicCreated = (bool)self::getTelegramEntityProperty($replyObject, 'forum_topic_created');
        }

        return array(
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'reply_message_id' => $replyMessageId,
            'is_explicit_reply' => $replyMessageId > 0 && !$forumTopicCreated,
            'quote_text' => $quoteText
        );
    }

    /**
     * Build the local reply reference used by the REST action.
     * An empty external ID must never reach the core reply renderer.
     */
    public static function buildTelegramReplyReference($dbMessageId, $telegramMessageId, $externalId = '')
    {
        $reference = array(
            'db_msg_id' => (int)$dbMessageId,
            'telegram_message_id' => (int)$telegramMessageId
        );
        $externalId = trim((string)$externalId);
        if ($externalId !== '') {
            $reference['iwh_msg_id'] = $externalId;
        }
        return $reference;
    }

    /**
     * Use the numeric marker only when the REST action can resolve an external
     * Telegram reply target. Local-only quotes use the regular display marker
     * without an ID, so the core never renders an empty reply block.
     */
    public static function formatTelegramQuotedText($messageText, $dbMessageId, $quoteText, $externalId = '')
    {
        $quoteText = self::normalizeTelegramQuoteText($quoteText);
        if (trim((string)$externalId) === '') {
            return $quoteText !== ''
                ? '[quote]' . $quoteText . '[/quote]' . (string)$messageText
                : (string)$messageText;
        }
        return '[quote=' . (int)$dbMessageId . ']' . (string)$quoteText . '[/quote]' . (string)$messageText;
    }

    /**
     * Keep quoted Telegram text from injecting nested LHC quote markers.
     * The outer marker is generated by this extension and remains intact.
     */
    public static function normalizeTelegramQuoteText($quoteText)
    {
        return trim((string)preg_replace('/\[\/?quote(?:=[^\]]*)?\]/i', '', (string)$quoteText));
    }

    /**
     * Ensure an incoming forum update belongs to the configured Telegram group.
     * Message/thread IDs are scoped to a chat and can otherwise collide.
     */
    public static function isTelegramForumChatMessage($tchat, $chatId)
    {
        if (!is_object($tchat) || !is_object($tchat->bot)) {
            return false;
        }

        $groupChatId = $tchat->bot->group_chat_id ?? null;
        return is_numeric($groupChatId) && is_numeric($chatId)
            && (int)$groupChatId === (int)$chatId;
    }

    /**
     * Return a JSON-path-safe namespace for one Telegram bot/group destination.
     * Telegram message IDs are only unique inside a destination chat.
     */
    public static function getTelegramTopicNamespace($botId, $groupChatId)
    {
        $botValue = preg_replace('/\D+/', '', (string)$botId);
        $groupValue = trim((string)$groupChatId);
        $groupSign = strpos($groupValue, '-') === 0 ? 'n' : 'p';
        $groupDigits = preg_replace('/\D+/', '', $groupValue);

        return 'bot_' . ($botValue !== '' ? $botValue : '0')
            . '_chat_' . $groupSign . '_' . ($groupDigits !== '' ? $groupDigits : '0');
    }

    public static function getTelegramTopicNamespaceFromContext($topicContext)
    {
        if (is_string($topicContext) && preg_match('/^bot_[0-9]+_chat_[np]_[0-9]+$/', $topicContext)) {
            return $topicContext;
        }

        if (!is_array($topicContext)) {
            return '';
        }

        if (isset($topicContext['namespace']) && preg_match('/^bot_[0-9]+_chat_[np]_[0-9]+$/', (string)$topicContext['namespace'])) {
            return (string)$topicContext['namespace'];
        }

        if (array_key_exists('bot_id', $topicContext) && array_key_exists('group_chat_id', $topicContext)) {
            return self::getTelegramTopicNamespace($topicContext['bot_id'], $topicContext['group_chat_id']);
        }

        return '';
    }

    public static function getTelegramTopicContextForChat($tchat)
    {
        if (!is_object($tchat) || !isset($tchat->bot_id) || !is_object($tchat->bot)) {
            return array();
        }

        return array(
            'bot_id' => (int)$tchat->bot_id,
            'group_chat_id' => (string)$tchat->bot->group_chat_id
        );
    }

    /**
     * Return the text/caption that was sent for a stored Telegram message.
     * This is used when Telegram omitted Message.quote (the normal case on core 79e5e3a).
     */
    public static function getStoredTelegramMessageText($msg, $topicMsgId = null, $topicContext = array())
    {
        if (!is_object($msg)) {
            return '';
        }

        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : array();
        $namespace = self::getTelegramTopicNamespaceFromContext($topicContext);
        if ($namespace !== '' && isset($meta['tg_topic_namespace']) && (string)$meta['tg_topic_namespace'] !== $namespace) {
            return '';
        }

        $key = $topicMsgId !== null ? (string)(int)$topicMsgId : '';
        if ($namespace !== '' && isset($meta['tg_topic_msg_contexts']) && is_array($meta['tg_topic_msg_contexts'])) {
            if (!array_key_exists($namespace, $meta['tg_topic_msg_contexts'])) {
                return '';
            }

            $context = is_array($meta['tg_topic_msg_contexts'][$namespace]) ? $meta['tg_topic_msg_contexts'][$namespace] : array();
            if ($key !== '' && isset($context['map'][$key]) && is_array($context['map'][$key])) {
                $entry = $context['map'][$key];
                $mappedText = self::normalizeStoredTelegramMessageText($entry['caption'] ?? ($entry['text'] ?? ''));
                if ($mappedText !== '') {
                    return $mappedText;
                }
            }

            // Older operator messages can contain a namespaced map entry with
            // a JSON null text value. The message row is still scoped to this
            // Telegram destination, so its body is a safe local fallback.
            return self::normalizeStoredTelegramMessageText($msg->msg);
        }

        if ($key !== '' && isset($meta['tg_topic_msg_map'][$key]) && is_array($meta['tg_topic_msg_map'][$key])) {
            $entry = $meta['tg_topic_msg_map'][$key];
            $mappedText = self::normalizeStoredTelegramMessageText($entry['caption'] ?? ($entry['text'] ?? ''));
            if ($mappedText !== '') {
                return $mappedText;
            }
        }

        return self::normalizeStoredTelegramMessageText($msg->msg);
    }

    public static function getTelegramFileCaption($msg, $chat, $file, $messageText = null)
    {
        $sender = $msg->name_support != '' ? '🤖 [' . $msg->name_support . ']' : '👤 [' . $chat->nick . ']';
        $messageText = $messageText === null ? $this->stripTelegramFileEmbeds($msg->msg) : trim((string)$messageText);

        if ($messageText !== '') {
            $caption = $sender . ': ' . $messageText;
        } elseif (strpos(strtolower((string)$file->type), 'image/') === 0) {
            $caption = $sender;
        } elseif ($this->isMeaningfulTelegramUploadName($file)) {
            $caption = $sender . ': ' . $file->upload_name;
        } else {
            $caption = $sender;
        }

        return htmlspecialchars(mb_substr($caption, 0, 900), ENT_QUOTES, 'UTF-8');
    }

    public static function normalizeStoredTelegramMessageText($text)
    {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\[file=\d+_[a-z0-9]+\]/i', '', $text));
    }

    public static function isMeaningfulTelegramUploadName($file)
    {
        $uploadName = trim((string)$file->upload_name);

        if ($uploadName === '') {
            return false;
        }

        if (mb_strlen($uploadName) <= 2 && strpos($uploadName, '.') === false) {
            return false;
        }

        return true;
    }



    // --- Backward-compatibility proxy methods for reflection / test suites ---

    public function sendTelegramChatFile(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::sendTelegramChatFile(...$args);
    }

    public function getTelegramMessageChunks(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::getTelegramMessageChunks(...$args);
    }

    public function getTelegramTextLength(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::getTelegramTextLength(...$args);
    }

    public function isTelegramTopicUnavailable(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::isTelegramTopicUnavailable(...$args);
    }

    public function shouldRetryTelegramWithoutReply(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::shouldRetryTelegramWithoutReply(...$args);
    }

    public function sendTelegramRequest(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::sendTelegramRequest(...$args);
    }

    public function getTelegramSendMessageIds(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::getTelegramSendMessageIds(...$args);
    }

    public function getTopicReplyId(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::getTopicReplyId(...$args);
    }

    public function saveTopicMsgId(...$args)
    {
        return \LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator::saveTopicMsgId(...$args);
    }
}
