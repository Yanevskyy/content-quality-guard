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

use ClarityWeb\ContentQualityGuard\Maintenance\Sweep;
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

            <?php if (isset($_GET['swept'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: number rechecked, 2: number still never checked. */
                            esc_html__('Rechecked %1$d pages. %2$d have still never been checked.', 'content-quality-guard'),
                            (int) $_GET['swept'],
                            (int) ($_GET['remaining'] ?? 0)
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php self::renderTotals($rows); ?>

            <?php self::renderCoverage(); ?>

            <?php self::renderHistory(); ?>

            <form method="post" style="margin:12px 0">
                <?php wp_nonce_field('cqg_rescan'); ?>
                <button type="submit" name="cqg_rescan" value="1" class="button">
                    <?php esc_html_e('Recheck all content', 'content-quality-guard'); ?>
                </button>
            </form>

            <?php self::renderSweepStatus(); ?>

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

    /**
     * Says out loud how much of the site the figures above actually cover.
     *
     * Without this the totals read as the whole site. A page written before the
     * plugin was installed has no analysis at all, contributes nothing, and so
     * makes the site look cleaner than it is. The number that is comfortable to
     * omit is exactly the one worth printing.
     */
    private static function renderCoverage(): void
    {
        $never = Sweep::neverChecked();

        if ($never === 0) {
            printf(
                '<p class="description">%s</p>',
                esc_html__('Every published page has been checked at least once.', 'content-quality-guard')
            );

            return;
        }

        printf(
            '<div class="notice notice-warning inline"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %d: number of pages never analysed. */
                _n(
                    '%d published page has never been checked, so it is not counted above.',
                    '%d published pages have never been checked, so they are not counted above.',
                    $never,
                    'content-quality-guard'
                ),
                $never
            ))
        );
    }

    /**
     * Issues over time, drawn as inline SVG.
     *
     * No charting library. This is one polyline over at most ninety points; a
     * dependency for that would be another package to keep patched for the
     * life of the contract, and it would need a script exception on any site
     * with a strict content policy.
     */
    private static function renderHistory(): void
    {
        $history = Sweep::history(90);

        if (count($history) < 2) {
            printf(
                '<h2 class="title">%s</h2><p class="description">%s</p>',
                esc_html__('Issues over time', 'content-quality-guard'),
                esc_html__('The chart appears once there are at least two days of history. A point is recorded each day the scheduled recheck runs.', 'content-quality-guard')
            );

            return;
        }

        $values = array_map(static fn(array $day): int => (int) $day['total'], array_values($history));
        $dates  = array_keys($history);
        $peak   = max($values) ?: 1;
        $count  = count($values);

        $width  = 640;
        $height = 120;
        $step   = $count > 1 ? $width / ($count - 1) : $width;

        $points = [];

        foreach ($values as $index => $value) {
            $x = round($index * $step, 1);
            $y = round($height - (($value / $peak) * ($height - 10)) - 5, 1);

            $points[] = $x . ',' . $y;
        }

        $trend = Sweep::trend(90);

        printf('<h2 class="title">%s</h2>', esc_html__('Issues over time', 'content-quality-guard'));

        if ($trend !== null) {
            $change = $trend['change'];

            printf(
                '<p class="description">%s</p>',
                esc_html(sprintf(
                    $change < 0
                        /* translators: 1: number of issues resolved, 2: first date, 3: last date. */
                        ? __('%1$d fewer issues than on %2$s. Measured to %3$s.', 'content-quality-guard')
                        : ($change > 0
                            /* translators: 1: number of new issues, 2: first date, 3: last date. */
                            ? __('%1$d more issues than on %2$s. Measured to %3$s.', 'content-quality-guard')
                            /* translators: 1: unused, 2: first date, 3: last date. */
                            : __('No net change since %2$s. Measured to %3$s.', 'content-quality-guard')),
                    abs($change),
                    (string) array_key_first($history),
                    (string) array_key_last($history)
                ))
            );
        }

        printf(
            '<svg class="cqg-chart" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s" preserveAspectRatio="none">'
                . '<polyline fill="none" stroke="#2271b1" stroke-width="2" points="%4$s" />'
                . '</svg>',
            $width,
            $height,
            esc_attr(sprintf(
                /* translators: 1: earliest count, 2: latest count, 3: number of days. */
                __('Total issues went from %1$d to %2$d over %3$d recorded days.', 'content-quality-guard'),
                (int) reset($values),
                (int) end($values),
                $count
            )),
            esc_attr(implode(' ', $points))
        );

        // The chart is decoration; the table is the record. A screen reader
        // user, and anyone copying figures into a report, gets the numbers.
        echo '<details class="cqg-chart-data"><summary>'
            . esc_html__('Show the figures behind this chart', 'content-quality-guard')
            . '</summary><table class="wp-list-table widefat striped"><thead><tr>'
            . '<th scope="col">' . esc_html__('Date', 'content-quality-guard') . '</th>'
            . '<th scope="col">' . esc_html__('Must fix', 'content-quality-guard') . '</th>'
            . '<th scope="col">' . esc_html__('Should fix', 'content-quality-guard') . '</th>'
            . '<th scope="col">' . esc_html__('Could improve', 'content-quality-guard') . '</th>'
            . '<th scope="col">' . esc_html__('Pages checked', 'content-quality-guard') . '</th>'
            . '</tr></thead><tbody>';

        foreach (array_reverse($history, true) as $date => $day) {
            printf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                esc_html((string) $date),
                (int) $day['error'],
                (int) $day['warning'],
                (int) $day['notice'],
                (int) ($day['posts'] ?? 0)
            );
        }

        echo '</tbody></table></details>';
    }

    /**
     * When the scheduled recheck last ran, and a way to run a batch by hand.
     */
    private static function renderSweepStatus(): void
    {
        $last = get_option(Sweep::OPTION_LAST_SWEEP, []);
        $next = wp_next_scheduled(Sweep::HOOK);

        printf('<h2 class="title">%s</h2>', esc_html__('Scheduled rechecking', 'content-quality-guard'));

        printf(
            '<p class="description">%s</p>',
            esc_html__('Pages are rechecked daily, oldest first, in batches. Several checks depend on things outside the page, so a page that has not been edited can still develop a problem.', 'content-quality-guard')
        );

        if (is_array($last) && !empty($last['at'])) {
            $timestamp = strtotime((string) $last['at'] . ' UTC');

            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: 1: date and time, 2: number of pages rechecked. */
                    __('Last run %1$s, %2$d pages rechecked.', 'content-quality-guard'),
                    $timestamp ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp) : '',
                    (int) ($last['checked'] ?? 0)
                ))
            );
        } else {
            printf('<p>%s</p>', esc_html__('The scheduled recheck has not run yet.', 'content-quality-guard'));
        }

        if ($next !== false) {
            printf(
                '<p class="description">%s</p>',
                esc_html(sprintf(
                    /* translators: %s: date and time of the next scheduled run. */
                    __('Next run: %s', 'content-quality-guard'),
                    wp_date(get_option('date_format') . ', ' . get_option('time_format'), $next)
                ))
            );
        }

        printf(
            '<form method="post" action="%s" style="margin:12px 0">'
                . '<input type="hidden" name="action" value="cqg_sweep_now">%s'
                . '<button type="submit" class="button">%s</button></form>',
            esc_url(admin_url('admin-post.php')),
            wp_nonce_field('cqg_sweep_now', '_wpnonce', true, false),
            esc_html__('Recheck the next batch now', 'content-quality-guard')
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
