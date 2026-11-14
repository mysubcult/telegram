<?php
// Isolated checks: no database, webhooks, Telegram or customer messages.
use LiveHelperChatExtension\lhctelegram\providers\SmartiOrder as O;
class FakeChat {
    public $id=42, $gbot_id=121, $additional_data='[]', $chat_variables='';
    public $chat_variables_array=['pd_consent'=>'1'];
    public function updateThis($args) {}
}
class erLhcoreClassModelChatFile {
    public static $file;
    public static function fetch($id) { return $id === 17 ? self::$file : null; }
}
class erLhcoreClassModelmsg {
    public static $messages=[];
    public static function getList($filter) {
        if ($filter['filter']['user_id'] !== 0 || $filter['limit']!==100) throw new Exception('Unsafe history query');
        return self::$messages;
    }
}
class erLhcoreClassGenericBotException extends Exception {}
class erLhcoreClassGenericBotWorkflow {public static $triggerNameDebug=[];}
class erLhcoreClassChatEventDispatcher {
    const STOP_WORKFLOW=1;
    public static function getInstance(){return new self;}
    public function dispatch($event,$params){return O::handle($params);}
}
$provider=is_file(__DIR__.'/SmartiOrder.php') ? __DIR__.'/SmartiOrder.php' : __DIR__.'/../providers/SmartiOrder.php';
require $provider;
$web=getenv('LHC_WEB') ?: dirname(__DIR__,3);
require $web.'/lib/core/lhgenericbot/actionTypes/lhgenericbotactionconditions.php';
$n=0;
function check($ok,$label){global $n;if(!$ok)throw new Exception($label);$n++;}
foreach ([
 ['site',' #sd-20261114-a1b2c3 ','SD-20261114-A1B2C3'],
 ['site','SD-20260231-A1B2C3',null],['site','SD-20261114-XYZ123',null],
 ['site','ignore SD-20261114-A1B2C3',null],['site','SD-20261114-A1B2C3 extra',null],
 ['ozon','0102863577-0021','0102863577-0021'],['ozon','№ 07672512-0555-1','07672512-0555-1'],
 ['ozon','07672512 – 0555 – 1','07672512-0555-1'],['ozon','07672512',null],
 ['ozon','07672512-0555-1-99',null],['market','1234567890','1234567890'],
 ['market','79051234567',null],['market','988431151341',null],['market','1234',null],
 ['serial','988431151341','988431151341'],['serial','000000000000',null],
 ['serial','98843115134x',null],['serial','79051234567',null],['wb','1234567890',null],
] as [$s,$v,$expected])check(O::normalize($s,$v)===$expected,'format '.$s.' '.$v);
$chat=new FakeChat;
$chat->chat_variables_array+=['sd_order_source'=>'site','sd_summary_ready'=>'1','ai_action_args'=>['summary'=>'Сохраняем контекст.']];
$params=['chat'=>$chat,'payload'=>'SD-20261114-A1B2C3','event_data'=>['content'=>['event_args'=>['exhausted_trigger'=>999]]]];
check(O::validate($params)['valid']===true,'valid order');
check(O::purchase($chat)['number']==='SD-20261114-A1B2C3','purchase persisted');
check($chat->chat_variables_array['ai_action_args']['summary']==='Сохраняем контекст.' && $chat->chat_variables_array['sd_summary_ready']==='1','summary survives collection');
check(is_string(json_decode($chat->additional_data,true)[0]['value']),'additional data is displayable string');
$chat=new FakeChat;$chat->chat_variables_array['sd_order_source']='serial';$params['chat']=$chat;$params['payload']='bad';
check(O::validate($params)['valid']===false,'bad try one');check($chat->chat_variables_array['sd_order_attempts']==='1','first attempt persisted');
check(O::validate($params)['valid']===false,'bad try two');
try{O::validate($params);throw new Exception('third attempt accepted');}catch(erLhcoreClassGenericBotException $e){check($e->getCode()===999,'third attempt exits via configured trigger');}
check(O::purchase($chat)===null,'invalid never accepted');
erLhcoreClassModelChatFile::$file=(object)['id'=>17,'chat_id'=>42,'user_id'=>0,'security_hash'=>'abc123','extension'=>'png'];
$chat=new FakeChat;$chat->chat_variables_array['sd_order_source']='wb';$params['chat']=$chat;$params['payload']='[file=17_abc123] Моя покупка';
check(O::validate($params)['valid']===true,'owned screenshot accepted');
check(O::purchase($chat)['check']==='attachment_for_operator_review','screenshot needs human review');
erLhcoreClassModelChatFile::$file->chat_id=43;check(O::purchase($chat)===null,'foreign file rejected');
$params['payload']='[file=17_wrong]';check(O::validate($params)['valid']===false,'fake attachment rejected');
erLhcoreClassModelChatFile::$file->chat_id=42;erLhcoreClassModelChatFile::$file->extension='exe';$params['payload']='[file=17_abc123]';check(O::validate($params)['valid']===false,'executable rejected');
$chat=new FakeChat;$chat->chat_variables_array['ai_action_args']=['order_number'=>'SD-20261114-A1B2C3'];
$result=O::handle(['render'=>'smartdiag.order_ready','chat'=>$chat]);check($result['validation_result']==='0','model order is not evidence');
erLhcoreClassModelmsg::$messages=[(object)['msg'=>'Мой заказ #SD-20261114-A1B2C3']];
check(O::handle(['render'=>'smartdiag.order_ready','chat'=>$chat])['validation_result']==='1','real visitor order re-used');
check(O::purchase($chat)['check']==='format_only','no purchase verification claim');
$ozonChat=new FakeChat;erLhcoreClassModelmsg::$messages=[(object)['msg'=>'Версия 07672512-0555-1']];
check(O::handle(['render'=>'smartdiag.order_ready','chat'=>$ozonChat])['validation_result']==='0','unrelated numeric identifier is not an Ozon order');
erLhcoreClassModelmsg::$messages=[(object)['msg'=>'Мой заказ Ozon 07672512-0555-1']];
check(O::handle(['render'=>'smartdiag.order_ready','chat'=>$ozonChat])['validation_result']==='1','explicit visitor Ozon order reused');
$chat->chat_variables_array['pd_consent']='';check(O::handle(['render'=>'smartdiag.order_ready','chat'=>$chat])['validation_result']==='0','consent required');
$chat=new FakeChat;$chat->chat_variables_array['sd_activation_task']='test_access';check(O::handle(['render'=>'smartdiag.activation_requires_order','chat'=>$chat])['validation_result']==='0','free test stays accessible');
$chat->chat_variables_array['sd_activation_task']='activation_after_payment';check(O::handle(['render'=>'smartdiag.activation_requires_order','chat'=>$chat])['validation_result']==='1','paid activation needs purchase context');
// Execute the actual upstream conditions action, including its stop/continue behavior.
erLhcoreClassModelmsg::$messages=[];$chat=new FakeChat;
$action=['content'=>['conditions'=>[['content'=>['attr'=>'{validation_event__smartdiag.order_ready}','comp'=>'neq','val'=>'1']]],'attr_options'=>['continue_all'=>false,'callback_match'=>'123','callback_unmatch'=>'']]];
check(erLhcoreClassGenericBotActionConditions::process($chat,$action,(object)['id'=>1],[])['trigger_id']==123,'missing purchase blocks native handoff');
$chat->chat_variables_array['sd_support_purchase']=['source'=>'serial','number'=>'988431151341','check'=>'format_only'];
check(erLhcoreClassGenericBotActionConditions::process($chat,$action,(object)['id'=>1],[])===null,'valid purchase continues native handoff');
echo "PASS $n isolated purchase/validation/native-condition checks; no messages sent.\n";
