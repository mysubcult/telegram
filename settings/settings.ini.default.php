<?php

return array(
    'disable_op_flow' => false,
    'disable_leads' => false,
    'convert_ogg' => false,
    'convert_to' => 'wav',
    'convert_command' => 'avconv -i {file_orig} {file_dest}',
    // Reply suggestions in operator topics, see providers/TelegramSuggest.php
    'suggest' => array(
        'enabled' => false,
        'mode' => 'command', // 'command' = only «!?» in the topic, 'button' = «💡 Ответ» under visitor messages, 'auto' = after every visitor message
        'rest_api_id' => 0,
        'dep_ids' => array(), // empty = all departments
        'delay' => 4, // auto mode: seconds to wait for operator's own reply
        'captions' => false // describe every visitor file once (caption_ocr), see providers/TelegramFileCaption.php
    ),
    // Command menu of the operators group, see modules/lhcron/sync_commands.php
    'topic_commands' => array(), // LHC commands in the menu, in order; empty = all with a description
    'topic_commands_bot_ids' => array(), // empty = all operator bots
    'elastic_search' => array(
        'search_attr' => 'attr_int_1'
    )
);

?>