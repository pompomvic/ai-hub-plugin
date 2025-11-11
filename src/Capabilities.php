<?php

declare(strict_types=1);

namespace AIHub\WordPress;

/**
 * Defines and manages custom capabilities introduced by the plugin.
 */
class Capabilities
{
    public const ACCESS = 'ai_hub_access';

    /**
     * Ensure core roles (currently Administrator) receive the default capabilities.
     */
    public static function grantDefaultCapabilities(): void
    {
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::ACCESS)) {
            $admin->add_cap(self::ACCESS);
        }
    }

    /**
     * @return int Number of users currently granted access.
     */
    public static function countUsersWithAccess(): int
    {
        $query = new \WP_User_Query(
            [
                'capability' => self::ACCESS,
                'fields' => 'ID',
                'count_total' => true,
            ]
        );

        if (method_exists($query, 'get_total')) {
            return (int) $query->get_total();
        }

        return is_array($query->results ?? null) ? count($query->results) : 0;
    }
}
