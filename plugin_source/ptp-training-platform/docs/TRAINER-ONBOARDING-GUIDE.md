# PTP Trainer Onboarding Guide

## Quick Start - Bringing Trainers onto the Platform

### The Trainer Journey

```
1. APPLY → 2. APPROVAL → 3. ONBOARD → 4. GO LIVE
```

---

## Step 1: Trainer Applies

**URL:** `yoursite.com/apply/`

Trainer fills out:
- Name, Email, Phone
- College/Team affiliation
- Playing experience
- Password (creates account)

**What happens:**
- WordPress user created with `ptp_trainer` role
- Entry in `wp_ptp_applications` table
- Admin notification email sent

---

## Step 2: Admin Approves

**Location:** WP Admin → PTP Training → Applications

1. Review application details
2. Click "Approve" button
3. System creates trainer record in `wp_ptp_trainers`
4. Trainer receives approval email with login link

**Quick Approval (for trusted trainers):**
```
WP Admin → Users → Add New
- Create user with ptp_trainer role
- They'll be prompted to complete profile on first login
```

---

## Step 3: Trainer Completes Onboarding

**URL:** `yoursite.com/trainer-onboarding/`

5-step wizard:
1. **Photo + Basic Info** - Headshot, name, headline
2. **Location + Specialties** - Training locations, skills
3. **Rate + Availability** - Hourly rate, weekly schedule
4. **Payment Setup** - Stripe Connect onboarding
5. **Review + Submit** - Preview and confirm

**After completion:**
- Redirects to `/trainer-dashboard/?welcome=1`
- Profile goes live on `/trainer/[slug]/`

---

## Step 4: Trainer is Live!

**Dashboard:** `yoursite.com/trainer-dashboard/`

Tabs:
- 📅 **Schedule** - View/edit weekly availability
- ⚽ **Sessions** - Upcoming and past sessions
- 💰 **Earnings** - Revenue and payout status
- 💬 **Messages** - Parent communications
- ⚙️ **Profile** - Edit profile details

**Public Profile:** `yoursite.com/trainer/[trainer-slug]/`

---

## Admin Quick Actions

### Manually Add a Trainer

```sql
-- In WP Admin → Tools → SQL or phpMyAdmin
INSERT INTO wp_ptp_trainers (user_id, display_name, slug, email, hourly_rate, status)
VALUES (123, 'John Smith', 'john-smith', 'john@email.com', 100, 'active');
```

Or use the admin panel:
1. WP Admin → PTP Training → Trainers → Add New
2. Fill in details
3. Trainer can log in and complete profile

### Bulk Import Trainers

Create CSV with columns:
```
email,name,phone,college,hourly_rate
john@email.com,John Smith,555-1234,Penn State,100
jane@email.com,Jane Doe,555-5678,Villanova,120
```

Upload via WP Admin → PTP Training → Import Trainers

---

## Key URLs

| Page | URL | Purpose |
|------|-----|---------|
| Apply | `/apply/` | New trainer application |
| Login | `/login/` | Trainer/parent login |
| Dashboard | `/trainer-dashboard/` | Trainer home base |
| Onboarding | `/trainer-onboarding/` | Complete profile wizard |
| Edit Profile | `/trainer-edit-profile/` | Update profile details |
| Public Profile | `/trainer/[slug]/` | Bookable trainer page |
| Find Trainers | `/find-trainers/` | Parent-facing trainer grid |

---

## Troubleshooting

### "Trainer profile not found"
- Check `wp_ptp_trainers` table has entry for user
- Verify `user_id` matches WordPress user ID
- Run: WP Admin → PTP Training → Tools → Repair Database

### Trainer stuck on onboarding
- Check `ptp_onboarding_completed` user meta
- Clear with: `delete_user_meta($user_id, 'ptp_onboarding_completed')`

### Profile not showing publicly
- Verify trainer `status` = 'active' in database
- Check trainer has at least: photo, hourly_rate, one availability slot

### Stripe Connect issues
- Trainer needs to complete Stripe onboarding
- Check `stripe_account_id` in trainer record
- Re-trigger: Dashboard → Earnings → Set Up Payouts

---

## Settings to Configure

**WP Admin → PTP Training → Settings:**

- [ ] Stripe API keys (live + test)
- [ ] Google Maps API key
- [ ] Default commission rate (%)
- [ ] Minimum booking notice (hours)
- [ ] Cancellation policy

---

## Ready to Launch Checklist

- [ ] Test apply flow end-to-end
- [ ] Test booking flow as parent
- [ ] Stripe Connect working
- [ ] Email notifications sending
- [ ] Google Maps locations working
- [ ] Mobile responsive checked
- [ ] First trainer profile looks good

---

## Support

Questions? Contact: luke@ptpsummercamps.com
