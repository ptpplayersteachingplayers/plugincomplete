# PTP Plugin v146 - WooCommerce Removal Guide

## Overview

Version 146 introduces a complete WooCommerce-free camp system using Stripe Checkout Sessions directly. This document outlines what was created and the steps to fully remove WooCommerce from your site.

## New Files Created

### Core Camp System Classes

| File | Purpose |
|------|---------|
| `includes/class-ptp-camp-orders.php` | Order management, database tables, discount calculations |
| `includes/class-ptp-camp-checkout.php` | Stripe Checkout Session creation, product sync |
| `includes/class-ptp-camp-emails.php` | Email notifications for orders, reminders, refunds |
| `includes/ptp-camp-system-loader.php` | Loads all camp classes, webhooks, API endpoints |

### Admin Interface

| File | Purpose |
|------|---------|
| `admin/class-ptp-camp-orders-admin.php` | Admin order management, refunds, export |

### Templates

| File | Purpose |
|------|---------|
| `templates/camp/camp-checkout.php` | Multi-step checkout UI |
| `templates/camp/camp-thank-you.php` | Order confirmation page |
| `templates/camp/camps-listing.php` | Camps grid display |

### Assets

| File | Purpose |
|------|---------|
| `assets/js/camp-checkout.js` | Checkout frontend logic |
| `assets/css/camp-checkout.css` | Checkout styling |

## Database Tables Created

The new system creates these tables (automatically on activation):

```sql
-- Camp Orders
wp_ptp_camp_orders
  - id, order_number, user_id, parent_id
  - billing info (name, email, phone, address)
  - emergency contact info
  - pricing (subtotal, discounts, fees, total)
  - payment (stripe session/intent IDs, status)
  - referral codes (used and generated)
  - team registration fields
  - timestamps

-- Camp Order Items (Campers)
wp_ptp_camp_order_items
  - order_id, stripe_product_id
  - camp info (name, dates, location, time)
  - camper info (name, dob, age, gender, shirt size)
  - medical info
  - add-ons (care bundle, jersey)
  - waiver info
  - status

-- Camp Referrals
wp_ptp_camp_referrals
  - code, order_id, user_id, email
  - usage stats
  - reward tracking

-- Stripe Products (Camps)
wp_ptp_stripe_products
  - stripe_product_id, stripe_price_id
  - name, description, price
  - camp metadata (dates, location, time, ages, capacity)
```

## Features Included

### Discount System
- **Sibling Discount**: 10% off additional campers (automatic)
- **Team Discounts**: 10% (5+), 15% (10+), 20% (15+ campers)
- **Multiweek Discounts**: 10% (2 weeks), 15% (3 weeks), 20% (4+ weeks)
- **Referral Codes**: $25 off for both parties

### Add-ons
- **Before + After Care Bundle**: $60 (extends 8am-4:30pm)
- **Camp Jersey**: $50 (was $75)

### Payment Processing
- Direct Stripe Checkout Sessions
- 3% + $0.30 processing fee (passed to customer)
- Webhook handling for payment completion
- Refund support via admin

### Emails
- Order confirmation with referral code
- Camp reminder (7 days before)
- Cancellation/refund notification

## Steps to Remove WooCommerce

### 1. Backup Everything
```bash
# Database
wp db export backup-before-woo-removal.sql

# Files
zip -r wp-content-backup.zip wp-content/
```

### 2. Migrate Existing Orders (Automatic)
The system includes a migration function that runs once on admin_init. It will:
- Find WooCommerce orders for camp products
- Create corresponding records in the new ptp_camp_orders table
- Mark orders as migrated to prevent duplicates

### 3. Update Camp Products in Stripe
Your camps should be created as Stripe Products with metadata:

```
type: camp (or clinic)
dates: June 17-21, 2024
location: Villanova University
time: 9:00 AM - 12:00 PM
age_min: 6
age_max: 14
capacity: 50
```

Then use the "Sync from Stripe" button in PTP > Camp Products.

### 4. Update Page Links
- Replace WooCommerce checkout links with `/camp-checkout/`
- Replace shop/product links with `/camps/`
- Update any menu items

### 5. Test the New Flow
1. Visit `/camps/` to see the listing
2. Select a camp and go through checkout
3. Verify Stripe Checkout Session is created
4. Complete payment (use test mode)
5. Verify order appears in PTP > Camp Orders
6. Check confirmation email received

### 6. Deactivate WooCommerce
```bash
wp plugin deactivate woocommerce woocommerce-stripe-gateway
```

### 7. Clean Up (Optional)
After confirming everything works:
```bash
# Remove WooCommerce tables (CAUTION: backup first!)
wp db query "DROP TABLE wp_woocommerce_sessions, wp_woocommerce_api_keys, wp_woocommerce_attribute_taxonomies, wp_woocommerce_order_items, wp_woocommerce_order_itemmeta, wp_woocommerce_tax_rates, wp_woocommerce_tax_rate_locations, wp_woocommerce_shipping_zones, wp_woocommerce_shipping_zone_locations, wp_woocommerce_shipping_zone_methods, wp_woocommerce_payment_tokens, wp_woocommerce_payment_tokenmeta, wp_woocommerce_log, wp_wc_product_meta_lookup, wp_wc_tax_rate_classes, wp_wc_reserved_stock, wp_wc_webhooks, wp_wc_download_log, wp_wc_customer_lookup, wp_wc_order_stats, wp_wc_order_product_lookup, wp_wc_order_tax_lookup, wp_wc_order_coupon_lookup;"

# Delete WooCommerce plugins
wp plugin delete woocommerce woocommerce-stripe-gateway
```

## Configuration

### Stripe Settings
The camp system uses the same Stripe settings as training:
- PTP Settings > Stripe > Test/Live keys
- Webhook endpoint: `/wp-json/ptp/v1/stripe-webhook-camps`

### Add these webhook events in Stripe Dashboard:
- `checkout.session.completed`
- `checkout.session.expired`
- `charge.refunded`

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[ptp_camps]` | Full camps listing page |
| `[ptp_camp_checkout]` | Multi-step checkout |
| `[ptp_camp_thank_you]` | Order confirmation |

## REST API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/ptp/v1/camps` | GET | List all active camps |
| `/wp-json/ptp/v1/camps/{id}` | GET | Get single camp |
| `/wp-json/ptp/v1/camp-orders` | GET | List orders (admin) |
| `/wp-json/ptp/v1/camp-orders/{id}` | GET | Get order (admin) |
| `/wp-json/ptp/v1/stripe-webhook-camps` | POST | Stripe webhooks |

## Troubleshooting

### Orders not completing
1. Check Stripe webhook is configured
2. Verify webhook secret in PTP Settings
3. Check error logs for webhook errors

### Products not showing
1. Run "Sync from Stripe" in Camp Products
2. Verify products have `type: camp` metadata
3. Check products are active in Stripe

### Emails not sending
1. Check WordPress email is working
2. Verify SMTP plugin if using one
3. Check spam folders

## What Stays with WooCommerce

If you still need WooCommerce for other products (merchandise, gift cards, etc.), the camp system will work alongside it. The plugin conditionally loads WooCommerce classes only if WC is active.

## Support

For issues or questions, contact: dev@ptpsummercamps.com
