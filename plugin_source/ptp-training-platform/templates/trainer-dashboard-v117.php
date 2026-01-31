<?php
/**
 * Trainer Dashboard v132 - Open Training Dates
 * 
 * Features:
 * - 100% functional: Home, Schedule, Earnings, Messages, Profile
 * - COMPACT design - fits more on screen
 * - Full mobile support (380px-480px-768px breakpoints)
 * - Touch-optimized interactions
 * - Scrollable stats row
 * - Inline quick actions
 * - Streamlined session cards
 * - Fixed bottom navigation with safe-area support
 * - Real-time data loading
 * - Session confirmation
 * - Availability management with inline editing
 * - Stripe Connect integration
 * - Profile photo upload
 * - Google Calendar sync
 * - v131: Open training dates feature
 * - v131: Schedule status prompt banner
 * 
 * v132: Added open training dates feature
 *   - Trainers can set specific future dates they're available
 *   - Schedule status prompt encourages trainers to keep schedule active
 *   - Open dates show on trainer profile for families
 */
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI'])));
    exit;
}

global $wpdb;
$user_id = get_current_user_id();

// Check for Stripe callback
$stripe_connected = isset($_GET['stripe_connected']) || isset($_GET['connected']);

// Get trainer
$trainer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", 
    $user_id
));

if (!$trainer) {
    wp_redirect(home_url('/apply/'));
    exit;
}

// v134: Pending trainers always go to pending page (waiting for approval)
if ($trainer->status === 'pending') {
    wp_redirect(home_url('/trainer-pending/'));
    exit;
}

// Handle rejected trainers
if ($trainer->status === 'rejected') {
    wp_redirect(home_url('/apply/?status=rejected'));
    exit;
}

// Check Stripe status
$has_stripe = !empty($trainer->stripe_account_id);
$stripe_complete = false;
if ($has_stripe && class_exists('PTP_Stripe')) {
    $stripe_complete = PTP_Stripe::is_account_complete($trainer->stripe_account_id);
}

$first_name = explode(' ', $trainer->display_name)[0];
$profile_url = home_url('/trainer/' . $trainer->slug . '/');
$nonce = wp_create_nonce('ptp_trainer_nonce');
$nonce_general = wp_create_nonce('ptp_nonce');

// Date ranges
$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');

// Upcoming sessions
$upcoming = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, 
           pl.name as player_name, 
           pa.display_name as parent_name,
           pa.phone as parent_phone
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    WHERE b.trainer_id = %d 
    AND b.session_date >= CURDATE() 
    AND b.status IN ('confirmed','pending') 
    ORDER BY b.session_date ASC, b.start_time ASC 
    LIMIT 10
", $trainer->id));

// Needs confirmation (past sessions not yet confirmed as completed)
$needs_confirmation = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name, pa.display_name as parent_name
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    WHERE b.trainer_id = %d 
    AND b.session_date < CURDATE()
    AND b.status = 'confirmed'
    ORDER BY b.session_date DESC
    LIMIT 5
", $trainer->id));

// Earnings
$week_earnings = floatval($wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id = %d AND session_date >= %s AND status = 'completed'", 
    $trainer->id, $week_start
)));

$month_earnings = floatval($wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id = %d AND session_date >= %s AND status = 'completed'", 
    $trainer->id, $month_start
)));

$pending_payout = floatval($wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'", 
    $trainer->id
)));

$total_earnings = floatval($wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id = %d AND status = 'completed'", 
    $trainer->id
)));

// Stats
$total_sessions = intval($trainer->total_sessions ?: 0);
$avg_rating = floatval($trainer->average_rating ?: 5.0);
$review_count = intval($trainer->review_count ?: 0);
$hourly_rate = intval($trainer->hourly_rate ?: 60);

// Training locations
$training_locations = array();
if (!empty($trainer->training_locations)) {
    $decoded = json_decode($trainer->training_locations, true);
    if (is_array($decoded)) {
        $training_locations = $decoded;
    }
}

// Availability
$availability = array();
$avail_table = $wpdb->prefix . 'ptp_availability';
if ($wpdb->get_var("SHOW TABLES LIKE '$avail_table'")) {
    $raw_availability = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$avail_table} WHERE trainer_id = %d ORDER BY day_of_week ASC",
        $trainer->id
    ));
    $day_names = array(0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday');
    foreach ($raw_availability as $slot) {
        $day_num = intval($slot->day_of_week);
        if (isset($day_names[$day_num])) {
            $availability[$day_names[$day_num]] = array(
                'enabled' => !empty($slot->is_active),
                'start' => substr($slot->start_time, 0, 5),
                'end' => substr($slot->end_time, 0, 5),
            );
        }
    }
}

// Messages
$conversations = array();
$unread_count = 0;
if (class_exists('PTP_Messaging_V71')) {
    $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
    foreach ($conversations as $c) {
        if (!empty($c->unread)) $unread_count++;
    }
}

// Completed sessions for earnings tab
$completed_sessions = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    WHERE b.trainer_id = %d AND b.status = 'completed'
    ORDER BY b.session_date DESC
    LIMIT 25
", $trainer->id));

// Trainer specialties
$trainer_specs = array();
if (!empty($trainer->specialties)) {
    if (is_string($trainer->specialties)) {
        $decoded = json_decode($trainer->specialties, true);
        $trainer_specs = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $trainer->specialties)));
    } elseif (is_array($trainer->specialties)) {
        $trainer_specs = $trainer->specialties;
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0A0A0A">
<title>Dashboard - <?php echo esc_html($trainer->display_name); ?> | PTP</title>
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --gold: #FCB900;
    --gold-light: rgba(252,185,0,0.12);
    --gold-dark: #E5A800;
    --black: #0A0A0A;
    --white: #FFFFFF;
    --gray-50: #F9FAFB;
    --gray-100: #F3F4F6;
    --gray-200: #E5E7EB;
    --gray-300: #D1D5DB;
    --gray-400: #9CA3AF;
    --gray-500: #6B7280;
    --gray-600: #4B5563;
    --gray-700: #374151;
    --green: #22C55E;
    --green-light: rgba(34,197,94,0.12);
    --red: #EF4444;
    --red-light: rgba(239,68,68,0.12);
    --blue: #3B82F6;
    --blue-light: rgba(59,130,246,0.12);
    --r: 0;
    --r-sm: 0;
    --r-lg: 0;
    --shadow: 0 2px 8px rgba(0,0,0,0.06);
    --shadow-lg: 0 4px 16px rgba(0,0,0,0.1);
    --safe-top: env(safe-area-inset-top, 0px);
    --safe-bottom: env(safe-area-inset-bottom, 0px);
    --safe-left: env(safe-area-inset-left, 0px);
    --safe-right: env(safe-area-inset-right, 0px);
    --ease: cubic-bezier(0.34, 1.56, 0.64, 1);
    --nav-height: 60px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body { 
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
    background: var(--gray-50); 
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overscroll-behavior-y: contain;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    min-height: 100vh;
    min-height: 100dvh;
    height: auto !important;
    position: relative !important;
    /* v133.2: Hide scrollbar */
    scrollbar-width: none;
    -ms-overflow-style: none;
}
body::-webkit-scrollbar { display: none; width: 0; }
html {
    overflow-x: hidden !important;
    overflow-y: auto !important;
    scroll-behavior: smooth;
    height: auto !important;
    position: relative !important;
    /* v133.2: Hide scrollbar */
    scrollbar-width: none;
    -ms-overflow-style: none;
}
html::-webkit-scrollbar { display: none; width: 0; }
h1, h2, h3, h4 { font-family: 'Oswald', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
a { color: inherit; text-decoration: none; }
button { font-family: inherit; }
input, select, textarea { font-family: inherit; font-size: 16px; }

/* App Shell */
.td {
    min-height: 100vh;
    min-height: 100dvh;
    padding-bottom: calc(var(--nav-height) + var(--safe-bottom) + 16px);
    -webkit-tap-highlight-color: transparent;
}

/* Header - Compact */
.td-header {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    padding: calc(var(--safe-top) + 12px) 16px 14px;
    position: sticky;
    top: 0;
    z-index: 100;
}

.td-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.td-greeting { font-size: 11px; color: var(--gray-400); margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.05em; }
.td-name { font-size: 22px; color: var(--white); line-height: 1.1; }
.td-name span { color: var(--gold); }

.td-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 2px solid var(--gold);
    object-fit: cover;
    background: var(--gray-700);
}

/* Stats Row - Compact Inline */
.td-stats {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding-bottom: 2px;
}
.td-stats::-webkit-scrollbar { display: none; }

.td-stat {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: 8px 12px;
    text-align: center;
    flex-shrink: 0;
    min-width: 70px;
}

.td-stat-value {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: var(--gold);
    line-height: 1;
}
.td-stat-value.green { color: var(--green); }

.td-stat-label {
    font-size: 8px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-400);
    margin-top: 3px;
    white-space: nowrap;
}

/* Tab Content */
.td-content { 
    padding: 14px 12px; 
    max-width: 800px;
    margin: 0 auto;
}

.td-tab {
    display: none;
    animation: fadeIn 0.2s ease-out;
}
.td-tab.active { display: block; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Cards - Compact */
.td-card {
    background: var(--white);
    border-radius: var(--r-sm);
    border: 2px solid var(--gray-200);
    padding: 14px;
    box-shadow: none;
    margin-bottom: 12px;
}

.td-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    gap: 10px;
}

.td-card-title { font-size: 12px; letter-spacing: 0.04em; color: var(--gray-600); }

/* Buttons - Compact */
.td-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 40px;
    padding: 0 16px;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border: 2px solid var(--gold);
    border-radius: 0;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
}
.td-btn:hover { background: var(--black); color: var(--gold); border-color: var(--black); }
.td-btn:active { transform: scale(0.97); }
.td-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.td-btn.sm { min-height: 36px; padding: 0 12px; font-size: 11px; }
.td-btn.outline { background: transparent; border: 2px solid var(--gold); }
.td-btn.outline:hover { background: var(--gold); color: var(--black); }
.td-btn.green { background: var(--green); color: var(--white); border-color: var(--green); }
.td-btn.red { background: var(--red); color: var(--white); border-color: var(--red); }
.td-btn.full { width: 100%; }

/* Quick Actions - Compact Row */
.td-quick-actions {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.td-quick-actions::-webkit-scrollbar { display: none; }

.td-quick-action {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 14px;
    background: var(--white);
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s var(--ease);
    position: relative;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
}

.td-quick-action:hover {
    border-color: var(--gold);
    background: var(--gold-light);
}

.td-quick-action:active {
    transform: scale(0.97);
}

.td-quick-action svg {
    width: 18px;
    height: 18px;
    color: var(--gold);
    flex-shrink: 0;
}

.td-quick-action span {
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--gray-700);
    white-space: nowrap;
}

.td-quick-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    background: var(--red);
    color: var(--white);
    font-family: 'Inter', sans-serif;
    font-size: 9px;
    font-weight: 700;
    border-radius: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Session Items - Compact */
.td-session {
    display: flex;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid var(--gray-100);
    align-items: center;
}
.td-session:last-child { border-bottom: none; padding-bottom: 0; }
.td-session:first-child { padding-top: 0; }

