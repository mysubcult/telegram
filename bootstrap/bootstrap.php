<?php

#[\AllowDynamicProperties]
class erLhcoreClassExtensionLhctelegram
{
    private $lastTelegramSendData = null;
    private $lastTelegramSendResponses = array();

    public function __construct()
    {

    }

    public function run()
    {
        $this->registerAutoload();

        $dispatcher = erLhcoreClassChatEventDispatcher::getInstance();

        $dispatcher->listen('chat.delete', array(
            $this,
            'deleteChat'
        ));

        $dispatcher->listen('chat.close', array(
            $this,
            'closeChat'
        ));

        $dispatcher->listen('instance.extensions_structure', array(
            $this,
            'checkStructure'
        ));

        $dispatcher->listen('instance.registered.created', array(
            $this,
            'instanceCreated'
        ));

        $dispatcher->listen('telegram.get_signature', array(
            $this,
            'getSignature'
        ));

        $dispatcher->listen('chat.chat_started', array(
            $this,
            'chatStarted'
        ));

        $dispatcher->listen('chat.web_add_msg_admin', array(
            $this,
            'messageAddedAdmin'
        ));

        $dispatcher->listen('chat.before_auto_responder_msg_saved', array(
            $this,
            'messageAddedResponder'
        ));

        $dispatcher->listen('chat.addmsguser', array(
            $this,
            'messageAdded'
        ));

        $dispatcher->listen('chat.messages_added_passive', array(
            $this,
            'messageAdded'
        ));

        $dispatcher->listen('chat.genericbot_get_trigger_click_processed', array(
            $this,
            'triggerClicked'
        ));

        // Handle canned messages custom workflow
        $dispatcher->listen('chat.canned_msg_before_save', array(
                $this, 'cannedMessageValidate')
        );

        $dispatcher->listen('chat.before_newcannedmsg', array(
                $this, 'cannedMessageValidate')
        );

        $dispatcher->listen('chat.workflow.canned_message_replace', array(
                $this, 'cannedMessageReplace')
        );

        $dispatcher->listen('chat.incoming_dynamic_array', array(
            $this,'incomingChatDynamicArray')
        );

        $dispatcher->listen('chat.webhook_incoming_chat_started', array(
            $this,'incommingChatStarted')
        );

        $dispatcher->listen('onlineuser.pageview_logged', array(
            $this,'pageViewLogged')
        );
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
                } elseif (isset($params['data']['message']['chat']['id'])) {
                    $chatId = $params['data']['callback_query']['message']['chat']['id'];
                    $messageData = $params['data']['callback_query']['from'];
                }

