# Suggestions, topic commands, file captions, /fix

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
- `/fix …` (`providers/TelegramFix.php`), text in the digest topic and a plain-text reply to a fix message (never sent to the visitor) store a row in `lhc_telegram_fix`; a new request first asks which AI prepares it (status `choose`, buttons `fxd`/`fxc` → `new` with `engine` deepseek/claude; a revision keeps its parent's engine); buttons `fxa`/`fxr` only change its status. The AI part and applying edits run outside LHC: `/root/smartdiag-fix/fixbot.py` (private repo `mysubcult/smartdiag-fix`, see its AGENTS.md; targets docs, site, REST 104 prompt, REST 105 suggestion prompt) (root, systemd `smartdiag-fix.timer` every minute: DeepSeek key, git push to the docs repo, REST 104 prompt edits with backups in `/root/lhc-production-backups/fix/`, daily digest at 19:00 MSK into the topic stored in `cache/telegram_digest_topic_<bot>.json`).
