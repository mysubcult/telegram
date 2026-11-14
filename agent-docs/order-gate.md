# Purchase context before Smarti handoff

Production bot: **121**. Keep the LHC core untouched. Backend code is
`providers/SmartiOrder.php`, registered by `TelegramLiveHelperChatOperator`.
This is a format/attachment check, **not purchase, seller, payment or ownership verification**.

## Routes

- Technical support: 2045 checks consent, collects purchase context if absent,
  then keeps the existing working-hours route. 2046 repeats the guard before
  the actual handoff, so a direct route cannot bypass collection.
- Paid activation: 2082 routes `activation_after_payment` through 2185;
  actual notification and queue are in 2184. Test EOBD and presales renewal
  retain their existing availability and do not require a previous order.
- Sales 2073/2077 is unchanged: customers must be able to ask before buying.
- Task buttons in 2085 now go through 2186–2188, preserving the selected task;
  2068 clears that button state when a new model tool call supplies its task.
- 2049 has one context-preserving route to 2170 instead of identical branches.

## Native collection

The native LHC **Collect custom attribute** (`attribute`) action creates the
pending chat event. Its `event` is `smartdiag.order_validate`; the extension
uses the documented `chat.genericbot_event_handler` hook and returns
`STOP_WORKFLOW` for its own events. The `preg_match` field also documents each
number's rule. Custom validation normalizes spaces around hyphens, accepts
`#` / `№` and order labels, rejects invalid calendar dates and checks attachment
ownership against the current chat.

| Trigger | Purpose |
|---|---|
| 2176 | Purchase-source menu |
| 2177 | Website order: `SD-YYYYMMDD-` + 6 hex characters |
| 2178 | Ozon: 8–10 digits, `-`, 4 digits, optional `-` + 1–3 digits |
| 2179 | Yandex Market: 7–12 digits; excludes common scanner serial prefixes and Russian phone numbers |
| 2180 | Declared purchase at SmartDiag without order: 12-digit serial |
| 2181 | Wildberries purchase screenshot |
| 2182 | Other purchase screenshot when the number cannot be found |
| 2183 | Validate collected context and resume the original route |
| 2174 | Cancel collection and return to assistant |
| 2175 | Return to assistant after the third invalid attempt |

WB has no fabricated numeric order rule. Ask for a purchase screenshot showing
the product and SmartDiag seller. Do not ask for pickup QR, card or passport.
Files must be current-chat visitor uploads (jpg/jpeg/png/webp/heic/pdf), with
the correct file security hash. Content/seller still requires human review.

The website rule is confirmed by `smartdiag/src/app/api/orders/route.ts`.
Ozon variants were seen in visitor messages. The Yandex numeric rule is a
deliberately broad compatibility rule, not a claim that all Yandex order IDs
have a fixed length. Non-matching legacy cases can use the screenshot path.

For website/Ozon, the guard can reuse a matching number from the last 100
actual visitor messages in the current chat (Ozon also requires an order/Ozon
keyword to avoid treating a version identifier as a purchase). It never trusts a model-generated
order argument as evidence. A serial requires the explicit purchase-without-
order choice; it is not silently treated as an order.

## State and public text

- `sd_support_purchase`: source, number/file reference, `check` status.
- `sd_order_source`, `sd_order_attempts`, `sd_order_pending`, `sd_order_return`:
  current collection state. No background API request.
- `sd_activation_task`: task chosen by a button, cleared by a model tool call.
- Additional information displays a **string**, labelled as reported by the
  visitor and not checked against an order database.
- Existing `sd_summary_ready` and `ai_action_args.summary` survive successful
  collection. The existing handoff message includes `{smartdiag.order_context}`
  and `{smartdiag.handoff_summary}`; there is no second summary request.
- Successful collection clears `sd_order_pending`: old source/cancel buttons
  stop silently. Cancellation clears summary readiness and AI busy flags.
- Invalid data gives two retry messages. The third failure removes the native
  pending event and returns to the normal assistant. Failure does not transfer.

## Validation / rollback

From `lhc_web`: `php extension/lhctelegram/tests/SmartiOrderTest.php`.
This uses isolated model stubs plus the real upstream conditions action.
Read-only live integration also checked listeners, guards and rendered handoff
text without executing triggers or sending customer/Telegram messages.
Existing Suggest, ReplyContract, Deduplication and DraftContract tests pass.
Restart `lhc-resque` after changing providers used by long-running workers.

Configuration/code backup:
`/root/lhc-production-backups/order-gate-2026-11-14-codex/`.
`before.json` contains the original REST104 and bot-121 trigger rows and is
private (600); do not commit it. `result.json` maps new trigger IDs.
To roll back, compare current state, restore the six modified trigger rows and
REST104 from the backup, remove only this change's new triggers, restore the
Operator provider and remove SmartiOrder files, then restart the queue.
Do not roll back unrelated later changes or overwrite visitor chat state.

Official collection hook: https://doc.livehelperchat.com/docs/bot/collect-custom-attribute
