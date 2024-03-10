<?php


require_once dirname(__FILE__) . '/lib/index.php';

class WC_Card_Paymennt extends Paymennt_Card_Parent
{
    public $paymentService;
    public $config;
    private $pmntUtils;

    public function __construct()
    {
        $this->pmntUtils = new Paymennt_Utils();
        $this->has_fields = true;
        if (is_admin()) {
            $this->init_form_fields();
            $this->init_settings();
        }

        // Define user set variables
        $this->method_title = __('Paymennt', 'woocommerce');
        $this->method_description = __('Have your customers pay with credit or debit cards via Paymennt', 'woocommerce');
        $this->title = Paymennt_Config::getInstance()->getTitle();
        $this->description = Paymennt_Config::getInstance()->getDescription();
        $this->paymentService = Paymennt_Card_Payment::getInstance();
        $this->config = Paymennt_Config::getInstance();
        $this->icon = plugin_dir_url(__FILE__) . '../assets/images/mc-visa-network-logos.png';

        // Actions
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Save options
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_wc_card_paymennt_process_response', array($this, 'process_response'));

        //Custom JS and CSS
        if ($this->config->isFramePayment()) {
            add_action('wp_enqueue_scripts', array($this, 'add_paymennt_card_styles'));
            add_action('wp_footer', array($this, 'add_paymennt_card_scripts'));
        }
    }

    function add_paymennt_card_scripts() {
        wp_enqueue_script('paymennt-frame-js',  'https://pay.paymennt.com/static/js/paymennt-frames.js', true);
        wp_enqueue_script('paymennt-checkout-script', plugins_url('/../assets/js/paymennt_checkout.js', __FILE__), true);
    }

    function add_paymennt_card_styles() {
        wp_enqueue_style('paymennt-checkout-styles', plugins_url('/../assets/css/styles.css', __FILE__));
    }

    function process_admin_options()
    {
        $result = parent::process_admin_options();
        $settings = $this->settings;
        $settings['enabled']  = isset($settings['enabled']) ? $settings['enabled'] : 'no';

        update_option('woocommerce_paymennt_card_settings', apply_filters('woocommerce_settings_api_sanitized_fields_paymennt_card', $settings));
        return $result;
    }

    public function is_available()
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $valid = true;
        if ($this->config->isSpecificUserRoles()) {
            $valid = false;
            if (WC()->customer != null) {
                $user_id = WC()->customer->get_id();
                $user = new WP_User($user_id);
                if (!empty($user->roles) && is_array($user->roles)) {
                    foreach ($user->roles as $user_role) {
                        foreach ($this->config->getSpecificUserRoles() as $role) {
                            if ($role == $user_role) {
                                $valid = true;
                            }
                        }
                    }
                }
            }
        }

