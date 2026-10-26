<?php

/**
 * Checks of the mail topic text: quoted history is cut, HTML-only e-mails become text,
 * the customer is taken from Reply-To when a form sends from its own address.
 * Run with: php tests/TelegramMailTopicsTest.php
 */

require_once __DIR__ . '/../providers/TelegramMailTopics.php';

use LiveHelperChatExtension\lhctelegram\providers\TelegramMailTopics;

function expectMailTopic($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mail = (object)[
    'alt_body' => "Thanks, it works now.\n\nOn Mon, 26 Oct 2026 at 10:00, Support <support@example.com> wrote:\n> Please try again",
    'body' => ''
];
expectMailTopic(TelegramMailTopics::getMailText($mail) === 'Thanks, it works now.', 'quoted history after "wrote:" must be cut');

$mail = (object)['alt_body' => "Спасибо!\n\n> цитата\n> ещё", 'body' => ''];
expectMailTopic(TelegramMailTopics::getMailText($mail) === 'Спасибо!', 'lines starting with ">" must be cut');

$mail = (object)['alt_body' => '', 'body' => '<html><head><style>p{color:red}</style></head><body><p>Hello</p><p>World &amp; more</p></body></html>'];
expectMailTopic(TelegramMailTopics::getMailText($mail) === "Hello\nWorld & more", 'HTML-only e-mail must become plain text');

$mail = (object)['alt_body' => str_repeat('a', TelegramMailTopics::BODY_LIMIT + 50), 'body' => ''];
expectMailTopic(mb_strlen(TelegramMailTopics::getMailText($mail)) === TelegramMailTopics::BODY_LIMIT, 'long e-mail must be cut to the limit');

$mailbox = (object)['mail' => 'support@example.com'];
$mail = (object)[
    'mailbox' => $mailbox,
    'from_address' => 'site@example.com',
    'from_name' => 'Ivan (site)',
    'reply_to_data_keyed' => ['ivan@customer.test' => null]
];
$customer = TelegramMailTopics::getCustomer($mail);
expectMailTopic($customer['email'] === 'ivan@customer.test' && $customer['name'] === 'Ivan (site)', 'customer must come from Reply-To');

$mail = (object)[
    'mailbox' => $mailbox,
    'from_address' => 'ivan@customer.test',
    'from_name' => 'Ivan',
    'reply_to_data_keyed' => ['ivan@customer.test' => 'Ivan']
];
$customer = TelegramMailTopics::getCustomer($mail);
expectMailTopic($customer['email'] === 'ivan@customer.test', 'plain e-mail: customer is the sender');

echo "TelegramMailTopicsTest: OK\n";
