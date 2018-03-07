﻿<?php
/*
Plugin Name: WooCommerce Apirone gateway
Plugin URI: http://apirone.com
Description: Bitcoin Forwarding Gateway for Woocoomerce.
Version: 1.0
Author: Apirone LLC
Author URI: http://www.apirone.com
Copyright: © 2017 Apirone.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
session_start();
require_once 'config.php'; //configuration files
require_once 'woocommerce-payment-name.php'; //payment gateway constants

function logger($var)
{
    if ($var) {
        $date   = '<---------- ' . date('Y-m-d H:i:s') . " ---------->\n";
        $result = $var;
        if (is_array($var) || is_object($var)) {
            $result = print_r($var, 1);
        }
        $result .= "\n";
        $path = 'wp-content/plugins/woocommerce-apirone/apirone-payment.log'; //defaults wp-content/plugins/woocomerce-apirone/
        error_log($date . $result, 3, $path);
        return true;
    }
    return false;
}

global $apirone_db_version;
$apirone_db_version = '1.01';

function jal_install()
{
    global $wpdb;
    global $apirone_db_version;
    
    $sale_table = $wpdb->prefix . 'woocommerce_apirone_sale';
    $transactions_table = $wpdb->prefix . 'woocommerce_apirone_transactions';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $sale_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        address text NOT NULL,
        order_id int DEFAULT '0' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql .= "CREATE TABLE $transactions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        paid bigint DEFAULT '0' NOT NULL,
        confirmations int DEFAULT '0' NOT NULL,
        thash text NOT NULL,
        order_id int DEFAULT '0' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    add_option('apirone_db_version', $apirone_db_version);
}

register_activation_hook(__FILE__, 'jal_install');

function apirone_update_db_check()
{
    global $apirone_db_version;
    if (get_site_option('apirone_db_version') != $apirone_db_version) {
        jal_install();
    }
}

add_action('plugins_loaded', 'apirone_update_db_check');

function Apirone_enqueue_script()
{
    wp_enqueue_script('apirone_script', plugin_dir_url(__FILE__) . 'apirone.js', array(
        'jquery'
    ), '1.0');
}
add_action('wp_enqueue_scripts', 'Apirone_enqueue_script');

if (DEBUG) {
    // Display errors
    ini_set('display_errors', 1);
    error_reporting(E_ALL & ~E_NOTICE);
}

if (!defined('ABSPATH'))
    exit; // Exit if accessed directly


/* Add a custom payment class to WC
------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_apironepayment', 0);
function woocommerce_apironepayment()
{
    if (!class_exists('WC_Payment_Gateway'))
        return; // if the WC payment gateway class is not available, do nothing
    if (class_exists('WC_APIRONE'))
        return;
    
    class WC_APIRONE extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $plugin_dir = plugin_dir_url(__FILE__);
            
            global $woocommerce;
            $this->id         = APIRONEPAYMENT_ID;
            $this->has_fields = false;
            $this->liveurl    = PROD_URL;
            $this->testurl    = TEST_URL;
            $this->icon       = ICON;
            $this->title       = APIRONEPAYMENT_TITLE_1;
            $this->description = APIRONEPAYMENT_TITLE_2;
            $this->testmode    = 'no';
            
            // Load the settings
            $this->init_form_fields();
            $this->init_settings();
            
            // Define user set variables
            $this->address     = $this->get_option('address');
            /*$this->testmode    = $this->get_option('testmode');*/
            
            // Actions
            add_action('valid-apironepayment-standard-ipn-reques', array(
                $this,
                'successful_request'
            ));
            add_action('woocommerce_receipt_' . $this->id, array(
                $this,
                'receipt_page'
            ));
            
            //Save our GW Options into Woocommerce
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
            
            // Payment listener/API hook
            add_action('woocommerce_api_callback_apirone', 'check_response');
            add_action('woocommerce_api_check_payment', 'ajax_response');
            
            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }
        
        /**
         * Check if this gateway is enabled and available in the user's country. Defaults it's USD
         */
        function is_valid_for_use()
        {
            if (!in_array(get_option('woocommerce_currency'), array(
                'USD'
            ))) {
                return false;
            }
            return true;
        }
        
        /**
         * Admin Panel Options
         */
        public function admin_options()
        {
?>
        <h3><?php _e(APIRONEPAYMENT_TITLE_1, 'woocommerce');?></h3>
        <p><?php _e(APIRONEPAYMENT_TITLE_2, 'woocommerce');?></p>

      <?php if ($this->is_valid_for_use()): ?>

        <table class="form-table">

        <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html(); ?>
    </table><!--/.form-table-->

    <?php else: ?>
        <div class="inline error"><p><strong><?php
                _e('Gateway offline', 'woocommerce');
?></strong>: <?php _e($this->id . ' don\'t support your shop currency', 'woocommerce'); ?></p></div>
        <?php endif;
            
        } // End admin_options()
        
        /**
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('On/off', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('On', 'woocommerce'),
                    'default' => 'no'
                ),
                'address' => array(
                    'title' => __('Destination Bitcoin address', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your Destination Bitcoin address', 'woocommerce'),
                    'default' => ''
                ),
                /*'testmode' => array(
                    'title' => __('Test Mode', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('On', 'woocommerce'),
                    'description' => __('This is test mode without money transfer.', 'woocommerce'),
                    'default' => 'no'
                ),*/
            );
        }
        
        /**
         * Generate the dibs button link
         */
        static function convert_to_btc($currency, $value)
        {
            $apironeConvertTotalCost = curl_init();
            $apirone_tobtc           = 'https://apirone.com/api/v1/tobtc?currency=' . $currency . '&value=' . $value;
            curl_setopt_array($apironeConvertTotalCost, array(
                CURLOPT_URL => $apirone_tobtc,
                CURLOPT_RETURNTRANSFER => 1
            ));
            $response_btc = curl_exec($apironeConvertTotalCost);
            curl_close($apironeConvertTotalCost);
            return $response_btc;
        }

        //checks that order has sale
        static function sale_exists($order_id, $input_address)
        {
            global $wpdb;
            $sale_table = $wpdb->prefix . 'woocommerce_apirone_sale';
            $sales = $wpdb->get_results("SELECT * FROM $sale_table WHERE order_id = $order_id AND address = '$input_address'");
            if ($sales[0]->address == $input_address) {return true;} else {return false;};
        }

        // function that checks what user complete full payment for order
        static function check_remains($order_id)
        {
            global $wpdb;
            global $woocommerce;
            $order = new WC_Order($order_id);
            $total = WC_APIRONE::convert_to_btc('USD', $order->order_total);
            $transactions_table = $wpdb->prefix . 'woocommerce_apirone_transactions';
            $transactions = $wpdb->get_results("SELECT * FROM $transactions_table WHERE order_id = $order_id");
            $remains = 0;
            $total_paid = 0;
            $total_empty = 0;
            foreach ($transactions as $transaction) {
                if ($transaction->thash == "empty") $total_empty+=$transaction->paid;
                $total_paid+=$transaction->paid;
            }
            $total_paid/=100000000;
            $total_empty/=100000000;
            $remains = $total - $total_paid;
            $remains_wo_empty = $remains + $total_empty;
            if ($remains_wo_empty > 0) {
                return false;
            } else {
                return true;
            };
        }

        static function remains_to_pay($order_id)
        {   
            global $woocommerce;
            global $wpdb;
            $order = new WC_Order($order_id);
            $transactions_table = $wpdb->prefix . 'woocommerce_apirone_transactions';
            $transactions = $wpdb->get_results("SELECT * FROM $transactions_table WHERE order_id = $order_id");
            $total_paid = 0;
            foreach ($transactions as $transaction) {
                $total_paid+=$transaction->paid;
            }
            $response_btc = WC_APIRONE::convert_to_btc('USD', $order->order_total);
            $remains = $response_btc - $total_paid/100000000;
            if($remains < 0) $remains = 0;  
            return $remains;
        }
        
        public function generate_form($order_id)
        {
            global $woocommerce;
            global $wpdb;
            $sale_table = $wpdb->prefix . 'woocommerce_apirone_sale';
            
            $order = new WC_Order($order_id);
            
            if ($this->testmode == 'yes') {
                $apirone_adr = $this->testurl;
            } else {
                $apirone_adr = $this->liveurl;
            }
            
            $_SESSION['testmode'] = $this->testmode;
            
            
            $response_btc = $this->convert_to_btc('USD', $order->order_total);
            /**
             * Args for Forward query
             */
            
            $sales = $wpdb->get_results("SELECT * FROM $sale_table WHERE order_id = $order_id");
            
            if ($sales == null) {
                $args           = array(
                    'address' => $this->address,
                    'callback' => urlencode(SHOP_URL . '?wc-api=callback_apirone&key=' . $order->order_key . '&order_id=' . $order_id)
                );
                $apirone_create = $apirone_adr . '?method=create&address=' . $args['address'] . '&callback=' . $args['callback'];
                
                $apironeCurl = curl_init();
                if ($this->testmode == 'yes') {
                    curl_setopt_array($apironeCurl, array(
                        CURLOPT_URL => $apirone_create,
                        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                        CURLOPT_RETURNTRANSFER => 1
                    ));
                } else {
                    curl_setopt_array($apironeCurl, array(
                        CURLOPT_URL => $apirone_create,
                        CURLOPT_RETURNTRANSFER => 1
                    ));
                }
                $response_create = curl_exec($apironeCurl);
                curl_close($apironeCurl);
                $response_create = json_decode($response_create, true);
                if ($response_create['input_address'] != null){
                    $wpdb->insert($sale_table, array(
                        'time' => current_time('mysql'),
                        'order_id' => $order_id,
                        'address' => $response_create['input_address']
                     ));
                } else{
                    echo "No Input Address from Apirone :(";
                }
            } else {
                
                $response_create['input_address'] = $sales[0]->address;
            }
            if ($response_create['input_address'] != null){
                echo '<div class="woocommerce"><ul class="order_details"><li>Please send exactly <strong>' . $response_btc . ' BTC</strong> </li><li>for this address:<strong>' . $response_create['input_address'];
                echo '</strong></li><li><img src="https://apirone.com/api/v1/qr?message=' . urlencode("bitcoin:" . $response_create['input_address'] . "?amount=" . $response_btc . "&label=Apirone") . '"></li><li class="apirone_result"></li></ul></div>';
            }
            if (DEBUG && !is_null($response_create)) {
                logger('Request: ' . $apirone_create . ': ' . print_r($args, true) . 'Response: ' . $response);
            }
        }
        
        /**
         * Process the payment and return the result
         */
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
            );
        }

        /**
         * Receipt page
         */
        function receipt_page($order)
        {
            echo $this->generate_form($order);
        }
    }
    
    function ajax_response()
    {
        if (isset($_GET['key']) && isset($_GET['order']) && ($_GET['order'] != 'undefined')) {
            global $woocommerce;
            global $wpdb;
            $transactions_table = $wpdb->prefix . 'woocommerce_apirone_transactions';
            $order = wc_get_order($_GET['order']);
            if (isset($_GET['order'])) {
                $transactions = $wpdb->get_results("SELECT * FROM $transactions_table WHERE order_id = ".$_GET['order']);
            }
            $empty = 0;
            $value = 0;
            $paid_value = 0;
            foreach ($transactions as $transaction) {
                if ($transaction->thash == "empty"){
                    $empty = 1; // has empty value in thash
                    $value = $transaction->paid;
                } else{
                    $paid_value = $transaction->paid;
                    $confirmed = $transaction->thash;
                }              
            }
            if ($order == '') {
                echo 'Error';
                exit;
            }
            $response_btc = WC_APIRONE::convert_to_btc('USD', $order->order_total);
            if ($order->status == 'processing' && WC_APIRONE::check_remains($_GET['order'])) {
                echo 'Payment accepted. Thank You!';
            } else {
                if($empty){
                echo "Transaction in progress... <b>Amount</b>: " . number_format($value/100000000, 8, '.', '') . " BTC. <b>Remains to pay</b>:".number_format(WC_APIRONE::remains_to_pay($_GET['order']), 8, '.', '');
                } else{
                echo "Waiting for payment... ";
                if($paid_value){
                    echo "<b>Last Confirmed</b>: ".  number_format($paid_value/100000000, 8, '.', '') . " BTC, <b>Transaction hash</b>: ". $confirmed ." <b>Remains to pay</b>: ".number_format(WC_APIRONE::remains_to_pay($_GET['order']), 8, '.', '') . ' BTC';
                }
            }
            }
            exit;
        }
    }

    /**
     * Check response
     */
    function check_response()
    {
        global $woocommerce;
        global $wpdb;
        $sale_table = $wpdb->prefix . 'woocommerce_apirone_sale';
        $transactions_table = $wpdb->prefix . 'woocommerce_apirone_transactions';
        $test = 0; //Nothing to do (empty callback, wrong order Id or Input Address)
        if (DEBUG) {
                logger('Callback' . $_SERVER['REQUEST_URI']);
        }
        if (isset($_GET['confirmations']) AND isset($_GET['value']) AND WC_APIRONE::sale_exists($_GET['order_id'], $_GET['input_address'])) {
            $test = 1; //transaction exists
            if ($_SESSION['testmode'] == 'yes') {
                $apirone_adr = TEST_URL;
            } else {
                $apirone_adr = PROD_URL;
            }
            $apirone_order = array(
                'confirmations' => $_GET['confirmations'],
                'orderId' => $_GET['order_id'], // order id
                'key' => $_GET['key'],
                'value' => $_GET['value'],
                'transaction_hash' => $_GET['transaction_hash'],
                'input_address' => $_GET['input_address']
            ); 
            if (isset($apirone_order['value']) && isset($apirone_order['input_address']) && isset($apirone_order['confirmations']) && !isset($apirone_order['transaction_hash'])) {
                $order = new WC_Order($apirone_order['orderId']);
                if ($apirone_order['key'] == $order->order_key) {
                $transactions = $wpdb->get_results("SELECT * FROM $transactions_table WHERE order_id = ".$apirone_order['orderId']);
                $flag = 1; //no simular transactions
                foreach ($transactions as $transaction) {
                    if(($transaction->thash == 'empty') && ($transaction->paid == $apirone_order['value'])){
                        $flag = 0; //simular transaction detected
                        break;
                    }
                }
                if($flag){
                    $wpdb->insert($transactions_table, array(
                        'time' => current_time('mysql'),
                        'confirmations' => $apirone_order['confirmations'],
                        'paid' => $apirone_order['value'],
                        'order_id' => $apirone_order['orderId'],
                        'thash' => 'empty'
                    ));
                $test = 2; //insert new transaction in DB without transaction hash
                } else {
                        $update_query = array(
                            'time' => current_time('mysql'),
                            'confirmations' => $apirone_order['confirmations'],
                        );
                        $where = array('paid' => $apirone_order['value'], 'thash' => 'empty');
                        $wpdb->update($transactions_table, $update_query, $where); 
                $test = 3; //update existing transaction
                    }
                }
            }

                if (isset($apirone_order['value']) && isset($apirone_order['input_address']) && isset($apirone_order['confirmations']) && isset($apirone_order['transaction_hash'])) {
                $test = 4; // callback with transaction_hash
                $apirone_order = array(
                    'confirmations' => $_GET['confirmations'],
                    'orderId' => $_GET['order_id'], // order id
                    'key' => $_GET['key'],
                    'value' => $_GET['value'],
                    'transaction_hash' => $_GET['transaction_hash'],
                    'input_address' => $_GET['input_address']
                );
                $transactions  = $wpdb->get_results("SELECT * FROM $transactions_table WHERE order_id = ".$apirone_order['orderId']);
                $sales = $wpdb->get_results("SELECT * FROM $sale_table WHERE order_id = ".$apirone_order['orderId']);
                $order = new WC_Order($apirone_order['orderId']);
                if ($sales == null) $test = 5; //no such information about input_address
                $flag = 1; //new transaction
                $empty = 0; //unconfirmed transaction
                   if ($apirone_order['key'] == $order->order_key) {
                        $test = 6; //WP key is valid but confirmations smaller that value from config or input_address not equivalent from DB
                        if (($apirone_order['confirmations'] >= COUNT_CONFIRMATIONS) && ($apirone_order['input_address'] == $sales[0]->address)) {
                            $test = 7; //valid transaction
                            foreach ($transactions as $transaction) {
                                $test = 8; //finding same transaction in DB
                                if($apirone_order['transaction_hash'] == $transaction->thash){
                                    $test = 9; // same transaction was in DB
                                    $flag = 0; // same transaction was in DB
                                    break;
                                }
                                if(($apirone_order['value'] == $transaction->paid) && ($transaction->thash == 'empty')){
                                    $empty = 1; //empty find
                                }
                            }
                        }
                    }
                if($flag){
                    $test = 10; //writing into DB, taking notes
                    $response_btc = WC_APIRONE::convert_to_btc('USD', $order->order_total);
                    $notes        = 'Input Address: ' . $apirone_order['input_address'] . ', Transaction hash: ' . $apirone_order['transaction_hash'] . 'Payment in BTC:' . $apirone_order['value']/100000000;
                    if ($response_btc > $apirone_order['value']/100000000)
                        $notes .= '. User trasfrer not enough money in USD. Waiting for next payment.';
                    if ($response_btc < $apirone_order['value']/100000000)
                        $notes .= '. User trasfrer more money than You need in USD.';

                    if($empty){
                    $update_query = array(
                        'time' => current_time('mysql'),
                        'confirmations' => $apirone_order['confirmations'],
                        'thash' => $apirone_order['transaction_hash']
                    );
                    $where = array(
                        'paid' => $apirone_order['value'],
                        'order_id' => $apirone_order['orderId'],
                        'thash' => 'empty'
                    );
                    $wpdb->update($transactions_table, $update_query, $where);
                    } else {

                    $wpdb->insert($transactions_table, array(
                            'time' => current_time('mysql'),
                            'confirmations' => $apirone_order['confirmations'],
                            'paid' => $apirone_order['value'],
                            'order_id' => $apirone_order['orderId'],
                            'thash' => $apirone_order['transaction_hash']
                        ));                        
                    } 
                    if (WC_APIRONE::check_remains($apirone_order['orderId'])){ //checking that payment is complete, if not enough money on payment it's not completed
                    $order->update_status('processing', __('Payment complete', 'woocommerce'));
                    WC()->cart->empty_cart();
                    $order->payment_complete();
                    }
                    $order->add_order_note("Payment accepted: ".  $apirone_order['value']/100000000 . " BTC");
                    $order->add_order_note('Order total: '.$response_btc . ' BTC');

                    $test = '*ok*';
                }
            }
        }

        if(($apirone_order['confirmations'] >= MAX_CONFIRMATIONS) && (MAX_CONFIRMATIONS != 0)) {// if callback's confirmations count more than MAX_CONFIRMATIONS we answer *ok*
            $test="*ok*";
            if(DEBUG) {
                logger('Skipped transaction: ' .  $apirone_order['transaction_hash'] . ' with confirmations: ' . $apirone_order['confirmations']);
            };
        };
        print_r($test);//global output
        exit;
    }
    
    /**
     * Add apirone the gateway to WooCommerce
     */
    function add_apirone_gateway($methods)
    {
        $methods[] = 'WC_APIRONE';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_apirone_gateway');
}