<?php
/**
 * Find Trainers v132.7 - Desktop Scroll Fix
 * Features: Skeleton loading states, spring animations, better touch feedback,
 * improved card design, haptic-style interactions, ZERO gap at top,
 * compact desktop cards, hidden map X on desktop,
 * FIXED: Desktop scrolling - account for header height with CSS variable,
 * removed overflow:hidden from ft-main, JS measures actual header
 * 
 * @version 132.7.0
 */
defined('ABSPATH') || exit;

global $wpdb;
$google_maps_key = get_option('ptp_google_maps_api_key', '');
$level_labels = array(
    'pro' => 'MLS PRO',
    'college_d1' => 'NCAA D1',
    'college_d2' => 'NCAA D2',
    'college_d3' => 'NCAA D3',
    'academy' => 'ACADEMY',
    'semi_pro' => 'SEMI-PRO'
);

// SEO: Get location from URL if present
$location_slug = get_query_var('trainer_location', '');
$seo_location = '';
$seo_state = '';
if ($location_slug) {
    $location_map = array(
        'philadelphia' => array('Philadelphia', 'PA'),
        'cherry-hill' => array('Cherry Hill', 'NJ'),
        'wilmington' => array('Wilmington', 'DE'),
        'baltimore' => array('Baltimore', 'MD'),
        'new-york' => array('New York', 'NY'),
        'princeton' => array('Princeton', 'NJ'),
        'wayne' => array('Wayne', 'PA'),
        'media' => array('Media', 'PA'),
        'newtown' => array('Newtown', 'PA'),
        'doylestown' => array('Doylestown', 'PA'),
        'king-of-prussia' => array('King of Prussia', 'PA'),
        'west-chester' => array('West Chester', 'PA'),
        'malvern' => array('Malvern', 'PA'),
        'radnor' => array('Radnor', 'PA'),
        'villanova' => array('Villanova', 'PA'),
        'ardmore' => array('Ardmore', 'PA'),
        'bryn-mawr' => array('Bryn Mawr', 'PA'),
        'haverford' => array('Haverford', 'PA'),
        'conshohocken' => array('Conshohocken', 'PA'),
    );
    if (isset($location_map[$location_slug])) {
        $seo_location = $location_map[$location_slug][0];
        $seo_state = $location_map[$location_slug][1];
    }
}

$trainers = $wpdb->get_results("
    SELECT t.*, 
           COALESCE(t.average_rating, 5.0) as avg_rating,
           COALESCE(t.review_count, 0) as reviews,
           COALESCE(t.total_sessions, 0) as sessions
    FROM {$wpdb->prefix}ptp_trainers t
    WHERE t.status = 'active'
    ORDER BY t.is_featured DESC, t.sort_order ASC, t.average_rating DESC
");
$count = count($trainers);

$page_title = $seo_location 
    ? "Soccer Trainers in {$seo_location}, {$seo_state} | PTP Soccer" 
    : "Find Soccer Trainers Near You | PTP Soccer";
$page_desc = $seo_location
    ? "Book private soccer training sessions with verified coaches in {$seo_location}, {$seo_state}. MLS pros, NCAA players, and elite trainers available."
    : "Find and book private soccer training with {$count}+ verified coaches across PA, NJ, DE, MD & NY.";

get_header();
?>
<style>
/* =============================================
   v125: CLEAN GAP FIX - Single source of truth
   v130: Mouse scroll fix + distance display
   v132.6: Internal scrolling approach
   v133.2: Hide scrollbar
   ============================================= */

/* v133.2: Hide scrollbar globally */
html,body{scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0}

/* v134.1: DESKTOP - Force scroll to work */
@media(min-width:1024px){
    html,body{
        overflow-y:scroll !important;
        overflow-x:hidden !important;
        height:auto !important;
        min-height:100% !important;
        position:static !important;
    }
}

/* v132.6: Prevent scroll-blocking classes from affecting page */
html.menu-open,
html.modal-open,
html.ptp-drawer-open,
html.no-scroll {
    overflow: visible !important;
    position: static !important;
    height: auto !important;
}
body.menu-open,
body.modal-open,
body.ptp-drawer-open,
body.no-scroll {
    overflow: visible !important;
    position: static !important;
    height: auto !important;
}

/* Hide any PTP plugin header on this page - use theme header only */
.ptp-header,
header.ptp-header,
#ptpHeader,
.ptp-bottom-nav,
.ptp-mobile-nav,
.ptp-mobile-nav-overlay {
    display: none !important;
}

/* Reset ALL spacing between theme header and content */
* { box-sizing: border-box; }

#page, #content, #primary, .site, .site-content, .content-area,
main, main.site-main, article, .hentry, .entry-content, .post-content,
.page-content, .ast-container, .ast-row, .container, .site-main, #main,
.wp-block-post-content, .is-layout-constrained, .is-layout-flow,
.elementor-widget-container, .ast-article-single, .ast-article-post,
.ast-separate-container, .ast-plain-container, .ast-page-builder-template {
    margin: 0 !important;
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    /* v134.1: Allow full height */
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
}

/* WordPress block spacing overrides */
.is-layout-constrained > * + *, .is-layout-flow > * + * {
    margin-block-start: 0 !important;
}

/* Astra specific */
.ast-separate-container #primary, .ast-plain-container #primary,
.ast-page-builder-template #primary, .ast-single-post #primary {
    margin: 0 !important; padding: 0 !important;
}

/* The main container */
.ft {
    position: relative;
    z-index: 1;
    margin: 0 !important;
    padding: 0 !important;
    --header-height: 70px; /* Default, adjusted per breakpoint */
}

/* Target this page specifically */
body.page-template-trainers-grid #content,
body.page-template-trainers-grid #primary,
body.page-template-trainers-grid .site-content,
body.page-template-trainers-grid .content-area,
body.page-template-trainers-grid main,
body.page-template-trainers-grid article,
body.page-template-trainers-grid .entry-content,
body.page-template-trainers-grid .post-content,
body.page-template-trainers-grid .ast-container,
body.page-template-trainers-grid .container,
body.page-template-trainers-grid .site-main,
body.page-template-trainers-grid #main,
body.page-template-trainers-grid .elementor-widget-container,
body.page-template-trainers-grid .elementor-element,
body.page-template-trainers-grid .wp-block-post-content,
body.page-template-trainers-grid .is-layout-constrained > *,
body.page-template-trainers-grid .is-layout-flow > * {
    margin-top: 0 !important;
    padding-top: 0 !important;
    margin-block-start: 0 !important;
}

