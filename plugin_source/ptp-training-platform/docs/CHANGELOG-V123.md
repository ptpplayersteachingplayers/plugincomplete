# PTP Training Platform v123 - Email Reliability Fix

**Release Date:** January 23, 2026

## Summary

Improves email delivery reliability for training bookings by adding `guest_email` fallback to the notification system.

## Problem

Parent confirmation emails were sometimes not being sent because the email lookup chain didn't include the `guest_email` column that was added in v122 for guest checkout support.

## Changes Made

### `class-ptp-unified-checkout.php`

**`notify_trainer()` method (line ~2101):**

Added `guest_email` as a fallback in the parent email lookup chain:

```php
// v123: Check guest_email column (used for guest checkout)
if (empty($parent_email) && !empty($booking->guest_email)) {
    $parent_email = $booking->guest_email;
    error_log('[PTP Notify v123] Using guest_email as fallback: ' . $parent_email);
}
```

### Email Lookup Priority (Parent)

1. `parent_email` from `ptp_parents` table
2. `wp_user_email` from WordPress users table
3. **NEW:** `guest_email` from `ptp_bookings` table (v123)
4. Payment intent metadata lookup (last resort)

## Booking Flow (Unchanged)

- Status remains `confirmed` on booking creation
- Escrow holds funds until session completion
- Trainer/parent confirmation flow still required for payout release
- Emails fire immediately after successful booking INSERT

## Verification

Check logs after a training booking:

```
[PTP Notify v117.2.18] Starting notify_trainer for booking X, trainer Y
[PTP Notify v123] Using guest_email as fallback: parent@example.com
[PTP Notify v117.2.18] Trainer email SENT to: trainer@example.com
[PTP Notify v117.2.18] Parent email SENT to: parent@example.com
```

## Files Modified

| File | Change |
|------|--------|
| `class-ptp-unified-checkout.php` | Added guest_email fallback in notify_trainer() |
| `ptp-training-platform.php` | Version 123.0.0 |
