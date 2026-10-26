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
 * "/fix ..." or plain text as a reply to a proposal asks for a revised proposal (the text never goes to the visitor);
 * the "✏️ Дополнить" button under a proposal only explains this.
 * "/fix" without text in the digest topic, or the button under the digest, lists the proposals waiting for a decision,
 * each with ✅ / ✖️ and a link to the full proposal.
 *
 * A new request first asks which AI prepares it (status choose, buttons fxd / fxc → new with engine deepseek / claude);
 * a revision keeps the engine of its parent (a reply to a digest suggestion asks too).
 * Statuses: choose → new → working → proposed → approved → applying → [deploying →] applied | failed; proposed → rejected;
 * new/working/proposed → revised (a child request). A reply to an applied or rejected fix starts a follow-up with parent_id.
 * target_text keeps the replied topic message and the operator's quote: suggestions are not chat messages.
 * The daily digest posts fixes it suggests itself (status suggested): "🛠 Сделать правку" turns one into a usual request (choose),
 * "✖️ Не нужно" → dismissed; a text reply to it is a request of its own with the suggestion as the parent.
 * "/digest" asks for the digest now: the request goes to cache/telegram_digest_request_<bot>.json, the service sends
 * the digest (since the previous one) within a minute.
 * Under the digest the service asks which AI makes the review (dge||deepseek / dge||claude): the choice is written to
 * cache/telegram_ai_engine_<bot>.json (with the question message id); the service starts the review.
 */
class TelegramFix
{
    const CALLBACK_APPLY = 'fxa';
    const CALLBACK_REJECT = 'fxr';
    const CALLBACK_LIST = 'fxl';
    const CALLBACK_EDIT = 'fxe';
    const CALLBACK_SUGGESTED_TAKE = 'fxs';
    const CALLBACK_SUGGESTED_DISMISS = 'fxn';
    const CALLBACK_ENGINE = 'dge';      // under the digest question: the review
    const CALLBACK_ENGINE_FIX = 'dgm';  // old global /fix switch, kept for buttons already posted
    const CALLBACK_FIX_DEEPSEEK = 'fxd'; // under a new request: which AI prepares this fix
    const CALLBACK_FIX_CLAUDE = 'fxc';
    const CALLBACKS = array(self::CALLBACK_APPLY, self::CALLBACK_REJECT, self::CALLBACK_LIST, self::CALLBACK_EDIT, self::CALLBACK_SUGGESTED_TAKE,
        self::CALLBACK_SUGGESTED_DISMISS, self::CALLBACK_ENGINE, self::CALLBACK_ENGINE_FIX, self::CALLBACK_FIX_DEEPSEEK, self::CALLBACK_FIX_CLAUDE);
    const CHOOSE_TEXT = '🛠 Чем сделать правку? 🐋 DeepSeek — копейки, 1–3 минуты; ✳️ Claude — по подписке, 2–6 минут, глубже.';
    const ENGINES = array('deepseek' => '🐋 DeepSeek', 'claude' => '✳️ Claude');
    const EDIT_HINT = 'Ответьте на это сообщение текстом: что добавить или изменить в правке. ИИ переделает её с учётом ваших слов, клиенту ответ не уйдёт.';
    const USAGE = 'Напишите после /fix, что не так, например: «/fix нельзя менять подписку на другую, только новая оплата». Лучше ответом на неверное сообщение.';

    public static function getSession()
    {
        return \erLhcoreClassExtensionLhctelegram::getSession();
    }

