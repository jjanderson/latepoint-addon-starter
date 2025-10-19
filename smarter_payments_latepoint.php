<?php
/**
 * Plugin Name: Latepoint Addon - Smarter Payments Gateway
 * Plugin URI: https://latepoint.com/
 * Description: Smarter Payments (Encoded Gateway) integration for Latepoint
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://latepoint.com/
 * Text Domain: latepoint-smarter-payments
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LATEPOINT_ADDON_SMARTER_PAYMENTS_VERSION', '1.0.0');

class OsSmarterPaymentsAddon {
    
    private $access_token = null;
    private $token_expiry = null;

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('latepoint_includes', [$this, 'register_addon']);
        add_action('latepoint_init', [$this, 'init']);
        add_filter('latepoint_payment_processors', [$this, 'register_payment_processor'], 10, 2);
        add_action('latepoint_payment_processor_settings', [$this, 'payment_processor_settings'], 10, 1);
        add_filter('latepoint_process_payment_for_booking', [$this, 'process_payment'], 10, 3);
        
        // Webhook handlers
        add_action('wp_ajax_nopriv_latepoint_smarter_payments_return', [$this, 'handle_return']);
        add_action('wp_ajax_latepoint_smarter_payments_return', [$this, 'handle_return']);
    }

    public function register_addon() {
        do_action('latepoint_addon_smarter_payments_included');
    }

    public function init() {
        $this->load_textdomain();
    }

    private function load_textdomain() {
        load_plugin_textdomain('latepoint-smarter-payments', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_payment_processor($payment_processors, $enabled_only = false) {
        $smarter_payments = [
            'code' => 'smarter_payments',
            'name' => __('Smarter Payments', 'latepoint-smarter-payments'),
            'description' => __('Accept payments through Smarter Payments Gateway (Encoded)', 'latepoint-smarter-payments'),
            'image_url' => plugins_url('assets/smarter-payments-logo.png', __FILE__),
        ];

        if ($enabled_only && !$this->is_enabled()) {
            return $payment_processors;
        }

        $payment_processors['smarter_payments'] = $smarter_payments;
        return $payment_processors;
    }

    private function is_enabled() {
        return (OsSettingsHelper::get_settings_value('smarter_payments_enabled', 'off') === 'on');
    }

    public function payment_processor_settings($payment_processor_code) {
        if ($payment_processor_code !== 'smarter_payments') {
            return;
        }
        ?>
        <div class="os-payment-processor-settings-w">
            <div class="os-section-header">
                <h3><?php _e('Smarter Payments Settings', 'latepoint-smarter-payments'); ?></h3>
            </div>
            
            <div class="white-box">
                <div class="white-box-header">
                    <div class="os-form-sub-header">
                        <h3><?php _e('API Configuration', 'latepoint-smarter-payments'); ?></h3>
                    </div>
                </div>
                <div class="white-box-content">
                    <?php
                    echo OsFormHelper::checkbox_field('settings[smarter_payments_enabled]', __('Enable Smarter Payments', 'latepoint-smarter-payments'), 'on', ($this->is_enabled()), ['class' => 'os-form-checkbox']);
                    
                    echo OsFormHelper::select_field('settings[smarter_payments_environment]', __('Environment', 'latepoint-smarter-payments'), [
                        'sit' => __('Test/Sandbox (SIT)', 'latepoint-smarter-payments'),
                        'prod' => __('Live (Production)', 'latepoint-smarter-payments')
                    ], OsSettingsHelper::get_settings_value('smarter_payments_environment', 'sit'));
                    
                    echo OsFormHelper::text_field('settings[smarter_payments_client_id]', __('Client ID', 'latepoint-smarter-payments'), OsSettingsHelper::get_settings_value('smarter_payments_client_id'), ['placeholder' => __('Enter your OAuth Client ID', 'latepoint-smarter-payments')]);
                    
                    echo OsFormHelper::text_field('settings[smarter_payments_client_secret]', __('Client Secret', 'latepoint-smarter-payments'), OsSettingsHelper::get_settings_value('smarter_payments_client_secret'), ['placeholder' => __('Enter your OAuth Client Secret', 'latepoint-smarter-payments'), 'type' => 'password']);
                    ?>
                    <div class="os-form-sub-description">
                        <?php _e('OAuth credentials are used to authenticate with the Encoded Gateway API.', 'latepoint-smarter-payments'); ?>
                    </div>
                </div>
            </div>

            <div class="white-box">
                <div class="white-box-header">
                    <div class="os-form-sub-header">
                        <h3><?php _e('Return URL Configuration', 'latepoint-smarter-payments'); ?></h3>
                        <div class="os-form-sub-header-actions"><?php _e('Customer will be redirected here after payment', 'latepoint-smarter-payments'); ?></div>
                    </div>
                </div>
                <div class="white-box-content">
                    <div class="os-form-group">
                        <label><?php _e('Return URL', 'latepoint-smarter-payments'); ?></label>
                        <input type="text" class="os-form-control" readonly value="<?php echo admin_url('admin-ajax.php?action=latepoint_smarter_payments_return'); ?>" onclick="this.select();" />
                        <div class="os-form-sub-description"><?php _e('Use this URL as the return URL in your transaction requests', 'latepoint-smarter-payments'); ?></div>
                    </div>
                </div>
            </div>

            <div class="white-box">
                <div class="white-box-header">
                    <div class="os-form-sub-header">
                        <h3><?php _e('Payment Options', 'latepoint-smarter-payments'); ?></h3>
                    </div>
                </div>
                <div class="white-box-content">
                    <?php
                    echo OsFormHelper::select_field('settings[smarter_payments_action]', __('Transaction Action', 'latepoint-smarter-payments'), [
                        'pay' => __('Pay (Authorize & Capture)', 'latepoint-smarter-payments'),
                        'authorise' => __('Authorize Only', 'latepoint-smarter-payments'),
                    ], OsSettingsHelper::get_settings_value('smarter_payments_action', 'pay'));
                    
                    echo OsFormHelper::checkbox_field('settings[smarter_payments_3ds]', __('Enable 3D Secure (Recommended)', 'latepoint-smarter-payments'), 'on', (OsSettingsHelper::get_settings_value('smarter_payments_3ds', 'on') === 'on'));
                    
                    echo OsFormHelper::checkbox_field('settings[smarter_payments_tokenisation]', __('Enable Card Tokenization', 'latepoint-smarter-payments'), 'on', (OsSettingsHelper::get_settings_value('smarter_payments_tokenisation', 'off') === 'on'));
                    
                    echo OsFormHelper::select_field('settings[smarter_payments_currency]', __('Currency', 'latepoint-smarter-payments'), [
                        'GBP' => 'GBP - British Pound',
                        'USD' => 'USD - US Dollar',
                        'EUR' => 'EUR - Euro',
                        'AUD' => 'AUD - Australian Dollar',
                        'CAD' => 'CAD - Canadian Dollar'
                    ], OsSettingsHelper::get_settings_value('smarter_payments_currency', 'GBP'));
                    ?>
                </div>
            </div>

            <div class="white-box">
                <div class="white-box-header">
                    <div class="os-form-sub-header">
                        <h3><?php _e('Test Connection', 'latepoint-smarter-payments'); ?></h3>
                    </div>
                </div>
                <div class="white-box-content">
                    <button type="button" class="latepoint-btn latepoint-btn-outline" id="test_smarter_payments">
                        <?php _e('Test API Connection', 'latepoint-smarter-payments'); ?>
                    </button>
                    <div id="test_result" style="margin-top: 10px;"></div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test_smarter_payments').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('<?php _e('Testing...', 'latepoint-smarter-payments'); ?>');
                $('#test_result').html('');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'smarter_payments_test_connection',
                        nonce: '<?php echo wp_create_nonce('test_connection'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#test_result').html('<div style="color: green;">' + response.data + '</div>');
                        } else {
                            $('#test_result').html('<div style="color: red;">' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        $('#test_result').html('<div style="color: red;"><?php _e('An error occurred', 'latepoint-smarter-payments'); ?></div>');
                    },
                    complete: function() {
                        btn.prop('disabled', false).text('<?php _e('Test API Connection', 'latepoint-smarter-payments'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function process_payment($payment_result, $booking, $customer) {
        if (!$this->is_enabled()) {
            return $payment_result;
        }

        $payment_result = [
            'status' => 'error',
            'message' => __('Payment processing failed', 'latepoint-smarter-payments'),
            'charge_id' => '',
        ];

        try {
            // Get OAuth token
            $access_token = $this->get_access_token();
            if (!$access_token) {
                throw new Exception(__('Failed to authenticate with payment gateway', 'latepoint-smarter-payments'));
            }

            // Get payment details
            $amount = $booking->full_amount_to_charge();
            $currency = OsSettingsHelper::get_settings_value('smarter_payments_currency', 'GBP');
            $action = OsSettingsHelper::get_settings_value('smarter_payments_action', 'pay');
            $enable_3ds = (OsSettingsHelper::get_settings_value('smarter_payments_3ds', 'on') === 'on');

            // Create customer in Encoded Gateway
            $encoded_customer = $this->create_or_get_customer($customer, $access_token);
            
            // Create order
            $order = $this->create_order($booking, $customer, $amount, $currency, $access_token);
            
            if (!$order || !isset($order['id'])) {
                throw new Exception(__('Failed to create order', 'latepoint-smarter-payments'));
            }

            // Prepare card data
            $card_data = $this->get_card_data_from_request();
            
            // Create transaction request
            $transaction_data = $this->prepare_transaction_data(
                $booking, 
                $customer, 
                $order, 
                $amount, 
                $currency, 
                $action,
                $enable_3ds,
                $card_data
            );

            // Submit transaction
            $transaction = $this->create_transaction($transaction_data, $access_token);

            if (!$transaction || !isset($transaction['id'])) {
                throw new Exception(__('Failed to create transaction', 'latepoint-smarter-payments'));
            }

            // Store transaction ID
            $booking->add_meta('smarter_payments_transaction_id', $transaction['id']);
            $booking->add_meta('smarter_payments_order_id', $order['id']);

            // Handle transaction status
            if (isset($transaction['status'])) {
                switch ($transaction['status']) {
                    case 'challenged':
                        // 3DS Challenge required
                        if (isset($transaction['challenge']['threeDSecure'])) {
                            $payment_result = [
                                'status' => 'redirect',
                                'redirect_url' => $this->handle_3ds_challenge($transaction, $booking),
                                'message' => __('3D Secure authentication required', 'latepoint-smarter-payments'),
                            ];
                        }
                        break;

                    case 'processed':
                        // Transaction completed
                        if (isset($transaction['response']['result']['resultType'])) {
                            if ($transaction['response']['result']['resultType'] === 'accepted') {
                                $payment_result = [
                                    'status' => 'success',
                                    'message' => __('Payment processed successfully', 'latepoint-smarter-payments'),
                                    'charge_id' => $transaction['id'],
                                ];

                                // Create Latepoint transaction record
                                $this->create_latepoint_transaction($booking, $customer, $transaction, $amount);

                                // Handle tokenization if enabled
                                if (isset($transaction['response']['token'])) {
                                    $booking->add_meta('smarter_payments_token_id', $transaction['response']['token']['id']);
                                }
                            } else {
                                $result_message = isset($transaction['response']['result']['message']) 
                                    ? $transaction['response']['result']['message'] 
                                    : __('Payment declined', 'latepoint-smarter-payments');
                                throw new Exception($result_message);
                            }
                        }
                        break;

                    case 'processing':
                        $payment_result = [
                            'status' => 'pending',
                            'message' => __('Payment is being processed', 'latepoint-smarter-payments'),
                            'charge_id' => $transaction['id'],
                        ];
                        break;

                    default:
                        throw new Exception(__('Unknown transaction status', 'latepoint-smarter-payments'));
                }
            }

        } catch (Exception $e) {
            $payment_result = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'charge_id' => '',
            ];
            OsDebugHelper::log('Smarter Payments Error: ' . $e->getMessage(), 'smarter_payments_error');
        }

        return $payment_result;
    }

    private function get_access_token() {
        // Check if we have a valid cached token
        if ($this->access_token && $this->token_expiry && time() < $this->token_expiry) {
            return $this->access_token;
        }

        $client_id = OsSettingsHelper::get_settings_value('smarter_payments_client_id');
        $client_secret = OsSettingsHelper::get_settings_value('smarter_payments_client_secret');
        $environment = OsSettingsHelper::get_settings_value('smarter_payments_environment', 'sit');

        if (empty($client_id) || empty($client_secret)) {
            return false;
        }

        $auth_url = "https://{$environment}.encoded.services/auth/oauth";

        $args = [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret)
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'scope' => 'read write'
            ],
            'timeout' => 30,
        ];

        $response = wp_remote_post($auth_url, $args);

        if (is_wp_error($response)) {
            OsDebugHelper::log('Smarter Payments OAuth Error: ' . $response->get_error_message(), 'smarter_payments_error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if ($response_code === 200 && isset($response_data['access_token'])) {
            $this->access_token = $response_data['access_token'];
            // Set expiry to 5 minutes before actual expiry
            $this->token_expiry = time() + ($response_data['expires_in'] ?? 3600) - 300;
            return $this->access_token;
        }

        OsDebugHelper::log('Smarter Payments OAuth Error: ' . $response_body, 'smarter_payments_error');
        return false;
    }

    private function create_or_get_customer($customer, $access_token) {
        $environment = OsSettingsHelper::get_settings_value('smarter_payments_environment', 'sit');
        $api_url = "https://{$environment}.encoded.services/api/v1";

        // Check if customer already exists
        $existing_customer_id = $customer->get_meta_by_key('smarter_payments_customer_id', '');
        
        if (!empty($existing_customer_id)) {
            return ['id' => $existing_customer_id];
        }

        // Create new customer
        $customer_data = [
            [
                'object' => 'customer',
                'ref' => 'LP-CUST-' . $customer->id,
                'forename' => $customer->first_name,
                'surname' => $customer->last_name,
                'contact' => [
                    'object' => 'contact',
                    'email' => $customer->email,
                    'phone' => [
                        'object' => 'phone',
                        'mobile' => $customer->phone
                    ]
                ]
            ]
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ],
            'body' => json_encode($customer_data),
            'timeout' => 30,
        ];

        $response = wp_remote_post($api_url . '/customers', $args);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($response_body[0]['id'])) {
                $customer->add_meta('smarter_payments_customer_id', $response_body[0]['id']);
                return $response_body[0];
            }
        }

        return null;
    }

    private function create_order($booking, $customer, $amount, $currency, $access_token) {
        $environment = OsSettingsHelper::get_settings_value('smarter_payments_environment', 'sit');
        $api_url = "https://{$environment}.encoded.services/api/v1";

        $order_data = [
            [
                'object' => 'order',
                'ref' => 'LP-BOOKING-' . $booking->id,
                'description' => 'Booking #' . $booking->id . ' - ' . $booking->service->name,
                'currency' => $currency,
                'totalAmount' => number_format($amount, 2, '.', ''),
                'billingCustomer' => [
                    'object' => 'customer',
                    'ref' => 'LP-CUST-' . $customer->id,
                    'forename' => $customer->first_name,
                    'surname' => $customer->last_name,
                    'contact' => [
                        'object' => 'contact',
                        'email' => $customer->email,
                        'phone' => [
                            'object' => 'phone',
                            'mobile' => $customer->phone
                        ]
                    ]
                ]
            ]
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ],
            'body' => json_encode($order_data),
            'timeout' => 30,
        ];

        $response = wp_remote_post($api_url . '/orders', $args);
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to create order: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code === 201 && isset($response_body[0])) {
            return $response_body[0];
        }

        throw new Exception('Failed to create order');
    }

    private function get_card_data_from_request() {
        return [
            'pan' => isset($_POST['card_number']) ? sanitize_text_field($_POST['card_number']) : '',
            'expiry' => isset($_POST['card_exp_month']) && isset($_POST['card_exp_year']) 
                ? sanitize_text_field($_POST['card_exp_year']) . '-' . str_pad($_POST['card_exp_month'], 2, '0', STR_PAD_LEFT)
                : '',
            'securityCode' => isset($_POST['card_cvv']) ? sanitize_text_field($_POST['card_cvv']) : '',
        ];
    }

    private function prepare_transaction_data($booking, $customer, $order, $amount, $currency, $action, $enable_3ds, $card_data) {
        $tokenisation_enabled = (OsSettingsHelper::get_settings_value('smarter_payments_tokenisation', 'off') === 'on');

        $source = [
            'object' => 'source',
            'card' => [
                'object' => 'card',
                'pan' => $card_data['pan'],
                'expiry' => $card_data['expiry'],
                'securityCode' => $card_data['securityCode']
            ]
        ];

        // Add tokenisation if enabled
        if ($tokenisation_enabled) {
            $source['card']['tokenisation'] = [
                'object' => 'tokenisation',
                'ref' => 'LP-TOKEN-' . $customer->id . '-' . time(),
                'agreement' => 'card_on_file'
            ];
        }

        $transaction_data = [
            'object' => 'transaction.request',
            'ref' => 'LP-TRANS-' . $booking->id . '-' . time(),
            'action' => $action,
            'order' => [
                'object' => 'order',
                'id' => $order['id']
            ],
            'currency' => $currency,
            'amount' => number_format($amount, 2, '.', ''),
            'threeDSecure' => $enable_3ds,
            'platformType' => 'ecom',
            'source' => $source,
            'billingCustomer' => [
                'object' => 'customer',
                'forename' => $customer->first_name,
                'surname' => $customer->last_name,
                'contact' => [
                    'object' => 'contact',
                    'email' => $customer->email
                ]
            ]
        ];

        return $transaction_data;
    }

    private function create_transaction($transaction_data, $access_token) {
        $environment = OsSettingsHelper::get_settings_value('smarter_payments_environment', 'sit');
        $api_url = "https://{$environment}.encoded.services/api/v1";

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ],
            'body' => json_encode($transaction_data),
            'timeout' => 45,
        ];

        $response = wp_remote_post($api_url . '/transactions', $args);

        if (is_wp_error($response)) {
            throw new Exception('Transaction request failed: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $transaction = json_decode($response_body, true);

        if ($response_code === 201 && $transaction) {
            return $transaction;
        }

        throw new Exception('Transaction failed: ' . $response_body);
    }

    private function handle_3ds_challenge($transaction, $booking) {
        // Store challenge data for later completion
        $booking->add_meta('smarter_payments_challenge_data', json_encode($transaction['challenge']));
        
        // For 3DS v1
        if (isset($transaction['challenge']['threeDSecure']['v1'])) {
            $challenge = $transaction['challenge']['threeDSecure']['v1'];
            // Return URL to 3DS page (you would need to create a 3DS handler page)
            return $challenge['acsUrl'];
        }
        
        // For 3DS v2
        if (isset($transaction['challenge']['threeDSecure']['v2'])) {
            $challenge = $transaction['challenge']['threeDSecure']['v2'];
            return $challenge['acsUrl'];
        }

        return '';
    }

    private function create_latepoint_transaction($booking, $customer, $transaction, $amount) {
        $lp_transaction = new OsTransactionModel();
        $lp_transaction->booking_id = $booking->id;
        $lp_transaction->customer_id = $customer->id;
        $lp_transaction->processor = 'smarter_payments';
        $lp_transaction->token = $transaction['id'];
        $lp_transaction->amount = $amount;
        $lp_transaction->status = LATEPOINT_TRANSACTION_STATUS_APPROVED;
        $lp_transaction->save();
    }

    public function handle_return() {
        // Handle return from 3DS or payment completion
        $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : '';
        
        if (empty($transaction_id)) {
            wp_redirect(home_url());
            exit;
        }

        // Get booking by transaction ID
        $bookings = OsBookingHelper::get_bookings_for_search([
            'meta_value' => $transaction_id,
            'meta_key' => 'smarter_payments_transaction_id'
        ]);

        if (empty($bookings)) {
            wp_redirect(home_url());
            exit;
        }

        $booking = $bookings[0];
        
        // Redirect to confirmation page
        wp_redirect(OsStepsHelper::get_step_link('confirmation') . '?booking_id=' . $booking->id);
        exit;
    }

    public static function activate() {
        update_option('latepoint_addon_smarter_payments_version', LATEPOINT_ADDON_SMARTER_PAYMENTS_VERSION);
    }

    public static function deactivate() {
        // Cleanup if needed
    }
}

// AJAX handler for test connection
add_action('wp_ajax_smarter_payments_test_connection', function() {
    check_ajax_referer('test_connection', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $addon = new OsSmarterPaymentsAddon();
    $reflection = new ReflectionClass($addon);
    $method = $reflection->getMethod('get_access_token');
    $method->setAccessible(true);
    
    $token = $method->invoke($addon);
    
    if ($token) {
        wp_send_json_success('✓ Connection successful! OAuth token obtained.');
    } else {
        wp_send_json_error('✗ Connection failed. Please check your credentials.');
    }
});

// Initialize the addon
function latepoint_addon_smarter_payments_init() {
    if (class_exists('OsSettingsHelper')) {
        new OsSmarterPaymentsAddon();
    }
}
add_action('plugins_loaded', 'latepoint_addon_smarter_payments_init');

// Activation/Deactivation hooks
register_activation_hook(__FILE__, ['OsSmarterPaymentsAddon', 'activate']);
register_deactivation_hook(__FILE__, ['OsSmarterPaymentsAddon', 'deactivate']);

// Add payment form fields to frontend
add_action('latepoint_payment_step_content', function($booking, $enabled_payment_times) {
    if (OsSettingsHelper::get_settings_value('smarter_payments_enabled', 'off') !== 'on') {
        return;
    }
    ?>
    <div class="latepoint-payment-processor-fields" data-processor="smarter_payments">
        <div class="os-row">
            <div class="os-col-12">
                <div class="os-form-group">
                    <label><?php _e('Card Number', 'latepoint-smarter-payments'); ?></label>
                    <input type="text" name="card_number" class="os-form-control" placeholder="4444 3333 2222 1111" required maxlength="19" />
                </div>
            </div>
        </div>
        <div class="os-row">
            <div class="os-col-4">
                <div class="os-form-group">
                    <label><?php _e('Expiry Month', 'latepoint-smarter-payments'); ?></label>
                    <input type="text" name="card_exp_month" class="os-form-control" placeholder="MM" maxlength="2" required />
                </div>
            </div>
            <div class="os-col-4">
                <div class="os-form-group">
                    <label><?php _e('Expiry Year', 'latepoint-smarter-payments'); ?></label>
                    <input type="text" name="card_exp_year" class="os-form-control" placeholder="YYYY" maxlength="4" required />
                </div>
            </div>
            <div class="os-col-4">
                <div class="os-form-group">
                    <label><?php _e('CVV', 'latepoint-smarter-payments'); ?></label>
                    <input type="text" name="card_cvv" class="os-form-control" placeholder="123" maxlength="4" required />
                </div>
            </div>
        </div>
        <div class="os-form-group">
            <div class="os-form-sub-description">
                <i class="latepoint-icon latepoint-icon-lock"></i>
                <?php _e('Your payment information is securely processed via Smarter Payments (Encoded Gateway)', 'latepoint-smarter-payments'); ?>
            </div>
        </div>
    </div>
    
    <style>
    .latepoint-payment-processor-fields[data-processor="smarter_payments"] {
        padding: 20px;
        background: #f9f9f9;
        border-radius: 5px;
        margin: 15px 0;
    }
    .latepoint-payment-processor-fields[data-processor="smarter_payments"] input {
        font-size: 16px;
        padding: 12px;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Card number formatting
        $('input[name="card_number"]').on('input', function() {
            var value = $(this).val().replace(/\s/g, '');
            var formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
            $(this).val(formattedValue);
        });
        
        // Only allow numbers
        $('input[name="card_number"], input[name="card_exp_month"], input[name="card_exp_year"], input[name="card_cvv"]').on('keypress', function(e) {
            if (e.which < 48 || e.which > 57) {
                e.preventDefault();
            }
        });
    });
    </script>
    <?php
}, 10, 2);
                