.td-session-date {
    width: 44px;
    text-align: center;
    flex-shrink: 0;
    background: var(--gray-50);
    border-radius: 8px;
    padding: 6px 4px;
}

.td-session-day {
    font-family: 'Oswald', sans-serif;
    font-size: 9px;
    color: var(--gray-500);
    text-transform: uppercase;
}

.td-session-num {
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--black);
    line-height: 1;
}

.td-session-info { flex: 1; min-width: 0; }

.td-session-player {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--black);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.td-session-meta {
    font-size: 12px;
    color: var(--gray-600);
    margin-top: 2px;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.td-session-right { 
    text-align: right; 
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.td-session-amount {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
    color: var(--green);
}

.td-session-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 50px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.td-session-status.confirmed { background: var(--green-light); color: var(--green); }
.td-session-status.pending { background: var(--gold-light); color: #D97706; }
.td-session-status.completed { background: var(--blue-light); color: var(--blue); }

/* Payout Banner - Compact */
.td-payout {
    background: linear-gradient(135deg, var(--green) 0%, #16A34A 100%);
    border-radius: var(--r-sm);
    padding: 16px 14px;
    color: var(--white);
    margin-bottom: 12px;
}

.td-payout-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.td-payout-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.9; }
.td-payout-value { font-family: 'Oswald', sans-serif; font-size: 26px; font-weight: 700; }
.td-payout .td-btn { background: var(--white); color: var(--green); }

/* Commission Card */
.td-commission-card { background: #FFFBEB; border: 2px solid var(--gold); }
.td-commission-tiers { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.td-commission-tier {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: 8px;
    background: var(--white);
}
.td-commission-tier.first { border-left: 4px solid #D97706; }
.td-commission-tier.repeat { border-left: 4px solid var(--green); }
.td-commission-icon { font-size: 20px; }
.td-commission-info { flex: 1; }
.td-commission-label { font-weight: 700; font-size: 14px; }
.td-commission-desc { font-size: 11px; color: var(--gray-500); }
.td-commission-rate { text-align: right; }
.td-commission-rate strong { display: block; font-family: 'Oswald', sans-serif; font-size: 24px; font-weight: 700; }
.td-commission-tier.first .td-commission-rate strong { color: #D97706; }
.td-commission-tier.repeat .td-commission-rate strong { color: var(--green); }
.td-commission-rate small { font-size: 10px; color: var(--gray-500); text-transform: uppercase; }
.td-commission-example {
    font-size: 12px;
    color: var(--gray-600);
    padding: 10px;
    background: rgba(252,185,0,0.1);
    border-radius: 6px;
}
.td-commission-example strong { color: var(--black); }

/* Confirmation Banner - Compact */
.td-confirm-banner {
    background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
    border-radius: var(--r-sm);
    padding: 14px;
    color: var(--black);
    margin-bottom: 12px;
}

.td-confirm-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.td-confirm-icon { font-size: 18px; }
.td-confirm-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }

.td-confirm-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px;
    background: rgba(255,255,255,0.9);
    border-radius: 8px;
    margin-bottom: 6px;
}
.td-confirm-item:last-child { margin-bottom: 0; }

.td-confirm-info { flex: 1; min-width: 0; }
.td-confirm-name { font-weight: 600; font-size: 13px; }
.td-confirm-date { font-size: 11px; color: var(--gray-600); }

/* Availability Grid - Compact */
.td-avail-grid { display: flex; flex-direction: column; gap: 8px; }

.td-avail-day {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--gray-50);
    border-radius: 8px;
    flex-wrap: wrap;
}

.td-avail-name {
    width: 40px;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-700);
}

.td-avail-toggle {
    width: 44px;
    height: 26px;
    background: var(--gray-300);
    border-radius: 13px;
    position: relative;
    cursor: pointer;
    transition: background 0.2s;
    flex-shrink: 0;
}
.td-avail-toggle.on { background: var(--green); }

.td-avail-toggle::after {
    content: '';
    position: absolute;
    width: 22px;
    height: 22px;
    background: var(--white);
    border-radius: 50%;
    top: 2px;
    left: 2px;
    transition: transform 0.2s var(--ease);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.td-avail-toggle.on::after { transform: translateX(18px); }

.td-avail-times {
    display: flex;
    gap: 6px;
    align-items: center;
    flex: 1;
    min-width: 0;
}

.td-avail-time {
    flex: 1;
    min-width: 70px;
    max-width: 90px;
    padding: 8px;
    font-size: 13px;
    border: 2px solid var(--gray-200);
    border-radius: 6px;
    text-align: center;
    background: var(--white);
}
.td-avail-time:focus { border-color: var(--gold); outline: none; }
.td-avail-time:disabled { background: var(--gray-100); color: var(--gray-400); }

.td-avail-sep { color: var(--gray-400); font-size: 12px; font-weight: 500; }

/* v131: Schedule Prompt Banner */
.td-schedule-prompt {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 16px;
}
.td-schedule-prompt.inactive {
    background: linear-gradient(135deg, #FFF3CD 0%, #FFE69C 100%);
    border: 2px solid #FFCA2C;
}
.td-schedule-prompt.active {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    border: 2px solid #22C55E;
}
.td-prompt-icon {
    font-size: 24px;
    line-height: 1;
    flex-shrink: 0;
}
.td-prompt-content strong {
    display: block;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--black);
    margin-bottom: 4px;
}
.td-prompt-content p {
    margin: 0;
    font-size: 13px;
    color: var(--gray-700);
    line-height: 1.4;
}

/* v131: Open Training Dates */
.td-open-date-form {
    background: var(--gray-50);
    padding: 16px;
    border-radius: 8px;
}
.td-open-date-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.td-open-date-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 80px;
}
.td-open-date-field label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
}
.td-open-date-field input {
    padding: 10px;
    font-size: 14px;
}
.td-open-dates-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.td-open-date-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--gray-50);
    border-radius: 8px;
    border-left: 4px solid var(--gold);
}
.td-open-date-info {
    flex: 1;
    min-width: 0;
}
.td-open-date-day {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--black);
}
.td-open-date-time {
    font-size: 13px;
    color: var(--gray-600);
    margin-top: 2px;
}
.td-open-date-location {
    font-size: 12px;
    color: var(--gray-500);
    margin-top: 2px;
}
.td-open-date-remove {
    width: 32px;
    height: 32px;
    border: none;
    background: var(--gray-200);
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--gray-600);
    transition: all 0.15s;
    flex-shrink: 0;
}
.td-open-date-remove:hover {
    background: #FEE2E2;
    color: #DC2626;
}
.td-open-dates-empty {
    text-align: center;
    padding: 24px;
    color: var(--gray-500);
    font-size: 13px;
}
.td-badge {
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 3px 8px;
    border-radius: 4px;
}
.td-badge.gold {
    background: var(--gold);
    color: var(--black);
}

/* Messages - Compact */
.td-msg-item {
    display: flex;
    gap: 10px;
    padding: 12px;
    background: var(--gray-50);
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.15s;
    align-items: center;
}
.td-msg-item:active { transform: scale(0.99); background: var(--gray-100); }
.td-msg-item.unread { background: #FFF9E6; border-left: 3px solid var(--gold); }
.td-msg-item:last-child { margin-bottom: 0; }

.td-msg-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid var(--gold);
    object-fit: cover;
    flex-shrink: 0;
    background: var(--gray-200);
}

.td-msg-info { flex: 1; min-width: 0; }

.td-msg-name {
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
}

.td-msg-preview {
    font-size: 12px;
    color: var(--gray-600);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}

.td-msg-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex-shrink: 0;
}

.td-msg-time { font-size: 10px; color: var(--gray-400); }

.td-msg-unread {
    width: 10px;
    height: 10px;
    background: var(--gold);
    border-radius: 50%;
}

/* Profile - Compact */
.td-profile-photo-wrap {
    text-align: center;
    margin-bottom: 24px;
}

.td-profile-photo {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid var(--gold);
    object-fit: cover;
    display: block;
    margin: 0 auto 14px;
    background: var(--gray-200);
    box-shadow: 0 4px 20px rgba(252, 185, 0, 0.2);
}

.td-photo-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 12px 18px;
    background: var(--gray-100);
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    min-height: 44px;
}
.td-photo-btn:hover { background: var(--gray-200); }

/* Cover Photo - Compact */
.td-cover-photo-wrap {
    margin-bottom: 20px;
}
.td-cover-photo-label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--gray-600);
    margin-bottom: 6px;
}
.td-cover-photo-preview {
    width: 100%;
    aspect-ratio: 16/6;
    border-radius: 8px;
    border: 2px dashed var(--gray-300);
    object-fit: cover;
    display: block;
    margin-bottom: 10px;
    background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
    transition: border-color 0.2s;
}
.td-cover-photo-preview.has-image {
    border: 2px solid var(--gold);
}
.td-cover-photo-hint {
    font-size: 11px;
    color: var(--gray-500);
    margin-top: 6px;
}

.td-input-group { margin-bottom: 12px; }

.td-label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--gray-600);
    margin-bottom: 5px;
}

.td-input {
    width: 100%;
    padding: 12px 14px;
    font-size: 15px;
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    transition: border-color 0.2s;
    background: var(--white);
}
.td-input:focus { border-color: var(--gold); outline: none; }

.td-textarea {
    width: 100%;
    padding: 12px 14px;
    font-size: 15px;
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    min-height: 100px;
    resize: vertical;
    line-height: 1.5;
}
.td-textarea:focus { border-color: var(--gold); outline: none; }

.td-input-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

@media (max-width: 400px) {
    .td-input-row { grid-template-columns: 1fr; }
}

.td-section-title {
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-600);
    margin: 0 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--gray-100);
}

.td-specialty-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.td-specialty-item {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    background: var(--gray-50);
    border: 2px solid var(--gray-200);
    border-radius: 50px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s;
    user-select: none;
}
.td-specialty-item input { display: none; }
.td-specialty-item.selected {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--black);
    font-weight: 600;
}
.td-specialty-item:active { transform: scale(0.97); }

/* Empty State - Compact */
.td-empty {
    text-align: center;
    padding: 32px 16px;
    color: var(--gray-600);
}

.td-empty-icon {
    width: 56px;
    height: 56px;
    background: var(--gray-100);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}
.td-empty-icon svg { width: 26px; height: 26px; stroke: var(--gray-400); }
.td-empty h3 { font-size: 16px; margin-bottom: 6px; color: var(--gray-700); }
.td-empty p { font-size: 13px; margin: 0; line-height: 1.4; }

