# Cardcom – standing-order (Recurring Payments) API

Screenshots of Cardcom's documentation for creating/updating standing orders (הוראות קבע).

- `recurring-api-1..4-*.png` – the full parameter table (1–44). Key fields:
  - `Operation`: `NewAndUpdate` / `Update` (update only; error if not found)
  - `RecurringPayments.RecurringId` (27): the standing-order number, required in Update mode
  - `RecurringPayments.IsActive` (33): Update mode only, `false` = stop charging (used for cancellation)
  - `RecurringPayments.ReturnValue` (32): our value returned in notifications (use for userId)
  - `Account.Email` (19): Cardcom merges customers by email when no business number is sent
  - `TerminalNumber`, `UserName`, `codepage=65001` are required
- `ipn-log-2026-11-17-rejected-recurring.png` – staging log: two notifications from the standing-order module (keys: RecurringId, RecordType, NextDateToBill, NumOfPaymentsAlreadyCharged, FlexItem_ProductId, IsActive…) rejected for "missing/invalid secret", plus one successful regular IPN.

Note: the endpoint URL of the API itself and the API password are not in these screenshots – check Cardcom's docs/dashboard.
