# PTP Training Platform - WooCommerce Removal Plan

## Executive Summary

**Goal:** Remove all WooCommerce dependencies from PTP Training Platform, replacing them with native PHP/WordPress functionality.

**Scope:**
- 93 files contain WooCommerce references
- ~302 WC function calls to replace
- 14,344 lines in the 6 most critical files
- 105,000+ total lines of code

**Estimated Effort:** 40-60 hours of development

---

## Current WooCommerce Usage Analysis

### Session Management (137 uses)
| Method | Count | Purpose |
|--------|-------|---------|
| `WC()->session->set()` | 52 | Store session data |
| `WC()->session->get()` | 27 | Retrieve session data |
| `WC()->session->set_customer_session_cookie()` | 6 | Initialize session |
| `WC()->session->has_session()` | 6 | Check if session exists |

### Cart Management (165 uses)
| Method | Count | Purpose |
|--------|-------|---------|
| `WC()->cart->get_cart()` | 27 | Get cart items |
| `WC()->cart->calculate_totals()` | 18 | Calculate cart totals |
| `WC()->cart->get_cart_contents_count()` | 11 | Get item count |
| `WC()->cart->is_empty()` | 10 | Check if cart empty |
| `WC()->cart->empty_cart()` | 10 | Clear cart |
| `WC()->cart->get_subtotal()` | 9 | Get subtotal |
| `WC()->cart->get_total()` | 8 | Get total |
| `WC()->cart->add_to_cart()` | 6 | Add item |
| `WC()->cart->remove_cart_item()` | 3 | Remove item |
| Other methods | 8 | Various |

### WC Functions (~150 uses)
| Function | Count | Purpose |
|----------|-------|---------|
| `wc_get_order()` | 40 | Get order object |
| `wc_get_product()` | 38 | Get product object |
| `wc_add_order_item_meta()` | 12 | Add order metadata |
| `wc_get_products()` | 11 | Query products |
| `wc_create_order()` | 9 | Create new order |
| `wc_update_order_item_meta()` | 8 | Update order metadata |
| URL functions | 15 | Cart/checkout URLs |
| Other | 17 | Various |

---

## New Architecture

### Core Replacement Classes

```
┌─────────────────────────────────────────────────────────────┐
│                    PTP Native System                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────┐ │
│  │   PTP_Session   │  │    PTP_Cart     │  │  PTP_Order  │ │
│  │                 │  │                 │  │             │ │
│  │ - get()         │  │ - add_item()    │  │ - create()  │ │
│  │ - set()         │  │ - remove_item() │  │ - get()     │ │
│  │ - has_session() │  │ - get_items()   │  │ - update()  │ │
│  │ - destroy()     │  │ - get_total()   │  │ - complete()│ │
│  │                 │  │ - empty()       │  │             │ │
│  └────────┬────────┘  └────────┬────────┘  └──────┬──────┘ │
│           │                    │                   │        │
│           └────────────────────┼───────────────────┘        │
│                                │                            │
│                    ┌───────────▼───────────┐                │
│                    │    ptp_sessions DB    │                │
│                    │    ptp_cart_items DB  │                │
│                    │    ptp_orders DB      │                │
│                    └───────────────────────┘                │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### New Database Tables

```sql
-- Session storage (replaces WC session)
CREATE TABLE {prefix}ptp_sessions (
    session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED DEFAULT 0,
    session_data LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    INDEX idx_session_key (session_key),
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at)
);

-- Cart items (replaces WC cart)
CREATE TABLE {prefix}ptp_cart_items (
    cart_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL,
    item_type ENUM('training', 'camp', 'package', 'addon') NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    metadata JSON,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_key),
    INDEX idx_type (item_type)
);

-- Orders (replaces WC orders for training/camps)
CREATE TABLE {prefix}ptp_orders (
    order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED DEFAULT 0,
    parent_id BIGINT UNSIGNED DEFAULT 0,
    status ENUM('pending','processing','completed','cancelled','refunded') DEFAULT 'pending',
    subtotal DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    tax DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(50),
    payment_status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    stripe_payment_intent VARCHAR(100),
    stripe_charge_id VARCHAR(100),
    billing_first_name VARCHAR(100),
    billing_last_name VARCHAR(100),
    billing_email VARCHAR(200),
    billing_phone VARCHAR(50),
    billing_address TEXT,
    notes TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referral_code VARCHAR(50),
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME,
    INDEX idx_order_number (order_number),
    INDEX idx_user (user_id),
    INDEX idx_parent (parent_id),
    INDEX idx_status (status),
    INDEX idx_payment_status (payment_status),
    INDEX idx_created (created_at)
);