/* Stripe Connect Card - Compact */
.td-stripe-card {
    background: linear-gradient(135deg, #635BFF 0%, #8257E5 100%);
    border-radius: var(--r-sm);
    padding: 18px 16px;
    color: var(--white);
    margin-bottom: 12px;
}

.td-stripe-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.td-stripe-logo {
    width: 40px;
    height: 40px;
    background: var(--white);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #635BFF;
    font-size: 10px;
}

.td-stripe-title { font-size: 16px; }
.td-stripe-text { font-size: 13px; opacity: 0.9; margin-bottom: 14px; line-height: 1.4; }
.td-stripe-card .td-btn { background: var(--white); color: #635BFF; }

/* Share Profile */
.td-share-row {
    display: flex;
    gap: 8px;
}

.td-share-input {
    flex: 1;
    min-width: 0;
}

/* Bottom Nav - Compact */
.td-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--white);
    border-top: 1px solid var(--gray-200);
    padding: 6px 8px;
    padding-bottom: calc(6px + var(--safe-bottom));
    display: flex;
    justify-content: space-around;
    z-index: 1000;
    box-shadow: 0 -2px 16px rgba(0,0,0,0.06);
}

.td-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 8px 12px;
    color: var(--gray-400);
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    cursor: pointer;
    transition: color 0.15s;
    position: relative;
    min-width: 54px;
    -webkit-tap-highlight-color: transparent;
}

.td-nav-item.active { color: var(--gold); }
.td-nav-item svg { width: 22px; height: 22px; }

.td-nav-badge {
    position: absolute;
    top: 2px;
    right: 6px;
    min-width: 16px;
    height: 16px;
    background: var(--red);
    color: var(--white);
    font-size: 9px;
    font-weight: 700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 3px;
}

/* Toast - Compact */
.td-toast {
    position: fixed;
    bottom: calc(var(--nav-height) + var(--safe-bottom) + 12px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--black);
    color: var(--white);
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    z-index: 9999;
    animation: toastIn 0.2s var(--ease);
    max-width: calc(100% - 32px);
    text-align: center;
    box-shadow: var(--shadow-lg);
}

@keyframes toastIn {
    from { opacity: 0; transform: translateX(-50%) translateY(16px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* Loading Spinner */
.td-loading {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* Mobile Small (under 380px) */
@media (max-width: 380px) {
    .td-content { padding: 10px 10px; }
    .td-card { padding: 12px; }
    .td-header { padding: calc(var(--safe-top) + 10px) 12px 12px; }
    .td-name { font-size: 20px; }
    .td-stat { padding: 6px 10px; min-width: 60px; }
    .td-stat-value { font-size: 16px; }
    .td-stat-label { font-size: 7px; }
    .td-quick-action { padding: 8px 10px; }
    .td-quick-action span { font-size: 10px; }
    .td-quick-action svg { width: 16px; height: 16px; }
    .td-session-date { width: 40px; }
    .td-session-num { font-size: 18px; }
    .td-session-player { font-size: 13px; }
    .td-session-meta { font-size: 11px; }
    .td-session-amount { font-size: 14px; }
    .td-btn { min-height: 38px; padding: 0 12px; font-size: 11px; }
    .td-btn.sm { min-height: 34px; padding: 0 10px; font-size: 10px; }
    .td-payout-value { font-size: 22px; }
    .td-confirm-name { font-size: 12px; }
    .td-confirm-date { font-size: 10px; }
    .td-avail-day { padding: 8px 10px; gap: 8px; }
    .td-avail-name { width: 36px; font-size: 11px; }
    .td-avail-time { padding: 6px; font-size: 12px; min-width: 60px; max-width: 75px; }
    .td-avail-toggle { width: 40px; height: 24px; }
    .td-avail-toggle::after { width: 20px; height: 20px; }
    .td-avail-toggle.on::after { transform: translateX(16px); }
    .td-avail-sep { font-size: 11px; }
    .td-msg-avatar { width: 36px; height: 36px; }
    .td-msg-name { font-size: 12px; }
    .td-msg-preview { font-size: 11px; }
    .td-input { padding: 10px 12px; font-size: 14px; }
    .td-textarea { padding: 10px 12px; font-size: 14px; min-height: 80px; }
    .td-label { font-size: 9px; }
    .td-section-title { font-size: 11px; }
    .td-specialty-item { padding: 6px 10px; font-size: 11px; }
    .td-profile-photo { width: 100px; height: 100px; }
    .td-nav-item { padding: 6px 8px; min-width: 48px; }
    .td-nav-item svg { width: 20px; height: 20px; }
    .td-nav-item { font-size: 8px; }
    .td-location-item { padding: 10px !important; gap: 8px !important; }
    .td-location-item div[style*="font-weight:600"] { font-size: 13px !important; }
    .td-location-item div[style*="font-size:12px"] { font-size: 11px !important; }
    .td-stripe-card { padding: 14px 12px; }
    .td-stripe-title { font-size: 14px; }
    .td-stripe-text { font-size: 12px; }
    .td-stripe-logo { width: 36px; height: 36px; font-size: 9px; }
    .td-empty { padding: 24px 12px; }
    .td-empty-icon { width: 48px; height: 48px; }
    .td-empty h3 { font-size: 14px; }
    .td-empty p { font-size: 12px; }
    #addLocationForm { padding: 12px !important; }
    #addLocationForm > div:first-child { font-size: 11px !important; }
    
    /* v130.3: Profile section mobile fixes */
    .td-cover-photo-wrap { margin-bottom: 16px; }
    .td-cover-photo-preview { aspect-ratio: 16/5; }
    .td-cover-photo-hint { font-size: 10px; }
    .td-photo-btn { padding: 10px 14px; font-size: 12px; }
}

/* v130.3: Global profile overflow prevention */
#tab-profile {
    overflow-x: hidden;
    max-width: 100%;
}

#tab-profile .td-card {
    overflow-x: hidden;
    max-width: 100%;
    box-sizing: border-box;
}

#tab-profile .td-input,
#tab-profile .td-textarea,
#tab-profile select {
    max-width: 100%;
    box-sizing: border-box;
}

#tab-profile .td-input-row {
    overflow-x: hidden;
    max-width: 100%;
}

/* v130.3: Specialty grid mobile fixes */
@media (max-width: 480px) {
    .td-specialty-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .td-specialty-item {
        padding: 6px 10px;
        font-size: 11px;
        flex-shrink: 0;
        max-width: calc(50% - 4px);
        text-align: center;
        justify-content: center;
    }
    
    /* Input rows stack on mobile */
    .td-input-row {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .td-input-row .td-input-group {
        width: 100%;
    }
    
    /* Cover photo compact on mobile */
    .td-cover-photo-wrap {
        margin-bottom: 16px;
    }
    
    .td-cover-photo-preview {
        aspect-ratio: 16/6;
        border-radius: 6px;
    }
    
    /* Share URL input truncation */
    .td-share-input {
        font-size: 12px !important;
        text-overflow: ellipsis;
        overflow: hidden;
        white-space: nowrap;
    }
    
    /* Location items compact */
    .td-location-item {
        padding: 10px !important;
        gap: 8px !important;
        flex-wrap: wrap;
    }
    
    /* v131: Open dates form mobile */
    .td-open-date-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .td-open-date-field {
        width: 100%;
        min-width: 100%;
    }
    
    .td-open-date-row .td-btn {
        width: 100%;
        margin-top: 4px;
    }
    
    .td-open-date-item {
        padding: 10px 12px;
    }
    
    .td-schedule-prompt {
        padding: 12px;
        gap: 10px;
    }
    
    .td-prompt-icon {
        font-size: 20px;
    }
    
    .td-prompt-content strong {
        font-size: 13px;
    }
    
    .td-prompt-content p {
        font-size: 12px;
    }
}

/* v130.3: Extra small screens (under 360px) */
@media (max-width: 360px) {
    #tab-profile .td-card {
        padding: 10px;
    }
    
    .td-specialty-item {
        padding: 5px 8px;
        font-size: 10px;
        max-width: calc(50% - 3px);
    }
    
    .td-profile-photo {
        width: 90px;
        height: 90px;
    }
    
    .td-photo-btn {
        padding: 8px 12px;
        font-size: 11px;
    }
    
    .td-input {
        padding: 10px !important;
        font-size: 14px !important;
    }
    
    .td-textarea {
        padding: 10px !important;
        font-size: 14px !important;
        min-height: 70px !important;
    }
    
    .td-section-title {
        font-size: 10px;
    }
    
    .td-label {
        font-size: 9px;
    }
}

/* Mobile Medium (380-480px) */
@media (min-width: 381px) and (max-width: 480px) {
    .td-content { padding: 12px; }
    .td-stat { min-width: 65px; }
    .td-stat-value { font-size: 17px; }
}

/* Desktop Navigation */
.td-desktop-nav {
    display: none;
    background: var(--white);
    border-bottom: 1px solid var(--gray-200);
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.td-desktop-nav-inner {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 4px;
    max-width: 900px;
    margin: 0 auto;
    padding: 8px 16px;
}

.td-desktop-nav-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    font-family: 'Oswald', sans-serif;
    text-transform: uppercase;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.03em;
    color: var(--gray-500);
    cursor: pointer;
    border-radius: 8px;
    transition: all 0.15s ease;
    position: relative;
    white-space: nowrap;
}

.td-desktop-nav-item:hover {
    color: var(--gray-700);
    background: var(--gray-50);
}

.td-desktop-nav-item.active {
    color: var(--black);
    background: var(--gold-light);
}

.td-desktop-nav-item.active::after {
    content: '';
    position: absolute;
    bottom: -8px;
    left: 50%;
    transform: translateX(-50%);
    width: 24px;
    height: 3px;
    background: var(--gold);
    border-radius: 2px;
}

.td-desktop-nav-item svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.td-desktop-nav-badge {
    min-width: 18px;
    height: 18px;
    background: var(--red);
    color: var(--white);
    font-size: 10px;
    font-weight: 700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    margin-left: 4px;
}

