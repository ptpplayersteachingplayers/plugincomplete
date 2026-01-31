<?php
/**
 * PTP Admin Settings - v71
 * Configuration for Stripe Connect, Google Calendar, SMS
 */

defined('ABSPATH') || exit;

class PTP_Admin_Settings_V71 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_ptp_test_stripe_connection', array($this, 'test_stripe_connection'));
        add_action('wp_ajax_ptp_test_twilio_connection', array($this, 'test_twilio_connection'));
    }
    
    /**
     * Add admin menu
     */
    public function add_menu_page() {
        // DISABLED - Settings integrated into main PTP menu
        // add_submenu_page(
        //     'edit.php?post_type=ptp_trainer',
        //     'PTP Settings',
        //     'Settings',
        //     'manage_options',
        //     'ptp-settings',
        //     array($this, 'render_settings_page')
        // );
        
        // DISABLED - Using main PTP menu instead
        // add_menu_page(
        //     'PTP Settings',
        //     'PTP Settings',
        //     'manage_options',
        //     'ptp-settings-main',
        //     array($this, 'render_settings_page'),
        //     'dashicons-tickets-alt',
        //     30
        // );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Stripe settings
        register_setting('ptp_stripe_settings', 'ptp_stripe_publishable_key');
        register_setting('ptp_stripe_settings', 'ptp_stripe_secret_key');
        register_setting('ptp_stripe_settings', 'ptp_stripe_connect_client_id');
        register_setting('ptp_stripe_settings', 'ptp_stripe_webhook_secret');
        register_setting('ptp_stripe_settings', 'ptp_platform_fee_percent');
        register_setting('ptp_stripe_settings', 'ptp_processing_fee_percent');
        
        // Google Calendar settings
        register_setting('ptp_google_settings', 'ptp_google_client_id');
        register_setting('ptp_google_settings', 'ptp_google_client_secret');
        
        // SMS settings
        register_setting('ptp_sms_settings', 'ptp_twilio_sid');
        register_setting('ptp_sms_settings', 'ptp_twilio_token');
        register_setting('ptp_sms_settings', 'ptp_twilio_from');
        register_setting('ptp_sms_settings', 'ptp_sms_enabled');
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $active_tab = $_GET['tab'] ?? 'stripe';
        ?>
        <div class="wrap">
            <h1>PTP Training Platform Settings</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=ptp-settings-main&tab=stripe" class="nav-tab <?php echo $active_tab === 'stripe' ? 'nav-tab-active' : ''; ?>">
                    💳 Stripe Payments
                </a>
                <a href="?page=ptp-settings-main&tab=google" class="nav-tab <?php echo $active_tab === 'google' ? 'nav-tab-active' : ''; ?>">
                    📅 Google Calendar
                </a>
                <a href="?page=ptp-settings-main&tab=sms" class="nav-tab <?php echo $active_tab === 'sms' ? 'nav-tab-active' : ''; ?>">
                    📱 SMS (Twilio)
                </a>
            </nav>
            
            <div class="ptp-settings-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'google':
                        $this->render_google_settings();
                        break;
                    case 'sms':
                        $this->render_sms_settings();
                        break;
                    default:
                        $this->render_stripe_settings();
                }
                ?>
            </div>
        </div>
        
        <style>
            .ptp-settings-content {
                max-width: 800px;
            }
            .ptp-settings-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin-bottom: 20px;
            }
            .ptp-settings-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .ptp-field-row {
                margin-bottom: 15px;
            }
            .ptp-field-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
            }
            .ptp-field-row input[type="text"],
            .ptp-field-row input[type="password"],
            .ptp-field-row input[type="number"] {
                width: 100%;
                max-width: 400px;
            }
            .ptp-field-row .description {
                color: #666;
                font-size: 13px;
                margin-top: 5px;
            }
            .ptp-test-btn {
                margin-left: 10px;
            }
            .ptp-status {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            .ptp-status.success {
                background: #d4edda;
                color: #155724;
            }
            .ptp-status.error {
                background: #f8d7da;
                color: #721c24;
            }
            .ptp-status.warning {
                background: #fff3cd;
                color: #856404;
            }
        </style>
        <?php
    }
    
    /**
     * Stripe settings section
     */
    private function render_stripe_settings() {
        $publishable = get_option('ptp_stripe_publishable_key', '');
        $secret = get_option('ptp_stripe_secret_key', '');
        $client_id = get_option('ptp_stripe_connect_client_id', '');
        $webhook = get_option('ptp_stripe_webhook_secret', '');
        $platform_fee = get_option('ptp_platform_fee_percent', 25);
        $processing_fee = get_option('ptp_processing_fee_percent', 3.2);
        
        $is_configured = !empty($publishable) && !empty($secret);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ptp_stripe_settings'); ?>
            
            <div class="ptp-settings-section">
                <h2>Stripe API Keys</h2>
                <p>
                    Status: 
                    <?php if ($is_configured): ?>
                        <span class="ptp-status success">Connected</span>
                    <?php else: ?>
                        <span class="ptp-status warning">Not configured</span>
                    <?php endif; ?>
                </p>
                
                <div class="ptp-field-row">
                    <label>Publishable Key</label>
                    <input type="text" name="ptp_stripe_publishable_key" value="<?php echo esc_attr($publishable); ?>" placeholder="pk_...">
                    <p class="description">Find this in your Stripe Dashboard > Developers > API Keys</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Secret Key</label>
                    <input type="password" name="ptp_stripe_secret_key" value="<?php echo esc_attr($secret); ?>" placeholder="sk_...">
                    <p class="description">Keep this private. Never share or expose publicly.</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Stripe Connect (For Trainer Payouts)</h2>
                
                <div class="ptp-field-row">
                    <label>Connect Client ID</label>
                    <input type="text" name="ptp_stripe_connect_client_id" value="<?php echo esc_attr($client_id); ?>" placeholder="ca_...">
                    <p class="description">Find this in Stripe Dashboard > Settings > Connect > OAuth</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Webhook Signing Secret</label>
                    <input type="password" name="ptp_stripe_webhook_secret" value="<?php echo esc_attr($webhook); ?>" placeholder="whsec_...">
                    <p class="description">
                        Webhook URL: <code><?php echo rest_url('ptp/v1/stripe-webhook'); ?></code>
                    </p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Fee Settings</h2>
                <p style="background:#FFFBEB;border:1px solid #FCB900;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;">
                    <strong>Tiered Commission Active:</strong> First session with new client = 50% PTP / 50% Trainer. Repeat sessions = 25% PTP / 75% Trainer. The setting below is deprecated.
                </p>
                
                <div class="ptp-field-row">
                    <label>Platform Fee (%) <em style="color:#999;font-weight:normal;">- Deprecated</em></label>
                    <input type="number" name="ptp_platform_fee_percent" value="<?php echo esc_attr($platform_fee); ?>" min="0" max="50" step="0.5" style="width: 100px;" disabled>
                    <p class="description">Now using tiered commission: 50% first session, 25% repeat</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Customer Processing Fee (%)</label>
                    <input type="number" name="ptp_processing_fee_percent" value="<?php echo esc_attr($processing_fee); ?>" min="0" max="10" step="0.1" style="width: 100px;">
                    <p class="description">Fee added to customer's order total (default: 3.2%)</p>
                </div>
            </div>
            
            <?php submit_button('Save Stripe Settings'); ?>
        </form>
        <?php
    }
    
    /**
     * Google Calendar settings
     */
    private function render_google_settings() {
        $client_id = get_option('ptp_google_client_id', '');
        $client_secret = get_option('ptp_google_client_secret', '');
        $is_configured = !empty($client_id) && !empty($client_secret);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ptp_google_settings'); ?>
            
            <div class="ptp-settings-section">
                <h2>Google Calendar API</h2>
                <p>
                    Status: 
                    <?php if ($is_configured): ?>
                        <span class="ptp-status success">Configured</span>
                    <?php else: ?>
                        <span class="ptp-status warning">Not configured</span>
                    <?php endif; ?>
                </p>
                
                <div class="ptp-field-row">
                    <label>Client ID</label>
                    <input type="text" name="ptp_google_client_id" value="<?php echo esc_attr($client_id); ?>">
                    <p class="description">From Google Cloud Console > APIs & Services > Credentials</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Client Secret</label>
                    <input type="password" name="ptp_google_client_secret" value="<?php echo esc_attr($client_secret); ?>">
                </div>
                
                <div class="ptp-field-row">
                    <label>OAuth Redirect URIs</label>
                    <p><strong>For Calendar Sync:</strong></p>
                    <code><?php echo admin_url('admin-ajax.php?action=ptp_google_oauth_callback'); ?></code>
                    <p style="margin-top:8px;"><strong>For "Continue with Google" Login:</strong></p>
                    <code><?php echo admin_url('admin-ajax.php?action=ptp_google_login_callback'); ?></code>
                    <p class="description" style="margin-top:12px;">Add BOTH URIs to your Google Cloud OAuth consent screen's Authorized redirect URIs.</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Setup Instructions</h2>
                <ol>
                    <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                    <li>Create a project or select existing</li>
                    <li>Enable the Google Calendar API</li>
                    <li>Create OAuth 2.0 credentials</li>
                    <li>Add the redirect URI above to your credentials</li>
                    <li>Copy Client ID and Secret here</li>
                </ol>
            </div>
            
            <?php submit_button('Save Google Settings'); ?>
        </form>
        <?php
    }
    
    /**
     * SMS settings
     */
    private function render_sms_settings() {
        $sid = get_option('ptp_twilio_sid', '');
        $token = get_option('ptp_twilio_token', '');
        $from = get_option('ptp_twilio_from', '');
        $enabled = get_option('ptp_sms_enabled', '0');
        $is_configured = !empty($sid) && !empty($token) && !empty($from);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ptp_sms_settings'); ?>
            
            <div class="ptp-settings-section">
                <h2>Twilio SMS</h2>
                <p>
                    Status: 
                    <?php if ($is_configured && $enabled): ?>
                        <span class="ptp-status success">Enabled</span>
                    <?php elseif ($is_configured): ?>
                        <span class="ptp-status warning">Configured but disabled</span>
                    <?php else: ?>
                        <span class="ptp-status error">Not configured</span>
                    <?php endif; ?>
                </p>
                
                <div class="ptp-field-row">
                    <label>
                        <input type="checkbox" name="ptp_sms_enabled" value="1" <?php checked($enabled, '1'); ?>>
                        Enable SMS Notifications
                    </label>
                </div>
                
                <div class="ptp-field-row">
                    <label>Account SID</label>
                    <input type="text" name="ptp_twilio_sid" value="<?php echo esc_attr($sid); ?>" placeholder="AC...">
                </div>
                
                <div class="ptp-field-row">
                    <label>Auth Token</label>
                    <input type="password" name="ptp_twilio_token" value="<?php echo esc_attr($token); ?>">
                </div>
                
                <div class="ptp-field-row">
                    <label>From Phone Number</label>
                    <input type="text" name="ptp_twilio_from" value="<?php echo esc_attr($from); ?>" placeholder="+1234567890">
                    <p class="description">Your Twilio phone number in E.164 format</p>
                </div>
            </div>
            
            <?php submit_button('Save SMS Settings'); ?>
        </form>
        <?php
    }
    
    /**
     * Test Stripe connection
     */
    public function test_stripe_connection() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $secret_key = get_option('ptp_stripe_secret_key');
        if (empty($secret_key)) {
            wp_send_json_error('Secret key not configured');
        }
        
        try {
            require_once PTP_PLUGIN_DIR . 'vendor/stripe/stripe-php/init.php';
            \Stripe\Stripe::setApiKey($secret_key);
            
            $account = \Stripe\Account::retrieve();
            
            wp_send_json_success(array(
                'message' => 'Connected to ' . $account->business_profile->name,
                'account_id' => $account->id
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Test Twilio connection
     */
    public function test_twilio_connection() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $sid = get_option('ptp_twilio_sid');
        $token = get_option('ptp_twilio_token');
        
        if (empty($sid) || empty($token)) {
            wp_send_json_error('Twilio credentials not configured');
        }
        
        $response = wp_remote_get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json", array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}")
            )
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['friendly_name'])) {
            wp_send_json_success(array(
                'message' => 'Connected: ' . $body['friendly_name'],
                'status' => $body['status']
            ));
        } else {
            wp_send_json_error('Invalid credentials');
        }
    }
}

// Initialize
PTP_Admin_Settings_V71::instance();
