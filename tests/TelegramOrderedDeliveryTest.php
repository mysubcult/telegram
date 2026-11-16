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
 echo "PASS: oldest-first delivery, failure retains cursor, retry drains backlog without duplicate; all HTTP mocked, DB rolled back\n";
}finally{$db->rollBack();}
