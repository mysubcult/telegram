<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

#[\AllowDynamicProperties]
class TelegramLiveHelperChatOperator {

    private static $lastTelegramSendResponses = array();
    private static $lastTelegramSendData = null;

    public static function registerListeners($dispatcher)
    {
        $dispatcher->listen('chat.delete', self::class . '::deleteChat');
        $dispatcher->listen('chat.close', self::class . '::closeChat');
        $dispatcher->listen('chat.chat_started', self::class . '::chatStarted');
        $dispatcher->listen('chat.web_add_msg_admin', self::class . '::messageAddedAdmin');
        $dispatcher->listen('chat.before_auto_responder_msg_saved', self::class . '::messageAddedResponder');
        $dispatcher->listen('chat.addmsguser', self::class . '::messageAdded');
        $dispatcher->listen('chat.messages_added_passive', self::class . '::messageAdded');
        $dispatcher->listen('chat.genericbot_get_trigger_click_processed', self::class . '::triggerClicked');
        $dispatcher->listen('onlineuser.pageview_logged', self::class . '::pageViewLogged');
    }

    public static function messageAddedAdmin($params)
    {
        if (isset($params['lhc_caller']['class']) && $params['lhc_caller']['class'] == 'Longman\\TelegramBot\\Commands\\SystemCommands\\GenericmessageCommand' && (!isset($params['always_process']) || $params['always_process'] === false)) {
            return;
        }

        // We want to by pass resque worker messages from rest_api
        if (isset($params['source']) && $params['source'] == 'webhook' && (!isset($params['sub_source']) || $params['sub_source'] != 'rest_api_worker')) {
            return;
        }

        self::messageAdded($params);
    }

    public static function messageAddedResponder($params)
    {
        if (isset($params['source']) && $params['source'] == 'webhook') {
            return;
        }

        $params['no_afterwards_messages'] = true;

        self::messageAdded($params);
    }

