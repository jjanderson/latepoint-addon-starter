<?php
/**
 * Plugin Name: Latepoint Addon - ORZI SMS
 * Plugin URI: https://latepoint.com/
 * Description: ORZI SMS Integration for Latepoint with native notification support
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://latepoint.com/
 * Text Domain: latepoint-orzi-sms
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LATEPOINT_ADDON_ORZI_SMS_VERSION', '1.0.0');
define('LATEPOINT_ADDON_ORZI_SMS_DB_VERSION', '1.0.0');
define('LATEPOINT_ADDON_ORZI_SMS_PLUGIN_NAME', 'latepoint-orzi-sms');

class OsOrziSmsAddon {
    
    public function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    private function define_constants() {
        if (!defined('LATEPOINT_ADDON_ORZI_SMS_ABSPATH')) {
            define('LATEPOINT_ADDON_ORZI_SMS_ABSPATH', dirname(__FILE__) . '/');
        }
    }

    private function init_hooks() {
        // Core Latepoint hooks
        add_action('latepoint_includes', [$this, 'register_addon']);
        add_action('latepoint_init', [$this, 'init']);
        
        // SMS processor integration
        add_filter('latepoint_has_sms_processors', '__return_true');
        
        // Booking lifecycle hooks for automatic SMS
        add_action('latepoint_booking_created', [$this, 'send_booking_created_sms'], 10, 1);
        add_action('latepoint_booking_updated', [$this, 'send_booking_updated_sms'], 10, 2);
        
        // Customer hooks
        add_action('latepoint_customer_created', [$this, 'send_welcome_sms'], 10, 1);
        
        // Variable replacement for SMS templates
        add_filter('latepoint_replace_booking_vars', [$this, 'replace_sms_variables'], 10, 2);
        add_filter('latepoint_replace_customer_vars', [$this, 'replace_sms_variables'], 10, 2);
        
        // Settings integration
        add_filter('latepoint_side_menu', [$this, 'add_menu_items']);
        add_action('latepoint_notifications_settings_sms', [$this, 'settings_page']);
        
        // Available variables for SMS templates
        add_action('latepoint_available_vars_booking', [$this, 'show_booking_vars']);
        add_action('latepoint_available_vars_customer', [$this, 'show_customer_vars']);
    }

    public function register_addon() {
        do_action('latepoint_addon_orzi_sms_included');
    }

    public function init() {
        // Initialize addon functionality
        $this->load_textdomain();
    }

    private function load_textdomain() {
        load_plugin_textdomain('latepoint-orzi-sms', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_menu_items($menus) {
        if (isset($menus[900])) { // Settings menu
            $menus[900]['children']['orzi_sms'] = [
                'label' => __('ORZI SMS', 'latepoint-orzi-sms'),
                'icon' => 'latepoint-icon latepoint-icon-message-circle',
                'link' => admin_url('admin.php?page=latepoint&route_name=settings__notifications_sms')
            ];
        }
        return $menus;
    }

    public function send_booking_created_sms($booking) {
        if (!$this->is_sms_enabled('booking_created')) {
            return;
        }

        $template = $this->get_sms_template('booking_created');
        if (empty($template)) {
            return;
        }

        $message = $this->parse_template($template, $booking);
        $phone = $this->format_phone_number($booking->customer->phone);
        
        if (!empty($phone)) {
            $this->send_sms_internal([$phone], $message, 'Booking Created');
        }

        // Also send to agent if enabled
        if ($this->is_sms_enabled('agent_booking_created') && !empty($booking->agent_id)) {
            $agent = new OsAgentModel($booking->agent_id);
            if (!empty($agent->phone)) {
                $agent_template = $this->get_sms_template('agent_booking_created');
                $agent_message = $this->parse_template($agent_template, $booking, $agent);
                $agent_phone = $this->format_phone_number($agent->phone);
                if (!empty($agent_phone)) {
                    $this->send_sms_internal([$agent_phone], $agent_message, 'Agent Notification');
                }
            }
        }
    }

    public function send_booking_updated_sms($booking, $old_booking) {
        if (!$this->is_sms_enabled('booking_updated')) {
            return;
        }

        // Check if status changed
        if ($booking->status != $old_booking->status) {
            $template_key = 'booking_status_' . $booking->status;
            $template = $this->get_sms_template($template_key);
            
            if (!empty($template)) {
                $message = $this->parse_template($template, $booking);
                $phone = $this->format_phone_number($booking->customer->phone);
                
                if (!empty($phone)) {
                    $this->send_sms_internal([$phone], $message, 'Booking Status Update');
                }
            }
        }
    }

    public function send_welcome_sms($customer) {
        if (!$this->is_sms_enabled('customer_welcome')) {
            return;
        }

        $template = $this->get_sms_template('customer_welcome');
        if (empty($template)) {
            return;
        }

        $message = $this->parse_template($template, null, null, $customer);
        $phone = $this->format_phone_number($customer->phone);
        
        if (!empty($phone)) {
            $this->send_sms_internal([$phone], $message, 'Welcome SMS');
        }
    }

    private function parse_template($template, $booking = null, $agent = null, $customer = null) {
        // Use Latepoint's native variable replacement
        if ($booking) {
            $template = apply_filters('latepoint_replace_booking_vars', $template, $booking);
            if ($customer === null) {
                $customer = $booking->customer;
            }
        }
        
        if ($customer) {
            $template = apply_filters('latepoint_replace_customer_vars', $template, $customer);
        }
        
        if ($agent) {
            $template = str_replace('{{agent_full_name}}', $agent->full_name, $template);
            $template = str_replace('{{agent_first_name}}', $agent->first_name, $template);
            $template = str_replace('{{agent_phone}}', $agent->phone, $template);
            $template = str_replace('{{agent_email}}', $agent->email, $template);
        }
        
        return strip_tags($template);
    }

    public function replace_sms_variables($text, $object) {
        // This hook allows other addons to add their own variables
        return $text;
    }

    public function show_booking_vars() {
        ?>
        <div class="os-form-sub-header">
            <h3><?php _e('SMS Variables - Booking', 'latepoint-orzi-sms'); ?></h3>
            <div class="os-form-sub-header-actions">
                <span class="os-sms-variable-tag">{{booking_id}}</span>
                <span class="os-sms-variable-tag">{{booking_code}}</span>
                <span class="os-sms-variable-tag">{{service_name}}</span>
                <span class="os-sms-variable-tag">{{start_date}}</span>
                <span class="os-sms-variable-tag">{{start_time}}</span>
                <span class="os-sms-variable-tag">{{end_time}}</span>
            </div>
        </div>
        <?php
    }

    public function show_customer_vars() {
        ?>
        <div class="os-form-sub-header">
            <h3><?php _e('SMS Variables - Customer', 'latepoint-orzi-sms'); ?></h3>
            <div class="os-form-sub-header-actions">
                <span class="os-sms-variable-tag">{{customer_full_name}}</span>
                <span class="os-sms-variable-tag">{{customer_first_name}}</span>
                <span class="os-sms-variable-tag">{{customer_phone}}</span>
                <span class="os-sms-variable-tag">{{customer_email}}</span>
            </div>
        </div>
        <?php
    }

    private function is_sms_enabled($type) {
        return (OsSettingsHelper::get_settings_value('orzi_sms_' . $type . '_enabled', 'off') === 'on');
    }

    private function get_sms_template($type) {
        return OsSettingsHelper::get_settings_value('orzi_sms_' . $type . '_template', '');
    }

    private function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($phone)) {
            return '';
        }

        // Get default country code from settings
        $default_country = OsSettingsHelper::get_settings_value('orzi_sms_default_country_code', '44');

        // If it starts with 0, replace with country code
        if (substr($phone, 0, 1) === '0') {
            $phone = $default_country . substr($phone, 1);
        }
        
        // If it doesn't start with a country code and is too short, prepend default country
        if (strlen($phone) < 10) {
            $phone = $default_country . $phone;
        }

        return $phone;
    }

    private function send_sms_internal($phone_numbers, $message, $context = '') {
        $api_key = OsSettingsHelper::get_settings_value('orzi_sms_api_key');
        
        if (empty($api_key)) {
            OsDebugHelper::log('ORZI SMS: API key not configured', 'orzi_sms_error');
            return false;
        }

        return $this->send_sms($api_key, $phone_numbers, $message, $context);
    }

    public function send_sms($api_key, $phone_numbers, $message, $context = '') {
        $url = 'https://api.orzi.app/api/SMS/Send';
        
        $body = [
            'MobilenumberList' => $phone_numbers,
            'SMSBody' => $message
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Key' => $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 30
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            OsDebugHelper::log('ORZI SMS Error (' . $context . '): ' . $response->get_error_message(), 'orzi_sms_error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            OsDebugHelper::log('ORZI SMS sent successfully (' . $context . ') to: ' . implode(', ', $phone_numbers), 'orzi_sms_success');
            return true;
        } else {
            OsDebugHelper::log('ORZI SMS Error (' . $context . '): HTTP ' . $response_code . ' - ' . $response_body, 'orzi_sms_error');
            return false;
        }
    }

    public function settings_page() {
        ?>
        <div class="os-section-header">
            <h3><?php _e('ORZI SMS Settings', 'latepoint-orzi-sms'); ?></h3>
        </div>
        <div class="os-settings-form-w">
            <form action="" method="post">
                <div class="white-box">
                    <div class="white-box-header">
                        <div class="os-form-sub-header">
                            <h3><?php _e('API Configuration', 'latepoint-orzi-sms'); ?></h3>
                        </div>
                    </div>
                    <div class="white-box-content">
                        <?php 
                        echo OsFormHelper::text_field('settings[orzi_sms_api_key]', __('ORZI API Key', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_api_key'), ['placeholder' => __('Enter your ORZI API key', 'latepoint-orzi-sms')]); 
                        
                        echo OsFormHelper::text_field('settings[orzi_sms_default_country_code]', __('Default Country Code', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_default_country_code', '44'), ['placeholder' => '44']); 
                        ?>
                        <div class="os-form-group">
                            <label><?php _e('Test SMS', 'latepoint-orzi-sms'); ?></label>
                            <div class="os-form-row">
                                <input type="text" id="test_phone" placeholder="447829999999" class="os-form-control" />
                                <button type="button" class="latepoint-btn latepoint-btn-outline" id="send_test_sms">
                                    <?php _e('Send Test SMS', 'latepoint-orzi-sms'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="white-box">
                    <div class="white-box-header">
                        <div class="os-form-sub-header">
                            <h3><?php _e('Customer SMS Notifications', 'latepoint-orzi-sms'); ?></h3>
                        </div>
                    </div>
                    <div class="white-box-content">
                        <?php
                        echo OsFormHelper::checkbox_field('settings[orzi_sms_booking_created_enabled]', __('Send SMS when booking is created', 'latepoint-orzi-sms'), 'on', (OsSettingsHelper::get_settings_value('orzi_sms_booking_created_enabled') === 'on'));
                        
                        echo OsFormHelper::textarea_field('settings[orzi_sms_booking_created_template]', __('Booking Created Template', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_booking_created_template', 'Hi {{customer_first_name}}, your appointment for {{service_name}} is confirmed for {{start_date}} at {{start_time}}. Booking #{{booking_code}}'), ['rows' => 4, 'placeholder' => __('Use variables like {{customer_first_name}}, {{service_name}}, {{start_date}}, {{start_time}}', 'latepoint-orzi-sms')]);

                        echo OsFormHelper::checkbox_field('settings[orzi_sms_booking_status_approved_enabled]', __('Send SMS when booking is approved', 'latepoint-orzi-sms'), 'on', (OsSettingsHelper::get_settings_value('orzi_sms_booking_status_approved_enabled') === 'on'));
                        
                        echo OsFormHelper::textarea_field('settings[orzi_sms_booking_status_approved_template]', __('Booking Approved Template', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_booking_status_approved_template', 'Great news {{customer_first_name}}! Your appointment has been approved for {{start_date}} at {{start_time}}.'), ['rows' => 4]);

                        echo OsFormHelper::checkbox_field('settings[orzi_sms_booking_status_cancelled_enabled]', __('Send SMS when booking is cancelled', 'latepoint-orzi-sms'), 'on', (OsSettingsHelper::get_settings_value('orzi_sms_booking_status_cancelled_enabled') === 'on'));
                        
                        echo OsFormHelper::textarea_field('settings[orzi_sms_booking_status_cancelled_template]', __('Booking Cancelled Template', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_booking_status_cancelled_template', 'Hi {{customer_first_name}}, your appointment for {{start_date}} has been cancelled. Contact us if you have questions.'), ['rows' => 4]);

                        echo OsFormHelper::checkbox_field('settings[orzi_sms_customer_welcome_enabled]', __('Send welcome SMS to new customers', 'latepoint-orzi-sms'), 'on', (OsSettingsHelper::get_settings_value('orzi_sms_customer_welcome_enabled') === 'on'));
                        
                        echo OsFormHelper::textarea_field('settings[orzi_sms_customer_welcome_template]', __('Welcome SMS Template', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_customer_welcome_template', 'Welcome {{customer_first_name}}! Thank you for choosing us. We look forward to serving you.'), ['rows' => 4]);
                        ?>
                    </div>
                </div>

                <div class="white-box">
                    <div class="white-box-header">
                        <div class="os-form-sub-header">
                            <h3><?php _e('Agent/Staff SMS Notifications', 'latepoint-orzi-sms'); ?></h3>
                        </div>
                    </div>
                    <div class="white-box-content">
                        <?php
                        echo OsFormHelper::checkbox_field('settings[orzi_sms_agent_booking_created_enabled]', __('Notify agent when new booking is created', 'latepoint-orzi-sms'), 'on', (OsSettingsHelper::get_settings_value('orzi_sms_agent_booking_created_enabled') === 'on'));
                        
                        echo OsFormHelper::textarea_field('settings[orzi_sms_agent_booking_created_template]', __('Agent Notification Template', 'latepoint-orzi-sms'), OsSettingsHelper::get_settings_value('orzi_sms_agent_booking_created_template', 'New booking: {{customer_full_name}} for {{service_name}} on {{start_date}} at {{start_time}}. Booking #{{booking_code}}'), ['rows' => 4]);
                        ?>
                    </div>
                </div>

                <?php 
                wp_nonce_field('latepoint_settings');
                echo OsFormHelper::button('submit', __('Save Settings', 'latepoint-orzi-sms'), 'submit', ['class' => 'latepoint-btn']); 
                ?>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#send_test_sms').on('click', function() {
                var phone = $('#test_phone').val();
                var apiKey = $('input[name="settings[orzi_sms_api_key]"]').val();
                
                if (!phone || !apiKey) {
                    alert('<?php _e('Please enter both API key and phone number', 'latepoint-orzi-sms'); ?>');
                    return;
                }
                
                var btn = $(this);
                btn.prop('disabled', true).text('<?php _e('Sending...', 'latepoint-orzi-sms'); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'orzi_send_test_sms',
                        phone: phone,
                        api_key: apiKey,
                        nonce: '<?php echo wp_create_nonce('orzi_test_sms'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php _e('Test SMS sent successfully!', 'latepoint-orzi-sms'); ?>');
                        } else {
                            alert('<?php _e('Error:', 'latepoint-orzi-sms'); ?> ' + response.data);
                        }
                    },
                    error: function() {
                        alert('<?php _e('An error occurred', 'latepoint-orzi-sms'); ?>');
                    },
                    complete: function() {
                        btn.prop('disabled', false).text('<?php _e('Send Test SMS', 'latepoint-orzi-sms'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function activate() {
        update_option('latepoint_addon_orzi_sms_version', LATEPOINT_ADDON_ORZI_SMS_VERSION);
        
        // Set default templates
        $defaults = [
            'orzi_sms_default_country_code' => '44',
            'orzi_sms_booking_created_template' => 'Hi {{customer_first_name}}, your appointment for {{service_name}} is confirmed for {{start_date}} at {{start_time}}. Booking #{{booking_code}}',
            'orzi_sms_booking_status_approved_template' => 'Great news {{customer_first_name}}! Your appointment has been approved for {{start_date}} at {{start_time}}.',
            'orzi_sms_booking_status_cancelled_template' => 'Hi {{customer_first_name}}, your appointment for {{start_date}} has been cancelled. Contact us if you have questions.',
            'orzi_sms_customer_welcome_template' => 'Welcome {{customer_first_name}}! Thank you for choosing us. We look forward to serving you.',
            'orzi_sms_agent_booking_created_template' => 'New booking: {{customer_full_name}} for {{service_name}} on {{start_date}} at {{start_time}}. Booking #{{booking_code}}'
        ];
        
        foreach ($defaults as $key => $value) {
            if (empty(get_option($key))) {
                update_option($key, $value);
            }
        }
    }

    public static function deactivate() {
        // Cleanup if needed
    }
}

// AJAX handler for test SMS
add_action('wp_ajax_orzi_send_test_sms', function() {
    check_ajax_referer('orzi_test_sms', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $phone = sanitize_text_field($_POST['phone']);
    $api_key = sanitize_text_field($_POST['api_key']);
    
    $addon = new OsOrziSmsAddon();
    $result = $addon->send_sms($api_key, [$phone], 'This is a test SMS from Latepoint ORZI SMS addon.', 'Test');
    
    if ($result) {
        wp_send_json_success('SMS sent successfully');
    } else {
        wp_send_json_error('Failed to send SMS. Check debug logs for details.');
    }
});

// Save settings handler - using Latepoint's save action
add_action('wp_ajax_latepoint_route_call', function() {
    if (isset($_POST['route_name']) && $_POST['route_name'] === 'settings__update') {
        if (isset($_POST['settings']) && is_array($_POST['settings'])) {
            foreach ($_POST['settings'] as $key => $value) {
                if (strpos($key, 'orzi_sms_') === 0) {
                    OsSettingsHelper::save_setting_by_name($key, sanitize_text_field($value));
                }
            }
        }
    }
}, 5);

// Initialize the addon
function latepoint_addon_orzi_sms_init() {
    if (class_exists('OsSettingsHelper')) {
        new OsOrziSmsAddon();
    }
}
add_action('plugins_loaded', 'latepoint_addon_orzi_sms_init');

// Activation/Deactivation hooks
register_activation_hook(__FILE__, ['OsOrziSmsAddon', 'activate']);
register_deactivation_hook(__FILE__, ['OsOrziSmsAddon', 'deactivate']);
