<?php
/**
 * Trainer Onboarding Template v133
 * 
 * MAJOR IMPROVEMENTS:
 * - Mobile-first responsive design
 * - Better touch targets (48px minimum)
 * - Improved availability grid for mobile
 * - Progress stepper with horizontal scroll
 * - Sticky submit button on mobile
 * - Better photo upload UX
 * - Improved form validation feedback
 * - Safe area insets for notched devices
 * - Smoother animations
 * - Better contract section readability
 * 
 * @since 133.0.0
 */

defined('ABSPATH') || exit;

// $trainer is passed from the shortcode
if (!isset($trainer) || !$trainer) {
    wp_redirect(home_url('/apply/'));
    exit;
}

$trainer_id = $trainer->id;
$is_edit = isset($_GET['edit']) && $_GET['edit'] == '1';

// Get existing training locations
$training_locations = array();
if (!empty($trainer->training_locations)) {
    $decoded = json_decode($trainer->training_locations, true);
    if (is_array($decoded)) {
        $training_locations = $decoded;
    } else {
        $training_locations = array_filter(array_map('trim', explode("\n", $trainer->training_locations)));
    }
}

// Get existing availability data
$availability = array();
if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'get_weekly')) {
    $raw_availability = PTP_Availability::get_weekly($trainer_id);
    $day_names = array(0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday');
    
    if (!empty($raw_availability)) {
        foreach ($raw_availability as $slot) {
            $day_num = intval($slot->day_of_week);
            if (isset($day_names[$day_num])) {
                $day_name = $day_names[$day_num];
                $availability[$day_name] = array(
                    'enabled' => !empty($slot->is_active),
                    'start' => substr($slot->start_time, 0, 5),
                    'end' => substr($slot->end_time, 0, 5),
                );
            }
        }
    }
}

// Check Stripe status
$has_stripe = !empty($trainer->stripe_account_id);
$stripe_complete = false;
if ($has_stripe && class_exists('PTP_Stripe')) {
    $stripe_complete = PTP_Stripe::is_account_complete($trainer->stripe_account_id);
}

// Calculate completion
$completion = array(
    'photo' => !empty($trainer->photo_url),
    'bio' => !empty($trainer->bio) && strlen($trainer->bio) > 50,
    'experience' => !empty($trainer->playing_level),
    'rate' => !empty($trainer->hourly_rate) && $trainer->hourly_rate > 0,
    'location' => !empty($trainer->city) && !empty($trainer->state),
    'training_locations' => !empty($training_locations),
    'availability' => !empty($availability),
    'contract' => !empty($trainer->contractor_agreement_signed),
    'stripe' => $stripe_complete,
);
$completed = array_filter($completion);
$percentage = count($completed) / count($completion) * 100;
$steps_done = count($completed);
$steps_total = count($completion);

// Google Maps API key
$google_maps_key = get_option('ptp_google_maps_key', '');

// First name for personalization
$first_name = explode(' ', $trainer->display_name)[0];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo $is_edit ? 'Edit Profile' : 'Complete Your Profile'; ?> - PTP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <?php if ($google_maps_key): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&libraries=places&loading=async" async defer></script>
    <?php endif; ?>
    <style>
    /* ===========================================
       v133: DESIGN TOKENS
       =========================================== */
    :root {
        --gold: #FCB900;
        --gold-hover: #E5A800;
        --gold-light: rgba(252, 185, 0, 0.1);
        --black: #0A0A0A;
        --white: #FFFFFF;
        --gray-50: #FAFAFA;
        --gray-100: #F5F5F5;
        --gray-200: #E5E5E5;
        --gray-300: #D4D4D4;
        --gray-400: #A3A3A3;
        --gray-500: #737373;
        --gray-600: #525252;
        --green: #22C55E;
        --green-light: rgba(34, 197, 94, 0.1);
        --red: #EF4444;
        --red-light: rgba(239, 68, 68, 0.1);
        --stripe: #635BFF;
        
        --radius-sm: 8px;
        --radius-md: 12px;
        --radius-lg: 16px;
        --radius-xl: 20px;
        
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
        --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
        --shadow-lg: 0 12px 32px rgba(0,0,0,0.12);
        
        --safe-top: env(safe-area-inset-top, 0px);
        --safe-bottom: env(safe-area-inset-bottom, 0px);
        --safe-left: env(safe-area-inset-left, 0px);
        --safe-right: env(safe-area-inset-right, 0px);
        
        --header-height: 60px;
        --touch-min: 48px;
    }
    
    /* ===========================================
       v133: GLOBAL RESET & SCROLLBAR HIDING
       =========================================== */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    html, body {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }
    
    html {
        -webkit-text-size-adjust: 100%;
        -webkit-tap-highlight-color: transparent;
        scroll-behavior: smooth;
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: var(--gray-100);
        color: var(--black);
        line-height: 1.5;
        min-height: 100vh;
        min-height: 100dvh;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    
    h1, h2, h3, h4 {
        font-family: 'Oswald', sans-serif;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        line-height: 1.2;
    }
    
    a { color: inherit; text-decoration: none; }
    img { max-width: 100%; height: auto; display: block; }
    button { font-family: inherit; cursor: pointer; }
    input, select, textarea { font-family: inherit; }
    
    /* ===========================================
       v133: HEADER
       =========================================== */
    .ptp-header {
        position: sticky;
        top: 0;
        z-index: 100;
        background: var(--black);
        padding: 12px 16px;
        padding-top: calc(12px + var(--safe-top));
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 3px solid var(--gold);
    }
    
    .ptp-header-logo { height: 32px; width: auto; }
    
    .ptp-header-help {
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--gray-400);
        font-size: 13px;
        padding: 8px 12px;
        border-radius: var(--radius-sm);
        transition: all 0.2s;
    }
    .ptp-header-help:hover { color: var(--white); background: rgba(255,255,255,0.1); }
    .ptp-header-help svg { width: 16px; height: 16px; }
    
    /* ===========================================
       v133: MAIN CONTAINER
       =========================================== */
    .ptp-onboard {
        max-width: 640px;
        margin: 0 auto;
        padding: 20px 16px 120px;
    }
    @media (min-width: 640px) {
        .ptp-onboard { padding: 32px 24px 100px; }
    }
    
    /* ===========================================
       v133: WELCOME HEADER
       =========================================== */
    .ptp-welcome {
        text-align: center;
        margin-bottom: 24px;
    }
    
    .ptp-welcome-emoji {
        font-size: 48px;
        margin-bottom: 12px;
    }
    
    .ptp-welcome h1 {
        font-size: 24px;
        margin-bottom: 8px;
        color: var(--black);
    }
    .ptp-welcome h1 span { color: var(--gold); }
    @media (min-width: 640px) {
        .ptp-welcome h1 { font-size: 28px; }
    }
    
    .ptp-welcome p {
        font-size: 15px;
        color: var(--gray-600);
        max-width: 400px;
        margin: 0 auto;
    }
    
    /* ===========================================
       v133: PROGRESS BAR
       =========================================== */
    .ptp-progress {
        background: var(--white);
        border-radius: var(--radius-lg);
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: var(--shadow-sm);
    }
    
    .ptp-progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }
    
    .ptp-progress-count {
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        color: var(--black);
    }
    .ptp-progress-count span { color: var(--gold); }
    
    .ptp-progress-percent {
        font-size: 13px;
        font-weight: 600;
        color: var(--green);
    }
    
    .ptp-progress-bar {
        height: 8px;
        background: var(--gray-200);
        border-radius: 4px;
        overflow: hidden;
    }
    
    .ptp-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--gold) 0%, #FFD54F 100%);
        border-radius: 4px;
        transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Progress Steps - Horizontal Scroll */
    .ptp-progress-steps {
        display: flex;
        gap: 8px;
        margin-top: 16px;
        overflow-x: auto;
        padding-bottom: 4px;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .ptp-progress-steps::-webkit-scrollbar { display: none; }
    
    .ptp-step {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background: var(--gray-100);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        color: var(--gray-500);
        transition: all 0.2s;
    }
    
    .ptp-step.done {
        background: var(--green-light);
        color: var(--green);
    }
    
    .ptp-step-icon {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: var(--gray-300);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .ptp-step.done .ptp-step-icon {
        background: var(--green);
    }
    .ptp-step-icon svg {
        width: 10px;
        height: 10px;
        stroke: var(--white);
        stroke-width: 3;
    }
    
    /* ===========================================
       v133: FORM SECTIONS
       =========================================== */
    .ptp-section {
        background: var(--white);
        border-radius: var(--radius-lg);
        margin-bottom: 16px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    
    .ptp-section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 20px;
        border-bottom: 1px solid var(--gray-200);
        cursor: pointer;
        transition: background 0.2s;
    }
    .ptp-section-header:hover { background: var(--gray-50); }
    
    .ptp-section-num {
        width: 32px;
        height: 32px;
        background: var(--black);
        color: var(--white);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        flex-shrink: 0;
    }
    .ptp-section.complete .ptp-section-num {
        background: var(--green);
    }
    
    .ptp-section-title {
        flex: 1;
    }
    .ptp-section-title h2 {
        font-size: 15px;
        margin-bottom: 2px;
    }
    .ptp-section-title p {
        font-size: 13px;
        color: var(--gray-500);
        margin: 0;
    }
    
    .ptp-section-toggle {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--gray-400);
        transition: transform 0.3s;
    }
    .ptp-section.open .ptp-section-toggle { transform: rotate(180deg); }
    
    .ptp-section-body {
        padding: 20px;
        display: none;
    }
    .ptp-section.open .ptp-section-body { display: block; }
    
    /* ===========================================
       v133: FORM ELEMENTS
       =========================================== */
    .ptp-field {
        margin-bottom: 20px;
    }
    .ptp-field:last-child { margin-bottom: 0; }
    
    .ptp-label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--gray-600);
        margin-bottom: 8px;
    }
    .ptp-label .required { color: var(--red); }
    
    .ptp-input,
    .ptp-select,
    .ptp-textarea {
        width: 100%;
        padding: 14px 16px;
        font-size: 16px; /* Prevents iOS zoom */
        border: 2px solid var(--gray-200);
        border-radius: var(--radius-sm);
        background: var(--white);
        transition: all 0.2s;
        min-height: var(--touch-min);
    }
    
    .ptp-input:focus,
    .ptp-select:focus,
    .ptp-textarea:focus {
        outline: none;
        border-color: var(--gold);
        box-shadow: 0 0 0 3px var(--gold-light);
    }
    
    .ptp-input.error,
    .ptp-select.error,
    .ptp-textarea.error {
        border-color: var(--red);
        background: var(--red-light);
    }
    
    .ptp-input::placeholder { color: var(--gray-400); }
    
    .ptp-textarea {
        min-height: 120px;
        resize: vertical;
    }
    
    .ptp-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23525252' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
        padding-right: 44px;
    }
    
    .ptp-hint {
        font-size: 12px;
        color: var(--gray-500);
        margin-top: 6px;
    }
    
    .ptp-error-msg {
        font-size: 12px;
        color: var(--red);
        margin-top: 6px;
        display: none;
    }
    .ptp-field.has-error .ptp-error-msg { display: block; }
    
    /* Grid layout */
    .ptp-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
    }
    @media (min-width: 480px) {
        .ptp-grid-2 { grid-template-columns: 1fr 1fr; }
    }
    
    /* ===========================================
       v133: PHOTO UPLOAD
       =========================================== */
    .ptp-photo-upload {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 16px;
        text-align: center;
    }
    @media (min-width: 480px) {
        .ptp-photo-upload {
            flex-direction: row;
            text-align: left;
        }
    }
    
    .ptp-photo-preview {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: var(--gray-100);
        border: 4px solid var(--gold);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        position: relative;
    }
    
    .ptp-photo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .ptp-photo-preview svg {
        width: 48px;
        height: 48px;
        stroke: var(--gray-400);
    }
    
    .ptp-photo-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.2s;
        border-radius: 50%;
    }
    .ptp-photo-preview:hover .ptp-photo-overlay { opacity: 1; }
    .ptp-photo-overlay svg { stroke: var(--white); width: 32px; height: 32px; }
    
    .ptp-photo-actions {
        flex: 1;
    }
    
    .ptp-photo-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 14px 24px;
        background: var(--black);
        color: var(--white);
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border: none;
        border-radius: var(--radius-sm);
        min-height: var(--touch-min);
        transition: all 0.2s;
    }
    .ptp-photo-btn:hover { background: #333; }
    .ptp-photo-btn svg { width: 18px; height: 18px; }
    
    .ptp-photo-tips {
        margin-top: 12px;
        font-size: 13px;
        color: var(--gray-500);
    }
    .ptp-photo-tips li {
        margin-bottom: 4px;
        padding-left: 16px;
        position: relative;
    }
    .ptp-photo-tips li::before {
        content: '✓';
        position: absolute;
        left: 0;
        color: var(--green);
    }
    
    /* ===========================================
       v133: AVAILABILITY GRID - MOBILE OPTIMIZED
       =========================================== */
    .ptp-availability {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .ptp-avail-day {
        background: var(--gray-50);
        border-radius: var(--radius-md);
        padding: 16px;
        border: 2px solid transparent;
        transition: all 0.2s;
    }
    .ptp-avail-day.active {
        background: var(--gold-light);
        border-color: var(--gold);
    }
    
    .ptp-avail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    
    .ptp-avail-name {
        font-family: 'Oswald', sans-serif;
        font-size: 15px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    /* Toggle Switch */
    .ptp-toggle {
        position: relative;
        width: 52px;
        height: 28px;
        background: var(--gray-300);
        border-radius: 14px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .ptp-toggle.active { background: var(--green); }
    
    .ptp-toggle::after {
        content: '';
        position: absolute;
        width: 24px;
        height: 24px;
        background: var(--white);
        border-radius: 50%;
        top: 2px;
        left: 2px;
        transition: transform 0.2s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    .ptp-toggle.active::after { transform: translateX(24px); }
    
    .ptp-avail-times {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 12px;
        align-items: center;
    }
    
    .ptp-avail-times span {
        text-align: center;
        font-size: 13px;
        color: var(--gray-500);
    }
    
    .ptp-time-input {
        padding: 12px;
        font-size: 16px;
        text-align: center;
        border: 2px solid var(--gray-200);
        border-radius: var(--radius-sm);
        background: var(--white);
        min-height: var(--touch-min);
    }
    .ptp-time-input:focus {
        outline: none;
        border-color: var(--gold);
    }
    
    /* ===========================================
       v133: TRAINING LOCATIONS
       =========================================== */
    .ptp-locations-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 16px;
    }
    
    .ptp-location-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        background: var(--gray-50);
        border-radius: var(--radius-sm);
        border: 2px solid var(--gray-200);
    }
    
    .ptp-location-item svg { flex-shrink: 0; }
    .ptp-location-item span { flex: 1; font-size: 14px; }
    
    .ptp-location-remove {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--red-light);
        color: var(--red);
        border: none;
        border-radius: 50%;
        transition: all 0.2s;
    }
    .ptp-location-remove:hover { background: var(--red); color: var(--white); }
    
    .ptp-location-add {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    @media (min-width: 480px) {
        .ptp-location-add {
            flex-direction: row;
        }
        .ptp-location-add .ptp-input { flex: 1; }
    }
    
    .ptp-location-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 14px 20px;
        background: var(--gold);
        color: var(--black);
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        border: none;
        border-radius: var(--radius-sm);
        min-height: var(--touch-min);
        transition: all 0.2s;
        white-space: nowrap;
    }
    .ptp-location-btn:hover { background: var(--gold-hover); }
    
    .ptp-map-container {
        height: 200px;
        border-radius: var(--radius-md);
        overflow: hidden;
        background: var(--gray-200);
        margin-top: 16px;
    }
    @media (min-width: 640px) {
        .ptp-map-container { height: 280px; }
    }
    
    /* ===========================================
       v133: CONTRACT SECTION
       =========================================== */
    .ptp-contract-status {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px;
        border-radius: var(--radius-md);
        background: var(--green-light);
    }
    .ptp-contract-status svg { flex-shrink: 0; }
    .ptp-contract-status strong { display: block; font-size: 15px; }
    .ptp-contract-status p { margin: 4px 0 0; font-size: 13px; color: var(--gray-600); }
    
    .ptp-contract-box {
        border: 2px solid var(--gray-200);
        border-radius: var(--radius-md);
        overflow: hidden;
    }
    
    .ptp-contract-scroll {
        max-height: 300px;
        overflow-y: auto;
        padding: 20px;
        background: var(--gray-50);
        font-size: 14px;
        line-height: 1.7;
        -webkit-overflow-scrolling: touch;
    }
    @media (min-width: 640px) {
        .ptp-contract-scroll { max-height: 400px; padding: 24px; }
    }
    
    .ptp-contract-scroll h3 {
        font-size: 16px;
        margin-bottom: 16px;
    }
    .ptp-contract-scroll h4 {
        font-size: 13px;
        margin: 20px 0 8px;
        color: var(--black);
    }
    .ptp-contract-scroll p { margin-bottom: 12px; color: #374151; }
    .ptp-contract-scroll ul { margin: 0 0 12px; padding-left: 20px; color: #374151; }
    .ptp-contract-scroll li { margin-bottom: 6px; }
    
    .ptp-contract-signature {
        padding: 20px;
        background: var(--white);
        border-top: 2px solid var(--gray-200);
    }
    
    .ptp-checkbox {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        cursor: pointer;
        font-size: 14px;
        line-height: 1.5;
    }
    
    .ptp-checkbox input[type="checkbox"] {
        width: 24px;
        height: 24px;
        margin-top: 2px;
        accent-color: var(--gold);
        cursor: pointer;
        flex-shrink: 0;
    }
    
    .ptp-contract-ip {
        font-size: 11px;
        color: var(--gray-400);
        margin-top: 12px;
    }
    
    /* ===========================================
       v133: STRIPE SECTION
       =========================================== */
    .ptp-stripe-status {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 20px;
        border-radius: var(--radius-md);
        flex-wrap: wrap;
    }
    .ptp-stripe-status.pending { background: #FEF3C7; }
    .ptp-stripe-status.complete { background: var(--green-light); }
    
    .ptp-stripe-status svg { flex-shrink: 0; }
    .ptp-stripe-status > div { flex: 1; min-width: 200px; }
    .ptp-stripe-status strong { display: block; font-size: 15px; }
    .ptp-stripe-status p { margin: 4px 0 0; font-size: 13px; color: var(--gray-600); }
    
    .ptp-stripe-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 14px 28px;
        background: var(--stripe);
        color: var(--white);
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border: none;
        border-radius: var(--radius-sm);
        min-height: var(--touch-min);
        transition: all 0.2s;
    }
    .ptp-stripe-btn:hover { background: #4F46E5; transform: translateY(-1px); }
    .ptp-stripe-btn svg { width: 20px; height: 20px; }
    
    /* ===========================================
       v133: STICKY SUBMIT FOOTER
       =========================================== */
    .ptp-submit-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: var(--white);
        padding: 16px;
        padding-bottom: calc(16px + var(--safe-bottom));
        box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
        z-index: 50;
    }
    
    .ptp-submit-footer-inner {
        max-width: 640px;
        margin: 0 auto;
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    .ptp-submit-btn {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 16px 24px;
        background: var(--gold);
        color: var(--black);
        font-family: 'Oswald', sans-serif;
        font-size: 16px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border: none;
        border-radius: var(--radius-sm);
        min-height: 56px;
        transition: all 0.2s;
    }
    .ptp-submit-btn:hover:not(:disabled) {
        background: var(--gold-hover);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(252, 185, 0, 0.4);
    }
    .ptp-submit-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .ptp-submit-btn svg { width: 20px; height: 20px; }
    
    .ptp-skip-link {
        padding: 12px 16px;
        color: var(--gray-500);
        font-size: 14px;
        font-weight: 500;
        white-space: nowrap;
        transition: color 0.2s;
    }
    .ptp-skip-link:hover { color: var(--black); }
    
    /* ===========================================
       v133: LOADING & STATES
       =========================================== */
    .ptp-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,0.2);
        border-top-color: currentColor;
        border-radius: 50%;
        animation: ptp-spin 0.8s linear infinite;
    }
    
    @keyframes ptp-spin {
        to { transform: rotate(360deg); }
    }
    
    /* Toast notification */
    .ptp-toast {
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%) translateY(20px);
        background: var(--black);
        color: var(--white);
        padding: 14px 24px;
        border-radius: var(--radius-md);
        font-size: 14px;
        font-weight: 500;
        opacity: 0;
        transition: all 0.3s;
        z-index: 200;
        pointer-events: none;
    }
    .ptp-toast.show {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
    .ptp-toast.success { background: var(--green); }
    .ptp-toast.error { background: var(--red); }
    
    /* ===========================================
       v133: RESPONSIVE ADJUSTMENTS
       =========================================== */
    @media (max-width: 380px) {
        .ptp-section-header { padding: 16px; }
        .ptp-section-body { padding: 16px; }
        .ptp-section-num { width: 28px; height: 28px; font-size: 13px; }
        .ptp-section-title h2 { font-size: 14px; }
        
        .ptp-avail-times { grid-template-columns: 1fr; gap: 8px; }
        .ptp-avail-times span { display: none; }
    }
    
    /* Landscape adjustments */
    @media (max-height: 500px) and (orientation: landscape) {
        .ptp-submit-footer {
            padding: 12px 16px;
        }
        .ptp-submit-btn { min-height: 48px; padding: 12px 20px; }
    }
    </style>
</head>
<body>

<!-- Header -->
<header class="ptp-header">
    <a href="<?php echo home_url('/'); ?>">
        <img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP" class="ptp-header-logo">
    </a>
    <a href="mailto:support@ptpsummercamps.com" class="ptp-header-help">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Help
    </a>
</header>

<main class="ptp-onboard">
    <!-- Welcome -->
    <div class="ptp-welcome">
        <div class="ptp-welcome-emoji">👋</div>
        <h1><?php echo $is_edit ? 'EDIT YOUR PROFILE' : "WELCOME, <span>$first_name</span>!"; ?></h1>
        <p><?php echo $is_edit ? 'Update your profile information below' : 'Complete your profile to start receiving bookings from families'; ?></p>
    </div>
    
    <!-- Progress -->
    <div class="ptp-progress">
        <div class="ptp-progress-header">
            <div class="ptp-progress-count"><span><?php echo $steps_done; ?></span> of <?php echo $steps_total; ?> steps</div>
            <div class="ptp-progress-percent"><?php echo round($percentage); ?>%</div>
        </div>
        <div class="ptp-progress-bar">
            <div class="ptp-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
        </div>
        <div class="ptp-progress-steps">
            <?php
            $step_labels = array(
                'photo' => 'Photo',
                'bio' => 'Bio',
                'experience' => 'Experience',
                'rate' => 'Pricing',
                'training_locations' => 'Locations',
                'availability' => 'Schedule',
                'contract' => 'Agreement',
                'stripe' => 'Payouts'
            );
            foreach ($step_labels as $key => $label):
                $is_done = !empty($completion[$key]) || ($key === 'rate' && !empty($completion['location']));
            ?>
            <div class="ptp-step <?php echo $is_done ? 'done' : ''; ?>">
                <span class="ptp-step-icon">
                    <?php if ($is_done): ?>
                    <svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php endif; ?>
                </span>
                <?php echo $label; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Form -->
    <form id="onboardingForm" method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('ptp_trainer_onboarding', 'ptp_nonce'); ?>
        <input type="hidden" name="action" value="ptp_save_trainer_onboarding_v60">
        <input type="hidden" name="trainer_id" value="<?php echo $trainer_id; ?>">
        
        <!-- SECTION 1: PHOTO -->
        <div class="ptp-section <?php echo $completion['photo'] ? 'complete' : ''; ?> open" data-section="photo">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">1</div>
                <div class="ptp-section-title">
                    <h2>Profile Photo</h2>
                    <p>First impressions matter</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-photo-upload">
                    <div class="ptp-photo-preview" id="photoPreview" onclick="document.getElementById('photoInput').click()">
                        <?php if (!empty($trainer->photo_url)): ?>
                            <img src="<?php echo esc_url($trainer->photo_url); ?>" alt="Profile">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        <?php endif; ?>
                        <div class="ptp-photo-overlay">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                <circle cx="12" cy="13" r="4"/>
                            </svg>
                        </div>
                    </div>
                    <div class="ptp-photo-actions">
                        <input type="file" name="photo" id="photoInput" accept="image/*" style="display:none">
                        <button type="button" class="ptp-photo-btn" onclick="document.getElementById('photoInput').click()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Upload Photo
                        </button>
                        <ul class="ptp-photo-tips">
                            <li>Square photos work best</li>
                            <li>Show your face clearly</li>
                            <li>Wear your training gear</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SECTION 2: ABOUT -->
        <div class="ptp-section <?php echo $completion['bio'] ? 'complete' : ''; ?>" data-section="bio">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">2</div>
                <div class="ptp-section-title">
                    <h2>About You</h2>
                    <p>Tell parents about yourself</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="ptp-input" value="<?php echo esc_attr($trainer->first_name); ?>" required>
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="ptp-input" value="<?php echo esc_attr($trainer->last_name); ?>" required>
                    </div>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Bio / About Me <span class="required">*</span></label>
                    <textarea name="bio" class="ptp-textarea" placeholder="Tell parents about yourself, your coaching style, and what makes you unique..." required><?php echo esc_textarea($trainer->bio ?? ''); ?></textarea>
                    <p class="ptp-hint">Min 50 characters. This appears on your public profile.</p>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Why Do You Coach?</label>
                    <textarea name="coaching_why" class="ptp-textarea" rows="3" placeholder="Share your story - what drives you to train young players?"><?php echo esc_textarea($trainer->coaching_why ?? ''); ?></textarea>
                    <p class="ptp-hint">Parents love hearing what motivates you!</p>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Training Philosophy</label>
                    <textarea name="training_philosophy" class="ptp-textarea" rows="3" placeholder="What makes your sessions different? Technical skills? Game IQ? Confidence?"><?php echo esc_textarea($trainer->training_philosophy ?? ''); ?></textarea>
                </div>
            </div>
        </div>
        
        <!-- SECTION 3: EXPERIENCE -->
        <div class="ptp-section <?php echo $completion['experience'] ? 'complete' : ''; ?>" data-section="experience">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">3</div>
                <div class="ptp-section-title">
                    <h2>Playing Experience</h2>
                    <p>Your soccer background</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-field">
                    <label class="ptp-label">Highest Level Played <span class="required">*</span></label>
                    <select name="playing_experience" class="ptp-select" required>
                        <option value="">Select your level</option>
                        <option value="pro" <?php selected($trainer->playing_experience ?? '', 'pro'); ?>>MLS / Professional</option>
                        <option value="college_d1" <?php selected($trainer->playing_experience ?? '', 'college_d1'); ?>>NCAA Division 1</option>
                        <option value="college_d2" <?php selected($trainer->playing_experience ?? '', 'college_d2'); ?>>NCAA Division 2/3</option>
                        <option value="academy" <?php selected($trainer->playing_experience ?? '', 'academy'); ?>>Academy / ECNL / MLS Next</option>
                        <option value="semi_pro" <?php selected($trainer->playing_experience ?? '', 'semi_pro'); ?>>Semi-Professional / USL</option>
                    </select>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Teams / Clubs Played For</label>
                    <input type="text" name="teams_played" class="ptp-input" value="<?php echo esc_attr($trainer->teams_played ?? ''); ?>" placeholder="e.g., Philadelphia Union, Villanova University">
                </div>
                
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">Years Playing</label>
                        <input type="number" name="years_playing" class="ptp-input" value="<?php echo esc_attr($trainer->years_playing ?? ''); ?>" placeholder="e.g., 15">
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">Years Coaching</label>
                        <input type="number" name="years_coaching" class="ptp-input" value="<?php echo esc_attr($trainer->years_coaching ?? ''); ?>" placeholder="e.g., 5">
                    </div>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Certifications</label>
                    <input type="text" name="certifications" class="ptp-input" value="<?php echo esc_attr($trainer->certifications ?? ''); ?>" placeholder="e.g., USSF D License, CPR Certified">
                </div>
            </div>
        </div>
        
        <!-- SECTION 4: PRICING -->
        <div class="ptp-section <?php echo ($completion['rate'] && $completion['location']) ? 'complete' : ''; ?>" data-section="pricing">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">4</div>
                <div class="ptp-section-title">
                    <h2>Pricing & Location</h2>
                    <p>Set your rate and area</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">Hourly Rate ($) <span class="required">*</span></label>
                        <input type="number" name="hourly_rate" class="ptp-input" value="<?php echo esc_attr($trainer->hourly_rate ?? '75'); ?>" min="25" max="300" required>
                        <p class="ptp-hint">Most trainers charge $50-100/hour</p>
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">Travel Radius (miles)</label>
                        <input type="number" name="travel_radius" class="ptp-input" value="<?php echo esc_attr($trainer->travel_radius ?? '15'); ?>" min="1" max="50">
                    </div>
                </div>
                
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">City <span class="required">*</span></label>
                        <input type="text" name="city" class="ptp-input" value="<?php echo esc_attr($trainer->city ?? ''); ?>" required>
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">State <span class="required">*</span></label>
                        <select name="state" class="ptp-select" required>
                            <option value="">Select</option>
                            <option value="PA" <?php selected($trainer->state ?? '', 'PA'); ?>>Pennsylvania</option>
                            <option value="NJ" <?php selected($trainer->state ?? '', 'NJ'); ?>>New Jersey</option>
                            <option value="DE" <?php selected($trainer->state ?? '', 'DE'); ?>>Delaware</option>
                            <option value="MD" <?php selected($trainer->state ?? '', 'MD'); ?>>Maryland</option>
                            <option value="NY" <?php selected($trainer->state ?? '', 'NY'); ?>>New York</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SECTION 5: TRAINING LOCATIONS -->
        <div class="ptp-section <?php echo $completion['training_locations'] ? 'complete' : ''; ?>" data-section="locations">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">5</div>
                <div class="ptp-section-title">
                    <h2>Training Locations</h2>
                    <p>Where you can train</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <p class="ptp-hint" style="margin-bottom:16px;">Add parks, fields, or facilities where you can train. Parents will see these on your profile.</p>
                
                <div class="ptp-locations-list" id="locationsList">
                    <?php foreach ($training_locations as $loc): ?>
                    <div class="ptp-location-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        <span><?php echo esc_html($loc); ?></span>
                        <input type="hidden" name="training_locations[]" value="<?php echo esc_attr($loc); ?>">
                        <button type="button" class="ptp-location-remove" onclick="removeLocation(this)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="ptp-location-add">
                    <input type="text" id="locationInput" class="ptp-input" placeholder="Type an address or place name...">
                    <button type="button" class="ptp-location-btn" onclick="addLocation()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add
                    </button>
                </div>
                
                <?php if ($google_maps_key): ?>
                <div class="ptp-map-container" id="locationsMap"></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- SECTION 6: AVAILABILITY -->
        <div class="ptp-section <?php echo $completion['availability'] ? 'complete' : ''; ?>" data-section="availability">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">6</div>
                <div class="ptp-section-title">
                    <h2>Weekly Availability</h2>
                    <p>Set your typical schedule</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <p class="ptp-hint" style="margin-bottom:16px;">Toggle days on/off and set your available hours. You can adjust specific dates later.</p>
                
                <div class="ptp-availability">
                    <?php 
                    $days = array('monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun');
                    $day_full = array('monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday');
                    foreach ($days as $key => $label):
                        $day_data = $availability[$key] ?? array();
                        $enabled = !empty($day_data['enabled']);
                        $start = $day_data['start'] ?? '16:00';
                        $end = $day_data['end'] ?? '20:00';
                    ?>
                    <div class="ptp-avail-day <?php echo $enabled ? 'active' : ''; ?>">
                        <div class="ptp-avail-header">
                            <span class="ptp-avail-name"><?php echo $day_full[$key]; ?></span>
                            <div class="ptp-toggle <?php echo $enabled ? 'active' : ''; ?>" data-day="<?php echo $key; ?>" onclick="toggleDay(this)"></div>
                            <input type="hidden" name="availability[<?php echo $key; ?>][enabled]" value="<?php echo $enabled ? '1' : '0'; ?>">
                        </div>
                        <div class="ptp-avail-times">
                            <input type="time" name="availability[<?php echo $key; ?>][start]" class="ptp-time-input" value="<?php echo esc_attr($start); ?>">
                            <span>to</span>
                            <input type="time" name="availability[<?php echo $key; ?>][end]" class="ptp-time-input" value="<?php echo esc_attr($end); ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- SECTION 7: CONTRACT -->
        <div class="ptp-section <?php echo $completion['contract'] ? 'complete' : ''; ?>" data-section="contract">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">7</div>
                <div class="ptp-section-title">
                    <h2>Trainer Agreement</h2>
                    <p>Required to join PTP</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <?php if ($trainer->contractor_agreement_signed): ?>
                <div class="ptp-contract-status">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <div>
                        <strong>Agreement Signed</strong>
                        <p>Signed on <?php echo date('F j, Y', strtotime($trainer->contractor_agreement_signed_at)); ?></p>
                    </div>
                </div>
                <?php else: ?>
                <div class="ptp-contract-box">
                    <div class="ptp-contract-scroll" id="contractContent">
                        <h3>Independent Contractor Agreement</h3>
                        <p style="font-size:12px;color:#666;">Between PTP - Players Teaching Players, LLC ("PTP") and You ("Trainer")</p>
                        
                        <h4>1. RELATIONSHIP</h4>
                        <p>Trainer agrees to provide private soccer training services as an <strong>independent contractor</strong>, not as an employee of PTP.</p>
                        
                        <h4>2. PLATFORM SERVICES</h4>
                        <p>PTP provides: online platform, booking system, payment processing, marketing support, and insurance coverage during sessions.</p>
                        
                        <h4>3. TRAINER RESPONSIBILITIES</h4>
                        <ul>
                            <li>Provide professional, safe, age-appropriate training</li>
                            <li>Arrive on time and prepared for all sessions</li>
                            <li>Communicate professionally with families</li>
                            <li>Cancel with 24+ hours notice except emergencies</li>
                            <li>Never solicit clients for off-platform bookings</li>
                        </ul>
                        
                        <h4>4. COMPENSATION</h4>
                        <p><strong>First session with new client:</strong> You receive 50%<br>
                        <strong>Repeat sessions:</strong> You receive 75%</p>
                        <p>Payouts processed weekly via Stripe Connect.</p>
                        
                        <h4>5. CANCELLATION POLICY</h4>
                        <p>Trainer must provide 24+ hours notice. Repeated last-minute cancellations may result in suspension.</p>
                        
                        <h4>6. CONDUCT & SAFETY</h4>
                        <ul>
                            <li>Never use inappropriate language with minors</li>
                            <li>Train in open, visible areas only</li>
                            <li>Report safety concerns immediately</li>
                        </ul>
                        
                        <h4>7. NON-SOLICITATION</h4>
                        <p>For 12 months after your last session, you agree not to solicit PTP clients outside the platform.</p>
                        
                        <p style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;"><strong>By checking below, you agree to be bound by this Independent Contractor Agreement.</strong></p>
                    </div>
                    
                    <div class="ptp-contract-signature">
                        <label class="ptp-checkbox">
                            <input type="checkbox" name="agree_contract" id="agreeContract" value="1" required>
                            <span>I, <strong><?php echo esc_html($trainer->display_name); ?></strong>, agree to the PTP Trainer Agreement.</span>
                        </label>
                        <p class="ptp-contract-ip">IP: <?php echo esc_html($_SERVER['REMOTE_ADDR']); ?> • Timestamp recorded on submission</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- SECTION 8: STRIPE -->
        <div class="ptp-section <?php echo $completion['stripe'] ? 'complete' : ''; ?>" data-section="stripe">
            <div class="ptp-section-header" onclick="toggleSection(this)">
                <div class="ptp-section-num">8</div>
                <div class="ptp-section-title">
                    <h2>Get Paid</h2>
                    <p>Connect your bank account</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <?php if ($stripe_complete): ?>
                <div class="ptp-stripe-status complete">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <div>
                        <strong>Stripe Connected!</strong>
                        <p>You're all set to receive payouts.</p>
                    </div>
                </div>
                <?php elseif ($has_stripe): ?>
                <div class="ptp-stripe-status pending">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <div>
                        <strong>Almost There!</strong>
                        <p>Complete your Stripe account setup.</p>
                    </div>
                    <button type="button" class="ptp-stripe-btn" onclick="connectStripe(event)">Complete Setup</button>
                </div>
                <?php else: ?>
                <p class="ptp-hint" style="margin-bottom:16px;">Connect your bank through Stripe to receive payouts. Stripe is secure and used by millions.</p>
                <button type="button" id="stripeConnectBtn" class="ptp-stripe-btn" onclick="connectStripe(event)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                    Connect with Stripe
                </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>

<!-- Sticky Submit Footer -->
<div class="ptp-submit-footer">
    <div class="ptp-submit-footer-inner">
        <?php if (!$is_edit): ?>
        <a href="<?php echo home_url('/trainer-dashboard/?skip_onboarding=1'); ?>" class="ptp-skip-link">Skip</a>
        <?php endif; ?>
        <button type="submit" form="onboardingForm" class="ptp-submit-btn" id="submitBtn">
            <?php echo $is_edit ? 'Save Changes' : 'Complete Profile'; ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="5" y1="12" x2="19" y2="12"/>
                <polyline points="12 5 19 12 12 19"/>
            </svg>
        </button>
    </div>
</div>

<!-- Toast -->
<div class="ptp-toast" id="toast"></div>

<script>
// Section toggle
function toggleSection(header) {
    const section = header.closest('.ptp-section');
    const wasOpen = section.classList.contains('open');
    
    // Close all sections
    document.querySelectorAll('.ptp-section.open').forEach(s => {
        if (s !== section) s.classList.remove('open');
    });
    
    // Toggle clicked section
    section.classList.toggle('open', !wasOpen);
    
    // Scroll into view
    if (!wasOpen) {
        setTimeout(() => {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }
}

// Day toggle
function toggleDay(el) {
    el.classList.toggle('active');
    const day = el.closest('.ptp-avail-day');
    day.classList.toggle('active', el.classList.contains('active'));
    const input = el.nextElementSibling;
    input.value = el.classList.contains('active') ? '1' : '0';
}

// Photo preview
document.getElementById('photoInput').addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('photoPreview').innerHTML = 
                '<img src="' + ev.target.result + '" alt="Preview">' +
                '<div class="ptp-photo-overlay"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>';
            showToast('Photo selected!', 'success');
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

// Toast
function showToast(message, type = '') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'ptp-toast show ' + type;
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// Training locations
function addLocation() {
    const input = document.getElementById('locationInput');
    const address = input.value.trim();
    if (!address) {
        showToast('Please enter a location', 'error');
        return;
    }
    
    const list = document.getElementById('locationsList');
    
    // Check duplicates
    const existing = list.querySelectorAll('input[name="training_locations[]"]');
    for (let i = 0; i < existing.length; i++) {
        if (existing[i].value.toLowerCase() === address.toLowerCase()) {
            showToast('Location already added', 'error');
            return;
        }
    }
    
    const item = document.createElement('div');
    item.className = 'ptp-location-item';
    item.innerHTML = `
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
            <circle cx="12" cy="10" r="3"/>
        </svg>
        <span>${escapeHtml(address)}</span>
        <input type="hidden" name="training_locations[]" value="${escapeHtml(address)}">
        <button type="button" class="ptp-location-remove" onclick="removeLocation(this)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    `;
    list.appendChild(item);
    input.value = '';
    showToast('Location added!', 'success');
}

function removeLocation(btn) {
    btn.closest('.ptp-location-item').remove();
    showToast('Location removed');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Enter key for location input
document.getElementById('locationInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        addLocation();
    }
});

// Stripe Connect
function connectStripe(e) {
    e.preventDefault();
    const btn = document.getElementById('stripeConnectBtn') || e.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="ptp-loading"></span> Connecting...';
    btn.style.pointerEvents = 'none';
    
    const formData = new FormData();
    formData.append('action', 'ptp_create_stripe_connect_account');
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.data.url) {
            window.location.href = data.data.url;
        } else {
            showToast(data.data.message || 'Error connecting to Stripe', 'error');
            btn.innerHTML = originalText;
            btn.style.pointerEvents = 'auto';
        }
    })
    .catch(() => {
        showToast('Connection error. Please try again.', 'error');
        btn.innerHTML = originalText;
        btn.style.pointerEvents = 'auto';
    });
}

// Form submit
document.getElementById('onboardingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate contract
    const contractCheckbox = document.getElementById('agreeContract');
    if (contractCheckbox && !contractCheckbox.checked) {
        showToast('Please agree to the Trainer Agreement', 'error');
        document.querySelector('[data-section="contract"]').classList.add('open');
        contractCheckbox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    const btn = document.getElementById('submitBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="ptp-loading"></span> Saving...';
    
    const formData = new FormData(this);
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Profile saved!', 'success');
            setTimeout(() => {
                window.location.href = '<?php echo home_url('/trainer-dashboard/?welcome=1'); ?>';
            }, 500);
        } else {
            showToast(data.data.message || 'Error saving profile', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(() => {
        showToast('Connection error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
});

// Auto-open first incomplete section
document.addEventListener('DOMContentLoaded', function() {
    const incomplete = document.querySelector('.ptp-section:not(.complete)');
    if (incomplete && !incomplete.classList.contains('open')) {
        // First section is already open, so find next incomplete
        const firstOpen = document.querySelector('.ptp-section.open');
        if (firstOpen && firstOpen.classList.contains('complete') && incomplete) {
            firstOpen.classList.remove('open');
            incomplete.classList.add('open');
        }
    }
});

// Google Maps (if key exists)
<?php if ($google_maps_key): ?>
var map = null;
var markers = [];

function initLocationsMap() {
    if (typeof google === 'undefined' || !google.maps) return;
    
    const mapElement = document.getElementById('locationsMap');
    if (!mapElement) return;
    
    map = new google.maps.Map(mapElement, {
        center: { lat: 39.95, lng: -75.16 },
        zoom: 10,
        styles: [
            { featureType: 'poi', stylers: [{ visibility: 'off' }] }
        ],
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false
    });
    
    // Init autocomplete
    const input = document.getElementById('locationInput');
    const autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['establishment', 'geocode'],
        componentRestrictions: { country: 'us' }
    });
    
    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();
        if (place.formatted_address || place.name) {
            const address = place.formatted_address || place.name;
            input.value = address;
        }
    });
    
    // Add existing location markers
    <?php foreach ($training_locations as $loc): ?>
    geocodeAndAddMarker('<?php echo esc_js($loc); ?>');
    <?php endforeach; ?>
}

function geocodeAndAddMarker(address) {
    if (!map) return;
    const geocoder = new google.maps.Geocoder();
    geocoder.geocode({ address: address + ', USA' }, function(results, status) {
        if (status === 'OK' && results[0]) {
            new google.maps.Marker({
                position: results[0].geometry.location,
                map: map,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 10,
                    fillColor: '#FCB900',
                    fillOpacity: 1,
                    strokeColor: '#0A0A0A',
                    strokeWeight: 2
                }
            });
        }
    });
}

if (typeof google !== 'undefined' && google.maps) {
    initLocationsMap();
} else {
    window.addEventListener('load', () => setTimeout(initLocationsMap, 500));
}
<?php endif; ?>
</script>

</body>
</html>
