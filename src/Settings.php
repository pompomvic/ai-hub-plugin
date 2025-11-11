<?php

declare(strict_types=1);

namespace AIHub\WordPress;

/**
 * Handles persistence and retrieval of plugin settings stored in wp_options.
 */
class Settings
{
    private const OPTION_KEY = 'ai_hub_wp_settings';
    private const SECRET_FIELDS = ['automation_token', 'tenant_api_key'];

    private ?string $cachedEncryptionKey = null;

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
     *     last_error?: string,
     *     ga4_measurement_id?: string,
     *     gtm_container_id?: string,
     *     conversion_event_name?: string,
     *     analytics_consent_cookie?: string,
     *     analytics_consent_opt_out?: string,
     *     session_replay_enabled?: bool,
     *     session_replay_project_key?: string,
     *     session_replay_host?: string,
     *     session_replay_mask_selectors?: string,
     *     feedback_enabled?: bool,
     *     feedback_widget_url?: string,
     *     feedback_project_key?: string,
     *     branding_primary_color?: string,
     *     branding_accent_color?: string,
     *     branding_logo_url?: string,
     *     tenant_label?: string,
     *     tenant_user_limit?: int,
     *     tenant_user_count?: int
     * }
     */
    public function all(): array
    {
        $value = get_option(self::OPTION_KEY, []);
        $settings = is_array($value) ? $value : [];

        foreach (self::SECRET_FIELDS as $field) {
            if (!isset($settings[$field]) || !is_string($settings[$field])) {
                continue;
            }
            $decrypted = $this->decryptSecretValue($settings[$field]);
            $settings[$field] = $decrypted ?? '';
        }

        return $settings;
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
        update_option(self::OPTION_KEY, $this->prepareForStorage($next), false);
    }

    /**
     * Prepare values for storage by encrypting sensitive fields.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function prepareForStorage(array $values): array
    {
        return $this->encryptSecrets($values);
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

    public function getGaMeasurementId(): ?string
    {
        $settings = $this->all();

        return isset($settings['ga4_measurement_id']) ? (string) $settings['ga4_measurement_id'] : null;
    }

    public function getGtmContainerId(): ?string
    {
        $settings = $this->all();

        return isset($settings['gtm_container_id']) ? (string) $settings['gtm_container_id'] : null;
    }

    public function getConversionEventName(): string
    {
        $settings = $this->all();

        return isset($settings['conversion_event_name']) && $settings['conversion_event_name']
            ? (string) $settings['conversion_event_name']
            : 'generate_lead';
    }

    public function getAnalyticsConsentCookie(): ?string
    {
        $settings = $this->all();

        return isset($settings['analytics_consent_cookie'])
            ? (string) $settings['analytics_consent_cookie']
            : null;
    }

    public function getAnalyticsConsentOptOutValue(): string
    {
        $settings = $this->all();

        return isset($settings['analytics_consent_opt_out']) && $settings['analytics_consent_opt_out'] !== ''
            ? (string) $settings['analytics_consent_opt_out']
            : 'deny';
    }

    public function getBrandPrimaryColor(): string
    {
        $settings = $this->all();

        $value = isset($settings['branding_primary_color'])
            ? (string) $settings['branding_primary_color']
            : '';

        return $this->normaliseHexColor($value) ?: '#0ABAB5';
    }

    public function getBrandAccentColor(): string
    {
        $settings = $this->all();
        $value = isset($settings['branding_accent_color'])
            ? (string) $settings['branding_accent_color']
            : '';

        return $this->normaliseHexColor($value) ?: '#FFC845';
    }

    public function getBrandLogoUrl(): ?string
    {
        $settings = $this->all();
        $value = isset($settings['branding_logo_url']) ? (string) $settings['branding_logo_url'] : '';

        return $value !== '' ? $value : null;
    }

    public function getTenantLabel(): string
    {
        $settings = $this->all();
        $value = isset($settings['tenant_label']) ? (string) $settings['tenant_label'] : '';

        return $value !== '' ? $value : 'AI Hub';
    }

    public function getTenantSeatLimit(): ?int
    {
        $settings = $this->all();

        if (!isset($settings['tenant_user_limit'])) {
            return null;
        }

        $limit = (int) $settings['tenant_user_limit'];

        return $limit > 0 ? $limit : null;
    }

    public function getTenantSeatUsage(): ?int
    {
        $settings = $this->all();

        if (!isset($settings['tenant_user_count'])) {
            return null;
        }

        $count = (int) $settings['tenant_user_count'];

        return $count >= 0 ? $count : null;
    }

    public function isSessionReplayEnabled(): bool
    {
        $settings = $this->all();

        return !empty($settings['session_replay_enabled']) && $this->getSessionReplayProjectKey() !== null;
    }

    public function getSessionReplayProjectKey(): ?string
    {
        $settings = $this->all();

        return isset($settings['session_replay_project_key'])
            ? (string) $settings['session_replay_project_key']
            : null;
    }

    public function getSessionReplayHost(): string
    {
        $settings = $this->all();

        return isset($settings['session_replay_host']) && $settings['session_replay_host']
            ? rtrim((string) $settings['session_replay_host'], '/')
            : 'https://app.openreplay.com';
    }

    /**
     * Returns CSS selectors to mask in the session replay tool.
     *
     * @return array<int, string>
     */
    public function getSessionReplayMaskSelectors(): array
    {
        $settings = $this->all();
        if (empty($settings['session_replay_mask_selectors'])) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn ($selector) => trim((string) $selector),
                    explode(',', (string) $settings['session_replay_mask_selectors'])
                )
            )
        );
    }

    public function isFeedbackWidgetEnabled(): bool
    {
        $settings = $this->all();

        return !empty($settings['feedback_enabled']) && $this->getFeedbackWidgetUrl() !== null;
    }

    public function getFeedbackWidgetUrl(): ?string
    {
        $settings = $this->all();

        return isset($settings['feedback_widget_url']) && $settings['feedback_widget_url']
            ? (string) $settings['feedback_widget_url']
            : null;
    }

    public function getFeedbackProjectKey(): ?string
    {
        $settings = $this->all();

        return isset($settings['feedback_project_key'])
            ? (string) $settings['feedback_project_key']
            : null;
    }

    public function isMatomoEnabled(): bool
    {
        $settings = $this->all();

        if (empty($settings['matomo_enabled'])) {
            return false;
        }

        return $this->getMatomoUrl() !== null && $this->getMatomoSiteId() !== null;
    }

    public function getMatomoUrl(): ?string
    {
        $settings = $this->all();

        if (empty($settings['matomo_url'])) {
            return null;
        }

        $url = trim((string) $settings['matomo_url']);

        return $url !== '' ? rtrim($url, '/') : null;
    }

    public function getMatomoSiteId(): ?string
    {
        $settings = $this->all();

        return isset($settings['matomo_site_id']) && $settings['matomo_site_id']
            ? (string) $settings['matomo_site_id']
            : null;
    }

    public function isMatomoHeatmapEnabled(): bool
    {
        $settings = $this->all();

        return $this->isMatomoEnabled() && !empty($settings['matomo_heatmap_enabled']);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function encryptSecrets(array $values): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $encrypted = $this->encryptSecretValue($values[$field]);
            if ($encrypted === null) {
                unset($values[$field]);
            } else {
                $values[$field] = $encrypted;
            }
        }

        return $values;
    }

    private function encryptSecretValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = trim((string) $value);
        if ($normalised === '') {
            return null;
        }

        $key = $this->getEncryptionKey();
        if (!$key) {
            return $normalised;
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = $this->secureRandom(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            try {
                $cipher = sodium_crypto_secretbox($normalised, $nonce, $key);
            } catch (\SodiumException $exception) {
                return $normalised;
            }

            return 'enc:v1:' . base64_encode($nonce . $cipher);
        }

        if (function_exists('openssl_encrypt')) {
            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            if ($ivLength === false) {
                return $normalised;
            }
            $iv = $this->secureRandom($ivLength);
            $tag = '';
            $cipher = openssl_encrypt(
                $normalised,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($cipher === false) {
                return $normalised;
            }

            return 'enc:v2:' . base64_encode($iv . $tag . $cipher);
        }

        return $normalised;
    }

    private function decryptSecretValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $candidate = $value;
        $attempts = 0;

        while (is_string($candidate) && str_starts_with($candidate, 'enc:') && $attempts < 4) {
            $decoded = $this->decryptEncodedSecret($candidate);
            if ($decoded === null) {
                return null;
            }

            $candidate = $decoded;
            $attempts++;
        }

        return is_string($candidate) ? $candidate : null;
    }

    private function decryptEncodedSecret(string $value): ?string
    {
        $key = $this->getEncryptionKey();
        if (!$key) {
            return null;
        }

        if (str_starts_with($value, 'enc:v1:') && function_exists('sodium_crypto_secretbox_open')) {
            $payload = base64_decode(substr($value, 7), true);
            if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }

            $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            try {
                $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            } catch (\SodiumException $exception) {
                return null;
            }

            return $plain === false ? null : $plain;
        }

        if (str_starts_with($value, 'enc:v2:') && function_exists('openssl_decrypt')) {
            $payload = base64_decode(substr($value, 7), true);
            if ($payload === false) {
                return null;
            }

            $ivLength = openssl_cipher_iv_length('aes-256-gcm');
            if ($ivLength === false || strlen($payload) <= ($ivLength + 16)) {
                return null;
            }

            $iv = substr($payload, 0, $ivLength);
            $tag = substr($payload, $ivLength, 16);
            $cipher = substr($payload, $ivLength + 16);

            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return $plain === false ? null : $plain;
        }

        return null;
    }

    private function getEncryptionKey(): ?string
    {
        if ($this->cachedEncryptionKey === '') {
            return null;
        }

        if ($this->cachedEncryptionKey !== null) {
            return $this->cachedEncryptionKey;
        }

        if (!function_exists('wp_salt')) {
            $this->cachedEncryptionKey = '';
            return null;
        }

        $material = trim((string) wp_salt('secure_auth') . '|' . wp_salt('auth'));
        if ($material === '') {
            $this->cachedEncryptionKey = '';
            return null;
        }

        if (function_exists('sodium_crypto_generichash')) {
            $this->cachedEncryptionKey = sodium_crypto_generichash(
                $material,
                '',
                defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32
            );
            return $this->cachedEncryptionKey;
        }

        $this->cachedEncryptionKey = substr(hash('sha256', $material, true), 0, 32);
        return $this->cachedEncryptionKey;
    }

    private function secureRandom(int $length): string
    {
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($length);
            } catch (\Exception $exception) {
                // Fall back to OpenSSL below.
            }
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($bytes !== false && $strong) {
                return $bytes;
            }
        }

        return str_repeat("\0", $length);
    }

    private function normaliseHexColor(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if ($trimmed[0] !== '#') {
            $trimmed = '#' . $trimmed;
        }

        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed)) {
            return '';
        }

        return strtoupper($trimmed);
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
