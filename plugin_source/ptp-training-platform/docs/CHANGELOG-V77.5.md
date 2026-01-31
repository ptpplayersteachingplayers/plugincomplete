# PTP Training Platform v77.5.1 Changelog

## Release Date: January 1, 2026

## Summary
v77.5.1 fixes trainer profile photo cropping on desktop and improves cart→checkout navigation reliability.

---

## 🖼️ Trainer Profile Photo Fix

### The Problem
On desktop, trainer profile photos were cropped to show only the top of the head instead of the face. This was caused by `object-fit: cover` defaulting to center-center positioning.

### The Fix
Added `object-position: center 20%` to position the crop point lower, showing the trainer's face:

```css
.tp-photo img {
    object-fit: cover;
    object-position: center 20%; /* Show face, not top of head */
}
```

---

## 🛒 Cart → Checkout Navigation Fix

### The Problem
Clicking "Proceed to Checkout" from the cart page could result in a 404 if the ptp-checkout page didn't exist or had an unexpected slug.

### The Fix
1. **Smart checkout URL detection** - Cart now searches for checkout pages by multiple slugs:
   - `ptp-checkout`
   - `checkout`  
   - `training-checkout`

2. **Auto-create if missing** - If no checkout page exists, one is automatically created

3. **Template matching flexibility** - Checkout template now loads for multiple slug variations:
   - `ptp-checkout`, `ptp_checkout`, `checkout`, `training-checkout`

4. **V77 template for shortcode** - The `[ptp_checkout]` shortcode now uses the v77 template instead of legacy

---

## 📋 Files Changed

### `templates/trainer-profile-v2.php`
- Added `object-position: center 20%` to `.tp-photo img`

### `templates/ptp-cart.php`
- Smart checkout URL detection with fallback
- Auto-create checkout page if missing

### `includes/class-ptp-unified-checkout-handler.php`
- Flexible slug matching for cart/checkout pages
- Updated shortcode to use v77 template

### `ptp-training-platform.php`
- Version bump to 77.5.1

---

## 🧪 Testing

1. **Trainer profile**: Photo should show face, not top of head
2. **Cart page**: Click "Proceed to Checkout" → should load checkout page
3. **Delete checkout page**: Cart should auto-create it on next load

---

## 🔧 Payout Logic Fix

### The Problem (v77.4 and earlier)
When discounts were applied (bundle discounts, package discounts), both the trainer AND platform took proportional cuts:

```
Trainer rate: $100/hr
15% discount applied:
- Customer pays: $85
- OLD: Trainer gets: $68 (80% of $85) ← WRONG
- OLD: Platform gets: $17 (20% of $85)
```

This penalized trainers for PTP's marketing promotions.

### The Solution (v77.5)
Trainers now always receive 80% of their **listed rate**, not the discounted amount:

```
Trainer rate: $100/hr
15% discount applied:
- Customer pays: $85
- NEW: Trainer gets: $80 (80% of $100) ← PROTECTED
- NEW: Platform gets: $5 ($85 - $80, absorbs discount)
```

### Payout Examples

| Scenario | Customer Pays | Trainer Gets | PTP Gets |
|----------|--------------|--------------|----------|
| No discount ($100/hr) | $100 | $80 | $20 |
| 15% bundle discount | $85 | $80 | $5 |
| 3-pack, 10% discount | $270 | $240 | $30 |
| 5-pack, 15% discount | $425 | $400 | $25 |
| 10-pack, 20% discount | $800 | $800 | $0 |

### Business Rationale
- Trainers set their rates and expect that income
- Discounts are PTP's marketing/acquisition cost
- This protects trainer relationships and retention
- Camps remain the profit center; training covers CAC

---

## 📋 Files Changed

### `includes/class-ptp-checkout-v77.php`
- Updated payout calculation logic
- Added trainer rate lookup from database
- Added session count support for packages
- Enhanced booking notes with financial breakdown
- Added logging for heavy discount scenarios

### `ptp-training-platform.php`
- Version bump to 77.5.0

---

## 🔍 Technical Details

### New Payout Calculation
```php
// Get trainer's listed rate from database
$trainer_rate = $wpdb->get_var("SELECT hourly_rate FROM ptp_trainers WHERE id = %d");

// Trainer always gets 80% of listed rate (not discounted amount)
$trainer_payout = round($trainer_rate * $sessions * 0.80, 2);

// Platform absorbs discount from their 20% cut
$platform_fee = round($amount_charged - $trainer_payout, 2);
```

### Booking Notes Now Include
```
Package: 5-Session Package (5 sessions)
Player: Billy Smith (Age 12)
Parent: John Smith <john@example.com>
Phone: (555) 123-4567
Trainer Rate: $100.00/hr
Customer Paid: $425.00
Platform Fee: $25.00
Checkout v77.5.0
```

---

## ⚠️ Notes

- Platform fee can be $0 with 20% discount (10-pack scenario)
- Platform fee can go negative if discount > 20% (rare edge case)
- Negative platform fees are logged as "CAC" (customer acquisition cost)
- This change affects NEW bookings only; existing bookings unchanged

---

## 🧪 Testing

1. Book single session without discount → Platform fee = 20%
2. Book with bundle discount (15%) → Trainer still gets 80% of rate
3. Book 5-pack (15% off) → Platform fee reduced, trainer protected
4. Check booking notes for accurate financial breakdown
