<?php

namespace LiveHelperChatExtension\lhctelegram\providers;

/**
 * Forum topic icon from the department name.
 *
 * Bots may set a topic icon only from Telegram's free set of topic icons
 * (getForumTopicIconStickers, no Premium needed). When a department name starts with an emoji
 * from that set — e.g. "💬 Support", "📝 Mail" — new topics of that department get it as their icon,
 * so departments are told apart at a glance (mobile clients otherwise draw the first letter).
 * A coloured circle, square or heart ("🔵 Telegram", "🟣 Max") gives the default icon in that colour
 * (icon_color, one of the six colours Telegram allows). Any other first character leaves the default icon.
 *
 * The set is cached per bot for a day in cache/telegram_topic_icons_<bot id>.json.
 */
class TelegramTopicIcons
{
    const CACHE_TTL = 86400;

    // icon_color values allowed by createForumTopic
    const COLORS = array(
        '🔵' => 7322096, '🟦' => 7322096, '💙' => 7322096,
        '🟡' => 16766590, '🟨' => 16766590, '💛' => 16766590,
        '🟣' => 13338331, '🟪' => 13338331, '💜' => 13338331,
        '🟢' => 9367192, '🟩' => 9367192, '💚' => 9367192,
        '🩷' => 16749490, '💗' => 16749490,
        '🔴' => 16478047, '🟥' => 16478047,
    );

    // Adds icon_custom_emoji_id (or icon_color) to createForumTopic data from the department name
    public static function addIcon($bot, $department, array $data)
    {
        $iconId = self::getIconId($bot, (string)$department);
        if ($iconId !== '') {
            $data['icon_custom_emoji_id'] = $iconId;
            return $data;
        }

        $emoji = self::normalize(self::getFirstEmoji((string)$department));
        if (isset(self::COLORS[$emoji])) {
            $data['icon_color'] = self::COLORS[$emoji];
        }

        return $data;
    }

    public static function getIconId($bot, $departmentName)
    {
        $emoji = self::getFirstEmoji($departmentName);
        if ($emoji === '') {
            return '';
        }

        $icons = self::getIcons($bot);
        return isset($icons[self::normalize($emoji)]) ? (string)$icons[self::normalize($emoji)] : '';
    }

    // First word of the name when it is not a word (no letters or digits)
    public static function getFirstEmoji($text)
    {
        $parts = preg_split('/\s+/u', trim((string)$text));
        $first = is_array($parts) && isset($parts[0]) ? $parts[0] : '';

        if ($first === '' || preg_match('/[\p{L}\p{N}\[\]\(\)]/u', $first)) {
            return '';
        }

        return $first;
    }

    // "⚡️" and "⚡" are the same icon
    public static function normalize($emoji)
    {
        return str_replace("\u{FE0F}", '', (string)$emoji);
    }

    // emoji => custom_emoji_id
    public static function getIcons($bot)
    {
        $cacheFile = 'cache/telegram_topic_icons_' . (int)$bot->id . '.json';

        if (is_file($cacheFile) && filemtime($cacheFile) > time() - self::CACHE_TTL) {
            $icons = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($icons)) {
                return $icons;
            }
        }

        $icons = array();

        try {
            // The library refuses empty data; Telegram ignores the extra field
            $response = \Longman\TelegramBot\Request::send('getForumTopicIconStickers', array('chat_id' => $bot->group_chat_id));
            if ($response->isOk() && is_array($response->getResult())) {
                foreach ($response->getResult() as $sticker) {
                    $emoji = is_object($sticker) ? (string)$sticker->getEmoji() : (string)($sticker['emoji'] ?? '');
                    $id = is_object($sticker) ? (string)$sticker->getCustomEmojiId() : (string)($sticker['custom_emoji_id'] ?? '');
                    if ($emoji !== '' && $id !== '') {
                        $icons[self::normalize($emoji)] = $id;
                    }
                }
            }
        } catch (\Throwable $e) {
            return array();
        }

        if (!empty($icons)) {
            @file_put_contents($cacheFile, json_encode($icons));
        }

        return $icons;
    }
}
