<?php
/**
 * PTP WooCommerce Emails v115
 * Dynamic camp/clinic order confirmation emails matching PTP v85 design
 * 
 * FIXED v114.2: Added comprehensive hooks to ensure emails are sent
 * UPDATED v115: Coordinates with PTP_Order_Email_Wiring class
 */

defined('ABSPATH') || exit;

class PTP_WooCommerce_Emails {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        if (!class_exists('WooCommerce')) return;
        
        // Override WooCommerce email templates
        add_filter('woocommerce_email_styles', array($this, 'email_styles'));
        add_filter('woocommerce_email_headers', array($this, 'email_headers'), 10, 3);
        
        // Complete email override for orders with camps/clinics
        add_action('woocommerce_email_before_order_table', array($this, 'before_order_table'), 5, 4);
        add_action('woocommerce_email_after_order_table', array($this, 'after_order_table'), 5, 4);
        
        // Or fully replace the email content
        add_filter('woocommerce_mail_content', array($this, 'maybe_replace_email_content'), 10, 1);
        
        // v115: Only use these hooks if the new wiring class is not active
        // The new PTP_Order_Email_Wiring class handles these more comprehensively
        if (!class_exists('PTP_Order_Email_Wiring')) {
            // Hook 1: Order status changes to processing/completed
            add_action('woocommerce_order_status_processing', array($this, 'send_ptp_confirmation'), 20, 1);
            add_action('woocommerce_order_status_completed', array($this, 'send_ptp_confirmation'), 20, 1);
            
            // Hook 2: Payment completed (Stripe, PayPal, etc.)
            add_action('woocommerce_payment_complete', array($this, 'send_ptp_confirmation'), 20, 1);
            
            // Hook 3: After checkout order is processed
            add_action('woocommerce_checkout_order_processed', array($this, 'send_ptp_confirmation'), 20, 1);
            
            // Hook 4: Thank you page (backup - ensures email is sent if nothing else worked)
            add_action('woocommerce_thankyou', array($this, 'send_ptp_confirmation'), 5, 1);
        }
        
