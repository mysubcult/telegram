<?php
// Run from lhc_web. All fixture tables are temporary and every Telegram request is mocked.
use LiveHelperChatExtension\lhctelegram\providers\TelegramReplyHistory as History;
use LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator as Operator;

require_once __DIR__ . '/../../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../../ezcomponents/Base/src/base.php';
spl_autoload_register(['ezcBase', 'autoload'], true, false);
erLhcoreClassSystem::init();
ezcBaseInit::setCallback('ezcInitDatabaseInstance', 'erLhcoreClassLazyDatabaseConfiguration');
require_once __DIR__ . '/../bootstrap/bootstrap.php';
require_once __DIR__ . '/../providers/TelegramReplyHistory.php';
require_once __DIR__ . '/../providers/TelegramLiveHelperChatOperator.php';

function checkHistory($pass, $description) {
    if (!$pass) {
        throw new RuntimeException($description);
    }
}

$extension = new erLhcoreClassExtensionLhctelegram();
$extension->settings = [];
(new ReflectionProperty(erLhcoreClassModule::class, 'extensionsBootstraps'))->setValue(null, [get_class($extension) => $extension]);
$db = ezcDbInstance::get();
foreach (['lh_msg', 'lh_chat_incoming', 'lhc_telegram_chat', 'lhc_telegram_bot', 'lhc_telegram_bot_dep', 'lh_audits'] as $table) {
    $schema = $db->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $db->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', preg_replace('/ AUTO_INCREMENT=\d+/', '', $schema)));
}
$db->exec('CREATE TEMPORARY TABLE lh_chat (id INT PRIMARY KEY, online_user_id INT, iwh_id INT)');
$db->exec('CREATE TEMPORARY TABLE lh_chat_online_user (id INT PRIMARY KEY, vid CHAR(32))');
$db->exec('CREATE TEMPORARY TABLE lh_chat_archive_range (id INT, first_id INT)');
$db->exec('CREATE TEMPORARY TABLE lh_chat_archive_91 LIKE lh_chat');
$db->exec('CREATE TEMPORARY TABLE lh_chat_archive_msg_91 LIKE lh_msg');
$db->exec('INSERT INTO lh_chat_archive_range VALUES (91,10)');
$db->exec('INSERT INTO lh_chat_archive_91 VALUES (10,1,11)');
$db->exec('INSERT INTO lh_chat VALUES (20,1,11),(21,2,12),(22,3,11),(23,1,12),(30,1,11),(31,2,12)');
$db->exec("INSERT INTO lh_chat_online_user VALUES (1,MD5('11_peer')),(2,MD5('12_peer')),(3,MD5('11_elsewhere'))");
$db->exec("INSERT INTO lh_chat_incoming (chat_id,incoming_id,chat_external_id,utime,payload) VALUES (30,11,'peer',0,'{}'),(31,12,'peer',0,'{}')");

$bot = new erLhcoreClassModelTelegramBot();
$bot->bot_client = 1;
$bot->group_chat_id = -100123;
$bot->bot_username = 'history_test_bot';
$bot->bot_api = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
$bot->dep_id = 4;
$bot->saveThis();
$botDep = new erLhcoreClassModelTelegramBotDep();
$botDep->bot_id = $bot->id;
$botDep->dep_id = 4;
$botDep->saveThis();
$topic = ['bot_id' => $bot->id, 'group_chat_id' => '-100123', 'thread_id' => 100];
$max = array_merge($topic, ['thread_id' => 200]);
$db->exec("INSERT INTO lhc_telegram_chat (chat_id,chat_id_internal,type,bot_id,tchat_id,ctime,utime) VALUES (30,-1,1,$bot->id,100,0,0),(31,-2,1,$bot->id,200,0,0)");
$ns = erLhcoreClassExtensionLhctelegram::getTelegramTopicNamespace($bot->id, '-100123');

function historyMsg($id, $chat, $meta, $archived = false, $user = 0) {
    global $db;
    $table = $archived ? 'lh_chat_archive_msg_91' : 'lh_msg';
    $query = $db->prepare("INSERT INTO $table (id,chat_id,user_id,msg,name_support,meta_msg,time) VALUES (?,?,?,'fixture','',?,1)");
    $query->execute([$id,$chat,$user,json_encode($meta)]);
}
function mapping($external, $id, $ns, $entry = []) {
    return ['iwh_msg_id' => $external, 'tg_topic_msg_contexts' => [$ns => ['ids' => [$id], 'map' => [$id => $entry]]]];
}
historyMsg(1, 10, mapping(71, 151, $ns), true);
historyMsg(2, 21, mapping('mid.Case', 251, $ns));
historyMsg(3, 22, mapping(71, 158, $ns));
historyMsg(4, 23, mapping(71, 159, $ns));
historyMsg(5, 20, mapping(81, 99, $ns));  // Before the new topic was created.
historyMsg(6, 20, mapping(82, 162, $ns, ['thread_id' => 90]));
historyMsg(7, 20, mapping(83, 163, 'bot_2_chat_n_100123'));
historyMsg(8, 20, ['iwh_msg_id' => 84, 'tg_topic_msg_id' => 164]);
historyMsg(9, 20, mapping(85, 165, $ns), false, -1);
historyMsg(10, 20, mapping('86', 166, $ns));
historyMsg(11, 20, mapping('87', 166, $ns));
historyMsg(12, 20, ['iwh_msg_ids' => [88], 'tg_topic_msg_contexts' => [$ns => ['ids' => [168]]]]);
$media = mapping('mid.second', 252, $ns, ['file_id' => 1, 'security_hash' => 'a']);
$media['iwh_msg_ids'] = ['mid.first', 'mid.second'];
$media['lhcmax_media'] = ['1_a' => ['mid' => 'mid.first'], '2_b' => ['mid' => 'mid.second']];
$media['tg_topic_msg_contexts'][$ns]['ids'][] = 253;
$media['tg_topic_msg_contexts'][$ns]['map'][253] = ['file_id' => 2, 'security_hash' => 'b'];
historyMsg(13, 21, $media);

