<?php

declare(strict_types=1);

namespace AIHub\WordPress\Rest;

use AIHub\WordPress\Capabilities;

use AIHub\WordPress\Sync\SyncService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes REST endpoint for manual sync triggers from the admin UI.
 */
class SyncController
{
    private SyncService $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function register(): void
    {
        add_action(
            'rest_api_init',
            function (): void {
                register_rest_route(
                    'ai-hub/v1',
                    '/sync',
                    [
                        'methods' => 'POST',
                        'callback' => [$this, 'handleSync'],
                        'permission_callback' => [$this, 'canSync'],
                    ]
                );
                register_rest_route(
                    'ai-hub/v1',
                    '/dashboards',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'listDashboards'],
                        'permission_callback' => [$this, 'canAccessDashboards'],
                    ]
                );
                register_rest_route(
                    'ai-hub/v1',
                    '/dashboards/(?P<slug>[a-zA-Z0-9\-_/]+)',
                    [
                        'methods' => 'GET',
                        'callback' => [$this, 'getDashboard'],
                        'permission_callback' => [$this, 'canAccessDashboards'],
                    ]
                );
            }
        );
    }

    public function handleSync(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->syncService->run('api');

        if ($result instanceof WP_Error) {
            return new WP_REST_Response(
                [
                    'error' => $result->get_error_message(),
                ],
                400
            );
        }

        return new WP_REST_Response(
            [
                'applied' => $result['applied'],
                'dismissed' => $result['dismissed'],
            ]
        );
    }

    public function listDashboards(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $dashboards = $this->syncService->fetchDashboardManifest();
        } catch (\Throwable $exception) {
            return new WP_REST_Response(
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }

        return new WP_REST_Response(
            [
                'dashboards' => $dashboards,
            ]
        );
    }

    public function getDashboard(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');
        if (!$slug) {
            return new WP_REST_Response(
                [
                    'error' => __('Dashboard slug is required.', 'ai-hub-seo'),
                ],
                400
            );
        }

        try {
            $dashboard = $this->syncService->fetchDashboardDetail($slug);
        } catch (\Throwable $exception) {
            return new WP_REST_Response(
                [
                    'error' => $exception->getMessage(),
                ],
                500
            );
        }

        if (empty($dashboard)) {
            return new WP_REST_Response(
                [
                    'error' => __('Dashboard not available.', 'ai-hub-seo'),
                ],
                404
            );
        }

        return new WP_REST_Response(
            [
                'dashboard' => $dashboard,
            ]
        );
    }

    public function canSync(): bool
    {
        return current_user_can('manage_options');
    }

    public function canAccessDashboards(): bool
    {
        return current_user_can(Capabilities::ACCESS);
    }
}
