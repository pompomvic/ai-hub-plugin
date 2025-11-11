<?php

declare(strict_types=1);

namespace AIHub\WordPress\Sync;

use AIHub\WordPress\Http\ApiClient;
use AIHub\WordPress\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
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
    ) {
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

        if (!$baseUrl || !$siteId || !$tenantKey) {
            throw new RuntimeException(
                __('AI Hub settings are incomplete. Please configure the tenant license key.', 'ai-hub-seo')
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

        if (!$baseUrl || !$siteId || !$tenantKey) {
            throw new RuntimeException(
                __('AI Hub settings are incomplete. Please configure the tenant license key.', 'ai-hub-seo')
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
    public function run(string $trigger = 'manual'): array|WP_Error
    {
        $baseUrl = $this->settings->getBaseUrl();
        $siteId = $this->settings->getSiteId();
        $token = $this->settings->getAutomationToken() ?: '';
        $tenantKey = $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$tenantKey) {
            return new WP_Error(
                'ai_hub_missing_license',
                __('AI Hub settings are incomplete. Please configure the tenant license key.', 'ai-hub-seo')
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
            $message = $exception->getMessage();
            if ($exception instanceof ClientException && $exception->getCode() === 401) {
                $message = __(
                    'Tenant license was rejected. Verify the Tenant API Key in AI Hub and update the plugin settings.',
                    'ai-hub-seo'
                );
            }
            $this->settings->recordError($message);
            $this->sendTelemetry(
                'sync.failed',
                'error',
                [
                    'stage' => 'pull',
                    'trigger' => $trigger,
                    'message' => $message,
                ]
            );

            return new WP_Error('ai_hub_fetch_failed', $message);
        }

        if (empty($updates)) {
            $this->settings->recordSync((new \DateTimeImmutable())->format(\DATE_ATOM));
            $this->sendTelemetry(
                'sync.completed',
                'info',
                [
                    'trigger' => $trigger,
                    'applied' => 0,
                    'dismissed' => 0,
                    'updates_fetched' => 0,
                ]
            );

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
            $message = $exception->getMessage();
            if ($exception instanceof ClientException && $exception->getCode() === 401) {
                $message = __(
                    'Tenant license was rejected while acknowledging updates. Verify the Tenant API Key in AI Hub and update the plugin settings.',
                    'ai-hub-seo'
                );
            }
            $this->settings->recordError($message);
            $this->sendTelemetry(
                'sync.failed',
                'error',
                [
                    'stage' => 'apply',
                    'trigger' => $trigger,
                    'message' => $message,
                    'acknowledged' => count($acknowledgements),
                ]
            );

            return new WP_Error('ai_hub_apply_failed', $message);
        }

        $this->settings->recordSync((new \DateTimeImmutable())->format(\DATE_ATOM));
        $this->sendTelemetry(
            'sync.completed',
            'info',
            [
                'trigger' => $trigger,
                'applied' => $results['applied'],
                'dismissed' => $results['dismissed'],
                'updates_fetched' => count($updates),
            ]
        );

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

        if (!empty($update['slug'])) {
            $postData['post_name'] = function_exists('sanitize_title')
                ? sanitize_title((string) $update['slug'])
                : (string) $update['slug'];
        }

        if (!empty($update['excerpt'])) {
            $postData['post_excerpt'] = (string) $update['excerpt'];
        } elseif (!empty($update['meta_description'])) {
            $postData['post_excerpt'] = (string) $update['meta_description'];
        }

        if ($postId) {
            $postData['ID'] = $postId;
            wp_update_post($postData);
        } else {
            $postId = wp_insert_post($postData);
        }

        if (!is_wp_error($postId)) {
            $this->applySeoMeta((int) $postId, $update);
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

    /**
     * Update SEO plugin metadata for the post.
     *
     * @param array<string, mixed> $update
     */
    private function applySeoMeta(int $postId, array $update): void
    {
        $metaTitle = $update['meta_title'] ?? $update['title'] ?? null;
        if ($metaTitle) {
            $title = (string) $metaTitle;
            update_post_meta($postId, '_yoast_wpseo_title', $title);
            update_post_meta($postId, '_aioseo_title', $title);
            update_post_meta($postId, 'rank_math_title', $title);
        }

        if (!empty($update['meta_description'])) {
            $description = (string) $update['meta_description'];
            update_post_meta($postId, '_yoast_wpseo_metadesc', $description);
            update_post_meta($postId, '_aioseo_description', $description);
            update_post_meta($postId, 'rank_math_description', $description);
        }

        $keywords = $this->extractKeywords($update);
        if ($keywords) {
            $primary = $keywords[0];
            $joined = implode(', ', $keywords);
            update_post_meta($postId, '_yoast_wpseo_focuskw', $primary);
            update_post_meta($postId, '_yoast_wpseo_focuskeywords', $joined);
            update_post_meta($postId, '_aioseo_focus_keyphrase', $primary);
            update_post_meta($postId, 'rank_math_focus_keyword', $joined);
        }
    }

    /**
     * Normalises keyword payloads coming from the Hub.
     *
     * @param array<string, mixed> $update
     *
     * @return array<int, string>
     */
    private function extractKeywords(array $update): array
    {
        $keywords = [];

        foreach (['keywords', 'meta_keywords'] as $key) {
            if (empty($update[$key])) {
                continue;
            }

            if (is_array($update[$key])) {
                $keywords = array_merge($keywords, $update[$key]);
            } else {
                $keywords = array_merge(
                    $keywords,
                    explode(',', (string) $update[$key])
                );
            }
        }

        foreach (['focus_keyword', 'focus_keyphrase', 'keyword'] as $key) {
            if (!empty($update[$key])) {
                $keywords[] = (string) $update[$key];
            }
        }

        $keywords = array_map(
            static fn ($keyword) => trim((string) $keyword),
            $keywords
        );
        $keywords = array_filter($keywords, static fn ($keyword) => $keyword !== '');

        return array_values(array_unique($keywords));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendTelemetry(string $eventType, string $severity, array $payload): void
    {
        $baseUrl = $this->settings->getBaseUrl();
        $siteId = $this->settings->getSiteId();
        $token = $this->settings->getAutomationToken();
        $tenantKey = $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$tenantKey) {
            return;
        }

        $eventPayload = array_filter(
            array_merge($this->defaultTelemetryPayload(), $payload),
            static fn ($value) => $value !== null
        );

        $event = [
            'event_type' => $eventType,
            'severity' => $severity,
            'payload' => $eventPayload,
            'occurred_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];

        try {
            $this->apiClient->sendTelemetryEvents(
                $baseUrl,
                $siteId,
                $token ?: null,
                [$event],
                $tenantKey
            );
        } catch (GuzzleException $exception) {
            // Telemetry failures should never block the sync pipeline.
        } catch (\Throwable $throwable) {
            // Swallow unexpected transport errors as well.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultTelemetryPayload(): array
    {
        return [
            'plugin_version' => defined('AI_HUB_PLUGIN_VERSION') ? AI_HUB_PLUGIN_VERSION : null,
            'php_version' => PHP_VERSION,
            'wp_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : null,
        ];
    }
}
