<?php
/**
 * @var string $screenView
 * @var string|null $activeDashboardSlug
 * @var string|null $activeDashboardLabel
 */

if (!defined('ABSPATH')) {
    exit;
}

$view = $screenView ?? 'dashboards';
$title = $view === 'dashboard' && $activeDashboardLabel
    ? sprintf(
        /* translators: %s is the dashboard title. */
        __('AIMXB · %s', 'ai-hub-seo'),
        $activeDashboardLabel
    )
    : __('AIMXB Dashboards', 'ai-hub-seo');
?>
<div class="wrap ai-hub-admin-wrap">
    <h1><?php echo esc_html($title); ?></h1>
    <p class="description">
        <?php esc_html_e('Browse live AIMXB metrics, cards, and actions without leaving WordPress.', 'ai-hub-seo'); ?>
    </p>
    <div
        id="ai-hub-admin-app"
        data-screen="<?php echo esc_attr($view); ?>"
        <?php if (!empty($activeDashboardSlug)) : ?>
            data-dashboard="<?php echo esc_attr($activeDashboardSlug); ?>"
        <?php endif; ?>
    ></div>
</div>
