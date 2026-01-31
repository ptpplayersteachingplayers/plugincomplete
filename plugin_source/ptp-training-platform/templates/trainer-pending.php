<?php 
defined('ABSPATH') || exit;

// Check if trainer completed onboarding
$onboarding_completed = false;
if (is_user_logged_in()) {
    $onboarding_completed = get_user_meta(get_current_user_id(), 'ptp_onboarding_completed', true);
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $onboarding_completed ? 'Pending Approval' : 'Complete Your Profile'; ?> - PTP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    /* v133.2: Hide scrollbar */
    html, body { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }
    body { font-family: Inter, -apple-system, sans-serif; background: #FFFFFF; min-height: 100vh; display: flex; flex-direction: column; }
    h1 { font-family: Oswald, sans-serif; font-weight: 700; text-transform: uppercase; }
    .ptp-header { background: #0A0A0A; padding: 16px 20px; text-align: center; }
    .ptp-header img { height: 36px; }
    .ptp-pending { flex: 1; display: flex; align-items: center; justify-content: center; padding: 60px 20px; text-align: center; }
    .ptp-pending-content { max-width: 440px; }
    .ptp-pending-icon { width: 80px; height: 80px; background: #FCB900; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
    .ptp-pending-icon svg { width: 40px; height: 40px; stroke: #0A0A0A; }
    .ptp-pending-icon.success { background: #22C55E; }
    .ptp-pending-icon.success svg { stroke: #fff; }
    .ptp-pending h1 { font-size: 28px; color: #0A0A0A; margin-bottom: 12px; }
    .ptp-pending p { color: #525252; font-size: 16px; line-height: 1.6; margin-bottom: 28px; }
    .ptp-pending-btn { display: inline-block; padding: 16px 32px; background: #FCB900; color: #0A0A0A; font-family: Oswald, sans-serif; font-size: 14px; font-weight: 600; text-transform: uppercase; text-decoration: none; border-radius: 0; transition: background 0.2s; border: 2px solid #FCB900; }
    .ptp-pending-btn:hover { background: #0A0A0A; color: #FCB900; }
    .ptp-pending-btn.secondary { background: transparent; color: #0A0A0A; border-color: #E5E5E5; margin-left: 12px; }
    .ptp-pending-btn.secondary:hover { border-color: #0A0A0A; }
    .ptp-steps { text-align: left; background: #FAFAFA; border: 2px solid #E5E5E5; padding: 20px 24px; margin-bottom: 28px; }
    .ptp-steps h3 { font-family: Oswald, sans-serif; font-size: 12px; text-transform: uppercase; letter-spacing: 0.1em; color: #A3A3A3; margin-bottom: 12px; }
    .ptp-step { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #E5E5E5; }
    .ptp-step:last-child { border-bottom: none; }
    .ptp-step-check { width: 24px; height: 24px; border-radius: 50%; background: #22C55E; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ptp-step-check svg { width: 14px; height: 14px; stroke: #fff; stroke-width: 3; }
    .ptp-step-text { font-size: 14px; color: #374151; }
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
<div class="ptp-header">
    <a href="<?php echo home_url('/'); ?>">
        <img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP">
    </a>
</div>
<div class="ptp-pending">
    <div class="ptp-pending-content">
        <?php if ($onboarding_completed): ?>
            <!-- Completed onboarding, waiting for approval -->
            <div class="ptp-pending-icon success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h1>Profile Submitted!</h1>
            <p>Your profile is complete and we're reviewing it now. You'll receive an email once you're approved to start training.</p>
            
            <div class="ptp-steps">
                <h3>What's Next</h3>
                <div class="ptp-step">
                    <div class="ptp-step-check"><svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12"/></svg></div>
                    <span class="ptp-step-text">Application submitted</span>
                </div>
                <div class="ptp-step">
                    <div class="ptp-step-check"><svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12"/></svg></div>
                    <span class="ptp-step-text">Profile completed</span>
                </div>
                <div class="ptp-step">
                    <div class="ptp-step-check" style="background: #FCB900;"><svg viewBox="0 0 24 24" fill="none" stroke="#0A0A0A"><circle cx="12" cy="12" r="3"/></svg></div>
                    <span class="ptp-step-text"><strong>Approval in progress</strong> (usually within 24 hours)</span>
                </div>
            </div>
            
            <a href="<?php echo home_url('/'); ?>" class="ptp-pending-btn">Back to Home</a>
            <a href="<?php echo wp_logout_url(home_url('/')); ?>" class="ptp-pending-btn secondary">Log Out</a>
        <?php else: ?>
            <!-- Hasn't completed onboarding yet -->
            <div class="ptp-pending-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <line x1="19" y1="8" x2="19" y2="14"/>
                    <line x1="22" y1="11" x2="16" y2="11"/>
                </svg>
            </div>
            <h1>Complete Your Profile</h1>
            <p>You're approved! Now let's set up your trainer profile so families can find and book you.</p>
            <a href="<?php echo home_url('/trainer-onboarding/'); ?>" class="ptp-pending-btn">Complete Profile</a>
        <?php endif; ?>
    </div>
</div>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
