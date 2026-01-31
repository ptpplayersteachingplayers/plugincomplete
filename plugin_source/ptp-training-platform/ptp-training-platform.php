<?php
/**
 * Plugin Name: PTP Training Platform
 * Plugin URI: https://ptpsummercamps.com
 * Description: Complete soccer training platform - camps, 1-on-1 training marketplace, and membership packages. Connects families with elite NCAA and professional trainers. Features booking funnel, sibling/team discounts, referral system, and Stripe Connect payouts. WOOCOMMERCE INDEPENDENT v148.
 * Version: 148.0.0
 * Author: PTP Soccer Camps
 * Author URI: https://ptpsummercamps.com
 * Text Domain: ptp-training
 * Requires at least: 6.0
 * Requires PHP: 8.2
 */

defined('ABSPATH') || exit;

// Plugin constants
define("PTP_VERSION", "148.0.0");
define('PTP_V71_VERSION', '71.0.0');
define('PTP_V72_VERSION', '72.0.0');
define('PTP_V73_VERSION', '73.0.0');
define('PTP_V81_VERSION', '81.0.0');
define('PTP_PLUGIN_FILE', __FILE__);
define('PTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PTP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PTP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PTP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// v148: WooCommerce independent mode
define('PTP_WC_INDEPENDENT', true);

// v130.3: Platform fee now configurable via admin
define('PTP_PLATFORM_FEE_PERCENT_DEFAULT', 0.20);

/**
 * Get platform fee as decimal (e.g., 0.20 for 20%)
 */
function ptp_get_platform_fee() {
    $fee_percent = get_option('ptp_platform_fee', 20);
    return floatval($fee_percent) / 100;
}

/**
 * Get platform fee as percentage (e.g., 20 for 20%)
 */
function ptp_get_platform_fee_percent() {
    return floatval(get_option('ptp_platform_fee', 20));
}

/**
 * Check if WooCommerce is active
 */
function ptp_wc_active() {
    return class_exists('WooCommerce');
}

/**
 * Check if running in WC-independent mode
 */
function ptp_is_wc_independent() {
    return defined('PTP_WC_INDEPENDENT') && PTP_WC_INDEPENDENT && !class_exists('WooCommerce');
}

/**
 * Main Plugin Class
 */
final class PTP_Training_Platform {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_native_core();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * v148: Load native core classes FIRST (WC-independent)
     * These must load before anything that might call WC()
     */
    private function load_native_core() {
        // Native session management (replaces WC()->session)
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-native-session.php';

        // Native cart management (replaces WC()->cart)
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-native-cart.php';

        // Native order management (replaces wc_create_order, wc_get_order)
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-native-orders.php';

        // WC compatibility layer (provides WC() shim if WC not active)
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-wc-compat.php';
    }

    private function includes() {
        // Core classes
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-database.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-images.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-image-optimizer.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-user.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-trainer.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-trainer-profile.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-parent.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-player.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-booking.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-availability.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-reviews.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-payments.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-trainer-payouts.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-notifications.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-ajax.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-shortcodes.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-templates.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-performance.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-mobile-optimization.php';
        require_once PTP_PLUGIN_DIR . 'includes/ptp-elementor-header-fix.php';

        // Header component v88
        require_once PTP_PLUGIN_DIR . 'templates/components/ptp-header.php';

        // Integration classes
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-email.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-email-templates.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-stripe.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-escrow.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-session-confirmation.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-seo.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-seo-locations.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-seo-sitemap.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-seo-content.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-seo-titles.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-social.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-push-notifications.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-schedule-calendar-v2.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-geocoding.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-maps.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-cron.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-onboarding-reminders.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camps-bridge.php';

        // v148: WooCommerce classes - only load if WC is active
        if (ptp_wc_active()) {
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-camps.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-camps.php';
            }
        }

        // v146: WooCommerce-Free Camp System - Stripe-direct checkout
        require_once PTP_PLUGIN_DIR . 'includes/ptp-camp-system-loader.php';

        // Advanced features
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-recurring.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-groups.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-calendar-sync.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-training-plans.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-tax-reporting.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-rest-api.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-faq.php';

        // New features v45
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-happy-score.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-gift-cards.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-subscriptions.php';

        // v88: Referral system
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-referral-system.php';

        // v48: TeachMe.to-style booking wizard
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-booking-wizard.php';

        // v122: Critical booking/email fix
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-booking-fix-v122.php';

        // Mobile App API classes
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-app-config.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-social-login.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camps-api.php';

        // Admin class
        require_once PTP_PLUGIN_DIR . 'admin/class-ptp-admin.php';
        require_once PTP_PLUGIN_DIR . 'admin/class-ptp-admin-ajax.php';
        require_once PTP_PLUGIN_DIR . 'admin/class-ptp-app-control.php';
        require_once PTP_PLUGIN_DIR . 'admin/class-ptp-admin-tools-v72.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-admin-payouts-v2.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-admin-payouts-v3.php';
        require_once PTP_PLUGIN_DIR . 'admin/stripe-diagnostic.php';
        require_once PTP_PLUGIN_DIR . 'admin/class-ptp-analytics-dashboard.php';

        // v88: PWA Support
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-pwa.php';

        // v50: Enhanced profile components
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-photo-upload.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-quick-profile-editor.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camps-crosssell.php';

        // v52: Analytics, Email Automation
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-analytics.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-email-automation.php';

        // v53: Growth Engine
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-trainer-referrals.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-pixels.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-growth.php';

        // v56: Salesmsg AI Integration
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-salesmsg-api.php';

        // v57: Cross-Sell Engine, Viral Sharing, and Instant Pay
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-crosssell-engine.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-viral-engine.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-instant-pay.php';

        // v116: Viral Enhancements
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-viral-enhancements.php';

        // v133: Clean Training Thank You Handler
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-training-thankyou.php';

        // v121: Booking Fix
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-booking-fix-v121.php';

        // v57.1: Supabase Bridge
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-supabase-bridge.php';

        // v58: Checkout UX Improvements
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-checkout-ux.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-packages-display.php';

        // v59: Flow Engine
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-flow-engine.php';

        // v59.3: Quality Control System
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-quality-control.php';

        // v59.3: Trainer Loyalty System
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-trainer-loyalty.php';

        // v59.4: Unified Bundle Checkout
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-bundle-checkout.php';

        // v148: WooCommerce-dependent v60 classes (only if WC active)
        if (ptp_wc_active()) {
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-camp-crosssell-everywhere.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camp-crosssell-everywhere.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-unified-cart.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-unified-cart.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-cart-enhancement.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-cart-enhancement.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-unified-checkout-handler.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-unified-checkout-handler.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-unified-checkout.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-unified-checkout.php';
            }
        }

        // v60.3: Cart Helper - centralized cart state (WC-independent)
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-cart-helper.php';

        // v60.5: Checkout Diagnostic Tool
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-checkout-diagnostic.php';

        // v60.2: N8N Integration
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-n8n-endpoints.php';

        // v71: Enhanced Features
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-availability-v71.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-sms.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-messaging.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-google-calendar-v71.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-stripe-connect-v71.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-ajax-v71.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-spa-dashboard.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-order-integration-v71.php';

        // v148: WooCommerce-dependent v71 classes
        if (ptp_wc_active()) {
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-cart-checkout-v71.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-cart-checkout-v71.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-orders-v71.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-orders-v71.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-emails.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-emails.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-order-email-wiring.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-order-email-wiring.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-training-woo-integration.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-training-woo-integration.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-unified-order-email.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-unified-order-email.php';
            }
            if (file_exists(PTP_PLUGIN_DIR . 'includes/class-ptp-camp-checkout-v99.php')) {
                require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camp-checkout-v99.php';
            }
        }

        // Camp Product Template - loads for camp/clinic products
        if (!defined('PTP_DISABLE_CAMP_TEMPLATE') || !PTP_DISABLE_CAMP_TEMPLATE) {
            if (file_exists(PTP_PLUGIN_DIR . 'templates/camp/ptp-camp-product-v10.3.5.php')) {
                require_once PTP_PLUGIN_DIR . 'templates/camp/ptp-camp-product-v10.3.5.php';
            }
        }

        // v86: Page creator tool
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-page-creator.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-admin-settings-v71.php';

        // v72: Comprehensive Fixes
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-autoloader.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-fixes-v72.php';

        // v129: Comprehensive fixes
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-fixes-v129.php';

        // v77: Bulletproof Checkout
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-checkout-v77.php';

        // v94: All-Access Pass Membership System
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-all-access-pass.php';
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // SECURITY: Add security headers
        add_action('send_headers', array($this, 'add_security_headers'));

        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'dequeue_theme_on_ptp_pages'), 999);
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        // Handle trainer application form
        add_action('admin_post_nopriv_ptp_trainer_apply', array($this, 'handle_simple_application'));
        add_action('admin_post_ptp_trainer_apply', array($this, 'handle_simple_application'));

        // AJAX handlers for trainer application
        add_action('wp_ajax_ptp_submit_application', array($this, 'ajax_submit_application'));
        add_action('wp_ajax_nopriv_ptp_submit_application', array($this, 'ajax_submit_application'));
        add_action('wp_ajax_ptp_coach_application', array($this, 'ajax_coach_application'));
        add_action('wp_ajax_nopriv_ptp_coach_application', array($this, 'ajax_coach_application'));

        // Early hooks
        add_action('parse_request', array($this, 'intercept_apply_post'), 1);
        add_action('parse_request', array($this, 'intercept_signout'), 1);

        // Template overrides
        add_action('template_redirect', array($this, 'maybe_override_template'), 1);

        // v148: WooCommerce hooks only if WC active
        if (ptp_wc_active()) {
            add_filter('woocommerce_login_redirect', array($this, 'woo_login_redirect'), 99, 2);
            add_filter('woocommerce_registration_redirect', array($this, 'woo_login_redirect'), 99, 2);
        }

        // Handle login failures
        add_action('wp_login_failed', array($this, 'handle_login_failure'));
        add_filter('login_redirect', array($this, 'custom_login_redirect'), 99, 3);

        // Initialize admin
        PTP_Admin::instance();

        // Initialize v57 growth engines
        PTP_Crosssell_Engine::instance();
        PTP_Viral_Engine::instance();
        PTP_Instant_Pay::instance();

        // v148: WooCommerce-dependent class instantiations
        if (ptp_wc_active()) {
            if (class_exists('PTP_Camp_Crosssell_Everywhere')) {
                PTP_Camp_Crosssell_Everywhere::instance();
            }
            if (class_exists('PTP_Unified_Cart')) {
                PTP_Unified_Cart::instance();
            }
            if (class_exists('PTP_Cart_Checkout_V71')) {
                PTP_Cart_Checkout_V71::instance();
            }
        }

        // Initialize v71 enhanced features (WooCommerce-independent)
        PTP_Availability_V71::init();
        PTP_SMS_V71::init();
        PTP_Messaging_V71::init();
        PTP_Admin_Payouts_V3::init();

        // Classes with instance() singleton pattern
        PTP_Google_Calendar_V71::instance();
        PTP_Stripe_Connect_V71::instance();
        PTP_SPA_Dashboard::instance();
        PTP_Admin_Settings_V71::instance();

        // Classes with constructor
        new PTP_Ajax_V71();

        // v86: Page Creator admin tool
        new PTP_Page_Creator();

        // Admin status notice
        add_action('admin_notices', array($this, 'check_plugin_status'));
    }

    /**
     * Intercept /apply/ POST requests
     */
    public function intercept_apply_post($wp) {
        $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');

        if ($request_uri === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            include PTP_PLUGIN_DIR . 'templates/apply.php';
            exit;
        }
    }

    /**
     * v130.3: Intercept /signout/ URLs
     */
    public function intercept_signout($wp) {
        $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');

        if (in_array($request_uri, array('signout', 'sign-out'))) {
            if (is_user_logged_in()) {
                wp_redirect(home_url('/logout/'));
            } else {
                wp_redirect(home_url('/login/?logged_out=1'));
            }
            exit;
        }
    }

    /**
     * Handle simple trainer application
     */
    public function handle_simple_application() {
        if (!wp_verify_nonce($_POST['ptp_apply_nonce'] ?? '', 'ptp_trainer_apply')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            exit;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            PTP_Database::create_tables();
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);

        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $location = trim($city . ', ' . $state, ', ');

        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($full_name) || empty($email)) {
            wp_send_json_error(array('message' => 'Name and email are required'));
            exit;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s",
            $email
        ));

        if ($existing) {
            wp_send_json_success(array('message' => 'Application already received!'));
            exit;
        }

        $data = array(
            'name' => $full_name,
            'email' => $email,
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'location' => $location,
            'playing_level' => sanitize_text_field($_POST['playing_level'] ?? ''),
            'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            error_log('PTP Application Error: ' . $wpdb->last_error);
            wp_send_json_error(array('message' => 'Error saving application'));
            exit;
        }

        if (class_exists('PTP_Email')) {
            PTP_Email::send_application_received($email, $full_name);
        }

        wp_mail(
            get_option('admin_email'),
            'New Trainer Application - ' . $full_name,
            sprintf(
                "New trainer application received:\n\nName: %s\nEmail: %s\nPhone: %s\nLocation: %s\nPlaying Level: %s\n\nReview: %s",
                $full_name, $email, $data['phone'], $location, $data['playing_level'],
                admin_url('admin.php?page=ptp-applications')
            )
        );

        wp_send_json_success(array('message' => 'Application submitted successfully!'));
        exit;
    }

    /**
     * AJAX handler for trainer application
     */
    public function ajax_submit_application() {
        if (!wp_verify_nonce($_POST['ptp_apply_nonce'] ?? '', 'ptp_submit_application')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            if (class_exists('PTP_Database')) {
                PTP_Database::create_tables();
            }
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);

        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $location = trim($city . ', ' . $state, ', ');

        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');

        if (empty($first_name) || empty($last_name) || empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
            return;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s",
            $email
        ));

        if ($existing) {
            wp_send_json_error(array('message' => 'An application with this email already exists.'));
            return;
        }

        $data = array(
            'name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'location' => $location,
            'playing_level' => sanitize_text_field($_POST['playing_level'] ?? ''),
            'current_team' => sanitize_text_field($_POST['current_team'] ?? ''),
            'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            wp_send_json_error(array('message' => 'Error saving application. Please try again.'));
            return;
        }

        $application_id = $wpdb->insert_id;

        // Send emails
        $site_name = get_bloginfo('name');
        wp_mail($email, 'Application Received - ' . $site_name,
            "Hi {$first_name},\n\nThanks for applying to become a PTP Coach! We've received your application and will review it within 24-48 hours.\n\n— The PTP Team\n" . home_url()
        );

        wp_mail(get_option('admin_email'), 'New Trainer Application - ' . $full_name,
            "New trainer application received!\n\nName: {$full_name}\nEmail: {$email}\nPhone: {$phone}\nLocation: {$location}\n\nReview: " . admin_url('admin.php?page=ptp-applications')
        );

        wp_send_json_success(array('message' => 'Application submitted successfully!', 'application_id' => $application_id));
    }

    /**
     * Coach application AJAX handler with account creation
     */
    public function ajax_coach_application() {
        if (!wp_verify_nonce($_POST['ptp_coach_nonce'] ?? '', 'ptp_coach_application')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            if (class_exists('PTP_Database')) {
                PTP_Database::create_tables();
            }
        }

        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $location = trim($city . ', ' . $state, ', ');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        // Validate
        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($password)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
            return;
        }

        if (strlen($password) < 8 || $password !== $password_confirm) {
            wp_send_json_error(array('message' => 'Passwords must match and be at least 8 characters.'));
            return;
        }

        if (empty($_POST['agree_contract']) || $_POST['agree_contract'] !== '1') {
            wp_send_json_error(array('message' => 'You must agree to the Trainer Agreement.'));
            return;
        }

        if (email_exists($email)) {
            wp_send_json_error(array('message' => 'An account with this email already exists.'));
            return;
        }

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s", $email));
        if ($existing) {
            wp_send_json_error(array('message' => 'An application with this email already exists.'));
            return;
        }

        $password_hash = wp_hash_password($password);

        $data = array(
            'name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => $password_hash,
            'location' => $location,
            'playing_level' => sanitize_text_field($_POST['playing_level'] ?? ''),
            'team' => sanitize_text_field($_POST['team'] ?? ''),
            'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
            'contractor_agreement_signed' => 1,
            'contractor_agreement_signed_at' => current_time('mysql'),
            'contractor_agreement_ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            wp_send_json_error(array('message' => 'Error saving application. Please try again.'));
            return;
        }

        $application_id = $wpdb->insert_id;

        // Auto-approve: Create WordPress user and trainer record
        $user_data = array(
            'user_login' => $email,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $full_name,
            'role' => 'ptp_trainer'
        );

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            wp_mail($email, 'Application Received - ' . get_bloginfo('name'),
                "Hi {$first_name},\n\nThanks for applying! We'll review your application within 24-48 hours.\n\n— The PTP Team");
        } else {
            $trainers_table = $wpdb->prefix . 'ptp_trainers';

            $slug = sanitize_title($full_name);
            $original_slug = $slug;
            $counter = 1;
            while ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$trainers_table} WHERE slug = %s", $slug))) {
                $slug = $original_slug . '-' . $counter++;
            }

            $trainer_data = array(
                'user_id' => $user_id,
                'display_name' => $full_name,
                'slug' => $slug,
                'email' => $email,
                'phone' => $phone,
                'city' => $city,
                'state' => $state,
                'playing_level' => $data['playing_level'],
                'bio' => $data['bio'],
                'hourly_rate' => 75,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            );

            $wpdb->insert($trainers_table, $trainer_data);
            $trainer_id = $wpdb->insert_id;

            $wpdb->update($table, array('status' => 'approved'), array('id' => $application_id));

            update_user_meta($user_id, 'ptp_trainer_id', $trainer_id);
            update_user_meta($user_id, 'ptp_needs_onboarding', 1);

            wp_mail($email, 'Welcome to PTP - Complete Your Profile',
                "Hi {$first_name},\n\nYour account is ready! Complete your profile to start getting booked.\n\nComplete Profile: " . home_url('/trainer-onboarding/') . "\n\n— The PTP Team");

            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);

            wp_send_json_success(array(
                'message' => 'Application approved!',
                'trainer_id' => $trainer_id,
                'redirect' => home_url('/trainer-onboarding/')
            ));
            return;
        }

        wp_send_json_success(array('message' => 'Application submitted successfully!', 'application_id' => $application_id));
    }

    /**
     * Check plugin status
     */
    public function check_plugin_status() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $tables_exist = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $trainers_table)) == $trainers_table;

        if (!$tables_exist) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>PTP Training Platform:</strong> Database tables not found. ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=ptp-tools')) . '">Go to Tools</a> to create them.</p>';
            echo '</div>';
        }

        // v148: Show WC-independent mode notice
        if (ptp_is_wc_independent()) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>PTP Training Platform v148:</strong> Running in WooCommerce-independent mode with native cart and checkout.</p>';
            echo '</div>';
        }
    }

    /**
     * Override template for login/register pages
     */
    public function maybe_override_template() {
        // Redirect my-account to PTP dashboard
        if (is_page('my-account')) {
            if (is_user_logged_in()) {
                wp_redirect(PTP_User::get_dashboard_url());
            } else {
                wp_redirect(home_url('/login/'));
            }
            exit;
        }

        // Login page
        if (is_page('login')) {
            if (is_user_logged_in() && !current_user_can('manage_options')) {
                wp_redirect(PTP_User::get_dashboard_url());
                exit;
            }
            include PTP_PLUGIN_DIR . 'templates/login.php';
            exit;
        }

        // Register page
        if (is_page('register')) {
            if (is_user_logged_in() && !current_user_can('manage_options')) {
                wp_redirect(PTP_User::get_dashboard_url());
                exit;
            }
            include PTP_PLUGIN_DIR . 'templates/register.php';
            exit;
        }

        // Social login
        if (is_page('social-login')) {
            include PTP_PLUGIN_DIR . 'templates/social-login.php';
            exit;
        }

        // Apply page
        if (is_page('apply')) {
            if (is_user_logged_in()) {
                $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
                if ($trainer) {
                    wp_redirect(home_url('/trainer-dashboard/'));
                    exit;
                }
            }
            include PTP_PLUGIN_DIR . 'templates/apply.php';
            exit;
        }

        // Logout page
        if (is_page('logout')) {
            include PTP_PLUGIN_DIR . 'templates/logout.php';
            exit;
        }

        // Signout redirect
        if (is_page('signout') || is_page('sign-out')) {
            wp_redirect(home_url('/logout/'));
            exit;
        }

        // Trainer dashboard
        if (is_page('trainer-dashboard')) {
            if (!is_user_logged_in()) {
                wp_redirect(home_url('/login/'));
                exit;
            }

            $current_user_id = get_current_user_id();
            $trainer = PTP_Trainer::get_by_user_id($current_user_id);

            if (!$trainer && current_user_can('manage_options') && isset($_GET['trainer_id'])) {
                $trainer = PTP_Trainer::get(intval($_GET['trainer_id']));
            }

            if (!$trainer) {
                wp_redirect(home_url('/apply/'));
                exit;
            }

            if (PTP_Trainer::is_new_trainer($trainer->id) && !isset($_GET['skip_onboarding'])) {
                wp_redirect(home_url('/trainer-onboarding/'));
                exit;
            }

            $upcoming = class_exists('PTP_Booking') ? PTP_Booking::get_trainer_bookings($trainer->id, null, true) ?: array() : array();
            $pending_confirmations = class_exists('PTP_Booking') && method_exists('PTP_Booking', 'get_pending_confirmations') ? PTP_Booking::get_pending_confirmations($trainer->id) ?: array() : array();
            $earnings = class_exists('PTP_Payments') ? PTP_Payments::get_trainer_earnings($trainer->id) ?: array('this_month' => 0, 'total_earnings' => 0, 'pending_payout' => 0) : array('this_month' => 0, 'total_earnings' => 0, 'pending_payout' => 0);
            $availability = class_exists('PTP_Availability') ? PTP_Availability::get_weekly($trainer->id) ?: array() : array();
            $completion = method_exists('PTP_Trainer', 'get_profile_completion_status') ? PTP_Trainer::get_profile_completion_status($trainer) ?: array('percentage' => 100, 'missing' => array()) : array('percentage' => 100, 'missing' => array());
            $show_welcome = isset($_GET['welcome']) && $_GET['welcome'] == '1';

            include PTP_PLUGIN_DIR . 'templates/trainer-dashboard-v117.php';
            exit;
        }

        // Account settings
        if (is_page('account')) {
            if (!is_user_logged_in()) {
                wp_redirect(home_url('/login/'));
                exit;
            }
            include PTP_PLUGIN_DIR . 'templates/account.php';
            exit;
        }

        // Parent dashboard
        if (is_page('parent-dashboard') || is_page('my-training')) {
            if (!is_user_logged_in()) {
                wp_redirect(home_url('/login/'));
                exit;
            }
            include PTP_PLUGIN_DIR . 'templates/parent-dashboard-v117.php';
            exit;
        }

        // v148: Native cart page
        if (is_page('ptp-cart') || is_page('cart')) {
            include PTP_PLUGIN_DIR . 'templates/ptp-cart.php';
            exit;
        }

        // v148: Native checkout page
        if (is_page('ptp-checkout') || is_page('training-checkout')) {
            include PTP_PLUGIN_DIR . 'templates/ptp-checkout.php';
            exit;
        }
    }

    /**
     * WooCommerce login redirect
     */
    public function woo_login_redirect($redirect, $user) {
        return PTP_User::get_dashboard_url($user->ID);
    }

    /**
     * Handle login failures
     */
    public function handle_login_failure($username) {
        $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

        if (strpos($referrer, '/login/') !== false || strpos($referrer, 'wp-login.php') !== false) {
            wp_redirect(home_url('/login/?login=failed'));
            exit;
        }
    }

    /**
     * Custom login redirect
     */
    public function custom_login_redirect($redirect_to, $requested_redirect, $user) {
        if (is_wp_error($user)) {
            return $redirect_to;
        }

        if (!empty($requested_redirect) && strpos($requested_redirect, 'wp-admin') === false) {
            return $redirect_to;
        }

        if ($user && isset($user->ID)) {
            $trainer = PTP_Trainer::get_by_user_id($user->ID);
            if ($trainer) {
                return home_url('/trainer-dashboard/');
            }
            return home_url('/parent-dashboard/');
        }

        return $redirect_to;
    }

    /**
     * Add security headers
     */
    public function add_security_headers() {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        header('X-Content-Type-Options: nosniff');

        if (!is_page('ptp-checkout') && !is_page('checkout')) {
            header('X-Frame-Options: SAMEORIGIN');
        }

        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
    }

    public function init() {
        load_plugin_textdomain('ptp-training', false, dirname(PTP_PLUGIN_BASENAME) . '/languages');

        if (class_exists('PTP_Database')) {
            PTP_Database::quick_repair();
        }

        $this->ensure_roles_exist();

        $critical_pages_missing = !get_page_by_path('trainer')
            || !get_page_by_path('ptp-cart')
            || !get_page_by_path('ptp-checkout')
            || !get_page_by_path('training-checkout');

        if (is_admin() || $critical_pages_missing) {
            $this->maybe_create_pages();
        }

        // Core functionality (all WC-independent)
        PTP_Ajax::init();
        PTP_Shortcodes::init();
        PTP_Templates::init();
        PTP_Availability::init();
        PTP_Trainer_Payouts::init();

        // Integrations
        PTP_Email::init();
        PTP_Stripe::init();
        PTP_SEO::init();
        PTP_Social::init();
        PTP_Geocoding::init();
        PTP_Cron::init();
        PTP_Onboarding_Reminders::init();

        // v148: Only initialize WC classes if WC is active
        if (ptp_wc_active() && class_exists('PTP_WooCommerce')) {
            PTP_WooCommerce::init();
            if (class_exists('PTP_WooCommerce_Emails')) {
                PTP_WooCommerce_Emails::instance();
            }
            if (class_exists('PTP_Order_Email_Wiring')) {
                PTP_Order_Email_Wiring::instance();
            }
            if (class_exists('PTP_Unified_Checkout')) {
                PTP_Unified_Checkout::instance();
            }
        }

        if (class_exists('PTP_Social_Announcement')) {
            PTP_Social_Announcement::instance();
        }

        add_action('ptp_send_booking_emails', array('PTP_Ajax', 'do_send_booking_emails'));

        // Advanced features
        PTP_Recurring::init();
        PTP_Groups::init();
        PTP_Calendar_Sync::init();
        PTP_Training_Plans::init();
        PTP_Tax_Reporting::init();
        PTP_REST_API::init();
        PTP_Push_Notifications::init();

        // Rewrite rules
        add_rewrite_rule('^trainer/([^/]+)/?$', 'index.php?pagename=trainer&trainer_slug=$matches[1]', 'top');
        add_rewrite_tag('%trainer_slug%', '([^&]+)');
        add_rewrite_rule('^find-trainers/([^/]+)/?$', 'index.php?pagename=find-trainers&trainer_location=$matches[1]', 'top');
        add_rewrite_tag('%trainer_location%', '([^&]+)');

        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('request', array($this, 'filter_trainer_request'), 1);

        $flush_version = get_option('ptp_rewrite_flush_version', '0');
        if (version_compare($flush_version, PTP_VERSION, '<')) {
            flush_rewrite_rules();
            update_option('ptp_rewrite_flush_version', PTP_VERSION);
        }

        add_action('template_redirect', array($this, 'redirect_old_trainer_urls'));
        add_action('template_redirect', array($this, 'redirect_checkout_with_trainer'), 1);
        add_action('template_redirect', array($this, 'catch_trainer_404'), 1);
    }

    public function add_query_vars($vars) {
        $vars[] = 'trainer_slug';
        $vars[] = 'trainer_location';
        return $vars;
    }

    public function filter_trainer_request($query_vars) {
        if (isset($query_vars['pagename']) && $query_vars['pagename'] === 'trainer' && !isset($query_vars['trainer_slug'])) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

            if (preg_match('#^trainer/([^/]+)/?$#', $path, $matches)) {
                $query_vars['trainer_slug'] = sanitize_text_field($matches[1]);
            }
        }
        return $query_vars;
    }

    public function catch_trainer_404() {
        if (!is_404()) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

        $home_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if ($home_path && strpos($path, $home_path) === 0) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }

        if (preg_match('#^trainer/([^/]+)/?$#', $path, $matches)) {
            $trainer_slug = sanitize_text_field($matches[1]);

            if (strpos($trainer_slug, '.') !== false) {
                return;
            }

            set_query_var('trainer_slug', $trainer_slug);

            $trainer_page = get_page_by_path('trainer');
            if (!$trainer_page) {
                wp_insert_post(array(
                    'post_title' => 'Trainer Profile',
                    'post_name' => 'trainer',
                    'post_content' => '[ptp_trainer_profile]',
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ));
                $trainer_page = get_page_by_path('trainer');
            }

            if ($trainer_page) {
                global $wp_query, $post;

                $wp_query->is_404 = false;
                $wp_query->is_page = true;
                $wp_query->is_singular = true;
                $wp_query->queried_object = $trainer_page;
                $wp_query->queried_object_id = $trainer_page->ID;
                $post = $trainer_page;

                $wp_query->posts = array($trainer_page);
                $wp_query->post_count = 1;
                $wp_query->found_posts = 1;

                status_header(200);

                include(get_page_template());
                exit;
            }
        }
    }

    public function redirect_old_trainer_urls() {
        if (is_page('trainer-profile') && !empty($_GET['trainer'])) {
            $trainer_slug = sanitize_text_field($_GET['trainer']);
            wp_redirect(home_url('/trainer/' . $trainer_slug . '/'), 301);
            exit;
        }
    }

    public function redirect_checkout_with_trainer() {
        if (!is_page('checkout') && !is_page('woocommerce-checkout')) {
            return;
        }

        if (isset($_GET['trainer_id']) || isset($_GET['trainer'])) {
            $params = $_GET;
            wp_redirect(add_query_arg($params, home_url('/training-checkout/')));
            exit;
        }
    }

    public function ensure_roles_exist() {
        if (!get_role('ptp_trainer')) {
            add_role('ptp_trainer', 'PTP Trainer', array(
                'read' => true,
                'edit_posts' => false,
                'delete_posts' => false,
                'upload_files' => true,
            ));
        }

        if (!get_role('ptp_parent')) {
            add_role('ptp_parent', 'PTP Parent', array(
                'read' => true,
                'edit_posts' => false,
                'delete_posts' => false,
            ));
        }
    }

    public function maybe_create_pages() {
        $pages = array(
            'trainer' => array(
                'title' => 'Trainer Profile',
                'content' => '[ptp_trainer_profile]'
            ),
            'find-trainers' => array(
                'title' => 'Find Trainers',
                'content' => '[ptp_find_trainers]'
            ),
            'ptp-cart' => array(
                'title' => 'Cart',
                'content' => '[ptp_cart]'
            ),
            'ptp-checkout' => array(
                'title' => 'Checkout',
                'content' => '[ptp_checkout]'
            ),
            'training-checkout' => array(
                'title' => 'Training Checkout',
                'content' => '[ptp_training_checkout]'
            ),
            'thank-you' => array(
                'title' => 'Thank You',
                'content' => '[ptp_thank_you]'
            ),
            'login' => array(
                'title' => 'Login',
                'content' => '[ptp_login]'
            ),
            'register' => array(
                'title' => 'Register',
                'content' => '[ptp_register]'
            ),
            'apply' => array(
                'title' => 'Apply to Coach',
                'content' => '[ptp_apply]'
            ),
            'trainer-dashboard' => array(
                'title' => 'Trainer Dashboard',
                'content' => '[ptp_trainer_dashboard]'
            ),
            'parent-dashboard' => array(
                'title' => 'My Training',
                'content' => '[ptp_parent_dashboard]'
            ),
            'trainer-onboarding' => array(
                'title' => 'Complete Your Profile',
                'content' => '[ptp_trainer_onboarding]'
            ),
            'account' => array(
                'title' => 'Account Settings',
                'content' => '[ptp_account]'
            ),
            'logout' => array(
                'title' => 'Logout',
                'content' => '[ptp_logout]'
            ),
        );

        foreach ($pages as $slug => $page) {
            if (!get_page_by_path($slug)) {
                wp_insert_post(array(
                    'post_title' => $page['title'],
                    'post_name' => $slug,
                    'post_content' => $page['content'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ));
            }
        }
    }

    public function enqueue_scripts() {
        wp_enqueue_style('ptp-main', PTP_PLUGIN_URL . 'assets/css/ptp-main.css', array(), PTP_VERSION);
        wp_enqueue_style('ptp-mobile', PTP_PLUGIN_URL . 'assets/css/ptp-mobile-v88.css', array(), PTP_VERSION);

        wp_enqueue_script('ptp-main', PTP_PLUGIN_URL . 'assets/js/ptp-main.js', array('jquery'), PTP_VERSION, true);

        wp_localize_script('ptp-main', 'ptp_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_ajax_nonce'),
            'home_url' => home_url(),
            'cart_url' => home_url('/ptp-cart/'),
            'checkout_url' => home_url('/ptp-checkout/'),
            'wc_independent' => ptp_is_wc_independent(),
        ));
    }

    public function dequeue_theme_on_ptp_pages() {
        // Dequeue conflicting theme styles on PTP pages
        if (is_page(array('trainer-dashboard', 'parent-dashboard', 'login', 'register', 'apply', 'trainer-onboarding', 'ptp-checkout', 'ptp-cart'))) {
            wp_dequeue_style('theme-style');
            wp_dequeue_style('elementor-frontend');
        }
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'ptp') !== false) {
            wp_enqueue_style('ptp-admin', PTP_PLUGIN_URL . 'assets/css/admin.css', array(), PTP_VERSION);
            wp_enqueue_script('ptp-admin', PTP_PLUGIN_URL . 'assets/js/ptp-admin.js', array('jquery'), PTP_VERSION, true);
        }
    }

    public function activate() {
        // Create database tables
        PTP_Database::create_tables();

        // v148: Create native session/cart/order tables
        PTP_Native_Session::create_table();
        PTP_Native_Cart::create_table();
        PTP_Native_Order_Manager::create_tables();

        // Add performance indexes
        PTP_Database::add_performance_indexes();

        // Create roles
        $this->ensure_roles_exist();

        // Create pages
        $this->maybe_create_pages();

        // Set default options
        if (get_option('ptp_platform_fee') === false) {
            update_option('ptp_platform_fee', 20);
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set version
        update_option('ptp_version', PTP_VERSION);
    }

    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('ptp_cleanup_native_sessions');
        wp_clear_scheduled_hook('ptp_cron_hourly');
        wp_clear_scheduled_hook('ptp_cron_daily');

        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

// Initialize the plugin
function PTP() {
    return PTP_Training_Platform::instance();
}

// Start the plugin
add_action('plugins_loaded', function() {
    PTP();
}, 10);