        // Admin settings - use priority 20 to ensure parent menu exists
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_ptp_preview_email', array($this, 'ajax_preview_email'));
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('woocommerce_order_status_changed', array($this, 'log_order_status_change'), 10, 4);
        }
    }
    
    /**
     * Log order status changes for debugging
     */
    public function log_order_status_change($order_id, $from, $to, $order) {
        error_log("PTP Email Debug: Order #$order_id status changed from $from to $to");
    }
    
    /**
     * Add admin menu - DISABLED: Email settings now in Settings → Emails tab
     */
    public function add_admin_menu() {
        // Email settings moved to main Settings page (Settings → Emails tab)
        // Keeping this method for backwards compatibility but not adding submenu
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ptp_email_settings', 'ptp_email_enabled', array('default' => 'yes'));
        register_setting('ptp_email_settings', 'ptp_email_logo_url');
        register_setting('ptp_email_settings', 'ptp_email_upsell_enabled', array('default' => 'yes'));
        register_setting('ptp_email_settings', 'ptp_email_upsell_text');
        register_setting('ptp_email_settings', 'ptp_email_support_phone');
        register_setting('ptp_email_settings', 'ptp_email_support_email');
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        
        // Handle manual resend
        if (isset($_GET['resend_order']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'ptp_resend_email')) {
                $order_id = intval($_GET['resend_order']);
                $order = wc_get_order($order_id);
                if ($order) {
                    // Clear the sent flag to allow resend
                    $order->delete_meta_data('_ptp_email_sent');
                    $order->save();
                    
                    // Send the email
                    $this->send_ptp_confirmation($order_id);
                    
                    echo '<div class="notice notice-success"><p>Email resent for order #' . $order->get_order_number() . '</p></div>';
                }
            }
        }
        
        if (isset($_POST['ptp_email_nonce']) && wp_verify_nonce($_POST['ptp_email_nonce'], 'ptp_email_settings')) {
            update_option('ptp_email_enabled', isset($_POST['ptp_email_enabled']) ? 'yes' : 'no');
            update_option('ptp_email_logo_url', esc_url_raw($_POST['ptp_email_logo_url'] ?? ''));
            update_option('ptp_email_upsell_enabled', isset($_POST['ptp_email_upsell_enabled']) ? 'yes' : 'no');
            update_option('ptp_email_upsell_text', sanitize_textarea_field($_POST['ptp_email_upsell_text'] ?? ''));
            update_option('ptp_email_support_phone', sanitize_text_field($_POST['ptp_email_support_phone'] ?? ''));
            update_option('ptp_email_support_email', sanitize_email($_POST['ptp_email_support_email'] ?? ''));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $enabled = get_option('ptp_email_enabled', 'yes');
        $logo = get_option('ptp_email_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png');
        $upsell_enabled = get_option('ptp_email_upsell_enabled', 'yes');
        $upsell_text = get_option('ptp_email_upsell_text', 'Get camp-ready with private training from our pro trainers.');
        $phone = get_option('ptp_email_support_phone', '');
        $email = get_option('ptp_email_support_email', get_option('admin_email'));
        ?>
        <div class="wrap">
            <h1>PTP Email Templates</h1>
            
            <?php if ($enabled !== 'yes'): ?>
            <div class="notice notice-warning"><p><strong>⚠️ PTP Emails are currently DISABLED.</strong> Enable them below to send branded order confirmations.</p></div>
            <?php endif; ?>
            
            <div style="display:grid;grid-template-columns:1fr 400px;gap:30px;margin-top:20px;">
                <div>
                    <form method="post">
                        <?php wp_nonce_field('ptp_email_settings', 'ptp_email_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th>Enable PTP Emails</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ptp_email_enabled" <?php checked($enabled, 'yes'); ?>>
                                        Use PTP branded emails for camp/clinic orders
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Logo URL</th>
                                <td>
                                    <input type="url" name="ptp_email_logo_url" value="<?php echo esc_attr($logo); ?>" class="regular-text">
                                    <p class="description">Logo displayed at top of emails</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Support Phone</th>
                                <td><input type="text" name="ptp_email_support_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Support Email</th>
                                <td><input type="email" name="ptp_email_support_email" value="<?php echo esc_attr($email); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Enable Upsell</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="ptp_email_upsell_enabled" <?php checked($upsell_enabled, 'yes'); ?>>
                                        Show private training upsell in camp emails
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th>Upsell Text</th>
                                <td>
                                    <textarea name="ptp_email_upsell_text" rows="3" class="large-text"><?php echo esc_textarea($upsell_text); ?></textarea>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button('Save Settings'); ?>
                    </form>
                    
                    <h2>Preview & Resend Emails</h2>
                    <p>Select an order to preview or resend the confirmation email:</p>
                    <select id="ptp-preview-order" style="width:300px;">
                        <option value="">Select an order...</option>
                        <?php
                        $orders = wc_get_orders(array('limit' => 30, 'orderby' => 'date', 'order' => 'DESC'));
                        foreach ($orders as $order) {
                            $has_camp = false;
                            foreach ($order->get_items() as $item) {
                                $product_id = $item->get_product_id();
                                if (get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                                    has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $product_id)) {
                                    $has_camp = true;
                                    break;
                                }
                            }
                            if ($has_camp) {
                                $email_sent = $order->get_meta('_ptp_email_sent');
                                $status_icon = $email_sent ? '✅' : '⚠️';
                                echo '<option value="' . $order->get_id() . '">' . $status_icon . ' #' . $order->get_order_number() . ' - ' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <button type="button" class="button" onclick="previewEmail()">Preview</button>
                    <button type="button" class="button button-primary" onclick="resendEmail()" style="margin-left:5px;">Resend Email</button>
                    
                    <script>
                    function previewEmail() {
                        var orderId = document.getElementById('ptp-preview-order').value;
                        if (!orderId) { alert('Select an order first'); return; }
                        window.open('<?php echo admin_url('admin-ajax.php'); ?>?action=ptp_preview_email&order_id=' + orderId, '_blank', 'width=700,height=900');
                    }
                    function resendEmail() {
                        var orderId = document.getElementById('ptp-preview-order').value;
                        if (!orderId) { alert('Select an order first'); return; }
                        if (confirm('Resend confirmation email for this order?')) {
                            window.location.href = '<?php echo admin_url('admin.php?page=ptp-email-templates'); ?>&resend_order=' + orderId + '&_wpnonce=<?php echo wp_create_nonce('ptp_resend_email'); ?>';
                        }
                    }
                    </script>
                    
                    <h3 style="margin-top:30px;">Recent Orders - Email Status</h3>
                    <table class="wp-list-table widefat striped" style="max-width:100%;">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>PTP Email Sent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $camp_orders = array();
                            foreach ($orders as $order) {
                                $has_camp = false;
                                foreach ($order->get_items() as $item) {
                                    $product_id = $item->get_product_id();
                                    if (get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                                        has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $product_id)) {
                                        $has_camp = true;
                                        break;
                                    }
                                }
                                if ($has_camp) {
                                    $camp_orders[] = $order;
                                }
                            }
                            if (empty($camp_orders)): ?>
                            <tr><td colspan="6" style="text-align:center;color:#666;">No camp/clinic orders found</td></tr>
                            <?php else:
                            foreach (array_slice($camp_orders, 0, 15) as $o):
                                $email_sent = $o->get_meta('_ptp_email_sent');
                                $resend_url = wp_nonce_url(admin_url('admin.php?page=ptp-email-templates&resend_order=' . $o->get_id()), 'ptp_resend_email');
                            ?>
                            <tr>
                                <td><a href="<?php echo admin_url('post.php?post=' . $o->get_id() . '&action=edit'); ?>">#<?php echo $o->get_order_number(); ?></a></td>
                                <td><?php echo esc_html($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()); ?></td>
                                <td><?php echo esc_html($o->get_billing_email()); ?></td>
                                <td><?php echo esc_html(ucfirst($o->get_status())); ?></td>
                                <td>
                                    <?php if ($email_sent): ?>
                                        <span style="color:green;">✅ <?php echo date('M j, g:i a', strtotime($email_sent)); ?></span>
                                    <?php else: ?>
                                        <span style="color:orange;">⚠️ Not sent</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($resend_url); ?>" class="button button-small"><?php echo $email_sent ? 'Resend' : 'Send'; ?></a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="background:#f0f0f1;padding:20px;border-radius:8px;">
                    <h3 style="margin-top:0;">Email Types</h3>
                    <ul style="line-height:2;">
                        <li><strong>Camp Registration</strong> - Black gradient card</li>
                        <li><strong>Clinic Registration</strong> - Blue gradient card</li>
                        <li><strong>Training Session</strong> - Standard PTP card</li>
                    </ul>
                    
                    <h3>Dynamic Data Pulled:</h3>
                    <ul style="font-size:13px;line-height:1.8;">
                        <li>✓ Product: name, dates, times, location</li>
                        <li>✓ Product: what's included, what to bring</li>
                        <li>✓ Order: player name, age from line item meta</li>
                        <li>✓ Order: total, order number</li>
                        <li>✓ Customer: name, email</li>
                    </ul>
                    
                    <h3>Required Product Meta:</h3>
                    <code style="font-size:11px;display:block;background:#fff;padding:10px;border-radius:4px;">
                        _ptp_is_camp = yes<br>
                        _ptp_camp_type = camp|clinic<br>
                        _ptp_start_date<br>
                        _ptp_end_date<br>
                        _ptp_daily_start<br>
                        _ptp_daily_end<br>
                        _ptp_location_name<br>
                        _ptp_address<br>
                        _ptp_city, _ptp_state<br>
                        _ptp_included<br>
                        _ptp_what_to_bring
                    </code>
                    
                    <h3 style="margin-top:20px;">Troubleshooting</h3>
                    <ul style="font-size:13px;line-height:1.8;">
                        <li>⚠️ Not sent? Click "Send" to trigger manually</li>
                        <li>Check WP Mail logs for delivery issues</li>
                        <li>Verify product has _ptp_is_camp = yes</li>
                        <li>Enable WP_DEBUG to see email logs</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX preview email
     */
    public function ajax_preview_email() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        
        $order_id = intval($_GET['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        
        if (!$order) wp_die('Order not found');
        
        header('Content-Type: text/html; charset=utf-8');
        echo $this->render_order_email($order);
        exit;
    }
    
    /**
     * Send PTP confirmation email
     * 
     * This is called from multiple hooks to ensure the email is sent:
     * - woocommerce_order_status_processing
     * - woocommerce_order_status_completed
     * - woocommerce_payment_complete
     * - woocommerce_checkout_order_processed
     * - woocommerce_thankyou
     */
    public function send_ptp_confirmation($order_id) {
        // Early exit if disabled
        if (get_option('ptp_email_enabled', 'yes') !== 'yes') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Disabled in settings, skipping order #$order_id");
            }
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Invalid order #$order_id");
            }
            return;
        }
        
        // Check if we already sent (prevents duplicate emails from multiple hooks)
        // v115: Check both old and new meta flags
        if ($order->get_meta('_ptp_email_sent') || $order->get_meta('_ptp_confirmation_email_sent')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Already sent for order #$order_id, skipping");
            }
            return;
        }
        
        // Check if order has camp/clinic items OR any PTP-related items
        $has_ptp_item = false;
        $is_camp_order = false;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            
            // Check for camp/clinic
            if (get_post_meta($product_id, '_ptp_is_camp', true) === 'yes') {
                $has_ptp_item = true;
                $is_camp_order = true;
                break;
            }
            
            // Check for training sessions, all-access pass, etc.
            $ptp_type = get_post_meta($product_id, '_ptp_product_type', true);
            if ($ptp_type) {
                $has_ptp_item = true;
                break;
            }
            
            // Check product categories for camps
            if (has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $product_id)) {
                $has_ptp_item = true;
                $is_camp_order = true;
                break;
            }
        }
        
        // If not a PTP order, let WooCommerce handle the email
        if (!$has_ptp_item) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Order #$order_id has no PTP items, letting WooCommerce handle");
            }
            return;
        }
        
        // Ensure order is paid before sending confirmation
        $order_status = $order->get_status();
        $paid_statuses = array('processing', 'completed', 'on-hold');
        if (!in_array($order_status, $paid_statuses) && !$order->is_paid()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Order #$order_id not yet paid (status: $order_status), will retry later");
            }
            return;
        }
        
        $to = $order->get_billing_email();
        if (!is_email($to)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Invalid email for order #$order_id: $to");
            }
            return;
        }
        
        $subject = 'Registration Confirmed - Order #' . $order->get_order_number();
        $body = $this->render_order_email($order);
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: PTP <luke@ptpsummercamps.com>'
        );
        
        // Log the attempt
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("PTP Email: Sending confirmation to $to for order #$order_id");
        }
        
        $sent = wp_mail($to, $subject, $body, $headers);
        
        if ($sent) {
            // v115: Set both flags for compatibility
            $order->update_meta_data('_ptp_email_sent', current_time('mysql'));
            $order->update_meta_data('_ptp_confirmation_email_sent', current_time('mysql'));
            $order->add_order_note('PTP confirmation email sent to ' . $to);
            $order->save();
            
            // v115: Fire action for tracking
            do_action('ptp_order_confirmation_email_sent', $order_id, $to);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: Successfully sent to $to for order #$order_id");
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("PTP Email: FAILED to send to $to for order #$order_id");
            }
            
            // Add order note about failure
            $order->add_order_note('PTP confirmation email FAILED to send to ' . $to);
            $order->save();
        }
    }
    
    /**
     * Render complete order email
     * v115.5.2: Bulletproof professional email with complete registration details
     * v116: Added unified email support for orders with training sessions
     */
    public function render_order_email($order) {
        // v116: Check if order has training items - use unified email
        if (class_exists('PTP_Training_Woo_Integration') && 
            PTP_Training_Woo_Integration::order_has_training($order)) {
            // Use unified email that handles both camps and training
            if (class_exists('PTP_Unified_Order_Email')) {
                return PTP_Unified_Order_Email::render($order);
            }
        }
        
        // v115.5.2: Use site logo or fallback to text
        $logo = get_option('ptp_email_logo_url', '');
        if (empty($logo)) {
            // Try WooCommerce email logo
            $logo = get_option('woocommerce_email_header_image', '');
        }
        if (empty($logo)) {
            // Try site custom logo
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo = wp_get_attachment_image_url($custom_logo_id, 'medium');
            }
        }
        // Final fallback - use site URL for a hosted logo
        if (empty($logo)) {
            $logo = home_url('/wp-content/uploads/ptp-logo.png');
        }
        
        $support_phone = '(484) 572-4770';
        $support_email = 'luke@ptpsummercamps.com';
        
        $customer_name = $order->get_billing_first_name();
        $customer_full = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        $order_number = $order->get_order_number();
        $order_total = $order->get_total();
        $order_date = $order->get_date_created()->format('F j, Y \a\t g:i A');
        
        // Get all order meta for camper info
        $player_name = $order->get_meta('_player_name');
        $camper_first = $order->get_meta('_camper_first_name');
        $camper_last = $order->get_meta('_camper_last_name');
        $campers_data = $order->get_meta('_ptp_campers_data');
        $emergency_name = $order->get_meta('_ptp_emergency_name') ?: $order->get_meta('_emergency_contact');
        $emergency_phone = $order->get_meta('_ptp_emergency_phone') ?: $order->get_meta('_emergency_phone');
        $medical_info = $order->get_meta('_medical_info') ?: $order->get_meta('_ptp_medical_info');
        $photo_consent = $order->get_meta('_photo_consent');
        $waiver_accepted = $order->get_meta('_waiver_accepted') ?: 'yes';
        $before_after_care = $order->get_meta('_ptp_care_bundle') ? 'yes' : ($order->get_meta('_before_after_care') ?: '');
        $jersey_added = $order->get_meta('_ptp_jersey') ? 'yes' : '';
        
        // Build camper name from various sources
        if (empty($player_name)) {
            if ($camper_first) {
                $player_name = trim($camper_first . ' ' . $camper_last);
            } elseif (is_array($campers_data) && !empty($campers_data)) {
                $first = reset($campers_data);
                $player_name = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));
            }
        }
        
        // Get shirt size from various sources
        $shirt_size = '';
        foreach ($order->get_items() as $item) {
            $shirt = $item->get_meta('T-Shirt Size') ?: $item->get_meta('_shirt_size') ?: $item->get_meta('shirt_size');
            if ($shirt) { $shirt_size = $shirt; break; }
        }
        if (empty($shirt_size) && is_array($campers_data) && !empty($campers_data)) {
            $first = reset($campers_data);
            $shirt_size = $first['shirt_size'] ?? '';
        }
        
        // Get camp/clinic items with FULL product data
        $event_items = array();
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            // Check if it's a camp item (either by meta or category)
            $is_camp_product = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                              has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $product_id);
            
            if (!$is_camp_product && $product) {
                // Also check product name
                $name_lower = strtolower($product->get_name());
                $is_camp_product = (strpos($name_lower, 'camp') !== false || strpos($name_lower, 'clinic') !== false);
            }
            
            if (!$is_camp_product) continue;
            
            // Get product name - this is the ACTUAL camp name
            $product_name = $item->get_name();
            $product_description = $product ? $product->get_short_description() : '';
            
            // Determine camp type from the product name itself
            $type = 'camp';
            $type_label = 'Summer Camp';
            $name_lower = strtolower($product_name);
            if (strpos($name_lower, 'clinic') !== false) {
                $type = 'clinic';
                $type_label = 'Skills Clinic';
            } elseif (strpos($name_lower, 'winter') !== false) {
                $type_label = 'Winter Camp';
            } elseif (strpos($name_lower, 'spring') !== false) {
                $type_label = 'Spring Camp';
            } elseif (strpos($name_lower, 'fall') !== false) {
                $type_label = 'Fall Camp';
            }
            
            // Override with product meta if set
            $meta_type = get_post_meta($product_id, '_ptp_camp_type', true);
            if ($meta_type === 'clinic') {
                $type = 'clinic';
                $type_label = 'Skills Clinic';
            }
            
            // Get event venue from WooCommerce Events or custom meta
            $venue_name = get_post_meta($product_id, '_ptp_location_name', true) 
                       ?: get_post_meta($product_id, '_EventVenueName', true)
                       ?: get_post_meta($product_id, '_event_location', true)
                       ?: get_post_meta($product_id, '_camp_location', true);
            
            $venue_address = get_post_meta($product_id, '_ptp_address', true)
                          ?: get_post_meta($product_id, '_EventAddress', true)
                          ?: get_post_meta($product_id, '_event_address', true);
            
            // Get dates from meta
            $start_date = get_post_meta($product_id, '_ptp_start_date', true) ?: get_post_meta($product_id, '_EventStartDate', true);
            $end_date = get_post_meta($product_id, '_ptp_end_date', true) ?: get_post_meta($product_id, '_EventEndDate', true);
            
            // v115.5.2: Parse dates from product name as fallback
            if (empty($start_date) && $product_name) {
                // Pattern: Month Day-Day, Year (e.g., "July 20-24, 2026")
                if (preg_match('/([A-Za-z]+)\s+(\d{1,2})\s*[-–]\s*(\d{1,2}),?\s*(\d{4})/i', $product_name, $matches)) {
                    $month = $matches[1];
                    $start_day = $matches[2];
                    $end_day = $matches[3];
                    $year = $matches[4];
                    $start_date = "$month $start_day, $year";
                    $end_date = "$month $end_day, $year";
                }
                // Pattern: Month Day, Year (single day)
                elseif (preg_match('/([A-Za-z]+)\s+(\d{1,2}),?\s*(\d{4})/i', $product_name, $matches)) {
                    $start_date = $matches[1] . ' ' . $matches[2] . ', ' . $matches[3];
                }
            }
            
            // v115.5.2: Parse location from product name as fallback
            if (empty($venue_name) && $product_name) {
                // Try to extract location between dashes
                if (preg_match('/[–-]\s*([^–-]+(?:Complex|Field|Park|Center|Facility|Stadium|Turf|Soccer)[^–-]*)\s*[–-]/i', $product_name, $matches)) {
                    $venue_name = trim($matches[1]);
                }
            }
            
            // v115.5.3: Check for multiple campers on this item
            $item_campers = $item->get_meta('_ptp_item_campers');
            $qty = $item->get_quantity();
            
            // Base event data
            $base_event = array(
                'name' => $product_name,
                'description' => $product_description,
                'type' => $type,
                'type_label' => $type_label,
                'price' => $item->get_total() / max(1, $qty), // Per-camper price
                'qty' => 1,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'daily_start' => get_post_meta($product_id, '_ptp_daily_start', true) ?: '9:00 AM',
                'daily_end' => get_post_meta($product_id, '_ptp_daily_end', true) ?: '12:00 PM',
                'location' => $venue_name,
                'address' => $venue_address,
                'city' => get_post_meta($product_id, '_ptp_city', true) ?: get_post_meta($product_id, '_EventCity', true),
                'state' => get_post_meta($product_id, '_ptp_state', true) ?: get_post_meta($product_id, '_EventState', true),
                'included' => get_post_meta($product_id, '_ptp_included', true),
                'what_to_bring' => get_post_meta($product_id, '_ptp_what_to_bring', true),
                'player_name' => '',
                'player_age' => '',
                'shirt_size' => '',
            );
            
            // If we have per-item camper data, create an entry for each camper
            if (is_array($item_campers) && !empty($item_campers)) {
                foreach ($item_campers as $camper) {
                    $event = $base_event;
                    $event['player_name'] = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
                    $event['shirt_size'] = $camper['shirt_size'] ?? '';
                    $event['player_dob'] = $camper['dob'] ?? '';
                    $event_items[] = $event;
                }
            }
            // If qty > 1 but no camper data, check order-level campers_data
            elseif ($qty > 1 && is_array($campers_data) && !empty($campers_data)) {
                // Use order-level campers data
                for ($q = 0; $q < $qty; $q++) {
                    $event = $base_event;
                    if (isset($campers_data[$q])) {
                        $camper = $campers_data[$q];
                        $event['player_name'] = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
                        $event['shirt_size'] = $camper['shirt_size'] ?? '';
                    } else {
                        $event['player_name'] = $player_name;
                        $event['shirt_size'] = $shirt_size;
                    }
                    $event_items[] = $event;
                }
            }
            // Single camper or no data
            else {
                $base_event['player_name'] = $item->get_meta('Player Name') ?: $item->get_meta('_player_name') ?: $player_name;
                $base_event['player_age'] = $item->get_meta('Player Age') ?: $item->get_meta('_player_age') ?: '';
                $base_event['shirt_size'] = $item->get_meta('T-Shirt Size') ?: $shirt_size;
                $base_event['price'] = $item->get_total();
                $base_event['qty'] = $qty;
                $event_items[] = $base_event;
            }
        }
        
        // If no events found but order has items, create generic entry
        if (empty($event_items)) {
            foreach ($order->get_items() as $item) {
                $event_items[] = array(
                    'name' => $item->get_name(),
                    'description' => '',
                    'type' => 'registration',
                    'type_label' => 'Registration',
                    'price' => $item->get_total(),
                    'qty' => $item->get_quantity(),
                    'start_date' => '',
                    'end_date' => '',
                    'daily_start' => '',
                    'daily_end' => '',
                    'location' => '',
                    'address' => '',
                    'city' => '',
                    'state' => '',
                    'included' => '',
                    'what_to_bring' => '',
                    'player_name' => $player_name,
                    'player_age' => '',
                    'shirt_size' => $shirt_size,
                );
            }
        }
        
        // Get order fees (processing, discounts, etc.)
        $fees = array();
        foreach ($order->get_fees() as $fee) {
            $fees[] = array(
                'name' => $fee->get_name(),
                'total' => $fee->get_total(),
            );
        }
        
        // v115.5.3: Build list of all camper names for multi-camper display
        $all_camper_names = [];
        if (is_array($campers_data) && !empty($campers_data)) {
            foreach ($campers_data as $camper) {
                $name = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
                if ($name) {
                    $all_camper_names[] = $name;
                }
            }
        }
        if (empty($all_camper_names) && $player_name) {
            $all_camper_names[] = $player_name;
        }
        
        // Format camper names for display
        $camper_count = count($all_camper_names);
        if ($camper_count > 1) {
            // Multiple campers: "Luke & Emma" or "Luke, Emma & Sam"
            if ($camper_count == 2) {
                $camper_display = $all_camper_names[0] . ' & ' . $all_camper_names[1];
            } else {
                $last = array_pop($all_camper_names);
                $camper_display = implode(', ', $all_camper_names) . ' & ' . $last;
                $all_camper_names[] = $last; // Put it back
            }
            $camper_verb = 'are';
        } else {
            $camper_display = $player_name ?: 'Your camper';
            $camper_verb = 'is';
        }
        
        ob_start();
        ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Registration Confirmed - PTP</title>
    <style type="text/css">
        body {margin: 0; padding: 0; -webkit-text-size-adjust: 100%; background-color: #0E0F11;}
        @media screen and (max-width: 600px) {
            .mobile-padding {padding-left: 16px !important; padding-right: 16px !important;}
            .mobile-full {width: 100% !important;}
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #0E0F11; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

    <!-- Preheader -->
    <div style="display: none; max-height: 0; overflow: hidden;">
        ✅ <?php echo esc_html($camper_display); ?>'s registration is confirmed for <?php echo esc_html($event_items[0]['name'] ?? 'PTP Camp'); ?>! Order #<?php echo esc_html($order_number); ?>
    </div>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #0E0F11;">
        <tr>
            <td align="center" style="padding: 40px 20px;" class="mobile-padding">
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 580px;">
                    
                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding-bottom: 32px;">
                            <a href="<?php echo esc_url(home_url()); ?>" target="_blank" style="text-decoration: none;">
                                <?php if ($logo): ?>
                                <img src="<?php echo esc_url($logo); ?>" alt="PTP" width="120" style="display: block; max-width: 120px; height: auto;" onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
                                <div style="display: none; font-size: 28px; font-weight: 800; color: #FCB900; font-family: 'Arial Black', sans-serif; letter-spacing: 2px;">PTP</div>
                                <?php else: ?>
                                <div style="font-size: 28px; font-weight: 800; color: #FCB900; font-family: 'Arial Black', sans-serif; letter-spacing: 2px;">PTP</div>
                                <?php endif; ?>
                            </a>
                        </td>
                    </tr>
                    
                    <!-- Main Card -->
                    <tr>
                        <td>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ffffff; border-radius: 16px; overflow: hidden;">
                                
                                <!-- Gold Top Border -->
                                <tr>
                                    <td style="background-color: #FCB900; height: 6px;">&nbsp;</td>
                                </tr>
                                
                                <!-- Hero Section -->
                                <tr>
                                    <td style="padding: 40px 36px 24px; text-align: center; background-color: #FCB900;" class="mobile-padding">
                                        <h1 style="margin: 0 0 12px; font-size: 32px; font-weight: 900; color: #0A0A0A; font-family: 'Arial Black', Impact, sans-serif; text-transform: uppercase; letter-spacing: 1px;">YOU'RE IN! ⚽</h1>
                                    </td>
                                </tr>
                                
                                <!-- Intro Text -->
                                <tr>
                                    <td style="padding: 28px 36px; text-align: left; background-color: #ffffff;" class="mobile-padding">
                                        <p style="margin: 0 0 16px; font-size: 16px; color: #000000; line-height: 1.6;">
                                            Hi <strong style="color: #000000;"><?php echo esc_html($customer_name); ?></strong>,
                                        </p>
                                        <p style="margin: 0; font-size: 16px; color: #333333; line-height: 1.6;">
                                            Thanks for signing up! <strong style="color: #000000;"><?php echo esc_html($camper_display); ?></strong> <?php echo $camper_verb; ?> registered and ready to train with the pros.
                                        </p>
                                    </td>
                                </tr>
                                
                                <?php foreach ($event_items as $idx => $event): 
                                    $is_camp = $event['type'] !== 'clinic';
                                    $gradient = $is_camp ? 'linear-gradient(135deg, #0A0A0A 0%, #1F1F1F 100%)' : 'linear-gradient(135deg, #1E3A8A 0%, #1E40AF 100%)';
                                    $accent = $is_camp ? '#FCB900' : '#93C5FD';
                                    
                                    // Format dates
                                    $start = $event['start_date'] ? strtotime($event['start_date']) : false;
                                    $end = $event['end_date'] ? strtotime($event['end_date']) : false;
                                    $dates = '';
                                    if ($start && $end && $start !== $end) {
                                        $dates = date('l, M j', $start) . ' – ' . date('l, M j, Y', $end);
                                    } elseif ($start) {
                                        $dates = date('l, F j, Y', $start);
                                    }
                                    
                                    // Format times
                                    $time_start = $event['daily_start'] ? date('g:i A', strtotime($event['daily_start'])) : '';
                                    $time_end = $event['daily_end'] ? date('g:i A', strtotime($event['daily_end'])) : '';
                                    $times = $time_start && $time_end ? "$time_start – $time_end" : '';
                                    
                                    // Full location
                                    $location = $event['location'];
                                    $full_address = implode(', ', array_filter(array($event['address'], $event['city'], $event['state'])));
                                ?>
                                
                                <!-- Event Card -->
                                <tr>
                                    <td style="padding: 0 24px 24px;" class="mobile-padding">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background: <?php echo $gradient; ?>; border-radius: 16px; overflow: hidden;">
                                            <tr>
                                                <td style="padding: 28px;">
                                                    <!-- Event Type Badge -->
                                                    <p style="margin: 0 0 8px; font-size: 11px; font-weight: 700; color: <?php echo $accent; ?>; text-transform: uppercase; letter-spacing: 1.5px;">
                                                        <?php echo esc_html($event['type_label']); ?>
                                                    </p>
                                                    
                                                    <!-- Event Name - THE FULL PRODUCT NAME -->
                                                    <h2 style="margin: 0 0 20px; font-size: 22px; font-weight: 800; color: #ffffff; line-height: 1.3;">
                                                        <?php echo esc_html($event['name']); ?>
                                                    </h2>
                                                    
                                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                        
                                                        <!-- Camper Info -->
                                                        <?php if ($event['player_name'] || $player_name): ?>
                                                        <tr>
                                                            <td style="padding: 14px 0; border-top: 1px solid rgba(255,255,255,0.15);">
                                                                <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td width="24" style="vertical-align: top; padding-top: 2px;">
                                                                            <span style="font-size: 16px;">⚽</span>
                                                                        </td>
                                                                        <td style="padding-left: 12px;">
                                                                            <span style="font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;">CAMPER</span><br>
                                                                            <span style="font-size: 16px; color: #ffffff; font-weight: 700;"><?php echo esc_html($event['player_name'] ?: $player_name); ?></span>
                                                                            <?php if ($event['player_age']): ?><span style="font-size: 14px; color: rgba(255,255,255,0.7);"> (Age <?php echo esc_html($event['player_age']); ?>)</span><?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Dates -->
                                                        <?php if ($dates): ?>
                                                        <tr>
                                                            <td style="padding: 14px 0; border-top: 1px solid rgba(255,255,255,0.15);">
                                                                <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td width="24" style="vertical-align: top; padding-top: 2px;">
                                                                            <span style="font-size: 16px;">📅</span>
                                                                        </td>
                                                                        <td style="padding-left: 12px;">
                                                                            <span style="font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;">DATES</span><br>
                                                                            <span style="font-size: 16px; color: #ffffff; font-weight: 600;"><?php echo esc_html($dates); ?></span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Times -->
                                                        <?php if ($times): ?>
                                                        <tr>
                                                            <td style="padding: 14px 0; border-top: 1px solid rgba(255,255,255,0.15);">
                                                                <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td width="24" style="vertical-align: top; padding-top: 2px;">
                                                                            <span style="font-size: 16px;">🕐</span>
                                                                        </td>
                                                                        <td style="padding-left: 12px;">
                                                                            <span style="font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;">DAILY CHECK-IN</span><br>
                                                                            <span style="font-size: 16px; color: #ffffff; font-weight: 600;"><?php echo esc_html($times); ?></span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Location -->
                                                        <?php if ($location): ?>
                                                        <tr>
                                                            <td style="padding: 14px 0; border-top: 1px solid rgba(255,255,255,0.15);">
                                                                <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td width="24" style="vertical-align: top; padding-top: 2px;">
                                                                            <span style="font-size: 16px;">📍</span>
                                                                        </td>
                                                                        <td style="padding-left: 12px;">
                                                                            <span style="font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;">LOCATION</span><br>
                                                                            <span style="font-size: 16px; color: #ffffff; font-weight: 600;"><?php echo esc_html($location); ?></span>
                                                                            <?php if ($full_address): ?><br><span style="font-size: 13px; color: rgba(255,255,255,0.7);"><?php echo esc_html($full_address); ?></span><?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <!-- T-Shirt Size -->
                                                        <?php if ($event['shirt_size'] || $shirt_size): ?>
                                                        <tr>
                                                            <td style="padding: 14px 0; border-top: 1px solid rgba(255,255,255,0.15);">
                                                                <table cellspacing="0" cellpadding="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td width="24" style="vertical-align: top; padding-top: 2px;">
                                                                            <span style="font-size: 16px;">👕</span>
                                                                        </td>
                                                                        <td style="padding-left: 12px;">
                                                                            <span style="font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px;">SHIRT SIZE</span><br>
                                                                            <span style="font-size: 16px; color: #ffffff; font-weight: 600;"><?php echo esc_html(strtoupper($event['shirt_size'] ?: $shirt_size)); ?></span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                
                                <?php endforeach; ?>
                                
                                <!-- What's Included (if available) -->
                                <?php 
                                $included = $event_items[0]['included'] ?? '';
                                if ($included): 
                                    $included_items = array_filter(array_map('trim', preg_split('/[\n,]+/', $included)));
                                ?>
                                <tr>
                                    <td style="padding: 0 24px 24px;" class="mobile-padding">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FFFBEB; border-radius: 12px; border: 1px solid #FDE68A;">
                                            <tr>
                                                <td style="padding: 20px;">
                                                    <p style="margin: 0 0 12px; font-size: 13px; font-weight: 700; color: #713F12; text-transform: uppercase; letter-spacing: 0.5px;">📦 What's Included</p>
                                                    <?php foreach ($included_items as $inc): ?>
                                                    <p style="margin: 0 0 6px; font-size: 14px; color: #451A03;">✓ <?php echo esc_html($inc); ?></p>
                                                    <?php endforeach; ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <!-- What to Bring (if available) -->
                                <?php 
                                $bring = $event_items[0]['what_to_bring'] ?? '';
                                if ($bring): 
                                    $bring_items = array_filter(array_map('trim', preg_split('/[\n,]+/', $bring)));
                                ?>
                                <tr>
                                    <td style="padding: 0 24px 24px;" class="mobile-padding">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #EFF6FF; border-radius: 12px; border: 1px solid #BFDBFE;">
                                            <tr>
                                                <td style="padding: 20px;">
                                                    <p style="margin: 0 0 12px; font-size: 13px; font-weight: 700; color: #1E3A8A; text-transform: uppercase; letter-spacing: 0.5px;">🎒 What to Bring</p>
                                                    <?php foreach ($bring_items as $b): ?>
                                                    <p style="margin: 0 0 6px; font-size: 14px; color: #172554;">• <?php echo esc_html($b); ?></p>
                                                    <?php endforeach; ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                
                                <!-- Registration Details -->
                                <tr>
                                    <td style="padding: 0 24px 24px;" class="mobile-padding">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0FDF4; border-radius: 12px; border: 1px solid #BBF7D0;">
                                            <tr>
                                                <td style="padding: 20px;">
                                                    <p style="margin: 0 0 16px; font-size: 13px; font-weight: 700; color: #14532D; text-transform: uppercase; letter-spacing: 0.5px;">✅ Registration Details</p>
                                                    
                                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                        <?php if ($player_name): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Camper:</strong> <?php echo esc_html($player_name); ?>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Parent/Guardian:</strong> <?php echo esc_html($customer_full); ?>
                                                            </td>
                                                        </tr>
                                                        
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Email:</strong> <?php echo esc_html($customer_email); ?>
                                                            </td>
                                                        </tr>
                                                        
                                                        <?php if ($customer_phone): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Phone:</strong> <?php echo esc_html($customer_phone); ?>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($emergency_name): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Emergency Contact:</strong> <?php echo esc_html($emergency_name); ?><?php if ($emergency_phone): ?> (<?php echo esc_html($emergency_phone); ?>)<?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($shirt_size): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>T-Shirt Size:</strong> <?php echo esc_html(strtoupper($shirt_size)); ?>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($medical_info): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Medical/Allergies:</strong> <?php echo esc_html($medical_info); ?>
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($before_after_care === 'yes'): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Extended Care:</strong> ✅ Before & After Care Enrolled (8am-4:30pm)
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($jersey_added === 'yes'): ?>
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>WC 2026 Jersey:</strong> ✅ Added
                                                            </td>
                                                        </tr>
                                                        <?php endif; ?>
                                                        
                                                        <tr>
                                                            <td style="padding: 6px 0; font-size: 14px; color: #14532D;">
                                                                <strong>Waiver:</strong> ✅ Signed & Accepted
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                
                                <!-- Order Summary -->
                                <tr>
                                    <td style="padding: 0 24px 24px;" class="mobile-padding">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px;">
                                            <tr>
                                                <td style="padding: 20px;">
                                                    <p style="margin: 0 0 16px; font-size: 13px; font-weight: 700; color: #444444; text-transform: uppercase; letter-spacing: 0.5px;">💳 Order Summary</p>
                                                    
                                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                                        <tr>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 13px; color: #444444;">Order Number</td>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 14px; font-weight: 600; color: #000000; text-align: right;">#<?php echo esc_html($order_number); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 13px; color: #444444;">Date</td>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 14px; color: #000000; text-align: right;"><?php echo esc_html($order_date); ?></td>
                                                        </tr>
                                                        
                                                        <?php foreach ($event_items as $event): ?>
                                                        <tr>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 13px; color: #222222;"><?php echo esc_html($event['name']); ?></td>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 14px; color: #000000; text-align: right;">$<?php echo number_format($event['price'], 2); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        
                                                        <?php foreach ($fees as $fee): ?>
                                                        <tr>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 13px; color: <?php echo $fee['total'] < 0 ? '#059669' : '#222222'; ?>;"><?php echo esc_html($fee['name']); ?></td>
                                                            <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 14px; color: <?php echo $fee['total'] < 0 ? '#059669' : '#000000'; ?>; text-align: right;"><?php echo $fee['total'] < 0 ? '-' : ''; ?>$<?php echo number_format(abs($fee['total']), 2); ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        
                                                        <tr>
                                                            <td style="padding: 16px 0 8px; font-size: 16px; font-weight: 700; color: #000000;">Total Paid</td>
                                                            <td style="padding: 16px 0 8px; font-size: 22px; font-weight: 800; color: #000000; text-align: right;">$<?php echo number_format($order_total, 2); ?></td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                
                                <!-- CTA Button -->
                                <tr>
                                    <td style="padding: 0 24px 32px; text-align: center; background-color: #ffffff;" class="mobile-padding">
                                        <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" style="display: inline-block; background-color: #FCB900; color: #000000; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 15px; text-transform: uppercase;" target="_blank">
                                            View Order Details
                                        </a>
                                    </td>
                                </tr>
                                
                                <!-- Help Section -->
                                <tr>
                                    <td style="padding: 24px 24px 32px; border-top: 1px solid #E5E7EB; text-align: center; background-color: #ffffff;" class="mobile-padding">
                                        <p style="margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #000000;">Questions? We're here to help!</p>
                                        <p style="margin: 0; font-size: 14px; color: #333333;">
                                            Call or text <a href="tel:+14845724770" style="color: #996600; text-decoration: underline; font-weight: 600;"><?php echo esc_html($support_phone); ?></a><br>
                                            Email <a href="mailto:<?php echo esc_attr($support_email); ?>" style="color: #996600; text-decoration: underline;"><?php echo esc_html($support_email); ?></a>
                                        </p>
                                    </td>
                                </tr>
                                
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 32px 20px; text-align: center;">
                            <p style="margin: 0 0 8px; font-size: 11px; color: #6B7280; text-transform: uppercase; letter-spacing: 1px;">
                                PA · NJ · DE · MD · NY
                            </p>
                            <p style="margin: 0 0 16px; font-size: 11px; color: #4B5563;">
                                <a href="<?php echo esc_url(home_url()); ?>" style="color: #6B7280; text-decoration: none;">ptpsummercamps.com</a>
                            </p>
                            <p style="margin: 0; font-size: 10px; color: #374151;">
                                © <?php echo date('Y'); ?> PTP. All rights reserved.
                            </p>
                        </td>
                    </tr>
                    
                </table>
                
            </td>
        </tr>
    </table>

</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Email CSS styles (fallback for WC emails)
     */
    public function email_styles($css) {
        // Only add if not using our custom email
        return $css;
    }
    
    /**
     * Email headers
     */
    public function email_headers($headers, $email_id, $order) {
        return $headers;
    }
    
    /**
     * Before order table hook
     */
    public function before_order_table($order, $sent_to_admin, $plain_text, $email) {
        // Not used when sending custom email
    }
    
    /**
     * After order table hook  
     */
    public function after_order_table($order, $sent_to_admin, $plain_text, $email) {
        // Not used when sending custom email
    }
    
    /**
     * Maybe replace email content
     */
    public function maybe_replace_email_content($content) {
        // Not used - we send our own email
        return $content;
    }
}

// Initialize
PTP_WooCommerce_Emails::instance();
