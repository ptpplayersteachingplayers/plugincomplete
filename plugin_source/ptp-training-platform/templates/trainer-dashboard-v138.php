<?php
/**
 * Trainer Dashboard v138.1 - Native App Ready (Fixed)
 * 
 * Mobile-first dashboard with native app UX patterns:
 * - Pull-to-refresh gesture
 * - Bottom sheet modals
 * - Haptic feedback hooks
 * - Session grouping by date
 * - Profile completion meter
 * - Large touch targets (56px)
 * 
 * v138.1 Fixes:
 * - Fixed AJAX action names (ptp_block_date/ptp_unblock_date)
 * - Fixed database table reference (ptp_availability_exceptions)
 * - Fixed blocked date removal parameter (date instead of id)
 */
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI'])));
    exit;
}

global $wpdb;
$user_id = get_current_user_id();

// Get trainer
$trainer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", 
    $user_id
));

if (!$trainer) {
    wp_redirect(home_url('/apply/'));
    exit;
}

if ($trainer->status === 'pending') {
    wp_redirect(home_url('/trainer-pending/'));
    exit;
}

if ($trainer->status === 'rejected') {
    wp_redirect(home_url('/apply/?status=rejected'));
    exit;
}

// Safe property access
$trainer_id = intval($trainer->id);
$trainer_slug = !empty($trainer->slug) ? $trainer->slug : 'trainer-' . $trainer_id;
$display_name = !empty($trainer->display_name) ? $trainer->display_name : 'Trainer';
$first_name = explode(' ', $display_name);
$first_name = $first_name[0];

// Stripe status
$has_stripe = !empty($trainer->stripe_account_id);
$stripe_complete = false;
if ($has_stripe && class_exists('PTP_Stripe')) {
    $stripe_complete = PTP_Stripe::is_account_complete($trainer->stripe_account_id);
}

$profile_url = home_url('/trainer/' . $trainer_slug . '/');
$nonce = wp_create_nonce('ptp_trainer_nonce');
$nonce_general = wp_create_nonce('ptp_nonce');

// Date ranges
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');

// Upcoming sessions
$upcoming_raw = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name, pa.display_name as parent_name, pa.phone as parent_phone
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    WHERE b.trainer_id = %d AND b.session_date >= CURDATE() AND b.status IN ('confirmed','pending') 
    ORDER BY b.session_date ASC, b.start_time ASC LIMIT 20
", $trainer_id));

if (!$upcoming_raw) {
    $upcoming_raw = array();
}

// Group sessions by date
$sessions_grouped = array(
    'today' => array(),
    'tomorrow' => array(),
    'this_week' => array(),
    'later' => array()
);

foreach ($upcoming_raw as $s) {
    if ($s->session_date === $today) {
        $sessions_grouped['today'][] = $s;
    } elseif ($s->session_date === $tomorrow) {
        $sessions_grouped['tomorrow'][] = $s;
    } elseif ($s->session_date <= $week_end) {
        $sessions_grouped['this_week'][] = $s;
    } else {
        $sessions_grouped['later'][] = $s;
    }
}
$total_upcoming = count($upcoming_raw);

// Needs confirmation
$needs_confirmation = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name, pa.display_name as parent_name
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    WHERE b.trainer_id = %d AND b.session_date < CURDATE() AND b.status = 'confirmed'
    ORDER BY b.session_date DESC LIMIT 10
", $trainer_id));

if (!$needs_confirmation) {
    $needs_confirmation = array();
}

// Earnings
$earnings_today = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout),0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id=%d AND session_date=CURDATE() AND status='completed'", 
    $trainer_id
));
$earnings_week = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout),0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id=%d AND session_date>=%s AND status='completed'", 
    $trainer_id, $week_start
));
$earnings_month = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout),0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id=%d AND session_date>=%s AND status='completed'", 
    $trainer_id, $month_start
));
$earnings_pending = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(trainer_payout),0) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id=%d AND status='completed' AND payout_status='pending'", 
    $trainer_id
));

$earnings = array(
    'today' => floatval($earnings_today),
    'week' => floatval($earnings_week),
    'month' => floatval($earnings_month),
    'pending' => floatval($earnings_pending)
);

// Stats
$stats = array(
    'sessions' => intval(isset($trainer->total_sessions) ? $trainer->total_sessions : 0),
    'rating' => floatval(isset($trainer->average_rating) ? $trainer->average_rating : 5.0),
    'reviews' => intval(isset($trainer->review_count) ? $trainer->review_count : 0),
    'rate' => intval(isset($trainer->hourly_rate) ? $trainer->hourly_rate : 60)
);

// Profile completion
$photo_url = isset($trainer->photo_url) ? $trainer->photo_url : '';
$bio = isset($trainer->bio) ? $trainer->bio : '';
$headline = isset($trainer->headline) ? $trainer->headline : '';

$profile_checks = array(
    'photo' => !empty($photo_url) && strpos($photo_url, 'ui-avatars') === false,
    'bio' => !empty($bio) && strlen($bio) > 50,
    'headline' => !empty($headline),
    'stripe' => $stripe_complete,
    'availability' => false
);

// Check availability table
$avail_table = $wpdb->prefix . 'ptp_availability';
$avail_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$avail_table'") === $avail_table;
if ($avail_table_exists) {
    $avail_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$avail_table} WHERE trainer_id=%d AND is_active=1", 
        $trainer_id
    ));
    $profile_checks['availability'] = intval($avail_count) > 0;
}

$profile_complete_count = 0;
foreach ($profile_checks as $check) {
    if ($check) $profile_complete_count++;
}
$profile_total = count($profile_checks);
$profile_percent = round(($profile_complete_count / $profile_total) * 100);

// Incomplete items
$incomplete_items = array();
if (!$profile_checks['photo']) {
    $incomplete_items[] = array('key' => 'photo', 'label' => 'Add photo');
}
if (!$profile_checks['bio']) {
    $incomplete_items[] = array('key' => 'bio', 'label' => 'Write bio');
}
if (!$profile_checks['stripe']) {
    $incomplete_items[] = array('key' => 'stripe', 'label' => 'Connect payments');
}
if (!$profile_checks['availability']) {
    $incomplete_items[] = array('key' => 'availability', 'label' => 'Set hours');
}

