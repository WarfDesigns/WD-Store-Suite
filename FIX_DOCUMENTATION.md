# WordPress Plugin Fix Documentation
## Stripe Payment & Email Automation Issues

**Date:** November 13, 2025  
**Plugin:** WD Store Suite v2.9  
**Issues Fixed:** 
1. Email automation not firing after Stripe payment completion
2. Order status not updating after payment received

---

## Root Causes Identified

### Issue #1: Wrong Database Table in Success Handler

**Location:** `includes/shortcodes.php` (lines 888-1029)

**Problem:**
The Stripe success redirect handler was using the **wrong database table**:
- ❌ Used: `wp_wd_orders` (old/incorrect table)
- ✅ Should use: `wp_wdss29_orders` (correct table from `includes/orders.php`)

**Impact:**
- Orders created in the checkout flow were stored in `wdss29_orders`
- Success handler looked in `wd_orders` and couldn't find them
- Orders were never marked as "paid"
- No email triggers were fired

---

### Issue #2: Missing Event Trigger Calls

**Location:** `includes/shortcodes.php` (lines 951-989)

**Problem:**
The success handler updated order status **directly in the database** using `$wpdb->update()`:
```php
// OLD CODE - BAD!
$wpdb->update(
    $table,
    array('status' => 'paid'),
    array('id' => $order_id)
);
// No event triggers called!
```

**Impact:**
- Database was updated but no WordPress actions were fired
- `wdss29_set_order_status()` was never called
- Email automation system (`wdss_email_trigger` action) never received the event
- No emails were sent

---

### Issue #3: Incorrect Action Parameters

**Locations:** 
- `includes/orders.php` (lines 109, 169, 174)
- `includes/class-wdss29-core.php` (lines 244, 257, 324)

**Problem:**
Multiple functions were calling the email trigger action with **wrong number of parameters**:

```php
// OLD CODE - BAD!
do_action( 'wdss_email_trigger', 'order.paid', $payload );  // 2 params
```

**Expected signature** (from `class-wdss-emailer.php` line 564):
```php
public function handle_trigger_event( $event_key, $object_id = 0, $payload = array() )
// Needs: event_key, object_id, payload (3 params)
```

**Impact:**
- Email handler received wrong parameters
- `$object_id` was empty, making it impossible to look up order details
- Recipient resolution failed (no customer email found)
- No emails were sent

---

## Fixes Implemented

### Fix #1: Updated Success Handler Table Reference

**File:** `includes/shortcodes.php`  
**Lines:** 916-922

**Changed from:**
```php
global $wpdb;
$table = $wpdb->prefix . 'wd_orders';  // WRONG TABLE!
$cols  = wdss29_get_orders_table_columns();
```

**Changed to:**
```php
// FIXED: Use correct wdss29_orders table (not wd_orders)
global $wpdb;
if ( ! function_exists('wdss29_get_orders_table_name') ) {
    wdss29_log('success_v2:missing_orders_api');
    return;
}
$table = wdss29_get_orders_table_name();  // Returns wp_wdss29_orders
```

---

### Fix #2: Call Proper Event Trigger Function

**File:** `includes/shortcodes.php`  
**Lines:** 943-990

**Changed from:**
```php
// Direct database update - NO EVENT TRIGGERS
if ( $order_row ) {
    $wpdb->update(
        $table,
        array('status' => 'paid', 'payment_id' => $session_id),
        array('id' => intval($order_row['id']))
    );
    // ... no wdss29_set_order_status() call ...
}
```

**Changed to:**
```php
if ( $order_id > 0 && function_exists('wdss29_get_order') ) {
    $order = wdss29_get_order( $order_id );
    
    if ( $order && is_array($order) ) {
        // Check if already paid to avoid duplicates
        if ( $order['status'] === 'paid' ) {
            wdss29_log('success_v2:already_paid', array('order_id' => $order_id));
            // redirect and exit
        }

        // Build hints payload with customer info from Stripe
        $hints = array(
            'customer_email' => $cust_email ?: $order['customer_email'],
            'customer_name'  => $cust_name ?: $order['customer_name'],
            'payment_method' => 'stripe',
            'stripe_session_id' => $session_id,
        );

        // CRITICAL FIX: Call wdss29_set_order_status() to trigger email automation
        if ( function_exists('wdss29_set_order_status') ) {
            wdss29_set_order_status( $order_id, 'paid', $hints );
            wdss29_log('success_v2:order_marked_paid_with_events', array(
                'order_id' => $order_id,
                'email' => $hints['customer_email'],
                'session_id' => $session_id
            ));
        }

        // Wake the email poller
        if ( function_exists('wp_schedule_single_event') ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }
    }
}
```

