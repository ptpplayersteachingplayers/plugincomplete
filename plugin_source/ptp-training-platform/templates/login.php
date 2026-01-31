<?php
/**
 * Login Template - PTP v130.6
 * Mobile-first, robust login with PTP styling
 */
defined('ABSPATH') || exit;

// Security: Check for brute force attempts
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$attempts_key = 'ptp_login_attempts_' . md5($ip);
$attempts = get_transient($attempts_key) ?: 0;
$max_attempts = 5;
$lockout_time = 15 * MINUTE_IN_SECONDS;
$is_locked = $attempts >= $max_attempts;

// Handle logout message
$logged_out = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';

// Redirect if already logged in
if (is_user_logged_in()) {
    $user = wp_get_current_user();
    $redirect_url = home_url('/parent-dashboard/');
    if (class_exists('PTP_Trainer')) {
        $trainer = PTP_Trainer::get_by_user_id($user->ID);
        if ($trainer) {
            $redirect_url = home_url('/trainer-dashboard/');
        }
    }
    if (isset($_GET['redirect_to'])) {
        $requested_redirect = esc_url($_GET['redirect_to']);
        // SECURITY: Only allow redirects to same domain
        if (wp_validate_redirect($requested_redirect, false)) {
            $redirect_url = $requested_redirect;
        }
    }
    wp_safe_redirect($redirect_url);
    exit;
}

// Handle redirect_to parameter - SECURITY: Validate redirect is local
$redirect = home_url('/parent-dashboard/');
if (isset($_GET['redirect_to']) && !empty($_GET['redirect_to'])) {
    $requested_redirect = esc_url($_GET['redirect_to']);
    // Only allow redirects to same domain
    if (wp_validate_redirect($requested_redirect, false)) {
        $redirect = $requested_redirect;
    }
}

// Error/success messages
$error = '';
$success = '';

if (isset($_GET['login']) && $_GET['login'] === 'failed') {
    $error = 'Invalid email or password.';
    set_transient($attempts_key, $attempts + 1, $lockout_time);
}

if (isset($_GET['error'])) {
    $error_code = sanitize_text_field($_GET['error']);
    $error_messages = array(
        'invalid_email' => 'Invalid email address.',
        'invalid_username' => 'No account found with that email.',
        'incorrect_password' => 'Incorrect password.',
        'invalid_password' => 'Incorrect password.',
        'empty_username' => 'Please enter your email.',
        'empty_password' => 'Please enter your password.',
        'authentication_failed' => 'Login failed. Please try again.',
        'too_many_attempts' => 'Too many attempts. Try again in 15 minutes.',
        'expired_session' => 'Session expired. Please log in again.',
    );
    $error = $error_messages[$error_code] ?? 'Login failed. Please try again.';
}

if (isset($_GET['checkemail']) && $_GET['checkemail'] === 'confirm') {
    $success = 'Check your email for the reset link.';
}
if (isset($_GET['password']) && $_GET['password'] === 'changed') {
    $success = 'Password changed! Please sign in.';
}
if (isset($_GET['registered'])) {
    $success = 'Account created! Please sign in.';
}
if ($logged_out) {
    $success = 'Logged out successfully.';
}
if ($is_locked) {
    $error = 'Too many attempts. Try again in 15 minutes.';
}