// Get availability
$availability = array();
if ($avail_table_exists) {
    $raw_avail = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$avail_table} WHERE trainer_id=%d", 
        $trainer_id
    ));
    if ($raw_avail) {
        $day_names = array(
            0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
            4 => 'thursday', 5 => 'friday', 6 => 'saturday'
        );
        foreach ($raw_avail as $slot) {
            $d = intval($slot->day_of_week);
            if (isset($day_names[$d])) {
                $availability[$day_names[$d]] = array(
                    'enabled' => !empty($slot->is_active),
                    'start' => substr($slot->start_time, 0, 5),
                    'end' => substr($slot->end_time, 0, 5)
                );
            }
        }
    }
}

// Messages
$unread_count = 0;
$conversations = array();
if (class_exists('PTP_Messaging_V71')) {
    $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
    if ($conversations && is_array($conversations)) {
        foreach ($conversations as $c) {
            if (!empty($c->unread)) $unread_count++;
        }
    }
}

// Completed sessions
$completed_sessions = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id=pl.id 
    WHERE b.trainer_id=%d AND b.status='completed' ORDER BY b.session_date DESC LIMIT 20
", $trainer_id));

if (!$completed_sessions) {
    $completed_sessions = array();
}

// Blocked dates (stored in ptp_availability_exceptions with is_available=0)
$blocked_dates = array();
$exceptions_table = $wpdb->prefix . 'ptp_availability_exceptions';
if ($wpdb->get_var("SHOW TABLES LIKE '$exceptions_table'") === $exceptions_table) {
    $blocked_dates = $wpdb->get_results($wpdb->prepare(
        "SELECT id, exception_date as date, reason FROM $exceptions_table 
         WHERE trainer_id=%d AND is_available=0 AND exception_date>=CURDATE() 
         ORDER BY exception_date ASC LIMIT 20", 
        $trainer_id
    ));
    if (!$blocked_dates) {
        $blocked_dates = array();
    }
}

// Avatar URL
$avatar_url = !empty($photo_url) ? $photo_url : 'https://ui-avatars.com/api/?name=' . urlencode($display_name) . '&size=112&background=FCB900&color=0A0A0A&bold=true';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0A0A0A">
<title>Dashboard | PTP</title>
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--gold:#FCB900;--gold-light:rgba(252,185,0,0.1);--black:#0A0A0A;--white:#FFF;--gray-50:#FAFAFA;--gray-100:#F5F5F5;--gray-200:#E5E5E5;--gray-300:#D4D4D4;--gray-400:#9CA3AF;--gray-500:#6B7280;--gray-600:#4B5563;--green:#10B981;--green-light:rgba(16,185,129,0.1);--red:#EF4444;--safe-top:env(safe-area-inset-top,0px);--safe-bottom:env(safe-area-inset-bottom,0px);--spring:cubic-bezier(0.34,1.56,0.64,1);--smooth:cubic-bezier(0.4,0,0.2,1)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{height:100%}
body{height:100%;font-family:'Inter',-apple-system,sans-serif;background:var(--gray-100);color:var(--black);-webkit-font-smoothing:antialiased;-webkit-tap-highlight-color:transparent;overflow:hidden}
h1,h2,h3,h4{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:0.02em}
a{color:inherit;text-decoration:none}
button{font-family:inherit;border:none;background:none;cursor:pointer}
input,textarea{font-family:inherit;font-size:16px}

