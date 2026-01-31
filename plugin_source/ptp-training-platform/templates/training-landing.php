<?php
/**
 * Training Landing v124 - Hero Image Added
 */
defined('ABSPATH') || exit;

global $wpdb;

// Get data
$trainer_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'") ?: 5;
$featured = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active' ORDER BY is_featured DESC, average_rating DESC, total_sessions DESC LIMIT 6");
$level_labels = array('pro'=>'MLS PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO');

get_header();
?>

<style>
/* ===========================================
   V117.2.20 TRAINING LANDING - COMPLETE RESET
   v133.2: Hide scrollbar
   =========================================== */

/* v133.2: Hide scrollbar */
html, body { scrollbar-width: none; -ms-overflow-style: none; }
html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }

/* Force full width */
.tl-wrap {
    width: 100vw !important;
    max-width: 100vw !important;
    margin-left: calc(-50vw + 50%) !important;
    margin-right: calc(-50vw + 50%) !important;
    overflow-x: hidden !important;
    background: #0A0A0A !important;
}

/* Kill ALL parent constraints */
body .tl-wrap,
#page .tl-wrap,
#content .tl-wrap,
#primary .tl-wrap,
.site-content .tl-wrap,
.content-area .tl-wrap,
main .tl-wrap,
article .tl-wrap,
.entry-content .tl-wrap,
.elementor .tl-wrap,
.ast-container .tl-wrap,
.container .tl-wrap {
    width: 100vw !important;
    max-width: none !important;
    padding: 0 !important;
    margin-left: calc(-50vw + 50%) !important;
}

/* Variables */
.tl-wrap {
    --gold: #FCB900;
    --black: #0A0A0A;
    --white: #FFFFFF;
    --gray: #F5F5F5;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.tl-wrap * {
    box-sizing: border-box;
}

/* ===========================================
   HERO SECTION
   =========================================== */
.tl-hero {
    background: linear-gradient(180deg, rgba(10,10,10,0.55) 0%, rgba(10,10,10,0.7) 100%), url('https://ptpsummercamps.com/wp-content/uploads/2024/09/IMG_8693-scaled.jpg');
    background-size: cover;
    background-position: center 40%;
    padding: 80px 20px 60px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 90vh;
    position: relative;
    margin-top: 0 !important;
}

.tl-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, rgba(252,185,0,0.08) 0%, transparent 70%);
    pointer-events: none;
}

/* Kill gaps from theme on training landing */
body .tl-wrap,
#content .tl-wrap,
.site-content .tl-wrap,
article .tl-wrap,
.entry-content .tl-wrap,
main .tl-wrap {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.tl-hero > * {
    position: relative;
    z-index: 2;
}

.tl-badge {
    display: inline-block;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding: 10px 24px;
    margin-bottom: 24px;
}

.tl-hero h1 {
    font-family: 'Oswald', sans-serif !important;
    font-size: 48px !important;
    font-weight: 700 !important;
    color: #FFFFFF !important;
    text-transform: uppercase;
    line-height: 1.1 !important;
    margin: 0 0 20px 0 !important;
    padding: 0 !important;
}

.tl-hero h1 em {
    display: block;
    color: var(--gold) !important;
    font-style: italic;
}

.tl-hero-sub {
    font-size: 17px;
    color: rgba(255,255,255,0.75);
    max-width: 550px;
    line-height: 1.7;
    margin: 0 auto 32px;
}

.tl-cta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--gold);
    color: var(--black) !important;
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 16px 40px;
    text-decoration: none !important;
    transition: transform 0.3s, box-shadow 0.3s;
}

.tl-cta:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(252,185,0,0.35);
    color: var(--black) !important;
}

/* Stats Row */
.tl-stats {
    display: flex;
    justify-content: center;
    gap: 40px;
    margin-top: 48px;
    flex-wrap: wrap;
}

.tl-stat {
    text-align: center;
}

.tl-stat-num {
    font-family: 'Oswald', sans-serif;
    font-size: 40px;
    font-weight: 700;
    color: var(--gold);
    line-height: 1;
}

.tl-stat-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,0.5);
    margin-top: 6px;
}

/* ===========================================
   SECTION BASE
   =========================================== */
.tl-section {
    padding: 80px 20px;
    width: 100%;
}

.tl-section-inner {
    max-width: 1100px;
    margin: 0 auto;
}

.tl-section-dark {
    background: #0A0A0A;
    color: #FFFFFF;
}

.tl-section-light {
    background: #F5F5F5;
    color: #0A0A0A;
}

