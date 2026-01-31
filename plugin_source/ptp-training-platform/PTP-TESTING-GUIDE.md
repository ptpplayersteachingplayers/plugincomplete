# PTP Platform Testing Guide v104

## Quick Test URLs

| Page | URL |
|------|-----|
| Find Trainers | `/find-trainers/` |
| Camps | `/camps/` |
| Parent Dashboard | `/my-training/` |
| Trainer Dashboard | `/trainer-dashboard/` |
| Admin Announcements | `/wp-admin/admin.php?page=ptp-announcements` |
| Admin Dashboard | `/wp-admin/admin.php?page=ptp-dashboard` |

---

## 🛒 TEST 1: Camp Checkout Flow

### Steps:
1. Go to `/camps/`
2. Add a camp to cart
3. Proceed to checkout
4. Fill in:
   - Billing info
   - Player name (camper)
   - Player age
5. Complete payment (use Stripe test card: `4242 4242 4242 4242`)
6. Should redirect to **Thank You Page** (`/thank-you/?order_id=XXX`)

### Thank You Page Should Show:
- [ ] ✅ Order confirmation message
- [ ] 📸 Instagram opt-in section (enter IG handle)
- [ ] 🎁 Referral share buttons (SMS, Email, Facebook, Copy Link)
- [ ] ⚽ Training CTA ("Browse Trainers" or "Book Again")

### Test Instagram Opt-in:
1. Enter an Instagram handle (e.g., `@testparent`)
2. Click "Yes, announce us!"
3. Check admin: `/wp-admin/admin.php?page=ptp-announcements`
4. Should see new pending announcement

### Test Share Buttons:
1. Click each share button (SMS, Email, Facebook, Copy)
2. Verify share tracking works

---

## 🏋️ TEST 2: Training Session Checkout

### Steps:
1. Go to `/find-trainers/`
2. Click on a trainer profile
3. Select date/time
4. Add to cart or proceed to checkout
5. Complete payment
6. Should redirect to Thank You Page

### Verify:
- [ ] Thank You page shows trainer name
- [ ] "Book Again" CTA appears (not "Browse Trainers")
- [ ] Instagram opt-in works
- [ ] Share buttons work

---

## 👨‍👩‍👧 TEST 3: Parent Dashboard

### URL: `/my-training/`

### Test Each Section:
- [ ] **Stats** - Sessions, Hours, Players counts display
- [ ] **Package Credits** - Shows remaining if any
- [ ] **Upcoming Sessions** - Lists booked sessions
- [ ] **Quick Actions** - All 4 buttons work:
  - Find Trainers → `/find-trainers/`
  - View Camps → `/camps/`
  - Messages → `/messages/`
  - Account → `/account/`
- [ ] **Your Trainers** - Shows recent/favorite trainers
- [ ] **Bottom Nav** - All 4 tabs work (Home, Find, Chat, Account)

### Test Review Flow:
1. If you have a completed session, click "Review"
2. Should go to `/review/?booking=XXX`
3. Submit a star rating and comment
4. Verify review saved

---

## 🏃 TEST 4: Trainer Dashboard

### URL: `/trainer-dashboard/` (login as trainer)

### Test Each Tab:

#### HOME Tab:
- [ ] Greeting shows trainer name
- [ ] Stats display (Sessions, Earnings, Rating, Response)
- [ ] Upcoming sessions list
- [ ] "Needs Confirmation" alerts (if any past sessions)

#### SCHEDULE Tab:
- [ ] Calendar/upcoming bookings display
- [ ] Availability toggles work
- [ ] Save Availability button works

#### EARNINGS Tab:
- [ ] Week/Month/Pending stats
- [ ] Request Payout button (if Stripe connected)

#### MESSAGES Tab:
- [ ] Conversation list loads
- [ ] Unread badge shows count
- [ ] Click conversation → goes to `/messages/`

#### PROFILE Tab:
- [ ] Photo displays
- [ ] Edit fields work (name, bio, phone, city)
- [ ] Save Profile button works
- [ ] Rate update works
- [ ] "Full Profile Settings" link works
- [ ] Stripe Connect status shows

### Test Stripe Connect (if not connected):
1. Click "Connect with Stripe"
2. Should redirect to Stripe onboarding
3. After completion, return to dashboard

### Test Session Confirmation:
1. If past sessions show in "Needs Confirmation"
2. Click "Confirm" button
3. Session should mark as completed

---

## 📸 TEST 5: Admin Announcements Queue

### URL: `/wp-admin/admin.php?page=ptp-announcements`

### Verify:
- [ ] Page loads with PTP admin styling
- [ ] Navigation tabs show (including 📸 Social)
- [ ] Stats cards: Pending / Posted / Total
- [ ] Post template displays
- [ ] Table shows announcements (or empty state)

### Test Actions:
1. Click "📋 Copy" on pending announcement
2. Verify text copied to clipboard
3. Click "✓ Posted" - enter optional URL
4. Verify status changes to Posted
5. Click "Skip" on another
6. Verify status changes to Skipped

---

## 🔧 TEST 6: Admin Settings

### Thank You Page Settings:
URL: `/wp-admin/admin.php?page=ptp-thankyou-settings`

- [ ] Training CTA toggle works
- [ ] Instagram handle setting saves
- [ ] Referral settings save

### Main PTP Settings:
URL: `/wp-admin/admin.php?page=ptp-settings`

- [ ] All tabs load
- [ ] Settings save properly

---

## 🧪 Stripe Test Cards

| Card Number | Result |
|-------------|--------|
| `4242 4242 4242 4242` | Success |
| `4000 0000 0000 0002` | Decline |
| `4000 0000 0000 9995` | Insufficient funds |

Use any future expiry date and any 3-digit CVC.

---

## 🐛 Debug Tips

### Check for PHP Errors:
```
/wp-admin/admin.php?page=ptp-tools
```

### Check Thank You Page Manually:
```
/thank-you/?order_id=YOUR_ORDER_ID
```

### Check Database Tables Exist:
- `wp_ptp_social_announcements`
- `wp_ptp_shares`
- `wp_ptp_bookings`
- `wp_ptp_trainers`
- `wp_ptp_parents`
- `wp_ptp_players`

### Console Errors:
Open browser DevTools (F12) → Console tab
Check for JavaScript errors during checkout/thank you page

---

## ✅ Success Criteria

Everything works if:
1. Camp checkout completes → Thank you page shows
2. Training checkout completes → Thank you page shows
3. Instagram opt-in saves to announcements queue
4. Share buttons track clicks
5. Parent dashboard loads with all sections
6. Trainer dashboard tabs all work
7. Admin announcements page loads and functions
8. No PHP errors or JavaScript console errors

---

## 📞 Quick Fixes

**Announcements page 404?**
- Deactivate/reactivate plugin to re-register menus

**Thank you page not styled?**
- Check `/thank-you/` page exists with `[ptp_thank_you]` shortcode

**Tables missing?**
- Visit any PTP admin page to trigger table creation
- Or go to PTP Settings → Tools → Recreate Tables

**Stripe not working?**
- Check API keys in PTP Settings → Stripe
- Verify webhook URL is configured in Stripe dashboard
