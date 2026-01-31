<?php
/**
 * Trainer Application - PTP Style (v134)
 * Responsive design - mobile-first, polished desktop
 */
defined('ABSPATH') || exit;
get_header();
?>
<style>
:root {
    --ptp-gold: #FCB900;
    --ptp-gold-light: #FFF8E1;
    --ptp-black: #0A0A0A;
    --ptp-gray-50: #FAFAFA;
    --ptp-gray-100: #F5F5F5;
    --ptp-gray-200: #E5E5E5;
    --ptp-gray-400: #A3A3A3;
    --ptp-gray-600: #525252;
    --ptp-green: #22C55E;
    --header-height: 64px;
    --safe-top: env(safe-area-inset-top, 0px);
}

* { box-sizing: border-box; }

/* v134: Force scroll to work */
html, body {
    overflow-y: auto !important;
    overflow-x: hidden !important;
    height: auto !important;
    position: static !important;
    -webkit-overflow-scrolling: touch !important;
}

/* Page wrapper */
html body .ptp-apply-page {
    padding-top: calc(var(--header-height) + var(--safe-top)) !important;
    margin-top: 0 !important;
    min-height: 100vh;
    background: var(--ptp-gray-50);
    overflow-y: auto !important;
    position: static !important;
}

@supports (-webkit-touch-callout: none) {
    html body .ptp-apply-page {
        padding-top: calc(64px + env(safe-area-inset-top, 0px)) !important;
    }
}

/* ============================================
   HERO SECTION
   ============================================ */
.ptp-apply-hero {
    background: var(--ptp-black);
    padding: 40px 20px;
    text-align: center;
    margin-top: 0;
}

.ptp-apply-hero h1 {
    font-family: 'Oswald', sans-serif;
    font-size: 32px;
    font-weight: 700;
    text-transform: uppercase;
    color: #fff;
    margin: 0 0 12px;
    letter-spacing: -0.5px;
    line-height: 1.15;
}
.ptp-apply-hero h1 span { color: var(--ptp-gold); }

.ptp-apply-hero p {
    font-family: 'Inter', -apple-system, sans-serif;
    font-size: 16px;
    color: var(--ptp-gray-400);
    margin: 0 auto;
    line-height: 1.5;
    max-width: 400px;
}

/* Desktop hero */
@media(min-width: 900px) {
    .ptp-apply-hero {
        padding: 64px 40px;
    }
    .ptp-apply-hero h1 {
        font-size: 48px;
        margin-bottom: 16px;
    }
    .ptp-apply-hero p {
        font-size: 18px;
        max-width: 500px;
    }
}

/* ============================================
   MAIN LAYOUT - MOBILE FIRST (BIGGER)
   ============================================ */
.ptp-apply-wrapper {
    max-width: 600px;
    margin: 0 auto;
    padding: 24px 20px 120px;
}

/* Desktop: Two column layout */
@media(min-width: 900px) {
    .ptp-apply-wrapper {
        max-width: 1100px;
        padding: 48px 40px 80px;
        display: grid;
        grid-template-columns: 340px 1fr;
        gap: 48px;
        align-items: start;
    }
}

@media(min-width: 1200px) {
    .ptp-apply-wrapper {
        max-width: 1200px;
        grid-template-columns: 380px 1fr;
        gap: 64px;
    }
}

/* ============================================
   LEFT SIDEBAR (Desktop only)
   ============================================ */
.ptp-apply-sidebar {
    display: none;
}

@media(min-width: 900px) {
    .ptp-apply-sidebar {
        display: block;
        position: sticky;
        top: calc(var(--header-height) + 32px);
    }
    
    .ptp-apply-sidebar-card {
        background: var(--ptp-black);
        padding: 32px;
        margin-bottom: 24px;
    }
    
    .ptp-apply-sidebar h3 {
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--ptp-gold);
        margin: 0 0 20px;
    }
    
    .ptp-apply-perk {
        display: flex;
        gap: 14px;
        margin-bottom: 20px;
    }
    .ptp-apply-perk:last-child { margin-bottom: 0; }
    
    .ptp-apply-perk-icon {
        width: 40px;
        height: 40px;
        background: rgba(252, 185, 0, 0.1);
        border: 2px solid var(--ptp-gold);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .ptp-apply-perk-icon svg {
        width: 20px;
        height: 20px;
        stroke: var(--ptp-gold);
        fill: none;
        stroke-width: 2;
    }
    
    .ptp-apply-perk-text h4 {
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        color: #fff;
        margin: 0 0 4px;
    }
    .ptp-apply-perk-text p {
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        color: var(--ptp-gray-400);
        margin: 0;
        line-height: 1.4;
    }
    
    /* Stats row */
    .ptp-apply-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        background: #fff;
        border: 2px solid var(--ptp-gray-200);
        padding: 24px;
    }
    
    .ptp-apply-stat {
        text-align: center;
    }
    .ptp-apply-stat-num {
        font-family: 'Oswald', sans-serif;
        font-size: 32px;
        font-weight: 700;
        color: var(--ptp-black);
        line-height: 1;
    }
    .ptp-apply-stat-label {
        font-family: 'Oswald', sans-serif;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--ptp-gray-600);
        margin-top: 4px;
    }
}