/* View Profile CTA in desktop nav */
.td-desktop-nav-cta {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    text-transform: uppercase;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.03em;
    border-radius: 8px;
    margin-left: 12px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.td-desktop-nav-cta:hover {
    background: var(--gold-dark);
    transform: translateY(-1px);
}

.td-desktop-nav-cta svg {
    width: 16px;
    height: 16px;
}

/* Responsive Desktop */
@media (min-width: 768px) {
    .td-nav { display: none; }
    .td-desktop-nav { display: block; }
    .td { padding-bottom: 32px; }
    .td-content { padding: 24px 20px; }
    .td-stats { gap: 12px; }
    .td-stat { padding: 12px 16px; min-width: 90px; }
    .td-stat-value { font-size: 22px; }
}

@media (min-width: 1024px) {
    .td-desktop-nav-inner { gap: 8px; }
    .td-desktop-nav-item { padding: 12px 24px; font-size: 14px; }
}

/* iOS Fixes */
@supports (-webkit-touch-callout: none) {
    .td-input, .td-textarea, .td-avail-time {
        font-size: 16px; /* Prevent zoom on iOS */
    }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
    .td-btn:active { transform: scale(0.96); }
    .td-quick-action:active { transform: scale(0.96); background: var(--gold-light); border-color: var(--gold); }
    .td-session { -webkit-tap-highlight-color: transparent; }
    .td-msg-item:active { background: var(--gray-100); }
    .td-avail-toggle:active { opacity: 0.8; }
    .td-specialty-item:active { transform: scale(0.95); }
}

/* ===== MOBILE FIXES v130 ===== */
/* Force proper stacking and prevent horizontal overflow on mobile */
@media (max-width: 767px) {
    /* Ensure app container doesn't overflow */
    .td {
        width: 100%;
        max-width: 100vw;
        overflow-x: hidden;
    }
    
    /* Content container max width */
    .td-content {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow-x: hidden;
    }
    
    /* Force tabs to be full width block elements */
    .td-tab {
        display: none !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
    }
    .td-tab.active {
        display: block !important;
    }
    
    /* Force cards to stack vertically - CRITICAL FIX */
    .td-card {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        float: none !important;
        clear: both !important;
        overflow-x: hidden !important;
    }
    
    /* Availability grid container */
    .td-avail-grid {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
    }
    
    /* Availability row - stack on small screens */
    .td-avail-day {
        flex-wrap: wrap !important;
        gap: 8px !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* Availability times - allow wrapping */
    .td-avail-times {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        max-width: calc(100% - 100px) !important;
        justify-content: flex-start !important;
    }
    
    /* Time inputs - smaller on mobile */
    .td-avail-time {
        flex: 0 1 auto !important;
        min-width: 75px !important;
        max-width: 95px !important;
        width: auto !important;
        padding: 8px 4px !important;
    }
    
    /* Hourly rate section - ensure it wraps */
    .td-card > div[style*="display:flex"][style*="gap:12px"] {
        flex-wrap: wrap !important;
    }
    
    /* Block dates section */
    .td-card > div[style*="display:flex"][style*="gap:10px"] {
        flex-wrap: wrap !important;
    }
}

/* Extra small screens (under 400px) */
@media (max-width: 400px) {
    /* Availability - tighter layout */
    .td-avail-day {
        padding: 10px !important;
        gap: 6px !important;
    }
    
    .td-avail-name {
        width: 36px !important;
        font-size: 11px !important;
    }
    
    .td-avail-toggle {
        width: 40px !important;
        height: 24px !important;
    }
    
    .td-avail-times {
        max-width: calc(100% - 90px) !important;
    }
    
    .td-avail-time {
        min-width: 70px !important;
        max-width: 85px !important;
        padding: 6px 4px !important;
        font-size: 13px !important;
    }
    
    .td-avail-sep {
        font-size: 11px !important;
        padding: 0 2px !important;
    }
    
    /* Card header - stack if needed */
    .td-card-header {
        flex-wrap: wrap !important;
        gap: 8px !important;
    }
    
    /* Quick actions - ensure horizontal scroll works */
    .td-quick-actions {
        margin: 0 -12px 12px !important;
        padding: 0 12px 8px !important;
    }
}

/* Very small screens (under 360px) */
@media (max-width: 360px) {
    .td-content {
        padding: 10px 8px !important;
    }
    
    .td-card {
        padding: 12px 10px !important;
    }
    
    .td-avail-day {
        padding: 8px !important;
        gap: 4px !important;
    }
    
    .td-avail-time {
        min-width: 60px !important;
        max-width: 72px !important;
        font-size: 12px !important;
        padding: 5px 2px !important;
    }
    
    .td-avail-name {
        width: 32px !important;
        font-size: 10px !important;
    }
    
    .td-avail-times {
        max-width: calc(100% - 82px) !important;
    }
}

/* Google Places Autocomplete styling */
.pac-container {
    font-family: 'Inter', -apple-system, sans-serif;
    border-radius: 12px;
    border: none;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    margin-top: 4px;
    z-index: 99999;
}
.pac-item {
    padding: 12px 14px;
    font-size: 14px;
    cursor: pointer;
    border-top: 1px solid #f3f4f6;
}
.pac-item:first-child {
    border-top: none;
    border-radius: 12px 12px 0 0;
}
.pac-item:last-child {
    border-radius: 0 0 12px 12px;
}
.pac-item:hover, .pac-item-selected {
    background: #FFFBEB;
}
.pac-item-query {
    font-weight: 600;
    color: #0A0A0A;
}
.pac-icon {
    margin-right: 10px;
}
.pac-matched {
    font-weight: 700;
}
</style>
</head>
<body style="margin: 0; padding: 0; overflow-y: scroll !important; height: auto !important; position: static !important;">
<script>
// v133.2.1: Force scroll to work
(function(){
    document.documentElement.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important;';
    document.body.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important; margin: 0; padding: 0;';
    document.body.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
    document.documentElement.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
})();
</script>
<div id="ptp-scroll-wrapper" style="width: 100%;">
<div class="td">
    <!-- Header -->
    <header class="td-header">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <a href="<?php echo home_url('/'); ?>" style="display:inline-flex;align-items:center;gap:4px;color:var(--gray-400);font-size:11px;text-decoration:none;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                PTP
            </a>
            <a href="<?php echo $profile_url; ?>" style="display:inline-flex;align-items:center;gap:4px;color:var(--gold);font-size:11px;text-decoration:none;">
                Profile
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
            </a>
        </div>
        <div class="td-header-top" onclick="switchToTab('home')" style="cursor:pointer;">
            <div>
                <p class="td-greeting">Welcome back</p>
                <h1 class="td-name"><span><?php echo esc_html(strtoupper($first_name)); ?></span></h1>
            </div>
            <img src="<?php echo esc_url($trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=100&background=FCB900&color=0A0A0A&bold=true'); ?>" alt="Profile" class="td-avatar">
        </div>
        
        <div class="td-stats">
            <div class="td-stat">
                <div class="td-stat-value" style="color:var(--gold);">$<?php echo $hourly_rate; ?></div>
                <div class="td-stat-label">Per Session</div>
            </div>
            <div class="td-stat">
                <div class="td-stat-value green">$<?php echo number_format($week_earnings); ?></div>
                <div class="td-stat-label">Week</div>
            </div>
            <div class="td-stat">
                <div class="td-stat-value"><?php echo count($upcoming); ?></div>
                <div class="td-stat-label">Upcoming</div>
            </div>
            <div class="td-stat">
                <div class="td-stat-value"><?php echo $total_sessions; ?></div>
                <div class="td-stat-label">Total</div>
            </div>
        </div>
    </header>
    
    <!-- Desktop Navigation -->
    <nav class="td-desktop-nav">
        <div class="td-desktop-nav-inner">
            <div class="td-desktop-nav-item active" data-tab="home">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Dashboard
            </div>
            <div class="td-desktop-nav-item" data-tab="schedule">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Schedule
            </div>
            <div class="td-desktop-nav-item" data-tab="earnings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Earnings
            </div>
            <div class="td-desktop-nav-item" data-tab="messages">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Messages
                <?php if ($unread_count > 0): ?>
                <span class="td-desktop-nav-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </div>
            <div class="td-desktop-nav-item" data-tab="profile">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </div>
            <a href="<?php echo esc_url($profile_url); ?>" class="td-desktop-nav-cta" target="_blank">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                View Profile
            </a>
        </div>
    </nav>
    
    <!-- Content -->
    <main class="td-content">
        <!-- HOME TAB -->
        <div class="td-tab active" id="tab-home">
            <!-- Quick Actions -->
            <div class="td-quick-actions">
                <button class="td-quick-action" onclick="switchToTab('profile')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span>Edit</span>
                </button>
                <button class="td-quick-action" onclick="switchToTab('messages')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>Messages</span>
                    <?php if ($unread_count > 0): ?><span class="td-quick-badge"><?php echo $unread_count; ?></span><?php endif; ?>
                </button>
                <button class="td-quick-action" onclick="switchToTab('earnings')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <span>Payouts</span>
                </button>
                <button class="td-quick-action" onclick="switchToTab('schedule')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Availability</span>
                </button>
            </div>
            
            <?php if ($pending_payout >= 10): ?>
            <div class="td-payout">
                <div class="td-payout-row">
                    <div>
                        <div class="td-payout-label">Available for Payout</div>
                        <div class="td-payout-value">$<?php echo number_format($pending_payout, 2); ?></div>
                    </div>
                    <button class="td-btn" onclick="requestPayout()">Request Payout</button>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($needs_confirmation)): ?>
            <div class="td-confirm-banner">
                <div class="td-confirm-header">
                    <span class="td-confirm-icon">⚡</span>
                    <span class="td-confirm-title">Sessions Need Confirmation</span>
                </div>
                <?php foreach ($needs_confirmation as $s): ?>
                <div class="td-confirm-item">
                    <div class="td-confirm-info">
                        <div class="td-confirm-name"><?php echo esc_html($s->player_name ?: 'Player'); ?></div>
                        <div class="td-confirm-date"><?php echo date('D, M j', strtotime($s->session_date)); ?> • <?php echo esc_html($s->parent_name); ?></div>
                    </div>
                    <button class="td-btn sm green" onclick="confirmSession(<?php echo $s->id; ?>, this)">Confirm</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Upcoming Sessions</h3>
                    <a href="<?php echo $profile_url; ?>" class="td-btn sm outline">View Profile</a>
                </div>
                <?php if (!empty($upcoming)): ?>
                    <?php foreach ($upcoming as $s): 
                        $time_fmt = $s->start_time ? date('g:i A', strtotime($s->start_time)) : '';
                    ?>
                    <div class="td-session">
                        <div class="td-session-date">
                            <div class="td-session-day"><?php echo date('D', strtotime($s->session_date)); ?></div>
                            <div class="td-session-num"><?php echo date('j', strtotime($s->session_date)); ?></div>
                        </div>
                        <div class="td-session-info">
                            <div class="td-session-player"><?php echo esc_html($s->player_name ?: 'Player'); ?></div>
                            <div class="td-session-meta">
                                <?php echo $time_fmt; ?><?php if ($s->location): ?> · <?php echo esc_html($s->location); ?><?php endif; ?><?php if ($s->parent_name): ?> · <?php echo esc_html($s->parent_name); ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="td-session-right">
                            <div class="td-session-amount">$<?php echo number_format($s->trainer_payout ?: 0); ?></div>
                            <div class="td-session-status <?php echo $s->status; ?>"><?php echo ucfirst($s->status); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="td-empty">
                    <div class="td-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <h3>No Upcoming Sessions</h3>
                    <p>Share your profile link to get more bookings!</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Share Profile -->
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Share Your Profile</h3>
                </div>
                <div class="td-share-row">
                    <input type="text" id="shareUrl" value="<?php echo esc_attr($profile_url); ?>" class="td-input td-share-input" readonly>
                    <button class="td-btn" onclick="copyLink()">Copy</button>
                </div>
            </div>
        </div>
        
        <!-- SCHEDULE TAB -->
        <div class="td-tab" id="tab-schedule">
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Weekly Availability</h3>
                    <button class="td-btn sm" id="saveAvailBtn" onclick="saveAvailability()">Save</button>
                </div>
                <div class="td-avail-grid">
                    <?php 
                    $days = array('monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun');
                    foreach ($days as $key => $label):
                        $day_data = $availability[$key] ?? array('enabled' => false, 'start' => '09:00', 'end' => '17:00');
                    ?>
                    <div class="td-avail-day">
                        <div class="td-avail-name"><?php echo $label; ?></div>
                        <div class="td-avail-toggle <?php echo $day_data['enabled'] ? 'on' : ''; ?>" 
                             data-day="<?php echo $key; ?>" 
                             onclick="toggleDay(this)"></div>
                        <div class="td-avail-times">
                            <input type="time" class="td-avail-time" 
                                   id="start_<?php echo $key; ?>" 
                                   value="<?php echo esc_attr($day_data['start']); ?>"
                                   <?php echo !$day_data['enabled'] ? 'disabled' : ''; ?>>
                            <span class="td-avail-sep">to</span>
                            <input type="time" class="td-avail-time" 
                                   id="end_<?php echo $key; ?>" 
                                   value="<?php echo esc_attr($day_data['end']); ?>"
                                   <?php echo !$day_data['enabled'] ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- v131: Schedule Status Prompt -->
            <?php
            // Check if trainer has any availability set
            $has_availability = false;
            foreach ($availability as $day => $data) {
                if (!empty($data['enabled'])) {
                    $has_availability = true;
                    break;
                }
            }
            
            // Check for open dates in next 30 days
            $open_dates_table = $wpdb->prefix . 'ptp_open_dates';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$open_dates_table'") === $open_dates_table;
            $upcoming_open_dates = 0;
            if ($table_exists) {
                $upcoming_open_dates = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $open_dates_table 
                     WHERE trainer_id = %d AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
                    $trainer->id
                ));
            }
            ?>
            <div class="td-schedule-prompt <?php echo ($has_availability || $upcoming_open_dates > 0) ? 'active' : 'inactive'; ?>">
                <?php if (!$has_availability && $upcoming_open_dates == 0): ?>
                    <div class="td-prompt-icon">📅</div>
                    <div class="td-prompt-content">
                        <strong>Set Your Availability</strong>
                        <p>Parents can't book you yet! Add your weekly hours or open specific dates below.</p>
                    </div>
                <?php elseif ($upcoming_open_dates == 0): ?>
                    <div class="td-prompt-icon">✨</div>
                    <div class="td-prompt-content">
                        <strong>Add Specific Training Dates</strong>
                        <p>Boost bookings by opening specific dates. Families love seeing your upcoming availability!</p>
                    </div>
                <?php else: ?>
                    <div class="td-prompt-icon">🟢</div>
                    <div class="td-prompt-content">
                        <strong>Schedule Active</strong>
                        <p>You have <?php echo $upcoming_open_dates; ?> open date<?php echo $upcoming_open_dates > 1 ? 's' : ''; ?> in the next 30 days. Keep it up!</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- v131: Open Training Dates -->
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Open Training Dates</h3>
                    <span class="td-badge gold">NEW</span>
                </div>
                <p style="font-size:13px;color:var(--gray-600);margin:0 0 16px;line-height:1.5;">
                    Add specific dates you're available to train. These show on your profile and help families find you!
                </p>
                
                <div class="td-open-date-form">
                    <div class="td-open-date-row">
                        <div class="td-open-date-field">
                            <label>Date</label>
                            <input type="date" id="openDate" class="td-input" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="td-open-date-field">
                            <label>From</label>
                            <input type="time" id="openTimeStart" class="td-input" value="09:00">
                        </div>
                        <div class="td-open-date-field">
                            <label>To</label>
                            <input type="time" id="openTimeEnd" class="td-input" value="17:00">
                        </div>
                    </div>
                    <div class="td-open-date-row" style="margin-top:12px;">
                        <div class="td-open-date-field" style="flex:1;">
                            <label>Location (optional)</label>
                            <input type="text" id="openLocation" class="td-input" placeholder="e.g., Villanova turf fields">
                        </div>
                        <button class="td-btn" onclick="addOpenDate()">Add Date</button>
                    </div>
                </div>
                
                <div id="openDatesList" class="td-open-dates-list" style="margin-top:20px;">
                    <!-- Populated by JS -->
                </div>
            </div>
            
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Your Hourly Rate</h3>
                </div>
                <div style="display:flex;gap:12px;align-items:center;">
                    <span style="font-size:22px;font-weight:700;">$</span>
                    <input type="number" id="hourlyRate" value="<?php echo intval($trainer->hourly_rate ?: 60); ?>" class="td-input" style="width:100px;text-align:center;font-size:18px;font-weight:600;">
                    <span style="color:var(--gray-600);font-size:14px;">/hour</span>
                    <button class="td-btn sm" style="margin-left:auto;" onclick="saveRate()">Update</button>
                </div>
            </div>
            
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Block Specific Dates</h3>
                </div>
                <p style="font-size:13px;color:var(--gray-600);margin:0 0 16px;line-height:1.5;">Block days you're unavailable (vacation, appointments, etc.)</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <input type="date" id="blockDate" class="td-input" style="flex:1;min-width:150px;" min="<?php echo date('Y-m-d'); ?>">
                    <button class="td-btn sm" onclick="blockDate()">Block Date</button>
                </div>
                <div id="blockedDates" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:10px;"></div>
            </div>
        </div>
        
        <!-- EARNINGS TAB -->
        <div class="td-tab" id="tab-earnings">
            <div class="td-payout" style="background:linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);color:var(--black);">
                <div class="td-payout-row">
                    <div>
                        <div class="td-payout-label" style="opacity:0.7;">Total Earned</div>
                        <div class="td-payout-value">$<?php echo number_format($total_earnings, 2); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Commission Structure Info -->
            <div class="td-card td-commission-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">How You Earn</h3>
                </div>
                <div class="td-commission-tiers">
                    <div class="td-commission-tier first">
                        <div class="td-commission-icon">🎯</div>
                        <div class="td-commission-info">
                            <div class="td-commission-label">First Session</div>
                            <div class="td-commission-desc">with a new client</div>
                        </div>
                        <div class="td-commission-rate">
                            <strong>50%</strong>
                            <small>You keep</small>
                        </div>
                    </div>
                    <div class="td-commission-tier repeat">
                        <div class="td-commission-icon">🔥</div>
                        <div class="td-commission-info">
                            <div class="td-commission-label">Repeat Sessions</div>
                            <div class="td-commission-desc">same client returns</div>
                        </div>
                        <div class="td-commission-rate">
                            <strong>75%</strong>
                            <small>You keep</small>
                        </div>
                    </div>
                </div>
                <div class="td-commission-example">
                    <strong>Example:</strong> $60/hr rate → First session you earn $30, repeat sessions you earn $45
                </div>
            </div>
            
            <?php if (!$stripe_complete): ?>
            <div class="td-stripe-card">
                <div class="td-stripe-header">
                    <div class="td-stripe-logo">STRIPE</div>
                    <h3 class="td-stripe-title">Get Paid Faster</h3>
                </div>
                <p class="td-stripe-text">Connect your bank account through Stripe to receive direct deposits for your training sessions.</p>
                <button class="td-btn full" onclick="connectStripe()">Connect Stripe Account</button>
            </div>
            <?php else: ?>
            <div class="td-card">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:48px;height:48px;background:var(--green-light);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:15px;">Stripe Connected</div>
                        <div style="font-size:13px;color:var(--gray-600);">You're all set to receive payouts</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Completed Sessions</h3>
                </div>
                <?php if (!empty($completed_sessions)): ?>
                    <?php foreach ($completed_sessions as $s): ?>
                    <div class="td-session">
                        <div class="td-session-date">
                            <div class="td-session-day"><?php echo date('M', strtotime($s->session_date)); ?></div>
                            <div class="td-session-num"><?php echo date('j', strtotime($s->session_date)); ?></div>
                        </div>
                        <div class="td-session-info">
                            <div class="td-session-player"><?php echo esc_html($s->player_name ?: 'Player'); ?></div>
                            <div class="td-session-meta"><?php echo $s->payout_status === 'paid' ? '✓ Paid out' : 'Pending payout'; ?></div>
                        </div>
                        <div class="td-session-right">
                            <div class="td-session-amount">$<?php echo number_format($s->trainer_payout ?: 0); ?></div>
                            <div class="td-session-status completed"><?php echo $s->payout_status === 'paid' ? 'Paid' : 'Pending'; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="td-empty">
                    <p>No completed sessions yet</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- MESSAGES TAB -->
        <div class="td-tab" id="tab-messages">
            <div class="td-card">
                <div class="td-card-header">
                    <h3 class="td-card-title">Messages</h3>
                </div>
                <?php if (!empty($conversations)): ?>
                    <?php foreach ($conversations as $c): 
                        $avatar = !empty($c->other_photo) ? $c->other_photo : 'https://ui-avatars.com/api/?name=' . urlencode($c->other_name ?: 'P') . '&size=96&background=FCB900&color=0A0A0A&bold=true';
                    ?>
                    <div class="td-msg-item <?php echo !empty($c->unread) ? 'unread' : ''; ?>" 
                         onclick="openConversation(<?php echo $c->id; ?>)">
                        <img src="<?php echo esc_url($avatar); ?>" alt="" class="td-msg-avatar">
                        <div class="td-msg-info">
                            <div class="td-msg-name"><?php echo esc_html($c->other_name ?: 'Parent'); ?></div>
                            <div class="td-msg-preview"><?php echo esc_html($c->last_message ?: 'No messages yet'); ?></div>
                        </div>
                        <div class="td-msg-meta">
                            <div class="td-msg-time"><?php echo $c->updated_at ? human_time_diff(strtotime($c->updated_at)) . ' ago' : ''; ?></div>
                            <?php if (!empty($c->unread)): ?>
                            <div class="td-msg-unread"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="td-empty">
                    <div class="td-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <h3>No Messages Yet</h3>
                    <p>Messages from parents will appear here once they contact you.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- PROFILE TAB -->
        <div class="td-tab" id="tab-profile">
            <div class="td-card">
                <div class="td-profile-photo-wrap">
                    <img src="<?php echo esc_url($trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=240&background=FCB900&color=0A0A0A&bold=true'); ?>" 
                         alt="Profile" class="td-profile-photo" id="profilePhotoPreview">
                    <label class="td-photo-btn">
                        <input type="file" id="profilePhotoInput" accept="image/*" style="display:none;" onchange="handlePhotoSelect(this)">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;vertical-align:-2px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Change Photo
                    </label>
                </div>
                
                <!-- Cover Photo Upload -->
                <div class="td-cover-photo-wrap">
                    <label class="td-cover-photo-label">Action Photo (appears behind your profile)</label>
                    <img src="<?php echo esc_url($trainer->cover_photo_url ?: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="800" height="300"><rect fill="%23e5e7eb" width="800" height="300"/><text x="400" y="160" font-family="Arial" font-size="16" fill="%239ca3af" text-anchor="middle">Cover Photo</text></svg>'); ?>" 
                         alt="Cover Photo" class="td-cover-photo-preview<?php echo $trainer->cover_photo_url ? ' has-image' : ''; ?>" id="coverPhotoPreview">
                    <label class="td-photo-btn">
                        <input type="file" id="coverPhotoInput" accept="image/*" style="display:none;" onchange="handleCoverPhotoSelect(this)">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;vertical-align:-2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        Upload Cover Photo
                    </label>
                    <p class="td-cover-photo-hint">Recommended: Landscape photo of you training or playing</p>
                </div>
                
                <div class="td-input-group">
                    <label class="td-label">Display Name *</label>
                    <input type="text" id="profileDisplayName" value="<?php echo esc_attr($trainer->display_name); ?>" class="td-input" required>
                </div>
                
                <div class="td-input-group">
                    <label class="td-label">Headline</label>
                    <input type="text" id="profileHeadline" value="<?php echo esc_attr($trainer->headline); ?>" class="td-input" placeholder="e.g. Professional MLS Player, Union Academy Graduate">
                </div>
                
                <div class="td-input-group">
                    <label class="td-label">Bio</label>
                    <textarea id="profileBio" class="td-textarea" placeholder="Tell families about your playing experience and coaching philosophy..."><?php echo esc_textarea($trainer->bio); ?></textarea>
                </div>
            </div>
            
            <div class="td-card">
                <h4 class="td-section-title">Background & Experience</h4>
                
                <div class="td-input-row">
                    <div class="td-input-group">
                        <label class="td-label">College/University</label>
                        <input type="text" id="profileCollege" value="<?php echo esc_attr($trainer->college); ?>" class="td-input" placeholder="e.g. Villanova University">
                    </div>
                    <div class="td-input-group">
                        <label class="td-label">Team/Club</label>
                        <input type="text" id="profileTeam" value="<?php echo esc_attr($trainer->team); ?>" class="td-input" placeholder="e.g. Philadelphia Union">
                    </div>
                </div>
                
                <div class="td-input-row">
                    <div class="td-input-group">
                        <label class="td-label">Position</label>
                        <input type="text" id="profilePosition" value="<?php echo esc_attr($trainer->position); ?>" class="td-input" placeholder="e.g. Midfielder">
                    </div>
                    <div class="td-input-group">
                        <label class="td-label">Playing Level</label>
                        <select id="profilePlayingLevel" class="td-input">
                            <option value="">Select...</option>
                            <option value="professional" <?php selected($trainer->playing_level, 'professional'); ?>>Professional</option>
                            <option value="college_d1" <?php selected($trainer->playing_level, 'college_d1'); ?>>College D1</option>
                            <option value="college_d2" <?php selected($trainer->playing_level, 'college_d2'); ?>>College D2</option>
                            <option value="college_d3" <?php selected($trainer->playing_level, 'college_d3'); ?>>College D3</option>
                            <option value="academy" <?php selected($trainer->playing_level, 'academy'); ?>>MLS Academy</option>
                            <option value="semi_pro" <?php selected($trainer->playing_level, 'semi_pro'); ?>>Semi-Pro</option>
                        </select>
                    </div>
                </div>
                
                <div class="td-input-group">
                    <label class="td-label">Specialties</label>
                    <div class="td-specialty-grid" id="specialtyGrid">
                        <?php 
                        $all_specialties = array('Dribbling', 'Shooting', 'Passing', 'First Touch', 'Ball Control', 'Speed & Agility', '1v1 Moves', 'Defending', 'Goalkeeping', 'Game IQ', 'Positioning', 'Fitness');
                        foreach ($all_specialties as $spec):
                            $checked = in_array($spec, $trainer_specs);
                        ?>
                        <label class="td-specialty-item <?php echo $checked ? 'selected' : ''; ?>">
                            <input type="checkbox" name="specialties[]" value="<?php echo esc_attr($spec); ?>" <?php checked($checked); ?> onchange="toggleSpecialty(this)">
                            <?php echo esc_html($spec); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="td-card">
                <h4 class="td-section-title">Contact & Location</h4>
                
                <div class="td-input-group">
                    <label class="td-label">Phone</label>
                    <input type="tel" id="profilePhone" value="<?php echo esc_attr($trainer->phone); ?>" class="td-input" placeholder="(555) 555-5555">
                </div>
                
                <div class="td-input-row">
                    <div class="td-input-group">
                        <label class="td-label">City</label>
                        <input type="text" id="profileCity" value="<?php echo esc_attr($trainer->city); ?>" class="td-input" placeholder="e.g. Philadelphia">
                    </div>
                    <div class="td-input-group">
                        <label class="td-label">State</label>
                        <select id="profileState" class="td-input">
                            <option value="">Select...</option>
                            <option value="PA" <?php selected($trainer->state, 'PA'); ?>>Pennsylvania</option>
                            <option value="NJ" <?php selected($trainer->state, 'NJ'); ?>>New Jersey</option>
                            <option value="DE" <?php selected($trainer->state, 'DE'); ?>>Delaware</option>
                            <option value="MD" <?php selected($trainer->state, 'MD'); ?>>Maryland</option>
                            <option value="NY" <?php selected($trainer->state, 'NY'); ?>>New York</option>
                        </select>
                    </div>
                </div>
                
                <div class="td-input-group">
                    <label class="td-label">Travel Radius (miles)</label>
                    <input type="number" id="profileTravelRadius" value="<?php echo intval($trainer->travel_radius ?: 15); ?>" class="td-input" min="1" max="50" style="width:120px;">
                </div>
            </div>
            
            <!-- Training Locations -->
            <div class="td-card">
                <h4 class="td-section-title"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:8px;vertical-align:-3px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>Training Locations</h4>
                <p style="font-size:13px;color:var(--gray-600);margin-bottom:16px;">Add locations where you're available to train (parks, fields, gyms, etc.)</p>
                
                <div id="trainingLocations" style="margin-bottom:16px;">
                    <?php if (!empty($training_locations)): ?>
                        <?php foreach ($training_locations as $i => $loc): ?>
                        <div class="td-location-item" data-index="<?php echo $i; ?>" data-lat="<?php echo esc_attr($loc['lat'] ?? ''); ?>" data-lng="<?php echo esc_attr($loc['lng'] ?? ''); ?>" style="display:flex;align-items:center;gap:10px;padding:14px;background:var(--gray-50);border-radius:var(--r-sm);margin-bottom:10px;">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <div style="flex:1;">
                                <div style="font-weight:600;font-size:14px;"><?php echo esc_html($loc['name'] ?? 'Location'); ?></div>
                                <div style="font-size:12px;color:var(--gray-600);"><?php echo esc_html($loc['address'] ?? ''); ?></div>
                            </div>
                            <button type="button" class="td-btn sm outline" onclick="removeLocation(<?php echo $i; ?>)" style="padding:8px;min-height:44px;">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div id="addLocationForm" style="background:var(--gray-50);border-radius:var(--r-sm);padding:16px;border:2px dashed var(--gray-200);">
                    <div style="font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:12px;color:var(--gray-700);">Add New Location</div>
                    <div class="td-input-group" style="margin-bottom:12px;">
                        <label class="td-label">Location Name *</label>
                        <input type="text" id="newLocationName" class="td-input" placeholder="e.g. Memorial Park, YSC Sports">
                    </div>
                    <div class="td-input-group" style="margin-bottom:12px;">
                        <label class="td-label">Address <span style="color:var(--gray-400);font-weight:400;font-size:11px;">(start typing to search)</span></label>
                        <input type="text" id="newLocationAddress" class="td-input" placeholder="Search for address..." autocomplete="off">
                    </div>
                    <button type="button" class="td-btn sm" onclick="addLocation()">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Location
                    </button>
                </div>
            </div>
            
            <div class="td-card">
                <h4 class="td-section-title">Social Media</h4>
                <div class="td-input-group">
                    <label class="td-label">Instagram Handle</label>
                    <div style="display:flex;align-items:center;gap:0;">
                        <span style="padding:14px 16px;background:var(--gray-50);border:2px solid var(--gray-200);border-right:none;border-radius:var(--r-sm) 0 0 var(--r-sm);color:var(--gray-500);font-size:16px;">@</span>
                        <input type="text" id="profileInstagram" value="<?php echo esc_attr($trainer->instagram); ?>" class="td-input" placeholder="username" style="border-radius:0 var(--r-sm) var(--r-sm) 0;">
                    </div>
                </div>
            </div>
            
            <!-- Google Calendar Sync -->
            <div class="td-card">
                <h4 class="td-section-title"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:8px;vertical-align:-3px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Calendar Sync</h4>
                <p style="font-size:13px;color:var(--gray-600);margin-bottom:16px;">Sync your sessions with Google Calendar or your phone's calendar to stay organized.</p>
                
                <div id="gcalStatus" style="margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:12px;padding:16px;background:var(--gray-50);border-radius:var(--r-sm);">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="var(--gray-400)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <div style="flex:1;">
                            <div style="font-weight:600;color:var(--black);font-size:14px;" id="gcalStatusText">Checking status...</div>
                            <div style="font-size:12px;color:var(--gray-500);" id="gcalStatusSub">Sessions will appear in your Google Calendar</div>
                        </div>
                    </div>
                </div>
                
                <div id="gcalActions">
                    <button class="td-btn outline full" id="gcalConnectBtn" onclick="connectGoogleCalendar()" style="display:none;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Connect Google Calendar
                    </button>
                    <div id="gcalConnected" style="display:none;">
                        <button class="td-btn green full" onclick="syncGoogleCalendar()" style="margin-bottom:10px;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                            Sync Now
                        </button>
                        <button class="td-btn outline full" onclick="disconnectGoogleCalendar()" style="color:var(--red);border-color:var(--red);">
                            Disconnect Calendar
                        </button>
                    </div>
                </div>
                
                <!-- Apple Calendar / Phone Calendar ICS -->
                <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--gray-200);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--gray-600)" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
                        <span style="font-weight:600;font-size:13px;color:var(--black);">iPhone / Apple Calendar</span>
                    </div>
                    <p style="font-size:12px;color:var(--gray-500);margin-bottom:12px;">Subscribe to your calendar to see sessions on any device. Works with iPhone, iPad, Mac Calendar, and Outlook.</p>
                    <div id="icsUrlContainer" style="display:flex;gap:8px;">
                        <input type="text" id="icsUrl" readonly style="flex:1;padding:10px 12px;border:2px solid var(--gray-200);border-radius:var(--r-sm);font-size:12px;background:var(--gray-50);color:var(--gray-600);" value="Loading...">
                        <button class="td-btn outline" onclick="copyIcsUrl()" style="padding:10px 14px;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                    </div>
                    <p style="font-size:11px;color:var(--gray-400);margin-top:8px;">
                        <strong>iPhone:</strong> Copy URL → Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar
                    </p>
                </div>
            </div>
            
            <button class="td-btn full" style="padding:18px 24px;font-size:14px;" onclick="saveProfileFull()">
                Save All Changes
            </button>
            
            <a href="<?php echo home_url('/trainer-onboarding/'); ?>" class="td-btn outline full" style="margin-top:12px;">
                Advanced Settings
            </a>
        </div>
    </main>
    
    <!-- Bottom Nav -->
    <nav class="td-nav">
        <div class="td-nav-item active" data-tab="home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Home
        </div>
        <div class="td-nav-item" data-tab="schedule">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Schedule
        </div>
        <div class="td-nav-item" data-tab="earnings">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Earnings
        </div>
        <div class="td-nav-item" data-tab="messages">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?php if ($unread_count > 0): ?>
            <span class="td-nav-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
            Chat
        </div>
        <div class="td-nav-item" data-tab="profile">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profile
        </div>
    </nav>
