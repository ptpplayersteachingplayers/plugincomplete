# PTP Training Platform v130.1.0 - Mobile Audit Fixes

**Released:** January 24, 2026

## v130.2.0 - Booking Popup Fix

**Fixed:** Trainer profile booking bottom sheet not sliding up on mobile
- The `ptp-mobile-fixes-v127.css` had overly broad selectors for `.book-bar` that forced `position: fixed`
- This broke the trainer profile where `.book-bar` is meant to stay inside the `.book` bottom sheet container
- Added more specific selectors to only affect standalone booking bars, not the trainer profile's integrated bottom sheet

## Mobile Improvements

### High Priority Fixes

1. **Fixed iOS Scroll Issues**
   - Changed `overflow-x: hidden` on `html` to `overflow-x: clip`
   - Prevents scroll chaining issues on iOS Safari while maintaining horizontal overflow prevention

2. **Virtual Keyboard Detection**
   - Added visual viewport API listeners to detect keyboard open/close
   - Parent Dashboard: Bottom nav hides smoothly when keyboard opens
   - Trainer Profile: Booking sheet adjusts height for keyboard
   - Checkout: Mobile bar hides when keyboard is active
   - Smooth transitions for better UX

3. **Checkout Timeout Safety**
   - Added 45-second timeout on checkout submit
   - If payment gets stuck, button resets with helpful error message
   - Prevents users from being stuck on loading state

### Accessibility Improvements

4. **Minimum Font Sizes on Mobile**
   - Secondary text (labels, hints): minimum 11px
   - Primary readable content: minimum 12px
   - Uses `max()` function for graceful degradation

5. **Improved Color Contrast**
   - Small text labels now use gray-600 (#4B5563) on mobile
   - Better readability on bright screens

## Files Modified

- `assets/css/ptp-mobile-fixes-v127.css`
- `templates/parent-dashboard-v117.php`
- `templates/trainer-profile-v3.php`
- `templates/ptp-checkout.php`
- `ptp-training-platform.php` (version bump)

## Technical Notes

- Virtual viewport API is well-supported (iOS 13+, Chrome 61+, Firefox 91+)
- Graceful fallback for older browsers - keyboard handling simply won't apply
- No breaking changes to existing functionality