/* ============================================
   FORM CONTAINER - MOBILE OPTIMIZED
   ============================================ */
.ptp-apply {
    font-family: 'Inter', -apple-system, sans-serif;
}

/* Form Card - BIGGER on mobile */
.ptp-apply-card {
    background: #fff;
    border: 2px solid var(--ptp-gray-200);
    padding: 24px 20px;
    margin-bottom: 20px;
}

@media(min-width: 900px) {
    .ptp-apply-card {
        padding: 28px;
        margin-bottom: 20px;
    }
}

.ptp-apply-card-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 24px;
    padding-bottom: 18px;
    border-bottom: 2px solid var(--ptp-gray-100);
}

.ptp-apply-card-num {
    width: 38px;
    height: 38px;
    background: var(--ptp-black);
    color: var(--ptp-gold);
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.ptp-apply-card-title {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--ptp-black);
    margin: 0;
}

/* Form Elements - BIGGER for mobile */
.ptp-apply label {
    display: block;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--ptp-gray-600);
    margin-bottom: 8px;
}

.ptp-apply input[type="text"],
.ptp-apply input[type="email"],
.ptp-apply input[type="tel"],
.ptp-apply input[type="password"],
.ptp-apply select,
.ptp-apply textarea {
    width: 100%;
    padding: 16px 14px;
    font-size: 16px;
    font-family: 'Inter', -apple-system, sans-serif;
    border: 2px solid var(--ptp-gray-200);
    background: #fff;
    margin-bottom: 18px;
    -webkit-appearance: none;
    appearance: none;
    border-radius: 0;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.ptp-apply input:focus,
.ptp-apply select:focus,
.ptp-apply textarea:focus {
    outline: none;
    border-color: var(--ptp-gold);
    box-shadow: 0 0 0 3px rgba(252, 185, 0, 0.1);
}

.ptp-apply textarea { 
    min-height: 120px; 
    resize: vertical; 
}

.ptp-apply select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23525252' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    cursor: pointer;
}

.ptp-apply .row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.ptp-apply .hint {
    font-size: 13px;
    color: var(--ptp-gray-400);
    margin: -12px 0 18px;
}

/* Contract Box */
.ptp-apply .contract-box {
    border: 2px solid var(--ptp-gray-200);
    background: #fff;
}

.ptp-apply .contract-scroll {
    max-height: 220px;
    overflow-y: auto;
    padding: 20px;
    font-size: 13px;
    line-height: 1.6;
    background: var(--ptp-gray-50);
}

@media(min-width: 900px) {
    .ptp-apply .contract-scroll {
        max-height: 280px;
        padding: 24px;
        font-size: 13px;
    }
}

.ptp-apply .contract-scroll h3 {
    font-family: 'Oswald', sans-serif;
    font-size: 15px;
    margin: 0 0 12px;
    text-transform: uppercase;
    color: var(--ptp-black);
}

.ptp-apply .contract-scroll h4 {
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    margin: 16px 0 6px;
    color: var(--ptp-black);
    text-transform: uppercase;
}

.ptp-apply .contract-scroll p { 
    margin: 0 0 10px; 
    color: #374151; 
}

.ptp-apply .contract-scroll ul { 
    margin: 0 0 10px; 
    padding-left: 18px; 
    color: #374151; 
}

.ptp-apply .contract-scroll li { 
    margin-bottom: 4px; 
}

.ptp-apply .contract-signature {
    padding: 18px 20px;
    background: #fff;
    border-top: 2px solid var(--ptp-gray-200);
}

