# lhctelegram — SmartDiag fork

Fork of [LiveHelperChat/telegram](https://github.com/LiveHelperChat/telegram) running in production at
`/var/www/html/livehelperchat/lhc_web/extension/lhctelegram`. This directory **is** the live code: an edited file
is served on the next request (opcache validates timestamps), there is no build or deploy step.

## Repositories and branches

- `origin` = upstream LiveHelperChat/telegram (`master`). `fork` = mysubcult/telegram.
- `mod-production` — what runs here. Upstream + our patches (topic replies/quotes, dedupe of topic messages,
  oversized files, stickers, reply suggestions). Commit here.
- Upstream PRs go from a `fix/…` or `feat/…` branch cut from `origin/master`, never from `mod-production`
  (it carries fork-only helpers such as `getStoredTopicMessageId` and `tg_topic_msg_contexts` that upstream lacks).
  Accepted PRs then exist twice — as our commit and as upstream's merge; resolve that on the next upstream sync.
- Sync with upstream = merge `origin/master` into `mod-production` (precedent: `34a623a`), then run the tests and
  check a topic chat by hand. Upstream changes touching group/supergroup or callback handling need extra care:
  the operator group (supergroup with forum topics) is exactly what this fork relies on.
- Keep LHC core, `lhcphpresque`, `nodejshelper`, `lhcmax`, `chatgpt` identical to upstream: changes go upstream as
  PRs, not as local edits. This fork is the place for Telegram-specific behaviour.

## How it is wired on this server

- Operator bot `lhcsmartdiag_bot` (lhc_telegram_bot id 1, `bot_client` = 1) posts every chat into a forum topic of
  group `-1001946886605`; operators answer inside the topic. Departments → bot mapping: `lhc_telegram_bot_dep`.
- Visitors on Telegram talk to `smartdiag_robot` (bot id 2, `bot_client` = 0) through LHC's REST/incoming-webhook
  transport, not through this extension's operator flow. Delivery of operator messages to visitors is done by LHC
  webhooks on `chat.web_add_msg_admin` (204 → Telegram bot 110 trigger 1609, 207 → Max bot 109 trigger 1607).
- `ozreply_bot` (id 3) serves department 6 — not SmartDiag support.
- E-mail: bot 1 has `mail_topics = 1` and the mail departments 9–11 (sales/support/service). Mail sync runs in resque
  (`lhc_mailconv` queue, 4 workers) — restart `lhc-resque` after changing `TelegramMailTopics.php`. Auto-reply to direct
  e-mails is core LHC, not this fork: webhook 222 `mail.conversation_started` → bot 123 trigger 2169 (action «mail»), skipped
  for our own domain (form letters come from `site@`, the site confirms them itself) and noreply senders.

## Code map

| Path | What |
|---|---|
| `bootstrap/bootstrap.php` | Extension class, autoload (`LiveHelperChatExtension\lhctelegram\…` → file path), reply/quote helpers |
| `providers/TelegramLiveHelperChatOperator.php` | Operator flow: listeners (`registerListeners`), topic message id mapping, locks. Every chat message goes into its topic through `forwardMessageToTopic()` (text or files, failed files as links, deleted topic re-created); chat text is raw in LHC — escape it (`escapeTelegramHtml`, `formatTopicLine`) before Telegram HTML |
| `providers/TelegramSuggest.php` | Reply suggestions (see below) |
| `providers/TelegramTopicIcons.php` | Topic icon per department: `lhc_telegram_bot_dep.topic_icon` (custom emoji id from Telegram's free set `getForumTopicIconStickers` or `color:<value>`), chosen next to each department in «Edit bot departments»; icon set cached a day in `cache/telegram_topic_icons_<bot>.json`. Department names are not touched; `topic_label` — start of topic names instead of «[Department]» («TG · Nick #id …», the letter a coloured icon shows); preview in the form |
| `providers/TelegramMailTopics.php` | E-mails in topics (bot option `mail_topics`): new mail conversation → topic (`lhc_telegram_chat.type = 2`, `chat_id` = conversation id), next e-mails posted into it, operator text in the topic → reply e-mail via `erLhcoreClassMailconvValidator::sendReply` |
| `classes/Commands/GenericmessageCommand.php` | Text/media written by an operator in a topic → LHC message; `!` commands; `!?` interception |
| `classes/Commands/CallbackqueryCommand.php` | Inline buttons in the group (take over / accept chat, online status, suggestion buttons) |
| `modules/lhtelegram/` | Admin UI and the webhook endpoint (`callback.php`) |
| `settings/settings.ini.php` | Live settings, **gitignored**. Template: `settings.ini.default.php` (CRLF line endings — keep them) |
| `tests/` | Plain PHP scripts, no PHPUnit |

A topic deleted in Telegram answers sends with "message thread not found" (rarely TOPIC_DELETED): every send path calls
`reopenDeletedTopic()` (Operator provider), which resets `tchat_id` and re-posts the chat via `chatStarted` into a new
topic. Keep both texts when merging upstream — checking only TOPIC_DELETED lost messages (fixed 26.10.2026).

Topic message mapping lives in `lh_msg.meta_msg`: `tg_topic_msg_contexts.<namespace>.ids` (namespace
`bot_<id>_chat_n_<group id>`) plus legacy `tg_topic_msg_id` / `tg_topic_msg_ids`. A message that already has an id
there is not forwarded again (`isTopicMessageAlreadySent`) — that is how operator messages avoid echoing back.

## Working rules

- LHC compiles module files (`settings.ini.php` → `modulecompile`): after editing anything under `modules/`
  (e.g. `callback.php`) run `sudo -u www-data php cron.php -s site_admin -c cron/util/clear_cache` in `lhc_web/`,
  otherwise the old code keeps running. Classes in `providers/`, `classes/` are picked up without it.

- Back up before touching live data: `/root/lhc-production-backups/<what>-<date>/`.
- Resque workers keep settings and loaded classes in memory: after changing `settings.ini.php` or code used by a
  job, run `systemctl restart lhc-resque`.
- Tests (from `lhc_web/`):
  - `sudo -u www-data php extension/lhctelegram/tests/TelegramSuggestTest.php`
  - `php extension/lhctelegram/tests/TelegramReplyContractTest.php`
  - `sudo -u www-data php extension/lhctelegram/tests/TelegramDeduplicationTest.php`
- End-to-end checks: create a chat in department 4 «Test» (it has an operator topic), never experiment on real
  customers' chats. Telegram API calls from CLI need `new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username)` first.
- Telegram limits worth remembering: `callback_data` ≤ 64 bytes; bots cannot delete messages older than 48 h;
  in forum topics every message «replies» to the topic-created message — use `extractTelegramReplyData()['is_explicit_reply']`.
- Commit messages: conventional prefix (`feat(scope):`, `fix(scope):`), Russian description is fine.

## More details

| File | What |
|---|---|
| `agent-docs/suggestions.md` | Operator reply suggestions (`!?`), "/" topic menu, file captions, `/fix` requests |
| `agent-docs/upstream.md` | Webhook secret token, open upstream PRs, how to build PR branches and sync with upstream |

Server map: `/root/AGENTS.md`. LHC core notes: `/var/www/html/livehelperchat/AGENTS.md`.
