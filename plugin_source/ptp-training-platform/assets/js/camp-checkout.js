/**
 * PTP Camp Checkout - Stripe-direct checkout without WooCommerce
 * @version 146.0.0
 */

(function($) {
    'use strict';
    
    // State
    let selectedCamps = [];
    let camperData = [];
    let currentStep = 1;
    let referralCode = '';
    let referralValid = false;
    let addonsSelected = {
        care_bundle: false,
        jersey: false
    };
    
    // Config from localized script
    const config = window.ptpCampCheckout || {};
    
    // Initialize
    $(document).ready(function() {
        initCampSelection();
        initStepNavigation();
        initReferralCode();
        initAddons();
        initPayment();
    });
    
    /**
     * Initialize camp selection
     */
    function initCampSelection() {
        // Camp card click
        $(document).on('click', '.camp-card:not(.selected) .btn-select-camp:not(:disabled)', function(e) {
            e.stopPropagation();
            const card = $(this).closest('.camp-card');
            selectCamp(card);
        });
        
        // Remove from selection
        $(document).on('click', '.selected-camp-item .remove-btn', function() {
            const index = $(this).closest('.selected-camp-item').data('index');
            removeCamp(index);
        });
        
        // Add another camper button
        $('#btn-add-camper').on('click', function() {
            // Scroll to camps grid
            $('html, body').animate({
                scrollTop: $('#camps-grid').offset().top - 100
            }, 500);
        });
    }
    
    /**
     * Select a camp
     */
    function selectCamp(card) {
        const campData = {
            stripe_product_id: card.data('product-id'),
            stripe_price_id: card.data('price-id'),
            base_price: parseFloat(card.data('price')),
            camp_name: card.find('.camp-name').text(),
            camp_dates: card.find('.camp-dates').text().trim(),
            camp_location: card.find('.camp-location').text().trim(),
            camp_time: card.find('.camp-time').text().trim()
        };
        
        selectedCamps.push(campData);
        camperData.push({}); // Initialize empty camper data
        
        updateSelectedCampsUI();
        
        // Show selected camps section
        $('#selected-camps').show();
    }
    
    /**
     * Remove a camp
     */
    function removeCamp(index) {
        selectedCamps.splice(index, 1);
        camperData.splice(index, 1);
        
        updateSelectedCampsUI();
        
        if (selectedCamps.length === 0) {
            $('#selected-camps').hide();
        }
    }
    
    /**
     * Update selected camps UI
     */
    function updateSelectedCampsUI() {
        const list = $('.selected-camps-list');
        list.empty();
        
        selectedCamps.forEach((camp, index) => {
            const isSibling = index > 0;
            const discount = isSibling ? camp.base_price * (config.siblingDiscount / 100) : 0;
            const finalPrice = camp.base_price - discount;
            
            list.append(`
                <div class="selected-camp-item" data-index="${index}">
                    <div class="camp-info">
                        <strong>${camp.camp_name}</strong>
                        <span>${camp.camp_dates || ''}</span>
                        ${isSibling ? '<span class="sibling-badge">Sibling Discount: -$' + discount.toFixed(0) + '</span>' : ''}
                    </div>
                    <div class="camp-price">$${finalPrice.toFixed(0)}</div>
                    <button type="button" class="remove-btn" title="Remove">×</button>
                </div>
            `);
        });
    }
    
    /**
     * Initialize step navigation
     */
    function initStepNavigation() {
        // Continue from step 1
        $('#btn-continue-step1').on('click', function() {
            if (selectedCamps.length === 0) {
                alert('Please select at least one camp.');
                return;
            }
            goToStep(2);
            renderCamperForms();
        });
        
        // Back from step 2
        $('#btn-back-step2').on('click', function() {
            saveCamperData();
            goToStep(1);
        });
        
        // Continue from step 2
        $('#btn-continue-step2').on('click', function() {
            if (!validateStep2()) {
                return;
            }
            saveCamperData();
            goToStep(3);
            renderOrderSummary();
        });
        
        // Back from step 3
        $('#btn-back-step3').on('click', function() {
            goToStep(2);
        });
    }
    
    /**
     * Go to step
     */
    function goToStep(step) {
        currentStep = step;
        
        // Update progress
        $('.progress-step').removeClass('active completed');
        for (let i = 1; i <= 3; i++) {
            const stepEl = $(`.progress-step[data-step="${i}"]`);
            if (i < step) {
                stepEl.addClass('completed');
            } else if (i === step) {
                stepEl.addClass('active');
            }
        }
        
        // Show/hide steps
        $('.checkout-step').hide();
        $(`#step-${step}`).show();
        
        // Scroll to top
        $('html, body').animate({ scrollTop: 0 }, 300);
    }
    
    /**
     * Render camper forms
     */
    function renderCamperForms() {
        const container = $('#campers-forms');
        container.empty();
        
        const template = $('#camper-form-template').html();
        
        selectedCamps.forEach((camp, index) => {
            const form = $(template);
            form.attr('data-camper-index', index);
            form.find('.camper-number').text(index + 1);
            form.find('.camp-name-badge').text(camp.camp_name);
            
            // Pre-fill if we have data
            if (camperData[index]) {
                const data = camperData[index];
                form.find('.camper-first-name').val(data.camper_first_name || '');
                form.find('.camper-last-name').val(data.camper_last_name || '');
                form.find('.camper-dob').val(data.camper_dob || '');
                form.find('.camper-gender').val(data.camper_gender || '');
                form.find('.camper-shirt-size').val(data.camper_shirt_size || '');
                form.find('.camper-skill-level').val(data.camper_skill_level || '');
                form.find('.camper-team').val(data.camper_team || '');
                form.find('.camper-position').val(data.camper_position || '');
                form.find('.camper-medical').val(data.medical_conditions || '');
            }
            
            // Hide remove button for first camper
            if (index === 0 && selectedCamps.length === 1) {
                form.find('.btn-remove-camper').hide();
            }
            
            container.append(form);
        });
        
        // Remove camper handler
        $(document).off('click', '.camper-form .btn-remove-camper').on('click', '.camper-form .btn-remove-camper', function() {
            const form = $(this).closest('.camper-form');
            const index = parseInt(form.data('camper-index'));
            
            selectedCamps.splice(index, 1);
            camperData.splice(index, 1);
            
            if (selectedCamps.length === 0) {
                goToStep(1);
                $('#selected-camps').hide();
            } else {
                renderCamperForms();
            }
            
            updateSelectedCampsUI();
        });
    }
    
    /**
     * Save camper data from forms
     */
    function saveCamperData() {
        $('.camper-form').each(function() {
            const index = parseInt($(this).data('camper-index'));
            const dob = $(this).find('.camper-dob').val();
            
            camperData[index] = {
                ...selectedCamps[index],
                camper_first_name: $(this).find('.camper-first-name').val(),
                camper_last_name: $(this).find('.camper-last-name').val(),
                camper_dob: dob,
                camper_age: dob ? calculateAge(dob) : null,
                camper_gender: $(this).find('.camper-gender').val(),
                camper_shirt_size: $(this).find('.camper-shirt-size').val(),
                camper_skill_level: $(this).find('.camper-skill-level').val(),
                camper_team: $(this).find('.camper-team').val(),
                camper_position: $(this).find('.camper-position').val(),
                medical_conditions: $(this).find('.camper-medical').val()
            };
        });
    }
    
    /**
     * Calculate age from DOB
     */
    function calculateAge(dob) {
        const today = new Date();
        const birthDate = new Date(dob);
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    }
    
    /**
     * Validate step 2
     */
    function validateStep2() {
        let valid = true;
        let firstError = null;
        
        // Validate camper forms
        $('.camper-form').each(function() {
            const firstName = $(this).find('.camper-first-name');
            const lastName = $(this).find('.camper-last-name');
            const dob = $(this).find('.camper-dob');
            const shirtSize = $(this).find('.camper-shirt-size');
            
            [firstName, lastName, dob, shirtSize].forEach(field => {
                if (!field.val()) {
                    field.css('border-color', '#ef4444');
                    valid = false;
                    if (!firstError) firstError = field;
                } else {
                    field.css('border-color', '');
                }
            });
        });
        
        // Validate parent info
        const parentFields = ['#billing_first_name', '#billing_last_name', '#billing_email', '#billing_phone', 
                             '#emergency_name', '#emergency_phone', '#emergency_relation'];
        
        parentFields.forEach(selector => {
            const field = $(selector);
            if (!field.val()) {
                field.css('border-color', '#ef4444');
                valid = false;
                if (!firstError) firstError = field;
            } else {
                field.css('border-color', '');
            }
        });
        
        // Validate waiver
        if (!$('#waiver_agree').is(':checked')) {
            $('#waiver_agree').closest('label').css('color', '#ef4444');
            valid = false;
        } else {
            $('#waiver_agree').closest('label').css('color', '');
        }
        
        if (!valid && firstError) {
            firstError.focus();
            $('html, body').animate({
                scrollTop: firstError.offset().top - 100
            }, 500);
        }
        
        return valid;
    }
    
    /**
     * Initialize referral code
     */
    function initReferralCode() {
        $('#btn-apply-referral').on('click', function() {
            const code = $('#referral_code').val().trim().toUpperCase();
            
            if (!code) {
                showReferralMessage('Please enter a code', 'error');
                return;
            }
            
            $(this).text('Checking...').prop('disabled', true);
            
            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'ptp_apply_camp_referral',
                    nonce: config.nonce,
                    code: code
                },
                success: function(response) {
                    if (response.success) {
                        referralCode = code;
                        referralValid = true;
                        showReferralMessage(response.data.message, 'success');
                        updateTotals();
                    } else {
                        referralCode = '';
                        referralValid = false;
                        showReferralMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    showReferralMessage('Failed to verify code. Please try again.', 'error');
                },
                complete: function() {
                    $('#btn-apply-referral').text('Apply').prop('disabled', false);
                }
            });
        });
    }
    
    /**
     * Show referral message
     */
    function showReferralMessage(message, type) {
        const el = $('#referral-message');
        el.removeClass('success error').addClass(type).text(message);
    }
    
    /**
     * Initialize add-ons
     */
    function initAddons() {
        $('#addon_care_bundle').on('change', function() {
            addonsSelected.care_bundle = $(this).is(':checked');
            updateTotals();
        });
        
        $('#addon_jersey').on('change', function() {
            addonsSelected.jersey = $(this).is(':checked');
            updateTotals();
        });
    }
    
    /**
     * Render order summary
     */
    function renderOrderSummary() {
        const summaryItems = $('#summary-items');
        summaryItems.empty();
        
        camperData.forEach((camper, index) => {
            const isSibling = index > 0;
            const discount = isSibling ? camper.base_price * (config.siblingDiscount / 100) : 0;
            const finalPrice = camper.base_price - discount;
            
            summaryItems.append(`
                <div class="summary-item">
                    <div>
                        <strong>${camper.camper_first_name} ${camper.camper_last_name}</strong>
                        <div style="font-size: 14px; color: #6b7280;">${camper.camp_name}</div>
                        ${isSibling ? '<div style="font-size: 13px; color: #10b981;">Sibling discount applied</div>' : ''}
                    </div>
                    <span>$${finalPrice.toFixed(0)}</span>
                </div>
            `);
        });
        
        updateTotals();
    }
    
    /**
     * Update totals
     */
    function updateTotals() {
        // Calculate subtotal
        let subtotal = 0;
        let discountAmount = 0;
        
        camperData.forEach((camper, index) => {
            subtotal += camper.base_price;
            if (index > 0) {
                discountAmount += camper.base_price * (config.siblingDiscount / 100);
            }
        });
        
        // Referral discount
        if (referralValid) {
            discountAmount += config.referralDiscount;
        }
        
        // Add-ons
        let careTotal = 0;
        let jerseyTotal = 0;
        
        if (addonsSelected.care_bundle) {
            careTotal = config.careBundle * camperData.length;
        }
        
        if (addonsSelected.jersey) {
            jerseyTotal = config.jerseyPrice * camperData.length;
        }
        
        // Calculate totals
        const discountedSubtotal = Math.max(0, subtotal - discountAmount);
        const totalBeforeFees = discountedSubtotal + careTotal + jerseyTotal;
        const processingFee = (totalBeforeFees * config.processingRate) + config.processingFlat;
        const total = totalBeforeFees + processingFee;
        
        // Update UI
        $('#subtotal').text('$' + subtotal.toFixed(0));
        
        if (discountAmount > 0) {
            $('#discount-row').show();
            $('#discount-amount').text('-$' + discountAmount.toFixed(0));
        } else {
            $('#discount-row').hide();
        }
        
        if (careTotal > 0) {
            $('#care-row').show();
            $('#care-amount').text('$' + careTotal.toFixed(0));
        } else {
            $('#care-row').hide();
        }
        
        if (jerseyTotal > 0) {
            $('#jersey-row').show();
            $('#jersey-amount').text('$' + jerseyTotal.toFixed(0));
        } else {
            $('#jersey-row').hide();
        }
        
        $('#processing-fee').text('$' + processingFee.toFixed(2));
        $('#total-amount').text('$' + total.toFixed(2));
    }
    
    /**
     * Initialize payment
     */
    function initPayment() {
        $('#btn-pay').on('click', function() {
            processPayment();
        });
    }
    
    /**
     * Process payment
     */
    function processPayment() {
        // Show loading
        $('#checkout-loading').show();
        $('#btn-pay').prop('disabled', true);
        
        // Prepare order data
        const items = camperData.map((camper, index) => ({
            ...camper,
            care_bundle: addonsSelected.care_bundle,
            jersey: addonsSelected.jersey,
            waiver_signed: true,
            photo_release: $('#photo_release').is(':checked') ? 1 : 0
        }));
        
        const orderData = {
            items: items,
            billing_first_name: $('#billing_first_name').val(),
            billing_last_name: $('#billing_last_name').val(),
            billing_email: $('#billing_email').val(),
            billing_phone: $('#billing_phone').val(),
            emergency_name: $('#emergency_name').val(),
            emergency_phone: $('#emergency_phone').val(),
            emergency_relation: $('#emergency_relation').val(),
            referral_code: referralValid ? referralCode : ''
        };
        
        // Submit to server
        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ptp_camp_checkout',
                nonce: config.nonce,
                data: JSON.stringify(orderData)
            },
            success: function(response) {
                if (response.success) {
                    // Redirect to Stripe Checkout
                    window.location.href = response.data.checkout_url;
                } else {
                    $('#checkout-loading').hide();
                    $('#btn-pay').prop('disabled', false);
                    alert(response.data.message || 'Failed to create checkout. Please try again.');
                }
            },
            error: function() {
                $('#checkout-loading').hide();
                $('#btn-pay').prop('disabled', false);
                alert('An error occurred. Please try again.');
            }
        });
    }
    
})(jQuery);
