# PTP Complete Flow Guide

## Overview

This document walks through the complete user journey for both trainers and parents/customers in the PTP Training Platform.

---

## 🏃 TRAINER FLOW

### 1. Application (`/apply/`)

**Template:** `templates/apply.php`

A prospective trainer submits their application with:
- Name, Email, Phone
- Password (stored hashed for later use)
- College/University
- Team/Club experience
- Playing level (MLS, NCAA D1, etc.)
- Specialties (Ball Mastery, Finishing, etc.)
- Short bio
- Hourly rate preference

**What happens:**
1. Data saved to `wp_ptp_applications` table with `status = 'pending'`
2. User account created immediately with `ptp_trainer` role
3. Password hash stored for use upon approval
4. Admin receives notification of new application

**Database:** `wp_ptp_applications`

---

### 2. Admin Approval (WP Admin → PTP Training → Applications)

**Class:** `admin/class-ptp-admin.php` → `approve_application()`

When admin clicks "Approve":

1. **User Account:** Verified/created with stored password
2. **Trainer Record:** Created in `wp_ptp_trainers` with `status = 'active'`
3. **Unique Slug:** Generated (e.g., `john-smith-123`)
4. **Role Added:** `ptp_trainer` role assigned

**Emails Sent:**
- ✉️ **Welcome Email** (`PTP_Email::send_application_approved`)
  - Login credentials (if legacy application)
  - Link to login page
- ✉️ **Compliance Sequence** (`PTP_Email::schedule_compliance_emails`)
  - SafeSport certification reminder (Day 1)
  - W9 form reminder (Day 3)

**Hook Fired:** `do_action('ptp_trainer_approved', $trainer_id)`

---

### 3. Trainer Onboarding (`/trainer-onboarding/`)

**Template:** `templates/trainer-onboarding.php`

5-step wizard for profile completion:

#### Step 1: Photo & Basic Info
- Profile photo upload (drag & drop, camera capture)
- Display name
- Headline (e.g., "Former MLS Pro | Youth Development Specialist")

#### Step 2: Location & Specialties
- Training locations (Google Places autocomplete)
- Specialties selection (multi-select)
- Travel radius

#### Step 3: Rate & Availability
- Hourly rate slider ($25-$200)
- Weekly availability (day/time slots)

#### Step 4: Payment Setup
- Stripe Connect onboarding
- Bank account connection for payouts

#### Step 5: Review & Submit
- Profile preview
- Final submission

**What happens on completion:**
1. Trainer status updated to `active`
2. `ptp_onboarding_completed` meta set
3. Profile goes live on `/find-a-trainer/`
4. ✉️ **Onboarding Complete Email** sent (`PTP_Email::send_onboarding_complete`)

---

### 4. Trainer Dashboard (`/trainer-dashboard/`)

**Template:** `templates/trainer-dashboard-v73.php`

Dashboard tabs:

#### Overview Tab
- Profile completeness score
- This month's earnings
- Pending payout amount
- Upcoming sessions list
- Quick actions (Edit Profile, View Public Profile)

#### Schedule Tab
- Weekly availability grid
- Enable/disable days
- Set start/end times per day

#### Locations Tab
- Manage training locations
- Google Places integration
- Add/remove locations

#### Earnings Tab
- Monthly earnings breakdown
- Session history
- Payout history
- Stripe Connect status

#### Messages Tab
- Conversations with parents
- Unread message count
- Real-time messaging

---

### 5. Trainer Profile (Public) (`/trainer/[slug]/`)

**Template:** `templates/trainer-profile.php`

What parents see:
- Photo, name, headline
- Bio/about section
- Specialties badges
- Training locations with map
- Availability calendar (green checkmarks on available days)
- Hourly rate
- Reviews/ratings
- "Book a Session" CTA

---

## 👨‍👩‍👧 PARENT/CUSTOMER FLOW

### 1. Browse Trainers (`/find-a-trainer/`)

**Template:** `templates/find-trainer-v71.php`

- Grid of trainer cards
- Filter by location, specialty, availability
- Search functionality
- Map view option

---

### 2. View Trainer Profile → Book Session

From trainer profile, parent can:
1. View availability calendar
2. Select training package (Single, 5-pack, 10-pack)
3. Choose date/time
4. Add to cart

**What happens:**
1. Training session saved to `wp_ptp_bundles` table
2. Session cookie set (`ptp_session`)
3. Redirected to cart or checkout

---

### 3. Add Camp/Clinic to Cart (`/ptp-shop-page/`)

WooCommerce products for camps/clinics:
1. Select camp product
2. Add to WooCommerce cart
3. Can combine with training (bundle discount!)

---

### 4. Cart (`/ptp-cart/`)

**Template:** `templates/ptp-cart.php`

Shows:
- Camp/clinic items (from WooCommerce)
- Training sessions (from `ptp_bundles`)
- Bundle discount (15% if both camp + training)
- Processing fee
- Total

**Upsell CTA:** If only camps in cart, shows "Add Private Training - Save 15%!"

---

### 5. Checkout (`/ptp-checkout/`)

**Template:** `templates/ptp-checkout.php`

#### Customer Information
- Email, Name, Phone
- Account creation (if not logged in)

#### Camper/Player Information
- First/Last name, Age, Skill level
- Support for multiple campers

#### Emergency Contact
- Name, Phone, Relationship

#### Medical Information
- Allergies, conditions, notes

#### Agreements
- Liability waiver
- Photo/video release
- Electronic signature

#### Payment
- Stripe Elements (card input)
- Apple Pay / Google Pay

---

### 6. Order Processing

**Class:** `includes/class-ptp-unified-checkout-handler.php`

