<?php

declare(strict_types=1);

namespace AIHub\WordPress;

use AIHub\WordPress\Admin\SettingsPage;
use AIHub\WordPress\Cron\Scheduler;
use AIHub\WordPress\Rest\SyncController;
use AIHub\WordPress\Sync\SyncService;

/**
 * Plugin bootstrapper responsible for wiring WordPress hooks.
 */
class Plugin
{
    private Settings $settings;

    private SyncService $syncService;

    private Scheduler $scheduler;

    private SettingsPage $settingsPage;

    private SyncController $restController;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->syncService = new SyncService($this->settings);
        $this->scheduler = new Scheduler($this->settings, $this->syncService);
        $this->settingsPage = new SettingsPage($this->settings, $this->syncService);
        $this->restController = new SyncController($this->syncService);
    }

    public function boot(): void
    {
        add_action('plugins_loaded', [$this, 'registerTextDomain']);
        add_filter('cron_schedules', [$this, 'provideCronIntervals']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        $this->scheduler->register();
        $this->settingsPage->register();
        $this->restController->register();

        add_action(
            'init',
            function (): void {
                if (!wp_next_scheduled(Scheduler::EVENT_HOOK)) {
                    $this->scheduler->schedule();
                }
            }
        );

        register_activation_hook(AI_HUB_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(AI_HUB_PLUGIN_FILE, [$this, 'deactivate']);
    }

    public function registerTextDomain(): void
    {
        load_plugin_textdomain(
            'ai-hub-seo',
            false,
            dirname(plugin_basename(AI_HUB_PLUGIN_FILE)) . '/assets/languages/'
        );
    }

    /**
     * Adds custom cron intervals to support more frequent syncs.
     *
     * @param array<string, mixed> $schedules
     *
     * @return array<string, mixed>
     */
    public function provideCronIntervals(array $schedules): array
    {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'ai-hub-seo'),
        ];

        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'ai-hub-seo'),
        ];

        $schedules['thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'ai-hub-seo'),
        ];

        return $schedules;
    }

    public function activate(): void
    {
        $this->scheduler->unschedule();
        $this->scheduler->schedule();
    }

    public function deactivate(): void
    {
        $this->scheduler->unschedule();
    }

    public function enqueueAdminAssets(): void
    {
        $manifestPath = AI_HUB_PLUGIN_DIR . 'dist/manifest.json';
        if (!file_exists($manifestPath)) {
            $alternate = AI_HUB_PLUGIN_DIR . 'dist/.vite/manifest.json';
            if (file_exists($alternate)) {
                $manifestPath = $alternate;
            } else {
                return;
            }
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entry = null;
        if (is_array($manifest)) {
            if (isset($manifest['admin']) && is_array($manifest['admin'])) {
                $entry = $manifest['admin'];
            } elseif (isset($manifest['admin/index.tsx']) && is_array($manifest['admin/index.tsx'])) {
                $entry = $manifest['admin/index.tsx'];
            } else {
                foreach ($manifest as $candidate) {
                    if (is_array($candidate) && ($candidate['name'] ?? null) === 'admin') {
                        $entry = $candidate;
                        break;
                    }
                }
            }
        }

        if (!is_array($entry) || empty($entry['file'])) {
            return;
        }

        $scriptHandle = 'ai-hub-admin';
        $scriptUrl = plugins_url('dist/' . ltrim((string) $entry['file'], '/'), AI_HUB_PLUGIN_FILE);

        wp_enqueue_script(
            $scriptHandle,
            $scriptUrl,
            [
                'wp-element',
                'wp-components',
                'wp-i18n',
                'wp-data',
            ],
            null,
            true
        );

        $status = $this->settings->all();
        wp_localize_script(
            $scriptHandle,
            'AIHubWordPress',
            [
                'status' => [
                    'last_sync' => $status['last_sync'] ?? null,
                    'last_error' => $status['last_error'] ?? null,
                ],
                'restUrl' => esc_url_raw(rest_url('ai-hub/v1/dashboards')),
                'nonce' => wp_create_nonce('wp_rest'),
            ]
        );
    }
}
