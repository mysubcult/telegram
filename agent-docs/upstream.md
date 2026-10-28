# Upstream PRs, syncing, webhook security

## Webhook security

- Operator bots (`bot_client` = 1) receive updates at `telegram/callback/<id>`. Webhook is registered with a
  `secret_token` (`erLhcoreClassModelTelegramBot::$webhook_secret` = HMAC of bot id + token with site `secrethash`);
  `webhook_set` = 2 means "set with secret" and `callback.php` answers 403 to requests without the matching
  `X-Telegram-Bot-Api-Secret-Token`. Bots 1 and 3 are on 2 (since 2026-10-24). After changing a bot token click
  **Set webhook** again (Telegram → Bots). Upstream PR LiveHelperChat/telegram#101.
- Bot 2 (visitors' Telegram) goes through LHC core incoming webhook `webhooks/incoming/<hash>` — the hash is the secret.

## Upstream PRs and syncing

- Merged upstream 26.10.2026: #97 link previews, #98 compact new chat card, #100 auto responder notes, #101 webhook secret,
  #104 escaping chat text; `mod-production` has merged `origin/master` after that (the code was already here).
- 28.10.2026: #106 `/whoami` merged; upstream also changed `doc/telegram/*.json` for several visitor bots
  (`chat_id_2` = `telegram_bot_id`, REST API `chat_external_first`). Merged into the templates here; the live incoming
  webhook / REST API are NOT switched — one visitor bot, and a composite id would split every existing chat.
- Open PRs (26.10.2026, all mergeable): #99 "/" command menu, #102 mail topics, #103 topic icons, #105 Bot API client (429 retry + audit log of refused calls). #103 merges cleanly
  with either; #99 and #102 both add a column to the one-line `CREATE TABLE lhc_telegram_bot` — whichever goes second
  needs `origin/master` merged into its branch (promised to the author in a #102 comment).
  All of them are already in `mod-production`. Check they still merge together before opening a new one:
  merge them one by one onto `upstream/master` in `/root/work/tg-merge`.
- PR branches are built in `/root/work/tg-merge` (remotes: `upstream` = LiveHelperChat, `gh` = mysubcult fork,
  `origin` = this live dir). PR text and code must stay generic: no SmartDiag names, domains, IPs, Russian texts.
- Commit author must be `mysubcult <mysubcult1@gmail.com>` (root's default identity leaks the server hostname).

## What the author rejected (do not re-propose)

- Things the bot builder can do (triggers, chat status, accepting a chat on a command, answering callback queries:
  `{{args.chat.incoming_chat.payload_array.callback_query.id}}` holds the latest payload since core 70baf7c) — #89, #91, #92.
- Fixing a consequence instead of the cause (`tchat_id` default 0, #81); complex de-duplication with named locks (#87 —
  his advice: lock the chat record until it is processed); replies/quotes sync (#77/#78) and renamed uploads (#75) —
  kept only in this fork. Small, one-purpose PRs with steps to reproduce; no AI-style sweeping changes.
