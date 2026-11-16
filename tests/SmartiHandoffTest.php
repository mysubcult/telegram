<?php
require_once __DIR__.'/../providers/SmartiHandoff.php';
use LiveHelperChatExtension\lhctelegram\providers\SmartiHandoff;
function checkHandoff($v,$m){if(!$v)throw new RuntimeException($m);}
$chat=(object)['gbot_id'=>121,'chat_variables_array'=>['sd_summary_ready'=>'1','ai_function_name'=>'call_service','ai_action_args'=>['summary'=>'Вы сообщили о неисправности. Пароль: secret123 https://example.com {unsafe} <b>Ремонт пока не выполнен.</b>']]];
$msg='Передаю в сервис.'.SmartiHandoff::MARKER;
SmartiHandoff::replaceSummary(['chat'=>$chat,'msg'=>&$msg]);
checkHandoff(strpos($msg,'Вы сообщили о неисправности.')!==false,'Service handoff lost its summary');
checkHandoff(strpos($msg,'secret123')===false&&strpos($msg,'https://')===false&&strpos($msg,'{')===false&&strpos($msg,'<b>')===false,'Unsafe summary content');
$chat->chat_variables_array['ai_function_name']='unknown_function';$msg=SmartiHandoff::MARKER;
SmartiHandoff::replaceSummary(['chat'=>$chat,'msg'=>&$msg]);checkHandoff($msg==='','Unknown function exposed a summary');
echo "Smarti service handoff summary checks passed\n";
