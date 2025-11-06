<?php

declare(strict_types=1);

namespace AIHub\WordPress\Cron;

use AIHub\WordPress\Settings;
use AIHub\WordPress\Sync\SyncService;
use WP_Error;

/**
 * Registers and runs the WP-Cron event for background sync.
 */
class Scheduler
{
    public const EVENT_HOOK = 'ai_hub_wp_sync_event';

    private Settings $settings;

    private SyncService $syncService;

    public function __construct(Settings $settings, SyncService $syncService)
    {
        $this->settings = $settings;
        $this->syncService = $syncService;
    }

    public function register(): void
    {
        add_action(
            self::EVENT_HOOK,
            function (): void {
                $result = $this->syncService->run();

                if ($result instanceof WP_Error) {
                    error_log('[AI Hub] Sync failed: ' . $result->get_error_message());
                }
            }
        );
    }

    public function schedule(): void
    {
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(time(), $this->intervalToSchedule(), self::EVENT_HOOK);
        }
    }

    public function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::EVENT_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::EVENT_HOOK);
        }
    }

    private function intervalToSchedule(): string
    {
        $minutes = $this->settings->getSyncInterval();

        if ($minutes <= 5) {
            return 'five_minutes';
        }

        if ($minutes <= 15) {
            return 'fifteen_minutes';
        }

        if ($minutes <= 30) {
            return 'thirty_minutes';
        }

        return 'hourly';
    }
}
