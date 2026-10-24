<?php

namespace LiveHelperChatExtension\lhctelegram\tests;

require_once __DIR__ . '/../../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../../ezcomponents/Base/src/base.php';
spl_autoload_register(array('ezcBase', 'autoload'), true, false);
\erLhcoreClassSystem::init();
\ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');

require_once __DIR__ . '/../bootstrap/bootstrap.php';
require_once __DIR__ . '/../providers/TelegramLiveHelperChatOperator.php';
require_once __DIR__ . '/../providers/TelegramSuggest.php';

use LiveHelperChatExtension\lhctelegram\providers\TelegramSuggest;

class TelegramSuggestTest
{
    public static function run()
    {
        echo "Running TelegramSuggestTest...\n";

        self::testIsSkip();
        self::testIsSuggestableText();
        self::testExtractSuggestion();
        self::testKeyboard();
        self::testShiftHistory();
        self::testExtractSystemText();
        self::testIsSuggestCommand();
        self::testPointedFileNote();

        echo "All TelegramSuggestTest passed successfully! [OK]\n";
    }

    private static function assert($condition, $message)
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    private static function testIsSkip()
    {
        foreach (array('SKIP', ' skip ', 'SKIP.', '"SKIP"', '') as $text) {
            self::assert(TelegramSuggest::isSkip($text) === true, 'Should be skip: ' . json_encode($text));
        }
        foreach (array('Ожидайте, пожалуйста.', 'SKIP the step and continue', 'Пропустите шаг SKIP') as $text) {
            self::assert(TelegramSuggest::isSkip($text) === false, 'Should not be skip: ' . json_encode($text));
        }
        self::assert(TelegramSuggest::isSkip('SKIP: клиент ругается, вопроса нет') === true, 'Skip with reason');
        self::assert(TelegramSuggest::skipReason('SKIP: клиент ругается, вопроса нет.') === 'клиент ругается, вопроса нет', 'Reason extracted');
        self::assert(TelegramSuggest::skipReason('SKIP') === '', 'No reason');
        self::assert(TelegramSuggest::buildNoSuggestion('нужна проверка оплаты') === '💡 Подсказки нет: нужна проверка оплаты. Ответьте сами.', 'Note with reason');
        self::assert(TelegramSuggest::buildNoSuggestion('') === '💡 Подсказки нет. Ответьте сами.', 'Note without reason');
        echo "  [PASS] isSkip\n";
    }

    private static function testIsSuggestableText()
    {
        self::assert(TelegramSuggest::isSuggestableText('Откуда скачать приложение?') === true, 'Question is suggestable');
        self::assert(TelegramSuggest::isSuggestableText(' [file=9472_13c91c500420e2169769ff7ae4cf1b22]') === false, 'Bare file is not suggestable');
        self::assert(TelegramSuggest::isSuggestableText('Вот [file=9472_13c91c500420e2169769ff7ae4cf1b22]') === true, 'File with caption is suggestable');
        self::assert(TelegramSuggest::isSuggestableText('/start') === false, 'Command is not suggestable');
        self::assert(TelegramSuggest::isSuggestableText('?') === false, 'Single char is not suggestable');
        echo "  [PASS] isSuggestableText\n";
    }

    private static function testExtractSuggestion()
    {
        $text = 'Оформить можно здесь: https://смартдиаг.рф/subscriptions';
        self::assert(TelegramSuggest::extractSuggestion(TelegramSuggest::HEADER . "\n\n" . $text) === $text, 'Header is stripped');
        self::assert(TelegramSuggest::extractSuggestion("Строка 1\n\nСтрока 2") === "Строка 1\n\nСтрока 2", 'Text without header is kept');
        $header = TelegramSuggest::buildHeader('Откуда скачать приложение?');
        self::assert($header === '💡 Подсказка на «Откуда скачать приложение?»:', 'Header names the message: ' . $header);
        self::assert(TelegramSuggest::extractSuggestion($header . "\n\n" . "Строка 1\n\nСтрока 2") === "Строка 1\n\nСтрока 2", 'Header with quote is stripped, text keeps blank lines');
        self::assert(mb_strlen(TelegramSuggest::buildHeader(str_repeat('а', 100))) < 80, 'Long message is shortened');
        self::assert(TelegramSuggest::buildHeader(' [file=1_abc]') === '💡 Подсказка на «📎»:', 'File becomes a clip');
        echo "  [PASS] extractSuggestion\n";
    }

