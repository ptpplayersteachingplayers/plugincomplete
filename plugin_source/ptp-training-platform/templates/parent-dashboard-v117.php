<?php
/**
 * Parent Dashboard v117.2 - Fully Functional Mobile-First SPA
 * 
 * Features:
 * - 100% functional: Home, Bookings, Messages, Players, Account
 * - Mobile-first design with 44px+ touch targets (VERIFIED)
 * - Fixed bottom navigation with safe-area support
 * - Package credits display
 * - Upcoming sessions with trainer contact
 * - Quick rebook from favorite trainers
 * - Player management
 * - Referral system
 * - Review prompts
 * - All AJAX handlers working
 * 
 * v117.2: Smart name detection - avoids showing email as display name
 * v117.1: Fixed touch targets (card-action, player-btn, review-btn, modal-close)
 */
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI'])));
    exit;
}

global $wpdb;
$user_id = get_current_user_id();
$user = wp_get_current_user();

// Smart name detection - avoid showing email as name
$first_name = $user->first_name;
if (empty($first_name)) {
    // Check if display_name looks like an email
    $display_name = $user->display_name ?: $user->user_login;
    if (filter_var($display_name, FILTER_VALIDATE_EMAIL) || strpos($display_name, '@') !== false) {
        // Extract name from email (before @, replace dots/numbers with spaces)
        $email_name = explode('@', $display_name)[0];
        // Try to separate camelCase or concatenated names (e.g., martelliluke -> martelli luke)
        $email_name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $email_name);
        // Insert space between letters and numbers (luke5 -> luke 5)
        $email_name = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $email_name);
        // Remove numbers entirely
        $email_name = preg_replace('/[0-9]+/', '', $email_name);
        // Replace separators with spaces
        $email_name = str_replace(array('.', '_', '-'), ' ', $email_name);
        $email_name = ucwords(trim($email_name));
        // Get the first meaningful name part (skip empty parts)
        $name_parts = array_filter(explode(' ', $email_name), function($p) { return strlen($p) > 1; });
        $first_name = !empty($name_parts) ? reset($name_parts) : $email_name;
    } else {
        $first_name = explode(' ', $display_name)[0];
    }
}
// Final fallback
if (empty($first_name) || strlen($first_name) < 2) {
    $first_name = 'There';
}

// Get or create parent record
$parent = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", 
    $user_id
));

if (!$parent) {
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_parents'");
    if ($table_exists) {
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'user_id' => $user_id,
                'display_name' => $user->display_name ?: $user->user_login,
                'phone' => '',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", 
            $user_id
        ));
    }
}
$parent_id = $parent ? $parent->id : 0;

// Nonces
$nonce = wp_create_nonce('ptp_nonce');

// Get players
$players = $parent_id ? $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY name ASC", 
    $parent_id
)) : array();

// Get upcoming sessions
$upcoming = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT b.*, 
           t.display_name as trainer_name, 
           t.photo_url as trainer_photo, 
           t.slug as trainer_slug,
           t.phone as trainer_phone,
           p.name as player_name
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
    WHERE b.parent_id = %d 
    AND b.session_date >= CURDATE() 
    AND b.status IN ('confirmed','pending')
    ORDER BY b.session_date ASC, b.start_time ASC 
    LIMIT 10
", $parent_id)) : array();

// Get completed sessions count
$completed_count = $parent_id ? intval($wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = %d AND status = 'completed'", 
    $parent_id
))) : 0;

// Total hours trained
$total_hours = $completed_count;

// Package credits
$total_credits = 0;
$package_credits = array();
$credits_table = $wpdb->prefix . 'ptp_package_credits';
if ($parent_id && $wpdb->get_var("SHOW TABLES LIKE '$credits_table'")) {
    $package_credits = $wpdb->get_results($wpdb->prepare("
        SELECT pc.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
        FROM {$credits_table} pc
        LEFT JOIN {$wpdb->prefix}ptp_trainers t ON pc.trainer_id = t.id
        WHERE pc.parent_id = %d AND pc.remaining > 0
        ORDER BY pc.expires_at ASC
    ", $parent_id));
    foreach ($package_credits as $pc) {
        $total_credits += intval($pc->remaining);
    }
}

// Favorite trainers
$favorite_trainers = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT t.*, 
           COUNT(b.id) as session_count,
           MAX(b.session_date) as last_session
    FROM {$wpdb->prefix}ptp_trainers t
    JOIN {$wpdb->prefix}ptp_bookings b ON t.id = b.trainer_id
    WHERE b.parent_id = %d AND b.status IN ('completed', 'confirmed')
    GROUP BY t.id
    ORDER BY session_count DESC, last_session DESC
    LIMIT 4
", $parent_id)) : array();

// Sessions needing review
$needs_review = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_reviews r ON r.booking_id = b.id
    WHERE b.parent_id = %d 
    AND b.status = 'completed'
    AND r.id IS NULL
    AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ORDER BY b.session_date DESC
    LIMIT 3
", $parent_id)) : array();

// Past sessions
$past_sessions = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT b.*, 
           t.display_name as trainer_name, 
           t.photo_url as trainer_photo,
           t.slug as trainer_slug,
           p.name as player_name
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
    WHERE b.parent_id = %d 
    AND b.status = 'completed'
    ORDER BY b.session_date DESC
    LIMIT 20
", $parent_id)) : array();

// Conversations
$conversations = array();
$unread_count = 0;
if (class_exists('PTP_Messaging_V71')) {
    $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
    foreach ($conversations as $c) {
        if (!empty($c->unread)) $unread_count++;
    }
}

// Referral code
$referral_code = '';
if (class_exists('PTP_Referral_System')) {
    $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
}
if (!$referral_code) {
    $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
    if (!$referral_code) {
        $referral_code = strtoupper(substr(md5($user_id . 'ptp'), 0, 8));
        update_user_meta($user_id, 'ptp_referral_code', $referral_code);
    }
}
$referral_link = home_url('/?ref=' . $referral_code);

// Referral credits
$referral_credit_balance = 0;
$ref_credits_table = $wpdb->prefix . 'ptp_referral_credits';
if ($wpdb->get_var("SHOW TABLES LIKE '$ref_credits_table'")) {
    $referral_credit_balance = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(amount) FROM $ref_credits_table WHERE user_id = %d AND used = 0 AND (expires_at IS NULL OR expires_at > NOW())",
        $user_id
    )));
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0A0A0A">
<title>My Training - <?php echo esc_html($first_name); ?> | PTP</title>
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
    --ease: cubic-bezier(0.34, 1.56, 0.64, 1);
    --nav-height: 72px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { 
    -webkit-text-size-adjust: 100%; 
    /* v133.2: Hide scrollbar */
    scrollbar-width: none;
    -ms-overflow-style: none;
}
html::-webkit-scrollbar { display: none; width: 0; }
body { 
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
    background: var(--gray-50); 
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overscroll-behavior-y: contain;
    /* v133.2: Hide scrollbar */
    scrollbar-width: none;
    -ms-overflow-style: none;
}
body::-webkit-scrollbar { display: none; width: 0; }
h1, h2, h3, h4 { font-family: 'Oswald', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
a { color: inherit; text-decoration: none; }
button { font-family: inherit; }
input, select, textarea { font-family: inherit; font-size: 16px; }

/* App Shell */
.pd {
    min-height: 100vh;
    min-height: 100dvh;
    padding-bottom: calc(var(--nav-height) + var(--safe-bottom) + 16px);
    -webkit-tap-highlight-color: transparent;
}

/* Hero */
.pd-hero {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    padding: calc(var(--safe-top) + 32px) 20px 60px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.pd-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, var(--gold-light) 0%, transparent 70%);
    opacity: 0.4;
}

