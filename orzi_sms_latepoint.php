<?php
/**
 * Plugin Name: Latepoint Addon - ORZI SMS
 * Plugin URI: https://latepoint.com/
 * Description: ORZI SMS Integration for Latepoint
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
        add_action('latepoint_includes', [$this, 'register_addon']);
        add_filter('latepoint_notifications_channels', [$this, 'add_sms_channel']);
        add_filter('latepoint_notification_channels_for_processor', [$this, 'add_sms_processor'], 10, 2);
    }

    public function register_addon() {
        do_action('latepoint_addon_orzi_sms_included');
    }

    public function add_sms_channel($channels) {
        $channels['sms'] = __('SMS (ORZI)', 'latepoint-orzi-sms');
        return $channels;
    }

    public function add_sms_processor($channels, $notification) {
        if (isset($channels['sms'])) {
            $channels['sms'] = [$this, 'send_sms_notification'];
        }
        return $channels;
    }

    public function send_sms_notification($notification, $booking) {
        $api_key = OsSettingsHelper::get_settings_value('orzi_sms_api_key');
        
        if (empty($api_key)) {
            OsDebugHelper::log('ORZI SMS: API key not configured', 'orzi_sms_error');
            return false;
        }

        // Get phone number from booking
        $phone = $this->format_phone_number($booking->customer->phone);
        
        if (empty($phone)) {
            OsDebugHelper::log('ORZI SMS: No phone number found for customer', 'orzi_sms_error');
            return false;
        }

        // Parse message body
        $message = OsNotificationsHelper::replace_notification_variables($notification->content, $booking);
        $message = strip_tags($message);

        return $this->send_sms($api_key, [$phone], $message);
    }

    private function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Basic validation - ensure we have a number
        if (empty($phone)) {
            return '';
        }

        // If it starts with 0, replace with country code (assuming UK, adjust as needed)
        if (substr($phone, 0, 1) === '0') {
            $phone = '44' . substr($phone, 1);
        }

        return $phone;
    }

    public function send_sms($api_key, $phone_numbers, $message) {
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
            OsDebugHelper::log('ORZI SMS Error: ' . $response->get_error_message(), 'orzi_sms_error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            OsDebugHelper::log('ORZI SMS sent successfully to: ' . implode(', ', $phone_numbers), 'orzi_sms_success');
            return true;
        } else {
            OsDebugHelper::log('ORZI SMS Error: HTTP ' . $response_code . ' - ' . $response_body, 'orzi_sms_error');
            return false;
        }
    }

    public static function activate() {
        // Activation tasks
        update_option('latepoint_addon_orzi_sms_version', LATEPOINT_ADDON_ORZI_SMS_VERSION);
    }

    public static function deactivate() {
        // Deactivation tasks
    }
}

// Settings integration
add_filter('latepoint_settings_sections', function($sections) {
    $sections['notifications']['children']['orzi_sms'] = [
        'label' => __('ORZI SMS', 'latepoint-orzi-sms'),
        'icon' => 'latepoint-icon latepoint-icon-message-circle'
    ];
    return $sections;
});

add_action('latepoint_settings_page_notifications_orzi_sms', function() {
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
            <?php echo OsFormHelper::button('submit', __('Save Settings', 'latepoint-orzi-sms'), 'submit', ['class' => 'latepoint-btn']); ?>
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
});

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
    $result = $addon->send_sms($api_key, [$phone], 'This is a test SMS from Latepoint ORZI SMS addon.');
    
    if ($result) {
        wp_send_json_success('SMS sent successfully');
    } else {
        wp_send_json_error('Failed to send SMS. Check debug logs for details.');
    }
});

// Save settings handler
add_action('latepoint_settings_saved', function($section) {
    if ($section === 'notifications.orzi_sms') {
        if (isset($_POST['settings']['orzi_sms_api_key'])) {
            OsSettingsHelper::save_setting_by_name('orzi_sms_api_key', sanitize_text_field($_POST['settings']['orzi_sms_api_key']));
        }
    }
});

// Initialize the addon
function latepoint_addon_orzi_sms_init() {
    new OsOrziSmsAddon();
}
add_action('plugins_loaded', 'latepoint_addon_orzi_sms_init');

// Activation/Deactivation hooks
register_activation_hook(__FILE__, ['OsOrziSmsAddon', 'activate']);
register_deactivation_hook(__FILE__, ['OsOrziSmsAddon', 'deactivate']);
