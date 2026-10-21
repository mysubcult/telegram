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
        echo "  [PASS] buildKeyboard\n";
    }
}

TelegramSuggestTest::run();
