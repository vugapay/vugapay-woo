<?php

/**
 * Plugin Name: VugaPay WooCommerce payment plugin
 * Plugin URI: https://vugapay.com
 * Version: 0.0.1
 * Description: Receive mobile money payments from your woocommerce site
 * Author: The VugaPay team, support@vugapay.com
 * Author URI: https://vugapay.com
 */

function callback_handler() { print "SSS"; return "ZZZ";  }
add_action( 'woocommerce_api_callback', 'callback_handler' );
add_action('wp_ajax_vp_check', 'vuga_check_callback');

include "curlGetPage.php";

add_action( 'plugins_loaded', 'vuga_init',0 );
add_filter( 'woocommerce_payment_gateways', 'vuga_wc_init');

function vuga_init() {
    class Vuga_WC_Gateway extends WC_Payment_Gateway {
        function __construct() {
            $this->debug = false;
            $this->id = 'vugapay';
            $this->icon = plugins_url( 'vugapay-woo/img/checkoutg.png' );
            $this->method_title = 'VugaPay';
            $this->method_description = 'Pay with VugaPay';

            $this->title = $this->method_title;
            $this->description = $this->method_description;
            $this->gateway_url = 'https://devs.vugapay.com';

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();


            /*
            // Define user set variables
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            */

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
            //add_action('init', array(&$this, 'check_vuga_response'));
            add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
            add_action('woocommerce_api_wc_' . $this->id, array($this, 'check_ipn'));

        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'vugapay-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable VugaPay', 'vugapay-for-woocommerce'),
                    'default' => 'no'
                ),
                /*
                'title' => array(
                    'title' => __('Title', 'vugapay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'vugapay-for-woocommerce'),
                    'default' => __('VugaPay Advanced', 'vugapay-for-woocommerce')
                ),
                'description' => array(
                    'title' => __('Description', 'vugapay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the description which the user sees during checkout.', 'vugapay-for-woocommerce'),
                    'default' => __('VugaPay Advanced description', 'vugapay-for-woocommerce')
                ),
                */
                'vpkey' => array(
                    'title' => __('API key', 'vugapay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Please enter VugaPay API key here', 'vugapay-for-woocommerce'),
                    'default' => __('<API KEY>', 'vugapay-for-woocommerce')
                ),
                'vpsecret' => array(
                    'title' => __('API secret', 'vugapay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Please enter VugaPay API secret here', 'vugapay-for-woocommerce'),
                    'default' => __('<API SECRET>', 'vugapay-for-woocommerce')
                ),
            );
        }

        public function process_payment($order_id) {
            return $this->receipt_page($order_id);
        }

        public function generate_form($order_id) {
            $order = new WC_Order( $order_id );

            return '<form action="'.esc_url($this->form_url).'" method="POST" id="vugapay_payment_form">'."\n".
            '<input type="hidden" name="proceed" value="1"/>'.
            '<input type="submit" class="button alt" id="submit_vugapay_payment_form" value="'.__('Pay', 'wc-vugapay').
            '" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel & return to cart', 'wc-vugapay').'</a>'."\n".
            '</form>';
        }


        // Generate redirect to payment gateway
        public function receipt_page($order_id) {
            $order = new WC_Order( $order_id );

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', __( 'Awaiting cheque payment', 'woocommerce' ));

            // Get total amount
            $total = $order->get_total();


            // Request token
            // Request for TOKEN
            $url = $this->gateway_url."/v1/init";
            $result = httpPOST($url, array(array('key', get_option('wpp_key')), array('amt', $total)));

            if ($result['isComplete']) {
                // We've got token (!!)
                $token = $result['content'];

                // Update metatag with token
                update_post_meta($order_id, '_vuga_token', $token);

                // Write attempt info
                $order->add_order_note(__('VugaPay payment attempt, token_id='.$token, 'wc-vugapay'));

                // Prepare redirect
                $processURL = site_url()."/wc-api/wc_vugapay/?order_id=".$order_id;

                $url = $this->gateway_url.'/v1/payment?token='.urlencode($token).'&amount='.urlencode($total).
                    '&name='.urlencode('Payment').
                    '&crn='.get_woocommerce_currency().
                    '&curl='.urlencode($processURL."&state=0").
                    '&surl='.urlencode($processURL."&state=1");

                // Redirect user to VugaPay gateway
                return array(
                    'result' => 'success',
                    'redirect' => $url,
                );
            } else {
                return 'Error contacting payment gateway';
            }

        }
        function check_ipn() {
            global $wpdb;

            // Retrieve transaction details
            $url = $this->gateway_url."/v1/records";
            $result = httpPOST($url, array(array('secret', $this->get_option('vpsecret')), array('tid', $_GET['tid'])));

            $order = new WC_Order( $_GET['order_id']);
            if ($result['isComplete']) {
                $data = json_decode($result['content'], true);

                // Check state
                if ($data['sta'] == "Approved") {
                    // Approved. Check transaction_id
                    $original_tid = get_post_meta($_GET['order_id'], '_vuga_token', true);

                    if ($original_tid == $data['token']) {
                        // COMPLETE
                        //$order->update_status('complete', __( 'Payment complete', 'woocommerce' ));
                        $order->reduce_order_stock();
                        $order->payment_complete($data['token']);

                        $order->add_order_note(__('VugaPay payment complete, token_id='.$data['token'], 'wc-vugapay'));

                        WC()->cart->empty_cart();
                        wp_redirect( $this->get_return_url( $order ) );
                        die();
                    } else {
                        WC()->cart->empty_cart();
                        $this->do_return_cart($order);

                        $this->own_die_msg($order->get_cancel_order_url( true ));
                        wp_die("VugaPay: Invalid token.<br/>Return to shopping: <a href='".$order->get_cancel_order_url().'">here</a>');
                    }
                } else {
                    WC()->cart->empty_cart();
                    $this->do_return_cart($order);

                    $this->own_die_msg($order->get_cancel_order_url( true ));

                    wp_die("VugaPay: Payment Failed.<br/>Return to shopping: <a href='".$order->get_cancel_order_url( true )."'>here</a>".
                        ($this->debug?"<br/>Diagnostics:<pre>".var_export($data,true)."</pre>":'').
                        "<script type='text/javascript'>".
                        "setTimeout('window.location=\"".$order->get_cancel_order_url( true )."\";',5000);".
                        "</script>"
                    );
                }
            }
            WC()->cart->empty_cart();
            $r = $this->do_return_cart($order);

            $this->own_die_msg($order->get_cancel_order_url( true ));

            wp_die("VugaPay: Payment Failed!<br/>Return to shopping: <a href='".$order->get_cancel_order_url( true )."'>here</a>".
                ($this->debug?"<br/>Diagnostics:<pre>".var_export($result,true)."</pre>".$r:'').
                        "<script type='text/javascript'>".
                        "setTimeout('window.location=\"".$order->get_cancel_order_url( true )."\";',5000);".
                        "</script>"
            );
        }

        function own_die_msg($url) {
            ?>
             <!DOCTYPE html>
            <html><head>
            <link rel='stylesheet' type='text/css' href='https://vugapay.com/css/sweetalert.css'>
            
            
            <script type='text/javascript'>
            
            </script></head>

            <body>
            <script src='https://vugapay.com/js/sweetalert.min.js'></script>
            <script type='text/javascript'>
             swal({   title: 'VugaPay checkout failed!',   text: 'This will close in 4 seconds.',   timer: 5000,   showConfirmButton: false }); 
setTimeout(window.location='<?php echo $url; ?>',6000);
           
             </script>
            </body></html>
            
            <?php
            die();
        }

        function do_return_cart($order) {
            $r = '*';
            foreach ( $order->get_items() as $item ) {
                $product = $order->get_product_from_item( $item );
                $amount = $item['qty'];

                $r .= '|>'.$product->id.'>'.$amount.'|'. WC()->cart->add_to_cart($product->id, $amount);
            }
            return $r;
        }

    }
}

