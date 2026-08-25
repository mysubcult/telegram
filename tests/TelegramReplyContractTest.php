<?php

/**
 * Isolated contract checks for Telegram reply parsing and fallback decisions.
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
}

$extension = new erLhcoreClassExtensionLhctelegram();
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
