<?php
/**
 * Plugin Name: AI Hub SEO Sync
 * Description: Synchronise AI Hub SEO drafts with this WordPress site on behalf of tenants.
 * Author: AI Hub
 * Version: 0.1.1
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: ai-hub-seo
 *
 * @package AIHub\WordPress
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AI_HUB_PLUGIN_FILE', __FILE__);
define('AI_HUB_PLUGIN_DIR', plugin_dir_path(__FILE__));

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require $autoloadPath;
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'AIHub\\WordPress\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
);

require_once __DIR__ . '/src/Plugin.php';

$aiHubPlugin = new \AIHub\WordPress\Plugin();
$aiHubPlugin->boot();
