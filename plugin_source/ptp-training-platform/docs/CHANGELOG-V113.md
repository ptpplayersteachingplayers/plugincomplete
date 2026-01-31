# PTP Training Platform - Changelog v113

## Version 113.0.0 - January 2025

### Payment Intent & Stripe Handling Fixes

#### PTP_Stripe Class Improvements
- Added **idempotency key support** to prevent duplicate charges on retry
- Improved error handling with specific error codes and messages
- Added **payment intent caching** to reduce API calls
- Pinned Stripe API version (2023-10-16) for consistency
- Better logging for debugging payment issues
- Added fallback to legacy option names for API keys
- Enhanced `is_enabled()` check to properly validate configuration

#### PTP_Payments Class Updates
- Stricter payment verification before completing bookings
- Multiple nonce action support for better compatibility
- Non-blocking email sending via wp_schedule_single_event
- Better error messages for users
- Improved idempotency key generation per booking

#### ptp-pay.php Direct Endpoint
- Complete rewrite with improved architecture
- Integrated with PTP_Stripe class when available
- Multiple nonce verification fallbacks
- Rate limiting (10 requests/minute for create, 5/minute for process)
- Payment verification before order creation
- Better error handling and logging
- Added `verify` action to check payment status
- Version tracking in test response

### Technical Details

#### Idempotency Keys
Payment intents now use idempotency keys in the format:
- Bookings: `ptp_booking_{booking_id}_{timestamp}`
- Direct pay: `ptp_pay_{uuid}`

This prevents duplicate charges if users:
- Double-click the pay button
- Have network issues causing retries
- Refresh during payment processing

#### Nonce Compatibility
The following nonce actions are now accepted:
- `ptp_nonce` (primary)
- `ptp_checkout_action` (cart helper)
- `ptp_checkout` (legacy)
- `ptp_cart_action` (cart operations)

#### Rate Limiting
- Payment intent creation: 10 attempts per 60 seconds
- Payment processing: 5 attempts per 60 seconds
- Per IP address tracking via transients

### Migration Notes
- No database changes required
- Backward compatible with existing integrations
- All existing API keys and settings are preserved
- Drop-in replacement - same folder name `ptp-training-platform`
