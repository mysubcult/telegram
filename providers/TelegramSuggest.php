<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/**
 * Reply suggestions for operators working from Telegram topics.
 *
 * "!?" typed in the topic (any mode): as a Telegram reply to a visitor message -> suggestion for that message,
 * otherwise for the latest visitor message. History sent to the REST API ends at that message.
 * mode 'command' (default): only "!?".
 * mode 'button': every visitor message in the topic gets a "💡 Ответ" button; pressing it queues a resque job
 * that asks the configured REST API (bot prompt lives in LHC) for a draft reply to that message.
 * mode 'auto': the job is queued by itself after each visitor message in a chat an operator already handles,
 * waits 'delay' seconds and gives up if anyone wrote meanwhile or the model answers SKIP.
 * The draft is posted into the chat topic with "Send" / "Dismiss" buttons. All buttons are handled in CallbackqueryCommand.
 *
 * settings/settings.ini.php:
 *  'suggest' => array('enabled' => true, 'mode' => 'command', 'rest_api_id' => 105, 'dep_ids' => array(4), 'delay' => 4)
 */
#[\AllowDynamicProperties]
class TelegramSuggest
{
    const QUEUE = 'lhc_telegram_suggest';
    const HEADER = '💡 Подсказка:';
    const CALLBACK_SEND = 'sgs';
    const CALLBACK_DISMISS = 'sgx';
    const CALLBACK_GENERATE = 'sgg';
    const COMMAND = '!?';
    const NO_SUGGESTION = '💡 Подсказки нет';
    const REQUEST_FAILED = '⚠️ Не удалось получить подсказку: ИИ не ответил. Попробуйте «!?» ещё раз.';
    const CHAT_CLOSED = '💡 Подсказки нет: чат уже закрыт.';

    public static function getSettings()
    {
        $settings = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhctelegram')->settings;
        $suggest = isset($settings['suggest']) && is_array($settings['suggest']) ? $settings['suggest'] : array();

        return array(
            'enabled' => !empty($suggest['enabled']),
            'mode' => in_array($suggest['mode'] ?? 'command', array('auto', 'button'), true) ? $suggest['mode'] : 'command',
            'rest_api_id' => (int)($suggest['rest_api_id'] ?? 0),
            'dep_ids' => array_map('intval', (array)($suggest['dep_ids'] ?? array())),
            'delay' => max(0, (int)($suggest['delay'] ?? 4)),
        );
    }

    // Visitor text worth a suggestion: not empty, not a bare file, not a /command
    public static function isSuggestableText($text)
    {
        $text = trim(preg_replace('/\[file=[^\]]+\]/i', '', (string)$text));
        return mb_strlen($text) >= 2 && strpos($text, '/') !== 0;
    }

    // "SKIP" or "SKIP: reason for the operator"
    public static function isSkip($text)
    {
        $text = trim((string)$text);
        return $text === '' || preg_match('/^\W*SKIP(?:\W*$|\s*[:\-—–])/iu', $text) === 1;
    }

    public static function skipReason($text)
    {
        if (preg_match('/^\W*SKIP\s*[:\-—–]\s*(.+)$/isu', trim((string)$text), $m)) {
            $reason = trim(preg_replace('/\s+/u', ' ', $m[1]), " \t.");
            return mb_strlen($reason) > 120 ? rtrim(mb_substr($reason, 0, 119)) . '…' : $reason;
        }
        return '';
    }

    public static function buildNoSuggestion($reason)
    {
        return self::NO_SUGGESTION . ($reason !== '' ? ': ' . $reason : '') . '. Ответьте сами.';
    }

    // "💡 Подсказка на «visitor text»:" — shows which message the suggestion answers
    public static function buildHeader($visitorText)
    {
        $visitorText = trim(preg_replace('/\s+/u', ' ', preg_replace('/\[file=[^\]]+\]/i', '📎', (string)$visitorText)));
        if (mb_strlen($visitorText) > 60) {
            $visitorText = rtrim(mb_substr($visitorText, 0, 59)) . '…';
        }
        return $visitorText === '' ? self::HEADER : mb_substr(self::HEADER, 0, -1) . ' на «' . $visitorText . '»:';
    }

