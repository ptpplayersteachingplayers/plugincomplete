# PTP Training Platform v86 - Clean Camp Checkout

**Release Date**: January 2025

## Overview

This release provides a clean, streamlined camp checkout experience with proper waiver validation and improved email formatting.

## Features

### 1. Clean Checkout Fields
- Reorganized checkout field layout for better UX
- Clear separation between camper info, parent info, and emergency contact
- Improved field validation messages

### 2. Waiver Validation
- Proper waiver checkbox with clear legal language
- Validation ensures waiver must be accepted before checkout
- Waiver acceptance stored in order meta

### 3. Email Formatting
- Clean confirmation email templates
- Proper formatting of camper details
- Emergency contact info included in order emails

### 4. Processing Fee Display
- Note: Processing fee calculation moved to v87
- v86 prepares the groundwork for transparent fee display

## Files Changed

### Added
- `includes/class-ptp-checkout-fields-v86.php` - New checkout fields class

### Modified
- `ptp-training-platform.php` - Updated to include v86 checkout class
- WooCommerce email templates updated for camp orders

## Technical Notes

- Works alongside WooCommerce checkout
- Hooks into `woocommerce_before_order_notes` for field placement
- Uses WooCommerce session for temporary data storage
- Order meta keys prefixed with `_ptp_` for namespace safety