    public static function pageViewLogged($params)
    {
        if (($params['ou']->id > 0 && $params['ou']->chat_id > 0) !== true) {
            return;
        }

        if (!isset($params['url_changed']) || $params['url_changed'] === false) {
            return;
        }

        foreach (\erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['ou']->id > 0 ? ($params['ou']->id * -1) : $params['ou']->chat_id), 'type' => 1]]) as $tchat) {
            if ($tchat->bot->bot_client == 0 || $tchat->bot->notify_page_change == 0) {
                continue;
            }

            $chat = $params['ou']->chat;
            if (!is_object($chat)) {
                continue;
            }

            $telegram = new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);

            $sendData = \Longman\TelegramBot\Request::send('editForumTopic', [
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id,
                'name' => mb_substr('[' . $chat->department . '] ' . $chat->nick . ' #' . $chat->id . ($params['ou']->ip != '' ? ' | ' . $params['ou']->ip : '') . ($params['ou']->user_country_code != '' ? ' | ' . strtoupper($params['ou']->user_country_code) : '') . ($params['ou']->current_page != '' ? ' | '. ltrim($params['ou']->current_page,'/') : '') . ($params['ou']->page_title != '' ? ' | '.$params['ou']->page_title : ''),0,128)
            ]);

            if (!$sendData->isOk()) {
                \erLhcoreClassLog::write('editForumTopic ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                    \ezcLog::SUCCESS_AUDIT,
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

    public static function stripTelegramFileEmbeds($text)
    {
        return \erLhcoreClassExtensionLhctelegram::stripTelegramFileEmbedsText($text);
    }

    public static function getTelegramMessageFiles($msg)
    {
        $files = array();
        $seen = array();

        if (isset($msg->meta_msg_array['content']['attachements']) && is_array($msg->meta_msg_array['content']['attachements'])) {
            foreach ($msg->meta_msg_array['content']['attachements'] as $messageAttachment) {
                if (isset($messageAttachment['id']) && isset($messageAttachment['security_hash'])) {
                    self::appendTelegramMessageFile($files, $seen, $messageAttachment['id'], $messageAttachment['security_hash']);
                }
            }
        }

        if (preg_match_all('/\[file=(\d+)_([a-f0-9]{32})\]/i', (string)$msg->msg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                self::appendTelegramMessageFile($files, $seen, $match[1], $match[2]);
            }
        }

        return $files;
    }

    private static function appendTelegramMessageFile(& $files, & $seen, $id, $hash)
    {
        $id = (int)$id;
        $hash = (string)$hash;
        $key = $id . '_' . $hash;

        if (isset($seen[$key])) {
            return;
        }

        try {
            $file = \erLhcoreClassModelChatFile::fetch($id);
        } catch (\Exception $e) {
            return;
        }

        if (!($file instanceof \erLhcoreClassModelChatFile) || strtolower($file->security_hash) !== strtolower($hash)) {
            return;
        }

        $seen[$key] = true;
        $files[] = array(
            'file' => $file,
            'embed' => '[file=' . $file->id . '_' . $file->security_hash . ']'
        );
    }

    public static function getTelegramFileCaption($msg, $chat, $file, $messageText = null)
    {
        $sender = $msg->name_support != '' ? '🤖 [' . $msg->name_support . ']' : '👤 [' . $chat->nick . ']';
        $messageText = $messageText === null ? self::stripTelegramFileEmbeds($msg->msg) : trim((string)$messageText);

        if ($messageText !== '') {
            $caption = $sender . ': ' . $messageText;
        } elseif (strpos(strtolower((string)$file->type), 'image/') === 0) {
            $caption = $sender;
        } elseif (self::isMeaningfulTelegramUploadName($file)) {
            $caption = $sender . ': ' . $file->upload_name;
        } else {
            $caption = $sender;
        }

        return htmlspecialchars(mb_substr($caption, 0, 900), ENT_QUOTES, 'UTF-8');
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

    public static function getTelegramChatFileUrl($file)
    {
        $URLHash = '';

        if ($file->chat_id > 0) {
            $tsHash = time();
            $temporaryHash = sha1($file->id . '_' . $file->hash . '_' . $tsHash . '_' . \erConfigClassLhConfig::getInstance()->getSetting('site', 'secrethash'));
            $URLHash = "/(vhash)/{$temporaryHash}/(vts)/{$tsHash}";
        }

        return \erLhcoreClassSystem::getHost() . \erLhcoreClassDesign::baseurldirect('file/downloadfile') . "/{$file->id}/{$file->security_hash}{$URLHash}";
    }

    public static function sendTelegramChatFile($tchat, $fileData, $caption, $disableNotification = false, $params = array(), $msg = null, $topicContext = array())
    {
        self::$lastTelegramSendData = null;
        $file = is_object($fileData) ? $fileData : ($fileData['file'] ?? null);

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

        $multipartFilePath = '';
        $multipartFileField = '';
        $tempUploadDir = null;
        $tempUploadFile = null;
        $fileSize = is_file($file->file_path_server) ? filesize($file->file_path_server) : 0;

        try {
            $data = array(
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id,
                'parse_mode' => 'HTML'
            );

            // Telegram Bot API supports multipart file uploads up to 50 MB (52428800 bytes).
            // URL-based download limit on Telegram servers is restricted to 20 MB.
            if ($fileSize > 0 && $fileSize <= 52428800) {
                $originalFilename = !empty($file->upload_name) ? $file->upload_name : ($file->name . (!empty($file->extension) ? '.' . $file->extension : ''));
                if (!empty($file->extension) && !preg_match('/\\.' . preg_quote($file->extension, '/') . '$/i', $originalFilename)) {
                    $originalFilename .= '.' . $file->extension;
                }
                $cleanFilename = preg_replace('/[^\\w\\.\\-\\s\\(\\)\\[\\]]/u', '_', $originalFilename);
                if (empty($cleanFilename) || $cleanFilename === '.' . $file->extension) {
                    $cleanFilename = $file->name . (!empty($file->extension) ? '.' . $file->extension : '');
                }
                $tempUploadDir = sys_get_temp_dir() . '/lhc_tg_upload_' . uniqid('', true);
                if (@mkdir($tempUploadDir, 0755, true)) {
                    $tempUploadFile = $tempUploadDir . '/' . $cleanFilename;
                    if (@copy($file->file_path_server, $tempUploadFile)) {
                        $multipartFilePath = $tempUploadFile;
                        $multipartFileField = $field;
                    }
                }
                if (empty($multipartFilePath)) {
                    $multipartFilePath = $file->file_path_server;
                    $multipartFileField = $field;
                }
                $fileHandle = \Longman\TelegramBot\Request::encodeFile($multipartFilePath);
                if (is_resource($fileHandle)) {
                    $data[$field] = $fileHandle;
                } else {
                    $data[$field] = self::getTelegramChatFileUrl($file);
                }
            } else {
                $data[$field] = self::getTelegramChatFileUrl($file);
            }

            if (!empty($caption)) {
                $data['caption'] = $caption;
            }

            if ($disableNotification) {
                $data['disable_notification'] = true;
            }

            $replyTopicMsgId = is_array($params) ? ($params['reply_to_message_id'] ?? null) : $params;
            if ($replyTopicMsgId !== null && (int)$replyTopicMsgId > 0) {
                $data['reply_to_message_id'] = (int)$replyTopicMsgId;
            }

            $sendData = self::sendTelegramRequest($method, $data, $multipartFilePath, $multipartFileField);
            self::$lastTelegramSendData = $sendData;

            if ($sendData->isOk()) {
                $msgIds = self::getTelegramSendMessageIds($sendData);
                return !empty($msgIds) ? (int)$msgIds[0] : true;
            }

            \erLhcoreClassLog::write('SendFile ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                \ezcLog::SUCCESS_AUDIT,
                array(
                    'source' => 'lhc',
                    'category' => 'telegram_exception',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'object_id' => $tchat->id
                )
            );
        } catch (\Exception $e) {
            \erLhcoreClassLog::write('SendFile exception '.$e->getMessage(),
                \ezcLog::SUCCESS_AUDIT,
                array(
                    'source' => 'lhc',
                    'category' => 'telegram_exception',
                    'line' => __LINE__,
                    'file' => __FILE__,
                    'object_id' => $tchat->id
                )
            );
        } finally {
            if ($tempUploadFile && file_exists($tempUploadFile)) {
                @unlink($tempUploadFile);
            }
            if ($tempUploadDir && is_dir($tempUploadDir)) {
                @rmdir($tempUploadDir);
            }
        }

        return false;
    }

    public static function sendTelegramRequest($method, array $data, $multipartFilePath = '', $multipartFileField = '')
    {
        $multipartFilePath = (string)$multipartFilePath;
        $multipartFileField = (string)$multipartFileField;
        $allowMessageSplit = ($method === 'sendMessage' && $multipartFilePath === '' && $multipartFileField === '');
        self::$lastTelegramSendResponses = array();

        if ($multipartFilePath !== '' && $multipartFileField !== '' && (!isset($data[$multipartFileField]) || !is_resource($data[$multipartFileField])) && file_exists($multipartFilePath)) {
            $fileHandle = \Longman\TelegramBot\Request::encodeFile($multipartFilePath);
            if (is_resource($fileHandle)) {
                $data[$multipartFileField] = $fileHandle;
            }
        }

        try {
            $sendData = self::sendTelegramRequestOnce($method, $data, $allowMessageSplit);
        } catch (\Throwable $e) {
            self::closeTelegramResources($data);
            self::$lastTelegramSendResponses = array();
            \erLhcoreClassLog::write('Telegram request exception ' . $e->getMessage(), \ezcLog::SUCCESS_AUDIT, array('source' => 'lhc', 'category' => 'telegram_exception', 'line' => __LINE__, 'file' => __FILE__));
            return new \Longman\TelegramBot\Entities\ServerResponse(array('ok' => false, 'error_code' => 500, 'description' => 'Telegram request failed'));
        }

        if (!$allowMessageSplit && self::hasTelegramStaleReplyResponse($sendData) && isset($data['reply_to_message_id'])) {
            unset($data['reply_to_message_id']);
            try {
                if ($multipartFilePath !== '' && $multipartFileField !== '') {
                    if (isset($data[$multipartFileField]) && is_resource($data[$multipartFileField])) {
                        @fclose($data[$multipartFileField]);
                    }
                    $data[$multipartFileField] = \Longman\TelegramBot\Request::encodeFile($multipartFilePath);
                } else {
                    self::rewindTelegramResources($data);
                }
                $sendData = self::sendTelegramRequestOnce($method, $data, $allowMessageSplit);
            } catch (\Throwable $e) {
                self::closeTelegramResources($data);
                self::$lastTelegramSendResponses = array();
                \erLhcoreClassLog::write('Telegram reply fallback exception ' . $e->getMessage(), \ezcLog::SUCCESS_AUDIT, array('source' => 'lhc', 'category' => 'telegram_exception', 'line' => __LINE__, 'file' => __FILE__));
                return new \Longman\TelegramBot\Entities\ServerResponse(array('ok' => false, 'error_code' => 500, 'description' => 'Telegram reply fallback failed'));
            }
        }

        self::closeTelegramResources($data);
        return $sendData;
    }

    public static function sendTelegramRequestOnce($method, array &$data, $allowMessageSplit = false)
    {
        self::rewindTelegramResources($data);

        if ($method === 'sendMessage' && $allowMessageSplit) {
            return self::sendTelegramMessageWithSplit($data);
        }

        $sendData = \Longman\TelegramBot\Request::send($method, $data);
        self::$lastTelegramSendResponses = array($sendData);
        return $sendData;
    }

    public static function sendTelegramMessageWithSplit(array &$data, $msg = null, $tchat = null, $topicContext = array())
    {
        $responses = array();
        $lastResponse = null;

        foreach (self::getTelegramMessageChunks($data) as $chunkData) {
            $response = \Longman\TelegramBot\Request::sendMessage($chunkData);

            if (self::shouldRetryTelegramWithoutReply($response) && isset($chunkData['reply_to_message_id'])) {
                unset($chunkData['reply_to_message_id']);
                $response = \Longman\TelegramBot\Request::sendMessage($chunkData);
            }

            $responses[] = $response;
            $lastResponse = $response;

            if (!$response->isOk()) {
                break;
            }
        }

        self::$lastTelegramSendResponses = $responses;

        if ($msg !== null) {
            foreach ($responses as $response) {
                if ($response->isOk()) {
                    self::saveTelegramTopicMessageIds($msg, $response, array('text' => $data['text'] ?? '', 'kind' => 'text'), $topicContext);
                }
            }
        }

        return $lastResponse !== null ? $lastResponse : \Longman\TelegramBot\Request::sendMessage($data);
    }

    public static function getTelegramMessageChunks(array $data)
    {
        $text = isset($data['text']) ? (string)$data['text'] : '';
        $parseMode = isset($data['parse_mode']) ? strtoupper((string)$data['parse_mode']) : '';
        $limit = 4000;

        if ($parseMode === 'HTML') {
            $plainText = preg_replace('/^((?:🤖|👤)\\s*\\[[^\\]]+\\]:\\s*(?:<i>)?)(.*?)((?:<\\/i>)?)$/su', '$2', $text);
            $prefix = '';
            $suffix = '';
            if (preg_match('/^((?:🤖|👤)\\s*\\[[^\\]]+\\]:\\s*(?:<i>)?)(.*?)((?:<\\/i>)?)$/su', $text, $matches)) {
                $prefix = $matches[1];
                $suffix = $matches[3];
            }

            if (self::getTelegramTextLength(\erLhcoreClassExtensionLhctelegram::escapeTelegramHtmlText($plainText)) <= 4096) {
                return array($data);
            }

            $chunks = self::splitTelegramHtmlText($plainText, $limit);
            $result = array();
            foreach ($chunks as $index => $chunk) {
                $chunkData = $data;
                $chunkData['text'] = ($index === 0 ? $prefix : '') . \erLhcoreClassExtensionLhctelegram::escapeTelegramHtmlText($chunk) . ($index === count($chunks) - 1 ? $suffix : '');
                if ($index > 0) {
                    unset($chunkData['reply_to_message_id']);
                }
                $result[] = $chunkData;
            }
            return !empty($result) ? $result : array($data);
        }

        if (self::getTelegramTextLength($text) <= 4096) {
            return array($data);
        }

        $chunks = self::splitTelegramText($text, $limit);
        $result = array();
        foreach ($chunks as $index => $chunk) {
            $chunkData = $data;
            $chunkData['text'] = $chunk;
            if ($index > 0) {
                unset($chunkData['reply_to_message_id']);
            }
            $result[] = $chunkData;
        }

        return !empty($result) ? $result : array($data);
    }

    public static function splitTelegramHtmlText($text, $limit = 4000)
    {
        $chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? self::splitTelegramCharacters($chars, $limit, true) : array((string)$text);
    }

    public static function splitTelegramText($text, $limit = 4000)
    {
        $chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? self::splitTelegramCharacters($chars, $limit, false) : array((string)$text);
    }

    public static function splitTelegramCharacters(array $chars, $limit, $escape)
    {
        $chunks = array();
        $current = '';
        $currentLength = 0;

        foreach ($chars as $char) {
            $value = $escape ? \erLhcoreClassExtensionLhctelegram::escapeTelegramHtmlText($char) : $char;
            $charLength = self::getTelegramTextLength($value);

            if ($currentLength + $charLength > $limit && $current !== '') {
                $chunks[] = $current;
                $current = '';
                $currentLength = 0;
            }

            $current .= $char;
            $currentLength += $charLength;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return !empty($chunks) ? $chunks : array('');
    }

    public static function getTelegramTextLength($text)
    {
        $text = (string)$text;
        if (function_exists('mb_convert_encoding')) {
            return (int)(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
        }

        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    public static function getTelegramTextSlice($text, $offset, $length)
    {
        return function_exists('mb_substr')
            ? mb_substr((string)$text, (int)$offset, (int)$length, 'UTF-8')
            : substr((string)$text, (int)$offset, (int)$length);
    }

    public static function rewindTelegramResources(array &$data)
    {
        foreach ($data as &$value) {
            if (is_resource($value)) {
                @rewind($value);
            }
        }
        unset($value);
    }

    public static function closeTelegramResources(array &$data)
    {
        foreach ($data as &$value) {
            if (is_resource($value)) {
                @fclose($value);
            }
        }
        unset($value);
    }

    public static function shouldRetryTelegramWithoutReply($sendData)
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

    public static function isTelegramTopicUnavailable($sendData)
    {
        if (!is_object($sendData) || $sendData->isOk() || (int)$sendData->getErrorCode() !== 400) {
            return false;
        }

        $description = strtolower((string)$sendData->getDescription());
        return strpos($description, 'message thread not found') !== false
            || strpos($description, 'topic_deleted') !== false
            || strpos($description, 'thread not found') !== false;
    }

    public static function hasTelegramStaleReplyResponse($sendData)
    {
        if (self::shouldRetryTelegramWithoutReply($sendData)) {
            return true;
        }

        foreach (self::$lastTelegramSendResponses as $response) {
            if (self::shouldRetryTelegramWithoutReply($response)) {
                return true;
            }
        }

        return false;
    }

    public static function getTelegramSendMessageIds($sendData)
    {
        $responses = (!empty(self::$lastTelegramSendResponses) && in_array($sendData, self::$lastTelegramSendResponses, true))
            ? self::$lastTelegramSendResponses
            : array($sendData);
        $messageIds = array();

        foreach ($responses as $response) {
            if (is_object($response) && method_exists($response, 'isOk') && $response->isOk()) {
                $result = is_callable(array($response, 'getResult')) ? $response->getResult() : (method_exists($response, 'getProperty') ? $response->getProperty('result') : null);
                $msgId = 0;
                if (is_object($result)) {
                    if (is_callable(array($result, 'getMessageId'))) {
                        $msgId = (int)$result->getMessageId();
                    } elseif (method_exists($result, 'getProperty')) {
                        $msgId = (int)$result->getProperty('message_id');
                    }
                } elseif (is_array($result) && isset($result['message_id'])) {
                    $msgId = (int)$result['message_id'];
                }
                if ($msgId > 0) {
                    $messageIds[] = $msgId;
                }
            }
        }

        return array_values(array_unique($messageIds));
    }

    public static function saveTelegramTopicMessageIds($msg, $sendData, $messageData = array(), $topicContext = array())
    {
        foreach (self::getTelegramSendMessageIds($sendData) as $topicMsgId) {
            self::saveTopicMsgId($msg, $topicMsgId, $messageData, $topicContext);
        }
    }

    public static function saveTopicMsgId($msg, $topicMsgId, $messageData = array(), $topicContext = array())
    {
        if (!($msg instanceof \erLhcoreClassModelmsg) || !(int)$topicMsgId || $msg->id <= 0) {
            return;
        }

        $db = \ezcDbInstance::get();
        $startedTransaction = method_exists($db, 'inTransaction') && !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $select = $db->prepare('SELECT meta_msg FROM lh_msg WHERE id = :id FOR UPDATE');
            $select->bindValue(':id', (int)$msg->id, \PDO::PARAM_INT);
            $select->execute();
            $row = $select->fetch(\PDO::FETCH_ASSOC);

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
            if ($namespace !== null) {
                if (!isset($meta['tg_topic_msg_contexts']) || !is_array($meta['tg_topic_msg_contexts'])) {
                    $meta['tg_topic_msg_contexts'] = array();
                }
                if (!isset($meta['tg_topic_msg_contexts'][$namespace]) || !is_array($meta['tg_topic_msg_contexts'][$namespace])) {
                    $meta['tg_topic_msg_contexts'][$namespace] = array(
                        'ids' => array(),
                        'latest_id' => 0,
                        'bot_id' => $topicContext['bot_id'] ?? 0,
                        'group_chat_id' => $topicContext['group_chat_id'] ?? '',
                        'map' => array()
                    );
                }
                $context = &$meta['tg_topic_msg_contexts'][$namespace];
                $contextIds = isset($context['ids']) && is_array($context['ids']) ? array_map('intval', $context['ids']) : array();
                $contextIds[] = (int)$topicMsgId;
                $context['ids'] = array_values(array_unique(array_filter($contextIds, function ($id) { return (int)$id > 0; })));
                $context['latest_id'] = (int)$topicMsgId;
                if (!isset($context['map']) || !is_array($context['map'])) {
                    $context['map'] = array();
                }
                if (!isset($context['map'][$mapKey]) || !is_array($context['map'][$mapKey])) {
                    $context['map'][$mapKey] = array();
                }
                if (!empty($entry)) {
                    $context['map'][$mapKey] = array_merge($context['map'][$mapKey], $entry);
                }
                unset($context);
            }

            $encoded = json_encode($meta);
            $msg->meta_msg = $encoded;
            $msg->meta_msg_array = $meta;

            $update = $db->prepare('UPDATE lh_msg SET meta_msg = :meta_msg WHERE id = :id');
            $update->bindValue(':meta_msg', $encoded, \PDO::PARAM_STR);
            $update->bindValue(':id', (int)$msg->id, \PDO::PARAM_INT);
            $update->execute();

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction) {
                $db->rollback();
            }
        }
    }

    public static function saveTelegramFileTopicMsgId($msg, $topicMsgId, $telegramFile, $caption = '', $topicContext = array())
    {
        if (!($msg instanceof \erLhcoreClassModelmsg) || !(int)$topicMsgId) {
            return;
        }

        $file = is_array($telegramFile) ? ($telegramFile['file'] ?? null) : null;
        $embed = is_array($telegramFile) ? ($telegramFile['embed'] ?? '') : '';

        self::saveTopicMsgId($msg, $topicMsgId, array(
            'caption' => (string)$caption,
            'embed' => (string)$embed,
            'file_id' => is_object($file) ? (int)$file->id : 0,
            'security_hash' => is_object($file) ? (string)$file->security_hash : '',
            'kind' => 'file'
        ), $topicContext);
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

    public static function getTelegramTopicNamespaceFromContext($topicContext)
    {
        if (!is_array($topicContext)) {
            return '';
        }

        $botId = isset($topicContext['bot_id']) ? (int)$topicContext['bot_id'] : 0;
        $groupChatId = isset($topicContext['group_chat_id']) ? (string)$topicContext['group_chat_id'] : '';

        return \erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace($botId, $groupChatId);
    }

    public static function getStoredTopicMessageId($msg, $preferredId = null, $topicContext = array())
    {
        if (!($msg instanceof \erLhcoreClassModelmsg)) {
            return null;
        }

        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : array();
        if (empty($meta) && isset($msg->meta_msg) && is_string($msg->meta_msg) && $msg->meta_msg !== '') {
            $decoded = json_decode($msg->meta_msg, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $namespace = self::getTelegramTopicNamespaceFromContext($topicContext);
        if ($namespace !== null && isset($meta['tg_topic_msg_contexts'][$namespace]) && is_array($meta['tg_topic_msg_contexts'][$namespace])) {
            $context = $meta['tg_topic_msg_contexts'][$namespace];
            $contextIds = isset($context['ids']) && is_array($context['ids']) ? array_filter(array_map('intval', $context['ids'])) : array();
            $knownContextIds = array_fill_keys($contextIds, true);

            if ($preferredId !== null && isset($knownContextIds[(int)$preferredId])) {
                return (int)$preferredId;
            }
            if (isset($context['latest_id']) && (int)$context['latest_id'] > 0) {
                return (int)$context['latest_id'];
            }
            if (!empty($contextIds)) {
                return (int)end($contextIds);
            }
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

    public static function formatTelegramMessageText($messageText)
    {
        $formatted = \erLhcoreClassBBCodePlain::make_clickable($messageText, array('sender' => 0));
        return preg_replace('#\[quote(?:="?[^"\]]*"?)?\](.*?)\[/quote\]#is', '<blockquote>$1</blockquote>', $formatted);
    }

    public static function getTopicReplyId($msg, $chatId, $topicContext = array())
    {
        if (!($msg instanceof \erLhcoreClassModelmsg)) {
            return null;
        }

        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : array();
        if (empty($meta) && !empty($msg->meta_msg)) {
            $decoded = json_decode($msg->meta_msg, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (isset($meta['content']['reply_to']['db_msg_id']) && (int)$meta['content']['reply_to']['db_msg_id'] > 0) {
            $targetMsg = \erLhcoreClassModelmsg::fetch((int)$meta['content']['reply_to']['db_msg_id']);
            if ($targetMsg instanceof \erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $preferredId = $meta['content']['reply_to']['telegram_message_id'] ?? ($meta['content']['reply_to']['tg_topic_msg_id'] ?? null);
                $resolvedId = self::getStoredTopicMessageId($targetMsg, $preferredId, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        if (isset($meta['content']['reply_to']['telegram_message_id']) && (int)$meta['content']['reply_to']['telegram_message_id'] > 0) {
            return (int)$meta['content']['reply_to']['telegram_message_id'];
        }

        if (isset($meta['content']['reply_to']['tg_topic_msg_id']) && (int)$meta['content']['reply_to']['tg_topic_msg_id'] > 0) {
            return (int)$meta['content']['reply_to']['tg_topic_msg_id'];
        }

        if (isset($meta['content']['reply_to']['iwh_msg_id']) && $meta['content']['reply_to']['iwh_msg_id'] != '') {
            $iwhId = (string)$meta['content']['reply_to']['iwh_msg_id'];
            $targetMsg = \erLhcoreClassModelmsg::findOne([
                'filter' => ['chat_id' => $chatId],
                'customfilter' => ["`meta_msg` != '' AND JSON_VALID(`meta_msg`) AND (JSON_UNQUOTE(JSON_EXTRACT(meta_msg,'$.iwh_msg_id')) = " . \ezcDbInstance::get()->quote($iwhId) . " OR JSON_EXTRACT(meta_msg,'$.iwh_msg_id') = " . (is_numeric($iwhId) ? (int)$iwhId : \ezcDbInstance::get()->quote($iwhId)) . ")"]
            ]);
            if ($targetMsg instanceof \erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $resolvedId = self::getStoredTopicMessageId($targetMsg, null, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        if (isset($meta['content']['quote']['id']) && (int)$meta['content']['quote']['id'] > 0) {
            $targetMsg = \erLhcoreClassModelmsg::fetch((int)$meta['content']['quote']['id']);
            if ($targetMsg instanceof \erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $resolvedId = self::getStoredTopicMessageId($targetMsg, null, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        if (preg_match('#\[quote="?([0-9]+)"?\]#is', (string)$msg->msg, $m)) {
            $targetMsg = \erLhcoreClassModelmsg::fetch((int)$m[1]);
            if ($targetMsg instanceof \erLhcoreClassModelmsg && (int)$targetMsg->chat_id === (int)$chatId) {
                $resolvedId = self::getStoredTopicMessageId($targetMsg, null, $topicContext);
                if ($resolvedId !== null) {
                    return $resolvedId;
                }
            }
        }

        return null;
    }

    public static function getTopicMessageId($msg, $chatId, $topicContext = array())
    {
        if (!($msg instanceof \erLhcoreClassModelmsg) || (int)$msg->chat_id !== (int)$chatId) {
            return null;
        }

        return self::getStoredTopicMessageId($msg, null, $topicContext);
    }

    public static function messageAdded($params)
    {
        $chat = $params['chat'];
        $db = \ezcDbInstance::get();

        foreach (\erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {

            if ((int)$tchat->chat_id !== (int)$params['chat']->id) {
                $tchat->chat_id = (int)$params['chat']->id;
                $tchat->utime = time();
                $tchat->updateThis(['update' => ['chat_id', 'utime']]);
            }


            $db->beginTransaction();
            $tchat->syncAndLock('`last_msg_id`');
            $db->commit();

            if ($tchat->bot->bot_client == 0) {
                continue;
            }

            $telegram = new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            $topicContext = self::getTelegramTopicContextForChat($tchat);

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

                $telegramFiles = self::getTelegramMessageFiles($params['msg']);
                $messageText = self::stripTelegramFileEmbeds($params['msg']->msg);

                $sendData = null;

                if ($messageText !== '' && empty($telegramFiles)) {
                    $data = [
                        'chat_id' => $tchat->bot->group_chat_id,
                        'message_thread_id' => $tchat->tchat_id,
                        'parse_mode' => 'HTML',
                        'text' => trim(($params['msg']->name_support != '' ? '🤖 [' . $params['msg']->name_support . ']: <i>' : '👤 [' . \erLhcoreClassBBCodePlain::make_clickable($chat->nick, array('sender' => 0)) . ']: ') . self::formatTelegramMessageText($messageText) . ($params['msg']->name_support != '' ? '</i>' : ''))
                    ];

                    if ($chat->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }

                    $replyTopicMsgId = self::getTopicReplyId($params['msg'], $chat->id, $topicContext);
                    if ($replyTopicMsgId !== null && (int)$replyTopicMsgId > 0) {
                        $data['reply_to_message_id'] = (int)$replyTopicMsgId;
                    }

                    $sendData = self::sendTelegramMessageWithSplit($data, $params['msg'], $tchat, $topicContext);

                    if (!$sendData->isOk() && $sendData->getErrorCode() == 400 && str_contains($sendData->getDescription(), 'TOPIC_DELETED') === true) {
                        // Reset telegram chat
                        $tchat->tchat_id = 0;
                        $tchat->updateThis(['update' => ['tchat_id']]);

                        // Process request as a new chat just
                        self::chatStarted(['chat' => $chat]);
                        return;
                    }

                    if (!$sendData->isOk()) {
                        \erLhcoreClassLog::write('sendMessagesss ['.$sendData->getErrorCode().']'. $sendData->getDescription(),
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

                if (!empty($telegramFiles) && ($sendData === null || $sendData->isOk())) {
                    $failedEmbedCodes = array();
                    $fileIndex = 0;

                    foreach ($telegramFiles as $telegramFile) {
                        $fileReplyTopicMsgId = self::getTopicReplyId($params['msg'], $chat->id, $topicContext);
                        $fileCaption = self::getTelegramFileCaption($params['msg'], $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : '');
                        $fileDisableNotification = $chat->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT;
                        $sendFileResult = self::sendTelegramChatFile(
                            $tchat,
                            $telegramFile,
                            $fileCaption,
                            $fileDisableNotification,
                            $fileReplyTopicMsgId,
                            $params['msg'],
                            $topicContext
                        );
                        if ($sendFileResult === false) {
                            $failedEmbedCodes[] = $telegramFile['embed'];
                        } else {
                            self::saveTelegramFileTopicMsgId($params['msg'], $sendFileResult, $telegramFile, $fileCaption, $topicContext);
                        }
                        $fileIndex++;
                    }

                    if (!empty($failedEmbedCodes)) {
                        self::sendTelegramRequest('sendMessage', array(
                            'chat_id' => $tchat->bot->group_chat_id,
                            'message_thread_id' => $tchat->tchat_id,
                            'parse_mode' => 'HTML',
                            'text' => trim(($params['msg']->name_support != '' ? '🤖 [' . $params['msg']->name_support . ']: <i>' : '👤 [' . \erLhcoreClassBBCodePlain::make_clickable($chat->nick, array('sender' => 0)) . ']: ') . implode(' ', $failedEmbedCodes) . ($params['msg']->name_support != '' ? '</i>' : ''))
                        ));
                    }
                }
            }
        }
    }

    public static function triggerClicked($params)
    {
        $chat = $params['chat'];

        foreach (\erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {

            if ($tchat->bot->bot_client == 0) {
                continue;
            }

            $telegram = new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            $topicContext = self::getTelegramTopicContextForChat($tchat);

            $botMessages = \erLhcoreClassModelmsg::getList(array('filterin' => ['user_id' => [0, -2]], 'filter' => array('chat_id' => $chat->id), 'filtergt' => array('id' => $params['last_msg_id'])));
            foreach ($botMessages as $botMessage) {

                $tchat->last_msg_id = $botMessage->id;
                $tchat->updateThis(['update' => ['last_msg_id']]);

                $telegramFiles = self::getTelegramMessageFiles($botMessage);
                $messageText = self::stripTelegramFileEmbeds($botMessage->msg);
                $botReplyTopicMsgId = self::getTopicMessageId($params['msg'], $chat->id, $topicContext);

                if ($messageText !== '' && empty($telegramFiles)) {
                    $data = [
                        'chat_id' => $tchat->bot->group_chat_id,
                        'message_thread_id' => $tchat->tchat_id,
                        'parse_mode' => 'HTML',
                        'text' => trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤: ') . \erLhcoreClassBBCodePlain::make_clickable($messageText, array('sender' => 0)) . ($botMessage->name_support != '' ? '</i>' : ''))
                    ];
                    if ($chat->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }
                    if ($botReplyTopicMsgId > 0) {
                        $data['reply_to_message_id'] = $botReplyTopicMsgId;
                    }
                    $sendData = self::sendTelegramRequest('sendMessage', $data);
                    self::saveTelegramTopicMessageIds($botMessage, $sendData, array('text' => $messageText, 'kind' => 'text'), $topicContext);

                    if (!$sendData->isOk()) {
                        \erLhcoreClassLog::write('['.$sendData->getErrorCode().']'. $sendData->getDescription(),
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

                if (!empty($telegramFiles)) {
                    $failedEmbedCodes = array();
                    $fileIndex = 0;

                    foreach ($telegramFiles as $telegramFile) {
                        $sentFileMsgId = self::sendTelegramChatFile(
                            $tchat,
                            $telegramFile,
                            self::getTelegramFileCaption($botMessage, $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''),
                            $chat->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT,
                            $botReplyTopicMsgId,
                            $botMessage,
                            $topicContext
                        );
                        if ($sentFileMsgId === false) {
                            $failedEmbedCodes[] = $telegramFile['embed'];
                        } else {
                            self::saveTelegramFileTopicMsgId($botMessage, $sentFileMsgId, $telegramFile, self::getTelegramFileCaption($botMessage, $chat, $telegramFile['file'], $fileIndex === 0 ? $messageText : ''), $topicContext);
                        }
                        $fileIndex++;
                    }

                    if (!empty($failedEmbedCodes)) {
                        self::sendTelegramRequest('sendMessage', array(
                            'chat_id' => $tchat->bot->group_chat_id,
                            'message_thread_id' => $tchat->tchat_id,
                            'parse_mode' => 'HTML',
                            'text' => trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤: ') . implode(' ', $failedEmbedCodes) . ($botMessage->name_support != '' ? '</i>' : ''))
                        ));
                    }
                }
            }
        }
    }

    public static function chatStarted($params)
    {
        $db = \ezcDbInstance::get();

        foreach (\erLhcoreClassModelTelegramBotDep::getList(array('filter' => array('dep_id' => $params['chat']->dep_id))) as $bot) {
            if ($bot->bot->bot_client == 1) {

                $db->beginTransaction();

                $chatInternal = $params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id;

                $stmt = $db->prepare('SELECT id, tchat_id, last_msg_id FROM lhc_telegram_chat WHERE bot_id = :bot_id AND chat_id_internal = :chat_id_internal AND type = 1 FOR UPDATE;');
                $stmt->bindValue(':bot_id', $bot->bot->id, \PDO::PARAM_INT);
                $stmt->bindValue(':chat_id_internal', $chatInternal, \PDO::PARAM_INT);
                $stmt->execute();
                $tChatData = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (is_array($tChatData)) {
                    $tChat = \erLhcoreClassModelTelegramChat::fetch($tChatData['id']);
                    $tChat->chat_id = $params['chat']->id;
                    $tChat->utime = time();
                    $tChat->updateThis(['update' => ['chat_id', 'utime']]);
                } else {
                    $tChat = new \erLhcoreClassModelTelegramChat();
                    $tChat->bot_id = $bot->bot->id;
                    $tChat->chat_id_internal = $chatInternal;
                    $tChat->chat_id = $params['chat']->id;
                    $tChat->type = 1;
                    $tChat->ctime = time();
                    $tChat->utime = time();
                    $tChat->saveThis();
                }
                $db->commit();

                try {
                    $onlineUser = $params['chat']->online_user;

                    if ($params['chat']->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT && $bot->bot->exclude_bot_chats == 1) {
                        continue;
                    }

                    $telegram = new \Longman\TelegramBot\Telegram($bot->bot->bot_api, $bot->bot->bot_username);
                    $topicContext = array(
                        'bot_id' => (int)$bot->bot->id,
                        'group_chat_id' => (string)$bot->bot->group_chat_id
                    );

                    $topicTitle = mb_substr('[' . $params['chat']->department . '] ' . $params['chat']->nick . ' #' . $params['chat']->id . ($params['chat']->ip != '' ? ' | ' . $params['chat']->ip : '') . ($params['chat']->country_code != '' ? ' | ' . strtoupper($params['chat']->country_code) : '') . ($params['chat']->referrer != '' ? ' | ' . ltrim($params['chat']->referrer, '/') : '') . (is_object($params['chat']->online_user) && $params['chat']->online_user->page_title != '' ? ' | ' . $params['chat']->online_user->page_title : ''), 0, 128);

                    if ($tChat->tchat_id == null || $tChat->tchat_id == 0) {
                        $sendData = \Longman\TelegramBot\Request::send('createForumTopic', [
                            'chat_id' => $bot->bot->group_chat_id,
                            'name' => $topicTitle
                        ]);

                        if ($sendData->isOk()) {
                            $tChat->tchat_id = $sendData->getResult()->getMessageThreadId();
                            $tChat->updateThis(['update' => ['tchat_id']]);
                        } else {
                            \erLhcoreClassLog::write($sendData->getDescription(),
                                \ezcLog::SUCCESS_AUDIT,
                                array(
                                    'source' => 'lhc',
                                    'category' => 'telegram_exception',
                                    'line' => __LINE__,
                                    'file' => __FILE__,
                                    'object_id' => $params['chat']->id
                                )
                            );
                            return;
                        }
                    }

                    $previousChatMessages = '';

                    if ($bot->bot->delete_on_close == 1 && $params['chat']->online_user_id > 0 && is_object($params['chat']->online_user) && is_object($params['chat']->online_user->previous_chat)) {
                        $previousChatMessagesList = [];
                        foreach (array_reverse(\erLhcoreClassModelmsg::getList(array('limit' => 15, 'sort' => 'id DESC', 'filternotin' => ['user_id' => [-1]], 'filter' => array('chat_id' => $params['chat']->online_user->previous_chat->id)))) as $botMessage) {
                            if (empty($botMessage->msg)) {
                                continue;
                            }
                            $previousChatMessagesList[] = trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤 ['. \erLhcoreClassBBCodePlain::make_clickable($params['chat']->nick, array('sender' => 0)) . ']: ') . self::formatTelegramMessageText($botMessage->msg) . ($botMessage->name_support != '' ? '</i>' : ''));
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
                    $botMessages = \erLhcoreClassModelmsg::getList(array('filterin' => ['user_id' => [0, -2]], 'filter' => array('chat_id' => $params['chat']->id)));
                    foreach ($botMessages as $botMessage) {
                        $tChat->last_msg_id = $botMessage->id;
                        $tChat->updateThis(['update' => ['last_msg_id']]);

                        $telegramFiles = self::getTelegramMessageFiles($botMessage);
                        $messageText = self::stripTelegramFileEmbeds($botMessage->msg);

                        if ($messageText !== '' && empty($telegramFiles)) {
                            $visitor[] = trim(($botMessage->name_support != '' ? '🤖 [' . $botMessage->name_support . ']: <i>' : '👤 ['. \erLhcoreClassBBCodePlain::make_clickable($params['chat']->nick, array('sender' => 0)) . ']: ') . self::formatTelegramMessageText($messageText) . ($botMessage->name_support != '' ? '</i>' : ''));
                            $initialAggregateMessages[] = array('msg' => $botMessage, 'text' => $messageText);
                        }

                        $fileIndex = 0;
                        foreach ($telegramFiles as $telegramFile) {
                            $initialTelegramFiles[] = array(
                                'file' => $telegramFile,
                                'text' => $fileIndex === 0 ? $messageText : '',
                                'msg' => $botMessage
                            );
                            $fileIndex++;
                        }
                    }

                    $data = [
                        'chat_id' => $tChat->bot->group_chat_id,
                        'message_thread_id' => $tChat->tchat_id,
                        'parse_mode' => 'HTML',
                        'text' => implode("\n", $visitor)
                    ];

                    if ($params['chat']->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT) {
                        $data['disable_notification'] = true;
                    }

                    $sendData = self::sendTelegramRequest('sendMessage', $data);

                    if ($sendData->isOk()) {
                        $aggregateMsgId = $sendData->getResult()->getMessageId();
                        foreach ($initialAggregateMessages as $aggregateMessage) {
                            self::saveTelegramTopicMessageIds($aggregateMessage['msg'], $sendData, array('text' => $aggregateMessage['text'], 'kind' => 'aggregate'), $topicContext);
                        }
                        if (empty($initialAggregateMessages)) {
                            $firstMsg = \erLhcoreClassModelmsg::findOne(['filter' => ['chat_id' => $params['chat']->id], 'sort' => 'id ASC']);
                            if ($firstMsg instanceof \erLhcoreClassModelmsg) {
                                self::saveTelegramTopicMessageIds($firstMsg, $sendData, array('text' => $data['text'], 'kind' => 'aggregate'), $topicContext);
                            }
                        }
                    } else {

                        // Try first time to create a topic if old one is gone
                        if ($sendData->getErrorCode() == 400 && (str_contains($sendData->getDescription(), 'message thread not found') || str_contains($sendData->getDescription(), 'TOPIC_DELETED'))) {
                            $sendData = \Longman\TelegramBot\Request::send('createForumTopic', [
                                'chat_id' => $bot->bot->group_chat_id,
                                'name' => $topicTitle
                            ]);

                            if ($sendData->isOk()) {
                                $tChat->tchat_id = $sendData->getResult()->getMessageThreadId();
                                $tChat->updateThis(['update' => ['tchat_id']]);
                            } else {
                                return;
                            }
                        }

                        $data['message_thread_id'] = $tChat->tchat_id;
                        $sendData = self::sendTelegramRequest('sendMessage', $data);

                        if ($sendData->isOk()) {
                            $aggregateMsgId = $sendData->getResult()->getMessageId();
                            foreach ($initialAggregateMessages as $aggregateMessage) {
                                self::saveTelegramTopicMessageIds($aggregateMessage['msg'], $sendData, array('text' => $aggregateMessage['text'], 'kind' => 'aggregate'), $topicContext);
                            }
                            if (empty($initialAggregateMessages)) {
                                $firstMsg = \erLhcoreClassModelmsg::findOne(['filter' => ['chat_id' => $params['chat']->id], 'sort' => 'id ASC']);
                                if ($firstMsg instanceof \erLhcoreClassModelmsg) {
                                    self::saveTelegramTopicMessageIds($firstMsg, $sendData, array('text' => $data['text'], 'kind' => 'aggregate'), $topicContext);
                                }
                            }
                        } else {
                            \erLhcoreClassLog::write('['.$sendData->getErrorCode().']'. $sendData->getDescription(),
                                \ezcLog::SUCCESS_AUDIT,
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

                    if (!empty($initialTelegramFiles)) {
                        $failedEmbedCodes = array();

                        foreach ($initialTelegramFiles as $initialTelegramFile) {
                            $sentFileMsgId = self::sendTelegramChatFile(
                                $tChat,
                                $initialTelegramFile['file'],
                                self::getTelegramFileCaption($initialTelegramFile['msg'], $params['chat'], $initialTelegramFile['file']['file'], $initialTelegramFile['text']),
                                $params['chat']->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT,
                                array(),
                                $initialTelegramFile['msg'] ?? null,
                                $topicContext
                            );
                            if ($sentFileMsgId === false) {
                                $failedEmbedCodes[] = $initialTelegramFile['file']['embed'];
                            } else if (isset($initialTelegramFile['msg']) && $initialTelegramFile['msg'] instanceof \erLhcoreClassModelmsg) {
                                self::saveTelegramFileTopicMsgId($initialTelegramFile['msg'], $sentFileMsgId, $initialTelegramFile['file'], self::getTelegramFileCaption($initialTelegramFile['msg'], $params['chat'], $initialTelegramFile['file']['file'], $initialTelegramFile['text']), $topicContext);
                            }
                        }

                        if (!empty($failedEmbedCodes)) {
                            self::sendTelegramRequest('sendMessage', array(
                                'chat_id' => $tChat->bot->group_chat_id,
                                'message_thread_id' => $tChat->tchat_id,
                                'parse_mode' => 'HTML',
                                'text' => trim('👤 ['. \erLhcoreClassBBCodePlain::make_clickable($params['chat']->nick, array('sender' => 0)) . ']: ' . implode(' ', $failedEmbedCodes))
                            ));
                        }
                    }
                } catch (\Exception $e) {
                    \erLhcoreClassLog::write($e->getMessage(),
                        \ezcLog::SUCCESS_AUDIT,
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

    public static function deleteChat($params)
    {
        foreach (\erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {
            $telegram = new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            \Longman\TelegramBot\Request::send('deleteForumTopic', [
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id
            ]);
            $tchat->removeThis();
        }
    }

    public static function closeChat($params)
    {
        foreach (\erLhcoreClassModelTelegramChat::getList(['filter' => ['chat_id_internal' => ($params['chat']->online_user_id > 0 ? ($params['chat']->online_user_id * -1) : $params['chat']->id), 'type' => 1]]) as $tchat) {
            $telegram = new \Longman\TelegramBot\Telegram($tchat->bot->bot_api, $tchat->bot->bot_username);
            \Longman\TelegramBot\Request::send('closeForumTopic', [
                'chat_id' => $tchat->bot->group_chat_id,
                'message_thread_id' => $tchat->tchat_id
            ]);
        }
    }
}
