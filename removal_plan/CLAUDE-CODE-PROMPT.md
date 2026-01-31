# Claude Code Execution Prompt: PTP WooCommerce Removal

Copy and paste this prompt to Claude Code (or Claude with computer use) to begin the migration.

---

## PROMPT START

You are tasked with removing all WooCommerce dependencies from the PTP Training Platform WordPress plugin. The goal is to make the plugin fully functional without WooCommerce installed.

## Project Context

- **Plugin Location**: `/wp-content/plugins/ptp-training-platform/`
- **Plugin Size**: 184 PHP files, 105,000+ lines of code
- **Target Version**: 148.0.0 (WooCommerce-Free)
- **Current Version**: 147

## WooCommerce Dependencies to Replace

### Session (137 uses across 16 files)
- `WC()->session->set()` - 52 uses
- `WC()->session->get()` - 27 uses
- `WC()->session->has_session()` - 6 uses
- `WC()->session->set_customer_session_cookie()` - 6 uses

### Cart (165 uses across 23 files)
- `WC()->cart->get_cart()` - 27 uses
- `WC()->cart->calculate_totals()` - 18 uses
- `WC()->cart->get_cart_contents_count()` - 11 uses
- `WC()->cart->is_empty()` - 10 uses
- `WC()->cart->empty_cart()` - 10 uses
- `WC()->cart->get_subtotal()` - 9 uses
- `WC()->cart->get_total()` - 8 uses
- `WC()->cart->add_to_cart()` - 6 uses
- Other cart methods - 15 uses

### WC Functions (~150 uses)
- `wc_get_order()` - 40 uses
- `wc_get_product()` - 38 uses
- `wc_add_order_item_meta()` - 12 uses
- `wc_get_products()` - 11 uses
- `wc_create_order()` - 9 uses
- `wc_update_order_item_meta()` - 8 uses
- URL functions - 15 uses
- Other - 17 uses

## Execution Plan

Execute these phases IN ORDER. Complete each phase fully before moving to the next.

### PHASE 1: Create Core Infrastructure Files

Create these 4 new files in `/includes/`:

#### 1.1 Create `class-ptp-session.php`
Purpose: Replace WC()->session with native PHP sessions + database storage

Required functionality:
- `get($key, $default)` - Get session value
- `set($key, $value)` - Set session value
- `has_session()` - Check if session exists
- `set_customer_session_cookie($set)` - Initialize session cookie
- `destroy()` - Clear session
- Database table: `wp_ptp_sessions` (session_key, user_id, session_data, expires_at)
- Cookie-based session tracking for guests
- User ID-based sessions for logged-in users
- Auto-save on shutdown
- Cleanup cron for expired sessions

#### 1.2 Create `class-ptp-cart.php`
Purpose: Replace WC()->cart with native cart system

Required functionality:
- `add_to_cart($type, $item_id, $qty, $price, $metadata)` - Add item
- `remove_cart_item($cart_key)` - Remove item
- `get_cart()` - Get all items
- `is_empty()` - Check if empty
- `empty_cart()` - Clear cart
- `get_cart_contents_count()` - Item count
- `get_subtotal()` - Subtotal
- `get_total($context)` - Total ('view' for formatted, 'edit' for raw)
- `calculate_totals()` - Recalculate
- `get_fees()` - Get applied fees
- `apply_coupon($code)` - Apply discount code
- Database table: `wp_ptp_cart_items` (session_key, item_type, item_id, quantity, price, metadata)
- Support item types: 'training', 'camp', 'package', 'addon'

#### 1.3 Create `class-ptp-order-manager.php`
Purpose: Replace wc_create_order(), wc_get_order(), WC_Order

Required functionality:
- `create_order($args)` - Create new order
- `get_order($order_id)` - Get order by ID
- `get_orders($args)` - Query orders with filters
- PTP_Order class with:
  - All billing getters/setters
  - `add_item($data)` - Add order item
  - `add_product($product, $qty)` - WC compatibility
  - `calculate_totals()` - Calculate order totals
  - `payment_complete($transaction_id)` - Mark paid
  - `add_order_note($note)` - Add note
  - `get_meta($key)` / `add_meta($key, $value)`
  - `save()` - Persist changes
  - `get_checkout_order_received_url()` - Thank you page URL
