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
 * The digest topic is a control panel, not a log: operators' messages there are deleted once taken, a dismissed or rejected
 * fix disappears at once, results come as short notices with "👌 Понятно" (deletes it), the service clears the rest before
 * the next digest. Topics of chats keep the full history as before.
 * Digest pack (statuses pack*, rows of the digest topic): the review's suggestions are items of one pack (vote → taken | dropped,
 * buttons pkt / pkd / pku), the pack message has "🧩 Собрать" (pkb → pack_build); the service builds all taken items into one set of
 * edits (pack_ready: ✅ pka → pack_apply, 🔙 pkv → pack_back, 🗑 pkx → pack_cancel) and applies it at once; ↩️ pkr → pack_revert.
 * A text reply to an item or to the pack, and any text in the digest topic while a pack is open, becomes a child request with the
 * pack's AI (the service rewrites the item, adds an item of the owner or rebuilds the pack); these parents are never "revised".
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
    const CALLBACK_OK = 'fxk';           // "👌 Понятно" under a result in the digest topic: deletes that message
    // Digest pack: votes under an item, the pack buttons (the service does the work, these only change the status)
    const PACK_ITEM_ACTIONS = array('pkt' => 'taken', 'pkd' => 'dropped', 'pku' => 'vote');
    const PACK_ACTIONS = array(
        'pkb' => array(array('pack'), 'pack_build'),
        'pka' => array(array('pack_ready'), 'pack_apply'),
        'pkv' => array(array('pack_ready'), 'pack_back'),
        'pkx' => array(array('pack', 'pack_ready'), 'pack_cancel'),
        'pkr' => array(array('pack_applied'), 'pack_revert'),
    );
    const PACK_OPEN = array('pack', 'pack_ready');
    const CALLBACKS = array(self::CALLBACK_APPLY, self::CALLBACK_REJECT, self::CALLBACK_LIST, self::CALLBACK_EDIT, self::CALLBACK_SUGGESTED_TAKE,
        self::CALLBACK_SUGGESTED_DISMISS, self::CALLBACK_ENGINE, self::CALLBACK_ENGINE_FIX, self::CALLBACK_FIX_DEEPSEEK, self::CALLBACK_FIX_CLAUDE, self::CALLBACK_OK,
        'pkt', 'pkd', 'pku', 'pkb', 'pka', 'pkv', 'pkx', 'pkr');
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

        self::dropInDigest($message, $bot);
        $ack = self::send($bot, array(
            'chat_id' => $message->getChat()->getId(),
            'message_thread_id' => (int)$message->getMessageThreadId(),
            'text' => $ok
                ? '📊 Сводка с прошлой будет в течение минуты' . ($digestTopicId > 0 && (int)$message->getMessageThreadId() !== $digestTopicId ? ' — в теме «Сводка»' : '') . ', под ней — выбор ИИ для разбора.'
                : 'Не удалось запросить сводку.',
        ));
        // The service deletes this notice together with the previous digest
        if ($ok && $ack->isOk()) {
            $data['ack_msg_id'] = (int)$ack->getResult()->getMessageId();
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $ack;
    }

    private static function deleteMessage($bot, $chatId, $msgId)
    {
        if ((int)$msgId > 0) {
            new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
            $response = \Longman\TelegramBot\Request::deleteMessage(array('chat_id' => $chatId, 'message_id' => (int)$msgId));
            if (!$response->isOk()) { // older than 48 h: bots cannot delete it, at least no buttons
                self::clearButtons($bot, $chatId, (int)$msgId);
            }
        }
    }

    // An operator's message in the digest topic is a command to the bot: once taken it is not needed there
    private static function dropInDigest($message, $bot)
    {
        $threadId = (int)$message->getMessageThreadId();
        if ($threadId > 0 && $threadId === self::digestTopicId($bot)) {
            self::deleteMessage($bot, $message->getChat()->getId(), $message->getMessageId());
        }
    }

    // Every message of a fix: the proposal, its copies from the pending list, the full-text file and screenshot previews
    private static function deleteFixMessages($bot, $row)
    {
        $proposal = json_decode((string)($row['proposal'] ?? ''), true);
        $ids = array_merge(array((int)$row['status_msg_id']), array_map('intval', array_filter(explode(',', (string)($row['extra_msg_ids'] ?? '')))),
            array_map('intval', is_array($proposal) && isset($proposal['_attach']) && is_array($proposal['_attach']) ? $proposal['_attach'] : array()));
        foreach (array_unique($ids) as $msgId) {
            self::deleteMessage($bot, $row['group_chat_id'], $msgId);
        }
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
        return \Longman\TelegramBot\Request::sendMessage($data + array('link_preview_options' => array('is_disabled' => true)));
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
            self::dropInDigest($message, $bot);
            return self::listPending($bot, $groupChatId, $threadId);
        }

        if ($comment === '') {
            return self::send($bot, array('chat_id' => $groupChatId, 'message_thread_id' => $threadId, 'reply_parameters' => array('message_id' => $message->getMessageId()), 'text' => self::USAGE));
        }

        $replyData = \erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
        $replyTopicMsgId = !empty($replyData['is_explicit_reply']) ? (int)$replyData['reply_message_id'] : 0;

        // A reply to a proposal asks to rework it
        $parent = self::findByStatusMessage($bot, $replyTopicMsgId);
        // Text in the digest topic while a pack is open goes to the pack (a new item of the owner, or a remark on the built pack)
        if ($parent === null && $threadId > 0 && $threadId === self::digestTopicId($bot)) {
            $parent = self::openPack($bot, $threadId);
        }
        $packParent = $parent !== null && (in_array($parent['status'], array('vote', 'taken', 'dropped'), true) || strpos((string)$parent['status'], 'pack') === 0);

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
            'reply_parameters' => array('message_id' => $message->getMessageId()),
            'text' => $engine === '' ? self::CHOOSE_TEXT : ($packParent
                ? ($parent['status'] === 'pack' ? '➕ Добавляю ваш пункт в пакет' : ($parent['status'] === 'pack_ready' ? '🧩 Учитываю замечание, пакет пересоберётся' : '✍️ Учитываю уточнение в пункте')) . ' (' . (self::ENGINES[$engine] ?? $engine) . ')…'
                : '🛠 Переделываю правку с учётом комментария (' . (self::ENGINES[$engine] ?? $engine) . ')…'),
            'reply_markup' => $engine !== '' ? null : self::chooseKeyboard($fixId),
        )));
        if ($ack->isOk()) {
            $update = $db->prepare('UPDATE lhc_telegram_fix SET status_msg_id = :msg_id WHERE id = :id');
            $update->bindValue(':msg_id', (int)$ack->getResult()->getMessageId(), \PDO::PARAM_INT);
            $update->bindValue(':id', $fixId, \PDO::PARAM_INT);
            $update->execute();
        }

        // A proposal or a request still in work is replaced by the child (the service drops a late result of the parent)
        if (!$packParent && $parent !== null && in_array($parent['status'], array('choose', 'new', 'working', 'proposed', 'suggested'), true)) {
            self::setStatus((int)$parent['id'], 'revised');
            if ($threadId > 0 && $threadId === self::digestTopicId($bot) && (int)$parent['thread_id'] === $threadId) {
                // The digest topic keeps only what waits for a decision: the replaced fix goes, its revision comes as a new message
                self::deleteFixMessages($bot, $parent);
            } elseif ($parent['status'] === 'proposed') {
                self::clearAllButtons($bot, $parent);
            }
        }

        self::dropInDigest($message, $bot);
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
            return self::send($bot, array('chat_id' => $groupChatId, 'message_thread_id' => $threadId, 'text' => '✅ Правок, ждущих решения, нет.', 'reply_markup' => self::okKeyboard()));
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

        if ($action === self::CALLBACK_OK) {
            $message = $callbackQuery->getMessage();
            self::deleteMessage($bot, $message->getChat()->getId(), $message->getMessageId());
            return 'Убрано';
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

        if (isset(self::PACK_ITEM_ACTIONS[$action]) || isset(self::PACK_ACTIONS[$action])) {
            return self::handlePack($callbackQuery, $bot, $action, (int)$fixId);
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
        $message = $callbackQuery->getMessage();
        $digestTopicId = self::digestTopicId($bot);

        // ✖️ in the digest topic: the fix is gone from it at once (a fix of a chat topic keeps its message there, its copies go)
        if ($action === self::CALLBACK_REJECT && is_array($row) && $digestTopicId > 0) {
            if ((int)$row['thread_id'] === $digestTopicId) {
                self::deleteFixMessages($bot, $row);
                return 'Правка отклонена';
            }
            foreach (array_filter(explode(',', (string)$row['extra_msg_ids'])) as $copyId) {
                self::deleteMessage($bot, $row['group_chat_id'], (int)$copyId);
            }
            if ((int)$message->getMessageThreadId() === $digestTopicId) {
                self::clearButtons($bot, $row['group_chat_id'], (int)$row['status_msg_id']);
                return 'Правка отклонена';
            }
        }

        if (is_array($row)) {
            self::clearAllButtons($bot, $row, (int)$message->getMessageId());
        }

        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => ($action === self::CALLBACK_APPLY ? '⏳ Применяю (' : '✖️ Отклонено (') . $operator->user->name_support . ")\n\n" . $message->getText(),
            'link_preview_options' => array('is_disabled' => true),
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

        if (!$take) {
            self::deleteMessage($bot, $message->getChat()->getId(), $message->getMessageId());
            return 'Убрано';
        }

        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => ($take ? self::CHOOSE_TEXT . ' (' : '✖️ Не нужно (') . $operator->user->name_support . ")\n\n" . $message->getText(),
            'link_preview_options' => array('is_disabled' => true),
            'reply_markup' => $take ? self::chooseKeyboard($fixId) : new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ));

        return $take ? 'Выберите ИИ для правки' : 'Убрано';
    }

    private static function openPack($bot, $threadId)
    {
        $db = \ezcDbInstance::get();
        $stmt = $db->prepare("SELECT * FROM lhc_telegram_fix WHERE bot_id = :bot_id AND thread_id = :thread_id AND status IN ('pack', 'pack_ready') ORDER BY id DESC LIMIT 1");
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->bindValue(':thread_id', (int)$threadId, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    // Same keyboards as digest_pack.item_buttons in the service
    private static function packItemKeyboard($itemId, $status)
    {
        if ($status === 'taken') {
            return new \Longman\TelegramBot\Entities\InlineKeyboard(array(array('text' => '✅ Взято · передумать', 'callback_data' => 'pku||' . (int)$itemId)));
        }
        if ($status === 'dropped') {
            return new \Longman\TelegramBot\Entities\InlineKeyboard(array(array('text' => '✖️ Не берём · передумать', 'callback_data' => 'pku||' . (int)$itemId)));
        }
        return new \Longman\TelegramBot\Entities\InlineKeyboard(array(
            array('text' => '✅ Взять', 'callback_data' => 'pkt||' . (int)$itemId),
            array('text' => '✖️ Не надо', 'callback_data' => 'pkd||' . (int)$itemId),
        ));
    }

    // Digest pack buttons: a vote under an item (only while the pack is voted on) or a step of the pack; the service does the rest
    private static function handlePack($callbackQuery, $bot, $action, $id)
    {
        $db = \ezcDbInstance::get();
        $message = $callbackQuery->getMessage();

        if (isset(self::PACK_ITEM_ACTIONS[$action])) {
            $stmt = $db->prepare("UPDATE lhc_telegram_fix i JOIN lhc_telegram_fix p ON p.id = i.parent_id AND p.status = 'pack' SET i.status = :status, i.utime = :utime WHERE i.id = :id AND i.bot_id = :bot_id AND i.status IN ('vote', 'taken', 'dropped')");
            $stmt->bindValue(':status', self::PACK_ITEM_ACTIONS[$action], \PDO::PARAM_STR);
            $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                return 'Пакет уже собирается или закрыт — голос не принят';
            }
            new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
            \Longman\TelegramBot\Request::editMessageReplyMarkup(array('chat_id' => $message->getChat()->getId(), 'message_id' => $message->getMessageId(),
                'reply_markup' => self::packItemKeyboard($id, self::PACK_ITEM_ACTIONS[$action])));
            return array('pkt' => 'Взято в пакет', 'pkd' => 'Не берём', 'pku' => 'Можно решить заново')[$action];
        }

        list($from, $to) = self::PACK_ACTIONS[$action];
        if ($action === 'pkb') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM lhc_telegram_fix WHERE parent_id = :id AND status = 'taken'");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                return 'Сначала возьмите хотя бы один пункт (✅ под ним)';
            }
        }
        $stmt = $db->prepare('UPDATE lhc_telegram_fix SET status = :to, utime = :utime WHERE id = :id AND bot_id = :bot_id AND status IN (' . implode(',', array_map(function ($s) { return "'" . $s . "'"; }, $from)) . ')');
        $stmt->bindValue(':to', $to, \PDO::PARAM_STR);
        $stmt->bindValue(':utime', time(), \PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':bot_id', (int)$bot->id, \PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            return 'Уже обработано';
        }

        $texts = array(
            'pkb' => '🧩 Собираю пакет правок — начну в течение минуты, пакет придёт сюда же.',
            'pka' => '⏳ Применяю пакет правок…',
            'pkv' => '🔙 Возвращаю пакет на голосование…',
            'pkx' => '🗑 Отменяю пакет…',
            'pkr' => '↩️ Откатываю пакет правок…',
        );
        new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username);
        \Longman\TelegramBot\Request::editMessageText(array(
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'text' => $texts[$action] . "\n\n" . $message->getText(),
            'link_preview_options' => array('is_disabled' => true),
            'reply_markup' => new \Longman\TelegramBot\Entities\InlineKeyboard([]),
        ));
        return array('pkb' => 'Собираю пакет', 'pka' => 'Применяю пакет', 'pkv' => 'Снова голосование', 'pkx' => 'Пакет отменён', 'pkr' => 'Откатываю пакет')[$action];
    }

    private static function okKeyboard()
    {
        return new \Longman\TelegramBot\Entities\InlineKeyboard(array(array('text' => '👌 Понятно', 'callback_data' => self::CALLBACK_OK . '||0')));
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
            'link_preview_options' => array('is_disabled' => true),
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
