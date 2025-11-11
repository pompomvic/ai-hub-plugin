<?php

declare(strict_types=1);

namespace AIHub\WordPress\Admin;

use AIHub\WordPress\Capabilities;
use AIHub\WordPress\Sync\SyncService;

/**
 * Registers the AI Hub admin menu and its subpages.
 */
class Menu
{
    private const MANIFEST_TRANSIENT_KEY = 'aimxb_dashboard_manifest';

    private DashboardPage $dashboardPage;

    private SettingsPage $settingsPage;

    private AccessControlPage $accessControlPage;

    private SyncService $syncService;

    public function __construct(
        DashboardPage $dashboardPage,
        SettingsPage $settingsPage,
        AccessControlPage $accessControlPage,
        SyncService $syncService
    ) {
        $this->dashboardPage = $dashboardPage;
        $this->settingsPage = $settingsPage;
        $this->accessControlPage = $accessControlPage;
        $this->syncService = $syncService;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        $parentSlug = 'aimxb';

        $icon = get_site_icon_url(32);
        if (!$icon) {
            $icon = 'dashicons-pets';
        }

        add_menu_page(
            __('AIMXB', 'ai-hub-seo'),
            __('AIMXB', 'ai-hub-seo'),
            Capabilities::ACCESS,
            $parentSlug,
            [$this->dashboardPage, 'render'],
            $icon,
            59
        );

        add_submenu_page(
            $parentSlug,
            __('Dashboards', 'ai-hub-seo'),
            __('Dashboards', 'ai-hub-seo'),
            Capabilities::ACCESS,
            $parentSlug,
            [$this->dashboardPage, 'render']
        );

        add_submenu_page(
            $parentSlug,
            __('Settings', 'ai-hub-seo'),
            __('Settings', 'ai-hub-seo'),
            'manage_options',
            'aimxb-settings',
            [$this->settingsPage, 'render']
        );

        add_submenu_page(
            $parentSlug,
            __('User Access', 'ai-hub-seo'),
            __('User Access', 'ai-hub-seo'),
            'manage_options',
            'aimxb-access-control',
            [$this->accessControlPage, 'render']
        );

        $this->registerDashboardTabs($parentSlug);
    }

    private function registerDashboardTabs(string $parentSlug): void
    {
        $dashboards = $this->getDashboardManifestForMenu();
        if (empty($dashboards)) {
            return;
        }

        $seen = [];
        foreach ($dashboards as $dashboard) {
            $slug = $dashboard['slug'];
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $label = $dashboard['label'];
            $menuSlug = $this->buildMenuSlug($slug);

            add_submenu_page(
                $parentSlug,
                sprintf(
                    /* translators: %s is the dashboard title. */
                    __('AIMXB · %s', 'ai-hub-seo'),
                    $label
                ),
                $label,
                Capabilities::ACCESS,
                $menuSlug,
                function () use ($slug, $label): void {
                    $this->dashboardPage->renderDashboard($slug, $label);
                }
            );
        }
    }

    /**
     * @return array<int, array{slug:string,label:string}>
     */
    private function getDashboardManifestForMenu(): array
    {
        $cached = get_transient(self::MANIFEST_TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $raw = $this->syncService->fetchDashboardManifest();
        } catch (\Throwable $exception) {
            return [];
        }

        $normalised = $this->normaliseDashboards($raw);
        set_transient(self::MANIFEST_TRANSIENT_KEY, $normalised, 10 * MINUTE_IN_SECONDS);

        return $normalised;
    }

    /**
     * @param array<int, array<string, mixed>> $dashboards
     *
     * @return array<int, array{slug:string,label:string}>
     */
    private function normaliseDashboards(array $dashboards): array
    {
        $normalised = [];

        foreach ($dashboards as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $slug = $this->resolveSlug($entry);
            if ($slug === '') {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? $entry['slug'] ?? ''));
            if ($label === '') {
                $label = __('Untitled Dashboard', 'ai-hub-seo');
            }

            $normalised[] = [
                'slug' => $slug,
                'label' => $label,
            ];
        }

        return $normalised;
    }

    private function resolveSlug(array $entry): string
    {
        $provided = isset($entry['slug']) ? trim((string) $entry['slug']) : '';
        if ($provided !== '') {
            return $provided;
        }

        return '';
    }

    private function buildMenuSlug(string $dashboardSlug): string
    {
        return 'aimxb-dashboard-' . rawurlencode($dashboardSlug);
    }
}