</div>

<?php 
// Load Google Maps for geocoding training locations
$google_maps_key = get_option('ptp_google_maps_api_key', '');
if ($google_maps_key): 
?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&libraries=places" async defer></script>
<?php endif; ?>

<script>
var nonce = '<?php echo $nonce; ?>';
var nonceGeneral = '<?php echo $nonce_general; ?>';
var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
var trainerId = <?php echo $trainer->id; ?>;

// Google Places Autocomplete for training location address
var locationAutocomplete = null;
var selectedPlaceData = { lat: '', lng: '', address: '' };

function initLocationAutocomplete() {
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
    
    var input = document.getElementById('newLocationAddress');
    if (!input || input.dataset.autocompleteInit) return;
    
    input.dataset.autocompleteInit = 'true';
    
    locationAutocomplete = new google.maps.places.Autocomplete(input, {
        types: ['establishment', 'geocode'],
        componentRestrictions: { country: 'us' },
        fields: ['formatted_address', 'geometry', 'name']
    });
    
    locationAutocomplete.addListener('place_changed', function() {
        var place = locationAutocomplete.getPlace();
        if (place && place.geometry) {
            selectedPlaceData.lat = place.geometry.location.lat();
            selectedPlaceData.lng = place.geometry.location.lng();
            selectedPlaceData.address = place.formatted_address || input.value;
            
            // If location name is empty, auto-fill with place name
            var nameInput = document.getElementById('newLocationName');
            if (nameInput && !nameInput.value.trim() && place.name) {
                nameInput.value = place.name;
            }
        }
    });
}

