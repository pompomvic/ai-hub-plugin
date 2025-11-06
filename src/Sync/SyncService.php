<?php

declare(strict_types=1);

namespace AIHub\WordPress\Sync;

use AIHub\WordPress\Http\ApiClient;
use AIHub\WordPress\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use WP_Error;

/**
 * Orchestrates the pull/apply lifecycle for SEO updates.
 */
class SyncService
{
    private Settings $settings;

    private ApiClient $apiClient;

    public function __construct(
        Settings $settings,
        ?ClientInterface $httpClient = null,
        ?ApiClient $apiClient = null
    )
    {
        $this->settings = $settings;

        $this->apiClient = $apiClient ?? new ApiClient(
            $httpClient ?? new Client(
                [
                    'verify' => true,
                ]
            )
        );
    }

    /**
     * Fetch the dashboard manifest for the configured tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchDashboardManifest(): array
    {
        $baseUrl = $this->settings->getBaseUrl();
        $siteId = $this->settings->getSiteId();
        $token = $this->settings->getAutomationToken();
        $tenantKey = $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$token) {
            throw new RuntimeException(
                __('AI Hub settings are incomplete. Please configure the plugin.', 'ai-hub-seo')
            );
        }

        try {
            return $this->apiClient->fetchDashboardsManifest($baseUrl, $siteId, $token, $tenantKey);
        } catch (GuzzleException $exception) {
            throw new RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * Fetch dashboard detail for a specific slug.
     *
     * @return array<string, mixed>
     */
    public function fetchDashboardDetail(string $slug): array
    {
        $baseUrl = $this->settings->getBaseUrl();
        $siteId = $this->settings->getSiteId();
        $token = $this->settings->getAutomationToken();
        $tenantKey = $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$token) {
            throw new RuntimeException(
                __('AI Hub settings are incomplete. Please configure the plugin.', 'ai-hub-seo')
            );
        }

        try {
            return $this->apiClient->fetchDashboardDetail($baseUrl, $siteId, $token, $slug, $tenantKey);
        } catch (GuzzleException $exception) {
            throw new RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    /**
     * Runs the sync pipeline: fetch updates, apply them, and report back.
     *
     * @return array{applied: int, dismissed: int}|WP_Error Summary or error.
     */
    public function run(): array|WP_Error
    {
        $baseUrl = $this->settings->getBaseUrl();
        $siteId = $this->settings->getSiteId();
        $token = $this->settings->getAutomationToken();
        $tenantKey = $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$token) {
            return new WP_Error(
                'ai_hub_missing_settings',
                __('AI Hub settings are incomplete. Please configure the plugin.', 'ai-hub-seo')
            );
        }

        try {
            $updates = $this->apiClient->fetchSeoUpdates(
                $baseUrl,
                $siteId,
                $token,
                [
                    'statuses' => ['pending'],
                    'limit' => 25,
                ],
                $tenantKey
            );
        } catch (GuzzleException $exception) {
            $this->settings->recordError($exception->getMessage());

            return new WP_Error('ai_hub_fetch_failed', $exception->getMessage());
        }

        if (empty($updates)) {
            $this->settings->recordSync((new \DateTimeImmutable())->format(\DATE_ATOM));

            return [
                'applied' => 0,
                'dismissed' => 0,
            ];
        }

        $results = [
            'applied' => 0,
            'dismissed' => 0,
        ];

        $acknowledgements = [];

        foreach ($updates as $update) {
            $result = $this->applyUpdate($update);
            $acknowledgements[] = $result['ack'];
            $results[$result['type']]++;
        }

        try {
            $this->apiClient->applySeoUpdates($baseUrl, $siteId, $token, $acknowledgements, $tenantKey);
        } catch (GuzzleException $exception) {
            $this->settings->recordError($exception->getMessage());

            return new WP_Error('ai_hub_apply_failed', $exception->getMessage());
        }

        $this->settings->recordSync((new \DateTimeImmutable())->format(\DATE_ATOM));

        return $results;
    }

    /**
     * Applies or dismisses a single update.
     *
     * @param array<string, mixed> $update
     *
     * @return array{ack: array<string, mixed>, type: 'applied'|'dismissed'}
     */
    private function applyUpdate(array $update): array
    {
        $draftId = isset($update['id']) ? (string) $update['id'] : null;

        if (!$draftId) {
            return [
                'ack' => [
                    'id' => $draftId,
                    'status' => 'dismissed',
                    'note' => 'Draft payload missing id.',
                ],
                'type' => 'dismissed',
            ];
        }

        try {
            $this->upsertPost($update);

            return [
                'ack' => [
                    'id' => $draftId,
                    'status' => 'applied',
                    'note' => '',
                ],
                'type' => 'applied',
            ];
        } catch (\Throwable $throwable) {
            return [
                'ack' => [
                    'id' => $draftId,
                    'status' => 'dismissed',
                    'note' => $throwable->getMessage(),
                ],
                'type' => 'dismissed',
            ];
        }
    }

    /**
     * Creates or updates the WordPress post based on the draft payload.
     *
     * @param array<string, mixed> $update
     */
    private function upsertPost(array $update): void
    {
        $postId = null;

        if (!empty($update['external_post_id'])) {
            $postId = (int) $update['external_post_id'];
        } elseif (!empty($update['post_meta_key'])) {
            $existing = $this->findPostByMeta(
                (string) $update['post_meta_key'],
                (string) $update['post_meta_value']
            );
            if ($existing) {
                $postId = $existing;
            }
        }

        $content = $update['body_html'] ?? $update['body_markdown'] ?? '';

        $postData = [
            'post_title' => $update['meta_title'] ?? $update['title'] ?? 'AI Hub Draft',
            'post_content' => $content,
            'post_status' => 'draft',
        ];

        if ($postId) {
            $postData['ID'] = $postId;
            wp_update_post($postData);
        } else {
            $postId = wp_insert_post($postData);
        }

        if (!is_wp_error($postId) && !empty($update['meta_description'])) {
            update_post_meta((int) $postId, '_yoast_wpseo_metadesc', (string) $update['meta_description']);
        }

        if (!empty($update['post_meta_key'])) {
            update_post_meta(
                (int) $postId,
                (string) $update['post_meta_key'],
                $update['post_meta_value'] ?? ''
            );
        }
    }

    private function findPostByMeta(string $metaKey, string $value): ?int
    {
        $query = new \WP_Query(
            [
                'post_type' => 'any',
                'meta_key' => $metaKey,
                'meta_value' => $value,
                'posts_per_page' => 1,
                'fields' => 'ids',
            ]
        );

        if (!empty($query->posts)) {
            return (int) $query->posts[0];
        }

        return null;
    }
}