        if ($valid && $this->config->isSpecificCountries()) {
            $valid = false;
            if (WC()->customer != null) {
                $billingCountry = WC()->customer->get_billing_country();
                $specfiedCountries = $this->config->getSpecificCountries();
                if (!$billingCountry == null && !empty($specfiedCountries) && is_array($specfiedCountries)) {
                    foreach ($specfiedCountries as $country) {
                        if ($country == $billingCountry) {
                            $valid = true;
                        }
                    }
                }
            }
        }
        if ($valid) {
            return parent::is_available();
        } else {
            return false;
        }
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'api keys' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options()
    {
?>
<h3><?php _e('Paymennt', 'paymennt_card'); ?></h3>
<p><?php _e('Please fill in the below section to start accepting payments on your site via Paymennt! Learn more at <a href="https://docs.paymennt.com/" target="_blank">Paymennt</a>.', 'paymennt_card'); ?>
</p>


<table class="form-table">
    <?php
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            ?>
    <script>
    jQuery(document).ready(function() {
        jQuery('[name=save]').click(function() {
            if (!jQuery('#woocommerce_paymennt_card_api_key').val()) {
                alert('API key not configured');
                return false;
            }
            if (!jQuery('#woocommerce_paymennt_card_api_secret').val()) {
                alert('API secret not configured');
                return false;
            }
            if (!jQuery('#woocommerce_paymennt_card_public_key').val()) {
                alert('Public key not configured');
                return false;
            }
            if (jQuery('#woocommerce_paymennt_card_allow_specific').val() == 1) {
                if (!jQuery('#woocommerce_paymennt_card_specific_countries').val()) {
                    alert(
                        'You enabled Paymennt for specific countries but you did not select any'
                    );
                    return false;
                }
            }
            if (jQuery('#woocommerce_paymennt_card_allow_user_specific').val() == 1) {
                if (!jQuery('#woocommerce_paymennt_card_specific_user_roles').val()) {
                    alert(
                        'You enabled Paymennt for speficic user roles but you did not select any'
                    );
                    return false;
                }
            }

        })
    });
    </script>
</table>
<!--/.form-table-->
<?php
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    function init_form_fields()
    {
        $staging_enabled = false;
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __('Enable/Disable', 'paymennt_card'),
                'type'    => 'checkbox',
                'label'   => __('Enable card payments via Paymennt', 'paymennt_card'),
                'default' => 'no'
            ),
            'title'         => array(
                'title'       => __('Title', 'paymennt_card'),
                'type'        => 'text',
                'description' => __('This is the payment method title the user sees during checkout.', 'paymennt_card'),
                'default'     => __('Credit Card (via Paymennt)', 'paymennt_card')
            ),
            'description'         => array(
                'title'       => __('Description', 'paymennt_card'),
                'type'        => 'text',
                'description' => __('This is the description the user sees during checkout.', 'paymennt_card'),
                'default'     => __('Complete your purchase using a credit or debit card.', 'paymennt_card')
            ),
            'mode'          => array(
                'title'       => 'Mode',
                'type'        => 'select',
                'options'     => $staging_enabled ? array(
                    '1' => __('Live', 'paymennt_card'),
                    '0' => __('Testing', 'paymennt_card'),
                    '2' => __('Staging', 'paymennt_card'),
                ) : array(
                    '1' => __('Live', 'paymennt_card'),
                    '0' => __('Testing', 'paymennt_card'),
                ),
                'default'     => '0',
                'desc_tip'    => true,
                'description' => sprintf(__('Logs additional information. <br>Log file path: %s', 'paymennt_card'), 'Your admin panel -> WooCommerce -> System Status -> Logs'),
                'placeholder' => '',
                'class'       => 'wc-enhanced-select',
            ),
            'api_key'         => array(
                'title'       => __('API Key', 'paymennt_card'),
                'type'        => 'text',
                'description' => __('Your Api Key, you can find in your Paymennt account  settings.', 'paymennt_card'),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => ''
            ),
            'api_secret'         => array(
                'title'       => __('API Secret', 'paymennt_card'),
                'type'        => 'text',
                'description' => __('Your Api Secret, you can find in your Paymennt account  settings.', 'paymennt_card'),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => ''
            ),
            'public_key'         => array(
                'title'       => __('Public Key', 'paymennt_card'),
                'type'        => 'text',
                'description' => __('Your public Key, you can find in your Paymennt account  settings.', 'paymennt_card'),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => ''
            ),
            'payment_type'          => array(
                'title'       => 'Payment Type',
                'type'        => 'select',
                'options'     =>  array(
                    '1' => __('Drop-in Frames', 'paymennt_card'),
                    '0' => __('Hosted Checkout', 'paymennt_card'),
                ),
                'default'     => '0',
                'desc_tip'    => true,
                'description' => __('Frame: Embeded in the same page <br>Redirect: Opens separate payment page.', 'paymennt_card'),
                'placeholder' => '',
                'class'       => 'wc-enhanced-select',
            ),
            'allow_specific' => array(
                'title'       => __('Applicable Countries', 'paymennt_card'),
                'type'        => 'select',
                'options'     => array(
                    '0' => __('All Countries', 'paymennt_card'),
                    '1' => __('Specific countries only', 'paymennt_card')
                )
            ),
            'specific_countries' => array(
                'title'   => __('Specific Countries', 'paymennt_card'),
                'desc'    => '',
                'css'     => 'min-width: 350px;min-height:300px;',
                'default' => 'wc_get_base_location()',
                'type'    => 'multiselect',
                'options' => $this->getCountries()
            ),
            'allow_user_specific' => array(
                'title'       => __('Applicable User Roles', 'paymennt_card'),
                'type'        => 'select',
                'options'     => array(
                    '0' => __('All User Roles', 'paymennt_card'),
                    '1' => __('Specific Roles only', 'paymennt_card')
                )
            ),
            'specific_user_roles' => array(
                'title'   => __('Specific User Roles', 'paymennt_card'),
                'desc'    => '',
                'css'     => 'min-width: 350px;min-height:300px;',
                'default' => 'wc_get_base_role()',
                'type'    => 'multiselect',
                'options' => $this->getRoles()
            )
        );
    }


    function getCountries()
    {
        $countries_obj   = new WC_Countries();
        return $countries_obj->__get('countries');
    }

    function getRoles()
    {
        global $wp_roles;
        $all_roles = $wp_roles->roles;
        $editable_roles = apply_filters('editable_roles', $all_roles);
        $user_roles = array();

        foreach ($editable_roles as $k => $v) {
            $user_roles[$k]  = $k;
        }
        return $user_roles;
    }

    public function payment_fields()
    {

        if (!$this->config->isFramePayment()) {
            $description = $this->get_description();
            if ($description) {
                // display the description with <p> tags etc.
                echo wpautop(wp_kses_post($description));
            }
        }

        if ($this->config->isFramePayment()) {
        ?>
<fieldset id="wc-<?php echo esc_attr($this->id); ?>-cc-form" class="wc-credit-card-form wc-payment-form">
    <div id="paymennt-frame" class="card-frame"></div>
    <p class="error-message"></p>
    <input id='public-key' type='hidden'
        value='<?php echo esc_attr(Paymennt_Config::getInstance()->getPublicKey()); ?>'>
    <input id='mode' type='hidden' value='<?php echo esc_attr(Paymennt_Config::getInstance()->getMode()); ?>'>
    <input class="info-message" type="hidden" id="paymennt-token" name="paymenntToken">

</fieldset>
<?php
        }

        if (!$this->config->isLiveMode()) {
            echo wpautop(wp_kses_post(sprintf(
                __('TEST MODE ENABLED. You can use test cards only. ' .
                'See the <a href="%s" target="_blank">Paymennt WooCommerce Guide</a> for more details.', 'woocommerce'),
                'https://docs.paymennt.com/docs/payment/ecomm/woocommerce'
            )));
        }
    }

    function process_payment($order_id)
    {
        global $woocommerce;
        $order   = new WC_Order($order_id);
        if (!isset($_GET['response_code'])) {
            update_post_meta($order->get_id(), '_payment_method_title', 'Card');
            update_post_meta($order->get_id(), '_payment_method', 'paymennt_card');
        }

        $failedPaymentTryAgainLater = 'Failed to process payment please try again later.';

        if ($this->config->isFramePayment()) {
            $token = sanitize_text_field($_POST['paymenntToken']);
            if (!empty($token)) {
                try {

                    $result = $this->paymentService->postTokenOrderToPaymennt($token);

                    WC()->session->set('checkoutId', $result->checkoutDetails->id);
                    $note = $this->paymentService->getOrderHistoryMessage($result->id, 0, $result->status, '');
                    $order->add_order_note($note);

                    if ($result->status == "REDIRECT") {
                        return array(
                            'result' => 'success',
                            'redirect' => $result->redirectUrl
                        );
                    } else if ($result->status == "CAPTURED") {
                        $order->payment_complete();
                        WC()->session->set('refresh_totals', true);
                        WC()->cart->empty_cart();
                        $redirectUrl = $this->get_return_url($order);
                        return array(
                            'result' => 'success',
                            'redirect' => $redirectUrl
                        );
                    } else if ($result->status == "AUTHORIZED") {
                        $paymentId = $result->id;

                        $paymentCaptureResult = $this->paymentService->captureAuthorizedPayment($paymentId);

                        if ($paymentCaptureResult->status == "CAPTURED") {
                            $order->payment_complete();
                            WC()->session->set('refresh_totals', true);
                            WC()->cart->empty_cart();
                            $redirectUrl = $this->get_return_url($order);
                            return array(
                                'result' => 'success',
                                'redirect' => $redirectUrl
                            );
                        } else {
                            $this->pmntUtils->log('Failed to initiate card payment using Paymennt, message : ' . "Error in capturing authorized payment");
                            wc_add_notice(__("Error in capturing payment."), 'error');
                        }
                    } else {
                        if (!empty($result->responseMessage)) {
                            wc_add_notice(__($result->responseMessage), 'error');
                        } else {
                            wc_add_notice(__($failedPaymentTryAgainLater), 'error');
                        }
                    }
                } catch (Exception $e) {
                    $this->pmntUtils->log('Failed to initiate card payment using Paymennt, message : ' . $e->getMessage());
                    wc_add_notice(__($failedPaymentTryAgainLater . " " . $e->getMessage()), 'error');
                }
            } else {
                $this->pmntUtils->log('Failed to initiate card payment using Paymennt. "Token" is empty');
                wc_add_notice(__($failedPaymentTryAgainLater . "Unable to tokenize payment details."), 'error');
            }
        } else {

            try {
                $result   = $this->paymentService->postOrderToPaymennt();

                $note = $this->paymentService->getOrderHistoryMessage($result->id, 0, $result->status, '');
                $order->add_order_note($note);

                WC()->session->set('checkoutId', $result->id);
                return array(
                    'result' => 'success',
                    'redirect' => $result->redirectUrl
                );
            } catch (Exception $e) {
                $this->pmntUtils->log('Failed to initiate card payment using Paymennt, message : ' . $e->getMessage());
                wc_add_notice(__($failedPaymentTryAgainLater . " " . $e->getMessage()), 'error');
            }
        }
    }

    public function process_response()
    {
        global $woocommerce;
        //send the second call to paymennt to confirm payment
        $success = $this->paymentService->checkPaymentStatus();

        $order = wc_get_order($this->pmntUtils->extractOrderId($_REQUEST['reference']));
        if ($success['success']) {
            $order->payment_complete();
            WC()->session->set('refresh_totals', true);
            WC()->cart->empty_cart();
            $redirectUrl = $this->get_return_url($order);
        } else {
            $redirectUrl = esc_url(wc_get_checkout_url());
            $order->update_status('cancelled');
            wc_add_notice(__('Failed to process payment please try again later'), 'error');
        }
        echo '<script>window.top.location.href = "' . esc_url($redirectUrl) . '"</script>';
        exit;
    }
}