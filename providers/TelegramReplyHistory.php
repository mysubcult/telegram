<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/** Resolve native replies between LHC sessions belonging to the same messenger peer and operator topic. */
class TelegramReplyHistory
{
    public static function find($chatId, array $topic, $externalId = null, $topicMessageId = null)
    {
        $thread = (int)($topic['thread_id'] ?? 0);
        if ($thread <= 0 || empty($topic['bot_id']) || empty($topic['group_chat_id']) ||
            ($externalId === null && (int)$topicMessageId <= $thread)) {
            return null;
        }

        try {
            $db = \ezcDbInstance::get();
            // LHC moves the incoming-chat row to the new session. Its online visitor keeps the
            // exact webhook + external chat identity, including after older chats are archived.
            $scope = $db->prepare("SELECT c.online_user_id, c.iwh_id, t.tchat_id
                FROM lh_chat c
                JOIN lh_chat_incoming i ON i.chat_id = c.id AND i.incoming_id = c.iwh_id
                JOIN lh_chat_online_user o ON o.id = c.online_user_id AND o.vid = MD5(CONCAT(i.incoming_id, '_', i.chat_external_id))
                JOIN lhc_telegram_chat t ON t.chat_id_internal = -c.online_user_id AND t.type = 1
                JOIN lhc_telegram_bot b ON b.id = t.bot_id AND b.bot_client = 1
                WHERE c.id = ? AND c.online_user_id > 0 AND i.chat_external_id <> ''
                    AND b.id = ? AND b.group_chat_id = ? LIMIT 2");
            $scope->execute([(int)$chatId, (int)$topic['bot_id'], (string)$topic['group_chat_id']]);
            $peer = $scope->fetch(\PDO::FETCH_ASSOC);
            if (!$peer || (int)$peer['tchat_id'] !== $thread || $scope->fetch()) {
                return null;
            }

            $namespace = \erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace($topic['bot_id'], $topic['group_chat_id']);
            $path = '$.tg_topic_msg_contexts.' . $namespace . '.ids';
            $params = [(int)$peer['online_user_id'], (int)$peer['iwh_id'], (int)$chatId];
            if ($externalId !== null) {
                $condition = "JSON_LENGTH(m.meta_msg, ?) > 0 AND
                    (BINARY JSON_UNQUOTE(JSON_EXTRACT(m.meta_msg, '$.iwh_msg_id')) = ?
                     OR JSON_CONTAINS(JSON_EXTRACT(m.meta_msg, '$.iwh_msg_ids'), JSON_QUOTE(?))
                     OR JSON_CONTAINS(JSON_EXTRACT(m.meta_msg, '$.iwh_msg_ids'), ?))";
                $numericId = preg_match('/^(0|[1-9][0-9]*)$/D', (string)$externalId) ? (string)$externalId : 'null';
                array_push($params, $path, (string)$externalId, (string)$externalId, $numericId);
            } else {
                $condition = 'JSON_CONTAINS(m.meta_msg, ?, ?)';
                array_push($params, (string)(int)$topicMessageId, $path);
            }

            $tables = [['lh_chat', 'lh_msg']];
            $ranges = $db->prepare('SELECT id FROM lh_chat_archive_range WHERE first_id > 0 AND first_id < ?');
            $ranges->execute([(int)$chatId]);
            foreach ($ranges->fetchAll(\PDO::FETCH_COLUMN) as $range) {
                $range = (int)$range;
                $tables[] = ['lh_chat_archive_' . $range, 'lh_chat_archive_msg_' . $range];
            }

            $found = null;
            foreach ($tables as [$chats, $messages]) {
                $query = $db->prepare("SELECT m.id, m.chat_id, m.name_support, m.msg, m.meta_msg
                    FROM $chats c JOIN $messages m ON m.chat_id = c.id
                    WHERE c.online_user_id = ? AND c.iwh_id = ? AND c.id < ? AND m.user_id <> -1
                        AND JSON_VALID(m.meta_msg) AND $condition");
                $query->execute($params);
                while ($row = $query->fetch(\PDO::FETCH_OBJ)) {
                    $row->meta_msg_array = json_decode($row->meta_msg, true);
                    $target = self::mappedTarget($row, $namespace, $thread, $externalId, $topicMessageId);
                    if ($target === null) {
                        continue;
                    }
                    // One aggregate topic post can represent multiple LHC rows. It cannot
                    // identify which of their different visitor messages the operator meant.
                    if ($found !== null && (int)$row->id !== (int)$found['msg']->id) {
                        return null;
                    }
                    $found = $target;
                }
            }
            return $found !== null && $found['external_id'] !== '' ? $found : null;
        } catch (\Throwable $error) {
            TelegramLiveHelperChatOperator::logTopicError('Historical reply lookup failed: ' . $error->getCode(), (int)$chatId);
            return null;
        }
    }

    private static function mappedTarget($msg, $namespace, $thread, $externalId, $topicMessageId)
    {
        $meta = $msg->meta_msg_array;
        $context = $meta['tg_topic_msg_contexts'][$namespace] ?? [];
        $externalIds = array_map('strval', (array)($meta['iwh_msg_ids'] ?? []));
        $primary = (string)($meta['iwh_msg_id'] ?? '');
        if ($primary !== '') {
            $externalIds[] = $primary;
        }
        $externalIds = array_values(array_unique($externalIds));
        foreach (array_reverse($context['ids'] ?? []) as $id) {
            $id = (int)$id;
            $entry = $context['map'][$id] ?? [];
            if ($id <= $thread || (int)($entry['thread_id'] ?? $thread) !== $thread ||
                ($topicMessageId !== null && $id !== (int)$topicMessageId)) {
                continue;
            }
            $key = ($entry['file_id'] ?? '') . '_' . ($entry['security_hash'] ?? '');
            $mid = (string)($meta['lhcmax_media'][$key]['mid'] ?? '');
            if ($mid === '' && count($externalIds) === 1) {
                $mid = $externalIds[0];
            }
            if ($externalId === null || ($mid !== '' && $mid === (string)$externalId)) {
                return ['msg' => $msg, 'topic_id' => $id, 'external_id' => $mid];
            }
        }
        return null;
    }

    private static function verifiedReference($chat, $msg)
    {
        if (!$chat || !$msg || (int)$msg->chat_id !== (int)$chat->id || (int)$msg->user_id <= 0) {
            return null;
        }
        $meta = $msg->meta_msg_array;
        $reply = $meta['content']['reply_to'] ?? [];
        if (empty($reply['history_topic']) || !is_array($reply['history_topic']) ||
            empty($reply['telegram_message_id']) || empty($reply['iwh_msg_id'])) {
            return null;
        }
        $target = self::find($chat->id, $reply['history_topic'], null, $reply['telegram_message_id']);
        return $target !== null && (int)$target['msg']->id === (int)($reply['db_msg_id'] ?? 0)
            && $target['external_id'] === (string)$reply['iwh_msg_id'] ? $target : null;
    }

    public static function validateExternalReply($params)
    {
        $target = self::verifiedReference($params['chat'], $params['msg']);
        if ($target !== null && $target['external_id'] === (string)$params['external_id']) {
            $params['valid'] = true;
        }
    }

    public static function prepareRestReply($params)
    {
        $msg = $params['params']['msg'] ?? null;
        if (!is_object($msg)) {
            return;
        }
        $meta = $msg->meta_msg_array;
        if (empty($meta['content']['reply_to']['history_topic'])) {
            return;
        }
        $target = self::verifiedReference($params['chat'], $msg);
        if ($target === null || !preg_match('/^[A-Za-z0-9._:-]{1,255}$/D', $target['external_id'])) {
            return;
        }

        // Core's {reply_to} resolves numeric BBCode only from live lh_msg. Fill the
        // transport's existing reply block from the verified mapping, also in workers.
        foreach (['body_raw', 'body_raw_file'] as $key) {
            if (isset($params['method'][$key])) {
                $params['method'][$key] = preg_replace_callback('~\{reply_to\}(.*?)\{/reply_to\}~s', static function ($match) use ($target) {
                    return strtr($match[1], [
                        'raw_{{iwh_msg_id}}' => $target['external_id'],
                        '{{iwh_msg_id}}' => json_encode($target['external_id']),
                        'raw_{{db_msg_id}}' => (string)(int)$target['msg']->id,
                        '{{db_msg_id}}' => (string)(int)$target['msg']->id,
                    ]);
                }, $params['method'][$key]);
            }
        }
    }
}
