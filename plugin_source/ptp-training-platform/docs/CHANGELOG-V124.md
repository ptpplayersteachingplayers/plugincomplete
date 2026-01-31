# PTP Training Platform v124.0.0 Changelog

## Release Date: January 23, 2026

### Trainer Profile Cover Photo (NEW)

#### Editable Cover Photo
- Trainers can now upload a landscape action photo for their profile
- Photo appears behind their profile picture with a dim overlay
- Recommended size: 1200x400px or wider (landscape orientation)
- Supports JPG, PNG, and WebP formats up to 8MB

#### Profile Display
- Cover photo displayed in hero section with gradient overlay
- Darker overlay (50-85%) ensures text remains readable
- Responsive sizing - larger on desktop, optimized for mobile
- Falls back gracefully when no cover photo is set

#### Database Changes
- Added `cover_photo_url` column to `ptp_trainers` table
- Automatic column addition for existing installations

### Find Trainers Page (/find-trainers)

#### Hero Section Flush with Header
- Removed gap between header and hero section
- Aggressive CSS overrides to combat WordPress/theme margins
- Negative margin technique (`margin-top: -1px`) as bulletproof fix
- Works with Astra, Elementor, and block themes

### Training Page (/training)

#### Hero Image Added
- Added background image to hero section
- Gradient overlay for text readability
- Visual consistency with find-trainers page

### Trainer Profile Mobile Optimization

#### Enhanced Mobile Experience
- Optimized touch targets (44px+)
- Bottom sheet booking widget with safe-area support
- Spring animations for smooth interactions
- Pull-to-refresh support
- Larger profile photos on desktop (up to 160px)
- Responsive stats section with proper spacing

### Calendar Integration Improvements

#### Google Calendar
- Full OAuth2 flow for trainers
- Automatic booking sync
- Busy time blocking from calendar
- Manual sync and disconnect options

#### Apple Calendar / iPhone Calendar
- ICS subscription feed for each trainer
- Secure auto-generated ICS tokens
- Works with iPhone, iPad, Mac, Outlook, Android
- Copy-to-clipboard functionality

### Checkout Improvements

#### Desktop Order Summary - BIGGER
- Increased sidebar width at responsive breakpoints
- Larger fonts and padding for better visibility
- Total line now 26px font size

### Admin Dashboard CSS Cleanup
- Complete rewrite with CSS variables
- Cleaner stat cards with hover effects
- Improved table styling
- Status badges with indicator dots
- Modern tab navigation

### Files Changed

1. `ptp-training-platform.php` - Version bump
2. `templates/trainer-profile-v3.php` - Cover photo background + mobile optimization
3. `templates/trainer-dashboard-v117.php` - Cover photo upload UI + JS handler
4. `templates/trainers-grid.php` - Hero flush with header fix
5. `templates/training-landing.php` - Hero background image
6. `templates/ptp-checkout.php` - Desktop order summary sizing
7. `includes/class-ptp-database.php` - cover_photo_url column
8. `includes/class-ptp-ajax.php` - Cover photo upload AJAX handler
9. `includes/class-ptp-google-calendar-v71.php` - ICS URL in status
10. `assets/css/ptp-admin.css` - Complete rewrite
