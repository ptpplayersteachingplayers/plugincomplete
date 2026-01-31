<?php
/**
 * Templates Class
 */

defined('ABSPATH') || exit;

class PTP_Templates {
    
    public static function init() {
        add_filter('template_include', array(__CLASS__, 'template_loader'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('template_redirect', array(__CLASS__, 'handle_trainer_profile'));
    }
    
    /**
     * Handle trainer profile URLs - output template directly and bypass theme
     * v132.0.1: Fixed scroll issue by outputting full HTML document directly
     */
    public static function handle_trainer_profile() {
        // Check if we're on the trainer page with a slug
        if (!is_page('trainer')) {
            return;
        }
        
        $trainer_slug = get_query_var('trainer_slug');
        
        // Also check URL path as fallback
        if (!$trainer_slug) {
            $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
            
            // Remove site subdirectory if present
            $home_path = trim(parse_url(home_url(), PHP_URL_PATH), '/');
            if ($home_path && strpos($path, $home_path) === 0) {
                $path = trim(substr($path, strlen($home_path)), '/');
            }
            
            // Try to extract trainer slug from path
            if (preg_match('#trainer/([^/]+)/?$#', $path, $matches)) {
                $trainer_slug = sanitize_text_field($matches[1]);
                set_query_var('trainer_slug', $trainer_slug);
            }
        }
        
        // If we have a trainer slug, output the template directly
        if ($trainer_slug) {
            // The trainer-profile-v3 template is a complete HTML document
            // Output it directly and exit to prevent theme from wrapping it
            include PTP_PLUGIN_DIR . 'templates/trainer-profile-v3.php';
            exit;
        }
    }
    
    public static function template_loader($template) {
        // Check if this is a PTP page
        if (is_page()) {
            $page_slug = get_post_field('post_name', get_the_ID());
            
            // All-Access landing page
            if ($page_slug === 'all-access') {
                return PTP_PLUGIN_DIR . 'templates/all-access-landing.php';
            }
            
            // Keep these in sync with the slugs created in create_pages() (ptp-training-platform.php).
            // Also keep legacy slugs for backwards compatibility.
            $ptp_pages = array(
                'training',
                'find-trainers',
                'trainer',
                'book-session',
                'booking-confirmation',
                'bundle-checkout',
                'training-checkout',
                'my-training',
                'parent-dashboard',
                'trainer-dashboard',
                'trainer-onboarding',
                'trainer-edit-profile',
                'messages',
                'account',
                'login',
                'register',
                'apply',
                'logout',
                // legacy
                'trainer-profile',
                'become-a-trainer',
            );
            
            if (in_array($page_slug, $ptp_pages)) {
                // Use theme template if exists, otherwise default
                $theme_template = locate_template('ptp-template.php');
                if ($theme_template) {
                    return $theme_template;
                }
            }
        }
        
        return $template;
    }
    
    public static function body_class($classes) {
        if (is_page()) {
            $page_slug = get_post_field('post_name', get_the_ID());
            // Keep these in sync with template_loader().
            $ptp_pages = array(
                'training',
                'find-trainers',
                'trainer',
                'book-session',
                'booking-confirmation',
                'bundle-checkout',
                'training-checkout',
                'my-training',
                'parent-dashboard',
                'trainer-dashboard',
                'trainer-onboarding',
                'trainer-edit-profile',
                'messages',
                'account',
                'login',
                'register',
                'apply',
                'logout',
                // legacy
                'trainer-profile',
                'become-a-trainer',
            );
            
            if (in_array($page_slug, $ptp_pages)) {
                $classes[] = 'ptp-page';
                $classes[] = 'ptp-' . $page_slug;
            }
        }
        
        return $classes;
    }
    
    public static function get_template($template_name, $args = array()) {
        if ($args && is_array($args)) {
            extract($args);
        }
        
        $template_path = PTP_PLUGIN_DIR . 'templates/' . $template_name . '.php';
        
        if (file_exists($template_path)) {
            include $template_path;
        }
    }
    
    public static function format_date($date) {
        return date('l, F j, Y', strtotime($date));
    }
    
    public static function format_time($time) {
        return date('g:i A', strtotime($time));
    }
    
    public static function format_price($amount) {
        return '$' . number_format(floatval($amount), 2);
    }
    
    public static function get_avatar_url($user_id, $size = 96) {
        return get_avatar_url($user_id, array('size' => $size));
    }
    
    public static function rating_stars($rating, $max = 5) {
        $full_stars = floor($rating);
        $half_star = ($rating - $full_stars) >= 0.5;
        $empty_stars = $max - $full_stars - ($half_star ? 1 : 0);
        
        $output = str_repeat('★', $full_stars);
        if ($half_star) {
            $output .= '½';
        }
        $output .= str_repeat('☆', $empty_stars);
        
        return $output;
    }
}
