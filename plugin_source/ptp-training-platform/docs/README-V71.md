# PTP Training Platform v71

A complete WordPress plugin for the Players Teaching Players soccer training marketplace.

## 📋 Feature Checklist

### ✅ Cart to Checkout Flow Fix
- [x] AJAX-powered cart management (`class-ptp-cart-checkout-v71.php`)
- [x] Add/remove/update cart items via AJAX
- [x] Real-time cart total updates
- [x] Step indicator (Cart → Details → Payment)
- [x] Mobile-optimized checkout flow
- [x] Processing fee calculation
- [x] Player data collection during checkout
- [x] WooCommerce integration

### ✅ SMS Updates for Trainer Onboarding
- [x] Twilio/Salesmsg integration (`class-ptp-sms-v71.php`)
- [x] Welcome SMS on trainer approval
- [x] Onboarding step completion notifications
- [x] Profile completion prompts
- [x] Stripe setup notifications
- [x] First booking celebration SMS
- [x] Booking confirmation SMS (to parent & trainer)
- [x] Session reminder SMS
- [x] New message notification SMS

### ✅ Mobile Stripe Integration
- [x] Stripe Connect for trainer payouts (`class-ptp-stripe-connect-v71.php`)
- [x] Mobile-friendly Stripe Elements
- [x] Payment Intent creation
- [x] Split payments (platform fee + trainer payout)
- [x] Trainer dashboard earnings view
- [x] Payout request functionality
- [x] Webhook handling for payment events
- [x] Express Dashboard links for trainers

### ✅ Google Calendar with Bridge Availability
- [x] OAuth 2.0 connection flow (`class-ptp-google-calendar-v71.php`)
- [x] Calendar selection
- [x] Bi-directional sync
- [x] Busy time blocking
- [x] Auto-sync cron job (hourly)
- [x] Create events for bookings
- [x] Bridge availability support (multiple time blocks per day)

### ✅ Recurring Weekly Schedule
- [x] Availability blocks table (`class-ptp-availability-v71.php`)
- [x] Day-of-week recurring schedules
- [x] Multiple time blocks per day
- [x] Available/unavailable block types
- [x] Copy schedule to other days
- [x] Clear day functionality
- [x] Specific date overrides
- [x] Google Calendar integration (busy blocks)
- [x] Public time slot API for booking

### ✅ SPA-Style Dashboards (no page reloads)
- [x] AJAX tab loading (`class-ptp-spa-dashboard.php`)
- [x] Trainer dashboard tabs: Overview, Schedule, Bookings, Earnings, Messages, Profile, Settings
- [x] Parent dashboard tabs: Overview, Players, Bookings, Messages, Settings
- [x] Real-time stats loading
- [x] Notification polling
- [x] Smooth tab transitions
- [x] Mobile-optimized SPA

### ✅ Complete Messaging System
- [x] Conversations table (`class-ptp-messaging-v71.php`)
- [x] Messages table
- [x] Real-time message polling (3s interval)
- [x] Unread count tracking (trainer & parent)
- [x] Conversation list with previews
- [x] Send/receive messages via AJAX
- [x] Mark as read functionality
- [x] Guest message flow (creates account)
- [x] SMS notification for new messages
- [x] Embedded in dashboard or standalone page

### ✅ Full Mobile Optimization
- [x] Mobile-first CSS (`ptp-mobile-global.css`, `ptp-mobile-fixes.css`)
- [x] Header size fix (70px max on mobile)
- [x] Sticky step indicator
- [x] Touch-friendly buttons (44px min)
- [x] Full-width form inputs
- [x] iOS zoom prevention (16px fonts)
- [x] Safe area padding for iPhone X+
- [x] Horizontal scroll for filter chips
- [x] Fixed bundle banner at bottom
- [x] Mobile trainer cards
- [x] Responsive checkout form

## 🗂️ File Structure

