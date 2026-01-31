# PTP Training Platform - Changelog v121

## Version 121.0.0 (January 2026)

### 🔧 Bug Fixes

#### Thank You Page - "Payment Received - Booking Processing" Warning
**Issue:** After completing a training booking, the thank you page displayed a yellow warning banner saying "Payment Received - Booking Processing" even though the booking was successful.

**Root Cause:** 
- The page checked if `booking_id == 0` or `booking_number === 'PENDING'` to show the warning
- The booking_id wasn't being passed correctly in the redirect URL in some cases
- The booking_number field wasn't being set properly during checkout

**Fix:**
- Improved booking lookup to find booking by payment_intent if booking_id is missing
- Auto-generates booking_number if it's empty or 'PENDING'
- Changed warning display logic to only show when genuinely missing booking data
- Now shows "Confirmed" or the booking number instead of "Processing..."

#### Emails Not Sending
**Issue:** Confirmation emails were not being sent to parents or trainers after booking.

**Root Cause:**
- Email sending depended on having valid parent_id linked to a WordPress user
- Guest checkouts didn't have parent records created
- Email fallback chain wasn't comprehensive enough

**Fix:**
- Added multiple fallbacks for email addresses: WP user → parent record → guest_email → notes parsing → current user
- Added `ptp_booking_confirmed` action hook to trigger emails
- Emails now send even for guest checkouts
- Added logging for email send success/failure

#### Bookings Not Showing in Parent Dashboard
**Issue:** After booking, the session wasn't appearing in the parent's dashboard.

**Root Cause:**
- Parent dashboard queries bookings by `parent_id`
- Guest checkouts created bookings with `parent_id = 0`
- When users registered/logged in, their parent record wasn't linked to existing guest bookings

**Fix:**
- On login: Checks for orphaned parent records by email and links them to the user
- On login: Links any guest bookings (by guest_email) to the newly linked parent
- On registration: Automatically creates a parent record for new users
- Ensures parent_id is properly set even for guest checkouts

### 📁 Files Changed

- `ptp-training-platform.php` - Version bump to 121.0.0
- `templates/thank-you-v100.php` - Fixed warning banner logic and booking number display
- `includes/class-ptp-booking-fix-v121.php` - **NEW** - Comprehensive booking fix class

### 🔄 How to Update

1. Backup your current plugin folder
2. Replace `/wp-content/plugins/ptp-training-platform/` with the new version
3. Clear any caching plugins
4. Test a booking flow to verify fixes

### ⚡ Technical Details

The new `PTP_Booking_Fix_V121` class hooks into:
- `template_redirect` - Fixes thank-you page booking lookup
- `wp_login` - Links parent records after user login
- `user_register` - Creates parent records for new users
- `ptp_booking_confirmed` - Ensures emails are sent

This fix is designed to be non-destructive and works alongside existing booking logic.
