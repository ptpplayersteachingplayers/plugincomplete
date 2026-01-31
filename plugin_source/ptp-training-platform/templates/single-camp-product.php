<?php
/**
 * Template Name: PTP Camp Product
 * Single camp product page - full PTP experience matching ptp-camp-complete.html
 * @version 146.0.0
 */
defined('ABSPATH') || exit;

global $wpdb;
$table = $wpdb->prefix . 'ptp_stripe_products';

$camp_id = isset($_GET['camp']) ? sanitize_text_field($_GET['camp']) : null;
$page_slug = get_post_field('post_name', get_the_ID());

if ($camp_id) {
    $camp = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE stripe_product_id = %s OR id = %d OR sku = %s",
        $camp_id, intval($camp_id), $camp_id
    ));
} else {
    $camp = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE sku LIKE %s OR LOWER(name) LIKE %s LIMIT 1",
        '%' . $wpdb->esc_like($page_slug) . '%',
        '%' . $wpdb->esc_like(str_replace('-', ' ', $page_slug)) . '%'
    ));
}

if (!$camp) {
    $camp = $wpdb->get_row("SELECT * FROM $table WHERE product_type = 'camp' AND active = 1 ORDER BY sort_order ASC LIMIT 1");
}

$all_camps = $wpdb->get_results("SELECT * FROM $table WHERE product_type = 'camp' AND active = 1 ORDER BY sort_order ASC");

$base_price = $camp ? ($camp->price_cents / 100) : 399;
$spots_remaining = $camp && $camp->camp_capacity ? ($camp->camp_capacity - $camp->camp_registered) : 50;
$sold_out = $spots_remaining <= 0;
$camp_name = $camp ? $camp->name : 'PTP Soccer Camp';
$camp_dates = $camp ? $camp->camp_dates : 'June 2026';
$camp_location = $camp ? $camp->camp_location : 'Philadelphia Area';
$camp_time = $camp ? $camp->camp_time : '9:00 AM - 3:00 PM';
$camp_age_range = $camp ? "Ages {$camp->camp_age_min}-{$camp->camp_age_max}" : 'Ages 6-14';
$camp_image = $camp && $camp->image_url ? $camp->image_url : 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg';
$location_short = $camp_location ? explode(',', $camp_location)[0] : 'PA';
$location_for_map = urlencode($camp_location ?: 'Radnor Memorial Park PA');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <meta name="theme-color" content="#0A0A0A">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title><?php echo esc_html($camp_name); ?> | PTP Soccer Camps</title>
  <meta name="description" content="Elite soccer camp for ages 6-14. NCAA D1 coaches play alongside your kid. 8:1 ratio. <?php echo esc_attr($camp_location); ?>. Register now!">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-camp-product-complete.css">
</head>
<body>
  <header class="site-header">
    <a href="<?php echo home_url(); ?>" class="logo">PTP</a>
    <button class="menu-btn" aria-label="Menu">
      <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
    </button>
  </header>
  
  <div id="ptp-camp-wrapper" data-product-id="<?php echo esc_attr($camp ? $camp->id : ''); ?>" data-base-price="<?php echo esc_attr($base_price); ?>">
    
    <section class="hero-two-col">
      <div class="hero-video-col">
        <video class="hero-video" id="heroVideo" autoplay muted loop playsinline poster="<?php echo esc_url($camp_image); ?>">
          <source src="https://ptpsummercamps.com/wp-content/uploads/2026/01/PRODUCT-VIDEO.mp4" type="video/mp4">
        </video>
        <div class="hero-overlay"></div>
        <div class="hero-top">
          <span class="hero-badge-top">
            <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
            5.0 · 50+ Reviews
          </span>
        </div>
        <button class="hero-sound-toggle" id="heroSoundToggle">
          <svg class="sound-off" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
          <svg class="sound-on" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
        </button>
        <div class="hero-content-mobile">
          <span class="hero-badge">⚽ SUMMER 2026</span>
          <h1 class="hero-headline">Train With <span>D1 Athletes</span></h1>
          <p class="hero-sub">NCAA D1 coaches <strong>play alongside</strong> your kid.</p>
        </div>
      </div>
      <div class="hero-reserve-col">
        <div class="reserve-card">
          <span class="reserve-badge">⚽ SUMMER 2026</span>
          <h1 class="reserve-headline">Train With <span>D1 Athletes</span></h1>
          <p class="reserve-sub">NCAA D1 coaches <strong>play alongside</strong> your kid. Not from the sideline.</p>
          <div class="reserve-pills">
            <span class="pill"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg><?php echo esc_html($camp_dates); ?></span>
            <span class="pill"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg><?php echo esc_html($location_short); ?></span>
            <span class="pill"><?php echo esc_html($camp_age_range); ?></span>
          </div>
          <?php if ($spots_remaining <= 15 && !$sold_out): ?>
          <div class="reserve-urgency">🔥 Only <strong><?php echo $spots_remaining; ?> spots</strong> remaining!</div>
          <?php endif; ?>
          <div class="reserve-price">
            <span class="price-amount">$<?php echo number_format($base_price, 0); ?></span>
            <span class="price-per">/week</span>
          </div>
          <button id="heroCta" class="reserve-btn">RESERVE YOUR SPOT →</button>
          <div class="reserve-guarantee">
            <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            14-Day Full Refund Guarantee
          </div>
        </div>
      </div>
    </section>
    
    <nav class="quick-nav" id="quickNav">
      <div class="quick-nav-inner">
        <div class="quick-info">
          <div class="quick-item"><svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg><span><?php echo esc_html($camp_dates); ?></span></div>
          <div class="quick-item"><svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg><span><?php echo esc_html($location_short); ?></span></div>
          <div class="quick-item"><svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg><span><?php echo esc_html($camp_time); ?></span></div>
          <div class="quick-item"><?php echo esc_html($camp_age_range); ?></div>
        </div>
        <div class="quick-links">
          <a href="#reelsSection" class="quick-link">Videos</a>
          <a href="#coachesSection" class="quick-link">Coaches</a>
          <a href="#scheduleSection" class="quick-link">Schedule</a>
          <a href="#pricing" class="quick-link quick-link-cta">Book Now</a>
        </div>
      </div>
    </nav>

    <?php include PTP_PLUGIN_DIR . 'templates/partials/camp-sections.php'; ?>
    
  </div>
  
  <div class="sticky-cta" id="stickyCta">
    <div class="sticky-left">
      <span class="sticky-price" id="stickyPrice">$<?php echo number_format($base_price, 0); ?></span>
      <span class="sticky-date"><?php echo esc_html($camp_dates); ?></span>
    </div>
    <button class="sticky-btn" id="stickyBtn">RESERVE</button>
  </div>
  
  <footer class="site-footer">
    <div class="footer-logo">PTP</div>
    <div class="footer-links">
      <a href="<?php echo home_url('/about/'); ?>">About</a>
      <a href="<?php echo home_url('/contact/'); ?>">Contact</a>
      <a href="<?php echo home_url('/privacy/'); ?>">Privacy</a>
      <a href="<?php echo home_url('/terms/'); ?>">Terms</a>
    </div>
    <p class="footer-copy">© 2026 PTP Soccer. All rights reserved.</p>
  </footer>
  
  <script src="<?php echo PTP_PLUGIN_URL; ?>assets/js/ptp-camp-product.js"></script>
</body>
</html>
