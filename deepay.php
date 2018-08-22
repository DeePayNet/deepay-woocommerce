<?php
//TODO 修改插件许可证
/*
Plugin Name: WooCommerce Payment Gateway - Deepay
Plugin URI: https://deepay.net
Description: Accept Bitcoin via Deepay in your WooCommerce store
Version: 1.0.0
Author: Deepay
Author URI: https://deepay.net
License: MIT License
License URI: https://github.com/coingate/woocommerce-plugin/blob/master/LICENSE
Github Plugin URI: https://github.com/coingate/woocommerce-plugin
*/
error_reporting(E_ERROR);
add_action('plugins_loaded', 'deepay_init');

define('DEEPAY_WOOCOMMERCE_VERSION', '1.0.0');

function deepay_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };

    define('DEEPAY_PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

    require_once(__DIR__ . '/lib/deepay/init.php');

    class WC_Gateway_Deepay extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'deepay';
            $this->has_fields = false;
            $this->method_title = 'Deepay';
            $this->icon = apply_filters('woocommerce_deepay_icon', DEEPAY_PLUGIN_DIR . 'assets/bitcoin.png');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->api_key = $this->get_option('api_key');
            $this->receive_currency = $this->get_option('receive_currency');
            $this->order_statuses = $this->get_option('order_statuses');
            $this->test = ('yes' === $this->get_option('test', 'no'));
            // var_dump($this->api_key);die;
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_order_statuses'));
            add_action('woocommerce_thankyou_deepay', array($this, 'thankyou'));
            add_action('woocommerce_api_wc_gateway_deepay', array($this, 'payment_callback'));
        }

        public function admin_options()
        {
            ?>
            <h3><?php _e('Deepay', 'woothemes'); ?></h3>
            <p><?php _e('Accept Bitcoin through the deepay.net and receive payments in euros and US dollars.<br>
        <a href="https://developer.deepay.com/docs/issues" target="_blank">Not working? Common issues</a> &middot; <a href="mailto:support@deepay.com">support@deepay.com</a>', 'woothemes'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php

        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable Deepay', 'woocommerce'),
                    'label' => __('Enable Cryptocurrency payments via Deepay', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('The payment method description which a user sees at the checkout of your store.', 'woocommerce'),
                    'default' => __('Pay with BTC, LTC, ETH, BCH. Powered by Deepay.'),
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('The payment method title which a customer sees at the checkout of your store.', 'woocommerce'),
                    'default' => __('Cryptocurrencies via Deepay (more than 50 supported)', 'woocommerce'),
                ),
                'merchant_id' => array(
                        'title' => __('Merchant ID', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('Deepay Merchant ID', 'woocommerce'),
                        'default' => (empty($this->get_option('merchant_id')) ? '' : $this->get_option('merchant_id')),
                ),
                'api_key' => array(
                      'title' => __('API KEY', 'woocommerce'),
                      'type' => 'text',
                      'description' => __('Deepay API KEY', 'woocommerce'),
                      'default' => (empty($this->get_option('api_secret')) ? '' : $this->get_option('api_secret')),
                ),

                'order_statuses' => array(
                    'type' => 'order_statuses'
                ),
            );
        }

        public function thankyou()
        {
            if ($description = $this->get_description()) {
                echo wpautop(wptexturize($description));
            }
        }

        public function process_payment($order_id)
        {
            global $woocommerce, $page, $paged;
            $order = new WC_Order($order_id);

            $this->init_deepay();

            $description = array();
            foreach ($order->get_items('line_item') as $item) {
                $description[] = $item['qty'] . ' × ' . $item['name'];
            }

            $wcOrder = wc_get_order($order_id);

            $data = array(
                'out_trade_id'      => $order->id,
                'price_amount'      => number_format($order->get_total(), 4, '.', ''),
                'price_currency'    => get_woocommerce_currency(),
                'notify_url'      => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_deepay',
                'callback_url'       => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, $this->get_return_url($wcOrder))),
                'title'             => get_bloginfo('name', 'raw') . ' Order #' . $order->id,
                'attach'       => implode($description, ', '),
                'merchant_id'  => $this->merchant_id,
            );

            $sign = strtoupper(\Deepay\Deepay::md5Sign($data, $this->api_key, '&key='));
            $data['sign'] = $sign;
            $order = \Deepay\Merchant\Order::create($data);

            if ($order && $order->payment_url) {
                return array(
                    'result' => 'success',
                    'redirect' => $order->payment_url,
                );
            } else {
                throw new Exception($order->msg);
            }
        }

        public function payment_callback()
        {
            $request = $_REQUEST;

            global $woocommerce;

            $order = new WC_Order($request['out_trade_id']);


            try {
                if (!$order || !$order->id) {
                    throw new Exception('Order #' . $request['out_trade_id'] . ' does not exists');
                }
                $sign_data = $request;
                unset($sign_data['sign']);
                $sign = strtoupper(\Deepay\Deepay::md5Sign($sign_data, $this->api_key, '&key='));

                if (!isset($request['sign']) || (isset($request['sign']) && $request['sign'] == $sign) ) {
                    throw new Exception('Callback sign does not match');
                }

                $query_data = [
                    'transaction_id' => $request['transaction_id'], 
                    'merchant_id'    => $this->merchant_id,
                ];
                $sign = strtoupper(\Deepay\Deepay::md5Sign($query_data, $this->api_key, '&key='));
                $query_data['sign'] = $sign;

                $this->init_deepay();
                $cgOrder = \Deepay\Merchant\Order::find($query_data);

                if (!$cgOrder) {
                    throw new Exception('Deepay Order #' . $order->id . ' does not exists');
                }

                $orderStatuses = $this->get_option('order_statuses');
                $wcOrderStatus = $orderStatuses[$cgOrder->status];
                $wcExpiredStatus = $orderStatuses['expired'];
                $wcPaidStatus = $orderStatuses['paid'];

                switch ($cgOrder->status) {
                    case 'paid':
                        $statusWas = "wc-" . $order->status;

                        $order->update_status($wcOrderStatus);
                        $order->add_order_note(__('Payment is paid on the network. Please wait for confirmation', 'deepay'));
                        
                        break;
                    case 'confirmed':
                        $order->update_status($wcOrderStatus);
                        $order->add_order_note(__('Payment is confirmed on the network, and has been credited to the merchant. Purchased goods/services can be securely delivered to the buyer.', 'deepay'));
                        $order->payment_complete();
                        break;
                    case 'invalid':
                        $order->update_status($wcOrderStatus);
                        $order->add_order_note(__('Payment rejected by the network or did not confirm within 1 day.', 'deepay'));
                        break;
                    case 'expired':
                        $order->update_status($wcOrderStatus);
                        $order->add_order_note(__('Buyer did not pay within the required time and the order expired.', 'deepay'));
                        break;
                }
                exit('OK');
            } catch (Exception $e) {
                die(get_class($e) . ': ' . $e->getMessage());
            }
        }

        public function generate_order_statuses_html()
        {
            ob_start();

            $cgStatuses = $this->cgOrderStatuses();
            $wcStatuses = wc_get_order_statuses();
            $defaultStatuses = array('paid' => 'wc-processing', 'confirmed' => 'wc-completed', 'invalid' => 'wc-failed', 'expired' => 'wc-cancelled');

            ?>
            <tr valign="top">
                <th scope="row" class="titledesc">Order Statuses:</th>
                <td class="forminp" id="deepay_order_statuses">
                    <table cellspacing="0">
                        <?php
                        foreach ($cgStatuses as $cgStatusName => $cgStatusTitle) {
                            ?>
                            <tr>
                                <th><?php echo $cgStatusTitle; ?></th>
                                <td>
                                    <select name="woocommerce_deepay_order_statuses[<?php echo $cgStatusName; ?>]">
                                        <?php
                                        $orderStatuses = get_option('woocommerce_deepay_settings');
                                        $orderStatuses = $orderStatuses['order_statuses'];

                                        foreach ($wcStatuses as $wcStatusName => $wcStatusTitle) {
                                            $currentStatus = $orderStatuses[$cgStatusName];

                                            if (empty($currentStatus) === true)
                                                $currentStatus = $defaultStatuses[$cgStatusName];

                                            if ($currentStatus == $wcStatusName)
                                                echo "<option value=\"$wcStatusName\" selected>$wcStatusTitle</option>";
                                            else
                                                echo "<option value=\"$wcStatusName\">$wcStatusTitle</option>";
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </table>
                </td>
            </tr>
            <?php

            return ob_get_clean();
        }

        public function validate_order_statuses_field()
        {
            $orderStatuses = $this->get_option('order_statuses');

            if (isset($_POST[$this->plugin_id . $this->id . '_order_statuses']))
                $orderStatuses = $_POST[$this->plugin_id . $this->id . '_order_statuses'];

            return $orderStatuses;
        }

        public function save_order_statuses()
        {
            $cgOrderStatuses = $this->cgOrderStatuses();
            $wcStatuses = wc_get_order_statuses();
            if (isset($_POST['woocommerce_deepay_order_statuses']) === true) {
                $cgSettings = get_option('woocommerce_deepay_settings');
                $orderStatuses = $cgSettings['order_statuses'];

                foreach ($cgOrderStatuses as $cgStatusName => $cgStatusTitle) {
                    if (isset($_POST['woocommerce_deepay_order_statuses'][$cgStatusName]) === false)
                        continue;

                    $wcStatusName = $_POST['woocommerce_deepay_order_statuses'][$cgStatusName];

                    if (array_key_exists($wcStatusName, $wcStatuses) === true)
                        $orderStatuses[$cgStatusName] = $wcStatusName;
                }

                $cgSettings['order_statuses'] = $orderStatuses;
                update_option('woocommerce_deepay_settings', $cgSettings);
            }
        }

        private function cgOrderStatuses()
        {
            return array('paid' => 'Paid', 'confirmed' => 'Confirmed', 'invalid' => 'Invalid', 'expired' => 'Expired');
        }

        private function init_deepay()
        {
            \Deepay\Deepay::config(
                array(
                    'merchant_id'   => $this->merchant_id,
                    'api_key'       => $this->api_key,
                    'environment'   => ($this->test ? 'test' : 'live'),
                    'user_agent'    => ('Deepay - WooCommerce v' . WOOCOMMERCE_VERSION . ' Plugin v' . DEEPAY_WOOCOMMERCE_VERSION),
                )
            );
        }
    }

    function add_deepay_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Deepay';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_deepay_gateway');
}
