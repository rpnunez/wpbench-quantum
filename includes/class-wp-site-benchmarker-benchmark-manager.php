<?php
/**
 * Manages Benchmark CPT, "Run New Benchmark" page, and related functionalities.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class WPSite_Benchmarker_Benchmark_Manager {

    private $plugin; // Instance of the main plugin class
    private $plugin_slug;
    private $benchmark_cpt_slug;
    private $profile_cpt_slug;

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

    public function __construct( WPSite_Benchmarker $plugin, $plugin_slug, $benchmark_cpt_slug, $profile_cpt_slug ) {
        $this->plugin = $plugin;
        $this->plugin_slug = $plugin_slug;
        $this->benchmark_cpt_slug = $benchmark_cpt_slug;
        $this->profile_cpt_slug = $profile_cpt_slug;

        add_action( 'init', array( $this, 'register_benchmark_cpt' ) );
        
        // AJAX Actions
        add_action( 'wp_ajax_wp_site_benchmark_run', array( $this, 'handle_ajax_run_benchmark' ) );
        add_action( 'wp_ajax_wpb_get_profile_data', array( $this, 'handle_ajax_get_profile_data' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_run_benchmark_scripts' ) );

        // Benchmark CPT Admin Columns
        add_filter( 'manage_' . $this->benchmark_cpt_slug . '_posts_columns', array( $this, 'set_custom_edit_benchmark_columns' ) );
        add_action( 'manage_' . $this->benchmark_cpt_slug . '_posts_custom_column', array( $this, 'custom_benchmark_column_content' ), 10, 2 );

        // Display results on single Benchmark CPT page
        add_filter( 'the_content', array( $this, 'display_benchmark_results_in_content' ) );
    }

    public function enqueue_run_benchmark_scripts( $hook_suffix ) {
        if ( 'toplevel_page_' . $this->plugin_slug !== $hook_suffix && 'wpbench_page_' . $this->plugin_slug . '-run' !== $hook_suffix ) { // Adjust hook if submenu slug changes
            return;
        }

        wp_register_script(
            'wp-site-benchmarker-run-benchmark',
            WP_SITE_BENCHMARKER_PLUGIN_URL . 'assets/js/run-benchmark.js',
            array( 'jquery' ),
            WP_SITE_BENCHMARKER_VERSION,
            true
        );

        $localized_data = array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'get_profile_nonce' => wp_create_nonce( 'wpb_get_profile_nonce' ),
            'i18n' => array(
                'profileErrorPrefix' => __( 'Error loading profile: ', 'wp-site-benchmarker' ),
                'profileAjaxError'   => __( 'AJAX error loading profile data.', 'wp-site-benchmarker' ),
                'initializing'       => __( 'Initializing benchmark...', 'wp-site-benchmarker' ),
                'waitMessage'        => __( 'This may take several minutes. Please do not navigate away from this page.', 'wp-site-benchmarker' ),
                'completedSuccess'   => __( 'Benchmark completed successfully!', 'wp-site-benchmarker' ),
                'calculatedScore'    => __( 'Calculated Benchmark Score:', 'wp-site-benchmarker' ),
                'resultsId'          => __( 'Results ID:', 'wp-site-benchmarker' ),
                'viewResults'        => __( 'View results:', 'wp-site-benchmarker' ),
                'viewResultsLink'    => __( 'View results', 'wp-site-benchmarker' ),
                'completedNotice'    => __( 'Benchmark completed!', 'wp-site-benchmarker' ),
                'scoreLabel'         => __( 'Score:', 'wp-site-benchmarker' ),
                'unknownError'       => __( 'An unknown error occurred.', 'wp-site-benchmarker' ),
                'errorPrefix'        => __( 'Error:', 'wp-site-benchmarker' ),
                'failedNotice'       => __( 'Benchmark failed:', 'wp-site-benchmarker' ),
                'ajaxErrorPrefix'    => __( 'AJAX Error:', 'wp-site-benchmarker' ),
                'serverResponse'     => __( 'Server Response:', 'wp-site-benchmarker' ),
                'ajaxErrorDetails'   => __( 'An AJAX error occurred. Check the browser console and the log above for details.', 'wp-site-benchmarker' ),
            )
        );
        wp_localize_script( 'wp-site-benchmarker-run-benchmark', 'wpBenchmarkRunParams', $localized_data );
        wp_enqueue_script( 'wp-site-benchmarker-run-benchmark' );
    }

    public function render_run_benchmark_page() {
        $all_tests = $this->get_all_benchmark_tests();
        $profiles = get_posts(['post_type' => $this->profile_cpt_slug, 'numberposts' => -1, 'post_status' => 'publish']);
        $this->render_template('run-benchmark-page-template.php', compact('all_tests', 'profiles'));
    }

    public function handle_ajax_run_benchmark() {
        check_ajax_referer('wp_site_benchmark_run_nonce', 'wp_benchmark_nonce_field');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $profile_id_used = isset($_POST['profile_id_hidden']) ? intval($_POST['profile_id_hidden']) : 0;
        $tests_to_run = isset($_POST['benchmark_tests']) ? (array)$_POST['benchmark_tests'] : [];
        $tests_to_run = array_map('sanitize_text_field', $tests_to_run);

        $test_intensities_input = isset($_POST['benchmark_tests_intensity']) ? (array)$_POST['benchmark_tests_intensity'] : [];
        $test_intensities = [];
        foreach ($test_intensities_input as $test_key => $intensity_val) {
            $test_intensities[sanitize_key($test_key)] = max(0, min(100, intval($intensity_val)));
        }
        
        $benchmark_log = []; 
        $benchmark_results = [];

        ob_start();

        $original_active_plugins = (array) get_option( 'active_plugins', array() );
        $user_selected_plugins_for_benchmark = isset( $_POST['benchmark_plugins'] ) ? (array) $_POST['benchmark_plugins'] : array();
        $user_selected_plugins_for_benchmark = array_map('sanitize_text_field', $user_selected_plugins_for_benchmark);

        $plugins_to_deactivate_temporarily = array_diff( $original_active_plugins, $user_selected_plugins_for_benchmark );
        $plugins_to_activate_temporarily = array_diff( $user_selected_plugins_for_benchmark, $original_active_plugins );

        // Ensure the main plugin itself is not deactivated/reactivated
        $self_plugin_file = plugin_basename(WP_SITE_BENCHMARKER_PLUGIN_DIR . 'wpbench.php'); // Use constant
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
            $cpu_intensity = isset($test_intensities['cpu']) ? $test_intensities['cpu'] : 50;
            $cpu_test_data = $this->plugin->perform_cpu_test($cpu_intensity); // Call main plugin method
            $benchmark_results['_cpu_intensity'] = $cpu_intensity;
            $benchmark_results = array_merge( $benchmark_results, $cpu_test_data );
            $benchmark_log[] = __('CPU test completed.', 'wp-site-benchmarker') . sprintf(" Duration: %.4f s", $cpu_test_data['_cpu_duration_seconds']);
        }
        if (in_array('memory', $tests_to_run)) {
            $benchmark_log[] = __('Running Memory test...', 'wp-site-benchmarker');
            $memory_intensity = isset($test_intensities['memory']) ? $test_intensities['memory'] : 50;
            $memory_test_data = $this->plugin->perform_memory_test($memory_intensity); // Call main plugin method
            $benchmark_results['_memory_intensity'] = $memory_intensity;
            $benchmark_results = array_merge( $benchmark_results, $memory_test_data );
            $benchmark_log[] = __('Memory test completed.', 'wp-site-benchmarker') . sprintf(" Peak Usage: %s, Duration: %.4f s", size_format($memory_test_data['_memory_peak_usage_bytes']), $memory_test_data['_memory_duration_seconds']);
        }
        if (in_array('db_read', $tests_to_run)) {
            $benchmark_log[] = __('Running DB Read test...', 'wp-site-benchmarker');
            $db_read_intensity = isset($test_intensities['db_read']) ? $test_intensities['db_read'] : 50;
            $db_read_test_data = $this->plugin->perform_db_read_test($db_read_intensity); // Call main plugin method
            $benchmark_results['_db_read_intensity'] = $db_read_intensity;
            $benchmark_results = array_merge( $benchmark_results, $db_read_test_data );
            $benchmark_log[] = __('DB Read test completed.', 'wp-site-benchmarker') . sprintf(" Queries: %d, Duration: %.4f s", $db_read_test_data['_db_read_queries_count'], $db_read_test_data['_db_read_duration_seconds']);
        }
        if (in_array('db_write', $tests_to_run)) {
             $benchmark_log[] = __('Running DB Write test...', 'wp-site-benchmarker');
            $db_write_intensity = isset($test_intensities['db_write']) ? $test_intensities['db_write'] : 50;
            $db_write_test_data = $this->plugin->perform_db_write_test($db_write_intensity); // Call main plugin method
            $benchmark_results['_db_write_intensity'] = $db_write_intensity;
            $benchmark_results = array_merge( $benchmark_results, $db_write_test_data );
            $benchmark_log[] = __('DB Write test completed.', 'wp-site-benchmarker') . sprintf(" Operations: %d, Duration: %.4f s", $db_write_test_data['_db_write_operations_count'], $db_write_test_data['_db_write_duration_seconds']);
        } 

        $benchmark_results['_benchmark_timestamp_end'] = microtime( true );
        $benchmark_results['_benchmark_duration_total'] = $benchmark_results['_benchmark_timestamp_end'] - $benchmark_results['_benchmark_timestamp_start'];

        foreach($tests_to_run as $test_key) {
            if (isset($test_intensities[$test_key])) {
                $meta_key = '_' . $test_key . '_intensity';
                $benchmark_results[$meta_key] = $test_intensities[$test_key];
            } else {
                $meta_key = '_' . $test_key . '_intensity';
                $benchmark_results[$meta_key] = 50;
                $benchmark_log[] = sprintf(__('Warning: Intensity for test "%s" not found, defaulted to 50.', 'wp-site-benchmarker'), $test_key);
            }
        }
        
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
        $benchmark_results['_benchmark_profile_used'] = $profile_id_used;

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

    public function handle_ajax_get_profile_data() {
        check_ajax_referer('wpb_get_profile_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        $profile_id = isset($_POST['profile_id']) ? intval($_POST['profile_id']) : 0;
        if (!$profile_id) {
            wp_send_json_error(['message' => 'Invalid profile ID.']);
        }
        $plugins = get_post_meta($profile_id, '_profile_plugins', true);
        $tests = get_post_meta($profile_id, '_profile_tests', true);
        // TODO: Add intensities to profiles and retrieve here
        wp_send_json_success(['plugins' => $plugins ?: [], 'tests' => $tests ?: []]);
    }
    
    public function register_benchmark_cpt() {
        $labels = [
            'name'                  => _x( 'Benchmarks', 'Post Type General Name', 'wp-site-benchmarker' ),
            'singular_name'         => _x( 'Benchmark', 'Post Type Singular Name', 'wp-site-benchmarker' ),
            'menu_name'             => __( 'Benchmarks', 'wp-site-benchmarker' ),
            'name_admin_bar'        => __( 'Benchmark', 'wp-site-benchmarker' ),
            'archives'              => __( 'Benchmark Archives', 'wp-site-benchmarker' ),
            'attributes'            => __( 'Benchmark Attributes', 'wp-site-benchmarker' ),
            'parent_item_colon'     => __( 'Parent Benchmark:', 'wp-site-benchmarker' ),
            'all_items'             => __( 'All Benchmarks', 'wp-site-benchmarker' ),
            'add_new_item'          => __( 'Add New Benchmark', 'wp-site-benchmarker' ),
            'add_new'               => __( 'Add New', 'wp-site-benchmarker' ),
            'new_item'              => __( 'New Benchmark', 'wp-site-benchmarker' ),
            'edit_item'             => __( 'Edit Benchmark', 'wp-site-benchmarker' ),
            'update_item'           => __( 'Update Benchmark', 'wp-site-benchmarker' ),
            'view_item'             => __( 'View Benchmark', 'wp-site-benchmarker' ),
            'view_items'            => __( 'View Benchmarks', 'wp-site-benchmarker' ),
            'search_items'          => __( 'Search Benchmark', 'wp-site-benchmarker' ),
            'not_found'             => __( 'Not found', 'wp-site-benchmarker' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'wp-site-benchmarker' ),
            'featured_image'        => __( 'Featured Image', 'wp-site-benchmarker' ),
            'set_featured_image'    => __( 'Set featured image', 'wp-site-benchmarker' ),
            'remove_featured_image' => __( 'Remove featured image', 'wp-site-benchmarker' ),
            'use_featured_image'    => __( 'Use as featured image', 'wp-site-benchmarker' ),
            'insert_into_item'      => __( 'Insert into benchmark', 'wp-site-benchmarker' ),
            'uploaded_to_this_item' => __( 'Uploaded to this benchmark', 'wp-site-benchmarker' ),
            'items_list'            => __( 'Benchmarks list', 'wp-site-benchmarker' ),
            'items_list_navigation' => __( 'Benchmarks list navigation', 'wp-site-benchmarker' ),
            'filter_items_list'     => __( 'Filter benchmarks list', 'wp-site-benchmarker' ),
        ];
        $args = [
            'label'                 => __( 'Benchmark', 'wp-site-benchmarker' ),
            'labels'                => $labels,
            'supports'              => array( 'title', 'editor', 'custom-fields' ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => false, 
            'has_archive'           => true,
            'exclude_from_search'   => true,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            'show_in_rest'          => true,
        ];
        register_post_type( $this->benchmark_cpt_slug, $args );
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
            $display_order = [
                '_benchmark_score_total' => __('Total Benchmark Score (Raw)', 'wp-site-benchmarker'),
                '_benchmark_score_cpu_component' => __('CPU Score Component', 'wp-site-benchmarker'),
                '_benchmark_score_mem_component' => __('Memory Score Component', 'wp-site-benchmarker'), '_benchmark_score_db_read_component' => __('DB Read Score Component', 'wp-site-benchmarker'), '_benchmark_score_db_write_component' => __('DB Write Score Component', 'wp-site-benchmarker'),
                '_benchmark_duration_total' => __('Total Benchmark Duration', 'wp-site-benchmarker'),
                '_cpu_duration_seconds' => __('CPU Test Duration', 'wp-site-benchmarker'),
                '_cpu_intensity' => __('CPU Test Intensity Used', 'wp-site-benchmarker'),
                '_cpu_details' => __('CPU Test Details', 'wp-site-benchmarker'),
                '_memory_peak_usage_bytes' => __('Memory Peak Usage', 'wp-site-benchmarker'),
                '_memory_duration_seconds' => __('Memory Test Duration', 'wp-site-benchmarker'),
                '_memory_intensity' => __('Memory Test Intensity Used', 'wp-site-benchmarker'),
                '_memory_details' => __('Memory Test Details', 'wp-site-benchmarker'),
                '_db_read_duration_seconds' => __('Database Read Test Duration', 'wp-site-benchmarker'), 
                '_db_read_queries_count' => __('Database Read Queries', 'wp-site-benchmarker'), 
                '_db_read_intensity' => __('DB Read Test Intensity Used', 'wp-site-benchmarker'),
                '_db_read_details' => __('Database Read Details', 'wp-site-benchmarker'),
                '_db_write_duration_seconds' => __('Database Write Test Duration', 'wp-site-benchmarker'),
                '_db_write_operations_count' => __('Database Write Operations', 'wp-site-benchmarker'),
                '_db_write_intensity' => __('DB Write Test Intensity Used', 'wp-site-benchmarker'),
                '_db_write_details' => __('Database Write Details', 'wp-site-benchmarker'),
            ];
            $active_during_test = maybe_unserialize( $meta_data['_benchmark_config_active_plugins'][0] ?? '' );
            $all_plugins_data = (!empty($active_during_test) && is_array($active_during_test) && function_exists('get_plugins')) ? get_plugins() : [];
            ob_start();
            $this->render_template('benchmark-results-content-template.php', compact('meta_data', 'profile_id', 'display_order', 'active_during_test', 'all_plugins_data'));
            $output = ob_get_clean();
            return $content . $output; 
        }
        return $content;
    }

    private function save_benchmark_results( $results_data ) {
        $post_title = sprintf(
            '%s - %s (Score: %s)',
            __( 'Benchmark', 'wp-site-benchmarker' ),
            date_i18n( 'Y-m-d H:i:s', $results_data['_benchmark_timestamp_start'] ),
            isset($results_data['_benchmark_score_total']) ? round($results_data['_benchmark_score_total'], 0) : 'N/A'
        );
        $post_content = "Benchmark run on " .
                        date_i18n(
                            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                            $results_data['_benchmark_timestamp_start']
                        ) . ".\n";
        if ( isset($results_data['_benchmark_score_total']) ) { 
            $post_content .= "Overall Benchmark Score: " . round($results_data['_benchmark_score_total'], 2) . "\n"; 
        }
        $post_content .= "Total duration: " . number_format_i18n( $results_data['_benchmark_duration_total'], 4 ) . " seconds.\n\n";
        $post_content .= "See custom fields and details below for individual metrics.";
        $post_data = [
            'post_title' => $post_title,
            'post_content' => $post_content,
            'post_status'  => 'publish',
            'post_type' => $this->benchmark_cpt_slug
        ];
        $post_id = wp_insert_post( $post_data, true ); 
        if ( ! is_wp_error( $post_id ) ) {
            foreach ( $results_data as $key => $value ) { 
                update_post_meta( $post_id, $key, $value );
            }
        }
        return $post_id;
    }

    private function calculate_and_add_benchmark_score(&$results) {
        $actual_cpu_s = max(self::MIN_DURATION_SECONDS, floatval($results['_cpu_duration_seconds'] ?? self::MIN_DURATION_SECONDS));
        $actual_mem_bytes = max(self::MIN_MEM_BYTES, intval($results['_memory_peak_usage_bytes'] ?? self::MIN_MEM_BYTES));
        $actual_db_read_s = max(self::MIN_DURATION_SECONDS, floatval($results['_db_read_duration_seconds'] ?? self::MIN_DURATION_SECONDS));
        $actual_db_write_s = max(self::MIN_DURATION_SECONDS, floatval($results['_db_write_duration_seconds'] ?? self::MIN_DURATION_SECONDS));
        
        $actual_mem_mb = $actual_mem_bytes / (1024 * 1024);
        $actual_mem_mb = max(0.001, $actual_mem_mb);

        $score_cpu = 0;
        $score_mem = 0;
        $score_db_read = 0;
        $score_db_write = 0;

        if(isset($results['_cpu_duration_seconds'])) {
            $score_cpu = (self::SCORE_REF_CPU_SECONDS / $actual_cpu_s) * self::SCORE_WEIGHT_CPU;
        }
        if(isset($results['_memory_peak_usage_bytes'])) {
            $score_mem = (self::SCORE_REF_MEM_MB / $actual_mem_mb) * self::SCORE_WEIGHT_MEM;
        }
        if(isset($results['_db_read_duration_seconds'])) {
            $score_db_read = (self::SCORE_REF_DB_READ_SECONDS / $actual_db_read_s) * self::SCORE_WEIGHT_DB_READ;
        }
        if(isset($results['_db_write_duration_seconds'])) {
            $score_db_write = (self::SCORE_REF_DB_WRITE_SECONDS / $actual_db_write_s) * self::SCORE_WEIGHT_DB_WRITE;
        }
        
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
                if ( file_exists(WP_PLUGIN_DIR . '/' . $plugin_path) ) {
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

    private function get_all_benchmark_tests() {
        return [
            'cpu' => __('CPU Test', 'wp-site-benchmarker'),
            'memory' => __('Memory Test', 'wp-site-benchmarker'),
            'db_read' => __('DB Read Test', 'wp-site-benchmarker'),
            'db_write' => __('DB Write Test', 'wp-site-benchmarker'),
        ];
    }
    
    private function render_plugin_list($checked_plugins = false) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $active_plugins_now = (array) get_option('active_plugins', array());
        $plugins_to_check = ($checked_plugins === false) ? $active_plugins_now : (array) $checked_plugins;
        $self_plugin_basename = plugin_basename(WP_SITE_BENCHMARKER_PLUGIN_DIR . 'wpbench.php');
        $this->render_template('plugin-list-template.php', compact('all_plugins', 'plugins_to_check', 'self_plugin_basename'));
    }

    private function render_template( $template_file_name, $data = array() ) {
        $template_path = WP_SITE_BENCHMARKER_PLUGIN_DIR . 'templates/' . $template_file_name;
        if ( file_exists( $template_path ) ) {
            if ( ! empty( $data ) && is_array( $data ) ) {
                extract( $data );
            }
            include $template_path;
        } else {
            echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Error: Template file "%s" not found.', 'wp-site-benchmarker' ), esc_html( $template_file_name ) ) . '</p></div>';
        }
    }
}
