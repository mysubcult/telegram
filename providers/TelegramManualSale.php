<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/**
 * "/paid 4900 Diagzone 1 год" in the operators group — a payment taken outside the site (bank transfer, SBP to a card).
 * Only a row in lhc_telegram_manual_sale and a confirmation in the topic: nothing reaches the visitor.
 * The sales summary (outside LHC) reads the rows; "/unpaid" (or a reply "/unpaid" to the confirmation) cancels one.
 */
class TelegramManualSale
{
    const TABLE = 'lhc_telegram_manual_sale';

    const USAGE = 'Отметка оплаты переводом/СБП вне сайта: «/paid 4900 Diagzone 1 год». Сначала сумма, потом что оплачено (программа, пакет, срок или товар). Можно добавить дату «09.11» и себестоимость «себ 3200». Отменить: /unpaid.';

    public static function isCommand($command)
    {
        return in_array(strtolower((string)$command), array('paid', 'unpaid'), true);
    }

    public static function handle($message, $bot, $command, $text)
    {
        $groupChatId = $message->getChat()->getId();
        if ((int)$bot->bot_client !== 1 || (string)$groupChatId !== (string)$bot->group_chat_id) {
            return \Longman\TelegramBot\Request::emptyResponse();
        }

        $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $bot->id, 'confirmed' => 1, 'tuser_id' => $message->getFrom()->getId())));
        if (!($operator instanceof \erLhcoreClassModelTelegramOperator)) {
            return \Longman\TelegramBot\Request::emptyResponse();
        }

        $threadId = (int)$message->getMessageThreadId();
        $reply = array('chat_id' => $groupChatId, 'reply_parameters' => array('message_id' => $message->getMessageId()));
        if ($threadId > 0) {
            $reply['message_thread_id'] = $threadId;
        }

        $db = \ezcDbInstance::get();

        if (strtolower($command) === 'unpaid') {
            $replyData = \erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
            $replyTo = !empty($replyData['is_explicit_reply']) ? (int)$replyData['reply_message_id'] : 0;
            if ($replyTo > 0) {
                $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE bot_id = :bot AND status = 1 AND confirm_msg_id = :msg ORDER BY id DESC LIMIT 1');
                $stmt->bindValue(':msg', $replyTo, \PDO::PARAM_INT);
            } else {
                $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE bot_id = :bot AND status = 1 AND tuser_id = :tuser ORDER BY id DESC LIMIT 1');
                $stmt->bindValue(':tuser', (int)$message->getFrom()->getId(), \PDO::PARAM_INT);
            }
            $stmt->bindValue(':bot', (int)$bot->id, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return self::send($bot, $reply + array('text' => 'Нет отметки оплаты, которую можно отменить.'));
            }
            $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET status = 0, utime = :time WHERE id = :id');
            $stmt->bindValue(':time', time(), \PDO::PARAM_INT);
            $stmt->bindValue(':id', (int)$row['id'], \PDO::PARAM_INT);
            $stmt->execute();
            return self::send($bot, $reply + array('text' => '↩️ Отметка отменена: ' . self::describe($row['amount'], $row['item']) . '. В сводку не войдёт.'));
        }

        $args = trim((string)preg_replace('/^\/\S+/u', '', trim((string)$text)));
        if (!preg_match('/^(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+)(?:[.,]\d{1,2})?\s*(?:₽|р\.?|руб\.?|rub)?(?:\s+(.*))?$/isu', $args, $m) || (int)preg_replace('/\D/', '', $m[1]) <= 0) {
            return self::send($bot, $reply + array('text' => self::USAGE));
        }
        $amount = (int)preg_replace('/\D/', '', $m[1]);
        $item = mb_substr(trim((string)($m[2] ?? '')), 0, 255);

        $chatId = 0;
        if ($threadId > 0) {
            $tchat = \erLhcoreClassModelTelegramChat::findOne(array('filter' => array('bot_id' => $bot->id, 'tchat_id' => $threadId, 'type' => 1)));
            if ($tchat instanceof \erLhcoreClassModelTelegramChat) {
                $chatId = (int)$tchat->chat_id;
            }
        }

        $stmt = $db->prepare('INSERT INTO ' . self::TABLE . ' (bot_id, chat_id, amount, item, tuser_id, user_id, group_chat_id, thread_id, status, ctime, utime) VALUES (:bot, :chat, :amount, :item, :tuser, :user, :gchat, :thread, 1, :time, :time)');
        $stmt->bindValue(':bot', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->bindValue(':chat', $chatId, \PDO::PARAM_INT);
        $stmt->bindValue(':amount', $amount, \PDO::PARAM_INT);
        $stmt->bindValue(':item', $item);
        $stmt->bindValue(':tuser', (int)$message->getFrom()->getId(), \PDO::PARAM_INT);
        $stmt->bindValue(':user', (int)$operator->user_id, \PDO::PARAM_INT);
        $stmt->bindValue(':gchat', (string)$groupChatId);
        $stmt->bindValue(':thread', $threadId, \PDO::PARAM_INT);
        $stmt->bindValue(':time', time(), \PDO::PARAM_INT);
        $stmt->execute();
        $id = (int)$db->lastInsertId();

        $hint = $item === '' ? "\nЧто оплачено, не указано — себестоимость не найдётся. Лучше отменить (/unpaid) и написать, например: «/paid {$amount} Diagzone 1 год»." : '';
        $response = self::send($bot, $reply + array('text' => '💰 Записал оплату вне сайта: ' . self::describe($amount, $item) . ($chatId > 0 ? ' (чат #' . $chatId . ')' : '') . '. Войдёт в сводку продаж — без эквайринга. Ошибся — /unpaid ответом на это сообщение.' . $hint));

        if ($response->isOk() && is_object($response->getResult())) {
            $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET confirm_msg_id = :msg WHERE id = :id');
            $stmt->bindValue(':msg', (int)$response->getResult()->getMessageId(), \PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return $response;
    }

    private static function describe($amount, $item)
    {
        return number_format((int)$amount, 0, ',', ' ') . ' ₽' . ($item !== '' ? ' — ' . $item : '');
    }

    private static function send($bot, $data)
    {
        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        return \Longman\TelegramBot\Request::sendMessage($data + array('link_preview_options' => array('is_disabled' => true), 'disable_notification' => true));
    }
}
