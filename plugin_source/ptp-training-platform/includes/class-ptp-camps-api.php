<?php
/**
 * PTP Camps API - Mobile App integration for WooCommerce camps/clinics
 * ALL WooCommerce products are treated as camps/clinics/programs
 * 
 * @version 2.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camps_API {
    
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    
    public static function register_routes() {
        $ns = 'ptp/v1';
        
        // ============================================
        // CAMPS ENDPOINTS (Primary)
        // ============================================
        
        // Get all camps/clinics - ALL WooCommerce products
        register_rest_route($ns, '/camps', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_camps'),
            'permission_callback' => '__return_true',
        ));
        
        // ALIAS: /programs -> /camps (for flexibility)
        register_rest_route($ns, '/programs', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_camps'),
            'permission_callback' => '__return_true',
        ));
        
        // Get single camp/program
        register_rest_route($ns, '/camps/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_camp'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route($ns, '/programs/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_camp'),
            'permission_callback' => '__return_true',
        ));
        
        // Get featured camps
        register_rest_route($ns, '/camps/featured', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_featured'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route($ns, '/programs/featured', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_featured'),
            'permission_callback' => '__return_true',
        ));
        
        // Get camps by location
        register_rest_route($ns, '/camps/nearby', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_by_location'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route($ns, '/camps/by-location', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_by_location'),
            'permission_callback' => '__return_true',
        ));
        
        // Get upcoming camps
        register_rest_route($ns, '/camps/upcoming', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_upcoming'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route($ns, '/programs/upcoming', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_upcoming'),
            'permission_callback' => '__return_true',
        ));
        
        // Get user registrations
        register_rest_route($ns, '/camps/my-registrations', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_my_registrations'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // Check availability
        register_rest_route($ns, '/camps/(?P<id>\d+)/availability', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'check_availability'),
            'permission_callback' => '__return_true',
        ));
        
        // Get schedule/sessions
        register_rest_route($ns, '/camps/(?P<id>\d+)/schedule', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_schedule'),
            'permission_callback' => '__return_true',
        ));
        
        // Get categories
        register_rest_route($ns, '/camps/categories', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_categories'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route($ns, '/programs/categories', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_categories'),
            'permission_callback' => '__return_true',
        ));
        
        // Search
        register_rest_route($ns, '/camps/search', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'search_camps'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route($ns, '/programs/search', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'search_camps'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Simple auth check
     */
    public static function check_auth($request) {
        $auth = $request->get_header('Authorization');
        return !empty($auth);
    }
    
    /**
     * Get all camps/clinics - ALL WooCommerce products
     */
    public static function get_camps($request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_required', 'WooCommerce not installed', array('status' => 400));
        }
        
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
        $type = sanitize_text_field($request->get_param('type'));
        $state = sanitize_text_field($request->get_param('state'));
        $category = sanitize_text_field($request->get_param('category'));
        $age_group = sanitize_text_field($request->get_param('age_group'));
        $sort = sanitize_text_field($request->get_param('sort')) ?: 'newest';
        
        // Base query - ALL published products
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
        );
        
        // Optional filters
        $meta_query = array();
        
        if ($type && $type !== 'all') {
            $meta_query[] = array(
                'key' => '_ptp_camp_type',
                'value' => $type,
            );
        }
        
        if ($state) {
            $meta_query[] = array(
                'key' => '_ptp_state',
                'value' => $state,
            );
        }
        
        if ($age_group) {
            $meta_query[] = array(
                'key' => '_ptp_age_groups',
                'value' => $age_group,
                'compare' => 'LIKE',
            );
        }
        
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }
        
        // Category filter
        if ($category) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $category,
                ),
            );
        }
        
        // Sorting - defaults to newest first (most reliable)
        switch ($sort) {
            case 'price_low':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'ASC';
                break;
            case 'price_high':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'DESC';
                break;
            case 'name':
            case 'title':
                $args['orderby'] = 'title';
                $args['order'] = 'ASC';
                break;
            case 'date':
            case 'start_date':
                $args['orderby'] = 'meta_value';
                $args['meta_key'] = '_ptp_start_date';
                $args['order'] = 'ASC';
                break;
            case 'newest':
            default:
                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
                break;
        }
        
        $query = new WP_Query($args);
        
        $camps = array();
        foreach ($query->posts as $post) {
            $formatted = self::format_camp($post->ID);
            if ($formatted) {
                $camps[] = $formatted;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'camps' => $camps,
            'programs' => $camps, // Alias for mobile app compatibility
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
        ));
    }
    
    /**
     * Get single camp
     */
    public static function get_camp($request) {
        $id = (int) $request->get_param('id');
        
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_required', 'WooCommerce not installed', array('status' => 400));
        }
        
        $product = wc_get_product($id);
        if (!$product) {
            return new WP_Error('not_found', 'Program not found', array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'camp' => self::format_camp($id, true),
            'program' => self::format_camp($id, true),
        ));
    }
    
    /**
     * Get featured camps - Featured WooCommerce products
     */
    public static function get_featured($request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_required', 'WooCommerce not installed', array('status' => 400));
        }
        
        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 10));
        
        // Get WooCommerce featured products
        $featured_ids = wc_get_featured_product_ids();
        
        $camps = array();
        foreach (array_slice($featured_ids, 0, $limit) as $id) {
            $formatted = self::format_camp($id);
            if ($formatted) {
                $camps[] = $formatted;
            }
        }
        
        // If no featured, get newest products
        if (empty($camps)) {
            $args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
            );
            
            $query = new WP_Query($args);
            foreach ($query->posts as $post) {
                $formatted = self::format_camp($post->ID);
                if ($formatted) {
                    $camps[] = $formatted;
                }
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'featured' => $camps,
            'camps' => $camps,
            'programs' => $camps,
        ));
    }
    
    /**
     * Get camps by location
     */
    public static function get_by_location($request) {
        $latitude = (float) $request->get_param('latitude') ?: (float) $request->get_param('lat');
        $longitude = (float) $request->get_param('longitude') ?: (float) $request->get_param('lng');
        $radius = min(100, max(1, (int) $request->get_param('radius') ?: 50));
        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 20));
        
        if (!$latitude || !$longitude) {
            // Return all camps if no location provided
            return self::get_camps($request);
        }
        
        global $wpdb;
        
        // Get products with coordinates
        $camps_raw = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID,
                   pm_lat.meta_value as latitude,
                   pm_lng.meta_value as longitude,
                   (
                       3959 * acos(
                           cos(radians(%f)) * cos(radians(CAST(pm_lat.meta_value AS DECIMAL(10,6)))) *
                           cos(radians(CAST(pm_lng.meta_value AS DECIMAL(10,6))) - radians(%f)) +
                           sin(radians(%f)) * sin(radians(CAST(pm_lat.meta_value AS DECIMAL(10,6))))
                       )
                   ) AS distance
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key = '_ptp_latitude'
            LEFT JOIN {$wpdb->postmeta} pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key = '_ptp_longitude'
            WHERE p.post_type = 'product' AND p.post_status = 'publish'
            AND pm_lat.meta_value IS NOT NULL AND pm_lat.meta_value != ''
            AND pm_lng.meta_value IS NOT NULL AND pm_lng.meta_value != ''
            HAVING distance <= %d
            ORDER BY distance ASC
            LIMIT %d
        ", $latitude, $longitude, $latitude, $radius, $limit));
        
        $camps = array();
        foreach ($camps_raw as $camp) {
            $data = self::format_camp($camp->ID);
            if ($data) {
                $data['distance'] = round($camp->distance, 1);
                $data['distance_unit'] = 'miles';
                $camps[] = $data;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'camps' => $camps,
            'programs' => $camps,
            'center' => array(
                'latitude' => $latitude,
                'longitude' => $longitude,
            ),
            'radius' => $radius,
            'total' => count($camps),
        ));
    }
    
    /**
     * Get upcoming camps
     */
    public static function get_upcoming($request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_required', 'WooCommerce not installed', array('status' => 400));
        }
        
        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 20));
        $days = min(365, max(1, (int) $request->get_param('days') ?: 90));
        
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => array(
                array(
                    'key' => '_ptp_start_date',
                    'value' => date('Y-m-d'),
                    'compare' => '>=',
                    'type' => 'DATE',
                ),
            ),
            'orderby' => 'meta_value',
            'meta_key' => '_ptp_start_date',
            'order' => 'ASC',
        );
        
        $query = new WP_Query($args);
        
        $camps = array();
        foreach ($query->posts as $post) {
            $formatted = self::format_camp($post->ID);
            if ($formatted) {
                $camps[] = $formatted;
            }
        }
        
        // If no upcoming found, return newest
        if (empty($camps)) {
            $fallback = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
            );
            $query = new WP_Query($fallback);
            foreach ($query->posts as $post) {
                $formatted = self::format_camp($post->ID);
                if ($formatted) {
                    $camps[] = $formatted;
                }
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'upcoming' => $camps,
            'camps' => $camps,
            'programs' => $camps,
            'total' => count($camps),
        ));
    }
    
    /**
     * Get user registrations
     */
    public static function get_my_registrations($request) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('not_authenticated', 'Authentication required', array('status' => 401));
        }
        
        // Check if registrations table exists
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        $registrations = array();
        
        if ($table_exists) {
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT r.*, p.post_title as camp_name
                FROM $table r
                JOIN {$wpdb->posts} p ON r.camp_id = p.ID
                WHERE r.user_id = %d
                ORDER BY r.created_at DESC
            ", $user_id));
            
            foreach ($results as $row) {
                $registrations[] = array(
                    'id' => $row->id,
                    'camp_id' => $row->camp_id,
                    'camp_name' => $row->camp_name,
                    'order_id' => $row->order_id,
                    'quantity' => $row->quantity,
                    'amount_paid' => floatval($row->amount_paid),
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'camp' => self::format_camp($row->camp_id),
                );
            }
        }
        
        // Also get WooCommerce orders for this user
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => array('completed', 'processing'),
            'limit' => 50,
        ));
        
        $order_items = array();
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $order_items[] = array(
                    'order_id' => $order->get_id(),
                    'camp_id' => $product_id,
                    'camp_name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'amount_paid' => floatval($item->get_total()),
                    'status' => $order->get_status(),
                    'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                    'camp' => self::format_camp($product_id),
                );
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'registrations' => $registrations,
            'orders' => $order_items,
            'total' => count($registrations) + count($order_items),
        ));
    }
    
    /**
     * Check availability
     */
    public static function check_availability($request) {
        $id = (int) $request->get_param('id');
        
        $product = wc_get_product($id);
        if (!$product) {
            return new WP_Error('not_found', 'Program not found', array('status' => 404));
        }
        
        $max = intval(get_post_meta($id, '_ptp_max_capacity', true));
        $sold = intval(get_post_meta($id, '_ptp_sold_count', true));
        
        // Get from WooCommerce stock if available
        if ($product->managing_stock()) {
            $stock = $product->get_stock_quantity();
        } else {
            $stock = $max > 0 ? max(0, $max - $sold) : -1; // -1 = unlimited
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'product_id' => $id,
            'available' => $product->is_in_stock(),
            'stock_quantity' => $stock,
            'max_capacity' => $max,
            'sold_count' => $sold,
            'remaining' => $max > 0 ? max(0, $max - $sold) : null,
            'is_sold_out' => $max > 0 && $sold >= $max,
            'stock_status' => $product->get_stock_status(),
        ));
    }
    
    /**
     * Get schedule/sessions
     */
    public static function get_schedule($request) {
        $id = (int) $request->get_param('id');
        
        $product = wc_get_product($id);
        if (!$product) {
            return new WP_Error('not_found', 'Program not found', array('status' => 404));
        }
        
        $start_date = get_post_meta($id, '_ptp_start_date', true);
        $end_date = get_post_meta($id, '_ptp_end_date', true) ?: $start_date;
        $daily_times = get_post_meta($id, '_ptp_daily_times', true) ?: array();
        
        $schedule = array();
        
        if ($start_date) {
            $current = strtotime($start_date);
            $end = strtotime($end_date);
            $day = 1;
            
            while ($current <= $end) {
                $schedule[] = array(
                    'day' => $day,
                    'date' => date('Y-m-d', $current),
                    'day_of_week' => date('l', $current),
                    'start_time' => $daily_times['start'] ?? '09:00',
                    'end_time' => $daily_times['end'] ?? '15:00',
                );
                $current = strtotime('+1 day', $current);
                $day++;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'product_id' => $id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'daily_times' => $daily_times,
            'schedule' => $schedule,
            'total_days' => count($schedule),
        ));
    }
    
    /**
     * Get camp categories
     */
    public static function get_categories($request) {
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
        ));
        
        if (is_wp_error($terms)) {
            $terms = array();
        }
        
        $categories = array();
        foreach ($terms as $term) {
            $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            $categories[] = array(
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'count' => $term->count,
                'image' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null,
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'categories' => $categories,
            'total' => count($categories),
        ));
    }
    
    /**
     * Search camps
     */
    public static function search_camps($request) {
        $query = sanitize_text_field($request->get_param('q') ?: $request->get_param('query') ?: $request->get_param('search'));
        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 20));
        
        if (strlen($query) < 2) {
            // Return all if no query
            return self::get_camps($request);
        }
        
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            's' => $query,
            'orderby' => 'relevance',
        );
        
        $search = new WP_Query($args);
        
        $camps = array();
        foreach ($search->posts as $post) {
            $formatted = self::format_camp($post->ID);
            if ($formatted) {
                $camps[] = $formatted;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'query' => $query,
            'results' => $camps,
            'camps' => $camps,
            'programs' => $camps,
            'total' => $search->found_posts,
        ));
    }
    
    /**
     * Format camp for API response
     */
    private static function format_camp($id, $full_details = false) {
        $product = wc_get_product($id);
        $post = get_post($id);
        
        if (!$product || !$post) {
            return null;
        }
        
        // Get images
        $image_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();
        
        $images = array();
        if ($image_id) {
            $images[] = array(
                'id' => $image_id,
                'url' => wp_get_attachment_url($image_id),
                'thumbnail' => wp_get_attachment_image_url($image_id, 'thumbnail'),
                'medium' => wp_get_attachment_image_url($image_id, 'medium'),
                'large' => wp_get_attachment_image_url($image_id, 'large'),
            );
        }
        
        foreach ($gallery_ids as $gid) {
            $images[] = array(
                'id' => $gid,
                'url' => wp_get_attachment_url($gid),
                'thumbnail' => wp_get_attachment_image_url($gid, 'thumbnail'),
                'medium' => wp_get_attachment_image_url($gid, 'medium'),
            );
        }
        
        // Auto-detect type from name
        $camp_type = get_post_meta($id, '_ptp_camp_type', true);
        if (empty($camp_type)) {
            $name_lower = strtolower($product->get_name());
            if (strpos($name_lower, 'clinic') !== false) {
                $camp_type = 'clinic';
            } elseif (strpos($name_lower, 'academy') !== false) {
                $camp_type = 'academy';
            } elseif (strpos($name_lower, 'training') !== false) {
                $camp_type = 'training';
            } else {
                $camp_type = 'camp';
            }
        }
        
        // Get age groups
        $age_groups = get_post_meta($id, '_ptp_age_groups', true) ?: array();
        if (is_string($age_groups) && !empty($age_groups)) {
            $decoded = json_decode($age_groups, true);
            $age_groups = is_array($decoded) ? $decoded : array();
        }
        
        // Get skill levels
        $skill_levels = get_post_meta($id, '_ptp_skill_levels', true) ?: array();
        if (is_string($skill_levels) && !empty($skill_levels)) {
            $decoded = json_decode($skill_levels, true);
            $skill_levels = is_array($decoded) ? $decoded : array();
        }
        
        // Capacity
        $max = intval(get_post_meta($id, '_ptp_max_capacity', true));
        $sold = intval(get_post_meta($id, '_ptp_sold_count', true));
        
        $data = array(
            'id' => $id,
            'name' => $product->get_name(),
            'title' => $product->get_name(),
            'slug' => $post->post_name,
            'type' => $camp_type,
            'short_description' => $product->get_short_description(),
            'price' => floatval($product->get_price()),
            'regular_price' => floatval($product->get_regular_price()),
            'sale_price' => $product->get_sale_price() ? floatval($product->get_sale_price()) : null,
            'on_sale' => $product->is_on_sale(),
            'currency' => get_woocommerce_currency(),
            'featured_image' => !empty($images) ? $images[0]['url'] : null,
            'thumbnail' => !empty($images) ? ($images[0]['medium'] ?? $images[0]['url']) : null,
            'start_date' => get_post_meta($id, '_ptp_start_date', true) ?: null,
            'end_date' => get_post_meta($id, '_ptp_end_date', true) ?: null,
            'location' => array(
                'name' => get_post_meta($id, '_ptp_location_name', true) ?: '',
                'address' => get_post_meta($id, '_ptp_address', true) ?: '',
                'city' => get_post_meta($id, '_ptp_city', true) ?: '',
                'state' => get_post_meta($id, '_ptp_state', true) ?: '',
                'zip' => get_post_meta($id, '_ptp_zip', true) ?: '',
                'latitude' => floatval(get_post_meta($id, '_ptp_latitude', true)),
                'longitude' => floatval(get_post_meta($id, '_ptp_longitude', true)),
            ),
            'age_groups' => $age_groups,
            'skill_levels' => $skill_levels,
            'capacity' => array(
                'max' => $max,
                'sold' => $sold,
                'remaining' => $max > 0 ? max(0, $max - $sold) : null,
                'is_sold_out' => $max > 0 && $sold >= $max,
            ),
            'is_featured' => $product->is_featured(),
            'is_in_stock' => $product->is_in_stock(),
            'stock_status' => $product->get_stock_status(),
            'permalink' => get_permalink($id),
            'created_at' => $post->post_date,
            'updated_at' => $post->post_modified,
        );
        
        // Full details for single camp view
        if ($full_details) {
            $data['description'] = $product->get_description();
            $data['images'] = $images;
            $data['gallery'] = array_map(function($img) { return $img['url']; }, $images);
            
            $daily_times = get_post_meta($id, '_ptp_daily_times', true) ?: array();
            $data['daily_times'] = array(
                'start' => $daily_times['start'] ?? '09:00',
                'end' => $daily_times['end'] ?? '15:00',
            );
            
            $data['what_to_bring'] = get_post_meta($id, '_ptp_what_to_bring', true) ?: '';
            $data['included'] = get_post_meta($id, '_ptp_included', true) ?: '';
            $data['contact'] = array(
                'email' => get_post_meta($id, '_ptp_contact_email', true) ?: '',
                'phone' => get_post_meta($id, '_ptp_contact_phone', true) ?: '',
            );
            
            // Categories
            $cats = wp_get_post_terms($id, 'product_cat');
            $data['categories'] = array_map(function($cat) {
                return array('id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug);
            }, is_array($cats) ? $cats : array());
            
            // Tags
            $tags = wp_get_post_terms($id, 'product_tag');
            $data['tags'] = array_map(function($tag) {
                return array('id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug);
            }, is_array($tags) ? $tags : array());
        }
        
        return $data;
    }
}

// Initialize
PTP_Camps_API::init();
