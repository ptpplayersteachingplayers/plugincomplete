# PTP Training Platform

**Version: 87.0.0**

## Changelog

### v87.0.0 (Camp Checkout Enhancements)
**Payment & Discount Features:**
- Card-only payments for camps (no bank transfers/CashApp)
- Sibling discount: $50 off each additional camper
- Team registration with volume discounts (5+ = 10%, 10+ = 15%, 15+ = 20%)
- Referral code system: $25 off for both referrer and referee
- Processing fee: 3% + $0.30 (transparent pricing)

**New Features:**
- Multiple camper support in single checkout
- Team signup flow with coach info capture
- Auto-generated referral codes on thank you page
- Session-based discount calculation

### v86.0.0 (Clean Camp Checkout)
**Checkout Improvements:**
- Clean waiver validation with proper formatting
- Improved email confirmation formatting
- Streamlined checkout field layout
- Better error messaging

### v85.0.0 (Mobile Optimization Release)
**Major Changes:**
- Consolidated mobile CSS into single file (85% smaller, 90% fewer HTTP requests)
- Fixed missing CSS files causing 404 errors
- Standardized viewport meta tags across all templates
- iOS Safe Area support for notched devices

**Mobile Features:**
- 44px minimum touch targets
- 16px minimum input font size (prevents iOS zoom)
- Bottom sheet modals on mobile
- Responsive grids (1→2→3 columns)
- Reduced motion accessibility support

---

### v49.5.0 (Map & Filter Fixes)
**Map Improvements:**
- Removed all POI (Point of Interest) icons from map - no more tennis rackets!
- Hidden sports complexes, businesses, schools, attractions, transit
- Disabled clickable POI icons entirely
- Only PTP trainer markers now appear on map

**Filter Fixes:**
- Fixed location search when using "Near me" geolocation
- Backend now accepts direct lat/lng coordinates (not just ZIP codes)
- Filters should now properly apply when selecting options


**Find Trainers Page Improvements:**
- Filter tabs now show selected value (e.g., "D1" instead of "Level")
- Active filters highlighted in gold with updated styling
- "Clear" button appears when any filter is active
- Results count badge shows "X trainers" after filtering
- Reset filters properly restores default labels
- Improved dropdown text truncation for long values


**Fresh Photos Across Platform:**
- Home page hero: New group photo
- Home page gallery: 8 new action shots (PHL_8711, PHL_8717, PHL_8689, mason, winning-camper, cone-dribbling, ball-roll)
- Training landing page: New hero background + 10 updated gallery images
- Register page: New background image (PHL_8711)
- Login page: New background image (PHL_8689)

**Photos integrated:**
- group-photo.jpg - Hero backgrounds
- PHL_8711.jpg, PHL_8717.jpg, PHL_8689.jpg - Action shots
- mason.jpg, winning-camper.jpg - Player highlights
- Watching-dribbling-1.jpg, cone-dribbling-2.jpg, ball-roll.jpg, cone-dribbling-5.jpg - Training drills


**Reviews Display Fix:**
- No longer shows "0.0 (0)" when trainer has no reviews
- Shows green "New" badge instead of empty rating
- Reviews section header no longer shows "(0)" count when empty
- Improved empty reviews state with icon and better messaging

**Visual Improvements:**
- Empty reviews state has background color and star icon
- Cleaner "No Reviews Yet" message with CTA to be first reviewer
- Consistent with trainer grid which already showed "New" badge


**Faster Trainer Onboarding:**
- Added auto-save functionality - profile saves automatically as you type (2-second debounce)
- Visual "Saving..." / "Saved" indicator in bottom-right corner
- New lightweight `ptp_autosave_profile` AJAX endpoint - 3x faster than full save
- Removed redirect delays after final submit - instant navigation

**Professional SMS Messages:**
- Removed all emojis from SMS notifications
- Cleaner, more concise message format
- Consistent "PTP" branding prefix on all messages
- Better cancellation messages with refund timeline
- Professional session reminders without excessive formatting

