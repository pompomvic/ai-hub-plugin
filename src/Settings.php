<?php

declare(strict_types=1);

namespace AIHub\WordPress;

/**
 * Handles persistence and retrieval of plugin settings stored in wp_options.
 */
class Settings
{
    private const OPTION_KEY = 'ai_hub_wp_settings';

    /**
     * Returns the stored settings array.
     *
     * @return array{
     *     base_url?: string,
     *     site_id?: string,
     *     automation_token?: string,
     *     tenant_api_key?: string,
     *     sync_interval?: int,
     *     last_sync?: string,
     *     last_error?: string
     * }
     */
    public function all(): array
    {
        $value = get_option(self::OPTION_KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Updates settings by merging with existing values.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        $current = $this->all();
        $next = array_merge($current, $values);
        update_option(self::OPTION_KEY, $next, false);
    }

    public function getBaseUrl(): ?string
    {
        $settings = $this->all();

        return isset($settings['base_url']) ? (string) $settings['base_url'] : null;
    }

    public function getSiteId(): ?string
    {
        $settings = $this->all();

        return isset($settings['site_id']) ? (string) $settings['site_id'] : null;
    }

    public function getAutomationToken(): ?string
    {
        $settings = $this->all();

        return isset($settings['automation_token']) ? (string) $settings['automation_token'] : null;
    }

    public function getTenantApiKey(): ?string
    {
        $settings = $this->all();

        return isset($settings['tenant_api_key']) ? (string) $settings['tenant_api_key'] : null;
    }

    public function getSyncInterval(): int
    {
        $settings = $this->all();

        return isset($settings['sync_interval']) && is_int($settings['sync_interval'])
            ? max(5, $settings['sync_interval'])
            : 15;
    }

    public function recordSync(string $timestamp): void
    {
        $this->update(
            [
                'last_sync' => $timestamp,
                'last_error' => null,
            ]
        );
    }

    public function recordError(string $message): void
    {
        $this->update(
            [
                'last_error' => $message,
                'last_sync' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ]
        );
    }
}