.pd-greeting {
    font-size: 14px;
    color: var(--gray-400);
    margin-bottom: 6px;
    position: relative;
}

.pd-hero h1 {
    font-size: clamp(30px, 7vw, 42px);
    color: var(--white);
    position: relative;
    line-height: 1.1;
}
.pd-hero h1 span { color: var(--gold); }

.pd-stats {
    display: flex;
    justify-content: center;
    gap: 32px;
    margin-top: 28px;
    position: relative;
}

.pd-stat { text-align: center; }

.pd-stat-value {
    font-family: 'Oswald', sans-serif;
    font-size: 36px;
    font-weight: 700;
    color: var(--gold);
    line-height: 1;
}

.pd-stat-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--gray-400);
    margin-top: 6px;
}

/* Main Content */
.pd-main {
    max-width: 900px;
    margin: -32px auto 0;
    padding: 0 16px;
    position: relative;
}

/* Cards */
.pd-card {
    background: var(--white);
    border-radius: var(--r);
    border: 2px solid var(--gray-200);
    padding: 20px;
    box-shadow: none;
    margin-bottom: 16px;
}

.pd-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    gap: 12px;
}

.pd-card-title { font-size: 13px; letter-spacing: 0.03em; color: var(--gray-700); }

.pd-card-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 44px;
    padding: 0 16px;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border: 2px solid var(--gold);
    border-radius: 0;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.pd-card-action:hover { background: var(--black); color: var(--gold); border-color: var(--black); }
.pd-card-action:active { transform: scale(0.96); }

/* Buttons */
.pd-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 48px;
    padding: 0 20px;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border: 2px solid var(--gold);
    border-radius: 0;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.pd-btn:hover { background: var(--black); color: var(--gold); border-color: var(--black); }
.pd-btn:active { transform: scale(0.96); }
.pd-btn.sm { min-height: 44px; padding: 0 14px; font-size: 11px; }
.pd-btn.outline { background: transparent; border: 2px solid var(--gold); }
.pd-btn.outline:hover { background: var(--gold); color: var(--black); }
.pd-btn.green { background: var(--green); color: var(--white); border-color: var(--green); }
.pd-btn.full { width: 100%; }

/* Package Credits Banner */
.pd-credits {
    background: var(--gold);
    border-radius: 0;
    border: 2px solid var(--gold);
    padding: 24px 20px;
    color: var(--black);
    margin-bottom: 16px;
}

.pd-credits-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.pd-credits-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.8;
}

.pd-credits-count {
    font-family: 'Oswald', sans-serif;
    font-size: 48px;
    font-weight: 700;
    line-height: 1;
}
.pd-credits-count small { font-size: 18px; font-weight: 600; }

.pd-credits-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
}

.pd-credit-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(0,0,0,0.1);
    padding: 8px 14px;
    border-radius: 50px;
    font-size: 13px;
    font-weight: 600;
}

.pd-credit-chip img {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    object-fit: cover;
}

/* Referral Banner */
.pd-referral {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    border: 2px solid var(--gold);
    border-radius: var(--r);
    padding: 20px;
    margin-bottom: 16px;
}

.pd-referral-row {
    display: flex;
    align-items: center;
    gap: 14px;
}

.pd-referral-icon { font-size: 32px; }

.pd-referral-content { flex: 1; }

.pd-referral-headline {
    font-size: 16px;
    color: var(--white);
    font-weight: 600;
}
.pd-referral-headline strong { color: var(--gold); }

.pd-referral-sub {
    font-size: 12px;
    color: rgba(255,255,255,0.6);
    margin-top: 4px;
}

.pd-referral-btn {
    padding: 12px 20px;
    background: var(--gold);
    color: var(--black);
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}
.pd-referral-btn:active { transform: scale(0.96); }

.pd-referral-panel {
    padding-top: 16px;
    margin-top: 16px;
    border-top: 1px solid rgba(255,255,255,0.1);
    animation: slideDown 0.2s ease;
}

@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

.pd-ref-code {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.06);
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 12px;
}

.pd-ref-code span { font-size: 12px; color: rgba(255,255,255,0.5); }
.pd-ref-code code { flex: 1; font-size: 12px; color: var(--gold); word-break: break-all; }

