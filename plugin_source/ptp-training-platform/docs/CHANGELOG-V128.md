# PTP Training Platform v128.0.0

## Release Date: January 2026

## Summary
Cleaned up legacy v1 schedule calendar code, fixed messaging between trainers and parents, documented the post-training payment release flow, and wired up "Continue with Google" OAuth login for the website.

---

## Changes

### 1. Google OAuth Web Login (NEW)

**Added:** `includes/class-ptp-google-web-login.php`

Enables "Continue with Google" / "Sign up with Google" buttons on login and register pages.

**Flow:**
1. User clicks "Continue with Google"
2. Redirected to Google OAuth consent screen
3. User authorizes PTP
4. Google redirects to callback with auth code
5. PTP exchanges code for tokens
6. PTP gets user info (email, name, photo)
7. User is logged in (new account created if needed)

**Setup Required (Admin > PTP Training > Settings > Google):**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create project or select existing
3. Enable Google Calendar API (also used for trainer calendar sync)
4. Create OAuth 2.0 credentials (Web Application)
5. Add these Authorized redirect URIs:
   - `https://yourdomain.com/wp-admin/admin-ajax.php?action=ptp_google_login_callback`
   - `https://yourdomain.com/wp-admin/admin-ajax.php?action=ptp_google_oauth_callback`
6. Copy Client ID and Client Secret to PTP settings

**Files Changed:**
- `includes/class-ptp-google-web-login.php` (NEW)
- `templates/login.php` - Added Google button with proper SVG
- `templates/register.php` - Added Google button with proper SVG
- `includes/class-ptp-admin-settings-v71.php` - Added login callback URI to settings

---

### 2. Schedule Calendar - v1 Removed (v2 Only)

**Removed Files:**
- `includes/class-ptp-schedule-calendar.php` (v1 class)
- `assets/js/schedule.js` (v1 JavaScript)
- `assets/css/schedule.css` (v1 styles)

**Kept:**
- `includes/class-ptp-schedule-calendar-v2.php` (enhanced admin calendar)
- `assets/js/schedule-v2.js` (v2 JavaScript)
- `assets/css/schedule-v2.css` (v2 styles)
- `templates/components/schedule-calendar.php` (trainer dashboard inline component)

**Why v2 Only:**
- v2 includes dashboard stats, recurring sessions, conflict detection, bulk actions, export, keyboard shortcuts
- v1 was already commented out since v126 - now fully removed
- Trainer dashboard uses its own inline schedule component, not the separate schedule calendar class

---

### 2. Messaging System Fix

**Fixed:** Template field name mismatch between query and display

**Problem:** 
- Query returned `other_name` and `other_photo`
- Template expected `parent_name`/`trainer_name` and `parent_photo`/`trainer_photo`

**Solution:**
Updated `templates/messaging.php` to use consistent field names:
```php
// BEFORE (broken)
$other_name = $is_trainer ? ($c->parent_name ?? 'Parent') : ($c->trainer_name ?? 'Trainer');

// AFTER (fixed)
$other_name = $c->other_name ?? 'Contact';
```

**Messaging Features Working:**
- Real-time AJAX messaging with 5-second polling
- Conversation list for both trainers and parents
- Unread message badges
- Message timestamps
- Profile photo avatars with fallback
- Guest message → auto account creation
- SMS/email notifications on new messages

---

### 3. Post-Training Payment Release Flow

**Documentation of existing escrow system:**

#### Flow Steps:

1. **Parent Pays** → Funds captured to PTP platform account (held in escrow)
2. **Session Occurs** → Training session takes place
3. **Trainer Marks Complete** → `ptp_trainer_complete_session` AJAX action
   - Status changes from `holding` to `session_complete`
   - 24-hour auto-release timer starts
4. **Parent Confirmation Window** (24 hours)
   - Option A: Parent clicks "Confirm & Release Payment" → Immediate release
   - Option B: Parent clicks "Report Issue" → Dispute opened
   - Option C: No action → Auto-release after 24 hours
5. **Payment Release** → Funds transfer to trainer's Stripe Connect account

#### Escrow Statuses:
- `holding` - Payment captured, awaiting session
- `session_complete` - Trainer marked complete, awaiting parent confirmation
- `confirmed` - Parent confirmed the session
- `disputed` - Parent reported an issue
- `released` - Funds sent to trainer
- `refunded` - Funds returned to parent

#### Key Classes:
- `PTP_Escrow` - Main escrow logic (`includes/class-ptp-escrow.php`)
- `PTP_Session_Confirmation` - UI components for confirmation (`includes/class-ptp-session-confirmation.php`)
- `PTP_Trainer_Payouts` - Stripe Connect transfers (`includes/class-ptp-trainer-payouts.php`)
- `PTP_Instant_Pay` - Instant payout option (`includes/class-ptp-instant-pay.php`)

#### Platform Fee: 20%
- Example: $100 session → $80 to trainer, $20 to PTP

---

### 4. Trainer Dashboard Navigation

**Existing Tabs (trainer-dashboard-v117.php):**
1. **Home** - Quick stats, upcoming sessions, needs confirmation
2. **Schedule** - Availability management with inline editing
3. **Earnings** - Balance, payout history, Stripe Connect status
4. **Messages** - Links to /messages/ page
5. **Profile** - Photo, bio, locations, specialties, settings

**Navigation Features:**
- Mobile-first with 44px+ touch targets
- Fixed bottom navigation with safe-area support
- Tab persistence via hash
- Pull-to-refresh support
- Toast notifications
- Google Calendar integration
- ICS calendar subscription

---

## Testing Checklist

### Messaging
- [ ] Trainer can view conversations with parents
- [ ] Parent can view conversations with trainers  
- [ ] New message sends successfully
- [ ] Unread badges update correctly
- [ ] Real-time polling fetches new messages
- [ ] Profile photos display (or fallback avatar)
- [ ] Guest message creates account

### Post-Training Flow
- [ ] Trainer sees "Mark Complete" button for past sessions
- [ ] Marking complete changes escrow status to `session_complete`
- [ ] Parent sees "Confirm Your Sessions" prompt
- [ ] Parent confirming releases payment immediately
- [ ] Auto-release works after 24 hours (cron: `ptp_process_escrow_releases`)
- [ ] Dispute form opens and submits

### Schedule (Admin)
- [ ] Admin calendar loads at /wp-admin/admin.php?page=ptp-schedule
- [ ] v2 features work: filters, bulk actions, export

---

## Files Changed

```
Modified:
- ptp-training-platform.php (version bump, removed v1 reference, added Google web login)
- templates/login.php (fixed Google button check, added SVG icon)
- templates/register.php (added Google sign-up button)
- templates/messaging.php (fixed field name mismatch)
- includes/class-ptp-admin-settings-v71.php (added login callback URI)

Deleted:
- includes/class-ptp-schedule-calendar.php
- assets/js/schedule.js
- assets/css/schedule.css

Added:
- includes/class-ptp-google-web-login.php (NEW - Google OAuth for website)
- docs/CHANGELOG-V128.md
- docs/POST-TRAINING-PAYMENT-FLOW.md
```
