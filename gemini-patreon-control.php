<?php
/**
 * Plugin Name: Gemini Patreon Control
 * Description: Stores Gemini & Patreon credentials and basic settings for the Gemini→WP→Patreon automation. Step 1/4.
 * Version: 0.1
 * Author: Farhan Sheikh
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add menu pages
 */
add_action( 'admin_menu', function() {
    add_menu_page(
        'Gemini Patreon Control',
        'Gemini Control',
        'manage_options',
        'gemini-patreon-control',
        'gpc_main_page',
        'dashicons-admin-generic',
        81
    );

    add_submenu_page(
        'gemini-patreon-control',
        'Settings',
        'Settings',
        'manage_options',
        'gpc-settings',
        'gpc_settings_page'
    );
});

/**
 * Main dashboard page (placeholder for prompt UI we'll add later)
 */
function gpc_main_page() {
    ?>
    <div class="wrap">
        <h1>Gemini → WordPress → Patreon</h1>
        <p>This plugin stores keys and settings used for automating episode unlocks with Gemini and the Patreon API.</p>

        <h2>Quick status</h2>
        <ul>
            <li>Gemini API key: <?php echo gpc_has('gpc_gemini_key') ? '<mark>set</mark>' : '<strong style="color:#b00">not set</strong>'; ?></li>
            <li>Patreon access token: <?php echo gpc_has('gpc_patreon_access_token') ? '<mark>set</mark>' : '<strong style="color:#b00">not set</strong>'; ?></li>
            <li>Patreon refresh token: <?php echo gpc_has('gpc_patreon_refresh_token') ? '<mark>set</mark>' : '<strong style="color:#b00">not set</strong>'; ?></li>
            <li>Episode number meta key: <code><?php echo esc_html( get_option('gpc_episode_number_meta', 'episode_number') ); ?></code></li>
            <li>Patreon post ID meta key: <code><?php echo esc_html( get_option('gpc_patreon_post_meta', 'patreon_post_id') ); ?></code></li>
        </ul>
        

        <p>Next: go to <a href="<?php echo menu_page_url('gpc-settings', false); ?>">Settings</a> and paste your keys and field names.</p>
    </div>
    <?php
}

/**
 * Settings page: store Gemeni key, Patreon tokens, and field names
 */
