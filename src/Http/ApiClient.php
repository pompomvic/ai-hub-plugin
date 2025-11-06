<?php

declare(strict_types=1);

namespace AIHub\WordPress\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin wrapper around Guzzle that calls the AI Hub endpoints.
 */
class ApiClient
{
    private ClientInterface $httpClient;

    public function __construct(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Fetches SEO updates from the Hub.
     *
     * @param string $baseUrl Hub base URL.
     * @param string $siteId  WordPress site identifier.
     * @param string $token   Automation token.
     * @param array<string, mixed> $filters Additional filters (status, limit, etc.).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GuzzleException
     */
    public function fetchSeoUpdates(
        string $baseUrl,
        string $siteId,
        string $token,
        array $filters = [],
        ?string $tenantKey = null
    ): array {
        $payload = [
            'site_id' => $siteId,
            'token' => $token,
        ];

        if (isset($filters['limit'])) {
            $payload['limit'] = max(1, (int) $filters['limit']);
        }

        if (isset($filters['statuses']) && is_array($filters['statuses'])) {
            $statuses = array_values(
                array_filter(
                    array_map(static fn ($status) => (string) $status, $filters['statuses']),
                    static fn (string $status): bool => $status !== ''
                )
            );
            if ($statuses) {
                $payload['statuses'] = $statuses;
            }
        } elseif (isset($filters['status'])) {
            $payload['statuses'] = [(string) $filters['status']];
        }

        if (!isset($payload['statuses']) || empty($payload['statuses'])) {
            $payload['statuses'] = ['pending'];
        }

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($tenantKey) {
            $headers['X-AI-Hub-Tenant-Key'] = $tenantKey;
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($baseUrl, '/') . '/wordpress/seo/pull',
            [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => 15,
            ]
        );

        $json = json_decode((string) $response->getBody(), true);

        if (isset($json['updates']) && is_array($json['updates'])) {
            return $json['updates'];
        }

        return [];
    }

    /**
     * Applies SEO updates in the Hub.
     *
     * @param array<int, array<string, mixed>> $updates
     *
     * @throws GuzzleException
     */
    public function applySeoUpdates(
        string $baseUrl,
        string $siteId,
        string $token,
        array $updates,
        ?string $tenantKey = null
    ): array {
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($tenantKey) {
            $headers['X-AI-Hub-Tenant-Key'] = $tenantKey;
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($baseUrl, '/') . '/wordpress/seo/apply',
            [
                'json' => [
                    'site_id' => $siteId,
                    'token' => $token,
                    'updates' => $updates,
                ],
                'headers' => $headers,
                'timeout' => 15,
            ]
        );

        $json = json_decode((string) $response->getBody(), true);

        if (isset($json['updates']) && is_array($json['updates'])) {
            return $json['updates'];
        }

        return [];
    }

    /**
     * Retrieve the dashboard manifest for a tenant via the automation token flow.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GuzzleException
     */
    public function fetchDashboardsManifest(string $baseUrl, string $siteId, string $token, ?string $tenantKey = null): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($tenantKey) {
            $headers['X-AI-Hub-Tenant-Key'] = $tenantKey;
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($baseUrl, '/') . '/wordpress/dashboards/manifest',
            [
                'json' => [
                    'site_id' => $siteId,
                    'token' => $token,
                ],
                'headers' => $headers,
                'timeout' => 15,
            ]
        );

        $json = json_decode((string) $response->getBody(), true);

        if (isset($json['dashboards']) && is_array($json['dashboards'])) {
            return $json['dashboards'];
        }

        return [];
    }

    /**
     * Retrieve detailed dashboard metadata for a single slug.
     *
     * @throws GuzzleException
     */
    public function fetchDashboardDetail(
        string $baseUrl,
        string $siteId,
        string $token,
        string $slug,
        ?string $tenantKey = null
    ): array {
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($tenantKey) {
            $headers['X-AI-Hub-Tenant-Key'] = $tenantKey;
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($baseUrl, '/') . '/wordpress/dashboards/details',
            [
                'json' => [
                    'site_id' => $siteId,
                    'token' => $token,
                    'slug' => $slug,
                ],
                'headers' => $headers,
                'timeout' => 15,
            ]
        );

        $json = json_decode((string) $response->getBody(), true);

        if (isset($json['dashboard']) && is_array($json['dashboard'])) {
            return $json['dashboard'];
        }

        return [];
    }
}
