<?php
// Isolated transport identity checks: no database or Telegram requests.
class erLhcoreClassModelTelegramBotDep {
    public static function findOne($query) {
        return in_array($query['filter']['dep_id'], [1, 2, 8], true) ? new self() : null;
    }
}
class erLhcoreClassModelDepartament {
    public static function fetch($id) { return (object)['id'=>$id]; }
}
require_once __DIR__ . '/../providers/TelegramTopicIcons.php';
use LiveHelperChatExtension\lhctelegram\providers\TelegramTopicIcons as Icons;
$settings=[1=>['departments'=>[1,2,8,13,14,15], 'scopes'=>[''=>1,'telegram'=>2,'max'=>8]]];
$count=0;
foreach ([1,2,8,13,14,15] as $dep) {
    foreach ([''=>1,'telegram'=>2,'max'=>8,'unknown'=> $dep] as $scope=>$expected) {
        $chat=(object)['dep_id'=>$dep,'department'=>(object)['id'=>$dep],
            'incoming_chat'=>$scope === '' ? null : (object)['incoming'=>(object)['scope'=>$scope]]];
        $actual=Icons::departmentForChat((object)['id'=>1], $chat, $settings);
        if ($actual->id !== $expected) throw new RuntimeException("Wrong source for $dep/$scope");
        $count++;
    }
}
foreach ([[1,4],[1,9],[1,10],[1,11],[3,6]] as [$bot,$dep]) {
    $chat=(object)['dep_id'=>$dep,'department'=>(object)['id'=>$dep],'incoming_chat'=>null];
    if (Icons::departmentForChat((object)['id'=>$bot],$chat,$settings)->id !== $dep)
        throw new RuntimeException('Unrelated department was changed');
    $count++;
}
echo "PASS: $count source/routing/unknown/mail/partner/test cases; no outbound\n";