function gpc_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['gpc_save_settings'] ) && check_admin_referer('gpc_save_settings_nonce') ) {
        // Save safely
        update_option( 'gpc_gemini_key', sanitize_text_field( $_POST['gpc_gemini_key'] ) );
        update_option( 'gpc_patreon_access_token', sanitize_text_field( $_POST['gpc_patreon_access_token'] ) );
        update_option( 'gpc_patreon_refresh_token', sanitize_text_field( $_POST['gpc_patreon_refresh_token'] ) );
        update_option( 'gpc_patreon_client_id', sanitize_text_field( $_POST['gpc_patreon_client_id'] ) );
        update_option( 'gpc_patreon_client_secret', sanitize_text_field( $_POST['gpc_patreon_client_secret'] ) );
        update_option( 'gpc_episode_number_meta', sanitize_text_field( $_POST['gpc_episode_number_meta'] ) );
        update_option( 'gpc_patreon_post_meta', sanitize_text_field( $_POST['gpc_patreon_post_meta'] ) );
        update_option( 'gpc_acf_field_type', sanitize_text_field( $_POST['gpc_acf_field_type'] ) );
        update_option('gpc_patreon_silver_tier_id', sanitize_text_field($_POST['gpc_patreon_silver_tier_id']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $gemini_key = get_option('gpc_gemini_key', '');
    $patreon_access = get_option('gpc_patreon_access_token', '');
    $patreon_refresh = get_option('gpc_patreon_refresh_token', '');
    $patreon_client_id = get_option('gpc_patreon_client_id', '');
    $patreon_client_secret = get_option('gpc_patreon_client_secret', '');
    $episode_meta = get_option('gpc_episode_number_meta', 'episode_number');
    $patreon_post_meta = get_option('gpc_patreon_post_meta', 'patreon_post_id');
    $acf_type_field = get_option('gpc_acf_field_type', 'section');
    ?>
    <div class="wrap">
        <h1>Gemini Patreon Control — Settings</h1>
        <form method="post">
            <?php wp_nonce_field('gpc_save_settings_nonce'); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th><label for="gpc_gemini_key">Gemini API Key</label></th>
                        <td><input name="gpc_gemini_key" id="gpc_gemini_key" type="text" style="width:420px" value="<?php echo esc_attr( $gemini_key ); ?>" />
                        <p class="description">Paste your Gemini/Google AI API key (from AI Studio).</p></td>
                    </tr>

                    <tr>
                        <th><label for="gpc_patreon_access_token">Patreon Creator Access Token</label></th>
                        <td><input name="gpc_patreon_access_token" id="gpc_patreon_access_token" type="text" style="width:420px" value="<?php echo esc_attr( $patreon_access ); ?>" />
                        <p class="description">Creator Access Token (used to call Patreon API).</p></td>
                    </tr>
                    <tr>
                        <th><label for="gpc_patreon_silver_tier_id">Patreon Silver Tier ID</label></th>
                        <td>
                        <input name="gpc_patreon_silver_tier_id" id="gpc_patreon_silver_tier_id" type="text" style="width:200px" value="<?php echo esc_attr(get_option('gpc_patreon_silver_tier_id', '')); ?>" />
                        <p class="description">Your $5 Silver tier ID (for advance episodes). Find this in your Patreon tier settings.</p>
    </td>
</tr>

                    <tr>
                        <th><label for="gpc_patreon_refresh_token">Patreon Refresh Token</label></th>
                        <td><input name="gpc_patreon_refresh_token" id="gpc_patreon_refresh_token" type="text" style="width:420px" value="<?php echo esc_attr( $patreon_refresh ); ?>" />
                        <p class="description">Refresh token (optional, used to get new access token).</p></td>
                    </tr>

                    <tr>
                        <th><label for="gpc_patreon_client_id">Patreon Client ID</label></th>
                        <td><input name="gpc_patreon_client_id" id="gpc_patreon_client_id" type="text" style="width:420px" value="<?php echo esc_attr( $patreon_client_id ); ?>" /></td>
                    </tr>

                    <tr>
                        <th><label for="gpc_patreon_client_secret">Patreon Client Secret</label></th>
                        <td><input name="gpc_patreon_client_secret" id="gpc_patreon_client_secret" type="text" style="width:420px" value="<?php echo esc_attr( $patreon_client_secret ); ?>" /></td>
                    </tr>

                    <tr>
                        <th><label for="gpc_episode_number_meta">Episode Number meta key</label></th>
                        <td><input name="gpc_episode_number_meta" id="gpc_episode_number_meta" type="text" style="width:200px" value="<?php echo esc_attr( $episode_meta ); ?>" />
                        <p class="description">Meta key that stores episode number (default: <code>episode_number</code>).</p></td>
                    </tr>

                    <tr>
                        <th><label for="gpc_patreon_post_meta">Patreon post ID meta key</label></th>
                        <td><input name="gpc_patreon_post_meta" id="gpc_patreon_post_meta" type="text" style="width:200px" value="<?php echo esc_attr( $patreon_post_meta ); ?>" />
                        <p class="description">Meta key that stores Patreon post ID for the episode (e.g. <code>patreon_post_id</code>).</p></td>
                    </tr>

                    <tr>
                        <th><label for="gpc_acf_field_type">ACF field name for type</label></th>
                        <td><input name="gpc_acf_field_type" id="gpc_acf_field_type" type="text" style="width:200px" value="<?php echo esc_attr( $acf_type_field ); ?>" />
                        <p class="description">ACF field name that stores 'advance' or 'free' (default: <code>section</code>).</p></td>
                    </tr>
                </tbody>
            </table>

            <p class="submit"><input type="submit" name="gpc_save_settings" id="submit" class="button button-primary" value="Save Settings"></p>
        </form>

        <h2>Notes & safety</h2>
        <ul>
            <li>Keep your tokens secret. This plugin stores them in the WP options table.</li>
            <li>If you prefer, we can change storage to an environment variable or `wp-config.php` constant for extra safety.</li>
            <li>Next step after this: add the Gemini command UI and the logic that updates WP + Patreon. Ready for Step 2?</li>
        </ul>
    </div>
    <?php
}

/**
 * small helper
 */
function gpc_has($option_name) {
    $v = get_option($option_name, '');
    return ! empty( $v );
}
require_once plugin_dir_path(__FILE__) . 'gemini-prompt.php';
