# PTP Training Platform v122 - Critical Booking Fix

**Release Date:** January 22, 2026

## Problem Identified

Training bookings were failing silently due to a **schema mismatch** between the `ptp_bookings` table definition in `class-ptp-database.php` and the INSERT statements in `class-ptp-unified-checkout.php`.

### Root Cause

The database had TWO conflicting table schemas:

**Schema A (class-ptp-database.php):**
- `session_date date NOT NULL`
- `start_time time NOT NULL`
- `end_time time NOT NULL`
- `hourly_rate decimal(10,2) NOT NULL`
- Used `session_type` and `session_count` columns

**Schema B (class-ptp-unified-checkout.php):**
- All date/time fields nullable
- Used `package_type` and `total_sessions` columns
- Different column set entirely

Since `CREATE TABLE IF NOT EXISTS` was used in both places, whichever schema ran first would stick. The INSERT code expected Schema B but many databases had Schema A.

### Impact

1. **Bookings not created** - INSERT failed silently (MySQL error but not reported)
2. **No booking_id returned** - `$wpdb->insert_id` returned 0
3. **Emails never sent** - `notify_trainer()` was called with booking_id=0, found nothing
4. **Parent dashboard empty** - No booking records to display

## Fix Applied

### 1. New Fix Class (`class-ptp-booking-fix-v122.php`)

Runs on `init` with priority 5 to fix the schema before any checkout operations:

- Adds missing columns (`package_type`, `total_sessions`, `amount_paid`, etc.)
- Modifies NOT NULL constraints to allow NULLs
- Adds missing indexes
- Works with BOTH schema versions

### 2. Updated INSERT Code (`class-ptp-unified-checkout.php`)

The booking INSERT at line ~1095 now:

- **Checks which columns exist** in the current database
- **Dynamically builds INSERT** with only valid columns
- **Maps between schemas** (`package_type` ↔ `session_type`)
- **Logs failures** with full error details
- **Stores parent email** in `guest_email` as backup for notifications

## Files Modified

| File | Changes |
|------|---------|
| `ptp-training-platform.php` | Version 122.0.0, added fix class include |
| `includes/class-ptp-unified-checkout.php` | Smart INSERT that handles both schemas |
| `includes/class-ptp-booking-fix-v122.php` | NEW - Schema fix and monitoring |

## Deployment

1. Upload the updated plugin files
2. Clear any caching plugins
3. Visit any page to trigger schema fix (runs on `init`)
4. Test a training booking

## Verification

Check the error logs after a booking attempt:

```
[PTP Fix v122] Current columns: id, booking_number, trainer_id, ...
[PTP Fix v122] Schema fix completed
[PTP Return v122] Attempting booking insert with columns: ...
[PTP Return v122] Booking created successfully: 123
[PTP Notify v117.2.18] Starting notify_trainer for booking 123, trainer 5
[PTP Notify v117.2.18] Trainer email SENT to: trainer@example.com
[PTP Notify v117.2.18] Parent email SENT to: parent@example.com
```

## If Still Failing

If inserts still fail after v122:

1. Check `wp-content/debug.log` for `[PTP Return v122] BOOKING INSERT FAILED!`
2. The log will show:
   - MySQL Error message
   - The exact query that failed
   - The data that was being inserted

Common issues:
- Foreign key constraints (trainer_id or parent_id doesn't exist)
- Missing columns not covered by the fix
- Database user lacking ALTER TABLE permissions
