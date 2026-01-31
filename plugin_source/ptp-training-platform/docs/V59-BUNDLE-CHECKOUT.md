# PTP Training Platform v59.4.0 - Unified Bundle Checkout

## Overview
This release consolidates all Training + Camps bundle checkout logic into a single, unified system. Previously, bundle checkout was fragmented across multiple files (`class-ptp-growth.php`, `class-ptp-crosssell-engine.php`, `class-ptp-checkout-ux.php`) with different state management approaches. Now everything is in one place: `class-ptp-bundle-checkout.php`.

## What's New

### Unified Bundle Checkout System (`class-ptp-bundle-checkout.php`)
A single class that handles:
- Bundle creation and state management
- Combined checkout for training + camp
- Stripe payment processing for bundles
- Discount calculations (15% bundle discount)
- Session/cookie tracking
- REST API endpoints

### New Template (`templates/bundle-checkout.php`)
A dedicated bundle checkout page that:
- Shows both training and camp items
- Displays bundle discount and savings
- Handles payment in one flow
- Works with the existing PTP design system

### New Page
- `/bundle-checkout/` - Unified checkout for bundles
- Uses shortcode `[ptp_bundle_checkout]`

## How It Works

### Creating a Bundle
1. User starts on trainer profile or camp page
2. When they want both training + camp, a bundle is created via AJAX
3. Bundle state is stored in database, session, and cookie
4. User is redirected to `/bundle-checkout/?bundle=BND-XXXXXXXX`

### Bundle Checkout Flow
1. User sees both items (training + camp) on one page
2. Bundle discount (15%) is automatically applied
3. User fills in contact and player info
4. Single Stripe payment processes entire bundle
5. System creates:
   - PTP booking record for training
   - WooCommerce order for camp
6. User redirected to confirmation page

## Key Changes

### Updated Files
- `ptp-training-platform.php` - Added bundle checkout include and page creation
- `includes/class-ptp-templates.php` - Added bundle-checkout to page list
- `includes/class-ptp-shortcodes.php` - Added bundle checkout shortcode
- `templates/checkout.php` - Updated to use unified bundle system

### New Files
- `includes/class-ptp-bundle-checkout.php` - Main bundle checkout class
- `templates/bundle-checkout.php` - Bundle checkout template
- `V59-BUNDLE-CHECKOUT.md` - This documentation

## API Reference

### PHP Functions
```php
// Get bundle checkout instance
$bundle = ptp_get_bundle_checkout();

// Check if bundle discount applies
if (ptp_has_bundle_discount()) {
    $discount = ptp_get_bundle_discount($amount);
}
```

### AJAX Endpoints
- `ptp_create_bundle` - Create new bundle with training
- `ptp_add_camp_to_bundle` - Add camp to existing bundle
- `ptp_process_bundle_checkout` - Process bundle payment
- `ptp_confirm_bundle_payment` - Confirm payment and create orders
- `ptp_get_bundle_status` - Get active bundle status
- `ptp_clear_bundle` - Clear active bundle

### REST API
- `GET /wp-json/ptp/v1/bundle/status` - Get bundle status
- `POST /wp-json/ptp/v1/bundle/create` - Create new bundle

## Database
New table: `wp_ptp_bundles`
- Stores bundle details (training + camp)
- Tracks payment status
- Links to booking and WooCommerce order IDs

## Configuration
Bundle settings in `class-ptp-bundle-checkout.php`:
```php
const BUNDLE_DISCOUNT_PERCENT = 15;  // 15% off
const BUNDLE_EXPIRY_HOURS = 48;      // Bundles expire after 48 hours
```

## Migration Notes
- Existing bundle logic in other classes remains for backwards compatibility
- New unified system takes precedence when active
- Old WooCommerce session-based bundle tracking still works as fallback
