<?php
/**
 * Scheduled re-checking and the history that comes out of it.
 *
 * Analysis used to happen only on save, which leaves two blind spots.
 *
 * A page written before the plugin was installed is never checked, and the
 * overview screen quietly reports on a subset while looking like it reports on
 * everything. That is the worse of the two, because the number on the screen
 * is wrong in the reassuring direction.
 *
 * The other is staleness. Several checks depend on the world outside the post:
 * a link that worked in March returns 404 in July, and the post has not
 * changed, so nothing re-runs. On a maintenance contract that is precisely the
 * problem worth catching.
 *
 * The sweep therefore works oldest first, in batches. A site of five thousand
 * pages is re-checked over days rather than in one request that times out, and
 * the batch size is filterable for sites where that pace is wrong.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Maintenance;

use ClarityWeb\ContentQualityGuard\Plugin;

defined('ABSPATH') || exit;

final class Sweep
{
    public const HOOK = 'cqg_sweep';

    /** Per post: when it was last analysed. Drives oldest-first ordering. */
    public const META_CHECKED = '_cqg_checked_at';

    /** Site wide: one entry per day, kept for a year. */
    public const OPTION_HISTORY = 'cqg_history';

    public const OPTION_LAST_SWEEP = 'cqg_last_sweep';

    /**
     * How many posts one run re-checks.
     *
     * Analysis is DOM parsing, not a network call, so this is bounded by CPU
     * rather than by anything remote. Fifty is comfortably inside a cron
     * request on modest hosting and still gets round a thousand page site in
     * under three weeks.
     */
    private const BATCH = 50;

    /** A year of daily points. Longer than any reporting period we serve. */
    private const HISTORY_DAYS = 365;

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('admin_post_cqg_sweep_now', [self::class, 'handleRunNow']);

        // Registered on init rather than on activation alone, so a plugin
        // updated by copying files still ends up scheduled.
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        while ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
        }
    }

    /**
     * Re-checks a batch and records where the whole site stands afterwards.
     *
     * @return array{checked:int,never_checked_before:int,remaining:int}
     */
    public static function run(): array
    {
        $batch = (int) apply_filters('cqg_sweep_batch', self::BATCH);
        $posts = self::oldestFirst($batch);

        $checked = 0;
        $first   = 0;

        foreach ($posts as $post) {
            if (get_post_meta($post->ID, self::META_CHECKED, true) === '') {
                $first++;
            }

            // Links are checked here and nowhere else. This is the job that
            // catches a page going stale without anybody editing it.
            Plugin::instance()->analyseAndStore($post, true);
            update_post_meta($post->ID, self::META_CHECKED, gmdate('Y-m-d H:i:s'));

            $checked++;
        }

        $totals = self::siteTotals();

        self::recordHistory($totals);

        update_option(self::OPTION_LAST_SWEEP, [
            'at'      => gmdate('Y-m-d H:i:s'),
            'checked' => $checked,
            'first'   => $first,
        ], false);

        return [
            'checked'              => $checked,
            'never_checked_before' => $first,
            'remaining'            => self::neverChecked(),
        ];
    }

    /**
     * Posts due for a re-check, never-checked ones first.
     *
     * The NOT EXISTS clause is what puts pages that predate the plugin at the
     * front of the queue. Without it they sort last for ever, because a missing
     * meta value orders after every real date.
     *
     * @return array<int,\WP_Post>
     */
    private static function oldestFirst(int $batch): array
    {
        $never = get_posts([
            'post_type'      => Plugin::instance()->analysedPostTypes(),
            'post_status'    => 'publish',
            'posts_per_page' => $batch,
            'meta_query'     => [
                ['key' => self::META_CHECKED, 'compare' => 'NOT EXISTS'],
            ],
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        if (count($never) >= $batch) {
            return $never;
        }

        $stale = get_posts([
            'post_type'      => Plugin::instance()->analysedPostTypes(),
            'post_status'    => 'publish',
            'posts_per_page' => $batch - count($never),
            'meta_key'       => self::META_CHECKED,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ]);

        return array_merge($never, $stale);
    }

    /**
     * How many published posts have never been analysed.
     *
     * Shown on the overview so the figures there carry their own caveat. A
     * screen that says "12 errors" while forty pages have never been looked at
     * is telling a comfortable half truth.
     */
    public static function neverChecked(): int
    {
        $query = new \WP_Query([
            'post_type'      => Plugin::instance()->analysedPostTypes(),
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [
                ['key' => self::META_CHECKED, 'compare' => 'NOT EXISTS'],
            ],
        ]);

        return (int) $query->found_posts;
    }

    /**
     * Current issue counts across every analysed post.
     *
     * @return array{error:int,warning:int,notice:int,total:int,posts:int,clean:int}
     */
    public static function siteTotals(): array
    {
        $totals = ['error' => 0, 'warning' => 0, 'notice' => 0, 'total' => 0, 'posts' => 0, 'clean' => 0];

        $posts = get_posts([
            'post_type'      => Plugin::instance()->analysedPostTypes(),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($posts as $postId) {
            $summary = get_post_meta($postId, Plugin::META_SUMMARY, true);

            if (!is_array($summary)) {
                continue;
            }

            $totals['posts']++;

            foreach (['error', 'warning', 'notice', 'total'] as $key) {
                $totals[$key] += (int) ($summary[$key] ?? 0);
            }

            if ((int) ($summary['total'] ?? 0) === 0) {
                $totals['clean']++;
            }
        }

        return $totals;
    }

    /**
     * Appends today's totals to the series, replacing today if it already ran.
     *
     * One point per day, not one per sweep. Two runs in an afternoon are not
     * two data points; they are the same day measured twice, and keeping both
     * would put a meaningless step in the chart.
     *
     * @param array{error:int,warning:int,notice:int,total:int,posts:int,clean:int} $totals
     */
    public static function recordHistory(array $totals): void
    {
        $history = get_option(self::OPTION_HISTORY, []);
        $history = is_array($history) ? $history : [];
        $today   = gmdate('Y-m-d');

        $history[$today] = [
            'error'   => $totals['error'],
            'warning' => $totals['warning'],
            'notice'  => $totals['notice'],
            'total'   => $totals['total'],
            'posts'   => $totals['posts'],
            'clean'   => $totals['clean'],
        ];

        ksort($history);

        if (count($history) > self::HISTORY_DAYS) {
            $history = array_slice($history, -self::HISTORY_DAYS, null, true);
        }

        update_option(self::OPTION_HISTORY, $history, false);
    }

    /**
     * The recorded series, oldest first.
     *
     * @return array<string,array<string,int>>
     */
    public static function history(int $days = 90): array
    {
        $history = get_option(self::OPTION_HISTORY, []);
        $history = is_array($history) ? $history : [];

        ksort($history);

        return $days > 0 ? array_slice($history, -$days, null, true) : $history;
    }

    /**
     * The change between the first and last recorded day.
     *
     * Returns null rather than zero when there is only one point. One
     * measurement is not a trend, and showing "0% change" for it would claim
     * a comparison that was never made.
     *
     * @return array{from:array<string,int>,to:array<string,int>,change:int}|null
     */
    public static function trend(int $days = 90): ?array
    {
        $history = self::history($days);

        if (count($history) < 2) {
            return null;
        }

        $first = reset($history);
        $last  = end($history);

        return [
            'from'   => $first,
            'to'     => $last,
            'change' => (int) $last['total'] - (int) $first['total'],
        ];
    }

    public static function handleRunNow(): void
    {
        if (!current_user_can('edit_others_posts')) {
            wp_die(esc_html__('You do not have permission to do that.', 'content-quality-guard'));
        }

        check_admin_referer('cqg_sweep_now');

        $result = self::run();

        wp_safe_redirect(add_query_arg(
            ['swept' => $result['checked'], 'remaining' => $result['remaining']],
            admin_url('admin.php?page=content-quality')
        ));
        exit;
    }
}
