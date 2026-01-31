# PTP Training Platform v87 - Camp Checkout Enhancements

**Release Date**: January 2025

## Overview

Major camp checkout enhancement release adding sibling discounts, team registration, referral codes, and card-only payment processing.

## Features

### 1. Sibling Discounts
- **$50 discount** for each additional camper after the first
- "Add Another Camper" button with savings badge
- Real-time savings display banner
- Unlimited siblings supported

### 2. Team Registration
- Volume discounts based on team size:
  - 5-9 players: **10% off**
  - 10-14 players: **15% off**
  - 15+ players: **20% off**
- Team name and coach info capture
- Contact email for team coordinator
- Post-checkout sharing instructions

### 3. Referral Code System
- **$25 off** for both referrer and referee
- Auto-generated unique codes on thank you page
- AJAX validation with instant feedback
- Codes stored in `ptp_referral_codes` table
- Usage tracking and expiration support

### 4. Card-Only Payments
- Blocked payment methods:
  - Bank transfers (BACS)
  - Cash on delivery
  - CashApp, Venmo
  - SEPA, iDEAL, Giropay, Sofort, Bancontact
- Only credit/debit cards accepted for camps

### 5. Transparent Processing Fee
- **3% + $0.30** processing fee
- Calculated after discounts applied
- Clearly displayed in cart totals
- Fee only applied when subtotal > $0

## New Database Table

```sql
CREATE TABLE ptp_referral_codes (
    id bigint(20) UNSIGNED AUTO_INCREMENT,
    code varchar(12) NOT NULL UNIQUE,
    order_id bigint(20) UNSIGNED,
    user_id bigint(20) UNSIGNED,
    uses_remaining int(11) DEFAULT 5,
    discount_amount decimal(10,2) DEFAULT 25.00,
    expires_at datetime DEFAULT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

## AJAX Endpoints

| Action | Purpose |
|--------|---------|
| `ptp_v87_validate_referral` | Validate and apply referral code |
| `ptp_v87_update_session` | Update camper count and team info |

## Files Changed

### Added
- `includes/class-ptp-camp-checkout-v87.php` - Main v87 checkout class
- `assets/js/camp-checkout-v87.js` - Frontend JavaScript
- `docs/CHANGELOG-V87.md` - This file

### Modified
- `ptp-training-platform.php` - Version bump to 87.0.0
- `README.md` - Added v87 changelog entry
- `includes/class-ptp-camp-checkout-v86.php` - Disabled processing fee (moved to v87)

## UI Components

### Registration Type Selector
- Individual/Family option (default)
- Team Registration option
- Visual card-style selection

### Referral Code Box
- Input field with "Apply" button
- Success/error message display
- Hidden field stores validated code

### Sibling Section
- Dynamic form generation for each sibling
- Remove button for each additional camper
- Savings banner shows total discount

### Team Info Box (shown when team selected)
- Team name (required)
- Coach name (optional)
- Number of players (min 5)
- Contact email (optional)

## CSS Classes

- `.ptp-v87-checkout` - Main container
- `.ptp-box` - Section wrapper
- `.ptp-type-card` - Registration type selector
- `.ptp-savings` - Savings banner
- `.ptp-sibling-section` - Additional camper form
- `.ptp-payment-note` - Card-only notice

## Order Meta Keys

| Key | Description |
|-----|-------------|
| `_ptp_camper_count` | Number of campers |
| `_ptp_referral_code` | Applied referral code |
| `_ptp_is_team` | Team registration flag |
| `_ptp_team_name` | Team name |
| `_ptp_team_coach` | Coach name |
| `_ptp_team_size` | Number of players |
| `_ptp_team_email` | Team contact email |
| `_ptp_sibling_X_*` | Sibling info fields |

## Upgrade Notes

- v87 runs alongside v86 (both classes initialize)
- v86 processing fee is disabled, v87 handles it
- Referral table created automatically on first load
- No manual migration required
