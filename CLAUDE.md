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

## Code map

| Path | What |
|---|---|
| `bootstrap/bootstrap.php` | Extension class, autoload (`LiveHelperChatExtension\lhctelegram\…` → file path), reply/quote helpers |
| `providers/TelegramLiveHelperChatOperator.php` | Operator flow: listeners (`registerListeners`), forwarding chat messages into topics, topic message id mapping, locks |
| `providers/TelegramSuggest.php` | Reply suggestions (see below) |
| `classes/Commands/GenericmessageCommand.php` | Text/media written by an operator in a topic → LHC message; `!` commands; `!?` interception |
| `classes/Commands/CallbackqueryCommand.php` | Inline buttons in the group (take over / accept chat, online status, suggestion buttons) |
| `modules/lhtelegram/` | Admin UI and the webhook endpoint (`callback.php`) |
| `settings/settings.ini.php` | Live settings, **gitignored**. Template: `settings.ini.default.php` (CRLF line endings — keep them) |
| `tests/` | Plain PHP scripts, no PHPUnit |

Topic message mapping lives in `lh_msg.meta_msg`: `tg_topic_msg_contexts.<namespace>.ids` (namespace
`bot_<id>_chat_n_<group id>`) plus legacy `tg_topic_msg_id` / `tg_topic_msg_ids`. A message that already has an id
there is not forwarded again (`isTopicMessageAlreadySent`) — that is how operator messages avoid echoing back.

## Reply suggestions (`TelegramSuggest`)

- Operator writes `!?` in a topic. As a Telegram reply to a visitor message → suggestion for that message (reply to
  an operator/bot message → the nearest visitor message before it); plain → the latest visitor message.
  `!?` is deleted and never reaches the visitor. Only for accepted (ACTIVE) chats.
- A resque job (`lhc_telegram_suggest`) calls LHC REST API `rest_api_id` (105 «Смарти | Подсказки оператору»).
  The prompt, model and history template live in that REST API record in LHC admin, not in code. History ends at
  the chosen message: `shiftHistory` adds an offset to `{previous_visitor_messages_list_url__20__N}`.
- REST 105's system text starts with `{suggest_prompt_from_rest_api__104}`: `injectPrompts` replaces it with the
  current system prompt of the main bot (REST 104), so suggestions follow its rules; REST 105 adds only the
  operator-mode section after it. Edit the main prompt in REST 104 only.
- Albums: one LHC message can be several topic messages (MAX/Telegram text + photos → `tg_topic_msg_ids`, map entry
  per photo with `file_id`). The replied topic message id travels with the job (`topic_msg_id`): the suggestion replies
  to that photo and the model is told which attachment the operator pointed at (`pointedFileNote`).
- The suggestion arrives as a reply to the chosen message with «✅ Отправить» (`sgs||chat`) / «✖️» (`sgx||chat`).
  Send posts the text as the operator's own message and maps it to the suggestion's topic message id.
- Model answers `SKIP: <reason>` when there is nothing to suggest → «💡 Подсказки нет: <reason>».
- Settings `suggest`: `enabled`, `mode` (`command` default; `button` = «💡 Ответ» under visitor messages;
  `auto` = after every visitor message with `delay`), `rest_api_id`, `dep_ids` (empty = all).
- REST 105 uses the smarti-ai Cloudflare worker (`/root/smarti-ai`) with DeepSeek thinking mode. Its body must end
  with a `user` turn, otherwise DeepSeek rejects the request («reasoning_content must be passed back»).

## Topic commands, file captions, /fix

- `/name` in a topic = `!name` (`classes/Commands/GenericCommand.php`); `/hint` = `!?`. The "/" menu lists the bot option "Commands in the "/" menu of the group" (`lhc_telegram_bot.topic_commands`), `providers/TelegramTopicCommands.php` re-sends it on change. Do not use LHC core flags (e.g. "Enabled for display") for Telegram behaviour.
- Bot option `disable_link_preview`: applied in `sendTelegramRequest()`, `sendTelegramRequestOnce()` and `sendTelegramMessageWithSplit()` (bot replies go through the last one directly).
- `providers/TelegramFileCaption.php`: every visitor photo/voice/PDF is described once (resque → worker `deepseek-flash@describe`) into core `lh_chat_file.caption_ocr`; suggestions list the descriptions of earlier files. Setting `suggest.captions`.
- `/fix …` (`providers/TelegramFix.php`), text in the digest topic and a plain-text reply to a fix message (never sent to the visitor) store a row in `lhc_telegram_fix`; buttons `fxa`/`fxr` only change its status. The AI part and applying edits run outside LHC: `/root/smartdiag-fix/fixbot.py` (private repo `mysubcult/smartdiag-fix`, see its CLAUDE.md; targets docs, site, REST 104 prompt, REST 105 suggestion prompt) (root, systemd `smartdiag-fix.timer` every minute: DeepSeek key, git push to the docs repo, REST 104 prompt edits with backups in `/root/lhc-production-backups/fix/`, daily digest at 19:00 MSK into the topic stored in `cache/telegram_digest_topic_<bot>.json`).

## Webhook security

- Operator bots (`bot_client` = 1) receive updates at `telegram/callback/<id>`. Webhook is registered with a
  `secret_token` (`erLhcoreClassModelTelegramBot::$webhook_secret` = HMAC of bot id + token with site `secrethash`);
  `webhook_set` = 2 means "set with secret" and `callback.php` answers 403 to requests without the matching
  `X-Telegram-Bot-Api-Secret-Token`. Bots 1 and 3 are on 2 (since 2026-10-24). After changing a bot token click
  **Set webhook** again (Telegram → Bots). Upstream PR LiveHelperChat/telegram#101.
- Bot 2 (visitors' Telegram) goes through LHC core incoming webhook `webhooks/incoming/<hash>` — the hash is the secret.

## Upstream PRs and syncing

- Open PRs (2026-10-24): #97 link previews, #98 compact new chat card, #99 "/" command menu (depends on #97: both
  add a `lhc_telegram_bot` column; #97 is merged into the #99 branch), #100 auto responder notes, #101 webhook secret.
  All of them are already in `mod-production`. Check they still merge together before opening a new one:
  merge them one by one onto `upstream/master` in `/root/work/tg-merge`.
- PR branches are built in `/root/work/tg-merge` (remotes: `upstream` = LiveHelperChat, `gh` = mysubcult fork,
  `origin` = this live dir). PR text and code must stay generic: no SmartDiag names, domains, IPs, Russian texts.
- Commit author must be `mysubcult <mysubcult1@gmail.com>` (root's default identity leaks the server hostname).

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
  - `TelegramDeduplicationTest.php` — its trigger 1791 check fails since that trigger was removed (known).
- End-to-end checks: create a chat in department 4 «Test» (it has an operator topic), never experiment on real
  customers' chats. Telegram API calls from CLI need `new \Longman\TelegramBot\Telegram($bot->bot_api, $bot->bot_username)` first.
- Telegram limits worth remembering: `callback_data` ≤ 64 bytes; bots cannot delete messages older than 48 h;
  in forum topics every message «replies» to the topic-created message — use `extractTelegramReplyData()['is_explicit_reply']`.
- Commit messages: conventional prefix (`feat(scope):`, `fix(scope):`), Russian description is fine.
