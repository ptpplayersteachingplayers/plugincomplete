<?php
/**
 * PTP Order Email Wiring v115
 * 
 * Comprehensive wiring for:
 * - Order confirmation emails (branded PTP)
 * - Thank you page redirect and integration
 * - WooCommerce email hooks coordination
 * - Email sending verification and logging
 * 
 * @since 115.0.0
 */

defined('ABSPATH') || exit;

class PTP_Order_Email_Wiring {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // High priority to run early
        add_action('init', array($this, 'init'), 5);
        
        // =====================================================
        // THANK YOU PAGE REDIRECT & INTEGRATION
        // =====================================================
        
        // Redirect WooCommerce thank you to PTP custom thank you page
        add_action('template_redirect', array($this, 'maybe_redirect_to_ptp_thankyou'), 1);
        
        // Override WooCommerce thank you page URL
        add_filter('woocommerce_get_checkout_order_received_url', array($this, 'custom_thankyou_url'), 99, 2);
        
        // =====================================================
        // EMAIL SENDING - COMPREHENSIVE HOOKS
        // =====================================================
        
        // Primary: After payment is complete (most reliable)
        add_action('woocommerce_payment_complete', array($this, 'trigger_ptp_confirmation_email'), 5);
        
        // Secondary: Status transitions
        add_action('woocommerce_order_status_pending_to_processing', array($this, 'trigger_ptp_confirmation_email'), 5);
        add_action('woocommerce_order_status_pending_to_completed', array($this, 'trigger_ptp_confirmation_email'), 5);
        add_action('woocommerce_order_status_failed_to_processing', array($this, 'trigger_ptp_confirmation_email'), 5);
        add_action('woocommerce_order_status_on-hold_to_processing', array($this, 'trigger_ptp_confirmation_email'), 5);
        
        // Tertiary: Checkout processed (backup)
        add_action('woocommerce_checkout_order_processed', array($this, 'trigger_ptp_confirmation_email'), 10);
        
        // Final fallback: Thank you page load
        add_action('woocommerce_thankyou', array($this, 'ensure_email_sent_on_thankyou'), 1);
        
        // =====================================================
        // EMAIL DEBUGGING & VERIFICATION
        // =====================================================
        
        // Log email attempts
        add_action('wp_mail_failed', array($this, 'log_mail_failure'));
        
        // Admin AJAX for manual email send
        add_action('wp_ajax_ptp_send_order_email', array($this, 'ajax_send_order_email'));
        
        // =====================================================
        // DISABLE COMPETING WOO EMAIL FOR PTP ORDERS
        // =====================================================
        
        // Disable default WooCommerce order emails for PTP orders (we send our own)
        add_filter('woocommerce_email_enabled_customer_processing_order', array($this, 'disable_woo_email_for_ptp'), 10, 2);
        add_filter('woocommerce_email_enabled_customer_completed_order', array($this, 'disable_woo_email_for_ptp'), 10, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', array($this, 'disable_woo_email_for_ptp'), 10, 2);
    }
    
