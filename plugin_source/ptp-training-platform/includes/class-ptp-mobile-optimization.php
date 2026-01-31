<?php
/**
 * PTP Mobile Optimization
 * 
 * Handles mobile-specific optimizations:
 * - Duplicate header removal
 * - Responsive image srcset generation
 * - Mobile performance improvements
 * - Touch-friendly UI adjustments
 * - Universal mobile CSS injection
 * 
 * @since 85.0.0
 */

defined('ABSPATH') || exit;

// Prevent loading twice
if (class_exists('PTP_Mobile_Optimization')) {
    return;
}

class PTP_Mobile_Optimization {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Mobile breakpoints
     */
    const MOBILE_MAX = 640;
    const TABLET_MAX = 1024;
    
    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Inject mobile meta tags on ALL pages (priority 1 = very early)
        add_action('wp_head', array($this, 'inject_mobile_meta'), 1);
        
        // Inject universal mobile CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_mobile_css'), 5);
        
        // Add body classes for mobile targeting
        add_filter('body_class', array($this, 'add_mobile_body_classes'));
        
        // Remove duplicate headers on PTP pages
        add_action('wp_head', array($this, 'remove_duplicate_headers'), 1);
        
        // Add responsive image support
        add_filter('wp_get_attachment_image_attributes', array($this, 'enhance_image_attributes'), 20, 3);
        add_filter('the_content', array($this, 'add_srcset_to_content_images'), 20);
        
        // Mobile-specific critical CSS (inline)
        add_action('wp_head', array($this, 'add_mobile_critical_css'), 5);
        
        // Remove unnecessary scripts on mobile
        add_action('wp_enqueue_scripts', array($this, 'optimize_mobile_scripts'), 100);
        
        // Optimize WooCommerce for mobile
        add_action('wp', array($this, 'optimize_woocommerce_mobile'));
        
        // Clean up Elementor duplicate headers
        add_action('elementor/frontend/after_enqueue_styles', array($this, 'handle_elementor_headers'), 100);
        
