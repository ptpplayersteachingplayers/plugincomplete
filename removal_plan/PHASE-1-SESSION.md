# Phase 1: Core Infrastructure - PTP Session Class

## File: `includes/class-ptp-session.php`

This class replaces `WC()->session` with native PHP session management backed by database storage.

```php
<?php
/**
 * PTP Session Manager
 * 
 * Replaces WooCommerce session with native PHP sessions + database persistence.
 * Provides the same API as WC_Session for easy migration.
 * 
 * @version 1.0.0
 * @since 148.0.0
 */

defined('ABSPATH') || exit;

class PTP_Session {
    
    /** @var PTP_Session */
    private static $instance = null;
    
    /** @var string */
    private $session_key = '';
    
    /** @var array */
    private $data = array();
    
    /** @var bool */
    private $dirty = false;
    
    /** @var bool */
    private $has_session = false;
    
    /** @var string */
    private $table_name;
    
    /** Cookie name */
    const COOKIE_NAME = 'ptp_session';
    
    /** Session expiry (7 days) */
    const SESSION_EXPIRY = 604800;
    
    /**
     * Get instance (singleton)
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
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ptp_sessions';
        
        // Initialize session on init (priority 1 = very early)
        add_action('init', array($this, 'init_session'), 1);
        
        // Save session on shutdown
        add_action('shutdown', array($this, 'save_session'), 20);
        
        // Cleanup expired sessions daily
        add_action('ptp_cleanup_sessions', array($this, 'cleanup_expired_sessions'));
        if (!wp_next_scheduled('ptp_cleanup_sessions')) {
            wp_schedule_event(time(), 'daily', 'ptp_cleanup_sessions');
        }
    }
    
    /**
     * Initialize session
     */
    public function init_session() {
        // Get or create session key
        $this->session_key = $this->get_session_key();
        
        // Load session data
        $this->load_session();
        
        // Set cookie if needed
        $this->set_session_cookie();
    }
    
    /**
     * Get session key from cookie or generate new one
     */
    private function get_session_key() {
        // Check for existing cookie
        if (isset($_COOKIE[self::COOKIE_NAME]) && !empty($_COOKIE[self::COOKIE_NAME])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
        }
        
        // Check for logged-in user
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }
        
        // Generate new guest session key
        return $this->generate_session_key();
    }
    
    /**
     * Generate unique session key
     */
    private function generate_session_key() {
        return 'ptp_' . bin2hex(random_bytes(16));
    }
    
    /**
     * Load session data from database
     */
    private function load_session() {
        global $wpdb;
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT session_data, expires_at FROM {$this->table_name} 
             WHERE session_key = %s AND expires_at > NOW()",
            $this->session_key
        ));
        
        if ($row) {
            $this->data = maybe_unserialize($row->session_data);
            $this->has_session = true;
            
            if (!is_array($this->data)) {
                $this->data = array();
            }
        } else {
            $this->data = array();
            $this->has_session = false;
        }
    }
    
    /**
     * Save session to database
     */
    public function save_session() {
        if (!$this->dirty) {
            return;
        }
        
        global $wpdb;
        
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_EXPIRY);
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        
        $wpdb->replace(
            $this->table_name,
            array(
                'session_key' => $this->session_key,
                'user_id' => $user_id,
                'session_data' => maybe_serialize($this->data),
                'expires_at' => $expires,
            ),
            array('%s', '%d', '%s', '%s')
        );
        
        $this->dirty = false;
        $this->has_session = true;
    }
    
    /**
     * Set session cookie
     */
    private function set_session_cookie() {
        if (headers_sent() || empty($this->session_key)) {
            return;
        }
        
        // Don't set cookie for logged-in users (they use user ID)
        if (is_user_logged_in()) {
            return;
        }
        
        // Set cookie
        $secure = is_ssl();
        $expiry = time() + self::SESSION_EXPIRY;
        
        setcookie(
            self::COOKIE_NAME,
            $this->session_key,
            $expiry,
            COOKIEPATH,
            COOKIE_DOMAIN,
            $secure,
            true // httponly
        );
    }
    
    /**
     * Set session cookie (public method for compatibility)
     * Matches WC()->session->set_customer_session_cookie()
     */
    public function set_customer_session_cookie($set = true) {
        if ($set) {
            $this->set_session_cookie();
            $this->has_session = true;
        }
    }
    
    /**
     * Check if session exists
     * Matches WC()->session->has_session()
     */
    public function has_session() {
        return $this->has_session || !empty($this->data);
    }
    
    /**
     * Get session value
     * Matches WC()->session->get()
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null) {
        return isset($this->data[$key]) ? $this->data[$key] : $default;
    }
    
    /**
     * Set session value
     * Matches WC()->session->set()
     * 
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value) {
        if ($this->data[$key] ?? null !== $value) {
            $this->data[$key] = $value;
            $this->dirty = true;
        }
    }
    
    /**
     * Delete session value
     * 
     * @param string $key
     */
    public function delete($key) {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }
    
    /**
     * Get all session data
     * 
     * @return array
     */
    public function get_all() {
        return $this->data;
    }
    
    /**
     * Clear all session data
     */
    public function clear() {
        $this->data = array();
        $this->dirty = true;
    }
    
    /**
     * Destroy session completely
     */
    public function destroy() {
        global $wpdb;
        
        // Delete from database
        $wpdb->delete(
            $this->table_name,
            array('session_key' => $this->session_key),
            array('%s')
        );
        
        // Clear cookie
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
        
        // Reset state
        $this->data = array();
        $this->has_session = false;
        $this->dirty = false;
    }
    
    /**
     * Get session key
     */
    public function get_session_key() {
        return $this->session_key;
    }
    
    /**
     * Cleanup expired sessions (cron job)
     */
    public function cleanup_expired_sessions() {
        global $wpdb;
        
        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE expires_at < NOW()"
        );
        
        error_log('[PTP Session] Cleaned up expired sessions');
    }
    
    /**
     * Create database table
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ptp_sessions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            session_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            UNIQUE KEY session_key (session_key),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Migrate WooCommerce session to PTP session
     * Call this during plugin activation if WC was previously used
     */
    public static function migrate_wc_session($user_id) {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }
        
        $instance = self::instance();
        
        // Get common session keys used by PTP
        $keys_to_migrate = array(
            'ptp_training_items',
            'ptp_current_training',
            'ptp_active_bundle',
            'ptp_bundle_code',
            'ptp_selected_trainer',
            'ptp_booking_data',
            'ptp_jersey_upsell',
            'ptp_referral_code',
        );
        
        foreach ($keys_to_migrate as $key) {
            $value = WC()->session->get($key);
            if ($value !== null) {
                $instance->set($key, $value);
            }
        }
        
        $instance->save_session();
        
        return true;
    }
}

// Global helper function
function ptp_session() {
    return PTP_Session::instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    PTP_Session::instance();
}, 5);
```

