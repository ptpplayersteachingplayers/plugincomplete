# PTP Training Platform v141 Changelog

## Camp Product ↔ Checkout Integration

### Overview
v141 introduces full integration between the camp product template (v10.3.5) and checkout (v99) to handle multi-week camp selections properly.

### The Problem
Previously, when a user selected 2-3 camp weeks on the product page:
1. Products were added to cart with multiweek discount
2. Checkout v98 didn't know about this selection
3. Checkout still showed upgrade bumps (2-pack, 3-pack, All-Access)
4. This could cause double-discounts or user confusion

### The Solution

#### Session Variables
The product template now sets session variables that checkout v99 reads:

```php
// Product template sets these when user selects multiple weeks:
WC()->session->set('ptp99_camps_in_cart', $count);      // Number of camps
WC()->session->set('ptp99_upgrade_pack', '');           // Clear any upgrade selection
WC()->session->set('ptp99_jersey', true);               // If jersey selected
```

#### Checkout v99 Detection
Checkout v99 checks for multi-week selection:

```php
$camps_in_cart = (int)$this->sess('camps_in_cart', 0);  // Reads ptp99_camps_in_cart
if ($camps_in_cart > 1) {
    // Show confirmation box instead of upgrade bumps
}
```

### Integration Flow

1. **User on Product Page**
   - Selects 2+ camp weeks via checkboxes
   - Clicks "Continue to Checkout"

2. **AJAX Handler (ptp_add_multiple_weeks)**
   - Adds selected products to cart
   - Sets `ptp99_camps_in_cart = count`
   - Sets `ptp99_upgrade_pack = ''` (prevents checkout upgrade conflicts)
   - If jersey selected, sets `ptp99_jersey = true`

3. **Cart Fee Handler (apply_multiweek_discount)**
   - Counts camp products in cart
   - Applies 10% discount for 2 camps, 20% for 3+
   - Sets `ptp99_camps_in_cart` session for checkout

4. **Checkout v99 (render_discounts_section)**
   - Reads `ptp99_camps_in_cart` from session
   - If > 1: Shows green confirmation box "X-CAMP PACK SELECTED"
   - If ≤ 1: Shows upgrade bumps (2-pack, 3-pack, All-Access)

### Files Changed

#### class-ptp-camp-checkout-v99.php
- Changed session prefix from `ptp98_` to `ptp99_`
- Added multicamp detection in `render_discounts_section()`
- Conditional UI: confirmation vs upgrade bumps
- Updated all AJAX actions to `ptp99_update`

#### ptp-camp-product-v10.3.5.php
- Added session flags in `ajax_add_multiple_weeks()`
- Added session update in `apply_multiweek_discount()`
- Added checkout integration comments

#### ptp-camp-product-v10.3.5.css
- Comprehensive mobile optimization
- Compact toggles for week selector and FAQ
- Header isolation to prevent platform CSS conflicts
- Admin bar support

### Deployment

1. Upload `class-ptp-camp-checkout-v99.php` to `/wp-content/plugins/ptp-training-platform/includes/`

2. Update main plugin file to load v99:
   ```php
   require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camp-checkout-v99.php';
   ```

3. Upload `ptp-camp-product-v10.3.5.php` and `.css` to `/wp-content/uploads/`

### Testing Checklist

- [ ] Select 1 camp week → Checkout shows upgrade bumps
- [ ] Select 2 camp weeks → Checkout shows "2-CAMP PACK SELECTED" (10% off)
- [ ] Select 3 camp weeks → Checkout shows "3-CAMP PACK SELECTED" (20% off)
- [ ] Add jersey on product page → Jersey appears in checkout
- [ ] Mobile: Header stays fixed, toggles are compact
- [ ] No double-discounts applied
