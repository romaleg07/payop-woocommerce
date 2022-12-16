<?php
/*
Plugin Name: PayOp for WooCommerce
Plugin URI: https://github.com/romaleg07/payop-woocommerce
Description: PayOp: Online payment processing service 
Author URI: https://github.com/romaleg07/
Version: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
*/


if(!defined('ABSPATH')){
    exit;
}

add_action('plugins_loaded', 'woocommerce_payop', 0);

ini_set( 'error_log', WP_CONTENT_DIR . '/debug-payop.log' );


add_action('woocommerce_checkout_process', 'process_payop_payment');
function process_payop_payment(){

    if($_POST['payment_method'] != 'payop')
        return;

    if( !isset($_POST['payment-method_payop']) || empty($_POST['payment-method_payop']) )
        wc_add_notice( 'Please choose payment method', 'error' );

}


/**
 * Update the order meta with field value
 */
add_action( 'woocommerce_checkout_update_order_meta', 'payop_payment_update_order_meta' );
function payop_payment_update_order_meta( $order_id ) {

    if($_POST['payment_method'] != 'payop')
        return;

    $chosen_mothod_id = $_POST['payment-method_payop'];
    $key_for_currency = 'available-currency_payop-' . $chosen_mothod_id;
    $currency_for_method = $_POST[$key_for_currency];
    update_post_meta( $order_id, '_payment_method_payop', $chosen_mothod_id );
    update_post_meta( $order_id, '_currencies_for_payment_method_payop', $currency_for_method );
    update_post_meta( $order_id, '_test_all', $_POST );
}


add_action( 'woocommerce_admin_order_data_after_billing_address', 'payop_checkout_field_display_admin_order_meta', 10, 1 );
function payop_checkout_field_display_admin_order_meta($order){
    $method = get_post_meta( $order->id, '_payment_method_payop', true );
    if($method != 'payop')
        return;

    $payop = get_post_meta( $order->id, 'payop', true );

    echo '<p><strong>'.__( 'payop' ).':</strong> ' . $payop . '</p>';
}

/** 
 *
 */