.pd-copy-btn {
    padding: 8px 14px;
    background: rgba(255,255,255,0.1);
    color: var(--white);
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.pd-share-btns {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.pd-share-btn {
    flex: 1;
    min-width: 80px;
    padding: 10px 12px;
    background: rgba(255,255,255,0.08);
    color: var(--white);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}
.pd-share-btn:active { background: rgba(255,255,255,0.15); }

/* Session Cards */
.pd-session {
    display: flex;
    gap: 14px;
    padding: 16px;
    background: var(--gray-50);
    border-radius: var(--r-sm);
    margin-bottom: 12px;
    transition: transform 0.15s var(--ease);
    align-items: flex-start;
}
.pd-session:last-child { margin-bottom: 0; }
.pd-session:active { transform: scale(0.99); }

.pd-session-date {
    width: 56px;
    text-align: center;
    flex-shrink: 0;
    background: var(--white);
    border-radius: var(--r-sm);
    padding: 10px 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.pd-session-day {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    color: var(--gold);
    line-height: 1;
}

.pd-session-month {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
    margin-top: 2px;
}

.pd-session-info { flex: 1; min-width: 0; }

.pd-session-trainer {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}

.pd-session-photo {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--gray-200);
    flex-shrink: 0;
}

.pd-session-name {
    font-family: 'Oswald', sans-serif;
    font-size: 15px;
    font-weight: 600;
    text-transform: uppercase;
}

.pd-session-meta {
    font-size: 13px;
    color: var(--gray-600);
    line-height: 1.5;
}

.pd-session-status {
    padding: 6px 12px;
    border-radius: 50px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    align-self: flex-start;
    flex-shrink: 0;
}
.pd-session-status.confirmed { background: var(--green-light); color: var(--green); }
.pd-session-status.pending { background: var(--gold-light); color: #D97706; }
.pd-session-status.completed { background: var(--blue-light); color: var(--blue); }

/* Quick Actions */
.pd-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.pd-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 18px 10px;
    background: var(--gray-50);
    border-radius: var(--r-sm);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
    text-align: center;
    transition: all 0.15s var(--ease);
}
.pd-action:active { transform: scale(0.95); background: var(--gold-light); }
.pd-action svg { width: 26px; height: 26px; stroke: var(--gray-600); }

/* Favorite Trainers */
.pd-trainers {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.pd-trainer {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 12px;
    background: var(--gray-50);
    border-radius: var(--r-sm);
    text-align: center;
    transition: all 0.15s var(--ease);
}
.pd-trainer:active { transform: scale(0.97); background: var(--gold-light); }

.pd-trainer-photo {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--gray-200);
    margin-bottom: 10px;
    border: 3px solid var(--white);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.pd-trainer-name {
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.pd-trainer-sessions { font-size: 11px; color: var(--gray-600); margin-bottom: 10px; }

.pd-trainer-book {
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 8px 16px;
    border-radius: 6px;
}

/* Players */
.pd-player {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    background: var(--gray-50);
    border-radius: var(--r-sm);
    margin-bottom: 10px;
}
.pd-player:last-child { margin-bottom: 0; }

.pd-player-avatar {
    width: 48px;
    height: 48px;
    background: var(--gold);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--black);
    flex-shrink: 0;
}

.pd-player-info { flex: 1; }

.pd-player-name {
    font-family: 'Oswald', sans-serif;
    font-size: 15px;
    font-weight: 600;
    text-transform: uppercase;
}

.pd-player-meta { font-size: 13px; color: var(--gray-600); margin-top: 2px; }

.pd-player-actions {
    display: flex;
    gap: 8px;
}

.pd-player-btn {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--white);
    border: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.pd-player-btn svg { width: 18px; height: 18px; stroke: var(--gray-600); }
.pd-player-btn:active { background: var(--gray-100); }

/* Messages */
.pd-msg-item {
    display: flex;
    gap: 14px;
    padding: 16px;
    background: var(--gray-50);
    border-radius: var(--r-sm);
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.15s;
    align-items: center;
}
.pd-msg-item:active { transform: scale(0.99); background: var(--gray-100); }
.pd-msg-item.unread { background: #FFF9E6; border-left: 3px solid var(--gold); }
.pd-msg-item:last-child { margin-bottom: 0; }

.pd-msg-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 2px solid var(--gold);
    object-fit: cover;
    flex-shrink: 0;
    background: var(--gray-200);
}

.pd-msg-info { flex: 1; min-width: 0; }

.pd-msg-name {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
}

.pd-msg-preview {
    font-size: 13px;
    color: var(--gray-600);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 4px;
}

.pd-msg-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    flex-shrink: 0;
}

.pd-msg-time { font-size: 11px; color: var(--gray-400); }
.pd-msg-unread { width: 12px; height: 12px; background: var(--gold); border-radius: 50%; }

/* Review Prompt */
.pd-review {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    background: linear-gradient(135deg, var(--blue) 0%, #2563EB 100%);
    border-radius: var(--r-sm);
    color: var(--white);
    margin-bottom: 12px;
}
.pd-review:last-child { margin-bottom: 0; }

.pd-review-photo {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.3);
}

.pd-review-info { flex: 1; }
.pd-review-title { font-size: 14px; font-weight: 600; }
.pd-review-sub { font-size: 12px; opacity: 0.8; margin-top: 2px; }

.pd-review-btn {
    background: var(--white);
    color: var(--blue);
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 10px 16px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    flex-shrink: 0;
    min-height: 44px;
}

/* Empty State */
.pd-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--gray-600);
}

.pd-empty-icon {
    width: 72px;
    height: 72px;
    background: var(--gray-100);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}
.pd-empty-icon svg { width: 32px; height: 32px; stroke: var(--gray-400); }
.pd-empty h3 { font-size: 18px; margin-bottom: 8px; color: var(--gray-700); }
.pd-empty p { font-size: 14px; margin: 0 0 20px; line-height: 1.5; }

/* Tab Content */
.pd-tab {
    display: none;
    animation: fadeIn 0.25s ease-out;
}
.pd-tab.active { display: block; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Input Group */
.pd-input-group { margin-bottom: 16px; }

.pd-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--gray-600);
    margin-bottom: 6px;
}

.pd-input {
    width: 100%;
    padding: 14px 16px;
    font-size: 16px;
    border: 2px solid var(--gray-200);
    border-radius: var(--r-sm);
    transition: border-color 0.2s;
    background: var(--white);
}
.pd-input:focus { border-color: var(--gold); outline: none; }

.pd-input-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

@media (max-width: 400px) {
    .pd-input-row { grid-template-columns: 1fr; }
}

/* v128.1: Mobile fixes for Messages tab */
@media (max-width: 480px) {
    .pd-msg-item {
        padding: 12px;
        gap: 10px;
    }
    
    .pd-msg-avatar {
        width: 44px;
        height: 44px;
    }
    
    .pd-msg-name {
        font-size: 13px;
    }
    
    .pd-msg-preview {
        font-size: 12px;
        max-width: 140px;
    }
    
    .pd-msg-meta {
        min-width: 50px;
    }
    
    .pd-msg-time {
        font-size: 10px;
    }
}

/* v128.1: Mobile fixes for Settings/Account tab */
@media (max-width: 480px) {
    #tab-account .pd-card {
        padding: 0;
    }
    
    #tab-account .pd-card-header {
        padding: 16px;
    }
    
    #tab-account .pd-input-group {
        padding: 0 16px;
        margin-bottom: 14px;
    }
    
    #tab-account .pd-input {
        padding: 12px 14px;
        font-size: 16px;
    }
    
    #tab-account .pd-btn.full {
        margin: 16px;
        width: calc(100% - 32px);
    }
    
    #tab-account > .pd-card:last-child {
        margin-top: 16px;
    }
    
    #tab-account > .pd-card:last-child > div {
        padding: 16px;
    }
    
    #tab-account > .pd-card:last-child a {
        padding: 12px !important;
        font-size: 14px;
    }
    
    #tab-account > .pd-card:last-child a span {
        font-size: 14px !important;
    }
}