.tl-section-title {
    font-family: 'Oswald', sans-serif !important;
    font-size: 32px !important;
    font-weight: 700 !important;
    text-transform: uppercase;
    text-align: center;
    margin: 0 0 48px 0 !important;
}

.tl-section-dark .tl-section-title {
    color: #FFFFFF !important;
}

.tl-section-light .tl-section-title {
    color: #0A0A0A !important;
}

.tl-section-title em {
    color: var(--gold) !important;
    font-style: italic;
}

/* ===========================================
   WHY PTP - FEATURES
   =========================================== */
.tl-features {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.tl-feature {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    padding: 36px 28px;
    text-align: center;
    transition: 0.3s;
}

.tl-feature:hover {
    border-color: var(--gold);
    transform: translateY(-6px);
}

.tl-feature-icon {
    width: 60px;
    height: 60px;
    background: var(--gold);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.tl-feature-icon svg {
    width: 28px;
    height: 28px;
    stroke: #0A0A0A;
    stroke-width: 2;
    fill: none;
}

.tl-feature h3 {
    font-family: 'Oswald', sans-serif !important;
    font-size: 18px !important;
    font-weight: 600 !important;
    text-transform: uppercase;
    color: #FFFFFF !important;
    margin: 0 0 12px 0 !important;
}

.tl-feature p {
    font-size: 14px;
    color: rgba(255,255,255,0.6);
    line-height: 1.7;
    margin: 0;
}

/* ===========================================
   TRAINERS GRID
   =========================================== */
.tl-trainers {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
}

.tl-trainer {
    background: #FFFFFF;
    overflow: hidden;
    text-decoration: none !important;
    transition: 0.3s;
    border: 2px solid transparent;
}

.tl-trainer:hover {
    border-color: var(--gold);
    transform: translateY(-6px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}

.tl-trainer-img {
    aspect-ratio: 1;
    overflow: hidden;
    position: relative;
}

.tl-trainer-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: 0.4s;
}

.tl-trainer:hover .tl-trainer-img img {
    transform: scale(1.08);
}

.tl-trainer-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: var(--gold);
    color: #0A0A0A;
    font-family: 'Oswald', sans-serif;
    font-size: 9px;
    font-weight: 600;
    padding: 4px 8px;
    letter-spacing: 0.5px;
}

.tl-trainer-info {
    padding: 12px 10px;
    text-align: center;
}

.tl-trainer-name {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: #0A0A0A;
    text-transform: uppercase;
}

/* ===========================================
   HOW IT WORKS - STEPS
   =========================================== */
.tl-steps {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 40px;
    counter-reset: step;
}

.tl-step {
    text-align: center;
}

.tl-step::before {
    counter-increment: step;
    content: counter(step);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: var(--gold);
    color: #0A0A0A;
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    font-weight: 700;
    margin: 0 auto 20px;
}

.tl-step h3 {
    font-family: 'Oswald', sans-serif !important;
    font-size: 18px !important;
    font-weight: 600 !important;
    text-transform: uppercase;
    color: #0A0A0A !important;
    margin: 0 0 10px 0 !important;
}

.tl-step p {
    font-size: 14px;
    color: #666;
    line-height: 1.7;
    margin: 0;
}

/* ===========================================
   FINAL CTA
   =========================================== */
.tl-final-cta {
    background: var(--gold);
    padding: 70px 20px;
    text-align: center;
}

.tl-final-cta h2 {
    font-family: 'Oswald', sans-serif !important;
    font-size: 36px !important;
    font-weight: 700 !important;
    color: #0A0A0A !important;
    text-transform: uppercase;
    margin: 0 0 16px 0 !important;
}

.tl-final-cta p {
    font-size: 17px;
    color: rgba(0,0,0,0.7);
    margin: 0 auto 28px;
    max-width: 450px;
}

.tl-final-btn {
    display: inline-flex;
    background: #0A0A0A;
    color: #FFFFFF !important;
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 16px 40px;
    text-decoration: none !important;
    transition: 0.3s;
}

.tl-final-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.25);
    color: #FFFFFF !important;
}

/* ===========================================
   RESPONSIVE
   =========================================== */
