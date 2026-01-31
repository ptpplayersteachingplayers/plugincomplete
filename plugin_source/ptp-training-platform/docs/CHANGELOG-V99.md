# PTP Training Platform v99 - Camp Product Template Update

**Release Date:** January 16, 2026

---

## Overview

Updated camp product template with hero video sound toggle and improved responsive design.

---

## Changes

### Camp Product Template v4.2

**New Features**
- Hero video sound toggle button (bottom-right positioned)
- Sound muting coordination - unmuting hero mutes all slider videos and vice versa
- Enhanced responsive scaling for sound buttons across breakpoints

**Styling Updates**
- Brighter hero video filter: `brightness(1.3) contrast(1.05) saturate(1.1)`
- Hero sound button positioned with `bottom: 24px` on mobile, scaling up to `60px` on ultra-wide
- Consistent PTP gold (#FCB900) on black (#0A0A0A) theming

**Responsive Breakpoints**
- Mobile: 40x40px sound buttons
- Tablet (768px+): 48x48px hero sound button
- Desktop (1024px+): 52x52px hero sound button  
- Large Desktop (1280px+): 56x56px hero sound button
- Ultra Wide (1600px+): 60x60px hero sound button

### Files Updated

```
includes/class-ptp-camp-product-template.php   - v4.2.0 with hero sound toggle
assets/css/ptp-camp-product.css                - v4.9 with hero sound styles
```

---

## Code Snippet Usage

For Code Snippets integration, upload these files to WordPress:

1. **PHP Template:** Upload `ptp-camp-product-template.php` to Code Snippets
2. **CSS File:** Upload `ptp-camp-product.css` to `/wp-content/uploads/`

The template automatically loads CSS from `content_url('/uploads/ptp-camp-product.css')`.

---

## Template Features

- **Hero Section:** Full-screen video with sound toggle
- **Countdown Timer:** Early bird pricing deadline
- **Google Reviews:** Rating display with review count
- **Video Slider:** Multiple videos with individual sound toggles
- **Coach Cards:** NCAA athlete profiles with photos
- **Day-by-Day Schedule:** Camp week breakdown
- **FAQ Accordion:** Expandable questions
- **Photo Gallery:** Camp photo slider
- **Sticky Footer:** Mobile price/CTA (auto-hides on scroll)
- **Checkout Section:** WooCommerce integration with trust badges

---

## Upgrade Notes

- No database changes required
- Template is backward compatible
- CSS cache may need clearing after update