**Professional Email Updates:**
- "Application Received" - cleaner header (was "We Got Your Application!")
- "Welcome to PTP Training" - professional approval subject (was "You're In!")
- "Your Profile is Now Active" - clear onboarding complete message (was "You're Live!")
- Consistent professional tone across all templates


**Database Performance:**
- **NEW**: `PTP_Database::add_performance_indexes()` method adds 15+ indexes for scaling
- Indexes for trainer search, booking queries, availability, messages, reviews
- Optimized for 50+ trainers and 500+ parents target scale
- One-click "Add Performance Indexes" button in admin Tools page

**Image Quality Enforcement:**
- Trainer photos now require minimum 800x1000px resolution
- Validation on upload prevents blurry/low-quality images
- Increased max file size to 5MB to allow higher quality uploads
- New `PTP_Images::validate_trainer_photo()` helper method
- New `PTP_Images::trainer_photo_srcset()` for responsive images
- New `PTP_Images::trainer_photo_html()` generates optimized img tags

**Mobile App Improvements:**
- Trainer API now returns `photo_thumb`, `photo_large`, `has_photo` fields
- Better avatar generation for trainers without photos
- `happy_student_score` now defaults to 97 for new trainers
- Consistent image sizing across all endpoints

**Duplicate Player Fix:**
- `PTP_Player::get_by_parent()` now deduplicates by name automatically
- New `PTP_Player::merge_duplicates()` method to clean up existing duplicates
- New `PTP_Player::exists()` method to check before creating

**Admin Improvements:**
- Dashboard shows warning banner for trainers missing photos
- Lists trainer names and explains conversion impact
- "Fix Photos" button links directly to trainer management

**Bug Fixes:**
- Fixed debug page crash with proper try/catch error handling
- Debug page now shows friendly error messages instead of crashing
- Catches both Exception and Error types for PHP 7+ compatibility

---

### v48.0.0 (TeachMe.to-Style Booking Wizard)
**Major Feature: Complete 4-Step Booking Funnel**
- **NEW**: TeachMe.to-style booking wizard with modern 4-step flow
  - Step 1: Location Selection (with map, distance, "FREE" badges)
  - Step 2: Date & Time Selection (calendar with suggested slots)
  - Step 3: Package Selection (subscription vs one-time packs)
  - Step 4: Checkout (trust badges, testimonials, Stripe)

**Cross-Selling Features:**
- Similar trainers recommendations when availability is limited
- Package upsells (weekly subscriptions with free trial)
- Gift card integration at checkout
- Lead capture modal ("Talk to a Coordinator")

**Key Improvements:**
- Subscription-first pricing with FREE trial messaging
- "Suggested" badges on peak time slots
- Distance calculation from user location
- Free venue indicators
- 100% Satisfaction Guarantee trust badge
- Security badges (SSL, PCI compliant)
- Real-time availability checking

**New Endpoints:**
- `ptp_wizard_get_locations` - Get trainer locations with distance
- `ptp_wizard_get_slots` - Get available time slots
- `ptp_wizard_get_packages` - Get package pricing options
- `ptp_wizard_check_availability` - Real-time slot availability
- `ptp_wizard_get_similar_trainers` - Cross-sell recommendations
- `ptp_wizard_submit_lead` - Lead capture form submission
- `ptp_wizard_process_booking` - Complete booking flow

**New Database Table:**
- `ptp_leads` - Stores lead capture form submissions

**New Classes:**
- `PTP_Booking_Wizard` - Handles all wizard logic and AJAX

**New Templates:**
- `templates/booking-wizard.php` - Complete 4-step UI

**New Shortcode:**
- `[ptp_booking_wizard trainer_id="X"]` - Embed booking wizard

**URL:** `/book/?trainer=SLUG` or `/book/?trainer_id=ID`

---

