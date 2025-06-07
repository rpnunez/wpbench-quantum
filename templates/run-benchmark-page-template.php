<?php
/**
 * Template for the "Run New Benchmark" page.
 *
 * Variables available:
 * @var array $all_tests Associative array of available benchmark tests (key => name).
 * @var array $profiles Array of WP_Post objects for saved profiles.
 * @var WPSite_Benchmarker $this The instance of the WPSite_Benchmarker class.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="wrap">
    <h1><?php _e('Run New Benchmark', 'wp-site-benchmarker'); ?></h1>
    <div id="benchmark-notice-area" class="notice-area" style="margin-bottom: 15px;"></div>

    <div id="profile-selector-wrapper" style="margin: 20px 0; padding: 15px; border: 1px solid #c3c4c7; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h2><span class="dashicons dashicons-id-alt" style="vertical-align: text-bottom;"></span> <?php _e('Load a Profile', 'wp-site-benchmarker'); ?></h2>
        <p><?php _e('Select a pre-configured profile to automatically set the plugin and test configuration below.', 'wp-site-benchmarker'); ?></p>
        <?php
        if (!empty($profiles)) {
        ?>
        <label for="profile-selector"><?php _e('Select Profile:', 'wp-site-benchmarker'); ?></label>
        <select id="profile-selector" name="profile_id">
            <option value=""><?php _e('-- Manual Configuration --', 'wp-site-benchmarker'); ?></option>
            <?php foreach ($profiles as $profile) : ?>
                <option value="<?php echo esc_attr($profile->ID); ?>"><?php echo esc_html($profile->post_title); ?></option>
            <?php endforeach; ?>
        </select>
        <span class="spinner" id="profile-spinner" style="float: none;"></span>
        <?php
        } else {
            echo '<p>' . __('No profiles found. You can create one under the "Profiles" menu.', 'wp-site-benchmarker') . '</p>';
        }
        ?>
    </div>

    <form id="run-benchmark-form">
        <?php wp_nonce_field('wp_site_benchmark_run_nonce', 'wp_benchmark_nonce_field'); ?>
        <input type="hidden" name="action" value="wp_site_benchmark_run">
        <input type="hidden" name="profile_id_hidden" id="profile_id_hidden" value="">

        <h3>1. <?php _e('Tests to Run', 'wp-site-benchmarker'); ?></h3>
        <fieldset id="tests-to-run-fieldset" style="margin-bottom: 20px;">
            <?php foreach ($all_tests as $key => $name) : ?>
                <div style="margin-bottom: 10px;">
                    <label style="display: inline-block; width: 200px;">
                        <input type="checkbox" name="benchmark_tests[]" value="<?php echo esc_attr($key); ?>" checked> <?php echo esc_html($name); ?>
                    </label>
                    <label style="margin-left: 20px;">
                        <?php _e('Intensity:', 'wp-site-benchmarker'); ?>
                        <input type="number" name="benchmark_tests_intensity[<?php echo esc_attr($key); ?>]" value="50" min="0" max="100" step="10" class="small-text" style="width: 70px;">
                        <span class="description">(0-100)</span>
                    </label>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <h3>2. <?php _e('Plugin Configuration', 'wp-site-benchmarker'); ?></h3>
        <p><label><input type="checkbox" id="select-all-plugins-toggle"> <?php _e('Toggle All Plugins', 'wp-site-benchmarker'); ?></label></p>
        <?php $this->render_plugin_list(); // $this is available from the calling method scope ?>
        
        <p class="submit">
            <button type="button" id="run-benchmark-button" class="button button-primary button-hero">
                <span class="dashicons dashicons-controls-play" style="vertical-align: middle;"></span> <?php _e('Run Benchmark', 'wp-site-benchmarker'); ?>
            </button>
            <span class="spinner" style="float: none; vertical-align: middle; margin-left: 5px;"></span>
        </p>
    </form>

    <div id="benchmark-progress-area" style="display:none; margin-top: 20px; padding: 15px; border: 1px solid #ccd0d4; background-color: #f6f7f7; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h3><?php _e('Benchmark Progress:', 'wp-site-benchmarker'); ?></h3>
        <pre id="benchmark-log" style="white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto; background-color: #fff; padding: 10px; border: 1px solid #e5e5e5;"></pre>
    </div>
</div>