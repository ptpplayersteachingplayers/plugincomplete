<?php
/**
 * Trainer Profile v124 - Cover Photo Background + Mobile Optimized
 * Features: Cover photo background, bottom sheet booking, skeleton loading,
 * haptic feedback, spring animations, progress indicator, swipe to close
 */
defined('ABSPATH') || exit;

global $wpdb;

// Get trainer
$slug = get_query_var('trainer_slug');
if (!$slug) {
    $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts = explode('/', $uri);
    $slug = end($parts);
}

$trainer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND status = 'active'",
    sanitize_title($slug)
));

if (!$trainer) {
    wp_redirect(home_url('/find-trainers/'));
    exit;
}

// Get availability (single query)
$availability = $wpdb->get_results($wpdb->prepare(
    "SELECT day_of_week, start_time, end_time FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d AND is_active = 1",
    $trainer->id
));

// Build availability by day
$avail_by_day = array();
foreach ($availability as $a) {
    if (!isset($avail_by_day[$a->day_of_week])) $avail_by_day[$a->day_of_week] = array();
    $avail_by_day[$a->day_of_week][] = array('start' => $a->start_time, 'end' => $a->end_time);
}

// Get booked slots (single query, next 60 days)
$booked = $wpdb->get_col($wpdb->prepare(
    "SELECT CONCAT(session_date, '_', start_time) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id = %d AND session_date >= CURDATE() AND session_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
     AND status NOT IN ('cancelled', 'refunded')",
    $trainer->id
));
$booked_map = array_flip($booked);

// Training locations
$locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : array();
if (empty($locations)) {
    $locations = array(array('name' => $trainer->city ?: 'Training Location', 'address' => trim(($trainer->city ?? '') . ', ' . ($trainer->state ?? ''), ', ')));
}

// Generate available dates
$available_dates = array();
$today = new DateTime();
for ($i = 1; $i <= 60; $i++) {
    $d = clone $today;
    $d->modify("+$i days");
    if (isset($avail_by_day[$d->format('w')])) {
        $available_dates[] = $d->format('Y-m-d');
    }
}

// Reviews (limit 5 for speed)
$reviews = $wpdb->get_results($wpdb->prepare(
    "SELECT rating, review_text, COALESCE(u.display_name, 'Parent') as name
     FROM {$wpdb->prefix}ptp_reviews r LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.trainer_id = %d ORDER BY r.created_at DESC LIMIT 5",
    $trainer->id
));

// Data
$rate = intval($trainer->hourly_rate ?: 60);
$photo = $trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=200&background=FCB900&color=0A0A0A&bold=true&format=svg';
$cover_photo = $trainer->cover_photo_url ?: '';
$bio = $trainer->bio ?: 'Professional soccer trainer dedicated to helping players reach their full potential.';
$specialties = $trainer->specialties ? array_filter(array_map('trim', explode(',', $trainer->specialties))) : array();

// v130.6: Use primary training location instead of home city
$location = '';
if (!empty($locations) && !empty($locations[0]['name'])) {
    $location = $locations[0]['name'];
    if (!empty($locations[0]['address']) && $locations[0]['address'] !== $locations[0]['name']) {
        // Extract city from address if different
        $addr_parts = explode(',', $locations[0]['address']);
        if (count($addr_parts) >= 2) {
            $location = trim($addr_parts[count($addr_parts) - 2]) . ', ' . trim($addr_parts[count($addr_parts) - 1]);
        }
    }
}
// Fallback to city/state
if (empty($location)) {
    $location = trim(($trainer->city ?? '') . ', ' . ($trainer->state ?? ''), ', ') ?: 'Philadelphia Area';
}

$level_labels = array('pro'=>'MLS PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO');
$level = $level_labels[$trainer->playing_level] ?? 'PRO';

// v130.6: Better rating display - show "New" if no reviews
$review_count = intval($trainer->review_count ?: count($reviews));
$has_reviews = $review_count > 0;
$rating = $has_reviews ? number_format(floatval($trainer->average_rating ?: 5), 1) : 'New';
$sessions = intval($trainer->total_sessions ?: 0);
$first_name = explode(' ', $trainer->display_name)[0];

// Supercoach status: 4.9+ rating with 10+ reviews
$is_supercoach = (floatval($trainer->average_rating ?: 0) >= 4.9 && $review_count >= 10);

// Review mode - check if parent came from review link
$review_booking_id = intval($_GET['review'] ?? 0);
$review_booking = null;
if ($review_booking_id && is_user_logged_in()) {
    $review_booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*, p.name as player_name
        FROM {$wpdb->prefix}ptp_bookings b
        LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
        WHERE b.id = %d AND b.trainer_id = %d AND b.status = 'completed'
    ", $review_booking_id, $trainer->id));
    
    // Check if already reviewed
    if ($review_booking) {
        $already_reviewed = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_reviews WHERE booking_id = %d",
            $review_booking_id
        ));
        if ($already_reviewed) {
            $review_booking = null; // Don't show modal if already reviewed
        }
    }
}

// New profile fields
$coaching_why = $trainer->coaching_why ?? '';
$training_philosophy = $trainer->training_philosophy ?? '';
$training_policy = $trainer->training_policy ?? 'Sessions must be cancelled at least 24 hours in advance for a full refund. No-shows will be charged the full session rate. Weather cancellations will be rescheduled at no additional cost.';

// Google Maps API key
$google_maps_key = get_option('ptp_google_maps_api_key', '');

// Packages
$pkgs = array(
    'single' => array('n' => 'Single', 's' => 1, 'p' => $rate, 'v' => 0),
    'pack3' => array('n' => '3-Pack', 's' => 3, 'p' => intval($rate * 3 * 0.9), 'v' => intval($rate * 3 * 0.1)),
    'pack5' => array('n' => '5-Pack', 's' => 5, 'p' => intval($rate * 5 * 0.85), 'v' => intval($rate * 5 * 0.15)),
);

// v134: Use WordPress theme header
get_header();
?>

<style>
/* v134: Trainer Profile Styles - Integrates with site theme */
/* Reset for PTP content area */
.ptp-trainer-profile *{margin:0;padding:0;box-sizing:border-box}
.ptp-trainer-profile {
    --gold:#FCB900;--gold-light:rgba(252,185,0,0.1);--gold-glow:rgba(252,185,0,0.4);
    --black:#0A0A0A;--white:#fff;--gray:#F5F5F5;--gray2:#E5E7EB;--gray3:#D1D5DB;--gray4:#9CA3AF;--gray5:#6B7280;--gray6:#4B5563;
    --green:#22C55E;--red:#EF4444;--rad:12px;--rad-lg:16px;--rad-xl:24px;
    --shadow-sm:0 1px 2px rgba(0,0,0,0.04);--shadow-md:0 4px 12px rgba(0,0,0,0.08);--shadow-lg:0 12px 40px rgba(0,0,0,0.12);
    --safe-bottom:env(safe-area-inset-bottom,0px);
    --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.4,0,0.2,1);
    font-family: 'Inter', -apple-system, sans-serif;
    background: #fff;
    min-height: 100vh;
}

/* v133.2: NATURAL SCROLL - Body scrolls normally */
html {
    margin: 0 !important;
    padding: 0 !important;
    height: auto !important;
    overflow-x: hidden;
    overflow-y: scroll !important;
    /* Hide scrollbar */
    scrollbar-width: none;
    -ms-overflow-style: none;
}
html::-webkit-scrollbar { display: none; width: 0; }
body {
    margin: 0 !important;
    padding: 0 !important;
    min-height: 100vh;
    min-height: 100dvh;
    height: auto !important;
    overflow-x: hidden;
    overflow-y: visible !important;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
body::-webkit-scrollbar { display: none; width: 0; }
/* Only block scroll when modal/menu active */
body.ptp-modal-active,
body.ptp-menu-active {
    overflow: hidden !important;
}
/* Legacy wrapper - now transparent, doesn't affect scroll */
#ptp-scroll-wrapper {
    width: 100%;
    min-height: 100vh;
    height: auto !important;
    overflow: visible !important;
}
#ptp-scroll-wrapper::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.5); }

html{
    -webkit-tap-highlight-color:transparent;
    scroll-behavior:smooth;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,0.2) transparent;
}
html::-webkit-scrollbar{width:6px}
html::-webkit-scrollbar-track{background:transparent}
html::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.2);border-radius:3px}
html::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,0.3)}
body{
    font-family:'Inter',system-ui,-apple-system,sans-serif;
    background:var(--gray);
    color:var(--black);
    line-height:1.5;
    -webkit-font-smoothing:antialiased;
}
h1,h2,h3{font-family:'Oswald',system-ui,sans-serif;font-weight:700;text-transform:uppercase;line-height:1.1}
a{color:inherit;text-decoration:none}

