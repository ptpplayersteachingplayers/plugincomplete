# PTP Post-Training Payment Flow

## Overview

PTP uses an escrow system to protect both parents and trainers. Payment is captured immediately when a session is booked, but funds are only released to the trainer after the session is confirmed complete.

---

## Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     BOOKING & PAYMENT                            │
├─────────────────────────────────────────────────────────────────┤
│  Parent books session → Stripe captures $100                     │
│  ↓                                                               │
│  Funds held in PTP platform account (escrow)                     │
│  Status: HOLDING                                                 │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                     SESSION OCCURS                               │
├─────────────────────────────────────────────────────────────────┤
│  Training session takes place as scheduled                       │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                  TRAINER MARKS COMPLETE                          │
├─────────────────────────────────────────────────────────────────┤
│  Trainer dashboard → "Mark Complete" button                      │
│  ↓                                                               │
│  Status: SESSION_COMPLETE                                        │
│  24-hour auto-release timer starts                               │
│  Parent notified (email + SMS if enabled)                        │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│              PARENT CONFIRMATION (24 HOURS)                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Option A: "Confirm & Release Payment"                           │
│  → Status: CONFIRMED → RELEASED                                  │
│  → Immediate Stripe transfer to trainer                          │
│                                                                  │
│  Option B: "Report Issue"                                        │
│  → Status: DISPUTED                                              │
│  → Admin notified for review                                     │
│                                                                  │
│  Option C: No action (24 hours pass)                             │
│  → Cron job processes auto-release                               │
│  → Status: RELEASED                                              │
│  → Stripe transfer to trainer                                    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                    PAYMENT RELEASE                               │
├─────────────────────────────────────────────────────────────────┤
│  $100 total                                                      │
│  - $20 platform fee (20%)                                        │
│  = $80 transfer to trainer's Stripe Connect account              │
│                                                                  │
│  Trainer notified (email + SMS)                                  │
│  Status: RELEASED                                                │
└─────────────────────────────────────────────────────────────────┘
```

---

## Escrow Statuses

| Status | Description | Who Can Act |
|--------|-------------|-------------|
| `holding` | Payment captured, session not yet occurred | System |
| `session_complete` | Trainer marked complete, awaiting confirmation | Parent |
| `confirmed` | Parent confirmed, ready for release | System |
| `disputed` | Parent reported issue | Admin |
| `released` | Funds sent to trainer | Complete |
| `refunded` | Funds returned to parent | Complete |

---

## Key Timeframes

- **Completion Window**: Trainer has 48 hours after session end time to mark complete
- **Confirmation Window**: Parent has 24 hours to confirm or dispute after trainer marks complete
- **Auto-Release**: If parent takes no action, funds auto-release after 24 hours

---

## Code References

### Escrow Class (`includes/class-ptp-escrow.php`)

```php
// Create escrow hold (called after payment)
PTP_Escrow::create_hold($booking_id, $payment_intent_id, $amount);

// Trainer marks complete (AJAX: ptp_trainer_complete_session)
PTP_Escrow::trainer_complete_session();

// Parent confirms (AJAX: ptp_parent_confirm_session)
PTP_Escrow::parent_confirm_session();

// Parent disputes (AJAX: ptp_parent_dispute_session)
PTP_Escrow::parent_dispute_session();

// Auto-release cron (hourly)
PTP_Escrow::process_auto_releases();
```

### Payout Class (`includes/class-ptp-trainer-payouts.php`)

```php
// Create transfer to trainer's Stripe
PTP_Trainer_Payouts::create_payout($trainer_id, $amount, $booking_ids, $description);

// Check if trainer can receive payouts
PTP_Trainer_Payouts::trainer_can_receive_payouts($trainer_id);

// Get pending balance
PTP_Trainer_Payouts::get_pending_balance($trainer_id);
```

### UI Components (`includes/class-ptp-session-confirmation.php`)

```php
// Render parent's pending confirmations
PTP_Session_Confirmation::render_parent_pending($parent_id);

// Render trainer's sessions to mark complete
PTP_Session_Confirmation::render_trainer_pending($trainer_id);
```

---

## Dashboard Integration

### Trainer Dashboard

The trainer sees a blue banner with "Mark Sessions Complete" for past sessions:
- Shows player name, date/time
- Shows potential earnings
- One-click "Mark Complete" button

### Parent Dashboard

The parent sees a yellow banner with "Confirm Your Sessions" for completed sessions:
- Shows trainer photo/name
- Shows session details
- Shows auto-confirm countdown timer
- "Confirm & Release Payment" button
- "Report Issue" link

---

## Stripe Connect Requirements

For payouts to work, trainers must:

1. **Complete Stripe Connect onboarding**
   - Dashboard → Profile → Payments → "Connect Stripe"
   - Fills out Stripe's identity verification

2. **Have `stripe_payouts_enabled = true`**
   - Set automatically after onboarding completes
   - Can verify via `PTP_Stripe::is_account_complete($account_id)`

3. **Have a valid `stripe_account_id`**
   - Stored in `ptp_trainers.stripe_account_id`

---

## Cron Job

The auto-release cron runs hourly:

```php
// Hook: ptp_process_escrow_releases
// Schedule: hourly
// Function: PTP_Escrow::process_auto_releases()
```

This processes all escrow records where:
- Status = `session_complete`
- `release_eligible_at` has passed (24 hours after trainer completion)

---

## Dispute Handling

When a parent disputes:

1. Status changes to `disputed`
2. Admin receives notification
3. Admin reviews at /wp-admin/ → PTP Training → Disputes
4. Admin can:
   - Release funds to trainer (session happened)
   - Refund parent (session didn't happen)
   - Partial refund (partial session)

---

## Database Tables

```sql
-- Escrow records
wp_ptp_escrow
  - id, booking_id, trainer_id, parent_id
  - payment_intent_id
  - total_amount, platform_fee, trainer_amount
  - status, release_eligible_at
  - trainer_completed_at, parent_confirmed_at
  - created_at

-- Payout history
wp_ptp_payouts
  - id, trainer_id, amount, status
  - payout_method (always 'stripe')
  - payout_reference (Stripe transfer ID)
  - created_at, processed_at

-- Payout line items
wp_ptp_payout_items
  - id, payout_id, booking_id
```
