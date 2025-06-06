<?php
/**
 * Plugin Name:       WP Site Benchmarker
 * Plugin URI:        https://example.com/wp-site-benchmarker
 * Description:       Allows running performance benchmarks on your WordPress site, with control over active plugins during tests and a calculated benchmark score.
 * Version:           1.2.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-site-benchmarker
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'WP_SITE_BENCHMARKER_VERSION', '1.2.0' );
define( 'WP_SITE_BENCHMARKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_SITE_BENCHMARKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

class WPSite_Benchmarker {

    private static $instance;
    private $plugin_slug = 'wp-site-benchmarker';
    private $benchmark_cpt_slug = 'wp_benchmark';
    private $profile_cpt_slug = 'wpb_profile'; // New CPT for Profiles
    private $settings_option_group = 'wp_benchmarker_settings_group';
    private $settings_option_name = 'wp_benchmarker_settings';

    // --- Scoring Constants ---
    private const SCORE_REF_CPU_SECONDS = 1.0;
    private const SCORE_REF_MEM_MB = 32.0;
    private const SCORE_REF_DB_READ_SECONDS = 0.5;
    private const SCORE_REF_DB_WRITE_SECONDS = 0.5;
    private const SCORE_WEIGHT_CPU = 1000;
    private const SCORE_WEIGHT_MEM = 1000;
    private const SCORE_WEIGHT_DB_READ = 1000;
    private const SCORE_WEIGHT_DB_WRITE = 1000;
    private const MIN_DURATION_SECONDS = 0.001;
    private const MIN_MEM_BYTES = 1024;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        
        // AJAX Actions
        add_action( 'wp_ajax_wp_site_benchmark_run', array( $this, 'handle_ajax_run_benchmark' ) );
        add_action( 'wp_ajax_wpb_get_profile_data', array( $this, 'handle_ajax_get_profile_data' ) ); // New AJAX for profiles

        // CPT Admin Columns
        add_filter( 'manage_' . $this->benchmark_cpt_slug . '_posts_columns', array( $this, 'set_custom_edit_benchmark_columns' ) );
        add_action( 'manage_' . $this->benchmark_cpt_slug . '_posts_custom_column', array( $this, 'custom_benchmark_column_content' ), 10, 2 );

        // Meta Boxes for Profile CPT
        add_action( 'add_meta_boxes', array( $this, 'add_profile_meta_boxes' ) );
        add_action( 'save_post_' . $this->profile_cpt_slug, array( $this, 'save_profile_meta_data' ) );

        // Display results on single Benchmark CPT page
        add_filter( 'the_content', array( $this, 'display_benchmark_results_in_content' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'WPBench', 'wp-site-benchmarker' ),
            __( 'WPBench', 'wp-site-benchmarker' ),
            'manage_options',
            $this->plugin_slug,
            array( $this, 'render_run_benchmark_page' ),
            'dashicons-dashboard',
            80
        );
        add_submenu_page(
            $this->plugin_slug,
            __( 'Run New Benchmark', 'wp-site-benchmarker' ),
            __( 'Run New Benchmark', 'wp-site-benchmarker' ),
            'manage_options',
            $this->plugin_slug,
            array( $this, 'render_run_benchmark_page' )
        );
        add_submenu_page(
            $this->plugin_slug,
            __( 'Benchmarks', 'wp-site-benchmarker' ),
            __( 'Benchmarks', 'wp-site-benchmarker' ),
            'manage_options',
            'edit.php?post_type=' . $this->benchmark_cpt_slug
        );
        add_submenu_page(
            $this->plugin_slug,
            __( 'Profiles', 'wp-site-benchmarker' ),
            __( 'Profiles', 'wp-site-benchmarker' ),
            'manage_options',
            'edit.php?post_type=' . $this->profile_cpt_slug
        );
        add_submenu_page(
            $this->plugin_slug,
            __( 'WPBench Settings', 'wp-site-benchmarker' ),
            __( 'Settings', 'wp-site-benchmarker' ),
            'manage_options',
            $this->plugin_slug . '-settings',
            array( $this, 'render_settings_page' )
        );
    }
    
    public function register_settings() {
        register_setting(
            $this->settings_option_group, // Option group
            $this->settings_option_name, // Option name
            array( $this, 'sanitize_settings' ) // Sanitize callback
        );

        add_settings_section(
            'wpb_main_settings_section', // ID
            __( 'Example Settings', 'wp-site-benchmarker' ), // Title
            array( $this, 'print_section_info' ), // Callback
            $this->plugin_slug . '-settings' // Page
        );

        add_settings_field(
            'example_text_field', // ID
            __( 'Example Text Input', 'wp-site-benchmarker' ), // Title
            array( $this, 'render_text_field' ), // Callback
            $this->plugin_slug . '-settings', // Page
            'wpb_main_settings_section' // Section
        );

        add_settings_field(
            'example_textarea_field',
            __( 'Example Textarea', 'wp-site-benchmarker' ),
            array( $this, 'render_textarea_field' ),
            $this->plugin_slug . '-settings',
            'wpb_main_settings_section'
        );

        add_settings_field(
            'example_checkbox_field',
            __( 'Example Checkbox', 'wp-site-benchmarker' ),
            array( $this, 'render_checkbox_field' ),
            $this->plugin_slug . '-settings',
            'wpb_main_settings_section'
        );

        add_settings_field(
            'example_radio_field',
            __( 'Example Radio Buttons', 'wp-site-benchmarker' ),
            array( $this, 'render_radio_field' ),
            $this->plugin_slug . '-settings',
            'wpb_main_settings_section'
        );

        add_settings_field(
            'example_select_field',
            __( 'Example Select Dropdown', 'wp-site-benchmarker' ),
            array( $this, 'render_select_field' ),
            $this->plugin_slug . '-settings',
            'wpb_main_settings_section'
        );
    }
    
    public function sanitize_settings( $input ) {
        $sanitized_input = array();

        if ( isset( $input['example_text_field'] ) ) {
            $sanitized_input['example_text_field'] = sanitize_text_field( $input['example_text_field'] );
        }
        if ( isset( $input['example_textarea_field'] ) ) {
            $sanitized_input['example_textarea_field'] = sanitize_textarea_field( $input['example_textarea_field'] );
        }
        if ( isset( $input['example_checkbox_field'] ) ) {
            $sanitized_input['example_checkbox_field'] = '1';
        } else {
             $sanitized_input['example_checkbox_field'] = '0';
        }
        if ( isset( $input['example_radio_field'] ) ) {
            $sanitized_input['example_radio_field'] = sanitize_text_field( $input['example_radio_field'] );
        }
        if ( isset( $input['example_select_field'] ) ) {
            $sanitized_input['example_select_field'] = sanitize_text_field( $input['example_select_field'] );
        }

        return $sanitized_input;
    }

    public function print_section_info() {
        print __('Enter your settings below. These are just examples to demonstrate the Settings API.', 'wp-site-benchmarker');
    }

    // --- Field Rendering Callbacks ---

    public function render_text_field() {
        $options = get_option( $this->settings_option_name );
        $value = isset( $options['example_text_field'] ) ? esc_attr( $options['example_text_field'] ) : '';
        printf(
            '<input type="text" id="example_text_field" name="%s[example_text_field]" value="%s" class="regular-text" />',
            esc_attr($this->settings_option_name),
            $value
        );
    }

    public function render_textarea_field() {
        $options = get_option( $this->settings_option_name );
        $value = isset( $options['example_textarea_field'] ) ? esc_textarea( $options['example_textarea_field'] ) : '';
        printf(
            '<textarea id="example_textarea_field" name="%s[example_textarea_field]" rows="5" class="large-text">%s</textarea>',
            esc_attr($this->settings_option_name),
            $value
        );
    }

    public function render_checkbox_field() {
        $options = get_option( $this->settings_option_name );
        $checked = isset( $options['example_checkbox_field'] ) && $options['example_checkbox_field'] === '1' ? 'checked' : '';
        printf(
            '<input type="checkbox" id="example_checkbox_field" name="%s[example_checkbox_field]" value="1" %s /> <label for="example_checkbox_field">Enable this feature</label>',
            esc_attr($this->settings_option_name),
            $checked
        );
    }

    public function render_radio_field() {
        $options = get_option( $this->settings_option_name );
        $selected_option = isset( $options['example_radio_field'] ) ? $options['example_radio_field'] : 'option1';
        $radio_options = [
            'option1' => __('Option One', 'wp-site-benchmarker'),
            'option2' => __('Option Two', 'wp-site-benchmarker'),
            'option3' => __('Option Three', 'wp-site-benchmarker'),
        ];
        
        $output = '<fieldset>';
        foreach($radio_options as $value => $label) {
            $checked = checked($selected_option, $value, false);
            $output .= sprintf(
                '<label><input type="radio" name="%s[example_radio_field]" value="%s" %s /> %s</label><br />',
                esc_attr($this->settings_option_name),
                esc_attr($value),
                $checked,
                esc_html($label)
            );
        }
        $output .= '</fieldset>';
        echo $output;
    }

    public function render_select_field() {
        $options = get_option( $this->settings_option_name );
        $selected_option = isset( $options['example_select_field'] ) ? $options['example_select_field'] : 'choice2';
        $select_options = [
            'choice1' => __('First Choice', 'wp-site-benchmarker'),
            'choice2' => __('Second Choice', 'wp-site-benchmarker'),
            'choice3' => __('Third Choice', 'wp-site-benchmarker'),
        ];
        
        $output = sprintf('<select id="example_select_field" name="%s[example_select_field]">', esc_attr($this->settings_option_name));
        foreach($select_options as $value => $label) {
            $selected = selected($selected_option, $value, false);
            $output .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                $selected,
                esc_html($label)
            );
        }
        $output .= '</select>';
        echo $output;
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form method="post" action="options.php">
                <?php
                // This prints out all hidden setting fields
                settings_fields( $this->settings_option_group );
                // This prints out all settings sections and their fields
                do_settings_sections( $this->plugin_slug . '-settings' );
                // This prints the submit button
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_run_benchmark_page() {
        // This was the old render_admin_page method, renamed for clarity
        ?>
        $all_tests = $this->get_all_benchmark_tests();
        ?>
        <div class="wrap">
            <h1><?php _e('Run New Benchmark', 'wp-site-benchmarker'); ?></h1>
            <div id="benchmark-notice-area" class="notice-area" style="margin-bottom: 15px;"></div>

            <div id="profile-selector-wrapper" style="margin: 20px 0; padding: 15px; border: 1px solid #c3c4c7; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><span class="dashicons dashicons-id-alt" style="vertical-align: text-bottom;"></span> <?php _e('Load a Profile', 'wp-site-benchmarker'); ?></h2>
                <p><?php _e('Select a pre-configured profile to automatically set the plugin and test configuration below.', 'wp-site-benchmarker'); ?></p>
                <?php
                $profiles = get_posts(['post_type' => $this->profile_cpt_slug, 'numberposts' => -1, 'post_status' => 'publish']);
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
                        <label style="padding-right: 20px;"><input type="checkbox" name="benchmark_tests[]" value="<?php echo esc_attr($key); ?>" checked> <?php echo esc_html($name); ?></label>
                    <?php endforeach; ?>
                </fieldset>

                <h3>2. <?php _e('Plugin Configuration', 'wp-site-benchmarker'); ?></h3>
                <p><label><input type="checkbox" id="select-all-plugins-toggle"> <?php _e('Toggle All Plugins', 'wp-site-benchmarker'); ?></label></p>
                <?php $this->render_plugin_list(); // Use reusable method ?>
                
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

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#select-all-plugins-toggle').on('change', function() {
                $('#the-list input[type="checkbox"]').prop('checked', $(this).prop('checked'));
            });

            $('#run-benchmark-button').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $noticeArea = $('#benchmark-notice-area');
                var $progressArea = $('#benchmark-progress-area');
                var $logArea = $('#benchmark-log');

                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $noticeArea.html(''); 
                $logArea.html('');    
                $progressArea.show();

                var formData = $('#run-benchmark-form').serialize();
                $logArea.append("<?php _e('Initializing benchmark...', 'wp-site-benchmarker'); ?>\n");
                $logArea.append("<?php _e('This may take several minutes. Please do not navigate away from this page.', 'wp-site-benchmarker'); ?>\n\n");

                $.ajax({
                    url: ajaxurl, 
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $logArea.append("<?php _e('Benchmark completed successfully!', 'wp-site-benchmarker'); ?>\n");
                            if(response.data.log) {
                                response.data.log.forEach(function(entry) {
                                    $logArea.append(entry + "\n");
                                });
                            }
                            if(typeof response.data.score !== 'undefined') {
                                $logArea.append("\n<?php _e('Calculated Benchmark Score:', 'wp-site-benchmarker'); ?> " + response.data.score + "\n");
                            }
                            $logArea.append("\n<?php _e('Results ID:', 'wp-site-benchmarker'); ?> " + response.data.post_id + "\n");
                            $logArea.append("<?php _e('View results:', 'wp-site-benchmarker'); ?> <a href='" + response.data.post_link + "' target='_blank'>" + response.data.post_link + "</a>\n");
                            $noticeArea.html('<div class="notice notice-success is-dismissible"><p><?php _e('Benchmark completed!', 'wp-site-benchmarker'); ?> <?php _e('Score:', 'wp-site-benchmarker'); ?> ' + response.data.score + '. <a href="' + response.data.post_link + '" target="_blank"><?php _e('View results', 'wp-site-benchmarker'); ?></a>.</p></div>');
                        } else {
                            var errorMessage = response.data && response.data.message ? response.data.message : "<?php _e('An unknown error occurred.', 'wp-site-benchmarker'); ?>";
                             if(response.data.log) {
                                response.data.log.forEach(function(entry) {
                                    $logArea.append(entry + "\n");
                                });
                            }
                            $logArea.append("\n<?php _e('Error:', 'wp-site-benchmarker'); ?> " + errorMessage + "\n");
                            $noticeArea.html('<div class="notice notice-error is-dismissible"><p><?php _e('Benchmark failed:', 'wp-site-benchmarker'); ?> ' + errorMessage + '</p></div>');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        $logArea.append("<?php _e('AJAX Error:', 'wp-site-benchmarker'); ?> " + textStatus + " - " + errorThrown + "\n");
                        if (jqXHR.responseText) {
                             $logArea.append("<?php _e('Server Response:', 'wp-site-benchmarker'); ?>\n" + jqXHR.responseText.substring(0, 500) + "...\n");
                        }
                        $noticeArea.html('<div class="notice notice-error is-dismissible"><p><?php _e('An AJAX error occurred. Check the browser console and the log above for details.', 'wp-site-benchmarker'); ?></p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        $logArea.scrollTop($logArea[0].scrollHeight);
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function handle_ajax_run_benchmark() {
        check_ajax_referer('wp_site_benchmark_run_nonce', 'wp_benchmark_nonce_field');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $profile_id_used = isset($_POST['profile_id_hidden']) ? intval($_POST['profile_id_hidden']) : 0;
        $tests_to_run = isset($_POST['benchmark_tests']) ? (array)$_POST['benchmark_tests'] : [];
        $tests_to_run = array_map('sanitize_text_field', $tests_to_run);
        
        $benchmark_log = []; 
        $benchmark_results = [];

        ob_start();

        $original_active_plugins = (array) get_option( 'active_plugins', array() );
        $user_selected_plugins_for_benchmark = isset( $_POST['benchmark_plugins'] ) ? (array) $_POST['benchmark_plugins'] : array();
        
        $user_selected_plugins_for_benchmark = array_map('sanitize_text_field', $user_selected_plugins_for_benchmark);

        $plugins_to_deactivate_temporarily = array_diff( $original_active_plugins, $user_selected_plugins_for_benchmark );
        $plugins_to_activate_temporarily = array_diff( $user_selected_plugins_for_benchmark, $original_active_plugins );

        $self_plugin_file = plugin_basename(__FILE__);
        $plugins_to_deactivate_temporarily = array_filter($plugins_to_deactivate_temporarily, function($p) use ($self_plugin_file) { return $p !== $self_plugin_file; });
        $plugins_to_activate_temporarily = array_filter($plugins_to_activate_temporarily, function($p) use ($self_plugin_file) { return $p !== $self_plugin_file; });

        update_option( 'wp_benchmark_temp_deactivated', $plugins_to_deactivate_temporarily, 'no' );
        update_option( 'wp_benchmark_temp_activated', $plugins_to_activate_temporarily, 'no' );
        $benchmark_log[] = __('Original plugin states recorded.', 'wp-site-benchmarker');

        if ( ! empty( $plugins_to_deactivate_temporarily ) ) {
            $benchmark_log[] = __('Deactivating plugins for benchmark: ', 'wp-site-benchmarker') . implode(', ', $plugins_to_deactivate_temporarily);
            deactivate_plugins( $plugins_to_deactivate_temporarily, true, false ); 
        }

        $activation_errors = array();
        if ( ! empty( $plugins_to_activate_temporarily ) ) {
            $benchmark_log[] = __('Activating plugins for benchmark: ', 'wp-site-benchmarker') . implode(', ', $plugins_to_activate_temporarily);
            foreach ( $plugins_to_activate_temporarily as $plugin_path ) {
                $result = activate_plugin( $plugin_path, '', false, true ); 
                if ( is_wp_error( $result ) ) {
                    $activation_errors[] = "Failed to activate {$plugin_path}: " . $result->get_error_message();
                }
            }
        }
        
        if (!empty($activation_errors)) {
            $this->restore_plugin_states($benchmark_log); 
            ob_end_clean(); 
            wp_send_json_error( array( 
                'message' => __( 'Error during plugin activation: ', 'wp-site-benchmarker' ) . implode('; ', $activation_errors),
                'log' => $benchmark_log 
            ) );
            return;
        }
        $benchmark_log[] = __('Plugin states configured for benchmark.', 'wp-site-benchmarker');
        
        ob_end_clean(); 

        $benchmark_results = array();
        $benchmark_results['_benchmark_timestamp_start'] = microtime( true );

        global $wp_version, $wpdb;
        $benchmark_results['_env_php_version'] = phpversion();
        $benchmark_results['_env_wp_version'] = $wp_version;
        $benchmark_results['_env_mysql_version'] = $wpdb->db_version();
        if (function_exists('sys_getloadavg')) {
            $load_avg = sys_getloadavg();
            $benchmark_results['_env_server_loadavg_before'] = implode(', ', $load_avg);
        }

        if (in_array('cpu', $tests_to_run)) {
            $benchmark_log[] = __('Running CPU test...', 'wp-site-benchmarker');
            $cpu_test_data = $this->perform_cpu_test();
            $benchmark_results = array_merge( $benchmark_results, $cpu_test_data );
            $benchmark_log[] = __('CPU test completed.', 'wp-site-benchmarker') . sprintf(" Duration: %.4f s", $cpu_test_data['_cpu_duration_seconds']);
        }
        if (in_array('memory', $tests_to_run)) {
            $benchmark_log[] = __('Running Memory test...', 'wp-site-benchmarker');
            $memory_test_data = $this->perform_memory_test();
            $benchmark_results = array_merge( $benchmark_results, $memory_test_data );
            $benchmark_log[] = __('Memory test completed.', 'wp-site-benchmarker') . sprintf(" Peak Usage: %s, Duration: %.4f s", size_format($memory_test_data['_memory_peak_usage_bytes']), $memory_test_data['_memory_duration_seconds']);
        }
        if (in_array('db_read', $tests_to_run)) {
            $benchmark_log[] = __('Running DB Read test...', 'wp-site-benchmarker');
            $db_read_test_data = $this->perform_db_read_test();
            $benchmark_results = array_merge( $benchmark_results, $db_read_test_data );
            $benchmark_log[] = __('DB Read test completed.', 'wp-site-benchmarker') . sprintf(" Queries: %d, Duration: %.4f s", $db_read_test_data['_db_read_queries_count'], $db_read_test_data['_db_read_duration_seconds']);
        }
        if (in_array('db_write', $tests_to_run)) {
             $benchmark_log[] = __('Running DB Write test...', 'wp-site-benchmarker');
            $db_write_test_data = $this->perform_db_write_test();
            $benchmark_results = array_merge( $benchmark_results, $db_write_test_data );
            $benchmark_log[] = __('DB Write test completed.', 'wp-site-benchmarker') . sprintf(" Operations: %d, Duration: %.4f s", $db_write_test_data['_db_write_operations_count'], $db_write_test_data['_db_write_duration_seconds']);
        } 

        $benchmark_results['_benchmark_timestamp_end'] = microtime( true );
        $benchmark_results['_benchmark_duration_total'] = $benchmark_results['_benchmark_timestamp_end'] - $benchmark_results['_benchmark_timestamp_start'];
        
        if (function_exists('sys_getloadavg')) {
             $load_avg_after = sys_getloadavg();
             $benchmark_results['_env_server_loadavg_after'] = implode(', ', $load_avg_after);
        }

        $this->calculate_and_add_benchmark_score($benchmark_results);
        $benchmark_log[] = __('Benchmark score calculated: ', 'wp-site-benchmarker') . round($benchmark_results['_benchmark_score_total'], 2);

        $benchmark_log[] = __('All tests completed.', 'wp-site-benchmarker');

        $this->restore_plugin_states($benchmark_log);

        $benchmark_results['_benchmark_config_active_plugins'] = (array) get_option('active_plugins', array()); 
        $benchmark_results['_benchmark_config_original_active_plugins'] = $original_active_plugins;
        $benchmark_results['_benchmark_config_user_selected_plugins'] = $user_selected_plugins_for_benchmark;
        $benchmark_results['_benchmark_profile_used'] = $profile_id_used; // Save profile ID

        $post_id = $this->save_benchmark_results( $benchmark_results );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 
                'message' => __( 'Failed to save benchmark results: ', 'wp-site-benchmarker' ) . $post_id->get_error_message(),
                'log' => $benchmark_log
            ) );
        } else {
            $benchmark_log[] = __('Benchmark results saved successfully.', 'wp-site-benchmarker');
            wp_send_json_success( array(
                'post_id'   => $post_id,
                'post_link' => get_permalink( $post_id ),
                'score'     => round($benchmark_results['_benchmark_score_total'], 2),
                'message'   => __( 'Benchmark completed and results saved.', 'wp-site-benchmarker' ),
                'log'       => $benchmark_log
            ) );
        }
    }
    
    // --- The rest of the methods (perform_cpu_test, restore_plugin_states, etc.) remain unchanged ---
    // --- They are omitted here for brevity but are included in the complete file ---
    
    public function register_post_types() {
        // Benchmark CPT
        $benchmark_labels = ['name' => _x( 'Benchmarks', 'Post Type General Name', 'wp-site-benchmarker' ), 'singular_name' => _x( 'Benchmark', 'Post Type Singular Name', 'wp-site-benchmarker' ), 'all_items' => __( 'All Benchmarks', 'wp-site-benchmarker' ) /* ... other labels */ ];
        $benchmark_args = ['label' => __( 'Benchmark', 'wp-site-benchmarker' ), 'labels' => $benchmark_labels, 'supports' => array( 'title', 'editor', 'custom-fields' ), 'hierarchical' => false, 'public' => true, 'show_ui' => true, 'show_in_menu' => false, 'has_archive' => true, 'exclude_from_search' => true, 'publicly_queryable' => true, 'capability_type' => 'post', 'show_in_rest' => true ];
        register_post_type( $this->benchmark_cpt_slug, $benchmark_args );

        // Profile CPT
        $profile_labels = ['name' => _x( 'Profiles', 'Post Type General Name', 'wp-site-benchmarker' ), 'singular_name' => _x( 'Profile', 'Post Type Singular Name', 'wp-site-benchmarker' ), 'menu_name' => __( 'Profiles', 'wp-site-benchmarker' ), 'all_items' => __( 'All Profiles', 'wp-site-benchmarker' ), 'add_new_item' => __( 'Add New Profile', 'wp-site-benchmarker' ), 'edit_item' => __( 'Edit Profile', 'wp-site-benchmarker' ) /* ... other labels */ ];
        $profile_args = ['label' => __( 'Profile', 'wp-site-benchmarker' ), 'labels' => $profile_labels, 'supports' => array( 'title' ), 'hierarchical' => false, 'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'exclude_from_search' => true, 'publicly_queryable' => false, 'capability_type' => 'post', 'show_in_rest' => false ];
        register_post_type( $this->profile_cpt_slug, $profile_args );
    }

    public function add_profile_meta_boxes() {
        add_meta_box( 'wpb_profile_description_mb', __( 'Profile Description', 'wp-site-benchmarker' ), array( $this, 'render_profile_description_mb' ), $this->profile_cpt_slug, 'normal', 'high' );
        add_meta_box( 'wpb_profile_plugins_mb', __( 'Plugin Configuration', 'wp-site-benchmarker' ), array( $this, 'render_profile_plugins_mb' ), $this->profile_cpt_slug, 'normal', 'high' );
        add_meta_box( 'wpb_profile_tests_mb', __( 'Tests to Run', 'wp-site-benchmarker' ), array( $this, 'render_profile_tests_mb' ), $this->profile_cpt_slug, 'normal', 'high' );
    }
    
    public function render_profile_description_mb($post) {
        $description = get_post_meta($post->ID, '_profile_description', true);
        echo '<textarea name="profile_description" style="width:100%;" rows="4">' . esc_textarea($description) . '</textarea>';
    }

    public function render_profile_plugins_mb($post) {
        wp_nonce_field('wpb_save_profile_meta', 'wpb_profile_nonce');
        $selected_plugins = get_post_meta($post->ID, '_profile_plugins', true);
        echo '<p>' . __('Select the plugins that should be active when this profile is used.', 'wp-site-benchmarker') . '</p>';
        $this->render_plugin_list($selected_plugins); // Use the reusable method
    }

    public function render_profile_tests_mb($post) {
        $all_tests = $this->get_all_benchmark_tests();
        $selected_tests = get_post_meta($post->ID, '_profile_tests', true);
        if (empty($selected_tests)) $selected_tests = array_keys($all_tests); // Default to all selected on new profile
        
        echo '<fieldset>';
        foreach ($all_tests as $key => $name) {
            $checked = in_array($key, $selected_tests) ? 'checked' : '';
            echo '<label style="padding-right: 20px;"><input type="checkbox" name="profile_tests[]" value="' . esc_attr($key) . '" ' . $checked . '> ' . esc_html($name) . '</label>';
        }
        echo '</fieldset>';
    }

    public function save_profile_meta_data($post_id) {
        if (!isset($_POST['wpb_profile_nonce']) || !wp_verify_nonce($_POST['wpb_profile_nonce'], 'wpb_save_profile_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Save description
        if (isset($_POST['profile_description'])) {
            update_post_meta($post_id, '_profile_description', sanitize_textarea_field($_POST['profile_description']));
        }
        
        // Save plugins
        $plugins_to_save = isset($_POST['benchmark_plugins']) ? (array)$_POST['benchmark_plugins'] : [];
        update_post_meta($post_id, '_profile_plugins', array_map('sanitize_text_field', $plugins_to_save));
        
        // Save tests
        $tests_to_save = isset($_POST['profile_tests']) ? (array)$_POST['profile_tests'] : [];
        update_post_meta($post_id, '_profile_tests', array_map('sanitize_text_field', $tests_to_save));
    }

    // --- Reusable Component & Helper ---

    /**
     * Renders a list of plugins with checkboxes.
     * @param array|bool $checked_plugins Array of plugin file paths to be pre-checked.
     */
    private function render_plugin_list($checked_plugins = false) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $active_plugins_now = (array) get_option('active_plugins', array());
        
        // If no pre-checked plugins are provided, default to currently active plugins.
        $plugins_to_check = ($checked_plugins === false) ? $active_plugins_now : (array) $checked_plugins;
        ?>
        <table class="wp-list-table widefat striped plugins">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column"><span class="screen-reader-text"><?php _e('Select', 'wp-site-benchmarker'); ?></span></th>
                    <th scope="col" class="manage-column column-name column-primary"><?php _e('Plugin', 'wp-site-benchmarker'); ?></th>
                    <th scope="col" class="manage-column column-description"><?php _e('Description', 'wp-site-benchmarker'); ?></th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php
                if (empty($all_plugins)) {
                    echo '<tr><td colspan="3">' . __('No plugins found.', 'wp-site-benchmarker') . '</td></tr>';
                } else {
                    foreach ($all_plugins as $plugin_file => $plugin_data) {
                        if (strpos($plugin_file, basename(dirname(__FILE__))) !== false) continue;
                        $checked = in_array($plugin_file, $plugins_to_check) ? 'checked' : '';
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="benchmark_plugins[]" value="<?php echo esc_attr($plugin_file); ?>" <?php echo $checked; ?>>
                            </th>
                            <td class="plugin-title column-primary">
                                <strong><?php echo esc_html($plugin_data['Name']); ?></strong>
                                <div class="row-actions visible">
                                    <span class="version"><?php printf(__('Version %s', 'wp-site-benchmarker'), esc_html($plugin_data['Version'])); ?></span> | 
                                    <span class="author"><?php printf(__('By %s', 'wp-site-benchmarker'), $plugin_data['Author']); ?></span>
                                </div>
                            </td>
                            <td class="column-description desc">
                                <div class="plugin-description">
                                    <p><?php echo esc_html($plugin_data['Description']); ?></p>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        <?php
    }
    
    private function get_all_benchmark_tests() {
        return [
            'cpu' => __('CPU Test', 'wp-site-benchmarker'),
            'memory' => __('Memory Test', 'wp-site-benchmarker'),
            'db_read' => __('DB Read Test', 'wp-site-benchmarker'),
            'db_write' => __('DB Write Test', 'wp-site-benchmarker'),
        ];
    }

    // --- Benchmark Scores ---

    private function calculate_and_add_benchmark_score(&$results) {
        $actual_cpu_s = max(self::MIN_DURATION_SECONDS, floatval($results['_cpu_duration_seconds']));
        $actual_mem_bytes = max(self::MIN_MEM_BYTES, intval($results['_memory_peak_usage_bytes']));
        $actual_db_read_s = max(self::MIN_DURATION_SECONDS, floatval($results['_db_read_duration_seconds']));
        $actual_db_write_s = max(self::MIN_DURATION_SECONDS, floatval($results['_db_write_duration_seconds']));
        $actual_mem_mb = $actual_mem_bytes / (1024 * 1024);
        $actual_mem_mb = max(0.001, $actual_mem_mb);
        $score_cpu = (self::SCORE_REF_CPU_SECONDS / $actual_cpu_s) * self::SCORE_WEIGHT_CPU;
        $score_mem = (self::SCORE_REF_MEM_MB / $actual_mem_mb) * self::SCORE_WEIGHT_MEM;
        $score_db_read = (self::SCORE_REF_DB_READ_SECONDS / $actual_db_read_s) * self::SCORE_WEIGHT_DB_READ;
        $score_db_write = (self::SCORE_REF_DB_WRITE_SECONDS / $actual_db_write_s) * self::SCORE_WEIGHT_DB_WRITE;
        $total_score = $score_cpu + $score_mem + $score_db_read + $score_db_write;
        $results['_benchmark_score_cpu_component'] = round($score_cpu, 2);
        $results['_benchmark_score_mem_component'] = round($score_mem, 2);
        $results['_benchmark_score_db_read_component'] = round($score_db_read, 2);
        $results['_benchmark_score_db_write_component'] = round($score_db_write, 2);
        $results['_benchmark_score_total'] = round($total_score, 2);
    }

    private function restore_plugin_states(&$log_array) {
        ob_start();
        $activated_for_test = (array) get_option( 'wp_benchmark_temp_activated' );
        $deactivated_for_test = (array) get_option( 'wp_benchmark_temp_deactivated' );
        if ( ! empty( $activated_for_test ) ) {
            $log_array[] = __('Restoring state: Deactivating plugins activated for test: ', 'wp-site-benchmarker') . implode(', ', $activated_for_test);
            deactivate_plugins( $activated_for_test, true, false );
        }
        if ( ! empty( $deactivated_for_test ) ) {
            $log_array[] = __('Restoring state: Reactivating plugins deactivated for test: ', 'wp-site-benchmarker') . implode(', ', $deactivated_for_test);
            foreach ( $deactivated_for_test as $plugin_path ) {
                if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_path)) {
                    activate_plugin( $plugin_path, '', false, true ); 
                } else {
                    $log_array[] = sprintf(__('Warning: Plugin %s not found during restoration, cannot reactivate.', 'wp-site-benchmarker'), $plugin_path);
                }
            }
        }
        delete_option( 'wp_benchmark_temp_activated' );
        delete_option( 'wp_benchmark_temp_deactivated' );
        $log_array[] = __('Plugin states restored to original configuration.', 'wp-site-benchmarker');
        ob_end_clean();
    }

    private function perform_cpu_test() {
        $start_time = microtime(true);
        $result_val = 0;
        for ($i = 0; $i < 2000000; $i++) { $result_val += sqrt($i) * sin($i / 1000) * log($i + 1.001); }
        $hashes = 0;
        for ($i = 0; $i < 2000; $i++) { 
            $string = openssl_random_pseudo_bytes(256); 
            $hash = hash('sha256', $string);
            if ($hash) $hashes++;
        }
        $duration = microtime(true) - $start_time;
        return array('_cpu_duration_seconds' => round($duration, 4), '_cpu_details' => sprintf('Performed 2,000,000 math operations and %d SHA256 hashes.', $hashes));
    }

    private function perform_memory_test() {
        $initial_memory = memory_get_usage();
        $start_time = microtime(true);
        $large_array = [];
        for ($i = 0; $i < 25000; $i++) { $large_array[] = str_repeat('x', 1024); }
        $peak_memory = memory_get_peak_usage(false); 
        $final_memory = memory_get_usage();
        $duration = microtime(true) - $start_time;
        unset($large_array); 
        return array('_memory_peak_usage_bytes' => $peak_memory, '_memory_duration_seconds' => round($duration, 4), '_memory_details' => sprintf('Created an array of 25,000 1KB strings. Initial usage: %s, Peak usage: %s, Final usage (after unset): %s.', size_format($initial_memory), size_format($peak_memory), size_format(memory_get_usage())));
    }

    private function perform_db_read_test() {
        global $wpdb;
        $start_time = microtime(true);
        $query_count = 0;
        $total_posts_queried = 0;
        $savequeries_defined_initially = defined('SAVEQUERIES');
        $savequeries_initial_value = $savequeries_defined_initially ? SAVEQUERIES : null;
        if (!$savequeries_defined_initially) { define('SAVEQUERIES', true); }
        $initial_query_count = (defined('SAVEQUERIES') && SAVEQUERIES === true && isset($wpdb->queries)) ? count($wpdb->queries) : 0;
        $post_ids = get_posts(array('numberposts' => 250, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => true, 'no_found_rows' => true));
        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                $wpdb->get_var($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE ID = %d", $post_id));
                $total_posts_queried++;
            }
        }
        for ($i = 0; $i < 250; $i++) { get_option('blogname'); }
        $total_options_queried = 250;
        $meta_query_posts = new WP_Query(array('post_type' => 'post', 'posts_per_page' => 100, 'meta_query' => array('relation' => 'OR', array('key' => '_thumbnail_id', 'compare' => 'EXISTS'), array('key' => 'some_nonexistent_meta_key', 'value' => 'some_value', 'compare' => '=')), 'fields' => 'ids', 'suppress_filters' => true, 'no_found_rows' => true,));
        $total_posts_queried += $meta_query_posts->post_count;
        $duration = microtime(true) - $start_time;
        if (defined('SAVEQUERIES') && SAVEQUERIES === true && isset($wpdb->queries)) { $query_count = count($wpdb->queries) - $initial_query_count; } else { $query_count = -1; }
        return array('_db_read_duration_seconds' => round($duration, 4), '_db_read_queries_count' => $query_count, '_db_read_details' => sprintf('Queried %d posts and %d options. WP_Query found %d posts. SAVEQUERIES was %s during test (initial state: %s).', $total_posts_queried, $total_options_queried, $meta_query_posts->post_count, (defined('SAVEQUERIES') && SAVEQUERIES ? 'enabled' : 'disabled/not tracked'), ($savequeries_defined_initially ? ($savequeries_initial_value ? 'true' : 'false') : 'undefined') ));
    }

    private function perform_db_write_test() {
        global $wpdb;
        $start_time = microtime(true);
        $operations = 0;
        $temp_option_prefix = 'wp_benchmark_temp_opt_';
        for ($i = 0; $i < 100; $i++) { add_option($temp_option_prefix . $i, 'benchmark_data_' . wp_generate_uuid4(), '', 'no'); $operations++; }
        for ($i = 0; $i < 100; $i++) { update_option($temp_option_prefix . $i, 'benchmark_data_updated_' . wp_generate_uuid4(), 'no'); $operations++; }
        for ($i = 0; $i < 100; $i++) { delete_option($temp_option_prefix . $i); $operations++; }
        $table_name = $wpdb->prefix . 'benchmark_temp_writes';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (id mediumint(9) NOT NULL AUTO_INCREMENT, data varchar(255) DEFAULT '' NOT NULL, created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (id)) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql); 
        for ($i = 0; $i < 200; $i++) { $wpdb->insert($table_name, array('data' => 'test_data_' . $i . '_' . wp_generate_uuid4(), 'created_at' => current_time('mysql'))); $operations++; }
        $wpdb->query("TRUNCATE TABLE $table_name"); 
        $duration = microtime(true) - $start_time;
        return array('_db_write_duration_seconds' => round($duration, 4), '_db_write_operations_count' => $operations, '_db_write_details' => sprintf('Performed %d option R/W/D operations. Inserted and truncated 200 rows in a temporary table.', $operations - 200));
    }

    private function save_benchmark_results( $results_data ) {
        $post_title = sprintf('%s - %s (Score: %s)', __( 'Benchmark', 'wp-site-benchmarker' ), date_i18n( 'Y-m-d H:i:s', $results_data['_benchmark_timestamp_start'] ), isset($results_data['_benchmark_score_total']) ? round($results_data['_benchmark_score_total'],0) : 'N/A');
        $post_content = "Benchmark run on " . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $results_data['_benchmark_timestamp_start'] ) . ".\n";
        if(isset($results_data['_benchmark_score_total'])) { 
            $post_content .= "Overall Benchmark Score: " . round($results_data['_benchmark_score_total'], 2) . "\n"; 
        }
        $post_content .= "Total duration: " . number_format_i18n( $results_data['_benchmark_duration_total'], 4 ) . " seconds.\n\n";
        $post_content .= "See custom fields and details below for individual metrics.";
        $post_data = array('post_title' => $post_title, 'post_content' => $post_content, 'post_status'  => 'publish', 'post_type' => $this->cpt_slug);
        $post_id = wp_insert_post( $post_data, true ); 
        if ( ! is_wp_error( $post_id ) ) { foreach ( $results_data as $key => $value ) { update_post_meta( $post_id, $key, $value ); } }
        return $post_id;
    }

    public function set_custom_edit_benchmark_columns( $columns ) {
        unset( $columns['date'], $columns['title'] ); 
        $new_columns = array();
        $new_columns['cb'] = $columns['cb']; 
        $new_columns['benchmark_title'] = __( 'Benchmark Title / Date', 'wp-site-benchmarker' );
        $new_columns['benchmark_score'] = __( 'Score', 'wp-site-benchmarker' );
        $new_columns['total_duration'] = __( 'Total Duration (s)', 'wp-site-benchmarker' );
        $new_columns['cpu_time'] = __( 'CPU Time (s)', 'wp-site-benchmarker' );
        $new_columns['memory_peak'] = __( 'Memory Peak', 'wp-site-benchmarker' );
        $new_columns['db_read_time'] = __( 'DB Read (s)', 'wp-site-benchmarker' );
        $new_columns['db_write_time'] = __( 'DB Write (s)', 'wp-site-benchmarker' );
        return $new_columns;
    }

    public function custom_benchmark_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'benchmark_title':
                $title = get_the_title($post_id);
                $timestamp = get_post_meta($post_id, '_benchmark_timestamp_start', true);
                echo '<strong><a class="row-title" href="' . esc_url(get_edit_post_link($post_id)) . '">' . esc_html($title) . '</a></strong>';
                echo '<br><small>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)) . '</small>';
                break;
            case 'benchmark_score':
                $score = get_post_meta( $post_id, '_benchmark_score_total', true );
                echo '<strong>' . esc_html( round(floatval($score), 0) ) . '</strong>';
                break;
            case 'total_duration':
                $duration = get_post_meta( $post_id, '_benchmark_duration_total', true );
                echo esc_html( number_format_i18n( $duration, 3 ) );
                break;
            case 'cpu_time':
                $val = get_post_meta( $post_id, '_cpu_duration_seconds', true );
                echo esc_html( number_format_i18n( $val, 3 ) );
                break;
            case 'memory_peak':
                $val = get_post_meta( $post_id, '_memory_peak_usage_bytes', true );
                echo esc_html( size_format( $val ) );
                break;
            case 'db_read_time':
                $val = get_post_meta( $post_id, '_db_read_duration_seconds', true );
                $queries = get_post_meta( $post_id, '_db_read_queries_count', true );
                echo esc_html( number_format_i18n( $val, 3 ) ) . ($queries != -1 ? ' (' . esc_html($queries) . 'q)' : ' (q?)');
                break;
            case 'db_write_time':
                $val = get_post_meta( $post_id, '_db_write_duration_seconds', true );
                $ops = get_post_meta( $post_id, '_db_write_operations_count', true );
                echo esc_html( number_format_i18n( $val, 3 ) ) . ' (' . esc_html($ops) . ' ops)';
                break;
        }
    }

    public function display_benchmark_results_in_content( $content ) {
        if (is_singular($this->benchmark_cpt_slug) && in_the_loop() && is_main_query()) {
            global $post;
            $meta_data = get_post_meta($post->ID);
            $profile_id = get_post_meta($post->ID, '_benchmark_profile_used', true);
            $output = '<div class="wp-benchmark-results-details" style="margin-top:20px; padding-top:20px; border-top: 1px solid #eee;">';
            if (isset($meta_data['_benchmark_score_total'][0])) { 
                $output .= '<h2 style="font-size: 1.8em; margin-bottom: 15px;">' . __('Overall Benchmark Score: ', 'wp-site-benchmarker') . '<strong style="color: #2271b1;">' . esc_html(round(floatval($meta_data['_benchmark_score_total'][0]), 0)) . '</strong></h2>'; 
            }
            $output .= '<h3>' . __('Detailed Benchmark Metrics', 'wp-site-benchmarker') . '</h3>';
            $output .= '<table class="form-table"><tbody>';
            $display_order = [
                '_benchmark_score_total' => __('Total Benchmark Score (Raw)', 'wp-site-benchmarker'),
                '_benchmark_score_cpu_component' => __('CPU Score Component', 'wp-site-benchmarker'),
                '_benchmark_score_mem_component' => __('Memory Score Component', 'wp-site-benchmarker'), '_benchmark_score_db_read_component' => __('DB Read Score Component', 'wp-site-benchmarker'), '_benchmark_score_db_write_component' => __('DB Write Score Component', 'wp-site-benchmarker'),
                '_benchmark_duration_total' => __('Total Benchmark Duration', 'wp-site-benchmarker'),
                '_cpu_duration_seconds' => __('CPU Test Duration', 'wp-site-benchmarker'),
                '_cpu_details' => __('CPU Test Details', 'wp-site-benchmarker'),
                '_memory_peak_usage_bytes' => __('Memory Peak Usage', 'wp-site-benchmarker'),
                '_memory_duration_seconds' => __('Memory Test Duration', 'wp-site-benchmarker'),
                '_memory_details' => __('Memory Test Details', 'wp-site-benchmarker'),
                '_db_read_duration_seconds' => __('Database Read Test Duration',
                'wp-site-benchmarker'), '_db_read_queries_count' => __('Database Read Queries', 'wp-site-benchmarker'), 
                '_db_read_details' => __('Database Read Details', 'wp-site-benchmarker'),
                '_db_write_duration_seconds' => __('Database Write Test Duration', 'wp-site-benchmarker'),
                '_db_write_operations_count' => __('Database Write Operations', 'wp-site-benchmarker'),
                '_db_write_details' => __('Database Write Details', 'wp-site-benchmarker'),
            ];
            foreach($display_order as $key => $label) {
                if (isset($meta_data[$key][0])) {
                    $value = $meta_data[$key][0];
                    if (strpos($key, '_duration_seconds') !== false || $key === '_benchmark_duration_total') { $value = number_format_i18n(floatval($value), 4) . ' ' . __('seconds', 'wp-site-benchmarker'); }
                    elseif (strpos($key, '_usage_bytes') !== false) { $value = size_format(intval($value), 2); }
                    elseif (strpos($key, '_score_') !== false) { $value = number_format_i18n(floatval($value), 2); }
                    $output .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
                }
            }
            $output .= '</tbody></table>';
            $output .= '<h4>' . __('Environment Information', 'wp-site-benchmarker') . '</h4>';
            $output .= '<ul>';
            if(isset($meta_data['_env_php_version'][0])) $output .= '<li>' . __('PHP Version:', 'wp-site-benchmarker') . ' ' . esc_html($meta_data['_env_php_version'][0]) . '</li>';
            if(isset($meta_data['_env_wp_version'][0])) $output .= '<li>' . __('WordPress Version:', 'wp-site-benchmarker') . ' ' . esc_html($meta_data['_env_wp_version'][0]) . '</li>';
            if(isset($meta_data['_env_mysql_version'][0])) $output .= '<li>' . __('MySQL Version:', 'wp-site-benchmarker') . ' ' . esc_html($meta_data['_env_mysql_version'][0]) . '</li>';
            if(isset($meta_data['_env_server_loadavg_before'][0])) $output .= '<li>' . __('Server Load Avg (Before):', 'wp-site-benchmarker') . ' ' . esc_html($meta_data['_env_server_loadavg_before'][0]) . '</li>';
            if(isset($meta_data['_env_server_loadavg_after'][0])) $output .= '<li>' . __('Server Load Avg (After):', 'wp-site-benchmarker') . ' ' . esc_html($meta_data['_env_server_loadavg_after'][0]) . '</li>';
            $output .= '</ul>';
            // Add profile info to display
            if (!empty($profile_id)) {
                $profile_title = get_the_title($profile_id);
                if ($profile_title) {
                    $output .= '<h4>' . __('Benchmark Profile Used', 'wp-site-benchmarker') . '</h4>';
                    $output .= '<p>' . sprintf(__('This benchmark was run using the "<strong>%s</strong>" profile.', 'wp-site-benchmarker'), esc_html($profile_title)) . '</p>';
                }
            }
            $output .= '<h4>' . __('Plugin Configuration During Test', 'wp-site-benchmarker') . '</h4>';
            $active_during_test = maybe_unserialize( $meta_data['_benchmark_config_active_plugins'][0] ?? '' );
            if (!empty($active_during_test) && is_array($active_during_test)) {
                $output .= '<h5>' . __('Plugins Active During Test:', 'wp-site-benchmarker') . '</h5><ul>';
                $all_plugins_data = get_plugins();
                foreach ($active_during_test as $plugin_file) { $output .= '<li>' . esc_html(isset($all_plugins_data[$plugin_file]['Name']) ? $all_plugins_data[$plugin_file]['Name'] : $plugin_file) . '</li>'; }
                $output .= '</ul>';
            } else { $output .= '<p>' . __('No plugins were active during the test.', 'wp-site-benchmarker') . '</p>'; }
            $output .= '</div>';
            return $content . $output; 
        }
        return $content;
    }

} // End of WPSite_Benchmarker class

WPSite_Benchmarker::get_instance();
