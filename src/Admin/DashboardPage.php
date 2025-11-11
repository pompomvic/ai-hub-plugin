<?php

declare(strict_types=1);

namespace AIHub\WordPress\Admin;

use AIHub\WordPress\Capabilities;

/**
 * Hosts the React dashboards experience inside wp-admin.
 */
class DashboardPage
{
    public function render(): void
    {
        $this->renderScreen('dashboards');
    }

    public function renderDashboard(string $slug, ?string $label = null): void
    {
        $this->renderScreen('dashboard', $slug, $label);
    }

    private function renderScreen(string $view, ?string $dashboardSlug = null, ?string $dashboardLabel = null): void
    {
        if (!current_user_can(Capabilities::ACCESS)) {
            wp_die(__('You do not have permission to view AIMXB dashboards.', 'ai-hub-seo'));
        }

        $screenView = $view;
        $activeDashboardSlug = $dashboardSlug;
        $activeDashboardLabel = $dashboardLabel;

        include __DIR__ . '/templates/dashboard-page.php';
    }
}
