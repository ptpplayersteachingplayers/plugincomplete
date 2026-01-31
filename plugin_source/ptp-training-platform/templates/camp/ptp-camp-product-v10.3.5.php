<?php
/**
 * PTP Camp Product Template v10.3.5
 * CLEAN VERSION - Won't affect rest of site
 * 
 * @version 10.3.5
 * 
 * Changes in v10.3.5:
 * - Added training platform conflict fixes in inline CSS
 * - Added Flavor theme isolation (hide duplicate headers)
 * - Fixed button/element sizing conflicts with ptp-mobile-fixes-v127
 * - Enhanced body padding and admin bar handling
 * - INTEGRATION: Sets ptp99_camps_in_cart session for checkout v99
 * - INTEGRATION: Sets ptp99_upgrade_pack to empty when multiweek selected
 * 
 * REQUIRES: class-ptp-camp-checkout-v99.php for full multiweek integration
 * 
 * Changes in v10.3.4:
 * - Unified multipack pricing with checkout v98 (10% for 2, 20% for 3+)
 * - Added critical inline CSS for mobile visibility
 * - Added fallback hooks for better theme compatibility
 * - Removed Astra theme hooks that interfere on mobile
 * - All changes scoped to body.ptp-camp-page
 */

if (!defined('ABSPATH')) exit;

class PTP_Camp_Product_Template_V10 {
    private static $instance = null;
    const VERSION = '10.3.5';
    
    // EARLY BIRD DEADLINE - February 16, 2026 11:59 PM EST
    const EARLY_BIRD_DEADLINE = '2026-02-16 23:59:59';
    const EARLY_BIRD_TIMEZONE = 'America/New_York';

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() { $this->init_hooks(); }

    private function init_hooks() {
        add_filter('template_include', array($this, 'load_camp_template'), 99);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_head', array($this, 'add_preload_hints'), 1);
        add_filter('body_class', array($this, 'add_body_classes'));
        add_action('wp_footer', array($this, 'output_inline_js'), 99);
        add_filter('woocommerce_add_to_cart_redirect', array($this, 'redirect_to_checkout'));
        add_action('add_meta_boxes', array($this, 'add_camp_meta_boxes'));
        add_action('woocommerce_process_product_meta', array($this, 'save_camp_meta'));
        add_action('wp_ajax_ptp_add_pack_to_cart', array($this, 'ajax_add_pack_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_pack_to_cart', array($this, 'ajax_add_pack_to_cart'));
        add_action('wp_ajax_ptp_add_multiple_weeks', array($this, 'ajax_add_multiple_weeks'));
        add_action('wp_ajax_nopriv_ptp_add_multiple_weeks', array($this, 'ajax_add_multiple_weeks'));
        // Apply multiweek discount when multiple camps in cart (priority 25 to run before checkout v98's fees at priority 30)
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_multiweek_discount'), 25);
    }

    public static function is_early_bird_active() {
        $tz = new DateTimeZone(self::EARLY_BIRD_TIMEZONE);
        $now = new DateTime('now', $tz);
        $deadline = new DateTime(self::EARLY_BIRD_DEADLINE, $tz);
        return $now <= $deadline;
    }

    public static function get_early_bird_data() {
        $tz = new DateTimeZone(self::EARLY_BIRD_TIMEZONE);
        $now = new DateTime('now', $tz);
        $deadline = new DateTime(self::EARLY_BIRD_DEADLINE, $tz);
        
        $diff = $now->diff($deadline);
        $total_seconds = $deadline->getTimestamp() - $now->getTimestamp();
        
        return array(
            'is_active' => $total_seconds > 0,
            'deadline' => self::EARLY_BIRD_DEADLINE,
            'deadline_formatted' => 'Feb 16',
            'days_left' => max(0, $diff->days),
            'hours_left' => max(0, $diff->h),
            'minutes_left' => max(0, $diff->i),
            'discount' => 50,
            'total_seconds' => max(0, $total_seconds)
        );
    }

    private function get_ptp_checkout_url() {
        // Always use PTP checkout for camp products
        return home_url('/ptp-checkout/');
    }

    public function ajax_add_pack_to_cart() {
        check_ajax_referer('ptp_pack_nonce', 'nonce');
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        if (!$product_id) wp_send_json_error(array('message' => 'Invalid product'));
        if (!function_exists('WC') || !WC() || !WC()->cart) wp_send_json_error(array('message' => 'Cart not available'));
        WC()->cart->empty_cart();
        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
        if ($cart_item_key) {
            wp_send_json_success(array('redirect' => $this->get_ptp_checkout_url(), 'cart_item_key' => $cart_item_key));
        } else {
            wp_send_json_error(array('message' => 'Could not add to cart'));
        }
    }

    public function ajax_add_multiple_weeks() {
        check_ajax_referer('ptp_pack_nonce', 'nonce');
        
        $product_ids = isset($_POST['product_ids']) ? array_map('absint', (array)$_POST['product_ids']) : array();
        $add_world_cup_jersey = isset($_POST['add_world_cup_jersey']) && $_POST['add_world_cup_jersey'] === 'true';
        
        if (empty($product_ids)) {
            wp_send_json_error(array('message' => 'No weeks selected'));
        }
        
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            wp_send_json_error(array('message' => 'Cart not available'));
        }
        
        // Clear cart and add selected camps
        WC()->cart->empty_cart();
        
        $count = count($product_ids);
        foreach ($product_ids as $product_id) {
            WC()->cart->add_to_cart($product_id, 1);
        }
        
        // INTEGRATION WITH class-ptp-camp-checkout-v98.php:
        // Set session flags that checkout v98 will recognize
        if (WC()->session) {
            // Store selection info
            WC()->session->set('ptp_multiweek_selection', array(
                'product_ids' => $product_ids,
                'count' => $count,
                'selected_from_product_page' => true
            ));
            
            // IMPORTANT: Set flag to hide upgrade bumps on checkout
            // This tells checkout v98 that user already selected multiple camps
            WC()->session->set('ptp99_camps_in_cart', $count);
            
            // Clear any previous upgrade_pack selection (user is replacing it with direct selection)
            WC()->session->set('ptp99_upgrade_pack', '');
        }
        
        // Handle World Cup Jersey add-on
        if ($add_world_cup_jersey && WC()->session) {
            WC()->session->set('ptp99_jersey', true);
        }
        
        wp_send_json_success(array(
            'redirect' => $this->get_ptp_checkout_url(),
            'items_added' => $count
        ));
    }
    
    /**
     * Apply multiweek discount at checkout
     * Hooked into woocommerce_cart_calculate_fees at priority 25 (before checkout v98 at 30)
     * 
     * INTEGRATION WITH class-ptp-camp-checkout-v98.php:
     * - Sets ptp99_camps_in_cart session to tell checkout how many camps are in cart
     * - Checkout v98 should hide upgrade bumps when this is > 1
     */
    public function apply_multiweek_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        
        // Don't apply if checkout's upgrade_pack is active (to avoid double discount)
        if (WC()->session && WC()->session->get('ptp99_upgrade_pack')) {
            return;
        }
        
        // Count camp products in cart
        $camp_count = 0;
        $camp_total = 0;
        
        foreach ($cart->get_cart() as $item) {
            $pid = $item['product_id'];
            $product = wc_get_product($pid);
            if (!$product) continue;
            
            $is_camp = has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $pid) ||
                       stripos($product->get_name(), 'camp') !== false ||
                       stripos($product->get_name(), 'clinic') !== false;
            