```
ptp-training-platform/
├── ptp-training-platform.php          # Main plugin file
├── README.md                          # This file
├── INSTALL.md                         # Installation guide
│
├── assets/
│   ├── css/
│   │   ├── ptp-mobile-global.css      # Global mobile styles
│   │   ├── ptp-mobile-fixes.css       # Mobile fix overrides
│   │   ├── ptp-mobile.css             # Additional mobile styles
│   │   ├── spa-dashboard.css          # SPA dashboard styles
│   │   ├── ptp-checkout.css           # Checkout page styles
│   │   └── ptp-admin.css              # Admin styles
│   │
│   └── js/
│       ├── spa-dashboard.js           # SPA tab loading
│       ├── messaging.js               # Real-time messaging
│       └── ptp-checkout.js            # Checkout flow
│
├── includes/
│   ├── class-ptp-availability-v71.php     # Schedule management
│   ├── class-ptp-sms-v71.php              # SMS (Twilio/Salesmsg)
│   ├── class-ptp-messaging-v71.php        # Messaging system
│   ├── class-ptp-google-calendar-v71.php  # Google Calendar
│   ├── class-ptp-stripe-connect-v71.php   # Stripe Connect
│   ├── class-ptp-cart-checkout-v71.php    # Cart & checkout
│   ├── class-ptp-ajax-v71.php             # AJAX handlers
│   ├── class-ptp-spa-dashboard.php        # SPA dashboard
│   ├── class-ptp-order-integration-v71.php
│   ├── class-ptp-checkout-fields-v71.php
│   ├── class-ptp-woocommerce-orders-v71.php
│   └── class-ptp-admin-settings-v71.php   # Admin settings
│
└── templates/
    ├── find-trainer-v71.php
    ├── trainer-profile-v71.php
    ├── trainer-dashboard-v71.php
    ├── trainer-dashboard-spa.php
    ├── parent-dashboard-v71.php
    ├── messaging-v71.php
    ├── cart-v71.php
    ├── checkout-v71.php
    ├── spa-dashboard.php
    └── components/
        ├── schedule-calendar.php
        ├── trainer-earnings.php
        └── trainer-contact-form.php
```

## 🚀 Installation

1. Upload the `ptp-training-platform` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to PTP Training > Settings to configure:
   - Stripe API keys and Connect
   - Google Calendar API
   - Twilio SMS credentials
4. Plugin automatically creates required pages:
   - Find a Trainer
   - Trainer Dashboard
   - Parent Dashboard
   - Messages
   - Cart & Checkout

## ⚙️ Configuration

### Stripe Settings
- Publishable Key: `pk_live_xxx` or `pk_test_xxx`
- Secret Key: `sk_live_xxx` or `sk_test_xxx`
- Connect Client ID: For trainer onboarding
- Webhook Secret: For payment event handling
- Platform Fee: Default 15%
- Processing Fee: Default 3.2% (added to customer)

### Google Calendar
- Client ID from Google Cloud Console
- Client Secret
- Redirect URI: `{admin_url}/admin-ajax.php?action=ptp_google_oauth_callback`

### SMS (Twilio)
- Account SID: `AC...`
- Auth Token
- From Number: `+1234567890` (E.164 format)

## 📱 Mobile-First Design

The plugin uses PTP's brand standards:
- **Fonts**: Oswald (headings), Inter (body)
- **Colors**: Gold (#FCB900), Black (#0A0A0A)
- **Style**: Sharp edges, 2px borders, uppercase labels
- **Touch**: 44px minimum touch targets

## 🔗 Shortcodes

- `[ptp_find_trainer]` - Trainer search/listing
- `[ptp_trainer_dashboard]` - Trainer SPA dashboard
- `[ptp_parent_dashboard]` - Parent SPA dashboard
- `[ptp_messages]` - Messaging interface
- `[ptp_cart]` - Shopping cart
- `[ptp_checkout]` - Checkout form
- `[ptp_trainer_signup]` - Trainer registration

## 🗄️ Database Tables

The plugin creates these tables on activation:
- `wp_ptp_trainers` - Trainer profiles
- `wp_ptp_parents` - Parent accounts
- `wp_ptp_players` - Player profiles
- `wp_ptp_bookings` - Training sessions
- `wp_ptp_availability` - Basic availability
- `wp_ptp_availability_blocks` - Bridge scheduling
- `wp_ptp_conversations` - Message threads
- `wp_ptp_messages` - Individual messages
- `wp_ptp_calendar_connections` - Google Calendar OAuth
- `wp_ptp_payouts` - Trainer earnings
- `wp_ptp_sms_log` - SMS delivery log

## 🔒 Security

- Nonce verification on all AJAX calls
- Capability checks for admin functions
- Sanitization of all user inputs
- Prepared statements for all database queries
- Stripe webhook signature verification

## 📞 Support

For issues or feature requests, contact PTP Soccer Camps.

---

**Version**: 71.0.0  
**Requires WordPress**: 5.8+  
**Requires PHP**: 7.4+  
**Requires WooCommerce**: 5.0+
