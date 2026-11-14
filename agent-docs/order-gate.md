# Native purchase collection before handoff

Purchase collection for bot 121 is configured entirely in LHC triggers.
`SmartiOrder.php`, its two listeners and its custom validator were removed.
The earlier handoff-summary and Telegram Hint features are separate.

## Editor map

| Trigger | Native actions / purpose |
|---|---|
| 2045, 2046 | Consent, `sd_purchase_ready` condition, existing support hours / handoff |
| 2082 | Native OR conditions select paid activation vs free test / renewal |
| 2185 | Paid activation purchase guard |
| 2184 | Activation notification and queue, default optional context |
| 2176 | Purchase-source menu, remove process, reset repeat limiter |
| 2177–2180 | Collect custom attribute `sd_purchase_number`, PCRE format rule |
| 2181, 2182 | Collect custom attribute `[file]`, extension PCRE |
| 2183 | Number success: native guards, save context, resume original route |
| 2189 | File success: native file argument, save context, resume route |
| 2190 | Validation failure: native repeat limiter, retry message and Back |
| 2174 | Cancel and return to assistant |
| 2175 | Third failure: stop collection and return to assistant |

2190 uses **Restrict execution more than defined times** (`repeat_restrict`),
identifier `sd_purchase_collection`, repeat count **2**, alternative callback
**2175**. Thus the third error exits. Menu/source selection resets this limiter.
All collection actions use native success, failure and cancellation callbacks.
The custom validation event is empty. Explicit Back quick replies work in
messenger channels. Native error Cancel metadata is disabled because 2190
already displays Back; this avoids duplicate error messages.

## Editable rules

- Website: `SD-` + eight digits + `-` + six hexadecimal characters.
- Ozon: 8–10 digits + `-` + four digits, optional `-` + 1–3 digits.
- Yandex Market: 7–12 digits, broad compatibility rather than verification.
- Declared purchase here without order: exactly 12 serial-number digits.
- WB / no order found: `jpg|jpeg|png|webp|heic|pdf`.

Website/Ozon PCRE rules also accept order labels, whitespace and alternate
dashes. No separate normalization, calendar checker, serial-prefix checker or
automatic scan of past messages remains. Rules are edited in **Collect custom
attribute → Regular expression match rule**. LHC handles `[file]` collection,
including its built-in file hash and extension checks. Content and seller
require human review. This does not verify purchase/payment/ownership.
No order database or additional DeepSeek request is involved.

## State and context

- Native additional attribute `sd_purchase_number`: collected number, or a
  statement that a screenshot is in the conversation.
- Native additional attribute `sd_purchase_context`: public handoff text.
- Chat variables `sd_order_source`, `sd_order_source_label`, `sd_order_pending`,
  `sd_order_return`, `sd_purchase_ready`: ordinary native command/condition state.
- Additional information labels the purchase as reported by the visitor,
  without checking it against an order database.
- Templates use standard `{lhc.add.sd_purchase_context}` replacement.
- Existing summary readiness and `ai_action_args.summary` survive collection.
- Cancellation clears pending/busy/summary readiness and public context.
- Presales and free test EOBD do not require an order. Support hours are kept.

## Checks and rollback

54 isolated checks exercised unmodified LHC collection, file handling,
cancellation, repeat limiter and conditions with fake persistence. 26 read-only
real-LHC checks covered guards, interpolation and configuration. No chats were
created and no customer or Telegram messages were sent.

Backup: `/root/lhc-production-backups/order-native-2026-11-14-codex/`.
Includes previous trigger rows, removed code and the callback ID map. There
were no active purchase events or states; the transaction rechecked this before
committing. Restoring the removed provider also requires restoring matching
old configuration and restarting resque; preserve unrelated later changes.

Official documentation:
- https://doc.livehelperchat.com/docs/bot/collect-custom-attribute
- https://doc.livehelperchat.com/docs/bot/collecting-information-two-tries
