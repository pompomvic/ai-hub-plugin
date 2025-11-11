<?php
/**
 * @var int|null $seatLimit
 * @var int|null $hubSeatUsage
 * @var array<int, array{id:int,name:string,email:string,roles:array<int,string>,has_access:bool,is_admin:bool}> $users
 * @var int $assignedCount
 * @var string|null $message
 * @var string $messageClass
 */

if (!defined('ABSPATH')) {
    exit;
}

$seatsLabel = $seatLimit !== null
    ? sprintf(
        /* translators: 1: assigned count, 2: seat limit */
        _n(
            '%1$d of %2$d seat used',
            '%1$d of %2$d seats used',
            (int) $seatLimit,
            'ai-hub-seo'
        ),
        (int) $assignedCount,
        (int) $seatLimit
    )
    : sprintf(
        /* translators: %d is the number of assigned users. */
        _n('%d user has access', '%d users have access', (int) $assignedCount, 'ai-hub-seo'),
        (int) $assignedCount
    );
?>
<div class="wrap ai-hub-admin-wrap">
    <h1><?php esc_html_e('AI Hub Access Control', 'ai-hub-seo'); ?></h1>
    <p class="description">
        <?php esc_html_e('Choose who can open the AI Hub menu, dashboards, and actions inside WordPress.', 'ai-hub-seo'); ?>
    </p>

    <?php if (!empty($message)) : ?>
        <div class="<?php echo esc_attr($messageClass); ?>">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <div class="ai-hub-seat-summary card">
        <div>
            <strong><?php echo esc_html($seatsLabel); ?></strong>
            <?php if ($seatLimit !== null) : ?>
                <p class="description">
                    <?php esc_html_e('Seat usage is enforced per tenant subscription. Deselect someone before adding another user if you are at the limit.', 'ai-hub-seo'); ?>
                </p>
            <?php endif; ?>
        </div>
        <ul>
            <?php if ($seatLimit !== null) : ?>
                <li>
                    <span><?php esc_html_e('Plan limit', 'ai-hub-seo'); ?></span>
                    <strong><?php echo esc_html((string) $seatLimit); ?></strong>
                </li>
            <?php endif; ?>
            <li>
                <span><?php esc_html_e('Assigned in WordPress', 'ai-hub-seo'); ?></span>
                <strong><?php echo esc_html((string) $assignedCount); ?></strong>
            </li>
            <?php if ($hubSeatUsage !== null) : ?>
                <li>
                    <span><?php esc_html_e('Hub usage (reported)', 'ai-hub-seo'); ?></span>
                    <strong><?php echo esc_html((string) $hubSeatUsage); ?></strong>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <form method="post" class="ai-hub-access-form">
        <?php wp_nonce_field('ai_hub_save_access', 'ai_hub_access_nonce'); ?>
        <table class="widefat striped ai-hub-users-table">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb"><?php esc_html_e('Access', 'ai-hub-seo'); ?></th>
                    <th scope="col"><?php esc_html_e('User', 'ai-hub-seo'); ?></th>
                    <th scope="col"><?php esc_html_e('Roles', 'ai-hub-seo'); ?></th>
                    <th scope="col"><?php esc_html_e('Email', 'ai-hub-seo'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)) : ?>
                    <tr>
                        <td colspan="4"><?php esc_html_e('No users were found on this site.', 'ai-hub-seo'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($users as $user) : ?>
                        <tr class="<?php echo $user['has_access'] ? 'ai-hub-row-active' : ''; ?>">
                            <th scope="row" class="check-column">
                                <input
                                    type="checkbox"
                                    name="ai_hub_allowed_users[]"
                                    value="<?php echo esc_attr((string) $user['id']); ?>"
                                    <?php checked($user['has_access']); ?>
                                />
                            </th>
                            <td>
                                <strong><?php echo esc_html($user['name']); ?></strong>
                                <?php if ($user['is_admin']) : ?>
                                    <span class="ai-hub-role-chip admin"><?php esc_html_e('Administrator', 'ai-hub-seo'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if (!empty($user['roles'])) {
                                    echo esc_html(implode(', ', $user['roles']));
                                } else {
                                    esc_html_e('—', 'ai-hub-seo');
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($user['email'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="description">
            <?php
            esc_html_e(
                'At least one administrator must remain selected so they can manage settings, syncs, and other users.',
                'ai-hub-seo'
            );
            ?>
        </p>
        <?php submit_button(__('Save Access', 'ai-hub-seo')); ?>
    </form>
</div>
