<?php
/**
 * Template for rendering the plugin list with checkboxes.
 *
 * Variables available:
 * @var array $all_plugins      Array of all installed plugins (plugin_file => plugin_data).
 * @var array $plugins_to_check Array of plugin file paths that should be checked.
 * @var string $self_plugin_basename The basename of the current plugin (WP Site Benchmarker) to exclude it.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
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
            echo '<tr><td colspan="3">' . esc_html__('No plugins found.', 'wp-site-benchmarker') . '</td></tr>';
        } else {
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                // Exclude the WP Site Benchmarker plugin itself from the list
                if (strpos($plugin_file, $self_plugin_basename) === 0) {
                    continue;
                }
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
                            <span class="author"><?php printf(__('By %s', 'wp-site-benchmarker'), esc_html($plugin_data['AuthorName'] ?? $plugin_data['Author'])); ?></span>
                        </div>
                    </td>
                    <td class="column-description desc">
                        <div class="plugin-description">
                            <p><?php echo wp_kses_post($plugin_data['Description']); ?></p>
                        </div>
                    </td>
                </tr>
                <?php
            }
        }
        ?>
    </tbody>
</table>