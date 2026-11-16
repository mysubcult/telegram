# SmartDiag business routing (2026-11-16)

Incoming channels 1 site / 2 Telegram / 8 MAX are named «Входящие». They are
entry points, not business roles. Native bot actions transfer human handoffs
to 13 sales / 14 support / 15 service using `setdepartment`. Source transport
stays in `incoming.scope`; outgoing webhooks 204/207 select it, not dep_id.
Bot 1 and native autoresponder 15 map to all three directions.

Native `stopchat` pins user 1 (Артём). Native `lh_userdep_alias` supplies
department-specific nick/job title. `GenericmessageCommand` applies this alias
to Telegram-written operator messages via the native `UserDepAlias` helper.
No duplicate user accounts and no name concatenation in custom templates.

Smarti bot 121 stays 24/7 (`bot_foh=false`) on channels and directions.
Native business hours: sales Mon–Fri 10–19, weekends 10–18 Moscow;
support/service Mon–Fri 10–19. Server uses Samara (+1 hour). Manual Telegram
acceptance and topic takeover have no hours prerequisite. The launcher
reflects published hours only, not active admin sessions.

`call_service` is declared in both REST 104 request methods. Native event
2191 checks service_work_hours and invokes 2192/2193. Online -> department 15
and user 1; offline -> information only. Consent and software support purchase
checks retained. Service requests need no purchase gate before a human can
clarify a repair. `SmartiHandoff` accepts sanitized service summary using the
same contract as other handoff tools. Worker dynamically forwards declarations,
so no Worker code/deploy is needed for this tool.

The closed-chat warning in `GenericmessageCommand` is intentional: an operator
topic message is delivered through the normal outgoing webhook, then the
operator is warned that the LHC chat remains closed. It is not an autoresponder
or a failed delivery report. Do not silently reopen customer chats or suppress
the warning. Reopen using native LHC controls before continuing a closed chat.
Case 25977: inactivity autoclose preceded the next-day operator message; it
used SmartDiag Telegram department 2, unrelated to OzReply department 6.

Tests: SmartiHandoffTest.php, TelegramReplyContractTest.php,
TelegramDeduplicationTest.php, TelegramSuggestTest.php. Native routing audit
covers 12 channel/direction cases in a rolled-back transaction; no real
messages sent. Model transport mocked for both call_service round trips.
After changing providers used by jobs, restart `lhc-resque`.
Backup: `/root/lhc-production-backups/business-routing-2026-11-16/`.