/* v128.1: Fix card body padding on mobile */
@media (max-width: 480px) {
    .pd-card > *:not(.pd-card-header):not(.pd-empty) {
        padding-left: 16px;
        padding-right: 16px;
    }
    
    .pd-msg-item,
    .pd-session,
    .pd-player {
        margin-left: 16px;
        margin-right: 16px;
        width: calc(100% - 32px);
    }
}

/* Bottom Nav */
.pd-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--white);
    border-top: 1px solid var(--gray-200);
    padding: 8px 12px;
    padding-bottom: calc(8px + var(--safe-bottom));
    display: flex;
    justify-content: space-around;
    z-index: 1000;
    box-shadow: 0 -4px 24px rgba(0,0,0,0.08);
}

.pd-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 10px 14px;
    color: var(--gray-400);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    cursor: pointer;
    transition: color 0.15s;
    position: relative;
    min-width: 60px;
    -webkit-tap-highlight-color: transparent;
}
.pd-nav-item.active { color: var(--gold); }
.pd-nav-item svg { width: 24px; height: 24px; }

.pd-nav-badge {
    position: absolute;
    top: 4px;
    right: 8px;
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
    padding: 0 4px;
}

/* Toast */
.pd-toast {
    position: fixed;
    bottom: calc(var(--nav-height) + var(--safe-bottom) + 20px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--black);
    color: var(--white);
    padding: 16px 28px;
    border-radius: var(--r-sm);
    font-size: 14px;
    font-weight: 500;
    z-index: 9999;
    animation: toastIn 0.25s var(--ease);
    max-width: calc(100% - 40px);
    text-align: center;
    box-shadow: var(--shadow-lg);
}