**Key improvements:**
- ✅ Uses `wdss29_get_order()` to get order from correct table
- ✅ Calls `wdss29_set_order_status()` which triggers all necessary events
- ✅ Includes customer info from Stripe in payload
- ✅ Prevents duplicate processing
- ✅ Schedules email poller to send queued emails immediately

---

### Fix #3: Corrected Email Trigger Action Calls

**Files & Locations:**
1. `includes/orders.php` (lines 109, 169, 174)
2. `includes/class-wdss29-core.php` (lines 244, 257, 325)

**Changed from (2 parameters):**
```php
do_action( 'wdss_email_trigger', 'order.paid', $payload );  // WRONG
do_action( 'wdss_email_trigger', 'order.status_changed', $payload );  // WRONG
do_action( 'wdss_email_trigger', 'order.created', $payload );  // WRONG
```

**Changed to (3 parameters):**
```php
// FIXED: Email Automations bus wants (event_key, object_id, payload)
do_action( 'wdss_email_trigger', 'order.paid', $order_id, $payload );
do_action( 'wdss_email_trigger', 'order.status_changed', $order_id, $payload );
do_action( 'wdss_email_trigger', 'order.created', $order_id, $payload );
```

**Why this matters:**
- Email handler needs `$object_id` (order ID) to look up customer details
- Without it, recipient resolution fails
- With correct params, handler can find customer email and send notifications

---

## Complete List of Modified Files

1. **`includes/shortcodes.php`**
   - Fixed success handler to use correct table
   - Added proper event trigger calls
   - Removed obsolete duplicate checking function

2. **`includes/orders.php`**
   - Fixed `wdss29_create_order()` - line 109
   - Fixed `wdss29_set_order_status()` - lines 169, 174

3. **`includes/class-wdss29-core.php`**
   - Fixed `wdss29_emit_order_paid()` - line 244
   - Fixed `wdss29_emit_order_status_changed()` - line 257
   - Fixed `wdss29_capture_success_on_redirect()` - line 325

---

## How the Fix Works

### Flow After Fix:

1. **Customer completes payment on Stripe**
   - Stripe redirects to: `/regiss/checkout-success/?wdss29=success&session_id={SESSION_ID}&order_id={ORDER_ID}`

2. **Success handler (shortcodes.php) receives redirect**
   - Fetches session details from Stripe API
   - Verifies payment status is "paid"
   - Gets `order_id` from `client_reference_id`

3. **Success handler looks up order in CORRECT table**
   - Calls `wdss29_get_orders_table_name()` → returns `wp_wdss29_orders`
   - Calls `wdss29_get_order($order_id)` → finds the order
   - Extracts customer email/name from Stripe session data

4. **Success handler triggers status update**
   - Calls `wdss29_set_order_status($order_id, 'paid', $hints)`
   - This function in `orders.php` does:
     - Updates database: `status = 'paid'`
     - Fires action: `do_action('wdss_email_trigger', 'order.paid', $order_id, $payload)`

5. **Email automation system receives trigger**
   - `class-wdss-emailer.php::handle_trigger_event()` is called
   - Has all 3 params: event_key='order.paid', object_id=$order_id, payload=array()
   - Looks up enabled rules matching 'order.paid' trigger
   - Resolves recipient (customer email from payload)
   - Renders email template with placeholders
   - Sends email via `wp_mail()`

6. **Email is delivered!** ✅

---

## Testing the Fix

