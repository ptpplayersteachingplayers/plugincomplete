# PTP Training Platform v120.0.0

## Stripe Product Webhook Integration

**Release Date:** January 2026

### Overview
Trainings are now created directly in Stripe (not WooCommerce). Email notifications are triggered automatically from Stripe `product.created` events via webhooks.

---

### New Features

#### 1. Stripe Product Webhooks
Added webhook handlers for Stripe product lifecycle events:

- **`product.created`** - Triggers email notification to trainer when a new training is created
- **`product.updated`** - Syncs product changes to local database
- **`product.deleted`** - Marks product as inactive in database

#### 2. New Email Template: `new-training-created`
Professional email sent to trainers when their training goes live:
- Training name and price display
- Description (if provided)
- Next steps guidance
- Dashboard link
- Also notifies admin

#### 3. New Database Table: `ptp_stripe_products`
Tracks Stripe products locally for reference:
```sql
- id (bigint, auto-increment)
- stripe_product_id (varchar, unique)
- trainer_id (bigint, indexed)
- name (varchar)
- description (text)
- price_cents (int)
- active (tinyint)
- created_at (datetime)
- updated_at (datetime)
```

---

### Technical Details

#### Stripe Webhook Events to Enable
In your Stripe Dashboard > Webhooks, add these events:
- `product.created`
- `product.updated`
- `product.deleted`

#### Required Product Metadata
When creating products in Stripe, include this metadata:
```json
{
  "trainer_id": "123"
}
```

This links the product to the trainer for notifications and database tracking.

#### Webhook Endpoint
```
POST /wp-json/ptp/v1/stripe-webhook
```

---

### Files Modified
- `includes/class-ptp-stripe.php` - Added product webhook handlers
- `includes/class-ptp-email.php` - Added `send_new_training_notification()` method and email template
- `includes/class-ptp-database.php` - Added `ptp_stripe_products` table
- `ptp-training-platform.php` - Version bump to 120.0.0

---

### Migration Notes
After updating, visit any admin page to trigger database migration and create the new `ptp_stripe_products` table.

No manual data migration required - the table will populate as new products are created in Stripe.
