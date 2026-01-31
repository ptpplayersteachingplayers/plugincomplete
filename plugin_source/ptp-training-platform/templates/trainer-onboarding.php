<?php
/**
 * Trainer Onboarding Template v119
 * 
 * Complete profile setup for new trainers:
 * - Profile photo
 * - Bio & playing experience
 * - Service areas & pricing
 * - Training locations with Google Maps
 * - Weekly availability
 * - Stripe Connect for payouts
 * 
 * Cookie: ptp_trainer_onboarding_{trainer_id} - stores form data
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

// Get existing availability data and convert to template format
$availability = array();
if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'get_weekly')) {
    $raw_availability = PTP_Availability::get_weekly($trainer_id);
    
    // Map day numbers to day names (0=Sunday, 1=Monday, etc.)
    $day_names = array(
        0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
        4 => 'thursday', 5 => 'friday', 6 => 'saturday'
    );
    
    // Convert database format to template format
    if (!empty($raw_availability)) {
        foreach ($raw_availability as $slot) {
            $day_num = intval($slot->day_of_week);
            if (isset($day_names[$day_num])) {
                $day_name = $day_names[$day_num];
                $availability[$day_name] = array(
                    'enabled' => !empty($slot->is_active),
                    'start' => substr($slot->start_time, 0, 5), // HH:MM format
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

// Calculate completion (added locations)
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

// Google Maps API key
$google_maps_key = get_option('ptp_google_maps_key', '');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit Profile' : 'Complete Your Profile'; ?> - PTP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <?php if ($google_maps_key): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&libraries=places&loading=async" async defer></script>
    <?php endif; ?>
    <style>
    :root {
        --gold: #FCB900;
        --black: #0A0A0A;
        --gray: #F5F5F5;
        --gray-dark: #525252;
        --green: #22C55E;
        --red: #EF4444;
        --radius: 12px;
    }
    * { box-sizing: border-box; }
    /* v133.2: Hide scrollbar */
    html, body { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }
    body { margin: 0; font-family: Inter, -apple-system, sans-serif; background: var(--gray); }
    h1, h2, h3 { font-family: Oswald, sans-serif; font-weight: 700; text-transform: uppercase; margin: 0; }
    
    /* Simple header bar */
    .ptp-simple-header { background: var(--black); padding: 16px 20px; text-align: center; }
    .ptp-simple-header img { height: 36px; }
    
    .ptp-onboard { max-width: 800px; margin: 0 auto; padding: 40px 20px 80px; }
    
    /* Header */
    .ptp-onboard-header { text-align: center; margin-bottom: 32px; }
    .ptp-onboard-header h1 { font-size: 28px; margin-bottom: 8px; color: var(--black); }
    .ptp-onboard-header p { color: var(--gray-dark); margin: 0; }
    
    /* Progress */
    .ptp-progress { background: #fff; border-radius: var(--radius); padding: 20px; margin-bottom: 24px; }
    .ptp-progress-bar { height: 8px; background: #E5E5E5; border-radius: 4px; overflow: hidden; margin-bottom: 12px; }
    .ptp-progress-fill { height: 100%; background: var(--gold); transition: width 0.3s; }
    .ptp-progress-text { font-size: 14px; color: var(--gray-dark); text-align: center; }
    .ptp-progress-steps { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 12px; }
    .ptp-progress-step { font-size: 12px; padding: 4px 10px; border-radius: 20px; background: #E5E5E5; }
    .ptp-progress-step.done { background: var(--green); color: #fff; }
    
    /* Sections */
    .ptp-onboard-section { background: #fff; border-radius: var(--radius); padding: 24px; margin-bottom: 16px; }
    .ptp-onboard-section h2 { font-size: 16px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .ptp-onboard-section h2 .step-num { 
        width: 28px; height: 28px; background: var(--black); color: #fff; 
        border-radius: 50%; display: flex; align-items: center; justify-content: center; 
        font-size: 14px; 
    }
    .ptp-onboard-section h2 .step-num.done { background: var(--green); }
    
    /* Training Locations */
    .ptp-locations-container { display: flex; flex-direction: column; gap: 16px; }
    .ptp-locations-list { display: flex; flex-direction: column; gap: 8px; }
    .ptp-location-item { display: flex; align-items: center; gap: 12px; background: var(--gray); padding: 12px 16px; border-radius: 8px; }
    .ptp-location-item span { flex: 1; font-size: 14px; }
    .ptp-location-remove { background: none; border: none; cursor: pointer; color: var(--red); padding: 4px; }
    .ptp-location-add { display: flex; gap: 8px; }
    .ptp-location-input { flex: 1; }
    .ptp-location-btn { padding: 14px 20px; background: var(--gold); color: var(--black); border: none; font-family: Oswald, sans-serif; font-size: 13px; font-weight: 600; text-transform: uppercase; border-radius: 8px; cursor: pointer; white-space: nowrap; }
    .ptp-location-btn:hover { background: #E5A800; }
    .ptp-map-container { height: 250px; border-radius: 8px; overflow: hidden; background: #E5E5E5; margin-top: 16px; }
    .ptp-map-hint { font-size: 12px; color: var(--gray-dark); margin-top: 8px; }
    
    /* Form Elements */
    .ptp-form-row { margin-bottom: 16px; }
    .ptp-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .ptp-label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-dark); margin-bottom: 6px; }
    .ptp-input, .ptp-select, .ptp-textarea { 
        width: 100%; padding: 14px; font-size: 15px; border: 2px solid #E5E5E5; 
        border-radius: 8px; font-family: inherit; transition: border-color 0.2s; 
    }
    .ptp-input:focus, .ptp-select:focus, .ptp-textarea:focus { outline: none; border-color: var(--gold); }
    .ptp-textarea { min-height: 120px; resize: vertical; }
    .ptp-select { appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23525252' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 14px center; }
    
    /* Photo Upload */
    .ptp-photo-upload { display: flex; align-items: center; gap: 20px; }
    .ptp-photo-preview { 
        width: 100px; height: 100px; border-radius: 50%; background: #E5E5E5; 
        display: flex; align-items: center; justify-content: center; overflow: hidden;
        border: 3px solid var(--gold);
    }
    .ptp-photo-preview img { width: 100%; height: 100%; object-fit: cover; }
    .ptp-photo-preview svg { width: 40px; height: 40px; stroke: var(--gray-dark); }
    .ptp-photo-btn { 
        padding: 12px 24px; background: var(--black); color: #fff; border: none; 
        font-family: Oswald, sans-serif; font-size: 13px; font-weight: 600; 
        text-transform: uppercase; border-radius: 8px; cursor: pointer; 
    }
    .ptp-photo-btn:hover { background: #333; }
    
    /* Availability Grid */
    .ptp-availability { display: grid; gap: 8px; }
    .ptp-availability-day { 
        display: grid; grid-template-columns: 100px 1fr 1fr auto; gap: 12px; 
        align-items: center; padding: 12px; background: var(--gray); border-radius: 8px; 
    }
    .ptp-day-name { font-weight: 600; font-size: 14px; }
    .ptp-time-input { padding: 10px; font-size: 14px; }
    .ptp-day-toggle { width: 44px; height: 24px; background: #ccc; border-radius: 12px; cursor: pointer; position: relative; transition: background 0.2s; }
    .ptp-day-toggle.active { background: var(--green); }
    .ptp-day-toggle::after { content: ''; position: absolute; width: 20px; height: 20px; background: #fff; border-radius: 50%; top: 2px; left: 2px; transition: left 0.2s; }
    .ptp-day-toggle.active::after { left: 22px; }
    
    /* Stripe Section */
    .ptp-stripe-status { display: flex; align-items: center; gap: 12px; padding: 16px; border-radius: 8px; }
    .ptp-stripe-status.pending { background: #FEF3C7; }
    .ptp-stripe-status.complete { background: #D1FAE5; }
    .ptp-stripe-btn { 
        display: inline-block; padding: 14px 28px; background: #635BFF; color: #fff; 
        text-decoration: none; font-family: Oswald, sans-serif; font-size: 14px; 
        font-weight: 600; text-transform: uppercase; border-radius: 8px; 
    }
    .ptp-stripe-btn:hover { background: #4F46E5; }
    
    /* Submit */
    .ptp-submit-section { text-align: center; margin-top: 32px; }
    .ptp-submit { 
        padding: 18px 48px; background: var(--gold); color: var(--black); border: none; 
        font-family: Oswald, sans-serif; font-size: 16px; font-weight: 700; 
        text-transform: uppercase; border-radius: 10px; cursor: pointer; transition: all 0.2s; 
    }
    .ptp-submit:hover { background: #E5A800; transform: translateY(-2px); }
    .ptp-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .ptp-skip { display: block; margin-top: 16px; color: var(--gray-dark); font-size: 14px; text-decoration: none; }
    
    /* Responsive */
    @media (max-width: 600px) {
        .ptp-form-grid { grid-template-columns: 1fr; }
        .ptp-availability-day { grid-template-columns: 1fr; gap: 8px; }
        .ptp-photo-upload { flex-direction: column; text-align: center; }
        .ptp-location-add { flex-direction: column; }
        .ptp-location-btn { width: 100%; }
    }
    
    /* Contract Section */
    .ptp-contract-status { display: flex; align-items: center; gap: 12px; padding: 16px; border-radius: 8px; }
    .ptp-contract-status.signed { background: #D1FAE5; }
    .ptp-contract-box { border: 2px solid #E5E5E5; border-radius: 8px; overflow: hidden; }
    .ptp-contract-scroll { 
        max-height: 400px; overflow-y: auto; padding: 24px; font-size: 14px; line-height: 1.6;
        background: #FAFAFA;
    }
    .ptp-contract-scroll h3 { color: var(--black); margin-bottom: 16px; }
    .ptp-contract-scroll h4 { font-family: Oswald, sans-serif; font-size: 14px; margin: 20px 0 8px; color: var(--black); text-transform: uppercase; }
    .ptp-contract-scroll p { margin: 0 0 12px; color: #374151; }
    .ptp-contract-scroll ul { margin: 0 0 12px; padding-left: 20px; color: #374151; }
    .ptp-contract-scroll li { margin-bottom: 6px; }
    .ptp-contract-signature { 
        padding: 20px 24px; background: #fff; border-top: 2px solid #E5E5E5; 
    }
    .ptp-contract-checkbox { 
        display: flex; align-items: flex-start; gap: 12px; cursor: pointer; font-size: 14px;
    }
    .ptp-contract-checkbox input[type="checkbox"] { 
        width: 20px; height: 20px; margin-top: 2px; accent-color: var(--gold); cursor: pointer;
    }
    .ptp-contract-checkbox span { flex: 1; line-height: 1.5; }
    .ptp-contract-ip { font-size: 11px; color: #9CA3AF; margin: 12px 0 0; }
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

<!-- Simple PTP Header -->
<div class="ptp-simple-header">
    <a href="<?php echo home_url('/'); ?>">
        <img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP">
    </a>
</div>

<div class="ptp-onboard">
    <div class="ptp-onboard-header">
        <h1><?php echo $is_edit ? 'EDIT YOUR PROFILE' : 'COMPLETE YOUR PROFILE'; ?></h1>
        <p>Fill out your profile to start receiving bookings</p>
    </div>
    
    <div class="ptp-progress">
        <div class="ptp-progress-bar">
            <div class="ptp-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
        </div>
        <div class="ptp-progress-text"><?php echo round($percentage); ?>% Complete</div>
        <div class="ptp-progress-steps">
            <span class="ptp-progress-step <?php echo $completion['photo'] ? 'done' : ''; ?>">Photo</span>
            <span class="ptp-progress-step <?php echo $completion['bio'] ? 'done' : ''; ?>">Bio</span>
            <span class="ptp-progress-step <?php echo $completion['experience'] ? 'done' : ''; ?>">Experience</span>
            <span class="ptp-progress-step <?php echo $completion['rate'] && $completion['location'] ? 'done' : ''; ?>">Pricing</span>
            <span class="ptp-progress-step <?php echo $completion['training_locations'] ? 'done' : ''; ?>">Locations</span>
            <span class="ptp-progress-step <?php echo $completion['availability'] ? 'done' : ''; ?>">Availability</span>
            <span class="ptp-progress-step <?php echo $completion['contract'] ? 'done' : ''; ?>">Agreement</span>
            <span class="ptp-progress-step <?php echo $completion['stripe'] ? 'done' : ''; ?>">Payouts</span>
        </div>
    </div>
    
    <form id="onboardingForm" method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('ptp_trainer_onboarding', 'ptp_nonce'); ?>
        <input type="hidden" name="action" value="ptp_save_trainer_onboarding_v60">
        <input type="hidden" name="trainer_id" value="<?php echo $trainer_id; ?>">
        
        <!-- STEP 1: PHOTO -->
        <div class="ptp-onboard-section">
            <h2><span class="step-num <?php echo $completion['photo'] ? 'done' : ''; ?>">1</span> Profile Photo</h2>
            <div class="ptp-photo-upload">
                <div class="ptp-photo-preview" id="photoPreview">
                    <?php if (!empty($trainer->photo_url)): ?>
                        <img src="<?php echo esc_url($trainer->photo_url); ?>" alt="Profile">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div>
                    <input type="file" name="photo" id="photoInput" accept="image/*" style="display:none">
                    <button type="button" class="ptp-photo-btn" onclick="document.getElementById('photoInput').click()">
                        Upload Photo
                    </button>
                    <p style="font-size:12px;color:#666;margin:8px 0 0;">Square photos work best</p>
                </div>
            </div>
        </div>
        
        <!-- STEP 2: BASIC INFO -->
        <div class="ptp-onboard-section">
            <h2><span class="step-num <?php echo $completion['bio'] ? 'done' : ''; ?>">2</span> About You</h2>
            <div class="ptp-form-row ptp-form-grid">
                <div>
                    <label class="ptp-label">First Name *</label>
                    <input type="text" name="first_name" class="ptp-input" value="<?php echo esc_attr($trainer->first_name); ?>" required>
                </div>
                <div>
                    <label class="ptp-label">Last Name *</label>
                    <input type="text" name="last_name" class="ptp-input" value="<?php echo esc_attr($trainer->last_name); ?>" required>
                </div>
            </div>
            <div class="ptp-form-row">
                <label class="ptp-label">Bio / About Me *</label>
                <textarea name="bio" class="ptp-textarea" placeholder="Tell parents about yourself, your coaching style, and what makes you unique..." required><?php echo esc_textarea($trainer->bio ?? ''); ?></textarea>
                <p style="font-size:12px;color:#666;margin:4px 0 0;">Min 50 characters. This appears on your public profile.</p>
            </div>
            <div class="ptp-form-row">
                <label class="ptp-label">Why Do You Coach? (Optional)</label>
                <textarea name="coaching_why" class="ptp-textarea" rows="3" placeholder="Share your story - what drives you to train young players? An injury that changed your path? A love of teaching? Wanting to give back?"><?php echo esc_textarea($trainer->coaching_why ?? ''); ?></textarea>
                <p style="font-size:12px;color:#666;margin:4px 0 0;">Parents love hearing what motivates you. Share your journey!</p>
            </div>
            <div class="ptp-form-row">
                <label class="ptp-label">Your Training Philosophy (Optional)</label>
                <textarea name="training_philosophy" class="ptp-textarea" rows="3" placeholder="What makes your sessions different? What do you focus on? Technical skills? Game IQ? Confidence building?"><?php echo esc_textarea($trainer->training_philosophy ?? ''); ?></textarea>
                <p style="font-size:12px;color:#666;margin:4px 0 0;">Help parents understand what to expect from training with you.</p>
            </div>
        </div>
        
        <!-- STEP 3: EXPERIENCE -->
        <div class="ptp-onboard-section">
            <h2><span class="step-num <?php echo $completion['experience'] ? 'done' : ''; ?>">3</span> Playing Experience</h2>
            <div class="ptp-form-row">
                <label class="ptp-label">Highest Level Played *</label>
                <select name="playing_experience" class="ptp-select" required>
                    <option value="">Select your level</option>
                    <option value="pro" <?php selected($trainer->playing_experience ?? '', 'pro'); ?>>MLS / Professional</option>
                    <option value="college_d1" <?php selected($trainer->playing_experience ?? '', 'college_d1'); ?>>NCAA Division 1</option>
                    <option value="college_d2" <?php selected($trainer->playing_experience ?? '', 'college_d2'); ?>>NCAA Division 2/3</option>
                    <option value="academy" <?php selected($trainer->playing_experience ?? '', 'academy'); ?>>Academy / ECNL / MLS Next</option>
                    <option value="semi_pro" <?php selected($trainer->playing_experience ?? '', 'semi_pro'); ?>>Semi-Professional / USL</option>
                </select>
            </div>
            <div class="ptp-form-row">
                <label class="ptp-label">Teams / Clubs Played For</label>
                <input type="text" name="teams_played" class="ptp-input" value="<?php echo esc_attr($trainer->teams_played ?? ''); ?>" placeholder="e.g., Philadelphia Union, Villanova University">
            </div>
            <div class="ptp-form-row ptp-form-grid">
                <div>
                    <label class="ptp-label">Years Playing</label>
                    <input type="number" name="years_playing" class="ptp-input" value="<?php echo esc_attr($trainer->years_playing ?? ''); ?>" placeholder="e.g., 15">
                </div>
                <div>
                    <label class="ptp-label">Years Coaching</label>
                    <input type="number" name="years_coaching" class="ptp-input" value="<?php echo esc_attr($trainer->years_coaching ?? ''); ?>" placeholder="e.g., 5">
                </div>
            </div>
            <div class="ptp-form-row">
                <label class="ptp-label">Certifications (Optional)</label>
                <input type="text" name="certifications" class="ptp-input" value="<?php echo esc_attr($trainer->certifications ?? ''); ?>" placeholder="e.g., USSF D License, CPR Certified">
            </div>
        </div>
        
        <!-- STEP 4: PRICING & LOCATION -->
        <div class="ptp-onboard-section" id="pricing">
            <h2><span class="step-num <?php echo $completion['rate'] && $completion['location'] ? 'done' : ''; ?>">4</span> Pricing & Location</h2>
            <div class="ptp-form-row ptp-form-grid">
                <div>
                    <label class="ptp-label">Hourly Rate ($) *</label>
                    <input type="number" name="hourly_rate" class="ptp-input" value="<?php echo esc_attr($trainer->hourly_rate ?? '75'); ?>" min="25" max="300" required>
                    <p style="font-size:12px;color:#666;margin:4px 0 0;">Most trainers charge $50-100/hour</p>
                </div>
                <div>
                    <label class="ptp-label">Travel Radius (miles)</label>
                    <input type="number" name="travel_radius" class="ptp-input" value="<?php echo esc_attr($trainer->travel_radius ?? '15'); ?>" min="1" max="50">
                </div>
            </div>
            <div class="ptp-form-row ptp-form-grid">
                <div>
                    <label class="ptp-label">City *</label>
                    <input type="text" name="city" class="ptp-input" value="<?php echo esc_attr($trainer->city ?? ''); ?>" required>
                </div>
                <div>
                    <label class="ptp-label">State *</label>
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
        
        <!-- STEP 5: TRAINING LOCATIONS (with Google Maps) -->
        <div class="ptp-onboard-section" id="training-locations">
            <h2><span class="step-num <?php echo $completion['training_locations'] ? 'done' : ''; ?>">5</span> Training Locations</h2>
            <p style="color:#666;font-size:14px;margin-bottom:16px;">Add the locations where you can train (parks, fields, facilities). Parents will see these on your profile.</p>
            
            <div class="ptp-locations-container">
                <div class="ptp-locations-list" id="locationsList">
                    <?php foreach ($training_locations as $loc): ?>
                    <div class="ptp-location-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2">
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
                    <input type="text" id="locationInput" class="ptp-input ptp-location-input" placeholder="Type an address or place name...">
                    <button type="button" class="ptp-location-btn" onclick="addLocation()">+ Add Location</button>
                </div>
                
                <?php if ($google_maps_key): ?>
                <div class="ptp-map-container" id="locationsMap"></div>
                <p class="ptp-map-hint">💡 Click on the map to add a location, or type an address above</p>
                <?php else: ?>
                <p class="ptp-map-hint">💡 Tip: Be specific with addresses so parents can easily find you (e.g., "Veterans Memorial Park, Chester, PA")</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- STEP 6: AVAILABILITY -->
        <div class="ptp-onboard-section" id="availability">
            <h2><span class="step-num <?php echo $completion['availability'] ? 'done' : ''; ?>">6</span> Weekly Availability</h2>
            <p style="color:#666;font-size:14px;margin-bottom:16px;">Set your typical weekly schedule. You can adjust specific dates later.</p>
            
            <div class="ptp-availability">
                <?php 
                $days = array('monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday');
                foreach ($days as $key => $label):
                    $day_data = $availability[$key] ?? array();
                    $enabled = !empty($day_data['enabled']);
                    $start = $day_data['start'] ?? '09:00';
                    $end = $day_data['end'] ?? '17:00';
                ?>
                <div class="ptp-availability-day">
                    <span class="ptp-day-name"><?php echo $label; ?></span>
                    <input type="time" name="availability[<?php echo $key; ?>][start]" class="ptp-input ptp-time-input" value="<?php echo esc_attr($start); ?>">
                    <input type="time" name="availability[<?php echo $key; ?>][end]" class="ptp-input ptp-time-input" value="<?php echo esc_attr($end); ?>">
                    <div class="ptp-day-toggle <?php echo $enabled ? 'active' : ''; ?>" data-day="<?php echo $key; ?>" onclick="toggleDay(this)"></div>
                    <input type="hidden" name="availability[<?php echo $key; ?>][enabled]" value="<?php echo $enabled ? '1' : '0'; ?>">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- STEP 7: TRAINER AGREEMENT (REQUIRED) -->
        <div class="ptp-onboard-section" id="contract">
            <h2><span class="step-num <?php echo $completion['contract'] ? 'done' : ''; ?>">7</span> Trainer Agreement <span style="color:#EF4444;font-size:14px;">(Required)</span></h2>
            
            <?php if ($trainer->contractor_agreement_signed): ?>
            <div class="ptp-contract-status signed">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <div>
                    <strong>Agreement Signed</strong>
                    <p style="margin:0;font-size:13px;color:#666;">Signed on <?php echo date('F j, Y', strtotime($trainer->contractor_agreement_signed_at)); ?></p>
                </div>
            </div>
            <?php else: ?>
            <p style="color:#666;font-size:14px;margin-bottom:16px;">Please review and agree to the PTP Trainer Agreement to continue.</p>
            
            <div class="ptp-contract-box">
                <div class="ptp-contract-scroll" id="contractContent">
                    <h3 style="font-family:Oswald,sans-serif;font-size:18px;margin:0 0 16px;text-transform:uppercase;">Independent Contractor Agreement</h3>
                    <p style="font-size:12px;color:#666;margin-bottom:16px;">Between PTP - Players Teaching Players, LLC ("PTP") and You ("Trainer")</p>
                    
                    <p><strong>Effective Date:</strong> Upon electronic signature below</p>
                    
                    <h4>1. RELATIONSHIP</h4>
                    <p>Trainer agrees to provide private soccer training services to clients introduced through the PTP platform as an <strong>independent contractor</strong>, not as an employee of PTP. Trainer maintains full control over their methods, schedule, and training approach.</p>
                    
                    <h4>2. PLATFORM SERVICES</h4>
                    <p>PTP provides:</p>
                    <ul>
                        <li>Online platform connecting trainers with families seeking training</li>
                        <li>Booking and scheduling system</li>
                        <li>Payment processing and payout services</li>
                        <li>Marketing and promotional support</li>
                        <li>Insurance coverage during PTP-booked sessions</li>
                    </ul>
                    
                    <h4>3. TRAINER RESPONSIBILITIES</h4>
                    <p>Trainer agrees to:</p>
                    <ul>
                        <li>Provide professional, safe, and age-appropriate training sessions</li>
                        <li>Arrive on time and prepared for all scheduled sessions</li>
                        <li>Maintain appropriate certifications (CPR/First Aid recommended)</li>
                        <li>Complete SafeSport training if requested</li>
                        <li>Communicate professionally with families through the platform</li>
                        <li>Cancel sessions with at least 24 hours notice except for emergencies</li>
                        <li>Never solicit PTP clients for off-platform bookings</li>
                        <li>Maintain confidentiality of client information</li>
                    </ul>
                    
                    <h4>4. COMPENSATION</h4>
                    <p>Trainer sets their own hourly rate. PTP uses a <strong>tiered commission structure</strong>:</p>
                    <ul>
                        <li><strong>First session with a new client:</strong> You receive <strong>50%</strong> (PTP retains 50% to cover customer acquisition)</li>
                        <li><strong>Repeat sessions with same client:</strong> You receive <strong>75%</strong> (PTP retains 25%)</li>
                    </ul>
                    <p>This model rewards you for building lasting relationships with clients. Payouts are processed weekly via Stripe Connect to your connected bank account.</p>
                    
                    <h4>5. CANCELLATION POLICY</h4>
                    <ul>
                        <li><strong>Trainer cancellation:</strong> Must provide 24+ hours notice. Repeated last-minute cancellations may result in account suspension.</li>
                        <li><strong>Client cancellation:</strong> Full refund if cancelled 24+ hours in advance. No refund for no-shows or late cancellations.</li>
                        <li><strong>Weather:</strong> Sessions cancelled due to weather will be rescheduled at no cost.</li>
                    </ul>
                    
                    <h4>6. CONDUCT & SAFETY</h4>
                    <p>Trainer agrees to:</p>
                    <ul>
                        <li>Never use inappropriate language or behavior with minors</li>
                        <li>Never be alone with a minor in an enclosed space (always train in open, visible areas)</li>
                        <li>Report any safety concerns or incidents to PTP immediately</li>
                        <li>Comply with all applicable laws and regulations</li>
                        <li>Never consume alcohol or controlled substances before or during sessions</li>
                    </ul>
                    
                    <h4>7. INSURANCE & LIABILITY</h4>
                    <p>PTP maintains general liability insurance covering trainers during PTP-booked sessions. Trainer is responsible for any damage or injury caused by gross negligence or intentional misconduct.</p>
                    
                    <h4>8. NON-SOLICITATION</h4>
                    <p>For 12 months after your last PTP session with a client, you agree not to solicit that client for private training services outside the PTP platform. Violation may result in immediate termination and legal action.</p>
                    
                    <h4>9. INTELLECTUAL PROPERTY</h4>
                    <p>PTP may use your name, likeness, and training content for marketing purposes. You retain ownership of any original training methods or materials you create.</p>
                    
                    <h4>10. TERMINATION</h4>
                    <p>Either party may terminate this agreement at any time with written notice. PTP may immediately terminate for:</p>
                    <ul>
                        <li>Violation of conduct or safety standards</li>
                        <li>Repeated cancellations or no-shows</li>
                        <li>Client complaints regarding unprofessional behavior</li>
                        <li>Soliciting clients outside the platform</li>
                    </ul>
                    
                    <h4>11. DISPUTE RESOLUTION</h4>
                    <p>Any disputes shall be resolved through binding arbitration in Philadelphia, PA under the rules of the American Arbitration Association.</p>
                    
                    <h4>12. ENTIRE AGREEMENT</h4>
                    <p>This agreement constitutes the entire understanding between PTP and Trainer. It supersedes all prior agreements and may only be modified in writing.</p>
                    
                    <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;"><strong>By checking the box below, you acknowledge that you have read, understand, and agree to be bound by the terms of this Independent Contractor Agreement.</strong></p>
                </div>
                
                <div class="ptp-contract-signature">
                    <label class="ptp-contract-checkbox">
                        <input type="checkbox" name="agree_contract" id="agreeContract" value="1" required>
                        <span>I, <strong><?php echo esc_html($trainer->display_name); ?></strong>, agree to the PTP Trainer Agreement and understand this constitutes a legally binding contract.</span>
                    </label>
                    <p class="ptp-contract-ip">Your IP address (<?php echo esc_html($_SERVER['REMOTE_ADDR']); ?>) and timestamp will be recorded.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- STEP 8: STRIPE -->
        <div class="ptp-onboard-section" id="stripe">
            <h2><span class="step-num <?php echo $completion['stripe'] ? 'done' : ''; ?>">8</span> Get Paid</h2>
            
            <?php if ($stripe_complete): ?>
            <div class="ptp-stripe-status complete">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <div>
                    <strong>Stripe Connected!</strong>
                    <p style="margin:0;font-size:13px;color:#666;">You're all set to receive payouts.</p>
                </div>
            </div>
            <?php elseif ($has_stripe): ?>
            <div class="ptp-stripe-status pending">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>
                    <strong>Almost There!</strong>
                    <p style="margin:0;font-size:13px;color:#666;">Complete your Stripe account setup.</p>
                </div>
                <a href="#" class="ptp-stripe-btn" onclick="connectStripe(event)">Complete Setup</a>
            </div>
            <?php else: ?>
            <p style="color:#666;margin-bottom:16px;">Connect your bank account through Stripe to receive payouts. Stripe is secure and used by millions of businesses.</p>
            <a href="#" id="stripeConnectBtn" class="ptp-stripe-btn" onclick="connectStripe(event)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:8px;">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
                Connect with Stripe
            </a>
            <?php endif; ?>
        </div>
        
        <!-- SUBMIT -->
        <div class="ptp-submit-section">
            <button type="submit" class="ptp-submit" id="submitBtn">
                <?php echo $is_edit ? 'Save Changes' : 'Complete Profile'; ?> →
            </button>
            <?php if (!$is_edit): ?>
            <a href="<?php echo home_url('/trainer-dashboard/?skip_onboarding=1'); ?>" class="ptp-skip">Skip for now →</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
// Cookie/localStorage key for remembering form data
var STORAGE_KEY = 'ptp_trainer_onboarding_<?php echo $trainer_id; ?>';

// Load saved form data on page load
function loadSavedData() {
    try {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            var data = JSON.parse(saved);
            var form = document.getElementById('onboardingForm');
            
            // Restore text inputs
            ['first_name', 'last_name', 'bio', 'coaching_why', 'training_philosophy', 
             'playing_experience', 'teams_played', 'years_playing', 'years_coaching', 
             'certifications', 'hourly_rate', 'travel_radius', 'city', 'state'].forEach(function(name) {
                if (data[name]) {
                    var input = form.querySelector('[name="' + name + '"]');
                    if (input) input.value = data[name];
                }
            });
            
            console.log('Loaded saved onboarding data');
        }
    } catch(e) {
        console.log('No saved data to restore');
    }
}

// Save form data as user types
function saveFormData() {
    try {
        var form = document.getElementById('onboardingForm');
        var data = {};
        
        ['first_name', 'last_name', 'bio', 'coaching_why', 'training_philosophy', 
         'playing_experience', 'teams_played', 'years_playing', 'years_coaching', 
         'certifications', 'hourly_rate', 'travel_radius', 'city', 'state'].forEach(function(name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input && input.value) {
                data[name] = input.value;
            }
        });
        
        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    } catch(e) {
        console.log('Could not save form data');
    }
}

// Add save listeners to inputs
document.querySelectorAll('#onboardingForm input, #onboardingForm textarea, #onboardingForm select').forEach(function(input) {
    input.addEventListener('change', saveFormData);
    input.addEventListener('blur', saveFormData);
});

// Load saved data on page load
loadSavedData();

// Photo preview
document.getElementById('photoInput').addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        var reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('photoPreview').innerHTML = '<img src="' + ev.target.result + '" alt="Preview">';
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

// Day toggle
function toggleDay(el) {
    el.classList.toggle('active');
    var input = el.nextElementSibling;
    input.value = el.classList.contains('active') ? '1' : '0';
}

// ==========================================
// TRAINING LOCATIONS with Google Maps
// ==========================================
var map = null;
var markers = [];
var autocomplete = null;

function initLocationsMap() {
    if (typeof google === 'undefined' || !google.maps) {
        console.log('Google Maps not loaded');
        return;
    }
    
    var mapElement = document.getElementById('locationsMap');
    if (!mapElement) return;
    
    // Default center (Philadelphia area)
    var defaultCenter = { lat: 39.9526, lng: -75.1652 };
    
    map = new google.maps.Map(mapElement, {
        center: defaultCenter,
        zoom: 10,
        styles: [
            { elementType: 'geometry', stylers: [{ color: '#f5f5f5' }] },
            { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
            { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#c9e4f5' }] }
        ],
        mapTypeControl: false,
        fullscreenControl: false,
        streetViewControl: false
    });
    
    // Initialize autocomplete on the location input
    var input = document.getElementById('locationInput');
    autocomplete = new google.maps.places.Autocomplete(input, {
        componentRestrictions: { country: 'us' },
        fields: ['formatted_address', 'geometry', 'name']
    });
    
    autocomplete.addListener('place_changed', function() {
        var place = autocomplete.getPlace();
        if (place.geometry) {
            var address = place.name && place.name !== place.formatted_address 
                ? place.name + ', ' + place.formatted_address 
                : place.formatted_address;
            addLocationToList(address, place.geometry.location);
            input.value = '';
        }
    });
    
    // Click on map to add location
    map.addListener('click', function(e) {
        var geocoder = new google.maps.Geocoder();
        geocoder.geocode({ location: e.latLng }, function(results, status) {
            if (status === 'OK' && results[0]) {
                addLocationToList(results[0].formatted_address, e.latLng);
            }
        });
    });
    
    // Add existing locations to map
    var existingLocations = document.querySelectorAll('#locationsList .ptp-location-item span');
    existingLocations.forEach(function(span) {
        geocodeAndAddMarker(span.textContent.trim());
    });
}

function addLocation() {
    var input = document.getElementById('locationInput');
    var address = input.value.trim();
    
    if (!address) {
        alert('Please enter a location');
        return;
    }
    
    if (typeof google !== 'undefined' && google.maps) {
        var geocoder = new google.maps.Geocoder();
        geocoder.geocode({ address: address + ', USA' }, function(results, status) {
            if (status === 'OK' && results[0]) {
                addLocationToList(results[0].formatted_address, results[0].geometry.location);
                input.value = '';
            } else {
                // Still add even without geocoding
                addLocationToList(address, null);
                input.value = '';
            }
        });
    } else {
        addLocationToList(address, null);
        input.value = '';
    }
}

function addLocationToList(address, latLng) {
    var list = document.getElementById('locationsList');
    
    // Check for duplicates
    var existing = list.querySelectorAll('input[name="training_locations[]"]');
    for (var i = 0; i < existing.length; i++) {
        if (existing[i].value.toLowerCase() === address.toLowerCase()) {
            alert('This location is already added');
            return;
        }
    }
    
    var item = document.createElement('div');
    item.className = 'ptp-location-item';
    item.innerHTML = 
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2">' +
            '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>' +
            '<circle cx="12" cy="10" r="3"/>' +
        '</svg>' +
        '<span>' + escapeHtml(address) + '</span>' +
        '<input type="hidden" name="training_locations[]" value="' + escapeHtml(address) + '">' +
        '<button type="button" class="ptp-location-remove" onclick="removeLocation(this)">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' +
            '</svg>' +
        '</button>';
    list.appendChild(item);
    
    // Add marker to map
    if (latLng && map) {
        var marker = new google.maps.Marker({
            position: latLng,
            map: map,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 10,
                fillColor: '#FCB900',
                fillOpacity: 1,
                strokeColor: '#0A0A0A',
                strokeWeight: 2
            },
            title: address
        });
        markers.push({ address: address, marker: marker });
        map.panTo(latLng);
    }
}

function removeLocation(btn) {
    var item = btn.closest('.ptp-location-item');
    var address = item.querySelector('span').textContent;
    
    // Remove marker from map
    for (var i = 0; i < markers.length; i++) {
        if (markers[i].address === address) {
            markers[i].marker.setMap(null);
            markers.splice(i, 1);
            break;
        }
    }
    
    item.remove();
}

function geocodeAndAddMarker(address) {
    if (!map || typeof google === 'undefined') return;
    
    var geocoder = new google.maps.Geocoder();
    geocoder.geocode({ address: address + ', USA' }, function(results, status) {
        if (status === 'OK' && results[0]) {
            var marker = new google.maps.Marker({
                position: results[0].geometry.location,
                map: map,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 10,
                    fillColor: '#FCB900',
                    fillOpacity: 1,
                    strokeColor: '#0A0A0A',
                    strokeWeight: 2
                },
                title: address
            });
            markers.push({ address: address, marker: marker });
        }
    });
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize map when Google Maps loads
if (typeof google !== 'undefined' && google.maps) {
    initLocationsMap();
} else {
    window.initLocationsMap = initLocationsMap;
    // Google Maps script calls this when loaded
    window.addEventListener('load', function() {
        setTimeout(initLocationsMap, 500);
    });
}

// Stripe Connect
function connectStripe(e) {
    e.preventDefault();
    var btn = document.getElementById('stripeConnectBtn');
    btn.textContent = 'Connecting...';
    btn.style.pointerEvents = 'none';
    
    var formData = new FormData();
    formData.append('action', 'ptp_create_stripe_connect_account');
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data.url) {
            window.location.href = data.data.url;
        } else {
            alert(data.data.message || 'Error connecting to Stripe');
            btn.textContent = 'Connect with Stripe';
            btn.style.pointerEvents = 'auto';
        }
    })
    .catch(function() {
        alert('Connection error. Please try again.');
        btn.textContent = 'Connect with Stripe';
        btn.style.pointerEvents = 'auto';
    });
}

// Form submit
document.getElementById('onboardingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Check if contract is signed (if checkbox exists and not already signed)
    var contractCheckbox = document.getElementById('agreeContract');
    if (contractCheckbox && !contractCheckbox.checked) {
        alert('Please read and agree to the Trainer Agreement to continue. This is required to join the PTP platform.');
        contractCheckbox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        contractCheckbox.focus();
        return;
    }
    
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    
    var formData = new FormData(this);
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            // Clear saved form data on success
            try { localStorage.removeItem(STORAGE_KEY); } catch(e) {}
            window.location.href = '<?php echo home_url('/trainer-dashboard/?welcome=1'); ?>';
        } else {
            alert(data.data.message || 'Error saving profile');
            btn.disabled = false;
            btn.textContent = 'Complete Profile →';
        }
    })
    .catch(function() {
        alert('Connection error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Complete Profile →';
    });
});
</script>

</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
