<?php

/**
 * Isolated contract checks for Telegram reply parsing, namespace isolation,
 * long-message splitting, and stale-reply fallback decisions.
 * Run with: php tests/TelegramReplyContractTest.php
 */

require_once __DIR__ . '/../bootstrap/bootstrap.php';

function expectTelegramContract($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$message = (object)[
    'raw_data' => [
        'message_id' => 101,
        'message_thread_id' => 50,
        'reply_to_message' => ['message_id' => 90],
        'quote' => ['text' => 'quoted text']
    ]
];
$reply = erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($message);
expectTelegramContract($reply['message_id'] === 101, 'message id must come from raw_data');
expectTelegramContract($reply['reply_message_id'] === 90, 'reply id must come from reply_to_message');
expectTelegramContract($reply['is_explicit_reply'] === true, 'ordinary reply must be explicit');
expectTelegramContract($reply['quote_text'] === 'quoted text', 'top-level quote must be read from raw_data');

$nestedQuote = (object)[
    'raw_data' => [
        'message_id' => 102,
        'message_thread_id' => 50,
        'reply_to_message' => [
            'message_id' => 91,
            'quote' => ['text' => 'nested quoted text']
        ]
    ]
];
$reply = erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($nestedQuote);
expectTelegramContract($reply['quote_text'] === 'nested quoted text', 'nested quote must be supported');

$topicRoot = (object)[
    'raw_data' => [
        'message_id' => 103,
        'message_thread_id' => 50,
        'reply_to_message' => [
            'message_id' => 50,
            'forum_topic_created' => ['name' => 'Topic']
        ]
    ]
];
$reply = erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($topicRoot);
expectTelegramContract($reply['is_explicit_reply'] === false, 'topic root service message is not a quote');

$topicRootReply = (object)[
    'raw_data' => [
        'message_id' => 105,
        'message_thread_id' => 50,
        'reply_to_message' => [
            'message_id' => 50
        ]
    ]
];
$reply = erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($topicRootReply);
expectTelegramContract($reply['is_explicit_reply'] === true, 'ordinary reply to a topic root must be explicit');

$referenceMethod = new ReflectionMethod('erLhcoreClassExtensionLhctelegram', 'buildTelegramReplyReference');
$referenceMethod->setAccessible(true);
$localReference = $referenceMethod->invoke(null, 12, 90, '');
expectTelegramContract(
    $localReference['db_msg_id'] === 12
    && $localReference['telegram_message_id'] === 90
    && !array_key_exists('iwh_msg_id', $localReference),
    'local-only quote must not create an empty external reply ID'
);
$externalReference = $referenceMethod->invoke(null, 12, 90, 'tg-90');
expectTelegramContract($externalReference['iwh_msg_id'] === 'tg-90', 'external reply ID is preserved');
$formatMethod = new ReflectionMethod('erLhcoreClassExtensionLhctelegram', 'formatTelegramQuotedText');
$formatMethod->setAccessible(true);
expectTelegramContract(
    $formatMethod->invoke(null, 'reply body', 12, 'local quote', '') === '[quote]local quote[/quote]reply body',
    'local-only quote must keep a display marker without a core reply ID'
);
expectTelegramContract(
    preg_match('#\[quote="?([0-9]+)"?\]#i', $formatMethod->invoke(null, 'reply body', 12, 'local quote', '')) !== 1,
    'local-only quote must not add a numeric core reply marker'
);
expectTelegramContract(
    $formatMethod->invoke(null, 'reply body', 12, 'external quote', 'tg-90') === '[quote=12]external quote[/quote]reply body',
    'external quote keeps the core reply marker'
);
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::normalizeTelegramQuoteText('[quote=99]nested[/quote] text') === 'nested text',
    'nested quote markers must not be injected from Telegram text'
);

$stored = (object)[
    'msg' => 'fallback [file=12_0123456789abcdef0123456789abcdef]',
    'meta_msg_array' => [
        'tg_topic_msg_map' => [
            '90' => ['caption' => 'stored caption']
        ]
    ]
];
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::getStoredTelegramMessageText($stored, 90) === 'stored caption',
    'stored caption must win when Telegram omits quote'
);
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::getStoredTelegramMessageText($stored, 91) === 'fallback',
    'file embeds must be removed from fallback text'
);

