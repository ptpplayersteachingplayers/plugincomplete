<?php
/**
 * PTP Training Thank You Handler v132.9
 * 
 * Clean, simple, bulletproof thank-you page for training bookings.
 * Catches /thank-you/ URL early and renders a standalone page.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Training_Thankyou {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook very early to catch the URL before WordPress processes it
        add_action('init', array($this, 'maybe_render_thankyou'), 1);
    }
    
    /**
     * Check if this is a thank-you page request and render it
     */
    public function maybe_render_thankyou() {
        // Get the request path
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($request_uri, PHP_URL_PATH);
        $path = trim($path, '/');
        
        // Only handle thank-you URLs
        if (!in_array($path, array('thank-you', 'thankyou', 'order-received', 'order-confirmation'))) {
            return;
        }
        
        // Check if this is a training booking (has session or bookings param, no order_id with WooCommerce order)
        $has_session = isset($_GET['session']) || isset($_GET['payment_intent']);
        $has_bookings = isset($_GET['bookings']) || isset($_GET['booking_id']);
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        
        // If there's an order_id, check if it's a WooCommerce camp order
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                // This is a WooCommerce order - let the normal flow handle it
                return;
            }
        }
        
        // If we have training params OR no order, render training thank-you
        if ($has_session || $has_bookings || !$order_id) {
            $this->render_training_thankyou();
            exit;
        }
    }
    
    /**
     * Render the training thank-you page
     */
    private function render_training_thankyou() {
        global $wpdb;
        
        // Collect all possible booking identifiers
        $booking_id = 0;
        $booking = null;
        $error_message = '';
        
        // Try to find booking from various params
        $booking_id = $this->find_booking_id();
        
        // Load booking data if we have an ID
        if ($booking_id) {
            $booking = $this->load_booking($booking_id);
        }
        
        // If no booking found, try to recover from session data
        $session_data = null;
        if (!$booking) {
            $session_param = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
            if ($session_param) {
                $session_data = get_transient('ptp_checkout_' . $session_param);
            }
        }
        
        // Render the page
        $this->output_page($booking, $session_data);
    }
    
    /**
     * Find booking ID from URL params, cookies, or database
     */
    private function find_booking_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_bookings';
        
        // 1. Direct booking_id param
        if (isset($_GET['booking_id'])) {
            return intval($_GET['booking_id']);
        }
        
        // 2. Bookings param (comma-separated, take first)
        if (isset($_GET['bookings'])) {
            $ids = array_map('intval', explode(',', sanitize_text_field($_GET['bookings'])));
            if (!empty($ids[0])) {
                return $ids[0];
            }
        }
        
        // 3. Payment intent lookup
        if (isset($_GET['payment_intent'])) {
            $pi = sanitize_text_field($_GET['payment_intent']);
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE payment_intent_id = %s ORDER BY id DESC LIMIT 1",
                $pi
            ));
            if ($found) {
                return intval($found);
            }
        }
        
        // 4. Session param - check transient for booking info
        if (isset($_GET['session'])) {
            $session = sanitize_text_field($_GET['session']);
            $data = get_transient('ptp_checkout_' . $session);
            if ($data && !empty($data['booking_id'])) {
                return intval($data['booking_id']);
            }
            // Also try to find by trainer from session
            if ($data && !empty($data['trainer_id'])) {
                $found = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE trainer_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1",
                    intval($data['trainer_id'])
                ));
                if ($found) {
                    return intval($found);
                }
            }
        }
        
        // 5. Cookie fallback
        if (isset($_COOKIE['ptp_last_booking'])) {
            return intval($_COOKIE['ptp_last_booking']);
        }
        
        // 6. Recent booking for logged-in user
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $user_id
            ));
            if ($parent_id) {
                $found = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE parent_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1",
                    $parent_id
                ));
                if ($found) {
                    return intval($found);
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Load booking with all related data
     */
    private function load_booking($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT 
                b.*,
                t.display_name as trainer_name,
                t.photo_url as trainer_photo,
                t.slug as trainer_slug,
                t.headline as trainer_headline,
                t.playing_level as trainer_level,
                p.email as parent_email,
                p.first_name as parent_first,
                p.last_name as parent_last,
                pl.first_name as player_first,
                pl.last_name as player_last
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.id = %d
        ", $booking_id));
        
        return $booking;
    }
    
    /**
     * Output the complete thank-you page
     */
    private function output_page($booking, $session_data) {
        // Extract display data
        $trainer_name = '';
        $trainer_photo = '';
        $player_name = '';
        $session_date = '';
        $session_time = '';
        $location = '';
        $package_type = 'single';
        $booking_number = '';
        
        if ($booking) {
            $trainer_name = $booking->trainer_name ?? '';
            $trainer_photo = $booking->trainer_photo ?? '';
            $player_name = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
            $session_date = $booking->session_date ?? '';
            $session_time = $booking->start_time ?? '';
            $location = $booking->location ?? '';
            $package_type = $booking->package_type ?? 'single';
            $booking_number = $booking->booking_number ?? ('PTP-' . $booking->id);
        } elseif ($session_data) {
            $trainer_id = $session_data['trainer_id'] ?? 0;
            if ($trainer_id) {
                global $wpdb;
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT display_name, photo_url FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $trainer_id
                ));
                if ($trainer) {
                    $trainer_name = $trainer->display_name;
                    $trainer_photo = $trainer->photo_url;
                }
            }
            $camper = $session_data['camper_data'] ?? array();
            $player_name = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
            $session_date = $session_data['session_date'] ?? '';
            $session_time = $session_data['session_time'] ?? '';
            $location = $session_data['session_location'] ?? '';
            $package_type = $session_data['training_package'] ?? 'single';
            $booking_number = 'Pending';
        }
        
        // Format date and time for display
        $date_display = $session_date ? date('l, F j, Y', strtotime($session_date)) : 'To be confirmed';
        $time_display = 'To be confirmed';
        if ($session_time) {
            $hour = intval(explode(':', $session_time)[0]);
            $min = explode(':', $session_time)[1] ?? '00';
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour > 12 ? $hour - 12 : ($hour ?: 12);
            $time_display = $hour12 . ':' . $min . ' ' . $ampm;
        }
        
        // Package labels
        $package_labels = array(
            'single' => '1 Session',
            'pack3' => '3-Session Pack',
            'pack5' => '5-Session Pack'
        );
        $package_display = $package_labels[$package_type] ?? '1 Session';
        
        // Get home URL safely
        $home_url = function_exists('home_url') ? home_url() : '/';
        
        // Output the page
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Booking Confirmed - PTP Training</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        html {
            height: 100%;
            overflow-x: hidden;
            overflow-y: scroll;
            -webkit-overflow-scrolling: touch;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0A0A0A;
            color: #fff;
            min-height: 100%;
            height: auto;
            overflow-x: hidden;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            position: relative;
        }
        
        .ty-container {
            max-width: 500px;
            margin: 0 auto;
            padding: 24px 16px 60px;
            padding-bottom: calc(60px + env(safe-area-inset-bottom, 0px));
            min-height: 100vh;
            min-height: 100dvh;
        }
        
        .ty-header {
            text-align: center;
            padding: 40px 0 32px;
        }
        
        .ty-check {
            width: 80px;
            height: 80px;
            background: #22C55E;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.5s ease;
        }
        
        @keyframes scaleIn {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .ty-check svg {
            width: 40px;
            height: 40px;
            stroke: #fff;
            stroke-width: 3;
        }
        
        .ty-title {
            font-family: 'Oswald', sans-serif;
            font-size: 28px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .ty-subtitle {
            color: rgba(255,255,255,0.7);
            font-size: 15px;
        }
        
        .ty-card {
            background: #141414;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        
        .ty-trainer {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .ty-trainer-photo {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #FCB900;
            background: #333;
        }
        
        .ty-trainer-info h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .ty-trainer-info p {
            color: #FCB900;
            font-size: 13px;
            font-weight: 500;
        }
        
        .ty-details {
            padding: 0;
        }
        
        .ty-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .ty-detail-row:last-child {
            border-bottom: none;
        }
        
        .ty-detail-label {
            color: rgba(255,255,255,0.5);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ty-detail-value {
            font-weight: 600;
            font-size: 14px;
            text-align: right;
        }
        
        .ty-status {
            background: linear-gradient(135deg, #FCB900 0%, #F59E0B 100%);
            padding: 16px 20px;
            text-align: center;
        }
        
        .ty-status-text {
            color: #0A0A0A;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ty-message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        
        .ty-message p {
            color: #22C55E;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .ty-btn {
            display: block;
            width: 100%;
            background: #FCB900;
            color: #0A0A0A;
            text-align: center;
            padding: 16px 24px;
            font-family: 'Oswald', sans-serif;
            font-size: 16px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 12px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .ty-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(252, 185, 0, 0.3);
        }
        
        .ty-btn-secondary {
            background: transparent;
            border: 2px solid rgba(255,255,255,0.2);
            color: #fff;
        }
        
        .ty-btn-secondary:hover {
            border-color: #FCB900;
            box-shadow: none;
        }
        
        .ty-footer {
            text-align: center;
            padding-top: 24px;
            color: rgba(255,255,255,0.4);
            font-size: 12px;
        }
        
        .ty-footer img {
            height: 24px;
            margin-bottom: 8px;
            opacity: 0.6;
        }
        
        /* No booking state */
        .ty-pending {
            text-align: center;
            padding: 40px 20px;
        }
        
        .ty-pending-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .ty-pending h3 {
            font-size: 20px;
            margin-bottom: 8px;
        }
        
        .ty-pending p {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            line-height: 1.6;
        }
        
        /* Mobile scroll fixes */
        @media (max-width: 768px) {
            html, body {
                overflow-y: scroll !important;
                -webkit-overflow-scrolling: touch !important;
                overscroll-behavior-y: auto;
            }
            .ty-container {
                padding-bottom: calc(80px + env(safe-area-inset-bottom, 20px));
            }
        }
        
        /* iOS Safari fixes */
        @supports (-webkit-touch-callout: none) {
            html, body {
                overflow-y: scroll !important;
                -webkit-overflow-scrolling: touch !important;
            }
        }
    </style>
</head>
<body>
    <div class="ty-container">
        <div class="ty-header">
            <div class="ty-check">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h1 class="ty-title">You're Locked In!</h1>
            <p class="ty-subtitle">Your training session is confirmed</p>
        </div>
        
        <?php if ($booking || $session_data): ?>
        
        <div class="ty-card">
            <?php if ($trainer_name): ?>
            <div class="ty-trainer">
                <?php if ($trainer_photo): ?>
                <img src="<?php echo esc_url($trainer_photo); ?>" alt="<?php echo esc_attr($trainer_name); ?>" class="ty-trainer-photo">
                <?php else: ?>
                <div class="ty-trainer-photo" style="display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:#FCB900;">
                    <?php echo esc_html(substr($trainer_name, 0, 1)); ?>
                </div>
                <?php endif; ?>
                <div class="ty-trainer-info">
                    <h3><?php echo esc_html($trainer_name); ?></h3>
                    <p>Your Trainer</p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="ty-details">
                <?php if ($player_name): ?>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Player</span>
                    <span class="ty-detail-value"><?php echo esc_html($player_name); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Package</span>
                    <span class="ty-detail-value"><?php echo esc_html($package_display); ?></span>
                </div>
                
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Date</span>
                    <span class="ty-detail-value"><?php echo esc_html($date_display); ?></span>
                </div>
                
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Time</span>
                    <span class="ty-detail-value"><?php echo esc_html($time_display); ?></span>
                </div>
                
                <?php if ($location): ?>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Location</span>
                    <span class="ty-detail-value"><?php echo esc_html($location); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($booking_number && $booking_number !== 'Pending'): ?>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Confirmation</span>
                    <span class="ty-detail-value"><?php echo esc_html($booking_number); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="ty-status">
                <span class="ty-status-text">✓ Payment Complete</span>
            </div>
        </div>
        
        <div class="ty-message">
            <p>📧 Confirmation email sent! Your trainer will reach out to confirm details.</p>
        </div>
        
        <?php else: ?>
        
        <div class="ty-card">
            <div class="ty-pending">
                <div class="ty-pending-icon">✓</div>
                <h3>Payment Received!</h3>
                <p>Your booking is being processed. You'll receive a confirmation email shortly with all the details.</p>
            </div>
            <div class="ty-status">
                <span class="ty-status-text">✓ Payment Complete</span>
            </div>
        </div>
        
        <?php endif; ?>
        
        <a href="<?php echo esc_url($home_url . '/my-training/'); ?>" class="ty-btn">
            View My Training
        </a>
        
        <a href="<?php echo esc_url($home_url); ?>" class="ty-btn ty-btn-secondary">
            Return Home
        </a>
        
        <div class="ty-footer">
            <img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP">
            <p>Players Teaching Players</p>
        </div>
    </div>
    
    <?php if (function_exists('wp_footer')): ?>
    <?php wp_footer(); ?>
    <?php endif; ?>
</body>
</html>
        <?php
    }
}

// Initialize
function ptp_training_thankyou_init() {
    return PTP_Training_Thankyou::instance();
}
add_action('plugins_loaded', 'ptp_training_thankyou_init', 1);
