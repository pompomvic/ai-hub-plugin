<?php
/**
 * @var array<string, mixed> $settings
 * @var array{type: string, text: string}|null $message
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap ai-hub-admin-wrap">
    <h1><?php esc_html_e('AI Hub WordPress Integration', 'ai-hub-seo'); ?></h1>
    <?php if ($message) : ?>
        <div class="<?php echo esc_attr($message['type']); ?>">
            <p><?php echo esc_html($message['text']); ?></p>
        </div>
    <?php endif; ?>
    <form method="post" action="options.php">
        <?php
        settings_fields('ai_hub_settings');
        do_settings_sections('aimxb-settings');
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
                        echo '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ';
                        echo esc_html__('Configured', 'ai-hub-seo');
                    } else {
                        echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
                        echo esc_html__('Not configured', 'ai-hub-seo');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Analytics', 'ai-hub-seo'); ?></th>
                <td>
                    <?php
                    $analyticsConfigured = !empty($settings['ga4_measurement_id'])
                        || !empty($settings['gtm_container_id']);
                    if ($analyticsConfigured) {
                        echo '<span class="dashicons dashicons-chart-line" aria-hidden="true"></span> ';
                        echo esc_html__('Tracking enabled', 'ai-hub-seo');
                    } else {
                        echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
                        echo esc_html__('No GA4 or GTM IDs configured', 'ai-hub-seo');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Matomo', 'ai-hub-seo'); ?></th>
                <td>
                    <?php
                    $matomoReady = !empty($settings['matomo_enabled'])
                        && !empty($settings['matomo_url'])
                        && !empty($settings['matomo_site_id']);
                    if ($matomoReady) {
                        echo '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ';
                        echo esc_html__('Sending events to Matomo', 'ai-hub-seo');
                        if (!empty($settings['matomo_heatmap_enabled'])) {
                            echo '<br><small>' . esc_html__('Heatmaps enabled for this site.', 'ai-hub-seo') . '</small>';
                        }
                    } else {
                        echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
                        echo esc_html__('Matomo is disabled or missing base URL / site ID.', 'ai-hub-seo');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Session Replay', 'ai-hub-seo'); ?></th>
                <td>
                    <?php
                    $sessionReplayReady = !empty($settings['session_replay_enabled'])
                        && !empty($settings['session_replay_project_key']);
                    if ($sessionReplayReady) {
                        echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span> ';
                        echo esc_html__('Streaming sessions', 'ai-hub-seo');
                    } else {
                        echo '<span class="dashicons dashicons-no" aria-hidden="true"></span> ';
                        echo esc_html__('Disabled', 'ai-hub-seo');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Feedback Widget', 'ai-hub-seo'); ?></th>
                <td>
                    <?php
                    $feedbackReady = !empty($settings['feedback_enabled'])
                        && !empty($settings['feedback_widget_url']);
                    if ($feedbackReady) {
                        echo '<span class="dashicons dashicons-testimonial" aria-hidden="true"></span> ';
                        echo esc_html__('Collecting feedback', 'ai-hub-seo');
                    } else {
                        echo '<span class="dashicons dashicons-no" aria-hidden="true"></span> ';
                        echo esc_html__('Disabled', 'ai-hub-seo');
                    }
                    ?>
                </td>
            </tr>
        </tbody>
    </table>

<div id="ai-hub-admin-app" data-screen="settings"></div>
</div>
<style>
    .ai-hub-secret-input {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .ai-hub-secret-input .ai-hub-secret-toggle {
        margin-left: 0.25rem;
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const placeholderFallback = '********';

        document.querySelectorAll('.ai-hub-secret-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.getAttribute('data-target');
                if (!targetId) {
                    return;
                }
                const input = document.getElementById(targetId);
                if (!input) {
                    return;
                }

                const showLabel = button.getAttribute('data-show-label') || 'Show';
                const hideLabel = button.getAttribute('data-hide-label') || 'Hide';
                const placeholder = input.getAttribute('data-placeholder') || placeholderFallback;

                if (input.getAttribute('data-visible') === 'true') {
                    input.setAttribute('data-secret', input.value);
                    input.type = 'password';
                    input.value = placeholder;
                    input.setAttribute('data-visible', 'false');
                    button.textContent = showLabel;
                } else {
                    const secret = input.getAttribute('data-secret') || '';
                    input.type = 'text';
                    input.value = secret;
                    input.setAttribute('data-visible', 'true');
                    button.textContent = hideLabel;
                }
            });
        });

        document.querySelectorAll('.ai-hub-secret-input input').forEach(function (input) {
            input.addEventListener('input', function () {
                if (input.getAttribute('data-visible') === 'true') {
                    input.setAttribute('data-secret', input.value);
                }
            });
        });
    });
</script>