function vuga_wc_init($methods) {
    $methods[] = 'Vuga_WC_Gateway';
    return $methods;
}


// =========================================
// Admin page processing

add_action('admin_menu', 'vuga_admin_menu');
add_action('wp_ajax_vuga_check', 'vuga_check_callback');

function vuga_admin_menu() {

    add_menu_page('VugaPay API', 'VugaPay', 'administrator', 'vp', 'vuga_test_page',plugins_url( 'vugapay-woo/img/logo.png' ),
        6);
    //call register settings function
    add_action( 'admin_init', 'wpp_api_init' );
}

function vuga_check_callback() {
    include_once "curlGetPage.php";

    $key = $_GET['key'];
    $secret = $_GET['secret'];

    $url = "https://devs.vugapay.com/v1/validate/wp";
    $result = httpPOST($url, array(array('key', $key), array('secret', $secret)));
    if (!$result['isComplete']) {
        // HTTP ERROR
        print json_encode(array('status' => 0, 'msg' => 'HTTP ERROR ['.$result['http_code'].'] during request to Payment Service'));
        wp_die();
    } else {
        // HTTP OK, check JSON
        $data = json_decode($result['content'], true);
        if (is_array($data) && isset($data['status'])) {
            // Response is correct
            print json_encode(array('status' => $data['status'], 'msg' => $data['msg']));
            wp_die();
        }
        print json_encode(array('status' => 0, 'msg' => 'Incorrect reply from Payment Service ['.$result['content'].']'));
        wp_die();
    }
}