When "Pay Now" clicked:

1. **Validation:** All fields checked
2. **User Account:** Created if guest
3. **Stripe Payment:** Payment intent created/confirmed
4. **WooCommerce Order:** Created with all items
5. **Order Meta Saved:**
   - `_ptp_campers` (array of camper info)
   - `_ptp_emergency_name`, `_ptp_emergency_phone`, `_ptp_emergency_relationship`
   - `_ptp_medical_info`
   - `_ptp_waiver_signed`, `_ptp_waiver_signature`, `_ptp_waiver_date`
6. **Training Booking:** If training included, booking created in `wp_ptp_bookings`
7. **Bundle Status:** Updated to `paid` in `wp_ptp_bundles`

---

### 7. Order Confirmation

**Displayed on:**
- Thank You page (`woocommerce_thankyou` hook)
- Order emails (`woocommerce_email_after_order_table` hook)
- My Account → Order View (`woocommerce_order_details_after_order_table` hook)
- Admin Order Page (meta box)

**Shows:**
- ⚽ Registered players (name, age, skill level)
- 📅 Event schedule (camp dates, times, locations)
- 🚨 Emergency contact
- ⚕️ Medical information
- ✅ Waiver status

---

## 📬 NOTIFICATION SYSTEM

### Database
**Table:** `wp_ptp_notifications`
- `user_id`, `type`, `title`, `message`, `data` (JSON)
- `is_read`, `read_at`, `created_at`

### Notification Types

| Type | Trigger | Recipients |
|------|---------|------------|
| `new_booking` | Booking created | Trainer |
| `booking_confirmed` | Booking created | Parent |
| `booking_cancelled` | Booking cancelled | Other party |
| `session_reminder` | 24h before session | Both |
| `new_message` | Message sent | Recipient |

### Email Notifications

**Class:** `includes/class-ptp-email.php`

| Email | Trigger | Template |
|-------|---------|----------|
| Application Approved | Admin approves | `application-approved` |
| Application Rejected | Admin rejects | `application-rejected` |
| Onboarding Complete | Profile finished | `onboarding-complete` |
| Booking Confirmation | Payment success | `booking-confirmation` |
| New Booking (Trainer) | Booking created | `trainer-new-booking` |
| Session Reminder | Cron (24h before) | `session-reminder` |
| Welcome Parent | Account created | `welcome-parent` |

### SMS Notifications (Twilio)

**Class:** `includes/class-ptp-sms.php`

| SMS | Trigger |
|-----|---------|
| Order Confirmation | Payment success |
| Session Reminder | 24h before |
| Booking Cancelled | Cancellation |

**Configuration:** WP Admin → PTP Training → Settings
- Twilio Account SID
- Twilio Auth Token
- From Phone Number

---

## 💰 PAYMENT FLOW

### Stripe Connect (Trainer Payouts)

**Class:** `includes/class-ptp-stripe-connect-v71.php`

1. **Onboarding:** Trainer connects Stripe account
2. **Booking Payment:** Full amount charged to parent
3. **Platform Fee:** 20% retained by PTP
4. **Trainer Payout:** 80% transferred to trainer's Stripe

### Bundle Discount Logic

**Class:** `includes/class-ptp-cart-helper.php`

```
IF has_camps AND has_training:
    bundle_discount = subtotal × 15%
    
processing_fee = (subtotal - bundle_discount) × 3%
total = subtotal - bundle_discount + processing_fee
```

---

## 🗄️ KEY DATABASE TABLES

| Table | Purpose |
|-------|---------|
| `wp_ptp_trainers` | Trainer profiles |
| `wp_ptp_applications` | Trainer applications |
| `wp_ptp_parents` | Parent profiles |
| `wp_ptp_players` | Player/camper profiles |
| `wp_ptp_bookings` | Training session bookings |
| `wp_ptp_bundles` | Cart bundles (camp + training) |
| `wp_ptp_availability` | Trainer weekly availability |
| `wp_ptp_notifications` | In-app notifications |
| `wp_ptp_messages` | Messaging system |
| `wp_ptp_conversations` | Message threads |
| `wp_ptp_reviews` | Trainer reviews |

---

## 🔧 KEY HOOKS & ACTIONS

### Trainer Lifecycle
- `ptp_trainer_approved` - Trainer approved by admin
- `ptp_trainer_onboarding_complete` - Onboarding finished

### Booking Lifecycle
- `ptp_booking_created` - New booking
- `ptp_booking_confirmed` - Payment confirmed
- `ptp_booking_cancelled` - Booking cancelled
- `ptp_booking_completed` - Session completed

### Order Lifecycle
- `ptp_order_created` - WooCommerce order created
- `ptp_bundle_paid` - Bundle payment confirmed

---

## 📱 MOBILE CONSIDERATIONS

All templates are mobile-first with:
- Touch-friendly inputs (44px minimum tap targets)
- Responsive grids
- Mobile bottom bar for checkout
- Camera capture for photo upload
- Swipe gestures where applicable

---

## 🔗 URL STRUCTURE

| URL | Purpose |
|-----|---------|
| `/apply/` | Trainer application |
| `/login/` | User login |
| `/trainer-onboarding/` | Trainer profile setup |
| `/trainer-dashboard/` | Trainer portal |
| `/trainer/[slug]/` | Public trainer profile |
| `/find-a-trainer/` | Trainer directory |
| `/ptp-shop-page/` | Camp/clinic shop |
| `/ptp-cart/` | Shopping cart |
| `/ptp-checkout/` | Checkout page |
| `/my-training/` | Parent dashboard |
| `/messages/` | Messaging center |
