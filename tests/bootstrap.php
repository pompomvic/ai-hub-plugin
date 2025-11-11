<?php
/**
 * PHPUnit bootstrap for the AI Hub WordPress plugin.
 */

declare(strict_types=1);

define('AI_HUB_PLUGIN_DIR', dirname(__DIR__));

if (file_exists(AI_HUB_PLUGIN_DIR . '/vendor/autoload.php')) {
    require AI_HUB_PLUGIN_DIR . '/vendor/autoload.php';
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'AIHub\\WordPress\\';
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $path = AI_HUB_PLUGIN_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($path)) {
                require $path;
            }
        }
    }
);

if (!function_exists('__')) {
    function __(string $message, ?string $domain = null): string
    {
        return $message;
    }
}

if (!function_exists('get_file_data')) {
    function get_file_data(string $file, array $default_headers, string $context = ''): array
    {
        return ['Version' => 'test'];
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-' . $scheme . '-salt';
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw')
    {
        if ($show === 'version') {
            return '6.x-test';
        }

        return 'ai-hub-test';
    }
}

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string
    {
        return 'testing';
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postData)
    {
        return $postData['ID'] ?? 1001;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $postData)
    {
        return $postData['ID'] ?? 1001;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $metaKey, $metaValue): bool
    {
        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return false;
    }
}
