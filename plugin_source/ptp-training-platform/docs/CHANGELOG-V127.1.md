# PTP Training Platform v127.1 - Mobile Touch Target Audit

## Overview
Comprehensive mobile audit fixing all touch targets below the 44px minimum recommended by Apple HIG and Google Material Design guidelines.

## Changes

### Find Trainers (/find-trainers)
- Fixed header-to-hero gap with `-1px` margin technique
- Reduced hero padding for more compact header (60px → 48px)
- Hidden map X button on desktop (only shows on mobile overlay)
- Smaller, more compact cards on desktop:
  - 4:3 aspect ratio images (instead of square)
  - 3 columns at 1280px+, 4 columns at 1536px+
  - Reduced fonts and padding throughout

### Parent Dashboard (parent-dashboard-v117.php → v117.1)
Touch targets fixed from 40px → 44px:
- `.pd-card-action` - Card action buttons
- `.pd-player-btn` - Player edit/delete buttons  
- `.pd-review-btn` - Review submission buttons
- `.pd-modal-close` - Modal close buttons
- `.pd-btn.sm` - Small button variant

### Trainer Dashboard (trainer-dashboard-v117.php → v117.1)
Touch targets fixed from 40px → 44px:
- `.td-btn.sm` - Small button variant
- Location remove buttons (inline style override)

### Checkout (ptp-checkout.php)
- Camp cross-sell "Add" button: 36px → 44px

## Touch Target Standards Applied
- Minimum: 44px (Apple HIG)
- Comfortable: 48px (Google Material)
- All interactive elements now meet or exceed 44px minimum

## Files Modified
1. `templates/trainers-grid.php` - Header gap, map X, card sizing
2. `templates/parent-dashboard-v117.php` - 5 touch target fixes
3. `templates/trainer-dashboard-v117.php` - 3 touch target fixes
4. `templates/ptp-checkout.php` - 1 touch target fix
5. `ptp-training-platform.php` - Version bump

## Verification
Run this grep to verify no touch targets below 44px:
```bash
grep -rn "min-height.*[34][0-3]px" templates/*.php | grep -v "100vh\|80vh\|text\|name"
```