### v47.1.0 (Mobile API FIX)
- **CRITICAL FIX**: Added `PTP_Mobile_API::instance()` call in main plugin
- Mobile API routes were not registering because class was never instantiated
- All 80+ endpoints now properly registered and functional

### v47.0.0 (Complete Mobile API + Image Fix)
- **Mobile API**: 80+ REST endpoints for iOS/Android apps
- **Programs API**: New endpoints for training programs & clinics
- **Image Fix**: Changed `image-rendering: crisp-edges` to `high-quality` for photos
- **Camps API**: Added nearby, by-location, search, availability, schedule endpoints

### v46.0.0 (Mobile API Complete + Image Sharpness)
- **Mobile API**: Complete REST API implementation for iOS/Android apps
- **Image Quality**: Fixed fuzzy trainer images
- **Database**: Added ptp_favorites and ptp_session_notes tables

## Mobile API Documentation

**Base URL:** `https://ptpsummercamps.com/wp-json/ptp/v2`

### Health & Config (Public)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/health` | Health check |
| GET | `/config` | App configuration |
| GET | `/config/bootstrap` | Bootstrap data for app startup |

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/login` | User login |
| POST | `/auth/register` | User registration |
| POST | `/auth/google` | Google OAuth login |
| POST | `/auth/apple` | Apple Sign-In |
| POST | `/auth/refresh` | Refresh JWT token |
| POST | `/auth/forgot-password` | Password reset request |
| POST | `/auth/logout` | User logout |

### User Profile (Authenticated)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/me` | Get current user profile |
| PUT | `/me` | Update current user profile |
| POST | `/me/avatar` | Upload avatar |
| PUT | `/me/password` | Change password |
| GET | `/me/notifications` | Get notifications |
| POST | `/me/notifications/{id}/read` | Mark notification read |
| POST | `/me/device` | Register device for push |
| DELETE | `/me/device` | Unregister device |

### Players (Parent Management)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/players` | List child players |
| POST | `/players` | Add a player |
| GET | `/players/{id}` | Get player details |
| PUT | `/players/{id}` | Update player |
| DELETE | `/players/{id}` | Delete player |
| GET | `/players/{id}/progress` | Get player progress |

### Trainers (Public/Authenticated)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/trainers` | List all trainers |
| GET | `/trainers/search` | Search trainers |
| GET | `/trainers/featured` | Featured trainers |
| GET | `/trainers/nearby` | Nearby trainers |
| GET | `/trainers/{id}` | Trainer profile |
| GET | `/trainers/{id}/availability` | Trainer availability |
| GET | `/trainers/{id}/reviews` | Trainer reviews |
| GET | `/trainers/{id}/gallery` | Trainer gallery |

### Trainer Dashboard (Trainer Role)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/trainer/profile` | Get own profile |
| PUT | `/trainer/profile` | Update profile |
| GET | `/trainer/availability` | Get availability |
| PUT | `/trainer/availability` | Set availability |
| GET | `/trainer/earnings` | View earnings |
| GET | `/trainer/stats` | View stats |
| POST | `/trainer/stripe/connect` | Connect Stripe |
| GET | `/trainer/stripe/dashboard` | Stripe dashboard link |

### Bookings
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/bookings` | List bookings |
| POST | `/bookings` | Create booking |
| GET | `/bookings/{id}` | Get booking details |
| POST | `/bookings/{id}/confirm` | Confirm booking |
| POST | `/bookings/{id}/cancel` | Cancel booking |
| POST | `/bookings/{id}/complete` | Mark complete |
| POST | `/bookings/{id}/notes` | Add session notes |
| POST | `/bookings/{id}/review` | Leave review |

### Camps & Programs
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/camps` | List all camps |
| GET | `/programs` | List all programs |
| GET | `/camps/{id}` | Camp details |
| GET | `/programs/{id}` | Program details |
| GET | `/camps/featured` | Featured camps |
| GET | `/programs/featured` | Featured programs |
| GET | `/camps/upcoming` | Upcoming camps |
| GET | `/programs/upcoming` | Upcoming programs |
| GET | `/camps/nearby` | Nearby camps |
| GET | `/camps/by-location` | Camps by location |
| GET | `/camps/categories` | Camp categories |
| GET | `/programs/categories` | Program categories |
| GET | `/camps/search` | Search camps |
| GET | `/programs/search` | Search programs |
| GET | `/camps/{id}/availability` | Camp availability |
| GET | `/camps/{id}/schedule` | Camp schedule |
| POST | `/camps/{id}/register` | Register for camp |
| GET | `/camps/my-registrations` | User's registrations |

