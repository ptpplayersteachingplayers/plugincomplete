# PTP Training Platform v60.0.0 - Rover-Inspired Trainer Profiles

## Philosophy: Make Leaving Painful, Staying Easy

Inspired by Rover's pet-sitting platform, this update transforms trainer profiles into **valuable assets** that trainers don't want to abandon.

---

## The Rover Playbook Applied to PTP

| Rover Strategy | PTP Implementation |
|----------------|-------------------|
| Insurance only covers Rover bookings | $1M liability insurance for PTP sessions only |
| Reviews live on Rover | Reputation (sessions, ratings, badges) locked to profile |
| Continuous lead flow | Camp → Private Training pipeline feeds trainers |
| Automatic payments | Fast payouts, no chasing money |
| Accept some leakage | Focus on capturing first booking, building value |

---

## What's New in v60.0.0

### 1. Enhanced Photo System

**Main Profile Photo**
- Clear guidelines for professional photos
- Photo tips panel (face visible, action shots, good lighting)
- Visual indicator when photo is uploaded

**Action Gallery**
- Up to 5 additional photos
- Shows training, playing, coaching in action
- Drag-and-drop upload
- Easy remove/replace

### 2. Structured Bio Sections

Instead of one free-form bio, trainers complete three focused sections:

1. **My Training Philosophy** (500 chars)
   - "What's your approach to developing young players?"
   - Helps parents understand training style

2. **What to Expect in a Session** (500 chars)
   - Walk parents through a typical session
   - Reduces uncertainty, builds confidence

3. **Experience & Credentials** (500 chars)
   - Playing background, certifications
   - Social proof and credibility

**Why This Matters:**
- More complete profiles = higher booking rates
- Structured data = better search/filtering later
- More effort invested = harder to abandon

### 3. Rich Training Locations

Each location now includes:
- **Name** (e.g., "Memorial Park Soccer Fields")
- **Address** (auto-complete or manual)
- **Field Type** (grass, turf, indoor, futsal, beach)
- **Access Type** (public park, school, club, private)
- **Amenities** (parking, restrooms, water, lights)

**Parent Benefits:**
- Know exactly where training happens
- Can plan logistics (parking, bathrooms)
- Understand facility quality

**Trainer Benefits:**
- Professional presentation
- Clear expectations
- Multiple locations supported

### 4. Session Preferences

New granular settings:
- **Default Session Length** (45, 60, 90 min)
- **Buffer Time** (0, 15, 30 min between sessions)
- **Max Sessions/Day** (to prevent burnout)
- **Minimum Booking Notice** (2, 12, 24, 48 hours)

### 5. Enhanced Availability

- Visual toggle for each day
- Active state clearly highlighted (green)
- Time range selectors
- Mobile-optimized layout

### 6. Reputation Preview (The Key Retention Feature)

Shows trainers their **platform value**:

```
┌─────────────────────────────────────────────┐
│  YOUR PTP REPUTATION                        │
│                                             │
│    147        4.9★        43                │
│  Sessions    Rating     Reviews             │
│                                             │
│  [✓ Background Checked] [✓ 10+ Sessions]   │
│  [🔒 Elite Trainer - 50 sessions to unlock] │
│                                             │
│  Your reputation lives here. Every session, │
│  review, and badge stays on your profile.   │
└─────────────────────────────────────────────┘
```

**Why This Works:**
- Trainers SEE their accumulated value
- Badges create progression/gamification
- Locked badges create goals
- Copy reinforces "this lives HERE"

---

## Badge System

| Badge | Requirement | Status |
|-------|-------------|--------|
| Background Checked | Pass background check | Always earned |
| 10+ Sessions | Complete 10 sessions | Unlockable |
| 25+ Sessions | Complete 25 sessions | Unlockable |
| Elite Trainer | Complete 50 sessions | Unlockable |
| Supercoach | High satisfaction scores | Unlockable |
| Camp Coach | Coached at PTP camp | Unlockable |

---

## Database Changes

### New Trainer Fields

```sql
-- Add to wp_ptp_trainers table
ALTER TABLE wp_ptp_trainers ADD COLUMN bio_sections TEXT NULL;
ALTER TABLE wp_ptp_trainers ADD COLUMN session_preferences TEXT NULL;
ALTER TABLE wp_ptp_trainers ADD COLUMN twitter VARCHAR(100) NULL;
```

**bio_sections** (JSON):
```json
{
  "philosophy": "I believe in building confidence...",
  "session_structure": "Each session starts with...",
  "credentials": "4-year starter at Villanova..."
}
```

**session_preferences** (JSON):
```json
{
  "duration_default": 60,
  "buffer_time": 15,
  "max_sessions_day": 5,
  "min_booking_notice": 24
}
```

### Location Data Structure

```json
{
  "name": "Memorial Park Soccer Fields",
  "address": "123 Main St, Philadelphia, PA 19103",
  "lat": 39.9526,
  "lng": -75.1652,
  "type": "turf",
  "access": "public",
  "amenities": ["parking", "restrooms", "lights"]
}
```

---

## AJAX Handler

New action: `ptp_save_trainer_onboarding_v60`

Handles:
- Main photo upload
- Gallery photo uploads (up to 5)
- Bio sections (JSON)
- Session preferences (JSON)
- Training locations (JSON array)
- Weekly availability
- All standard trainer fields

---

## Template Usage

Replace the old onboarding template reference:

```php
// In class-ptp-templates.php or router
// Old:
include PTP_PLUGIN_DIR . 'templates/trainer-onboarding.php';

// New:
include PTP_PLUGIN_DIR . 'templates/trainer-onboarding-v60.php';
```

---

## The Retention Math

**What a trainer with 100 sessions "loses" by leaving PTP:**

| Asset | Value |
|-------|-------|
| 100 verified sessions | Trust signal |
| 4.9★ rating from 30+ reviews | Social proof |
| "Elite Trainer" badge | Credibility |
| Background check verification | Safety signal |
| Insurance coverage | Risk protection |
| Continuous new client flow | Lead generation |
| Camp coaching opportunities | Income stream |

**Total platform value: Priceless**

When a trainer considers texting clients directly, they weigh:
- Save 20% on a few sessions
- Lose ALL of the above

---

## Mobile Optimization

- All touch targets 48px minimum
- Sticky navigation at bottom
- Collapsible sections
- Responsive grid layouts
- Form inputs sized for thumb typing

---

## Next Steps (Recommended)

1. **Add Insurance Badge** - Partner with insurance provider, display coverage prominently
2. **Camp Coaching Integration** - Show camp opportunities on trainer dashboard
3. **Fast Payouts** - Implement same-day or next-day payouts
4. **Lead Flow Dashboard** - Show trainers "You've received X new clients from PTP this month"
5. **Review Prompts** - Automatically request reviews after sessions

---

## File Changes

- `templates/trainer-onboarding-v60.php` (NEW)
- `ptp-training-platform.php` (version bump to 60.0.0)
- `V60-ROVER-PROFILES.md` (this file)

---

## Design System Maintained

All new UI follows PTP design system:
- **Fonts:** Oswald (headings), Inter (body)
- **Colors:** #FCB900 (gold), #0A0A0A (black), #10B981 (success)
- **Borders:** 2px solid, no border-radius
- **Labels:** Uppercase, 0.02em letter-spacing
- **Buttons:** Sharp edges, bold weight