/* Skeleton Loading */
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
.skeleton{background:linear-gradient(90deg,var(--gray2) 25%,var(--gray3) 50%,var(--gray2) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:var(--rad)}

/* Hero - with optional cover photo background */
.hero{
    background:var(--black);
    padding:max(16px,env(safe-area-inset-top)) 16px 100px;
    text-align:center;
    position:relative;
    overflow:hidden;
}
.hero.has-cover{
    background-size:cover;
    background-position:center 30%;
    padding-top:max(24px,env(safe-area-inset-top));
    padding-bottom:120px;
}
.hero.has-cover::before{
    content:'';
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, rgba(10,10,10,0.5) 0%, rgba(10,10,10,0.85) 100%);
    z-index:0;
}
.hero > *{position:relative;z-index:1}

/* Desktop hero with cover - larger photo and more padding */
@media(min-width:768px){
    .hero.has-cover{padding:40px 24px 140px}
    .hero .stats{gap:48px}
    .hero .stat-v{font-size:32px}
}
@media(min-width:1024px){
    .hero.has-cover{padding:60px 32px 160px}
}

.back{display:inline-flex;align-items:center;gap:6px;color:var(--gray4);font-size:13px;margin-bottom:20px;padding:10px 16px;border-radius:var(--rad);transition:background .2s}
.back:active{background:rgba(255,255,255,0.1)}

/* Coach Photo - Mobile First with Proper Cropping */
.photo{
    width:140px;height:140px;
    border-radius:50%;
    border:4px solid var(--gold);
    margin:0 auto 16px;
    overflow:hidden;
    box-shadow:0 0 40px var(--gold-glow);
    flex-shrink:0;
    position:relative;
    background:var(--black);
}
.photo img{
    width:100%;height:100%;
    object-fit:cover;
    object-position:center 15%; /* Focus on face - slightly above center */
    transition:transform .3s;
    display:block;
}
/* Small phones get slightly smaller */
@media(max-width:360px){
    .photo{width:120px;height:120px;border-width:3px}
}
/* Tablet - slightly larger */
@media(min-width:768px){
    .photo{width:160px;height:160px;border-width:5px}
}
/* Desktop - largest */
@media(min-width:1024px){
    .photo{width:180px;height:180px}
}

/* Landscape Mobile Optimizations */
@media(max-width:896px) and (orientation:landscape){
    .hero{padding-bottom:60px !important}
    .hero.has-cover{padding-bottom:80px !important}
    .photo{width:100px !important;height:100px !important;border-width:3px !important}
    .name{font-size:24px !important}
    .stats{gap:20px !important}
    .stat-v{font-size:22px !important}
    .book{max-height:75vh !important;max-height:75dvh !important}
    .book-body{max-height:calc(75vh - 140px) !important;max-height:calc(75dvh - 140px) !important}
}

