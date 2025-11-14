# Code Removed & Restored - Explanation

Good catch on questioning the removed code. After reviewing, I found a critical issue and had to restore most of what was removed. Here's what happened and why.

## The Real Problem

The plugin uses two separate order systems that run in parallel:

1. **`wd_orders` table** - Used by the V2 checkout flow in `shortcodes.php`
   - `wdss29_save_order()` writes here
   - Creates pending orders during checkout

2. **`wdss29_orders` table** - Used by `orders.php`
   - `wdss29_create_order()` writes here
   - Used by email automation

My initial fix tried reading from `wdss29_orders`, but the checkout writes to `wd_orders`. Both tables are in use, which explains the mismatch.

## What Was Removed (And Why It Needed to Come Back)

### Order Lookup by Meta Field

I removed the meta field lookup because I assumed `client_reference_id` was the order ID. Turns out it's not.

Looking at the checkout code around line 748:
```php
$client_ref = 'wd-' . wp_generate_password(12, false);
```

The `client_reference_id` is a random string like `"wd-a1b2c3d4e5f6"`, not the order ID. It's stored in the order's `meta` JSON field, so we need to search that field to find the matching order.

**Status:** Restored - this lookup is required.

### Order Items Persistence

I saw that checkout already saves items at line 787, so I figured why save them again? But this code is idempotent - it's safe to run multiple times. The function deletes and re-inserts items, which provides a safety net if items weren't saved during checkout. It also recalculates prices to ensure accuracy.

**Status:** Restored - provides important redundancy and data integrity.

### Fallback Order Creation

I thought if no order exists, something is broken and we should just error out. But there are edge cases where this helps:
- Database write fails during checkout but payment succeeds
- Race conditions or timing issues
- User manually navigates to an old Stripe session URL

Instead of losing the payment, we create an order from Stripe data. Better to have an order with minimal info than no order at all.

**Status:** Restored - handles important edge cases.

### Duplicate De-dupe Function

This ran at priority 7 and did the same duplicate check as the main handler at priority 8. I removed it and moved the duplicate check into the main handler (lines 933-945). One function doing one job is cleaner.

**Status:** Kept removed - functionality consolidated.

## Final Implementation

The restored code now:

1. Uses the correct `wd_orders` table (line 930)
2. Searches for orders by `client_reference_id` in the meta field (lines 947-961)
3. Re-persists order items for data integrity (lines 980-1007)
4. Has fallback order creation for edge cases (lines 1043-1083)
5. Fires email trigger with correct parameters (line 1152)
6. Includes duplicate checking to prevent double-processing (lines 933-945)

## The Email Bug

Even with all the logic restored, emails still weren't sending. The issue was that multiple files were calling `wdss_email_trigger` with the wrong number of parameters:

```php
// Wrong (2 parameters):
do_action( 'wdss_email_trigger', 'order.paid', $payload );

// Correct (3 parameters):
do_action( 'wdss_email_trigger', 'order.paid', $order_id, $payload );
```

The email handler expects:
```php
public function handle_trigger_event( $event_key, $object_id = 0, $payload = array() )
```

Without `$object_id`, the email system couldn't look up customer details.

Fixed in:
- `includes/shortcodes.php` - success handler
- `includes/orders.php` - order status functions  
- `includes/class-wdss29-core.php` - helper functions

## Additional Fixes

1. **Session ID placeholder** - The `{CHECKOUT_SESSION_ID}` placeholder was being URL-encoded, so Stripe couldn't replace it. Fixed by building the URL manually without encoding.

2. **Meta column missing** - The `meta` column wasn't being added to existing tables. Fixed by adding manual column checking in the upgrade function.

3. **Function name conflict** - Two functions with the same name were conflicting. Renamed the one in `shortcodes.php` to `wdss29_maybe_install_or_upgrade_wd_orders_table()`.

4. **Condition matching** - Email rules had condition `status=paid` but payload only had `order_status=paid`. Added `status` as an alias in the payload.

## Testing

After these changes:

1. **Normal checkout flow** - Order should be marked paid, email sent, items saved
2. **Duplicate redirect** - Visiting success URL twice shouldn't create duplicates
3. **Lost pending order** - Fallback should create order from Stripe data

## Summary

The removed code was actually necessary. The main issues were:
- Wrong table being queried
- Missing email trigger parameters
- Session ID placeholder encoding
- Missing database column
- Condition matching in email rules

All of these are now fixed.