    // Suggestion text back from the topic message the button belongs to (header line + blank line + text)
    public static function extractSuggestion($messageText)
    {
        $messageText = (string)$messageText;
        $headerStart = mb_substr(self::HEADER, 0, -1);
        if (strpos($messageText, $headerStart) === 0) {
            $parts = explode("\n\n", $messageText, 2);
            $messageText = $parts[1] ?? '';
        }
        return trim($messageText);
    }

    public static function buildKeyboard($chatId, $withSend = true)
    {
        $buttons = array();
        if ($withSend === true) {
            $buttons[] = ['text' => '✅ Отправить', 'callback_data' => self::CALLBACK_SEND . '||' . (int)$chatId];
        }
        $buttons[] = ['text' => '✖️', 'callback_data' => self::CALLBACK_DISMISS . '||' . (int)$chatId];
        return new \Longman\TelegramBot\Entities\InlineKeyboard($buttons);
    }

    public static function buildGenerateKeyboard($chatId, $msgId)
    {
        return new \Longman\TelegramBot\Entities\InlineKeyboard([
            ['text' => '💡 Ответ', 'callback_data' => self::CALLBACK_GENERATE . '||' . (int)$chatId . '||' . (int)$msgId],
        ]);
    }

    public static function getOperatorTopics($chat)
    {
        $topics = array();
        foreach (\erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($chat->online_user_id > 0 ? ($chat->online_user_id * -1) : $chat->id), 'type' => 1]]) as $tchat) {
            if ($tchat->bot->bot_client != 0 && (int)$tchat->tchat_id > 0) {
                $topics[] = $tchat;
            }
        }
        return $topics;
    }

    // Puts "💡 Ответ" under the visitor message the operator topic already shows
    public static function attachGenerateButton($chat, $msg)
    {
        foreach (self::getOperatorTopics($chat) as $tchat) {
            new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            $topicContext = TelegramLiveHelperChatOperator::getTelegramTopicContextForChat($tchat);

            // Re-reads meta_msg, messageAdded has just stored the topic message id there
            if (!TelegramLiveHelperChatOperator::isTopicMessageAlreadySent($msg, $topicContext)) {
                continue;
            }

            \Longman\TelegramBot\Request::editMessageReplyMarkup([
                'chat_id' => $tchat->bot->group_chat_id,
                'message_id' => TelegramLiveHelperChatOperator::getStoredTopicMessageId($msg, null, $topicContext),
                'reply_markup' => self::buildGenerateKeyboard($chat->id, $msg->id),
            ]);
        }
    }

    /**
     * Listener for chat.addmsguser and chat.messages_added_passive
     */
    public static function visitorMessageAdded($params)
    {
        if (!isset($params['chat'], $params['msg']) || !($params['chat'] instanceof \erLhcoreClassModelChat) || !is_object($params['msg'])) {
            return;
        }

        $chat = $params['chat'];
        $msg = $params['msg'];
        $settings = self::getSettings();

        if ($settings['enabled'] === false || $settings['rest_api_id'] <= 0 || (int)$msg->user_id !== 0 || (int)$msg->id <= 0) {
            return;
        }

        if ($settings['mode'] === 'command') {
            return;
        }

        if (!self::isDepartmentEnabled($chat, $settings)) {
            return;
        }

        if (!self::isSuggestableText($msg->msg)) {
            return;
        }

        // Only chats an operator already accepted: "Send" posts a plain operator message and does not accept pending chats
        if ((int)$chat->status !== \erLhcoreClassModelChat::STATUS_ACTIVE_CHAT || (int)$chat->user_id <= 0) {
            return;
        }

        if ($settings['mode'] === 'button') {
            self::attachGenerateButton($chat, $msg);
            return;
        }

        self::enqueue($chat->id, $msg->id, false);
    }

    public static function isDepartmentEnabled($chat, $settings)
    {
        return empty($settings['dep_ids']) || in_array((int)$chat->dep_id, $settings['dep_ids'], true);
    }

    public static function isSuggestCommand($text)
    {
        return trim((string)$text) === self::COMMAND;
    }

    // LHC message shown as the given topic message (same lookup as replies in GenericmessageCommand)
    public static function findMessageByTopicId($chatId, $topicMsgId, $topicNamespace)
    {
        $db = \ezcDbInstance::get();
        $msg = \erLhcoreClassModelmsg::findOne([
            'filter' => ['chat_id' => (int)$chatId],
            'customfilter' => ['`meta_msg` != \'\' AND JSON_VALID(`meta_msg`) AND JSON_CONTAINS(JSON_EXTRACT(meta_msg, ' . $db->quote('$.tg_topic_msg_contexts.' . $topicNamespace . '.ids') . '), ' . $db->quote(json_encode(array((int)$topicMsgId))) . ')']
        ]);

        if (!($msg instanceof \erLhcoreClassModelmsg)) {
            $msg = \erLhcoreClassModelmsg::findOne([
                'filter' => ['chat_id' => (int)$chatId],
                'customfilter' => ['meta_msg != \'\' AND JSON_VALID(meta_msg) AND (JSON_EXTRACT(meta_msg, \'$.tg_topic_msg_id\') = ' . (int)$topicMsgId . ' OR JSON_CONTAINS(JSON_EXTRACT(meta_msg, \'$.tg_topic_msg_ids\'), \'[' . (int)$topicMsgId . ']\'))']
            ]);
        }

        return $msg instanceof \erLhcoreClassModelmsg ? $msg : null;
    }

    // Visitor message the suggestion is for: the replied one (or the last visitor message before it), else the latest
    public static function findTargetMessage($chat, $replyTopicMsgId, $topicNamespace)
    {
        $filter = array('filter' => array('chat_id' => $chat->id, 'user_id' => 0), 'sort' => 'id DESC');

        if ($replyTopicMsgId > 0) {
            $replied = self::findMessageByTopicId($chat->id, $replyTopicMsgId, $topicNamespace);
            if ($replied instanceof \erLhcoreClassModelmsg) {
                $filter['filterlte'] = array('id' => $replied->id);
            }
        }

        $msg = \erLhcoreClassModelmsg::findOne($filter);
        return $msg instanceof \erLhcoreClassModelmsg ? $msg : null;
    }

    /**
     * "!?" typed by the operator in the chat topic. Returns an error text for the topic or '' when queued.
     */
    public static function handleCommand(\erLhcoreClassModelChat $chat, $message, $topicNamespace)
    {
        $settings = self::getSettings();

        if ($settings['enabled'] === false || $settings['rest_api_id'] <= 0 || !self::isDepartmentEnabled($chat, $settings)) {
            return 'Подсказки выключены для этого отдела.';
        }

        // "Send" posts a plain operator message and does not accept pending chats
        if ((int)$chat->status !== \erLhcoreClassModelChat::STATUS_ACTIVE_CHAT) {
            return 'Подсказки работают в принятом чате: сначала ответьте клиенту сами.';
        }

        $replyData = \erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
        $replyTopicMsgId = !empty($replyData['is_explicit_reply']) ? (int)$replyData['reply_message_id'] : 0;

        $target = self::findTargetMessage($chat, $replyTopicMsgId, $topicNamespace);
        if ($target === null) {
            return 'В чате нет сообщений клиента.';
        }

        if (!self::enqueue($chat->id, $target->id, true)) {
            return 'Фоновые задачи недоступны.';
        }

        // Keep the topic clean, the suggestion arrives as a reply to the chosen message
        \Longman\TelegramBot\Request::deleteMessage(['chat_id' => $message->getChat()->getId(), 'message_id' => $message->getMessageId()]);

        return '';
    }

    public static function enqueue($chatId, $msgId, $manual)
    {
        $resque = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhcphpresque');
        if (!is_object($resque)) {
            return false;
        }

        $resque->enqueue(self::QUEUE, '\\' . self::class, array('chat_id' => (int)$chatId, 'msg_id' => (int)$msgId, 'manual' => $manual ? 1 : 0));
        return true;
    }

    // True when anyone (visitor or operator) wrote after the message we are suggesting for
    public static function hasNewerMessages($chatId, $msgId)
    {
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('SELECT id FROM lh_msg WHERE chat_id = :chat_id AND id > :msg_id AND user_id >= 0 LIMIT 1');
        $stmt->bindValue(':chat_id', (int)$chatId, \PDO::PARAM_INT);
        $stmt->bindValue(':msg_id', (int)$msgId, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public static function shiftHistory($bodyRaw, $offset)
    {
        return preg_replace_callback('/\{previous_visitor_messages_list_url__([0-9]+)(?:__([0-9]+))?\}/', function ($m) use ($offset) {
            return '{previous_visitor_messages_list_url__' . $m[1] . '__' . ((int)($m[2] ?? 0) + (int)$offset) . '}';
        }, (string)$bodyRaw);
    }

    public static function requestSuggestion($chat, $msg, $restApiId)
    {
        $restAPI = \erLhcoreClassModelGenericBotRestAPI::fetch($restApiId);
        if (!($restAPI instanceof \erLhcoreClassModelGenericBotRestAPI) || empty($restAPI->configuration_array['parameters'][0])) {
            return '';
        }

        $method = $restAPI->configuration_array['parameters'][0];

        // History placeholder counts back from the newest chat message; shift it so the history ends at $msg
        // (same filters as the placeholder in erLhcoreClassGenericBotActionRestapi::extractDynamicVariables)
        $newer = \erLhcoreClassModelmsg::getCount(array(
            'filter' => array('chat_id' => $chat->id),
            'filtergt' => array('id' => $msg->id),
            'filternotlikefields' => [['meta_msg' => '"debug":true'], ['meta_msg' => '{"content":{"typing"'], ['meta_msg' => '{"content":{"execute_js"']]
        ));
        $method['body_raw'] = self::shiftHistory($method['body_raw'], $newer);

        $response = \erLhcoreClassGenericBotActionRestapi::makeRequest(
            $restAPI->configuration_array['host'],
            $method,
            array('rest_api' => $restAPI, 'action' => array(), 'rest_api_method_params' => array(), 'chat' => $chat, 'params' => array('msg' => $msg))
        );

        if ((int)($response['http_code'] ?? 0) !== 200) {
            return array('text' => '', 'reason' => '', 'error' => true);
        }

        $data = json_decode((string)($response['content_raw'] ?? ''), true);
        $text = '';
        foreach ((array)($data['candidates'][0]['content']['parts'] ?? array()) as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        if (self::isSkip($text)) {
            return array('text' => '', 'reason' => self::skipReason($text), 'error' => trim($text) === '');
        }

        return array('text' => trim($text), 'reason' => '', 'error' => false);
    }

    // Resque job
    public function perform()
    {
        $settings = self::getSettings();
        $manual = !empty($this->args['manual']);

        if ($settings['enabled'] === false) {
            return;
        }

        if ($manual === false && $settings['delay'] > 0) {
            sleep($settings['delay']);
        }

        $db = \ezcDbInstance::get();
        $db->reconnect();

        $chatId = (int)$this->args['chat_id'];
        $msgId = (int)$this->args['msg_id'];

        $chat = \erLhcoreClassModelChat::fetch($chatId);
        $msg = \erLhcoreClassModelmsg::fetch($msgId);

        if (!($chat instanceof \erLhcoreClassModelChat) || !($msg instanceof \erLhcoreClassModelmsg) || (int)$msg->chat_id !== $chatId) {
            return;
        }

        if ((int)$chat->status === \erLhcoreClassModelChat::STATUS_CLOSED_CHAT) {
            if ($manual === true) {
                self::postSuggestion($chat, $msg, '', self::CHAT_CLOSED);
            }
            return;
        }

        // Auto mode: operator already answered or visitor keeps typing (the newer message has its own job)
        if ($manual === false && ((int)$chat->status !== \erLhcoreClassModelChat::STATUS_ACTIVE_CHAT || self::hasNewerMessages($chatId, $msgId))) {
            return;
        }

        $result = self::requestSuggestion($chat, $msg, $settings['rest_api_id']);

        if ($manual === false && ($result['text'] === '' || self::hasNewerMessages($chatId, $msgId))) {
            return;
        }

        // Operator asked explicitly, so an empty answer or a failure is reported too
        $note = $result['error'] === true ? self::REQUEST_FAILED : self::buildNoSuggestion($result['reason']);
        self::postSuggestion($chat, $msg, $result['text'], $note);
    }

    // $suggestion with Send/Dismiss buttons, or $note (no suggestion) with Dismiss only
    public static function postSuggestion($chat, $msg, $suggestion, $note = '')
    {
        foreach (self::getOperatorTopics($chat) as $tchat) {

            new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            $topicContext = TelegramLiveHelperChatOperator::getTelegramTopicContextForChat($tchat);

            $data = array(
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id,
                'text' => $suggestion !== '' ? self::buildHeader($msg->msg) . "\n\n" . $suggestion : ($note !== '' ? $note : self::buildNoSuggestion('')),
                'disable_web_page_preview' => true,
                'reply_markup' => self::buildKeyboard($chat->id, $suggestion !== ''),
            );

            $replyId = TelegramLiveHelperChatOperator::getStoredTopicMessageId($msg, null, $topicContext);
            if ($replyId !== null) {
                $data['reply_to_message_id'] = (int)$replyId;
                $data['allow_sending_without_reply'] = true;
            }

            $result = \Longman\TelegramBot\Request::sendMessage($data);

            if (!$result->isOk()) {
                \erLhcoreClassLog::write('Suggestion sendMessage failed: ' . $result->getDescription(),
                    \ezcLog::SUCCESS_AUDIT,
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
    }

    public static function isTopicMessageUsed($chatId, $topicMsgId)
    {
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('SELECT id FROM lh_msg WHERE chat_id = :chat_id AND meta_msg != \'\' AND JSON_VALID(meta_msg) AND JSON_EXTRACT(meta_msg, \'$.tg_topic_msg_id\') = :topic_msg_id LIMIT 1');
        $stmt->bindValue(':chat_id', (int)$chatId, \PDO::PARAM_INT);
        $stmt->bindValue(':topic_msg_id', (int)$topicMsgId, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Inline button pressed under a suggestion. Returns the text for answerCallbackQuery.
     */
    public static function handleCallback($callbackQuery, $tBot, $action, $chatId, $msgId = 0)
    {
        $message = $callbackQuery->getMessage();
        $groupChatId = $message->getChat()->getId();
        $topicMsgId = (int)$message->getMessageId();

        // Any group member sees the buttons, only registered operators may use them
        $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $tBot->id, 'confirmed' => 1, 'tuser_id' => $callbackQuery->getFrom()->getId())));
        if (!($operator instanceof \erLhcoreClassModelTelegramOperator)) {
            return 'Оператор не найден';
        }

        if ($action === self::CALLBACK_GENERATE) {
            if (self::getSettings()['enabled'] === false) {
                return 'Подсказки выключены';
            }
            return self::enqueue((int)$chatId, (int)$msgId, true) ? 'Готовлю ответ…' : 'Фоновые задачи недоступны';
        }

        if ($action === self::CALLBACK_DISMISS) {
            $deleted = \Longman\TelegramBot\Request::deleteMessage(['chat_id' => $groupChatId, 'message_id' => $topicMsgId]);
            if (!$deleted->isOk()) {
                // Telegram does not let bots delete messages older than 48 hours
                \Longman\TelegramBot\Request::editMessageReplyMarkup(['chat_id' => $groupChatId, 'message_id' => $topicMsgId, 'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([])]);
            }
            return 'Подсказка скрыта';
        }

        $chat = \erLhcoreClassModelChat::fetch((int)$chatId);
        if (!($chat instanceof \erLhcoreClassModelChat) || (int)$chat->status === \erLhcoreClassModelChat::STATUS_CLOSED_CHAT) {
            \Longman\TelegramBot\Request::editMessageReplyMarkup(['chat_id' => $groupChatId, 'message_id' => $topicMsgId, 'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([])]);
            return 'Чат уже закрыт';
        }

        $text = self::extractSuggestion($message->getText());
        if ($text === '') {
            return 'Пустая подсказка';
        }

        $topicContext = array('bot_id' => (int)$tBot->id, 'group_chat_id' => (string)$tBot->group_chat_id);
        $lockNamespace = 'suggest_' . $topicContext['bot_id'] . '_' . $topicContext['group_chat_id'];

        // Double tap: the second press waits for the first one and then sees the message as used
        TelegramLiveHelperChatOperator::acquireTelegramMessageLock($topicMsgId, $lockNamespace);
        try {
            if (self::isTopicMessageUsed($chat->id, $topicMsgId)) {
                return 'Уже отправлено';
            }
            self::sendAsOperator($chat, $operator, $text, $topicMsgId, $topicContext);
        } finally {
            TelegramLiveHelperChatOperator::releaseTelegramMessageLock($topicMsgId, $lockNamespace);
        }

        \Longman\TelegramBot\Request::editMessageText([
            'chat_id' => $groupChatId,
            'message_id' => $topicMsgId,
            'text' => '✅ Отправлено (' . $operator->user->name_support . '):' . "\n\n" . $text,
            'disable_web_page_preview' => true,
            'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ]);

        return 'Отправлено';
    }

    /**
     * Posts the suggestion to the visitor as the operator's own message, the same way GenericmessageCommand does
     * for text typed into the topic. The topic message is mapped to the new message so it is not echoed back.
     */
    public static function sendAsOperator(\erLhcoreClassModelChat $chat, $operator, $text, $topicMsgId, $topicContext)
    {
        $topicNamespace = \erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace($topicContext['bot_id'], $topicContext['group_chat_id']);

        $metaMsg = array(
            'tg_topic_msg_id' => (int)$topicMsgId,
            'tg_topic_msg_contexts' => array(
                $topicNamespace => array(
                    'ids' => array((int)$topicMsgId),
                    'latest_id' => (int)$topicMsgId,
                    'bot_id' => $topicContext['bot_id'],
                    'group_chat_id' => $topicContext['group_chat_id'],
                    'map' => array((string)(int)$topicMsgId => array('text' => $text, 'kind' => 'text'))
                )
            )
        );

        $msg = new \erLhcoreClassModelmsg();
        $msg->msg = $text;
        $msg->meta_msg = json_encode($metaMsg);
        $msg->meta_msg_array = $metaMsg;
        $msg->chat_id = $chat->id;
        $msg->user_id = $operator->user_id;
        $msg->time = time();
        $msg->name_support = $operator->user->name_support;

        \erLhcoreClassChat::getSession()->save($msg);

        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('UPDATE lh_chat SET last_msg_id = :last_msg_id, last_op_msg_time = :last_op_msg_time, has_unread_op_messages = 1, unread_op_messages_informed = 0 WHERE id = :id');
        $stmt->bindValue(':id', $chat->id, \PDO::PARAM_INT);
        $stmt->bindValue(':last_msg_id', $msg->id, \PDO::PARAM_INT);
        $stmt->bindValue(':last_op_msg_time', time(), \PDO::PARAM_INT);
        $stmt->execute();

        $chat->last_msg_id = $msg->id;
        $chat->last_op_msg_time = time();
        $chat->has_unread_op_messages = 1;
        $chat->unread_op_messages_informed = 0;
        $chat->last_message = $msg;

        \erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.web_add_msg_admin', array(
            'chat' => & $chat,
            'msg' => & $msg
        ));

        \erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.nodjshelper_notify_delay', array(
            'chat' => & $chat
        ));

        return $msg;
    }
}
