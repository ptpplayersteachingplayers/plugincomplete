# PTP Training Platform v128.2.7

## Release Date: January 24, 2026

## Summary
Fixed critical issues with the training thank-you page not displaying correctly and parent confirmation emails not triggering upon ordering.

## Bug Fixes

### Thank You Page - Training Display Fix
- **Issue**: Training thank-you page would sometimes show blank or generic content instead of training confirmation
- **Root Cause**: Booking detection was failing when `booking_id` wasn't passed in URL parameters
- **Fix**: Added enhanced booking lookup that:
  - Searches for recent bookings by trainer_id from checkout session data
  - Extends search window from 5 to 10 minutes for trainer-based lookup
  - Adds booking lookup by `guest_email` field

### Parent Email Trigger Fix
- **Issue**: Parent confirmation emails were not reliably sending after training purchases
- **Root Cause**: Email was being skipped because parent email wasn't found in booking record
- **Fix**: Enhanced email fallback chain to include:
  1. Parent email from `ptp_parents` table
  2. WP user email from parent's user account
  3. `guest_email` field stored in booking (for guest checkout)
  4. Email from checkout session transient data
  5. Currently logged-in user's email

### notify_trainer Function Enhancement
- Improved parent email retrieval with checkout session transient lookup
- Auto-updates parent record with email if found from checkout data but missing in database
- Better logging for email sending success/failure

## Technical Changes

### Files Modified
1. `templates/thank-you-v100.php`
   - Added early booking lookup by trainer_id from session
   - Enhanced email fallback logic
   - Improved booking query to include `booking_guest_email` alias
   - Better time formatting for display

2. `includes/class-ptp-unified-checkout.php`
   - Enhanced `notify_trainer()` function with checkout transient lookup
   - Auto-fix for missing parent emails in database
   - Improved logging throughout email sending flow

3. `ptp-training-platform.php`
   - Version bump to 128.2.7

## Testing Checklist
- [ ] Purchase training as logged-in user → Verify thank-you page shows training details
- [ ] Purchase training as guest → Verify thank-you page shows training details
- [ ] Verify parent receives confirmation email immediately after purchase
- [ ] Verify trainer receives notification email
- [ ] Refresh thank-you page → Should not send duplicate emails
- [ ] Test with different packages (single, pack3, pack5)

## Rollback
If issues arise, restore `thank-you-v100.php.bak` from templates directory.