    private static function testKeyboard()
    {
        $keyboard = json_decode(json_encode(TelegramSuggest::buildKeyboard(25535)), true)['inline_keyboard'];
        self::assert($keyboard[0][0]['callback_data'] === 'sgs||25535', 'Send callback data');
        self::assert($keyboard[0][1]['callback_data'] === 'sgx||25535', 'Dismiss callback data');
        foreach ($keyboard[0] as $button) {
            self::assert(strlen($button['callback_data']) <= 64, 'Telegram limits callback_data to 64 bytes');
        }
        $noSend = json_decode(json_encode(TelegramSuggest::buildKeyboard(25535, false)), true)['inline_keyboard'];
        self::assert(count($noSend[0]) === 1 && $noSend[0][0]['callback_data'] === 'sgx||25535', 'Without send only dismiss stays');

        $generate = json_decode(json_encode(TelegramSuggest::buildGenerateKeyboard(25535, 298976)), true)['inline_keyboard'];
        self::assert($generate[0][0]['callback_data'] === 'sgg||25535||298976', 'Generate callback data');
        self::assert(strlen($generate[0][0]['callback_data']) <= 64, 'Generate callback data fits 64 bytes');
        $parts = explode('||', $generate[0][0]['callback_data']);
        self::assert($parts[0] === TelegramSuggest::CALLBACK_GENERATE && (int)$parts[1] === 25535 && (int)$parts[2] === 298976, 'CallbackqueryCommand splits it into action, chat, message');
        echo "  [PASS] buildKeyboard\n";
    }

    private static function testShiftHistory()
    {
        $body = '[{previous_visitor_messages_list_url__20__0}{{args.item.msg}}{/previous_visitor_messages_list_url}]';
        self::assert(TelegramSuggest::shiftHistory($body, 3) === '[{previous_visitor_messages_list_url__20__3}{{args.item.msg}}{/previous_visitor_messages_list_url}]', 'Offset is added');
        self::assert(TelegramSuggest::shiftHistory('{previous_visitor_messages_list_url__10}', 2) === '{previous_visitor_messages_list_url__10__2}', 'Missing offset becomes explicit');
        self::assert(TelegramSuggest::shiftHistory($body, 0) === str_replace('__20__0', '__20__0', $body), 'Zero offset keeps body');
        echo "  [PASS] shiftHistory\n";
    }

    private static function testExtractSystemText()
    {
        $body = "{\n  \"system_instruction\": {\n    \"parts\": [\n      {\n        \"text\": \"Ты — \\\"Смарти\\\".\\nПравила\"\n      }\n    ]\n  },\n  \"contents\": [{previous_visitor_messages_list_url__20__0}]\n}";
        self::assert(TelegramSuggest::extractSystemText($body) === 'Ты — \\"Смарти\\".\\nПравила', 'Escaped prompt is extracted as is');
        self::assert(json_decode('"' . TelegramSuggest::extractSystemText($body) . '"') === "Ты — \"Смарти\".\nПравила", 'Extracted text stays valid JSON string content');
        self::assert(TelegramSuggest::extractSystemText('{"contents": []}') === '', 'No system instruction gives empty string');
        echo "  [PASS] extractSystemText\n";
    }

    private static function testIsSuggestCommand()
    {
        foreach (array('!?', ' !? ') as $text) {
            self::assert(TelegramSuggest::isSuggestCommand($text) === true, 'Command: ' . json_encode($text));
        }
        foreach (array('!? спасибо', '?', '!note', 'Что?!?') as $text) {
            self::assert(TelegramSuggest::isSuggestCommand($text) === false, 'Not a command: ' . json_encode($text));
        }
        echo "  [PASS] isSuggestCommand\n";
    }

    private static function testPointedFileNote()
    {
        $map = array();
        $files = array();
        foreach (array(101 => 11, 102 => 12, 103 => 13) as $topicMsgId => $fileId) {
            $map[$topicMsgId] = array('kind' => 'file', 'file_id' => $fileId);
            $file = new \stdClass();
            $file->id = $fileId;
            $files[] = $file;
        }
        $msg = new \stdClass();
        $msg->meta_msg = json_encode(array('tg_topic_msg_contexts' => array('bot_1_chat_n_1' => array('ids' => array(101, 102, 103), 'map' => $map))));

        self::assert(strpos(TelegramSuggest::pointedFileNote($msg, $files, 102), 'вложение №2 из 3') !== false, 'Replied album item is named');
        self::assert(TelegramSuggest::pointedFileNote($msg, $files, 0) === '', 'No reply — no note');
        self::assert(TelegramSuggest::pointedFileNote($msg, array($files[0]), 101) === '', 'Single file — no note');
        self::assert(TelegramSuggest::pointedFileNote($msg, $files, 999) === '', 'Unknown topic message — no note');
        echo "  [PASS] pointedFileNote\n";
    }
}

TelegramSuggestTest::run();
