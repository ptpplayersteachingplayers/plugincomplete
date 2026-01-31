/**
 * PTP UI v88
 * ==========
 * Global UI utilities and interactions
 * 
 * Features:
 * - Toast notifications
 * - Modals & bottom sheets
 * - Dropdown management
 * - Smooth scroll
 * - Form validation
 * - Loading states
 */

(function() {
    'use strict';
    
    // ===========================================
    // TOAST NOTIFICATIONS
    // ===========================================
    
    window.PTPToast = {
        container: null,
        queue: [],
        
        init: function() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'ptp-toast-container';
                document.body.appendChild(this.container);
            }
        },
        
        show: function(message, type, duration) {
            this.init();
            
            type = type || 'info';
            duration = duration || 3500;
            
            var toast = document.createElement('div');
            toast.className = 'ptp-toast ' + type;
            
            var icons = {
                success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>',
                error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 6L6 18M6 6l12 12"/></svg>',
                warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 9v4m0 4h.01"/></svg>',
                info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4m0-4h.01"/></svg>'
            };
            
            toast.innerHTML = 
                '<span class="ptp-toast-icon">' + (icons[type] || icons.info) + '</span>' +
                '<span class="ptp-toast-message">' + message + '</span>' +
                '<button class="ptp-toast-close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>';
            
            this.container.appendChild(toast);
            
            // Close button
            var closeBtn = toast.querySelector('.ptp-toast-close');
            closeBtn.addEventListener('click', function() {
                PTPToast.dismiss(toast);
            });
            
            // Auto dismiss
            if (duration > 0) {
                setTimeout(function() {
                    PTPToast.dismiss(toast);
                }, duration);
            }
            
            return toast;
        },
        
        dismiss: function(toast) {
            if (!toast || toast.classList.contains('removing')) return;
            
            toast.classList.add('removing');
            
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 250);
        },
        
        success: function(message, duration) {
            return this.show(message, 'success', duration);
        },
        
        error: function(message, duration) {
            return this.show(message, 'error', duration);
        },
        
        warning: function(message, duration) {
            return this.show(message, 'warning', duration);
        },
        
        info: function(message, duration) {
            return this.show(message, 'info', duration);
        }
    };
    
    // ===========================================
    // MODALS
    // ===========================================
    
    window.PTPModal = {
        current: null,
        
        open: function(modalId) {
            var overlay = document.getElementById(modalId);
            if (!overlay) return;
            
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
            this.current = overlay;
            
            // Focus trap
            var focusable = overlay.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusable.length) {
                focusable[0].focus();
            }
            
            // ESC to close
            document.addEventListener('keydown', this.handleEsc);
        },
        
        close: function(modalId) {
            var overlay = modalId ? document.getElementById(modalId) : this.current;
            if (!overlay) return;
            
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            this.current = null;
            
            document.removeEventListener('keydown', this.handleEsc);
        },
        
        handleEsc: function(e) {
            if (e.key === 'Escape' && PTPModal.current) {
                PTPModal.close();
            }
        },
        
        init: function() {
            // Close on overlay click
            document.querySelectorAll('.ptp-modal-overlay').forEach(function(overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        PTPModal.close(overlay.id);
                    }
                });
            });
            
            // Close buttons
            document.querySelectorAll('[data-modal-close]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    PTPModal.close();
                });
            });
            
            // Open triggers
            document.querySelectorAll('[data-modal-open]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var modalId = this.getAttribute('data-modal-open');
                    PTPModal.open(modalId);
                });
            });
        }
    };
    
    // ===========================================
    // BOTTOM SHEETS
    // ===========================================
    
    window.PTPBottomSheet = {
        current: null,
        startY: 0,
        currentY: 0,
        
        open: function(sheetId) {
            var overlay = document.getElementById(sheetId);
            if (!overlay) return;
            
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
            this.current = overlay;
            
            // Touch handling for drag to dismiss
            var sheet = overlay.querySelector('.ptp-bottom-sheet');
            if (sheet) {
                sheet.addEventListener('touchstart', this.handleTouchStart.bind(this));
                sheet.addEventListener('touchmove', this.handleTouchMove.bind(this));
                sheet.addEventListener('touchend', this.handleTouchEnd.bind(this));
            }
        },
        
        close: function(sheetId) {
            var overlay = sheetId ? document.getElementById(sheetId) : this.current;
            if (!overlay) return;
            
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            this.current = null;
        },
        
        handleTouchStart: function(e) {
            this.startY = e.touches[0].clientY;
        },
        
        handleTouchMove: function(e) {
            this.currentY = e.touches[0].clientY;
            var diff = this.currentY - this.startY;
            
            if (diff > 0) {
                var sheet = this.current.querySelector('.ptp-bottom-sheet');
                sheet.style.transform = 'translateY(' + diff + 'px)';
            }
        },
        
        handleTouchEnd: function(e) {
            var diff = this.currentY - this.startY;
            var sheet = this.current.querySelector('.ptp-bottom-sheet');
            
            if (diff > 100) {
                this.close();
            }
            
            sheet.style.transform = '';
        },
        
        init: function() {
            // Close on overlay click
            document.querySelectorAll('.ptp-bottom-sheet-overlay').forEach(function(overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        PTPBottomSheet.close(overlay.id);
                    }
                });
            });
            
            // Open triggers
            document.querySelectorAll('[data-sheet-open]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var sheetId = this.getAttribute('data-sheet-open');
                    PTPBottomSheet.open(sheetId);
                });
            });
            
            // Close triggers
            document.querySelectorAll('[data-sheet-close]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    PTPBottomSheet.close();
                });
            });
        }
    };
    
    // ===========================================
    // TABS
    // ===========================================
    
    window.PTPTabs = {
        init: function() {
            document.querySelectorAll('.ptp-tabs').forEach(function(tabsContainer) {
                var tabs = tabsContainer.querySelectorAll('.ptp-tab');
                var contentId = tabsContainer.getAttribute('data-tabs-for');
                
                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        // Remove active from all
                        tabs.forEach(function(t) { t.classList.remove('active'); });
                        tab.classList.add('active');
                        
                        // Show content
                        var targetId = tab.getAttribute('data-tab');
                        if (contentId) {
                            var container = document.getElementById(contentId);
                            if (container) {
                                container.querySelectorAll('.ptp-tab-content').forEach(function(content) {
                                    content.classList.remove('active');
                                });
                                var targetContent = container.querySelector('[data-tab-content="' + targetId + '"]');
                                if (targetContent) {
                                    targetContent.classList.add('active');
                                }
                            }
                        }
                    });
                });
            });
        }
    };
    
    // ===========================================
    // DROPDOWNS
    // ===========================================
    
    window.PTPDropdown = {
        init: function() {
            document.querySelectorAll('.ptp-dropdown').forEach(function(dropdown) {
                var trigger = dropdown.querySelector('[data-dropdown-trigger]');
                if (!trigger) return;
                
                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    
                    // Close other dropdowns
                    document.querySelectorAll('.ptp-dropdown.open').forEach(function(d) {
                        if (d !== dropdown) d.classList.remove('open');
                    });
                    
                    dropdown.classList.toggle('open');
                });
            });
            
            // Close on outside click
            document.addEventListener('click', function() {
                document.querySelectorAll('.ptp-dropdown.open').forEach(function(d) {
                    d.classList.remove('open');
                });
            });
        }
    };
    
    // ===========================================
    // COPY TO CLIPBOARD
    // ===========================================
    
    window.PTPCopy = {
        exec: function(text, successMsg) {
            successMsg = successMsg || 'Copied!';
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    PTPToast.success(successMsg);
                }).catch(function() {
                    PTPCopy.fallback(text, successMsg);
                });
            } else {
                PTPCopy.fallback(text, successMsg);
            }
        },
        
        fallback: function(text, successMsg) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            
            try {
                document.execCommand('copy');
                PTPToast.success(successMsg);
            } catch (err) {
                PTPToast.error('Copy failed');
            }
            
            document.body.removeChild(textarea);
        },
        
        init: function() {
            document.querySelectorAll('[data-copy]').forEach(function(el) {
                el.addEventListener('click', function() {
                    var text = this.getAttribute('data-copy');
                    var msg = this.getAttribute('data-copy-msg');
                    PTPCopy.exec(text, msg);
                });
            });
            
            document.querySelectorAll('[data-copy-input]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inputId = this.getAttribute('data-copy-input');
                    var input = document.getElementById(inputId);
                    if (input) {
                        PTPCopy.exec(input.value);
                    }
                });
            });
        }
    };
    
    // ===========================================
    // SMOOTH SCROLL
    // ===========================================
    
    window.PTPScroll = {
        to: function(target, offset) {
            offset = offset || 80;
            var element = typeof target === 'string' ? document.querySelector(target) : target;
            
            if (element) {
                var top = element.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        },
        
        init: function() {
            document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
                anchor.addEventListener('click', function(e) {
                    var href = this.getAttribute('href');
                    if (href.length > 1) {
                        e.preventDefault();
                        PTPScroll.to(href);
                    }
                });
            });
        }
    };
    
    // ===========================================
    // LOADING STATES
    // ===========================================
    
    window.PTPLoading = {
        show: function(container) {
            container = typeof container === 'string' ? document.querySelector(container) : container;
            if (!container) return;
            
            container.style.position = 'relative';
            
            var overlay = document.createElement('div');
            overlay.className = 'ptp-loading-overlay';
            overlay.innerHTML = '<div class="ptp-spinner ptp-spinner-lg"></div>';
            
            container.appendChild(overlay);
        },
        
        hide: function(container) {
            container = typeof container === 'string' ? document.querySelector(container) : container;
            if (!container) return;
            
            var overlay = container.querySelector('.ptp-loading-overlay');
            if (overlay) {
                overlay.remove();
            }
        },
        
        button: function(btn, loading) {
            btn = typeof btn === 'string' ? document.querySelector(btn) : btn;
            if (!btn) return;
            
            if (loading) {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = '<span class="ptp-spinner ptp-spinner-sm"></span>';
            } else {
                btn.disabled = false;
                if (btn.dataset.originalText) {
                    btn.innerHTML = btn.dataset.originalText;
                }
            }
        }
    };
    
    // ===========================================
    // FAVORITE TOGGLE
    // ===========================================
    
    window.PTPFavorite = {
        toggle: function(btn, trainerId) {
            btn.classList.toggle('active');
            
            var isActive = btn.classList.contains('active');
            
            // Send to server (implement your own endpoint)
            if (typeof jQuery !== 'undefined' && typeof ptp_ajax !== 'undefined') {
                jQuery.ajax({
                    url: ptp_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ptp_toggle_favorite',
                        trainer_id: trainerId,
                        favorite: isActive ? 1 : 0,
                        nonce: ptp_ajax.nonce
                    }
                });
            }
            
            PTPToast.success(isActive ? 'Added to favorites!' : 'Removed from favorites');
        },
        
        init: function() {
            document.querySelectorAll('.ptp-trainer-card-favorite').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var trainerId = this.getAttribute('data-trainer-id');
                    PTPFavorite.toggle(this, trainerId);
                });
            });
        }
    };
    
    // ===========================================
    // RATING INPUT
    // ===========================================
    
    window.PTPRating = {
        init: function() {
            document.querySelectorAll('.ptp-rating-input').forEach(function(container) {
                var buttons = container.querySelectorAll('button');
                var input = container.querySelector('input[type="hidden"]');
                
                buttons.forEach(function(btn, index) {
                    btn.addEventListener('click', function() {
                        var rating = index + 1;
                        
                        // Update input
                        if (input) input.value = rating;
                        
                        // Update UI
                        buttons.forEach(function(b, i) {
                            b.classList.toggle('active', i <= index);
                        });
                    });
                    
                    btn.addEventListener('mouseenter', function() {
                        buttons.forEach(function(b, i) {
                            b.querySelector('svg').style.fill = i <= index ? 'var(--ptp-gold)' : 'var(--ptp-gray-200)';
                        });
                    });
                    
                    btn.addEventListener('mouseleave', function() {
                        var currentRating = input ? parseInt(input.value) || 0 : 0;
                        buttons.forEach(function(b, i) {
                            b.querySelector('svg').style.fill = i < currentRating ? 'var(--ptp-gold)' : 'var(--ptp-gray-200)';
                        });
                    });
                });
            });
        }
    };
    
    // ===========================================
    // FORM VALIDATION
    // ===========================================
    
    window.PTPForm = {
        validate: function(form) {
            form = typeof form === 'string' ? document.querySelector(form) : form;
            if (!form) return false;
            
            var isValid = true;
            
            // Clear previous errors
            form.querySelectorAll('.ptp-input-error').forEach(function(el) {
                el.classList.remove('ptp-input-error');
            });
            form.querySelectorAll('.ptp-error-text').forEach(function(el) {
                el.remove();
            });
            
            // Check required fields
            form.querySelectorAll('[required]').forEach(function(input) {
                if (!input.value.trim()) {
                    isValid = false;
                    PTPForm.showError(input, 'This field is required');
                }
            });
            
            // Check email fields
            form.querySelectorAll('input[type="email"]').forEach(function(input) {
                if (input.value && !PTPForm.isValidEmail(input.value)) {
                    isValid = false;
                    PTPForm.showError(input, 'Please enter a valid email');
                }
            });
            
            return isValid;
        },
        
        showError: function(input, message) {
            input.classList.add('ptp-input-error');
            
            var error = document.createElement('div');
            error.className = 'ptp-error-text';
            error.textContent = message;
            
            input.parentNode.appendChild(error);
        },
        
        isValidEmail: function(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },
        
        init: function() {
            document.querySelectorAll('form[data-validate]').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    if (!PTPForm.validate(form)) {
                        e.preventDefault();
                        PTPToast.error('Please fix the errors above');
                    }
                });
            });
        }
    };
    
    // ===========================================
    // INTERSECTION OBSERVER (Animations)
    // ===========================================
    
    window.PTPAnimate = {
        init: function() {
            if (!('IntersectionObserver' in window)) return;
            
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('ptp-animated');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
            
            document.querySelectorAll('[data-animate]').forEach(function(el) {
                observer.observe(el);
            });
        }
    };
    
    // ===========================================
    // INITIALIZATION
    // ===========================================
    
    function init() {
        PTPModal.init();
        PTPBottomSheet.init();
        PTPTabs.init();
        PTPDropdown.init();
        PTPCopy.init();
        PTPScroll.init();
        PTPFavorite.init();
        PTPRating.init();
        PTPForm.init();
        PTPAnimate.init();
    }
    
    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
