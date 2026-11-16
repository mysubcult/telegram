# Unified native purchase collection before handoff

Bot 121 uses LHC triggers and its existing AI REST/vision transport. LHC core
and the AI worker are unchanged. There is one intake prompt, not separate
collectors per marketplace. This replaces the 2026-11-14 source-menu flow.

## Editor map

| Trigger | Purpose |
|---|---|
| 2045, 2046 | Consent, purchase readiness, support hours and handoff |
| 2082, 2185, 2184 | Existing activation/free-test routing, paid-purchase guard and queue |
| 2176 | One prompt accepting order number, serial or purchase screenshot; Return on the same message |
| 2177–2182 | Historical callback compatibility: return to 2176 while intake is pending |
| 2068 → 2183 | Dispatch `submit_purchase_details` before normal AI action/summary reset |
| 2183 | Native consent/state/type/reference/file guards, save attributes, restore original handoff |
| 2031 → 2189 | AI clarification while pending, with Return on that latest message |
| 2190 | Invalid input: short retry and Return, without example order numbers |
| 2174 | Cancel intake and return to assistant |
| 2175 | Retained historical callback; no longer the automatic third-failure exit |

REST API 104 (initial and follow-up methods) exposes `submit_purchase_details`
with `kind` (`order`, `serial`, `screenshot`), `reference` and `details`. Existing
2033/2034 media processing supplies image/PDF content to the existing vision
path. The model must read only visible information, reject random text and
unreadable images, clarify another seller, and never invent or reuse an example
number. No order-format examples are shown in the initial prompt.

The current `sd_order_pending` value is a separate final system-instruction text
part using native REST `{{lhc.var.sd_order_pending}}` JSON interpolation. Do not
put a single-brace workflow placeholder inside raw REST JSON: it remains literal.

## Native guards and state

- Require consent and pending intake before accepting the tool result.
- Order: 4–80 allowed letters/digits/separators, at least one digit. This is a
  permissive input guard, not order ownership verification.
- Serial: exactly 12 digits.
- Screenshot: requires a real file on the current message. A tool declaration
  alone cannot mark a nonexistent upload as collected.
- `sd_purchase_number` and `sd_purchase_context` are native additional attributes.
  Context explicitly says the information has not been checked against an order DB.
- Preserve `sd_purchase_task`, `sd_purchase_handoff_summary` and
  `sd_purchase_handoff_function`; restore `ai_function_name`, summary and task
  after extraction, then resume support (2045) or activation (2082).
- `sd_purchase_ready=1` and cleared `sd_order_pending` finish collection.
- Human review remains necessary; collecting evidence does not authorize payment,
  activation or an ownership claim. Presales/free EOBD tests do not require an order.

LHC condition PCRE uses `_com_` inside repetition ranges (`{3_com_79}`), because
the native condition parser otherwise splits comma-separated values. Command
chat-variable payloads translated with `as_json` require unquoted placeholders:
the native translator already emits JSON string quotes. Additional-attribute
values use their action's normal nested argument translation instead.

## Checks and rollback

2026-11-17: real LHC actions tested under a rolled-back transaction, with no
outbound calls. Checks cover order/random text, complete/incomplete serial,
screenshot with/without a real file, attribute interpolation, preserved handoff
summary containing quotes/newlines, and both REST pending-state substitutions.
The existing business audit covers 12 channel/direction routes, hours, operator
aliases, consent and 66 condition references. Live model OCR quality was not
tested by these isolated checks.

Backup: `/root/lhc-production-backups/order-delivery-2026-11-17/` (`config.sql`).
Restore individual trigger/REST rows selectively; preserve unrelated later edits.
Clear LHC cache and restart `lhc-resque` after configuration changes.

The former custom `SmartiOrder.php` collector stays removed. Hint drafts,
Telegram transport, public-summary sanitization and answered-only mail auto-close
serve separate capabilities and must not be removed as unused collection code.