// Custom minimal header for login
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Sign In - <?php bloginfo('name'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    :root {
        --gold: #FCB900;
        --black: #0A0A0A;
        --white: #FFFFFF;
        --gray-100: #F5F5F5;
        --gray-200: #E5E5E5;
        --gray-500: #737373;
        --gray-700: #404040;
        --red: #EF4444;
        --green: #22C55E;
        --font-display: 'Oswald', sans-serif;
        --font-body: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    
    html, body {
        height: 100%;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        /* v133.2: Hide scrollbar */
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }
    
    body {
        font-family: var(--font-body);
        background: var(--black);
        color: var(--black);
    }
    
    .ptp-login-page {
        min-height: 100%;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 24px 20px;
        padding-top: max(24px, env(safe-area-inset-top));
        padding-bottom: max(24px, env(safe-area-inset-bottom));
    }
    
    .ptp-login-card {
        width: 100%;
        max-width: 400px;
        background: var(--white);
        border: 2px solid var(--gray-200);
    }
    
    .ptp-login-header {
        background: var(--black);
        padding: 24px 24px 20px;
        text-align: center;
    }
    
    .ptp-login-logo { margin-bottom: 16px; }
    .ptp-login-logo img { height: 32px; width: auto; }
    
    .ptp-login-header h1 {
        font-family: var(--font-display);
        font-size: 24px;
        font-weight: 700;
        color: var(--white);
        text-transform: uppercase;
        letter-spacing: -0.02em;
        margin: 0;
    }
    
    .ptp-login-header h1 span { color: var(--gold); }
    
    .ptp-login-form { padding: 24px; }
    
    .ptp-login-message {
        padding: 14px 16px;
        font-size: 14px;
        line-height: 1.4;
        margin-bottom: 20px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    
    .ptp-login-message.error {
        background: #FEF2F2;
        color: #991B1B;
        border-left: 3px solid var(--red);
    }
    
    .ptp-login-message.success {
        background: #F0FDF4;
        color: #166534;
        border-left: 3px solid var(--green);
    }
    
    .ptp-login-message svg {
        flex-shrink: 0;
        width: 18px;
        height: 18px;
        margin-top: 1px;
    }
    
    .ptp-form-group { margin-bottom: 16px; }
    
    .ptp-form-group label {
        display: block;
        font-family: var(--font-display);
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--gray-700);
        margin-bottom: 8px;
    }
    
    .ptp-form-group input {
        width: 100%;
        height: 52px;
        padding: 0 16px;
        font-family: var(--font-body);
        font-size: 16px;
        border: 2px solid var(--gray-200);
        background: var(--white);
        color: var(--black);
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        border-radius: 0;
        transition: border-color 0.2s;
    }
    
    .ptp-form-group input::placeholder { color: var(--gray-500); }
    .ptp-form-group input:focus { outline: none; border-color: var(--gold); }
    .ptp-form-group input.has-error { border-color: var(--red); }
    
    .ptp-login-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        font-size: 14px;
    }
    
    .ptp-remember {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--gray-700);
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
    }
    
    .ptp-remember input[type="checkbox"] {
        width: 20px;
        height: 20px;
        accent-color: var(--gold);
        cursor: pointer;
    }
    
    .ptp-forgot a {
        color: var(--gold);
        text-decoration: none;
        font-weight: 500;
    }
    
    .ptp-forgot a:active { opacity: 0.7; }
    
    .ptp-login-btn {
        width: 100%;
        height: 52px;
        font-family: var(--font-display);
        font-size: 15px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: var(--black);
        background: var(--gold);
        border: 2px solid var(--gold);
        cursor: pointer;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        border-radius: 0;
        transition: all 0.15s;
        -webkit-tap-highlight-color: transparent;
    }
    
    .ptp-login-btn:active { transform: scale(0.98); opacity: 0.9; }
    .ptp-login-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    .ptp-login-btn.loading { pointer-events: none; opacity: 0.7; }
    
    .ptp-login-divider {
        display: flex;
        align-items: center;
        gap: 16px;
        margin: 20px 0;
        color: var(--gray-500);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .ptp-login-divider::before,
    .ptp-login-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--gray-200);
    }
    
    .ptp-google-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        width: 100%;
        height: 52px;
        font-family: var(--font-body);
        font-size: 15px;
        font-weight: 500;
        color: var(--black);
        background: var(--white);
        border: 2px solid var(--gray-200);
        cursor: pointer;
        text-decoration: none;
        -webkit-tap-highlight-color: transparent;
        transition: border-color 0.2s;
    }
    
    .ptp-google-btn:active { background: var(--gray-100); }
    .ptp-google-btn svg { width: 20px; height: 20px; }
    
    .ptp-login-footer { padding: 0 24px 24px; text-align: center; }
    
    .ptp-login-signup {
        font-size: 14px;
        color: var(--gray-700);
        margin-bottom: 16px;
    }
    
    .ptp-login-signup a {
        color: var(--gold);
        text-decoration: none;
        font-weight: 600;
    }
    
    .ptp-login-apply {
        font-size: 13px;
        color: var(--gray-500);
        padding-top: 16px;
        border-top: 1px solid var(--gray-200);
    }
    
    .ptp-login-apply a { color: var(--gray-500); text-decoration: none; }
    .ptp-login-apply strong { color: var(--gold); font-weight: 600; }
    
    @keyframes spin { to { transform: rotate(360deg); } }
    
    .ptp-spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 2px solid var(--black);
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin-right: 8px;
        vertical-align: middle;
    }
    
    @media (max-height: 500px) {
        .ptp-login-footer { display: none; }
        .ptp-login-page { justify-content: flex-start; padding-top: 20px; }
    }
    
    @media (min-width: 600px) {
        .ptp-login-header { padding: 32px 32px 24px; }
        .ptp-login-form { padding: 32px; }
        .ptp-login-footer { padding: 0 32px 32px; }
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

<div class="ptp-login-page">
    <div class="ptp-login-card">
        <div class="ptp-login-header">
            <div class="ptp-login-logo">
                <a href="<?php echo esc_url(home_url('/')); ?>">
                    <?php
                    $logo_url = 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png';
                    $custom_logo_id = get_theme_mod('custom_logo');
                    if ($custom_logo_id) {
                        $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
                    }
                    ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="PTP">
                </a>
            </div>
            <h1>Sign <span>In</span></h1>
        </div>
        
        <div class="ptp-login-form">
            <?php if ($error): ?>
                <div class="ptp-login-message error">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo esc_html($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="ptp-login-message success">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo esc_html($success); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo esc_url(wp_login_url()); ?>" id="loginForm" <?php echo $is_locked ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
                
                <div class="ptp-form-group">
                    <label for="user_login">Email</label>
                    <input type="email" name="log" id="user_login" placeholder="you@email.com" required autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="email">
                </div>
                
                <div class="ptp-form-group">
                    <label for="user_pass">Password</label>
                    <input type="password" name="pwd" id="user_pass" placeholder="Your password" required autocomplete="current-password">
                </div>
                
                <div class="ptp-login-options">
                    <label class="ptp-remember">
                        <input type="checkbox" name="rememberme" value="forever" checked>
                        <span>Remember me</span>
                    </label>
                    <div class="ptp-forgot">
                        <a href="<?php echo esc_url(wp_lostpassword_url(home_url('/login/'))); ?>">Forgot?</a>
                    </div>
                </div>
                
                <button type="submit" class="ptp-login-btn" id="loginBtn">
                    <span id="btnText">Sign In</span>
                </button>
            </form>
            
            <?php if (class_exists('PTP_Google_Web_Login') && PTP_Google_Web_Login::is_configured()): ?>
            <div class="ptp-login-divider">or</div>
            <a href="<?php echo esc_url(home_url('/login/google/')); ?>" class="ptp-google-btn">
                <svg viewBox="0 0 24 24">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Continue with Google
            </a>
            <?php endif; ?>
        </div>
        
        <div class="ptp-login-footer">
            <p class="ptp-login-signup">
                No account? <a href="<?php echo esc_url(home_url('/register/')); ?>">Sign up</a>
            </p>
            <div class="ptp-login-apply">
                <a href="<?php echo esc_url(home_url('/apply/')); ?>">Coach? <strong>Apply here</strong></a>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('loginForm');
    var btn = document.getElementById('loginBtn');
    var btnText = document.getElementById('btnText');
    var emailInput = document.getElementById('user_login');
    var passInput = document.getElementById('user_pass');
    
    form.addEventListener('submit', function(e) {
        var email = emailInput.value.trim();
        var pass = passInput.value;
        
        if (!email || !pass) {
            e.preventDefault();
            if (!email) emailInput.classList.add('has-error');
            if (!pass) passInput.classList.add('has-error');
            return;
        }
        
        btn.classList.add('loading');
        btn.disabled = true;
        btnText.innerHTML = '<span class="ptp-spinner"></span>Signing in...';
    });
    
    emailInput.addEventListener('input', function() { this.classList.remove('has-error'); });
    passInput.addEventListener('input', function() { this.classList.remove('has-error'); });
    
    if (window.innerWidth >= 768 && !emailInput.value) {
        setTimeout(function() { emailInput.focus(); }, 100);
    }
})();
</script>

<?php wp_footer(); ?>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