// Try to init autocomplete after Google Maps loads
if (typeof google !== 'undefined' && google.maps) {
    initLocationAutocomplete();
} else {
    window.addEventListener('load', function() {
        setTimeout(initLocationAutocomplete, 500);
    });
}

// Tab switching function
function switchToTab(tab) {
    // Map common aliases to actual tab names
    var tabMap = {
        'edit': 'profile',
        'profile': 'profile',
        'messages': 'messages',
        'chat': 'messages',
        'payouts': 'earnings',
        'earnings': 'earnings',
        'payout': 'earnings',
        'availability': 'schedule',
        'schedule': 'schedule',
        'calendar': 'schedule',
        'home': 'home',
        'overview': 'home'
    };
    
    var actualTab = tabMap[tab.toLowerCase()] || 'home';
    
    // Update both mobile and desktop nav items
    document.querySelectorAll('.td-nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.querySelectorAll('.td-desktop-nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.querySelectorAll('.td-tab').forEach(function(t) { t.classList.remove('active'); });
    
    var navItem = document.querySelector('.td-nav-item[data-tab="' + actualTab + '"]');
    var desktopNavItem = document.querySelector('.td-desktop-nav-item[data-tab="' + actualTab + '"]');
    var tabPanel = document.getElementById('tab-' + actualTab);
    
    if (navItem) navItem.classList.add('active');
    if (desktopNavItem) desktopNavItem.classList.add('active');
    if (tabPanel) tabPanel.classList.add('active');
    
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    // Update URL without reload
    if (history.replaceState) {
        history.replaceState(null, null, '?tab=' + actualTab);
    }
}

// Handle initial tab from URL params or hash
function handleInitialTab() {
    var urlParams = new URLSearchParams(window.location.search);
    var tab = urlParams.get('tab');
    
    // Also check hash (e.g., #messages, #payouts)
    if (!tab && window.location.hash) {
        tab = window.location.hash.replace('#', '');
    }
    
    if (tab) {
        switchToTab(tab);
    }
}

// Tab click handlers - Mobile
document.querySelectorAll('.td-nav-item').forEach(function(item) {
    item.addEventListener('click', function() {
        switchToTab(this.dataset.tab);
    });
});

// Tab click handlers - Desktop
document.querySelectorAll('.td-desktop-nav-item').forEach(function(item) {
    item.addEventListener('click', function() {
        switchToTab(this.dataset.tab);
    });
});

// Handle browser back/forward
window.addEventListener('popstate', handleInitialTab);

// Initialize on load
handleInitialTab();

function showToast(msg) {
    var existing = document.querySelector('.td-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'td-toast';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3000);
}

function copyLink() {
    var input = document.getElementById('shareUrl');
    input.select();
    input.setSelectionRange(0, 99999);
    if (navigator.clipboard) {
        navigator.clipboard.writeText(input.value).then(function() { showToast('Link copied! ✓'); });
    } else {
        document.execCommand('copy');
        showToast('Link copied! ✓');
    }
}

function confirmSession(bookingId, btn) {
    if (!confirm('Mark this session as completed? The parent will be notified and payment will be released after 24 hours (or when they confirm).')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="td-loading"></span>';
    
    // Use escrow system for proper payout flow
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_trainer_complete_session&booking_id=' + bookingId + '&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Session completed! Payment will be released in 24 hours. ✓');
            btn.parentElement.parentElement.style.display = 'none';
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            showToast('Error: ' + (res.data?.message || res.data || 'Please try again'));
            btn.disabled = false;
            btn.textContent = 'Confirm';
        }
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Confirm';
    });
}

