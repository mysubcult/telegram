# Operator topic identity

The channel and business department are different dimensions. Topic names show
the original channel (`Сайт`, `TG`, `Max`), visitor name and chat ID. Native LHC
departments still control sales/support/service routing, hours and operator alias.
The assistant does not infer the channel from customer text.

`TelegramTopicIcons::departmentForChat()` reads native
`chat.incoming_chat.incoming.scope`. The optional `topic_sources` extension
setting maps that scope to the bot-department row used for title and icon.
For SmartDiag bot 1, scopes `telegram`, `max`, and absent incoming transport map
to departments 2, 8 and 1. Apply only in departments 1/2/8/13/14/15. Unknown
integration scopes retain their own department. Test, partner and mail topics
keep the previous behavior. Current department and chat data are not mutated.

All topic creation/recreation and online visitor title refreshes share this
resolver. Label/icon remain editable in the bot-department UI (`topic_label`,
`topic_icon`). Channel rows are named without the redundant «Входящие» prefix.

On 2026-11-17 six recently renamed business topics were restored from native
transport data using `editForumTopic`; no messages were sent. Code/settings
backup: `/root/lhc-production-backups/order-delivery-2026-11-17/topic-source/`.

Check: `php extension/lhctelegram/tests/TelegramTopicSourceTest.php` (29 isolated
channel/transfer/unknown/mail/partner/test cases, no DB or Telegram calls).
