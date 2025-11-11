<?php

declare(strict_types=1);

namespace AIHub\WordPress\Admin;

use AIHub\WordPress\Settings;
use AIHub\WordPress\Integration\InstrumentationSync;
use AIHub\WordPress\Sync\SyncService;
use WP_Error;

/**
 * Renders the plugin's settings screen and handles form submissions.
 */
class SettingsPage
{
    private const SECRET_PLACEHOLDER = '********';

    private Settings $settings;

    private SyncService $syncService;

    private InstrumentationSync $instrumentationSync;

    public function __construct(Settings $settings, SyncService $syncService, ?InstrumentationSync $instrumentationSync = null)
    {
        $this->settings = $settings;
        $this->syncService = $syncService;
        $this->instrumentationSync = $instrumentationSync ?? new InstrumentationSync($settings);
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings(): void
    {
        register_setting(
            'ai_hub_settings',
            'ai_hub_wp_settings',
            [
                'sanitize_callback' => [$this, 'sanitize'],
            ]
        );

        add_settings_section(
            'ai_hub_general',
            __('Connection Settings', 'ai-hub-seo'),
            fn () => print '<p>' . esc_html__('Connect this site to AI Hub.', 'ai-hub-seo') . '</p>',
            'aimxb-settings'
        );

        add_settings_field(
            'ai_hub_base_url',
            __('AI Hub Base URL', 'ai-hub-seo'),
            fn () => $this->renderInput('base_url', 'https://hub.example.com'),
            'aimxb-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_site_id',
            __('Site ID', 'ai-hub-seo'),
            fn () => $this->renderInput('site_id', __('e.g. 123e4567-e89b-12d3-a456-426614174000', 'ai-hub-seo')),
            'aimxb-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_tenant_api_key',
            __('Tenant API Key', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'tenant_api_key',
                __('Paste the tenant automation key from AI Hub', 'ai-hub-seo'),
                'password',
                true
            ),
            'aimxb-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_token',
            __('Automation Token', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'automation_token',
                __('Paste the one-time token from AI Hub', 'ai-hub-seo'),
                'password',
                true
            ),
            'aimxb-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_sync_interval',
            __('Sync Interval (minutes)', 'ai-hub-seo'),
            fn () => $this->renderInput('sync_interval', '15', 'number'),
            'aimxb-settings',
            'ai_hub_general'
        );

        add_settings_section(
            'ai_hub_analytics',
            __('Analytics & Tag Manager', 'ai-hub-seo'),
            fn () => print '<p>' . esc_html__(
                'Configure GA4 or Google Tag Manager IDs plus consent mapping.',
                'ai-hub-seo'
            ) . '</p>',
            'aimxb-settings'
        );

        add_settings_field(
            'ai_hub_ga4_measurement_id',
            __('GA4 Measurement ID', 'ai-hub-seo'),
            fn () => $this->renderInput('ga4_measurement_id', __('e.g. G-ABC1234567', 'ai-hub-seo')),
            'aimxb-settings',
            'ai_hub_analytics'
        );

        add_settings_field(
            'ai_hub_gtm_container_id',
            __('GTM Container ID', 'ai-hub-seo'),
            fn () => $this->renderInput('gtm_container_id', __('e.g. GTM-ABC1234', 'ai-hub-seo')),
            'aimxb-settings',
            'ai_hub_analytics'
        );

        add_settings_field(
            'ai_hub_conversion_event_name',
            __('Default Conversion Event', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'conversion_event_name',
                __('generate_lead', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_analytics'
        );

        add_settings_field(
            'ai_hub_analytics_consent_cookie',
            __('Consent Cookie Name', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'analytics_consent_cookie',
                __('e.g. hub_consent', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_analytics'
        );

        add_settings_field(
            'ai_hub_analytics_consent_opt_out',
            __('Opt-out Value', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'analytics_consent_opt_out',
                __('deny', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_analytics'
        );

        add_settings_section(
            'ai_hub_matomo',
            __('Matomo & Heatmaps', 'ai-hub-seo'),
            fn () => print '<p>' . esc_html__(
                'Enable the self-hosted Matomo tracker and optional heatmaps.',
                'ai-hub-seo'
            ) . '</p>',
            'aimxb-settings'
        );

        add_settings_field(
            'ai_hub_matomo_enabled',
            __('Enable Matomo tracker', 'ai-hub-seo'),
            fn () => $this->renderCheckbox(
                'matomo_enabled',
                __('Send analytics to your Matomo instance instead of third-party pixels.', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_matomo'
        );

        add_settings_field(
            'ai_hub_matomo_url',
            __('Matomo Base URL', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'matomo_url',
                __('https://analytics.example.com', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_matomo'
        );

        add_settings_field(
            'ai_hub_matomo_site_id',
            __('Matomo Site ID', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'matomo_site_id',
                __('e.g. 5', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_matomo'
        );

        add_settings_field(
            'ai_hub_matomo_heatmap_enabled',
            __('Enable Matomo heatmaps', 'ai-hub-seo'),
            fn () => $this->renderCheckbox(
                'matomo_heatmap_enabled',
                __('Publish Matomo heatmaps & session recording for this site.', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_matomo'
        );

        add_settings_section(
            'ai_hub_session_replay',
            __('Session Replay', 'ai-hub-seo'),
            fn () => print '<p>' . esc_html__(
                'Enable and configure the self-hosted session replay snippet.',
                'ai-hub-seo'
            ) . '</p>',
            'aimxb-settings'
        );

        add_settings_field(
            'ai_hub_session_replay_enabled',
            __('Activate Session Replay', 'ai-hub-seo'),
            fn () => $this->renderCheckbox(
                'session_replay_enabled',
                __('Stream sessions to OpenReplay (requires consent).', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_session_replay'
        );

        add_settings_field(
            'ai_hub_session_replay_project_key',
            __('Project Key', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'session_replay_project_key',
                __('Paste the OpenReplay/Open-source key', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_session_replay'
        );

        add_settings_field(
            'ai_hub_session_replay_host',
            __('Ingest Host', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'session_replay_host',
                __('https://app.openreplay.com', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_session_replay'
        );

        add_settings_field(
            'ai_hub_session_replay_mask_selectors',
            __('Mask Selectors', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'session_replay_mask_selectors',
                __('Comma-separated selectors to redact', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_session_replay'
        );

        add_settings_section(
            'ai_hub_feedback',
            __('Feedback Widget', 'ai-hub-seo'),
            fn () => print '<p>' . esc_html__(
                'Embed a self-hosted feedback widget such as Astuto.',
                'ai-hub-seo'
            ) . '</p>',
            'aimxb-settings'
        );

        add_settings_field(
            'ai_hub_feedback_enabled',
            __('Enable Feedback Widget', 'ai-hub-seo'),
            fn () => $this->renderCheckbox(
                'feedback_enabled',
                __('Show the on-site feedback launcher.', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_feedback'
        );

        add_settings_field(
            'ai_hub_feedback_widget_url',
            __('Widget Script URL', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'feedback_widget_url',
                __('https://feedback.example.com/widget.js', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_feedback'
        );

        add_settings_field(
            'ai_hub_feedback_project_key',
            __('Feedback Project Key', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'feedback_project_key',
                __('Project or board identifier', 'ai-hub-seo')
            ),
            'aimxb-settings',
            'ai_hub_feedback'
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to view this page.', 'ai-hub-seo'));
        }

        $message = null;
        $result = null;

        if (!empty($_POST['ai_hub_run_sync'])) { // phpcs:ignore WordPress.Security.NonceVerification
            check_admin_referer('ai_hub_run_sync');
            $result = $this->syncService->run('manual');
            if ($result instanceof WP_Error) {
                $message = [
                    'type' => 'error',
                    'text' => $result->get_error_message(),
                ];
            } else {
                $message = [
                    'type' => 'updated',
                    'text' => sprintf(
                        /* translators: 1: applied count, 2: dismissed count */
                        __('Sync complete. Applied %1$d drafts, dismissed %2$d.', 'ai-hub-seo'),
                        $result['applied'],
                        $result['dismissed']
                    ),
                ];
            }
        }

        $settings = $this->settings->all();

        include __DIR__ . '/templates/settings-page.php';
    }

    private function renderInput(string $key, string $placeholder, string $type = 'text', bool $sensitive = false): void
    {
        $settings = $this->settings->all();
        $storedValue = (string) ($settings[$key] ?? '');
        $inputId = 'ai-hub-field-' . $key;

        if ($sensitive) {
            $displayValue = $storedValue !== '' ? self::SECRET_PLACEHOLDER : '';
            printf(
                '<div class="ai-hub-secret-input"><input id="%6$s" type="%3$s" class="regular-text" name="ai_hub_wp_settings[%1$s]" value="%2$s" placeholder="%4$s" autocomplete="new-password" data-secret="%7$s" data-placeholder="%8$s" data-visible="false" />'
                . '<button type="button" class="button-link ai-hub-secret-toggle" data-target="%6$s" data-show-label="%9$s" data-hide-label="%10$s">%9$s</button></div>',
                esc_attr($key),
                esc_attr($displayValue),
                esc_attr($type),
                esc_attr($placeholder),
                '',
                esc_attr($inputId),
                esc_attr($storedValue),
                esc_attr(self::SECRET_PLACEHOLDER),
                esc_html__('Show', 'ai-hub-seo'),
                esc_html__('Hide', 'ai-hub-seo')
            );
            return;
        }

        printf(
            '<input id="%6$s" type="%3$s" class="regular-text" name="ai_hub_wp_settings[%1$s]" value="%2$s" ' .
            'placeholder="%4$s" autocomplete="%5$s" />',
            esc_attr($key),
            esc_attr((string) $storedValue),
            esc_attr($type),
            esc_attr($placeholder),
            $sensitive ? 'new-password' : 'off',
            esc_attr($inputId)
        );
    }

    private function renderCheckbox(string $key, string $label): void
    {
        $settings = $this->settings->all();
        $checked = !empty($settings[$key]);
        printf(
            '<label><input type="checkbox" name="ai_hub_wp_settings[%1$s]" value="1" %2$s /> %3$s</label>',
            esc_attr($key),
            checked($checked, true, false),
            esc_html($label)
        );
    }


    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $sanitised = [];
        $currentSettings = $this->settings->all();

        if (isset($input['base_url'])) {
            $sanitised['base_url'] = esc_url_raw((string) $input['base_url']);
        }

        if (isset($input['site_id'])) {
            $sanitised['site_id'] = sanitize_text_field((string) $input['site_id']);
        }

        if (array_key_exists('tenant_api_key', $input)) {
            $sanitised['tenant_api_key'] = $this->sanitizeSecretField(
                (string) $input['tenant_api_key'],
                $currentSettings['tenant_api_key'] ?? null
            );
        }

        if (array_key_exists('automation_token', $input)) {
            $sanitised['automation_token'] = $this->sanitizeSecretField(
                (string) $input['automation_token'],
                $currentSettings['automation_token'] ?? null
            );
        }

        if (isset($input['sync_interval'])) {
            $interval = (int) $input['sync_interval'];
            $sanitised['sync_interval'] = max(5, $interval);
        }

        if (isset($input['ga4_measurement_id'])) {
            $sanitised['ga4_measurement_id'] = sanitize_text_field((string) $input['ga4_measurement_id']);
        }

        if (isset($input['gtm_container_id'])) {
            $sanitised['gtm_container_id'] = sanitize_text_field((string) $input['gtm_container_id']);
        }

        if (isset($input['conversion_event_name'])) {
            $sanitised['conversion_event_name'] = sanitize_text_field((string) $input['conversion_event_name']);
        }

        if (isset($input['analytics_consent_cookie'])) {
            $sanitised['analytics_consent_cookie'] = sanitize_text_field((string) $input['analytics_consent_cookie']);
        }

        if (isset($input['analytics_consent_opt_out'])) {
            $sanitised['analytics_consent_opt_out'] = sanitize_text_field((string) $input['analytics_consent_opt_out']);
        }

        $sanitised['matomo_enabled'] = !empty($input['matomo_enabled']);

        if (isset($input['matomo_url'])) {
            $sanitised['matomo_url'] = esc_url_raw((string) $input['matomo_url']);
        }

        if (isset($input['matomo_site_id'])) {
            $sanitised['matomo_site_id'] = sanitize_text_field((string) $input['matomo_site_id']);
        }

        $sanitised['matomo_heatmap_enabled'] = !empty($input['matomo_heatmap_enabled']);

        $sanitised['session_replay_enabled'] = !empty($input['session_replay_enabled']);

        if (isset($input['session_replay_project_key'])) {
            $sanitised['session_replay_project_key'] = sanitize_text_field(
                (string) $input['session_replay_project_key']
            );
        }

        if (isset($input['session_replay_host'])) {
            $sanitised['session_replay_host'] = esc_url_raw((string) $input['session_replay_host']);
        }

        if (isset($input['session_replay_mask_selectors'])) {
            $value = array_values(
                array_filter(
                    array_map(
                        static fn ($selector) => trim((string) $selector),
                        explode(',', (string) $input['session_replay_mask_selectors'])
                    )
                )
            );
            $sanitised['session_replay_mask_selectors'] = implode(',', $value);
        }

        $sanitised['feedback_enabled'] = !empty($input['feedback_enabled']);

        if (isset($input['feedback_widget_url'])) {
            $sanitised['feedback_widget_url'] = esc_url_raw((string) $input['feedback_widget_url']);
        }

        if (isset($input['feedback_project_key'])) {
            $sanitised['feedback_project_key'] = sanitize_text_field((string) $input['feedback_project_key']);
        }

        $sanitised = array_filter(
            $sanitised,
            fn ($value) => $value !== null && $value !== ''
        );

        try {
            $merged = array_merge($this->settings->all(), $sanitised);
            $this->instrumentationSync->push($merged);
        } catch (\Throwable $throwable) {
            add_settings_error(
                'ai_hub_settings',
                'ai_hub_integration_sync_failed',
                sprintf(
                    /* translators: %s: error message */
                    __('Failed to sync instrumentation settings with AI Hub: %s', 'ai-hub-seo'),
                    $throwable->getMessage()
                ),
                'error'
            );
        }

        return $this->settings->prepareForStorage($sanitised);
    }

    private function sanitizeSecretField(string $submitted, ?string $existing): string
    {
        $submitted = trim($submitted);
        if ($submitted === '' || $submitted === self::SECRET_PLACEHOLDER) {
            return $existing !== null ? (string) $existing : '';
        }

        return sanitize_text_field($submitted);
    }
}
