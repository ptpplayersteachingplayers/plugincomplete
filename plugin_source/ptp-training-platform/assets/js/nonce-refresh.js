/**
 * PTP Nonce Refresh System
 * 
 * Automatically refreshes WordPress nonces before they expire
 * Prevents "Security check failed" errors on idle pages
 * 
 * @version 72.0.0
 */

(function($) {
    'use strict';

    var PTPNonceRefresh = {
        
        // Current nonce value
        nonce: null,
        
        // Timestamp when nonce was last refreshed
        lastRefresh: null,
        
        // Refresh interval timer
        refreshTimer: null,
        
        // Activity tracking
        lastActivity: null,
        
        // Configuration
        config: {
            refreshInterval: 1800000, // 30 minutes default
            maxAge: 43200000, // 12 hours max
            activityTimeout: 300000, // 5 minutes of inactivity before pausing refresh
            ajaxUrl: null
        },
        
        /**
         * Initialize the nonce refresh system
         */
        init: function() {
            // Get configuration from localized script
            if (typeof ptpNonceRefresh !== 'undefined') {
                this.config.ajaxUrl = ptpNonceRefresh.ajaxUrl;
                this.nonce = ptpNonceRefresh.nonce;
                this.config.refreshInterval = ptpNonceRefresh.refreshInterval || 1800000;
                this.config.maxAge = ptpNonceRefresh.maxAge || 43200000;
            } else {
                console.warn('PTP Nonce Refresh: Configuration not found');
                return;
            }
            
            this.lastRefresh = Date.now();
            this.lastActivity = Date.now();
            
            // Track user activity
            this.bindActivityTracking();
            
            // Start refresh timer
            this.startRefreshTimer();
            
            // Intercept all AJAX calls to use fresh nonce
            this.interceptAjaxCalls();
            
            // Refresh on page visibility change (user returns to tab)
            this.bindVisibilityChange();
            
            console.log('PTP Nonce Refresh: Initialized');
        },
        
        /**
         * Bind activity tracking events
         */
        bindActivityTracking: function() {
            var self = this;
            var activityEvents = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
            
            // Throttled activity update
            var updateActivity = this.throttle(function() {
                self.lastActivity = Date.now();
            }, 10000); // Update at most every 10 seconds
            
            activityEvents.forEach(function(event) {
                document.addEventListener(event, updateActivity, { passive: true });
            });
        },
        
        /**
         * Start the automatic refresh timer
         */
        startRefreshTimer: function() {
            var self = this;
            
            // Clear existing timer
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
            }
            
            // Check every minute if refresh is needed
            this.refreshTimer = setInterval(function() {
                self.checkAndRefresh();
            }, 60000);
        },
        
        /**
         * Check if refresh is needed and perform it
         */
        checkAndRefresh: function() {
            var now = Date.now();
            var timeSinceRefresh = now - this.lastRefresh;
            var timeSinceActivity = now - this.lastActivity;
            
            // Don't refresh if user has been inactive for too long
            if (timeSinceActivity > this.config.activityTimeout) {
                return;
            }
            
            // Refresh if approaching expiration
            if (timeSinceRefresh >= this.config.refreshInterval) {
                this.refreshNonce();
            }
        },
        
        /**
         * Refresh the nonce via AJAX
         */
        refreshNonce: function(callback) {
            var self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_refresh_nonce'
                },
                success: function(response) {
                    if (response.success && response.data.nonce) {
                        self.nonce = response.data.nonce;
                        self.lastRefresh = Date.now();
                        
                        // Update global nonce references
                        self.updateGlobalNonces(response.data.nonce);
                        
                        console.log('PTP Nonce Refresh: Nonce refreshed successfully');
                        
                        if (typeof callback === 'function') {
                            callback(true, response.data.nonce);
                        }
                    } else {
                        console.warn('PTP Nonce Refresh: Failed to refresh nonce');
                        if (typeof callback === 'function') {
                            callback(false);
                        }
                    }
                },
                error: function() {
                    console.error('PTP Nonce Refresh: AJAX error during refresh');
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                }
            });
        },
        
        /**
         * Update all global nonce references
         */
        updateGlobalNonces: function(newNonce) {
            // Update ptpNonceRefresh
            if (typeof ptpNonceRefresh !== 'undefined') {
                ptpNonceRefresh.nonce = newNonce;
            }
            
            // Update ptp_ajax (common in PTP plugin)
            if (typeof ptp_ajax !== 'undefined') {
                ptp_ajax.nonce = newNonce;
            }
            
            // Update ptpAjax
            if (typeof ptpAjax !== 'undefined') {
                ptpAjax.nonce = newNonce;
            }
            
            // Update ptp_training_ajax
            if (typeof ptp_training_ajax !== 'undefined') {
                ptp_training_ajax.nonce = newNonce;
            }
            
            // Update hidden nonce fields in forms
            $('input[name="ptp_nonce"], input[name="nonce"]').each(function() {
                if ($(this).closest('form').find('[data-ptp-form]').length || 
                    $(this).closest('.ptp-').length) {
                    $(this).val(newNonce);
                }
            });
            
            // Trigger custom event for other scripts
            $(document).trigger('ptp_nonce_refreshed', [newNonce]);
        },
        
        /**
         * Intercept AJAX calls to inject fresh nonce
         */
        interceptAjaxCalls: function() {
            var self = this;
            
            // Store original $.ajax
            var originalAjax = $.ajax;
            
            // Override $.ajax
            $.ajax = function(settings) {
                // Only intercept PTP AJAX calls
                if (settings && settings.data && self.isPtpAjax(settings)) {
                    // Ensure nonce is current
                    if (typeof settings.data === 'string') {
                        // Handle URL-encoded string data
                        settings.data = settings.data.replace(
                            /nonce=[^&]*/,
                            'nonce=' + encodeURIComponent(self.nonce)
                        );
                    } else if (typeof settings.data === 'object' && !(settings.data instanceof FormData)) {
                        // Handle object data
                        settings.data.nonce = self.nonce;
                    } else if (settings.data instanceof FormData) {
                        // Handle FormData
                        settings.data.set('nonce', self.nonce);
                    }
                }
                
                return originalAjax.apply(this, arguments);
            };
        },
        
        /**
         * Check if AJAX call is PTP-related
         */
        isPtpAjax: function(settings) {
            if (!settings.data) return false;
            
            var data = settings.data;
            var action = '';
            
            if (typeof data === 'string') {
                var match = data.match(/action=([^&]*)/);
                action = match ? match[1] : '';
            } else if (typeof data === 'object') {
                action = data.action || '';
            }
            
            // Check if it's a PTP action
            return action.indexOf('ptp_') === 0;
        },
        
        /**
         * Handle page visibility changes
         */
        bindVisibilityChange: function() {
            var self = this;
            
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    // User returned to the page
                    var timeSinceRefresh = Date.now() - self.lastRefresh;
                    
                    // If nonce is likely stale, refresh immediately
                    if (timeSinceRefresh >= self.config.refreshInterval) {
                        console.log('PTP Nonce Refresh: Refreshing stale nonce after tab return');
                        self.refreshNonce();
                    }
                    
                    // Update activity timestamp
                    self.lastActivity = Date.now();
                }
            });
        },
        
        /**
         * Get current valid nonce
         */
        getNonce: function() {
            return this.nonce;
        },
        
        /**
         * Force immediate nonce refresh
         */
        forceRefresh: function(callback) {
            this.refreshNonce(callback);
        },
        
        /**
         * Throttle helper function
         */
        throttle: function(func, limit) {
            var inThrottle;
            return function() {
                var args = arguments;
                var context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(function() {
                        inThrottle = false;
                    }, limit);
                }
            };
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        PTPNonceRefresh.init();
    });
    
    // Expose globally for other scripts
    window.PTPNonceRefresh = PTPNonceRefresh;
    
})(jQuery);