$namespaceA = erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace(7, '-100123');
$namespaceB = erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace(8, '-100123');
expectTelegramContract($namespaceA !== $namespaceB, 'bot namespaces must be distinct');
$namespaced = (object)[
    'msg' => 'legacy fallback',
    'meta_msg_array' => [
        'tg_topic_msg_contexts' => [
            $namespaceA => ['map' => ['90' => ['text' => 'source A text']], 'latest_id' => 90],
            $namespaceB => ['map' => ['90' => ['text' => 'source B text']], 'latest_id' => 90]
        ]
    ]
];
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::getStoredTelegramMessageText($namespaced, 90, ['bot_id' => 7, 'group_chat_id' => '-100123']) === 'source A text',
    'source A namespace must resolve its own text'
);
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::getStoredTelegramMessageText($namespaced, 90, ['bot_id' => 8, 'group_chat_id' => '-100123']) === 'source B text',
    'source B namespace must resolve its own text'
);
$namespaced->meta_msg_array['tg_topic_msg_contexts'][$namespaceA]['map']['91'] = [
    'caption' => 'caption &amp; [file=12_0123456789abcdef0123456789abcdef]'
];
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::getStoredTelegramMessageText($namespaced, 91, ['bot_id' => 7, 'group_chat_id' => '-100123']) === 'caption &',
    'stored HTML captions and file embeds must be normalized for fallback quotes'
);
$namespaced->meta_msg_array['tg_topic_msg_contexts'][$namespaceA]['map']['92'] = [
    'text' => null,
    'kind' => 'text'
];
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::getStoredTelegramMessageText($namespaced, 92, ['bot_id' => 7, 'group_chat_id' => '-100123']) === 'legacy fallback',
    'null namespaced map text must fall back to the stored message body'
);
expectTelegramContract(
    erLhcoreClassExtensionLhctelegram::stripTelegramFileEmbedsText('operator body [file=12_0123456789abcdef0123456789abcdef]') === 'operator body',
    'Telegram command text helper must strip file embeds without Update delegation'
);
$genericMessageSource = file_get_contents(__DIR__ . '/../classes/Commands/GenericmessageCommand.php');
expectTelegramContract(
    is_string($genericMessageSource) && strpos($genericMessageSource, '::stripTelegramFileEmbedsText($text)') !== false,
    'Telegram generic command must use the extension text helper explicitly'
);

$extension = new erLhcoreClassExtensionLhctelegram();
$sendFileMethod = new ReflectionMethod($extension, 'sendTelegramChatFile');
$sendFileMethod->setAccessible(true);
$missingFile = (object)['file_path_server' => sys_get_temp_dir() . '/telegram-contract-missing-file'];
expectTelegramContract(
    $sendFileMethod->invoke($extension, (object)[], ['file' => $missingFile], '') === false,
    'missing local files must not be sent as broken download URLs'
);
$splitMethod = new ReflectionMethod($extension, 'getTelegramMessageChunks');
$splitMethod->setAccessible(true);
$lengthMethod = new ReflectionMethod($extension, 'getTelegramTextLength');
$lengthMethod->setAccessible(true);
$chunks = $splitMethod->invoke($extension, [
    'chat_id' => -100,
    'message_thread_id' => 77,
    'parse_mode' => 'HTML',
    'reply_to_message_id' => 91,
    'text' => str_repeat('A&B <i>quoted</i> ', 400)
]);
expectTelegramContract(count($chunks) > 1, 'long text must be split');
foreach ($chunks as $index => $chunk) {
    expectTelegramContract($lengthMethod->invoke($extension, $chunk['text']) <= 4000, 'split chunk must be within Telegram limit');
    expectTelegramContract(strpos($chunk['text'], '&apos;') === false, 'split HTML must not emit unsupported apostrophe entity');
    if ($index > 0) {
        expectTelegramContract(!isset($chunk['reply_to_message_id']), 'only first split chunk keeps reply target');
    }
}

$emojiChunks = $splitMethod->invoke($extension, [
    'chat_id' => -100,
    'text' => str_repeat('😀', 3000)
]);
expectTelegramContract(count($emojiChunks) > 1, 'surrogate-pair text must be split by UTF-16 length');
foreach ($emojiChunks as $chunk) {
    expectTelegramContract($lengthMethod->invoke($extension, $chunk['text']) <= 4000, 'emoji split chunk must be within UTF-16 limit');
}

$ampChunks = $splitMethod->invoke($extension, [
    'chat_id' => -100,
    'parse_mode' => 'HTML',
    'text' => str_repeat('&', 4096)
]);
expectTelegramContract(count($ampChunks) > 1, 'HTML entities must be measured after escaping');
foreach ($ampChunks as $chunk) {
    expectTelegramContract($lengthMethod->invoke($extension, $chunk['text']) <= 4000, 'escaped HTML chunk must be within UTF-16 limit');
}

$literalLessThanChunks = $splitMethod->invoke($extension, [
    'chat_id' => -100,
    'parse_mode' => 'HTML',
    'text' => str_repeat('<', 5000)
]);
expectTelegramContract(count($literalLessThanChunks) > 1, 'literal HTML less-than signs must be split');
foreach ($literalLessThanChunks as $chunk) {
    expectTelegramContract($lengthMethod->invoke($extension, $chunk['text']) <= 4000, 'literal less-than chunk must be within UTF-16 limit');
    expectTelegramContract(strpos($chunk['text'], '&lt;') !== false, 'literal less-than signs must be escaped');
}