        // Fix missing viewport in AJAX-loaded templates
        add_action('send_headers', array($this, 'add_viewport_header'));
    }
    
    /**
     * Inject mobile meta tags on ALL WordPress pages
     * This ensures every page has proper mobile support
     */
    public function inject_mobile_meta() {
        // Check if viewport already exists (some templates have it)
        // We'll add it anyway with a unique ID so we can dedupe via JS
        ?>
        <!-- PTP Mobile Meta Tags -->
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover" id="ptp-viewport">
        <meta name="format-detection" content="telephone=no">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="theme-color" content="#0A0A0A">
        <meta name="msapplication-navbutton-color" content="#0A0A0A">
        <meta name="apple-touch-fullscreen" content="yes">
        <script>
        // Remove duplicate viewport tags
        (function(){
            var viewports = document.querySelectorAll('meta[name="viewport"]');
            for(var i = 1; i < viewports.length; i++) {
                viewports[i].remove();
            }
        })();
        </script>
        <?php
    }
    
    /**
     * Enqueue universal mobile CSS on all pages
     */
    public function enqueue_mobile_css() {
        $css_file = PTP_PLUGIN_DIR . 'assets/css/ptp-universal-mobile.css';
        $css_url = PTP_PLUGIN_URL . 'assets/css/ptp-universal-mobile.css';
        
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'ptp-universal-mobile',
                $css_url,
                array(),
                filemtime($css_file)
            );
        }
    }
    
    /**
     * Add mobile-specific body classes
     */
    public function add_mobile_body_classes($classes) {
        // Add device type class
        if (wp_is_mobile()) {
            $classes[] = 'ptp-is-mobile';
            
            // Detect tablet vs phone
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $ua)) {
                $classes[] = 'ptp-is-tablet';
            } else {
                $classes[] = 'ptp-is-phone';
            }
        } else {
            $classes[] = 'ptp-is-desktop';
        }
        
        // Check if this is a PTP custom template page
        if ($this->is_ptp_page()) {
            $classes[] = 'ptp-page';
        }
        
        if ($this->is_ptp_custom_template()) {
            $classes[] = 'ptp-custom-template';
        }
        
        // iOS detection for safe area handling
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $classes[] = 'ptp-ios';
        }
        
        return $classes;
    }
    
    /**
     * Remove duplicate headers on PTP custom template pages
     */
    public function remove_duplicate_headers() {
        if (!$this->is_ptp_custom_template()) {
            return;
        }
        
        // These pages have their own headers, remove theme/Elementor headers
        ?>
        <style id="ptp-header-cleanup">
        /* Hide duplicate theme/Elementor headers on PTP pages */
        body.ptp-custom-template .elementor-location-header,
        body.ptp-custom-template header.elementor-element,
        body.ptp-custom-template #masthead,
        body.ptp-custom-template .site-header,
        body.ptp-custom-template .theme-header,
        body.ptp-custom-template header[data-elementor-type="header"],
        body.ptp-custom-template .elementor-header,
        body.ptp-custom-template nav.elementor-nav-menu {
            display: none !important;
        }
        
        /* Also hide duplicate skip-to-content links */
        .skip-link:not(:first-of-type),
        a.skip-link + a.skip-link {
            display: none !important;
        }
        
        /* Ensure body starts at top */
        body.ptp-custom-template {
            padding-top: 0 !important;
            margin-top: 0 !important;
        }
        
        /* Handle admin bar */
        body.admin-bar.ptp-custom-template {
            margin-top: 32px !important;
        }
        
        @media screen and (max-width: 782px) {
            body.admin-bar.ptp-custom-template {
                margin-top: 46px !important;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Handle Elementor header conflicts
     */
    public function handle_elementor_headers() {
        if (!$this->is_ptp_custom_template()) {
            return;
        }
        
        // Remove Elementor header template
        remove_all_actions('elementor/theme/header');
        
        // Remove Elementor's header display
        add_filter('elementor/theme/header/display', '__return_false');
    }
    
    /**
     * Check if current page is any PTP-related page
     */
    private function is_ptp_page() {
        // Check for PTP shortcodes in content
        global $post;
        if ($post && has_shortcode($post->post_content, 'ptp_')) {
            return true;
        }
        
        // Check for PTP query vars
        if (get_query_var('trainer_id') || get_query_var('ptp_action')) {
            return true;
        }
        
        // Check page slug
        if (is_page()) {
            $ptp_pages = array(
                'find-trainers', 'trainer', 'trainers', 'book-session', 'booking',
                'parent-dashboard', 'trainer-dashboard', 'trainer-onboarding',
                'login', 'register', 'messages', 'messaging', 'my-training',
                'checkout', 'cart', 'apply', 'training', 'account',
                'player-progress', 'thank-you', 'booking-confirmation'
            );
            
            $page_slug = get_post_field('post_name', get_the_ID());
            if (in_array($page_slug, $ptp_pages)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if current page uses PTP custom template (has its own header)
     */
    private function is_ptp_custom_template() {
        if (!is_page()) {
            return false;
        }
        
        // Pages that have their OWN header/footer (not using theme header)
        $custom_template_pages = array(
            'trainer',
            'book-session',
            'parent-dashboard',
            'trainer-dashboard',
            'trainer-onboarding',
            'login',
            'register',
            'messages',
            'messaging',
            'my-training',
            'checkout',
            'apply',
            'training',
            'account',
            'booking-wizard',
            'booking-confirmation',
            'player-progress',
            'thank-you',
            'ptp-checkout'
        );
        
        $page_slug = get_post_field('post_name', get_the_ID());
        
        // Check slug match
        if (in_array($page_slug, $custom_template_pages)) {
            return true;
        }
        
        // Check if page has ptp_template meta
        $template = get_post_meta(get_the_ID(), '_ptp_template', true);
        if (!empty($template)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Add mobile critical CSS (inline for fastest paint)
     */
    public function add_mobile_critical_css() {
        ?>
        <style id="ptp-mobile-critical">
        /* Critical mobile CSS - Inline for fastest paint */
        
        /* v133.2: UNIVERSAL SCROLL FIX - Ensure scrolling always works */
        html {
            height: auto !important;
            min-height: 100%;
            overflow-x: hidden;
            overflow-y: scroll !important;
        }
        body {
            height: auto !important;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: visible !important;
            position: relative !important;
        }
        /* Override any scroll-blocking classes unless truly needed */
        html:not(.ptp-modal-active):not(.ptp-menu-active),
        body:not(.ptp-modal-active):not(.ptp-menu-active) {
            overflow-y: auto !important;
            position: static !important;
        }
        
        /* Prevent horizontal scroll */
        html, body {
            max-width: 100vw;
        }
        
        /* Safe area support */
        body {
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        
        /* Responsive images */
        img {
            max-width: 100%;
            height: auto;
        }
        
        /* Touch targets */
        a, button, input[type="button"], input[type="submit"], .btn {
            min-height: 44px;
        }
        
        /* Prevent iOS input zoom */
        input, textarea, select {
            font-size: 16px !important;
        }
        
        /* Mobile typography */
        @media (max-width: 640px) {
            h1 { font-size: 28px !important; }
            h2 { font-size: 24px !important; }
            h3 { font-size: 20px !important; }
        }
        
        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Enhance image attributes with srcset and sizes
     */
    public function enhance_image_attributes($attr, $attachment, $size) {
        // Skip if srcset already set
        if (isset($attr['srcset']) && !empty($attr['srcset'])) {
            return $attr;
        }
        
        $image_src = wp_get_attachment_image_src($attachment->ID, 'full');
        if (!$image_src) {
            return $attr;
        }
        
        // Generate srcset
        $srcset = $this->generate_srcset($attachment->ID);
        if ($srcset) {
            $attr['srcset'] = $srcset;
            $attr['sizes'] = $this->get_default_sizes();
        }
        
        // Add loading="lazy" if not above fold
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        
        // Add decoding="async"
        $attr['decoding'] = 'async';
        
        return $attr;
    }
    
    /**
     * Generate srcset for attachment
     */
    private function generate_srcset($attachment_id) {
        $sizes = array(
            'thumbnail' => 150,
            'medium' => 300,
            'medium_large' => 768,
            'large' => 1024,
            'full' => 2560
        );
        
        $srcset_parts = array();
        
        foreach ($sizes as $size_name => $width) {
            $image = wp_get_attachment_image_src($attachment_id, $size_name);
            if ($image && isset($image[0]) && isset($image[1])) {
                $srcset_parts[] = $image[0] . ' ' . $image[1] . 'w';
            }
        }
        
        return implode(', ', array_unique($srcset_parts));
    }
    
    /**
     * Get default sizes attribute
     */
    private function get_default_sizes() {
        return '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw';
    }
    
    /**
     * Add srcset to content images
     */
    public function add_srcset_to_content_images($content) {
        if (empty($content)) {
            return $content;
        }
        
        // Find images without srcset
        $pattern = '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i';
        
        return preg_replace_callback($pattern, function($matches) {
            $img_tag = $matches[0];
            
            // Skip if already has srcset
            if (strpos($img_tag, 'srcset') !== false) {
                return $img_tag;
            }
            
            // Skip external images
            $src = $matches[1];
            if (strpos($src, home_url()) === false && strpos($src, '/wp-content/') === false) {
                return $img_tag;
            }
            
            // Add loading lazy if not present
            if (strpos($img_tag, 'loading=') === false) {
                $img_tag = str_replace('<img', '<img loading="lazy"', $img_tag);
            }
            
            // Add decoding async
            if (strpos($img_tag, 'decoding=') === false) {
                $img_tag = str_replace('<img', '<img decoding="async"', $img_tag);
            }
            
            return $img_tag;
            
        }, $content);
    }
    
    /**
     * Optimize scripts for mobile
     */
    public function optimize_mobile_scripts() {
        if (!wp_is_mobile()) {
            return;
        }
        
        // Defer non-critical scripts on mobile
        add_filter('script_loader_tag', function($tag, $handle) {
            $defer_scripts = array(
                'google-maps',
                'stripe',
                'facebook-pixel',
                'analytics',
                'google-analytics',
                'gtag'
            );
            
            foreach ($defer_scripts as $script) {
                if (strpos($handle, $script) !== false && strpos($tag, 'defer') === false) {
                    return str_replace(' src', ' defer src', $tag);
                }
            }
            
            return $tag;
        }, 10, 2);
    }
    
    /**
     * Optimize WooCommerce for mobile
     */
    public function optimize_woocommerce_mobile() {
        if (!wp_is_mobile() || !class_exists('WooCommerce')) {
            return;
        }
        
        // Reduce cart fragments AJAX calls on mobile
        add_filter('woocommerce_cart_fragments_ajax_call_threshold', function() {
            return 5;
        });
    }
    
    /**
     * Add viewport header for AJAX requests
     */
    public function add_viewport_header() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            header('X-PTP-Mobile-Ready: true');
        }
    }
    
    /**
     * Generate responsive image HTML (static helper)
     */
    public static function responsive_image($url, $args = array()) {
        $defaults = array(
            'alt' => '',
            'class' => '',
            'width' => null,
            'height' => null,
            'sizes' => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
            'loading' => 'lazy',
            'decoding' => 'async'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Try to get attachment ID from URL
        $attachment_id = attachment_url_to_postid($url);
        
        if ($attachment_id) {
            return wp_get_attachment_image($attachment_id, 'large', false, array(
                'class' => $args['class'],
                'alt' => $args['alt'],
                'loading' => $args['loading'],
                'decoding' => $args['decoding'],
                'sizes' => $args['sizes']
            ));
        }
        
        // Fallback for non-WordPress images
        $html = '<img';
        $html .= ' src="' . esc_url($url) . '"';
        
        if ($args['alt']) {
            $html .= ' alt="' . esc_attr($args['alt']) . '"';
        }
        
        if ($args['class']) {
            $html .= ' class="' . esc_attr($args['class']) . '"';
        }
        
        if ($args['width']) {
            $html .= ' width="' . intval($args['width']) . '"';
        }
        
        if ($args['height']) {
            $html .= ' height="' . intval($args['height']) . '"';
        }
        
        $html .= ' loading="' . esc_attr($args['loading']) . '"';
        $html .= ' decoding="' . esc_attr($args['decoding']) . '"';
        $html .= '>';
        
        return $html;
    }
    
    /**
     * Check if device is mobile (static helper)
     */
    public static function is_mobile() {
        return wp_is_mobile();
    }
    
    /**
     * Get device type (static helper)
     */
    public static function get_device_type() {
        if (!wp_is_mobile()) {
            return 'desktop';
        }
        
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }
        
        return 'mobile';
    }
}

// Initialize
PTP_Mobile_Optimization::instance();

/**
 * Helper function for templates
 */
if (!function_exists('ptp_responsive_image')) {
    function ptp_responsive_image($url, $args = array()) {
        return PTP_Mobile_Optimization::responsive_image($url, $args);
    }
}

/**
 * Helper to check mobile
 */
if (!function_exists('ptp_is_mobile')) {
    function ptp_is_mobile() {
        return PTP_Mobile_Optimization::is_mobile();
    }
}

/**
 * Helper to get device type
 */
if (!function_exists('ptp_get_device_type')) {
    function ptp_get_device_type() {
        return PTP_Mobile_Optimization::get_device_type();
    }
}
