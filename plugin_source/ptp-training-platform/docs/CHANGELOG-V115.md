# PTP Training Platform v115.0.0 - Email & Thank You Page Wiring

**Release Date:** January 2025

## Overview

Complete overhaul of order confirmation emails and thank you page integration. This update ensures that branded PTP emails are reliably sent for all camp and training orders, and that the custom thank you page is properly displayed.

---

## New Features

### 1. Centralized Email Wiring (`class-ptp-order-email-wiring.php`)

New comprehensive class that coordinates all email sending with multiple fallback hooks:

**Email Trigger Points (in order of priority):**
1. `woocommerce_payment_complete` - Primary trigger when payment succeeds
2. `woocommerce_order_status_*` - Status transitions (pending→processing, pending→completed, etc.)
3. `woocommerce_checkout_order_processed` - Checkout processed backup
4. `woocommerce_thankyou` - Final fallback when thank you page loads

**Key Features:**
- Prevents duplicate emails with `_ptp_confirmation_email_sent` meta flag
- Automatic detection of PTP orders (camps, training sessions)
- Disables default WooCommerce emails for PTP orders (we send our own branded version)
- Comprehensive debug logging for troubleshooting
- Manual resend capability via admin AJAX

### 2. Thank You Page Integration

**Custom Redirect:**
- WooCommerce orders now redirect to `/thank-you/` page with PTP branding
- Falls back gracefully to WooCommerce default if custom page doesn't exist
- Passes `order` and `key` params for security validation

**Email Trigger on Page Load:**
- Thank you page template now triggers email if not already sent
- Multiple fallback methods to ensure email delivery
- Logs all actions for debugging

### 3. PTP Order Detection

Comprehensive detection of PTP-related orders:
- Product meta `_ptp_is_camp = yes`
- Product meta `_ptp_product_type` (training sessions)
- Product categories: camps, clinics, camp, summer-camps, training, ptp-training
- Product name contains: camp, clinic, training

---

## Files Changed

### New Files
- `includes/class-ptp-order-email-wiring.php` - Complete email coordination system

### Modified Files
- `ptp-training-platform.php` - Added new include, version bump to 115.0.0
- `templates/thank-you-v100.php` - Added email trigger on page load

---

## How It Works

### Email Flow

```
Customer Places Order
         ↓
[woocommerce_payment_complete]
         ↓
Check: Is this a PTP order?
         ↓
Check: Email already sent?
         ↓
Send branded PTP confirmation email
         ↓
Set _ptp_confirmation_email_sent meta
         ↓
Add order note
```

### Thank You Page Flow

```
Order Complete
         ↓
WooCommerce redirects to order-received
         ↓
PTP intercepts if PTP order
         ↓
Redirect to /thank-you/?order=XXX&key=XXX
         ↓
Thank you page checks email sent
         ↓
If not sent → triggers email
         ↓
Displays branded thank you page
```

---

## Configuration

### Email Settings

Navigate to **PTP Dashboard → Emails** to configure:
- Enable/disable PTP branded emails
- Logo URL
- Support phone number
- Support email
- Upsell section enable/disable
- Upsell text customization

### Required Settings

The system will auto-set these defaults if not configured:
- `ptp_email_enabled` = 'yes'
- `ptp_email_logo_url` = PTP logo URL
- `ptp_email_support_phone` = '(610) 761-5230'

---

## Troubleshooting

### Email Not Sending?

1. **Check WP Debug Log:**
   ```
   [PTP Email Wiring] Sending PTP confirmation email to email@example.com for order #1234
   [PTP Email Wiring] ✓ Email sent successfully
   ```

2. **Check Order Notes:**
   - Look for "PTP confirmation email sent to..." note
   - Or "PTP confirmation email FAILED to send..." for errors

3. **Manual Resend:**
   - Go to **PTP Dashboard → Emails**
   - Select order from dropdown
   - Click "Resend Email"

4. **Check Email Settings:**
   - Ensure `ptp_email_enabled` is set to 'yes'
   - Verify SMTP is configured in WordPress

### Thank You Page Not Showing?

1. **Create the page:**
   - Go to **PTP Dashboard → Pages**
   - Click "Create" next to "Thank You"

2. **Check redirect:**
   - Enable WP_DEBUG to see redirect logs
   - Look for `[PTP Email Wiring]` log entries

3. **Manual test:**
   - Visit `/thank-you/?order=XXX` with a real order ID

---

## WooCommerce Email Coordination

The new wiring class **disables** default WooCommerce emails for PTP orders:
- Customer Processing Order
- Customer Completed Order  
- Customer On-Hold Order

This prevents duplicate emails. Non-PTP orders continue to use WooCommerce defaults.

---

## Backwards Compatibility

- All existing meta flags are honored (`_ptp_email_sent`, `_ptp_confirmation_email_sent`)
- Existing `PTP_WooCommerce_Emails` class continues to work
- Falls back gracefully if new wiring class not available

---

## Testing Checklist

- [ ] Place a camp order and verify email is received
- [ ] Check order notes show email sent
- [ ] Verify thank you page displays with order details
- [ ] Test manual email resend from admin
- [ ] Verify non-PTP orders still get WooCommerce emails
- [ ] Test with WP_DEBUG to check log messages

---

## Support

If you encounter issues:
1. Enable WP_DEBUG and WP_DEBUG_LOG
2. Check `wp-content/debug.log` for `[PTP Email Wiring]` entries
3. Verify email server is working with a test plugin
4. Contact support with order ID and log entries