/* Also target page without template class (generic) */
body[class*="find-trainers"] #content,
body[class*="find-trainers"] .site-content,
body[class*="find-trainers"] article,
body[class*="find-trainers"] .entry-content,
body[class*="trainers"] #content,
body[class*="trainers"] .site-content,
body[class*="trainers"] article,
body[class*="trainers"] .entry-content {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Astra theme specific */
.ast-separate-container #primary,
.ast-separate-container .ast-article-single,
.ast-plain-container #primary {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Elementor specific - target the content after header */
.elementor-location-header + *,
.elementor-location-header + * > *,
.elementor-location-header ~ #content,
.elementor-location-header ~ .site-content,
.elementor-location-header ~ main,
header + #content,
header + .site-content,
header + main,
#masthead + #content,
#masthead + .site-content,
#masthead + main {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* WordPress block editor gap */
.is-layout-constrained > * + *,
.is-layout-flow > * + * {
    margin-block-start: 0 !important;
}

/* Protect site header from PTP styles */
body > header:not(.ptp-header), #masthead, .site-header, header.header, .elementor-location-header { all: revert !important; }

/* v92 Enhanced Mobile */
.ft *{box-sizing:border-box}
.ft{
    --gold:#FCB900;--gold-light:rgba(252,185,0,0.1);--gold-glow:rgba(252,185,0,0.3);
    --black:#0A0A0A;--gray:#F5F5F5;--gray2:#E5E7EB;--gray3:#6B7280;--green:#22C55E;
    --r:12px;--r-lg:16px;
    --shadow-sm:0 1px 2px rgba(0,0,0,0.04);--shadow-md:0 4px 12px rgba(0,0,0,0.08);--shadow-lg:0 12px 32px rgba(0,0,0,0.12);
    --safe-bottom:env(safe-area-inset-bottom,0px);
    --ease-spring:cubic-bezier(0.34,1.56,0.64,1);
    font-family:'Inter',-apple-system,sans-serif;background:#fff;min-height:100vh;
    display:flex;flex-direction:column;
    -webkit-tap-highlight-color:transparent;
    margin-top:0 !important;
    padding-top:0 !important;
}
.ft h1,.ft h2,.ft h3{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;margin:0}

/* Skeleton Loading */
@keyframes ft-shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
.ft-skeleton{background:linear-gradient(90deg,var(--gray) 25%,var(--gray2) 50%,var(--gray) 75%);background-size:200% 100%;animation:ft-shimmer 1.5s infinite;border-radius:var(--r)}
.ft-skeleton-card{border-radius:var(--r-lg);overflow:hidden;background:#fff}
.ft-skeleton-card .ft-skeleton-img{aspect-ratio:1;border-radius:0}
.ft-skeleton-card .ft-skeleton-body{padding:14px}
.ft-skeleton-card .ft-skeleton-line{height:14px;margin-bottom:8px;border-radius:4px}
.ft-skeleton-card .ft-skeleton-line.short{width:60%}
.ft-skeleton-card .ft-skeleton-line.medium{width:80%}

.ft-hero{
    background:linear-gradient(to bottom, rgba(10,10,10,0.55) 0%, rgba(10,10,10,0.7) 100%), url('https://ptpsummercamps.com/wp-content/uploads/2025/12/winning-camper-1.jpg');
    background-size:cover;
    background-position:center 25%;
    padding:48px 20px 48px;
    text-align:center;
    margin-top:-1px !important; /* Pull up to close any gap */
    position:relative;
}
/* Force hero flush with header - aggressive override */
body .ft-hero,
#content .ft-hero,
article .ft-hero,
.entry-content .ft-hero,
main .ft-hero {
    margin-top: -1px !important;
    padding-top: 48px !important;
}
.ft-hero h1{font-size:36px;color:#fff;margin-bottom:10px;text-shadow:0 2px 20px rgba(0,0,0,.5)}
.ft-hero h1 span{color:var(--gold)}
.ft-hero p{color:rgba(255,255,255,.9);font-size:15px;margin:0 0 24px;text-shadow:0 1px 10px rgba(0,0,0,.5)}
.ft-hero-stats{display:flex;justify-content:center;gap:32px;margin-bottom:24px}
.ft-hero-stat{text-align:center}
.ft-hero-stat-num{font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:var(--gold);text-shadow:0 2px 10px rgba(0,0,0,.3)}
.ft-hero-stat-label{font-size:11px;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px}

.ft-search{max-width:560px;margin:0 auto 20px;display:flex;background:#fff;border-radius:var(--r);overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.35)}
.ft-search input{flex:1;border:none;padding:14px 16px;font-size:15px}
.ft-search input:focus{outline:none}
.ft-search button{background:var(--gold);color:var(--black);border:none;padding:14px 20px;font-family:Oswald,sans-serif;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;min-height:48px;-webkit-tap-highlight-color:transparent;transition:all .2s}
.ft-search button:hover{background:#e5a800}
.ft-search button:active{transform:scale(0.96);background:#d4a000}
.ft-search button svg{width:16px;height:16px}
.ft-locate{background:#f5f5f5;border:none;border-right:1px solid #e5e7eb;padding:14px;cursor:pointer;color:var(--gray3);transition:.2s;min-width:48px;min-height:48px;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
.ft-locate:hover{color:var(--gold);background:#fff}
.ft-locate:active{background:#e5e5e5;transform:scale(0.95)}
.ft-locate.loading{animation:pulse 1s infinite}
.ft-locate svg{width:20px;height:20px}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

.ft-filters{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.ft-pill{padding:10px 18px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:50px;color:rgba(255,255,255,.9);font-size:12px;font-weight:500;cursor:pointer;transition:all .2s var(--ease-spring);backdrop-filter:blur(8px);min-height:44px;display:inline-flex;align-items:center;-webkit-tap-highlight-color:transparent}
.ft-pill:active{transform:scale(0.95)}
.ft-pill.on{background:var(--gold);border-color:var(--gold);color:var(--black)}

.ft-loc-notice{background:rgba(34,197,94,.2);border:1px solid rgba(34,197,94,.4);border-radius:8px;padding:8px 14px;color:#fff;font-size:12px;margin-top:16px;display:inline-flex;align-items:center;gap:6px;backdrop-filter:blur(8px)}
.ft-loc-notice svg{width:14px;height:14px;stroke:var(--green)}

.ft-main{display:flex;flex-direction:column;flex:1;min-height:0;overflow-y:auto;overflow-x:hidden}

.ft-list{padding:20px;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;scroll-behavior:smooth}
.ft-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.ft-count{font-size:13px;color:var(--gray3)}
.ft-count b{color:var(--black)}
.ft-sort select{padding:10px 14px;border:2px solid var(--gray2);border-radius:var(--r);font-size:13px;background:#fff;min-height:44px}

/* Trainer Grid - Mobile First */
.ft-grid{display:grid;grid-template-columns:1fr;gap:16px}

/* Two columns on wider phones */
@media(min-width:420px){.ft-grid{grid-template-columns:repeat(2,1fr);gap:14px}}

/* Enhanced Trainer Cards */
.ft-card{
    background:#fff;border-radius:var(--r-lg);overflow:hidden;
    border:2px solid var(--gray2);
    transition:all .2s var(--ease-spring);
    text-decoration:none;color:inherit;display:block;
    box-shadow:var(--shadow-sm);
    position:relative;
}
.ft-card:active{transform:scale(0.98);border-color:var(--gold)}
@media(hover:hover){
    .ft-card:hover{border-color:var(--gold);transform:translateY(-3px);box-shadow:var(--shadow-lg)}
    .ft-card:hover img{transform:scale(1.05)}
}

/* Supercoach Card - Full Highlight */
.ft-supercoach{
    border:3px solid var(--gold) !important;
    background:linear-gradient(180deg, #FFFBEB 0%, #FFF 30%);
    box-shadow:0 4px 20px rgba(252,185,0,0.3);
}
@media(hover:hover){
    .ft-supercoach:hover{
        box-shadow:0 8px 30px rgba(252,185,0,0.4);
        transform:translateY(-5px);
    }
}
.ft-supercoach-ribbon{
    position:absolute;
    top:0;left:0;right:0;
    background:linear-gradient(90deg, var(--gold) 0%, #F59E0B 100%);
    color:var(--black);
    font-family:'Oswald',sans-serif;
    font-size:11px;
    font-weight:700;
    text-align:center;
    padding:6px 12px;
    letter-spacing:0.1em;
    z-index:10;
}
@media(min-width:420px){
    .ft-supercoach-ribbon{font-size:10px;padding:5px 10px;}
}

/* Card Image - Taller on mobile single column */
.ft-card-img{aspect-ratio:3/4;background:var(--gray);position:relative;overflow:hidden}
@media(min-width:420px){.ft-card-img{aspect-ratio:1}}
.ft-card-img img{width:100%;height:100%;object-fit:cover;object-position:center top;transition:transform .4s var(--ease-spring)}
.ft-supercoach .ft-card-img{padding-top:28px}
@media(min-width:420px){.ft-supercoach .ft-card-img{padding-top:24px}}
.ft-card-tag{position:absolute;top:10px;left:10px;padding:5px 10px;background:var(--gold);color:var(--black);font-family:'Oswald',sans-serif;font-size:10px;font-weight:600;border-radius:6px;letter-spacing:.02em}
.ft-supercoach .ft-card-tag{top:38px}
@media(min-width:420px){.ft-supercoach .ft-card-tag{top:34px}}
.ft-card-badges{position:absolute;top:10px;right:10px;display:flex;gap:5px}
.ft-supercoach .ft-card-badges{top:38px}
@media(min-width:420px){.ft-supercoach .ft-card-badges{top:34px}}
.ft-badge{width:26px;height:26px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-md)}
.ft-badge svg{width:12px;height:12px}
.ft-badge.v svg{stroke:var(--green)}
.ft-badge.f svg{fill:var(--gold);stroke:var(--gold)}

/* Card Body - Bigger text on mobile */
.ft-card-body{padding:16px}
@media(min-width:420px){.ft-card-body{padding:14px}}
.ft-card-name{font-family:'Oswald',sans-serif;font-size:18px;font-weight:600;margin-bottom:6px;letter-spacing:.02em;text-transform:uppercase}
@media(min-width:420px){.ft-card-name{font-size:14px;margin-bottom:4px}}
.ft-card-loc{font-size:13px;color:var(--gray3);margin-bottom:14px;display:flex;align-items:center;gap:4px}
@media(min-width:420px){.ft-card-loc{font-size:11px;margin-bottom:12px}}
.ft-card-meta{display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--gray2)}
.ft-card-rate{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700}
@media(min-width:420px){.ft-card-rate{font-size:18px}}
.ft-card-rate small{font-size:11px;color:var(--gray3);font-weight:400;font-family:'Inter',sans-serif}
.ft-card-stars{display:flex;align-items:center;gap:4px;font-size:15px;font-weight:600;color:var(--gold)}
@media(min-width:420px){.ft-card-stars{font-size:13px}}
.ft-card-stars span{color:var(--gray3);font-weight:400;font-size:11px}

.ft-map-wrap{background:var(--gray2);min-height:300px;display:none;overflow:hidden;border-radius:0}
#map{width:100%;height:100%;min-height:300px}

/* Mobile mini-map - shows above grid */
.ft-mobile-map{display:none;margin-bottom:16px;border-radius:12px;overflow:hidden;border:2px solid var(--gray2)}
.ft-mobile-map #mobileMap{height:180px;width:100%}
@media(max-width:1023px){
    .ft-mobile-map{display:block}
}
@media(min-width:1024px){
    .ft-mobile-map{display:none !important}
}

.ft-map-toggle{position:fixed;bottom:calc(20px + var(--safe-bottom));left:50%;transform:translateX(-50%);z-index:100;background:var(--black);color:#fff;padding:14px 28px;border-radius:0;font-family:Oswald,sans-serif;font-size:13px;font-weight:600;border:2px solid var(--black);box-shadow:0 4px 20px rgba(0,0,0,.3);display:flex;align-items:center;gap:8px;cursor:pointer;min-height:52px;-webkit-tap-highlight-color:transparent;transition:all .2s}
.ft-map-toggle:active{transform:translateX(-50%) scale(0.95);background:var(--gold);color:var(--black);border-color:var(--gold)}
.ft-map-toggle svg{width:18px;height:18px}

.ft-empty{text-align:center;padding:40px 20px;grid-column:1/-1}
.ft-empty h3{font-size:16px;margin-bottom:6px}
.ft-empty p{color:var(--gray3);font-size:13px}

/* Mobile map overlay */
@media(max-width:1023px){
    .ft-map-wrap.on{display:block;position:fixed;top:0;left:0;right:0;bottom:0;z-index:200}
    .ft-map-close{position:absolute;top:16px;right:16px;z-index:201;background:#fff;border:none;width:40px;height:40px;border-radius:50%;box-shadow:0 2px 12px rgba(0,0,0,.2);cursor:pointer;font-size:18px;display:none;align-items:center;justify-content:center}
    .ft-map-wrap.on .ft-map-close{display:flex}
}

/* Hide map close button on desktop - map is always visible */
@media(min-width:1024px){
    .ft-map-close{display:none !important}
}

/* Desktop: Side-by-side layout with internal scroll */
@media(min-width:1024px){
    /* v134.1: Full page scroll with sticky map sidebar */
    .ft{
        --header-height:76px;
        min-height:100vh;
        height:auto !important;
        max-height:none !important;
        display:block !important;
        overflow:visible !important;
    }
    
    .ft-hero{padding:48px 32px 40px}
    .ft-hero h1{font-size:44px;margin-bottom:10px}
    .ft-hero p{font-size:15px;margin-bottom:24px}
    .ft-search{max-width:560px}
    .ft-search input{padding:16px 18px;font-size:15px}
    .ft-search button{padding:16px 26px;font-size:14px}
    .ft-pill{padding:10px 20px;font-size:13px}
    
    /* v134.1: Two-column layout - trainers scroll, map stays visible */
    .ft-main{
        display:flex !important;
        flex-direction:row;
        max-width:1800px;
        margin:0 auto;
        align-items:flex-start;
        min-height:800px;
        height:auto !important;
        overflow:visible !important;
    }
    .ft-list{
        width:55%;
        padding:28px;
        overflow:visible !important;
        height:auto !important;
        min-height:800px;
    }
    .ft-map-wrap{
        display:block !important;
        width:45%;
        position:sticky;
        top:80px;
        height:calc(100vh - 100px);
        min-height:600px;
        border-radius:0;
    }
    #map{min-height:100%;height:100%}
    .ft-map-toggle{display:none}
    
    /* v134: BIGGER cards on desktop - less squished */
    .ft-grid{grid-template-columns:repeat(2,1fr);gap:20px}
    .ft-card-img{aspect-ratio:4/5} /* Taller images */
    .ft-card-body{padding:18px 20px}
    .ft-card-name{font-size:18px;margin-bottom:6px}
    .ft-card-loc{font-size:13px;margin-bottom:16px}
    .ft-card-meta{padding-top:14px}
    .ft-card-rate{font-size:22px}
    .ft-card-rate small{font-size:12px}
    .ft-card-stars{font-size:16px}
    .ft-card-tag{padding:6px 12px;font-size:11px;top:12px;left:12px}
    .ft-badge{width:28px;height:28px}
    .ft-badge svg{width:12px;height:12px}
    
    /* Supercoach ribbon bigger */
    .ft-supercoach-ribbon{font-size:11px;padding:8px 14px}
    .ft-supercoach .ft-card-tag{top:42px}
    .ft-supercoach .ft-card-badges{top:42px}
    .ft-supercoach .ft-card-img{padding-top:32px}
}

@media(min-width:1280px){
    .ft-list{width:50%;padding:32px}
    .ft-map-wrap{width:50%}
    .ft-grid{grid-template-columns:repeat(2,1fr);gap:24px}
    /* Keep cards big at this size */
    .ft-card-img{aspect-ratio:4/5}
}

@media(min-width:1536px){
    .ft-grid{grid-template-columns:repeat(3,1fr);gap:24px}
    .ft-card-img{aspect-ratio:4/5} /* Stay tall even with 3 columns */
    .ft-card-body{padding:20px 22px}
    .ft-card-name{font-size:20px}
    .ft-card-loc{font-size:14px}
    .ft-card-rate{font-size:24px}
}
</style>

<div class="ft">
<section class="ft-hero">
    <h1><?php echo $seo_location ? "TRAINERS IN <span>{$seo_location}</span>" : "FIND YOUR <span>TRAINER</span>"; ?></h1>
    <p>Private soccer training with verified pros across PA, NJ, DE, MD & NY</p>
    <div class="ft-hero-stats">
        <div class="ft-hero-stat">
            <div class="ft-hero-stat-num">2,300+</div>
            <div class="ft-hero-stat-label">Families Served</div>
        </div>
        <div class="ft-hero-stat">
            <div class="ft-hero-stat-num"><?php echo $count; ?>+</div>
            <div class="ft-hero-stat-label">Verified Trainers</div>
        </div>
        <div class="ft-hero-stat">
            <div class="ft-hero-stat-num">5</div>
            <div class="ft-hero-stat-label">States Covered</div>
        </div>
    </div>
    <div class="ft-search">
        <button type="button" class="ft-locate" id="locateBtn" title="Use my location">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>
        </button>
        <input type="text" id="locInput" placeholder="Enter city or zip code..." value="<?php echo esc_attr($seo_location); ?>">
        <button id="searchBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            Search
        </button>
    </div>
    <div class="ft-filters">
        <span class="ft-pill on" data-lv="">All Levels</span>
        <span class="ft-pill" data-lv="pro">MLS Pro</span>
        <span class="ft-pill" data-lv="college_d1">NCAA D1</span>
        <span class="ft-pill" data-lv="college_d2">D2/D3</span>
        <span class="ft-pill" data-lv="academy">Academy</span>
    </div>
    <div class="ft-loc-notice" id="locNotice" style="display:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span id="locText">Showing trainers near you</span>
    </div>
</section>

<main class="ft-main">
    <div class="ft-list">
        <div class="ft-bar">
            <div class="ft-count">Showing <b id="cnt"><?php echo $count; ?></b> trainers</div>
            <div class="ft-sort">
                <select id="sortSel">
                    <option value="featured">Featured</option>
                    <option value="distance">Nearest</option>
                    <option value="rating">Highest Rated</option>
                    <option value="price_low">Price: Low→High</option>
                    <option value="price_high">Price: High→Low</option>
                </select>
            </div>
        </div>
        
        <?php if($google_maps_key): ?>
        <div class="ft-mobile-map" id="mobileMapWrap">
            <div id="mobileMap"></div>
        </div>
        <?php endif; ?>
        
        <div class="ft-grid" id="grid"></div>
    </div>
    
    <?php if($google_maps_key): ?>
    <div class="ft-map-wrap" id="mapWrap">
        <button class="ft-map-close" id="mapClose">✕</button>
        <div id="map"></div>
    </div>
    <?php endif; ?>
</main>

<button class="ft-map-toggle" id="mapToggle">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    View Map
</button>
</div>

<?php if($google_maps_key): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&libraries=places&callback=initMap" async defer></script>
<?php endif; ?>
<script>
(function(){
var data=<?php echo json_encode(array_map(function($t) use ($level_labels) {
    // Parse training locations and extract first valid coords for map
    $training_locs = array();
    $map_lat = floatval($t->latitude ?: 0);
    $map_lng = floatval($t->longitude ?: 0);
    $primary_training_location = '';
    
    if (!empty($t->training_locations)) {
        $locs = json_decode($t->training_locations, true);
        if (is_array($locs)) {
            $training_locs = $locs;
            // Use first training location with coordinates for map marker
            foreach ($locs as $loc) {
                // Store first location name
                if (empty($primary_training_location) && !empty($loc['name'])) {
                    $primary_training_location = $loc['name'];
                }
                if (!empty($loc['address'])) {
                    if (empty($primary_training_location)) {
                        $primary_training_location = $loc['address'];
                    }
                }
                // If this location has coords, use it for the map marker
                if (!empty($loc['lat']) && !empty($loc['lng'])) {
                    $map_lat = floatval($loc['lat']);
                    $map_lng = floatval($loc['lng']);
                    break; // Use first location with coords
                }
            }
        }
    }
    
    return array(
        'id'=>$t->id,'name'=>$t->display_name,
        'slug'=>$t->slug?:sanitize_title($t->display_name),
        'photo'=>$t->photo_url?:'https://ui-avatars.com/api/?name='.urlencode($t->display_name).'&size=400&background=FCB900&color=0A0A0A&bold=true',
        'level'=>$t->playing_level,
        'tag'=>$level_labels[$t->playing_level]??strtoupper($t->playing_level?:'PRO'),
        'rate'=>intval($t->hourly_rate?:60),
        'rating'=>floatval($t->avg_rating?:5),
        'reviews'=>intval($t->reviews),
        'city'=>$t->city?:'','state'=>$t->state?:'',
        // Use training location coords if available, otherwise home location
        'lat'=>$map_lat,'lng'=>$map_lng,
        'home_lat'=>floatval($t->latitude?:0),'home_lng'=>floatval($t->longitude?:0),
        'training_locations'=>$training_locs,
        'primary_location'=>$primary_training_location,
        'featured'=>intval($t->is_featured?:0),'verified'=>intval($t->is_verified?:0),
        // Supercoach: 4.9+ rating with 10+ reviews
        'supercoach'=>(floatval($t->avg_rating?:0) >= 4.9 && intval($t->reviews) >= 10) ? 1 : 0,
        'distance'=>null
    );
}, $trainers)); ?>;

// Default coordinates for cities (fallback when trainer has no lat/lng)
var cityCoords = {
    'philadelphia,pa': {lat: 39.9526, lng: -75.1652},
    'philadelphia area,pa': {lat: 39.9526, lng: -75.1652},
    'cherry hill,nj': {lat: 39.9346, lng: -74.9981},
    'wilmington,de': {lat: 39.7391, lng: -75.5398},
    'baltimore,md': {lat: 39.2904, lng: -76.6122},
    'new york,ny': {lat: 40.7128, lng: -74.0060},
    'princeton,nj': {lat: 40.3573, lng: -74.6672},
    'wayne,pa': {lat: 40.0440, lng: -75.3877},
    'media,pa': {lat: 39.9168, lng: -75.3877},
    'newtown,pa': {lat: 40.2293, lng: -74.9368},
    'doylestown,pa': {lat: 40.3101, lng: -75.1299},
    'king of prussia,pa': {lat: 40.0893, lng: -75.3963},
    'west chester,pa': {lat: 39.9607, lng: -75.6055},
    'malvern,pa': {lat: 40.0362, lng: -75.5138},
    'radnor,pa': {lat: 40.0462, lng: -75.3599},
    'villanova,pa': {lat: 40.0388, lng: -75.3463},
    'ardmore,pa': {lat: 40.0065, lng: -75.2913},
    'bryn mawr,pa': {lat: 40.0220, lng: -75.3163},
    'haverford,pa': {lat: 40.0093, lng: -75.3049},
    'conshohocken,pa': {lat: 40.0793, lng: -75.3016},
    'trenton,nj': {lat: 40.2206, lng: -74.7597},
    'camden,nj': {lat: 39.9259, lng: -75.1196},
    'newark,de': {lat: 39.6837, lng: -75.7497},
    // v124: Additional PA/NJ towns
    'kinzers,pa': {lat: 39.9990, lng: -76.0551},
    'lancaster,pa': {lat: 40.0379, lng: -76.3055},
    'exton,pa': {lat: 40.0290, lng: -75.6213},
    'devon,pa': {lat: 40.0454, lng: -75.4238},
    'berwyn,pa': {lat: 40.0454, lng: -75.4385},
    'paoli,pa': {lat: 40.0426, lng: -75.4813},
    'downingtown,pa': {lat: 40.0065, lng: -75.7035},
    'coatesville,pa': {lat: 39.9835, lng: -75.8238},
    'kennett square,pa': {lat: 39.8468, lng: -75.7113},
    'springfield,pa': {lat: 39.9301, lng: -75.3202},
    'swarthmore,pa': {lat: 39.9018, lng: -75.3499},
    'upper darby,pa': {lat: 39.9593, lng: -75.2602},
    'drexel hill,pa': {lat: 39.9468, lng: -75.2924},
    'norristown,pa': {lat: 40.1218, lng: -75.3399},
    'blue bell,pa': {lat: 40.1526, lng: -75.2660},
    'plymouth meeting,pa': {lat: 40.1026, lng: -75.2749},
    'voorhees,nj': {lat: 39.8460, lng: -74.9529},
    'haddonfield,nj': {lat: 39.8912, lng: -75.0368},
    'moorestown,nj': {lat: 39.9690, lng: -74.9490},
    'mount laurel,nj': {lat: 39.9340, lng: -74.8913}
};

// Assign fallback coordinates to trainers without lat/lng
data.forEach(function(t) {
    if (!t.lat || !t.lng || (t.lat === 0 && t.lng === 0)) {
        var key = ((t.city || 'philadelphia') + ',' + (t.state || 'pa')).toLowerCase().trim();
        var coords = cityCoords[key];
        if (!coords) {
            // Try just city name with PA default
            var cityKey = (t.city || 'philadelphia').toLowerCase().trim() + ',pa';
            coords = cityCoords[cityKey];
        }
        if (!coords) {
            // Default to Philadelphia area with slight random offset
            coords = {
                lat: 39.9526 + (Math.random() - 0.5) * 0.15,
                lng: -75.1652 + (Math.random() - 0.5) * 0.15
            };
        } else {
            // Add small random offset so markers don't stack
            coords = {
                lat: coords.lat + (Math.random() - 0.5) * 0.03,
                lng: coords.lng + (Math.random() - 0.5) * 0.03
            };
        }
        t.lat = coords.lat;
        t.lng = coords.lng;
    }
});

var grid=document.getElementById('grid'),cnt=document.getElementById('cnt'),
    pills=document.querySelectorAll('.ft-pill'),sortSel=document.getElementById('sortSel'),
    mapWrap=document.getElementById('mapWrap'),mapToggle=document.getElementById('mapToggle'),
    mapClose=document.getElementById('mapClose'),locateBtn=document.getElementById('locateBtn'),
    locNotice=document.getElementById('locNotice'),locText=document.getElementById('locText'),
    locInput=document.getElementById('locInput'),
    lv='',sort='featured',userLat=null,userLng=null,gmap=null,markers=[],
    base='<?php echo home_url('/trainer/'); ?>';

function calcDist(lat1,lng1,lat2,lng2){
    var R=3959,dLat=(lat2-lat1)*Math.PI/180,dLng=(lng2-lng1)*Math.PI/180;
    var a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)*Math.sin(dLng/2);
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function updateDist(){
    if(!userLat||!userLng)return;
    data.forEach(function(t){if(t.lat&&t.lng)t.distance=calcDist(userLat,userLng,t.lat,t.lng);});
}

// Show skeleton loading state
function showSkeletons(count){
    var skeletons='';
    for(var i=0;i<(count||6);i++){
        skeletons+='<div class="ft-skeleton-card"><div class="ft-skeleton ft-skeleton-img"></div><div class="ft-skeleton-body"><div class="ft-skeleton ft-skeleton-line medium"></div><div class="ft-skeleton ft-skeleton-line short"></div><div style="display:flex;justify-content:space-between;margin-top:12px;padding-top:12px;border-top:1px solid var(--gray2)"><div class="ft-skeleton ft-skeleton-line" style="width:60px;height:20px"></div><div class="ft-skeleton ft-skeleton-line" style="width:50px;height:16px"></div></div></div></div>';
    }
    grid.innerHTML=skeletons;
}

function render(showLoading){
    // Show skeleton loading briefly for better UX
    if(showLoading){
        showSkeletons(6);
        setTimeout(function(){renderCards();},200);
    }else{
        renderCards();
    }
}

function renderCards(){
    var list=data.slice();
    if(lv)list=list.filter(function(t){return lv==='college_d2'?(t.level==='college_d2'||t.level==='college_d3'):t.level===lv;});
    if(sort==='distance'&&userLat)list.sort(function(a,b){return(a.distance||999)-(b.distance||999);});
    else if(sort==='rating')list.sort(function(a,b){return b.rating-a.rating;});
    else if(sort==='price_low')list.sort(function(a,b){return a.rate-b.rate;});
    else if(sort==='price_high')list.sort(function(a,b){return b.rate-a.rate;});
    // Default: Supercoach first, then featured, then rating
    else list.sort(function(a,b){
        if(b.supercoach!==a.supercoach)return b.supercoach-a.supercoach;
        if(b.featured!==a.featured)return b.featured-a.featured;
        return b.rating-a.rating;
    });
    cnt.textContent=list.length;
    if(!list.length){grid.innerHTML='<div class="ft-empty"><h3>NO TRAINERS FOUND</h3><p>Try adjusting your filters or location</p></div>';return;}
    grid.innerHTML=list.map(function(t){
        var loc=t.primary_location||(t.city&&t.state?t.city+', '+t.state:(t.city||t.state||'Philadelphia Area'));
        var dist=t.distance!==null&&t.distance<100?' · '+t.distance.toFixed(1)+' mi away':'';
        var badges='';
        if(t.verified)badges+='<span class="ft-badge v"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></span>';
        if(t.featured)badges+='<span class="ft-badge f"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>';
        // v130.6: Show "New" for trainers with no reviews
        var ratingDisplay = t.reviews > 0 ? '★ '+t.rating.toFixed(1)+' <span>('+t.reviews+')</span>' : '<span style="color:#FCB900">New</span>';
        // Supercoach card class and ribbon
        var cardClass = 'ft-card' + (t.supercoach ? ' ft-supercoach' : '');
        var supercoachRibbon = t.supercoach ? '<div class="ft-supercoach-ribbon">⭐ SUPERCOACH</div>' : '';
        return '<a href="'+base+t.slug+'/" class="'+cardClass+'">'+supercoachRibbon+'<div class="ft-card-img"><img src="'+t.photo+'" alt="'+t.name+'" loading="lazy"><span class="ft-card-tag">'+t.tag+'</span>'+(badges?'<div class="ft-card-badges">'+badges+'</div>':'')+'</div><div class="ft-card-body"><h3 class="ft-card-name">'+t.name.toUpperCase()+'</h3><p class="ft-card-loc">'+loc+dist+'</p><div class="ft-card-meta"><span class="ft-card-rate">$'+t.rate+'<small>/hr</small></span><span class="ft-card-stars">'+ratingDisplay+'</span></div></div></a>';
    }).join('');
    if(gmap)updateMarkers(list);
}

pills.forEach(function(p){p.onclick=function(){pills.forEach(function(x){x.classList.remove('on');});p.classList.add('on');lv=p.dataset.lv;render(true);};});
sortSel.onchange=function(){sort=sortSel.value;render(true);};

if(mapToggle)mapToggle.onclick=function(){mapWrap.classList.add('on');if(!gmap&&typeof google!=='undefined')initMap();};
if(mapClose)mapClose.onclick=function(){mapWrap.classList.remove('on');};

function autoLocate(){
    if(!('geolocation' in navigator))return;
    locateBtn.classList.add('loading');
    navigator.geolocation.getCurrentPosition(function(pos){
        userLat=pos.coords.latitude;userLng=pos.coords.longitude;
        updateDist();locNotice.style.display='inline-flex';
        locText.textContent='Showing trainers near your location';
        sortSel.value='distance';sort='distance';render();
        locateBtn.classList.remove('loading');
        if(gmap){gmap.setCenter({lat:userLat,lng:userLng});gmap.setZoom(11);}
        if(typeof google!=='undefined'&&google.maps){
            new google.maps.Geocoder().geocode({location:{lat:userLat,lng:userLng}},function(r,s){
                if(s==='OK'&&r[0]){var city='';r[0].address_components.forEach(function(c){if(c.types.includes('locality'))city=c.long_name;});
                if(city){locText.textContent='Showing trainers near '+city;locInput.value=city;}}
            });
        }
    },function(){locateBtn.classList.remove('loading');},{enableHighAccuracy:false,timeout:10000,maximumAge:300000});
}

locateBtn.onclick=autoLocate;

document.getElementById('searchBtn').onclick=function(){
    var q=locInput.value.trim();if(!q||typeof google==='undefined')return;
    new google.maps.Geocoder().geocode({address:q+', USA'},function(r,s){
        if(s==='OK'&&r[0]){
            userLat=r[0].geometry.location.lat();userLng=r[0].geometry.location.lng();
            updateDist();locNotice.style.display='inline-flex';locText.textContent='Showing trainers near '+q;
            sortSel.value='distance';sort='distance';render();
            if(gmap){gmap.setCenter({lat:userLat,lng:userLng});gmap.setZoom(11);}
        }
    });
};

window.initMap=function(){
    // Main fullscreen map
    gmap=new google.maps.Map(document.getElementById('map'),{
        zoom:9,center:{lat:39.95,lng:-75.17},
        disableDefaultUI:true,zoomControl:true,
        styles:[
            {elementType:'geometry',stylers:[{color:'#1a1a1a'}]},
            {elementType:'labels.text.stroke',stylers:[{color:'#0A0A0A'}]},
            {elementType:'labels.text.fill',stylers:[{color:'#9CA3AF'}]},
            {featureType:'administrative',elementType:'geometry',stylers:[{visibility:'off'}]},
            {featureType:'administrative.locality',elementType:'labels.text.fill',stylers:[{color:'#E5E7EB'}]},
            {featureType:'poi',stylers:[{visibility:'off'}]},
            {featureType:'road',elementType:'geometry',stylers:[{color:'#2d2d2d'}]},
            {featureType:'road',elementType:'geometry.stroke',stylers:[{color:'#1a1a1a'}]},
            {featureType:'road',elementType:'labels.text.fill',stylers:[{color:'#9CA3AF'}]},
            {featureType:'road.highway',elementType:'geometry',stylers:[{color:'#3d3d3d'}]},
            {featureType:'road.highway',elementType:'geometry.stroke',stylers:[{color:'#1a1a1a'}]},
            {featureType:'road.highway',elementType:'labels.text.fill',stylers:[{color:'#F3F4F6'}]},
            {featureType:'transit',stylers:[{visibility:'off'}]},
            {featureType:'water',elementType:'geometry',stylers:[{color:'#0d1117'}]},
            {featureType:'water',elementType:'labels.text.fill',stylers:[{color:'#4B5563'}]}
        ]
    });
    
    // Mobile mini-map (if exists)
    var mobileMapEl = document.getElementById('mobileMap');
    if(mobileMapEl){
        window.mobileGmap=new google.maps.Map(mobileMapEl,{
            zoom:9,center:{lat:39.95,lng:-75.17},
            disableDefaultUI:true,
            gestureHandling:'cooperative',
            styles:[
                {elementType:'geometry',stylers:[{color:'#f5f5f5'}]},
                {elementType:'labels.text.fill',stylers:[{color:'#616161'}]},
                {featureType:'administrative',elementType:'geometry',stylers:[{visibility:'off'}]},
                {featureType:'poi',stylers:[{visibility:'off'}]},
                {featureType:'road',elementType:'geometry',stylers:[{color:'#ffffff'}]},
                {featureType:'road',elementType:'labels',stylers:[{visibility:'off'}]},
                {featureType:'transit',stylers:[{visibility:'off'}]},
                {featureType:'water',elementType:'geometry',stylers:[{color:'#c9c9c9'}]}
            ]
        });
    }
    
    // Show all trainer markers immediately
    updateMarkers(data);
    // Then try to get user location
    setTimeout(autoLocate,800);
};

function updateMarkers(list){
    markers.forEach(function(m){m.setMap(null);});markers=[];
    var bounds=new google.maps.LatLngBounds(),has=false;
    var geocoder = new google.maps.Geocoder();
    var pendingGeocodes = 0;
    
    // Cache for geocoded addresses
    if (!window.geocodeCache) window.geocodeCache = {};
    
    list.forEach(function(t){
        // Check if trainer has training_locations - PRIORITIZE these over home location
        if (t.training_locations && t.training_locations.length > 0) {
            t.training_locations.forEach(function(loc) {
                // Check if location already has lat/lng coordinates
                if (loc.lat && loc.lng && loc.lat !== 0 && loc.lng !== 0) {
                    createTrainingMarker(t, loc, parseFloat(loc.lat), parseFloat(loc.lng), bounds);
                    has = true;
                    return; // Continue to next location
                }
                
                if (!loc.address) return;
                
                var cacheKey = loc.address.toLowerCase().trim();
                
                // Check cache first
                if (window.geocodeCache[cacheKey]) {
                    var coords = window.geocodeCache[cacheKey];
                    createTrainingMarker(t, loc, coords.lat, coords.lng, bounds);
                    has = true;
                } else {
                    // Geocode the address
                    pendingGeocodes++;
                    geocoder.geocode({address: loc.address + ', USA'}, function(results, status) {
                        pendingGeocodes--;
                        if (status === 'OK' && results[0]) {
                            var lat = results[0].geometry.location.lat();
                            var lng = results[0].geometry.location.lng();
                            window.geocodeCache[cacheKey] = {lat: lat, lng: lng};
                            createTrainingMarker(t, loc, lat, lng, bounds);
                            has = true;
                            // Fit bounds after all geocoding done
                            if (pendingGeocodes === 0 && markers.length > 0) {
                                fitMapBounds(bounds);
                            }
                        }
                    });
                }
            });
        } else if (t.lat && t.lng && (t.lat !== 0 || t.lng !== 0)) {
            // Fallback to trainer's home location ONLY if no training locations exist
            has = true;
            var m = new google.maps.Marker({
                position: {lat: t.lat, lng: t.lng},
                map: gmap,
                title: t.name,
                icon: {path: google.maps.SymbolPath.CIRCLE, fillColor: '#FCB900', fillOpacity: 1, strokeColor: '#0A0A0A', strokeWeight: 2, scale: 10}
            });
            // v130.6: Show "New" for trainers with no reviews
            var ratingHtml = t.reviews > 0 ? '<span style="color:#FCB900">★ ' + t.rating.toFixed(1) + '</span>' : '<span style="color:#FCB900">New</span>';
            var info = new google.maps.InfoWindow({
                content: '<div style="padding:12px;font-family:Inter;min-width:180px;background:#1a1a1a;color:#fff;border-radius:8px">' +
                    '<b style="font-family:Oswald;font-size:14px;color:#fff">' + t.name.toUpperCase() + '</b><br>' +
                    ratingHtml + ' · ' +
                    '<span style="color:#FCB900;font-weight:600">$' + t.rate + '</span><span style="color:#9CA3AF">/hr</span>' +
                    (t.distance !== null ? '<br><span style="color:#9CA3AF;font-size:11px">' + t.distance.toFixed(1) + ' mi away</span>' : '') +
                    '<br><a href="' + base + t.slug + '/" style="color:#FCB900;font-weight:600;text-decoration:none">Book →</a></div>'
            });
            m.addListener('click', function(){ info.open(gmap, m); });
            markers.push(m);
            bounds.extend({lat: t.lat, lng: t.lng});
            
            // Also add to mobile map
            if(window.mobileGmap){
                var mm = new google.maps.Marker({
                    position: {lat: t.lat, lng: t.lng},
                    map: window.mobileGmap,
                    title: t.name,
                    icon: {path: google.maps.SymbolPath.CIRCLE, fillColor: '#FCB900', fillOpacity: 1, strokeColor: '#0A0A0A', strokeWeight: 2, scale: 7}
                });
                mm.addListener('click', function(){ window.location.href = base + t.slug + '/'; });
            }
        }
    });
    
    // Add user location marker if available
    if (userLat && userLng) {
        bounds.extend({lat: userLat, lng: userLng});
        new google.maps.Marker({
            position: {lat: userLat, lng: userLng},
            map: gmap,
            title: 'Your Location',
            icon: {path: google.maps.SymbolPath.CIRCLE, fillColor: '#3B82F6', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3, scale: 8}
        });
        // Also add to mobile map
        if(window.mobileGmap){
            new google.maps.Marker({
                position: {lat: userLat, lng: userLng},
                map: window.mobileGmap,
                title: 'Your Location',
                icon: {path: google.maps.SymbolPath.CIRCLE, fillColor: '#3B82F6', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3, scale: 6}
            });
        }
    }
    
    // Fit bounds if no pending geocodes
    if (pendingGeocodes === 0 && has && markers.length > 0) {
        fitMapBounds(bounds);
    }
}

function createTrainingMarker(t, loc, lat, lng, bounds) {
    var m = new google.maps.Marker({
        position: {lat: lat, lng: lng},
        map: gmap,
        title: t.name + ' - ' + (loc.name || 'Training Location'),
        icon: {path: google.maps.SymbolPath.CIRCLE, fillColor: '#FCB900', fillOpacity: 1, strokeColor: '#0A0A0A', strokeWeight: 2, scale: 10}
    });
    // v130.6: Show "New" for trainers with no reviews
    var ratingHtml = t.reviews > 0 ? '<span style="color:#FCB900">★ ' + t.rating.toFixed(1) + '</span>' : '<span style="color:#FCB900">New</span>';
    var info = new google.maps.InfoWindow({
        content: '<div style="padding:12px;font-family:Inter;min-width:200px;background:#1a1a1a;color:#fff;border-radius:8px">' +
            '<b style="font-family:Oswald;font-size:14px;color:#fff">' + t.name.toUpperCase() + '</b><br>' +
            '<span style="color:#9CA3AF;font-size:12px">' + (loc.name || 'Training Location') + '</span><br>' +
            ratingHtml + ' · ' +
            '<span style="color:#FCB900;font-weight:600">$' + t.rate + '</span><span style="color:#9CA3AF">/hr</span>' +
            (t.distance !== null ? '<br><span style="color:#9CA3AF;font-size:11px">' + t.distance.toFixed(1) + ' mi away</span>' : '') +
            '<br><a href="' + base + t.slug + '/" style="color:#FCB900;font-weight:600;text-decoration:none">Book →</a></div>'
    });
    m.addListener('click', function(){ info.open(gmap, m); });
    markers.push(m);
    bounds.extend({lat: lat, lng: lng});
    
    // Also add to mobile map
    if(window.mobileGmap){
        var mm = new google.maps.Marker({
            position: {lat: lat, lng: lng},
            map: window.mobileGmap,
            title: t.name,
            icon: {path: google.maps.SymbolPath.CIRCLE, fillColor: '#FCB900', fillOpacity: 1, strokeColor: '#0A0A0A', strokeWeight: 2, scale: 7}
        });
        mm.addListener('click', function(){ window.location.href = base + t.slug + '/'; });
    }
}

function fitMapBounds(bounds) {
    if (markers.length === 1) {
        gmap.setCenter(markers[0].getPosition());
        gmap.setZoom(12);
        if(window.mobileGmap){window.mobileGmap.setCenter(markers[0].getPosition());window.mobileGmap.setZoom(10);}
    } else if (markers.length > 1) {
        gmap.fitBounds(bounds);
        if(window.mobileGmap){window.mobileGmap.fitBounds(bounds);}
        // Prevent too much zoom out
        var listener = google.maps.event.addListener(gmap, 'idle', function() {
            if (gmap.getZoom() > 15) gmap.setZoom(15);
            google.maps.event.removeListener(listener);
        });
    }
}

// Initial render with skeleton loading
showSkeletons(6);
setTimeout(function(){render();},300);

locInput.addEventListener('keypress',function(e){if(e.key==='Enter')document.getElementById('searchBtn').click();});

// v125: Clean gap fix + hide PTP plugin header (use theme header only)
(function cleanPageLayout(){
    // Hide any PTP plugin header elements
    var ptpElements = document.querySelectorAll('.ptp-header, #ptpHeader, .ptp-bottom-nav, .ptp-mobile-nav, .ptp-mobile-nav-overlay');
    ptpElements.forEach(function(el) { el.style.display = 'none'; });
    
    var ft = document.querySelector('.ft');
    if(!ft) return;
    
    // Remove all margins/padding up the DOM tree
    var el = ft;
    while(el && el !== document.body){
        el.style.marginTop = '0';
        el.style.paddingTop = '0';
        el.style.marginBlockStart = '0';
        el.style.paddingBlockStart = '0';
        el = el.parentElement;
    }
    
    // Target common WordPress containers
    var containers = ['#page', '#content', '#primary', '.site-content', '.entry-content', 'article', 'main', '.ast-container', '.hentry', '.post-content', '.content-area'];
    containers.forEach(function(sel){
        var c = document.querySelector(sel);
        if(c){
            c.style.marginTop = '0';
            c.style.paddingTop = '0';
        }
    });
    
    // Measure and fix any remaining gap
    var header = document.querySelector('header:not(.ptp-header), .site-header:not(.ptp-header), #masthead, .ast-primary-header, .elementor-location-header');
    if(header && ft){
        var headerRect = header.getBoundingClientRect();
        var ftRect = ft.getBoundingClientRect();
        var gap = ftRect.top - headerRect.bottom;
        if(gap > 2){ // Only fix if gap > 2px
            ft.style.marginTop = '-' + gap + 'px';
        }
    }
})();

// Re-run after fonts/images load
window.addEventListener('load', function(){
    var ft = document.querySelector('.ft');
    var header = document.querySelector('header:not(.ptp-header), .site-header:not(.ptp-header), #masthead, .ast-primary-header, .elementor-location-header');
    if(header && ft){
        var headerRect = header.getBoundingClientRect();
        var ftRect = ft.getBoundingClientRect();
        var gap = ftRect.top - headerRect.bottom;
        if(gap > 2){
            ft.style.marginTop = '-' + gap + 'px';
        }
    }
    
    // v130: Fix mouse wheel scrolling on trainer list
    var ftList = document.querySelector('.ft-list');
    if(ftList){
        // Ensure the list can receive scroll events
        ftList.style.pointerEvents = 'auto';
        // Force scrollable behavior
        ftList.addEventListener('wheel', function(e){
            // Prevent page scroll, only scroll the list
            if(ftList.scrollHeight > ftList.clientHeight){
                e.stopPropagation();
            }
        }, {passive: true});
    }
});
})();
</script>

<!-- v132.7: Scroll fix - clean up stuck classes + measure actual header -->
<script>
(function(){
    function cleanupScrollBlocking(){
        // Remove all scroll-blocking classes
        document.body.classList.remove('menu-open', 'modal-open', 'ptp-drawer-open', 'ptp-drawer-active', 'no-scroll', 'overflow-hidden');
        document.documentElement.classList.remove('menu-open', 'modal-open', 'ptp-drawer-open', 'ptp-drawer-active', 'no-scroll', 'overflow-hidden');
        
        // Clear any inline overflow styles on body that might block scroll
        if (document.body.style.overflow === 'hidden') {
            document.body.style.overflow = '';
        }
        if (document.body.style.position === 'fixed') {
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.width = '';
        }
    }
    
    function setHeaderHeight(){
        // Measure actual header height and set CSS variable
        var header = document.querySelector('.ptp-hdr, header.ptp-hdr, #masthead, .site-header, header[role="banner"]');
        var ft = document.querySelector('.ft');
        if(header && ft){
            var h = header.offsetHeight || 70;
            ft.style.setProperty('--header-height', h + 'px');
        }
    }
    
    // Run immediately and on load
    cleanupScrollBlocking();
    window.addEventListener('load', function(){
        cleanupScrollBlocking();
        setHeaderHeight();
    });
    
    // Run on resize (header height might change)
    window.addEventListener('resize', setHeaderHeight);
    
    // Run a few times after load to catch delayed scripts
    setTimeout(cleanupScrollBlocking, 500);
    setTimeout(cleanupScrollBlocking, 1000);
    setTimeout(cleanupScrollBlocking, 2000);
    setTimeout(setHeaderHeight, 100);
})();
</script>

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"LocalBusiness","name":"PTP Soccer Training<?php echo $seo_location ? ' - '.$seo_location : ''; ?>","description":"<?php echo esc_attr($page_desc); ?>","url":"<?php echo esc_url(home_url('/find-trainers/')); ?>","telephone":"+1-484-572-4770","priceRange":"$60-$120/hr","aggregateRating":{"@type":"AggregateRating","ratingValue":"4.9","reviewCount":"<?php echo $count * 5; ?>"}}
</script>
<?php get_footer(); ?>