.ptp-apply .contract-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    cursor: pointer;
    font-size: 14px;
    line-height: 1.4;
}

.ptp-apply .contract-checkbox input[type="checkbox"] {
    width: 24px;
    height: 24px;
    margin-top: 0;
    accent-color: var(--ptp-gold);
    cursor: pointer;
    flex-shrink: 0;
}
.ptp-apply .contract-ip {
    font-size: 11px;
    color: var(--ptp-gray-400);
    margin: 10px 0 0;
}

/* Submit Button - BIG on mobile */
.ptp-apply button[type="submit"] {
    width: 100%;
    padding: 18px 24px;
    font-family: 'Oswald', sans-serif;
    font-size: 17px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ptp-black);
    background: var(--ptp-gold);
    border: 2px solid var(--ptp-gold);
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 0;
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    -webkit-tap-highlight-color: transparent;
}

@media(min-width: 900px) {
    .ptp-apply button[type="submit"] {
        font-size: 16px;
        min-height: 58px;
    }
    .ptp-apply button[type="submit"]:hover {
        background: var(--ptp-black);
        color: var(--ptp-gold);
        border-color: var(--ptp-black);
    }
}

.ptp-apply button[type="submit"]:active { transform: scale(0.98); }
.ptp-apply button[type="submit"]:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Error Message */
.ptp-apply .error {
    background: var(--ptp-black);
    color: var(--ptp-gold);
    padding: 14px 16px;
    margin-bottom: 16px;
    font-size: 13px;
    display: none;
    border-left: 4px solid var(--ptp-gold);
}

/* Success State */
.ptp-apply .success {
    text-align: center;
    padding: 48px 24px;
    display: none;
    background: #fff;
    border: 2px solid var(--ptp-gray-200);
}

@media(min-width: 900px) {
    .ptp-apply .success {
        padding: 64px 40px;
    }
}

.ptp-apply .success-icon {
    width: 72px;
    height: 72px;
    background: var(--ptp-green);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.ptp-apply .success-icon svg {
    width: 36px;
    height: 36px;
    stroke: #fff;
}

.ptp-apply .success h2 {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--ptp-black);
    margin: 0 0 12px;
}

.ptp-apply .success p {
    color: var(--ptp-gray-600);
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
}

/* Note */
.ptp-apply .note {
    text-align: center;
    font-size: 13px;
    color: var(--ptp-gray-400);
    margin-top: 16px;
}

/* Safe area for notched phones */
@supports(padding: max(0px)) {
    .ptp-apply-wrapper {
        padding-left: max(16px, env(safe-area-inset-left));
        padding-right: max(16px, env(safe-area-inset-right));
        padding-bottom: max(100px, calc(100px + env(safe-area-inset-bottom)));
    }
    
    @media(min-width: 900px) {
        .ptp-apply-wrapper {
            padding-left: max(40px, env(safe-area-inset-left));
            padding-right: max(40px, env(safe-area-inset-right));
            padding-bottom: max(80px, calc(80px + env(safe-area-inset-bottom)));
        }
    }
}
</style>

<!-- v131: Wrapper for header spacing -->
<div class="ptp-apply-page">

<!-- Hero Section -->
<div class="ptp-apply-hero">
    <h1>Join <span>PTP</span></h1>
    <p>Teaching what team coaches don't. Help players develop the individual skills they need to stand out.</p>
</div>