            if ($is_camp) {
                $camp_count += $item['quantity'];
                $camp_total += $item['line_total'];
            }
        }
        
        // INTEGRATION: Tell checkout v98 how many camps are in cart
        // This allows checkout to hide upgrade bumps when multiple camps already selected
        if (WC()->session && $camp_count > 0) {
            WC()->session->set('ptp99_camps_in_cart', $camp_count);
        }
        
        // Apply multiweek discount (UNIFIED WITH checkout v98 CAMP_PACKS)
        // 2 camps = 10% off, 3+ camps = 20% off
        if ($camp_count >= 3) {
            $discount = round($camp_total * 0.20, 2);
            $cart->add_fee('3-Camp Pack Discount (20% off)', -$discount, false);
        } else if ($camp_count == 2) {
            $discount = round($camp_total * 0.10, 2);
            $cart->add_fee('2-Camp Pack Discount (10% off)', -$discount, false);
        }
    }

    public function redirect_to_checkout($url) {
        if (is_product() && $this->is_camp_product()) return $this->get_ptp_checkout_url();
        return $url;
    }

    public function load_camp_template($template) {
        if (is_product() && $this->is_camp_product()) {
            // Remove all default WooCommerce product elements
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);
            remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);
            remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);
            remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15);
            remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
            
            // Remove additional hooks that might interfere
            remove_action('woocommerce_before_single_product', 'woocommerce_output_all_notices', 10);
            
            // Remove Astra theme hooks that might interfere on mobile
            remove_action('woocommerce_before_single_product_summary', 'astra_woo_single_product_images', 20);
            remove_action('woocommerce_single_product_summary', 'astra_woo_product_summary', 5);
            
            // Add our template - SINGLE HOOK ONLY to prevent double rendering
            add_action('woocommerce_before_single_product', array($this, 'render_camp_template_once'), 5);
        }
        return $template;
    }
    
    /**
     * Render template only once (prevents double rendering)
     */
    private $template_rendered = false;
    
    public function render_camp_template_once() {
        if ($this->template_rendered) return;
        $this->template_rendered = true;
        $this->render_camp_template();
    }

    private function is_camp_product() {
        global $post;
        if (!$post) return false;
        
        $product_id = $post->ID;
        $product_name = strtolower($post->post_title);
        
        // Check 1: Product title contains camp/clinic keywords
        $title_match = (
            strpos($product_name, 'camp') !== false ||
            strpos($product_name, 'clinic') !== false ||
            strpos($product_name, 'soccer camp') !== false ||
            strpos($product_name, 'summer camp') !== false ||
            strpos($product_name, 'ptp camp') !== false
        );
        
        // Check 2: WooCommerce product category (matches checkout v99 detection)
        $category_match = has_term(
            array('camps', 'clinics', 'camp', 'clinic', 'summer-camps', 'soccer-camps'),
            'product_cat',
            $product_id
        );
        
        // Check 3: Product type or custom meta
        $meta_match = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes';
        
        $is_camp = $title_match || $category_match || $meta_match;
        
        return apply_filters('ptp_is_camp_product', $is_camp, $product_id);
    }

    public function enqueue_assets() {
        if (!is_product() || !$this->is_camp_product()) return;
        wp_enqueue_style('ptp-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Oswald:wght@500;600;700&display=swap', array(), null);
        
        // Check multiple locations for CSS file
        $css_locations = array(
            // 1. Plugin assets folder (if loaded from plugin)
            array(
                'url' => plugins_url('ptp-training-platform/assets/css/ptp-camp-product-v10.3.5.css'),
                'file' => WP_PLUGIN_DIR . '/ptp-training-platform/assets/css/ptp-camp-product-v10.3.5.css'
            ),
            // 2. Uploads folder (if using Code Snippets method)
            array(
                'url' => content_url('/uploads/ptp-camp-product-v10.3.5.css'),
                'file' => WP_CONTENT_DIR . '/uploads/ptp-camp-product-v10.3.5.css'
            ),
        );
        
        $css_url = '';
        $version = self::VERSION;
        
        foreach ($css_locations as $loc) {
            if (file_exists($loc['file'])) {
                $css_url = $loc['url'];
                $version = filemtime($loc['file']);
                break;
            }
        }
        
        // Fallback to uploads URL even if file doesn't exist yet
        if (empty($css_url)) {
            $css_url = content_url('/uploads/ptp-camp-product-v10.3.5.css');
        }
        
        wp_enqueue_style('ptp-camp-v10', $css_url, array('ptp-fonts'), $version);
    }

    public function add_preload_hints() {
        if (!is_product() || !$this->is_camp_product()) return;
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        
        // CRITICAL: Inline CSS to ensure mobile visibility before external CSS loads
        // This prevents any flash of hidden content and overrides header v6.3 rules
        // Also counters ptp-training-platform, Flavor theme, and Astra conflicts
        echo '<style id="ptp-camp-critical-mobile">
            /* =============================================
               GLOBAL HEADER PROTECTION
               These rules ensure the site header is NEVER
               affected by camp template styles
            ============================================= */
            
            /* Protect PTP global header - use !important to override everything */
            html body .ptp-hdr,
            html body .ptp-header,
            html body header.ptp-hdr,
            html body header.ptp-header,
            body.ptp-camp-page .ptp-hdr,
            body.ptp-camp-page .ptp-header {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                height: auto !important;
                min-height: 56px !important;
                max-height: none !important;
                z-index: 999999 !important;
                background: #0A0A0A !important;
                transform: none !important;
                opacity: 1 !important;
                visibility: visible !important;
                display: block !important;
                overflow: visible !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            /* Admin bar header adjustment */
            body.admin-bar .ptp-hdr,
            body.admin-bar .ptp-header,
            body.admin-bar.ptp-camp-page .ptp-hdr {
                top: 32px !important;
            }
            @media (max-width: 782px) {
                body.admin-bar .ptp-hdr,
                body.admin-bar .ptp-header,
                body.admin-bar.ptp-camp-page .ptp-hdr {
                    top: 46px !important;
                }
            }
            
            /* =============================================
               BODY PADDING FOR FIXED HEADER
            ============================================= */
            body.ptp-camp-page {
                padding-top: 56px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                position: static !important;
            }
            @media (min-width: 480px) {
                body.ptp-camp-page { padding-top: 60px !important; }
            }
            @media (min-width: 640px) {
                body.ptp-camp-page { padding-top: 64px !important; }
            }
            @media (min-width: 768px) {
                body.ptp-camp-page { padding-top: 68px !important; }
            }
            @media (min-width: 992px) {
                body.ptp-camp-page { padding-top: 70px !important; }
            }
            
            /* Admin bar body padding */
            body.admin-bar.ptp-camp-page { padding-top: calc(56px + 32px) !important; }
            @media (max-width: 782px) {
                body.admin-bar.ptp-camp-page { padding-top: calc(56px + 46px) !important; }
            }
            
            /* =============================================
               FLAVOR/ASTRA THEME FIXES
            ============================================= */
            body.ptp-camp-page .flavor-header,
            body.ptp-camp-page .flavor-nav,
            body.ptp-camp-page #flavor-header,
            body.ptp-camp-page .site-header:not(.ptp-hdr):not(.ptp-header),
            body.ptp-camp-page .flavor-wrapper > header:first-child,
            body.ptp-camp-page .flavor-wrapper .site-header,
            body.ptp-camp-page .header-spacer,
            body.ptp-camp-page .nav-spacer,
            body.ptp-camp-page .top-header-spacer,
            body.ptp-camp-page .ast-above-header,
            body.ptp-camp-page .ast-below-header {
                display: none !important;
            }
            
            /* Remove wrapper gaps */
            body.ptp-camp-page .flavor-wrapper,
            body.ptp-camp-page #flavor-wrapper,
            body.ptp-camp-page .ast-container,
            body.ptp-camp-page #page,
            body.ptp-camp-page #content,
            body.ptp-camp-page .site-content {
                padding-top: 0 !important;
                margin-top: 0 !important;
            }
            
            /* =============================================
               CAMP WRAPPER VISIBILITY
            ============================================= */
            html body.single-product.ptp-camp-page #ptp-camp-wrapper.ptp-v10,
            html body.ptp-camp-page #ptp-camp-wrapper,
            body.ptp-camp-page #ptp-camp-wrapper {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                position: relative !important;
                width: 100% !important;
                min-height: 100px !important;
                z-index: 1 !important;
                isolation: isolate;
            }
            
            /* Hide default WooCommerce */
            body.ptp-camp-page .woocommerce-product-gallery,
            body.ptp-camp-page .summary.entry-summary,
            body.ptp-camp-page div.product > div.images,
            body.ptp-camp-page div.product > div.summary {
                display: none !important;
            }
            
            /* =============================================
               MOBILE COMPACT OVERRIDES
            ============================================= */
            @media (max-width: 767px) {
                /* Reset training platform button sizes */
                body.ptp-camp-page #ptp-camp-wrapper button,
                body.ptp-camp-page #ptp-camp-wrapper .btn,
                body.ptp-camp-page #ptp-camp-wrapper .button,
                body.ptp-camp-page #ptp-camp-wrapper .quick-link,
                body.ptp-camp-page #ptp-camp-wrapper .scroll-btn,
                body.ptp-camp-page #ptp-camp-wrapper .week-option,
                body.ptp-camp-page #ptp-camp-wrapper .faq-q {
                    min-height: auto !important;
                    min-width: auto !important;
                }
                
                /* Ensure sections visible */
                body.ptp-camp-page #ptp-camp-wrapper .hero,
                body.ptp-camp-page #ptp-camp-wrapper .hero-two-col,
                body.ptp-camp-page #ptp-camp-wrapper section {
                    display: block !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    width: 100% !important;
                }
                
                body.ptp-camp-page #ptp-camp-wrapper .hero-two-col {
                    display: flex !important;
                    flex-direction: column !important;
                }
            }
        </style>' . "\n";
    }

    public function add_body_classes($classes) {
        if (is_product() && $this->is_camp_product()) {
            $classes[] = 'ptp-camp-page';
            $classes[] = 'ptp-camp-product';
            $classes[] = 'ptp-template-v10';
        }
        return $classes;
    }

    public function add_camp_meta_boxes() {
        add_meta_box('ptp_camp_details', 'PTP Camp Details v10', array($this, 'render_camp_meta_box'), 'product', 'normal', 'high');
    }

    public function render_camp_meta_box($post) {
        wp_nonce_field('ptp_camp_meta', 'ptp_camp_meta_nonce');
        $fields = array(
            '_camp_hero_video' => 'Hero Video URL',
            '_founder_photo' => 'Founder Photo URL',
            '_hero_badge' => 'Hero Badge Text',
            '_hero_headline' => 'Hero Headline (use <span> for gold)',
            '_camp_start_date' => 'Camp Start Date (YYYY-MM-DD)',
            '_camp_end_date' => 'Camp End Date (YYYY-MM-DD)',
            '_camp_time' => 'Camp Time',
            '_camp_venue_name' => 'Venue Name',
            '_camp_venue_address' => 'Venue Full Address',
            '_camp_map_embed' => 'Google Maps Embed URL',
            '_camp_map_link' => 'Google Maps Link',
            '_age_range' => 'Age Range',
        );
        echo '<table class="form-table"><tbody>';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, $key, true);
            echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td><input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="large-text"></td></tr>';
        }
        echo '</tbody></table>';
    }

    public function save_camp_meta($post_id) {
        if (!isset($_POST['ptp_camp_meta_nonce']) || !wp_verify_nonce($_POST['ptp_camp_meta_nonce'], 'ptp_camp_meta')) return;
        $fields = array('_camp_hero_video', '_founder_photo', '_hero_badge', '_hero_headline', '_camp_start_date', '_camp_end_date', '_camp_time', '_camp_venue_name', '_camp_venue_address', '_camp_map_embed', '_camp_map_link', '_age_range');
        foreach ($fields as $field) {
            if (isset($_POST[$field])) update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }
    }

    public static function get_camp_meta($product_id, $key, $default = '') {
        $value = get_post_meta($product_id, $key, true);
        return !empty($value) ? $value : $default;
    }

    public static function get_smart_location($product_id) {
        // First check for manual venue override
        $manual_venue = self::get_camp_meta($product_id, '_camp_venue_name', '');
        if (!empty($manual_venue)) return $manual_venue;
        
        $product = wc_get_product($product_id);
        if (!$product) return 'TBD';
        
        $title = $product->get_name();
        
        // Primary venue locations
        $locations = array(
            // Main PTP Fields
            'USTC' => 'United Sports Center (USTC)',
            'United Sports' => 'United Sports Center (USTC)',
            'Radnor Memorial' => 'Radnor Memorial Park',
            'Radnor Park' => 'Radnor Memorial Park',
            'Decou' => 'Decou Field',
            'Wilson Farm' => 'Wilson Farm Park',
            'Wilson Park' => 'Wilson Farm Park',
            'Sleighton' => 'Sleighton Park',
            
            // Backup patterns for towns (if no specific field found)
            'Radnor' => 'Radnor Memorial Park',
            'Downingtown' => 'Downingtown',
            'West Chester' => 'West Chester',
            'Exton' => 'Exton',
            'Malvern' => 'Malvern',
            'Media' => 'Media',
            'Wayne' => 'Wayne',
            'Devon' => 'Devon',
        );
        
        foreach ($locations as $pattern => $full_name) {
            if (stripos($title, $pattern) !== false) {
                return $full_name;
            }
        }
        
        // Try to extract location after common separators
        $separators = array(' - ', ' @ ', ' at ', ' | ');
        foreach ($separators as $sep) {
            if (stripos($title, $sep) !== false) {
                $parts = explode($sep, $title);
                if (count($parts) > 1) {
                    $potential_location = trim(end($parts));
                    $potential_location = preg_replace('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}[-–]\d{1,2}\b/i', '', $potential_location);
                    $potential_location = trim($potential_location, ' ,-');
                    if (!empty($potential_location) && strlen($potential_location) > 3) {
                        return $potential_location;
                    }
                }
            }
        }
        
        return 'Philadelphia Area';
    }

    public static function get_smart_camp_date($product_id) {
        $start = self::get_camp_meta($product_id, '_camp_start_date', '');
        $end = self::get_camp_meta($product_id, '_camp_end_date', '');
        if ($start && $end) {
            $start_date = date_create($start);
            $end_date = date_create($end);
            if ($start_date && $end_date) return date_format($start_date, 'M j') . '-' . date_format($end_date, 'j');
        }
        $product = wc_get_product($product_id);
        if (!$product) return 'Summer 2026';
        $title = $product->get_name();
        if (preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\s*[-–]\s*(\d{1,2})(?:,?\s*(\d{4}))?/i', $title, $matches)) {
            return substr($matches[1], 0, 3) . ' ' . $matches[2] . '-' . $matches[3];
        }
        return 'Summer 2026';
    }

    public static function get_smart_camp_time($product_id) {
        // First check manual meta field
        $manual_time = self::get_camp_meta($product_id, '_camp_time', '');
        if (!empty($manual_time)) return $manual_time;
        
        // Try to extract time from product title
        $product = wc_get_product($product_id);
        if ($product) {
            $title = $product->get_name();
            
            // Match common time patterns in title
            // e.g., "9am-12pm", "9AM - 12PM", "9:00am-12:00pm", "9am-3pm", "9AM - 3PM"
            if (preg_match('/(\d{1,2}(?::\d{2})?\s*(?:am|AM))\s*[-–—]\s*(\d{1,2}(?::\d{2})?\s*(?:pm|PM|noon|NOON))/i', $title, $matches)) {
                return strtoupper($matches[1]) . ' - ' . strtoupper($matches[2]);
            }
        }
        
        return '9AM - 3PM';
    }

    public static function get_full_address($product_id) {
        $address = self::get_camp_meta($product_id, '_camp_venue_address', '');
        if (!empty($address)) return $address;
        return self::get_smart_location($product_id) . ', PA';
    }

    public static function get_pack_pricing($product) {
        $base_price = floatval($product->get_price());
        // UNIFIED WITH CHECKOUT: Match class-ptp-camp-checkout-v98.php CAMP_PACKS
        return array(
            'single' => array('quantity' => 1, 'total' => $base_price, 'savings' => 0, 'per_week' => $base_price, 'discount_pct' => 0),
            'pack_2' => array('quantity' => 2, 'total' => round($base_price * 2 * 0.90), 'savings' => round($base_price * 2 * 0.10), 'per_week' => round($base_price * 0.90), 'discount_pct' => 10),
            'pack_3' => array('quantity' => 3, 'total' => round($base_price * 3 * 0.80), 'savings' => round($base_price * 3 * 0.20), 'per_week' => round($base_price * 0.80), 'discount_pct' => 20),
        );
    }

    /**
     * Get all available camp weeks/products for multipack selection
     * Pulls ALL WooCommerce products that are camp products
     */
    public static function get_available_weeks($current_product_id) {
        $weeks = array();
        
        // Method 1: Try to get by product category first (most reliable)
        $camp_category_ids = array();
        $camp_categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'search' => 'camp'
        ));
        
        if (!is_wp_error($camp_categories) && !empty($camp_categories)) {
            foreach ($camp_categories as $cat) {
                $camp_category_ids[] = $cat->term_id;
            }
        }
        
        // Build the query args
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 50, // Get up to 50 camp products
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        );
        
        // If we found camp categories, use tax_query
        if (!empty($camp_category_ids)) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $camp_category_ids,
                    'operator' => 'IN'
                )
            );
        }
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                
                if (!$product || !$product->is_in_stock()) {
                    continue;
                }
                
                // Check if this is a camp product by title
                $product_name = strtolower($product->get_name());
                $is_camp = (
                    strpos($product_name, 'camp') !== false || 
                    strpos($product_name, 'soccer camp') !== false || 
                    strpos($product_name, 'summer camp') !== false || 
                    strpos($product_name, 'ptp camp') !== false ||
                    strpos($product_name, 'week') !== false
                );
                
                // If using category query, trust it; otherwise filter by name
                if (!empty($camp_category_ids) || $is_camp) {
                    $weeks[] = array(
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'price' => $product->get_price(),
                        'date' => self::get_smart_camp_date($product_id),
                        'location' => self::get_smart_location($product_id),
                        'time' => self::get_smart_camp_time($product_id),
                        'is_current' => $product_id == $current_product_id,
                        'stock_status' => $product->get_stock_status(),
                        'sku' => $product->get_sku()
                    );
                }
            }
            wp_reset_postdata();
        }
        
        // Method 2: If no products found via category, try name-based search
        if (empty($weeks)) {
            $search_args = array(
                'post_type' => 'product',
                'posts_per_page' => 50,
                'post_status' => 'publish',
                's' => 'camp',
                'orderby' => 'title',
                'order' => 'ASC',
            );
            
            $search_query = new WP_Query($search_args);
            
            if ($search_query->have_posts()) {
                while ($search_query->have_posts()) {
                    $search_query->the_post();
                    $product_id = get_the_ID();
                    $product = wc_get_product($product_id);
                    
                    if ($product && $product->is_in_stock()) {
                        $weeks[] = array(
                            'id' => $product_id,
                            'name' => $product->get_name(),
                            'price' => $product->get_price(),
                            'date' => self::get_smart_camp_date($product_id),
                            'location' => self::get_smart_location($product_id),
                            'time' => self::get_smart_camp_time($product_id),
                            'is_current' => $product_id == $current_product_id,
                            'stock_status' => $product->get_stock_status(),
                            'sku' => $product->get_sku()
                        );
                    }
                }
                wp_reset_postdata();
            }
        }
        
        // Method 3: Also try using wc_get_products if still empty
        if (empty($weeks)) {
            $wc_products = wc_get_products(array(
                'status' => 'publish',
                'limit' => 50,
                'stock_status' => 'instock',
                'orderby' => 'title',
                'order' => 'ASC',
            ));
            
            foreach ($wc_products as $product) {
                $product_name = strtolower($product->get_name());
                $is_camp = (
                    strpos($product_name, 'camp') !== false || 
                    strpos($product_name, 'soccer camp') !== false || 
                    strpos($product_name, 'summer camp') !== false || 
                    strpos($product_name, 'ptp camp') !== false ||
                    strpos($product_name, 'week') !== false
                );
                
                if ($is_camp) {
                    $product_id = $product->get_id();
                    $weeks[] = array(
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'price' => $product->get_price(),
                        'date' => self::get_smart_camp_date($product_id),
                        'location' => self::get_smart_location($product_id),
                        'time' => self::get_smart_camp_time($product_id),
                        'is_current' => $product_id == $current_product_id,
                        'stock_status' => $product->get_stock_status(),
                        'sku' => $product->get_sku()
                    );
                }
            }
        }
        
        // Remove duplicates by product ID
        $unique_weeks = array();
        $seen_ids = array();
        foreach ($weeks as $week) {
            if (!in_array($week['id'], $seen_ids)) {
                $seen_ids[] = $week['id'];
                $unique_weeks[] = $week;
            }
        }
        
        // Sort by date string (best effort)
        usort($unique_weeks, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        return $unique_weeks;
    }
    
    /**
     * Get all camp products as a simple array for JS
     */
    public static function get_all_camp_products_for_js($current_product_id) {
        $weeks = self::get_available_weeks($current_product_id);
        $js_data = array();
        
        foreach ($weeks as $week) {
            $js_data[] = array(
                'id' => intval($week['id']),
                'name' => esc_html($week['name']),
                'price' => floatval($week['price']),
                'date' => esc_html($week['date']),
                'location' => esc_html($week['location']),
                'is_current' => (bool)$week['is_current']
            );
        }
        
        return $js_data;
    }

    public static function get_video_reels() {
        return array(
            array('id' => 'reel-1', 'src' => 'https://ptpsummercamps.com/wp-content/uploads/2025/11/EDDY-DAVIS-PTP.mp4', 'poster' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/eddy-davis-signing-jersey.jpg.jpg', 'caption' => 'Pro Player Visit'),
            array('id' => 'reel-2', 'src' => 'https://ptpsummercamps.com/wp-content/uploads/2025/10/COACHES-vs-CAMPERS-MIKE-1.mp4', 'poster' => '', 'caption' => 'Coaches vs Campers'),
            array('id' => 'reel-3', 'src' => 'https://ptpsummercamps.com/wp-content/uploads/2025/11/SEB-PEP-TALK.mp4', 'poster' => '', 'caption' => 'Coach Pep Talk'),
            array('id' => 'reel-4', 'src' => 'https://ptpsummercamps.com/wp-content/uploads/2025/11/wall-ball-mike.mp4', 'poster' => '', 'caption' => 'First Touch Drills'),
        );
    }

    public static function get_gallery() {
        return array(
            array('url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/hunter.jpg', 'caption' => '1v1 Moves'),
            array('url' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/cone-dribbling-6.jpg', 'caption' => 'Ball Mastery'),
            array('url' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/cone-dribbling-4.jpg', 'caption' => 'Close Control'),
            array('url' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1787.jpg', 'caption' => 'Small-Sided Games'),
        );
    }

    public static function get_coaches() {
        return array(
            array('name' => 'Luke Martelli', 'school' => 'Villanova', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2025/11/BG7A5661.jpg', 'badge' => 'FOUNDER'),
            array('name' => 'Danny Krueger', 'school' => 'Wake Forest', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2025/10/Untitled-design-33.png', 'badge' => 'NCAA D1'),
            array('name' => 'Caden Grabfelder', 'school' => 'Penn State', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/TMC-L-Gladfelder-1.webp', 'badge' => 'NCAA D1'),
            array('name' => 'Devon Stopek', 'school' => 'Rutgers', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2025/10/convert-3.webp', 'badge' => 'NCAA D1'),
            array('name' => 'Lily Phillips', 'school' => 'Penn State', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/UOnqfNuuBJ3ajtJ2Hs9QNXb6QGFHlhqvMfn5SvBX.webp', 'badge' => 'NCAA D1'),
            array('name' => 'Sebastian Perez', 'school' => 'Drexel', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/convert-1.webp', 'badge' => 'NCAA D1'),
        );
    }

    public static function get_reviews() {
        return array(
            array('name' => 'Ben Prusky', 'text' => 'Coaches v kids scrimmage, then a talk with a live pro player. <strong>Amazing experience.</strong>', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/unnamed-2.webp'),
            array('name' => 'J. Bunnell', 'text' => 'Small ratio = individual attention. Coaches are <strong>genuinely invested</strong> in every kid.', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/unnamed-3.webp'),
            array('name' => 'Kelci Edwards-Graber', 'text' => 'Great time at the clinic. My son learned so much. <strong>We\'ll definitely be back!</strong>', 'photo' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/unnamed.webp'),
        );
    }

    public static function get_differentiators() {
        return array(
            array('eyebrow' => 'COACHES PLAY', 'title' => 'In the Game', 'desc' => 'Our coaches are <strong>in the 3v3</strong>, playing alongside your kid—not standing on the sideline.'),
            array('eyebrow' => 'REAL PLAYERS', 'title' => 'NCAA D1 Athletes', 'desc' => '<strong>Current and former</strong> Division 1 players who know what it takes to compete.'),
            array('eyebrow' => 'SMALL GROUPS', 'title' => '8:1 Ratio', 'desc' => 'Your kid is <strong>known by name</strong>. Individual feedback every session.'),
            array('eyebrow' => 'SKILL FOCUSED', 'title' => 'Real Development', 'desc' => '<strong>1v1 moves, finishing, first touch, passing combos</strong>—skills that transfer to games.'),
        );
    }

    public static function get_daily_schedule($camp_time = '') {
        // Check if half-day camp (9am-12pm or similar patterns)
        $is_half_day = false;
        $time_lower = strtolower(str_replace(' ', '', $camp_time));
        
        // Match patterns like "9am-12pm", "9:00am-12:00pm", "9am-noon", "9-12"
        if (preg_match('/9.*12|9.*noon|half.?day/i', $time_lower)) {
            $is_half_day = true;
        }
        
        if ($is_half_day) {
            // HALF-DAY SCHEDULE (9am-12pm)
            return array(
                array('time' => '9:00 AM', 'activity' => 'Check In & Warm Up', 'desc' => 'Dynamic stretching, ball activation, footwork ladders.', 'tag' => 'ARRIVAL', 'tag_class' => 'arrival'),
                array('time' => '9:20 AM', 'activity' => 'Station 1: First Touch', 'desc' => 'Receiving under pressure. Inside/outside. Cushion touches. 50+ reps.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
                array('time' => '9:50 AM', 'activity' => 'Station 2: 1v1 Moves', 'desc' => 'Scissors, stepovers, Cruyff turns. Beat a defender in live 1v1s.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
                array('time' => '10:20 AM', 'activity' => 'Water Break', 'desc' => 'Quick hydration. World Cup trivia.', 'tag' => 'BREAK', 'tag_class' => 'break'),
                array('time' => '10:30 AM', 'activity' => 'Station 3: Finishing', 'desc' => 'Placement vs power. Near post, far post. Live finishing drills.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
                array('time' => '11:00 AM', 'activity' => '3v3 Tournament Games', 'desc' => 'Country vs country. Coaches play alongside campers.', 'tag' => 'TOURNAMENT', 'tag_class' => 'tournament'),
                array('time' => '11:50 AM', 'activity' => 'Awards & Pickup', 'desc' => 'Daily MVP. Skill challenge winners. Parent pickup.', 'tag' => 'PICKUP', 'tag_class' => 'pickup'),
            );
        }
        
        // FULL-DAY SCHEDULE (9am-3pm)
        return array(
            array('time' => '9:00 AM', 'activity' => 'Check In & Warm Up', 'desc' => 'Dynamic stretching, ball activation, footwork ladders.', 'tag' => 'ARRIVAL', 'tag_class' => 'arrival'),
            array('time' => '9:30 AM', 'activity' => 'Station 1: First Touch', 'desc' => 'Receiving under pressure. Inside/outside. Cushion touches. 50+ reps.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
            array('time' => '10:15 AM', 'activity' => 'Station 2: 1v1 Moves', 'desc' => 'Scissors, stepovers, Cruyff turns. Beat a defender in live 1v1s.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
            array('time' => '11:00 AM', 'activity' => '3v3 Games', 'desc' => 'Maximum touches. Coaches play with campers.', 'tag' => 'GAMEPLAY', 'tag_class' => 'gameplay'),
            array('time' => '12:00 PM', 'activity' => 'Lunch Break', 'desc' => 'Refuel. World Cup trivia. Player Q&A.', 'tag' => 'LUNCH', 'tag_class' => 'lunch'),
            array('time' => '12:45 PM', 'activity' => 'Station 3: Finishing', 'desc' => 'Placement vs power. Near post, far post, chip. Live finishing drills.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
            array('time' => '1:30 PM', 'activity' => 'Station 4: Passing Combos', 'desc' => 'Wall passes, give-and-gos, through balls. Scanning before receiving.', 'tag' => 'SKILL', 'tag_class' => 'skill'),
            array('time' => '2:15 PM', 'activity' => 'Tournament Games', 'desc' => 'Country vs country competition. Points toward World Cup standings.', 'tag' => 'TOURNAMENT', 'tag_class' => 'tournament'),
            array('time' => '2:50 PM', 'activity' => 'Awards & Pickup', 'desc' => 'Daily MVP. Skill challenge winners. Parent pickup.', 'tag' => 'PICKUP', 'tag_class' => 'pickup'),
        );
    }

    public static function get_week_experience() {
        return array(
            array('day' => 'Monday', 'theme' => 'Draft Day', 'skill' => 'FIRST TOUCH', 'focus' => 'Get drafted to your country.', 'desc' => 'Receiving, cushion control, turns under pressure.'),
            array('day' => 'Tuesday', 'theme' => 'Group Stage', 'skill' => '1v1 MOVES', 'focus' => 'First tournament matches.', 'desc' => 'Scissors, stepovers, body feints to beat defenders.'),
            array('day' => 'Wednesday', 'theme' => 'Knockout', 'skill' => 'PASSING', 'focus' => 'Win or go home.', 'desc' => 'Wall passes, through balls, scanning before receiving.'),
            array('day' => 'Thursday', 'theme' => 'Semi Finals', 'skill' => 'FINISHING', 'focus' => 'Top 4 compete.', 'desc' => 'Placement, power, composure in front of goal.'),
            array('day' => 'Friday', 'theme' => 'Finals', 'skill' => 'PRO DAY', 'focus' => 'Championship + pro guest.', 'desc' => 'Q&A with pro player. Awards ceremony.', 'highlight' => true),
        );
    }

    public static function get_whats_included() {
        return array(
            'PTP Camp T-Shirt',
            'Soccer Ball (Size 4 or 5)',
            '5 Days of Expert Coaching',
            '8:1 Camper to Coach Ratio',
            'Daily Video Clips of Your Child',
            'MVP Awards & Skill Prizes',
            'Snacks & Hydration Provided',
            'Friday Pro Player Visit'
        );
    }

    public static function get_faqs() {
        return array(
            array('q' => 'Who are the coaches?', 'a' => 'Current and former NCAA Division 1 players from programs like Penn State, Villanova, Wake Forest, and Rutgers. All coaches are background checked and CPR certified. Past coaches include players who\'ve competed at the highest collegiate level.'),
            array('q' => 'What ages and skill levels?', 'a' => 'Ages 6-14. All skill levels welcome—from beginners to travel players. Campers are grouped by age and ability for appropriate challenges.'),
            array('q' => 'What should my child bring?', 'a' => 'Cleats, shin guards, water bottle, packed lunch, and sunscreen. We provide the ball, shirt, and snacks.'),
            array('q' => 'What\'s the coach to camper ratio?', 'a' => '8:1 maximum, all day. Your child gets real attention and feedback, not just a number in a crowd.'),
            array('q' => 'What if it rains?', 'a' => 'Light rain = we play (it\'s soccer!). Lightning = we reschedule. You\'ll get a text by 7 AM if there\'s a weather cancellation.'),
            array('q' => 'What\'s the refund policy?', 'a' => 'Full refund within 14 days of purchase, no questions asked. After that, we offer camp credit for future sessions.'),
            array('q' => 'Can I add a World Cup jersey?', 'a' => 'Yes! The PTP camp shirt is included. World Cup country jerseys are available as an add-on at checkout for an additional fee.'),
            array('q' => 'Questions?', 'a' => 'Text or call Luke directly: <strong>(484) 572-4770</strong>. Happy to answer anything.'),
        );
    }

    public function render_camp_template() {
        global $product;
        if (!$product || !$product->is_visible()) {
            echo '<div class="woocommerce"><p class="woocommerce-info">This product is not available.</p></div>';
            return;
        }

        $product_id = $product->get_id();
        $hero_video = self::get_camp_meta($product_id, '_camp_hero_video', 'https://ptpsummercamps.com/wp-content/uploads/2026/01/PRODUCT-VIDEO.mp4');
        $founder_photo = self::get_camp_meta($product_id, '_founder_photo', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/BG7A5661.jpg');
        $camp_date = self::get_smart_camp_date($product_id);
        $camp_time = self::get_smart_camp_time($product_id);
        $venue_name = self::get_smart_location($product_id);
        $full_address = self::get_full_address($product_id);
        $map_embed = self::get_camp_meta($product_id, '_camp_map_embed', '');
        $map_link = self::get_camp_meta($product_id, '_camp_map_link', 'https://maps.google.com/?q=' . urlencode($full_address));
        $hero_badge = self::get_camp_meta($product_id, '_hero_badge', 'SUMMER 2026');
        $hero_headline = self::get_camp_meta($product_id, '_hero_headline', 'Train With <span>D1 Athletes</span>');
        $pack_pricing = self::get_pack_pricing($product);
        $video_reels = self::get_video_reels();
        $coaches = self::get_coaches();
        $reviews = self::get_reviews();
        $gallery = self::get_gallery();
        $differentiators = self::get_differentiators();
        $week_experience = self::get_week_experience();
        $daily_schedule = self::get_daily_schedule($camp_time);
        $faqs = self::get_faqs();
        $whats_included = self::get_whats_included();
        $current_price = $product->get_price();
        $pack_nonce = wp_create_nonce('ptp_pack_nonce');
        $checkout_url = $this->get_ptp_checkout_url();
        $available_weeks = self::get_available_weeks($product_id);
        $available_weeks_json = wp_json_encode(self::get_all_camp_products_for_js($product_id));
        ?>

        <div id="ptp-camp-wrapper" class="ptp-v10" 
             data-product-id="<?php echo esc_attr($product_id); ?>" 
             data-pack-nonce="<?php echo esc_attr($pack_nonce); ?>" 
             data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" 
             data-checkout-url="<?php echo esc_url($checkout_url); ?>"
             data-base-price="<?php echo esc_attr($current_price); ?>"
             data-available-weeks='<?php echo esc_attr($available_weeks_json); ?>'>
            
            <?php wc_print_notices(); ?>

            <!-- HERO SECTION -->
            <section class="hero hero-two-col">
                <div class="hero-video-col">
                    <?php if ($hero_video) : ?>
                    <video class="hero-video" id="heroVideo" autoplay muted loop playsinline>
                        <source src="<?php echo esc_url($hero_video); ?>" type="video/mp4">
                    </video>
                    <?php endif; ?>
                    <div class="hero-overlay"></div>
                    
                    <div class="hero-top">
                        <div class="hero-badge-top">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="#FCB900"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                            5.0 · 50+ Reviews
                        </div>
                    </div>
                    
                    <?php if ($hero_video) : ?>
                    <button class="hero-sound-toggle muted" id="heroSoundToggle" aria-label="Toggle sound">
                        <svg class="sound-off" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
                        <svg class="sound-on" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </button>
                    <?php endif; ?>
                    
                    <!-- Mobile Hero Content -->
                    <div class="hero-content-mobile">
                        <div class="hero-badge">⚽ <?php echo esc_html($hero_badge); ?></div>
                        <h1 class="hero-headline"><?php echo wp_kses_post($hero_headline); ?></h1>
                        <p class="hero-sub">NCAA D1 coaches <strong>play alongside</strong> your kid.</p>
                    </div>
                </div>

                <!-- Right Column: Reserve Spot -->
                <div class="hero-reserve-col">
                    <div class="reserve-card">
                        <div class="reserve-badge">⚽ <?php echo esc_html($hero_badge); ?></div>
                        <h1 class="reserve-headline"><?php echo wp_kses_post($hero_headline); ?></h1>
                        <p class="reserve-sub">NCAA D1 coaches <strong>play alongside</strong> your kid. Not from the sideline.</p>
                        
                        <div class="reserve-pills">
                            <span class="pill"><svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg><?php echo esc_html($camp_date); ?></span>
                            <span class="pill"><svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg><?php echo esc_html($venue_name); ?></span>
                            <span class="pill">Ages 6-14</span>
                        </div>

                        <div class="reserve-price">
                            <span class="price-amount">$<?php echo number_format($current_price); ?></span>
                            <span class="price-per">/week</span>
                        </div>

                        <button class="reserve-btn" id="heroCta">
                            RESERVE YOUR SPOT
                        </button>

                        <div class="reserve-guarantee">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="#22C55E"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                            14-Day Full Refund Guarantee
                        </div>
                    </div>
                </div>
            </section>

            <!-- QUICK INFO & NAV TAB -->
            <div class="quick-nav" id="quickNav">
                <div class="quick-nav-inner">
                    <div class="quick-info">
                        <div class="quick-item">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                            <span><?php echo esc_html($camp_date); ?></span>
                        </div>
                        <div class="quick-item">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                            <span><?php echo esc_html($venue_name); ?></span>
                        </div>
                        <div class="quick-item">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                            <span><?php echo esc_html($camp_time); ?></span>
                        </div>
                        <div class="quick-item">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            <span>Ages 6-14</span>
                        </div>
                    </div>
                    <div class="quick-links">
                        <a href="#reelsSection" class="quick-link">Videos</a>
                        <a href="#coachesSection" class="quick-link">Coaches</a>
                        <a href="#scheduleSection" class="quick-link">Schedule</a>
                        <a href="#pricing" class="quick-link quick-link-cta">Book Now</a>
                    </div>
                </div>
            </div>

            <!-- FOUNDER QUOTE -->
            <div class="mission">
                <div class="founder-profile">
                    <img src="<?php echo esc_url($founder_photo); ?>" alt="Luke Martelli" class="founder-photo">
                </div>
                <p class="mission-quote">"After two ACL injuries ended my playing career, I built the camp I <span>wish I had</span> as a kid—what my teammates and I never got."</p>
                <p class="mission-author"><strong>Luke Martelli</strong>Villanova Soccer · PTP Founder</p>
            </div>

            <!-- VIDEO REELS -->
            <div class="reels" id="reelsSection">
                <div class="reels-header">
                    <span class="label">See It In Action</span>
                    <h2 class="headline">Real <span>Camp Moments</span></h2>
                </div>
                <div class="scroll-container">
                    <button class="scroll-btn scroll-prev" data-target="reelsContainer" aria-label="Scroll left">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" fill="currentColor"/></svg>
                    </button>
                    <div class="reels-scroll" id="reelsContainer">
                        <?php foreach ($video_reels as $index => $reel) : ?>
                        <div class="reel" data-reel-id="<?php echo esc_attr($reel['id']); ?>">
                            <div class="reel-wrap">
                                <video id="video-<?php echo esc_attr($reel['id']); ?>" playsinline muted loop preload="metadata" <?php if (!empty($reel['poster'])) : ?>poster="<?php echo esc_url($reel['poster']); ?>"<?php endif; ?>>
                                    <source src="<?php echo esc_url($reel['src']); ?>" type="video/mp4">
                                </video>
                                <div class="reel-overlay">
                                    <button class="reel-play" data-video-id="video-<?php echo esc_attr($reel['id']); ?>">
                                        <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </button>
                                </div>
                                <button class="reel-sound muted" data-video-id="video-<?php echo esc_attr($reel['id']); ?>">
                                    <svg class="sound-off" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
                                    <svg class="sound-on" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>
                                </button>
                            </div>
                            <p class="reel-caption"><?php echo esc_html($reel['caption']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="scroll-btn scroll-next" data-target="reelsContainer" aria-label="Scroll right">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" fill="currentColor"/></svg>
                    </button>
                </div>
            </div>

            <!-- STATS -->
            <div class="stats">
                <div class="stat"><div class="stat-value">500+</div><div class="stat-label">Families</div></div>
                <div class="stat"><div class="stat-value">8:1</div><div class="stat-label">Ratio</div></div>
                <div class="stat"><div class="stat-value">5.0</div><div class="stat-label">Rating</div></div>
            </div>

            <!-- DIFFERENTIATORS -->
            <section class="bg-dark">
                <div class="section-header">
                    <span class="label">The PTP Difference</span>
                    <h2 class="headline headline-white">The <span>PTP Difference</span></h2>
                </div>
                <div class="diff-grid">
                    <?php foreach ($differentiators as $diff) : ?>
                    <div class="diff-card">
                        <div class="diff-eyebrow"><?php echo esc_html($diff['eyebrow']); ?></div>
                        <h3 class="diff-title"><?php echo esc_html($diff['title']); ?></h3>
                        <p class="diff-desc"><?php echo wp_kses_post($diff['desc']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- GALLERY -->
            <div class="gallery">
                <div class="gallery-header">
                    <span class="label">Skills in Action</span>
                    <h2 class="headline headline-white">Real <span>Training</span></h2>
                </div>
                <div class="scroll-container">
                    <button class="scroll-btn scroll-prev" data-target="galleryScroll" aria-label="Scroll left">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" fill="currentColor"/></svg>
                    </button>
                    <div class="gallery-scroll" id="galleryScroll">
                        <?php foreach ($gallery as $img) : ?>
                        <div class="gallery-item">
                            <img src="<?php echo esc_url($img['url']); ?>" alt="<?php echo esc_attr($img['caption']); ?>" loading="lazy">
                            <div class="gallery-caption"><?php echo esc_html($img['caption']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="scroll-btn scroll-next" data-target="galleryScroll" aria-label="Scroll right">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" fill="currentColor"/></svg>
                    </button>
                </div>
            </div>

            <!-- WORLD CUP EXPERIENCE -->
            <section class="bg-white">
                <div class="section-header center">
                    <span class="label">The Experience</span>
                    <h2 class="headline">Five Days of <span>Growth</span></h2>
                </div>
                <div class="skills-grid">
                    <div class="skill-card"><div class="skill-icon">⚽</div><div class="skill-name">1v1 Moves</div><div class="skill-desc">Beat defenders with scissors, stepovers, and body feints</div></div>
                    <div class="skill-card"><div class="skill-icon">🎯</div><div class="skill-name">Finishing</div><div class="skill-desc">Placement, power, and composure in front of goal</div></div>
                    <div class="skill-card"><div class="skill-icon">👟</div><div class="skill-name">First Touch</div><div class="skill-desc">Receiving, cushion control, and turns under pressure</div></div>
                    <div class="skill-card"><div class="skill-icon">🔗</div><div class="skill-name">Passing Combos</div><div class="skill-desc">Wall passes, through balls, and give-and-gos</div></div>
                    <div class="skill-card"><div class="skill-icon">👁️</div><div class="skill-name">Scanning</div><div class="skill-desc">Check shoulders before receiving—see the field</div></div>
                    <div class="skill-card"><div class="skill-icon">🛡️</div><div class="skill-name">Defending</div><div class="skill-desc">Body position, jockeying, and winning 1v1 duels</div></div>
                </div>
            </section>

            <!-- WEEK EXPERIENCE -->
            <section class="bg-gray">
                <div class="section-header">
                    <span class="label">Your Week</span>
                    <h2 class="headline">Five Days of <span>Growth</span></h2>
                </div>
                <div class="week-scroll">
                    <?php foreach ($week_experience as $day) : ?>
                    <div class="week-card<?php echo !empty($day['highlight']) ? ' highlight' : ''; ?>">
                        <div class="week-header">
                            <span class="week-day"><?php echo esc_html($day['day']); ?></span>
                            <span class="week-theme"><?php echo esc_html($day['theme']); ?></span>
                        </div>
                        <div class="week-body">
                            <span class="week-skill"><?php echo esc_html($day['skill']); ?></span>
                            <p class="week-focus"><?php echo esc_html($day['focus']); ?></p>
                            <p class="week-desc"><?php echo esc_html($day['desc']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- DAILY SCHEDULE -->
            <section class="bg-gray" id="scheduleSection">
                <div class="section-header">
                    <span class="label">Typical Day</span>
                    <h2 class="headline"><?php echo esc_html($camp_time); ?></h2>
                </div>
                <div class="schedule-badge">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                    Rep-Based Training · Skill Stations
                </div>
                <div class="scroll-container">
                    <button class="scroll-btn scroll-prev" data-target="scheduleScroll" aria-label="Scroll left">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" fill="currentColor"/></svg>
                    </button>
                    <div class="schedule-list" id="scheduleScroll">
                        <?php foreach ($daily_schedule as $item) : ?>
                        <div class="schedule-item">
                            <div class="schedule-time"><?php echo esc_html($item['time']); ?></div>
                            <div>
                                <span class="schedule-tag tag-<?php echo esc_attr($item['tag_class']); ?>"><?php echo esc_html($item['tag']); ?></span>
                                <div class="schedule-activity"><?php echo esc_html($item['activity']); ?></div>
                                <div class="schedule-desc"><?php echo esc_html($item['desc']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="scroll-btn scroll-next" data-target="scheduleScroll" aria-label="Scroll right">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" fill="currentColor"/></svg>
                    </button>
                </div>
            </section>

            <!-- COACHES -->
            <section class="bg-dark" id="coachesSection">
                <div class="section-header center">
                    <span class="label">Your Coaches</span>
                    <h2 class="headline headline-white"><span>NCAA D1</span> Athletes</h2>
                </div>
                <p class="coaches-note">Past and current coaches from top D1 programs. Roster updates as summer approaches.</p>
                <div class="scroll-container">
                    <button class="scroll-btn scroll-prev scroll-btn-light" data-target="coachesScroll" aria-label="Scroll left">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" fill="currentColor"/></svg>
                    </button>
                    <div class="coaches-scroll" id="coachesScroll">
                        <?php foreach ($coaches as $coach) : ?>
                        <div class="coach">
                            <div class="coach-photo">
                                <img src="<?php echo esc_url($coach['photo']); ?>" alt="<?php echo esc_attr($coach['name']); ?>" loading="lazy">
                                <span class="coach-badge"><?php echo esc_html($coach['badge']); ?></span>
                            </div>
                            <h4 class="coach-name"><?php echo esc_html($coach['name']); ?></h4>
                            <span class="coach-school"><?php echo esc_html($coach['school']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="scroll-btn scroll-next scroll-btn-light" data-target="coachesScroll" aria-label="Scroll right">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" fill="currentColor"/></svg>
                    </button>
                </div>
            </section>

            <!-- TRUST -->
            <div class="trust">
                <div class="trust-item">
                    <div class="trust-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg></div>
                    <div class="trust-value">100%</div>
                    <div class="trust-label">Background Checked</div>
                </div>
                <div class="trust-item">
                    <div class="trust-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 11h-4v4h-4v-4H6v-4h4V6h4v4h4v4z"/></svg></div>
                    <div class="trust-value">CPR</div>
                    <div class="trust-label">Certified</div>
                </div>
                <div class="trust-item">
                    <div class="trust-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>
                    <div class="trust-value">14-Day</div>
                    <div class="trust-label">Full Refund</div>
                </div>
            </div>

            <!-- WHAT'S INCLUDED -->
            <section class="bg-gray">
                <div class="section-header">
                    <span class="label">Everything Included</span>
                    <h2 class="headline">What's <span>Included</span></h2>
                </div>
                <div class="included-list">
                    <?php foreach ($whats_included as $item) : ?>
                    <div class="included-item">
                        <div class="included-check"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
                        <div class="included-text"><?php echo esc_html($item); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- World Cup Jersey Add-On -->
                <div class="addon-section">
                    <div class="addon-card">
                        <div class="addon-check">
                            <input type="checkbox" id="worldCupJersey" name="world_cup_jersey">
                            <label for="worldCupJersey"></label>
                        </div>
                        <div class="addon-info">
                            <div class="addon-title">+ World Cup Country Jersey</div>
                            <div class="addon-desc">Get drafted to a country and wear their jersey all week</div>
                        </div>
                        <div class="addon-price">+$25</div>
                    </div>
                </div>
            </section>

            <!-- REVIEWS -->
            <section class="bg-white">
                <div class="section-header">
                    <span class="label">Parent Reviews</span>
                    <h2 class="headline">What Families <span>Say</span></h2>
                </div>
                <div class="reviews-scroll">
                    <?php foreach ($reviews as $review) : ?>
                    <div class="review">
                        <div class="review-header">
                            <img class="review-avatar" src="<?php echo esc_url($review['photo']); ?>" alt="" width="60" height="60">
                            <div>
                                <div class="review-name"><?php echo esc_html($review['name']); ?></div>
                                <div class="review-stars">★★★★★</div>
                            </div>
                        </div>
                        <p class="review-text">"<?php echo wp_kses_post($review['text']); ?>"</p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="reviews-more">
                    <a href="/reviews/" class="reviews-link">View More Reviews →</a>
                </div>
            </section>

            <!-- LOCATION -->
            <section class="bg-gray">
                <div class="section-header">
                    <span class="label">Location</span>
                    <h2 class="headline">Where to <span>Find Us</span></h2>
                </div>
                <div class="location-card">
                    <div class="location-map">
                        <?php $auto_map = $full_address ? 'https://maps.google.com/maps?q=' . urlencode($full_address) . '&t=&z=14&ie=UTF8&iwloc=&output=embed' : ''; $final_map = $map_embed ?: $auto_map; if ($final_map) : ?>
                        <iframe src="<?php echo esc_url($final_map); ?>" width="100%" height="200" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
                        <?php endif; ?>
                    </div>
                    <div class="location-info">
                        <div class="location-row">
                            <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                            <div><strong><?php echo esc_html($venue_name); ?></strong><span><?php echo esc_html($full_address); ?></span></div>
                        </div>
                        <div class="location-row">
                            <svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2z"/></svg>
                            <div><strong><?php echo esc_html($camp_time); ?></strong><span>Monday – Friday</span></div>
                        </div>
                        <div class="location-row">
                            <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                            <div><strong><?php echo esc_html($camp_date); ?></strong><span>5-Day Camp Week</span></div>
                        </div>
                        <?php if ($map_link) : ?><a href="<?php echo esc_url($map_link); ?>" target="_blank" class="location-btn">Get Directions →</a><?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- MULTI-WEEK SELECTION -->
            <section class="bg-white" id="pricing">
                <div class="section-header center">
                    <span class="label">Select Your Weeks</span>
                    <h2 class="headline">Choose Your <span>Camp Dates</span></h2>
                </div>
                
                <div class="week-selector" id="weekSelector">
                    <?php if (count($available_weeks) > 1) : ?>
                    <p class="selector-hint">Select one or more weeks for multipack discount:</p>
                    <?php else : ?>
                    <p class="selector-hint">Your selected camp week:</p>
                    <?php endif; ?>
                    
                    <?php if (!empty($available_weeks)) : ?>
                    <div class="weeks-grid">
                        <?php foreach ($available_weeks as $week) : ?>
                        <label class="week-option<?php echo $week['is_current'] ? ' current' : ''; ?>" data-product-id="<?php echo esc_attr($week['id']); ?>">
                            <input type="checkbox" name="selected_weeks[]" value="<?php echo esc_attr($week['id']); ?>" <?php echo $week['is_current'] ? 'checked' : ''; ?>>
                            <div class="week-option-content">
                                <div class="week-option-name"><?php echo esc_html($week['name']); ?></div>
                                <div class="week-option-date"><?php echo esc_html($week['date']); ?></div>
                                <div class="week-option-location"><?php echo esc_html($week['location']); ?></div>
                                <?php if (!empty($week['time'])) : ?>
                                <div class="week-option-time"><?php echo esc_html($week['time']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="week-option-price">$<?php echo number_format($week['price']); ?></div>
                            <div class="week-option-check">
                                <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php else : ?>
                    <div class="no-weeks-message" style="text-align:center;padding:20px;background:#f5f5f5;border-radius:8px;">
                        <p>No additional camp weeks found.</p>
                        <p style="font-size:12px;color:#666;">Current product will be added to cart.</p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (count($available_weeks) > 1) : ?>
                    <div class="multi-week-discount">
                        <div class="discount-row"><span>2 weeks:</span><strong>Save 10%</strong></div>
                        <div class="discount-row"><span>3+ weeks:</span><strong>Save 20%</strong></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="selection-summary" id="selectionSummary">
                        <div class="summary-count"><span id="weekCount">1</span> week<?php echo count($available_weeks) > 1 ? '(s)' : ''; ?> selected</div>
                        <div class="summary-total">Total: <strong>$<span id="totalPrice"><?php echo number_format($current_price); ?></span></strong></div>
                    </div>
                    
                    <button class="checkout-btn" id="checkoutBtn">
                        CONTINUE TO CHECKOUT
                    </button>
                </div>
            </section>

            <!-- FAQ -->
            <section class="bg-gray">
                <div class="section-header center">
                    <span class="label">Questions</span>
                    <h2 class="headline">FAQ</h2>
                </div>
                <div class="faq-list" id="faqList">
                    <?php foreach ($faqs as $index => $faq) : ?>
                    <div class="faq-item<?php echo $index === 0 ? ' open' : ''; ?>">
                        <button class="faq-q" type="button" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                            <span><?php echo esc_html($faq['q']); ?></span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="faq-a" <?php echo $index !== 0 ? 'style="display:none;"' : ''; ?>>
                            <?php echo wp_kses_post($faq['a']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- FINAL CTA -->
            <section class="final-cta">
                <h2 class="final-headline">Ready to <span>Level Up</span>?</h2>
                <p class="final-sub">500+ families. NCAA D1 coaches. Real development.</p>
                <button class="final-btn" id="finalCta">
                    RESERVE YOUR SPOT
                    <span class="cta-price">$<?php echo number_format($current_price); ?></span>
                </button>
                <p class="final-guarantee">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="#22C55E"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                    14-Day Full Refund Guarantee
                </p>
            </section>

            <!-- STICKY FOOTER (Mobile) -->
            <div class="sticky" id="stickyFooter">
                <div class="sticky-left">
                    <span class="sticky-price" id="stickyPrice">$<?php echo number_format($current_price); ?></span>
                    <span class="sticky-date"><?php echo esc_html($camp_date); ?></span>
                </div>
                <button class="sticky-btn" id="stickyCta">RESERVE</button>
            </div>

        </div><!-- END #ptp-camp-wrapper -->
        <?php
    }

    public function output_inline_js() {
        if (!is_product() || !$this->is_camp_product()) return;
        ?>
        <script>
        (function(){
            'use strict';
            document.addEventListener('DOMContentLoaded', function(){
                var wrapper = document.getElementById('ptp-camp-wrapper');
                if (!wrapper) return;
                
                var ajaxUrl = wrapper.dataset.ajaxUrl;
                var nonce = wrapper.dataset.packNonce;
                var productId = parseInt(wrapper.dataset.productId);
                var checkoutUrl = wrapper.dataset.checkoutUrl || '';
                var basePrice = parseFloat(wrapper.dataset.basePrice) || 399;
                
                // Parse available weeks from PHP
                var availableWeeks = [];
                try {
                    availableWeeks = JSON.parse(wrapper.dataset.availableWeeks || '[]');
                } catch(e) {
                    availableWeeks = [];
                }
                
                var selectedWeeks = [productId];
                var addWorldCupJersey = false;

                // HERO VIDEO SOUND
                var heroVideo = document.getElementById('heroVideo');
                var heroSoundToggle = document.getElementById('heroSoundToggle');
                if (heroVideo && heroSoundToggle) {
                    heroVideo.muted = true;
                    heroVideo.play().catch(function(){});
                    heroSoundToggle.addEventListener('click', function(e){
                        e.preventDefault();
                        heroVideo.muted = !heroVideo.muted;
                        this.classList.toggle('muted', heroVideo.muted);
                        this.classList.toggle('unmuted', !heroVideo.muted);
                    });
                }

                // SCROLL NAVIGATION BUTTONS
                var scrollBtns = document.querySelectorAll('.scroll-btn');
                scrollBtns.forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        var targetId = this.dataset.target;
                        var scrollContainer = document.getElementById(targetId);
                        if (!scrollContainer) return;
                        
                        var scrollAmount = scrollContainer.offsetWidth * 0.8;
                        var isNext = this.classList.contains('scroll-next');
                        
                        scrollContainer.scrollBy({
                            left: isNext ? scrollAmount : -scrollAmount,
                            behavior: 'smooth'
                        });
                    });
                });

                // VIDEO REELS
                var reelsContainer = document.getElementById('reelsContainer');
                var currentlyPlaying = null;
                
                if (reelsContainer) {
                    reelsContainer.addEventListener('click', function(e) {
                        var playBtn = e.target.closest('.reel-play');
                        var soundBtn = e.target.closest('.reel-sound');
                        
                        if (playBtn) {
                            e.preventDefault();
                            var videoId = playBtn.dataset.videoId;
                            var video = document.getElementById(videoId);
                            var reel = playBtn.closest('.reel');
                            
                            if (reel.classList.contains('playing')) {
                                video.pause();
                                reel.classList.remove('playing');
                                currentlyPlaying = null;
                            } else {
                                if (currentlyPlaying && currentlyPlaying !== video) {
                                    currentlyPlaying.pause();
                                    currentlyPlaying.closest('.reel-wrap').parentElement.classList.remove('playing');
                                }
                                video.play().catch(function(){});
                                reel.classList.add('playing');
                                currentlyPlaying = video;
                            }
                        } else if (soundBtn) {
                            e.preventDefault();
                            var videoId = soundBtn.dataset.videoId;
                            var video = document.getElementById(videoId);
                            video.muted = !video.muted;
                            soundBtn.classList.toggle('muted', video.muted);
                            soundBtn.classList.toggle('unmuted', !video.muted);
                        }
                    });
                }

                // FAQ ACCORDION
                var faqList = document.getElementById('faqList');
                if (faqList) {
                    faqList.addEventListener('click', function(e) {
                        var btn = e.target.closest('.faq-q');
                        if (!btn) return;
                        
                        var item = btn.closest('.faq-item');
                        var answer = item.querySelector('.faq-a');
                        var wasOpen = item.classList.contains('open');
                        
                        faqList.querySelectorAll('.faq-item').forEach(function(i){
                            i.classList.remove('open');
                            i.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
                            i.querySelector('.faq-a').style.display = 'none';
                        });
                        
                        if (!wasOpen) {
                            item.classList.add('open');
                            btn.setAttribute('aria-expanded', 'true');
                            answer.style.display = 'block';
                        }
                    });
                }

                // MULTI-WEEK SELECTION
                var weekSelector = document.getElementById('weekSelector');
                if (weekSelector) {
                    var checkboxes = weekSelector.querySelectorAll('input[name="selected_weeks[]"]');
                    var weekCountEl = document.getElementById('weekCount');
                    var totalPriceEl = document.getElementById('totalPrice');
                    
                    function updateSelection() {
                        selectedWeeks = [];
                        checkboxes.forEach(function(cb){
                            if (cb.checked) selectedWeeks.push(parseInt(cb.value));
                        });
                        
                        var count = selectedWeeks.length || 1;
                        // UNIFIED WITH CHECKOUT: Match class-ptp-camp-checkout-v98.php CAMP_PACKS
                        // 2-Camp Pack: 10% discount (0.90)
                        // 3-Camp Pack: 20% discount (0.80)
                        var discount = count >= 3 ? 0.80 : (count >= 2 ? 0.90 : 1);
                        var total = Math.round(basePrice * count * discount);
                        
                        if (weekCountEl) weekCountEl.textContent = count;
                        if (totalPriceEl) totalPriceEl.textContent = total.toLocaleString();
                        
                        var stickyPrice = document.getElementById('stickyPrice');
                        if (stickyPrice) stickyPrice.textContent = '$' + total.toLocaleString();
                    }
                    
                    checkboxes.forEach(function(cb){ cb.addEventListener('change', updateSelection); });
                    updateSelection();
                }

                // WORLD CUP JERSEY ADD-ON
                var jerseyCheckbox = document.getElementById('worldCupJersey');
                if (jerseyCheckbox) {
                    jerseyCheckbox.addEventListener('change', function(){ addWorldCupJersey = this.checked; });
                }

                // STICKY FOOTER
                var hero = document.querySelector('.hero');
                var stickyFooter = document.getElementById('stickyFooter');
                
                function updateSticky() {
                    if (!stickyFooter || !hero) return;
                    if (window.innerWidth >= 768) {
                        stickyFooter.classList.remove('visible');
                        return;
                    }
                    var heroBottom = hero.getBoundingClientRect().bottom;
                    stickyFooter.classList.toggle('visible', heroBottom < 50);
                }
                
                window.addEventListener('scroll', updateSticky, {passive: true});
                window.addEventListener('resize', updateSticky);
                updateSticky();

                // CHECKOUT FUNCTION
                function goToCheckout(e) {
                    if (e && e.preventDefault) e.preventDefault();
                    
                    var btn = e && e.target ? e.target.closest('button') : null;
                    if (btn) {
                        btn.disabled = true;
                        btn.style.opacity = '0.7';
                        btn.textContent = 'Processing...';
                    }
                    
                    // Always use AJAX to add to cart - ensures proper session handling
                    var formData = new FormData();
                    formData.append('nonce', nonce);
                    
                    if (selectedWeeks.length > 1) {
                        // Multiple weeks selected - use multi-week handler
                        formData.append('action', 'ptp_add_multiple_weeks');
                        formData.append('add_world_cup_jersey', addWorldCupJersey ? 'true' : 'false');
                        selectedWeeks.forEach(function(id){ formData.append('product_ids[]', id); });
                    } else {
                        // Single camp - use pack handler (also works for single items)
                        formData.append('action', 'ptp_add_pack_to_cart');
                        formData.append('product_id', selectedWeeks.length > 0 ? selectedWeeks[0] : productId);
                        formData.append('quantity', 1);
                    }
                    
                    fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data && data.success && data.data && data.data.redirect) {
                            window.location.href = data.data.redirect;
                        } else {
                            // Fallback - redirect to checkout anyway
                            window.location.href = checkoutUrl || '/ptp-checkout/';
                        }
                    })
                    .catch(function(err){ 
                        console.error('Checkout error:', err);
                        window.location.href = checkoutUrl || '/ptp-checkout/'; 
                    });
                }

                // Bind checkout buttons
                ['heroCta', 'finalCta', 'stickyCta', 'checkoutBtn'].forEach(function(id){
                    var btn = document.getElementById(id);
                    if (btn) btn.addEventListener('click', goToCheckout);
                });
            });
        })();
        </script>
        <?php
    }
}

PTP_Camp_Product_Template_V10::instance();
