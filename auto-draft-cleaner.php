<?php
/**
 * Plugin Name: Auto Draft Cleaner
 * Description: Automatically deletes old auto-draft posts and reusable blocks after a safe time window. Includes settings page, dashboard log, and manual cleanup.
 * Version: 1.1.0
 * Author: Shoky77
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AutoDraftCleaner {
    const OPTION_LAST_RUN = 'adc_last_cleanup';
    const OPTION_DELAY_HOURS = 'adc_delay_hours';

    public function __construct() {
        add_action( 'init', [ $this, 'schedule_cleanup_cron' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_notices', [ $this, 'admin_notice' ] );
        add_action( 'admin_post_adc_manual_cleanup', [ $this, 'manual_cleanup' ] );
        add_action( 'adc_cleanup_cron_event', [ $this, 'clean_old_drafts' ] );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function activate() {
        if ( ! wp_next_scheduled( 'adc_cleanup_cron_event' ) ) {
            wp_schedule_event( time(), 'hourly', 'adc_cleanup_cron_event' );
        }
        add_option( self::OPTION_DELAY_HOURS, 3 );
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'adc_cleanup_cron_event' );
    }

    public function schedule_cleanup_cron() {
        if ( ! wp_next_scheduled( 'adc_cleanup_cron_event' ) ) {
            wp_schedule_event( time(), 'hourly', 'adc_cleanup_cron_event' );
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'Auto Draft Cleaner',
            'Auto Draft Cleaner',
            'manage_options',
            'auto-draft-cleaner',
            [ $this, 'settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'adc_settings_group', self::OPTION_DELAY_HOURS );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Auto Draft Cleaner Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'adc_settings_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Hours Before Deletion</th>
                        <td><input type="number" name="<?php echo self::OPTION_DELAY_HOURS; ?>" value="<?php echo esc_attr( get_option( self::OPTION_DELAY_HOURS, 3 ) ); ?>" min="1" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="adc_manual_cleanup" />
                <?php submit_button( 'Run Manual Cleanup', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    public function admin_notice() {
        if ( current_user_can( 'manage_options' ) ) {
            $last_run = get_option( self::OPTION_LAST_RUN );
            if ( $last_run ) {
                echo '<div class="notice notice-info"><p>Auto Draft Cleaner last ran at: <strong>' . esc_html( $last_run ) . '</strong></p></div>';
            }
        }
    }

    public function manual_cleanup() {
        if ( current_user_can( 'manage_options' ) ) {
            $this->clean_old_drafts();
        }
        wp_redirect( admin_url( 'options-general.php?page=auto-draft-cleaner' ) );
        exit;
    }

    public function clean_old_drafts() {
        $delay = intval( get_option( self::OPTION_DELAY_HOURS, 3 ) );
        $cutoff = current_time( 'timestamp' ) - ( $delay * HOUR_IN_SECONDS );

        $auto_drafts = new WP_Query([
            'post_type'      => [ 'post', 'page', 'wp_block' ],
            'post_status'    => 'auto-draft',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'ASC',
            'date_query'     => [
                [
                    'column' => 'post_modified_gmt',
                    'before' => gmdate( 'Y-m-d H:i:s', $cutoff ),
                ],
            ],
        ]);

        if ( $auto_drafts->have_posts() ) {
            foreach ( $auto_drafts->posts as $post_id ) {
                wp_delete_post( $post_id, true );
            }
        }
        update_option( self::OPTION_LAST_RUN, current_time( 'mysql' ) );
    }
}

new AutoDraftCleaner();