    // "/digest": the service (fixbot.py) sees the request within a minute and sends the digest since the previous one
    public static function requestDigest($message, $bot)
    {
        $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $bot->id, 'confirmed' => 1, 'tuser_id' => $message->getFrom()->getId())));
        if (!($operator instanceof \erLhcoreClassModelTelegramOperator)) {
            return \Longman\TelegramBot\Request::emptyResponse();
        }

        $file = 'cache/telegram_digest_request_' . (int)$bot->id . '.json';
        $data = array('ts' => time(), 'operator' => (string)$operator->user->name_support);
        $ok = file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
        $digestTopicId = self::digestTopicId($bot);

        return self::send($bot, array(
            'chat_id' => $message->getChat()->getId(),
            'message_thread_id' => (int)$message->getMessageThreadId(),
            'reply_to_message_id' => $message->getMessageId(),
            'text' => $ok
                ? '📊 Сводка с прошлой будет в течение минуты' . ($digestTopicId > 0 && (int)$message->getMessageThreadId() !== $digestTopicId ? ' — в теме «Сводка»' : '') . ', под ней — выбор ИИ для разбора.'
                : 'Не удалось запросить сводку.',
        ));
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

    // A reply in a topic to one of the fix messages (proposal, result) — a correction of that fix, not a message to the visitor
    public static function isReplyToFix($message, $bot)
    {
        $replyData = \erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
        if (empty($replyData['is_explicit_reply'])) {
            return false;
        }
        return self::findByStatusMessage($bot, (int)$replyData['reply_message_id']) !== null;
    }

    private static function findByStatusMessage($bot, $topicMsgId)
    {
        if ($topicMsgId <= 0) {
            return null;
        }
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('SELECT * FROM lhc_telegram_fix WHERE bot_id = :bot_id AND (status_msg_id = :msg_id OR FIND_IN_SET(:msg_id_set, extra_msg_ids) > 0) ORDER BY id DESC LIMIT 1');
        $stmt->bindValue(':msg_id_set', (string)(int)$topicMsgId, \PDO::PARAM_STR);
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

        if ($comment === '' && $threadId > 0 && $threadId === self::digestTopicId($bot)) {
            return self::listPending($bot, $groupChatId, $threadId);
        }

        if ($comment === '') {
            return self::send($bot, array('chat_id' => $groupChatId, 'message_thread_id' => $threadId, 'reply_to_message_id' => $message->getMessageId(), 'text' => self::USAGE));
        }

        $replyData = \erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
        $replyTopicMsgId = !empty($replyData['is_explicit_reply']) ? (int)$replyData['reply_message_id'] : 0;

        // A reply to a proposal asks to rework it
        $parent = self::findByStatusMessage($bot, $replyTopicMsgId);

        // The replied message as the operator saw it: a suggestion or a service message is not in the chat history
        $raw = \erLhcoreClassExtensionLhctelegram::getTelegramRawMessageData($message);
        $replied = $replyTopicMsgId > 0 && $parent === null && isset($raw['reply_to_message']) && is_array($raw['reply_to_message']) ? $raw['reply_to_message'] : array();
        $targetText = array_filter(array(
            'text' => mb_substr((string)($replied['text'] ?? $replied['caption'] ?? ''), 0, 3000),
            'quote' => $parent === null ? mb_substr((string)($replyData['quote_text'] ?? ''), 0, 1000) : '',
        ), 'strlen');

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

        $db = \ezcDbInstance::get();
        $stmt = $db->prepare('INSERT INTO lhc_telegram_fix (status, engine, bot_id, group_chat_id, thread_id, chat_id, target_msg_id, target_text, request_msg_id, status_msg_id, parent_id, operator_user_id, operator_name, comment, proposal, result, ctime, utime) VALUES (:status, :engine, :bot_id, :group_chat_id, :thread_id, :chat_id, :target_msg_id, :target_text, :request_msg_id, :status_msg_id, :parent_id, :operator_user_id, :operator_name, :comment, \'\', \'\', :ctime, :utime)');
        // A revision is made by the AI of its parent, a new request (or a reply to a digest suggestion) asks first
        $engine = $parent !== null ? (string)$parent['engine'] : '';
        $stmt->bindValue(':status', $engine !== '' ? 'new' : 'choose', \PDO::PARAM_STR);
        $stmt->bindValue(':engine', $engine, \PDO::PARAM_STR);
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->bindValue(':group_chat_id', (string)$groupChatId, \PDO::PARAM_STR);
        $stmt->bindValue(':thread_id', $threadId, \PDO::PARAM_INT);
        $stmt->bindValue(':chat_id', $chatId, \PDO::PARAM_INT);
        $stmt->bindValue(':target_msg_id', $targetMsgId, \PDO::PARAM_INT);
        $stmt->bindValue(':target_text', empty($targetText) ? '' : json_encode($targetText, JSON_UNESCAPED_UNICODE), \PDO::PARAM_STR);
        $stmt->bindValue(':request_msg_id', (int)$message->getMessageId(), \PDO::PARAM_INT);
        $stmt->bindValue(':status_msg_id', 0, \PDO::PARAM_INT);
        $stmt->bindValue(':parent_id', $parent !== null ? (int)$parent['id'] : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':operator_user_id', (int)$operator->user_id, \PDO::PARAM_INT);
        $stmt->bindValue(':operator_name', (string)$operator->user->name_support, \PDO::PARAM_STR);
        $stmt->bindValue(':comment', mb_substr($comment, 0, 4000), \PDO::PARAM_STR);
        $stmt->bindValue(':ctime', time(), \PDO::PARAM_INT);
        $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
        $stmt->execute();
        $fixId = (int)$db->lastInsertId();

        $ack = self::send($bot, array_filter(array(
            'chat_id' => $groupChatId,
            'message_thread_id' => $threadId,
            'reply_to_message_id' => $message->getMessageId(),
            'text' => $engine !== '' ? '🛠 Переделываю правку с учётом комментария (' . (self::ENGINES[$engine] ?? $engine) . ')…' : self::CHOOSE_TEXT,
            'reply_markup' => $engine !== '' ? null : self::chooseKeyboard($fixId),
        )));
        if ($ack->isOk()) {
            $update = $db->prepare('UPDATE lhc_telegram_fix SET status_msg_id = :msg_id WHERE id = :id');
            $update->bindValue(':msg_id', (int)$ack->getResult()->getMessageId(), \PDO::PARAM_INT);
            $update->bindValue(':id', $fixId, \PDO::PARAM_INT);
            $update->execute();
        }

        // A proposal or a request still in work is replaced by the child (the service drops a late result of the parent)
        if ($parent !== null && in_array($parent['status'], array('choose', 'new', 'working', 'proposed', 'suggested'), true)) {
            self::setStatus((int)$parent['id'], 'revised');
            if ($parent['status'] === 'proposed') {
                self::clearAllButtons($bot, $parent);
            }
        }

        return $ack;
    }

    public static function topicLink($groupChatId, $threadId, $msgId)
    {
        return 'https://t.me/c/' . preg_replace('/^-100/', '', (string)$groupChatId) . '/' . (int)$threadId . '/' . (int)$msgId;
    }

    private static function short($text, $limit)
    {
        $text = trim((string)$text);
        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    // The whole proposal as HTML (same layout as the service posts under the /fix request)
    public static function proposalHtml($row)
    {
        $proposal = json_decode((string)$row['proposal'], true);
        // The service stores the message it posted (a diff that fits Telegram's limit)
        if (!empty($proposal['_html'])) {
            return (string)$proposal['_html'];
        }
        $labels = array('prompt' => 'Промпт ассистента', 'suggest_prompt' => 'Промпт подсказок оператору', 'site' => 'Сайт', 'doc' => 'База знаний');
        $where = $labels[$proposal['target'] ?? ''] ?? '';
        if (!empty($proposal['path'])) {
            $where .= ': ' . htmlspecialchars($proposal['path'], ENT_NOQUOTES);
        }
        $e = function ($text) { return htmlspecialchars((string)$text, ENT_NOQUOTES); };

        return '🛠 <b>Правка #' . (int)$row['id'] . '</b>: ' . $e($proposal['summary'] ?? '') . "\n"
            . '📍 ' . $where . "\n"
            . '💬 ' . $e($proposal['why'] ?? '') . "\n"
            . '✍️ ' . $e($row['operator_name']) . ', ' . date('d.m H:i', $row['ctime']) . ((int)$row['chat_id'] > 0 ? ', чат #' . (int)$row['chat_id'] : '') . ': «' . $e(self::short($row['comment'], 300)) . "»\n\n"
            . (!empty($proposal['replace_all']) ? "🔁 Во всех одинаковых местах файла\n" : '')
            . "<b>Было:</b>\n<blockquote expandable>" . $e(self::short($proposal['old'] ?? '', 1300)) . "</blockquote>\n"
            . "<b>Станет:</b>\n<blockquote expandable>" . $e(self::short($proposal['new'] ?? '', 1500)) . "</blockquote>\n"
            . '<i>Дополнить или поправить — ответьте на это сообщение текстом.</i>';
    }

    public static function proposalKeyboard($fixId)
    {
        return new \Longman\TelegramBot\Entities\InlineKeyboard(
            [['text' => '✅ Применить', 'callback_data' => self::CALLBACK_APPLY . '||' . (int)$fixId], ['text' => '✖️ Отклонить', 'callback_data' => self::CALLBACK_REJECT . '||' . (int)$fixId]],
            [['text' => '✏️ Дополнить', 'callback_data' => self::CALLBACK_EDIT . '||' . (int)$fixId]]
        );
    }

    // Every proposal waiting for a decision, in full, with the same buttons; a text reply to it revises it right there.
    // The copies are remembered in extra_msg_ids, so replies and the final result reach them too.
    public static function listPending($bot, $groupChatId, $threadId)
    {
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare("SELECT * FROM lhc_telegram_fix WHERE bot_id = :bot_id AND status = 'proposed' ORDER BY id LIMIT 15");
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return self::send($bot, array('chat_id' => $groupChatId, 'message_thread_id' => $threadId, 'text' => '✅ Правок, ждущих решения, нет.'));
        }

        $response = null;
        foreach ($rows as $row) {
            $response = self::send($bot, array(
                'chat_id' => $groupChatId,
                'message_thread_id' => $threadId,
                'disable_notification' => true,
                'parse_mode' => 'HTML',
                'text' => self::proposalHtml($row),
                'reply_markup' => self::proposalKeyboard($row['id']),
            ));

            if ($response->isOk()) {
                $ids = array_filter(explode(',', (string)$row['extra_msg_ids']));
                $ids[] = (int)$response->getResult()->getMessageId();
                $update = $db->prepare('UPDATE lhc_telegram_fix SET extra_msg_ids = :ids WHERE id = :id');
                $update->bindValue(':ids', implode(',', array_slice($ids, -20)), \PDO::PARAM_STR);
                $update->bindValue(':id', (int)$row['id'], \PDO::PARAM_INT);
                $update->execute();
            }
        }

        return $response;
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

    // Buttons off on the proposal and on every copy of it in the digest topic, except $exceptMsgId
    private static function clearAllButtons($bot, $row, $exceptMsgId = 0)
    {
        $ids = array_merge(array((int)$row['status_msg_id']), array_map('intval', array_filter(explode(',', (string)($row['extra_msg_ids'] ?? '')))));
        foreach (array_unique($ids) as $msgId) {
            if ($msgId !== (int)$exceptMsgId) {
                self::clearButtons($bot, $row['group_chat_id'], $msgId);
            }
        }
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
        if ($action === self::CALLBACK_EDIT) {
            return self::EDIT_HINT;
        }

        if ($action === self::CALLBACK_LIST) {
            $message = $callbackQuery->getMessage();
            self::listPending($bot, $message->getChat()->getId(), (int)$message->getMessageThreadId());
            return 'Список правок ниже';
        }

        $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $bot->id, 'confirmed' => 1, 'tuser_id' => $callbackQuery->getFrom()->getId())));
        if (!($operator instanceof \erLhcoreClassModelTelegramOperator)) {
            return 'Оператор не найден';
        }

        if ($action === self::CALLBACK_SUGGESTED_TAKE || $action === self::CALLBACK_SUGGESTED_DISMISS) {
            return self::handleSuggested($callbackQuery, $bot, $action, $fixId, $operator);
        }

        if ($action === self::CALLBACK_ENGINE) {
            return self::chooseEngine($callbackQuery, $bot, (string)$fixId, $operator);
        }

        if ($action === self::CALLBACK_ENGINE_FIX) {
            return 'ИИ для правки теперь выбирается у каждой /fix';
        }

        if ($action === self::CALLBACK_FIX_DEEPSEEK || $action === self::CALLBACK_FIX_CLAUDE) {
            return self::chooseFixEngine($callbackQuery, $bot, $action === self::CALLBACK_FIX_CLAUDE ? 'claude' : 'deepseek', $fixId, $operator);
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

        // The other copies of the proposal must not keep their buttons (the service reports the result in all of them)
        $stmt = $db->prepare('SELECT * FROM lhc_telegram_fix WHERE id = :id');
        $stmt->bindValue(':id', (int)$fixId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            self::clearAllButtons($bot, $row, (int)$callbackQuery->getMessage()->getMessageId());
        }

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

    // A fix suggested by the digest: the operator who takes it becomes its author, the message turns into "preparing…"
    private static function handleSuggested($callbackQuery, $bot, $action, $fixId, $operator)
    {
        $take = $action === self::CALLBACK_SUGGESTED_TAKE;
        $message = $callbackQuery->getMessage();

        $db = \ezcDbInstance::get();
        $stmt = $db->prepare("UPDATE lhc_telegram_fix SET status = :status, operator_user_id = :operator_user_id, operator_name = :operator_name, status_msg_id = :status_msg_id, utime = :utime WHERE id = :id AND bot_id = :bot_id AND status = 'suggested'");
        $stmt->bindValue(':status', $take ? 'choose' : 'dismissed', \PDO::PARAM_STR);
        $stmt->bindValue(':operator_user_id', (int)$operator->user_id, \PDO::PARAM_INT);
        $stmt->bindValue(':operator_name', (string)$operator->user->name_support, \PDO::PARAM_STR);
        $stmt->bindValue(':status_msg_id', (int)$message->getMessageId(), \PDO::PARAM_INT);
        $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$fixId, \PDO::PARAM_INT);
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return 'Уже обработано';
        }

        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => ($take ? self::CHOOSE_TEXT . ' (' : '✖️ Не нужно (') . $operator->user->name_support . ")\n\n" . $message->getText(),
            'disable_web_page_preview' => true,
            'reply_markup' => $take ? self::chooseKeyboard($fixId) : new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ));

        return $take ? 'Выберите ИИ для правки' : 'Убрано';
    }

    private static function chooseKeyboard($fixId)
    {
        return new \Longman\TelegramBot\Entities\InlineKeyboard(array(
            array('text' => self::ENGINES['deepseek'], 'callback_data' => self::CALLBACK_FIX_DEEPSEEK . '||' . (int)$fixId),
            array('text' => self::ENGINES['claude'], 'callback_data' => self::CALLBACK_FIX_CLAUDE . '||' . (int)$fixId),
        ));
    }

    // The AI for one fix: the request goes to the service (it falls back to DeepSeek if Claude has no subscription token)
    private static function chooseFixEngine($callbackQuery, $bot, $engine, $fixId, $operator)
    {
        $message = $callbackQuery->getMessage();
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare("UPDATE lhc_telegram_fix SET status = 'new', engine = :engine, utime = :utime WHERE id = :id AND bot_id = :bot_id AND status = 'choose'");
        $stmt->bindValue(':engine', $engine, \PDO::PARAM_STR);
        $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
        $stmt->bindValue(':id', (int)$fixId, \PDO::PARAM_INT);
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return 'Уже обработано';
        }

        // The digest suggestion stays under the line, a plain request's message is only the question
        $text = (string)$message->getText();
        $rest = strpos($text, self::CHOOSE_TEXT) === 0 ? ltrim(preg_replace('/^[^\n]*/u', '', $text)) : '';
        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => '🛠 Готовлю правку: ' . self::ENGINES[$engine] . ' (' . $operator->user->name_support . '), обычно это ' . ($engine === 'claude' ? '2–6' : '1–3') . ' минуты…' . ($rest !== '' ? "\n\n" . $rest : ''),
            'disable_web_page_preview' => true,
            'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ));

        return 'Готовлю правку: ' . self::ENGINES[$engine];
    }

    // The AI for the digest review: the service reads the file (it runs as root and holds the keys)
    private static function chooseEngine($callbackQuery, $bot, $engine, $operator)
    {
        if (!isset(self::ENGINES[$engine])) {
            return 'Неизвестный вариант';
        }
        $message = $callbackQuery->getMessage();
        $file = 'cache/telegram_ai_engine_' . (int)$bot->id . '.json';
        // msg_id ties a choice to the digest question: the service starts the review for it
        $data = array('engine' => $engine, 'msg_id' => (int)$message->getMessageId(), 'ts' => time(), 'operator' => (string)$operator->user->name_support);
        if (file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return 'Не удалось сохранить выбор';
        }

        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => '🧠 ИИ-разбор: ' . self::ENGINES[$engine] . ' (' . $operator->user->name_support . '), начну в течение минуты.',
            'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ));

        return 'Выбрано: ' . self::ENGINES[$engine];
    }
}
