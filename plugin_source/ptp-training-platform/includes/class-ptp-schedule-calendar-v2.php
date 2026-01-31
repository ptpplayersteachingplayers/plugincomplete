<?php
/**
 * PTP Schedule Calendar v2.0
 * Enhanced admin calendar with:
 * - Dashboard stats panel
 * - Recurring sessions
 * - Conflict detection
 * - Bulk actions
 * - Quick status updates
 * - Export functionality
 * - Keyboard shortcuts
 * - Advanced filtering
 * 
 * @version 2.0.0
 * @since 125.0.0
 */

defined('ABSPATH') || exit;

class PTP_Schedule_Calendar_V2 {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function init() {
        self::instance();
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // AJAX handlers - existing
        add_action('wp_ajax_ptp_schedule_get_events', array($this, 'ajax_get_events'));
        add_action('wp_ajax_ptp_schedule_create_session', array($this, 'ajax_create_session'));
        add_action('wp_ajax_ptp_schedule_update_session', array($this, 'ajax_update_session'));
        add_action('wp_ajax_ptp_schedule_delete_session', array($this, 'ajax_delete_session'));
        add_action('wp_ajax_ptp_schedule_search_customers', array($this, 'ajax_search_customers'));
        add_action('wp_ajax_ptp_schedule_search_players', array($this, 'ajax_search_players'));
        add_action('wp_ajax_ptp_schedule_get_trainer_stats', array($this, 'ajax_get_trainer_stats'));
        
        // AJAX handlers - NEW v2
        add_action('wp_ajax_ptp_schedule_get_dashboard_stats', array($this, 'ajax_get_dashboard_stats'));
        add_action('wp_ajax_ptp_schedule_quick_status', array($this, 'ajax_quick_status'));
        add_action('wp_ajax_ptp_schedule_bulk_action', array($this, 'ajax_bulk_action'));
        add_action('wp_ajax_ptp_schedule_create_recurring', array($this, 'ajax_create_recurring'));
        add_action('wp_ajax_ptp_schedule_check_conflicts', array($this, 'ajax_check_conflicts'));
        add_action('wp_ajax_ptp_schedule_export', array($this, 'ajax_export'));
        add_action('wp_ajax_ptp_schedule_get_trainer_availability', array($this, 'ajax_get_trainer_availability'));
    }
    