-- Order items
CREATE TABLE {prefix}ptp_order_items (
    item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    item_type ENUM('training', 'camp', 'package', 'addon') NOT NULL,
    item_reference_id BIGINT UNSIGNED,
    name VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    metadata JSON,
    FOREIGN KEY (order_id) REFERENCES {prefix}ptp_orders(order_id) ON DELETE CASCADE,
    INDEX idx_order (order_id),
    INDEX idx_type (item_type)
);

-- Order metadata
CREATE TABLE {prefix}ptp_order_meta (
    meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value LONGTEXT,
    FOREIGN KEY (order_id) REFERENCES {prefix}ptp_orders(order_id) ON DELETE CASCADE,
    INDEX idx_order_key (order_id, meta_key)
);
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Files: 4, Priority: CRITICAL)
Create the foundation classes that replace WooCommerce core functionality.

| File to Create | Replaces | Lines Est. |
|----------------|----------|------------|
| `class-ptp-session.php` | WC()->session | ~300 |
| `class-ptp-cart.php` | WC()->cart | ~500 |
| `class-ptp-order-manager.php` | wc_create_order, WC_Order | ~600 |
| `class-ptp-product-manager.php` | wc_get_product | ~200 |

### Phase 2: Compatibility Layer (Files: 1, Priority: CRITICAL)
Create a WC compatibility shim for gradual migration.

| File to Create | Purpose | Lines Est. |
|----------------|---------|------------|
| `class-ptp-wc-compat.php` | WC() function replacement | ~150 |

### Phase 3: High-Impact File Modifications (Files: 6, Priority: HIGH)
Modify the most critical files with the most WC dependencies.

| File | Lines | WC Calls | Action |
|------|-------|----------|--------|
| `class-ptp-ajax.php` | 6,064 | 21 | Modify |
| `class-ptp-cart-helper.php` | 1,152 | 13 | Rewrite |
| `class-ptp-checkout-v77.php` | 1,208 | 9 | Modify |
| `templates/ptp-checkout.php` | 3,382 | 8 | Modify |
| `class-ptp-bundle-checkout.php` | 1,493 | 6 | Modify |
| `templates/ptp-cart.php` | 1,045 | 4 | Modify |

### Phase 4: Medium-Impact Modifications (Files: 15, Priority: MEDIUM)
Files with moderate WC dependencies.

| File | WC Calls | Action |
|------|----------|--------|
| `class-ptp-crosssell-engine.php` | 12 | Modify |
| `ptp-pay.php` | 11 | Modify |
| `class-ptp-unified-checkout-handler.php` | 12 | Modify |
| `class-ptp-rest-api.php` | 8 | Modify |
| `class-ptp-growth.php` | 8 | Modify |
| `class-ptp-unified-checkout.php` | 7 | Modify |
| `templates/camp/ptp-camp-product-v10.3.5.php` | 13 | Modify |
| `class-ptp-checkout-ux.php` | 4 | Modify |
| `class-ptp-referral-system.php` | 3 | Modify |
| `class-ptp-viral-engine.php` | 2 | Modify |
| `templates/components/ptp-header.php` | 1 | Modify |
| `class-ptp-shortcodes.php` | 3 | Modify |
| `class-ptp-fixes-v72.php` | 2 | Modify |
| `class-ptp-order-integration-v71.php` | 3 | Modify |
| `ptp-training-platform.php` | 5 | Modify |

### Phase 5: Remove WC-Only Files (Files: 12, Priority: LOW)
Files that exist solely for WooCommerce integration.

