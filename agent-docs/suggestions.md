# Suggestions, topic commands, file captions, /fix

## Reply suggestions (`TelegramSuggest`)

- Operator writes `!?` in a topic. As a Telegram reply to a visitor message → suggestion for that message (reply to
  an operator/bot message → the nearest visitor message before it); plain → the latest visitor message.
  `!?` is deleted and never reaches the visitor. Works for accepted, pending and bot chats; Send accepts a pending/bot chat for the operator.
- A resque job (`lhc_telegram_suggest`) calls LHC REST API `rest_api_id` (105 «Смарти | Подсказки оператору»).
  The prompt, model and history template live in that REST API record in LHC admin, not in code. History ends at
  the chosen message: `shiftHistory` adds an offset to `{previous_visitor_messages_list_url__20__N}`.
- REST 105's system text starts with `{suggest_prompt_from_rest_api__104}`: `injectPrompts` replaces it with the
  current system prompt of the main bot (REST 104), so suggestions follow its rules; REST 105 adds only the
  operator-mode section after it. Edit the main prompt in REST 104 only.
- Albums: one LHC message can be several topic messages (MAX/Telegram text + photos → `tg_topic_msg_ids`, map entry
  per photo with `file_id`). The replied topic message id travels with the job (`topic_msg_id`): the suggestion replies
  to that photo and the model is told which attachment the operator pointed at (`pointedFileNote`).
- The suggestion arrives as a reply to the chosen message with «✅ Отправить», «✏️ Изменить» and «✖️».
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
- `/fix …` and plain-text replies to a fix message store notes in `lhc_telegram_fix`; they never reach the visitor.
  Adding a note does not invoke AI. `/digest` asks the root service for a digest now. The regular digest runs
  Monday at 10:00 MSK. The owner reviews its Markdown export with an external coding agent; there is no automatic
  AI review or automatic application of new notes. Legacy approved packages still use the minute service.
  Details: `/root/smartdiag-fix/AGENTS.md`. Do not mark the entire export completed unless its work is complete.

## Draft editing (14 November 2026)

Edit opens a selective Telegram ForceReply in the same topic. Reply with the complete replacement text
(up to 3500 characters), review the updated suggestion and press Send. A direct reply to a suggestion also
edits it. Reply «Отмена» to cancel. Unsent draft replies, including attachments and stale edit prompts, are
consumed before uploads, commands and visitor delivery. Sent answers (including saved-delivery warnings)
are ordinary quote targets. A saved LHC message mapping also overrides a stale draft header on a direct reply.
Slash-prefixed draft text routed through GenericCommand stays literal.

Draft text, revision, status and editor prompt are stored atomically in
`cache/telegram_suggest_draft_<hash>.json` (0600). Send/Edit callback revisions reject stale actions;
operator, bot, group, topic and chat are checked. MySQL draft locks serialize editing and sending.
A saved lh_msg mapping prevents duplicate sends, including an exception after save. In that case the operator
sees a delivery warning and checks the chat. Older unedited suggestions work; missing versioned state fails
closed and requires a new hint. Cache clearing can invalidate open edited drafts.

SmartDiag REST104 handoffs accept a public `summary` argument. SmartiHandoff replaces
`{smartdiag.handoff_summary}` inside the existing online support/sales and activation notification for bot121.
Rendering does not call AI. Readiness resets before each model call and on the direct task-button path,
so old summaries are not reused. Without a fresh summary the normal notification remains valid.
The saved assistant message is delivered to visitor and operator. Working hours and queue policy are unchanged.
The public summary addresses the visitor from the assistant's perspective and distinguishes proposed steps
from checks the visitor confirmed doing. The wrapper reads «Подведу итог нашего разговора».

`tests/TelegramDraftContractTest.php` runs isolated editing/sending/handoff interaction contracts with fake
Telegram and DB transports. It makes no network calls or production writes.
