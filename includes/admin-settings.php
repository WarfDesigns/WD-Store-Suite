<?php 
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WD Store Settings
 * - Stripe keys
 * - Sales Tax / Card Processing Fee
 * - Add-on Prices (Back, 3" Front Length, 12" Train)
 */

add_action( 'admin_menu', function() {
    add_menu_page(
        'WD Store Settings',
        'WD Store',
        'manage_options',
        'wdss29-settings',
        'wdss29_render_settings_page',
        'dashicons-store',
        56
    );
});

/**
 * Defaults used when an option is missing.
 */
function wdss29_settings_defaults() {
    return array(
        'stripe_sk'       => '',
        'stripe_pk'       => '',
        'sales_tax_rate'  => 6, // %
        'card_fee_rate'   => 3, // %
        // Add-on prices (USD) — per unit
        'addon_prices'    => array(
            'back_zip_up'   => 0.00,
            'back_lace_up'  => 0.00,
            'length3_yes'   => 0.00,
            'length3_no'    => 0.00,
            'train12_yes'   => 0.00,
            'train12_no'    => 0.00,
        ),
    );
}

function wdss29_get_settings_full() {
    $current  = get_option( 'wdss29_settings', array() );
    $settings = wp_parse_args( is_array($current) ? $current : array(), wdss29_settings_defaults() );

    // Ensure nested addon_prices array has all keys
    $defaults_addons = wdss29_settings_defaults()['addon_prices'];
    if ( empty($settings['addon_prices']) || ! is_array($settings['addon_prices']) ) {
        $settings['addon_prices'] = $defaults_addons;
    } else {
        $settings['addon_prices'] = array_merge( $defaults_addons, $settings['addon_prices'] );
    }
    return $settings;
}

function wdss29_render_settings_page() {
    // Load current settings and merge with defaults so all keys exist
    $settings = wdss29_get_settings_full();

    // Save
    if ( isset($_POST['wdss29_save']) && check_admin_referer('wdss29_save_settings') ) {
        $new = $settings;

        // Stripe keys
        $new['stripe_sk'] = sanitize_text_field( $_POST['stripe_sk'] ?? '' );
        $new['stripe_pk'] = sanitize_text_field( $_POST['stripe_pk'] ?? '' );

        // Rates (clamped 0–100)
        $sales = isset($_POST['sales_tax_rate']) ? floatval($_POST['sales_tax_rate']) : $settings['sales_tax_rate'];
        $fee   = isset($_POST['card_fee_rate'])  ? floatval($_POST['card_fee_rate'])  : $settings['card_fee_rate'];
        $new['sales_tax_rate'] = max(0, min(100, $sales));
        $new['card_fee_rate']  = max(0, min(100, $fee));

        // Add-on prices (USD)
        $ap = $settings['addon_prices'];
        $getm = function($key) {
            return isset($_POST['addon_prices'][$key]) ? floatval($_POST['addon_prices'][$key]) : 0.0;
        };
        $ap['back_zip_up']  = $getm('back_zip_up');
        $ap['back_lace_up'] = $getm('back_lace_up');
        $ap['length3_yes']  = $getm('length3_yes');
        $ap['length3_no']   = $getm('length3_no');
        $ap['train12_yes']  = $getm('train12_yes');
        $ap['train12_no']   = $getm('train12_no');

        // Clamp to sensible bounds (-99999..99999)
        foreach ($ap as $k => $v) {
            $ap[$k] = max(-99999, min(99999, round((float)$v, 2)));
        }
        $new['addon_prices'] = $ap;

        update_option( 'wdss29_settings', $new );
        $settings = $new;

        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $ap = $settings['addon_prices'];
    ?>
    <div class="wrap">
        <h1>WD Store Settings</h1>
        <form method="post">
            <?php wp_nonce_field('wdss29_save_settings'); ?>

            <h2 class="title">Stripe</h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="wdss29_stripe_sk">Stripe Secret Key</label></th>
                        <td>
                            <input id="wdss29_stripe_sk" type="text" name="stripe_sk" value="<?php echo esc_attr($settings['stripe_sk']); ?>" size="60">
                            <p class="description">Format: <code>sk_test_…</code> or <code>sk_live_…</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wdss29_stripe_pk">Stripe Publishable Key</label></th>
                        <td>
                            <input id="wdss29_stripe_pk" type="text" name="stripe_pk" value="<?php echo esc_attr($settings['stripe_pk']); ?>" size="60">
                            <p class="description">Format: <code>pk_test_…</code> or <code>pk_live_…</code></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2 class="title">Rates</h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="wdss29_sales_tax_rate">Sales Tax (%)</label></th>
                        <td>
                            <input id="wdss29_sales_tax_rate" type="number" step="0.01" min="0" max="100" name="sales_tax_rate" value="<?php echo esc_attr($settings['sales_tax_rate']); ?>" style="width:120px;">
                            <span>%</span>
                            <p class="description">Applied to cart subtotal.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wdss29_card_fee_rate">Card Processing Fee (%)</label></th>
                        <td>
                            <input id="wdss29_card_fee_rate" type="number" step="0.01" min="0" max="100" name="card_fee_rate" value="<?php echo esc_attr($settings['card_fee_rate']); ?>" style="width:120px;">
                            <span>%</span>
                            <p class="description">Applied to (subtotal + sales tax).</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2 class="title">Add-on Prices (USD)</h2>
            <p class="description">Per-item charges that apply when a shopper selects an option on the product page.</p>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">Back</th>
                        <td>
                            <label style="display:inline-block;margin-right:18px;">
                                Zip Up:
                                <input type="number" step="0.01" name="addon_prices[back_zip_up]" value="<?php echo esc_attr( $ap['back_zip_up'] ); ?>" style="width:120px;margin-left:6px;">
                            </label>
                            <label style="display:inline-block;">
                                Lace Up:
                                <input type="number" step="0.01" name="addon_prices[back_lace_up]" value="<?php echo esc_attr( $ap['back_lace_up'] ); ?>" style="width:120px;margin-left:6px;">
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Additional 3" Front Length?</th>
                        <td>
                            <label style="display:inline-block;margin-right:18px;">
                                Yes:
                                <input type="number" step="0.01" name="addon_prices[length3_yes]" value="<?php echo esc_attr( $ap['length3_yes'] ); ?>" style="width:120px;margin-left:6px;">
                            </label>
                            <label style="display:inline-block;">
                                No:
                                <input type="number" step="0.01" name="addon_prices[length3_no]" value="<?php echo esc_attr( $ap['length3_no'] ); ?>" style="width:120px;margin-left:6px;">
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Additional 12" Train Length?</th>
                        <td>
                            <label style="display:inline-block;margin-right:18px;">
                                Yes:
                                <input type="number" step="0.01" name="addon_prices[train12_yes]" value="<?php echo esc_attr( $ap['train12_yes'] ); ?>" style="width:120px;margin-left:6px;">
                            </label>
                            <label style="display:inline-block;">
                                No:
                                <input type="number" step="0.01" name="addon_prices[train12_no]" value="<?php echo esc_attr( $ap['train12_no'] ); ?>" style="width:120px;margin-left:6px;">
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button('Save Settings', 'primary', 'wdss29_save'); ?>
        </form>
    </div>
    <?php
}