| File | Action |
|------|--------|
| `class-ptp-woocommerce.php` | DELETE |
| `class-ptp-woocommerce-camps.php` | DELETE |
| `class-ptp-woocommerce-emails.php` | DELETE |
| `class-ptp-woocommerce-orders-v71.php` | DELETE |
| `class-ptp-woocommerce-cart-enhancement.php` | DELETE |
| `class-ptp-cart-checkout-v71.php` | DELETE |
| `class-ptp-unified-cart.php` | DELETE |
| `class-ptp-camp-checkout-v98.php` | DELETE |
| `class-ptp-camp-checkout-v99.php` | DELETE |
| `class-ptp-camp-crosssell-everywhere.php` | DELETE |
| `class-ptp-training-woo-integration.php` | DELETE |
| `class-ptp-order-email-wiring.php` | DELETE |

### Phase 6: Template Updates (Files: 8, Priority: MEDIUM)

| File | Action |
|------|--------|
| `templates/ptp-checkout.php` | Major rewrite |
| `templates/ptp-cart.php` | Major rewrite |
| `templates/thank-you.php` | Modify |
| `templates/thank-you-v100.php` | Modify |
| `templates/components/ptp-header.php` | Minor fix |
| `templates/camp/ptp-camp-product-v10.3.5.php` | Major rewrite |
| `templates/all-access-landing.php` | Minor fix |
| `templates/parent-dashboard-v117.php` | Check |

### Phase 7: Admin & API Updates (Files: 6, Priority: LOW)

| File | Action |
|------|--------|
| `admin/class-ptp-admin.php` | Minor fix |
| `admin/class-ptp-admin-tools-v72.php` | Minor fix |
| `admin/class-ptp-analytics-dashboard.php` | Minor fix |
| `admin/class-ptp-app-control.php` | Modify |
| `includes/class-ptp-rest-api.php` | Modify |
| `includes/class-ptp-n8n-endpoints.php` | Check |

### Phase 8: Testing & Cleanup (Priority: CRITICAL)

- Full regression testing
- Remove WC compatibility layer if no longer needed
- Update documentation
- Version bump to 148.0.0

---

## Files Summary

### Files to CREATE (4 new core files)
1. `includes/class-ptp-session.php`
2. `includes/class-ptp-cart.php`
3. `includes/class-ptp-order-manager.php`
4. `includes/class-ptp-wc-compat.php`

### Files to DELETE (12 WC-only files)
1. `includes/class-ptp-woocommerce.php`
2. `includes/class-ptp-woocommerce-camps.php`
3. `includes/class-ptp-woocommerce-emails.php`
4. `includes/class-ptp-woocommerce-orders-v71.php`
5. `includes/class-ptp-woocommerce-cart-enhancement.php`
6. `includes/class-ptp-cart-checkout-v71.php`
7. `includes/class-ptp-unified-cart.php`
8. `includes/class-ptp-camp-checkout-v98.php`
9. `includes/class-ptp-camp-checkout-v99.php`
10. `includes/class-ptp-camp-crosssell-everywhere.php`
11. `includes/class-ptp-training-woo-integration.php`
12. `includes/class-ptp-order-email-wiring.php`

### Files to MODIFY (31 files)
See detailed list in Phases 3-7 above.

---

## Detailed Task Breakdown

See the following files for implementation details:
- `PHASE-1-CORE.md` - Core class implementations
- `PHASE-2-COMPAT.md` - Compatibility layer
- `PHASE-3-CRITICAL.md` - High-impact modifications
- `PHASE-4-MEDIUM.md` - Medium-impact modifications
- `PHASE-5-DELETE.md` - Files to remove
- `PHASE-6-TEMPLATES.md` - Template updates
- `PHASE-7-ADMIN.md` - Admin updates
- `TESTING.md` - Test plan

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Breaking existing bookings | Medium | High | Database migration script |
| Payment flow issues | Medium | Critical | Extensive Stripe testing |
| Session data loss | Low | Medium | Session migration script |
| Email delivery failure | Low | Medium | Test all email triggers |
| Third-party integration breaks | Medium | Medium | API compatibility layer |

---

## Success Criteria

1. ✅ Plugin activates without WooCommerce installed
2. ✅ Training bookings work end-to-end
3. ✅ Camp registrations work end-to-end
4. ✅ Payments process correctly via Stripe
5. ✅ All emails send correctly
6. ✅ Dashboards display order history
7. ✅ No PHP errors in debug.log
8. ✅ Performance equal or better than WC version
