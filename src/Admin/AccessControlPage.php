<?php

declare(strict_types=1);

namespace AIHub\WordPress\Admin;

use AIHub\WordPress\Capabilities;
use AIHub\WordPress\Settings;
use WP_Error;
use WP_User;

/**
 * Allows administrators to grant or revoke AI Hub access per WordPress user.
 */
class AccessControlPage
{
    private Settings $settings;

    private ?string $message = null;

    private string $messageClass = 'notice notice-info';

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to manage AI Hub access.', 'ai-hub-seo'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $result = $this->handleSubmission();
            if ($result instanceof WP_Error) {
                $this->message = $result->get_error_message();
                $this->messageClass = 'notice notice-error';
            } elseif ($result === true) {
                $this->message = __('AI Hub access updated successfully.', 'ai-hub-seo');
                $this->messageClass = 'notice notice-success';
            }
        }

        $seatLimit = $this->settings->getTenantSeatLimit();
        $hubSeatUsage = $this->settings->getTenantSeatUsage();
        $users = $this->buildUserRows();
        $assignedCount = array_reduce(
            $users,
            static fn (int $carry, array $user): int => $carry + ($user['has_access'] ? 1 : 0),
            0
        );

        $message = $this->message;
        $messageClass = $this->messageClass;

        include __DIR__ . '/templates/access-control-page.php';
    }

    /**
     * Process POST submissions and persist capability assignments.
     *
     * @return true|WP_Error
     */
    private function handleSubmission()
    {
        check_admin_referer('ai_hub_save_access', 'ai_hub_access_nonce');

        $raw = $_POST['ai_hub_allowed_users'] ?? []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $allowed = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn ($value): int => absint($value),
                        wp_unslash($raw)
                    )
                )
            )
        );

        $seatLimit = $this->settings->getTenantSeatLimit();
        if ($seatLimit !== null && count($allowed) > $seatLimit) {
            return new WP_Error(
                'ai_hub_seat_limit',
                sprintf(
                    /* translators: %d is the seat limit. */
                    __('You can only assign AI Hub access to %d users based on the tenant plan.', 'ai-hub-seo'),
                    $seatLimit
                )
            );
        }

        if (!$this->hasAdministratorSelected($allowed)) {
            return new WP_Error(
                'ai_hub_admin_required',
                __('Select at least one administrator so someone can continue managing the plugin.', 'ai-hub-seo')
            );
        }

        $this->persistAssignments($allowed);

        return true;
    }

    /**
     * @return array<int, array{id:int,name:string,email:string,roles:array<int, string>,has_access:bool,is_admin:bool}>
     */
    private function buildUserRows(): array
    {
        global $wp_roles;

        $roleLabels = [];
        if (isset($wp_roles) && $wp_roles instanceof \WP_Roles) {
            foreach ($wp_roles->roles as $key => $role) {
                $roleLabels[$key] = translate_user_role($role['name']);
            }
        }

        $rows = [];
        $users = get_users(
            [
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => 'all',
            ]
        );

        foreach ($users as $user) {
            if (!$user instanceof WP_User) {
                continue;
            }

            $roles = [];
            foreach ($user->roles as $role) {
                $roles[] = $roleLabels[$role] ?? ucwords(str_replace('_', ' ', $role));
            }

            $rows[] = [
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
                'roles' => $roles,
                'has_access' => user_can($user->ID, Capabilities::ACCESS),
                'is_admin' => user_can($user->ID, 'manage_options'),
            ];
        }

        return $rows;
    }

    private function hasAdministratorSelected(array $userIds): bool
    {
        foreach ($userIds as $userId) {
            $user = get_user_by('id', (int) $userId);
            if ($user && user_can($user, 'manage_options')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist capability assignments for the provided user IDs.
     *
     * @param array<int, int> $allowedUserIds
     */
    private function persistAssignments(array $allowedUserIds): void
    {
        $map = array_fill_keys($allowedUserIds, true);
        $userIds = get_users(
            [
                'fields' => 'ids',
            ]
        );

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $user = get_user_by('id', $userId);
            if (!$user instanceof WP_User) {
                continue;
            }

            if (isset($map[$userId])) {
                $user->add_cap(Capabilities::ACCESS);
            } else {
                $user->remove_cap(Capabilities::ACCESS);
            }
        }
    }
}
