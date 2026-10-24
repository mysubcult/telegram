<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/**
 * Operator corrections of the AI assistant from Telegram topics.
 *
 * "/fix what is wrong" in a chat topic (as a reply to the wrong message, or plain) or any text in the digest topic
 * stores a request in lhc_telegram_fix. A separate service (/root/smartdiag-fix/fixbot.py, runs as root because it
 * holds the AI key and the git access) picks it up, prepares an edit of the bot prompt or of a knowledge base article
 * and edits the "preparing…" message into the proposal with ✅ / ✖️. The buttons only change the status here;
 * the service applies an approved edit and reports the result in the same message.
 * "/fix ..." as a reply to a proposal asks for a revised proposal.
 *
 * Statuses: new → proposed → approved → applied | failed; proposed → rejected; new/proposed → revised (a child request).
 */
class TelegramFix
{
    const CALLBACK_APPLY = 'fxa';
    const CALLBACK_REJECT = 'fxr';
    const USAGE = 'Напишите после /fix, что не так, например: «/fix нельзя менять подписку на другую, только новая оплата». Лучше ответом на неверное сообщение.';

    public static function getSession()
    {
        return \erLhcoreClassExtensionLhctelegram::getSession();
    }

    public static function digestTopicId($bot)
    {
        $file = 'cache/telegram_digest_topic_' . (int)$bot->id . '.json';
        if (!is_file($file)) {
            return 0;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return (int)($data['thread_id'] ?? 0);
    }

    private static function send($bot, $data)
    {
        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        return \Longman\TelegramBot\Request::sendMessage($data + array('disable_web_page_preview' => true));
    }

    private static function findByStatusMessage($bot, $topicMsgId)
    {
        if ($topicMsgId <= 0) {
            return null;
        }
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('SELECT * FROM lhc_telegram_fix WHERE bot_id = :bot_id AND status_msg_id = :msg_id LIMIT 1');
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->bindValue(':msg_id', (int)$topicMsgId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * "/fix ..." in a topic or text in the digest topic. Returns the ServerResponse of the reply.
     */
    public static function handleRequest($message, $bot, $comment)
    {
        $groupChatId = $message->getChat()->getId();
        $threadId = (int)$message->getMessageThreadId();
        $comment = trim((string)$comment);

        $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $bot->id, 'confirmed' => 1, 'tuser_id' => $message->getFrom()->getId())));
        if (!($operator instanceof \erLhcoreClassModelTelegramOperator)) {
            return \Longman\TelegramBot\Request::emptyResponse();
        }

        if ($comment === '') {
            return self::send($bot, array('chat_id' => $groupChatId, 'message_thread_id' => $threadId, 'reply_to_message_id' => $message->getMessageId(), 'text' => self::USAGE));
        }

        $replyData = \erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
        $replyTopicMsgId = !empty($replyData['is_explicit_reply']) ? (int)$replyData['reply_message_id'] : 0;

        // A reply to a proposal asks to rework it
        $parent = self::findByStatusMessage($bot, $replyTopicMsgId);

        $chatId = 0;
        $tchat = \erLhcoreClassModelTelegramChat::findOne(array('filter' => array('bot_id' => $bot->id, 'tchat_id' => $threadId, 'type' => 1)));
        if ($tchat instanceof \erLhcoreClassModelTelegramChat) {
            $chatId = (int)$tchat->chat_id;
        }
        if ($parent !== null) {
            $chatId = (int)$parent['chat_id'];
        }

        $targetMsgId = 0;
        if ($chatId > 0 && $replyTopicMsgId > 0 && $parent === null) {
            $topicNamespace = \erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace($bot->id, $bot->group_chat_id);
            $target = TelegramSuggest::findMessageByTopicId($chatId, $replyTopicMsgId, $topicNamespace);
            $targetMsgId = $target instanceof \erLhcoreClassModelmsg ? (int)$target->id : 0;
        }

        $ack = self::send($bot, array(
            'chat_id' => $groupChatId,
            'message_thread_id' => $threadId,
            'reply_to_message_id' => $message->getMessageId(),
            'text' => $parent !== null ? '🛠 Переделываю правку с учётом комментария…' : '🛠 Готовлю правку, обычно это 1–3 минуты…',
        ));

        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('INSERT INTO lhc_telegram_fix (status, bot_id, group_chat_id, thread_id, chat_id, target_msg_id, request_msg_id, status_msg_id, parent_id, operator_user_id, operator_name, comment, proposal, result, ctime, utime) VALUES (\'new\', :bot_id, :group_chat_id, :thread_id, :chat_id, :target_msg_id, :request_msg_id, :status_msg_id, :parent_id, :operator_user_id, :operator_name, :comment, \'\', \'\', :ctime, :utime)');
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->bindValue(':group_chat_id', (string)$groupChatId, \PDO::PARAM_STR);
        $stmt->bindValue(':thread_id', $threadId, \PDO::PARAM_INT);
        $stmt->bindValue(':chat_id', $chatId, \PDO::PARAM_INT);
        $stmt->bindValue(':target_msg_id', $targetMsgId, \PDO::PARAM_INT);
        $stmt->bindValue(':request_msg_id', (int)$message->getMessageId(), \PDO::PARAM_INT);
        $stmt->bindValue(':status_msg_id', $ack->isOk() ? (int)$ack->getResult()->getMessageId() : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':parent_id', $parent !== null ? (int)$parent['id'] : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':operator_user_id', (int)$operator->user_id, \PDO::PARAM_INT);
        $stmt->bindValue(':operator_name', (string)$operator->user->name_support, \PDO::PARAM_STR);
        $stmt->bindValue(':comment', mb_substr($comment, 0, 4000), \PDO::PARAM_STR);
        $stmt->bindValue(':ctime', time(), \PDO::PARAM_INT);
        $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
        $stmt->execute();

        if ($parent !== null && $parent['status'] === 'proposed') {
            self::setStatus((int)$parent['id'], 'revised');
            self::clearButtons($bot, $groupChatId, (int)$parent['status_msg_id']);
        }

        return $ack;
    }