.badges{display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.badge{padding:6px 12px;border-radius:50px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.02em}
.badge-gold{background:var(--gold);color:var(--black)}
.badge-green{background:rgba(34,197,94,.15);color:var(--green)}
.badge-supercoach{background:linear-gradient(90deg, #FCB900 0%, #F59E0B 100%);color:var(--black);font-weight:700;padding:6px 14px !important;font-size:11px !important;box-shadow:0 2px 8px rgba(252,185,0,0.4)}
.name{font-size:clamp(26px,7vw,40px);color:var(--white);margin-bottom:4px;letter-spacing:-0.02em}
.loc{color:var(--gray4);font-size:14px;margin-bottom:16px;display:flex;align-items:center;justify-content:center;gap:4px}
.stats{display:flex;justify-content:center;gap:32px}
.stat-v{font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:var(--gold)}
.stat-l{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray4);margin-top:2px}

/* v116: Trainer Share Button */
.trainer-share-wrap{position:relative;margin-top:20px;display:flex;justify-content:center}
.trainer-share-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:rgba(255,255,255,0.1);color:#fff;border:2px solid rgba(255,255,255,0.2);border-radius:50px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s}
.trainer-share-btn:hover{background:rgba(255,255,255,0.15);border-color:var(--gold)}
.trainer-share-dropdown{position:absolute;top:100%;left:50%;transform:translateX(-50%);margin-top:10px;background:#fff;border-radius:var(--rad);box-shadow:var(--shadow-lg);min-width:200px;overflow:hidden;z-index:100;display:none}
.trainer-share-dropdown.show{display:block;animation:dropIn .2s ease}
@keyframes dropIn{from{opacity:0;transform:translateX(-50%) translateY(-8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.trainer-share-header{padding:12px 16px;background:var(--gray);font-size:12px;font-weight:600;color:var(--gray5);text-transform:uppercase;letter-spacing:.5px}
.trainer-share-opt{display:flex;align-items:center;gap:10px;padding:14px 16px;color:var(--black);text-decoration:none;font-size:14px;font-weight:500;border:none;background:none;width:100%;cursor:pointer;transition:background .15s}
.trainer-share-opt:hover{background:var(--gray)}

/* Main */
.main{max-width:900px;margin:-70px auto 0;padding:0 16px 140px;position:relative}
@media(min-width:768px){.main{padding-bottom:60px}}
.grid{display:grid;gap:16px}
@media(min-width:768px){.grid{grid-template-columns:1fr 360px;align-items:start}}

/* Cards */
.card{background:var(--white);border-radius:var(--rad-lg);padding:20px;box-shadow:var(--shadow-md);transition:transform .2s var(--ease-spring)}
.card-t{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid var(--gray);display:flex;align-items:center;gap:8px}
.bio{font-size:14px;color:var(--gray6);line-height:1.7}
.specs{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}
.spec{background:var(--gray);color:var(--gray5);font-size:12px;font-weight:500;padding:8px 14px;border-radius:50px;transition:all .2s}
.review{padding:14px 0;border-bottom:1px solid var(--gray2)}
.review:last-child{border:none;padding-bottom:0}
.r-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.r-name{font-weight:600;font-size:14px}
.r-stars{color:var(--gold);font-size:13px;letter-spacing:1px}
.r-text{font-size:13px;color:var(--gray6);line-height:1.6}

/* BOOKING WIDGET - Enhanced Mobile Bottom Sheet */
.book{position:sticky;top:16px;border-radius:16px;border:2px solid var(--gray2);overflow:hidden;align-self:start}
/* v130.3: Hide scrollbar on booking widget */
.book,.book *{scrollbar-width:none;-ms-overflow-style:none}
.book::-webkit-scrollbar,.book *::-webkit-scrollbar{display:none}

/* Backdrop overlay when expanded */
.book-backdrop{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);
    z-index:99;opacity:0;transition:opacity .3s ease;
}
@media(max-width:767px){
    .book-backdrop.show{display:block;opacity:1}
}

@media(max-width:767px){
    .book{
        position:fixed;bottom:0;left:0;right:0;z-index:100;
        background:var(--white);
        border-radius:24px 24px 0 0;
        border:none;
        box-shadow:0 -4px 30px rgba(0,0,0,0.25);
        max-height:88vh;max-height:88dvh;
        transform:translateY(calc(100% - 90px));
        transition:transform .35s cubic-bezier(0.32, 0.72, 0, 1);
        padding-bottom:env(safe-area-inset-bottom, 20px);
        will-change:transform;
        display:flex;
        flex-direction:column;
    }
    .book.expanded{transform:translateY(0)}
    .book::before{
        content:'';position:absolute;top:10px;left:50%;transform:translateX(-50%);
        width:36px;height:4px;background:var(--gray3);border-radius:4px;
        z-index:10;
    }
}
.book-bar{
    display:flex;justify-content:space-between;align-items:center;padding:24px 20px;
    cursor:pointer;-webkit-tap-highlight-color:transparent;
    touch-action:manipulation;
    user-select:none;-webkit-user-select:none;
    flex-shrink:0;
}
@media(min-width:768px){.book-bar{display:none}}
.book-bar-left{}
.book-bar-price{font-family:'Oswald',sans-serif;font-size:28px;font-weight:700}
.book-bar-unit{font-size:14px;color:var(--gray4);font-weight:400}
.book-bar-save{font-size:11px;color:var(--green);font-weight:600;margin-top:2px}
.book-bar-btn{
    padding:16px 32px;background:var(--gold);color:var(--black);
    font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;
    border-radius:0;font-size:14px;letter-spacing:.02em;border:2px solid var(--gold);
    transition:all .15s;
}
.book-bar-btn:active{transform:scale(0.96);background:var(--black);color:var(--gold)}
.book-header{text-align:center;padding:20px 20px 16px;border-bottom:2px solid var(--gray2);position:relative;flex-shrink:0}
@media(max-width:767px){.book-header{padding-top:28px}}
@media(min-width:768px){.book-header{padding-top:20px}}

/* Close button for mobile */
.book-close{
    display:none;position:absolute;top:16px;right:16px;
    width:36px;height:36px;border-radius:50%;
    background:var(--gray);border:none;cursor:pointer;
    font-size:18px;color:var(--gray5);
    transition:all .15s ease;
    z-index:10;
}
.book-close:active{background:var(--gray2);transform:scale(0.95)}
@media(max-width:767px){.book-close{display:flex;align-items:center;justify-content:center}}
.book-rate{font-family:'Oswald',sans-serif;font-size:36px;font-weight:700;line-height:1}
@media(min-width:480px){.book-rate{font-size:42px}}
.book-rate small{font-size:16px;color:var(--gray4);font-weight:400}
@media(min-width:480px){.book-rate small{font-size:18px}}
.book-save{font-size:13px;color:var(--green);font-weight:600;margin-top:4px}

/* Book Body - Scrollable Content Area */
.book-body{
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    padding:20px;
    flex:1;
    min-height:0;
    /* v130.3: Hide scrollbar but keep scrollable */
    scrollbar-width:none;
    -ms-overflow-style:none;
}
.book-body::-webkit-scrollbar{display:none}
@media(max-width:767px){
    .book-body{
        padding:16px;
        padding-bottom:calc(32px + env(safe-area-inset-bottom, 20px));
    }
}
@media(max-width:374px){
    .book-body{
        padding:14px;
        padding-bottom:calc(28px + env(safe-area-inset-bottom, 20px));
    }
}
@media(min-width:768px){.book-body{max-height:calc(100vh - 150px);overflow-y:auto}}

/* Steps Indicator - Enhanced Mobile (5 steps) */
.steps{display:flex;justify-content:space-between;margin-bottom:20px;position:relative;padding:0 4px}
@media(min-width:480px){.steps{padding:0 8px;margin-bottom:24px}}
.steps::before{content:'';position:absolute;top:14px;left:28px;right:28px;height:3px;background:var(--gray2);border-radius:2px}
@media(max-width:374px){.steps::before{left:20px;right:20px;top:12px}}
.steps::after{content:'';position:absolute;top:14px;left:28px;height:3px;background:var(--gold);border-radius:2px;transition:width .3s var(--ease-smooth);width:var(--progress,0%)}
@media(max-width:374px){.steps::after{left:20px;top:12px}}
.step{text-align:center;position:relative;z-index:1;flex:1;min-width:0}
.step-n{
    width:28px;height:28px;border-radius:50%;
    background:var(--gray2);color:var(--gray4);
    font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 4px;transition:all .25s var(--ease-spring);
}
@media(max-width:480px){.step-n{width:24px;height:24px;font-size:11px;margin-bottom:2px}}
@media(max-width:374px){.step-n{width:22px;height:22px;font-size:10px}}
.step.on .step-n,.step.done .step-n{background:var(--gold);color:var(--black);transform:scale(1.1)}
.step.done .step-n::after{content:'✓';font-size:12px}
@media(max-width:374px){.step.done .step-n::after{font-size:10px}}
.step-l{font-size:9px;text-transform:uppercase;letter-spacing:.02em;color:var(--gray4);font-weight:500}
@media(max-width:480px){.step-l{font-size:8px;letter-spacing:0}}
@media(max-width:374px){.step-l{font-size:7px}}
.step.on .step-l{color:var(--black);font-weight:600}

/* Sections */
.sec{display:none;animation:fadeSlide .3s var(--ease-spring);overflow-x:hidden;max-width:100%}
.sec.on{display:block}
@keyframes fadeSlide{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.sec-t{font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--gray5);margin-bottom:10px}
@media(min-width:480px){.sec-t{margin-bottom:12px}}

/* Calendar - Enhanced Mobile */
.cal-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
@media(min-width:480px){.cal-h{margin-bottom:12px}}
.cal-m{font-family:'Oswald',sans-serif;font-size:15px;font-weight:600}
@media(min-width:480px){.cal-m{font-size:16px}}
.cal-nav{display:flex;gap:6px}
@media(min-width:480px){.cal-nav{gap:8px}}
.cal-btn{
    width:44px;height:44px;border:2px solid var(--gray2);border-radius:var(--rad);
    background:var(--white);cursor:pointer;display:flex;align-items:center;justify-content:center;
    font-size:16px;transition:all .15s var(--ease-spring);
}
@media(max-width:374px){.cal-btn{width:40px;height:40px;font-size:14px}}
.cal-btn:active{background:var(--gray);transform:scale(0.95)}
.cal-days{display:grid;grid-template-columns:repeat(7,1fr);text-align:center;margin-bottom:6px}
@media(min-width:480px){.cal-days{margin-bottom:8px}}
.cal-d{font-size:10px;font-weight:600;color:var(--gray4);text-transform:uppercase;padding:6px 0}
@media(min-width:480px){.cal-d{font-size:11px;padding:8px 0}}
.cal-g{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;overflow:hidden}
@media(min-width:480px){.cal-g{gap:4px}}
.cal-c{
    aspect-ratio:1;border-radius:8px;display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:500;cursor:pointer;transition:all .15s var(--ease-spring);
    border:2px solid transparent;position:relative;
    min-height:38px;
}
@media(min-width:480px){.cal-c{font-size:14px;min-height:42px;border-radius:var(--rad)}}
@media(max-width:374px){.cal-c{font-size:12px;min-height:34px;border-radius:6px}}
.cal-c.off{color:var(--gray3);cursor:default;text-decoration:line-through}
.cal-c.av{color:var(--black)}
.cal-c.av:active{background:var(--gold-light);transform:scale(0.95)}
.cal-c.av::after{content:'';position:absolute;bottom:2px;width:4px;height:4px;background:var(--green);border-radius:50%}
@media(max-width:374px){.cal-c.av::after{width:3px;height:3px;bottom:1px}}
.cal-c.sel{background:var(--gold);color:var(--black);font-weight:700;transform:scale(1.05);border-color:var(--gold)}
.cal-c.sel::after{background:var(--black)}
.cal-c.today{border-color:var(--gold);font-weight:700}

/* Time Slots - Enhanced Mobile */
.times{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
@media(max-width:374px){.times{grid-template-columns:repeat(2,1fr);gap:6px}}
.time{
    padding:14px 8px;border:2px solid var(--gray2);border-radius:var(--rad);
    text-align:center;font-size:14px;font-weight:500;cursor:pointer;
    transition:all .15s var(--ease-spring);
    min-height:48px;display:flex;align-items:center;justify-content:center;
}
@media(max-width:374px){.time{padding:12px 6px;font-size:13px;min-height:44px}}
.time:active{transform:scale(0.97)}
.time.sel{border-color:var(--gold);background:var(--gold);color:var(--black);font-weight:600}
.time.off{color:var(--gray4);text-decoration:line-through;cursor:not-allowed;opacity:.5}

/* Locations - Enhanced Mobile */
.locs{display:flex;flex-direction:column;gap:10px}
@media(max-width:374px){.locs{gap:8px}}
.loc-o{
    display:flex;align-items:center;gap:12px;padding:16px;
    border:2px solid var(--gray2);border-radius:var(--rad-lg);
    cursor:pointer;transition:all .15s var(--ease-spring);
    min-height:64px;
}
@media(max-width:374px){.loc-o{padding:12px;gap:10px;min-height:56px}}
.loc-o:active{transform:scale(0.99)}
.loc-o.sel{border-color:var(--gold);background:var(--gold-light)}
.loc-o svg{width:20px;height:20px;stroke:var(--gold);flex-shrink:0}
.loc-i{flex:1}
.loc-n{font-weight:600;font-size:14px}
@media(max-width:374px){.loc-n{font-size:13px}}
.loc-a{font-size:12px;color:var(--gray4);margin-top:2px}
@media(max-width:374px){.loc-a{font-size:11px}}

/* Packages - Enhanced Mobile */
.pkgs{display:flex;flex-direction:column;gap:10px}
@media(max-width:374px){.pkgs{gap:8px}}
.pkg{
    display:flex;align-items:center;gap:14px;padding:16px;
    border:2px solid var(--gray2);border-radius:var(--rad-lg);
    cursor:pointer;transition:all .15s var(--ease-spring);position:relative;
    min-height:72px;
}
@media(max-width:374px){.pkg{padding:12px;gap:10px;min-height:64px}}
.pkg:active{transform:scale(0.99)}
.pkg.sel{border-color:var(--gold);background:var(--gold-light)}
.pkg.popular::before{
    content:'BEST VALUE';position:absolute;top:-10px;right:12px;
    background:var(--green);color:white;font-size:9px;font-weight:700;
    padding:4px 10px;border-radius:var(--rad);
}
@media(max-width:374px){.pkg.popular::before{font-size:8px;padding:3px 8px;top:-8px;right:8px}}
.pkg input{display:none}
.pkg-radio{
    width:22px;height:22px;border:2px solid var(--gray3);border-radius:50%;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s;
}
@media(max-width:374px){.pkg-radio{width:20px;height:20px}}
.pkg.sel .pkg-radio{border-color:var(--gold);background:var(--gold)}
.pkg.sel .pkg-radio::after{content:'';width:8px;height:8px;background:var(--black);border-radius:50%}
@media(max-width:374px){.pkg.sel .pkg-radio::after{width:6px;height:6px}}
.pkg-i{flex:1;min-width:0}
.pkg-n{font-family:'Oswald',sans-serif;font-size:15px;font-weight:600;text-transform:uppercase}
@media(max-width:374px){.pkg-n{font-size:13px}}
.pkg-d{font-size:12px;color:var(--gray4);margin-top:2px}
@media(max-width:374px){.pkg-d{font-size:11px}}
.pkg-pr{text-align:right;flex-shrink:0}
.pkg-p{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700}
@media(max-width:374px){.pkg-p{font-size:18px}}
.pkg-v{font-size:11px;color:var(--green);font-weight:600}
@media(max-width:374px){.pkg-v{font-size:10px}}

/* Group Size Selector - v130.3 */
.group-sizes{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
@media(min-width:400px){.group-sizes{grid-template-columns:repeat(4,1fr);gap:8px}}
.group-size{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    padding:16px 10px;border:2px solid var(--gray2);border-radius:12px;
    cursor:pointer;transition:all .15s var(--ease-spring);text-align:center;
    min-height:100px;background:#fff;-webkit-tap-highlight-color:transparent;
}
@media(min-width:400px){.group-size{padding:14px 6px;min-height:95px;border-radius:var(--rad)}}
.group-size:active{transform:scale(0.96)}
.group-size.sel{border-color:var(--gold);background:linear-gradient(180deg,#FFFBEB 0%,#FFF 100%);box-shadow:0 2px 12px rgba(252,185,0,0.25)}
.group-size-icon{width:36px;height:36px;margin-bottom:8px;color:var(--gray4)}
.group-size-icon svg{width:100%;height:100%}
.group-size.sel .group-size-icon{color:var(--black)}
@media(min-width:400px){.group-size-icon{width:28px;height:28px;margin-bottom:6px}}
.group-size-label{font-family:'Oswald',sans-serif;font-size:16px;font-weight:600;text-transform:uppercase;color:var(--gray5)}
.group-size.sel .group-size-label{color:var(--black)}
@media(min-width:400px){.group-size-label{font-size:13px}}
.group-size-price{font-size:14px;color:var(--gray5);margin-top:4px;font-weight:500}
.group-size.sel .group-size-price{color:var(--black)}
@media(min-width:400px){.group-size-price{font-size:12px}}
.group-size-per{font-size:12px;color:var(--green);font-weight:600;margin-top:3px}
@media(min-width:400px){.group-size-per{font-size:10px;margin-top:2px}}

/* Summary - Mobile Optimized */
.sum{background:var(--gray);border-radius:var(--rad);padding:16px;margin-top:16px}
@media(max-width:374px){.sum{padding:12px;margin-top:12px}}
.sum-r{display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px}
@media(max-width:374px){.sum-r{font-size:13px;margin-bottom:6px}}
.sum-r:last-child{margin:0;padding-top:10px;border-top:2px solid var(--gray2);font-weight:600;font-size:16px}
@media(max-width:374px){.sum-r:last-child{padding-top:8px;font-size:15px}}
.sum-r span:last-child{font-family:'Oswald',sans-serif}

/* Buttons - Enhanced Touch Targets */
.btn{
    width:100%;min-height:52px;padding:16px;border:none;border-radius:var(--rad);
    font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;text-transform:uppercase;
    letter-spacing:.02em;cursor:pointer;transition:all .15s var(--ease-spring);margin-top:12px;
    display:flex;align-items:center;justify-content:center;gap:8px;
    -webkit-tap-highlight-color:transparent;
}
@media(max-width:374px){.btn{min-height:48px;padding:14px;font-size:14px;margin-top:10px}}
.btn:active{transform:scale(0.98)}
.btn-g{background:var(--gold);color:var(--black)}
.btn-g:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-o{background:transparent;border:2px solid var(--gray2);color:var(--gray5)}
.btn-o:active{background:var(--gray)}

/* Loading state */
.btn.loading{color:transparent;pointer-events:none;position:relative}
.btn.loading::after{
    content:'';position:absolute;width:20px;height:20px;
    border:2px solid var(--black);border-right-color:transparent;
    border-radius:50%;animation:spin .6s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* Toast - Mobile Position */
.toast{
    position:fixed;bottom:calc(100px + var(--safe-bottom));left:50%;transform:translateX(-50%) translateY(20px);
    z-index:200;background:var(--black);color:var(--white);padding:14px 24px;
    border-radius:50px;font-size:14px;font-weight:500;box-shadow:var(--shadow-lg);
    opacity:0;visibility:hidden;transition:all .3s var(--ease-spring);
    max-width:calc(100vw - 32px);text-align:center;
}
@media(max-width:374px){.toast{padding:12px 20px;font-size:13px}}
.toast.show{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0)}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

.empty{text-align:center;padding:24px;color:var(--gray4);font-size:14px}
@media(max-width:374px){.empty{padding:16px;font-size:13px}}
/* Gallery */
.gallery-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.gallery-img{aspect-ratio:1;border-radius:8px;overflow:hidden;background:var(--gray)}
.gallery-img img{width:100%;height:100%;object-fit:cover}
/* Coaching Why & Philosophy */
.story-sec{margin-top:16px}
.story-icon{width:32px;height:32px;background:var(--gold);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:10px}
.story-icon svg{width:18px;height:18px;stroke:var(--black)}
.story-title{font-family:Oswald,sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;margin-bottom:8px;color:var(--black)}
.story-text{font-size:13px;line-height:1.6;color:var(--gray6)}
/* Locations Map */
.map-sec{margin-top:16px}
.map-wrap{height:200px;border-radius:12px;overflow:hidden;background:var(--gray);margin-bottom:12px}
.map-wrap iframe{width:100%;height:100%;border:0}
.map-locs{display:flex;flex-direction:column;gap:8px}
.map-loc{display:flex;align-items:flex-start;gap:10px;padding:10px;background:var(--gray);border-radius:8px}
.map-loc-icon{width:32px;height:32px;background:var(--gold);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.map-loc-icon svg{width:16px;height:16px;stroke:var(--black)}
.map-loc-name{font-weight:600;font-size:13px}
.map-loc-addr{font-size:11px;color:var(--gray4)}
/* Training Policy */
.policy-sec{margin-top:16px;background:rgba(252,185,0,.05);border:1px solid rgba(252,185,0,.2)}
.policy-list{list-style:none;padding:0;margin:0}
.policy-list li{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--gray6);padding:6px 0;border-bottom:1px solid var(--gray2)}
.policy-list li:last-child{border:0}
.policy-list li svg{width:14px;height:14px;stroke:var(--gold);flex-shrink:0;margin-top:2px}
/* Coordinator Box */
.coordinator-card{background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%);border:2px solid #22c55e}
.coord-btn{display:flex;align-items:center;justify-content:center;padding:14px 20px;background:#22c55e;color:#fff;border-radius:10px;font-family:Oswald,sans-serif;font-size:15px;font-weight:600;text-transform:uppercase;text-decoration:none;transition:.15s}
.coord-btn:active{background:#16a34a}

/* Review Modal - Mobile First */
.review-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;display:none;align-items:flex-end;justify-content:center;padding:0;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.review-overlay.show{display:flex}
.review-modal{background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:100%;overflow:hidden;animation:reviewSlide .3s ease;max-height:90vh;overflow-y:auto;-webkit-overflow-scrolling:touch}
@keyframes reviewSlide{from{opacity:0;transform:translateY(100%)}to{opacity:1;transform:translateY(0)}}
@media(min-width:500px){
    .review-overlay{align-items:center;padding:20px}
    .review-modal{border-radius:16px;max-width:400px}
    @keyframes reviewSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
}
.review-header{background:linear-gradient(135deg,var(--gold) 0%,#F59E0B 100%);padding:24px 20px;text-align:center}
.review-header h2{font-family:Oswald,sans-serif;font-size:22px;font-weight:700;color:var(--black);margin:0 0 4px;text-transform:uppercase}
.review-header p{font-size:13px;color:rgba(0,0,0,.7);margin:0}
.review-body{padding:20px;padding-bottom:calc(20px + env(safe-area-inset-bottom, 0px))}
.review-stars{display:flex;justify-content:center;gap:10px;margin-bottom:20px}
.review-star{width:52px;height:52px;background:none;border:2px solid var(--gray2);border-radius:12px;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
.review-star svg{width:28px;height:28px;fill:#d1d5db;transition:.15s}
.review-star:hover,.review-star.active{border-color:var(--gold);background:rgba(252,185,0,.15)}
.review-star:hover svg,.review-star.active svg{fill:var(--gold)}
@media(min-width:500px){
    .review-star{width:44px;height:44px}
    .review-star svg{width:24px;height:24px}
}
.review-textarea{width:100%;min-height:100px;padding:14px;border:2px solid var(--gray2);border-radius:10px;font-family:Inter,sans-serif;font-size:16px;resize:none;transition:.15s;-webkit-appearance:none}
.review-textarea:focus{outline:none;border-color:var(--gold)}
.review-textarea::placeholder{color:#9ca3af}
.review-hint{font-size:12px;color:#6b7280;margin-top:8px;text-align:center}
.review-submit{width:100%;padding:18px;background:var(--black);color:#fff;border:none;border-radius:12px;font-family:Oswald,sans-serif;font-size:17px;font-weight:600;text-transform:uppercase;cursor:pointer;margin-top:16px;transition:.15s;-webkit-tap-highlight-color:transparent}
.review-submit:hover{background:#1a1a1a}
.review-submit:active{transform:scale(0.98)}
.review-submit:disabled{background:#9ca3af;cursor:not-allowed}
.review-close{position:absolute;top:12px;right:12px;width:36px;height:36px;background:rgba(0,0,0,.1);border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
.review-close svg{width:18px;height:18px;stroke:#000}
.review-success{text-align:center;padding:40px 24px}
.review-success-icon{width:64px;height:64px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.review-success-icon svg{width:32px;height:32px;stroke:var(--black);stroke-width:3}
.review-success h3{font-family:Oswald,sans-serif;font-size:20px;margin:0 0 8px}
.review-success p{font-size:14px;color:#6b7280;margin:0}
</style>

<!-- v134: Trainer Profile Content - Uses site theme header -->
<div class="ptp-trainer-profile">

<div class="hero<?php echo $cover_photo ? ' has-cover' : ''; ?>"<?php echo $cover_photo ? ' style="background-image:url(' . esc_url($cover_photo) . ');"' : ''; ?>>
    <a href="<?php echo home_url('/find-trainers/'); ?>" class="back">← All Trainers</a>
    <div class="photo"><img src="<?php echo esc_url($photo); ?>" alt="" loading="eager" width="120" height="120"></div>
    <div class="badges">
        <?php if ($is_supercoach): ?><span class="badge badge-supercoach">⭐ SUPERCOACH</span><?php endif; ?>
        <span class="badge badge-gold"><?php echo $level; ?></span>
        <?php if ($trainer->is_verified): ?><span class="badge badge-green">Verified</span><?php endif; ?>
    </div>
    <h1 class="name"><?php echo esc_html($trainer->display_name); ?></h1>
    <p class="loc"><?php echo esc_html($location); ?></p>
    <div class="stats">
        <?php if ($has_reviews): ?>
        <div><div class="stat-v"><?php echo $rating; ?></div><div class="stat-l">Rating</div></div>
        <div><div class="stat-v"><?php echo $review_count; ?></div><div class="stat-l">Reviews</div></div>
        <?php else: ?>
        <div><div class="stat-v" style="color:var(--gold);">New</div><div class="stat-l">Trainer</div></div>
        <?php endif; ?>
        <div><div class="stat-v"><?php echo $sessions > 0 ? $sessions . '+' : '—'; ?></div><div class="stat-l">Sessions</div></div>
    </div>
    
    <?php 
    // v116: Share button for trainer profile - USE PTP_Referral_System for discount to work
    $share_url = home_url('/trainer/' . $trainer->slug . '/');
    $user_id = get_current_user_id();
    if ($user_id && class_exists('PTP_Referral_System')) {
        $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
        if ($referral_code) {
            $share_url = home_url('/trainer/' . $trainer->slug . '/?ref=' . $referral_code);
        }
    } elseif ($user_id) {
        // Fallback to user meta (discount may not apply)
        $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
        if ($referral_code) {
            $share_url = home_url('/trainer/' . $trainer->slug . '/?ref=' . $referral_code);
        }
    }
    $share_text = "Check out {$first_name} on PTP Soccer! Professional training that actually works:";
    $encoded_text = urlencode($share_text . ' ' . $share_url);
    ?>
    <div class="trainer-share-wrap">
        <button type="button" class="trainer-share-btn" onclick="toggleTrainerShare()">
            <span>📤</span> Share <?php echo esc_html($first_name); ?>
        </button>
        <div class="trainer-share-dropdown" id="trainerShareDropdown">
            <div class="trainer-share-header">Share & Earn $25</div>
            <a href="sms:?body=<?php echo $encoded_text; ?>" class="trainer-share-opt">💬 Text a Friend</a>
            <a href="https://wa.me/?text=<?php echo $encoded_text; ?>" target="_blank" class="trainer-share-opt">📱 WhatsApp</a>
            <a href="mailto:?subject=<?php echo urlencode('Great soccer trainer!'); ?>&body=<?php echo $encoded_text; ?>" class="trainer-share-opt">✉️ Email</a>
            <button type="button" class="trainer-share-opt" onclick="copyTrainerLink('<?php echo esc_js($share_url); ?>', this)">🔗 Copy Link</button>
        </div>
    </div>
</div>

<div class="main">
    <div class="grid">
        <div>
            <div class="card">
                <div class="card-t">About <?php echo esc_html($first_name); ?></div>
                <p class="bio"><?php echo nl2br(esc_html($bio)); ?></p>
                <?php if ($specialties): ?>
                <div class="specs"><?php foreach ($specialties as $s): ?><span class="spec"><?php echo esc_html($s); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            
            <?php if ($coaching_why): ?>
            <div class="card story-sec">
                <div class="story-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </div>
                <div class="story-title">Why I Coach</div>
                <p class="story-text"><?php echo nl2br(esc_html($coaching_why)); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($training_philosophy): ?>
            <div class="card story-sec">
                <div class="story-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                </div>
                <div class="story-title">Training Philosophy</div>
                <p class="story-text"><?php echo nl2br(esc_html($training_philosophy)); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($locations)): ?>
            <div class="card map-sec">
                <div class="card-t">Training Locations</div>
                <?php 
                // Build map query for all locations
                $first_addr = $locations[0]['address'] ?? $location;
                if ($google_maps_key && $first_addr):
                ?>
                <div class="map-wrap">
                    <iframe 
                        src="https://www.google.com/maps/embed/v1/place?key=<?php echo esc_attr($google_maps_key); ?>&q=<?php echo urlencode($first_addr); ?>&zoom=13"
                        allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
                <?php elseif ($first_addr): ?>
                <div class="map-wrap" style="display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f5f5f5 0%,#e5e7eb 100%);">
                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($first_addr); ?>" target="_blank" style="display:flex;flex-direction:column;align-items:center;text-decoration:none;color:#374151;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:40px;height:40px;margin-bottom:8px;color:#FCB900"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span style="font-weight:600;font-size:13px;">Open in Google Maps</span>
                    </a>
                </div>
                <?php endif; ?>
                <div class="map-locs">
                    <?php foreach ($locations as $loc): 
                        if (empty($loc['name']) && empty($loc['address'])) continue;
                    ?>
                    <div class="map-loc">
                        <div class="map-loc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </div>
                        <div>
                            <div class="map-loc-name"><?php echo esc_html($loc['name'] ?? 'Training Field'); ?></div>
                            <?php if (!empty($loc['address'])): ?>
                            <div class="map-loc-addr">
                                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($loc['address']); ?>" target="_blank" style="color:#6B7280;text-decoration:none;">
                                    <?php echo esc_html($loc['address']); ?> →
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card policy-sec">
                <div class="card-t">Training Policy</div>
                <ul class="policy-list">
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        <span><strong>24-Hour Cancellation:</strong> Cancel at least 24 hours before your session for a full refund.</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                        <span><strong>No-Shows:</strong> No-shows will be charged the full session rate.</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M12 2v6M12 18v4M4.93 4.93l4.24 4.24M14.83 14.83l4.24 4.24M2 12h6M18 12h4M4.93 19.07l4.24-4.24M14.83 9.17l4.24-4.24"/></svg>
                        <span><strong>Weather:</strong> Sessions cancelled due to weather will be rescheduled at no extra cost.</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span><strong>Rescheduling:</strong> Reschedule up to 12 hours before without penalty.</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span><strong>Packages:</strong> Session packs are valid for 6 months from purchase date.</span>
                    </li>
                </ul>
            </div>
            
            <?php if ($reviews): ?>
            <div class="card" style="margin-top:16px">
                <div class="card-t">Reviews</div>
                <?php foreach ($reviews as $r): ?>
                <div class="review">
                    <div class="r-top"><span class="r-name"><?php echo esc_html($r->name); ?></span><span class="r-stars"><?php echo str_repeat('★', intval($r->rating)); ?></span></div>
                    <p class="r-text"><?php echo esc_html($r->review_text); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php 
            // Gallery images
            $gallery = $trainer->gallery_images ? json_decode($trainer->gallery_images, true) : array();
            if (!empty($gallery)):
            ?>
            <div class="card" style="margin-top:16px">
                <div class="card-t">📸 Gallery</div>
                <div class="gallery-grid">
                    <?php foreach (array_slice($gallery, 0, 6) as $img): ?>
                    <div class="gallery-img"><img src="<?php echo esc_url($img); ?>" alt="" loading="lazy"></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Text Coordinator Box -->
            <div class="card coordinator-card" style="margin-top:16px">
                <div class="card-t">💬 Need Help Scheduling?</div>
                <p style="font-size:14px;color:var(--gray5);margin-bottom:12px;">Text our scheduling coordinator to find the perfect time or ask any questions!</p>
                <a href="sms:+14845724770" class="coord-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;margin-right:8px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Text (484) 572-4770
                </a>
                <p style="font-size:11px;color:var(--gray4);margin-top:8px;text-align:center;">Available Mon-Sat, 9am-8pm EST</p>
            </div>
        </div>
        
        <div class="card book" id="book">
            <div class="book-bar" id="bar">
                <div class="book-bar-left">
                    <div><span class="book-bar-price">$<?php echo $rate; ?></span><span class="book-bar-unit">/session</span></div>
                    <div class="book-bar-save">Save up to 15%</div>
                </div>
                <span class="book-bar-btn">Book Now</span>
            </div>
            
            <div class="book-body">
                <div class="book-header">
                    <button class="book-close" id="bookClose" type="button" aria-label="Close">✕</button>
                    <div class="book-rate">$<?php echo $rate; ?><small>/session</small></div>
                    <div class="book-save">Save up to 15% with packs</div>
                </div>
                
                <div class="steps" id="stepsBar" style="--progress:0%">
                    <div class="step on" data-s="1"><div class="step-n">1</div><div class="step-l">Group</div></div>
                    <div class="step" data-s="2"><div class="step-n">2</div><div class="step-l">Date</div></div>
                    <div class="step" data-s="3"><div class="step-n">3</div><div class="step-l">Time</div></div>
                    <div class="step" data-s="4"><div class="step-n">4</div><div class="step-l">Location</div></div>
                    <div class="step" data-s="5"><div class="step-n">5</div><div class="step-l">Package</div></div>
                </div>
                
                <!-- Step 1: Group Size -->
                <div class="sec on" data-sec="1">
                    <div class="sec-t">How Many Players?</div>
                    <div class="group-sizes" id="groupSizes">
                        <div class="group-size sel" data-size="1" data-mult="1">
                            <div class="group-size-icon"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="7" r="4"/><path d="M12 14c-4.42 0-8 1.79-8 4v2h16v-2c0-2.21-3.58-4-8-4z"/></svg></div>
                            <div class="group-size-label">Solo</div>
                            <div class="group-size-price">$<?php echo $rate; ?></div>
                        </div>
                        <div class="group-size" data-size="2" data-mult="1.6">
                            <div class="group-size-icon"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="7" r="4"/><path d="M9 14c-4.42 0-8 1.79-8 4v2h16v-2c0-2.21-3.58-4-8-4z"/><circle cx="17" cy="8" r="3" opacity="0.6"/><path d="M17 13c2.21 0 4 .9 4 2v1h-4" opacity="0.6"/></svg></div>
                            <div class="group-size-label">Duo</div>
                            <div class="group-size-price">$<?php echo intval($rate * 1.6); ?></div>
                            <div class="group-size-per">$<?php echo intval($rate * 1.6 / 2); ?>/ea</div>
                        </div>
                        <div class="group-size" data-size="3" data-mult="2">
                            <div class="group-size-icon"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="3"/><circle cx="6" cy="8" r="2.5" opacity="0.7"/><circle cx="18" cy="8" r="2.5" opacity="0.7"/><path d="M12 10c-3.31 0-6 1.34-6 3v2h12v-2c0-1.66-2.69-3-6-3z"/><path d="M6 12c-1.66 0-3 .67-3 1.5v1.5h3" opacity="0.7"/><path d="M18 12c1.66 0 3 .67 3 1.5v1.5h-3" opacity="0.7"/></svg></div>
                            <div class="group-size-label">Trio</div>
                            <div class="group-size-price">$<?php echo intval($rate * 2); ?></div>
                            <div class="group-size-per">$<?php echo intval($rate * 2 / 3); ?>/ea</div>
                        </div>
                        <div class="group-size" data-size="4" data-mult="2.4">
                            <div class="group-size-icon"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="5" r="2.5"/><circle cx="16" cy="5" r="2.5"/><circle cx="5" cy="11" r="2" opacity="0.7"/><circle cx="19" cy="11" r="2" opacity="0.7"/><path d="M12 9c-2.67 0-5 1.07-5 2.4v1.6h10v-1.6c0-1.33-2.33-2.4-5-2.4z"/><path d="M5 14c-1.33 0-2.5.5-2.5 1.2v1.3h2.5" opacity="0.7"/><path d="M19 14c1.33 0 2.5.5 2.5 1.2v1.3h-2.5" opacity="0.7"/></svg></div>
                            <div class="group-size-label">Quad</div>
                            <div class="group-size-price">$<?php echo intval($rate * 2.4); ?></div>
                            <div class="group-size-per">$<?php echo intval($rate * 2.4 / 4); ?>/ea</div>
                        </div>
                    </div>
                    <p style="font-size:11px;color:var(--gray5);text-align:center;margin:0 0 12px;">Group sessions = more fun, lower cost per player</p>
                    <button class="btn btn-g" id="toDate" type="button">Continue</button>
                </div>
                
                <!-- Step 2: Date -->
                <div class="sec" data-sec="2">
                    <div class="sec-t">Select Date</div>
                    <div class="cal-h">
                        <span class="cal-m" id="calM"></span>
                        <div class="cal-nav">
                            <button class="cal-btn" id="calP" type="button">◀</button>
                            <button class="cal-btn" id="calN" type="button">▶</button>
                        </div>
                    </div>
                    <div class="cal-days"><span class="cal-d">S</span><span class="cal-d">M</span><span class="cal-d">T</span><span class="cal-d">W</span><span class="cal-d">T</span><span class="cal-d">F</span><span class="cal-d">S</span></div>
                    <div class="cal-g" id="calG"></div>
                    <button class="btn btn-o" id="bGroup" type="button">← Back</button>
                    <button class="btn btn-g" id="to2" type="button" disabled>Continue</button>
                </div>
                
                <!-- Step 3: Time -->
                <div class="sec" data-sec="3">
                    <div class="sec-t">Select Time</div>
                    <div class="times" id="times"></div>
                    <button class="btn btn-o" id="b1" type="button">← Back</button>
                    <button class="btn btn-g" id="to3" type="button" disabled>Continue</button>
                </div>
                
                <!-- Step 4: Location -->
                <div class="sec" data-sec="4">
                    <div class="sec-t">Select Location</div>
                    <div class="locs" id="locs">
                        <?php foreach ($locations as $i => $l): ?>
                        <div class="loc-o<?php echo $i === 0 ? ' sel' : ''; ?>" data-loc='<?php echo esc_attr(json_encode($l)); ?>'>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <div class="loc-i"><div class="loc-n"><?php echo esc_html($l['name'] ?? 'Location'); ?></div><div class="loc-a"><?php echo esc_html($l['address'] ?? ''); ?></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-o" id="b2" type="button">← Back</button>
                    <button class="btn btn-g" id="to4" type="button">Continue</button>
                </div>
                
                <!-- Step 5: Package -->
                <div class="sec" data-sec="5">
                    <div class="sec-t">Select Package</div>
                    <div class="pkgs">
                        <?php foreach ($pkgs as $k => $p): ?>
                        <label class="pkg<?php echo $k === 'single' ? ' sel' : ''; ?>" data-pk="<?php echo $k; ?>" data-pr="<?php echo $p['p']; ?>" data-base="<?php echo $p['p']; ?>">
                            <input type="radio" name="pk" value="<?php echo $k; ?>" <?php checked($k, 'single'); ?>>
                            <div class="pkg-i"><div class="pkg-n"><?php echo $p['n']; ?></div><div class="pkg-d"><?php echo $p['s']; ?> session<?php echo $p['s'] > 1 ? 's' : ''; ?></div></div>
                            <div><div class="pkg-p">$<span class="pkg-price-val"><?php echo $p['p']; ?></span></div><?php if ($p['v']): ?><div class="pkg-v">Save $<span class="pkg-save-val"><?php echo $p['v']; ?></span></div><?php endif; ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="sum">
                        <div class="sum-r"><span>Players</span><span id="sG">1 player</span></div>
                        <div class="sum-r"><span>Date</span><span id="sD">-</span></div>
                        <div class="sum-r"><span>Time</span><span id="sT">-</span></div>
                        <div class="sum-r"><span>Location</span><span id="sL">-</span></div>
                        <div class="sum-r"><span>Total</span><span id="sP">$<?php echo $rate; ?></span></div>
                    </div>
                    <button class="btn btn-o" id="b3">← Back</button>
                    <button class="btn btn-g" id="checkout">Continue to Checkout</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="book-backdrop" id="bookBackdrop"></div>
<div class="toast" id="toast"></div>

<script>
(function(){
'use strict';

var tid=<?php echo $trainer->id; ?>,
    baseRate=<?php echo $rate; ?>,
    avD=<?php echo json_encode($available_dates); ?>,
    avB=<?php echo json_encode($avail_by_day); ?>,
    bk=<?php echo json_encode($booked_map); ?>,
    pk=<?php echo json_encode($pkgs); ?>,
    loc=<?php echo json_encode($locations[0] ?? null); ?>,
    cM=new Date(),sD=null,sT=null,sPk='single',
    groupSize=1,groupMult=1,
    currentStep=1;

var $=function(s){return document.querySelector(s)},
    $$=function(s){return document.querySelectorAll(s)};

// Toast notifications
function toast(msg,type){
    var t=$('#toast');
    t.textContent=msg;
    t.className='toast show'+(type?' '+type:'');
    setTimeout(function(){t.classList.remove('show')},3000);
}

// Haptic feedback (if supported)
function haptic(){
    if(navigator.vibrate)navigator.vibrate(10);
}

// Mobile bottom sheet toggle with smooth animation
var book=$('#book'),bar=$('#bar'),backdrop=$('#bookBackdrop'),startY=null,currentY=null,isDragging=false;
var scrollWrapper = document.getElementById('ptp-scroll-wrapper');

function showBooking(){
    // Only lock scroll on mobile (bottom sheet mode)
    if(window.innerWidth < 768){
        haptic();
        book.classList.add('expanded');
        if(backdrop)backdrop.classList.add('show');
        document.body.classList.add('ptp-modal-active');
    }
}

function hideBooking(){
    book.classList.remove('expanded');
    if(backdrop)backdrop.classList.remove('show');
    document.body.classList.remove('ptp-modal-active');
}

// Handle both click and touch for the bar
function openBooking(e){
    e.preventDefault();
    e.stopPropagation();
    if(!isDragging){
        showBooking();
    }
}

// Click handler for desktop and as fallback
bar.addEventListener('click', openBooking);

// Touch handler for mobile - more reliable than click on iOS
bar.addEventListener('touchend', function(e){
    if(!isDragging && !book.classList.contains('expanded')){
        openBooking(e);
    }
    isDragging = false;
}, {passive: false});

// Also make the "Book Now" button specifically tappable
var bookBtn = bar.querySelector('.book-bar-btn');
if(bookBtn){
    bookBtn.addEventListener('touchend', function(e){
        e.preventDefault();
        e.stopPropagation();
        showBooking();
    }, {passive: false});
    
    bookBtn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        showBooking();
    });
}

// Close on backdrop tap
if(backdrop){
    backdrop.addEventListener('click', hideBooking);
    backdrop.addEventListener('touchend', function(e){
        e.preventDefault();
        hideBooking();
    }, {passive: false});
}

// Close button handler
var closeBtn = $('#bookClose');
if(closeBtn){
    closeBtn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        hideBooking();
    });
    closeBtn.addEventListener('touchend', function(e){
        e.preventDefault();
        e.stopPropagation();
        hideBooking();
    }, {passive: false});
}

// Swipe to close bottom sheet
book.addEventListener('touchstart',function(e){
    if(e.target.closest('.book-body'))return;
    startY=e.touches[0].clientY;
    isDragging=false;
},{passive:true});

book.addEventListener('touchmove',function(e){
    if(!startY)return;
    currentY=e.touches[0].clientY;
    var diff=currentY-startY;
    if(diff>10)isDragging=true;
    if(diff>50&&book.classList.contains('expanded')){
        hideBooking();
        startY=null;
    }
},{passive:true});

book.addEventListener('touchend',function(){startY=null;},{passive:true});

// Update step progress bar
function updateProgress(step){
    currentStep=step;
    var progress=((step-1)/4)*100; // 5 steps = divide by 4
    $('#stepsBar').style.setProperty('--progress',progress+'%');
    
    $$('.step').forEach(function(s,i){
        s.classList.remove('on','done');
        if(i+1<step)s.classList.add('done');
        if(i+1===step)s.classList.add('on');
    });
}

// Go to step with animation
function goStep(n){
    haptic();
    $$('.sec').forEach(function(s){s.classList.remove('on')});
    $('[data-sec="'+n+'"]').classList.add('on');
    updateProgress(n);
    
    // Scroll to top of booking body on mobile
    if(window.innerWidth<768){
        $('.book-body').scrollTop=0;
    }
}

// Calendar with enhanced interactions
function rCal(){
    var y=cM.getFullYear(),m=cM.getMonth(),f=new Date(y,m,1).getDay(),n=new Date(y,m+1,0).getDate(),
        td=new Date();td.setHours(0,0,0,0);
    $('#calM').textContent=cM.toLocaleDateString('en-US',{month:'long',year:'numeric'});
    var h='';
    for(var i=0;i<f;i++)h+='<div class="cal-c"></div>';
    for(var d=1;d<=n;d++){
        var dt=new Date(y,m,d),ds=dt.toISOString().split('T')[0],
            isT=dt.getTime()===td.getTime(),isP=dt<td,isA=avD.indexOf(ds)>-1,isS=sD===ds,
            c='cal-c';
        if(isT)c+=' today';
        if(isP||!isA)c+=' off';
        else c+=' av';
        if(isS)c+=' sel';
        h+='<div class="'+c+'" data-d="'+ds+'">'+d+'</div>';
    }
    $('#calG').innerHTML=h;
    
    $$('.cal-c.av').forEach(function(e){
        e.onclick=function(){
            haptic();
            $$('.cal-c').forEach(function(x){x.classList.remove('sel')});
            e.classList.add('sel');
            sD=e.dataset.d;
            $('#to2').disabled=false;
            // Auto-advance after brief delay
            setTimeout(function(){
                if(sD)goStep(3);rTimes();
            },300);
        };
    });
}

$('#calP').onclick=function(){haptic();cM.setMonth(cM.getMonth()-1);rCal()};
$('#calN').onclick=function(){haptic();cM.setMonth(cM.getMonth()+1);rCal()};

// Time slots with loading state
function rTimes(){
    if(!sD)return;
    var timesEl=$('#times');
    
    // Show skeleton loading
    timesEl.innerHTML='<div class="skeleton" style="height:48px"></div><div class="skeleton" style="height:48px"></div><div class="skeleton" style="height:48px"></div>';
    
    setTimeout(function(){
        var dt=new Date(sD+'T12:00:00'),dow=dt.getDay(),av=avB[dow]||[],slots=[];
        av.forEach(function(b){
            var s=parseInt(b.start.split(':')[0]),e=parseInt(b.end.split(':')[0]);
            for(var h=s;h<e;h++){
                var ts=('0'+h).slice(-2)+':00:00',isBk=bk[sD+'_'+ts],
                    dh=h>12?h-12:(h||12),ap=h>=12?'PM':'AM';
                slots.push({t:ts,d:dh+':00 '+ap,b:!!isBk});
            }
        });
        
        if(!slots.length){
            timesEl.innerHTML='<p class="empty">No times available for this date</p>';
            return;
        }
        
        timesEl.innerHTML=slots.map(function(s){
            return '<div class="time'+(s.b?' off':'')+'" data-t="'+s.t+'">'+s.d+'</div>';
        }).join('');
        
        $$('.time:not(.off)').forEach(function(e){
            e.onclick=function(){
                haptic();
                $$('.time').forEach(function(x){x.classList.remove('sel')});
                e.classList.add('sel');
                sT=e.dataset.t;
                $('#to3').disabled=false;
            };
        });
    },200);
}

// Locations
$$('.loc-o').forEach(function(e){
    e.onclick=function(){
        haptic();
        $$('.loc-o').forEach(function(x){x.classList.remove('sel')});
        e.classList.add('sel');
        loc=JSON.parse(e.dataset.loc);
    };
});

// Packages with "Best Value" highlight
function initPackages(){
    var pkgEls=$$('.pkg');
    pkgEls.forEach(function(e,i){
        // Add "popular" class to 5-pack (best value)
        if(e.dataset.pk==='pack5')e.classList.add('popular');
        
        e.onclick=function(){
            haptic();
            pkgEls.forEach(function(x){x.classList.remove('sel')});
            e.classList.add('sel');
            sPk=e.dataset.pk;
            updatePackagePrices();
        };
    });
}

// Update package prices based on group size
function updatePackagePrices(){
    $$('.pkg').forEach(function(e){
        var basePrice=parseInt(e.dataset.base||e.dataset.pr);
        var newPrice=Math.round(basePrice*groupMult);
        e.dataset.pr=newPrice;
        var priceEl=e.querySelector('.pkg-price-val');
        if(priceEl)priceEl.textContent=newPrice;
    });
    // Update total in summary
    var selPkg=$('.pkg.sel');
    if(selPkg){
        $('#sP').textContent='$'+selPkg.dataset.pr;
    }
}

initPackages();

// Group size selection
$$('.group-size').forEach(function(e){
    e.onclick=function(){
        haptic();
        $$('.group-size').forEach(function(x){x.classList.remove('sel')});
        e.classList.add('sel');
        groupSize=parseInt(e.dataset.size);
        groupMult=parseFloat(e.dataset.mult);
        updatePackagePrices();
    };
});

// Update summary display
function updateSummary(){
    $('#sG').textContent=groupSize+' player'+(groupSize>1?'s':'');
    if(sD){
        var d=new Date(sD+'T12:00:00');
        $('#sD').textContent=d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
    }
    if(sT){
        var h=parseInt(sT.split(':')[0]),dh=h>12?h-12:(h||12),ap=h>=12?'PM':'AM';
        $('#sT').textContent=dh+':00 '+ap;
    }
    if(loc){
        $('#sL').textContent=loc.name||'Selected';
    }
}

// Navigation buttons - 5 step flow
$('#toDate').onclick=function(){goStep(2);rCal()};
$('#to2').onclick=function(){goStep(3);rTimes()};
$('#to3').onclick=function(){goStep(4)};
$('#to4').onclick=function(){goStep(5);updateSummary()};
$('#bGroup').onclick=function(){goStep(1)};
$('#b1').onclick=function(){goStep(2)};
$('#b2').onclick=function(){goStep(3)};
$('#b3').onclick=function(){goStep(4)};

// Checkout with loading state
$('#checkout').onclick=function(){
    if(!sD||!sT){
        toast('Please select date and time','error');
        return;
    }
    
    haptic();
    var btn=this;
    btn.classList.add('loading');
    btn.disabled=true;
    
    // Build checkout URL with group size
    var u='<?php echo home_url('/ptp-checkout/'); ?>?trainer_id='+tid+'&package='+sPk+'&date='+sD+'&time='+sT+'&location='+encodeURIComponent(loc?loc.name:'')+'&group_size='+groupSize;
    
    // Slight delay for visual feedback
    setTimeout(function(){
        window.location.href=u;
    },300);
};

// Initialize
rCal();
updateProgress(1);

// Expand booking widget on desktop
if(window.innerWidth>=768){
    book.classList.add('expanded');
}

// v116: Trainer Share Functions
window.toggleTrainerShare=function(){
    var dd=document.getElementById('trainerShareDropdown');
    if(dd){dd.classList.toggle('show');}
};

window.copyTrainerLink=function(link,btn){
    if(navigator.clipboard&&navigator.clipboard.writeText){
        navigator.clipboard.writeText(link).then(function(){
            var orig=btn.innerHTML;
            btn.innerHTML='✓ Copied!';
            setTimeout(function(){btn.innerHTML=orig;},2000);
        });
    }else{
        var inp=document.createElement('textarea');
        inp.value=link;inp.style.position='fixed';inp.style.opacity='0';
        document.body.appendChild(inp);inp.select();
        try{document.execCommand('copy');
            var orig=btn.innerHTML;btn.innerHTML='✓ Copied!';
            setTimeout(function(){btn.innerHTML=orig;},2000);
        }catch(e){alert('Link: '+link);}
        document.body.removeChild(inp);
    }
};

// Close share dropdown when clicking outside
document.addEventListener('click',function(e){
    if(!e.target.closest('.trainer-share-wrap')){
        var dd=document.getElementById('trainerShareDropdown');
        if(dd)dd.classList.remove('show');
    }
});

// v130: Virtual keyboard detection - adjust booking sheet when keyboard opens
(function() {
    var book = document.querySelector('.book');
    if (!book || !('visualViewport' in window)) return;
    
    window.visualViewport.addEventListener('resize', function() {
        var keyboardHeight = window.innerHeight - window.visualViewport.height;
        if (keyboardHeight > 100 && window.innerWidth < 768) {
            // Keyboard open on mobile - shrink booking sheet
            book.style.maxHeight = (window.visualViewport.height - 20) + 'px';
        } else {
            // Keyboard closed - restore
            book.style.maxHeight = '';
        }
    });
})();

})();
</script>

<?php if ($review_booking): ?>
<!-- Review Modal -->
<div class="review-overlay" id="reviewOverlay">
    <div class="review-modal" style="position:relative;">
        <button class="review-close" onclick="closeReview()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        
        <div id="reviewForm">
            <div class="review-header">
                <h2>Rate Your Session</h2>
                <p>with <?php echo esc_html($trainer->display_name); ?></p>
            </div>
            <div class="review-body">
                <div class="review-stars" id="reviewStars">
                    <button class="review-star" data-rating="1"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>
                    <button class="review-star" data-rating="2"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>
                    <button class="review-star" data-rating="3"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>
                    <button class="review-star" data-rating="4"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>
                    <button class="review-star" data-rating="5"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>
                </div>
                <textarea class="review-textarea" id="reviewText" placeholder="Tell other parents about your experience (optional)"></textarea>
                <p class="review-hint">Your review helps other families find great trainers!</p>
                <button class="review-submit" id="reviewSubmit" disabled>Submit Review</button>
            </div>
        </div>
        
        <div id="reviewSuccess" style="display:none;">
            <div class="review-success">
                <div class="review-success-icon">
                    <svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <h3>Thank You!</h3>
                <p>Your review helps other families find great trainers.</p>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var overlay = document.getElementById('reviewOverlay');
    var stars = document.querySelectorAll('.review-star');
    var submitBtn = document.getElementById('reviewSubmit');
    var textArea = document.getElementById('reviewText');
    var selectedRating = 0;
    var bookingId = <?php echo intval($review_booking_id); ?>;
    
    // Auto-open modal
    setTimeout(function(){ overlay.classList.add('show'); }, 300);
    
    // Star click handlers
    stars.forEach(function(star){
        star.addEventListener('click', function(){
            selectedRating = parseInt(this.dataset.rating);
            stars.forEach(function(s, i){
                if(i < selectedRating) s.classList.add('active');
                else s.classList.remove('active');
            });
            submitBtn.disabled = false;
        });
    });
    
    // Submit handler
    submitBtn.addEventListener('click', function(){
        if(!selectedRating) return;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
        
        var formData = new FormData();
        formData.append('action', 'ptp_submit_review');
        formData.append('nonce', '<?php echo wp_create_nonce('ptp_ajax'); ?>');
        formData.append('booking_id', bookingId);
        formData.append('rating', selectedRating);
        formData.append('review', textArea.value);
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(data.success){
                document.getElementById('reviewForm').style.display = 'none';
                document.getElementById('reviewSuccess').style.display = 'block';
                // Confetti!
                for(var i=0;i<50;i++){
                    var c = document.createElement('div');
                    c.style.cssText = 'position:fixed;width:10px;height:10px;background:'+['#FCB900','#22c55e','#3b82f6','#ef4444','#8b5cf6'][Math.floor(Math.random()*5)]+';border-radius:50%;pointer-events:none;z-index:99999;left:'+(Math.random()*100)+'vw;top:-20px;animation:confetti '+(1+Math.random())+'s ease-out forwards';
                    document.body.appendChild(c);
                    setTimeout(function(el){el.remove();},2000,c);
                }
                // Close after delay
                setTimeout(function(){
                    closeReview();
                    window.history.replaceState({}, '', window.location.pathname);
                }, 2500);
            } else {
                alert(data.data?.message || 'Error submitting review');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Review';
            }
        })
        .catch(function(){
            alert('Error submitting review');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Review';
        });
    });
    
    // Close function
    window.closeReview = function(){
        overlay.classList.remove('show');
        window.history.replaceState({}, '', window.location.pathname);
    };
    
    // Close on overlay click
    overlay.addEventListener('click', function(e){
        if(e.target === overlay) closeReview();
    });
    
    // Handle virtual keyboard on mobile
    if('visualViewport' in window){
        window.visualViewport.addEventListener('resize', function(){
            var modal = document.querySelector('.review-modal');
            if(modal && window.innerWidth < 500){
                var vh = window.visualViewport.height;
                modal.style.maxHeight = (vh - 20) + 'px';
            }
        });
    }
    
    // Add confetti animation
    var style = document.createElement('style');
    style.textContent = '@keyframes confetti{to{transform:translateY(100vh) rotate(720deg);opacity:0}}';
    document.head.appendChild(style);
})();
</script>
<?php endif; ?>

<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
</div><!-- .ptp-trainer-profile -->

<?php get_footer(); ?>