function toggleDay(el) {
    el.classList.toggle('on');
    var day = el.dataset.day;
    var isOn = el.classList.contains('on');
    document.getElementById('start_' + day).disabled = !isOn;
    document.getElementById('end_' + day).disabled = !isOn;
}

function saveAvailability() {
    var btn = document.getElementById('saveAvailBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="td-loading"></span>';
    
    var data = [];
    var days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    var dayMap = {monday: 1, tuesday: 2, wednesday: 3, thursday: 4, friday: 5, saturday: 6, sunday: 0};
    
    days.forEach(function(day) {
        var toggle = document.querySelector('[data-day="' + day + '"]');
        var startEl = document.getElementById('start_' + day);
        var endEl = document.getElementById('end_' + day);
        
        if (toggle && startEl && endEl) {
            data.push({
                day_of_week: dayMap[day],
                is_active: toggle.classList.contains('on') ? 1 : 0,
                start_time: startEl.value || '09:00',
                end_time: endEl.value || '17:00'
            });
        }
    });
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_save_availability&trainer_id=' + trainerId + '&availability=' + encodeURIComponent(JSON.stringify(data)) + '&nonce=' + nonce
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.textContent = 'Save';
        showToast(res.success ? 'Availability saved! ✓' : ('Error: ' + (res.data?.message || 'Could not save')));
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Save';
        showToast('Connection error. Please try again.');
    });
}

function saveRate() {
    var rate = document.getElementById('hourlyRate').value;
    if (rate < 20 || rate > 500) {
        showToast('Rate must be between $20 and $500');
        return;
    }
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_update_trainer_rate&trainer_id=' + trainerId + '&rate=' + rate + '&nonce=' + nonce
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        showToast(res.success ? 'Rate updated! ✓' : 'Error updating rate');
    });
}

function blockDate() {
    var dateInput = document.getElementById('blockDate');
    var date = dateInput.value;
    if (!date) {
        showToast('Select a date to block');
        return;
    }
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_block_date&date=' + date + '&reason=Manual block&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Date blocked ✓');
            dateInput.value = '';
            loadBlockedDates();
        } else {
            showToast(res.data?.message || 'Error blocking date');
        }
    });
}

function loadBlockedDates() {
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_availability_calendar&trainer_id=' + trainerId + '&month=' + (new Date().getMonth() + 1) + '&year=' + new Date().getFullYear()
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success && res.data && res.data.blocked) {
            var container = document.getElementById('blockedDates');
            container.innerHTML = '';
            res.data.blocked.forEach(function(date) {
                var chip = document.createElement('div');
                chip.style.cssText = 'display:inline-flex;align-items:center;gap:8px;padding:8px 14px;background:var(--red-light);border-radius:50px;font-size:13px;font-weight:500;';
                chip.innerHTML = new Date(date + 'T12:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric'}) + 
                    ' <span onclick="unblockDate(\'' + date + '\')" style="cursor:pointer;color:var(--red);font-size:16px;">✕</span>';
                container.appendChild(chip);
            });
        }
    });
}

function unblockDate(date) {
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_unblock_date&date=' + date + '&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        showToast(res.success ? 'Date unblocked ✓' : 'Error');
        loadBlockedDates();
    });
}

// v131: Open Training Dates functions
function addOpenDate() {
    var date = document.getElementById('openDate').value;
    var startTime = document.getElementById('openTimeStart').value;
    var endTime = document.getElementById('openTimeEnd').value;
    var location = document.getElementById('openLocation').value;
    
    if (!date) {
        showToast('Please select a date');
        return;
    }
    
    if (!startTime || !endTime) {
        showToast('Please set start and end times');
        return;
    }
    
    if (startTime >= endTime) {
        showToast('End time must be after start time');
        return;
    }
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_add_open_date&trainer_id=' + trainerId + '&date=' + date + 
              '&start_time=' + startTime + '&end_time=' + endTime + 
              '&location=' + encodeURIComponent(location) + '&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Date added ✓');
            document.getElementById('openDate').value = '';
            document.getElementById('openLocation').value = '';
            loadOpenDates();
            updateSchedulePrompt();
        } else {
            showToast(res.data?.message || 'Error adding date');
        }
    });
}

function loadOpenDates() {
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_open_dates&trainer_id=' + trainerId + '&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        var container = document.getElementById('openDatesList');
        if (!container) return;
        
        if (res.success && res.data && res.data.length > 0) {
            container.innerHTML = res.data.map(function(item) {
                var dateObj = new Date(item.date + 'T12:00:00');
                var dayName = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
                var startTime = formatTime12(item.start_time);
                var endTime = formatTime12(item.end_time);
                var locationHtml = item.location ? '<div class="td-open-date-location">📍 ' + escapeHtml(item.location) + '</div>' : '';
                
                return '<div class="td-open-date-item">' +
                    '<div class="td-open-date-info">' +
                        '<div class="td-open-date-day">' + dayName + '</div>' +
                        '<div class="td-open-date-time">' + startTime + ' - ' + endTime + '</div>' +
                        locationHtml +
                    '</div>' +
                    '<button class="td-open-date-remove" onclick="removeOpenDate(' + item.id + ')" title="Remove">✕</button>' +
                '</div>';
            }).join('');
        } else {
            container.innerHTML = '<div class="td-open-dates-empty">No open dates set. Add dates above to let families know when you\'re available!</div>';
        }
    });
}

function removeOpenDate(id) {
    if (!confirm('Remove this open date?')) return;
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_remove_open_date&id=' + id + '&trainer_id=' + trainerId + '&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        showToast(res.success ? 'Date removed ✓' : 'Error');
        loadOpenDates();
        updateSchedulePrompt();
    });
}

function formatTime12(time24) {
    var parts = time24.split(':');
    var h = parseInt(parts[0]);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateSchedulePrompt() {
    // Refresh the prompt banner after changes
    var prompt = document.querySelector('.td-schedule-prompt');
    if (prompt) {
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_get_open_dates&trainer_id=' + trainerId + '&nonce=' + nonceGeneral
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var count = res.success && res.data ? res.data.length : 0;
            if (count > 0) {
                prompt.className = 'td-schedule-prompt active';
                prompt.innerHTML = '<div class="td-prompt-icon">🟢</div>' +
                    '<div class="td-prompt-content">' +
                        '<strong>Schedule Active</strong>' +
                        '<p>You have ' + count + ' open date' + (count > 1 ? 's' : '') + ' in the next 30 days. Keep it up!</p>' +
                    '</div>';
            }
        });
    }
}