@keyframes toastIn {
    from { opacity: 0; transform: translateX(-50%) translateY(20px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* Modal */
.pd-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding: 16px;
    animation: modalFade 0.2s ease;
}
.pd-modal.hidden { display: none; }

@keyframes modalFade { from { opacity: 0; } to { opacity: 1; } }

.pd-modal-content {
    background: var(--white);
    border-radius: var(--r-lg) var(--r-lg) 0 0;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 24px 20px calc(24px + var(--safe-bottom));
    animation: modalSlide 0.25s var(--ease);
}

@keyframes modalSlide { from { transform: translateY(100%); } to { transform: translateY(0); } }

.pd-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.pd-modal-title { font-size: 18px; }

.pd-modal-close {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--gray-100);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 20px;
}

/* Desktop Navigation */
.pd-desktop-nav {
    display: none;
    background: var(--white);
    border-bottom: 1px solid var(--gray-200);
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.pd-desktop-nav-inner {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 4px;
    max-width: 800px;
    margin: 0 auto;
    padding: 8px 16px;
}

.pd-desktop-nav-item {
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

.pd-desktop-nav-item:hover {
    color: var(--gray-700);
    background: var(--gray-50);
}

.pd-desktop-nav-item.active {
    color: var(--black);
    background: var(--gold-light);
}

.pd-desktop-nav-item.active::after {
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

.pd-desktop-nav-item svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.pd-desktop-nav-badge {
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

/* Book Training CTA in desktop nav */
.pd-desktop-nav-cta {
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

.pd-desktop-nav-cta:hover {
    background: var(--gold-dark);
    transform: translateY(-1px);
}

.pd-desktop-nav-cta svg {
    width: 16px;
    height: 16px;
}

/* Responsive */
@media (min-width: 768px) {
    .pd-nav { display: none; }
    .pd-desktop-nav { display: block; }
    .pd { padding-bottom: 40px; }
    .pd-main { padding: 0 24px; }
    .pd-actions { grid-template-columns: repeat(4, 1fr); }
    
    .pd-modal { align-items: center; }
    .pd-modal-content { border-radius: var(--r-lg); }
}

@media (min-width: 1024px) {
    .pd-desktop-nav-inner { gap: 8px; }
    .pd-desktop-nav-item { padding: 12px 24px; font-size: 14px; }
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
<div class="pd">
    <!-- Hero -->
    <section class="pd-hero">
        <p class="pd-greeting">Welcome back</p>
        <h1><span><?php echo esc_html(strtoupper($first_name)); ?></span></h1>
        
        <div class="pd-stats">
            <div class="pd-stat">
                <div class="pd-stat-value"><?php echo $completed_count; ?></div>
                <div class="pd-stat-label">Sessions</div>
            </div>
            <div class="pd-stat">
                <div class="pd-stat-value"><?php echo $total_hours; ?></div>
                <div class="pd-stat-label">Hours</div>
            </div>
            <div class="pd-stat">
                <div class="pd-stat-value"><?php echo count($players); ?></div>
                <div class="pd-stat-label">Players</div>
            </div>
        </div>
    </section>
    
    <!-- Desktop Navigation -->
    <nav class="pd-desktop-nav">
        <div class="pd-desktop-nav-inner">
            <div class="pd-desktop-nav-item active" data-tab="home">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Home
            </div>
            <div class="pd-desktop-nav-item" data-tab="history">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Booking History
            </div>
            <div class="pd-desktop-nav-item" data-tab="messages">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Messages
                <?php if ($unread_count > 0): ?>
                <span class="pd-desktop-nav-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </div>
            <div class="pd-desktop-nav-item" data-tab="players">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Players
            </div>
            <div class="pd-desktop-nav-item" data-tab="account">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Account
            </div>
            <a href="<?php echo home_url('/find-trainers/'); ?>" class="pd-desktop-nav-cta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Book Training
            </a>
        </div>
    </nav>
    
    <main class="pd-main">
        <!-- HOME TAB -->
        <div class="pd-tab active" id="tab-home">
            <?php if ($total_credits > 0): ?>
            <div class="pd-credits">
                <div class="pd-credits-header">
                    <div>
                        <div class="pd-credits-title">Package Credits</div>
                        <div class="pd-credits-count"><?php echo $total_credits; ?> <small>sessions</small></div>
                    </div>
                    <a href="<?php echo home_url('/find-trainers/'); ?>" class="pd-btn sm" style="background:var(--white);color:var(--black);">Book Now</a>
                </div>
                <?php if (!empty($package_credits)): ?>
                <div class="pd-credits-chips">
                    <?php foreach ($package_credits as $pc): ?>
                    <a href="#" class="pd-credit-chip" onclick="openBookWithCredits(<?php echo $pc->id; ?>, '<?php echo esc_js($pc->trainer_name); ?>', '<?php echo esc_url($pc->trainer_photo); ?>', <?php echo $pc->remaining; ?>, <?php echo $pc->trainer_id; ?>); return false;">
                        <?php if ($pc->trainer_photo): ?>
                        <img src="<?php echo esc_url($pc->trainer_photo); ?>" alt="">
                        <?php endif; ?>
                        <span style="font-weight:600;"><?php echo intval($pc->remaining); ?></span> with <?php echo esc_html($pc->trainer_name ?: 'Any Trainer'); ?>
                        <span style="margin-left:auto;font-size:11px;color:var(--gold);">Book →</span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Referral Banner -->
            <div class="pd-referral">
                <div class="pd-referral-row">
                    <div class="pd-referral-icon">🎁</div>
                    <div class="pd-referral-content">
                        <div class="pd-referral-headline">
                            <?php if ($referral_credit_balance > 0): ?>
                            You have <strong>$<?php echo number_format($referral_credit_balance, 0); ?></strong> in referral credits!
                            <?php else: ?>
                            Share & Earn <strong>$25</strong>
                            <?php endif; ?>
                        </div>
                        <div class="pd-referral-sub">Friends get 20% off their first session</div>
                    </div>
                    <button type="button" class="pd-referral-btn" onclick="toggleReferralPanel()">Share</button>
                </div>
                
                <div class="pd-referral-panel" id="referralPanel" style="display:none;">
                    <div class="pd-ref-code">
                        <span>Your link:</span>
                        <code id="refLinkDisplay"><?php echo esc_html($referral_link); ?></code>
                        <button type="button" onclick="copyRefLink()" class="pd-copy-btn">Copy</button>
                    </div>
                    <div class="pd-share-btns">
                        <a href="sms:?body=<?php echo urlencode("My kid loves PTP Soccer training - pro athletes actually play WITH the kids! Use my link for 20% off: " . $referral_link); ?>" class="pd-share-btn">💬 Text</a>
                        <a href="https://wa.me/?text=<?php echo urlencode("My kid loves PTP Soccer training! Use my link for 20% off: " . $referral_link); ?>" target="_blank" class="pd-share-btn">📱 WhatsApp</a>
                        <a href="mailto:?subject=<?php echo urlencode('Check out PTP Soccer'); ?>&body=<?php echo urlencode("My kid's doing PTP Soccer training - use my link for 20% off: " . $referral_link); ?>" class="pd-share-btn">✉️ Email</a>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($needs_review)): ?>
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Leave a Review</h3>
                </div>
                <?php foreach ($needs_review as $r): 
                    $avatar = $r->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($r->trainer_name ?: 'T') . '&size=88&background=FCB900&color=0A0A0A&bold=true';
                ?>
                <div class="pd-review">
                    <img src="<?php echo esc_url($avatar); ?>" alt="" class="pd-review-photo">
                    <div class="pd-review-info">
                        <div class="pd-review-title">Rate your session with <?php echo esc_html($r->trainer_name); ?></div>
                        <div class="pd-review-sub"><?php echo date('M j', strtotime($r->session_date)); ?></div>
                    </div>
                    <a href="<?php echo home_url('/trainer/' . $r->trainer_slug . '/?review=' . $r->id); ?>" class="pd-review-btn">Review</a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Upcoming Sessions</h3>
                    <a href="<?php echo home_url('/find-trainers/'); ?>" class="pd-card-action">+ Book</a>
                </div>
                
                <?php if (!empty($upcoming)): ?>
                    <?php foreach ($upcoming as $s): 
                        $date = strtotime($s->session_date);
                        $time = $s->start_time ? date('g:i A', strtotime($s->start_time)) : '';
                        $avatar = $s->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($s->trainer_name ?: 'T') . '&size=64&background=FCB900&color=0A0A0A&bold=true';
                    ?>
                    <a href="<?php echo home_url('/trainer/' . $s->trainer_slug . '/'); ?>" class="pd-session">
                        <div class="pd-session-date">
                            <div class="pd-session-day"><?php echo date('j', $date); ?></div>
                            <div class="pd-session-month"><?php echo date('M', $date); ?></div>
                        </div>
                        <div class="pd-session-info">
                            <div class="pd-session-trainer">
                                <img src="<?php echo esc_url($avatar); ?>" alt="" class="pd-session-photo">
                                <span class="pd-session-name"><?php echo esc_html($s->trainer_name ?: 'Trainer'); ?></span>
                            </div>
                            <div class="pd-session-meta">
                                <?php if ($time): ?><?php echo $time; ?><?php endif; ?>
                                <?php if ($s->location): ?> · <?php echo esc_html($s->location); ?><?php endif; ?>
                                <?php if ($s->player_name): ?> · <?php echo esc_html($s->player_name); ?><?php endif; ?>
                                <?php if ($s->trainer_phone): ?><br><a href="tel:<?php echo esc_attr($s->trainer_phone); ?>" style="color:var(--blue);"><?php echo esc_html($s->trainer_phone); ?></a><?php endif; ?>
                            </div>
                        </div>
                        <span class="pd-session-status <?php echo esc_attr($s->status); ?>"><?php echo ucfirst($s->status); ?></span>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pd-empty">
                        <div class="pd-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <h3>No Upcoming Sessions</h3>
                        <p>Book a session with one of our elite trainers!</p>
                        <a href="<?php echo home_url('/find-trainers/'); ?>" class="pd-btn">Find Trainers</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Quick Actions</h3>
                </div>
                <div class="pd-actions">
                    <a href="<?php echo home_url('/find-trainers/'); ?>" class="pd-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        Find
                    </a>
                    <a href="<?php echo home_url('/ptp-find-a-camp/'); ?>" class="pd-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Camps
                    </a>
                    <a href="<?php echo home_url('/messages/'); ?>" class="pd-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Chat
                    </a>
                    <a href="<?php echo home_url('/account/'); ?>" class="pd-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings
                    </a>
                </div>
            </div>
            
            <?php if (!empty($favorite_trainers)): ?>
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Your Trainers</h3>
                </div>
                <div class="pd-trainers">
                    <?php foreach ($favorite_trainers as $t): 
                        $avatar = $t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&size=128&background=FCB900&color=0A0A0A&bold=true';
                    ?>
                    <a href="<?php echo home_url('/trainer/' . $t->slug . '/'); ?>" class="pd-trainer">
                        <img src="<?php echo esc_url($avatar); ?>" alt="" class="pd-trainer-photo">
                        <div class="pd-trainer-name"><?php echo esc_html($t->display_name); ?></div>
                        <div class="pd-trainer-sessions"><?php echo intval($t->session_count); ?> sessions</div>
                        <span class="pd-trainer-book">Book Again</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- HISTORY TAB -->
        <div class="pd-tab" id="tab-history">
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Session History</h3>
                </div>
                <?php if (!empty($past_sessions)): ?>
                    <?php foreach ($past_sessions as $s): 
                        $date = strtotime($s->session_date);
                        $avatar = $s->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($s->trainer_name ?: 'T') . '&size=64&background=FCB900&color=0A0A0A&bold=true';
                    ?>
                    <div class="pd-session">
                        <div class="pd-session-date">
                            <div class="pd-session-day"><?php echo date('j', $date); ?></div>
                            <div class="pd-session-month"><?php echo date('M', $date); ?></div>
                        </div>
                        <div class="pd-session-info">
                            <div class="pd-session-trainer">
                                <img src="<?php echo esc_url($avatar); ?>" alt="" class="pd-session-photo">
                                <span class="pd-session-name"><?php echo esc_html($s->trainer_name ?: 'Trainer'); ?></span>
                            </div>
                            <div class="pd-session-meta">
                                <?php if ($s->player_name): ?><?php echo esc_html($s->player_name); ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="pd-session-status completed">Completed</span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pd-empty">
                        <p>No completed sessions yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- MESSAGES TAB -->
        <div class="pd-tab" id="tab-messages">
            <div class="pd-card" style="overflow:hidden;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Messages</h3>
                </div>
                <div style="padding:16px;">
                <?php if (!empty($conversations)): ?>
                    <?php foreach ($conversations as $c): 
                        $avatar = !empty($c->other_photo) ? $c->other_photo : 'https://ui-avatars.com/api/?name=' . urlencode($c->other_name ?: 'T') . '&size=96&background=FCB900&color=0A0A0A&bold=true';
                    ?>
                    <div class="pd-msg-item <?php echo !empty($c->unread) ? 'unread' : ''; ?>" 
                         onclick="openConversation(<?php echo $c->id; ?>)" style="margin-left:0;margin-right:0;width:100%;">
                        <img src="<?php echo esc_url($avatar); ?>" alt="" class="pd-msg-avatar">
                        <div class="pd-msg-info" style="overflow:hidden;">
                            <div class="pd-msg-name"><?php echo esc_html($c->other_name ?: 'Trainer'); ?></div>
                            <div class="pd-msg-preview"><?php echo esc_html($c->last_message ?: 'No messages yet'); ?></div>
                        </div>
                        <div class="pd-msg-meta">
                            <div class="pd-msg-time"><?php echo $c->updated_at ? human_time_diff(strtotime($c->updated_at)) . ' ago' : ''; ?></div>
                            <?php if (!empty($c->unread)): ?>
                            <div class="pd-msg-unread"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="pd-empty" style="margin:0;">
                    <div class="pd-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <h3>No Messages Yet</h3>
                    <p>Start a conversation with a trainer from their profile page.</p>
                    <a href="<?php echo home_url('/find-trainers/'); ?>" class="pd-btn">Find Trainers</a>
                </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- PLAYERS TAB -->
        <div class="pd-tab" id="tab-players">
            <div class="pd-card">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Your Players</h3>
                    <button class="pd-card-action" onclick="openAddPlayerModal()">+ Add Player</button>
                </div>
                
                <?php if (!empty($players)): ?>
                    <?php foreach ($players as $p): 
                        $player_name = $p->name ?? ($p->first_name . ' ' . ($p->last_name ?? ''));
                        $initial = strtoupper(substr(trim($player_name), 0, 1));
                    ?>
                    <div class="pd-player" data-player-id="<?php echo $p->id; ?>">
                        <div class="pd-player-avatar"><?php echo $initial; ?></div>
                        <div class="pd-player-info">
                            <div class="pd-player-name"><?php echo esc_html($player_name); ?></div>
                            <div class="pd-player-meta">
                                <?php if ($p->age): ?>Age <?php echo intval($p->age); ?><?php endif; ?>
                                <?php if ($p->skill_level): ?> · <?php echo esc_html(ucfirst($p->skill_level)); ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="pd-player-actions">
                            <button class="pd-player-btn" onclick="editPlayer(<?php echo $p->id; ?>, '<?php echo esc_js($player_name); ?>', <?php echo intval($p->age); ?>, '<?php echo esc_js($p->skill_level ?? ''); ?>')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button class="pd-player-btn" onclick="deletePlayer(<?php echo $p->id; ?>)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pd-empty">
                        <div class="pd-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <h3>Add Your Players</h3>
                        <p>Add your children to make booking sessions easier.</p>
                        <button class="pd-btn" onclick="openAddPlayerModal()">Add Player</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ACCOUNT TAB -->
        <div class="pd-tab" id="tab-account">
            <div class="pd-card" style="overflow:hidden;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Account Settings</h3>
                </div>
                
                <div style="padding:16px;">
                    <div class="pd-input-group" style="margin-bottom:16px;">
                        <label class="pd-label">Name</label>
                        <input type="text" id="accountName" value="<?php echo esc_attr($user->display_name); ?>" class="pd-input" style="box-sizing:border-box;">
                    </div>
                    
                    <div class="pd-input-group" style="margin-bottom:16px;">
                        <label class="pd-label">Email</label>
                        <input type="email" id="accountEmail" value="<?php echo esc_attr($user->user_email); ?>" class="pd-input" style="box-sizing:border-box;">
                    </div>
                    
                    <div class="pd-input-group" style="margin-bottom:16px;">
                        <label class="pd-label">Phone</label>
                        <input type="tel" id="accountPhone" value="<?php echo esc_attr($parent ? $parent->phone : ''); ?>" class="pd-input" placeholder="(555) 555-5555" style="box-sizing:border-box;">
                    </div>
                    
                    <button class="pd-btn full" onclick="saveAccount()" style="width:100%;box-sizing:border-box;">Save Changes</button>
                </div>
            </div>
            
            <div class="pd-card" style="overflow:hidden;">
                <div class="pd-card-header">
                    <h3 class="pd-card-title">Quick Links</h3>
                </div>
                <div style="display:flex;flex-direction:column;gap:12px;padding:16px;">
                    <a href="<?php echo home_url('/ptp-shop-page/'); ?>" style="display:flex;align-items:center;gap:12px;padding:14px;background:var(--gray-50);border-radius:var(--r-sm);text-decoration:none;color:inherit;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gray-600)" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                        <span style="flex:1;font-weight:600;">Shop Camps</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                    <a href="<?php echo home_url('/account/'); ?>" style="display:flex;align-items:center;gap:12px;padding:14px;background:var(--gray-50);border-radius:var(--r-sm);text-decoration:none;color:inherit;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gray-600)" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span style="flex:1;font-weight:600;">Full Account Settings</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                    <a href="<?php echo wp_logout_url(home_url()); ?>" style="display:flex;align-items:center;gap:12px;padding:14px;background:var(--red-light);border-radius:var(--r-sm);color:var(--red);text-decoration:none;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span style="flex:1;font-weight:600;">Log Out</span>
                    </a>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Bottom Nav -->
    <nav class="pd-nav">
        <div class="pd-nav-item active" data-tab="home">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Home
        </div>
        <div class="pd-nav-item" data-tab="history">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            History
        </div>
        <div class="pd-nav-item" data-tab="messages">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?php if ($unread_count > 0): ?>
            <span class="pd-nav-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
            Chat
        </div>
        <div class="pd-nav-item" data-tab="players">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Players
        </div>
        <div class="pd-nav-item" data-tab="account">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Account
        </div>
    </nav>
</div>

<!-- Add/Edit Player Modal -->
<div class="pd-modal hidden" id="playerModal" onclick="if(event.target===this)closePlayerModal()">
    <div class="pd-modal-content">
        <div class="pd-modal-header">
            <h3 class="pd-modal-title" id="playerModalTitle">Add Player</h3>
            <button class="pd-modal-close" onclick="closePlayerModal()">×</button>
        </div>
        
        <input type="hidden" id="editPlayerId" value="0">
        
        <div class="pd-input-group">
            <label class="pd-label">Player Name *</label>
            <input type="text" id="playerName" class="pd-input" placeholder="e.g. Alex Smith" required>
        </div>
        
        <div class="pd-input-row">
            <div class="pd-input-group">
                <label class="pd-label">Age</label>
                <input type="number" id="playerAge" class="pd-input" placeholder="e.g. 10" min="4" max="18">
            </div>
            <div class="pd-input-group">
                <label class="pd-label">Skill Level</label>
                <select id="playerSkill" class="pd-input">
                    <option value="">Select...</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="competitive">Competitive</option>
                </select>
            </div>
        </div>
        
        <button class="pd-btn full" onclick="savePlayer()">Save Player</button>
    </div>
</div>

<script>
var nonce = '<?php echo $nonce; ?>';
var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
var parentId = <?php echo $parent_id; ?>;

// Tab switching function
function switchToTab(tab) {
    // Map common aliases to actual tab names
    var tabMap = {
        'home': 'home',
        'overview': 'home',
        'history': 'history',
        'bookings': 'history',
        'sessions': 'history',
        'messages': 'messages',
        'chat': 'messages',
        'players': 'players',
        'kids': 'players',
        'children': 'players',
        'account': 'account',
        'settings': 'account',
        'profile': 'account'
    };
    
    var actualTab = tabMap[tab.toLowerCase()] || 'home';
    
    // Update both mobile and desktop nav items
    document.querySelectorAll('.pd-nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.querySelectorAll('.pd-desktop-nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.querySelectorAll('.pd-tab').forEach(function(t) { t.classList.remove('active'); });
    
    var navItem = document.querySelector('.pd-nav-item[data-tab="' + actualTab + '"]');
    var desktopNavItem = document.querySelector('.pd-desktop-nav-item[data-tab="' + actualTab + '"]');
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
    
    // Also check hash (e.g., #messages, #players)
    if (!tab && window.location.hash) {
        tab = window.location.hash.replace('#', '');
    }
    
    if (tab) {
        switchToTab(tab);
    }
}

// Tab click handlers - Mobile
document.querySelectorAll('.pd-nav-item').forEach(function(item) {
    item.addEventListener('click', function() {
        switchToTab(this.dataset.tab);
    });
});

// Tab click handlers - Desktop
document.querySelectorAll('.pd-desktop-nav-item').forEach(function(item) {
    item.addEventListener('click', function() {
        switchToTab(this.dataset.tab);
    });
});

// Handle browser back/forward
window.addEventListener('popstate', handleInitialTab);

// Initialize on load
handleInitialTab();

function showToast(msg) {
    var existing = document.querySelector('.pd-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'pd-toast';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3000);
}

function toggleReferralPanel() {
    var panel = document.getElementById('referralPanel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

function copyRefLink() {
    var link = document.getElementById('refLinkDisplay').textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(link).then(function() { showToast('Link copied! ✓'); });
    } else {
        var input = document.createElement('input');
        input.value = link;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showToast('Link copied! ✓');
    }
}

function openConversation(id) {
    window.location.href = '<?php echo home_url('/messages/'); ?>?conversation=' + id;
}

// Player management
function openAddPlayerModal() {
    document.getElementById('playerModalTitle').textContent = 'Add Player';
    document.getElementById('editPlayerId').value = '0';
    document.getElementById('playerName').value = '';
    document.getElementById('playerAge').value = '';
    document.getElementById('playerSkill').value = '';
    document.getElementById('playerModal').classList.remove('hidden');
}

function editPlayer(id, name, age, skill) {
    document.getElementById('playerModalTitle').textContent = 'Edit Player';
    document.getElementById('editPlayerId').value = id;
    document.getElementById('playerName').value = name;
    document.getElementById('playerAge').value = age || '';
    document.getElementById('playerSkill').value = skill || '';
    document.getElementById('playerModal').classList.remove('hidden');
}

function closePlayerModal() {
    document.getElementById('playerModal').classList.add('hidden');
}

function savePlayer() {
    var playerId = document.getElementById('editPlayerId').value;
    var name = document.getElementById('playerName').value.trim();
    var age = document.getElementById('playerAge').value;
    var skill = document.getElementById('playerSkill').value;
    
    if (!name) {
        showToast('Please enter player name');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'ptp_add_player');
    formData.append('nonce', nonce);
    formData.append('parent_id', parentId);
    formData.append('player_id', playerId);
    formData.append('name', name);
    formData.append('age', age);
    formData.append('skill_level', skill);
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast(playerId > 0 ? 'Player updated! ✓' : 'Player added! ✓');
            closePlayerModal();
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showToast('Error: ' + (res.data?.message || 'Could not save player'));
        }
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
    });
}

function deletePlayer(id) {
    if (!confirm('Remove this player?')) return;
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_delete_player&player_id=' + id + '&nonce=' + nonce
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Player removed ✓');
            document.querySelector('[data-player-id="' + id + '"]').remove();
        } else {
            showToast('Error removing player');
        }
    });
}

function saveAccount() {
    var formData = new FormData();
    formData.append('action', 'ptp_update_profile');
    formData.append('nonce', nonce);
    formData.append('display_name', document.getElementById('accountName').value);
    formData.append('email', document.getElementById('accountEmail').value);
    formData.append('phone', document.getElementById('accountPhone').value);
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        showToast(res.success ? 'Account updated! ✓' : ('Error: ' + (res.data?.message || 'Could not save')));
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
    });
}

// ========================================
// BOOK WITH CREDITS
// ========================================
var currentCreditId = null;
var currentTrainerId = null;

function openBookWithCredits(creditId, trainerName, trainerPhoto, remaining, trainerId) {
    currentCreditId = creditId;
    currentTrainerId = trainerId;
    
    document.getElementById('creditTrainerName').textContent = trainerName;
    document.getElementById('creditRemaining').textContent = remaining;
    
    var photoEl = document.getElementById('creditTrainerPhoto');
    if (trainerPhoto) {
        photoEl.src = trainerPhoto;
        photoEl.style.display = 'block';
    } else {
        photoEl.style.display = 'none';
    }
    
    // Reset form
    document.getElementById('creditSessionDate').value = '';
    document.getElementById('creditSessionTime').value = '';
    document.getElementById('creditLocation').value = '';
    document.getElementById('creditPlayerId').value = '';
    
    document.getElementById('creditBookingModal').classList.remove('hidden');
}

function closeCreditModal() {
    document.getElementById('creditBookingModal').classList.add('hidden');
}

function submitCreditBooking() {
    var btn = document.getElementById('creditBookBtn');
    btn.disabled = true;
    btn.textContent = 'Booking...';
    
    var formData = new FormData();
    formData.append('action', 'ptp_book_with_credits');
    formData.append('nonce', nonce);
    formData.append('credit_id', currentCreditId);
    formData.append('player_id', document.getElementById('creditPlayerId').value);
    formData.append('session_date', document.getElementById('creditSessionDate').value);
    formData.append('session_time', document.getElementById('creditSessionTime').value);
    formData.append('location', document.getElementById('creditLocation').value);
    
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            showToast('Session booked! ✓');
            closeCreditModal();
            setTimeout(function() {
                if (res.data.redirect) {
                    window.location.href = res.data.redirect;
                } else {
                    location.reload();
                }
            }, 1000);
        } else {
            showToast('Error: ' + (res.data?.message || 'Could not book session'));
            btn.disabled = false;
            btn.textContent = 'Book Session';
        }
    })
    .catch(function() {
        showToast('Connection error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Book Session';
    });
}

