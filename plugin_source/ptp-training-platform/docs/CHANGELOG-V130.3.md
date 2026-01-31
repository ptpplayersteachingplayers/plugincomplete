# Changelog v130.3.0 - Enterprise Hardening

**Release Date:** January 2025

## Summary
Enterprise-grade improvements for scaling to 50+ trainers and 500+ parents, plus critical scroll fix, profile overflow fixes, trainer profile booking widget scrollbar fix, and signout URL support.

---

## Changes

### Critical Bug Fix: Page Scrolling
- **Fixed scroll blocking** that prevented users from scrolling on pages
- Removed `position: fixed` from `body.menu-open` and `body.modal-open` CSS rules (this was breaking scroll restoration)
- Added early JavaScript cleanup on page load to remove any stuck scroll-blocking classes
- Added fallback CSS rule to ensure scroll works when no modal is open

### Trainer Profile Booking Widget Scrollbar Fix
- **Hidden ugly scrollbar** on booking widget while keeping content scrollable
- Added `scrollbar-width: none` and `::-webkit-scrollbar { display: none }` to booking widget
- Fixed calendar section overflow to prevent horizontal scroll
- Styled page scrollbar to be thin and subtle (6px width, transparent track)

### Trainer Dashboard Profile Mobile Fixes
- **Fixed profile elements overflowing** on small screens (under 480px)
- Added `overflow-x: hidden` and `max-width: 100%` to profile tab and cards
- Specialty grid now wraps properly with max 50% width per item
- Input rows stack vertically on mobile instead of side-by-side
- Cover photo aspect ratio adjusts for small screens
- Share URL input properly truncates with ellipsis
- Extra small screen support (under 360px) with compact spacing

### Signout URL Support
- **Added /signout/ and /sign-out/ URL support** - both redirect to /logout/
- Works even without creating a WordPress page
- Handles logged-out users by redirecting to login page

### Database Performance
- **Added 6 new indexes** for high-traffic queries:
  - `idx_booking_created_at` - Faster date range reports
  - `idx_booking_stripe` - Quick payment lookups
  - `idx_trainer_stripe_account` - Payout processing
  - `idx_trainer_featured_sort` - Optimized trainer grid
  - `idx_parent_created` - Customer growth reports
  - `idx_booking_payout_pending` - Batch payout queries

### Auto-Setup on Activation
- Database tables now auto-create on plugin activation
- Performance indexes auto-apply on activation AND update
- Version tracking ensures upgrades apply incrementally

### Configurable Platform Fee
- **Breaking Change:** `PTP_PLATFORM_FEE_PERCENT` constant deprecated
- Platform fee now managed via **PTP Settings > General > Platform Fee**
- New helper functions:
  - `ptp_get_platform_fee()` - Returns decimal (0.20 for 20%)
  - `ptp_get_platform_fee_percent()` - Returns percentage (20)
- Default remains 20% if not configured

---

## Files Changed
- `ptp-training-platform.php` - Version bump, scroll fix script, signout intercept, configurable fee functions, activation hook
- `includes/class-ptp-database.php` - New indexes, activate() method
- `includes/class-ptp-supabase-bridge.php` - Use `ptp_get_platform_fee()`
- `assets/css/ptp-universal-mobile.css` - Removed position:fixed from body scroll rules
- `assets/css/ptp-header-v88.css` - Fixed body.menu-open rule
- `templates/components/ptp-header.php` - Added scroll class cleanup on load
- `templates/trainer-dashboard-v117.php` - Added comprehensive mobile CSS for profile section
- `templates/trainer-profile-v3.php` - Hidden booking widget scrollbar, fixed calendar overflow

---

## Testing Checklist
- [ ] Pages scroll properly on all devices
- [ ] Mobile menu opens/closes without breaking scroll
- [ ] Modals open/close without breaking scroll
- [ ] Trainer dashboard profile tab fits on small screens (360px-480px)
- [ ] Specialty grid wraps properly on mobile
- [ ] /signout/ URL redirects to logout page
- [ ] /sign-out/ URL redirects to logout page
- [ ] Fresh install creates all tables
- [ ] Fresh install applies all indexes
- [ ] Upgrade from 130.2 applies new indexes
- [ ] Platform fee change in admin reflects in bookings