.app{height:100%;display:flex;flex-direction:column}
.app-header{flex-shrink:0;background:linear-gradient(135deg,var(--black),#1a1a1a);padding:calc(var(--safe-top) + 16px) 16px 16px;z-index:100}
.app-content{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.app-nav{flex-shrink:0;background:var(--white);border-top:1px solid var(--gray-200);padding:8px 8px calc(8px + var(--safe-bottom));display:flex;justify-content:space-around;z-index:100}

.header-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.header-greeting{font-size:12px;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px}
.header-name{font-size:28px;color:var(--white);line-height:1}
.header-name span{color:var(--gold)}
.header-avatar{width:56px;height:56px;border-radius:50%;border:3px solid var(--gold);object-fit:cover;cursor:pointer;transition:transform 0.2s var(--spring)}
.header-avatar:active{transform:scale(0.95)}

.stats-row{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;padding-bottom:4px;margin:0 -16px;padding-left:16px;padding-right:16px}
.stats-row::-webkit-scrollbar{display:none}
.stat-card{flex-shrink:0;min-width:80px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px 16px;text-align:center}
.stat-value{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;color:var(--gold);line-height:1}
.stat-value.green{color:var(--green)}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray-400);margin-top:4px}

.ptr-indicator{position:absolute;top:-60px;left:50%;transform:translateX(-50%);width:40px;height:40px;background:var(--white);border-radius:50%;box-shadow:0 2px 12px rgba(0,0,0,0.15);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s,top 0.2s;z-index:50}
.ptr-indicator.visible{opacity:1;top:10px}
.ptr-indicator.refreshing{animation:spin 0.8s linear infinite}
@keyframes spin{to{transform:translateX(-50%) rotate(360deg)}}
.ptr-indicator svg{width:20px;height:20px;color:var(--gold)}

.tab-content{padding:16px;padding-bottom:32px;min-height:100%;position:relative}
.tab-panel{display:none;animation:fadeIn 0.25s var(--smooth)}
.tab-panel.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

.confirm-banner{position:sticky;top:0;z-index:50;background:linear-gradient(135deg,#FEF3C7,#FDE68A);border:2px solid #F59E0B;border-radius:16px;padding:16px;margin-bottom:16px;box-shadow:0 4px 20px rgba(245,158,11,0.2)}
.confirm-banner-header{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.confirm-banner-icon{width:40px;height:40px;background:#F59E0B;border-radius:10px;display:flex;align-items:center;justify-content:center}
.confirm-banner-icon svg{width:20px;height:20px;color:var(--white)}
.confirm-banner-title{font-size:14px;font-weight:700;color:#92400E}
.confirm-banner-subtitle{font-size:12px;color:#B45309}
.confirm-item{display:flex;align-items:center;gap:12px;background:var(--white);border-radius:12px;padding:12px;margin-bottom:8px;transition:transform 0.3s,opacity 0.3s}
.confirm-item:last-child{margin-bottom:0}
.confirm-item-content{flex:1;min-width:0}
.confirm-item-name{font-weight:600;font-size:15px;color:var(--black)}
.confirm-item-meta{font-size:13px;color:var(--gray-500);margin-top:2px}

.card{background:var(--white);border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04)}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.card-title{font-size:13px;letter-spacing:0.05em;color:var(--gray-500)}
.card-action{font-size:13px;font-weight:600;color:var(--gold)}

.profile-completion{background:linear-gradient(135deg,var(--black),#1F2937);border-radius:16px;padding:20px;margin-bottom:16px}
.profile-completion-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.profile-completion-title{font-size:14px;font-weight:700;color:var(--white);text-transform:uppercase}
.profile-completion-percent{font-family:'Oswald',sans-serif;font-size:24px;font-weight:700;color:var(--gold)}
.profile-completion-bar{height:8px;background:rgba(255,255,255,0.1);border-radius:4px;overflow:hidden;margin-bottom:16px}
.profile-completion-fill{height:100%;background:linear-gradient(90deg,var(--gold),#F59E0B);border-radius:4px;transition:width 0.5s var(--spring)}
.profile-completion-items{display:flex;gap:8px;flex-wrap:wrap}
.profile-completion-item{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:10px 12px;font-size:12px;color:var(--gray-300);cursor:pointer;transition:all 0.15s;min-height:48px}
.profile-completion-item:hover{background:var(--gold);color:var(--black);border-color:var(--gold)}

.payout-section{background:var(--green-light);border:2px solid var(--green);border-radius:12px;padding:16px;display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;cursor:pointer}
.payout-label{font-size:12px;color:var(--gray-600)}
.payout-amount{font-family:'Oswald',sans-serif;font-size:24px;font-weight:700;color:var(--green)}

.session-group{margin-bottom:20px}
.session-group-header{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.session-group-title{font-size:13px;font-weight:700;text-transform:uppercase;color:var(--gray-500)}
.session-group-count{font-size:11px;background:var(--gray-200);color:var(--gray-600);padding:2px 8px;border-radius:10px}

.session-card{display:flex;align-items:center;gap:16px;background:var(--white);border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04);cursor:pointer;transition:transform 0.15s;min-height:56px}
.session-card:active{transform:scale(0.98)}
.session-date-badge{width:56px;height:56px;background:var(--gold-light);border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0}
.session-date-badge.today{background:var(--gold)}
.session-date-badge.today .session-date-day,.session-date-badge.today .session-date-num{color:var(--black)}
.session-date-day{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--gold)}
.session-date-num{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;color:var(--gold);line-height:1}
.session-info{flex:1;min-width:0}
.session-player{font-weight:600;font-size:16px;color:var(--black);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.session-meta{font-size:13px;color:var(--gray-500);margin-top:2px}
.session-right{text-align:right;flex-shrink:0}
.session-amount{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:var(--green)}
.session-status{font-size:11px;text-transform:uppercase;font-weight:600;padding:2px 8px;border-radius:6px;margin-top:4px;display:inline-block}
.session-status.confirmed{background:var(--green-light);color:var(--green)}
.session-status.pending{background:#FEF3C7;color:#D97706}

.earnings-hero{text-align:center;padding:24px 0}
.earnings-label{font-size:12px;text-transform:uppercase;letter-spacing:0.1em;color:var(--gray-500);margin-bottom:8px}
.earnings-amount{font-family:'Oswald',sans-serif;font-size:56px;font-weight:700;color:var(--green);line-height:1;margin-bottom:8px}
.earnings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:20px;padding-top:20px;border-top:1px solid var(--gray-200)}
.earnings-stat{text-align:center}
.earnings-stat-value{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:var(--black)}
.earnings-stat-label{font-size:11px;text-transform:uppercase;color:var(--gray-500);margin-top:2px}

.empty-state{text-align:center;padding:32px 16px}
.empty-state-icon{width:80px;height:80px;background:var(--gray-100);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.empty-state-icon svg{width:36px;height:36px;color:var(--gray-400)}
.empty-state-title{font-size:18px;color:var(--black);margin-bottom:8px}
.empty-state-text{font-size:14px;color:var(--gray-500);max-width:280px;margin:0 auto;line-height:1.5}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 20px;background:var(--gold);color:var(--black);font-family:'Oswald',sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:0.03em;border:2px solid var(--gold);border-radius:12px;cursor:pointer;transition:all 0.15s}
.btn:active{transform:scale(0.97)}
.btn:disabled{opacity:0.5;cursor:not-allowed;transform:none}
.btn-sm{min-height:44px;padding:0 16px;font-size:12px;border-radius:10px}
.btn-outline{background:transparent;color:var(--gold)}
.btn-green{background:var(--green);border-color:var(--green);color:var(--white)}
.btn-full{width:100%}

.avail-day{display:flex;align-items:center;gap:12px;padding:16px;background:var(--gray-50);border-radius:12px;margin-bottom:8px}
.avail-name{width:48px;font-family:'Oswald',sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;color:var(--gray-600)}
.avail-toggle{width:52px;height:32px;background:var(--gray-300);border-radius:16px;position:relative;cursor:pointer;transition:background 0.2s;flex-shrink:0}
.avail-toggle.on{background:var(--green)}
.avail-toggle::after{content:'';position:absolute;width:28px;height:28px;background:var(--white);border-radius:50%;top:2px;left:2px;transition:transform 0.2s var(--spring);box-shadow:0 2px 4px rgba(0,0,0,0.2)}
.avail-toggle.on::after{transform:translateX(20px)}
.avail-times{display:flex;gap:8px;align-items:center;flex:1}
.avail-time{flex:1;min-width:70px;max-width:90px;padding:10px;font-size:14px;font-weight:500;border:2px solid var(--gray-200);border-radius:10px;text-align:center;background:var(--white)}
.avail-time:focus{border-color:var(--gold);outline:none}
.avail-time:disabled{background:var(--gray-100);color:var(--gray-400)}
.avail-sep{color:var(--gray-400);font-size:13px}

.message-item{display:flex;align-items:center;gap:12px;padding:16px;background:var(--white);border-radius:16px;margin-bottom:12px;cursor:pointer;transition:transform 0.15s;min-height:56px}
.message-item:active{transform:scale(0.98)}
.message-item.unread{border-left:4px solid var(--gold)}
.message-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;background:var(--gray-200);flex-shrink:0}
.message-content{flex:1;min-width:0}
.message-name{font-weight:600;font-size:15px;color:var(--black)}
.message-preview{font-size:13px;color:var(--gray-500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.message-time{font-size:12px;color:var(--gray-400);flex-shrink:0}
.message-badge{width:10px;height:10px;background:var(--gold);border-radius:50%;flex-shrink:0}

.nav-item{display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 12px;min-width:64px;color:var(--gray-400);font-size:10px;font-weight:600;text-transform:uppercase;cursor:pointer;transition:color 0.15s;position:relative}
.nav-item.active{color:var(--gold)}
.nav-item svg{width:24px;height:24px}
.nav-badge{position:absolute;top:0;right:8px;min-width:18px;height:18px;background:var(--red);color:var(--white);font-size:10px;font-weight:700;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px}

.sheet-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:500;opacity:0;visibility:hidden;transition:opacity 0.25s,visibility 0.25s}
.sheet-overlay.open{opacity:1;visibility:visible}
.sheet{position:fixed;left:0;right:0;bottom:0;background:var(--white);border-radius:24px 24px 0 0;z-index:501;transform:translateY(100%);transition:transform 0.3s var(--spring);max-height:90vh;display:flex;flex-direction:column}
.sheet.open{transform:translateY(0)}
.sheet-handle{width:40px;height:5px;background:var(--gray-300);border-radius:3px;margin:12px auto;flex-shrink:0}
.sheet-header{padding:0 20px 16px;border-bottom:1px solid var(--gray-200);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.sheet-title{font-size:18px}
.sheet-close{width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:var(--gray-100)}
.sheet-close svg{width:20px;height:20px;color:var(--gray-600)}
.sheet-body{padding:20px;overflow-y:auto;flex:1}
.sheet-footer{padding:16px 20px calc(16px + var(--safe-bottom));border-top:1px solid var(--gray-200);flex-shrink:0}

.toast{position:fixed;bottom:calc(72px + var(--safe-bottom) + 16px);left:50%;transform:translateX(-50%) translateY(100px);background:var(--black);color:var(--white);padding:16px 20px;border-radius:12px;font-size:14px;font-weight:500;z-index:600;opacity:0;transition:transform 0.3s var(--spring),opacity 0.3s;max-width:calc(100% - 32px)}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

.share-card{background:linear-gradient(135deg,var(--black),#1F2937);border-radius:16px;padding:20px}
.share-title{font-size:14px;color:var(--white);margin-bottom:12px}
.share-row{display:flex;gap:8px}
.share-input{flex:1;padding:12px 16px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:10px;color:var(--white);font-size:16px}
.share-btn{min-height:48px;padding:0 16px;background:var(--gold);color:var(--black);font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;border-radius:10px;display:flex;align-items:center;gap:8px}

.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--gray-600);margin-bottom:8px}
.form-input{width:100%;padding:16px;background:var(--gray-50);border:2px solid var(--gray-200);border-radius:12px;font-size:16px;transition:border-color 0.15s}
.form-input:focus{border-color:var(--gold);outline:none}
.form-textarea{min-height:120px;resize:vertical}

.text-center{text-align:center}
.text-gold{color:var(--gold)}
.text-green{color:var(--green)}
.text-gray{color:var(--gray-500)}
.mt-4{margin-top:16px}

@media(min-width:768px){.app-content,.app-header,.app-nav{max-width:600px;margin:0 auto}.app-header{border-radius:0 0 24px 24px}.app-nav{border-radius:24px 24px 0 0}}
</style>
</head>
<body>
<div class="app" id="app">
    <header class="app-header">
        <div class="header-top">
            <div>
                <p class="header-greeting">Welcome back</p>
                <h1 class="header-name"><span><?php echo esc_html(strtoupper($first_name)); ?></span></h1>
            </div>
            <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="header-avatar" onclick="switchTab('profile')">
        </div>
        <div class="stats-row">
            <div class="stat-card"><div class="stat-value">$<?php echo esc_html($stats['rate']); ?></div><div class="stat-label">Rate</div></div>
            <div class="stat-card"><div class="stat-value green">$<?php echo esc_html(number_format($earnings['week'])); ?></div><div class="stat-label">This Week</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo esc_html($total_upcoming); ?></div><div class="stat-label">Upcoming</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo esc_html($stats['sessions']); ?></div><div class="stat-label">Total</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo esc_html(number_format($stats['rating'], 1)); ?>★</div><div class="stat-label"><?php echo esc_html($stats['reviews']); ?> Reviews</div></div>
        </div>
    </header>
    
    <main class="app-content" id="appContent">
        <div class="ptr-indicator" id="ptrIndicator"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg></div>
        <div class="tab-content">
            <!-- HOME TAB -->
            <div class="tab-panel active" id="tab-home" data-tab="home">
                <?php if (!empty($needs_confirmation)): ?>
                <div class="confirm-banner" id="confirmBanner">
                    <div class="confirm-banner-header">
                        <div class="confirm-banner-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                        <div><div class="confirm-banner-title"><?php echo count($needs_confirmation); ?> Session<?php echo count($needs_confirmation) > 1 ? 's' : ''; ?> Need Confirmation</div><div class="confirm-banner-subtitle">Confirm to receive payment</div></div>
                    </div>
                    <?php foreach ($needs_confirmation as $nc): ?>
                    <div class="confirm-item" data-id="<?php echo intval($nc->id); ?>">
                        <div class="confirm-item-content">
                            <div class="confirm-item-name"><?php echo esc_html(!empty($nc->player_name) ? $nc->player_name : 'Player'); ?></div>
                            <div class="confirm-item-meta"><?php echo esc_html(date('D, M j', strtotime($nc->session_date))); ?><?php if (!empty($nc->parent_name)): ?> · <?php echo esc_html($nc->parent_name); ?><?php endif; ?></div>
                        </div>
                        <button class="btn btn-sm btn-green" onclick="confirmSession(<?php echo intval($nc->id); ?>,this)">Confirm</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($profile_percent < 100): ?>
                <div class="profile-completion">
                    <div class="profile-completion-header">
                        <div class="profile-completion-title">Complete Your Profile</div>
                        <div class="profile-completion-percent"><?php echo esc_html($profile_percent); ?>%</div>
                    </div>
                    <div class="profile-completion-bar"><div class="profile-completion-fill" style="width:<?php echo intval($profile_percent); ?>%"></div></div>
                    <?php if (!empty($incomplete_items)): ?>
                    <div class="profile-completion-items">
                        <?php 
                        $show_items = array_slice($incomplete_items, 0, 3);
                        foreach ($show_items as $item): 
                        ?>
                        <div class="profile-completion-item" onclick="handleProfileAction('<?php echo esc_attr($item['key']); ?>')"><?php echo esc_html($item['label']); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($earnings['pending'] >= 10): ?>
                <div class="payout-section" onclick="openPayoutSheet()">
                    <div><div class="payout-label">Available for Payout</div><div class="payout-amount">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?></div></div>
                    <button class="btn btn-green">Cash Out</button>
                </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Upcoming Sessions</h3>
                        <a href="<?php echo esc_url($profile_url); ?>" target="_blank" class="card-action">View Profile →</a>
                    </div>
                    <?php if ($total_upcoming > 0): ?>
                        <?php 
                        $group_labels = array(
                            'today' => 'Today',
                            'tomorrow' => 'Tomorrow', 
                            'this_week' => 'This Week',
                            'later' => 'Later'
                        );
                        foreach ($group_labels as $group_key => $group_label): 
                            if (!empty($sessions_grouped[$group_key])): 
                        ?>
                        <div class="session-group">
                            <div class="session-group-header"><span class="session-group-title"><?php echo esc_html($group_label); ?></span><span class="session-group-count"><?php echo count($sessions_grouped[$group_key]); ?></span></div>
                            <?php foreach ($sessions_grouped[$group_key] as $session): ?>
                            <div class="session-card">
                                <div class="session-date-badge <?php echo $group_key === 'today' ? 'today' : ''; ?>">
                                    <div class="session-date-day"><?php echo esc_html(date('D', strtotime($session->session_date))); ?></div>
                                    <div class="session-date-num"><?php echo esc_html(date('j', strtotime($session->session_date))); ?></div>
                                </div>
                                <div class="session-info">
                                    <div class="session-player"><?php echo esc_html(!empty($session->player_name) ? $session->player_name : 'Player'); ?></div>
                                    <div class="session-meta"><?php echo !empty($session->start_time) ? esc_html(date('g:i A', strtotime($session->start_time))) : ''; ?><?php if (!empty($session->location)): ?> · <?php echo esc_html($session->location); ?><?php endif; ?></div>
                                </div>
                                <div class="session-right">
                                    <div class="session-amount">$<?php echo esc_html(number_format(floatval($session->trainer_payout))); ?></div>
                                    <div class="session-status <?php echo esc_attr($session->status); ?>"><?php echo esc_html(ucfirst($session->status)); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                        <h3 class="empty-state-title">No Upcoming Sessions</h3>
                        <p class="empty-state-text">Share your profile link to get booked!</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="share-card">
                    <h4 class="share-title">Share Your Profile</h4>
                    <div class="share-row">
                        <input type="text" value="<?php echo esc_attr($profile_url); ?>" class="share-input" id="shareUrl" readonly>
                        <button class="share-btn" onclick="copyProfileLink()">Copy</button>
                    </div>
                </div>
            </div>
            
            <!-- SCHEDULE TAB -->
            <div class="tab-panel" id="tab-schedule" data-tab="schedule">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Weekly Availability</h3>
                        <button class="btn btn-sm" id="saveAvailBtn" onclick="saveAvailability()">Save</button>
                    </div>
                    <?php 
                    $day_list = array(
                        'monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed',
                        'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'
                    );
                    foreach ($day_list as $day_key => $day_label):
                        $day_avail = isset($availability[$day_key]) ? $availability[$day_key] : array('enabled' => false, 'start' => '09:00', 'end' => '17:00');
                    ?>
                    <div class="avail-day">
                        <div class="avail-name"><?php echo esc_html($day_label); ?></div>
                        <div class="avail-toggle <?php echo $day_avail['enabled'] ? 'on' : ''; ?>" data-day="<?php echo esc_attr($day_key); ?>" onclick="toggleDay(this)"></div>
                        <div class="avail-times">
                            <input type="time" class="avail-time" id="start_<?php echo esc_attr($day_key); ?>" value="<?php echo esc_attr($day_avail['start']); ?>" <?php echo !$day_avail['enabled'] ? 'disabled' : ''; ?>>
                            <span class="avail-sep">to</span>
                            <input type="time" class="avail-time" id="end_<?php echo esc_attr($day_key); ?>" value="<?php echo esc_attr($day_avail['end']); ?>" <?php echo !$day_avail['enabled'] ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Blocked Dates</h3>
                        <button class="btn btn-sm btn-outline" onclick="openBlockDateSheet()">+ Add</button>
                    </div>
                    <?php if (empty($blocked_dates)): ?>
                    <p class="text-gray text-center" style="padding:16px">No blocked dates</p>
                    <?php else: ?>
                    <?php foreach ($blocked_dates as $bd): ?>
                    <div class="session-card" style="cursor:default">
                        <div class="session-date-badge">
                            <div class="session-date-day"><?php echo esc_html(date('D', strtotime($bd->date))); ?></div>
                            <div class="session-date-num"><?php echo esc_html(date('j', strtotime($bd->date))); ?></div>
                        </div>
                        <div class="session-info">
                            <div class="session-player"><?php echo esc_html(date('F j, Y', strtotime($bd->date))); ?></div>
                            <div class="session-meta"><?php echo esc_html(!empty($bd->reason) ? $bd->reason : 'Blocked'); ?></div>
                        </div>
                        <button class="btn btn-sm btn-outline" onclick="removeBlockedDate('<?php echo esc_attr($bd->date); ?>')" style="color:var(--red);border-color:var(--red)">Remove</button>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- EARNINGS TAB -->
            <div class="tab-panel" id="tab-earnings" data-tab="earnings">
                <div class="card">
                    <div class="earnings-hero">
                        <div class="earnings-label">Available Balance</div>
                        <div class="earnings-amount">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?></div>
                        <?php if ($earnings['pending'] >= 10): ?>
                        <button class="btn btn-green btn-full mt-4" onclick="openPayoutSheet()">Request Payout</button>
                        <?php elseif (!$stripe_complete): ?>
                        <button class="btn btn-full mt-4" onclick="connectStripe()">Connect Stripe</button>
                        <?php endif; ?>
                    </div>
                    <div class="earnings-grid">
                        <div class="earnings-stat"><div class="earnings-stat-value">$<?php echo esc_html(number_format($earnings['today'])); ?></div><div class="earnings-stat-label">Today</div></div>
                        <div class="earnings-stat"><div class="earnings-stat-value">$<?php echo esc_html(number_format($earnings['week'])); ?></div><div class="earnings-stat-label">This Week</div></div>
                        <div class="earnings-stat"><div class="earnings-stat-value">$<?php echo esc_html(number_format($earnings['month'])); ?></div><div class="earnings-stat-label">This Month</div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Recent Sessions</h3></div>
                    <?php if (!empty($completed_sessions)): ?>
                        <?php 
                        $show_completed = array_slice($completed_sessions, 0, 10);
                        foreach ($show_completed as $cs): 
                        ?>
                        <div class="session-card" style="cursor:default">
                            <div class="session-date-badge">
                                <div class="session-date-day"><?php echo esc_html(date('D', strtotime($cs->session_date))); ?></div>
                                <div class="session-date-num"><?php echo esc_html(date('j', strtotime($cs->session_date))); ?></div>
                            </div>
                            <div class="session-info">
                                <div class="session-player"><?php echo esc_html(!empty($cs->player_name) ? $cs->player_name : 'Player'); ?></div>
                                <div class="session-meta"><?php echo esc_html(date('M j, Y', strtotime($cs->session_date))); ?></div>
                            </div>
                            <div class="session-right">
                                <div class="session-amount">$<?php echo esc_html(number_format(floatval($cs->trainer_payout))); ?></div>
                                <div class="session-status confirmed">Paid</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></div>
                        <h3 class="empty-state-title">No Sessions Yet</h3>
                        <p class="empty-state-text">Your earnings will appear here.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- MESSAGES TAB -->
            <div class="tab-panel" id="tab-messages" data-tab="messages">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Messages</h3><?php if ($unread_count > 0): ?><span class="text-gold"><?php echo esc_html($unread_count); ?> unread</span><?php endif; ?></div>
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conv): 
                            $conv_name = isset($conv->other_user_name) ? $conv->other_user_name : 'Parent';
                            $conv_photo = isset($conv->other_user_photo) ? $conv->other_user_photo : '';
                            if (empty($conv_photo)) {
                                $conv_photo = 'https://ui-avatars.com/api/?name=' . urlencode($conv_name) . '&size=96&background=E5E5E5&color=6B7280';
                            }
                            $conv_preview = isset($conv->last_message) ? $conv->last_message : '';
                            $conv_time = isset($conv->last_message_time) ? $conv->last_message_time : '';
                        ?>
                        <div class="message-item <?php echo !empty($conv->unread) ? 'unread' : ''; ?>" onclick="openConversation(<?php echo intval($conv->id); ?>)">
                            <img src="<?php echo esc_url($conv_photo); ?>" alt="" class="message-avatar">
                            <div class="message-content">
                                <div class="message-name"><?php echo esc_html($conv_name); ?></div>
                                <div class="message-preview"><?php echo esc_html(wp_trim_words($conv_preview, 8, '...')); ?></div>
                            </div>
                            <?php if (!empty($conv_time)): ?><div class="message-time"><?php echo esc_html(human_time_diff(strtotime($conv_time))); ?></div><?php endif; ?>
                            <?php if (!empty($conv->unread)): ?><div class="message-badge"></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg></div>
                        <h3 class="empty-state-title">No Messages</h3>
                        <p class="empty-state-text">Messages will appear here.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- PROFILE TAB -->
            <div class="tab-panel" id="tab-profile" data-tab="profile">
                <div class="card text-center">
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:4px solid var(--gold);margin:0 auto 16px">
                    <h2 style="font-size:20px;margin-bottom:4px"><?php echo esc_html($display_name); ?></h2>
                    <p class="text-gray" style="margin-bottom:16px"><?php echo esc_html(!empty($headline) ? $headline : 'Soccer Trainer'); ?></p>
                    <button class="btn btn-outline" onclick="openPhotoUpload()">Change Photo</button>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Settings</h3></div>
                    <div class="form-group">
                        <label class="form-label">Hourly Rate</label>
                        <div style="display:flex;align-items:center;gap:8px"><span style="font-size:20px;font-weight:600">$</span><input type="number" id="hourlyRate" value="<?php echo esc_attr($stats['rate']); ?>" class="form-input" style="max-width:120px"><span class="text-gray">/session</span></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Headline</label>
                        <input type="text" id="headline" value="<?php echo esc_attr($headline); ?>" class="form-input" placeholder="e.g., Former MLS Academy Player">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bio</label>
                        <textarea id="bio" class="form-input form-textarea" placeholder="Tell families about your experience..."><?php echo esc_textarea($bio); ?></textarea>
                    </div>
                    <button class="btn btn-full" onclick="saveProfile()">Save Changes</button>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Payments</h3><?php if ($stripe_complete): ?><span class="text-green">✓ Connected</span><?php endif; ?></div>
                    <?php if (!$stripe_complete): ?>
                    <p class="text-gray" style="margin-bottom:16px">Connect your bank to receive payouts.</p>
                    <button class="btn btn-full" onclick="connectStripe()">Connect Stripe</button>
                    <?php else: ?>
                    <p class="text-gray">Your Stripe is connected and ready.</p>
                    <?php endif; ?>
                </div>
                
                <div class="share-card">
                    <h4 class="share-title">Your Public Profile</h4>
                    <div class="share-row"><input type="text" value="<?php echo esc_attr($profile_url); ?>" class="share-input" readonly><a href="<?php echo esc_url($profile_url); ?>" target="_blank" class="share-btn">View</a></div>
                </div>
                
                <button class="btn btn-outline btn-full mt-4" onclick="logout()" style="color:var(--red);border-color:var(--red)">Log Out</button>
            </div>
        </div>
    </main>
    
    <nav class="app-nav">
        <div class="nav-item active" data-tab="home" onclick="switchTab('home')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>Home</div>
        <div class="nav-item" data-tab="schedule" onclick="switchTab('schedule')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>Schedule</div>
        <div class="nav-item" data-tab="earnings" onclick="switchTab('earnings')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>Earnings</div>
        <div class="nav-item" data-tab="messages" onclick="switchTab('messages')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg><?php if ($unread_count > 0): ?><span class="nav-badge"><?php echo esc_html($unread_count); ?></span><?php endif; ?>Messages</div>
        <div class="nav-item" data-tab="profile" onclick="switchTab('profile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>Profile</div>
    </nav>
</div>

<div class="toast" id="toast"></div>

<div class="sheet-overlay" id="payoutSheetOverlay" onclick="closePayoutSheet()"></div>
<div class="sheet" id="payoutSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h3 class="sheet-title">Request Payout</h3><button class="sheet-close" onclick="closePayoutSheet()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div>
    <div class="sheet-body"><div class="earnings-hero" style="padding:16px 0"><div class="earnings-label">Available Balance</div><div class="earnings-amount">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?></div></div><p class="text-gray text-center">Funds transfer within 1-2 business days.</p></div>
    <div class="sheet-footer"><button class="btn btn-green btn-full" onclick="requestPayout()" id="payoutBtn">Confirm Payout</button></div>
</div>

<div class="sheet-overlay" id="blockDateSheetOverlay" onclick="closeBlockDateSheet()"></div>
<div class="sheet" id="blockDateSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h3 class="sheet-title">Block a Date</h3><button class="sheet-close" onclick="closeBlockDateSheet()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div>
    <div class="sheet-body">
        <div class="form-group"><label class="form-label">Date</label><input type="date" id="blockDate" class="form-input" min="<?php echo esc_attr(date('Y-m-d')); ?>"></div>
        <div class="form-group"><label class="form-label">Reason (optional)</label><input type="text" id="blockReason" class="form-input" placeholder="e.g., Vacation"></div>
    </div>
    <div class="sheet-footer"><button class="btn btn-full" onclick="saveBlockedDate()">Block This Date</button></div>
</div>

<script>
var CONFIG={
    ajaxUrl:'<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce:'<?php echo esc_js($nonce); ?>',
    nonceGeneral:'<?php echo esc_js($nonce_general); ?>',
    trainerId:<?php echo intval($trainer_id); ?>
};
var STATE={currentTab:'home',isRefreshing:false,pullDistance:0};

function switchTab(tab){
    STATE.currentTab=tab;
    document.querySelectorAll('.nav-item').forEach(function(el){el.classList.toggle('active',el.dataset.tab===tab);});
    document.querySelectorAll('.tab-panel').forEach(function(el){el.classList.toggle('active',el.dataset.tab===tab);});
    document.getElementById('appContent').scrollTop=0;
    triggerHaptic('light');
}

(function(){
    var content=document.getElementById('appContent'),indicator=document.getElementById('ptrIndicator'),startY=0,pulling=false;
    content.addEventListener('touchstart',function(e){if(content.scrollTop===0){startY=e.touches[0].pageY;pulling=true;}},{passive:true});
    content.addEventListener('touchmove',function(e){if(!pulling||STATE.isRefreshing)return;var diff=e.touches[0].pageY-startY;if(diff>0&&content.scrollTop===0){STATE.pullDistance=Math.min(diff*0.4,80);if(STATE.pullDistance>20){indicator.classList.add('visible');indicator.style.top=(STATE.pullDistance-30)+'px';}}},{passive:true});
    content.addEventListener('touchend',function(){if(STATE.pullDistance>60&&!STATE.isRefreshing){STATE.isRefreshing=true;indicator.classList.add('refreshing');triggerHaptic('medium');setTimeout(function(){location.reload();},800);}else{indicator.classList.remove('visible');}pulling=false;STATE.pullDistance=0;});
})();

function triggerHaptic(style){if('vibrate'in navigator){var p={light:10,medium:20,heavy:30,success:[10,50,10],error:[30,50,30]};navigator.vibrate(p[style]||10);}}

function showToast(msg,type){var t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(type?' '+type:'');triggerHaptic(type==='success'?'success':type==='error'?'error':'light');setTimeout(function(){t.classList.remove('show');},3000);}

function confirmSession(id,btn){
    btn.disabled=true;btn.textContent='...';
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_confirm_session&booking_id='+id+'&nonce='+CONFIG.nonce})
    .then(function(r){return r.json();})
    .then(function(res){
        if(res.success){triggerHaptic('success');showToast('Session confirmed!','success');var item=btn.closest('.confirm-item');if(item){item.style.transform='translateX(100%)';item.style.opacity='0';setTimeout(function(){item.remove();var banner=document.getElementById('confirmBanner');if(banner&&banner.querySelectorAll('.confirm-item').length===0)banner.style.display='none';},300);}}
        else{showToast(res.data&&res.data.message?res.data.message:'Error','error');btn.disabled=false;btn.textContent='Confirm';}
    }).catch(function(){showToast('Connection error','error');btn.disabled=false;btn.textContent='Confirm';});
}

function toggleDay(toggle){var day=toggle.dataset.day,isOn=toggle.classList.toggle('on');document.getElementById('start_'+day).disabled=!isOn;document.getElementById('end_'+day).disabled=!isOn;triggerHaptic('light');}

function saveAvailability(){
    var btn=document.getElementById('saveAvailBtn');btn.disabled=true;btn.textContent='Saving...';
    var availability={};['monday','tuesday','wednesday','thursday','friday','saturday','sunday'].forEach(function(day){var toggle=document.querySelector('.avail-toggle[data-day="'+day+'"]');availability[day]={enabled:toggle.classList.contains('on'),start:document.getElementById('start_'+day).value,end:document.getElementById('end_'+day).value};});
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_save_availability&trainer_id='+CONFIG.trainerId+'&nonce='+CONFIG.nonce+'&availability='+encodeURIComponent(JSON.stringify(availability))})
    .then(function(r){return r.json();})
    .then(function(res){if(res.success){triggerHaptic('success');showToast('Availability saved!','success');}else{showToast(res.data&&res.data.message?res.data.message:'Error','error');}btn.disabled=false;btn.textContent='Save';})
    .catch(function(){showToast('Connection error','error');btn.disabled=false;btn.textContent='Save';});
}

function openPayoutSheet(){document.getElementById('payoutSheetOverlay').classList.add('open');document.getElementById('payoutSheet').classList.add('open');triggerHaptic('light');}
function closePayoutSheet(){document.getElementById('payoutSheetOverlay').classList.remove('open');document.getElementById('payoutSheet').classList.remove('open');}
function openBlockDateSheet(){document.getElementById('blockDateSheetOverlay').classList.add('open');document.getElementById('blockDateSheet').classList.add('open');document.getElementById('blockDate').value='';document.getElementById('blockReason').value='';triggerHaptic('light');}
function closeBlockDateSheet(){document.getElementById('blockDateSheetOverlay').classList.remove('open');document.getElementById('blockDateSheet').classList.remove('open');}

function requestPayout(){
    var btn=document.getElementById('payoutBtn');btn.disabled=true;btn.textContent='Processing...';
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_request_payout&trainer_id='+CONFIG.trainerId+'&nonce='+CONFIG.nonce})
    .then(function(r){return r.json();})
    .then(function(res){if(res.success){triggerHaptic('success');showToast('Payout requested!','success');closePayoutSheet();setTimeout(function(){location.reload();},1500);}else{showToast(res.data&&res.data.message?res.data.message:'Error','error');btn.disabled=false;btn.textContent='Confirm Payout';}})
    .catch(function(){showToast('Connection error','error');btn.disabled=false;btn.textContent='Confirm Payout';});
}

function saveBlockedDate(){
    var date=document.getElementById('blockDate').value,reason=document.getElementById('blockReason').value;
    if(!date){showToast('Please select a date','error');return;}
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_block_date&date='+date+'&reason='+encodeURIComponent(reason)+'&nonce='+CONFIG.nonceGeneral})
    .then(function(r){return r.json();})
    .then(function(res){if(res.success){triggerHaptic('success');showToast('Date blocked!','success');closeBlockDateSheet();location.reload();}else{showToast(res.data&&res.data.message?res.data.message:'Error','error');}})
    .catch(function(){showToast('Connection error','error');});
}

function removeBlockedDate(date){
    if(!confirm('Remove this blocked date?'))return;
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_unblock_date&date='+date+'&nonce='+CONFIG.nonceGeneral})
    .then(function(r){return r.json();})
    .then(function(res){if(res.success){triggerHaptic('success');showToast('Date unblocked','success');location.reload();}else{showToast('Error','error');}});
}

function saveProfile(){
    var btn=event.target;btn.disabled=true;btn.textContent='Saving...';
    var data={hourly_rate:document.getElementById('hourlyRate').value,headline:document.getElementById('headline').value,bio:document.getElementById('bio').value};
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_update_trainer_profile&trainer_id='+CONFIG.trainerId+'&nonce='+CONFIG.nonce+'&data='+encodeURIComponent(JSON.stringify(data))})
    .then(function(r){return r.json();})
    .then(function(res){if(res.success){triggerHaptic('success');showToast('Profile updated!','success');}else{showToast(res.data&&res.data.message?res.data.message:'Error','error');}btn.disabled=false;btn.textContent='Save Changes';})
    .catch(function(){showToast('Connection error','error');btn.disabled=false;btn.textContent='Save Changes';});
}

function connectStripe(){
    showToast('Connecting to Stripe...');
    fetch(CONFIG.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=ptp_create_stripe_connect_account&nonce='+CONFIG.nonceGeneral})
    .then(function(r){return r.json();})
    .then(function(data){if(data.success&&data.data&&data.data.url){window.location.href=data.data.url;}else{showToast(data.data&&data.data.message?data.data.message:'Error','error');}})
    .catch(function(){showToast('Connection error','error');});
}

function handleProfileAction(action){
    switch(action){
        case 'photo':openPhotoUpload();break;
        case 'bio':switchTab('profile');setTimeout(function(){document.getElementById('bio').focus();},300);break;
        case 'stripe':connectStripe();break;
        case 'availability':switchTab('schedule');break;
    }
}

function openPhotoUpload(){var input=document.createElement('input');input.type='file';input.accept='image/*';input.onchange=function(e){var file=e.target.files[0];if(file)uploadPhoto(file);};input.click();}

function uploadPhoto(file){
    showToast('Uploading photo...');
    var formData=new FormData();formData.append('action','ptp_upload_trainer_photo');formData.append('nonce',CONFIG.nonceGeneral);formData.append('photo',file);
    fetch(CONFIG.ajaxUrl,{method:'POST',body:formData})
    .then(function(r){return r.json();})
    .then(function(res){if(res.success){triggerHaptic('success');showToast('Photo updated!','success');location.reload();}else{showToast(res.data&&res.data.message?res.data.message:'Upload failed','error');}})
    .catch(function(){showToast('Upload error','error');});
}

function openConversation(id){window.location.href='<?php echo esc_url(home_url('/messages/')); ?>?conversation='+id;}

function copyProfileLink(){
    var input=document.getElementById('shareUrl');input.select();input.setSelectionRange(0,99999);
    if(navigator.clipboard){navigator.clipboard.writeText(input.value).then(function(){triggerHaptic('success');showToast('Link copied!','success');});}
    else{document.execCommand('copy');triggerHaptic('success');showToast('Link copied!','success');}
}

function logout(){if(confirm('Are you sure you want to log out?')){window.location.href='<?php echo esc_url(wp_logout_url(home_url())); ?>';}}

document.addEventListener('DOMContentLoaded',function(){
    var params=new URLSearchParams(window.location.search);
    var tab=params.get('tab');if(tab)switchTab(tab);
    if(params.get('stripe_connected')||params.get('connected'))showToast('Stripe connected!','success');
});
</script>
<?php wp_footer(); ?>
</body>
</html>