checkHistory(History::find(30, $topic, '71')['topic_id'] === 151, 'archive source and same external IDs on other peers');
checkHistory(History::find(30, $topic, null, 151)['external_id'] === '71', 'archived target');
checkHistory(History::find(30, $topic, '88')['topic_id'] === 168, 'numeric ID in a JSON array');
checkHistory(History::find(31, $max, 'mid.Case')['topic_id'] === 251, 'MAX native incoming reply');
checkHistory(History::find(31, $max, 'mid.first')['topic_id'] === 252, 'first attachment');
checkHistory(History::find(31, $max, null, 253)['external_id'] === 'mid.second', 'second attachment');
foreach (['81','82','83','84','85','mid.Case'] as $id) {
    checkHistory(History::find(30, $topic, $id) === null, 'foreign, missing or recreated mapping ' . $id);
}
checkHistory(History::find(30, $topic, null, 166) === null, 'ambiguous old aggregate');
checkHistory(History::find(31, $max, 'mid.case') === null, 'case-sensitive external ID');
checkHistory(History::find(31, $topic, 'mid.Case') === null, 'wrong topic');
checkHistory(History::find(30, array_merge($topic,['bot_id'=>2]), '71') === null, 'wrong operator bot');
checkHistory(History::find(30, array_merge($topic,['group_chat_id'=>'-100124']), '71') === null, 'wrong operator group');

$chat = new erLhcoreClassModelChat();
$chat->id = 30;
$chat->online_user_id = 1;
$chat->online_user = false;
$chat->dep_id = 4;
$chat->department = 'Test';
$chat->iwh_id = 11;
$chat->nick = 'Fixture';
$reply = new erLhcoreClassModelmsg();
$reply->user_id = 1;
$reply->chat_id = 30;
$reply->meta_msg = json_encode(['content' => ['reply_to' => [
    'history_topic' => $topic, 'telegram_message_id' => 151, 'db_msg_id' => 1, 'iwh_msg_id' => '71'
]]]);
$method = ['body_raw' => '{"text":"response"{reply_to},"reply_parameters":{"message_id":raw_{{iwh_msg_id}}}{/reply_to}}',
    'body_raw_file' => '{"file":"fixture"{reply_to},"reply_parameters":"{\\"message_id\\":raw_{{iwh_msg_id}}}"{/reply_to}}'];
History::prepareRestReply(['chat' => $chat, 'params' => ['msg' => $reply], 'method' => &$method]);
checkHistory(json_decode($method['body_raw'], true)['reply_parameters']['message_id'] === 71, 'REST JSON');
checkHistory(json_decode(json_decode($method['body_raw_file'], true)['reply_parameters'], true)['message_id'] === 71, 'REST multipart');
$valid = false;
History::validateExternalReply(['msg' => $reply, 'chat' => $chat, 'external_id' => 'wrong', 'valid' => &$valid]);
checkHistory(!$valid, 'unverified external ID');

historyMsg(14, 30, ['iwh_msg_id' => 1010, 'content' => ['reply_to' => ['iwh_msg_id' => 71]]]);
historyMsg(15, 30, ['iwh_msg_id' => 1011]);
$requests = [];
$handler = static function ($request) use (&$requests) {
    parse_str((string)$request->getBody(), $body);
    $requests[] = $body;
    $result = ['message_id' => 400 + count($requests), 'date' => 1, 'chat' => ['id' => -100123, 'type' => 'supergroup']];
    return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['ok' => true, 'result' => $result])));
};
\Longman\TelegramBot\Request::setClient(new \GuzzleHttp\Client(['handler' => $handler]));
Operator::chatStarted(['chat' => $chat]);
checkHistory(count($requests) === 3, 'separate opening card, reply, next message');
checkHistory(!isset($requests[0]['reply_parameters']) && !isset($requests[2]['reply_parameters']), 'plain card and message');
checkHistory(json_decode($requests[1]['reply_parameters'], true)['message_id'] === 151, 'first message replies to the archived source');
checkHistory((int)$db->query('SELECT last_msg_id FROM lhc_telegram_chat WHERE chat_id=30')->fetchColumn() === 15, 'delivery cursor advanced after both messages');
$db->exec("INSERT INTO lhc_telegram_chat (chat_id,chat_id_internal,type,bot_id,tchat_id,ctime,utime) VALUES (20,-1,1,$bot->id,90,0,0)");
checkHistory(History::find(30, $topic, '71') === null, 'ambiguous peer to topic assignment');
$otherThread = ['message_thread_id' => 100, 'reply_to_message' => ['message_id' => 151, 'message_thread_id' => 90]];
checkHistory(!erLhcoreClassExtensionLhctelegram::extractTelegramReplyData($otherThread)['is_explicit_reply'], 'explicit foreign topic');
echo "Telegram historical reply tests: OK\n";