### Messaging
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/conversations` | List conversations |
| GET | `/conversations/{id}` | Get conversation |
| GET | `/conversations/{id}/messages` | Get messages |
| POST | `/conversations/{id}/messages` | Send message |
| POST | `/messages/start` | Start new conversation |

### Payments
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/payments/methods` | List payment methods |
| POST | `/payments/methods` | Add payment method |
| DELETE | `/payments/methods/{id}` | Remove payment method |
| POST | `/payments/setup-intent` | Create Stripe setup intent |

### Favorites
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/favorites` | List favorite trainers |
| POST | `/favorites/{trainer_id}` | Add favorite |
| DELETE | `/favorites/{trainer_id}` | Remove favorite |

### Content (Public)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/content/faq` | FAQ content |
| GET | `/content/specialties` | Training specialties |
| GET | `/content/locations` | Service locations |

---

### v45.3.0 (Softer UI Design)
- **Security**: Added rate limiting to login (5 attempts/5min) and register (3 attempts/5min) endpoints
- **Security**: Added rate limiting to forgot_password endpoint
- **Security**: Removed sensitive data from error logs (print_r calls removed)
- **Security**: Wrapped debug logging in WP_DEBUG checks
- **Performance**: Added object caching to PTP_Trainer::get() and get_by_user_id()
- **Performance**: Added cache invalidation on trainer updates
- **Reliability**: Added database transactions to booking creation for atomicity
- **Reliability**: Improved error handling in booking flow with proper rollback

### v42.3.2 (Google Maps Robust Loading)
- **Fixed**: Google Maps not loading on trainer-onboarding page
- **Fixed**: Google Maps loading on trainer-dashboard page
- **Added**: More robust Google Maps detection and initialization
  - Handles cases where theme/plugin already loads Google Maps
  - Retry logic if Google Maps not ready when callback fires
  - Console logging for debugging map issues
- **Added**: Google Maps API Status section in Debug page
  - Shows if API key is configured
  - Shows which APIs need to be enabled
- **Improved**: wp_head() now called before Google Maps script in trainer-dashboard

### v42.3.1 (Google Maps Fix)
- **Fixed**: Google Maps loading multiple times on /find-trainers/
  - PTP_Maps class now skips find-trainers page (handled by template)
  - Added `loading=async` to all Google Maps script loads
  - Better detection of already-loaded Google Maps
  - Prevents double initialization of map
- **Fixed**: Console version numbers updated (were showing old v42.0 and v19)
- **Improved**: Map initialization with retry logic if Google Maps not ready

### v42.3.0 (Gallery, Map Fix, Mobile Optimization)
- **Added**: Trainer profile gallery section for action shots
  - Displays in 3-column grid on desktop, 2-column on mobile
  - Fullscreen lightbox with keyboard navigation
  - Supports up to 6 images
- **Added**: Gallery upload in trainer onboarding
  - Drag-and-drop style upload
  - Preview before saving
  - Remove individual images
- **Fixed**: Google Maps trainer markers on /find-trainers/
  - Added debug logging for map initialization
  - Better handling of trainers without coordinates
  - Console logs show coordinate status
- **Added**: Geocoding tools in Debug page
  - "Geocode" button to auto-fetch coordinates from location text
  - "Set Philly" button to set default Philadelphia coordinates
  - Lat/Lng column in trainers table