function handlePhotoSelect(input) {
    if (input.files && input.files[0]) {
        uploadProfilePhoto(input.files[0]);
    }
}

function uploadProfilePhoto(file) {
    showToast('Uploading photo...');
    
    var formData = new FormData();
    formData.append('action', 'ptp_upload_trainer_photo');
    formData.append('trainer_id', trainerId);
    formData.append('nonce', nonceGeneral);
    formData.append('photo', file);
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Photo updated! ✓');
            if (res.data && res.data.url) {
                document.getElementById('profilePhotoPreview').src = res.data.url;
                document.querySelector('.td-avatar').src = res.data.url;
            }
        } else {
            showToast('Error: ' + (res.data?.message || 'Upload failed'));
        }
    })
    .catch(function() {
        showToast('Error uploading photo');
    });
}

// Cover Photo Upload Handler
function handleCoverPhotoSelect(input) {
    if (input.files && input.files[0]) {
        uploadCoverPhoto(input.files[0]);
    }
}

function uploadCoverPhoto(file) {
    showToast('Uploading cover photo...');
    
    var formData = new FormData();
    formData.append('action', 'ptp_upload_trainer_cover_photo');
    formData.append('trainer_id', trainerId);
    formData.append('nonce', nonceGeneral);
    formData.append('photo', file);
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Cover photo updated! ✓');
            if (res.data && res.data.url) {
                var preview = document.getElementById('coverPhotoPreview');
                preview.src = res.data.url;
                preview.classList.add('has-image');
            }
        } else {
            showToast('Error: ' + (res.data?.message || 'Upload failed'));
        }
    })
    .catch(function() {
        showToast('Error uploading cover photo');
    });
}

function toggleSpecialty(checkbox) {
    checkbox.parentElement.classList.toggle('selected', checkbox.checked);
}

function saveProfileFull() {
    var specialties = [];
    document.querySelectorAll('#specialtyGrid input:checked').forEach(function(cb) {
        specialties.push(cb.value);
    });
    
    // Collect training locations with lat/lng
    var locations = [];
    document.querySelectorAll('#trainingLocations .td-location-item').forEach(function(item) {
        var name = item.querySelector('div > div:first-child').textContent.trim();
        var address = item.querySelector('div > div:last-child').textContent.trim();
        var lat = item.getAttribute('data-lat') || '';
        var lng = item.getAttribute('data-lng') || '';
        if (name) {
            locations.push({ 
                name: name, 
                address: address,
                lat: lat ? parseFloat(lat) : null,
                lng: lng ? parseFloat(lng) : null
            });
        }
    });
    
    var data = new FormData();
    data.append('action', 'ptp_update_trainer_profile_full');
    data.append('nonce', nonce);
    data.append('trainer_id', trainerId);
    data.append('display_name', document.getElementById('profileDisplayName').value);
    data.append('headline', document.getElementById('profileHeadline').value);
    data.append('bio', document.getElementById('profileBio').value);
    data.append('college', document.getElementById('profileCollege').value);
    data.append('team', document.getElementById('profileTeam').value);
    data.append('position', document.getElementById('profilePosition').value);
    data.append('playing_level', document.getElementById('profilePlayingLevel').value);
    data.append('specialties', JSON.stringify(specialties));
    data.append('phone', document.getElementById('profilePhone').value);
    data.append('city', document.getElementById('profileCity').value);
    data.append('state', document.getElementById('profileState').value);
    data.append('travel_radius', document.getElementById('profileTravelRadius').value);
    data.append('instagram', document.getElementById('profileInstagram').value);
    data.append('training_locations', JSON.stringify(locations));
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: data
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        showToast(res.success ? 'Profile saved! ✓' : ('Error: ' + (res.data?.message || 'Could not save')));
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
    });
}

// Training Location Management with Geocoding
function addLocation() {
    var name = document.getElementById('newLocationName').value.trim();
    var addressInput = document.getElementById('newLocationAddress');
    var address = addressInput.value.trim();
    
    if (!name) {
        showToast('Please enter a location name');
        return;
    }
    
    var container = document.getElementById('trainingLocations');
    var index = container.querySelectorAll('.td-location-item').length;
    
    // Use autocomplete data if available
    if (selectedPlaceData.lat && selectedPlaceData.address) {
        appendLocationItem(container, index, name, selectedPlaceData.address, selectedPlaceData.lat, selectedPlaceData.lng);
        // Reset selected place data
        selectedPlaceData = { lat: '', lng: '', address: '' };
    }
    // Otherwise try to geocode the entered address
    else if (address && typeof google !== 'undefined' && google.maps) {
        var geocoder = new google.maps.Geocoder();
        geocoder.geocode({ address: address + ', USA' }, function(results, status) {
            var lat = '';
            var lng = '';
            var formattedAddress = address;
            if (status === 'OK' && results[0]) {
                lat = results[0].geometry.location.lat();
                lng = results[0].geometry.location.lng();
                formattedAddress = results[0].formatted_address;
            }
            appendLocationItem(container, index, name, formattedAddress, lat, lng);
        });
    } else {
        appendLocationItem(container, index, name, address, '', '');
    }
    
    // Clear inputs
    document.getElementById('newLocationName').value = '';
    addressInput.value = '';
}

function appendLocationItem(container, index, name, address, lat, lng) {
    var html = '<div class="td-location-item" data-index="' + index + '" data-lat="' + lat + '" data-lng="' + lng + '" style="display:flex;align-items:center;gap:10px;padding:14px;background:var(--gray-50);border-radius:var(--r-sm);margin-bottom:10px;">' +
        '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>' +
        '<div style="flex:1;">' +
            '<div style="font-weight:600;font-size:14px;">' + escapeHtml(name) + '</div>' +
            '<div style="font-size:12px;color:var(--gray-600);">' + escapeHtml(address) + '</div>' +
        '</div>' +
        '<button type="button" class="td-btn sm outline" onclick="removeLocation(' + index + ')" style="padding:8px;min-height:44px;">' +
            '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        '</button>' +
    '</div>';
    
    container.insertAdjacentHTML('beforeend', html);
    
    // Clear inputs
    document.getElementById('newLocationName').value = '';
    document.getElementById('newLocationAddress').value = '';
    
    showToast('Location added! Don\'t forget to save.');
}

function removeLocation(index) {
    var item = document.querySelector('.td-location-item[data-index="' + index + '"]');
    if (item) {
        item.remove();
        showToast('Location removed. Don\'t forget to save.');
    }
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function requestPayout() {
    <?php if (!$stripe_complete): ?>
    showToast('Please connect Stripe first to request payouts');
    return;
    <?php endif; ?>
    
    if (!confirm('Request payout of your available balance?')) return;
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_request_payout&trainer_id=' + trainerId + '&nonce=' + nonce
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        showToast(res.success ? 'Payout requested! ✓' : (res.data?.message || 'Error requesting payout'));
    });
}

function connectStripe() {
    showToast('Connecting to Stripe...');
    
    var formData = new FormData();
    formData.append('action', 'ptp_create_stripe_connect_account');
    formData.append('nonce', nonceGeneral);
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data && data.data.url) {
            window.location.href = data.data.url;
        } else {
            showToast(data.data?.message || 'Error connecting to Stripe');
        }
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
    });
}

function openConversation(id) {
    window.location.href = '<?php echo home_url('/messages/'); ?>?conversation=' + id;
}

// Google Calendar Functions
function checkGoogleCalendarStatus() {
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_calendar_status&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var statusText = document.getElementById('gcalStatusText');
        var statusSub = document.getElementById('gcalStatusSub');
        var connectBtn = document.getElementById('gcalConnectBtn');
        var connectedDiv = document.getElementById('gcalConnected');
        
        if (data.success && data.data && data.data.connected) {
            statusText.textContent = '✓ Connected to Google Calendar';
            statusText.style.color = 'var(--green)';
            statusSub.textContent = data.data.calendar_name || 'Sessions will sync automatically';
            connectBtn.style.display = 'none';
            connectedDiv.style.display = 'block';
        } else {
            statusText.textContent = 'Not connected';
            statusText.style.color = 'var(--gray-600)';
            statusSub.textContent = 'Connect to sync sessions to your calendar';
            connectBtn.style.display = 'flex';
            connectedDiv.style.display = 'none';
        }
    })
    .catch(function() {
        document.getElementById('gcalStatusText').textContent = 'Unable to check status';
        document.getElementById('gcalConnectBtn').style.display = 'flex';
    });
}

function connectGoogleCalendar() {
    var btn = document.getElementById('gcalConnectBtn');
    btn.textContent = 'Connecting...';
    btn.disabled = true;
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_google_connect&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data && data.data.auth_url) {
            window.location.href = data.data.auth_url;
        } else {
            showToast(data.data?.message || 'Unable to connect. Please try again.');
            btn.textContent = 'Connect Google Calendar';
            btn.disabled = false;
        }
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
        btn.textContent = 'Connect Google Calendar';
        btn.disabled = false;
    });
}

function syncGoogleCalendar() {
    showToast('Syncing calendar...');
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_google_sync&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Calendar synced successfully!');
        } else {
            showToast(data.data?.message || 'Sync failed. Please try again.');
        }
    })
    .catch(function() {
        showToast('Sync error. Please try again.');
    });
}

function disconnectGoogleCalendar() {
    if (!confirm('Are you sure you want to disconnect Google Calendar?')) return;
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_google_disconnect&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Calendar disconnected');
            checkGoogleCalendarStatus();
        } else {
            showToast('Error disconnecting. Please try again.');
        }
    })
    .catch(function() {
        showToast('Error. Please try again.');
    });
}

// ICS Calendar Subscription Functions
function loadIcsUrl() {
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_calendar_status&nonce=' + nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var icsInput = document.getElementById('icsUrl');
        if (data.success && data.data && data.data.ics_url) {
            icsInput.value = data.data.ics_url;
        } else {
            icsInput.value = 'Unable to load - refresh page';
        }
    })
    .catch(function() {
        document.getElementById('icsUrl').value = 'Error loading URL';
    });
}

function copyIcsUrl() {
    var icsInput = document.getElementById('icsUrl');
    icsInput.select();
    icsInput.setSelectionRange(0, 99999);
    
    if (navigator.clipboard) {
        navigator.clipboard.writeText(icsInput.value).then(function() {
            showToast('Calendar URL copied! Paste in your calendar app.');
        }).catch(function() {
            document.execCommand('copy');
            showToast('Calendar URL copied!');
        });
    } else {
        document.execCommand('copy');
        showToast('Calendar URL copied!');
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadBlockedDates();
    loadOpenDates(); // v131: Load open training dates
    checkGoogleCalendarStatus();
    loadIcsUrl();
});
</script>

<?php wp_footer(); ?>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
