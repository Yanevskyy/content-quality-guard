<?php
/**
 * Site-wide overview.
 *
 * The editor panel fixes one page at a time. This screen answers the question a
 * content manager actually has: where are the problems across the whole site,
 * and which pages should someone open first.
 *
 * Sorted by blocking problems, because a page with two missing alt attributes
 * matters more than a page with six suggestions.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Admin;

use ClarityWeb\ContentQualityGuard\Plugin;
use ClarityWeb\ContentQualityGuard\Speed\PageSpeedClient;

defined('ABSPATH') || exit;

final class OverviewPage
{
    public const SLUG = 'content-quality';

    public static function addMenu(): void
    {
        add_menu_page(
            __('Content Quality', 'content-quality-guard'),
            __('Content Quality', 'content-quality-guard'),
            'edit_others_posts',
            self::SLUG,
            [self::class, 'render'],
            'dashicons-universal-access-alt',
            27
        );
    }

    public static function registerSettings(): void
    {
        register_setting(self::SLUG, PageSpeedClient::OPTION_API_KEY, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to open this page.', 'content-quality-guard'));
        }

        if (isset($_POST['cqg_rescan']) && check_admin_referer('cqg_rescan')) {
            $scanned = self::rescanAll();

            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %d: number of items scanned. */
                    __('Rechecked %d items.', 'content-quality-guard'),
                    $scanned
                ))
            );
        }

        $rows = self::collect();

        ?>
        <div class="wrap cqg-overview">
            <h1><?php esc_html_e('Content Quality', 'content-quality-guard'); ?></h1>

            <?php self::renderTotals($rows); ?>

            <form method="post" style="margin:12px 0">
                <?php wp_nonce_field('cqg_rescan'); ?>
                <button type="submit" name="cqg_rescan" value="1" class="button">
                    <?php esc_html_e('Recheck all content', 'content-quality-guard'); ?>
                </button>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Page', 'content-quality-guard'); ?></th>
                        <th scope="col" style="width:110px"><?php esc_html_e('Must fix', 'content-quality-guard'); ?></th>
                        <th scope="col" style="width:110px"><?php esc_html_e('Should fix', 'content-quality-guard'); ?></th>
                        <th scope="col" style="width:120px"><?php esc_html_e('Could improve', 'content-quality-guard'); ?></th>
                        <th scope="col" style="width:110px"><?php esc_html_e('Speed', 'content-quality-guard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="5"><?php esc_html_e('Nothing analysed yet. Use "Recheck all content".', 'content-quality-guard'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($row['id']) ?: ''); ?>">
                                    <strong><?php echo esc_html($row['title']); ?></strong>
                                </a>
                            </td>
                            <td><?php echo self::cell($row['error'], 'error'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><?php echo self::cell($row['warning'], 'warning'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><?php echo self::cell($row['notice'], 'notice'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td>
                                <?php
                                echo $row['speed'] === null
                                    ? '<span class="cqg-dash">-</span>'
                                    : esc_html((string) $row['speed']);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('PageSpeed Insights', 'content-quality-guard'); ?></h2>
            <p class="description">
                <?php esc_html_e('Measurement works without a key, but Google allows only a small number of anonymous requests per day. A free key raises that limit.', 'content-quality-guard'); ?>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields(self::SLUG); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="cqg-key"><?php esc_html_e('API key', 'content-quality-guard'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="cqg-key" class="regular-text"
                                   name="<?php echo esc_attr(PageSpeedClient::OPTION_API_KEY); ?>"
                                   value="<?php echo esc_attr((string) get_option(PageSpeedClient::OPTION_API_KEY, '')); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save key', 'content-quality-guard')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function renderTotals(array $rows): void
    {
        $totals = ['error' => 0, 'warning' => 0, 'notice' => 0];

        foreach ($rows as $row) {
            $totals['error']   += (int) $row['error'];
            $totals['warning'] += (int) $row['warning'];
            $totals['notice']  += (int) $row['notice'];
        }

        printf(
            '<p class="cqg-totals">%s</p>',
            esc_html(sprintf(
                /* translators: 1: blocking issues, 2: warnings, 3: suggestions, 4: pages. */
                __('%1$d must fix, %2$d should fix, %3$d could improve, across %4$d pages.', 'content-quality-guard'),
                $totals['error'],
                $totals['warning'],
                $totals['notice'],
                count($rows)
            ))
        );
    }

    private static function cell(int $count, string $severity): string
    {
        if ($count === 0) {
            return '<span class="cqg-dash">-</span>';
        }

        return sprintf(
            '<span class="cqg-badge cqg-badge--%s">%d</span>',
            esc_attr($severity),
            $count
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function collect(): array
    {
        $posts = get_posts([
            'post_type'      => Plugin::instance()->analysedPostTypes(),
            'post_status'    => ['publish', 'draft', 'pending'],
            'posts_per_page' => 200,
        ]);

        $speed = Plugin::instance()->pageSpeed();
        $rows  = [];

        foreach ($posts as $post) {
            $summary = get_post_meta($post->ID, Plugin::META_SUMMARY, true);

            if (!is_array($summary)) {
                continue;
            }

            $measurement = $speed->lastResult($post->ID);

            $rows[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title !== '' ? $post->post_title : __('(no title)', 'content-quality-guard'),
                'error'   => (int) ($summary['error'] ?? 0),
                'warning' => (int) ($summary['warning'] ?? 0),
                'notice'  => (int) ($summary['notice'] ?? 0),
                'speed'   => $measurement['score'] ?? null,
            ];
        }

        // Worst first. A list sorted by title makes the manager read all of it
        // to find the two pages that matter.
        usort($rows, static function (array $a, array $b): int {
            return [$b['error'], $b['warning'], $b['notice']] <=> [$a['error'], $a['warning'], $a['notice']];
        });

        return $rows;
    }

    private static function rescanAll(): int
    {
        $posts = get_posts([
            'post_type'      => Plugin::instance()->analysedPostTypes(),
            'post_status'    => ['publish', 'draft', 'pending'],
            'posts_per_page' => 200,
        ]);

        foreach ($posts as $post) {
            Plugin::instance()->analyseAndStore($post);
        }

        return count($posts);
    }
}
