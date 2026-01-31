# PTP Training Platform v115.5.3

**Release Date:** January 2025

## Fixes

### 1. Version Number Consistency
**Fixed:** Version mismatch between plugin header (115.5.1) and PTP_VERSION constant (115.5.2)
- Both now correctly set to 115.5.3
- Ensures proper cache busting and version tracking

### 2. Booking Wizard Availability Check
**Fixed:** Similar trainers in booking wizard always showing `has_availability: true`
- Now properly checks if trainer has availability set via `PTP_Availability::get_weekly()`
- Falls back to `true` if Availability class not loaded
- Prevents user confusion when trainer hasn't configured their schedule

## Technical Details

### Files Modified
| File | Change |
|------|--------|
| `ptp-training-platform.php` | Updated version header and constant to 115.5.3 |
| `includes/class-ptp-booking-wizard.php` | Implemented actual availability checking |

## Code Quality Audit Results

The following were verified as properly implemented:

✅ **SQL Security** - All database queries use `$wpdb->prepare()`
✅ **Nonce Verification** - All AJAX handlers verify nonces
✅ **Escaping** - Templates properly use `esc_attr`, `esc_html`, `wp_kses`
✅ **Class Initialization** - All V71 classes properly exist and have `init()` methods
✅ **Stripe Methods** - `get_publishable_key()`, `is_enabled()`, `create_transfer()` all defined
✅ **REST API Auth** - `check_auth()` and `check_trainer_auth()` properly implemented
✅ **No Deprecated Functions** - No `mysql_*`, `ereg()`, `split()`, `create_function()` usage
✅ **PHP 8.2 Compatible** - No unguarded array access patterns found
