# Upstream PRs, syncing, webhook security

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