    /**
     * Initialize
     */
    public function init() {
        // Ensure email templates class is loaded
        if (!class_exists('PTP_Email_Templates')) {
            $path = PTP_PLUGIN_DIR . 'includes/class-ptp-email-templates.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        
        // Ensure WooCommerce emails class is loaded
        if (!class_exists('PTP_WooCommerce_Emails')) {
            $path = PTP_PLUGIN_DIR . 'includes/class-ptp-woocommerce-emails.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        
        // Set default options if not set
        if (false === get_option('ptp_email_enabled')) {
            update_option('ptp_email_enabled', 'yes');
        }
        
        if (false === get_option('ptp_email_logo_url')) {
            update_option('ptp_email_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png');
        }
        
        if (false === get_option('ptp_email_support_phone')) {
            update_option('ptp_email_support_phone', '(610) 761-5230');
        }
    }
    
    /**
     * Maybe redirect to PTP custom thank you page
     */
    public function maybe_redirect_to_ptp_thankyou() {
        // Check if we're on WooCommerce order-received endpoint
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-received')) {
            return;
        }
        
        // Get order ID from URL
        global $wp;
        $order_id = absint($wp->query_vars['order-received'] ?? 0);
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // v115.3: Find thank you page using multiple methods
        $thank_you_page = null;
        
        $paths_to_try = array('thank-you', 'ptp-thank-you', 'booking-confirmation', 'thankyou');
        foreach ($paths_to_try as $path) {
            $thank_you_page = get_page_by_path($path);
            if ($thank_you_page && $thank_you_page->post_status === 'publish') break;
            $thank_you_page = null;
        }
        
        if (!$thank_you_page) {
            $page_by_title = get_page_by_title('Thank You');
            if ($page_by_title && $page_by_title->post_status === 'publish') {
                $thank_you_page = $page_by_title;
            }
        }
        
        if (!$thank_you_page) {
            $saved_page_id = get_option('ptp_thankyou_page_id');
            if ($saved_page_id) {
                $page = get_post($saved_page_id);
                if ($page && $page->post_status === 'publish') {
                    $thank_you_page = $page;
                }
            }
        }
        
        if ($thank_you_page) {
            $redirect_url = add_query_arg(array(
                'order' => $order_id,
                'key' => $order->get_order_key(),
            ), get_permalink($thank_you_page));
            
            $this->log("Redirecting order #$order_id from order-received to: $redirect_url");
            
            wp_safe_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Custom thank you URL for WooCommerce
     */
    public function custom_thankyou_url($url, $order) {
        if (!$order) {
            return $url;
        }
        
        // v115.3: Always redirect PTP orders to custom thank you
        // Try multiple ways to find the thank you page
        $thank_you_page = null;
        
        // Method 1: By path
        $paths_to_try = array('thank-you', 'ptp-thank-you', 'booking-confirmation', 'thankyou');
        foreach ($paths_to_try as $path) {
            $thank_you_page = get_page_by_path($path);
            if ($thank_you_page) break;
        }
        
        // Method 2: By title
        if (!$thank_you_page) {
            $page_by_title = get_page_by_title('Thank You');
            if ($page_by_title) {
                $thank_you_page = $page_by_title;
            }
        }
        
        // Method 3: By saved option
        if (!$thank_you_page) {
            $saved_page_id = get_option('ptp_thankyou_page_id');
            if ($saved_page_id) {
                $thank_you_page = get_post($saved_page_id);
            }
        }
        
        if ($thank_you_page && $thank_you_page->post_status === 'publish') {
            $custom_url = add_query_arg(array(
                'order' => $order->get_id(),
                'key' => $order->get_order_key(),
            ), get_permalink($thank_you_page));
            
            $this->log("Redirecting order #{$order->get_id()} to custom thank you: $custom_url");
            return $custom_url;
        }
        
        // If no custom page, still add order param to default URL
        return add_query_arg('order', $order->get_id(), $url);
    }
    
    /**
     * Check if order contains PTP items
     * v115.3: Made more robust - if setting enabled, treat ALL orders as PTP orders
     */
    private function is_ptp_order($order) {
        // Option to treat all WooCommerce orders as PTP orders
        if (get_option('ptp_email_all_orders', 'yes') === 'yes') {
            return true;
        }
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            
            // Check for camp
            if (get_post_meta($product_id, '_ptp_is_camp', true) === 'yes') {
                return true;
            }
            
            // Check for training session
            if (get_post_meta($product_id, '_ptp_product_type', true)) {
                return true;
            }
            
            // Check categories
            if (has_term(['camps', 'clinics', 'camp', 'summer-camps', 'training', 'ptp-training'], 'product_cat', $product_id)) {
                return true;
            }
            
            // Check product name
            $product = $item->get_product();
            if ($product) {
                $name = strtolower($product->get_name());
                if (strpos($name, 'camp') !== false || strpos($name, 'clinic') !== false || strpos($name, 'training') !== false) {
                    return true;
                }
            }
        }
        
        // v115.3: Default to true for PTP sites - most orders are camp related
        return true;
    }
    
    /**
     * Trigger PTP confirmation email
     */
    public function trigger_ptp_confirmation_email($order_id) {
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("Email trigger failed: Invalid order ID $order_id");
            return;
        }
        
        // Check if already sent
        if ($order->get_meta('_ptp_confirmation_email_sent')) {
            $this->log("Email already sent for order #$order_id, skipping");
            return;
        }
        
        // Check if PTP order
        if (!$this->is_ptp_order($order)) {
            $this->log("Order #$order_id is not a PTP order, letting WooCommerce handle");
            return;
        }
        
        // Check if paid (don't send for pending orders)
        $status = $order->get_status();
        $valid_statuses = array('processing', 'completed', 'on-hold');
        
        if (!in_array($status, $valid_statuses) && !$order->is_paid()) {
            $this->log("Order #$order_id not yet paid (status: $status), will send later");
            return;
        }
        
        // Send the email
        $this->send_ptp_order_email($order);
    }
    
    /**
     * Ensure email is sent when thank you page loads (final fallback)
     */
    public function ensure_email_sent_on_thankyou($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Only for PTP orders
        if (!$this->is_ptp_order($order)) {
            return;
        }
        
        // Check if already sent
        if ($order->get_meta('_ptp_confirmation_email_sent')) {
            return;
        }
        
        // Give other hooks a chance first, then send
        $this->send_ptp_order_email($order);
    }
    
    /**
     * Send PTP branded order email
     */
    private function send_ptp_order_email($order) {
        // Check if emails are enabled
        if (get_option('ptp_email_enabled', 'yes') !== 'yes') {
            $this->log("PTP emails disabled, skipping order #{$order->get_id()}");
            return false;
        }
        
        $order_id = $order->get_id();
        $to = $order->get_billing_email();
        
        if (!is_email($to)) {
            $this->log("Invalid email for order #$order_id: $to");
            return false;
        }
        
        // Use WooCommerce Emails class if available
        if (class_exists('PTP_WooCommerce_Emails')) {
            $emails = PTP_WooCommerce_Emails::instance();
            if (method_exists($emails, 'render_order_email')) {
                $body = $emails->render_order_email($order);
            } else {
                $body = $this->render_fallback_email($order);
            }
        } else {
            $body = $this->render_fallback_email($order);
        }
        
        $subject = 'Registration Confirmed - Order #' . $order->get_order_number();
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: PTP <luke@ptpsummercamps.com>',
        );
        
        // Log attempt
        $this->log("Sending PTP confirmation email to $to for order #$order_id");
        
        $sent = wp_mail($to, $subject, $body, $headers);
        
        if ($sent) {
            // Mark as sent
            $order->update_meta_data('_ptp_confirmation_email_sent', current_time('mysql'));
            $order->add_order_note('PTP confirmation email sent to ' . $to);
            $order->save();
            
            $this->log("✓ Email sent successfully to $to for order #$order_id");
            
            // Fire action for other systems
            do_action('ptp_order_confirmation_email_sent', $order_id, $to);
            
            return true;
        } else {
            $this->log("✗ Email FAILED to send to $to for order #$order_id");
            $order->add_order_note('PTP confirmation email FAILED to send to ' . $to);
            $order->save();
            
            return false;
        }
    }
    
