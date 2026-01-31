<?php
/**
 * PTP WooCommerce Camps Sync
 * Bidirectional sync between PTP Camps admin and WooCommerce products
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_WooCommerce_Camps {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Only run if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Add PTP Camp tab to WooCommerce product
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_panel'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
        
        // Sync WooCommerce orders to PTP registrations
        add_action('woocommerce_order_status_completed', array($this, 'sync_order_to_registration'));
        add_action('woocommerce_order_status_processing', array($this, 'sync_order_to_registration'));
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_registration'));
        add_action('woocommerce_order_status_refunded', array($this, 'cancel_registration'));
        
        // Add camp info to order emails
        add_action('woocommerce_email_after_order_table', array($this, 'add_camp_info_to_email'), 10, 4);
        
        // Add camp details to order admin
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_camp_order_meta'));
        
        // Track stock for camps
        add_filter('woocommerce_product_get_stock_quantity', array($this, 'get_camp_stock'), 10, 2);
        add_filter('woocommerce_product_get_manage_stock', array($this, 'manage_camp_stock'), 10, 2);
        
        // Add custom columns to products list
        add_filter('manage_edit-product_columns', array($this, 'add_camp_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'render_camp_columns'), 10, 2);
        
        // Create camp product category on activation
        add_action('init', array($this, 'create_camp_category'));
    }
    
    /**
     * Create Camps & Clinics product category
     */
    public function create_camp_category() {
        if (!term_exists('camps-clinics', 'product_cat')) {
            wp_insert_term(
                'Camps & Clinics',
                'product_cat',
                array(
                    'slug' => 'camps-clinics',
                    'description' => 'Camps and training clinics',
                )
            );
        }
    }
    
    /**
     * Add PTP Camp tab to product data
     */
    public function add_product_tab($tabs) {
        $tabs['ptp_camp'] = array(
            'label' => __('Camp/Clinic', 'ptp'),
            'target' => 'ptp_camp_data',
            'class' => array('show_if_simple'),
            'priority' => 25,
        );
        return $tabs;
    }
    
    /**
     * Add PTP Camp panel content
     */
    public function add_product_panel() {
        global $post;
        
        $is_camp = get_post_meta($post->ID, '_ptp_is_camp', true);
        $camp_type = get_post_meta($post->ID, '_ptp_camp_type', true) ?: 'camp';
        ?>
        <div id="ptp_camp_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(array(
                    'id' => '_ptp_is_camp',
                    'label' => __('This is a Camp/Clinic', 'ptp'),
                    'description' => __('Enable PTP camp features for this product', 'ptp'),
                ));
                
                woocommerce_wp_select(array(
                    'id' => '_ptp_camp_type',
                    'label' => __('Type', 'ptp'),
                    'options' => array(
                        'camp' => __('Summer Camp', 'ptp'),
                        'clinic' => __('Skills Clinic', 'ptp'),
                        'academy' => __('Academy Program', 'ptp'),
                    ),
                    'value' => $camp_type,
                ));
                ?>
            </div>
            
            <div class="options_group ptp-camp-fields" style="<?php echo $is_camp !== 'yes' ? 'display:none;' : ''; ?>">
                <h4 style="padding-left:12px;margin:15px 0 10px;">📅 Dates & Times</h4>
                <?php
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_start_date',
                    'label' => __('Start Date', 'ptp'),
                    'type' => 'date',
                    'value' => get_post_meta($post->ID, '_ptp_start_date', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_end_date',
                    'label' => __('End Date', 'ptp'),
                    'type' => 'date',
                    'value' => get_post_meta($post->ID, '_ptp_end_date', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_daily_start',
                    'label' => __('Daily Start Time', 'ptp'),
                    'type' => 'time',
                    'value' => get_post_meta($post->ID, '_ptp_daily_start', true) ?: '09:00',
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_daily_end',
                    'label' => __('Daily End Time', 'ptp'),
                    'type' => 'time',
                    'value' => get_post_meta($post->ID, '_ptp_daily_end', true) ?: '15:00',
                ));
                ?>
            </div>
            
            <div class="options_group ptp-camp-fields" style="<?php echo $is_camp !== 'yes' ? 'display:none;' : ''; ?>">
                <h4 style="padding-left:12px;margin:15px 0 10px;">📍 Location</h4>
                <?php
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_location_name',
                    'label' => __('Venue Name', 'ptp'),
                    'placeholder' => 'e.g., Villanova Stadium',
                    'value' => get_post_meta($post->ID, '_ptp_location_name', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_address',
                    'label' => __('Address', 'ptp'),
                    'value' => get_post_meta($post->ID, '_ptp_address', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_city',
                    'label' => __('City', 'ptp'),
                    'value' => get_post_meta($post->ID, '_ptp_city', true),
                ));
                
                woocommerce_wp_select(array(
                    'id' => '_ptp_state',
                    'label' => __('State', 'ptp'),
                    'options' => array(
                        '' => __('Select State', 'ptp'),
                        'PA' => 'Pennsylvania',
                        'NJ' => 'New Jersey',
                        'DE' => 'Delaware',
                        'MD' => 'Maryland',
                        'NY' => 'New York',
                    ),
                    'value' => get_post_meta($post->ID, '_ptp_state', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_zip',
                    'label' => __('ZIP Code', 'ptp'),
                    'value' => get_post_meta($post->ID, '_ptp_zip', true),
                ));
                ?>
                
                <p class="form-field">
                    <label>Coordinates</label>
                    <input type="text" name="_ptp_latitude" value="<?php echo esc_attr(get_post_meta($post->ID, '_ptp_latitude', true)); ?>" placeholder="Latitude" style="width:45%;">
                    <input type="text" name="_ptp_longitude" value="<?php echo esc_attr(get_post_meta($post->ID, '_ptp_longitude', true)); ?>" placeholder="Longitude" style="width:45%;">
                </p>
            </div>
            
            <div class="options_group ptp-camp-fields" style="<?php echo $is_camp !== 'yes' ? 'display:none;' : ''; ?>">
                <h4 style="padding-left:12px;margin:15px 0 10px;">👥 Capacity & Age Groups</h4>
                <?php
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_max_capacity',
                    'label' => __('Max Campers', 'ptp'),
                    'type' => 'number',
                    'custom_attributes' => array('min' => '1'),
                    'value' => get_post_meta($post->ID, '_ptp_max_capacity', true),
                ));
                
                $age_groups = get_post_meta($post->ID, '_ptp_age_groups', true) ?: array();
                if (!is_array($age_groups)) {
                    $age_groups = json_decode($age_groups, true) ?: array();
                }
                ?>
                <p class="form-field">
                    <label><?php _e('Age Groups', 'ptp'); ?></label>
                    <span class="ptp-checkbox-group">
                        <?php
                        $ages = array('u6' => 'U6', 'u8' => 'U8', 'u10' => 'U10', 'u12' => 'U12', 'u14' => 'U14', 'u16' => 'U16', 'u18' => 'U18');
                        foreach ($ages as $key => $label):
                        ?>
                        <label style="display:inline-block;margin-right:15px;">
                            <input type="checkbox" name="_ptp_age_groups[]" value="<?php echo $key; ?>" <?php checked(in_array($key, $age_groups)); ?>>
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </span>
                </p>
                
                <?php
                $skill_levels = get_post_meta($post->ID, '_ptp_skill_levels', true) ?: array();
                if (!is_array($skill_levels)) {
                    $skill_levels = json_decode($skill_levels, true) ?: array();
                }
                ?>
                <p class="form-field">
                    <label><?php _e('Skill Levels', 'ptp'); ?></label>
                    <span class="ptp-checkbox-group">
                        <?php
                        $levels = array('beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'elite' => 'Elite');
                        foreach ($levels as $key => $label):
                        ?>
                        <label style="display:inline-block;margin-right:15px;">
                            <input type="checkbox" name="_ptp_skill_levels[]" value="<?php echo $key; ?>" <?php checked(in_array($key, $skill_levels)); ?>>
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </span>
                </p>
            </div>
            
            <div class="options_group ptp-camp-fields" style="<?php echo $is_camp !== 'yes' ? 'display:none;' : ''; ?>">
                <h4 style="padding-left:12px;margin:15px 0 10px;">📋 Additional Info</h4>
                <?php
                woocommerce_wp_textarea_input(array(
                    'id' => '_ptp_what_to_bring',
                    'label' => __('What to Bring', 'ptp'),
                    'placeholder' => 'Athletic gear, water bottle, appropriate footwear...',
                    'value' => get_post_meta($post->ID, '_ptp_what_to_bring', true),
                ));
                
                woocommerce_wp_textarea_input(array(
                    'id' => '_ptp_included',
                    'label' => __("What's Included", 'ptp'),
                    'placeholder' => 'Camp t-shirt, snacks, certificate...',
                    'value' => get_post_meta($post->ID, '_ptp_included', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_contact_email',
                    'label' => __('Contact Email', 'ptp'),
                    'type' => 'email',
                    'value' => get_post_meta($post->ID, '_ptp_contact_email', true),
                ));
                
                woocommerce_wp_text_input(array(
                    'id' => '_ptp_contact_phone',
                    'label' => __('Contact Phone', 'ptp'),
                    'value' => get_post_meta($post->ID, '_ptp_contact_phone', true),
                ));
                ?>
            </div>
            
            <script>
            jQuery(function($) {
                $('#_ptp_is_camp').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('.ptp-camp-fields').show();
                    } else {
                        $('.ptp-camp-fields').hide();
                    }
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Save PTP Camp meta
     */
    public function save_product_meta($post_id) {
        // Camp checkbox
        $is_camp = isset($_POST['_ptp_is_camp']) ? 'yes' : 'no';
        update_post_meta($post_id, '_ptp_is_camp', $is_camp);
        
        if ($is_camp !== 'yes') {
            return;
        }
        
        // Text fields
        $text_fields = array(
            '_ptp_camp_type', '_ptp_start_date', '_ptp_end_date',
            '_ptp_daily_start', '_ptp_daily_end',
            '_ptp_location_name', '_ptp_address', '_ptp_city', '_ptp_state', '_ptp_zip',
            '_ptp_latitude', '_ptp_longitude',
            '_ptp_max_capacity', '_ptp_what_to_bring', '_ptp_included',
            '_ptp_contact_email', '_ptp_contact_phone',
        );
        
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
        
        // Array fields
        $age_groups = isset($_POST['_ptp_age_groups']) ? array_map('sanitize_text_field', $_POST['_ptp_age_groups']) : array();
        update_post_meta($post_id, '_ptp_age_groups', $age_groups);
        
        $skill_levels = isset($_POST['_ptp_skill_levels']) ? array_map('sanitize_text_field', $_POST['_ptp_skill_levels']) : array();
        update_post_meta($post_id, '_ptp_skill_levels', $skill_levels);
        
        // Update stock management
        if (!empty($_POST['_ptp_max_capacity'])) {
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', intval($_POST['_ptp_max_capacity']));
        }
        
        // Add to camps category
        $camp_cat = get_term_by('slug', 'camps-clinics', 'product_cat');
        if ($camp_cat) {
            wp_set_object_terms($post_id, array($camp_cat->term_id), 'product_cat', true);
        }
    }
    
    /**
     * Sync WooCommerce order to PTP registration
     */
    public function sync_order_to_registration($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        
        // Check if table exists, create if not
        $this->maybe_create_registrations_table();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            
            if (get_post_meta($product_id, '_ptp_is_camp', true) !== 'yes') {
                continue;
            }
            
            $quantity = $item->get_quantity();
            
            // Get customer info
            $customer_id = $order->get_customer_id();
            $billing_email = $order->get_billing_email();
            $billing_first = $order->get_billing_first_name();
            $billing_last = $order->get_billing_last_name();
            $billing_phone = $order->get_billing_phone();
            
            // Check if already registered
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE order_id = %d AND camp_id = %d",
                $order_id, $product_id
            ));
            
            if ($exists) {
                // Update existing
                $wpdb->update($table, array(
                    'status' => 'confirmed',
                    'quantity' => $quantity,
                    'updated_at' => current_time('mysql'),
                ), array('id' => $exists));
            } else {
                // Create new registration
                $wpdb->insert($table, array(
                    'camp_id' => $product_id,
                    'order_id' => $order_id,
                    'user_id' => $customer_id,
                    'parent_email' => $billing_email,
                    'parent_name' => trim("$billing_first $billing_last"),
                    'parent_phone' => $billing_phone,
                    'quantity' => $quantity,
                    'amount_paid' => $item->get_total(),
                    'status' => 'confirmed',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ));
                
                // Update sold count
                $sold = intval(get_post_meta($product_id, '_ptp_sold_count', true));
                update_post_meta($product_id, '_ptp_sold_count', $sold + $quantity);
                
                // Update WooCommerce stock
                $max = intval(get_post_meta($product_id, '_ptp_max_capacity', true));
                if ($max > 0) {
                    $remaining = max(0, $max - $sold - $quantity);
                    update_post_meta($product_id, '_stock', $remaining);
                    
                    if ($remaining <= 0) {
                        update_post_meta($product_id, '_stock_status', 'outofstock');
                    }
                }
            }
            
            // Store camper details from order meta if available
            $camper_details = $order->get_meta('_ptp_camper_details');
            if (!empty($camper_details) && is_array($camper_details)) {
                foreach ($camper_details as $camper) {
                    if (isset($camper['camp_id']) && $camper['camp_id'] == $product_id) {
                        $wpdb->insert($wpdb->prefix . 'ptp_campers', array(
                            'registration_id' => $wpdb->insert_id,
                            'camp_id' => $product_id,
                            'name' => sanitize_text_field($camper['name'] ?? ''),
                            'age' => intval($camper['age'] ?? 0),
                            'birth_date' => sanitize_text_field($camper['birth_date'] ?? ''),
                            'gender' => sanitize_text_field($camper['gender'] ?? ''),
                            'medical_notes' => sanitize_textarea_field($camper['medical_notes'] ?? ''),
                            'emergency_contact' => sanitize_text_field($camper['emergency_contact'] ?? ''),
                            'emergency_phone' => sanitize_text_field($camper['emergency_phone'] ?? ''),
                            'created_at' => current_time('mysql'),
                        ));
                    }
                }
            }
        }
    }
    
    /**
     * Cancel registration when order cancelled/refunded
     */
    public function cancel_registration($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order_id
        ));
        
        foreach ($registrations as $reg) {
            // Update status
            $wpdb->update($table, array(
                'status' => 'cancelled',
                'updated_at' => current_time('mysql'),
            ), array('id' => $reg->id));
            
            // Restore stock
            $sold = intval(get_post_meta($reg->camp_id, '_ptp_sold_count', true));
            update_post_meta($reg->camp_id, '_ptp_sold_count', max(0, $sold - $reg->quantity));
            
            // Update WooCommerce stock
            $max = intval(get_post_meta($reg->camp_id, '_ptp_max_capacity', true));
            $new_sold = max(0, $sold - $reg->quantity);
            if ($max > 0) {
                update_post_meta($reg->camp_id, '_stock', $max - $new_sold);
                update_post_meta($reg->camp_id, '_stock_status', 'instock');
            }
        }
    }
    
    /**
     * Add camp info to order emails
     */
    public function add_camp_info_to_email($order, $sent_to_admin, $plain_text, $email) {
        $camp_items = array();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (get_post_meta($product_id, '_ptp_is_camp', true) !== 'yes') {
                continue;
            }
            
            $camp_items[] = array(
                'name' => $item->get_name(),
                'start_date' => get_post_meta($product_id, '_ptp_start_date', true),
                'end_date' => get_post_meta($product_id, '_ptp_end_date', true),
                'location' => get_post_meta($product_id, '_ptp_location_name', true),
                'address' => get_post_meta($product_id, '_ptp_address', true),
                'city' => get_post_meta($product_id, '_ptp_city', true),
                'state' => get_post_meta($product_id, '_ptp_state', true),
                'daily_start' => get_post_meta($product_id, '_ptp_daily_start', true),
                'daily_end' => get_post_meta($product_id, '_ptp_daily_end', true),
                'what_to_bring' => get_post_meta($product_id, '_ptp_what_to_bring', true),
                'contact_email' => get_post_meta($product_id, '_ptp_contact_email', true),
            );
        }
        
        if (empty($camp_items)) return;
        
        if ($plain_text) {
            echo "\n\n=== CAMP DETAILS ===\n\n";
            foreach ($camp_items as $camp) {
                echo $camp['name'] . "\n";
                echo "Dates: " . date('M j', strtotime($camp['start_date'])) . " - " . date('M j, Y', strtotime($camp['end_date'])) . "\n";
                echo "Location: " . $camp['location'] . "\n";
                echo "Address: " . $camp['address'] . ", " . $camp['city'] . ", " . $camp['state'] . "\n";
                echo "Daily Hours: " . date('g:ia', strtotime($camp['daily_start'])) . " - " . date('g:ia', strtotime($camp['daily_end'])) . "\n";
                if ($camp['what_to_bring']) {
                    echo "What to Bring: " . $camp['what_to_bring'] . "\n";
                }
                echo "\n";
            }
        } else {
            ?>
            <h2 style="color:#FCB900;font-family:'Oswald',sans-serif;text-transform:uppercase;margin-top:30px;">Camp Details</h2>
            <?php foreach ($camp_items as $camp): ?>
            <table cellspacing="0" cellpadding="10" style="width:100%;border:2px solid #0a0a0a;margin-bottom:20px;background:#fff;">
                <tr>
                    <td style="background:#0a0a0a;color:#FCB900;font-weight:bold;font-size:16px;" colspan="2">
                        <?php echo esc_html($camp['name']); ?>
                    </td>
                </tr>
                <tr>
                    <td style="width:30%;font-weight:bold;border-bottom:1px solid #eee;">📅 Dates</td>
                    <td style="border-bottom:1px solid #eee;">
                        <?php echo date('M j', strtotime($camp['start_date'])); ?> - <?php echo date('M j, Y', strtotime($camp['end_date'])); ?>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight:bold;border-bottom:1px solid #eee;">⏰ Daily Hours</td>
                    <td style="border-bottom:1px solid #eee;">
                        <?php echo date('g:ia', strtotime($camp['daily_start'])); ?> - <?php echo date('g:ia', strtotime($camp['daily_end'])); ?>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight:bold;border-bottom:1px solid #eee;">📍 Location</td>
                    <td style="border-bottom:1px solid #eee;">
                        <strong><?php echo esc_html($camp['location']); ?></strong><br>
                        <?php echo esc_html($camp['address']); ?><br>
                        <?php echo esc_html($camp['city'] . ', ' . $camp['state']); ?>
                    </td>
                </tr>
                <?php if ($camp['what_to_bring']): ?>
                <tr>
                    <td style="font-weight:bold;border-bottom:1px solid #eee;">🎒 What to Bring</td>
                    <td style="border-bottom:1px solid #eee;"><?php echo esc_html($camp['what_to_bring']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($camp['contact_email']): ?>
                <tr>
                    <td style="font-weight:bold;">📧 Questions?</td>
                    <td><a href="mailto:<?php echo esc_attr($camp['contact_email']); ?>"><?php echo esc_html($camp['contact_email']); ?></a></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php endforeach;
        }
    }
    
    /**
     * Display camp details in order admin
     */
    public function display_camp_order_meta($order) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as camp_name 
             FROM $table r
             JOIN {$wpdb->posts} p ON r.camp_id = p.ID
             WHERE r.order_id = %d",
            $order->get_id()
        ));
        
        if (empty($registrations)) return;
        
        echo '<div class="ptp-camp-registrations" style="margin-top:20px;">';
        echo '<h3 style="color:#FCB900;">⚽ Camp Registrations</h3>';
        
        foreach ($registrations as $reg) {
            $start = get_post_meta($reg->camp_id, '_ptp_start_date', true);
            $location = get_post_meta($reg->camp_id, '_ptp_location_name', true);
            
            echo '<div style="background:#f5f5f5;border:2px solid #0a0a0a;padding:10px;margin-bottom:10px;">';
            echo '<strong>' . esc_html($reg->camp_name) . '</strong><br>';
            echo 'Status: <span style="color:' . ($reg->status === 'confirmed' ? '#16a34a' : '#dc3545') . ';font-weight:bold;">' . ucfirst($reg->status) . '</span><br>';
            echo 'Quantity: ' . $reg->quantity . ' camper(s)<br>';
            echo 'Date: ' . date('M j, Y', strtotime($start)) . '<br>';
            echo 'Location: ' . esc_html($location);
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Get camp stock (remaining spots)
     */
    public function get_camp_stock($quantity, $product) {
        // All products are treated as camps now
        $max = intval(get_post_meta($product->get_id(), '_ptp_max_capacity', true));
        if ($max <= 0) {
            return $quantity; // No capacity limit set
        }
        
        $sold = intval(get_post_meta($product->get_id(), '_ptp_sold_count', true));
        return max(0, $max - $sold);
    }
    
    /**
     * Enable stock management for camps with capacity
     */
    public function manage_camp_stock($manage, $product) {
        $max = intval(get_post_meta($product->get_id(), '_ptp_max_capacity', true));
        if ($max > 0) {
            return true;
        }
        return $manage;
    }
    
    /**
     * Add camp column to products list
     */
    public function add_camp_columns($columns) {
        $new = array();
        foreach ($columns as $key => $value) {
            $new[$key] = $value;
            if ($key === 'name') {
                $new['ptp_camp'] = 'Camp/Clinic';
            }
        }
        return $new;
    }
    
    /**
     * Render camp column
     */
    public function render_camp_columns($column, $post_id) {
        if ($column !== 'ptp_camp') return;
        
        if (get_post_meta($post_id, '_ptp_is_camp', true) !== 'yes') {
            echo '—';
            return;
        }
        
        $type = get_post_meta($post_id, '_ptp_camp_type', true) ?: 'camp';
        $start = get_post_meta($post_id, '_ptp_start_date', true);
        $max = intval(get_post_meta($post_id, '_ptp_max_capacity', true));
        $sold = intval(get_post_meta($post_id, '_ptp_sold_count', true));
        
        $type_colors = array(
            'camp' => '#3b82f6',
            'clinic' => '#8b5cf6',
            'academy' => '#0a0a0a',
        );
        
        echo '<span style="background:' . ($type_colors[$type] ?? '#666') . ';color:#fff;padding:2px 8px;font-size:11px;text-transform:uppercase;border-radius:3px;">' . ucfirst($type) . '</span><br>';
        
        if ($start) {
            echo '<small>' . date('M j, Y', strtotime($start)) . '</small><br>';
        }
        
        if ($max > 0) {
            $pct = ($sold / $max) * 100;
            $color = $pct >= 90 ? '#dc3545' : ($pct >= 70 ? '#f59e0b' : '#16a34a');
            echo '<small style="color:' . $color . ';">' . $sold . '/' . $max . ' registered</small>';
        }
    }
    
    /**
     * Create registrations table if needed
     */
    private function maybe_create_registrations_table() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            camp_id bigint(20) UNSIGNED NOT NULL,
            order_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT 0,
            parent_email varchar(255) NOT NULL,
            parent_name varchar(255) NOT NULL,
            parent_phone varchar(50) DEFAULT '',
            quantity int(11) DEFAULT 1,
            amount_paid decimal(10,2) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY camp_id (camp_id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Also create campers table
        $campers_table = $wpdb->prefix . 'ptp_campers';
        $sql = "CREATE TABLE IF NOT EXISTS $campers_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id bigint(20) UNSIGNED NOT NULL,
            camp_id bigint(20) UNSIGNED NOT NULL,
            name varchar(255) NOT NULL,
            age int(11) DEFAULT 0,
            birth_date date DEFAULT NULL,
            gender varchar(20) DEFAULT '',
            medical_notes text,
            emergency_contact varchar(255) DEFAULT '',
            emergency_phone varchar(50) DEFAULT '',
            waiver_signed tinyint(1) DEFAULT 0,
            waiver_signed_at datetime DEFAULT NULL,
            checked_in tinyint(1) DEFAULT 0,
            checked_in_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY camp_id (camp_id)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * Get registrations for a camp
     */
    public static function get_registrations($camp_id, $status = 'confirmed') {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, 
                    GROUP_CONCAT(c.name SEPARATOR ', ') as camper_names
             FROM {$wpdb->prefix}ptp_camp_registrations r
             LEFT JOIN {$wpdb->prefix}ptp_campers c ON r.id = c.registration_id
             WHERE r.camp_id = %d AND r.status = %s
             GROUP BY r.id
             ORDER BY r.created_at DESC",
            $camp_id, $status
        ));
    }
    
    /**
     * Get all camps (for admin)
     */
    public static function get_all_camps($args = array()) {
        $defaults = array(
            'status' => 'any',
            'type' => '',
            'upcoming' => false,
            'limit' => 50,
        );
        $args = wp_parse_args($args, $defaults);
        
        $meta_query = array(
            array(
                'key' => '_ptp_is_camp',
                'value' => 'yes',
            ),
        );
        
        if ($args['type']) {
            $meta_query[] = array(
                'key' => '_ptp_camp_type',
                'value' => $args['type'],
            );
        }
        
        if ($args['upcoming']) {
            $meta_query[] = array(
                'key' => '_ptp_start_date',
                'value' => date('Y-m-d'),
                'compare' => '>=',
                'type' => 'DATE',
            );
        }
        
        return get_posts(array(
            'post_type' => 'product',
            'post_status' => $args['status'],
            'posts_per_page' => $args['limit'],
            'meta_query' => $meta_query,
            'orderby' => 'meta_value',
            'meta_key' => '_ptp_start_date',
            'order' => 'ASC',
        ));
    }
}

// Initialize
PTP_WooCommerce_Camps::instance();
