<?php
/**
 * @var array<string, mixed> $settings
 * @var array{type: string, text: string}|null $message
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('AI Hub WordPress Integration', 'ai-hub-seo'); ?></h1>
    <?php if ($message) : ?>
        <div class="<?php echo esc_attr($message['type']); ?>">
            <p><?php echo esc_html($message['text']); ?></p>
        </div>
    <?php endif; ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('ai_hub_settings');
        do_settings_sections('ai-hub-settings');
        submit_button(__('Save Settings', 'ai-hub-seo'));
        ?>
    </form>

    <form method="post" style="margin-top: 2rem;">
        <?php wp_nonce_field('ai_hub_run_sync'); ?>
        <input type="hidden" name="ai_hub_run_sync" value="1" />
        <?php submit_button(__('Run Sync Now', 'ai-hub-seo'), 'secondary'); ?>
    </form>

    <h2><?php esc_html_e('Status', 'ai-hub-seo'); ?></h2>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Last Sync', 'ai-hub-seo'); ?></th>
                <td><?php echo isset($settings['last_sync']) ? esc_html($settings['last_sync']) : '—'; ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Last Error', 'ai-hub-seo'); ?></th>
                <td><?php echo isset($settings['last_error']) ? esc_html($settings['last_error']) : '—'; ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Tenant API Key', 'ai-hub-seo'); ?></th>
                <td>
                    <?php
                    if (!empty($settings['tenant_api_key'])) {
                        echo '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' . esc_html__('Configured', 'ai-hub-seo');
                    } else {
                        echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('Not configured', 'ai-hub-seo');
                    }
                    ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div id="ai-hub-wordpress-admin"></div>
</div>