    /**
     * Render fallback email if main class not available
     */
    private function render_fallback_email($order) {
        $logo = get_option('ptp_email_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png');
        $customer_name = $order->get_billing_first_name();
        $order_number = $order->get_order_number();
        $order_total = $order->get_total();
        $order_date = $order->get_date_created()->format('F j, Y');
        
        // Get items
        $items_html = '';
        foreach ($order->get_items() as $item) {
            $items_html .= '<tr>';
            $items_html .= '<td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">' . esc_html($item->get_name()) . '</td>';
            $items_html .= '<td style="padding: 12px; border-bottom: 1px solid #E5E7EB; text-align: center;">' . $item->get_quantity() . '</td>';
            $items_html .= '<td style="padding: 12px; border-bottom: 1px solid #E5E7EB; text-align: right;">$' . number_format($item->get_total(), 2) . '</td>';
            $items_html .= '</tr>';
        }
        
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0E0F11; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #0E0F11;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table width="100%" style="max-width: 560px;" cellpadding="0" cellspacing="0">
                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding-bottom: 32px;">
                            <a href="<?php echo esc_url(home_url()); ?>">
                                <img src="<?php echo esc_url($logo); ?>" alt="PTP" width="100" style="max-width: 100px;">
                            </a>
                        </td>
                    </tr>
                    
                    <!-- Content Card -->
                    <tr>
                        <td>
                            <table width="100%" style="background-color: #ffffff; border-radius: 16px; overflow: hidden;" cellpadding="0" cellspacing="0">
                                <!-- Gold Border -->
                                <tr>
                                    <td style="background-color: #FCB900; height: 5px;">&nbsp;</td>
                                </tr>
                                
                                <!-- Main Content -->
                                <tr>
                                    <td style="padding: 40px;">
                                        <div style="text-align: center; margin-bottom: 24px;">
                                            <span style="display: inline-block; background-color: #ECFDF5; color: #059669; font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: 100px; text-transform: uppercase;">Registration Confirmed</span>
                                        </div>
                                        
                                        <h1 style="margin: 0 0 8px; font-size: 26px; font-weight: 800; color: #0E0F11; text-align: center;">You're In!</h1>
                                        <p style="margin: 0 0 28px; font-size: 16px; color: #6B7280; text-align: center;">Hi <?php echo esc_html($customer_name); ?>, your registration is confirmed!</p>
                                        
                                        <!-- Order Summary -->
                                        <div style="background: #F9FAFB; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                                            <p style="margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #9CA3AF; text-transform: uppercase;">Order #<?php echo esc_html($order_number); ?></p>
                                            <p style="margin: 0; font-size: 14px; color: #6B7280;"><?php echo esc_html($order_date); ?></p>
                                        </div>
                                        
                                        <!-- Items -->
                                        <table width="100%" style="border-collapse: collapse; margin-bottom: 24px;">
                                            <thead>
                                                <tr style="background: #F3F4F6;">
                                                    <th style="padding: 12px; text-align: left; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Item</th>
                                                    <th style="padding: 12px; text-align: center; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Qty</th>
                                                    <th style="padding: 12px; text-align: right; font-size: 12px; font-weight: 600; color: #6B7280; text-transform: uppercase;">Price</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php echo $items_html; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="2" style="padding: 16px 12px; font-weight: 700; color: #0E0F11;">Total</td>
                                                    <td style="padding: 16px 12px; text-align: right; font-weight: 700; font-size: 18px; color: #059669;">$<?php echo number_format($order_total, 2); ?></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        
                                        <!-- CTA -->
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center">
                                                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" style="display: inline-block; background-color: #FCB900; color: #0E0F11; padding: 16px 32px; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 15px;">View Order Details</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 32px 20px; text-align: center;">
                            <p style="margin: 0 0 16px; font-size: 13px; color: #6B7280;">PA • NJ • DE • MD • NY</p>
                            <p style="margin: 0 0 16px; font-size: 12px;">
                                <a href="<?php echo esc_url(home_url()); ?>" style="color: #FCB900; text-decoration: none;">Website</a>
                                <span style="color: #4B5563; margin: 0 8px;">|</span>
                                <a href="mailto:hello@ptpsummercamps.com" style="color: #FCB900; text-decoration: none;">Contact</a>
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #4B5563;">
                                Questions? Call <?php echo esc_html(get_option('ptp_email_support_phone', '(610) 761-5230')); ?>
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
     * Disable WooCommerce emails for PTP orders
     */
    public function disable_woo_email_for_ptp($enabled, $order) {
        if (!$order || !($order instanceof WC_Order)) {
            return $enabled;
        }
        
        if ($this->is_ptp_order($order)) {
            return false; // Disable WooCommerce email, we send our own
        }
        
        return $enabled;
    }
    
    /**
     * Log mail failures
     */
    public function log_mail_failure($error) {
        $this->log("WordPress mail failed: " . print_r($error->get_error_message(), true));
    }
    
    /**
     * AJAX handler for manual email send
     */
    public function ajax_send_order_email() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Clear sent flag to allow resend
        $order->delete_meta_data('_ptp_confirmation_email_sent');
        $order->delete_meta_data('_ptp_email_sent'); // Also clear old flag
        $order->save();
        
        // Send email
        $sent = $this->send_ptp_order_email($order);
        
        if ($sent) {
            wp_send_json_success('Email sent successfully');
        } else {
            wp_send_json_error('Failed to send email');
        }
    }
    
    /**
     * Logging helper
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PTP Email Wiring] ' . $message);
        }
    }
}

// Initialize on plugins_loaded to ensure WooCommerce is ready
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        PTP_Order_Email_Wiring::instance();
    }
}, 15);
