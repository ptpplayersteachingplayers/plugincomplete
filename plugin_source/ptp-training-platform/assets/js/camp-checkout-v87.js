/**
 * PTP Camp Checkout v87 JavaScript
 */
(function($) {
    'use strict';
    
    var siblingCount = 0;
    var isTeam = false;
    
    $(document).ready(function() {
        if ($('#ptp-v87-checkout').length === 0) return;
        
        initTypeSelector();
        initReferralCode();
        initAddSibling();
        initTeamSize();
        initCareOptions();
    });
    
    /**
     * Registration type selector
     */
    function initTypeSelector() {
        $('.ptp-type-card').on('click', function() {
            var $card = $(this);
            var type = $card.data('type');
            
            $('.ptp-type-card').removeClass('active');
            $card.addClass('active');
            $card.find('input').prop('checked', true);
            
            if (type === 'team') {
                isTeam = true;
                $('#ptp-team-box').slideDown(300);
                $('#ptp-sibling-box').slideUp(300);
                $('#ptp_is_team').val('1');
            } else {
                isTeam = false;
                $('#ptp-team-box').slideUp(300);
                $('#ptp-sibling-box').slideDown(300);
                $('#ptp_is_team').val('0');
            }
            
            updateSession();
        });
    }
    
    /**
     * Referral code validation
     */
    function initReferralCode() {
        $('#ptp_apply_referral').on('click', function() {
            var $btn = $(this);
            var code = $('#ptp_referral_input').val().trim().toUpperCase();
            var $msg = $('#ptp_referral_msg');
            
            if (!code) {
                $msg.removeClass('success').addClass('error').text('Please enter a code').show();
                return;
            }
            
            $btn.prop('disabled', true).text('Checking...');
            
            $.ajax({
                url: ptpV87.ajax,
                type: 'POST',
                data: {
                    action: 'ptp_v87_validate_referral',
                    nonce: ptpV87.nonce,
                    code: code
                },
                success: function(res) {
                    $btn.prop('disabled', false).text('Apply');
                    
                    if (res.success) {
                        $msg.removeClass('error').addClass('success').text('✅ ' + res.data.message).show();
                        $('#ptp_referral_code').val(code);
                        $('#ptp_referral_input').prop('readonly', true);
                        $btn.hide();
                        $('body').trigger('update_checkout');
                    } else {
                        $msg.removeClass('success').addClass('error').text('❌ ' + res.data.message).show();
                        $('#ptp_referral_code').val('');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Apply');
                    $msg.removeClass('success').addClass('error').text('Error. Please try again.').show();
                }
            });
        });
        
        $('#ptp_referral_input').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#ptp_apply_referral').click();
            }
        });
    }
    
    /**
     * Add sibling functionality
     */
    function initAddSibling() {
        $('#ptp_add_sibling').on('click', function() {
            siblingCount++;
            
            var html = getSiblingHTML(siblingCount);
            $('#ptp-siblings-container').append(html);
            
            updateCamperCount();
            bindRemoveButtons();
            
            // Scroll to new section
            $('html, body').animate({
                scrollTop: $('.ptp-sibling-section').last().offset().top - 100
            }, 400);
        });
    }
    
    /**
     * Team size change handler
     */
    function initTeamSize() {
        $('#ptp_team_size').on('change', function() {
            updateSession();
            $('body').trigger('update_checkout');
        });
    }
    
    /**
     * Bind remove buttons for siblings
     */
    function bindRemoveButtons() {
        $('.ptp-remove-btn').off('click').on('click', function() {
            var $section = $(this).closest('.ptp-sibling-section');
            $section.slideUp(300, function() {
                $section.remove();
                renumberSiblings();
                updateCamperCount();
            });
        });
    }
    
    /**
     * Renumber siblings after removal
     */
    function renumberSiblings() {
        $('.ptp-sibling-section').each(function(i) {
            var num = i + 2;
            $(this).find('h4 span').first().text('⚽ Camper #' + num);
            $(this).find('input, select').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/\[\d+\]/, '[' + i + ']'));
                }
            });
        });
    }
    
    /**
     * Update camper count and savings display
     */
    function updateCamperCount() {
        var count = 1 + $('.ptp-sibling-section').length;
        $('#ptp_camper_count').val(count);
        
        var savings = (count - 1) * ptpV87.sibling_discount;
        
        if (savings > 0) {
            $('#ptp-savings-amount').text(savings);
            $('#ptp-savings-banner').slideDown(200);
        } else {
            $('#ptp-savings-banner').slideUp(200);
        }
        
        updateSession();
        $('body').trigger('update_checkout');
    }
    
    /**
     * Update session via AJAX
     */
    function updateSession() {
        var camperCount = 1 + $('.ptp-sibling-section').length;
        var teamSize = parseInt($('#ptp_team_size').val()) || 0;
        
        $.ajax({
            url: ptpV87.ajax,
            type: 'POST',
            data: {
                action: 'ptp_v87_update_session',
                nonce: ptpV87.nonce,
                camper_count: camperCount,
                is_team: isTeam ? 'true' : 'false',
                team_size: teamSize
            }
        });
    }
    
    /**
     * Generate sibling HTML
     */
    function getSiblingHTML(index) {
        var num = index + 1;
        return '<div class="ptp-sibling-section">' +
            '<button type="button" class="ptp-remove-btn">✕ Remove</button>' +
            '<h4><span>⚽ Camper #' + num + '</span> <span class="ptp-sibling-badge">-$' + ptpV87.sibling_discount + '</span></h4>' +
            '<div class="ptp-row">' +
                '<div class="ptp-col"><label>First Name *</label><input type="text" name="ptp_sibling[' + (index-1) + '][first_name]" required></div>' +
                '<div class="ptp-col"><label>Last Name *</label><input type="text" name="ptp_sibling[' + (index-1) + '][last_name]" required></div>' +
            '</div>' +
            '<div class="ptp-row">' +
                '<div class="ptp-col"><label>Age *</label><input type="number" name="ptp_sibling[' + (index-1) + '][age]" min="4" max="18" required></div>' +
                '<div class="ptp-col"><label>Shirt Size *</label>' +
                    '<select name="ptp_sibling[' + (index-1) + '][shirt_size]" required>' +
                        '<option value="">Select...</option>' +
                        '<option value="Youth Small">Youth Small</option>' +
                        '<option value="Youth Medium">Youth Medium</option>' +
                        '<option value="Youth Large">Youth Large</option>' +
                        '<option value="Adult Small">Adult Small</option>' +
                        '<option value="Adult Medium">Adult Medium</option>' +
                        '<option value="Adult Large">Adult Large</option>' +
                        '<option value="Adult XL">Adult XL</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
            '<div class="ptp-row">' +
                '<div class="ptp-col"><label>Skill Level</label>' +
                    '<select name="ptp_sibling[' + (index-1) + '][level]">' +
                        '<option value="">Select...</option>' +
                        '<option value="Beginner">Beginner</option>' +
                        '<option value="Intermediate">Intermediate</option>' +
                        '<option value="Advanced">Advanced</option>' +
                        '<option value="Elite">Elite</option>' +
                    '</select>' +
                '</div>' +
                '<div class="ptp-col"><label>Medical/Allergies</label><input type="text" name="ptp_sibling[' + (index-1) + '][medical]" placeholder="Leave blank if none"></div>' +
            '</div>' +
        '</div>';
    }
    
    /**
     * Before/After care options
     */
    function initCareOptions() {
        var $beforeCare = $('#ptp_before_care');
        var $afterCare = $('#ptp_after_care');
        var $bundle = $('#ptp-care-bundle');
        
        function updateCareBundle() {
            var beforeChecked = $beforeCare.is(':checked');
            var afterChecked = $afterCare.is(':checked');
            
            if (beforeChecked && afterChecked) {
                $bundle.slideDown(200);
            } else {
                $bundle.slideUp(200);
            }
            
            // Update session and trigger checkout refresh
            updateCareSession();
            $('body').trigger('update_checkout');
        }
        
        $beforeCare.on('change', updateCareBundle);
        $afterCare.on('change', updateCareBundle);
    }
    
    /**
     * Update care options in session
     */
    function updateCareSession() {
        var beforeCare = $('#ptp_before_care').is(':checked') ? 1 : 0;
        var afterCare = $('#ptp_after_care').is(':checked') ? 1 : 0;
        
        $.ajax({
            url: ptpV87.ajax,
            type: 'POST',
            data: {
                action: 'ptp_v87_update_care',
                nonce: ptpV87.nonce,
                before_care: beforeCare,
                after_care: afterCare
            }
        });
    }
    
})(jQuery);
