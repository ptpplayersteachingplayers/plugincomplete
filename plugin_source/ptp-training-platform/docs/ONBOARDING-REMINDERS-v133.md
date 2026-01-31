# PTP Trainer Onboarding Reminders System v133

## Overview

Automated reminder system to ensure trainers complete their profile after approval. Reduces trainer drop-off by sending strategic email + SMS reminders.

## The Problem

Trainers apply and get approved, but often forget to complete the onboarding steps:
1. Profile Photo
2. Bio
3. Playing Experience
4. Hourly Rate
5. Training Locations
6. Weekly Availability
7. Trainer Agreement
8. Stripe Connect (Payment Setup)

Without completing these steps, they can't receive bookings.

## The Solution

### Reminder Sequence

| # | Timing | Subject | Tone |
|---|--------|---------|------|
| 1 | 24 hours after approval | "Complete your PTP profile to start earning 💰" | Encouraging |
| 2 | 3 days after approval | "You're X% done - finish your profile!" | Progress-focused |
| 3 | 7 days after approval | "Need help completing your profile?" | Supportive |
| 4 | 14 days after approval | "⚠️ Last chance: Complete your profile" | Urgent |

### Features

- **Email + SMS** - Both channels for each reminder
- **Personalized** - Shows what's complete vs incomplete
- **Smart timing** - Won't send if they completed or already got reminder
- **Completion tracking** - Detects when 100% complete
- **Congratulations email** - Sent when onboarding is complete

## Database Columns Added

```sql
-- Added to ptp_trainers table:
approved_at datetime DEFAULT NULL,
onboarding_reminder_count int(11) DEFAULT 0,
last_onboarding_reminder_at datetime DEFAULT NULL,
onboarding_completed_at datetime DEFAULT NULL,
```

## Cron Job

Runs **twice daily** (9am and 5pm) to check for trainers who need reminders.

Hook: `ptp_check_onboarding_reminders`

## Action Hooks

```php
// Fired when trainer is approved (sets approved_at)
do_action('ptp_trainer_approved', $trainer_id);

// Fired when trainer saves onboarding form (checks completion)
do_action('ptp_trainer_onboarding_saved', $trainer_id);
```

## Admin Features

### Manual Reminder

Send a reminder manually via AJAX:
```javascript
jQuery.post(ajaxurl, {
    action: 'ptp_send_onboarding_reminder',
    nonce: ptp_admin_nonce,
    trainer_id: 123
});
```

### Get Incomplete Trainers

```php
$incomplete = PTP_Onboarding_Reminders::get_incomplete_trainers();
foreach ($incomplete as $item) {
    $trainer = $item['trainer'];
    $completion = $item['completion'];
    echo $trainer->display_name . ': ' . $completion['percentage'] . '%';
}
```

### Run Manual Check

```php
// From admin context:
PTP_Onboarding_Reminders::run_manual_check();
```

## Files

- `/includes/class-ptp-onboarding-reminders.php` - Main reminder class
- `/templates/trainer-onboarding-v133.php` - Improved onboarding form

## Configuration

Currently no admin settings UI. To disable reminders, comment out the initialization:

```php
// In ptp-training-platform.php, comment out:
// PTP_Onboarding_Reminders::init();
```

## SMS Integration

Uses existing `PTP_SMS` class (Salesmsg). If SMS is not enabled, only emails are sent.

## Completion Criteria

A trainer is considered "complete" when ALL of these are true:
- Has photo
- Bio >= 50 characters
- Playing level set
- Hourly rate > 0
- City + State set
- At least 1 training location
- At least 1 availability slot
- Contract signed
- Stripe account fully connected

## Testing

1. **Test Approval Flow:**
   - Approve a trainer in admin
   - Check `approved_at` is set in database
   - Verify action `ptp_trainer_approved` fires

2. **Test Reminder Cron:**
   - Create a trainer with incomplete profile
   - Set `approved_at` to 25 hours ago
   - Run: `PTP_Onboarding_Reminders::check_and_send_reminders();`
   - Verify email sent and `onboarding_reminder_count` incremented

3. **Test Completion Detection:**
   - Complete all onboarding steps
   - Save form
   - Verify `onboarding_completed_at` is set
   - Verify congratulations email sent

## Future Improvements

- [ ] Admin dashboard widget showing incomplete trainers
- [ ] Admin settings for reminder timing
- [ ] A/B test different reminder copy
- [ ] Track conversion rate (approved → completed)
- [ ] Push notifications via app
