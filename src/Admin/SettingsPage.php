<?php

declare(strict_types=1);

namespace AIHub\WordPress\Admin;

use AIHub\WordPress\Settings;
use AIHub\WordPress\Sync\SyncService;
use WP_Error;

/**
 * Renders the plugin's settings screen and handles form submissions.
 */
class SettingsPage
{
    private Settings $settings;

    private SyncService $syncService;

    public function __construct(Settings $settings, SyncService $syncService)
    {
        $this->settings = $settings;
        $this->syncService = $syncService;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerPage(): void
    {
        add_menu_page(
            __('AI Hub WordPress', 'ai-hub-seo'),
            __('AI Hub', 'ai-hub-seo'),
            'manage_options',
            'ai-hub-settings',
            [$this, 'render'],
            'dashicons-text-page'
        );
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
            'ai-hub-settings'
        );

        add_settings_field(
            'ai_hub_base_url',
            __('AI Hub Base URL', 'ai-hub-seo'),
            fn () => $this->renderInput('base_url', 'https://hub.example.com'),
            'ai-hub-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_site_id',
            __('Site ID', 'ai-hub-seo'),
            fn () => $this->renderInput('site_id', __('e.g. 123e4567-e89b-12d3-a456-426614174000', 'ai-hub-seo')),
            'ai-hub-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_tenant_api_key',
            __('Tenant API Key', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'tenant_api_key',
                __('Paste the tenant automation key from AI Hub', 'ai-hub-seo'),
                'password'
            ),
            'ai-hub-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_token',
            __('Automation Token', 'ai-hub-seo'),
            fn () => $this->renderInput(
                'automation_token',
                __('Paste the one-time token from AI Hub', 'ai-hub-seo'),
                'password'
            ),
            'ai-hub-settings',
            'ai_hub_general'
        );

        add_settings_field(
            'ai_hub_sync_interval',
            __('Sync Interval (minutes)', 'ai-hub-seo'),
            fn () => $this->renderInput('sync_interval', '15', 'number'),
            'ai-hub-settings',
            'ai_hub_general'
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
            $result = $this->syncService->run();
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

    private function renderInput(string $key, string $placeholder, string $type = 'text'): void
    {
        $settings = $this->settings->all();
        $value = $settings[$key] ?? '';
        printf(
            '<input type="%3$s" class="regular-text" name="ai_hub_wp_settings[%1$s]" value="%2$s" ' .
            'placeholder="%4$s" autocomplete="off" />',
            esc_attr($key),
            esc_attr((string) $value),
            esc_attr($type),
            esc_attr($placeholder)
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

        if (isset($input['base_url'])) {
            $sanitised['base_url'] = esc_url_raw((string) $input['base_url']);
        }

        if (isset($input['site_id'])) {
            $sanitised['site_id'] = sanitize_text_field((string) $input['site_id']);
        }

        if (isset($input['tenant_api_key'])) {
            $sanitised['tenant_api_key'] = sanitize_text_field((string) $input['tenant_api_key']);
        }

        if (isset($input['automation_token'])) {
            $sanitised['automation_token'] = sanitize_text_field((string) $input['automation_token']);
        }

        if (isset($input['sync_interval'])) {
            $interval = (int) $input['sync_interval'];
            $sanitised['sync_interval'] = max(5, $interval);
        }

        return array_filter(
            $sanitised,
            fn ($value) => $value !== null && $value !== ''
        );
    }
}
