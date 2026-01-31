# PTP Training Platform v126.0.0

## Enhanced Schedule Calendar v2.0

Complete rebuild of the admin schedule calendar with modern UX and powerful features.

### New Features

**Dashboard Stats Panel**
- Real-time stats at top: Today's sessions, This week, Pending, Week revenue, Active trainers
- Auto-refreshes every 5 minutes
- Animated number transitions

**Keyboard Shortcuts**
- `N` - New session
- `R` - Refresh calendar
- `T` - Go to today
- `W` - Week view
- `M` - Month view
- `D` - Day view
- `←` / `→` - Previous/Next
- `/` - Focus search
- `Esc` - Close modal / Clear selection
- `?` - Show shortcuts help

**Recurring Sessions**
- Create multiple sessions at once
- Select frequency: Weekly, Bi-weekly, Monthly
- Choose days of week
- Set count (1-52 sessions)
- Preview before creating
- Automatic conflict detection skips conflicts

**Bulk Actions**
- Ctrl/Cmd + Click to multi-select events
- Bulk Confirm, Complete, or Cancel
- Selection counter in toolbar
- Clear selection with Escape

**Conflict Detection**
- Real-time warning when scheduling conflicts
- Checks both sessions and bookings tables
- Visual alert in modal before save

**Export Functionality**
- Export to CSV or iCal
- Custom date range selection
- Downloads file directly

**Advanced Filtering**
- Global search (sessions, trainers, players)
- Status filter chips (All, Pending, Confirmed, Scheduled, Completed)
- Payment filter dropdown
- Session type filter
- Clear all filters button

**Right-Click Context Menu**
- Edit session
- Quick status changes (Confirm, Complete, Cancel)
- Delete option

**Tooltips**
- Hover over events to see details
- Player name, trainer, status, price, location

**Visual Improvements**
- Modern PTP branding with gold accents
- Sticky top bar with search
- Dashboard stats cards with icons
- Improved trainer sidebar with session counts
- Source indicators (Parent Booking vs Admin Session)
- Toast notifications for actions
- Smooth animations throughout

### Technical Details

**New Files:**
- `includes/class-ptp-schedule-calendar-v2.php` - Main class (1500+ lines)
- `assets/js/schedule-v2.js` - JavaScript (540 lines)
- `assets/css/schedule-v2.css` - Styles (900 lines)

**New AJAX Endpoints:**
- `ptp_schedule_get_dashboard_stats` - Dashboard statistics
- `ptp_schedule_quick_status` - Quick status change
- `ptp_schedule_bulk_action` - Bulk operations
- `ptp_schedule_create_recurring` - Create recurring sessions
- `ptp_schedule_check_conflicts` - Conflict detection
- `ptp_schedule_export` - CSV/iCal export
- `ptp_schedule_get_trainer_availability` - Trainer availability

**Menu Location:**
- PTP Training → Schedule (was Schedule Calendar)
- URL: `/wp-admin/admin.php?page=ptp-schedule`

### Migration Notes

- Old schedule calendar class disabled (class-ptp-schedule-calendar.php)
- Same AJAX action names maintained for compatibility
- No database changes required
- User view preferences saved to localStorage