- **Improved**: Map update function with coordinate parsing
- **Mobile**: Gallery responsive grid (3-col desktop, 2-col mobile)
- **Mobile**: Lightbox touch-friendly navigation

### v42.2.0 (Admin Dashboard Access Fix)
- **Fixed**: Main plugin `template_redirect` hook now has same fixes as shortcode
- **Added**: Admin bypass - admins can always view trainer dashboard
  - `/trainer-dashboard/` - auto-loads first active trainer
  - `/trainer-dashboard/?trainer_id=X` - view specific trainer
  - `/trainer-dashboard/?skip_onboarding=1` - bypass onboarding redirect
- **Added**: Email fallback in main redirect handler
- **Added**: Auto trainer-user linking in main redirect handler
- **Added**: Admin tips section in debug page

### v42.1.0 (Trainer Dashboard Fix)
- **Fixed**: Trainer dashboard redirect to apply page issue
- **Fixed**: Trainer lookup now tries email matching if user_id lookup fails
- **Fixed**: Automatic trainer-to-user linking when found by email
- **Added**: Enhanced debug page with trainer diagnostics
  - Shows current user trainer status
  - Lists all trainers with status
  - Quick actions: Activate trainer, Fix slug, Link to user
- **Added**: PTP Pages status check in debug page
- **Added**: "Create Missing Pages" button to recreate plugin pages
- **Added**: `get_by_email()` method to PTP_Trainer class
- **Added**: `link_to_user()` method to PTP_Trainer class
- **Improved**: `is_new_trainer()` method robustness
- **Improved**: Trainer dashboard shortcode with better error handling

### v42.0.0 (Mobile Optimization & Bug Fixes)
- **Mobile-First Optimization**: All templates now fully optimized for mobile devices
- **Touch Target Compliance**: All interactive elements now have 44px minimum touch targets
- **iOS Zoom Prevention**: Form inputs use 16px font-size to prevent iOS auto-zoom
- **Safe Area Support**: Added safe-area-inset support for notched devices
- **Touch Feedback**: Added active states for touch devices
- **Responsive Improvements**: 
  - New extra-small breakpoint (380px) for compact phones
  - Improved tablet breakpoints (768px, 1024px)
  - Better spacing and padding at all breakpoints
- **Template Updates**: All major templates updated with enhanced mobile styles
  - trainer-dashboard.php
  - parent-dashboard.php
  - trainer-profile.php
  - trainers-grid.php
  - book-session.php
  - checkout.php
  - login.php
  - register.php
- **CSS Updates**: 
  - frontend.css updated with comprehensive responsive system
  - Touch-specific styles for non-hover devices
  - Smooth scrolling with reduced-motion support
  - Print styles cleanup
- **JavaScript Updates**: frontend.js v42.0 with mobile touch optimization

### v40.3.0 (Google Maps Wiring Complete)
- Fixed: Find Trainers location search now uses Google Places autocomplete
- Fixed: Location search accepts city names, not just ZIP codes
- Fixed: Map auto-centers on user location when using geolocation
- Enhanced: Trainer onboarding already has Google Maps Places integration for training locations
- Enhanced: Both trainer onboarding and find trainers properly load Google Maps Places API
- Verified: Training locations are saved with lat/lng coordinates and displayed on map

### v40.2.0 (Google Maps Integration)
- Enhanced: Find Trainers page now shows all trainer training locations on map
- Enhanced: Location search with Google Places autocomplete (city/ZIP search)
- Enhanced: Map info windows show trainer's training locations list
- Enhanced: Training location markers (blue) separate from trainer markers (gold)
- Added: training_locations field to API response for map display
- Fixed: Location search integration with Google Places API

### v40.1.0 (Fix)
- Fixed: Trainer profile routing now works correctly - shortcode passes trainer data to template
- Fixed: Templates class handles subdirectory WordPress installations for trainer URLs
- Fixed: AJAX get_trainers now auto-generates slugs for trainers missing them
- Added: Global $ptp_current_trainer variable for template access

