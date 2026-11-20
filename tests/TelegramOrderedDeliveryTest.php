<?php
namespace LiveHelperChatExtension\lhctelegram\tests;
require_once __DIR__.'/../../../lib/vendor/autoload.php';
require_once __DIR__.'/../../../ezcomponents/Base/src/base.php';
spl_autoload_register(['ezcBase','autoload'],true,false);
\erLhcoreClassSystem::init();\ezcBaseInit::setCallback('ezcInitDatabaseInstance','erLhcoreClassLazyDatabaseConfiguration');
require_once __DIR__.'/../bootstrap/bootstrap.php';
require_once __DIR__.'/../classes/erlhcoreclassmodeltelegrambot.php';
require_once __DIR__.'/../classes/erlhcoreclassmodeltelegramchat.php';
require_once __DIR__.'/../providers/TelegramLiveHelperChatOperator.php';
use LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator as O;
use GuzzleHttp\Client;use GuzzleHttp\Handler\MockHandler;use GuzzleHttp\HandlerStack;use GuzzleHttp\Middleware;use GuzzleHttp\Psr7\Response;
function ok($v,$m){if(!$v)throw new \RuntimeException($m);}
$history=[];$mock=new MockHandler();$stack=HandlerStack::create($mock);$stack->push(Middleware::history($history));\Longman\TelegramBot\Request::setClient(new Client(['handler'=>$stack]));
function response($id){return new Response(200,[],json_encode(['ok'=>true,'result'=>['message_id'=>$id,'date'=>time(),'chat'=>['id'=>-1001946886605,'type'=>'supergroup'],'text'=>'mock']]));}
$db=\ezcDbInstance::get();$db->beginTransaction();
try{
 $c=new \erLhcoreClassModelChat();$c->nick='Codex delivery test';$c->dep_id=4;$c->time=time();$c->status=2;$c->hash=sha1(random_bytes(32));$c->saveThis();
 $msgs=[];foreach(['first','second','third'] as $text){$m=new \erLhcoreClassModelmsg();$m->chat_id=$c->id;$m->user_id=0;$m->time=time();$m->msg=$text;$m->saveThis();$msgs[]=$m;}
 $t=new \erLhcoreClassModelTelegramChat();$t->bot_id=1;$t->chat_id_internal=$c->id;$t->chat_id=$c->id;$t->type=1;$t->ctime=time();$t->utime=time();$t->tchat_id=99999999;$t->last_msg_id=0;$t->saveThis();
 $mock->append(response(901),new Response(200,[],json_encode(['ok'=>false,'error_code'=>403,'description'=>'mock rejection'])));
 O::messageAdded(['chat'=>$c,'msg'=>$msgs[2]]);
 $cursor=$db->query('SELECT last_msg_id FROM lhc_telegram_chat WHERE id='.$t->id)->fetchColumn();ok((int)$cursor===$msgs[0]->id,'failed send advanced cursor');
 $mock->append(response(902),response(903));O::messageAdded(['chat'=>$c,'msg'=>$msgs[2]]);
 $cursor=$db->query('SELECT last_msg_id FROM lhc_telegram_chat WHERE id='.$t->id)->fetchColumn();ok((int)$cursor===$msgs[2]->id,'retry did not drain backlog');
 ok(count($history)===4,'dedup request count');$texts=[];foreach($history as $h){parse_str((string)$h['request']->getBody(),$data);$texts[]=$data['text']??(string)$h['request']->getBody();}
 foreach(['first','second','second','third'] as $i=>$word)ok(str_contains($texts[$i],$word),'out of order '.$i);
 $long=new \erLhcoreClassModelmsg();$long->chat_id=$c->id;$long->user_id=0;$long->time=time();$long->msg=str_repeat('word ',1600);$long->saveThis();
 $data=['chat_id'=>$t->bot->group_chat_id,'message_thread_id'=>$t->tchat_id,'text'=>$long->msg];$context=['bot_id'=>1,'group_chat_id'=>(string)$t->bot->group_chat_id];
 $mock->append(response(904),new Response(200,[],json_encode(['ok'=>false,'error_code'=>403,'description'=>'mock rejection'])));
 $r=O::sendTelegramMessageWithSplit($data,$long,$t,$context);ok(!$r->isOk(),'partial message reported complete');
 $long=\erLhcoreClassModelmsg::fetch($long->id);ok(!O::isTopicMessageAlreadySent($long,$context),'partial message dedup suppressed retry');
 $chunks=count(O::getTelegramMessageChunks($data));for($i=1;$i<$chunks;$i++)$mock->append(response(905+$i));$r=O::sendTelegramMessageWithSplit($data,$long,$t,$context);ok($r->isOk(),'partial retry failed');ok(count($history)===5+$chunks,'partial retry resent first chunk');
 // A network timeout has no Bot API response. It must not escape through the customer's webhook.
 $mock->append(new \GuzzleHttp\Exception\ConnectException(
     'Mock timeout while sending the operator copy',
     new \GuzzleHttp\Psr7\Request('POST', 'https://example.invalid/')
 ));
 $reply = new \erLhcoreClassModelmsg();
 $reply->chat_id = $c->id;
 $reply->user_id = -2;
 $reply->time = time();
 $reply->msg = 'Already delivered to the visitor';
 $reply->meta_msg = json_encode(['iwh_msg_id' => 444]);
 $reply->saveThis();
 $before = count($history);
 O::messageAdded(['chat' => $c, 'msg' => $reply]);
 $afterTimeout = \erLhcoreClassModelmsg::fetch($reply->id, false);
 ok($afterTimeout->meta_msg_array === ['iwh_msg_id' => 444], 'timeout changed the delivery receipt');
 $cursor = $db->query('SELECT last_msg_id FROM lhc_telegram_chat WHERE id=' . $t->id)->fetchColumn();
 ok((int)$cursor === $long->id, 'timeout advanced past the unconfirmed message');
 ok(count($history) === $before + 1, 'ambiguous send immediately repeated');
 $mock->append(response(950));
 O::messageAdded(['chat' => $c, 'msg' => $reply]);
 $recovered = \erLhcoreClassModelmsg::fetch($reply->id, false);
 ok($recovered->meta_msg_array['iwh_msg_id'] === 444, 'visitor delivery receipt lost');
 ok(O::getStoredTopicMessageId($recovered, null, $context) === 950, 'operator receipt missing after recovery');
 O::messageAdded(['chat' => $c, 'msg' => $reply]);
 ok(count($history) === $before + 2, 'acknowledged message resent');
 echo "PASS: ordered delivery, partial-send recovery and webhook isolation on timeout; all HTTP mocked, DB rolled back\n";
}finally{$db->rollBack();}