- Database tables: `wp_ptp_orders`, `wp_ptp_order_items`, `wp_ptp_order_meta`

#### 1.4 Create `class-ptp-wc-compat.php`
Purpose: Provide WC() shim for gradual migration

Required functionality:
- `WC()` function that returns compat object
- `WC()->session` routes to `ptp_session()`
- `WC()->cart` routes to `ptp_cart()`
- All `wc_*` function replacements
- Only loads if WooCommerce is NOT active
- Logs unknown method calls for migration tracking

### PHASE 2: Update Main Plugin File

Modify `ptp-training-platform.php`:

1. Add at top after constants:
```php
define('PTP_VERSION', '148.0.0');
```

2. Add helper function:
```php
function ptp_wc_active() {
    return class_exists('WooCommerce') && function_exists('WC');
}
```

3. In `includes()` method, add at the BEGINNING:
```php
// Load WC compatibility layer FIRST (only if WC not active)
if (!ptp_wc_active()) {
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-wc-compat.php';
}

// Load native PTP core classes
require_once PTP_PLUGIN_DIR . 'includes/class-ptp-session.php';
require_once PTP_PLUGIN_DIR . 'includes/class-ptp-cart.php';
require_once PTP_PLUGIN_DIR . 'includes/class-ptp-order-manager.php';
```

4. Wrap ALL WC-only includes with `if (ptp_wc_active())`:
- class-ptp-woocommerce.php
- class-ptp-woocommerce-camps.php
- class-ptp-woocommerce-emails.php
- class-ptp-woocommerce-orders-v71.php
- class-ptp-woocommerce-cart-enhancement.php
- class-ptp-cart-checkout-v71.php
- class-ptp-unified-cart.php
- class-ptp-camp-checkout-v98.php
- class-ptp-camp-checkout-v99.php
- class-ptp-camp-crosssell-everywhere.php
- class-ptp-training-woo-integration.php
- class-ptp-order-email-wiring.php
- class-ptp-unified-order-email.php
- templates/camp/ptp-camp-product-v10.3.5.php

### PHASE 3: Update Database Class

Modify `includes/class-ptp-database.php`:

Add to `create_tables()` method:
```php
// PTP Native tables (WC replacement)
PTP_Session::create_table();
PTP_Cart::create_table();
PTP_Order_Manager::create_tables();
```

### PHASE 4: Modify High-Impact Files

For each file, apply these search/replace patterns:

#### Session replacements:
- `WC()->session->get(` → `ptp_session()->get(`
- `WC()->session->set(` → `ptp_session()->set(`
- `WC()->session->has_session()` → `ptp_session()->has_session()`
- `WC()->session->set_customer_session_cookie(` → `ptp_session()->set_customer_session_cookie(`

#### Cart replacements:
- `WC()->cart->get_cart()` → `ptp_cart()->get_cart()`
- `WC()->cart->calculate_totals()` → `ptp_cart()->calculate_totals()`
- `WC()->cart->get_cart_contents_count()` → `ptp_cart()->get_cart_contents_count()`
- `WC()->cart->is_empty()` → `ptp_cart()->is_empty()`
- `WC()->cart->empty_cart()` → `ptp_cart()->empty_cart()`
- `WC()->cart->get_subtotal()` → `ptp_cart()->get_subtotal()`
- `WC()->cart->get_total('edit')` → `ptp_cart()->get_total('edit')`
- `WC()->cart->get_total()` → `ptp_cart()->get_total()`

#### Function replacements:
- `wc_create_order(` → `ptp_create_order(`
- `wc_get_order(` → `ptp_get_order(`
- `wc_get_orders(` → `ptp_get_orders(`

Files to modify (in order of priority):