                if (is_numeric($chatId)){
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

    public function cannedMessageReplace($params)
    {
        if (is_object($params['chat']->incoming_chat) && is_object($params['chat']->incoming_chat->incoming) && $params['chat']->incoming_chat->incoming->scope == 'telegram') {
            foreach ($params['items'] as & $item) {

                if ($params['chat']->locale != '' && $item->languages != '') {
                    // Override language by chat locale
                    $languages = json_decode($item->languages, true);

                    if (is_array($languages)) {
                        foreach ($languages as & $lang) {

                            if (isset($lang['message_lang_tel']) && !empty($lang['message_lang_tel'])) {
                                $lang['message'] = $lang['message_lang_tel'];
                            }

                            if (isset($lang['fallback_message_lang_tel']) && !empty($lang['fallback_message_lang_tel'])) {
                                $lang['fallback_msg'] = $lang['fallback_message_lang_tel'];
                            }
                        }
                    }

                    $item->languages = json_encode($languages);
                }

                $additionalData = $item->additional_data_array;

                if (isset($additionalData['message_tel']) && !empty($additionalData['message_tel'])) {
                    $item->msg = $additionalData['message_tel'];
                }

                if (isset($additionalData['fallback_tel']) && !empty($additionalData['fallback_tel'])) {
                    $item->fallback_msg = $additionalData['fallback_tel'];
                }
            }
        }
    }

    public function cannedMessageValidate($params)
    {
        $definition = array(
            'MessageExtTel' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw'),
            'FallbackMessageExtTel' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw'),

            'message_lang_tel' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw', null, FILTER_REQUIRE_ARRAY),
            'fallback_message_lang_tel' => new ezcInputFormDefinitionElement(ezcInputFormDefinitionElement::OPTIONAL, 'unsafe_raw', null, FILTER_REQUIRE_ARRAY)
        );

        $form = new ezcInputForm(INPUT_POST, $definition);

        $langArray = array();
        foreach ($params['msg']->languages_array as $index => $langData) {
            $langData['message_lang_tel'] = $form->message_lang_tel[$index];
            $langData['fallback_message_lang_tel'] = $form->fallback_message_lang_tel[$index];
            $langArray[] = $langData;
        }

        $params['msg']->languages = json_encode($langArray);
        $params['msg']->languages_array = $langArray;

        // Store additional data
        $additionalArray = $params['msg']->additional_data_array;

        if ($form->hasValidData('MessageExtTel')) {
            $additionalArray['message_tel'] = $form->MessageExtTel;
        }

        if ($form->hasValidData('FallbackMessageExtTel')) {
            $additionalArray['fallback_tel'] = $form->FallbackMessageExtTel;
        }

        $params['msg']->additional_data = json_encode($additionalArray);
        $params['msg']->additional_data_array = $additionalArray;
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

    public function messageAddedAdmin($params)
    {
        if (isset($params['lhc_caller']['class']) && $params['lhc_caller']['class'] == 'Longman\TelegramBot\Commands\SystemCommands\GenericmessageCommand' && (!isset($params['always_process']) || $params['always_process'] === false)) {
            return;
        }

        // We want to by pass resque worker messages from rest_api
        if (isset($params['source']) && $params['source'] == 'webhook' && (!isset($params['sub_source']) || $params['sub_source'] != 'rest_api_worker')) {
            return;
        }

        $this->messageAdded($params);
    }

    public function messageAddedResponder($params)
    {
        if (isset($params['source']) && $params['source'] == 'webhook') {
            return;
        }

        $params['no_afterwards_messages'] = true;

        $this->messageAdded($params);
    }

    public function pageViewLogged($params)
    {
        if (($params['ou']->id > 0 && $params['ou']->chat_id > 0) !== true) {
            return;
        }

        if (!isset($params['url_changed']) || $params['url_changed'] === false) {
            return;
        }

        foreach (erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['ou']->id > 0 ? ($params['ou']->id * -1) : $params['ou']->chat_id), 'type' => 1]]) as $tchat) {
            if ($tchat->bot->bot_client == 0 || $tchat->bot->notify_page_change == 0) {
                continue;
            }

            $chat = $params['ou']->chat;
            if (!is_object($chat)) {
                continue;
            }

            $telegram = new Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);

            $sendData = Longman\TelegramBot\Request::send('editForumTopic', [
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id,
                'name' => mb_substr('[' . $chat->department . '] ' . $chat->nick . ' #' . $chat->id . ($params['ou']->ip != '' ? ' | ' . $params['ou']->ip : '') . ($params['ou']->user_country_code != '' ? ' | ' . strtoupper($params['ou']->user_country_code) : '') . ($params['ou']->current_page != '' ? ' | '. ltrim($params['ou']->current_page,'/') : '') . ($params['ou']->page_title != '' ? ' | '.$params['ou']->page_title : ''),0,128)
            ]);

            if (!$sendData->isOk()) {
                erLhcoreClassLog::write('editForumTopic ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                    ezcLog::SUCCESS_AUDIT,
                    array(
                        'source' => 'lhc',
                        'category' => 'telegram_exception',
                        'line' => __LINE__,
                        'file' => __FILE__,
                        'object_id' => $params['ou']->id
                    )
                );
            }
        }
    }

    private function stripTelegramFileEmbeds($text)
    {
        return trim(preg_replace('/\[file=\d+_[a-z0-9]+\]/i', '', (string)$text));
    }

    private function getTelegramMessageFiles($msg)
    {
        $files = array();
        $seen = array();

        if (isset($msg->meta_msg_array['content']['attachements']) && is_array($msg->meta_msg_array['content']['attachements'])) {
            foreach ($msg->meta_msg_array['content']['attachements'] as $messageAttachment) {
                if (isset($messageAttachment['id']) && isset($messageAttachment['security_hash'])) {
                    $this->appendTelegramMessageFile($files, $seen, $messageAttachment['id'], $messageAttachment['security_hash']);
                }
            }
        }

        if (preg_match_all('/\[file=(\d+)_([a-f0-9]{32})\]/i', (string)$msg->msg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->appendTelegramMessageFile($files, $seen, $match[1], $match[2]);
            }
        }

        return $files;
    }

    private function appendTelegramMessageFile(& $files, & $seen, $id, $hash)
    {
        $id = (int)$id;
        $hash = (string)$hash;
        $key = $id . '_' . $hash;

        if (isset($seen[$key])) {
            return;
        }

        try {
            $file = erLhcoreClassModelChatFile::fetch($id);
        } catch (Exception $e) {
            return;
        }

        if (!($file instanceof erLhcoreClassModelChatFile) || strtolower($file->security_hash) !== strtolower($hash)) {
            return;
        }

        $seen[$key] = true;
        $files[] = array(
            'file' => $file,
            'embed' => '[file=' . $file->id . '_' . $file->security_hash . ']'
        );
    }

    /**
     * Return the raw Telegram message payload on old and new telegram-core releases.
     * telegram-core 79e5e3a keeps unknown fields (including Message.quote) in raw_data
     * and exposes them through Entity::__call(), but does not define a Quote entity.
     */
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

    private static function getTelegramEntityProperty($entity, $property)
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

    private static function getTelegramQuoteText($quote)
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

        $forumTopicCreated = isset($replyRaw['forum_topic_created']);
        if (!$forumTopicCreated && is_object($replyObject)) {
            $forumTopicCreated = (bool)self::getTelegramEntityProperty($replyObject, 'forum_topic_created');
        }

        return array(
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'reply_message_id' => $replyMessageId,
            'is_explicit_reply' => $replyMessageId > 0 && $replyMessageId !== $threadId && !$forumTopicCreated,
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

    private static function getTelegramTopicNamespaceFromContext($topicContext)
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

    private function getTelegramTopicContextForChat($tchat)
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

            return '';
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

    private function getTelegramFileCaption($msg, $chat, $file, $messageText = null)
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

    private static function normalizeStoredTelegramMessageText($text)
    {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\[file=\d+_[a-z0-9]+\]/i', '', $text));
    }

    private function isMeaningfulTelegramUploadName($file)
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

    public function saveTopicMsgId($msg, $topicMsgId, $messageData = array(), $topicContext = array())
    {
        if (!($msg instanceof erLhcoreClassModelmsg) || !(int)$topicMsgId || $msg->id <= 0) {
            return;
        }

        $db = ezcDbInstance::get();
        $startedTransaction = method_exists($db, 'inTransaction') && !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            // Lock while merging to preserve IDs written by concurrent workers.
            $select = $db->prepare('SELECT meta_msg FROM lh_msg WHERE id = :id FOR UPDATE');
            $select->bindValue(':id', (int)$msg->id, PDO::PARAM_INT);
            $select->execute();
            $row = $select->fetch(PDO::FETCH_ASSOC);

            $meta = array();
            if (is_array($row) && isset($row['meta_msg']) && $row['meta_msg'] !== '') {
                $decoded = json_decode($row['meta_msg'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            if (empty($meta) && is_array($msg->meta_msg_array)) {
                $meta = $msg->meta_msg_array;
            }

            $topicMsgIds = isset($meta['tg_topic_msg_ids']) && is_array($meta['tg_topic_msg_ids']) ? array_map('intval', $meta['tg_topic_msg_ids']) : array();
            $topicMsgIds[] = (int)$topicMsgId;
            $topicMsgIds = array_values(array_unique(array_filter($topicMsgIds, function ($id) { return (int)$id > 0; })));
            $meta['tg_topic_msg_ids'] = $topicMsgIds;
            $meta['tg_topic_msg_id'] = (int)$topicMsgId;

            $topicMap = isset($meta['tg_topic_msg_map']) && is_array($meta['tg_topic_msg_map']) ? $meta['tg_topic_msg_map'] : array();
            $entry = array();
            foreach (array('text', 'caption', 'embed', 'kind') as $key) {
                if (isset($messageData[$key]) && is_scalar($messageData[$key])) {
                    $entry[$key] = (string)$messageData[$key];
                }
            }
            if (isset($messageData['file_id']) && (int)$messageData['file_id'] > 0) {
                $entry['file_id'] = (int)$messageData['file_id'];
            }
            if (isset($messageData['security_hash']) && is_scalar($messageData['security_hash'])) {
                $entry['security_hash'] = (string)$messageData['security_hash'];
            }
            $mapKey = (string)(int)$topicMsgId;
            if (!isset($topicMap[$mapKey]) || !is_array($topicMap[$mapKey])) {
                $topicMap[$mapKey] = array();
            }
            if (!empty($entry)) {
                $topicMap[$mapKey] = array_merge($topicMap[$mapKey], $entry);
            }
            $meta['tg_topic_msg_map'] = $topicMap;

            $namespace = self::getTelegramTopicNamespaceFromContext($topicContext);
            if ($namespace !== '') {
                $contexts = isset($meta['tg_topic_msg_contexts']) && is_array($meta['tg_topic_msg_contexts']) ? $meta['tg_topic_msg_contexts'] : array();
                $context = isset($contexts[$namespace]) && is_array($contexts[$namespace]) ? $contexts[$namespace] : array();
                $contextIds = isset($context['ids']) && is_array($context['ids']) ? array_map('intval', $context['ids']) : array();
                $contextIds[] = (int)$topicMsgId;
                $context['ids'] = array_values(array_unique(array_filter($contextIds, function ($id) { return (int)$id > 0; })));
                $context['latest_id'] = (int)$topicMsgId;
                $context['bot_id'] = isset($topicContext['bot_id']) ? (int)$topicContext['bot_id'] : 0;
                $context['group_chat_id'] = isset($topicContext['group_chat_id']) ? (string)$topicContext['group_chat_id'] : '';
                $contextMap = isset($context['map']) && is_array($context['map']) ? $context['map'] : array();
                if (!isset($contextMap[$mapKey]) || !is_array($contextMap[$mapKey])) {
                    $contextMap[$mapKey] = array();
                }
                if (!empty($entry)) {
                    $contextMap[$mapKey] = array_merge($contextMap[$mapKey], $entry);
                }
                $context['map'] = $contextMap;
                $contexts[$namespace] = $context;
                $meta['tg_topic_msg_contexts'] = $contexts;
            }

            $msg->meta_msg_array = $meta;
            $msg->meta_msg = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $stmt = $db->prepare('UPDATE lh_msg SET meta_msg = :meta_msg WHERE id = :id');
            $stmt->bindValue(':meta_msg', $msg->meta_msg);
            $stmt->bindValue(':id', (int)$msg->id, PDO::PARAM_INT);
            $stmt->execute();

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function saveTelegramFileTopicMsgId($msg, $topicMsgId, $telegramFile, $caption = '', $topicContext = array())
    {
        if (!is_array($telegramFile) || !isset($telegramFile['file']) || !is_object($telegramFile['file'])) {
            $this->saveTopicMsgId($msg, $topicMsgId, array(), $topicContext);
            return;
        }

        $file = $telegramFile['file'];
        $this->saveTopicMsgId($msg, $topicMsgId, array(
            'file_id' => (int)$file->id,
            'security_hash' => (string)$file->security_hash,
            'embed' => (string)($telegramFile['embed'] ?? ''),
            'caption' => (string)$caption,
            'text' => (string)$caption,
            'kind' => (string)$file->type
        ), $topicContext);
    }

    private function getStoredTopicMessageId($msg, $preferredId = null, $topicContext = array())
    {
        if (!($msg instanceof erLhcoreClassModelmsg)) {
            return null;
        }

        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : array();
        $namespace = self::getTelegramTopicNamespaceFromContext($topicContext);
        if ($namespace !== '' && isset($meta['tg_topic_namespace']) && (string)$meta['tg_topic_namespace'] !== $namespace) {
            return null;
        }

        if ($namespace !== '' && isset($meta['tg_topic_msg_contexts']) && is_array($meta['tg_topic_msg_contexts'])) {
            if (!array_key_exists($namespace, $meta['tg_topic_msg_contexts'])) {
                return null;
            }

            $context = is_array($meta['tg_topic_msg_contexts'][$namespace]) ? $meta['tg_topic_msg_contexts'][$namespace] : array();
            $knownIds = array();
            if (isset($context['ids']) && is_array($context['ids'])) {
                foreach ($context['ids'] as $id) {
                    if ((int)$id > 0) {
                        $knownIds[(int)$id] = true;
                    }
                }
            }
            if (isset($context['map']) && is_array($context['map'])) {
                foreach (array_keys($context['map']) as $id) {
                    if ((int)$id > 0) {
                        $knownIds[(int)$id] = true;
                    }
                }
            }
            if (isset($context['latest_id']) && (int)$context['latest_id'] > 0) {
                $knownIds[(int)$context['latest_id']] = true;
            }

            if ($preferredId !== null && isset($knownIds[(int)$preferredId])) {
                return (int)$preferredId;
            }
            if (isset($context['latest_id']) && (int)$context['latest_id'] > 0) {
                return (int)$context['latest_id'];
            }
            if (!empty($knownIds)) {
                return (int)array_key_last($knownIds);
            }

            return null;
        }

        // A message that already has namespaced metadata must not fall back to
        // its legacy scalar ID for a different bot/group.
        if ($namespace !== '' && isset($meta['tg_topic_msg_contexts']) && is_array($meta['tg_topic_msg_contexts'])) {
            return null;
        }

        $knownIds = array();
        if (isset($meta['tg_topic_msg_ids']) && is_array($meta['tg_topic_msg_ids'])) {
            foreach ($meta['tg_topic_msg_ids'] as $id) {
                if ((int)$id > 0) {
                    $knownIds[(int)$id] = true;
                }
            }
        }
        if (isset($meta['tg_topic_msg_map']) && is_array($meta['tg_topic_msg_map'])) {
            foreach (array_keys($meta['tg_topic_msg_map']) as $id) {
                if ((int)$id > 0) {
                    $knownIds[(int)$id] = true;
                }
            }
        }
        if (isset($meta['tg_topic_msg_id']) && (int)$meta['tg_topic_msg_id'] > 0) {
            $knownIds[(int)$meta['tg_topic_msg_id']] = true;
        }

        if ($preferredId !== null && isset($knownIds[(int)$preferredId])) {
            return (int)$preferredId;
        }
        if (isset($meta['tg_topic_msg_id']) && (int)$meta['tg_topic_msg_id'] > 0) {
            return (int)$meta['tg_topic_msg_id'];
        }
        if (!empty($knownIds)) {
            return (int)array_key_last($knownIds);
        }

        return null;
    }

    public function getTopicReplyId($msg, $chatId, $topicContext = array())
    {
        if (!($msg instanceof erLhcoreClassModelmsg)) {
            return null;
        }

        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : array();

        if (isset($meta['content']['reply_to']['db_msg_id']) && (int)$meta['content']['reply_to']['db_msg_id'] > 0) {
            $targetMsg = erLhcoreClassModelmsg::fetch((int)$meta['content']['reply_to']['db_msg_id']);
            if ($targetMsg instanceof erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $preferredId = $meta['content']['reply_to']['telegram_message_id'] ?? ($meta['content']['reply_to']['tg_topic_msg_id'] ?? null);
                $resolvedId = $this->getStoredTopicMessageId($targetMsg, $preferredId, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        if (isset($meta['content']['reply_to']['iwh_msg_id']) && $meta['content']['reply_to']['iwh_msg_id'] != '') {
            $iwhId = (string)$meta['content']['reply_to']['iwh_msg_id'];
            $targetMsg = erLhcoreClassModelmsg::findOne([
                'filter' => ['chat_id' => $chatId],
                'customfilter' => ["`meta_msg` != '' AND JSON_VALID(`meta_msg`) AND (JSON_UNQUOTE(JSON_EXTRACT(meta_msg,'$.iwh_msg_id')) = " . ezcDbInstance::get()->quote($iwhId) . " OR JSON_EXTRACT(meta_msg,'$.iwh_msg_id') = " . (is_numeric($iwhId) ? (int)$iwhId : ezcDbInstance::get()->quote($iwhId)) . ")"]
            ]);
            if ($targetMsg instanceof erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $resolvedId = $this->getStoredTopicMessageId($targetMsg, null, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        if (isset($meta['content']['quote']['id']) && (int)$meta['content']['quote']['id'] > 0) {
            $targetMsg = erLhcoreClassModelmsg::fetch((int)$meta['content']['quote']['id']);
            if ($targetMsg instanceof erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $resolvedId = $this->getStoredTopicMessageId($targetMsg, null, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        if (preg_match('#\[quote="?([0-9]+)"?\]#is', (string)$msg->msg, $m)) {
            $targetMsg = erLhcoreClassModelmsg::fetch((int)$m[1]);
            if ($targetMsg instanceof erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $resolvedId = $this->getStoredTopicMessageId($targetMsg, null, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        return null;
    }

    public function getTopicMessageId($msg, $chatId, $topicContext = array())
    {
        if (!($msg instanceof erLhcoreClassModelmsg) || (int)$msg->chat_id !== (int)$chatId) {
            return null;
        }

        return $this->getStoredTopicMessageId($msg, null, $topicContext);
    }

    private function shouldRetryTelegramWithoutReply($sendData)
    {
        if (!is_object($sendData) || $sendData->isOk() || (int)$sendData->getErrorCode() !== 400) {
            return false;
        }

        $description = strtolower((string)$sendData->getDescription());
        foreach (array('message to be replied not found', 'reply message not found', 'message_id_invalid', "message can't be replied", 'message cannot be replied') as $needle) {
            if (strpos($description, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isTelegramTopicUnavailable($sendData)
    {
        if (!is_object($sendData) || $sendData->isOk() || (int)$sendData->getErrorCode() !== 400) {
            return false;
        }

        $description = strtolower((string)$sendData->getDescription());
        return strpos($description, 'message thread not found') !== false
            || strpos($description, 'topic_deleted') !== false
            || strpos($description, 'thread not found') !== false;
    }

    private function rewindTelegramResources(array &$data)
    {
        foreach ($data as &$value) {
            if (is_resource($value)) {
                @rewind($value);
            }
        }
        unset($value);
    }

    private function closeTelegramResources(array &$data)
    {
        foreach ($data as &$value) {
            if (is_resource($value)) {
                @fclose($value);
            }
        }
        unset($value);
    }

    private function getTelegramTextLength($text)
    {
        $text = (string)$text;
        if (function_exists('mb_convert_encoding')) {
            // Telegram applies its 4096-character limit to UTF-16 code units.
            return (int)(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
        }

        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function getTelegramTextSlice($text, $offset, $length)
    {
        return function_exists('mb_substr')
            ? mb_substr((string)$text, (int)$offset, (int)$length, 'UTF-8')
            : substr((string)$text, (int)$offset, (int)$length);
    }

    private function splitTelegramText($text, $limit = 4000)
    {
        $chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? $this->splitTelegramCharacters($chars, $limit, false) : array((string)$text);
    }

    private function getTelegramMessageChunks(array $data)
    {
        $isHtml = isset($data['parse_mode']) && strtolower((string)$data['parse_mode']) === 'html';
        $text = (string)($data['text'] ?? '');
        $plainText = $text;
        if ($isHtml) {
            // A split HTML message cannot safely retain arbitrary open tags or
            // entities. Long messages deliberately fall back to escaped text.
            $plainText = preg_replace('#<(?:br|/p|/div)\s*/?>#i', "\n", $text);
            // strip_tags() drops a run of literal '<' characters as if it were
            // an unfinished tag. Remove only tag-shaped markup so user text is
            // retained and can still be escaped/split below.
            $plainText = preg_replace('#<!--.*?-->|<![^>]*>|</?[a-z][^>]*>#is', '', (string)$plainText);
            $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($text) !== '' && trim($plainText) === '') {
                // PHP's strip_tags() drops malformed/raw angle-bracket text
                // such as "<" x5000. Keep it as text so the splitter can
                // escape and bound the payload instead of returning one
                // oversized raw HTML chunk.
                $plainText = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($this->getTelegramTextLength($this->escapeTelegramHtmlText($plainText)) <= 4096) {
                return array($data);
            }
        } elseif ($this->getTelegramTextLength($text) <= 4096) {
            return array($data);
        }

        $plainChunks = $isHtml
            ? $this->splitTelegramHtmlText($plainText)
            : $this->splitTelegramText($plainText);

        $chunks = array();
        foreach ($plainChunks as $index => $chunk) {
            $chunkData = $data;
            if ($isHtml) {
                // Telegram HTML accepts only four named entities. Escaping
                // explicitly avoids producing unsupported entities such as
                // &apos; in long-message fallbacks.
                $chunkData['text'] = $this->escapeTelegramHtmlText($chunk);
            } else {
                $chunkData['text'] = $chunk;
            }

            // The initial part preserves an explicit reply. Continuations are
            // left as ordinary messages in the same forum topic.
            if ($index > 0) {
                unset($chunkData['reply_to_message_id']);
            }

            $chunks[] = $chunkData;
        }

        return $chunks;
    }

    private function escapeTelegramHtmlText($text)
    {
        return strtr((string)$text, array(
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            '"' => '&quot;'
        ));
    }

    private function splitTelegramHtmlText($text, $limit = 4000)
    {
        $chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? $this->splitTelegramCharacters($chars, $limit, true) : array((string)$text);
    }

    private function splitTelegramCharacters(array $chars, $limit, $escape)
    {
        if (empty($chars)) {
            return array('');
        }

        $chunks = array();
        $current = array();
        $encodedLength = 0;
        $lastBreak = -1;

        foreach ($chars as $char) {
            $value = $escape ? $this->escapeTelegramHtmlText($char) : $char;
            $charLength = $this->getTelegramTextLength($value);
            if (!empty($current) && $encodedLength + $charLength > $limit) {
                $currentCount = count($current);
                $cut = ($lastBreak >= (int)floor($currentCount / 2)) ? $lastBreak + 1 : $currentCount;
                $chunks[] = implode('', array_slice($current, 0, $cut));
                $current = array_slice($current, $cut);
                $encodedLength = 0;
                $lastBreak = -1;
                foreach ($current as $index => $remainingChar) {
                    $remainingValue = $escape ? $this->escapeTelegramHtmlText($remainingChar) : $remainingChar;
                    $encodedLength += $this->getTelegramTextLength($remainingValue);
                    if ($remainingChar === "\n" || $remainingChar === ' ') {
                        $lastBreak = $index;
                    }
                }
            }

            $current[] = $char;
            $encodedLength += $charLength;
            if ($char === "\n" || $char === ' ') {
                $lastBreak = count($current) - 1;
            }
        }

        if (!empty($current)) {
            $chunks[] = implode('', $current);
        }

        return $chunks;
    }

    private function sendTelegramMessageWithSplit(array &$data)
    {
        $responses = array();
        foreach ($this->getTelegramMessageChunks($data) as $chunkData) {
            $response = Longman\TelegramBot\Request::send('sendMessage', $chunkData);
            if ($this->shouldRetryTelegramWithoutReply($response) && isset($chunkData['reply_to_message_id'])) {
                unset($chunkData['reply_to_message_id']);
                $response = Longman\TelegramBot\Request::send('sendMessage', $chunkData);
            }
            $responses[] = $response;

            if (!is_object($response) || !$response->isOk()) {
                break;
            }
        }

        $this->lastTelegramSendResponses = $responses;
        return end($responses);
    }

    private function sendTelegramRequestOnce($method, array &$data, $allowMessageSplit = false)
    {
        $this->rewindTelegramResources($data);

        if ($allowMessageSplit && $method === 'sendMessage') {
            return $this->sendTelegramMessageWithSplit($data);
        }

        $sendData = Longman\TelegramBot\Request::send($method, $data);
        $this->lastTelegramSendResponses = array($sendData);
        return $sendData;
    }

    private function hasTelegramStaleReplyResponse($sendData)
    {
        if ($this->shouldRetryTelegramWithoutReply($sendData)) {
            return true;
        }

        foreach ($this->lastTelegramSendResponses as $response) {
            if ($this->shouldRetryTelegramWithoutReply($response)) {
                return true;
            }
        }

        return false;
    }

    private function getTelegramSendMessageIds($sendData)
    {
        $ids = array();
        $responses = !empty($this->lastTelegramSendResponses) ? $this->lastTelegramSendResponses : array($sendData);
        foreach ($responses as $response) {
            if (is_object($response) && method_exists($response, 'isOk') && $response->isOk()) {
                $result = $response->getResult();
                if (is_object($result) && method_exists($result, 'getMessageId') && (int)$result->getMessageId() > 0) {
                    $ids[] = (int)$result->getMessageId();
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function saveTelegramTopicMessageIds($msg, $sendData, $messageData = array(), $topicContext = array())
    {
        foreach ($this->getTelegramSendMessageIds($sendData) as $topicMsgId) {
            $this->saveTopicMsgId($msg, $topicMsgId, $messageData, $topicContext);
        }
    }

    /**
     * Send once, then retry without a stale reply target for known 400 errors.
     *
     * Guzzle closes the raw resource returned by Request::encodeFile() after
     * consuming a multipart request. Keep the source path/field as private
     * retry context so a file upload can be reopened for the retry.
     */
    private function sendTelegramRequest($method, array $data, $multipartFilePath = '', $multipartFileField = '')
    {
        $multipartFilePath = (string)$multipartFilePath;
        $multipartFileField = (string)$multipartFileField;
        $allowMessageSplit = $method === 'sendMessage' && $multipartFilePath === '' && $multipartFileField === '';
        $this->lastTelegramSendResponses = array();

        try {
            $sendData = $this->sendTelegramRequestOnce($method, $data, $allowMessageSplit);
        } catch (\Throwable $e) {
            $this->closeTelegramResources($data);
            $this->lastTelegramSendResponses = array();
            erLhcoreClassLog::write('Telegram request exception ' . $e->getMessage(), ezcLog::SUCCESS_AUDIT, array('source' => 'lhc', 'category' => 'telegram_exception', 'line' => __LINE__, 'file' => __FILE__));
            return new Longman\TelegramBot\Entities\ServerResponse(array('ok' => false, 'error_code' => 500, 'description' => 'Telegram request failed'));
        }

        if (!$allowMessageSplit && $this->hasTelegramStaleReplyResponse($sendData) && isset($data['reply_to_message_id'])) {
            unset($data['reply_to_message_id']);
            try {
                if ($multipartFilePath !== '' && $multipartFileField !== '') {
                    if (isset($data[$multipartFileField]) && is_resource($data[$multipartFileField])) {
                        @fclose($data[$multipartFileField]);
                    }
                    $data[$multipartFileField] = Longman\TelegramBot\Request::encodeFile($multipartFilePath);
                } else {
                    $this->rewindTelegramResources($data);
                }
                $sendData = $this->sendTelegramRequestOnce($method, $data, $allowMessageSplit);
            } catch (\Throwable $e) {
                $this->closeTelegramResources($data);
                $this->lastTelegramSendResponses = array();
                erLhcoreClassLog::write('Telegram reply fallback exception ' . $e->getMessage(), ezcLog::SUCCESS_AUDIT, array('source' => 'lhc', 'category' => 'telegram_exception', 'line' => __LINE__, 'file' => __FILE__));
                return new Longman\TelegramBot\Entities\ServerResponse(array('ok' => false, 'error_code' => 500, 'description' => 'Telegram reply fallback failed'));
            }
        }

        return $sendData;
    }

    private function sendTelegramChatFile($tchat, $fileData, $caption, $disableNotification = false, $params = array())
    {
        $this->lastTelegramSendData = null;
        $file = $fileData['file'];

        // The download URL below resolves the stored local file. Do not send
        // a broken URL when cleanup removed the file before the worker ran.
        if (!is_object($file)
            || !is_string($file->file_path_server ?? null)
            || !is_file($file->file_path_server)
            || !is_readable($file->file_path_server)) {
            return false;
        }

        $extension = strtolower((string)$file->extension);
        $type = strtolower((string)$file->type);
        $method = 'sendDocument';
        $field = 'document';

        if (in_array($extension, array('jpg', 'jpeg', 'png', 'webp')) || in_array($type, array('image/jpeg', 'image/png', 'image/webp'))) {
            $method = 'sendPhoto';
            $field = 'photo';
        } elseif ($extension === 'ogg' || $type === 'audio/ogg') {
            $method = 'sendVoice';
            $field = 'voice';
        } elseif (in_array($extension, array('mp3', 'm4a')) || in_array($type, array('audio/mpeg', 'audio/mp4'))) {
            $method = 'sendAudio';
            $field = 'audio';
        } elseif ($extension === 'mp4' || $type === 'video/mp4') {
            $method = 'sendVideo';
            $field = 'video';
        }

        try {
            $data = array(
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id,
                'parse_mode' => 'HTML',
                // Keep the accepted URL-based path: downloadfile applies the
                // original upload name and storage callbacks before Telegram
                // receives the file.
                $field => $this->getTelegramChatFileUrl($file)
            );
        } catch (\Throwable $e) {
            erLhcoreClassLog::write('SendFile encode exception ' . $e->getMessage(), ezcLog::SUCCESS_AUDIT, array('source' => 'lhc', 'category' => 'telegram_exception', 'line' => __LINE__, 'file' => __FILE__, 'object_id' => $file->chat_id));
            return false;
        }

        if (isset($params['reply_to_message_id']) && $params['reply_to_message_id'] > 0) {
            $data['reply_to_message_id'] = $params['reply_to_message_id'];
        }

        if ($caption !== '') {
            $data['caption'] = $caption;
        }

        if ($disableNotification === true) {
            $data['disable_notification'] = true;
        }

        $sendData = $this->sendTelegramRequest($method, $data);
        $this->lastTelegramSendData = $sendData;
        if ($sendData === null) {
            return false;
        }

        if (!$sendData->isOk()) {
            erLhcoreClassLog::write('SendFile ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                ezcLog::SUCCESS_AUDIT,
                array(
                    'source' => 'lhc',
                    'category' => 'telegram_exception',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'object_id' => $file->chat_id
                )
            );

            return false;
        }

        return $sendData->getResult()->getMessageId();
    }

    private function getTelegramChatFileUrl($file)
    {
        $URLHash = '';

        if ($file->chat_id > 0) {
            $tsHash = time();
            $temporaryHash = sha1($file->id . '_' . $file->hash . '_' . $tsHash . '_' . erConfigClassLhConfig::getInstance()->getSetting('site', 'secrethash'));
            $URLHash = "/(vhash)/{$temporaryHash}/(vts)/{$tsHash}";
        }

        return erLhcoreClassSystem::getHost() . erLhcoreClassDesign::baseurldirect('file/downloadfile') . "/{$file->id}/{$file->security_hash}{$URLHash}";
    }

    public function messageAdded($params)
    {
        $chat = $params['chat'];
        $db = ezcDbInstance::get();

        foreach (erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {

            $db->beginTransaction();
            $tchat->syncAndLock('`last_msg_id`');
            $db->commit();

            if ($tchat->bot->bot_client == 0) {
                continue;
            }

            $telegram = new Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            $topicContext = $this->getTelegramTopicContextForChat($tchat);

            if ($params['msg']->id > $tchat->last_msg_id) {

                $db->beginTransaction();
                $tchat->syncAndLock('`id`');
                $tchat->last_msg_id = $params['msg']->id;
                $tchat->updateThis(['update' => ['last_msg_id']]);
                $db->commit();


                // remove following if you want enable autoresponder messages for operators chat
                if (isset($params['msg']->meta_msg_array['content']['auto_responder'])) {
                    continue;
                }
                // end here

                $telegramFiles = $this->getTelegramMessageFiles($params['msg']);
                $messageText = $this->stripTelegramFileEmbeds($params['msg']->msg);

                $sendData = null;

                if ($messageText !== '' && empty($telegramFiles)) {
                    $data = [
                        'chat_id' => $tchat->bot->group_chat_id,
                        'message_thread_id' => $tchat->tchat_id,
                        'parse_mode' => 'HTML',
                        'text' => trim(($params['msg']->name_support != '' ? '🤖 [' . $params['msg']->name_support . ']: <i>' : '👤 [' . erLhcoreClassBBCodePlain::make_clickable($chat->nick, array('sender' => 0)) . ']: ') . erLhcoreClassBBCodePlain::make_clickable($messageText, array('sender' => 0)) . ($params['msg']->name_support != '' ? '</i>' : ''))
                    ];

                    if ($chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }

                    $replyTopicMsgId = $this->getTopicReplyId($params['msg'], $chat->id, $topicContext);
                    if ($replyTopicMsgId > 0) {
                        $data['reply_to_message_id'] = $replyTopicMsgId;
                    }

                    $sendData = $this->sendTelegramRequest('sendMessage', $data);
                    $this->saveTelegramTopicMessageIds($params['msg'], $sendData, array('text' => $messageText, 'kind' => 'text'), $topicContext);

                    if ($this->isTelegramTopicUnavailable($sendData)) {
                        // Reset telegram chat
                        $tchat->tchat_id = 0;
                        $tchat->updateThis(['update' => ['tchat_id']]);

                        // Process request as a new chat just
                        $this->chatStarted(['chat' => $chat]);
                        return;
                    }

                    if (!$sendData->isOk()) {
                        erLhcoreClassLog::write('sendMessagesss ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                            ezcLog::SUCCESS_AUDIT,
                            array(
                                'source' => 'lhc',
                                'category' => 'telegram_exception',
                                'line' => __LINE__,
                                'file' => __FILE__,
                                'object_id' => $chat->id
                            )
                        );
                    }
                }

                if (!empty($telegramFiles) && ($sendData === null || $sendData->isOk())) {
                    $failedEmbedCodes = array();
                    $fileIndex = 0;

                    $replyTopicMsgId = $this->getTopicReplyId($params['msg'], $chat->id, $topicContext);
                    foreach ($telegramFiles as $telegramFile) {
                        $sentFileMsgId = $this->sendTelegramChatFile($tchat, $telegramFile, $this->getTelegramFileCaption($params['msg'], $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT, ['reply_to_message_id' => $replyTopicMsgId]);
                        if ($sentFileMsgId === false) {
                            if ($fileIndex === 0 && $this->isTelegramTopicUnavailable($this->lastTelegramSendData)) {
                                $tchat->tchat_id = 0;
                                $tchat->updateThis(['update' => ['tchat_id']]);
                                $this->chatStarted(['chat' => $chat]);
                                return;
                            }
                            $failedEmbedCodes[] = $telegramFile['embed'];
                        } else {
                            $this->saveTelegramFileTopicMsgId($params['msg'], $sentFileMsgId, $telegramFile, $this->getTelegramFileCaption($params['msg'], $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $topicContext);
                        }
                        $fileIndex++;
                    }

                    if (!empty($failedEmbedCodes)) {
                            $this->sendTelegramRequest('sendMessage', array(
                            'chat_id' => $tchat->bot->group_chat_id,
                            'message_thread_id' => $tchat->tchat_id,
                            'parse_mode' => 'HTML',
                            'text' => erLhcoreClassBBCodePlain::make_clickable(implode("\n", $failedEmbedCodes), array('sender' => 0))
                        ));
                    }
                }
            }

            if (isset($params['no_afterwards_messages']) && $params['no_afterwards_messages'] == true) {
                continue;
            }

            // remove following if you want enable autoresponder messages for operators chat
            if (isset($params['msg']->meta_msg_array['content']['auto_responder'])) {
                continue;
            }


            // Send bot responses if any
            $botMessages = erLhcoreClassModelmsg::getList(array('filter' => array('user_id' => -2, 'chat_id' => $chat->id), 'filtergt' => array('id' => $params['msg']->id)));
            $botReplyTopicMsgId = $this->getTopicMessageId($params['msg'], $chat->id, $topicContext);

            foreach ($botMessages as $botMessage) {

                $db->beginTransaction();
                $tchat->syncAndLock('`last_msg_id`');

                if ($botMessage->id <= $tchat->last_msg_id) {
                    $db->commit();
                    continue;
                } else {
                    $tchat->last_msg_id = $botMessage->id;
                }

                $tchat->updateThis(['update' => ['last_msg_id']]);
                $db->commit();

                $telegramFiles = $this->getTelegramMessageFiles($botMessage);
                $messageText = $this->stripTelegramFileEmbeds($botMessage->msg);

                if ($messageText !== '' && empty($telegramFiles)) {
                    $data = [
                        'chat_id' => $tchat->bot->group_chat_id,
                        'message_thread_id' => $tchat->tchat_id,
                        'parse_mode' => 'HTML',
                        'text' => trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤 ['. erLhcoreClassBBCodePlain::make_clickable($chat->nick, array('sender' => 0)) . ']: ') . erLhcoreClassBBCodePlain::make_clickable($messageText, array('sender' => 0)) . ($botMessage->name_support != '' ? '</i>' : ''))
                    ];
                    if ($chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }
                    if ($botReplyTopicMsgId > 0) {
                        $data['reply_to_message_id'] = $botReplyTopicMsgId;
                    }
                    $sendData = $this->sendTelegramRequest('sendMessage', $data);
                    $this->saveTelegramTopicMessageIds($botMessage, $sendData, array('text' => $messageText, 'kind' => 'text'), $topicContext);

                    if (!$sendData->isOk()) {
                        erLhcoreClassLog::write('SendMessage BOT ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                            ezcLog::SUCCESS_AUDIT,
                            array(
                                'source' => 'lhc',
                                'category' => 'telegram_exception',
                                'line' => __LINE__,
                                'file' => __FILE__,
                                'object_id' => $chat->id
                            )
                        );
                    }
                }

                if (!empty($telegramFiles)) {
                    $failedEmbedCodes = array();
                    $fileIndex = 0;

                    foreach ($telegramFiles as $telegramFile) {
                        $sentFileMsgId = $this->sendTelegramChatFile($tchat, $telegramFile, $this->getTelegramFileCaption($botMessage, $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT, ['reply_to_message_id' => $botReplyTopicMsgId]);
                        if ($sentFileMsgId === false) {
                            $failedEmbedCodes[] = $telegramFile['embed'];
                        } else {
                            $this->saveTelegramFileTopicMsgId($botMessage, $sentFileMsgId, $telegramFile, $this->getTelegramFileCaption($botMessage, $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $topicContext);
                        }
                        $fileIndex++;
                    }

                    if (!empty($failedEmbedCodes)) {
                        $this->sendTelegramRequest('sendMessage', array(
                            'chat_id' => $tchat->bot->group_chat_id,
                            'message_thread_id' => $tchat->tchat_id,
                            'parse_mode' => 'HTML',
                            'text' => erLhcoreClassBBCodePlain::make_clickable(implode("\n", $failedEmbedCodes), array('sender' => 0))
                        ));
                    }
                }
            }
        }
    }

    public function triggerClicked($params)
    {

        // Everything will be processed on main start chat trigger
        if (erLhcoreClassGenericBotWorkflow::$startChat == true) {
            return;
        }

        if (is_object($params['chat']->incoming_chat) && $params['chat']->incoming_chat->incoming->scope == 'telegram') {
            $telegramBot = erLhcoreClassModelTelegramBot::fetch((int)$_GET['telegram_bot_id']);
            if (is_object($telegramBot)) {
                $telegram = new \Longman\TelegramBot\Telegram($telegramBot->bot_api, $telegramBot->bot_username);
                \Longman\TelegramBot\Request::send('editMessageReplyMarkup',[
                    'chat_id' => $params['chat']->incoming_chat->chat_external_id,
                    'message_id' => $params['msg']->meta_msg_array['iwh_msg_id'],
                    'reply_markup' => null
                ]);
            }
        }

        $chat = $params['chat'];

        foreach (erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {

            $telegram = new Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            $topicContext = $this->getTelegramTopicContextForChat($tchat);

            if ($tchat->bot->bot_client == 0) {
                continue;
            }

            // remove following if you want enable autoresponder messages for operators chat
            if (isset($params['msg']->meta_msg_array['content']['auto_responder'])) {
                continue;
            }
            // end here

            // Send bot responses if any
            $botMessages = erLhcoreClassModelmsg::getList(array('filterin' => ['user_id' => [0, -2]], 'filter' => array('chat_id' => $chat->id), 'filtergt' => array('id' => $params['last_msg_id'])));
            foreach ($botMessages as $botMessage) {

                $tchat->last_msg_id = $botMessage->id;
                $tchat->updateThis(['update' => ['last_msg_id']]);

                $telegramFiles = $this->getTelegramMessageFiles($botMessage);
                $messageText = $this->stripTelegramFileEmbeds($botMessage->msg);
                $botReplyTopicMsgId = $this->getTopicMessageId($params['msg'], $chat->id, $topicContext);

                if ($messageText !== '' && empty($telegramFiles)) {
                    $data = [
                        'chat_id' => $tchat->bot->group_chat_id,
                        'message_thread_id' => $tchat->tchat_id,
                        'parse_mode' => 'HTML',
                        'text' => trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤: ') . erLhcoreClassBBCodePlain::make_clickable($messageText, array('sender' => 0)) . ($botMessage->name_support != '' ? '</i>' : ''))
                    ];
                    if ($chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }
                    if ($botReplyTopicMsgId > 0) {
                        $data['reply_to_message_id'] = $botReplyTopicMsgId;
                    }
                    $sendData = $this->sendTelegramRequest('sendMessage', $data);
                    $this->saveTelegramTopicMessageIds($botMessage, $sendData, array('text' => $messageText, 'kind' => 'text'), $topicContext);

                    if (!$sendData->isOk()) {
                        erLhcoreClassLog::write('['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                            ezcLog::SUCCESS_AUDIT,
                            array(
                                'source' => 'lhc',
                                'category' => 'telegram_exception',
                                'line' => __LINE__,
                                'file' => __FILE__,
                                'object_id' => $chat->id
                            )
                        );
                    }
                }

                if (!empty($telegramFiles)) {
                    $failedEmbedCodes = array();
                    $fileIndex = 0;

                    foreach ($telegramFiles as $telegramFile) {
                        $sentFileMsgId = $this->sendTelegramChatFile($tchat, $telegramFile, $this->getTelegramFileCaption($botMessage, $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $chat->status == erLhcoreClassModelChat::STATUS_BOT_CHAT, ['reply_to_message_id' => $botReplyTopicMsgId]);
                        if ($sentFileMsgId === false) {
                            $failedEmbedCodes[] = $telegramFile['embed'];
                        } else {
                            $this->saveTelegramFileTopicMsgId($botMessage, $sentFileMsgId, $telegramFile, $this->getTelegramFileCaption($botMessage, $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $topicContext);
                        }
                        $fileIndex++;
                    }

                    if (!empty($failedEmbedCodes)) {
                        $this->sendTelegramRequest('sendMessage', array(
                            'chat_id' => $tchat->bot->group_chat_id,
                            'message_thread_id' => $tchat->tchat_id,
                            'parse_mode' => 'HTML',
                            'text' => erLhcoreClassBBCodePlain::make_clickable(implode("\n", $failedEmbedCodes), array('sender' => 0))
                        ));
                    }
                }
            }
        }
    }

    public function chatStarted($params)
    {
        $bots = erLhcoreClassModelTelegramBotDep::getList(array('filter' => array('dep_id' => $params['chat']->dep_id)));
        $db = ezcDbInstance::get();

        foreach ($bots as $bot) {
            if ($bot->bot instanceof erLhcoreClassModelTelegramBot && $bot->bot->bot_client == 1) {

                try {
                    $db->beginTransaction();

                    $chatId = $params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id;

                    $tChat = \erLhcoreClassModelTelegramChat::findOne(array(
                        'filter' => array(
                            'chat_id_internal' => $chatId,
                            'bot_id' => $bot->bot->id,
                            'type' => 1
                        )
                    ));

                    if (!($tChat instanceof \erLhcoreClassModelTelegramChat)) {
                        $tChat = new \erLhcoreClassModelTelegramChat();
                        $tChat->type = 1;
                        $tChat->bot_id = $bot->bot->id;
                        $tChat->chat_id_internal = $chatId;
                        $tChat->chat_id = $params['chat']->id;
                        $tChat->utime = time();
                        $tChat->ctime = time();
                    } else {
                        // Update to a new chat
                        $tChat->chat_id = $params['chat']->id;
                        $tChat->updateThis(['update' => ['chat_id']]);
                    }

                    $telegram = new Longman\TelegramBot\Telegram($bot->bot->bot_api, $bot->bot->bot_username);
                    $topicContext = array(
                        'bot_id' => (int)$bot->bot->id,
                        'group_chat_id' => (string)$bot->bot->group_chat_id
                    );

                    if ($tChat->tchat_id == null || $tChat->tchat_id == 0) {
                        $sendData = Longman\TelegramBot\Request::send('createForumTopic', [
                            'chat_id' => $bot->bot->group_chat_id,
                            'name' => mb_substr('[' . $params['chat']->department . '] ' . $params['chat']->nick . ' #' . $params['chat']->id. ($params['chat']->ip != '' ? ' | ' . $params['chat']->ip : '') . ($params['chat']->country_code != '' ? ' | ' . strtoupper($params['chat']->country_code) : '') . ($params['chat']->referrer != '' ? ' | '. ltrim($params['chat']->referrer,'/') : '') . (is_object($params['chat']->online_user) && $params['chat']->online_user->page_title != '' ? ' | '.$params['chat']->online_user->page_title : ''),0,128)
                        ]);

                        if ($sendData->isOk()) {
                            $tChat->tchat_id = $sendData->getResult()->getMessageThreadId();
                        } else {
                            throw new Exception('['.$sendData->getErrorCode().']'. $sendData->getDescription());
                        }
                    }

                    $previousChatMessages = '';

                    if ($bot->bot->delete_on_close == 1 && $params['chat']->online_user_id > 0 && is_object($params['chat']->online_user) && is_object($params['chat']->online_user->previous_chat)) {
                        $previousChatMessagesList = [];
                        foreach (array_reverse(erLhcoreClassModelmsg::getList(array('limit' => 15, 'sort' => 'id DESC', 'filternotin' => ['user_id' => [-1]], 'filter' => array('chat_id' => $params['chat']->online_user->previous_chat->id)))) as $botMessage) {
                            if (empty($botMessage->msg)) {
                                continue;
                            }
                            $previousChatMessagesList[] = trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤 ['. erLhcoreClassBBCodePlain::make_clickable($params['chat']->nick, array('sender' => 0)) . ']: ') . erLhcoreClassBBCodePlain::make_clickable($botMessage->msg, array('sender' => 0)) . ($botMessage->name_support != '' ? '</i>' : ''));
                        }

                        if (!empty($previousChatMessagesList)){
                            $previousChatMessages = "\n├──Previous chat messages: \n" . implode("\n", $previousChatMessagesList);
                        }
                    }

                    $additionalDataFormatted = '';
                    if (isset($params['chat']->additional_data) && !empty($params['chat']->additional_data)) {
                        $additionalData = json_decode($params['chat']->additional_data, true);
                        if (is_array($additionalData) && !empty($additionalData)) {
                            $additionalDataLines = [];
                            foreach ($additionalData as $dataItem) {
                                if (isset($dataItem['key']) && isset($dataItem['value']) && $dataItem['key'] !== '' && $dataItem['value'] !== '') {
                                    $additionalDataLines[] = "├──" . $dataItem['key'] . ": " . $dataItem['value'];
                                }
                            }
                            if (!empty($additionalDataLines)) {
                                $additionalDataFormatted = "\n" . implode("\n", $additionalDataLines);
                            }
                        }
                    }

                    $visitor = array();
                    $visitor[] = "├──New chat\n├──Department: " . ((string)$params['chat']->department) . "\n├──ID: " . $params['chat']->id . (isset($params['chat']->chat_variables_array['iwh_field']) ? "\n├──Username: @" . $params['chat']->chat_variables_array['iwh_field'] : '') . (isset($params['chat']->phone) && !empty($params['chat']->phone) ? "\n├──Phone: +" . $params['chat']->phone : '') .  "\n├──Nick: " . $params['chat']->nick .(isset($params['chat']->referrer) && !empty($params['chat']->referrer) ? "\n├──Referrer: " . ltrim($params['chat']->referrer,'/') : '') . (is_object($params['chat']->online_user) && $params['chat']->online_user->page_title != '' ? "\n├──Page title: " . $params['chat']->online_user->page_title : '') . (isset($params['chat']->ip) && !empty($params['chat']->ip) ? "\n├──IP: " . $params['chat']->ip  : '') . (isset($params['chat']->country_name) && !empty($params['chat']->country_name) ? "\n├──GEO: " . $params['chat']->country_name : '') . $additionalDataFormatted . $previousChatMessages . "\n└──Messages:";

                    // Collect all chat messages including bot
                    $initialTelegramFiles = array();
                    $initialAggregateMessages = array();
                    $botMessages = erLhcoreClassModelmsg::getList(array('filterin' => ['user_id' => [0, -2]], 'filter' => array('chat_id' => $params['chat']->id)));
                    foreach ($botMessages as $botMessage) {
                        $tChat->last_msg_id = $botMessage->id;
                        $telegramFiles = $this->getTelegramMessageFiles($botMessage);
                        $messageText = $this->stripTelegramFileEmbeds($botMessage->msg);

                        if ($messageText === '' && empty($telegramFiles)) {
                            continue;
                        }

                        if ($messageText !== '' && empty($telegramFiles)) {
                            $visitor[] = trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤 ['. erLhcoreClassBBCodePlain::make_clickable($params['chat']->nick, array('sender' => 0)) . ']: ') . erLhcoreClassBBCodePlain::make_clickable($messageText, array('sender' => 0)) . ($botMessage->name_support != '' ? '</i>' : ''));
                            $initialAggregateMessages[] = array('msg' => $botMessage, 'text' => $messageText);
                        }

                        $fileIndex = 0;
                        foreach ($telegramFiles as $telegramFile) {
                            $initialTelegramFiles[] = array('msg' => $botMessage, 'file' => $telegramFile, 'text' => $fileIndex === 0 ? $messageText : '');
                            $fileIndex++;
                        }
                    }

                    $data = [
                        'chat_id' => $bot->bot->group_chat_id,
                        'message_thread_id' => $tChat->tchat_id,
                        'text' => implode("\n\n", $visitor),
                        'parse_mode' => 'HTML'
                    ];

                    if ($params['chat']->status == erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }

                    $sendData = $this->sendTelegramRequest('sendMessage', $data);

                    if ($sendData->isOk()) {
                        $aggregateMsgId = $sendData->getResult()->getMessageId();
                        foreach ($initialAggregateMessages as $aggregateMessage) {
                            $this->saveTelegramTopicMessageIds($aggregateMessage['msg'], $sendData, array('text' => $aggregateMessage['text'], 'kind' => 'aggregate'), $topicContext);
                        }
                        if (empty($initialAggregateMessages)) {
                            $firstMsg = erLhcoreClassModelmsg::findOne(['filter' => ['chat_id' => $params['chat']->id], 'sort' => 'id ASC']);
                            if ($firstMsg instanceof erLhcoreClassModelmsg) {
                                $this->saveTelegramTopicMessageIds($firstMsg, $sendData, array('text' => $data['text'], 'kind' => 'aggregate'), $topicContext);
                            }
                        }
                    } else {

                        // Try first time to create a topic if old one is gone
                        if ($sendData->getErrorCode() == 400 && (str_contains($sendData->getDescription(), 'message thread not found') || str_contains($sendData->getDescription(), 'TOPIC_DELETED'))) {

                            $sendData = Longman\TelegramBot\Request::send('createForumTopic', [
                                'chat_id' => $bot->bot->group_chat_id,
                                'name' => mb_substr('[' . $params['chat']->department . '] ' . $params['chat']->nick . ' #' . $params['chat']->id. ($params['chat']->ip != '' ? ' | ' . $params['chat']->ip : '') . ($params['chat']->country_code != '' ? ' | ' . strtoupper($params['chat']->country_code) : '') . ($params['chat']->referrer != '' ? ' | '. ltrim($params['chat']->referrer,'/') : '') . (is_object($params['chat']->online_user) && $params['chat']->online_user->page_title != '' ? ' | '.$params['chat']->online_user->page_title : ''),0,128)
                            ]);

                            if ($sendData->isOk()) {
                                $tChat->tchat_id = $sendData->getResult()->getMessageThreadId();
                            } else {
                                throw new Exception('['.$sendData->getErrorCode().']'. $sendData->getDescription());
                            }
                        }

                        $data['message_thread_id'] = $tChat->tchat_id;
                        $sendData = $this->sendTelegramRequest('sendMessage', $data);

                        if ($sendData->isOk()) {
                            $aggregateMsgId = $sendData->getResult()->getMessageId();
                            foreach ($initialAggregateMessages as $aggregateMessage) {
                            $this->saveTelegramTopicMessageIds($aggregateMessage['msg'], $sendData, array('text' => $aggregateMessage['text'], 'kind' => 'aggregate'), $topicContext);
                            }
                            if (empty($initialAggregateMessages)) {
                                $firstMsg = erLhcoreClassModelmsg::findOne(['filter' => ['chat_id' => $params['chat']->id], 'sort' => 'id ASC']);
                                if ($firstMsg instanceof erLhcoreClassModelmsg) {
                                    $this->saveTelegramTopicMessageIds($firstMsg, $sendData, array('text' => $data['text'], 'kind' => 'aggregate'), $topicContext);
                                }
                            }
                        } else {
                            erLhcoreClassLog::write('['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                                ezcLog::SUCCESS_AUDIT,
                                array(
                                    'source' => 'lhc',
                                    'category' => 'telegram_exception',
                                    'line' => __LINE__,
                                    'file' => __FILE__,
                                    'object_id' => $tChat->chat_id
                                )
                            );
                        }
                    }

                    if (!empty($initialTelegramFiles)) {
                        $failedEmbedCodes = array();

                        foreach ($initialTelegramFiles as $initialTelegramFile) {
                            $sentFileMsgId = $this->sendTelegramChatFile($tChat, $initialTelegramFile['file'], $this->getTelegramFileCaption($initialTelegramFile['msg'], $params['chat'], $initialTelegramFile['file']['file'], $initialTelegramFile['text']), $params['chat']->status == erLhcoreClassModelChat::STATUS_BOT_CHAT);
                            if ($sentFileMsgId === false) {
                                $failedEmbedCodes[] = $initialTelegramFile['file']['embed'];
                            } else if (isset($initialTelegramFile['msg']) && $initialTelegramFile['msg'] instanceof erLhcoreClassModelmsg) {
                                $this->saveTelegramFileTopicMsgId($initialTelegramFile['msg'], $sentFileMsgId, $initialTelegramFile['file'], $this->getTelegramFileCaption($initialTelegramFile['msg'], $params['chat'], $initialTelegramFile['file']['file'], $initialTelegramFile['text']), $topicContext);
                            }
                        }

                        if (!empty($failedEmbedCodes)) {
                            $this->sendTelegramRequest('sendMessage', array(
                                'chat_id' => $tChat->bot->group_chat_id,
                                'message_thread_id' => $tChat->tchat_id,
                                'parse_mode' => 'HTML',
                                'text' => erLhcoreClassBBCodePlain::make_clickable(implode("\n", $failedEmbedCodes), array('sender' => 0))
                            ));
                        }
                    }

                    $tChat->saveThis();

                    $db->commit();

                } catch (Exception $e) {

                    $db->rollback();

                    erLhcoreClassLog::write($e->getMessage() . '-' . $e->getTraceAsString(),
                        ezcLog::SUCCESS_AUDIT,
                        array(
                            'source' => 'lhc',
                            'category' => 'telegram_exception',
                            'line' => __LINE__,
                            'file' => __FILE__,
                            'object_id' => $params['chat']->id
                        )
                    );
                }
            }
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
            'erLhcoreClassModelTelegramSignature' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegramsignature.php',
            'erLhcoreClassModelTelegramLead' => 'extension/lhctelegram/classes/erlhcoreclassmodeltelegramlead.php',
            'erLhcoreClassTelegramValidator' => 'extension/lhctelegram/classes/erlhcoreclasstelegramvalidator.php'
        );

        if (key_exists($className, $classesArray)) {
            include_once $classesArray [$className];
        }
    }

    public static function getSession()
    {
        if (!isset (self::$persistentSession)) {
            self::$persistentSession = new ezcPersistentSession (ezcDbInstance::get(), new ezcPersistentCodeManager ('./extension/lhctelegram/pos'));
        }
        return self::$persistentSession;
    }

    /**
     * @desc delete chat if exists
     *
     * @param $params
     */
    public function deleteChat($params)
    {
        $this->closeChat($params);

        $db = ezcDbInstance::get();
        $stmt = $db->prepare('DELETE FROM lhc_telegram_chat WHERE chat_id_internal = :chat_id_internal');
        $stmt->bindValue(':chat_id_internal', ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), PDO::PARAM_INT);
        $stmt->execute();
    }

    /*
     * Delete forum topic if configured
     * */
    public function closeChat($params)
    {
        foreach (erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {

            if ($tchat->bot->bot_client == 0 || $tchat->bot->delete_on_close == 0) {
                continue;
            }

            if ($tchat->tchat_id > 0) {

                $telegram = new Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);

                $sendData = Longman\TelegramBot\Request::send('deleteForumTopic', [
                    'chat_id' => $tchat->bot->group_chat_id,
                    'message_thread_id' => $tchat->tchat_id
                ]);

                $tchat->tchat_id = 0;
                $tchat->updateThis(['update' => ['tchat_id']]);

                if (!$sendData->isOk()) {
                    erLhcoreClassLog::write('deleteForumTopic ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                        ezcLog::SUCCESS_AUDIT,
                        array(
                            'source' => 'lhc',
                            'category' => 'telegram_exception',
                            'line' => __LINE__,
                            'file' => __FILE__,
                            'object_id' => $params['chat']->id
                        )
                    );
                }
            }
        }
    }

    /**
     * @desc Returns signature. Other extensions can use this callback also. E.g Twilio extension
     *
     * @param $params
     * @return array
     */
    public function getSignature($params)
    {

        if (isset($params['bot_id'])) {
            $signature = erLhcoreClassModelTelegramSignature::findOne(array('filter' => array('bot_id' => $params['bot_id'], 'user_id' => $params['user_id'])));
            if ($signature instanceof erLhcoreClassModelTelegramSignature) {
                return array('status' => erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'signature' => $signature->signature);
            }
        }

        $signature = erLhcoreClassModelTelegramSignature::findOne(array('filter' => array('bot_id' => 0, 'user_id' => $params['user_id'])));
        if ($signature instanceof erLhcoreClassModelTelegramSignature) {
            return array('status' => erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'signature' => $signature->signature);
        }

        return array('status' => erLhcoreClassChatEventDispatcher::STOP_WORKFLOW, 'signature' => '');
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
}
