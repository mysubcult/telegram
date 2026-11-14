<?php
// Isolated interaction test: fake Telegram/DB transports; no network and no production writes.
namespace Longman\TelegramBot\Entities {
    class InlineKeyboard implements \JsonSerializable {
        public $rows;
        public function __construct(...$rows) { $this->rows = $rows; }
        public function jsonSerialize(): mixed { return ['inline_keyboard' => $this->rows]; }
    }
}
namespace Longman\TelegramBot {
    class Request {
        public static $calls = [], $failEdit = false;
        public static function __callStatic($method, $args) {
            self::$calls[] = [$method, $args[0]];
            return new \FakeResponse(!($method === 'editMessageText' && self::$failEdit));
        }
    }
}
namespace LiveHelperChatExtension\lhctelegram\providers {
    class TelegramLiveHelperChatOperator {
        public static $locked = false;
        public static function acquireTelegramMessageLock($id, $namespace) { return !self::$locked; }
        public static function releaseTelegramMessageLock($id, $namespace) {}
    }
}
namespace {
    #[AllowDynamicProperties] class FakeEntity {
        public $v;
        public function __construct($v = []) { $this->v = $v; }
        public function __call($name, $args) { return $this->v[substr($name, 3)] ?? null; }
    }
    class FakeResponse {
        public $ok;
        public function __construct($ok) { $this->ok = $ok; }
        public function isOk() { return $this->ok; }
        public function getResult() { return new FakeEntity(['MessageId' => 900]); }
    }
    #[AllowDynamicProperties] class erLhcoreClassModelChat {
        const STATUS_CLOSED_CHAT = 2, STATUS_ACTIVE_CHAT = 1, STATUS_PENDING_CHAT = 0, STATUS_BOT_CHAT = 5;
        public static $chat;
        public static function fetch($id) { return $id === 10 ? self::$chat : false; }
    }
    class erLhcoreClassModelTelegramOperator {
        public $user_id = 7, $user;
        public static function findOne($args) {
            if (($args['filter']['tuser_id'] ?? 0) !== 42) { return false; }
            $op = new self; $op->user = (object)['name_support' => 'Тест', 'invisible_mode' => 0]; return $op;
        }
    }
    class erLhcoreClassModelTelegramChat { public static function getList($args) { return [$GLOBALS['topic']]; } }
    #[AllowDynamicProperties] class erLhcoreClassModelmsg {}
    class erLhcoreClassExtensionLhctelegram { public static function getTelegramTopicNamespace($b, $g) { return 'test'; } }
    class FakeDb {
        public function prepare($sql) { return new FakeStatement($sql); }
    }
    class FakeStatement {
        public $sql, $args = [];
        public function __construct($sql) { $this->sql = $sql; }
        public function bindValue($key, $value, $type) { $this->args[$key] = $value; }
        public function execute() {}
        public function fetchColumn() {
            foreach (erLhcoreClassChat::$messages as $msg) {
                if ($msg->chat_id === ($this->args[':chat_id'] ?? -1) && json_decode($msg->meta_msg,true)['tg_topic_msg_id'] === ($this->args[':topic_msg_id'] ?? -1)) { return $msg->id; }
            }
            return false;
        }
    }
    class ezcDbInstance { public static function get() { return new FakeDb; } }
    class erLhcoreClassChat {
        public static $messages = [], $failSave = false;
        public static function getSession() { return new self; }
        public function save($msg) {
            if (self::$failSave) { throw new \RuntimeException('mock save failure'); }
            $msg->id = count(self::$messages)+1; self::$messages[] = $msg;
        }
    }
    class erLhcoreClassChatEventDispatcher {
        public static $events = [], $failDelivery = false;
        public static function getInstance() { return new self; }
        public function dispatch($name, $args) {
            self::$events[] = $name;
            if (self::$failDelivery && $name === 'chat.web_add_msg_admin') { throw new \RuntimeException('mock transport failure'); }
        }
    }
    require_once getenv('SUGGEST_PROVIDER') ?: __DIR__.'/../providers/TelegramSuggest.php';
    require_once getenv('HANDOFF_PROVIDER') ?: __DIR__.'/../providers/SmartiHandoff.php';
    use LiveHelperChatExtension\lhctelegram\providers\TelegramSuggest as S;
    use LiveHelperChatExtension\lhctelegram\providers\SmartiHandoff as H;
    use LiveHelperChatExtension\lhctelegram\providers\TelegramLiveHelperChatOperator as O;
    use Longman\TelegramBot\Request as R;
    $tmp = sys_get_temp_dir().'/telegram-draft-test-'.bin2hex(random_bytes(6));
    mkdir($tmp); mkdir($tmp.'/cache'); $cwd = getcwd(); chdir($tmp);
    $checks = 0;
    function check($condition, $label) { global $checks; $checks++; if (!$condition) { throw new \RuntimeException($label); } }
    $bot = (object)['id'=>1,'bot_client'=>1,'bot_username'=>'test_bot','group_chat_id'=>-1001];
    $topic = (object)['bot_id'=>1,'tchat_id'=>20,'bot'=>$bot];
    $chat = new erLhcoreClassModelChat; $chat->id=10; $chat->status=1; $chat->online_user_id=0; $chat->user_id=7;
    erLhcoreClassModelChat::$chat = $chat;
    function draftMessage($id, $rev=0, $text='Исходный ответ') {
        return new FakeEntity(['MessageId'=>$id,'MessageThreadId'=>20,'Chat'=>new FakeEntity(['Id'=>-1001]),
            'Text'=>S::HEADER."\n\n".$text,'From'=>new FakeEntity(['IsBot'=>true,'Username'=>'test_bot']),
            'ReplyMarkup'=>S::buildKeyboard(10,true,$rev)]);
    }
    function callback($msg, $action, $rev=0, $uid=42) {
        global $bot;
        return S::handleCallback(new FakeEntity(['Message'=>$msg,'From'=>new FakeEntity(['Id'=>$uid]),'Id'=>'cb']),$bot,$action,10,$rev);
    }
    function reply($to, $text, $uid=42, $type='text') {
        return new FakeEntity(['ReplyToMessage'=>$to,'MessageId'=>901,'MessageThreadId'=>20,'Chat'=>new FakeEntity(['Id'=>-1001]),
            'Text'=>$text,'From'=>new FakeEntity(['Id'=>$uid]),'Type'=>$type]);
    }
    function editReply($to,$text,$uid=42,$type='text') { global $bot,$chat; return S::handleDraftReply($chat,reply($to,$text,$uid,$type),$bot,$text); }
    function seed($id) { S::saveDraft(1,-1001,$id,S::initialDraft(10,20,S::HEADER."\n\nИсходный ответ")); }
    try {
        seed(100); $msg=draftMessage(100);
        callback($msg,S::CALLBACK_EDIT);
        check(count(erLhcoreClassChat::$messages)===0,'Edit click never delivers');
        $promptData=R::$calls[count(R::$calls)-1][1];
        check($promptData['reply_markup']['force_reply']===true && $promptData['message_thread_id']===20,'ForceReply in same topic');
        $prompt=draftMessage(900); $prompt->v['Text']='✏️ Черновик №100';
        check(editReply($prompt,'Новая проверенная формулировка'),'Edit reply consumed');
        check(count(erLhcoreClassChat::$messages)===0,'Draft reply never delivers');
        $state=S::loadDraft(1,-1001,100);
        check($state['revision']===1 && $state['text']==='Новая проверенная формулировка','Full replacement saved');
        check(callback($msg,S::CALLBACK_SEND)!==null && count(erLhcoreClassChat::$messages)===0,'Old Send rejected');
        $fresh=draftMessage(100,1,$state['text']);
        callback($fresh,S::CALLBACK_SEND,1);
        check(count(erLhcoreClassChat::$messages)===1 && erLhcoreClassChat::$messages[0]->msg===$state['text'],'Send publishes edited text');
        callback($fresh,S::CALLBACK_SEND,1);
        check(count(erLhcoreClassChat::$messages)===1,'Repeated Send no duplicate');
        check(editReply($prompt,'Поздняя правка') && count(erLhcoreClassChat::$messages)===1,'Late prompt is consumed');

        seed(101); callback(draftMessage(101),S::CALLBACK_EDIT);
        $p=draftMessage(900);$p->v['Text']='✏️ Черновик №101';
        editReply($p,'Чужая правка',43);
        check(S::loadDraft(1,-1001,101)['revision']===0,'Other editor cannot answer prompt');
        editReply($p,'Отмена'); editReply($p,'После отмены');
        check(S::loadDraft(1,-1001,101)['revision']===0,'Cancelled prompt invalidated');
        editReply(draftMessage(101),'Текст напрямую');
        editReply(draftMessage(101),'Устаревший ответ');
        check(S::loadDraft(1,-1001,101)['text']==='Текст напрямую','Stale direct reply rejected');
        editReply(draftMessage(101,1),'',42,'photo');
        check(S::loadDraft(1,-1001,101)['revision']===1,'Attachment consumed without update');

        seed(102);R::$failEdit=true;editReply(draftMessage(102),'Нельзя обновить');R::$failEdit=false;
        check(S::loadDraft(1,-1001,102)['revision']===0,'Telegram edit error rolls back');
        O::$locked=true;callback(draftMessage(102),S::CALLBACK_SEND);editReply(draftMessage(102),'Занято');O::$locked=false;
        check(count(erLhcoreClassChat::$messages)===1 && S::loadDraft(1,-1001,102)['revision']===0,'Lock timeout fails closed');
        $chat->status=2;callback(draftMessage(102),S::CALLBACK_SEND);editReply(draftMessage(102),'Закрыто');$chat->status=1;
        check(count(erLhcoreClassChat::$messages)===1 && S::loadDraft(1,-1001,102)['revision']===0,'Closed chat cannot send/edit');
        callback(draftMessage(102),S::CALLBACK_DISMISS);editReply(draftMessage(102),'Скрыто');callback(draftMessage(102),S::CALLBACK_SEND);
        check(S::loadDraft(1,-1001,102)['status']==='dismissed' && count(erLhcoreClassChat::$messages)===1,'Dismissal final');
        $wrong=draftMessage(103);$wrong->v['MessageThreadId']=21;
        callback($wrong,S::CALLBACK_SEND);callback(draftMessage(103),S::CALLBACK_SEND,0,43);
        check(count(erLhcoreClassChat::$messages)===1,'Topic and operator authorization enforced');

        seed(104);erLhcoreClassChatEventDispatcher::$failDelivery=true;callback(draftMessage(104),S::CALLBACK_SEND);erLhcoreClassChatEventDispatcher::$failDelivery=false;
        callback(draftMessage(104),S::CALLBACK_SEND);
        check(count(erLhcoreClassChat::$messages)===2 && S::loadDraft(1,-1001,104)['status']==='sent','Transport failure after save never duplicates');
        seed(105);erLhcoreClassChat::$failSave=true;callback(draftMessage(105),S::CALLBACK_SEND);erLhcoreClassChat::$failSave=false;
        check(S::loadDraft(1,-1001,105)['status']==='draft','Save failure remains retryable');
        callback(draftMessage(105),S::CALLBACK_SEND);
        check(count(erLhcoreClassChat::$messages)===3,'Retry after failed save succeeds once');
        callback(draftMessage(106,2),S::CALLBACK_SEND,2);
        check(count(erLhcoreClassChat::$messages)===3,'Lost versioned state fails closed');
        callback(draftMessage(107),S::CALLBACK_SEND);
        check(count(erLhcoreClassChat::$messages)===4,'Pre-deployment draft remains usable');
        $normal=draftMessage(108);$normal->v['From']=new FakeEntity(['IsBot'=>false,'Username'=>'test_bot']);
        check(!editReply($normal,'Обычный ответ'),'Forged user draft not intercepted');
        check(!editReply(null,'Обычный ответ'),'Ordinary messages unchanged');

        $chat->gbot_id=121;$chat->chat_variables_array=['sd_summary_ready'=>'1','ai_function_name'=>'call_operator','ai_action_args'=>['summary'=>'Не скачиваются марки. Проверили VPN, ошибка осталась.']];
        $render='Передаю специалисту.'.H::MARKER;H::replaceSummary(['msg'=>&$render,'chat'=>$chat]);
        check(strpos($render,'Кратко по обращению:')!==false && strpos($render,H::MARKER)===false,'Summary inside existing notification');
        $vars=$chat->chat_variables_array;$vars['sd_summary_ready']='0';$chat->chat_variables_array=$vars;
        $render='Передаю специалисту.'.H::MARKER;H::replaceSummary(['msg'=>&$render,'chat'=>$chat]);
        check($render==='Передаю специалисту.','Stale summary omitted');
        $clean=H::cleanSummary('Пароль: secret123, логин user123, https://example.com [file=1_hash]');
        check(strpos($clean,'secret123')===false && strpos($clean,'user123')===false && strpos($clean,'https:')===false && strpos($clean,'[file')===false,'Sensitive values and files removed');
        check(mb_strlen(H::cleanSummary(str_repeat('я',900)))===700,'Summary bounded');
        check(H::cleanSummary(['bad'])==='','Malformed summary ignored');
        echo "PASS: $checks draft/handoff interaction contracts; zero network calls.\n";
    } finally {
        foreach (glob($tmp.'/cache/*') as $file) { unlink($file); }
        rmdir($tmp.'/cache');chdir($cwd);rmdir($tmp);
    }
}
