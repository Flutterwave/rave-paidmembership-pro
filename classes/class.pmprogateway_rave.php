<?php
/**
 * Plugin Name: Rave Gateway for Paid Memberships Pro
 * Plugin URI: https://github.com/emmajiugo
 * Description: Rave Plugin Payment for Paid Memberships Pro
 * Version: 1.2
 * Author: Rave
 * License: GPLv2 or later
 */
defined('ABSPATH') or die('No script kiddies please!');
if (!function_exists('Rave_Pmp_Gateway_load')) {
    add_action('plugins_loaded', 'Rave_Pmp_Gateway_load', 20);

    DEFINE('KKD_RAVEPMP', "rave-paidmembershipspro");

    function Rave_Pmp_Gateway_load() 
    {
        // paid memberships pro required
        if (!class_exists('PMProGateway')) {
            return;
        }

        // load classes init method
        add_action('init', array('PMProGateway_Rave', 'init'));

        // plugin links
        add_filter('plugin_action_links', array('PMProGateway_Rave', 'plugin_action_links'), 10, 2);

        if (!class_exists('PMProGateway_Rave')) {
            /**
             * PMProGateway_Rave Class
             *
             * Handles Rave integration.
             *
             */
            class PMProGateway_Rave extends PMProGateway
            {

                function __construct($gateway = null)
                {
                    $this->gateway = $gateway;
                    $this->gateway_environment =  pmpro_getOption("gateway_environment");

                    return $this->gateway;
                }

                /**
                 * Run on WP init
                 */
                static function init() 
                {
                    //make sure Rave is a gateway option
                    add_filter('pmpro_gateways', array('PMProGateway_Rave', 'pmpro_gateways'));
                    
                    //add fields to payment settings
                    add_filter('pmpro_payment_options', array('PMProGateway_Rave', 'pmpro_payment_options'));
                    add_filter('pmpro_payment_option_fields', array('PMProGateway_Rave', 'pmpro_payment_option_fields'), 10, 2);
                    add_action('wp_ajax_kkd_pmpro_rave_ipn', array('PMProGateway_Rave', 'kkd_pmpro_rave_ipn'));
                    add_action('wp_ajax_nopriv_kkd_pmpro_rave_ipn', array('PMProGateway_Rave', 'kkd_pmpro_rave_ipn'));

                    //code to add at checkout
                    $gateway = pmpro_getGateway();
                    if ($gateway == "rave") {
                        add_filter('pmpro_include_billing_address_fields', '__return_false');
                        add_filter('pmpro_required_billing_fields', array('PMProGateway_Rave', 'pmpro_required_billing_fields'));
                        add_filter('pmpro_include_payment_information_fields', '__return_false');
                        add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_Rave', 'pmpro_checkout_before_change_membership_level'), 10, 2);
                        
                        add_filter('pmpro_gateways_with_pending_status', array('PMProGateway_Rave', 'pmpro_gateways_with_pending_status'));
                        add_filter('pmpro_pages_shortcode_checkout', array('PMProGateway_Rave', 'pmpro_pages_shortcode_checkout'), 20, 1);
                        add_filter('pmpro_checkout_default_submit_button', array('PMProGateway_Rave', 'pmpro_checkout_default_submit_button'));
                        // custom confirmation page
                        add_filter('pmpro_pages_shortcode_confirmation', array('PMProGateway_Rave', 'pmpro_pages_shortcode_confirmation'), 20, 1);
                    }
                }

                /**
                 * Redirect Settings to PMPro settings
                 */
                static function plugin_action_links($links, $file) 
                {
                    static $this_plugin;

                    if (false === isset($this_plugin) || true === empty($this_plugin)) {
                        $this_plugin = plugin_basename(__FILE__);
                    }

                    if ($file == $this_plugin) {
                        $settings_link = '<a href="'.admin_url('admin.php?page=pmpro-paymentsettings').'">'.__('Settings', KKD_RAVEPMP).'</a>';
                        array_unshift($links, $settings_link);
                    }

                    return $links;
                }
                static function pmpro_checkout_default_submit_button($show)
                {
                    global $gateway, $pmpro_requirebilling;
                    
                    //show our submit buttons
                    ?>
                    <span id="pmpro_submit_span">
                    <input type="hidden" name="submit-checkout" value="1" />		
                    <input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php if ($pmpro_requirebilling) { _e('Check Out with Rave', 'pmpro'); } else { _e('Submit and Confirm', 'pmpro');}?> &raquo;" />		
                    </span>
                    <?php
                
                    //don't show the default
                    return false;
                }

                /**
                 * Make sure Rave is in the gateways list
                 */
                static function pmpro_gateways($gateways) 
                {
                    if (empty($gateways['rave'])) {
                        $gateways = array_slice($gateways, 0, 1) + array("rave" => __('Rave', KKD_RAVEPMP)) + array_slice($gateways, 1);
                    }
                    return $gateways;
                }
                function kkd_pmpro_rave_ipn() 
                {
                    // print_r('kkd_pmpro_rave_ipn'); exit;
                    global $wpdb;
                    
                    define('SHORTINIT', true);
                    $input = @file_get_contents("php://input");
                    $response = json_decode($input);

                    $orderref = $response->orderRef;

                    // explode to check if orderRef has SUB as prefix
                    $result = explode("_", $orderref);
                    if (in_array("SUB", $result, TRUE)) {
                        self::renewpayment($response);
                    }

                    http_response_code(200);
                    exit();
                }

                /**
                 * Get a list of payment options that the Rave gateway needs/supports.
                 */
                static function getGatewayOptions() 
                {
                    $options = array (
                        'rave_tsk',
                        'rave_tpk',
                        'rave_lsk',
                        'rave_lpk',
                        'gateway_environment',
                        'currency',
                        'tax_state',
                        'tax_rate'
                    );

                    return $options;
                }

                /**
                 * Set payment options for payment settings page.
                 */
                static function pmpro_payment_options($options) 
                {
                    //get Rave options
                    $rave_options = self::getGatewayOptions();

                    //merge with others.
                    $options = array_merge($rave_options, $options);

                    return $options;
                }

                /**
                 * Display fields for Rave options.
                 */
                static function pmpro_payment_option_fields($values, $gateway) 
                {
                    ?>
                    <tr class="pmpro_settings_divider gateway gateway_rave" <?php if($gateway != "rave") { ?>style="display: none;"<?php } ?>>
                        <td colspan="2">
                            <?php _e('Rave API Configuration', 'pmpro'); ?>
                        </td>
                    </tr>
                    <tr class="gateway gateway_rave" <?php if($gateway != "rave") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label><?php _e('Webhook', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <p><?php _e('To fully integrate with Rave, be sure to use the following for your Webhook URL', 'pmpro');?> <pre style="color: red"><?php echo admin_url("admin-ajax.php") . "?action=kkd_pmpro_rave_ipn";?></pre></p>
                            
                        </td>
                    </tr>		
                    <tr class="gateway gateway_rave" <?php if($gateway != "rave") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="rave_tsk"><?php _e('Test Secret Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="rave_tsk" name="rave_tsk" size="60" value="<?php echo esc_attr($values['rave_tsk'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_rave" <?php if($gateway != "rave") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="rave_tpk"><?php _e('Test Public Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="rave_tpk" name="rave_tpk" size="60" value="<?php echo esc_attr($values['rave_tpk'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_rave" <?php if($gateway != "rave") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="rave_lsk"><?php _e('Live Secret Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="rave_lsk" name="rave_lsk" size="60" value="<?php echo esc_attr($values['rave_lsk'])?>" />
                        </td>
                    </tr>
                    <tr class="gateway gateway_rave" <?php if($gateway != "rave") { ?>style="display: none;"<?php } ?>>
                        <th scope="row" valign="top">
                            <label for="rave_lpk"><?php _e('Live Public Key', 'pmpro');?>:</label>
                        </th>
                        <td>
                            <input type="text" id="rave_lpk" name="rave_lpk" size="60" value="<?php echo esc_attr($values['rave_lpk'])?>" />
                        </td>
                    </tr>
                    
                    <?php
                }

                /**
                 * Remove required billing fields
                 */
                static function pmpro_required_billing_fields($fields)
                {
                    unset($fields['bfirstname']);
                    unset($fields['blastname']);
                    unset($fields['baddress1']);
                    unset($fields['bcity']);
                    unset($fields['bstate']);
                    unset($fields['bzipcode']);
                    unset($fields['bphone']);
                    unset($fields['bemail']);
                    unset($fields['bcountry']);
                    unset($fields['CardType']);
                    unset($fields['AccountNumber']);
                    unset($fields['ExpirationMonth']);
                    unset($fields['ExpirationYear']);
                    unset($fields['CVV']);

                    return $fields;
                }

                static function pmpro_gateways_with_pending_status($gateways) {
                    // Execute 4
                    // print_r('pmpro_gateways_with_pending_status'); exit;
                    $morder = new MemberOrder();
                    // MemberOrder Object ( [gateway] => rave [Gateway] => PMProGateway_Rave Object ( [gateway] => rave [gateway_environment] => sandbox ) )
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
                    // return MemberOrderID from Orders

                    if ((!in_array("rave", $gateways)) && $found) {
                        array_push($gateways, "rave");
                    } elseif (($key = array_search("rave", $gateways)) !== false) {
                        unset($gateways[$key]);
                    }
                    
                    // print_r($gateways); exit;

                    return $gateways;
                }

                /**
                 * Instead of change membership levels, send users to Rave payment page.
                 */
                static function pmpro_checkout_before_change_membership_level($user_id, $morder)
                {
                    // Execute 2
                    // print_r('pmpro_checkout_before_change_membership_level'); exit;
                    global $wpdb, $discount_code_id;
                    
                    //if no order, no need to pay
                    if (empty($morder)) {
                        return;
                    }
                    if (empty($morder->code))
                        $morder->code = $morder->getRandomCode();
                        
                    $morder->payment_type = "rave";
                    $morder->status = "pending";
                    $morder->user_id = $user_id;
                    $morder->saveOrder();

                    //save discount code use
                    if (!empty($discount_code_id))
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");

                    $morder->Gateway->sendToRave($morder);
                }

                function sendToRave(&$order) 
                {
                    // Execute 3
                    // print_r(json_encode($order)); exit;
                    global $wp;

                    $mode = pmpro_getOption("gateway_environment");
                    if ($mode == 'sandbox') {
                        $key = pmpro_getOption("rave_tpk");
                        $skey = pmpro_getOption("rave_tsk");
                    } else {
                        $key = pmpro_getOption("rave_lpk");
                        $skey = pmpro_getOption("rave_lsk");
                    }
                    if ($key  == '' || $skey == '') {
                        echo "Api keys not set";
                        exit;
                    }

                    // set the plans details
                    $pmpro_level = $order->membership_level;
                    $plan_name = $pmpro_level->id .'_'. $pmpro_level->name;

                    // setting interval for the subscription
                    if (($pmpro_level->cycle_number > 0) && ($pmpro_level->billing_amount > 0) && ($pmpro_level->cycle_period != "")) {
                        if ($pmpro_level->cycle_number < 10 && $pmpro_level->cycle_period == 'Day') {
                            $interval = 'weekly';
                        } elseif (($pmpro_level->cycle_number == 90) && ($pmpro_level->cycle_period == 'Day')) {
                            $interval = 'quarterly';
                        } elseif (($pmpro_level->cycle_number >= 10) && ($pmpro_level->cycle_period == 'Day')) {
                            $interval = 'monthly';
                        } elseif (($pmpro_level->cycle_number == 3) && ($pmpro_level->cycle_period == 'Month')) {
                            $interval = 'quarterly';
                        } elseif (($pmpro_level->cycle_number > 0) && ($pmpro_level->cycle_period == 'Month')) {
                            $interval = 'monthly';
                        } elseif (($pmpro_level->cycle_number > 0) && ($pmpro_level->cycle_period == 'Year')) {
                            $interval = 'annually';
                        }

                        // amount
                        $amount = $pmpro_level->billing_amount;
                        if ($amount == 0) {
                            $amount = $pmpro_level->initial_payment;
                        }

                        // duration
                        $duration = $pmpro_level->billing_limit;
                        if ($duration == '0') {
                            $duration = '';
                        }

                        //Create Plan
                        $rave_plan_url = 'https://api.ravepay.co/v2/gpx/paymentplans/create';
                        // fetch Plan
                        $rave_fetch_plan_url = 'https://api.ravepay.co/v2/gpx/paymentplans/query?seckey='.$skey.'&q='.$plan_name;

                        $headers = array(
                            'Content-Type'  => 'application/json'
                        );

                        $checkargs = array(
                            'headers' => $headers,
                            'timeout' => 60
                        );

                        // Check if plan exist
                        $checkrequest = wp_remote_get($rave_fetch_plan_url, $checkargs);
                        if (!is_wp_error($checkrequest)) {
                            $response = json_decode(wp_remote_retrieve_body($checkrequest));
                            if ($response->data->page_info->total >= 1) {
                                $planid = $response->data->paymentplans->id;
                                
                            } else {
                                //Create Plan
                                $body = array(
                                    'name'      => $plan_name,
                                    'amount'    => $amount,
                                    'interval'  => $interval,
                                    'duration'  => $duration,
                                    'seckey'    => $skey
                                );
                                $args = array(
                                    'body'      => json_encode($body),
                                    'headers'   => $headers,
                                    'timeout'   => 60
                                );

                                $request = wp_remote_post($rave_plan_url, $args);
                                if (!is_wp_error($request)) {
                                    $rave_response = json_decode(wp_remote_retrieve_body($request));
                                    $planid = $rave_response->data->id;
                                    $plan_name = $rave_response->data->name;
                                }
                            }

                        }
                        
                    } // end of subscription setting and plan

                    $params = array();
                    $amount = $order->PaymentAmount;
                    $amount_tax = $order->getTaxForPrice($amount);
                    $amount = round((float)$amount + (float)$amount_tax, 2);
            
                    //call directkit to get Webkit Token
                    $amount = floatval($order->InitialPayment);                    

                    $currency = pmpro_getOption("currency");
                    
                    $rave_url = 'https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/hosted/pay';
                    $headers = array(
                        'Content-Type'  => 'application/json'
                    );

                    // request to make payment
                    $body = array(

                        'customer_email'        => $order->Email,
                        'amount'                => $amount,
                        'txref'                 => $order->code,
				        'PBFPubKey'             => $key,
                        'currency'              => $currency,
                        'payment_plan'          => $planid,
                        'redirect_url'          => pmpro_url("confirmation", "?level=" . $order->membership_level->id)

                    );
                    $args = array(
                        'body'      => json_encode($body),
                        'headers'   => $headers,
                        'timeout'   => 60
                    );

                    $request = wp_remote_post($rave_url, $args);
                    // print_r($request);
                    // exit;
                    if (!is_wp_error($request)) {
                        $rave_response = json_decode(wp_remote_retrieve_body($request));
                        if ($rave_response->status == 'success'){
                            $url = $rave_response->data->link;
                            wp_redirect($url);
                            exit;
                        } else {
                            $order->Gateway->delete($order);
                            wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=" . $rave_response->message));
                            exit();
                        }
                    } else {
                        $order->Gateway->delete($order);
                        wp_redirect(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&error=Failed"));
                        exit();
                    }
                    exit;
                }

                // renew payment
                static function renewpayment($response) 
                {
                    global $wp, $wpdb;

                    if (isset($response->status) && ($response->status == 'successful')) {

                        $amount = $response->amount;
                        $old_order = new MemberOrder();
                        $txref = $response->txRef;
                        $email = $response->customer->email;
                        $old_order->getLastMemberOrderBySubscriptionTransactionID($txref);

                        if (empty($old_order)) { 
                            exit();
                        }
                        $user_id = $old_order->user_id;
                        $user = get_userdata($user_id);
                        $user->membership_level = pmpro_getMembershipLevelForUser($user_id);

                        if (empty($user)) { 
                            exit(); 
                        }

                        $morder = new MemberOrder();
                        $morder->user_id = $old_order->user_id;
                        $morder->membership_id = $old_order->membership_id;
                        $morder->InitialPayment = $amount;  //not the initial payment, but the order class is expecting this
                        $morder->PaymentAmount = $amount;
                        $morder->payment_transaction_id = $response->id;
                        $morder->subscription_transaction_id = $txref;

                        $morder->gateway = $old_order->gateway;
                        $morder->gateway_environment = $old_order->gateway_environment;
                        $morder->Email = $email;

                        $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                        $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                        $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);
                        
                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";
                        
                        $custom_level = array(
                            'user_id'           => $morder->user_id,
                            'membership_id'     => $pmpro_level->id,
                            'code_id'           => '',
                            'initial_payment'   => $pmpro_level->initial_payment,
                            'billing_amount'    => $pmpro_level->billing_amount,
                            'cycle_number'      => $pmpro_level->cycle_number,
                            'cycle_period'      => $pmpro_level->cycle_period,
                            'billing_limit'     => $pmpro_level->billing_limit,
                            'trial_amount'      => $pmpro_level->trial_amount,
                            'trial_limit'       => $pmpro_level->trial_limit,
                            'startdate'         => $startdate,
                            'enddate'           => $enddate
                        );
                        
                        //get CC info that is on file
                        $morder->expirationmonth = get_user_meta($user_id, "pmpro_ExpirationMonth", true);
                        $morder->expirationyear = get_user_meta($user_id, "pmpro_ExpirationYear", true);
                        $morder->ExpirationDate = $morder->expirationmonth . $morder->expirationyear;
                        $morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;

                        
                        //save
                        if ($morder->status != 'success') {
                            
                            if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                $morder->status = "success";
                                $morder->saveOrder();
                            }
                                
                        }
                        $morder->getMemberOrderByID($morder->id);

                        //email the user their invoice
                        $pmproemail = new PMProEmail();
                        $pmproemail->sendInvoiceEmail($user, $morder);

                        do_action('pmpro_subscription_payment_completed', $morder);
                        exit();
                    }

                }

                static function pmpro_pages_shortcode_checkout($content) 
                {
                    // Execute 1
                    // print_r('pmpro_pages_shortcode_checkout'); exit;
                    $morder = new MemberOrder();
                    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
                    if ($found) {
                        $morder->Gateway->delete($morder);
                    }
                    
                    if (isset($_REQUEST['error'])) {
                        global $pmpro_msg, $pmpro_msgt;

                        $pmpro_msg = __("IMPORTANT: Something went wrong during the payment. Please try again later or contact the site owner to fix this issue.<br/>" . urldecode($_REQUEST['error']), "pmpro");
                        $pmpro_msgt = "pmpro_error";

                        $content = "<div id='pmpro_message' class='pmpro_message ". $pmpro_msgt . "'>" . $pmpro_msg . "</div>" . $content;
                    }

                    return $content;
                }

                /**
                 * Custom confirmation page
                 */
                static function pmpro_pages_shortcode_confirmation($content, $reference = null)
                {

                    global $wpdb, $current_user, $pmpro_invoice, $pmpro_currency, $gateway;

                    if (!isset($_REQUEST['txref'])) {
                        $_REQUEST['txref'] = null;
                    }
                    if ($reference != null) {
                        $_REQUEST['txref'] = $reference;
                    }
                    
                    if (empty($pmpro_invoice)) {
                        $morder =  new MemberOrder($_REQUEST['txref']);
                        if (!empty($morder) && $morder->gateway == "rave") $pmpro_invoice = $morder;
                    }
                        
                    if (!empty($pmpro_invoice) && $pmpro_invoice->gateway == "rave" && isset($pmpro_invoice->total) && $pmpro_invoice->total > 0) {
                            $morder = $pmpro_invoice;
                        if ($morder->code == $_REQUEST['txref']) {
                            $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
                            $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
                            $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);
                                    
                            $mode = pmpro_getOption("gateway_environment");
                            if ($mode == 'sandbox') {
                                $key = pmpro_getOption("rave_tsk");
                            } else {
                                $key = pmpro_getOption("rave_lsk");

                            }

                            $rave_url = 'https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/verify';
                            $headers = array(
                                'Content-Type' => 'application/json'
                            );
                            $body = array(
                                'SECKEY' => $key,
                                'txref' => $_REQUEST['txref']
                            );
                            $args = array(
                                'body' => json_encode($body),
                                'headers' => $headers,
                                'timeout' => 60
                            );

                            $request = wp_remote_post($rave_url, $args);
                            // print_r($request);
                            // exit;

                            if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request) ) {
                                $rave_response = json_decode(wp_remote_retrieve_body($request));
                                if ('successful' == $rave_response->data->status && $rave_response->data->chargecode == '00' || $rave_response->data->chargecode == '0') {
                                    
                                    
                                    //$customer_code = $rave_response->data->customer->customer_code;
                                    
                                    if (strlen($order->subscription_transaction_id) > 3) {
                                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $order->subscription_transaction_id, current_time("timestamp"))) . "'";
                                    } elseif (!empty($pmpro_level->expiration_number)) {
                                        $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";
                                    } else {
                                        $enddate = "NULL";
                                    }

                                    // 
                                    // die();

                                    //using the plan details to set as subscription details
                                    $morder->subscription_transaction_id = $rave_response->data->txref;
                                    $morder->subscription_token = $rave_response->data->txid;
                                    
                                    $custom_level = array(
                                        'user_id'           => $morder->user_id,
                                        'membership_id'     => $pmpro_level->id,
                                        'code_id'           => '',
                                        'initial_payment'   => $pmpro_level->initial_payment,
                                        'billing_amount'    => $pmpro_level->billing_amount,
                                        'cycle_number'      => $pmpro_level->cycle_number,
                                        'cycle_period'      => $pmpro_level->cycle_period,
                                        'billing_limit'     => $pmpro_level->billing_limit,
                                        'trial_amount'      => $pmpro_level->trial_amount,
                                        'trial_limit'       => $pmpro_level->trial_limit,
                                        'startdate'         => $startdate,
                                        'enddate'           => $enddate
                                    );

                                    if ($morder->status != 'success') {
                                        
                                        if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                                            $morder->membership_id = $pmpro_level->id;
                                            $morder->payment_transaction_id = $_REQUEST['txref'];
                                            $morder->status = "success";
                                            $morder->saveOrder();
                                        }
                                            
                                    }
                                    // echo "<pre>";
                                    // print_r($morder);
                                    // die();
                                    //setup some values for the emails
                                    if (!empty($morder)) {
                                        $pmpro_invoice = new MemberOrder($morder->id);
                                    } else {
                                        $pmpro_invoice = null;
                                    }

                                    $current_user->membership_level = $pmpro_level; //make sure they have the right level info
                                    $current_user->membership_level->enddate = $enddate;
                                    if ($current_user->ID) {
                                        $current_user->membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
                                    }
                                    
                                    //send email to member
                                    $pmproemail = new PMProEmail();
                                    $pmproemail->sendCheckoutEmail($current_user, $pmpro_invoice);

                                    //send email to admin
                                    $pmproemail = new PMProEmail();
                                    $pmproemail->sendCheckoutAdminEmail($current_user, $pmpro_invoice);

                                    $content = "<ul>
                                        <li><strong>".__('Account', KKD_RAVEPMP).":</strong> ".$current_user->display_name." (".$current_user->user_email.")</li>
                                        <li><strong>".__('Order', KKD_RAVEPMP).":</strong> ".$pmpro_invoice->code."</li>
                                        <li><strong>".__('Membership Level', KKD_RAVEPMP).":</strong> ".$pmpro_level->name."</li>
                                        <li><strong>".__('Amount Paid', KKD_RAVEPMP).":</strong> ".$pmpro_invoice->total." ".$pmpro_currency."</li>
                                    </ul>";

                                    ob_start();

                                    if (file_exists(get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php")) {
                                        include get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php";
                                    } else {
                                        include PMPRO_DIR . "/pages/confirmation.php";
                                    }
                                    
                                    $content .= ob_get_contents();
                                    ob_end_clean();

                                } else {

                                    $content = 'Invalid Reference';
                                    
                                }

                            } else {
                                
                                $content = 'Unable to Verify Transaction';

                            }
                            
                        } else {
                            
                            $content = 'Invalid Transaction Reference';
                        }
                    }
            
            
                    return $content;
                    
                }

                // cancel subscriptiom
                function cancel(&$order) 
                {
                    global $wpdb;

                    //no matter what happens below, we're going to cancel the order in our system
                    $order->updateStatus("cancelled");
                    $mode = pmpro_getOption("gateway_environment");
                    // $transaction_id = $order->subscription_transaction_id;
                    $rave_txid = $order->subscription_token;

                    if ($mode == 'sandbox') {
                        $skey = pmpro_getOption("rave_tsk");
                    } else {
                        $skey = pmpro_getOption("rave_lsk");

                    }
                    if ($rave_txid != "") {
                        $rave_url = 'https://api.ravepay.co/v2/gpx/subscriptions/'.$rave_txid.'/cancel?fetch_by_tx=1';
                        $headers = array(
                            'Content-Type' => 'application/json'
                        );
                        $body = array(
                            'seckey' => $skey,
                        );
                        $args = array(
                            'body' => json_encode($body),
                            'headers' => $headers,
                            'timeout' => 60
                        );
                        $request = wp_remote_post($rave_url, $args);
                        if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {
                            $rave_response = json_decode(wp_remote_retrieve_body($request));
                            if ('success' == $rave_response->status && 'cancelled' == $rave_response->data->status) {
                                
                                $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");

                            }
                        }
                    }    
                }

                function delete(&$order) 
                {
                    //no matter what happens below, we're going to cancel the order in our system
                    $order->updateStatus("cancelled");
                    global $wpdb;
                    $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");
                }
            }
        }
    }
}
?>