<!-- Main Two-Column Layout -->
<div class="ptp-apply-wrapper">
    
    <!-- Left Sidebar (Desktop only) -->
    <div class="ptp-apply-sidebar">
        <div class="ptp-apply-sidebar-card">
            <h3>Why Train With PTP</h3>
            
            <div class="ptp-apply-perk">
                <div class="ptp-apply-perk-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <div class="ptp-apply-perk-text">
                    <h4>You Set Your Rate</h4>
                    <p>Earn 50% first session, 75% repeat. Weekly Stripe payouts.</p>
                </div>
            </div>
            
            <div class="ptp-apply-perk">
                <div class="ptp-apply-perk-icon">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="ptp-apply-perk-text">
                    <h4>We Find Clients</h4>
                    <p>Marketing, booking system, and payment processing handled.</p>
                </div>
            </div>
            
            <div class="ptp-apply-perk">
                <div class="ptp-apply-perk-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div class="ptp-apply-perk-text">
                    <h4>Fully Insured</h4>
                    <p>Liability coverage during all sessions. Train worry-free.</p>
                </div>
            </div>
            
            <div class="ptp-apply-perk">
                <div class="ptp-apply-perk-icon">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="ptp-apply-perk-text">
                    <h4>Flexible Schedule</h4>
                    <p>Set your own availability. Train when it works for you.</p>
                </div>
            </div>
        </div>
        
        <div class="ptp-apply-stats">
            <div class="ptp-apply-stat">
                <div class="ptp-apply-stat-num">500+</div>
                <div class="ptp-apply-stat-label">Families Served</div>
            </div>
            <div class="ptp-apply-stat">
                <div class="ptp-apply-stat-num">5</div>
                <div class="ptp-apply-stat-label">States</div>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Form -->
    <div class="ptp-apply">
        <div class="error" id="errorMsg"></div>
        
        <form id="applyForm">
            <input type="hidden" name="action" value="ptp_coach_application">
            <?php wp_nonce_field('ptp_coach_application', 'ptp_coach_nonce'); ?>
            
            <!-- Card 1: Personal Info -->
            <div class="ptp-apply-card">
                <div class="ptp-apply-card-header">
                    <div class="ptp-apply-card-num">1</div>
                    <h3 class="ptp-apply-card-title">Your Info</h3>
                </div>
                <div class="row">
                    <div><label>First Name *</label><input type="text" name="first_name" required></div>
                    <div><label>Last Name *</label><input type="text" name="last_name" required></div>
                </div>
                <label>Email *</label>
                <input type="email" name="email" required placeholder="you@email.com">
                <label>Phone *</label>
                <input type="tel" name="phone" required placeholder="(555) 555-5555">
            </div>
            
            <!-- Card 2: Password -->
            <div class="ptp-apply-card">
                <div class="ptp-apply-card-header">
                    <div class="ptp-apply-card-num">2</div>
                    <h3 class="ptp-apply-card-title">Create Login</h3>
                </div>
                <label>Password *</label>
                <input type="password" name="password" required minlength="8">
                <p class="hint">Minimum 8 characters</p>
                <label>Confirm Password *</label>
                <input type="password" name="password_confirm" required>
            </div>
            
            <!-- Card 3: Soccer Background -->
            <div class="ptp-apply-card">
                <div class="ptp-apply-card-header">
                    <div class="ptp-apply-card-num">3</div>
                    <h3 class="ptp-apply-card-title">Background</h3>
                </div>
                <label>Highest Playing Level *</label>
                <select name="playing_level" required>
                    <option value="">Select level</option>
                    <option value="pro">MLS / Professional</option>
                    <option value="college_d1">NCAA Division 1</option>
                    <option value="college_d2">NCAA Division 2</option>
                    <option value="college_d3">NCAA Division 3</option>
                    <option value="academy">Academy / ECNL / MLS Next</option>
                    <option value="high_school">High School Varsity</option>
                    <option value="club">Club / Travel</option>
                </select>
                <label>Current Team / School</label>
                <input type="text" name="team" placeholder="e.g. Villanova">
                <div class="row">
                    <div><label>City *</label><input type="text" name="city" required></div>
                    <div><label>State *</label>
                        <select name="state" required>
                            <option value="">Select</option>
                            <option value="PA">PA</option>
                            <option value="NJ">NJ</option>
                            <option value="DE">DE</option>
                            <option value="MD">MD</option>
                            <option value="NY">NY</option>
                        </select>
                    </div>
                </div>
                <label>Bio (Optional)</label>
                <textarea name="bio" placeholder="Position, experience, coaching style..."></textarea>
            </div>
            
            <!-- Card 4: Agreement -->
            <div class="ptp-apply-card">
                <div class="ptp-apply-card-header">
                    <div class="ptp-apply-card-num">4</div>
                    <h3 class="ptp-apply-card-title">Agreement</h3>
                </div>
                <div class="contract-box">
                    <div class="contract-scroll">
                        <h3>Independent Contractor Agreement</h3>
                        <p style="font-size:10px;color:#666;margin-bottom:10px;">Between PTP - Players Teaching Players, LLC ("PTP") and You ("Trainer")</p>
                        
                        <h4>1. Relationship</h4>
                        <p>You agree to provide private soccer training services as an <strong>independent contractor</strong>, not an employee.</p>
                        
                        <h4>2. Platform Services</h4>
                        <p>PTP provides: booking system, payment processing, marketing, and insurance during sessions.</p>
                        
                        <h4>3. Responsibilities</h4>
                        <ul>
                            <li>Provide professional, safe training</li>
                            <li>Arrive on time and prepared</li>
                            <li>Cancel with 24+ hours notice</li>
                            <li>Never solicit clients off-platform</li>
                        </ul>
                        
                        <h4>4. Compensation</h4>
                        <p>You set your rate. Tiered commission: <strong>50%</strong> on first session with a new client (covers acquisition), then <strong>75%</strong> on repeat sessions. Weekly Stripe payouts.</p>
                        
                        <h4>5. Conduct</h4>
                        <ul>
                            <li>Appropriate behavior with minors</li>
                            <li>Train in open, visible areas</li>
                            <li>No substances before/during sessions</li>
                        </ul>
                        
                        <h4>6. Non-Solicitation</h4>
                        <p>12 months after last session, you agree not to solicit clients outside PTP.</p>
                        
                        <h4>7. Termination</h4>
                        <p>Either party may terminate. PTP may immediately terminate for violations.</p>
                    </div>
                    <div class="contract-signature">
                        <label class="contract-checkbox">
                            <input type="checkbox" name="agree_contract" id="agreeContract" value="1" required>
                            <span>I agree to the PTP Trainer Agreement</span>
                        </label>
                        <p class="contract-ip">IP (<?php echo esc_html($_SERVER['REMOTE_ADDR']); ?>) recorded</p>
                    </div>
                </div>
            </div>
            
            <button type="submit" id="submitBtn">Submit Application</button>
            <p class="note">Reviewed within 24-48 hours</p>
        </form>
        
        <div class="success" id="successMsg">
            <div class="success-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2>Application Received</h2>
            <p>We'll email you within 24-48 hours.<br>Once approved, log in with your password.</p>
        </div>
    </div>
    