    public function add_menu() {
        add_submenu_page(
            'ptp-training',
            'Schedule Calendar',
            '📅 Schedule',
            'edit_posts',
            'ptp-schedule',
            array($this, 'render_calendar')
        );
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'ptp-schedule') === false) return;
        
        // FullCalendar
        wp_enqueue_style('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css', array(), '6.1.10');
        wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array(), '6.1.10', true);
        
        // Tippy.js for tooltips
        wp_enqueue_script('popper', 'https://unpkg.com/@popperjs/core@2', array(), '2.0', true);
        wp_enqueue_script('tippy', 'https://unpkg.com/tippy.js@6', array('popper'), '6.0', true);
        
        // Plugin assets
        wp_enqueue_style('ptp-schedule-v2', PTP_PLUGIN_URL . 'assets/css/schedule-v2.css', array(), PTP_VERSION);
        wp_enqueue_script('ptp-schedule-v2', PTP_PLUGIN_URL . 'assets/js/schedule-v2.js', array('jquery', 'fullcalendar', 'tippy'), PTP_VERSION, true);
        
        wp_localize_script('ptp-schedule-v2', 'PTPSchedule', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_schedule_nonce'),
            'trainers' => $this->get_trainers_data(),
            'defaultView' => get_user_meta(get_current_user_id(), 'ptp_calendar_view', true) ?: 'timeGridWeek',
        ));
    }
    
    private function get_trainers_data() {
        global $wpdb;
        $trainers = $wpdb->get_results(
            "SELECT id, user_id, display_name, photo_url, status, hourly_rate
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active' 
             ORDER BY display_name"
        );
        
        $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
        
        $data = array();
        foreach ($trainers as $i => $t) {
            $data[] = array(
                'id' => $t->id,
                'name' => $t->display_name,
                'photo' => $t->photo_url,
                'color' => $colors[$i % 10],
                'rate' => $t->hourly_rate,
            );
        }
        return $data;
    }
    
    public function render_calendar() {
        global $wpdb;
        
        // Get active trainers
        $trainers = $wpdb->get_results(
            "SELECT id, user_id, display_name, photo_url, status, hourly_rate
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active' 
             ORDER BY display_name"
        );
        
        $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
        
        ?>
        <div class="ptp-schedule-v2">
            <!-- Top Bar -->
            <div class="ptp-topbar">
                <div class="ptp-topbar-left">
                    <h1>📅 Schedule Calendar</h1>
                    <span class="ptp-version-badge">v2.0</span>
                </div>
                <div class="ptp-topbar-right">
                    <div class="ptp-search-global">
                        <input type="text" id="globalSearch" placeholder="Search sessions, trainers, players...">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    </div>
                    <button type="button" id="btnExport" class="ptp-btn-icon" title="Export">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </button>
                    <button type="button" id="btnRefresh" class="ptp-btn-icon" title="Refresh (R)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                    <button type="button" id="btnKeyboardHelp" class="ptp-btn-icon" title="Keyboard Shortcuts (?)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M8 12h.01M12 12h.01M16 12h.01M18 16H6"/></svg>
                    </button>
                </div>
            </div>
            
            <!-- Dashboard Stats -->
            <div class="ptp-dashboard-stats" id="dashboardStats">
                <div class="ptp-stat-card">
                    <div class="ptp-stat-icon today"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                    <div class="ptp-stat-content">
                        <span class="ptp-stat-value" id="statToday">-</span>
                        <span class="ptp-stat-label">Today</span>
                    </div>
                </div>
                <div class="ptp-stat-card">
                    <div class="ptp-stat-icon week"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4M16 2v4M3 10h18M21 8v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                    <div class="ptp-stat-content">
                        <span class="ptp-stat-value" id="statWeek">-</span>
                        <span class="ptp-stat-label">This Week</span>
                    </div>
                </div>
                <div class="ptp-stat-card">
                    <div class="ptp-stat-icon pending"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <div class="ptp-stat-content">
                        <span class="ptp-stat-value" id="statPending">-</span>
                        <span class="ptp-stat-label">Pending</span>
                    </div>
                </div>
                <div class="ptp-stat-card">
                    <div class="ptp-stat-icon revenue"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                    <div class="ptp-stat-content">
                        <span class="ptp-stat-value" id="statRevenue">-</span>
                        <span class="ptp-stat-label">Week Revenue</span>
                    </div>
                </div>
                <div class="ptp-stat-card">
                    <div class="ptp-stat-icon trainers"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                    <div class="ptp-stat-content">
                        <span class="ptp-stat-value" id="statTrainers">-</span>
                        <span class="ptp-stat-label">Active Trainers</span>
                    </div>
                </div>
            </div>
            
            <!-- Main Layout -->
            <div class="ptp-main-layout">
                <!-- Sidebar -->
                <div class="ptp-sidebar">
                    <!-- Quick Actions -->
                    <div class="ptp-card">
                        <div class="ptp-card-header">
                            <h3>Quick Actions</h3>
                        </div>
                        <div class="ptp-quick-actions">
                            <button type="button" id="btnNewSession" class="ptp-btn primary full">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                New Session
                            </button>
                            <button type="button" id="btnRecurring" class="ptp-btn secondary full">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                Recurring
                            </button>
                            <div class="ptp-btn-row">
                                <button type="button" id="btnToday" class="ptp-btn outline">Today</button>
                                <button type="button" id="btnThisWeek" class="ptp-btn outline">Week</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="ptp-card">
                        <div class="ptp-card-header">
                            <h3>Filters</h3>
                            <button type="button" id="clearFilters" class="ptp-btn-text">Clear</button>
                        </div>
                        <div class="ptp-filters">
                            <div class="ptp-filter-group">
                                <label>Status</label>
                                <div class="ptp-status-filters">
                                    <label class="ptp-chip active" data-status="all">
                                        <input type="radio" name="statusFilter" value="all" checked> All
                                    </label>
                                    <label class="ptp-chip" data-status="pending">
                                        <input type="radio" name="statusFilter" value="pending">
                                        <span class="dot pending"></span> Pending
                                    </label>
                                    <label class="ptp-chip" data-status="confirmed">
                                        <input type="radio" name="statusFilter" value="confirmed">
                                        <span class="dot confirmed"></span> Confirmed
                                    </label>
                                    <label class="ptp-chip" data-status="scheduled">
                                        <input type="radio" name="statusFilter" value="scheduled">
                                        <span class="dot scheduled"></span> Scheduled
                                    </label>
                                    <label class="ptp-chip" data-status="completed">
                                        <input type="radio" name="statusFilter" value="completed">
                                        <span class="dot completed"></span> Completed
                                    </label>
                                </div>
                            </div>
                            <div class="ptp-filter-group">
                                <label>Payment</label>
                                <select id="paymentFilter">
                                    <option value="">All Payments</option>
                                    <option value="paid">Paid</option>
                                    <option value="unpaid">Unpaid</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                            <div class="ptp-filter-group">
                                <label>Session Type</label>
                                <select id="typeFilter">
                                    <option value="">All Types</option>
                                    <option value="1on1">1-on-1</option>
                                    <option value="small_group">Small Group</option>
                                    <option value="group">Group</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Trainers -->
                    <div class="ptp-card trainers-card">
                        <div class="ptp-card-header">
                            <h3>Trainers</h3>
                            <div class="ptp-trainer-toggle">
                                <button type="button" id="selectAll" class="active" title="Select All">All</button>
                                <button type="button" id="selectNone" title="Select None">None</button>
                            </div>
                        </div>
                        
                        <div id="focusBanner" class="ptp-focus-banner">
                            <span id="focusName"></span>
                            <button type="button" id="exitFocus">&times;</button>
                        </div>
                        
                        <div id="trainerList" class="ptp-trainer-list">
                            <?php if (empty($trainers)): ?>
                            <div class="ptp-empty">No active trainers</div>
                            <?php else: ?>
                            <?php foreach ($trainers as $i => $t): 
                                $color = $colors[$i % 10];
                                $initials = strtoupper(substr($t->display_name, 0, 1));
                            ?>
                            <div class="ptp-trainer-row selected" 
                                 data-id="<?php echo $t->id; ?>" 
                                 data-name="<?php echo esc_attr($t->display_name); ?>"
                                 data-color="<?php echo $color; ?>">
                                <input type="checkbox" value="<?php echo $t->id; ?>" checked>
                                <?php if ($t->photo_url): ?>
                                <img src="<?php echo esc_url($t->photo_url); ?>" class="ptp-trainer-photo" alt="">
                                <?php else: ?>
                                <div class="ptp-trainer-avatar" style="background:<?php echo $color; ?>"><?php echo $initials; ?></div>
                                <?php endif; ?>
                                <div class="ptp-trainer-info">
                                    <span class="ptp-trainer-name"><?php echo esc_html($t->display_name); ?></span>
                                    <span class="ptp-trainer-meta">
                                        <span class="sessions-count">0 sessions</span>
                                        <?php if ($t->hourly_rate): ?>
                                        <span class="rate">$<?php echo number_format($t->hourly_rate); ?>/hr</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="ptp-trainer-color" style="background:<?php echo $color; ?>"></div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="ptp-tip">💡 Double-click for focus mode</p>
                    </div>
                    
                    <!-- Legend -->
                    <div class="ptp-card">
                        <div class="ptp-card-header">
                            <h3>Legend</h3>
                        </div>
                        <div class="ptp-legend">
                            <div class="ptp-legend-section">
                                <span class="ptp-legend-title">Status</span>
                                <div class="ptp-legend-item"><span class="dot pending"></span>Pending</div>
                                <div class="ptp-legend-item"><span class="dot confirmed"></span>Confirmed</div>
                                <div class="ptp-legend-item"><span class="dot scheduled"></span>Scheduled</div>
                                <div class="ptp-legend-item"><span class="dot completed"></span>Completed</div>
                                <div class="ptp-legend-item"><span class="dot cancelled"></span>Cancelled</div>
                            </div>
                            <div class="ptp-legend-section">
                                <span class="ptp-legend-title">Source</span>
                                <div class="ptp-legend-item"><span class="source-dot booking"></span>Parent Booking</div>
                                <div class="ptp-legend-item"><span class="source-dot admin"></span>Admin Session</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Calendar -->
                <div class="ptp-calendar-wrap">
                    <!-- Bulk Actions Bar (hidden by default) -->
                    <div id="bulkActionsBar" class="ptp-bulk-bar">
                        <span id="selectedCount">0 selected</span>
                        <div class="ptp-bulk-actions">
                            <button type="button" data-action="confirm" class="ptp-btn small success">Confirm</button>
                            <button type="button" data-action="complete" class="ptp-btn small">Complete</button>
                            <button type="button" data-action="cancel" class="ptp-btn small danger">Cancel</button>
                            <button type="button" id="clearSelection" class="ptp-btn small outline">Clear</button>
                        </div>
                    </div>
                    
                    <div id="calendar"></div>
                </div>
            </div>
        </div>
        
        <!-- Session Modal -->
        <div id="sessionModal" class="ptp-modal">
            <div class="ptp-modal-content">
                <div class="ptp-modal-header">
                    <div class="ptp-modal-title-row">
                        <h2 id="modalTitle">New Session</h2>
                        <span id="sessionSource" class="ptp-source-badge"></span>
                    </div>
                    <button type="button" class="ptp-modal-close">&times;</button>
                </div>
                <form id="sessionForm">
                    <input type="hidden" id="sessionId" name="id">
                    <input type="hidden" id="sessionType" name="source" value="admin">
                    
                    <!-- Conflict Warning -->
                    <div id="conflictWarning" class="ptp-conflict-warning">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span id="conflictMessage">Scheduling conflict detected</span>
                    </div>
                    
                    <div class="ptp-form-tabs">
                        <button type="button" class="ptp-tab active" data-tab="details">Details</button>
                        <button type="button" class="ptp-tab" data-tab="scheduling">Schedule</button>
                        <button type="button" class="ptp-tab" data-tab="payment">Payment</button>
                    </div>
                    
                    <!-- Tab: Details -->
                    <div class="ptp-tab-content active" data-tab="details">
                        <div class="ptp-form-row">
                            <div class="ptp-field">
                                <label>Trainer <span class="req">*</span></label>
                                <select id="trainerId" name="trainer_id" required>
                                    <option value="">Select trainer...</option>
                                    <?php foreach ($trainers as $t): ?>
                                    <option value="<?php echo $t->id; ?>"><?php echo esc_html($t->display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ptp-field">
                                <label>Status</label>
                                <select id="sessionStatus" name="session_status">
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="scheduled" selected>Scheduled</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="no_show">No Show</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="ptp-field">
                            <label>Parent / Customer</label>
                            <div class="ptp-search-wrap">
                                <input type="text" id="customerSearch" placeholder="Search by name or email...">
                                <input type="hidden" id="customerId" name="customer_id">
                                <input type="hidden" id="parentId" name="parent_id">
                                <div id="customerResults" class="ptp-search-results"></div>
                            </div>
                            <div id="selectedCustomer" class="ptp-selected-chip">
                                <span id="customerName"></span>
                                <button type="button" id="clearCustomer">&times;</button>
                            </div>
                        </div>
                        
                        <div class="ptp-field">
                            <label>Player</label>
                            <select id="playerId" name="player_id">
                                <option value="">-- Select or enter manually --</option>
                            </select>
                        </div>
                        
                        <div class="ptp-form-row">
                            <div class="ptp-field flex-2">
                                <label>Player Name <span class="req">*</span></label>
                                <input type="text" id="playerName" name="player_name" required>
                            </div>
                            <div class="ptp-field flex-1">
                                <label>Age</label>
                                <input type="number" id="playerAge" name="player_age" min="4" max="18">
                            </div>
                        </div>
                        
                        <div class="ptp-field">
                            <label>Session Type</label>
                            <div class="ptp-type-selector">
                                <label class="ptp-type-option active">
                                    <input type="radio" name="session_type" value="1on1" checked>
                                    <span class="ptp-type-icon">👤</span>
                                    <span class="ptp-type-label">1-on-1</span>
                                </label>
                                <label class="ptp-type-option">
                                    <input type="radio" name="session_type" value="small_group">
                                    <span class="ptp-type-icon">👥</span>
                                    <span class="ptp-type-label">Small Group</span>
                                </label>
                                <label class="ptp-type-option">
                                    <input type="radio" name="session_type" value="group">
                                    <span class="ptp-type-icon">👨‍👩‍👧‍👦</span>
                                    <span class="ptp-type-label">Group</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab: Scheduling -->
                    <div class="ptp-tab-content" data-tab="scheduling">
                        <div class="ptp-form-row">
                            <div class="ptp-field">
                                <label>Date <span class="req">*</span></label>
                                <input type="date" id="sessionDate" name="session_date" required>
                            </div>
                            <div class="ptp-field">
                                <label>Start Time <span class="req">*</span></label>
                                <input type="time" id="startTime" name="start_time" required>
                            </div>
                        </div>
                        
                        <div class="ptp-field">
                            <label>Duration</label>
                            <div class="ptp-duration-selector">
                                <label class="ptp-duration-option">
                                    <input type="radio" name="duration_minutes" value="30">
                                    <span>30m</span>
                                </label>
                                <label class="ptp-duration-option">
                                    <input type="radio" name="duration_minutes" value="45">
                                    <span>45m</span>
                                </label>
                                <label class="ptp-duration-option active">
                                    <input type="radio" name="duration_minutes" value="60" checked>
                                    <span>1hr</span>
                                </label>
                                <label class="ptp-duration-option">
                                    <input type="radio" name="duration_minutes" value="90">
                                    <span>1.5hr</span>
                                </label>
                                <label class="ptp-duration-option">
                                    <input type="radio" name="duration_minutes" value="120">
                                    <span>2hr</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="ptp-field">
                            <label>Location</label>
                            <input type="text" id="locationText" name="location_text" placeholder="Field name or address">
                        </div>
                        
                        <div class="ptp-field">
                            <label>Internal Notes</label>
                            <textarea id="internalNotes" name="internal_notes" rows="3" placeholder="Notes visible only to admins..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Tab: Payment -->
                    <div class="ptp-tab-content" data-tab="payment">
                        <div class="ptp-form-row">
                            <div class="ptp-field">
                                <label>Price ($)</label>
                                <input type="number" id="price" name="price" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="ptp-field">
                                <label>Payment Status</label>
                                <select id="paymentStatus" name="payment_status">
                                    <option value="unpaid">Unpaid</option>
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="ptp-payment-summary" id="paymentSummary">
                            <div class="ptp-summary-row">
                                <span>Session Price</span>
                                <span id="summaryPrice">$0.00</span>
                            </div>
                            <div class="ptp-summary-row">
                                <span>Platform Fee (25%)</span>
                                <span id="summaryFee">$0.00</span>
                            </div>
                            <div class="ptp-summary-row total">
                                <span>Trainer Payout</span>
                                <span id="summaryPayout">$0.00</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ptp-modal-footer">
                        <button type="button" id="deleteSession" class="ptp-btn danger-text">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Delete
                        </button>
                        <div class="ptp-modal-actions">
                            <button type="button" class="ptp-btn secondary ptp-modal-cancel">Cancel</button>
                            <button type="submit" class="ptp-btn primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                Save Session
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Recurring Modal -->
        <div id="recurringModal" class="ptp-modal">
            <div class="ptp-modal-content">
                <div class="ptp-modal-header">
                    <h2>Create Recurring Sessions</h2>
                    <button type="button" class="ptp-modal-close">&times;</button>
                </div>
                <form id="recurringForm">
                    <div class="ptp-form-row">
                        <div class="ptp-field">
                            <label>Trainer <span class="req">*</span></label>
                            <select id="recurringTrainer" name="trainer_id" required>
                                <option value="">Select trainer...</option>
                                <?php foreach ($trainers as $t): ?>
                                <option value="<?php echo $t->id; ?>"><?php echo esc_html($t->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ptp-field">
                            <label>Frequency</label>
                            <select id="recurringFrequency" name="frequency">
                                <option value="weekly">Weekly</option>
                                <option value="biweekly">Bi-weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="ptp-field">
                        <label>Day of Week</label>
                        <div class="ptp-day-selector">
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="1"> Mon</label>
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="2"> Tue</label>
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="3"> Wed</label>
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="4"> Thu</label>
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="5"> Fri</label>
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="6"> Sat</label>
                            <label class="ptp-day"><input type="checkbox" name="days[]" value="0"> Sun</label>
                        </div>
                    </div>
                    
                    <div class="ptp-form-row">
                        <div class="ptp-field">
                            <label>Start Time</label>
                            <input type="time" id="recurringTime" name="start_time" value="09:00" required>
                        </div>
                        <div class="ptp-field">
                            <label>Duration</label>
                            <select id="recurringDuration" name="duration_minutes">
                                <option value="30">30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60" selected>1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="ptp-form-row">
                        <div class="ptp-field">
                            <label>Start Date</label>
                            <input type="date" id="recurringStart" name="start_date" required>
                        </div>
                        <div class="ptp-field">
                            <label>Number of Sessions</label>
                            <input type="number" id="recurringCount" name="count" value="8" min="1" max="52">
                        </div>
                    </div>
                    
                    <div class="ptp-field">
                        <label>Player Name</label>
                        <input type="text" id="recurringPlayer" name="player_name" placeholder="Optional">
                    </div>
                    
                    <div id="recurringPreview" class="ptp-recurring-preview">
                        <h4>Preview</h4>
                        <div id="previewDates"></div>
                    </div>
                    
                    <div class="ptp-modal-footer">
                        <button type="button" class="ptp-btn secondary ptp-modal-cancel">Cancel</button>
                        <button type="submit" class="ptp-btn primary">Create Sessions</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Keyboard Shortcuts Modal -->
        <div id="keyboardModal" class="ptp-modal">
            <div class="ptp-modal-content small">
                <div class="ptp-modal-header">
                    <h2>Keyboard Shortcuts</h2>
                    <button type="button" class="ptp-modal-close">&times;</button>
                </div>
                <div class="ptp-shortcuts">
                    <div class="ptp-shortcut"><kbd>N</kbd> New session</div>
                    <div class="ptp-shortcut"><kbd>R</kbd> Refresh calendar</div>
                    <div class="ptp-shortcut"><kbd>T</kbd> Go to today</div>
                    <div class="ptp-shortcut"><kbd>W</kbd> Week view</div>
                    <div class="ptp-shortcut"><kbd>M</kbd> Month view</div>
                    <div class="ptp-shortcut"><kbd>D</kbd> Day view</div>
                    <div class="ptp-shortcut"><kbd>←</kbd> Previous</div>
                    <div class="ptp-shortcut"><kbd>→</kbd> Next</div>
                    <div class="ptp-shortcut"><kbd>/</kbd> Focus search</div>
                    <div class="ptp-shortcut"><kbd>Esc</kbd> Close modal</div>
                    <div class="ptp-shortcut"><kbd>?</kbd> Show shortcuts</div>
                </div>
            </div>
        </div>
        
        <!-- Export Modal -->
        <div id="exportModal" class="ptp-modal">
            <div class="ptp-modal-content small">
                <div class="ptp-modal-header">
                    <h2>Export Calendar</h2>
                    <button type="button" class="ptp-modal-close">&times;</button>
                </div>
                <form id="exportForm">
                    <div class="ptp-field">
                        <label>Date Range</label>
                        <div class="ptp-form-row">
                            <input type="date" id="exportStart" name="start" required>
                            <span>to</span>
                            <input type="date" id="exportEnd" name="end" required>
                        </div>
                    </div>
                    <div class="ptp-field">
                        <label>Format</label>
                        <div class="ptp-export-options">
                            <label class="ptp-export-option active">
                                <input type="radio" name="format" value="csv" checked>
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span>CSV</span>
                            </label>
                            <label class="ptp-export-option">
                                <input type="radio" name="format" value="ical">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>iCal</span>
                            </label>
                        </div>
                    </div>
                    <div class="ptp-modal-footer">
                        <button type="button" class="ptp-btn secondary ptp-modal-cancel">Cancel</button>
                        <button type="submit" class="ptp-btn primary">Download</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Toast Container -->
        <div id="toastContainer" class="ptp-toast-container"></div>
        
        <?php
    }
    
    // ===================
    // AJAX HANDLERS
    // ===================
    
    public function ajax_get_events() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $start = sanitize_text_field($_GET['start'] ?? '');
        $end = sanitize_text_field($_GET['end'] ?? '');
        $trainer_ids = isset($_GET['trainers']) ? sanitize_text_field($_GET['trainers']) : '';
        $trainer_ids = $trainer_ids ? array_map('intval', explode(',', $trainer_ids)) : array();
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $payment_filter = sanitize_text_field($_GET['payment'] ?? '');
        $type_filter = sanitize_text_field($_GET['type'] ?? '');
        $search = sanitize_text_field($_GET['search'] ?? '');
        
        $events = array();
        
        // Get admin-created sessions from ptp_sessions
        $sql = "SELECT s.*, t.display_name as trainer_name, u.display_name as customer_name, u.user_email as customer_email
                FROM {$wpdb->prefix}ptp_sessions s
                LEFT JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
                LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
                WHERE s.session_date >= %s AND s.session_date <= %s";
        
        $params = array($start, $end);
        
        if (!empty($trainer_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_ids), '%d'));
            $sql .= " AND s.trainer_id IN ($placeholders)";
            $params = array_merge($params, $trainer_ids);
        }
        
        if ($status_filter && $status_filter !== 'all') {
            $sql .= " AND s.session_status = %s";
            $params[] = $status_filter;
        }
        
        if ($payment_filter) {
            $sql .= " AND s.payment_status = %s";
            $params[] = $payment_filter;
        }
        
        if ($type_filter) {
            $sql .= " AND s.session_type = %s";
            $params[] = $type_filter;
        }
        
        if ($search) {
            $sql .= " AND (s.player_name LIKE %s OR t.display_name LIKE %s)";
            $search_param = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $sql .= " ORDER BY s.session_date, s.start_time";
        
        $sessions = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        foreach ($sessions as $s) {
            $events[] = $this->format_event($s, 'admin');
        }
        
        // Get parent-booked sessions from ptp_bookings
        $sql2 = "SELECT b.*, t.display_name as trainer_name, 
                        p.name as player_name, p.age as player_age,
                        pa.user_id as customer_id,
                        u.display_name as customer_name, u.user_email as customer_email
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                 LEFT JOIN {$wpdb->users} u ON pa.user_id = u.ID
                 WHERE b.session_date >= %s AND b.session_date <= %s";
        
        $params2 = array($start, $end);
        
        if (!empty($trainer_ids)) {
            $sql2 .= " AND b.trainer_id IN ($placeholders)";
            $params2 = array_merge($params2, $trainer_ids);
        }
        
        if ($status_filter && $status_filter !== 'all') {
            $sql2 .= " AND b.status = %s";
            $params2[] = $status_filter;
        }
        
        if ($payment_filter) {
            $sql2 .= " AND b.payment_status = %s";
            $params2[] = $payment_filter;
        }
        
        if ($search) {
            $sql2 .= " AND (p.name LIKE %s OR t.display_name LIKE %s)";
            $params2[] = $search_param;
            $params2[] = $search_param;
        }
        
        $sql2 .= " ORDER BY b.session_date, b.start_time";
        
        $bookings = $wpdb->get_results($wpdb->prepare($sql2, $params2));
        
        foreach ($bookings as $b) {
            $events[] = $this->format_event($b, 'booking');
        }
        
        wp_send_json($events);
    }
    
    private function format_event($row, $source) {
        $status = $source === 'booking' ? ($row->status ?? 'scheduled') : ($row->session_status ?? 'scheduled');
        $id_prefix = $source === 'booking' ? 'booking_' : 'session_';
        $id_field = $source === 'booking' ? $row->id : $row->id;
        
        return array(
            'id' => $id_prefix . $id_field,
            'title' => ($row->player_name ?: 'Session') . ' - ' . ($row->trainer_name ?: 'Unassigned'),
            'start' => $row->session_date . 'T' . $row->start_time,
            'end' => $row->session_date . 'T' . ($row->end_time ?? date('H:i:s', strtotime($row->start_time) + 3600)),
            'className' => 'status-' . $status . ' source-' . $source,
            'extendedProps' => array(
                'source' => $source,
                'record_id' => $id_field,
                'trainer_id' => $row->trainer_id,
                'trainer_name' => $row->trainer_name,
                'customer_id' => $row->customer_id ?? null,
                'customer_name' => $row->customer_name ?? '',
                'customer_email' => $row->customer_email ?? '',
                'parent_id' => $row->parent_id ?? null,
                'player_id' => $row->player_id ?? null,
                'player_name' => $row->player_name ?? '',
                'player_age' => $row->player_age ?? null,
                'session_status' => $status,
                'payment_status' => $row->payment_status ?? 'unpaid',
                'session_type' => $row->session_type ?? '1on1',
                'location_text' => $row->location_text ?? $row->location ?? '',
                'price' => $row->price ?? $row->total_amount ?? 0,
                'internal_notes' => $row->internal_notes ?? $row->notes ?? '',
                'duration_minutes' => $row->duration_minutes ?? 60,
                'booking_number' => $row->booking_number ?? null,
            ),
        );
    }
    
    public function ajax_get_dashboard_stats() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $today = date('Y-m-d');
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        
        // Today's sessions
        $today_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions WHERE session_date = %s AND session_status NOT IN ('cancelled')",
            $today
        ));
        $today_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE session_date = %s AND status NOT IN ('cancelled')",
            $today
        ));
        
        // This week
        $week_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions WHERE session_date BETWEEN %s AND %s AND session_status NOT IN ('cancelled')",
            $week_start, $week_end
        ));
        $week_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE session_date BETWEEN %s AND %s AND status NOT IN ('cancelled')",
            $week_start, $week_end
        ));
        
        // Pending
        $pending_sessions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions WHERE session_status = 'pending'"
        );
        $pending_bookings = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE status = 'pending'"
        );
        
        // Week revenue
        $session_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(price), 0) FROM {$wpdb->prefix}ptp_sessions 
             WHERE session_date BETWEEN %s AND %s AND session_status NOT IN ('cancelled') AND payment_status = 'paid'",
            $week_start, $week_end
        ));
        $booking_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE session_date BETWEEN %s AND %s AND status NOT IN ('cancelled') AND payment_status = 'paid'",
            $week_start, $week_end
        ));
        
        // Active trainers
        $active_trainers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'"
        );
        
        wp_send_json_success(array(
            'today' => intval($today_count) + intval($today_bookings),
            'week' => intval($week_sessions) + intval($week_bookings),
            'pending' => intval($pending_sessions) + intval($pending_bookings),
            'revenue' => floatval($session_revenue) + floatval($booking_revenue),
            'trainers' => intval($active_trainers),
        ));
    }
    
    public function ajax_quick_status() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $id = sanitize_text_field($_POST['id'] ?? '');
        $new_status = sanitize_text_field($_POST['status'] ?? '');
        
        if (!$id || !$new_status) {
            wp_send_json_error('Missing parameters');
        }
        
        if (strpos($id, 'booking_') === 0) {
            $record_id = intval(str_replace('booking_', '', $id));
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_bookings",
                array('status' => $new_status),
                array('id' => $record_id)
            );
        } else {
            $record_id = intval(str_replace('session_', '', $id));
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_sessions",
                array('session_status' => $new_status),
                array('id' => $record_id)
            );
        }
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Update failed');
        }
    }
    
    public function ajax_bulk_action() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $ids = isset($_POST['ids']) ? array_map('sanitize_text_field', $_POST['ids']) : array();
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        
        if (empty($ids) || !$action) {
            wp_send_json_error('Missing parameters');
        }
        
        $status_map = array(
            'confirm' => 'confirmed',
            'complete' => 'completed',
            'cancel' => 'cancelled',
        );
        
        if (!isset($status_map[$action])) {
            wp_send_json_error('Invalid action');
        }
        
        $new_status = $status_map[$action];
        $updated = 0;
        
        foreach ($ids as $id) {
            if (strpos($id, 'booking_') === 0) {
                $record_id = intval(str_replace('booking_', '', $id));
                $result = $wpdb->update(
                    "{$wpdb->prefix}ptp_bookings",
                    array('status' => $new_status),
                    array('id' => $record_id)
                );
            } else {
                $record_id = intval(str_replace('session_', '', $id));
                $result = $wpdb->update(
                    "{$wpdb->prefix}ptp_sessions",
                    array('session_status' => $new_status),
                    array('id' => $record_id)
                );
            }
            if ($result) $updated++;
        }
        
        wp_send_json_success(array('updated' => $updated));
    }
    
    public function ajax_create_recurring() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        $days = isset($_POST['days']) ? array_map('intval', $_POST['days']) : array();
        $start_time = sanitize_text_field($_POST['start_time'] ?? '09:00');
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $count = intval($_POST['count'] ?? 8);
        $player_name = sanitize_text_field($_POST['player_name'] ?? '');
        
        if (!$trainer_id || empty($days) || !$start_date) {
            wp_send_json_error('Missing required fields');
        }
        
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        $created = 0;
        $conflicts = 0;
        
        $interval = $frequency === 'weekly' ? 7 : ($frequency === 'biweekly' ? 14 : 28);
        
        $current_date = new DateTime($start_date);
        $sessions_created = 0;
        $max_iterations = $count * 7; // Safety limit
        $iterations = 0;
        
        while ($sessions_created < $count && $iterations < $max_iterations) {
            $iterations++;
            $day_of_week = intval($current_date->format('w'));
            
            if (in_array($day_of_week, $days)) {
                $session_date = $current_date->format('Y-m-d');
                
                // Check for conflicts
                $conflict = $this->check_conflict($trainer_id, $session_date, $start_time, $end_time);
                
                if (!$conflict) {
                    $data = array(
                        'trainer_id' => $trainer_id,
                        'player_name' => $player_name,
                        'session_date' => $session_date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'duration_minutes' => $duration,
                        'session_status' => 'scheduled',
                        'session_type' => '1on1',
                        'created_by' => get_current_user_id(),
                    );
                    
                    $wpdb->insert("{$wpdb->prefix}ptp_sessions", $data);
                    $created++;
                } else {
                    $conflicts++;
                }
                
                $sessions_created++;
            }
            
            $current_date->modify('+1 day');
            
            // Reset to start of next interval period if we've passed all selected days
            if ($day_of_week === 6 && $frequency !== 'weekly') {
                $current_date->modify('+' . ($interval - 7) . ' days');
            }
        }
        
        wp_send_json_success(array(
            'created' => $created,
            'conflicts' => $conflicts,
        ));
    }
    
    private function check_conflict($trainer_id, $date, $start_time, $end_time, $exclude_id = null) {
        global $wpdb;
        
        // Check sessions table
        $sql = "SELECT id FROM {$wpdb->prefix}ptp_sessions 
                WHERE trainer_id = %d 
                AND session_date = %s 
                AND session_status NOT IN ('cancelled')
                AND (
                    (start_time < %s AND end_time > %s) OR
                    (start_time < %s AND end_time > %s) OR
                    (start_time >= %s AND end_time <= %s)
                )";
        
        $params = array($trainer_id, $date, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
        
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        $session_conflict = $wpdb->get_var($wpdb->prepare($sql, $params));
        
        if ($session_conflict) return true;
        
        // Check bookings table
        $sql2 = "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d 
                 AND session_date = %s 
                 AND status NOT IN ('cancelled')
                 AND (
                     (start_time < %s AND end_time > %s) OR
                     (start_time < %s AND end_time > %s) OR
                     (start_time >= %s AND end_time <= %s)
                 )";
        
        $booking_conflict = $wpdb->get_var($wpdb->prepare($sql2, $trainer_id, $date, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time));
        
        return $booking_conflict ? true : false;
    }
    
    public function ajax_check_conflicts() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        $trainer_id = intval($_GET['trainer_id'] ?? 0);
        $date = sanitize_text_field($_GET['date'] ?? '');
        $start_time = sanitize_text_field($_GET['start_time'] ?? '');
        $duration = intval($_GET['duration'] ?? 60);
        $exclude_id = sanitize_text_field($_GET['exclude_id'] ?? '');
        
        if (!$trainer_id || !$date || !$start_time) {
            wp_send_json_error('Missing parameters');
        }
        
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        $exclude = null;
        if ($exclude_id && strpos($exclude_id, 'session_') === 0) {
            $exclude = intval(str_replace('session_', '', $exclude_id));
        }
        
        $has_conflict = $this->check_conflict($trainer_id, $date, $start_time, $end_time, $exclude);
        
        wp_send_json_success(array('conflict' => $has_conflict));
    }
    
    public function ajax_export() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $start = sanitize_text_field($_POST['start'] ?? '');
        $end = sanitize_text_field($_POST['end'] ?? '');
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        
        // Get all sessions and bookings in date range
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, t.display_name as trainer_name, 'admin' as source
             FROM {$wpdb->prefix}ptp_sessions s
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
             WHERE s.session_date BETWEEN %s AND %s
             ORDER BY s.session_date, s.start_time",
            $start, $end
        ));
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, p.name as player_name, 'booking' as source
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.session_date BETWEEN %s AND %s
             ORDER BY b.session_date, b.start_time",
            $start, $end
        ));
        
        $all = array_merge($sessions, $bookings);
        usort($all, function($a, $b) {
            $cmp = strcmp($a->session_date, $b->session_date);
            return $cmp !== 0 ? $cmp : strcmp($a->start_time, $b->start_time);
        });
        
        if ($format === 'csv') {
            $csv = "Date,Time,Trainer,Player,Status,Type,Price,Source\n";
            foreach ($all as $row) {
                $status = $row->source === 'booking' ? ($row->status ?? '') : ($row->session_status ?? '');
                $price = $row->source === 'booking' ? ($row->total_amount ?? 0) : ($row->price ?? 0);
                $csv .= sprintf(
                    "%s,%s,%s,%s,%s,%s,$%.2f,%s\n",
                    $row->session_date,
                    $row->start_time,
                    str_replace(',', '', $row->trainer_name ?? ''),
                    str_replace(',', '', $row->player_name ?? ''),
                    $status,
                    $row->session_type ?? '1on1',
                    $price,
                    $row->source
                );
            }
            
            wp_send_json_success(array(
                'data' => $csv,
                'filename' => 'ptp-schedule-' . $start . '-to-' . $end . '.csv',
                'mime' => 'text/csv',
            ));
        } else {
            // iCal format
            $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//PTP//Schedule//EN\r\n";
            
            foreach ($all as $row) {
                $uid = ($row->source === 'booking' ? 'booking-' : 'session-') . $row->id . '@ptp';
                $dtstart = date('Ymd\THis', strtotime($row->session_date . ' ' . $row->start_time));
                $dtend = date('Ymd\THis', strtotime($row->session_date . ' ' . ($row->end_time ?? date('H:i:s', strtotime($row->start_time) + 3600))));
                
                $ical .= "BEGIN:VEVENT\r\n";
                $ical .= "UID:$uid\r\n";
                $ical .= "DTSTART:$dtstart\r\n";
                $ical .= "DTEND:$dtend\r\n";
                $ical .= "SUMMARY:" . ($row->player_name ?? 'Session') . " - " . ($row->trainer_name ?? 'Trainer') . "\r\n";
                $ical .= "END:VEVENT\r\n";
            }
            
            $ical .= "END:VCALENDAR\r\n";
            
            wp_send_json_success(array(
                'data' => $ical,
                'filename' => 'ptp-schedule-' . $start . '-to-' . $end . '.ics',
                'mime' => 'text/calendar',
            ));
        }
    }
    
    public function ajax_create_session() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        $price = floatval($_POST['price'] ?? 0);
        $platform_fee = floatval(get_option('ptp_platform_fee', 25)) / 100;
        $trainer_payout = $price * (1 - $platform_fee);
        
        $data = array(
            'trainer_id' => intval($_POST['trainer_id']),
            'customer_id' => intval($_POST['customer_id']) ?: null,
            'parent_id' => intval($_POST['parent_id']) ?: null,
            'player_id' => intval($_POST['player_id']) ?: null,
            'player_name' => sanitize_text_field($_POST['player_name']),
            'player_age' => intval($_POST['player_age']) ?: null,
            'session_date' => sanitize_text_field($_POST['session_date']),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration_minutes' => $duration,
            'session_status' => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
            'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
            'session_type' => sanitize_text_field($_POST['session_type'] ?? '1on1'),
            'location_text' => sanitize_text_field($_POST['location_text'] ?? ''),
            'price' => $price,
            'trainer_payout' => $trainer_payout,
            'internal_notes' => sanitize_textarea_field($_POST['internal_notes'] ?? ''),
            'created_by' => get_current_user_id(),
        );
        
        $result = $wpdb->insert("{$wpdb->prefix}ptp_sessions", $data);
        
        if ($result) {
            wp_send_json_success(array('id' => $wpdb->insert_id));
        } else {
            wp_send_json_error('Failed to create session: ' . $wpdb->last_error);
        }
    }
    
    public function ajax_update_session() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $id = sanitize_text_field($_POST['id'] ?? '');
        $source = 'admin';
        
        if (strpos($id, 'booking_') === 0) {
            $source = 'booking';
            $id = intval(str_replace('booking_', '', $id));
        } else {
            $id = intval(str_replace('session_', '', $id));
        }
        
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        if ($source === 'booking') {
            $data = array(
                'trainer_id' => intval($_POST['trainer_id']),
                'session_date' => sanitize_text_field($_POST['session_date']),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'status' => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
                'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
                'location' => sanitize_text_field($_POST['location_text'] ?? ''),
                'notes' => sanitize_textarea_field($_POST['internal_notes'] ?? ''),
            );
            
            $result = $wpdb->update("{$wpdb->prefix}ptp_bookings", $data, array('id' => $id));
        } else {
            $price = floatval($_POST['price'] ?? 0);
            $platform_fee = floatval(get_option('ptp_platform_fee', 25)) / 100;
            $trainer_payout = $price * (1 - $platform_fee);
            
            $data = array(
                'trainer_id' => intval($_POST['trainer_id']),
                'customer_id' => intval($_POST['customer_id']) ?: null,
                'parent_id' => intval($_POST['parent_id']) ?: null,
                'player_id' => intval($_POST['player_id']) ?: null,
                'player_name' => sanitize_text_field($_POST['player_name']),
                'player_age' => intval($_POST['player_age']) ?: null,
                'session_date' => sanitize_text_field($_POST['session_date']),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'session_status' => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
                'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
                'session_type' => sanitize_text_field($_POST['session_type'] ?? '1on1'),
                'location_text' => sanitize_text_field($_POST['location_text'] ?? ''),
                'price' => $price,
                'trainer_payout' => $trainer_payout,
                'internal_notes' => sanitize_textarea_field($_POST['internal_notes'] ?? ''),
            );
            
            $result = $wpdb->update("{$wpdb->prefix}ptp_sessions", $data, array('id' => $id));
        }
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to update: ' . $wpdb->last_error);
        }
    }
    
    public function ajax_delete_session() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $id = sanitize_text_field($_POST['id'] ?? '');
        
        if (strpos($id, 'booking_') === 0) {
            $record_id = intval(str_replace('booking_', '', $id));
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_bookings",
                array('status' => 'cancelled'),
                array('id' => $record_id)
            );
        } else {
            $record_id = intval(str_replace('session_', '', $id));
            $result = $wpdb->delete("{$wpdb->prefix}ptp_sessions", array('id' => $record_id));
        }
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Delete failed');
        }
    }
    
    public function ajax_search_customers() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $q = sanitize_text_field($_GET['q'] ?? '');
        
        if (strlen($q) < 2) {
            wp_send_json_success(array());
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID as user_id, u.display_name, u.user_email, p.id as parent_id
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON u.ID = p.user_id
             WHERE u.display_name LIKE %s OR u.user_email LIKE %s
             LIMIT 10",
            '%' . $wpdb->esc_like($q) . '%',
            '%' . $wpdb->esc_like($q) . '%'
        ));
        
        wp_send_json_success($results);
    }
    
    public function ajax_search_players() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $parent_id = intval($_GET['parent_id'] ?? 0);
        
        if (!$parent_id) {
            wp_send_json_success(array());
        }
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, age FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY name",
            $parent_id
        ));
        
        wp_send_json_success($players);
    }
    
    public function ajax_get_trainer_stats() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $start = sanitize_text_field($_GET['start'] ?? date('Y-m-d'));
        $end = sanitize_text_field($_GET['end'] ?? date('Y-m-d', strtotime('+7 days')));
        
        // Get session counts
        $session_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT trainer_id, COUNT(*) as cnt 
             FROM {$wpdb->prefix}ptp_sessions 
             WHERE session_date BETWEEN %s AND %s AND session_status NOT IN ('cancelled')
             GROUP BY trainer_id",
            $start, $end
        ), OBJECT_K);
        
        // Get booking counts
        $booking_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT trainer_id, COUNT(*) as cnt 
             FROM {$wpdb->prefix}ptp_bookings 
             WHERE session_date BETWEEN %s AND %s AND status NOT IN ('cancelled')
             GROUP BY trainer_id",
            $start, $end
        ), OBJECT_K);
        
        $result = array();
        foreach ($session_counts as $tid => $row) {
            $result[$tid] = intval($row->cnt);
        }
        foreach ($booking_counts as $tid => $row) {
            $result[$tid] = ($result[$tid] ?? 0) + intval($row->cnt);
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_get_trainer_availability() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $trainer_id = intval($_GET['trainer_id'] ?? 0);
        $date = sanitize_text_field($_GET['date'] ?? '');
        
        if (!$trainer_id || !$date) {
            wp_send_json_error('Missing parameters');
        }
        
        // Get trainer's availability settings
        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability 
             WHERE trainer_id = %d AND day_of_week = %d AND is_available = 1",
            $trainer_id,
            date('w', strtotime($date))
        ));
        
        // Get existing sessions/bookings for the day
        $busy_times = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, end_time FROM {$wpdb->prefix}ptp_sessions 
             WHERE trainer_id = %d AND session_date = %s AND session_status NOT IN ('cancelled')
             UNION
             SELECT start_time, end_time FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s AND status NOT IN ('cancelled')",
            $trainer_id, $date, $trainer_id, $date
        ));
        
        wp_send_json_success(array(
            'availability' => $availability,
            'busy' => $busy_times,
        ));
    }
}

// Initialize
PTP_Schedule_Calendar_V2::init();
