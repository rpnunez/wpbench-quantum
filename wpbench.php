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

require_once WP_SITE_BENCHMARKER_PLUGIN_DIR . 'includes/class-wp-site-benchmarker-benchmark-manager.php';

class WPSite_Benchmarker {

    private static $instance;
    public $plugin_slug = 'wp-site-benchmarker'; // Made public for manager access
    public $benchmark_cpt_slug = 'wp_benchmark'; // Made public for manager access
    public $profile_cpt_slug = 'wpb_profile';   // Made public for manager access
    private $settings_option_group = 'wp_benchmarker_settings_group';
    private $settings_option_name = 'wp_benchmarker_settings';

    private $benchmark_manager;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->benchmark_manager = new WPSite_Benchmarker_Benchmark_Manager($this, $this->plugin_slug, $this->benchmark_cpt_slug, $this->profile_cpt_slug);

        add_action( 'init', array( $this, 'register_profile_cpt' ) ); // Benchmark CPT registration moved to manager
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        
        // Meta Boxes for Profile CPT
        add_action( 'add_meta_boxes', array( $this, 'add_profile_meta_boxes' ) );
        add_action( 'save_post_' . $this->profile_cpt_slug, array( $this, 'save_profile_meta_data' ) );

        // Note: Benchmark CPT related hooks (AJAX, enqueue, columns, content filter) are now in Benchmark_Manager
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'WPBench', 'wp-site-benchmarker' ),
            __( 'WPBench', 'wp-site-benchmarker' ),
            'manage_options',
            $this->plugin_slug,
            array( $this->benchmark_manager, 'render_run_benchmark_page' ), // Delegated
            'dashicons-dashboard',
            80
        );
        add_submenu_page(
            $this->plugin_slug,
            __( 'Run New Benchmark', 'wp-site-benchmarker' ),
            __( 'Run New Benchmark', 'wp-site-benchmarker' ),
            'manage_options',
            $this->plugin_slug,
            array( $this->benchmark_manager, 'render_run_benchmark_page' ) // Delegated
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
        $data = [
            'admin_page_title'     => get_admin_page_title(),
            'settings_option_group'=> $this->settings_option_group,
            'settings_page_slug'   => $this->plugin_slug . '-settings',
        ];

        // $this context is also available in the template
        $this->render_template('settings-page-template.php', $data);
    }

    /**
     * Registers the custom post types used by the plugin.
     * This includes 'wp_benchmark' for storing benchmark results and 'wpb_profile' for storing benchmark profiles.
     */
    public function register_profile_cpt() {
        // Profile CPT
        $profile_labels = [
            'name'                  => _x( 'Profiles', 'Post Type General Name', 'wp-site-benchmarker' ),
            'singular_name'         => _x( 'Profile', 'Post Type Singular Name', 'wp-site-benchmarker' ),
            'menu_name'             => __( 'Profiles', 'wp-site-benchmarker' ),
            'name_admin_bar'        => __( 'Profile', 'wp-site-benchmarker' ),
            'archives'              => __( 'Profile Archives', 'wp-site-benchmarker' ),
            'attributes'            => __( 'Profile Attributes', 'wp-site-benchmarker' ),
            'parent_item_colon'     => __( 'Parent Profile:', 'wp-site-benchmarker' ),
            'all_items'             => __( 'All Profiles', 'wp-site-benchmarker' ),
            'add_new_item'          => __( 'Add New Profile', 'wp-site-benchmarker' ),
            'add_new'               => __( 'Add New', 'wp-site-benchmarker' ),
            'new_item'              => __( 'New Profile', 'wp-site-benchmarker' ),
            'edit_item'             => __( 'Edit Profile', 'wp-site-benchmarker' ),
            'update_item'           => __( 'Update Profile', 'wp-site-benchmarker' ),
            'view_item'             => __( 'View Profile', 'wp-site-benchmarker' ),
            'view_items'            => __( 'View Profiles', 'wp-site-benchmarker' ),
            'search_items'          => __( 'Search Profile', 'wp-site-benchmarker' ),
            'not_found'             => __( 'Not found', 'wp-site-benchmarker' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'wp-site-benchmarker' ),
            'items_list'            => __( 'Profiles list', 'wp-site-benchmarker' ),
            'items_list_navigation' => __( 'Profiles list navigation', 'wp-site-benchmarker' ),
            'filter_items_list'     => __( 'Filter profiles list', 'wp-site-benchmarker' ),
        ];

        $profile_args = [
            'label'                 => __( 'Profile', 'wp-site-benchmarker' ),
            'labels'                => $profile_labels,
            'supports'              => array( 'title' ), // Profiles primarily store settings in meta
            'hierarchical'          => false,
            'public'                => false, // Not publicly viewable on the frontend
            'show_ui'               => true,
            'show_in_menu'          => false, // Handled by submenu
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post', // Or 'page' if more appropriate, 'post' is fine
            'show_in_rest'          => false, // Not needed for REST API by default
        ];
        
        register_post_type( $this->profile_cpt_slug, $profile_args ); // Benchmark CPT is registered by Benchmark_Manager
    }

    /**
     * Adds meta boxes to the 'Profile' custom post type edit screen.
     *
     * These meta boxes allow users to define a description, select plugins, and choose tests for the profile.
     */
    public function add_profile_meta_boxes() {
        add_meta_box( 'wpb_profile_description_mb', __( 'Profile Description', 'wp-site-benchmarker' ), array( $this, 'render_profile_description_mb' ), $this->profile_cpt_slug, 'normal', 'high' );
        add_meta_box( 'wpb_profile_plugins_mb', __( 'Plugin Configuration', 'wp-site-benchmarker' ), array( $this, 'render_profile_plugins_mb' ), $this->profile_cpt_slug, 'normal', 'high' );
        add_meta_box( 'wpb_profile_tests_mb', __( 'Tests to Run', 'wp-site-benchmarker' ), array( $this, 'render_profile_tests_mb' ), $this->profile_cpt_slug, 'normal', 'high' );
    }
    
    /**
     * Renders the meta box for the profile description.
     *
     * @param WP_Post $post The post object.
     * @since 1.1.0
     */
    public function render_profile_description_mb($post) {
        $description = get_post_meta($post->ID, '_profile_description', true);
        echo '<textarea name="profile_description" style="width:100%;" rows="4">' . esc_textarea($description) . '</textarea>';
    }

    public function render_profile_plugins_mb($post) {
        wp_nonce_field('wpb_save_profile_meta', 'wpb_profile_nonce');
        $selected_plugins = get_post_meta($post->ID, '_profile_plugins', true);
        echo '<p>' . __('Select the plugins that should be active when this profile is used.', 'wp-site-benchmarker') . '</p>';
        $this->render_plugin_list($selected_plugins);
    }

    public function render_profile_tests_mb($post) {
        $all_tests = $this->get_all_benchmark_tests();
        $selected_tests = get_post_meta($post->ID, '_profile_tests', true);

        if (empty($selected_tests)) {
            $selected_tests = array_keys($all_tests); // Default to all selected on new profile
        }

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
        // These variables will be available in the scope of the included template file.
        $all_plugins = get_plugins();
        $active_plugins_now = (array) get_option('active_plugins', array());

        // If no pre-checked plugins are provided, default to currently active plugins.
        $plugins_to_check = ($checked_plugins === false) ? $active_plugins_now : (array) $checked_plugins;
        $self_plugin_basename = plugin_basename(dirname(__FILE__)); // To exclude the benchmarker plugin itself

        $this->render_template('plugin-list-template.php', compact('all_plugins', 'plugins_to_check', 'self_plugin_basename'));
    }

    /**
     * Restores the plugin activation states to what they were before the benchmark ran.
     *
     * It deactivates plugins that were temporarily activated for the test and
     * reactivates plugins that were temporarily deactivated.
     *
     * @param array &$log_array A reference to the benchmark log array to add operational messages.
     */
    // This method is used by Benchmark_Manager, but it's general enough to be here or in a utility class.
    public function restore_plugin_states(&$log_array) { // Made public for Benchmark_Manager
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

    /**
     * Performs CPU-intensive operations.
     * @param int $intensity Controls the load (0-100).
     */
    public function perform_cpu_test($intensity = 50) { // Made public for Benchmark_Manager
        $intensity = max(0, min(100, intval($intensity))); // Ensure intensity is within 0-100
        $intensity_factor = max(0.01, $intensity / 100); // Ensure at least a minimal operation if intensity > 0

        $start_time = microtime(true);
        $result_val = 0;
        
        // --- Scalable Math Operations ---
        $math_ops_count = 0;
        $base_math_iterations = 4000000; // Base for intensity 100
        $actual_math_iterations = intval(round($base_math_iterations * $intensity_factor));
        for ($i = 0; $i < $actual_math_iterations; $i++) { 
            $result_val += sqrt($i + 0.001) * sin($i / 1000.001) * log($i + 1.001); 
            $math_ops_count++;
        }

        // --- Scalable Hashing Operations ---
        $hash_ops_count = 0;
        $base_hash_iterations = 4000; // Base for intensity 100
        $actual_hash_iterations = intval(round($base_hash_iterations * $intensity_factor));
        for ($i = 0; $i < $actual_hash_iterations; $i++) { 
            $string = openssl_random_pseudo_bytes(256); 
            $hash = hash('sha256', $string);
            if ($hash) $hash_ops_count++;
        }

        // --- Scalable String Manipulations ---
        $string_ops_count = 0;
        $base_string_iterations = 10000; // Base for intensity 100
        $actual_string_iterations = intval(round($base_string_iterations * $intensity_factor));
        $test_string = str_repeat("The quick brown fox jumps over the lazy dog. ", 20);
        for ($i = 0; $i < $actual_string_iterations; $i++) {
            $temp_string = str_replace('fox', 'cat', $test_string);
            preg_match_all('/(quick|lazy)/', $temp_string, $matches);
            $string_ops_count++;
        }

        // --- Scalable Array Sorting ---
        $array_ops_count = 0;
        $base_array_size = 5000; // Base for intensity 100
        $actual_array_size = intval(round($base_array_size * $intensity_factor));
        if ($actual_array_size > 0) {
            $sort_array = range(1, $actual_array_size);
            shuffle($sort_array);
            sort($sort_array);
            $array_ops_count++;
        }

        // --- Scalable Fibonacci Calculation (Iterative) ---
        $fib_ops_count = 0;
        $base_fib_n = 28; // Nth Fibonacci number (higher gets very slow)
        $base_fib_repeats = 5000; // Base for intensity 100
        $actual_fib_repeats = intval(round($base_fib_repeats * $intensity_factor));
        for ($k = 0; $k < $actual_fib_repeats; $k++) {
            $a = 0; $b = 1;
            for ($j = 0; $j < $base_fib_n; $j++) { $temp = $a; $a = $b; $b = $temp + $b; }
            $fib_ops_count++;
        }

        $duration = microtime(true) - $start_time;
        $details = sprintf(
            __('CPU Intensity: %1$d%%. Math ops: %2$s. Hashes: %3$s. String ops: %4$s. Array sorts: %5$s (size %6$s). Fibonacci calcs (N=%7$d): %8$s.', 'wp-site-benchmarker'),
            $intensity, 
            number_format_i18n($math_ops_count), 
            number_format_i18n($hash_ops_count), 
            number_format_i18n($string_ops_count), 
            number_format_i18n($array_ops_count), 
            number_format_i18n($actual_array_size), 
            $base_fib_n, 
            number_format_i18n($fib_ops_count)
        );
        
        return array('_cpu_duration_seconds' => round($duration, 4), '_cpu_details' => $details);
    }

    /**
     * Performs memory-intensive operations.
     * @param int $intensity Controls the load (0-100).
     */
    public function perform_memory_test($intensity = 50) { // Made public for Benchmark_Manager
        $intensity = max(0, min(100, intval($intensity)));
        $intensity_factor = max(0.01, $intensity / 100);
        
        $base_iterations = 50000; // Base for 100% intensity
        $actual_iterations = intval(round($base_iterations * $intensity_factor));
        $string_length = 1024; // 1KB strings

        $initial_memory = memory_get_usage();
        $start_time = microtime(true);
        $large_array = [];
        for ($i = 0; $i < $actual_iterations; $i++) { $large_array[] = str_repeat('x', $string_length); }
        $peak_memory = memory_get_peak_usage(false); 
        $duration = microtime(true) - $start_time;
        unset($large_array); 
        $details = sprintf(__('Memory Intensity: %1$d%%. Created an array of %2$s %3$s strings. Initial usage: %4$s, Peak usage: %5$s, Final usage (after unset): %6$s.', 'wp-site-benchmarker'), $intensity, number_format_i18n($actual_iterations), size_format($string_length), size_format($initial_memory), size_format($peak_memory), size_format(memory_get_usage()));
        return array('_memory_peak_usage_bytes' => $peak_memory, '_memory_duration_seconds' => round($duration, 4), '_memory_details' => $details);
    }

    /**
     * Performs database read-intensive operations.
     * @param int $intensity Controls the load (0-100).
     */
    public function perform_db_read_test($intensity = 50) { // Made public for Benchmark_Manager
        global $wpdb;
        $intensity = max(0, min(100, intval($intensity)));
        $intensity_factor = max(0.01, $intensity / 100);

        $base_posts_to_query = 500;
        $actual_posts_to_query = intval(round($base_posts_to_query * $intensity_factor));
        $base_options_to_read = 500;
        $actual_options_to_read = intval(round($base_options_to_read * $intensity_factor));
        $base_wp_query_posts_per_page = 200;
        $actual_wp_query_ppp = intval(round($base_wp_query_posts_per_page * $intensity_factor));

        $start_time = microtime(true);
        $query_count = 0;
        $total_posts_queried = 0;
        $savequeries_defined_initially = defined('SAVEQUERIES');
        $savequeries_initial_value = $savequeries_defined_initially ? SAVEQUERIES : null;
        if (!$savequeries_defined_initially) { define('SAVEQUERIES', true); }
        $initial_query_count = (SAVEQUERIES === true && isset($wpdb->queries)) ? count($wpdb->queries) : 0;
        
        if ($actual_posts_to_query > 0) {
            $post_ids = get_posts(array('numberposts' => $actual_posts_to_query, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC', 'suppress_filters' => true, 'no_found_rows' => true));
            if (!empty($post_ids)) {
                foreach ($post_ids as $post_id) {
                    $wpdb->get_var($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE ID = %d", $post_id));
                    $total_posts_queried++;
                }
            }
        }
        for ($i = 0; $i < $actual_options_to_read; $i++) { get_option('blogname'); } // Reading same option repeatedly is fine for load
        $total_options_queried = $actual_options_to_read;
        
        $meta_query_posts_count = 0;
        if ($actual_wp_query_ppp > 0) {
            $meta_query_posts = new WP_Query(array('post_type' => 'post', 'posts_per_page' => $actual_wp_query_ppp, 'meta_query' => array('relation' => 'OR', array('key' => '_thumbnail_id', 'compare' => 'EXISTS'), array('key' => 'some_nonexistent_meta_key', 'value' => 'some_value', 'compare' => '=')), 'fields' => 'ids', 'suppress_filters' => true, 'no_found_rows' => true,));
            $meta_query_posts_count = $meta_query_posts->post_count;
            $total_posts_queried += $meta_query_posts_count;
        }

        $duration = microtime(true) - $start_time;
        if (SAVEQUERIES === true && isset($wpdb->queries)) { $query_count = count($wpdb->queries) - $initial_query_count; } else { $query_count = -1; }
        $details = sprintf(__('DB Read Intensity: %1$d%%. Queried %2$s posts and %3$s options. WP_Query (for %4$s posts) found %5$s posts. SAVEQUERIES was %6$s during test (initial state: %7$s).', 'wp-site-benchmarker'), $intensity, number_format_i18n($total_posts_queried), number_format_i18n($total_options_queried), number_format_i18n($actual_wp_query_ppp), number_format_i18n($meta_query_posts_count), (SAVEQUERIES ? 'enabled' : 'disabled/not tracked'), ($savequeries_defined_initially ? ($savequeries_initial_value ? 'true' : 'false') : 'undefined') );
        return array('_db_read_duration_seconds' => round($duration, 4), '_db_read_queries_count' => $query_count, '_db_read_details' => $details);
    }

    /**
     * Performs database write-intensive operations.
     * @param int $intensity Controls the load (0-100).
     */
    public function perform_db_write_test($intensity = 50) { // Made public for Benchmark_Manager
        global $wpdb;
        $intensity = max(0, min(100, intval($intensity)));
        $intensity_factor = max(0.01, $intensity / 100);

        $base_option_ops = 200; // Each op is add/update/delete, so 200 means 200 adds, 200 updates, 200 deletes
        $actual_option_ops = intval(round($base_option_ops * $intensity_factor));
        $base_table_rows = 400;
        $actual_table_rows = intval(round($base_table_rows * $intensity_factor));

        $start_time = microtime(true);
        $operations = 0;
        $temp_option_prefix = 'wp_benchmark_temp_opt_';
        for ($i = 0; $i < $actual_option_ops; $i++) { add_option($temp_option_prefix . $i, 'benchmark_data_' . wp_generate_uuid4(), '', 'no'); $operations++; }
        for ($i = 0; $i < $actual_option_ops; $i++) { update_option($temp_option_prefix . $i, 'benchmark_data_updated_' . wp_generate_uuid4(), 'no'); $operations++; }
        for ($i = 0; $i < $actual_option_ops; $i++) { delete_option($temp_option_prefix . $i); $operations++; }
        $table_name = $wpdb->prefix . 'benchmark_temp_writes';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (id mediumint(9) NOT NULL AUTO_INCREMENT, data varchar(255) DEFAULT '' NOT NULL, created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY  (id)) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql); 
        for ($i = 0; $i < $actual_table_rows; $i++) { $wpdb->insert($table_name, array('data' => 'test_data_' . $i . '_' . wp_generate_uuid4(), 'created_at' => current_time('mysql'))); $operations++; }
        $wpdb->query("TRUNCATE TABLE $table_name"); 
        $duration = microtime(true) - $start_time;
        $details = sprintf(__('DB Write Intensity: %1$d%%. Performed %2$s option Add/Update/Delete operations (%3$s each). Inserted and truncated %4$s rows in a temporary table.', 'wp-site-benchmarker'), $intensity, number_format_i18n($actual_option_ops * 3), number_format_i18n($actual_option_ops), number_format_i18n($actual_table_rows));
        return array('_db_write_duration_seconds' => round($duration, 4), '_db_write_operations_count' => $operations, '_db_write_details' => $details);
    }

    /**
     * Helper function to render a template file.
     *
     * @param string $template_name The name of the template file (without .php extension) located in the 'templates' directory.
     * @param array  $data          Associative array of data to be extracted and made available to the template.
     */
    private function render_template( $template_file_name, $data = array() ) {
        $template_path = WP_SITE_BENCHMARKER_PLUGIN_DIR . 'templates/' . $template_file_name;

        if ( file_exists( $template_path ) ) {
            // Extract the data array into individual variables for the template
            if ( ! empty( $data ) && is_array( $data ) ) {
                extract( $data );
            }
            // $this is automatically available in the scope of the included file
            include $template_path;
        } else {
            // Output a more specific error if the template is missing
            echo '<div class="notice notice-error"><p>' . sprintf( esc_html__( 'Error: Template file "%s" not found.', 'wp-site-benchmarker' ), esc_html( $template_file_name ) ) . '</p></div>';
        }
    }

} // End of WPSite_Benchmarker class

WPSite_Benchmarker::get_instance();
