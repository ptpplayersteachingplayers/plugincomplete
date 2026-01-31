# PTP Training Platform v92.0.0 - CHANGELOG

**Released:** January 15, 2026

## Summary
Full mobile-optimized trainer dashboard with SPA-style tabs, trainers-grid gap fix, consolidated jersey upsell features.

---

## New Features

### Trainer Dashboard v92 (trainer-dashboard-v92.php)
Complete rewrite with mobile-first SPA architecture:

**5 Tab Navigation:**
- **Home** - Stats overview, upcoming sessions, sessions needing confirmation, share profile
- **Schedule** - Inline availability editing with toggles and time pickers
- **Earnings** - Total earned, available to withdraw, completed session history
- **Messages** - Conversation list with unread indicators
- **Profile** - Quick edit name, bio, phone, city

**Mobile Optimizations:**
- Fixed bottom navigation with 44px touch targets
- Safe area padding for iPhone X+ notch
- Spring animations for tab transitions
- Sticky header with stats always visible
- Touch-friendly toggles and buttons

**AJAX Functionality:**
- Save availability without page reload
- Update hourly rate inline
- Request payout with one tap
- Save profile changes via AJAX
- Session confirmation via AJAX

---

## Fixed

### Trainers Grid (trainers-grid.php)
- **Gap at top REMOVED** - No more whitespace between header and hero section
- Added aggressive CSS overrides to prevent theme margins/padding

### Cart (ptp-cart.php)
- Version bump to v92 (maintains v90 functionality)

### Checkout (ptp-checkout.php)
- Version bump to v92 (maintains v91 functionality)

---

## New AJAX Endpoints

```php
// Trainer Dashboard v92
ptp_save_availability    // Save weekly availability (day toggles + times)
ptp_update_trainer_rate  // Update hourly rate
ptp_request_payout       // Request payout of available balance
```

---

## Files Changed
- `ptp-training-platform.php` - Version 89.1.0 → 92.0.0
- `templates/trainer-dashboard-v92.php` - NEW (replaces v88)
- `templates/trainers-grid.php` - v88 → v92 with gap fix
- `templates/ptp-cart.php` - v90 → v92
- `templates/ptp-checkout.php` - v91 → v92
- `includes/class-ptp-shortcodes.php` - Updated to load v92 trainer dashboard
- `includes/class-ptp-ajax.php` - Added 3 new AJAX handlers

---

## Testing Checklist

### Trainer Dashboard
- [ ] Home tab shows stats, upcoming sessions, share profile
- [ ] Schedule tab toggles work, times save via AJAX
- [ ] Earnings tab shows total/available/history
- [ ] Messages tab shows conversations
- [ ] Profile tab saves changes via AJAX
- [ ] Bottom nav switches tabs without page reload
- [ ] All touch targets are 44px minimum
- [ ] Works on iPhone with notch (safe area)

### Trainers Grid
- [ ] No gap at top on mobile
- [ ] No gap at top on desktop

### Cart/Checkout
- [ ] Jersey upsell displays and syncs
- [ ] All mobile layouts correct