function woocommerce_payop()
{
    load_plugin_textdomain('payop-woocommerce', false, plugin_basename(dirname(__FILE__)) . '/languages');

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    if (class_exists('WC_Payop')) {
        return;
    }

    class WC_Payop extends WC_Payment_Gateway
    {

        public function __construct()
        {
            global $woocommerce;

            $plugin_dir = plugin_dir_url(__FILE__);

            $this->apiUrl = 'https://payop.com/v1/invoices/create';

            $this->chosen_method = "381";

            $this->id = 'payop';
            $this->icon = apply_filters('woocommerce_payop_icon', '' . $plugin_dir . 'all_methods.png');
            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->public_key = $this->get_option('public_key');
            $this->jwt_token = $this->get_option('jwt_token');
            $this->secret_key = $this->get_option('secret_key');
            $this->skip_confirm = $this->get_option('skip_confirm');
            $this->lifetime = $this->get_option('lifetime'); 
            $this->auto_complete = $this->get_option('auto_complete');
            $this->payment_method = "381";
            $this->language = $this->get_option('payment_form_language');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');
            $this->accepted_methods = [381, 3822, 200001, 200003, 360, 200946, 200947, 200948, 200949, 203821, 210000];


 
            //Actions
            add_action('payop-ipn-request', [$this, 'successful_request']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);

            //Payment listner/API hook
            add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);

            //Save options
            add_action('woocommerce_update_options_payment_gateways_payop', [$this, 'process_admin_options']);

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        public function is_valid_for_use()
        {
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 0.1
         **/
        public function admin_options()
        {
            global $woocommerce;

            ?>
            <h3><?php _e('PayOp', 'payop-woocommerce'); ?></h3>
            <p><?php _e('Take payments via PayOp.', 'payop-woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>

            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>

            <?php else : ?>
                <div class="inline error">
                    <p>
                        <strong><?php _e('Gateway is disabled', 'payop-woocommerce'); ?></strong>:
                        <?php _e('PayOp does not support the currency of your store.', 'payop-woocommerce'); ?>
                    </p>
                </div>
            <?php
            endif;

        } // End admin_options()

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */

        public function init_form_fields()
        {

            global $woocommerce;

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable PayOp payments', 'payop-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable/Disable', 'payop-woocommerce'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => __('Name of payment gateway', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('The name of the payment gateway that the user see when placing the order', 'payop-woocommerce'),
                    'default' => __('PayOp', 'payop-woocommerce'),
                ),
                'public_key' => array(
                    'title' => __('Public key', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
                    'default' => '',
                ),
                'secret_key' => array(
                    'title' => __('Secret key', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
                    'default' => '',
                ),
                'description' => array(
                    'title' => __('Description', 'payop-woocommerce'),
                    'type' => 'textarea',
                    'description' => __(
                        'Description of the payment gateway that the client will see on your site.',
                        'payop-woocommerce'
                    ),
                    'default' => __('Accept online payments using PayOp.com', 'payop-woocommerce'),
                ),
                'auto_complete' => array(
                    'title' => __('Order completion', 'payop-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __(
                        'Automatic transfer of the order to the status "Completed" after successful payment',
                        'payop-woocommerce'
                    ),
                    'description' => __('', 'payop-woocommerce'),
                    'default' => '1',
                ),
                'skip_confirm' => array(
                    'title' => __('Skip confirmation', 'payop-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __(
                        'Skip page checkout confirmation',
                        'payop-woocommerce'
                    ),
                    'description' => __('', 'payop-woocommerce'),
                    'default' => 'yes',
                ),
                'payment_form_language' => array(
                    'title' => __('Payment form language', 'payop-woocommerce'),
                    'type' => 'select',
                    'description' => __('Select the language of the payment form for your store', 'payop-woocommerce'),
                    'default' => 'en',
                    'options' => array(
                        'en' => __('English', 'payop-woocommerce'),
                        'ru' => __('Russian', 'payop-woocommerce'),
                    ),
                ),
                'jwt_token' => array(
                    'title' => __('JWT Token', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('Required for Directpay! Issued in the client panel https://payop.com/en/profile/settings/jwt-token', 'payop-woocommerce'),
                    'default' => '',
                ),
                'payment_method' => array(
                    'title' => __('Directpay payment method', 'wcs'),
                    'type' => 'select',
                    'description' => (count($this->get_payments_methods_options(true )) > 1) ?  __('Select default payment method to directpay. (In list only available to your account payment methods.) <br><span style="color: red;">This function is in experimental mode! Use this option with caution.<br>
                ilability of the payment method or not all submitted fields can make payment impossible</span>', 'payop-woocommerce') : "<span style='color: red'>JWT TOKEN REQUIRED FOR THIS FEATURE!!! </span><br>" . __('Select default payment method to directpay. (In list only available to your account payment methods.) <br><span style="color: red;">This function is in experimental mode! Use this option with caution.<br>
                The unavailability of the payment method or not all submitted fields can make payment impossible</span>', 'payop-woocommerce'),
                    'id' => 'wcs_chosen_categories',
                    'default' => '',
                    'css' => '',
                    'options' => $this->get_payments_methods_options(true),
                ),
            );
        }
//
        /**
         * Дополнительная информация в форме выбора способа оплаты
         **/
        public function payment_fields()
        {
            

            $paymentMethods = $this->get_payments_methods_options(false);
            $user_country = wcpbc_get_woocommerce_country();
            ?>

            <div id="custom_input">
            <?php   
            
            foreach ($paymentMethods as $payment_method) {
                if(in_array( $payment_method['identifier'], $this->accepted_methods ) and in_array( $user_country, $payment_method['countries'] )) { 
                ?>
                    <p class="form-row form-row-wide">
                        <input type="radio" name="payment-method_payop" value="<?php echo $payment_method['identifier']; ?>" id="<?php echo $payment_method['title']; ?>">
                        <label for="<?php echo $payment_method['title']; ?>" class=""><?php echo $payment_method['title']; ?></label>
                    </p>
                    <input type="hidden" name="available-currency_payop-<?php echo $payment_method['identifier']; ?>" value="<?php print_r(implode(',', $payment_method['currencies'])); ?>">
                <?php
                }
            } 
            ?>
            
            </div>
            <?php
        }
        

        /**
         * Select payment methods
         **/
        private function get_payments_methods_options( $default )
        {
            $public_key = $this->get_option('public_key');
            $request_url = 'https://payop.com/v1/instrument-settings/payment-methods/available-for-application/'. str_replace('application-', '', $public_key);

            if ($default) {
                $methodOptions = array('none' => __('None direct pay', 'woocommerce'));
            }

            $arrData['jwt_token'] = $this->get_option('jwt_token');
            $args = array(
                'timeout' => 10,
                'sslverify' => false,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $arrData['jwt_token']
                ),
            );
            if (!empty($arrData['jwt_token'])) {
                $response = wp_remote_get($request_url, $args);
                $response = wp_remote_retrieve_body($response);
                $response = json_decode($response, true);
            }
            if (!empty($response['data'])) {
                $this->valid_token = true;
                return $response['data'];
                foreach ($response['data'] as $item) {
                    if ($default) {
                        $methodOptions[$item['identifier']] = __($item['title'], 'woocommerce');
                    } else {
                        $methodOptions[] = $item['identifier'];
                    }
                }
            }
            return $methodOptions;

        }

        public function generate_form( $order_id )
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            $chosen_method = get_post_meta( $order_id, '_payment_method_payop', true );

            $out_summ = number_format($order->get_total(), 4, '.', '');
            $paymentMethods = $this->get_payments_methods_options(false);

            $amount = $this->get_current_currency( $order_id, $out_summ, $order->get_currency() );
            
            error_log("Получили стомость: " . $amount[0] . " | Валюта: " . $amount[1]);

            error_log("Выбранный способ: " . $chosen_method);

            $arrData = [];
            $arrData['publicKey'] = $this->public_key;
            $arrData['order'] = [];
            $arrData['order']['id'] = strval($order_id);
            $arrData['order']['amount'] = $amount[0];
            $arrData['order']['currency'] = $amount[1];

            $orderInfo = [
                'id' => $order_id,
                'amount' => $amount[0],
                'currency' => $amount[1]
            ];

            ksort($orderInfo, SORT_STRING);
            $dataSet = array_values($orderInfo);
            $dataSet[]  = $this->secret_key;
            $arrData['signature'] = hash('sha256', implode(':', $dataSet));

            // if ($paymentMethods && in_array($this->payment_method, $paymentMethods)) {
            //     $arrData['paymentMethod'] = $this->payment_method;
            // }
            $arrData['paymentMethod'] =  $chosen_method;

            $arrData['order']['description'] = __('Payment order #', 'payop-woocommerce') . $order_id;
            $arrData['order']['items'] = [];
            $arrData['payer']['email'] = $order->get_billing_email();
            $arrData['payer']['name'] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

            if ($order->get_billing_phone()) {
                $arrData['payer']['phone'] = $order->get_billing_phone();
            }

            $arrData['language'] = $this->language; 

            $arrData['resultUrl'] = get_site_url() . "/?wc-api=wc_payop&payop=success&orderId={$order_id}";

            $arrData['failPath'] = get_site_url() . "/?wc-api=wc_payop&payop=fail&orderId={$order_id}";

            error_log(json_encode($arrData));

            $response = $this->apiRequest($arrData, 'identifier');
            if(isset($response['messages'])) {
                return '<p>' . __('Request to payment service was sent incorrectly', 'payop-woocommerce') . '</p><br><p>' . $response['messages'] .'</p>';
            }

            $action_adr = 'https://payop.com/' . $this->language . '/payment/invoice-preprocessing/' . $response;

            if ($this->skip_confirm === "yes"){
                wp_redirect(esc_url($action_adr));
                exit;
            }

            $args_array = [];

            return '<form action="' . esc_url($action_adr) . '" method="GET" id="payop_payment_form">' . "\n" .
                implode("\n", $args_array) .
                '<input type="submit" class="button alt" id="submit_payop_payment_form" value="' . __('Pay', 'payop-woocommerce') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Refuse payment & return to cart', 'payop-woocommerce') . '</a>' . "\n" .
                '</form>';
        }


        public function get_current_currency( $order_id, $amount, $order_currency ) {
            $currency_for_chosen_method = get_post_meta($order_id, '_currencies_for_payment_method_payop', true);
            $currency_for_chosen_method_array = explode(',', $currency_for_chosen_method);
            error_log("Получаем валюту и номинал. Исходный номинал: " . $amount . " | Валюта: " . $order_currency);
            error_log("Разрешенные валюты для этой оплты: " . $currency_for_chosen_method );
            if(in_array($order_currency, $currency_for_chosen_method_array)) {
                error_log("Валюта есть в списке и мы возрващаем исходные данные");
                return array($amount, $order_currency);
            } else {
                $order_currency = strtolower($order_currency);
                $currency_payop = strtolower($currency_for_chosen_method_array[0]);

                error_log("Валюты нет в списке, получаем курс валюты первого в списке разрешенных, это " . $currency_payop);

                $url = 'https://cdn.jsdelivr.net/gh/fawazahmed0/currency-api@1/latest/currencies/'.$order_currency.'/'.$currency_payop.'.json';
                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                ));

                $response = curl_exec($curl);

                curl_close($curl);

                error_log("Ответ апи: " . $response);
                $response = json_decode($response, true);

                $rate = $response[$currency_payop];

                error_log("Курс валюты: " . $rate);

                $total = $amount * $rate;

                $total = number_format($total, 4, '.', '');

                error_log("Стоимость в новой валюте: " . $total);

                error_log("Возвращаем данные: " . $total . " | Валюта: " . $currency_for_chosen_method_array[0]);

                return array($total, $currency_for_chosen_method_array[0]); 
            }

        }

        

        public function process_payment( $order_id )
        {
            $order = new WC_Order($order_id);
            $order_key = $order->get_order_key();



	        remove_all_filters('woocommerce_get_checkout_order_received_url');

            // Return thankyou redirect
            return [
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->get_id(),
                add_query_arg('key', $order_key, $order->get_checkout_order_received_url())),
            ];
        }

        public function receipt_page( $order )
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay', 'payop-woocommerce') . '</p>';

            echo $this->generate_form($order);
        }

        /**
         * Check PayOp IPN validity
         *
         * @param array $posted
         *
         * @return bool|string
         */
        public function check_ipn_request_is_valid( $posted )
        {
            $invoiceId = !empty($posted['invoice']['id']) ? $posted['invoice']['id'] : null;
            $txId = !empty($posted['invoice']['txid']) ? $posted['invoice']['txid'] : null;
            $orderId = !empty($posted['transaction']['order']['id']) ? $posted['transaction']['order']['id'] : null;
            $signature = !empty($posted['signature']) ? $posted['signature'] : null;
            // check IPN V1
            if (!$invoiceId) {
                if (!$signature) {
                    return 'Empty invoice id';
                } else {
                    $orderId = !empty($posted['orderId']) ? $posted['orderId'] : null;
                    if (!$orderId) {
                        return 'Empty order id V1';
                    }
                    $order = new WC_Order($orderId);
                    $currency = $order->get_currency();
                    $amount = number_format($order->get_total(), 4, '.', '');

                    $status = $posted['status'];

                    if ($status !== 'success' && $status !== 'error') {
                        return 'Status is not valid';
                    }

                    $o = ['id' => $orderId, 'amount' => $amount, 'currency' => $currency];

                    ksort($o, SORT_STRING);

                    $dataSet = array_values($o);

                    if ($status) {
                        array_push($dataSet, $status);
                    }

                    array_push($dataSet, $this->secret_key);

                    if ($posted['signature'] === hash('sha256', implode(':', $dataSet))) {
                        return 'V1';
                    }
                    return 'Invalid signature';
                }
            }
            if (!$txId) {
               return 'Empty transaction id';
            }
            if (!$orderId) {
                return 'Empty order id V2';
            }

            $order = new WC_Order($orderId);
            $currency = $order->get_currency();
            $state = $posted['transaction']['state'];
            if (!(1 <= $state && $state <= 5)) {
                return 'State is not valid';
            }
            return 'V2';
        }

        /**
         * Check Response
         **/
        public function check_ipn_response()
        {
            global $woocommerce;

            $requestType = !empty($_GET['payop']) ? $_GET['payop'] : '';

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $postedData = json_decode(file_get_contents('php://input'), true);
                if (!is_array($postedData)) {
                    $postedData = [];
                }
            } else {
                $postedData = $_GET;
            }

            error_log("Ответ IPN: " . json_encode($postedData));


            switch ($requestType) {
                case 'result':
                    @ob_clean();

                    $postedData = wp_unslash($postedData);
                    $valid = $this->check_ipn_request_is_valid($postedData);
                    if ($valid === 'V2'){
                        if ($postedData['transaction']['state'] === 4) {
                            wp_die('Status wait', 'Status wait', 200);
                        }
                        $orderId = $postedData['transaction']['order']['id'];
                        $order = new WC_Order($orderId);
                        if ($postedData['transaction']['state'] === 2) {
                            if ($this->auto_complete === 'yes') {
                                $order->update_status('completed', __('Payment successfully paid', 'payop-woocommerce'));
                            } else {
                                $order->update_status('processing', __('Payment successfully paid', 'payop-woocommerce'));
                            }
                            wp_die('Status success', 'Status success', 200);
                        } elseif ($postedData['transaction']['state'] === 3 or $postedData['transaction']['state'] === 5) {
                            $order->update_status('failed', __('Payment not paid', 'payop-woocommerce'));
                            wp_die('Status fail', 'Status fail', 200);
                        }
                        do_action('payop-ipn-request', $postedData);
                    } elseif ($valid = 'V1') {
                        if ($postedData['status'] === 'wait') {
                            wp_die('Status wait', 'Status wait', 200);
                        }
                        $orderId = $postedData['orderId'];
                        $order = new WC_Order($orderId);

                        if ($postedData['status'] === 'success') {
                            if ($this->auto_complete === 'yes') {
                                $order->update_status('completed', __('Payment successfully paid', 'payop-woocommerce'));
                            } else {
                                $order->update_status('processing', __('Payment successfully paid', 'payop-woocommerce'));
                            }
                            wp_die('Status success', 'Status success', 200);
                        } elseif ($postedData['status'] === 'error') {
                            $order->update_status('failed', __('Payment not paid', 'payop-woocommerce'));
                            wp_die('Status fail', 'Status fail', 200);
                        }
                        do_action('payop-ipn-request', $postedData);
                    } else {
                        wp_die($valid, $valid, 400);
                    }
                    break;
                case 'success':
                    $orderId = $postedData['transaction']['order']['id'] ? $postedData['transaction']['order']['id'] : $postedData['orderId'];

                    $order = new WC_Order($orderId);

                    WC()->cart->empty_cart();

                    wp_redirect($this->get_return_url($order));
                    break;
                case 'fail':
                    $orderId = $postedData['transaction']['order']['id'] ? $postedData['transaction']['order']['id'] : $postedData['orderId'];
                    $order = new WC_Order($orderId);
                    wp_redirect($order->get_cancel_order_url_raw());
                    $text = json_encode($postedData);
                    $order->add_order_note( 'Оплата не прошла в PayOp' );
                    break;
                default:
                    wp_die('Invalid request', 'Invalid request', 400);
            }
        }

        public function successful_request( $posted )
        {
            global $woocommerce;

            $orderId = $posted['transaction']['order']['id'] ? $posted['transaction']['order']['id'] : $posted['orderId'];

            $order = new WC_Order($orderId);

            // Check order not already completed
            if ($order->status == 'completed') {
                exit;
            }

            // Payment completed
            $order->add_order_note(__('Payment completed successfully', 'payop-woocommerce'));

            $order->payment_complete();

            exit;
        }

        public function apiRequest( $arrData = [], $retrieved_header)
        {

            $request_url = $this->apiUrl;
            $args = array(
                'sslverify' => false,
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($arrData),
            );
            $response = wp_remote_post($request_url, $args);
            if ($retrieved_header !== ''){
                $response = wp_remote_retrieve_header($response, $retrieved_header);
                if (!empty($response)){
                    return $response;
                }
            } else {
                $response = wp_remote_retrieve_body($response);
            }
            return json_decode($response, true);
        }

    }
    /**
     * Add the gateway to WooCommerce
     **/
    function add_payop_gateway( $methods )
    {
        $methods[] = 'WC_Payop';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_payop_gateway');

    /**
     * Add settings button in plugin area
     **/
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_plugin_page_settings_link');

    function add_plugin_page_settings_link( $links )
    {
        $links[] = '<a href="' .
            admin_url('admin.php?page=wc-settings&tab=checkout&section=payop') .
            '">' . __('Settings') . '</a>';
        return $links;
    }

}