</div><!-- .ptp-apply-wrapper -->

<script>
document.getElementById('applyForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('submitBtn');
    var err = document.getElementById('errorMsg');
    
    var pw = this.password.value;
    var pwConfirm = this.password_confirm.value;
    
    if (pw.length < 8) {
        err.textContent = 'Password must be at least 8 characters.';
        err.style.display = 'block';
        err.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    if (pw !== pwConfirm) {
        err.textContent = 'Passwords do not match.';
        err.style.display = 'block';
        err.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    var contractCheckbox = document.getElementById('agreeContract');
    if (!contractCheckbox.checked) {
        err.textContent = 'Please agree to the Trainer Agreement.';
        err.style.display = 'block';
        contractCheckbox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    
    err.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'SUBMITTING...';
    
    try {
        var res = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST',body:new FormData(this)});
        var data = await res.json();
        if (data.success) {
            // v134: Check for redirect URL (auto-approved)
            if (data.data && data.data.redirect) {
                btn.textContent = 'APPROVED! REDIRECTING...';
                window.location.href = data.data.redirect;
            } else {
                // Manual approval flow - show success message
                this.style.display = 'none';
                document.getElementById('successMsg').style.display = 'block';
                document.getElementById('successMsg').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            err.textContent = data.data?.message || 'Error. Please try again.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Submit Application';
        }
    } catch (e) {
        err.textContent = 'Network error. Please try again.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Submit Application';
    }
});

document.querySelector('.ptp-apply input[name="phone"]').addEventListener('input', function(e) {
    var x = e.target.value.replace(/\D/g, '').match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
    e.target.value = !x[2] ? x[1] : '(' + x[1] + ') ' + x[2] + (x[3] ? '-' + x[3] : '');
});
</script>

</div><!-- .ptp-apply-page -->

<script>
// v134: Force scroll to work - remove any blocking classes/styles
(function(){
    document.documentElement.style.cssText += 'overflow-y: auto !important; height: auto !important; position: static !important;';
    document.body.style.cssText += 'overflow-y: auto !important; height: auto !important; position: static !important;';
    document.body.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden', 'noscroll');
    document.documentElement.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden', 'noscroll');
    
    // Also handle any fixed position elements
    var page = document.querySelector('.ptp-apply-page');
    if (page) {
        page.style.cssText += 'overflow-y: auto !important; position: static !important;';
    }
})();
</script>

<?php get_footer(); ?>
