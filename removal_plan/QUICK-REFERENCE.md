# Quick Reference: Files to Modify

## Files Sorted by Priority

### CRITICAL (Do First)
| File | Lines | WC Calls | Action |
|------|-------|----------|--------|
| `ptp-training-platform.php` | 2,695 | 5 | Add conditionals, load new classes |
| `includes/class-ptp-database.php` | ~800 | 0 | Add new table creation |

### NEW FILES TO CREATE
| File | Purpose |
|------|---------|
| `includes/class-ptp-session.php` | Replace WC()->session |
| `includes/class-ptp-cart.php` | Replace WC()->cart |
| `includes/class-ptp-order-manager.php` | Replace wc_create_order, wc_get_order |
| `includes/class-ptp-wc-compat.php` | WC() function shim |

### HIGH PRIORITY (Core Functionality)
| File | Lines | WC Calls | Notes |
|------|-------|----------|-------|
| `includes/class-ptp-ajax.php` | 6,064 | 21 | Main AJAX handlers |
| `includes/class-ptp-cart-helper.php` | 1,152 | 13 | **MAJOR REWRITE** - mostly WC code |
| `includes/class-ptp-checkout-v77.php` | 1,208 | 9 | Training checkout |
| `templates/ptp-checkout.php` | 3,382 | 8 | Checkout template |
| `includes/class-ptp-bundle-checkout.php` | 1,493 | 6 | Bundle purchases |
| `templates/ptp-cart.php` | 1,045 | 4 | Cart template |
| `ptp-pay.php` | ~400 | 11 | Direct payment endpoint |

### MEDIUM PRIORITY
| File | WC Calls | Notes |
|------|----------|-------|
| `includes/class-ptp-crosssell-engine.php` | 12 | Upsell logic |
| `includes/class-ptp-unified-checkout-handler.php` | 12 | Combined checkout |
| `includes/class-ptp-rest-api.php` | 8 | API endpoints |
| `includes/class-ptp-growth.php` | 8 | Growth tracking |
| `includes/class-ptp-unified-checkout.php` | 7 | Checkout handler |
| `templates/camp/ptp-camp-product-v10.3.5.php` | 13 | Camp product page |
| `includes/class-ptp-checkout-ux.php` | 4 | UX helpers |
| `includes/class-ptp-referral-system.php` | 3 | Referrals |
| `includes/class-ptp-shortcodes.php` | 3 | Shortcodes |
| `includes/class-ptp-order-integration-v71.php` | 3 | Order integration |

### LOW PRIORITY
| File | WC Calls | Notes |
|------|----------|-------|
| `includes/class-ptp-viral-engine.php` | 2 | Viral features |
| `includes/class-ptp-fixes-v72.php` | 2 | Bug fixes |
| `templates/components/ptp-header.php` | 1 | Cart count in header |
| `templates/thank-you.php` | 2 | Thank you page |
| `templates/thank-you-v100.php` | 3 | Alt thank you |
| `admin/class-ptp-admin.php` | 1 | Admin checks |
| `admin/class-ptp-analytics-dashboard.php` | 1 | Analytics |

### FILES TO WRAP (Not Modify)
These should be wrapped with `if (ptp_wc_active())` in main plugin file:

```
includes/class-ptp-woocommerce.php
includes/class-ptp-woocommerce-camps.php
includes/class-ptp-woocommerce-emails.php
includes/class-ptp-woocommerce-orders-v71.php
includes/class-ptp-woocommerce-cart-enhancement.php
includes/class-ptp-cart-checkout-v71.php
includes/class-ptp-unified-cart.php
includes/class-ptp-camp-checkout-v98.php
includes/class-ptp-camp-checkout-v99.php
includes/class-ptp-camp-crosssell-everywhere.php
includes/class-ptp-training-woo-integration.php
includes/class-ptp-order-email-wiring.php
includes/class-ptp-unified-order-email.php
templates/camp/ptp-camp-product-v10.3.5.php
```

---

## Search/Replace Cheatsheet

### Session
```
Find:    WC()->session->get(
Replace: ptp_session()->get(

Find:    WC()->session->set(
Replace: ptp_session()->set(

Find:    WC()->session->has_session()
Replace: ptp_session()->has_session()

Find:    WC()->session->set_customer_session_cookie(
Replace: ptp_session()->set_customer_session_cookie(
```

### Cart
```
Find:    WC()->cart->get_cart()
Replace: ptp_cart()->get_cart()

Find:    WC()->cart->calculate_totals()
Replace: ptp_cart()->calculate_totals()

Find:    WC()->cart->get_cart_contents_count()
Replace: ptp_cart()->get_cart_contents_count()

Find:    WC()->cart->is_empty()
Replace: ptp_cart()->is_empty()

Find:    WC()->cart->empty_cart()
Replace: ptp_cart()->empty_cart()

Find:    WC()->cart->get_subtotal()
Replace: ptp_cart()->get_subtotal()

Find:    WC()->cart->get_total(
Replace: ptp_cart()->get_total(

Find:    WC()->cart->add_to_cart(
Replace: ptp_cart()->add_to_cart(

Find:    WC()->cart->remove_cart_item(
Replace: ptp_cart()->remove_cart_item(
```

### Orders
```
Find:    wc_create_order(
Replace: ptp_create_order(

Find:    wc_get_order(
Replace: ptp_get_order(

Find:    wc_get_orders(
Replace: ptp_get_orders(
```

### Conditional Guards
```
Find:    if (function_exists('WC') && WC()->cart)
Replace: if (function_exists('ptp_cart'))

Find:    if (function_exists('WC') && WC()->session)  
Replace: if (function_exists('ptp_session'))

Find:    if (class_exists('WooCommerce'))
Replace: if (ptp_wc_active())
```

---

## Database Tables to Create

```sql
-- Sessions
CREATE TABLE wp_ptp_sessions (
    session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED DEFAULT 0,
    session_data LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    KEY (user_id),
    KEY (expires_at)
);

-- Cart Items
CREATE TABLE wp_ptp_cart_items (
    cart_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL,
    item_type VARCHAR(20) NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    metadata JSON,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY (session_key),
    KEY (item_type)
);

-- Orders
CREATE TABLE wp_ptp_orders (
    order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED DEFAULT 0,
    parent_id BIGINT UNSIGNED DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending',
    subtotal DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(50),
    payment_status VARCHAR(20) DEFAULT 'pending',
    stripe_payment_intent VARCHAR(100),
    billing_first_name VARCHAR(100),
    billing_last_name VARCHAR(100),
    billing_email VARCHAR(200),
    billing_phone VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    KEY (user_id),
    KEY (status)
);

-- Order Items
CREATE TABLE wp_ptp_order_items (
    item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(20) NOT NULL,
    item_reference_id BIGINT UNSIGNED,
    name VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    metadata JSON,
    KEY (order_id)
);

-- Order Meta
CREATE TABLE wp_ptp_order_meta (
    meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value LONGTEXT,
    KEY (order_id, meta_key)
);
```

---

## Testing Commands

```bash
# Check for remaining WC calls after migration
grep -rn "WC()" --include="*.php" | grep -v "ptp_wc_active\|class_exists\|function_exists\|// "

# Count remaining calls
grep -roh "WC()->" --include="*.php" | wc -l

# Check for wc_ functions
grep -rn "wc_" --include="*.php" | grep -v "ptp_wc\|// "

# Verify no syntax errors
find . -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```
