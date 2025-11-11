<?php

declare(strict_types=1);

namespace AIHub\WordPress\Integration;

use AIHub\WordPress\Http\ApiClient;
use AIHub\WordPress\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Synchronises instrumentation settings between the hub and the local plugin.
 */
class InstrumentationSync
{
    private const PULL_TRANSIENT = 'ai_hub_integration_pull_lock';

    private Settings $settings;

    private ApiClient $apiClient;

    public function __construct(Settings $settings, ?ApiClient $apiClient = null)
    {
        $this->settings = $settings;
        $this->apiClient = $apiClient ?? new ApiClient(new Client(['verify' => true]));
    }

    /**
     * Push instrumentation settings upstream after saving the options form.
     *
     * @param array<string, mixed> $settingsPayload
     */
    public function push(array $settingsPayload): void
    {
        $baseUrl = $settingsPayload['base_url'] ?? $this->settings->getBaseUrl();
        $siteId = $settingsPayload['site_id'] ?? $this->settings->getSiteId();
        $tenantKey = $settingsPayload['tenant_api_key'] ?? $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$tenantKey) {
            return;
        }

        $payload = $this->buildPayload($settingsPayload);
        if (empty($payload)) {
            return;
        }

        try {
            $this->apiClient->upsertSiteIntegration(
                (string) $baseUrl,
                (string) $siteId,
                (string) $tenantKey,
                $payload
            );
        } catch (GuzzleException $exception) {
            // Surface the exception to callers so settings pages can display notices.
            throw $exception;
        }
    }

    /**
     * Periodically refresh instrumentation data from the hub so the plugin reflects portal changes.
     */
    public function maybePull(): void
    {
        $baseUrl = $this->settings->getBaseUrl();
        $siteId = $this->settings->getSiteId();
        $tenantKey = $this->settings->getTenantApiKey();

        if (!$baseUrl || !$siteId || !$tenantKey) {
            return;
        }

        if (get_transient(self::PULL_TRANSIENT)) {
            return;
        }

        set_transient(self::PULL_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $integration = $this->apiClient->fetchSiteIntegration($baseUrl, $siteId, $tenantKey);
        } catch (GuzzleException $exception) {
            delete_transient(self::PULL_TRANSIENT);
            return;
        }

        if (empty($integration)) {
            delete_transient(self::PULL_TRANSIENT);
            return;
        }

        $local = [
            'ga4_measurement_id' => $integration['ga_measurement_id'] ?? '',
            'gtm_container_id' => $integration['gtm_container_id'] ?? '',
            'conversion_event_name' => $integration['conversion_event'] ?? 'generate_lead',
            'analytics_consent_cookie' => $integration['consent_cookie_name'] ?? '',
            'analytics_consent_opt_out' => $integration['consent_opt_out_value'] ?? 'deny',
            'session_replay_enabled' => (bool) ($integration['session_replay_enabled'] ?? false),
            'session_replay_project_key' => $integration['session_replay_project_key'] ?? '',
            'session_replay_host' => $integration['session_replay_host'] ?? 'https://app.openreplay.com',
            'session_replay_mask_selectors' => implode(
                ', ',
                array_map(
                    static fn ($value): string => (string) $value,
                    $integration['session_replay_mask_selectors'] ?? []
                )
            ),
            'feedback_enabled' => (bool) ($integration['feedback_enabled'] ?? false),
            'feedback_widget_url' => $integration['feedback_widget_url'] ?? '',
            'feedback_project_key' => $integration['feedback_project_key'] ?? '',
            'matomo_enabled' => (bool) ($integration['matomo_enabled'] ?? false),
            'matomo_site_id' => $integration['matomo_site_id'] ?? '',
            'matomo_url' => $integration['matomo_url'] ?? '',
            'matomo_heatmap_enabled' => (bool) ($integration['matomo_heatmap_enabled'] ?? false),
        ];

        $branding = is_array($integration['branding'] ?? null) ? $integration['branding'] : [];
        $tenantMeta = is_array($integration['tenant'] ?? null) ? $integration['tenant'] : [];
        $local['branding_primary_color'] = $branding['primary_color'] ?? ($integration['branding_primary_color'] ?? '');
        $local['branding_accent_color'] = $branding['accent_color'] ?? ($integration['branding_accent_color'] ?? '');
        $local['branding_logo_url'] = $branding['logo_url'] ?? ($integration['branding_logo_url'] ?? '');
        $local['tenant_label'] = $integration['tenant_label'] ?? ($tenantMeta['label'] ?? 'AI Hub');
        $seatLimit = $integration['seat_limit'] ?? ($tenantMeta['seat_limit'] ?? null);
        $seatUsage = $integration['seat_usage'] ?? ($tenantMeta['seat_usage'] ?? null);
        $local['tenant_user_limit'] = $seatLimit !== null
            ? (int) $seatLimit
            : null;
        $local['tenant_user_count'] = $seatUsage !== null
            ? (int) $seatUsage
            : null;

        $this->settings->update($local);
        delete_transient(self::PULL_TRANSIENT);
    }

    /**
     * @param array<string, mixed> $settingsPayload
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $settingsPayload): array
    {
        $payload = [
            'gaMeasurementId' => $settingsPayload['ga4_measurement_id'] ?? null,
            'gtmContainerId' => $settingsPayload['gtm_container_id'] ?? null,
            'conversionEvent' => $settingsPayload['conversion_event_name'] ?? null,
            'consentCookieName' => $settingsPayload['analytics_consent_cookie'] ?? null,
            'consentOptOutValue' => $settingsPayload['analytics_consent_opt_out'] ?? null,
            'sessionReplayEnabled' => $settingsPayload['session_replay_enabled'] ?? false,
            'sessionReplayProjectKey' => $settingsPayload['session_replay_project_key'] ?? null,
            'sessionReplayHost' => $settingsPayload['session_replay_host'] ?? null,
            'sessionReplayMaskSelectors' => $this->serialiseMaskSelectors(
                $settingsPayload['session_replay_mask_selectors'] ?? ''
            ),
            'feedbackEnabled' => $settingsPayload['feedback_enabled'] ?? false,
            'feedbackWidgetUrl' => $settingsPayload['feedback_widget_url'] ?? null,
            'feedbackProjectKey' => $settingsPayload['feedback_project_key'] ?? null,
            'matomoEnabled' => $settingsPayload['matomo_enabled'] ?? false,
            'matomoSiteId' => $settingsPayload['matomo_site_id'] ?? null,
            'matomoUrl' => $settingsPayload['matomo_url'] ?? null,
            'matomoHeatmapEnabled' => $settingsPayload['matomo_heatmap_enabled'] ?? false,
        ];

        return array_filter(
            $payload,
            static fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * @return array<int, string>
     */
    private function serialiseMaskSelectors(string $raw): array
    {
        $selectors = array_values(
            array_filter(
                array_map(
                    static fn ($selector) => trim((string) $selector),
                    preg_split('/[\n,]+/', $raw) ?: []
                )
            )
        );

        return $selectors;
    }
}