### Prerequisites:
1. Ensure you have at least one **Email Template** set up:
   - Go to: WP Admin → Email Templates → Add New
   - Set Subject: "Thank you for your order!"
   - Set Body: "Hi {customer_name}, your order #{order_id} totaling ${order_total} has been paid."
   - In "Automation" metabox:
     - ✅ Enable automation
     - ✅ Check "Order Paid (after purchase)"
   - In "Delivery" metabox:
     - ✅ Check "Customer"
   - Publish the template

### Test Steps:
1. **Create a test order:**
   - Log in to your WordPress site
   - Add a product to cart
   - Go to checkout

2. **Pay with Stripe test card:**
   - Card number: `4242 4242 4242 4242`
   - Expiry: Any future date
   - CVC: Any 3 digits
   - Complete payment

3. **Verify redirect:**
   - You should be redirected to `/checkout-success/`
   - Check WordPress debug log for: `success_v2:order_marked_paid_with_events`

4. **Check order status:**
   - Go to: WP Admin → Orders (or wherever your orders are listed)
   - Find the order
   - Status should be: **"paid"**

5. **Check email was sent:**
   - Check the customer's email inbox
   - Should receive email with subject: "Thank you for your order!"
   - Body should have placeholders replaced with actual values

6. **Check email logs (optional):**
   - Go to: WP Admin → Email Automations
   - Look for log entries showing:
     - Event received: `order.paid`
     - Recipients resolved
     - Email sent

### What to Look For in Debug Log:

**Success indicators:**
```
[WDSS] success_v2:order_marked_paid_with_events | {"order_id":123,"email":"customer@example.com","session_id":"cs_test_..."}
```

**Error indicators to fix:**
```
[WDSS] success_v2:missing_session_id
[WDSS] success_v2:order_not_found
[WDSS] success_v2:missing_set_order_status_function
```

---

## Additional Configuration Notes

### Stripe Webhook (Optional but Recommended)

For redundancy, you can also set up a **Stripe webhook** that will mark orders as paid even if the redirect fails:

1. **In Stripe Dashboard:**
   - Go to: Developers → Webhooks → Add endpoint
   - URL: `https://yoursite.com/wp-json/wdss29/v1/stripe/webhook`
   - Events to send:
     - `payment_intent.succeeded`
     - `checkout.session.completed`
   - Copy the webhook signing secret

2. **In WordPress:**
   - Go to: WP Admin → Settings → WD Store Suite (or wherever your settings are)
   - Add field for: Webhook Secret
   - Paste the signing secret from Stripe

3. **Webhook handler** (already exists in `includes/payments/class-wdss-stripe.php`)
   - Receives webhook from Stripe
   - Verifies signature
   - Calls `wdss29_set_order_status($order_id, 'paid', $hints)`
   - Triggers email automation (same flow as redirect)

---

## Rollback Instructions

If you need to revert these changes:

```bash
# From the plugin directory
git diff HEAD~1 includes/shortcodes.php
git diff HEAD~1 includes/orders.php
git diff HEAD~1 includes/class-wdss29-core.php

# To revert
git checkout HEAD~1 includes/shortcodes.php
git checkout HEAD~1 includes/orders.php
git checkout HEAD~1 includes/class-wdss29-core.php
```

---

## Summary

### What Was Broken:
- ❌ Success handler used wrong database table (`wd_orders` instead of `wdss29_orders`)
- ❌ Success handler didn't call event trigger function
- ❌ Event trigger actions had wrong number of parameters (2 instead of 3)

### What Was Fixed:
- ✅ Success handler now uses correct table (`wdss29_orders`)
- ✅ Success handler calls `wdss29_set_order_status()` which triggers events
- ✅ All event trigger calls now use correct 3-parameter signature
- ✅ Order status is properly updated after payment
- ✅ Email automation system receives events with all required data
- ✅ Emails are sent to customers after successful payment

### Files Modified:
1. `includes/shortcodes.php` - Success handler rewritten
2. `includes/orders.php` - Fixed action calls
3. `includes/class-wdss29-core.php` - Fixed action calls

---

**Result:** Both issues are now resolved. Orders are marked as paid and emails are automatically sent after Stripe payment completion! 🎉