    public static function setStatus($id, $status)
    {
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('UPDATE lhc_telegram_fix SET status = :status, utime = :utime WHERE id = :id');
        $stmt->bindValue(':status', $status, \PDO::PARAM_STR);
        $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function clearButtons($bot, $groupChatId, $msgId)
    {
        if ($msgId > 0) {
            new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
            \Longman\TelegramBot\Request::editMessageReplyMarkup(array('chat_id' => $groupChatId, 'message_id' => $msgId, 'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([])));
        }
    }

    /**
     * ✅ / ✖️ under a proposal. Returns the text for answerCallbackQuery.
     */
    public static function handleCallback($callbackQuery, $bot, $action, $fixId)
    {
        $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $bot->id, 'confirmed' => 1, 'tuser_id' => $callbackQuery->getFrom()->getId())));
        if (!($operator instanceof \erLhcoreClassModelTelegramOperator)) {
            return 'Оператор не найден';
        }

        $db = \ezcDbInstance::get();
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT status FROM lhc_telegram_fix WHERE id = :id AND bot_id = :bot_id FOR UPDATE');
        $stmt->bindValue(':id', (int)$fixId, \PDO::PARAM_INT);
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->execute();
        $status = $stmt->fetchColumn();

        if ($status !== 'proposed') {
            $db->commit();
            return $status === false ? 'Правка не найдена' : 'Уже обработано';
        }

        self::setStatus($fixId, $action === self::CALLBACK_APPLY ? 'approved' : 'rejected');
        $db->commit();

        $message = $callbackQuery->getMessage();
        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => ($action === self::CALLBACK_APPLY ? '⏳ Применяю (' : '✖️ Отклонено (') . $operator->user->name_support . ")\n\n" . $message->getText(),
            'disable_web_page_preview' => true,
            'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ));

        return $action === self::CALLBACK_APPLY ? 'Применяю правку' : 'Правка отклонена';
    }
}
