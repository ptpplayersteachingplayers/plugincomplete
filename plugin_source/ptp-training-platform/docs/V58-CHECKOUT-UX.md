# PTP Training Platform v58.0.0 - Checkout UX Improvements

## Overview
This release fixes UX issues in the checkout flow between trainings and camps/clinics.

## Key Fixes

### 1. Smarter Popup Timing
- Exit intent popup no longer interrupts users on trainer profiles
- Popup suppressed during active booking flow
- No popup when user has items in cart
- No popup when there's a pending training booking

### 2. Improved Package Display
- New `ptp_render_package_selector()` function for displaying packages
- Better visual hierarchy with clear pricing
- "Most Popular" badge highlights 5-session package
- Proper contrast and touch targets for mobile
- PTP design system compliance (Oswald/Inter fonts, gold/black colors)

### 3. Enhanced Referral Code UX
- New `ptp_render_referral_banner_v2()` with improved copy functionality
- One-click copy with visual feedback
- Native share integration (SMS, WhatsApp, Web Share API)
- Dark and light theme options
- Compact mode for inline display

### 4. Bundle Cross-Sell Flow
- Clear bundle discount indicator on checkout
- Sticky awareness banner on camp product pages
- Improved thank-you page CTA for completing training booking
- Better session handling for bundle state
- Smart redirect to checkout (not cart) for bundle purchases

## New Files
- `includes/class-ptp-checkout-ux.php` - Main UX improvements class
- `includes/class-ptp-packages-display.php` - Package selector and referral banner components
- `assets/css/checkout-ux.css` - Styling for checkout UX components

## Usage

### Package Selector
```php
// In your template
ptp_render_package_selector(120, 'trainer-slug');

// Or via shortcode
[ptp_packages rate="120" trainer="trainer-slug"]
```

### Referral Banner
```php
// In your template
ptp_render_referral_banner_v2(null, array(
    'style' => 'dark', // or 'light'
    'compact' => false,
));

// Or via shortcode
[ptp_referral_v2 style="dark" compact="false"]
```

## Filter Hooks

### `ptp_should_show_exit_popup`
Control whether the exit popup should display.

```php
// Example: Disable popup on specific pages
add_filter('ptp_should_show_exit_popup', function($should_show) {
    if (is_page('my-special-page')) {
        return false;
    }
    return $should_show;
});
```

## JavaScript Events

### `ptp:packageSelected`
Fired when a user selects a package option.

```javascript
document.addEventListener('ptp:packageSelected', function(e) {
    console.log('Package selected:', e.detail);
    // { packageId: '5pack', sessions: 5, price: 510 }
});
```

## Design System Compliance
All components follow the PTP design system:
- Fonts: Oswald (headings), Inter (body)
- Colors: #FCB900 (gold), #0A0A0A (black)
- Borders: 2px solid, sharp edges
- Labels: Uppercase with letter-spacing
- Hover states: Gold highlight
- Mobile-first responsive design