$shortHtml = $splitMethod->invoke($extension, [
    'chat_id' => -100,
    'parse_mode' => 'HTML',
    'text' => '<i>short valid HTML</i>'
]);
expectTelegramContract(count($shortHtml) === 1 && $shortHtml[0]['text'] === '<i>short valid HTML</i>', 'short valid HTML must keep its markup');

$vendorAutoload = __DIR__ . '/../../../lib/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
    $entity = new \Longman\TelegramBot\Entities\Message([
        'message_id' => 104,
        'message_thread_id' => 50,
        'reply_to_message' => ['message_id' => 92],
        'quote' => ['text' => 'entity quote']
    ], 'contract_bot');
    $reply = erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($entity);
    expectTelegramContract($reply['reply_message_id'] === 92 && $reply['quote_text'] === 'entity quote', 'installed telegram-core entity compatibility');

    $fixture = tempnam(sys_get_temp_dir(), 'tg_contract_');
    file_put_contents($fixture, 'file');
    $handle = \Longman\TelegramBot\Request::encodeFile($fixture);
    expectTelegramContract(is_resource($handle), 'Request::encodeFile must return a readable resource');
    fclose($handle);
    unlink($fixture);

    // Guzzle consumes and closes multipart resources. The wrapper must reopen
    // the local file before retrying a stale reply target.
    $responses = [
        new \GuzzleHttp\Psr7\Response(200, [], '{"ok":false,"error_code":400,"description":"Bad Request: message to be replied not found"}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"ok":true,"result":{"message_id":123,"date":1,"chat":{"id":-100}}}')
    ];
    $requestBodies = [];
    $handler = function ($request, $options) use (&$responses, &$requestBodies) {
        $requestBodies[] = $request->getBody()->getContents();
        return \GuzzleHttp\Promise\Create::promiseFor(array_shift($responses));
    };
    new \Longman\TelegramBot\Telegram('123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', 'contract_bot');
    \Longman\TelegramBot\Request::setClient(new \GuzzleHttp\Client(['handler' => $handler]));

    $retryFixture = tempnam(sys_get_temp_dir(), 'tg_retry_');
    file_put_contents($retryFixture, 'retry-fixture-payload');
    $retryHandle = \Longman\TelegramBot\Request::encodeFile($retryFixture);
    $retryMethod = new ReflectionMethod($extension, 'sendTelegramRequest');
    $retryMethod->setAccessible(true);
    $retryResponse = $retryMethod->invoke($extension, 'sendDocument', [
        'chat_id' => -100,
        'message_thread_id' => 77,
        'document' => $retryHandle,
        'reply_to_message_id' => 91
    ], $retryFixture, 'document');
    expectTelegramContract($retryResponse->isOk(), 'multipart stale-reply retry must succeed');
    expectTelegramContract(count($requestBodies) === 2, 'multipart stale-reply retry must make two requests');
    expectTelegramContract(strpos($requestBodies[0], 'retry-fixture-payload') !== false && strpos($requestBodies[1], 'retry-fixture-payload') !== false, 'multipart retry must include the file payload twice');
    expectTelegramContract(strpos($requestBodies[1], 'reply_to_message_id') === false, 'multipart retry must remove stale reply target');
    if (is_resource($retryHandle)) {
        fclose($retryHandle);
    }
    unlink($retryFixture);
}

$fallbackMethod = new ReflectionMethod($extension, 'shouldRetryTelegramWithoutReply');
$fallbackMethod->setAccessible(true);
$topicMethod = new ReflectionMethod($extension, 'isTelegramTopicUnavailable');
$topicMethod->setAccessible(true);
$staleReply = new class {
    public function isOk() { return false; }
    public function getErrorCode() { return 400; }
    public function getDescription() { return 'Bad Request: message to be replied not found'; }
};
$otherError = new class {
    public function isOk() { return false; }
    public function getErrorCode() { return 400; }
    public function getDescription() { return 'Bad Request: chat not found'; }
};
expectTelegramContract($fallbackMethod->invoke($extension, $staleReply) === true, 'stale reply must trigger fallback');
expectTelegramContract($fallbackMethod->invoke($extension, $otherError) === false, 'unrelated API error must not retry');
expectTelegramContract($topicMethod->invoke($extension, $staleReply) === false, 'stale reply is not a deleted topic');
$deletedTopic = new class {
    public function isOk() { return false; }
    public function getErrorCode() { return 400; }
    public function getDescription() { return 'Bad Request: message thread not found'; }
};
expectTelegramContract($topicMethod->invoke($extension, $deletedTopic) === true, 'deleted topic must be detected');

fwrite(STDOUT, "Telegram reply contract tests: OK\n");