function vuga_test_page() {
    wp_enqueue_script("jquery");

    // Load WC options
    $opts = get_option('woocommerce_vugapay_settings');

    // Process incoming POST request
    if (isset($_POST['wpp_key']) && isset($_POST['wpp_secret'])) {
        $opts['vpkey'] = $_POST['wpp_key'];
        $opts['vpsecret'] = $_POST['wpp_secret'];
        update_option('woocommerce_vugapay_settings', $opts);
    }

    $key = $opts['vpkey'];
    $secret = $opts['vpsecret'];

    ?>
    <div class="wrap">
        <h2>VugaPay - API credential</h2>
        <?php settings_errors(); ?>
        <form method="post" action="admin.php?page=vp">
            <?php settings_fields( 'wpp-settings' ); ?>
            <?php do_settings_sections( 'wpp-settings' ); ?>
            <h3>API settings</h3><hr />
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Live key:</th>
                    <td><input type="text" id="wpp_key" name="wpp_key" style="width: 50%;" value="<?php echo esc_attr( $key ); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Live secret:</th>
                    <td>
                        <input type="text" id="wpp_secret" name="wpp_secret" style="width: 50%;" value="<?php echo esc_attr( $secret ); ?>" />
                    </td>
                </tr>
            </table>
            <div id="testResults">&nbsp;</div>
            <br/>
            <input type="button" value="Test API connection" class="button button-primary" onclick="testAPI();"/>
            &nbsp; &nbsp;
            <input id="submit" disabled="disabled" class="button button-primary" name="submit" value="Save Changes" type="submit"/>

            <script type="text/javascript">
                function testAPI() {
                    jQuery("#testResults").html('Testing key ... please wait.');
                    var xKey    = jQuery("#wpp_key").val();
                    var xSecret = jQuery("#wpp_secret").val();
                    jQuery.ajax({
                        type: 'GET',
                        url: ajaxurl,
                        data: 'action=vuga_check&key='+encodeURIComponent(xKey)+'&secret='+encodeURIComponent(xSecret),
                        success: function(resp) {
                            console.log('AJAX Resp: '+resp);
                            var res = eval('('+resp+')');
                            if (res['status'] == "1") {
                                // COMPLETE
                                jQuery("#testResults").html('complete');
                                jQuery("#submit").removeAttr("disabled");
                                jQuery("#wpp_key").attr("readonly", "readonly");
                                jQuery("#wpp_secret").attr("readonly", "readonly");

                            } else {
                                // FAILED
                                jQuery("#testResults").html('ERROR: '+res['msg']+'<br/>API invalid Please click here to <a href="https://vugapay.com/account/signup">sign up</a> and get your API key and secret');
                                jQuery("#submit").attr("disabled", "disabled");
                            }

                        }
                    });
                }
            </script>
        </form>
    </div>
    <?php
}