@media (max-width: 900px) {
    .tl-features {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .tl-trainers {
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }
    .tl-steps {
        grid-template-columns: 1fr;
        gap: 32px;
    }
}

@media (max-width: 600px) {
    .tl-hero {
        padding: 60px 16px 50px;
        min-height: auto;
    }
    .tl-hero h1 {
        font-size: 36px !important;
    }
    .tl-hero-sub {
        font-size: 15px;
    }
    .tl-cta {
        padding: 14px 32px;
        font-size: 15px;
    }
    .tl-stats {
        gap: 24px;
    }
    .tl-stat-num {
        font-size: 32px;
    }
    .tl-section {
        padding: 50px 16px;
    }
    .tl-section-title {
        font-size: 26px !important;
        margin-bottom: 32px !important;
    }
    .tl-trainers {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .tl-trainer-name {
        font-size: 12px;
    }
    .tl-final-cta {
        padding: 50px 16px;
    }
    .tl-final-cta h2 {
        font-size: 28px !important;
    }
}
</style>

<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

<div class="tl-wrap">

    <!-- HERO -->
    <section class="tl-hero">
        <span class="tl-badge">Now Booking 2025</span>
        <h1>Private Soccer<em>Training</em></h1>
        <p class="tl-hero-sub">Train 1-on-1 with verified MLS players and NCAA Division 1 athletes. We teach what team coaches don't have time to.</p>
        <a href="<?php echo home_url('/find-trainers/'); ?>" class="tl-cta">Find Your Trainer →</a>
        <div class="tl-stats">
            <div class="tl-stat">
                <div class="tl-stat-num"><?php echo $trainer_count; ?>+</div>
                <div class="tl-stat-label">Pro Trainers</div>
            </div>
            <div class="tl-stat">
                <div class="tl-stat-num">5</div>
                <div class="tl-stat-label">States</div>
            </div>
            <div class="tl-stat">
                <div class="tl-stat-num">4.9</div>
                <div class="tl-stat-label">Avg Rating</div>
            </div>
            <div class="tl-stat">
                <div class="tl-stat-num">2,300+</div>
                <div class="tl-stat-label">Families</div>
            </div>
        </div>
    </section>

    <!-- WHY PTP -->
    <section class="tl-section tl-section-dark">
        <div class="tl-section-inner">
            <h2 class="tl-section-title">Why Choose <em>PTP</em></h2>
            <div class="tl-features">
                <div class="tl-feature">
                    <div class="tl-feature-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <h3>Verified Pros</h3>
                    <p>Every trainer is background checked and SafeSport certified. Train with confidence.</p>
                </div>
                <div class="tl-feature">
                    <div class="tl-feature-icon">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    </div>
                    <h3>Flexible Scheduling</h3>
                    <p>Book sessions that fit your schedule. Morning, evening, weekends—your call.</p>
                </div>
                <div class="tl-feature">
                    <div class="tl-feature-icon">
                        <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <h3>Real Results</h3>
                    <p>Individual skills that team coaches don't have time to teach. See real improvement.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURED TRAINERS -->
    <section class="tl-section tl-section-light">
        <div class="tl-section-inner">
            <h2 class="tl-section-title">Meet Our <em>Trainers</em></h2>
            <div class="tl-trainers">
                <?php foreach($featured as $t): 
                    $photo = $t->photo_url ?: 'https://ui-avatars.com/api/?name='.urlencode($t->display_name).'&size=300&background=FCB900&color=0A0A0A&bold=true';
                    $level = $level_labels[$t->playing_level] ?? 'TRAINER';
                    $slug = $t->slug ?: sanitize_title($t->display_name);
                ?>
                <a href="<?php echo home_url('/trainer/' . $slug . '/'); ?>" class="tl-trainer">
                    <div class="tl-trainer-img">
                        <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($t->display_name); ?>" loading="lazy">
                        <span class="tl-trainer-badge"><?php echo esc_html($level); ?></span>
                    </div>
                    <div class="tl-trainer-info">
                        <div class="tl-trainer-name"><?php echo esc_html($t->display_name); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section class="tl-section tl-section-light" style="padding-top:0;">
        <div class="tl-section-inner">
            <h2 class="tl-section-title">How It <em>Works</em></h2>
            <div class="tl-steps">
                <div class="tl-step">
                    <h3>Browse Trainers</h3>
                    <p>Search by location. View profiles, credentials, and reviews from other families.</p>
                </div>
                <div class="tl-step">
                    <h3>Book Online</h3>
                    <p>Pick your date, time, and location. Pay securely. No contracts required.</p>
                </div>
                <div class="tl-step">
                    <h3>Train & Improve</h3>
                    <p>Meet your trainer at the field. Get personalized coaching for your player.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FINAL CTA -->
    <section class="tl-final-cta">
        <h2>Ready to Level Up?</h2>
        <p>Join 2,300+ families who trust PTP for private soccer training.</p>
        <a href="<?php echo home_url('/find-trainers/'); ?>" class="tl-final-btn">Find Your Trainer →</a>
    </section>

</div>

<?php get_footer(); ?>