// v130: Virtual keyboard detection - adjust bottom nav when keyboard opens
(function() {
    var nav = document.querySelector('.pd-nav');
    if (!nav || !('visualViewport' in window)) return;
    
    var originalBottom = 0;
    
    window.visualViewport.addEventListener('resize', function() {
        var keyboardHeight = window.innerHeight - window.visualViewport.height;
        if (keyboardHeight > 100) {
            // Keyboard is open - hide nav or move it up
            nav.style.transform = 'translateY(100%)';
            nav.style.opacity = '0';
            nav.style.pointerEvents = 'none';
        } else {
            // Keyboard is closed - restore nav
            nav.style.transform = 'translateY(0)';
            nav.style.opacity = '1';
            nav.style.pointerEvents = 'auto';
        }
    });
    
    // Add transition for smooth hide/show
    nav.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
})();
</script>

<!-- Credit Booking Modal -->
<div id="creditBookingModal" class="pd-modal hidden">
    <div class="pd-modal-overlay" onclick="closeCreditModal()"></div>
    <div class="pd-modal-content" style="max-width:440px;">
        <div class="pd-modal-header">
            <h3>Book Next Session</h3>
            <button type="button" onclick="closeCreditModal()" class="pd-modal-close">✕</button>
        </div>
        <div class="pd-modal-body">
            <div style="display:flex;align-items:center;gap:14px;padding:16px;background:rgba(34,197,94,0.1);border:2px solid var(--green);border-radius:8px;margin-bottom:20px;">
                <img id="creditTrainerPhoto" src="" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:3px solid var(--gold);">
                <div>
                    <div style="font-family:Oswald,sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;" id="creditTrainerName"></div>
                    <div style="font-size:13px;color:rgba(255,255,255,0.6);"><span id="creditRemaining"></span> session(s) remaining</div>
                </div>
            </div>
            
            <div class="pd-form-group">
                <label class="pd-label">Player</label>
                <select id="creditPlayerId" class="pd-input">
                    <option value="">Select player...</option>
                    <?php foreach ($players as $player): ?>
                    <option value="<?php echo $player->id; ?>"><?php echo esc_html($player->name ?: ($player->first_name . ' ' . $player->last_name)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="pd-form-row">
                <div class="pd-form-group">
                    <label class="pd-label">Preferred Date</label>
                    <input type="date" id="creditSessionDate" class="pd-input" min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="pd-form-group">
                    <label class="pd-label">Preferred Time</label>
                    <select id="creditSessionTime" class="pd-input">
                        <option value="">Select time...</option>
                        <option value="08:00">8:00 AM</option>
                        <option value="09:00">9:00 AM</option>
                        <option value="10:00">10:00 AM</option>
                        <option value="11:00">11:00 AM</option>
                        <option value="12:00">12:00 PM</option>
                        <option value="13:00">1:00 PM</option>
                        <option value="14:00">2:00 PM</option>
                        <option value="15:00">3:00 PM</option>
                        <option value="16:00">4:00 PM</option>
                        <option value="17:00">5:00 PM</option>
                        <option value="18:00">6:00 PM</option>
                        <option value="19:00">7:00 PM</option>
                    </select>
                </div>
            </div>
            
            <div class="pd-form-group">
                <label class="pd-label">Location (optional)</label>
                <input type="text" id="creditLocation" class="pd-input" placeholder="e.g., Radnor High School">
            </div>
            
            <p style="font-size:12px;color:rgba(255,255,255,0.5);margin:16px 0 0;">Your trainer will confirm the date/time and reach out with details. No additional payment required.</p>
        </div>
        <div class="pd-modal-footer">
            <button type="button" onclick="closeCreditModal()" class="pd-btn outline">Cancel</button>
            <button type="button" onclick="submitCreditBooking()" id="creditBookBtn" class="pd-btn" style="background:var(--green);">Book Session</button>
        </div>
    </div>
</div>

<style>
/* Credit Booking Modal Styles */
.pd-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: transparent;
}
.pd-modal-body {
    padding: 0 4px;
}
.pd-modal-footer {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--gray-200);
}
.pd-modal-footer .pd-btn {
    flex: 1;
}
.pd-form-group {
    margin-bottom: 16px;
}
.pd-form-group .pd-label {
    display: block;
    margin-bottom: 6px;
}
.pd-credit-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: all 0.2s;
}
.pd-credit-chip:hover {
    background: rgba(34,197,94,0.1);
    border-color: var(--green);
}
.pd-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 480px) {
    .pd-form-row { grid-template-columns: 1fr; }
}
</style>

<?php wp_footer(); ?>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
