<?php
/**
 * Register Template - PTP v104
 * Full width desktop layout
 */
defined('ABSPATH') || exit;

// Redirect if already logged in
if (is_user_logged_in()) {
    $user = wp_get_current_user();
    $redirect_url = home_url('/parent-dashboard/');
    
    // Check if trainer (with class_exists check for safety)
    if (class_exists('PTP_Trainer')) {
        $trainer = PTP_Trainer::get_by_user_id($user->ID);
        if ($trainer) {
            $redirect_url = home_url('/trainer-dashboard/');
        }
    }
    
    wp_safe_redirect($redirect_url);
    exit;
}

get_header();
?>
<style>
:root{--gold:#FCB900;--black:#0A0A0A;--gray:#F5F5F5;--red:#EF4444;--green:#22C55E}
/* v133.2: Hide scrollbar */
html,body{scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0}

.ptp-auth-page{font-family:Inter,-apple-system,sans-serif;min-height:100vh;min-height:100dvh;display:grid;grid-template-columns:1fr;background:var(--black)}
@media(min-width:900px){.ptp-auth-page{grid-template-columns:1fr 1fr}}

.ptp-auth-hero{display:none;background:linear-gradient(135deg,var(--black) 0%,#1a1a1a 100%);padding:60px;flex-direction:column;justify-content:center}
@media(min-width:900px){.ptp-auth-hero{display:flex}}
.ptp-auth-hero h1{font-family:Oswald,sans-serif;font-size:clamp(36px,5vw,48px);font-weight:700;color:#fff;text-transform:uppercase;line-height:1.1;margin:0 0 20px}
.ptp-auth-hero h1 span{color:var(--gold)}
.ptp-auth-hero p{color:rgba(255,255,255,.6);font-size:17px;line-height:1.6;margin:0 0 40px;max-width:420px}
.ptp-auth-features{display:flex;flex-direction:column;gap:16px}
.ptp-auth-feature{display:flex;align-items:center;gap:12px;color:rgba(255,255,255,.8);font-size:14px}
.ptp-auth-feature-icon{width:36px;height:36px;background:rgba(252,185,0,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:16px}
.ptp-auth-testimonial{margin-top:48px;padding:24px;background:rgba(255,255,255,.03);border-left:3px solid var(--gold)}
.ptp-auth-testimonial p{color:rgba(255,255,255,.7);font-size:14px;font-style:italic;line-height:1.6;margin:0 0 12px}
.ptp-auth-testimonial cite{color:var(--gold);font-size:13px;font-style:normal;font-weight:600}

/* v123: Enhanced mobile form wrapper */
.ptp-auth-form-wrap{display:flex;align-items:center;justify-content:center;padding:24px 16px;background:#fff;min-height:100vh;min-height:100dvh}
@media(min-width:900px){.ptp-auth-form-wrap{padding:60px}}

/* v123: Safe area insets for notched phones */
@supports(padding: max(0px)) {
    .ptp-auth-form-wrap {
        padding-left: max(16px, env(safe-area-inset-left));
        padding-right: max(16px, env(safe-area-inset-right));
        padding-bottom: max(24px, env(safe-area-inset-bottom));
    }
}

.ptp-auth-card{width:100%;max-width:440px}
.ptp-auth-logo{text-align:center;margin-bottom:24px}
@media(min-width:900px){.ptp-auth-logo{margin-bottom:32px}}
.ptp-auth-logo img{height:40px}
@media(min-width:900px){.ptp-auth-logo img{height:44px}}
.ptp-auth-card h1{font-family:Oswald,sans-serif;font-size:24px;font-weight:700;text-transform:uppercase;text-align:center;margin:0 0 20px;color:var(--black)}
@media(min-width:900px){.ptp-auth-card h1{font-size:28px;margin:0 0 28px}}
.ptp-auth-error{background:rgba(239,68,68,.08);border:2px solid rgba(239,68,68,.15);color:var(--red);padding:14px 16px;border-radius:10px;font-size:14px;margin-bottom:20px;text-align:center;display:none}
.ptp-auth-success{background:rgba(34,197,94,.08);border:2px solid rgba(34,197,94,.15);color:var(--green);padding:14px 16px;border-radius:10px;font-size:14px;margin-bottom:20px;text-align:center;display:none}

/* v123: Better touch targets */
.ptp-form-row{margin-bottom:14px}
@media(min-width:900px){.ptp-form-row{margin-bottom:16px}}
.ptp-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:400px){.ptp-form-grid{grid-template-columns:1fr;gap:14px}}
.ptp-label{display:block;font-family:Oswald,sans-serif;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#666;margin-bottom:8px}
.ptp-input{width:100%;padding:16px;font-size:16px;border:2px solid #e5e5e5;border-radius:10px;transition:.2s;box-sizing:border-box;-webkit-appearance:none;appearance:none}
@media(min-width:900px){.ptp-input{font-size:15px;padding:14px}}
.ptp-input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(252,185,0,0.1)}
.ptp-input.error{border-color:var(--red)}
.ptp-checkbox{display:flex;align-items:flex-start;gap:10px;font-size:13px;color:#666;margin:16px 0;min-height:44px}
@media(min-width:900px){.ptp-checkbox{margin:20px 0}}
.ptp-checkbox input{width:20px;height:20px;accent-color:var(--gold);margin-top:2px;flex-shrink:0}
.ptp-checkbox a{color:var(--gold);text-decoration:none}
.ptp-checkbox a:hover{text-decoration:underline}
.ptp-auth-submit{width:100%;padding:18px;background:var(--gold);color:var(--black);border:none;font-family:Oswald,sans-serif;font-size:16px;font-weight:600;text-transform:uppercase;cursor:pointer;border-radius:10px;transition:.2s;letter-spacing:.5px;min-height:56px;-webkit-tap-highlight-color:transparent}
.ptp-auth-submit:hover{background:#E5A800;transform:translateY(-1px)}
.ptp-auth-submit:active{transform:scale(0.98);background:#D4990A}
.ptp-auth-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}
.ptp-auth-footer{text-align:center;margin-top:24px;font-size:15px;color:#666}
@media(min-width:900px){.ptp-auth-footer{margin-top:28px}}
.ptp-auth-footer a{color:var(--gold);font-weight:600;text-decoration:none;padding:8px;display:inline-block}
.ptp-auth-footer a:hover{text-decoration:underline}
.ptp-password-hint{font-size:11px;color:#999;margin-top:4px}

/* Social login */
.ptp-auth-divider{display:flex;align-items:center;margin:24px 0;gap:16px}
.ptp-auth-divider::before,.ptp-auth-divider::after{content:'';flex:1;height:1px;background:#e5e5e5}
.ptp-auth-divider span{font-size:12px;color:#999;text-transform:uppercase;letter-spacing:1px}
.ptp-social-login{margin-bottom:20px}
.ptp-social-btn{display:flex;align-items:center;justify-content:center;gap:12px;padding:16px;border:2px solid #e5e5e5;border-radius:10px;background:#fff;cursor:pointer;font-size:14px;font-weight:500;color:#333;transition:all .2s;text-decoration:none;min-height:52px;-webkit-tap-highlight-color:transparent}
.ptp-social-btn:hover{border-color:#ccc;background:#f9f9f9}
.ptp-social-btn:active{background:#f0f0f0;transform:scale(0.98)}
</style>

<div class="ptp-auth-page">
    <div class="ptp-auth-hero">
        <h1>JOIN THE <span>PTP FAMILY</span></h1>
        <p>Create an account to book camps, schedule private training, and give your child the competitive edge they deserve.</p>
        <div class="ptp-auth-features">
            <div class="ptp-auth-feature">
                <div class="ptp-auth-feature-icon">🏆</div>
                <span>MLS & D1 coaches who actually PLAY with kids</span>
            </div>
            <div class="ptp-auth-feature">
                <div class="ptp-auth-feature-icon">📅</div>
                <span>Camps + Private Training in one place</span>
            </div>
            <div class="ptp-auth-feature">
                <div class="ptp-auth-feature-icon">💰</div>
                <span>Earn $25 for every family you refer</span>
            </div>
        </div>
        <div class="ptp-auth-testimonial">
            <p>"My son improved more in 4 days than an entire season of rec league. The coaches actually play with the kids—it's not just drills."</p>
            <cite>— Sarah M., Villanova</cite>
        </div>
    </div>
    
    <div class="ptp-auth-form-wrap">
        <div class="ptp-auth-card">
            <div class="ptp-auth-logo">
                <a href="<?php echo home_url('/'); ?>">
                    <img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP">
                </a>
            </div>
            <h1>Create Account</h1>
            
            <?php
            // Show social login if Google is configured
            if (class_exists('PTP_Google_Web_Login') && PTP_Google_Web_Login::is_configured()):
            ?>
            <div class="ptp-social-login">
                <a href="<?php echo esc_url(home_url('/register/google/')); ?>" class="ptp-social-btn ptp-google-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    <span>Sign up with Google</span>
                </a>
            </div>
            
            <div class="ptp-auth-divider">
                <span>or</span>
            </div>
            <?php endif; ?>
            
            <div class="ptp-auth-error" id="errorMsg"></div>
            <div class="ptp-auth-success" id="successMsg"></div>
            
            <form id="registerForm">
                <?php wp_nonce_field('ptp_ajax_nonce', 'ptp_nonce'); ?>
                <input type="hidden" name="action" value="ptp_register">
                
                <div class="ptp-form-row ptp-form-grid">
                    <div>
                        <label class="ptp-label">First Name *</label>
                        <input type="text" name="first_name" id="firstName" class="ptp-input" placeholder="John" required>
                    </div>
                    <div>
                        <label class="ptp-label">Last Name *</label>
                        <input type="text" name="last_name" id="lastName" class="ptp-input" placeholder="Smith" required>
                    </div>
                </div>
                
                <div class="ptp-form-row">
                    <label class="ptp-label">Email *</label>
                    <input type="email" name="email" id="email" class="ptp-input" placeholder="john@example.com" required>
                </div>
                
                <div class="ptp-form-row">
                    <label class="ptp-label">Phone *</label>
                    <input type="tel" name="phone" id="phone" class="ptp-input" placeholder="(555) 123-4567" required>
                </div>
                
                <div class="ptp-form-row">
                    <label class="ptp-label">Password *</label>
                    <input type="password" name="password" id="password" class="ptp-input" placeholder="Create a password" minlength="8" required>
                    <div class="ptp-password-hint">At least 8 characters</div>
                </div>
                
                <label class="ptp-checkbox">
                    <input type="checkbox" name="terms" id="terms" required>
                    <span>I agree to the <a href="<?php echo home_url('/terms/'); ?>" target="_blank">Terms</a> and <a href="<?php echo home_url('/privacy/'); ?>" target="_blank">Privacy Policy</a></span>
                </label>
                
                <button type="submit" class="ptp-auth-submit" id="submitBtn">Create Account</button>
            </form>
            
            <p class="ptp-auth-footer">Already have an account? <a href="<?php echo home_url('/login/'); ?>">Sign in</a></p>
        </div>
    </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var btn = document.getElementById('submitBtn');
    var errorDiv = document.getElementById('errorMsg');
    var successDiv = document.getElementById('successMsg');
    
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    document.querySelectorAll('.ptp-input').forEach(function(el) { el.classList.remove('error'); });
    
    var firstName = document.getElementById('firstName').value.trim();
    var lastName = document.getElementById('lastName').value.trim();
    var email = document.getElementById('email').value.trim();
    var phone = document.getElementById('phone').value.trim();
    var password = document.getElementById('password').value;
    var terms = document.getElementById('terms').checked;
    
    if (!firstName || !lastName) { showError('Please enter your full name'); return; }
    if (!email || !email.includes('@')) { showError('Please enter a valid email'); document.getElementById('email').classList.add('error'); return; }
    if (!phone) { showError('Please enter your phone'); document.getElementById('phone').classList.add('error'); return; }
    if (password.length < 8) { showError('Password must be at least 8 characters'); document.getElementById('password').classList.add('error'); return; }
    if (!terms) { showError('Please agree to Terms and Privacy Policy'); return; }
    
    btn.disabled = true;
    btn.textContent = 'Creating Account...';
    
    var formData = new FormData();
    formData.append('action', 'ptp_register');
    formData.append('name', firstName + ' ' + lastName);
    formData.append('email', email);
    formData.append('phone', phone);
    formData.append('password', password);
    formData.append('user_type', 'parent');
    formData.append('nonce', document.querySelector('[name="ptp_nonce"]').value);
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            successDiv.textContent = 'Account created! Redirecting...';
            successDiv.style.display = 'block';
            setTimeout(function() { window.location.href = data.data.redirect || '<?php echo home_url('/parent-dashboard/'); ?>'; }, 1000);
        } else {
            showError(data.data.message || 'Registration failed. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Create Account';
        }
    })
    .catch(function(err) {
        showError('Connection error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Create Account';
    });
    
    function showError(msg) { errorDiv.textContent = msg; errorDiv.style.display = 'block'; }
});
</script>
<?php get_footer(); ?>
