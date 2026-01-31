# PTP Training Platform - Changelog v114.1

## Release Date: January 2026

## Critical Order Processing Fixes

This release addresses critical issues with WooCommerce order creation, confirmation emails, and thank-you page display.

---

### 🔴 CRITICAL: Order Creation Fixed

**Problem**: Orders weren't being created when cart items were empty (camps-only flow).

**Fix**: Modified `class-ptp-unified-checkout.php` line 463-464:
- Removed the `!empty($cart_items_data)` condition that was blocking order creation
- Order creation now proceeds even without WC cart items
- Added fallback product addition from camp_id when cart is empty

```php
// OLD (v114):
if ($has_woo && !empty($cart_items_data) && function_exists('wc_create_order'))

// NEW (v114.1):
if ($has_woo && function_exists('wc_create_order'))
```

---

### 🔴 CRITICAL: Email Fallback System

**Problem**: Confirmation emails weren't sent when WooCommerce order creation failed.

**Fix**: Added comprehensive fallback email handling:
- Customer receives confirmation email even if WC order fails
- Admin receives alert email about failed orders
- Full logging of email delivery attempts

---

### 🟡 HIGH: Thank You Page Enhancements

**Problem**: Thank-you page showed blank when order ID wasn't passed correctly.

**Fixes**:
1. **Enhanced parameter handling** - Now supports both `order` and `order_id` GET params
2. **Recent order fallback** - If no order_id, checks user's most recent orders
3. **Alternate lookup methods** - Tries post lookup if wc_get_order fails
4. **Comprehensive logging** - Full debug output to track order retrieval

```php
// v114.1: If no order ID, check recent orders for current user
if (!$order_id && is_user_logged_in() && function_exists('wc_get_customer_orders')) {
    $recent_orders = wc_get_customer_orders(array(
        'customer_id' => get_current_user_id(),
        'limit' => 1,
        'status' => array('pending', 'processing', 'completed'),
    ));
    if (!empty($recent_orders)) {
        $order_id = $recent_orders[0]->get_id();
    }
}
```

---

### 🟢 NEW: Order Diagnostic API Endpoint

**Feature**: Added `ptp_check_order_status` AJAX endpoint for debugging.

**Usage**: Call via JavaScript to diagnose order issues:
```javascript
jQuery.post(ajaxurl, {
    action: 'ptp_check_order_status',
    nonce: ptp_vars.nonce,
    order_id: 123,
    session: 'checkout_session_id'
}, function(response) {
    console.log(response);
});
```

**Returns**:
- `order_exists`: Boolean
- `order_data`: Order ID, status, total, item count, date
- `session_data`: Checkout session info if available
- `error`: Error message if applicable

---

## Files Modified

| File | Changes |
|------|---------|
| `ptp-training-platform.php` | Version bump to 114.1.0 |
| `includes/class-ptp-unified-checkout.php` | Order creation fixes, email fallback |
| `includes/class-ptp-ajax.php` | Added diagnostic endpoint |
| `templates/thank-you-v100.php` | Enhanced order lookup, debug logging |

---

## Testing Checklist

After deploying, verify:

- [ ] Place camp order → Order appears in WooCommerce admin
- [ ] Place order → Confirmation email received
- [ ] Thank-you page shows order details and total
- [ ] Check `wp-content/debug.log` for `[PTP Return v114.1]` and `[PTP Thank You v114.1]` entries

---

## Debug Commands

Check order status:
```php
$order = wc_get_order(123);
var_dump($order ? 'Found' : 'Not found');
```

Check recent orders:
```php
$orders = wc_get_orders(array('limit' => 5, 'orderby' => 'date'));
foreach ($orders as $o) {
    echo $o->get_id() . ' - ' . $o->get_status() . "\n";
}
```

Watch logs:
```bash
tail -f wp-content/debug.log | grep "PTP Return\|PTP Thank You"
```

---

## Rollback Instructions

If issues occur, restore the v114 files:
1. Restore `class-ptp-unified-checkout.php` from backup
2. Restore `thank-you-v100.php` from backup
3. Remove diagnostic endpoint from `class-ptp-ajax.php`
