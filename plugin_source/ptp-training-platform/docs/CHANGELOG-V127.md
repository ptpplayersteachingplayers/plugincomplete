# PTP Training Platform v127 Changelog

## v127.0.0 - Mobile Optimization Audit

### Overview
Comprehensive mobile optimization audit across all pages to ensure consistent spacing, sizing, and touch targets throughout the platform.

### Mobile Fixes

#### Global Touch Targets
- All buttons, links, and interactive elements now have minimum 48px touch targets
- Form checkboxes increased to 22px for easier tapping
- Navigation items optimized for thumb-zone interaction

#### Find Trainers Page
- Hero section padding optimized (48px top, 40px bottom)
- Search bar vertical stacking with proper spacing
- Filter pills increased to 44px minimum height
- Trainer cards now horizontal layout on mobile with 130px image width
- Map toggle button increased to 52px height

#### Trainer Dashboard
- Tab navigation with sticky positioning and horizontal scroll
- Tab items now 16px vertical padding with 48px minimum height
- Booking cards with improved action button layout
- Earnings display optimized for mobile screens
- Payout button full-width with 52px minimum height

#### Parent Dashboard
- Hero padding adjusted for safe areas
- Stats section with 24px gap
- Quick actions grid maintained at 4 columns with smaller padding
- Session cards with optimized spacing (14px padding)
- Trainer favorites grid responsive at 2 columns
- Bottom navigation with proper safe area padding

#### Trainer Profile
- Photo size optimized to 110px
- Name font size reduced to 26px for mobile
- Stats gap reduced to 28px
- Booking widget maximum height set to 90vh/90dvh
- Calendar buttons 38px size
- Time slots grid 3 columns with 48px minimum height
- Package selection cards with 48px minimum height

#### Booking Wizard
- Progress steps with horizontal scroll
- Trainer cards with 56px photo size
- Calendar days 42px minimum height
- Time slots 48px minimum height
- Form inputs 16px font size (iOS zoom prevention)
- Fixed action bar with safe area support

#### Checkout Page
- Form padding optimized (16px horizontal, 140px bottom for fixed button)
- Input fields with 14px padding and 48px minimum height
- Waiver checkbox with 48px minimum height
- Fixed summary bar at bottom with safe area support

#### Auth Pages (Login/Register)
- Form wrapper full viewport height using 100dvh
- Input padding increased to 16px
- Submit button 56px height
- Social login buttons 52px height
- Remember me checkbox 22px size

#### Apply Page
- Full-width inputs with 16px padding
- Section labels with proper spacing
- Contract checkbox 48px minimum height
- Submit button 56px height
- Safe area bottom padding

#### Training Landing Page
- Hero minimum height 85vh
- Title 36px font size
- CTA button 52px minimum height

#### Membership & All Access Pages
- Tier cards single column on mobile
- Price font size 36px
- CTA buttons 52px minimum height

#### Account & Messaging
- Section padding optimized
- Row layout vertical on mobile
- Message input fixed at bottom with safe area

### Small Screen Support (< 375px)
- Find Trainers hero stats stacked vertically
- Quick actions grid 2x2 instead of 4x1
- Trainer favorites single column
- Time slots 2 columns instead of 3
- Reduced font sizes for headings

### Landscape Mode
- Hero heights reduced to 60vh
- Booking sheet maximum height 70vh
- Modals maximum height 80vh

### Technical Improvements
- CSS custom properties for safe areas and touch targets
- Consistent use of `--ptp-safe-bottom` variable
- Prevention of horizontal scroll
- Focus states with gold highlight
- Print styles hide fixed navigation elements

### Files Changed
- `ptp-training-platform.php` - Version bump and CSS enqueue update
- `assets/css/ptp-mobile-fixes-v127.css` - New comprehensive mobile fixes
