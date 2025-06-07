<?php
/**
 * Template for the plugin settings page.
 *
 * Variables available:
 * @var string $admin_page_title      The title of the admin page.
 * @var string $settings_option_group The option group for settings_fields().
 * @var string $settings_page_slug    The slug for do_settings_sections().
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="wrap">
    <h1><?php echo esc_html( $admin_page_title ); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields( $settings_option_group );
        do_settings_sections( $settings_page_slug );
        submit_button();
        ?>
    </form>
</div>