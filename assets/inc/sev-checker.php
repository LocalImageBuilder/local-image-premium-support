<?php
class SEV_Checker {
    /**
     * Initialize the checker functionality
     */
    public static function init() {
        // Schedule monthly check
        add_action('admin_init', array(__CLASS__, 'schedule_monthly_check'));
        
        // Add the check action to our scheduled event
        add_action('sev_checker_monthly_event', array(__CLASS__, 'run_check'));
        
        // Check when the option changes
        add_action('update_option_blog_public', array(__CLASS__, 'on_option_update'), 10, 2);
    }

    /**
     * Run the search engine visibility check
     */
    public static function run_check() {
        if ((int) get_option('blog_public') === 0) {
            self::send_notification();
        }
    }

    /**
     * Handle option updates
     */
    public static function on_option_update($old_value, $new_value) {
        if ((int) $new_value === 0) {
            self::send_notification();
        }
    }

    /**
     * Schedule monthly check
     */
    public static function schedule_monthly_check() {
        if (!wp_next_scheduled('sev_checker_monthly_event')) {
            wp_schedule_event(time(), 'monthly', 'sev_checker_monthly_event');
        }
    }

    /**
     * Send notification email
     */
    public static function send_notification() {
        $to = 'hosting@localimageco.com';
        $subject = 'Search Engine Visibility Alert: ' . get_bloginfo('name');
        
        $message = "Search engines are currently discouraged from indexing the site.\n\n";
        $message .= "Site URL: " . home_url() . "\n";
        $message .= "Network(Multisite): " . (is_multisite() ? 'Yes (Site ID: ' . get_current_blog_id() . ')' : 'No') . "\n";
        $message .= "\nPlease check the site's settings under Settings > Reading in WordPress admin.";
        
        $mail_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! is_string( $mail_host ) || '' === $mail_host ) {
            $mail_host = 'localhost';
        }

        // Set custom headers for From Name
        $headers = array(
            'From: Local Image Premium Support <wordpress@' . sanitize_text_field( $mail_host ) . '>',
            'Content-Type: text/plain; charset=UTF-8',
        );
        
        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Clean up scheduled events
     */
    public static function cleanup() {
        wp_clear_scheduled_hook('sev_checker_monthly_event');
    }
}