1. `includes/class-ptp-ajax.php` (6064 lines, 21 WC calls)
2. `includes/class-ptp-cart-helper.php` (1152 lines, 13 WC calls) - MAJOR REWRITE
3. `includes/class-ptp-checkout-v77.php` (1208 lines, 9 WC calls)
4. `templates/ptp-checkout.php` (3382 lines, 8 WC calls)
5. `includes/class-ptp-bundle-checkout.php` (1493 lines, 6 WC calls)
6. `templates/ptp-cart.php` (1045 lines, 4 WC calls)
7. `ptp-pay.php` (11 WC calls)
8. `includes/class-ptp-crosssell-engine.php` (12 WC calls)
9. `includes/class-ptp-rest-api.php` (8 WC calls)
10. `includes/class-ptp-growth.php` (8 WC calls)
11. `templates/components/ptp-header.php` (1 WC call)
12. `templates/camp/ptp-camp-product-v10.3.5.php` (13 WC calls) - Only if NOT wrapped

### PHASE 5: Handle Conditional Checks

Many files have `if (function_exists('WC'))` or `if (class_exists('WooCommerce'))` guards.

For each occurrence:
1. If the guarded code can use PTP native classes, update to use them
2. If the code is WC-only functionality, wrap entire block with `if (ptp_wc_active())`
3. Add PTP alternative in an `else` block if needed

Example:
```php
// BEFORE
if (function_exists('WC') && WC()->cart) {
    $count = WC()->cart->get_cart_contents_count();
}

// AFTER
if (function_exists('ptp_cart')) {
    $count = ptp_cart()->get_cart_contents_count();
} elseif (ptp_wc_active() && WC()->cart) {
    $count = WC()->cart->get_cart_contents_count();
} else {
    $count = 0;
}
```

### PHASE 6: Create Thank You Page Handler

The thank you page needs to work with PTP orders:

Update `templates/thank-you.php` to:
1. Check for PTP order number in URL: `$_GET['order']`
2. Load order: `$order = ptp_get_order_by_number($order_number)`
3. Display order details from PTP_Order object
4. Fall back to WC order if using compat mode

### PHASE 7: Testing Checklist

After all changes, verify:

1. [ ] Plugin activates without WooCommerce
2. [ ] No PHP errors in debug.log
3. [ ] Session persists across page loads
4. [ ] Cart add/remove/update works
5. [ ] Cart persists across page loads
6. [ ] Training booking flow completes
7. [ ] Camp registration flow completes
8. [ ] Stripe payment processes
9. [ ] Order is created in database
10. [ ] Thank you page displays order
11. [ ] Confirmation emails send
12. [ ] Parent dashboard shows orders
13. [ ] Trainer dashboard works
14. [ ] Admin can view orders

## Files to NOT Modify

These files are WC-only and will be conditionally loaded:
- class-ptp-woocommerce.php
- class-ptp-woocommerce-camps.php
- class-ptp-woocommerce-emails.php
- class-ptp-woocommerce-orders-v71.php
- class-ptp-woocommerce-cart-enhancement.php
- class-ptp-cart-checkout-v71.php
- class-ptp-unified-cart.php
- class-ptp-camp-checkout-v98.php
- class-ptp-camp-checkout-v99.php
- class-ptp-camp-crosssell-everywhere.php
- class-ptp-training-woo-integration.php
- class-ptp-order-email-wiring.php
- class-ptp-unified-order-email.php

## Important Notes

1. **Backup First**: Always backup before making changes
2. **Test Incrementally**: Test after each phase
3. **Keep WC Compat**: The compatibility layer allows fallback
4. **Log Errors**: Check debug.log frequently
5. **Database**: Tables are created on activation, may need manual trigger

## Global Helper Functions to Create

```php
// In a new file or at end of class-ptp-session.php
function ptp_session() {
    return PTP_Session::instance();
}

// In class-ptp-cart.php
function ptp_cart() {
    return PTP_Cart::instance();
}

// In class-ptp-order-manager.php
function ptp_orders() {
    return PTP_Order_Manager::instance();
}

function ptp_create_order($args = array()) {
    return PTP_Order_Manager::instance()->create_order($args);
}

function ptp_get_order($order_id) {
    return PTP_Order_Manager::instance()->get_order($order_id);
}

function ptp_get_orders($args = array()) {
    return PTP_Order_Manager::instance()->get_orders($args);
}

function ptp_price($amount) {
    return '$' . number_format((float)$amount, 2);
}
```

Begin with Phase 1. Create the core infrastructure files first, then proceed through each phase sequentially.

---

## PROMPT END
