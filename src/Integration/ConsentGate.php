<?php

declare(strict_types=1);

namespace AIHub\WordPress\Integration;

use AIHub\WordPress\Settings;

/**
 * Centralises consent logic for analytics-related injections.
 */
class ConsentGate
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function analyticsAllowed(): bool
    {
        return $this->allows('analytics');
    }

    public function sessionReplayAllowed(): bool
    {
        return $this->allows('session_replay');
    }

    public function feedbackAllowed(): bool
    {
        return $this->allows('feedback');
    }

    private function allows(string $scope): bool
    {
        $settings = $this->settings->all();

        $decision = $this->applyFilters("ai_hub_allow_{$scope}", null, $settings);
        if ($decision !== null) {
            return (bool) $decision;
        }

        $globalDecision = $this->applyFilters('ai_hub_allow_tracking', null, $scope, $settings);
        if ($globalDecision !== null) {
            return (bool) $globalDecision;
        }

        $cookieName = $this->settings->getAnalyticsConsentCookie();
        if (!$cookieName) {
            return true;
        }

        $value = $_COOKIE[$cookieName] ?? null; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledCookies
        if ($value === null) {
            return true;
        }

        $rejectedValue = strtolower($this->settings->getAnalyticsConsentOptOutValue());

        return strtolower((string) $value) !== $rejectedValue;
    }

    private function applyFilters(string $hook, mixed ...$args): mixed
    {
        if (!function_exists('apply_filters')) {
            return null;
        }

        return apply_filters($hook, ...$args);
    }
}
