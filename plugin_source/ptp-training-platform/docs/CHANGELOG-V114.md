# Changelog v114 - Order Wiring Fix

## Release Date: January 2025

## Critical Bug Fix: Orders Not Creating After Payment

### The Problem
Orders were not being created in WooCommerce after successful Stripe payments. The payment would succeed, but no order appeared in the backend.

### Root Cause
The checkout flow had a **session/cart timing issue**:

1. When the checkout form submits, `save_checkout_data()` saved form data to a WordPress transient
2. After payment succeeds, the user redirects to the thank-you page
3. `handle_payment_return()` tried to create the WooCommerce order
4. **BUT** it relied on `WC()->cart` having items - which was empty after the redirect!

The transient data didn't include the actual cart items (product IDs, quantities, prices), so even when payment succeeded, the WooCommerce order couldn't be created.

### The Fix

#### 1. Enhanced `save_checkout_data()` (class-ptp-unified-checkout.php)
- Now captures **all WooCommerce cart items** before the redirect
- Stores product IDs, variation IDs, quantities, line totals, and prices
- Includes all add-on data: before/after care, upgrades, referral codes, jersey upsells
- Extended transient lifetime from 1 hour to 2 hours

#### 2. Fixed `create_orders_from_session()` (class-ptp-unified-checkout.php)  
- Creates WooCommerce order from **stored cart data** instead of relying on `WC()->cart`
- Properly adds all fees (care, upgrades, referral discounts, jerseys)
- Saves comprehensive order meta: player info, waiver status, emergency contacts
- Triggers WooCommerce emails correctly after order creation
- Creates parent and player records if not exists

#### 3. Fixed Thank You Page (thank-you-v100.php)
- Now handles both `?order=xxx` and `?order_id=xxx` URL parameters
- Added debug logging for troubleshooting
- Improved camper name extraction from order meta

#### 4. Added Hidden Fields (ptp-checkout.php)
- Added `jersey_amount` hidden field
- JavaScript now updates jersey amount when toggled

### Technical Details

**Before (broken):**
```php
// Relied on cart existing after redirect - UNRELIABLE
if (WC()->cart && !WC()->cart->is_empty()) {
    foreach (WC()->cart->get_cart() as $cart_item) {
        // Cart was often empty here!
    }
}
```

**After (fixed):**
```php
// Uses stored cart data - RELIABLE
$cart_items_data = $data['cart_items'] ?? array();
foreach ($cart_items_data as $item_data) {
    $product = wc_get_product($item_data['product_id']);
    $order->add_product($product, $item_data['quantity'], ...);
}
```

### Files Modified
- `includes/class-ptp-unified-checkout.php` - Core checkout handling
- `templates/ptp-checkout.php` - Added jersey_amount field
- `templates/thank-you-v100.php` - Fixed order param handling

### Testing Checklist
- [ ] Add camp to cart
- [ ] Complete checkout with card payment
- [ ] Verify order appears in WooCommerce Orders
- [ ] Verify order has correct player info, fees, totals
- [ ] Verify confirmation email is sent
- [ ] Verify thank-you page shows order details
- [ ] Test with add-ons (before/after care, upgrades)
- [ ] Test with referral code applied
- [ ] Test with jersey upsell added

### Debugging
Check your site's error log for messages starting with:
- `[PTP Checkout v114]` - Data being saved before payment
- `[PTP Return v114]` - Order being created after payment return
- `[PTP Thank You v114]` - Thank you page loading order