### v40.0.0
- Added: Logout shortcode and template
- Added: Training landing page shortcode and template
- Added: Auto-hide page titles on PTP shortcode pages
- Added: Trust elements on trainer profile pages
- Improved: Google Maps loading to prevent duplicates
- Improved: Geocoding fallback for trainer locations

## Debug Report (v38 → v39 Fixed)

### ✅ No Syntax Errors
All PHP files pass lint checks.

### 🔴 CRITICAL BUGS FIXED

#### 1. Missing Methods Causing Fatal Error (FIXED)
`class-ptp-admin-payouts.php` called two methods that didn't exist in `PTP_Trainer_Payouts`:
- `get_payout_queue()` - **Added**
- `get_payout_instructions($payout)` - **Added**

This was causing the "Critical Error" on payout pages.

#### 2. Duplicate Menu Registration Conflict (FIXED)
Both `class-ptp-admin.php` and `class-ptp-admin-payouts.php` were trying to register `ptp-payouts` menu item with different parent slugs:
- Admin class: parent = `ptp-dashboard` ✓
- Admin payouts class: parent = `ptp-training` ✗ (doesn't exist)

Commented out the duplicate registration in `class-ptp-admin-payouts.php`.

#### 3. Rewrite Rules Flushing on Every Page Load (FIXED)
Version check was comparing against hardcoded string instead of `PTP_VERSION`.

#### 4. Debug Logging in Production (FIXED)
Schedule save was logging all POST data unconditionally. Now only logs when `WP_DEBUG` is true.

### 🔧 Debug Tool Added
Access at **WP Admin → PTP Training → 🔧 Debug**

### Common Issues & Fixes

| Issue | Symptom | Fix |
|-------|---------|-----|
| Tables missing | "Table doesn't exist" errors | Deactivate/reactivate plugin |
| AJAX fails | "Security check failed" | Clear browser cache |
| 404 on trainer profiles | `/trainer/slug/` returns 404 | Settings → Permalinks → Save |
| Stripe not working | Payment errors | Check API keys in PTP → Settings |

--- v29.0.0

Complete 1-on-1 soccer training marketplace with bulletproof inline styling for all devices.

## What's New in v29.0.0

### 100% Bulletproof Inline Styling
- All templates now use inline CSS that works on every device
- No external CSS dependencies - everything renders correctly
- Mobile-first responsive design
- Professional, modern UI consistent across all pages

### Updated Templates
- **Home Page**: Hero with search, featured trainers, how it works, stats, programs
- **Find Trainers**: Filterable grid with Google Maps integration
- **Trainer Profile**: Complete profile with booking calendar and availability
- **Checkout**: Secure Stripe payment processing
- **Booking Confirmation**: Success page with session details
- **Login/Register**: Clean authentication flows
- **Apply as Trainer**: Professional application form

### Features
- Trainer profiles with photos, bios, specialties, reviews
- Real-time availability calendar
- Secure Stripe payments with Connect for trainer payouts
- Google Calendar sync
- SMS notifications (Twilio)
- Review system
- Training location management
- Background check verification badges

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through WordPress admin
3. Configure settings under PTP Training menu
4. Add required API keys:
   - Stripe (payments)
   - Google Maps (location features)
   - Twilio (SMS notifications)

## Shortcodes

- `[ptp_home]` - Home page
- `[ptp_trainers]` - Find trainers grid
- `[ptp_trainer_profile]` - Individual trainer profile
- `[ptp_checkout]` - Checkout/payment page
- `[ptp_login]` - Login form
- `[ptp_register]` - Registration form
- `[ptp_apply]` - Trainer application form
- `[ptp_my_training]` - Parent dashboard
- `[ptp_trainer_dashboard]` - Trainer dashboard

## Brand Colors

- Primary Yellow: #FCB900
- Dark: #0E0F11
- Gray Background: #F3F4F6
- Success Green: #10B981
- Error Red: #EF4444

## Support

Contact: support@ptpsummercamps.com