---

## Database Migration

Add to `class-ptp-database.php` in the `create_tables()` method:

```php
// Add PTP Sessions table
PTP_Session::create_table();
```

---

## Usage Examples

### Before (WooCommerce):
```php
// Get value
$items = WC()->session->get('ptp_training_items', array());

// Set value
WC()->session->set('ptp_training_items', $items);

// Check session
if (WC()->session->has_session()) {
    WC()->session->set_customer_session_cookie(true);
}
```

### After (PTP Native):
```php
// Get value
$items = ptp_session()->get('ptp_training_items', array());

// Set value
ptp_session()->set('ptp_training_items', $items);

// Check session
if (ptp_session()->has_session()) {
    ptp_session()->set_customer_session_cookie(true);
}
```

---

## Search & Replace Patterns

| Find | Replace |
|------|---------|
| `WC()->session->get(` | `ptp_session()->get(` |
| `WC()->session->set(` | `ptp_session()->set(` |
| `WC()->session->has_session()` | `ptp_session()->has_session()` |
| `WC()->session->set_customer_session_cookie(` | `ptp_session()->set_customer_session_cookie(` |

---

## Files Affected by This Change

1. `includes/class-ptp-ajax.php` - 6 session calls
2. `includes/class-ptp-cart-helper.php` - 10 session calls
3. `includes/class-ptp-checkout-v77.php` - 3 session calls
4. `includes/class-ptp-bundle-checkout.php` - 4 session calls
5. `includes/class-ptp-checkout-ux.php` - 2 session calls
6. `includes/class-ptp-crosssell-engine.php` - 3 session calls
7. `includes/class-ptp-growth.php` - 2 session calls
8. `includes/class-ptp-referral-system.php` - 2 session calls
9. `includes/class-ptp-viral-engine.php` - 1 session call
10. `templates/ptp-checkout.php` - 5 session calls
11. `templates/ptp-cart.php` - 2 session calls
12. `templates/camp/ptp-camp-product-v10.3.5.php` - 2 session calls
