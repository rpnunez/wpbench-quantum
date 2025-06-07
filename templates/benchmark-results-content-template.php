<?php
/**
 * Template for displaying benchmark results within the_content.
 *
 * Variables available:
 * @var array $meta_data          All post meta for the current benchmark result.
 * @var int|string $profile_id    The ID of the profile used for this benchmark, if any.
 * @var array $display_order      Associative array defining the order and labels for metrics.
 * @var array $active_during_test Array of plugin slugs active during the test.
 * @var array $all_plugins_data   Array of all installed plugins data (fetched if active_during_test is not empty).
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="wp-benchmark-results-details" style="margin-top:20px; padding-top:20px; border-top: 1px solid #eee;">

    <?php if (isset($meta_data['_benchmark_score_total'][0])) : ?>
        <h2 style="font-size: 1.8em; margin-bottom: 15px;"><?php esc_html_e('Overall Benchmark Score: ', 'wp-site-benchmarker'); ?><strong style="color: #2271b1;"><?php echo esc_html(round(floatval($meta_data['_benchmark_score_total'][0]), 0)); ?></strong></h2>
    <?php endif; ?>

    <h3><?php esc_html_e('Detailed Benchmark Metrics', 'wp-site-benchmarker'); ?></h3>
    <table class="form-table">
        <tbody>
            <?php foreach($display_order as $key => $label) : ?>
                <?php if ( isset($meta_data[$key][0]) ) :
                    $value = $meta_data[$key][0];
                    if ( strpos($key, '_duration_seconds') !== false || $key === '_benchmark_duration_total' ) {
                        $value = number_format_i18n(floatval($value), 4) . ' ' . __('seconds', 'wp-site-benchmarker');
                    } elseif (strpos($key, '_usage_bytes') !== false) {
                        $value = size_format(intval($value), 2);
                    } elseif (strpos($key, '_score_') !== false) {
                        $value = number_format_i18n(floatval($value), 2);
                    } elseif (strpos($key, '_intensity') !== false) {
                        $value = esc_html(intval($value)) . '%';
                    }
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html($label); ?></th>
                    <td><?php echo esc_html($value); ?></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4><?php esc_html_e('Environment Information', 'wp-site-benchmarker'); ?></h4>
    <ul>
        <?php if ( isset($meta_data['_env_php_version'][0]) ) : ?>
            <li><?php esc_html_e('PHP Version:', 'wp-site-benchmarker'); ?> <?php echo esc_html($meta_data['_env_php_version'][0]); ?></li>
        <?php endif; ?>
        <?php if ( isset($meta_data['_env_wp_version'][0]) ) : ?>
            <li><?php esc_html_e('WordPress Version:', 'wp-site-benchmarker'); ?> <?php echo esc_html($meta_data['_env_wp_version'][0]); ?></li>
        <?php endif; ?>
        <?php if ( isset($meta_data['_env_mysql_version'][0]) ) : ?>
            <li><?php esc_html_e('MySQL Version:', 'wp-site-benchmarker'); ?> <?php echo esc_html($meta_data['_env_mysql_version'][0]); ?></li>
        <?php endif; ?>
        <?php if ( isset($meta_data['_env_server_loadavg_before'][0]) ) : ?>
            <li><?php esc_html_e('Server Load Avg (Before):', 'wp-site-benchmarker'); ?> <?php echo esc_html($meta_data['_env_server_loadavg_before'][0]); ?></li>
        <?php endif; ?>
        <?php if ( isset($meta_data['_env_server_loadavg_after'][0]) ) : ?>
            <li><?php esc_html_e('Server Load Avg (After):', 'wp-site-benchmarker'); ?> <?php echo esc_html($meta_data['_env_server_loadavg_after'][0]); ?></li>
        <?php endif; ?>
    </ul>

    <?php if ( !empty($profile_id) ) :
        $profile_title = get_the_title($profile_id);
        if ( $profile_title ) : ?>
            <h4><?php esc_html_e('Benchmark Profile Used', 'wp-site-benchmarker'); ?></h4>
            <p><?php printf(esc_html__('This benchmark was run using the "%s" profile.', 'wp-site-benchmarker'), '<strong>' . esc_html($profile_title) . '</strong>'); ?></p>
        <?php endif;
    endif; ?>

    <h4><?php esc_html_e('Plugin Configuration During Test', 'wp-site-benchmarker'); ?></h4>
    <?php if (!empty($active_during_test) && is_array($active_during_test) && !empty($all_plugins_data)) : ?>
        <h5><?php esc_html_e('Plugins Active During Test:', 'wp-site-benchmarker'); ?></h5>
        <ul>
            <?php foreach ($active_during_test as $plugin_file) : ?>
                <li><?php echo esc_html(isset($all_plugins_data[$plugin_file]['Name']) ? $all_plugins_data[$plugin_file]['Name'] : $plugin_file); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p><?php esc_html_e('No plugins were active during the test, or plugin data could not be retrieved.', 'wp-site-benchmarker'); ?></p>
    <?php endif; ?>
</div>