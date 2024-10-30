<?php

/*
 *
 * Plugin Name: Boxcoin Cloud
 * Plugin URI: https://boxcoin.dev/
 * Description: Accept cryptocurrency payments
 * Version: 1.0.2
 * Author: Schiocco
 * Author URI: https://schiocco.com/
 * © 2022-2024 boxcoin.dev. All rights reserved.
 *
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    function bxc_declare_cart_checkout_blocks_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    function bxc_wc_add_to_gateways($gateways) {
        $gateways[] = 'WC_Boxcoin';
        return $gateways;
    }

    function bxc_wc_plugin_links($links) {
        return array_merge(['<a href="' . admin_url('options-general.php?page=boxcoin-cloud') . '">Settings</a>'], $links);
    }

    function bxc_checkout_block_support() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType') && file_exists(__DIR__ . '/config.php')) {

            final class WC_Boxcoin_Blocks extends AbstractPaymentMethodType {

                private $gateway;
                protected $name = 'boxcoin';

                public function initialize() {
                    $this->settings = get_option('woocommerce_boxcoin_settings', []);
                    $this->gateway = new WC_Boxcoin();
                }

                public function is_active() {
                    return $this->gateway->is_available();
                }

                public function get_payment_method_script_handles() {
                    wp_register_script('boxcoin-blocks-integration', plugin_dir_url(__FILE__) . '/assets/checkout.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'], null, true);
                    if (function_exists('wp_set_script_translations')) {
                        wp_set_script_translations('boxcoin-blocks-integration');
                    }
                    return ['boxcoin-blocks-integration'];
                }

                public function get_payment_method_data() {
                    return ['title' => $this->gateway->title, 'description' => $this->gateway->description];
                }
            }

            add_action('woocommerce_blocks_payment_method_type_registration', function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Boxcoin_Blocks);
            });
        }
    }

    function bxc_wc_init() {
        class WC_Boxcoin extends WC_Payment_Gateway {
            public function __construct() {
                $settings = bxc_get_wp_settings();
                $this->id = 'boxcoin';
                $this->has_fields = false;
                $this->method_title = 'Boxcoin';
                $this->method_description = 'Accept cryptocurrency payments.';
                $this->title = __(bxc_isset($settings, 'boxcoin-payment-option-name', 'Pay with crypto'), 'boxcoin');
                $this->description = __(bxc_isset($settings, 'boxcoin-payment-option-text', 'Pay via Bitcoin, Ethereum and other cryptocurrencies.'), 'boxcoin');
                $this->init_form_fields();
                $this->init_settings();
                $icon = bxc_isset($settings, 'boxcoin-payment-option-icon');
                if ($icon) {
                    $this->icon = $icon;
                }
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            }

            public function process_payment($order_id) {
                $settings = bxc_get_wp_settings();
                $order = wc_get_order($order_id);
                $order->update_status('pending');
                wc_reduce_stock_levels($order_id);
                return ['result' => 'success', 'redirect' => 'https://cloud.boxcoin.dev/pay.php?checkout_id=custom-wc-' . $order_id . '&price=' . $order->get_total() . '&currency=' . strtolower($order->get_currency()) . '&external_reference=' . bxc_wp_encryption($order_id . '|' . $this->get_return_url($order) . '|woo') . '&plugin=woocommerce&redirect=' . urlencode($this->get_return_url($order)) . '&cloud=' . bxc_isset($settings, 'boxcoin-cloud-key') . '&plugin=woocommerce&note=' . urlencode('WooCommerce order ID ' . $order_id)];
            }

            public function init_form_fields() {
                $this->form_fields = apply_filters('wc_offline_form_fields', ['enabled' => ['title' => __('Enable/Disable', 'boxcoin'), 'type' => 'checkbox', 'label' => __('Enable Boxcoin', 'boxcoin'), 'default' => 'yes']]);
            }
        }
    }
    add_filter('woocommerce_payment_gateways', 'bxc_wc_add_to_gateways');
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bxc_wc_plugin_links');
    add_action('woocommerce_blocks_loaded', 'bxc_checkout_block_support');
    add_action('plugins_loaded', 'bxc_wc_init', 11);
    add_action('before_woocommerce_init', 'bxc_declare_cart_checkout_blocks_compatibility');
}

function bxc_edd_register_gateway($gateways) {
    $settings = bxc_get_wp_settings();
    $gateways['boxcoin'] = ['admin_label' => 'Boxcoin', 'checkout_label' => __(bxc_isset($settings, 'boxcoin-payment-option-name', 'Pay with crypto'), 'boxcoin')];
    return $gateways;
}

function bxc_edd_process_payment($data) {
    if (!edd_get_errors()) {
        $settings = bxc_get_wp_settings();
        $payment_id = edd_insert_payment($data);
        $url = 'checkout_id=custom-edd-' . $payment_id . '&price=' . $data['price'] . '&currency=' . strtolower(edd_get_currency()) . '&external_reference=' . bxc_wp_encryption('edd|' . $payment_id) . '&redirect=' . urlencode(edd_get_success_page_uri()) . '&cloud=' . bxc_isset($settings, 'boxcoin-cloud-key') . '&note=' . urlencode('Easy Digital Download payment ID ' . $payment_id);
        edd_send_back_to_checkout($url);
    }
}

function bxc_wp_on_load() {
    if (function_exists('edd_is_checkout') && edd_is_checkout()) {
        echo '<script>var bxc_href = document.location.href; if (bxc_href.includes("custom-edd-")) { document.location = "https://cloud.boxcoin.dev/pay.php" + bxc_href.substring(bxc_href.indexOf("?")); }</script>';
    }
}

function bxc_edd_disable_gateway_cc_form() {
    return;
}

function bxc_set_admin_menu() {
    add_submenu_page('options-general.php', 'Boxcoin', 'Boxcoin', 'administrator', 'boxcoin-cloud', 'bxc_admin');
}

function bxc_enqueue_admin() {
    if (key_exists('page', $_GET) && $_GET['page'] == 'boxcoin-cloud') {
        wp_enqueue_style('bxc-cloud-admin-css', plugin_dir_url(__FILE__) . '/assets/style.css', [], '1.0', 'all');
    }
}

function bxc_wp_encryption($string, $encrypt = true) {
    $settings = bxc_get_wp_settings();
    $output = false;
    $encrypt_method = 'AES-256-CBC';
    $secret_key = bxc_isset($settings, 'boxcoin-key');
    $key = hash('sha256', $secret_key);
    $iv = substr(hash('sha256', bxc_isset($settings, 'boxcoin-cloud-key')), 0, 16);
    if ($encrypt) {
        $output = openssl_encrypt(is_string($string) ? $string : json_encode($string, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE), $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
        if (substr($output, -1) == '=')
            $output = substr($output, 0, -1);
    } else {
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }
    return $output;
}

function bxc_get_wp_settings() {
    return json_decode(get_option('bxc-cloud-settings'), true);
}

function bxc_isset($array, $key, $default = '') {
    return !empty($array) && isset($array[$key]) && $array[$key] !== '' ? $array[$key] : $default;
}

function bxc_admin() {
    if (isset($_POST['bxc_submit'])) {
        if (!isset($_POST['bxc_nonce']) || !wp_verify_nonce($_POST['bxc_nonce'], 'bxc-nonce'))
            die('nonce-check-failed');
        $settings = [
            'boxcoin-key' => sanitize_text_field($_POST['boxcoin-key']),
            'boxcoin-cloud-key' => sanitize_text_field($_POST['boxcoin-cloud-key']),
            'boxcoin-payment-option-name' => sanitize_text_field($_POST['boxcoin-payment-option-name']),
            'boxcoin-payment-option-text' => sanitize_text_field($_POST['boxcoin-payment-option-text']),
            'boxcoin-payment-option-icon' => sanitize_text_field($_POST['boxcoin-payment-option-icon'])
        ];
        update_option('bxc-cloud-settings', json_encode($settings));
    }
    $settings = bxc_get_wp_settings();
    ?>
    <form method="post" action="">
        <div class="wrap">
            <h1>Boxcoin</h1>
            <div class="postbox-container">
                <table class="form-table bxc-table">
                    <tbody>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Webhook secret key</label>
                            </th>
                            <td>
                                <input type="password" id="boxcoin-key" name="boxcoin-key" value="<?php echo esc_html(bxc_isset($settings, 'boxcoin-key')) ?>" />
                                <br />
                                <p class="description">Enter the Boxcoin webhook secret key. Get it from Boxcoin > Settings > Webhook > Webhook secret key.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Cloud API key</label>
                            </th>
                            <td>
                                <input type="password" id="boxcoin-cloud-key" name="boxcoin-cloud-key" value="<?php echo esc_html(bxc_isset($settings, 'boxcoin-cloud-key')) ?>" />
                                <br />
                                <p class="description">Enter the Boxcoin API key. Get it from Boxcoin > Account > API key.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Payment option name</label>
                            </th>
                            <td>
                                <input type="text" id="boxcoin-payment-option-name" name="boxcoin-payment-option-name" value="<?php echo esc_html(bxc_isset($settings, 'boxcoin-payment-option-name')) ?>" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Payment option description</label>
                            </th>
                            <td>
                                <input type="text" id="boxcoin-payment-option-text" name="boxcoin-payment-option-text" value="<?php echo esc_html(bxc_isset($settings, 'boxcoin-payment-option-text')) ?>" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Payment option icon URL</label>
                            </th>
                            <td>
                                <input type="text" id="boxcoin-payment-option-icon" name="boxcoin-payment-option-icon" value="<?php echo esc_html(bxc_isset($settings, 'boxcoin-payment-option-icon')) ?>" />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">
                                <label for="name">Webhook URL</label>
                            </th>
                            <td>
                                <input type="text" readonly value="<?php echo site_url() ?>/wp-json/boxcoin/webhook" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="hidden" name="bxc_nonce" id="bxc_nonce" value="<?php echo wp_create_nonce('bxc-nonce') ?>" />
                    <input type="submit" class="button-primary" name="bxc_submit" value="Save changes" />
                </p>
            </div>
        </div>
    </form>
<?php }

function bxc_wp_webhook_callback($request) {
    $response = json_decode(file_get_contents('php://input'), true);
    if (!isset($response['key'])) {
        return;
    }
    $settings = bxc_get_wp_settings();
    if ($response['key'] !== $settings['boxcoin-key']) {
        return 'Invalid Webhook Key';
    }
    $transaction = bxc_isset($response, 'transaction');
    if ($transaction) {
        $external_reference = explode('|', bxc_wp_encryption($transaction['external_reference'], false));
        $text = 'Boxcoin transaction ID: ' . $transaction['id'];
        if (in_array('woo', $external_reference)) {
            $order = wc_get_order($external_reference[0]);
            $amount_fiat = $transaction['amount_fiat'];
            if (($amount_fiat && floatval($amount_fiat) < floatval($order->get_total())) || (strtoupper($transaction['currency']) != strtoupper($order->get_currency()))) {
                return 'Invalid amount or currency';
            }
            if ($order->get_status() == 'pending') {
                $products = $order->get_items();
                $is_virtual = true;
                foreach ($products as $product) {
                    $product = wc_get_product($product->get_data()['product_id']);
                    if (!$product->is_virtual() && !$product->is_downloadable()) {
                        $is_virtual = false;
                        break;
                    }
                }
                if ($is_virtual) {
                    $order->payment_complete();
                } else {
                    $order->update_status('processing');
                }
                $order->add_order_note($text);
                return 'success';
            }
        } else if (in_array('edd', $external_reference)) {
            edd_update_payment_status($external_reference[0], 'complete');
            edd_insert_payment_note($external_reference[0], $text);
            return 'success';
        }
        return 'Invalid order status';
    }
    return 'Transaction not found';
}

function bxc_wp_on_user_logout($user_id) {
    if (!headers_sent()) {
        setcookie('BXC_LOGIN', '', time() - 3600);
    }
    return $user_id;
}

add_action('admin_menu', 'bxc_set_admin_menu');
add_action('network_admin_menu', 'bxc_set_admin_menu');
add_action('admin_enqueue_scripts', 'bxc_enqueue_admin');
add_action('edd_gateway_boxcoin', 'bxc_edd_process_payment');
add_action('edd_boxcoin_cc_form', 'bxc_edd_disable_gateway_cc_form');
add_filter('edd_payment_gateways', 'bxc_edd_register_gateway');
add_action('wp_logout', 'bxc_wp_on_user_logout');
add_action('wp_head', 'bxc_wp_on_load');
add_action('rest_api_init', function () {
    register_rest_route('boxcoin', '/webhook', [
        'methods' => 'POST',
        'callback' => 'bxc_wp_webhook_callback',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => [
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
});

?>