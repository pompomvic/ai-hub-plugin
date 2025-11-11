<?php

declare(strict_types=1);

namespace AIHub\WordPress;

use AIHub\WordPress\Admin\AccessControlPage;
use AIHub\WordPress\Admin\DashboardPage;
use AIHub\WordPress\Admin\Menu as AdminMenu;
use AIHub\WordPress\Admin\SettingsPage;
use AIHub\WordPress\Cron\Scheduler;
use AIHub\WordPress\Integration\InstrumentationManager;
use AIHub\WordPress\Integration\InstrumentationSync;
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

    private DashboardPage $dashboardPage;

    private AccessControlPage $accessControlPage;

    private AdminMenu $adminMenu;

    private SyncController $restController;

    private InstrumentationManager $instrumentationManager;

    private InstrumentationSync $instrumentationSync;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->syncService = new SyncService($this->settings);
        $this->scheduler = new Scheduler($this->settings, $this->syncService);
        $this->instrumentationSync = new InstrumentationSync($this->settings);
        $this->settingsPage = new SettingsPage($this->settings, $this->syncService, $this->instrumentationSync);
        $this->dashboardPage = new DashboardPage();
        $this->accessControlPage = new AccessControlPage($this->settings);
        $this->adminMenu = new AdminMenu(
            $this->dashboardPage,
            $this->settingsPage,
            $this->accessControlPage,
            $this->syncService
        );
        $this->restController = new SyncController($this->syncService);
        $this->instrumentationManager = new InstrumentationManager($this->settings);
    }

    public function boot(): void
    {
        Capabilities::grantDefaultCapabilities();
        add_action('plugins_loaded', [$this, 'registerTextDomain']);
        add_filter('cron_schedules', [$this, 'provideCronIntervals']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        $this->scheduler->register();
        $this->settingsPage->register();
        $this->adminMenu->register();
        $this->restController->register();
        $this->instrumentationManager->register();
        add_action('init', [$this->instrumentationSync, 'maybePull'], 5);

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
        Capabilities::grantDefaultCapabilities();
        $this->scheduler->unschedule();
        $this->scheduler->schedule();
    }

    public function deactivate(): void
    {
        $this->scheduler->unschedule();
    }

    private const DASHBOARD_MANIFEST_TRANSIENT = 'aimxb_dashboard_manifest';

    public function enqueueAdminAssets(?string $hook = null): void
    {
        if (!$this->isPluginScreen($hook)) {
            return;
        }

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

        if (!empty($entry['css']) && is_array($entry['css'])) {
            foreach ($entry['css'] as $index => $cssFile) {
                $styleHandle = 'ai-hub-admin-style' . ($index ?: '');
                wp_enqueue_style(
                    $styleHandle,
                    plugins_url('dist/' . ltrim((string) $cssFile, '/'), AI_HUB_PLUGIN_FILE),
                    [],
                    AI_HUB_PLUGIN_VERSION
                );
            }
        }

        $status = $this->settings->all();
        $restBase = rest_url('ai-hub/v1/dashboards');
        $activeDashboardSlug = $this->resolveActiveDashboardSlug();
        $view = $this->determineView($hook, $activeDashboardSlug);
        $currentUser = wp_get_current_user();

        wp_localize_script(
            $scriptHandle,
            'AIHubWordPress',
            [
                'status' => [
                    'last_sync' => $status['last_sync'] ?? null,
                    'last_error' => $status['last_error'] ?? null,
                ],
                'rest' => [
                    'dashboards' => esc_url_raw($restBase),
                    'dashboardDetail' => esc_url_raw(trailingslashit($restBase)),
                ],
                'nonce' => wp_create_nonce('wp_rest'),
                'view' => $view,
                'brand' => [
                    'primary' => $this->settings->getBrandPrimaryColor(),
                    'accent' => $this->settings->getBrandAccentColor(),
                    'logo' => $this->settings->getBrandLogoUrl(),
                    'label' => $this->settings->getTenantLabel(),
                ],
                'tenant' => [
                    'seatLimit' => $this->settings->getTenantSeatLimit(),
                    'seatsInUse' => Capabilities::countUsersWithAccess(),
                ],
                'portalUrl' => $this->settings->getBaseUrl() ? rtrim((string) $this->settings->getBaseUrl(), '/') : null,
                'currentUser' => [
                    'id' => (int) $currentUser->ID,
                    'name' => $currentUser->display_name ?: $currentUser->user_login,
                    'canManageSettings' => current_user_can('manage_options'),
                ],
                'activeDashboardSlug' => $activeDashboardSlug,
                'activeDashboardLabel' => $this->resolveActiveDashboardLabel($activeDashboardSlug),
            ]
        );
    }

    private function isPluginScreen(?string $hook): bool
    {
        if ($hook === null) {
            return false;
        }

        return str_contains($hook, 'aimxb');
    }

    private function determineView(?string $hook, ?string $activeDashboardSlug = null): string
    {
        if ($activeDashboardSlug) {
            return 'dashboard';
        }

        if ($hook && str_contains($hook, 'aimxb-settings')) {
            return 'settings';
        }

        if ($hook && str_contains($hook, 'aimxb-access-control')) {
            return 'access';
        }

        return 'dashboards';
    }

    private function resolveActiveDashboardSlug(): ?string
    {
        if (!isset($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return null;
        }

        $page = (string) wp_unslash($_GET['page']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $prefix = 'aimxb-dashboard-';

        if (!str_starts_with($page, $prefix)) {
            return null;
        }

        $slug = substr($page, strlen($prefix));
        if ($slug === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9\\/_-]+$/', $slug)) {
            return null;
        }

        return $slug;
    }

    private function resolveActiveDashboardLabel(?string $slug): ?string
    {
        if (!$slug) {
            return null;
        }

        $cached = get_transient(self::DASHBOARD_MANIFEST_TRANSIENT);
        if (!is_array($cached)) {
            return null;
        }

        foreach ($cached as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['slug'] ?? null) === $slug) {
                $label = isset($entry['label']) ? (string) $entry['label'] : '';

                return $label !== '' ? $label : null;
            }
        }

        return null;
    }
}
