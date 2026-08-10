<?php
/**
 * The panel an editor sees beside the content.
 *
 * Written for someone who is not a developer and did not ask for a lecture.
 * Each finding says what is wrong, shows where, and gives the fix in one line.
 * Findings are grouped by severity so the two things that actually block
 * publication are not buried under six suggestions.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Admin;

use ClarityWeb\ContentQualityGuard\Analyser\Issue;
use ClarityWeb\ContentQualityGuard\Plugin;

defined('ABSPATH') || exit;

final class EditorPanel
{
    public static function render(\WP_Post $post): void
    {
        // Content issues and link verdicts together: they are stored apart
        // because they are produced at different times, not because a reader
        // cares about the difference.
        $issues = Plugin::instance()->allIssues($post->ID);
        $issues = is_array($issues) ? $issues : [];

        $dismissed = Plugin::instance()->dismissedRules($post->ID);
        $speed     = Plugin::instance()->pageSpeed()->lastResult($post->ID);

        echo '<div class="cqg-panel" data-post="' . (int) $post->ID . '">';

        self::renderScore($issues);
        self::renderIssues($issues);
        self::renderDismissed($post->ID, $dismissed);
        self::renderSpeed($speed);

        echo '</div>';
    }

    /**
     * What has been hidden on this page, and how to bring it back.
     *
     * Listed rather than forgotten: a dismissal that cannot be found again is
     * indistinguishable from a check that silently stopped working.
     *
     * @param array<int,string> $dismissed
     */
    private static function renderDismissed(int $postId, array $dismissed): void
    {
        if ($dismissed === []) {
            return;
        }

        echo '<details class="cqg-dismissed"><summary>';

        printf(
            esc_html(
                /* translators: %d: number of hidden checks. */
                _n('%d check hidden on this page', '%d checks hidden on this page', count($dismissed), 'content-quality-guard')
            ),
            count($dismissed)
        );

        echo '</summary><ul>';

        foreach ($dismissed as $rule) {
            printf(
                '<li><code>%s</code> <button type="button" class="button-link cqg-issue__restore" data-rule="%1$s">%s</button></li>',
                esc_html($rule),
                esc_html__('Show again', 'content-quality-guard')
            );
        }

        echo '</ul></details>';
    }

    /**
     * @param array<int,array<string,string>> $issues
     */
    private static function renderScore(array $issues): void
    {
        $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];

        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? Issue::SEVERITY_NOTICE;

            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        if (array_sum($counts) === 0) {
            echo '<p class="cqg-clear">' . esc_html__('No problems found.', 'content-quality-guard') . '</p>';

            return;
        }

        echo '<ul class="cqg-counts">';

        foreach ([
            'error'   => __('must fix', 'content-quality-guard'),
            'warning' => __('should fix', 'content-quality-guard'),
            'notice'  => __('could improve', 'content-quality-guard'),
        ] as $severity => $label) {
            if ($counts[$severity] === 0) {
                continue;
            }

            printf(
                '<li class="cqg-count cqg-count--%1$s"><strong>%2$d</strong> %3$s</li>',
                esc_attr($severity),
                (int) $counts[$severity],
                esc_html($label)
            );
        }

        echo '</ul>';
    }

    /**
     * How many findings are shown before the rest are summarised.
     *
     * A page with a hundred images missing alt text produces a hundred
     * findings, all of them true and all of them the same instruction. Printing
     * every one turns a useful panel into a wall an editor scrolls past. The
     * count is never hidden; only the repetition is.
     */
    private const VISIBLE_LIMIT = 12;

    /**
     * @param array<int,array<string,string>> $issues
     */
    private static function renderIssues(array $issues): void
    {
        if ($issues === []) {
            return;
        }

        // Errors first: an editor scanning the panel should meet the blocking
        // problems before the suggestions.
        $order = [Issue::SEVERITY_ERROR => 0, Issue::SEVERITY_WARNING => 1, Issue::SEVERITY_NOTICE => 2];

        usort($issues, static function (array $a, array $b) use ($order): int {
            return ($order[$a['severity'] ?? 'notice'] ?? 3) <=> ($order[$b['severity'] ?? 'notice'] ?? 3);
        });

        $total    = count($issues);
        $overflow = [];

        if ($total > self::VISIBLE_LIMIT) {
            $hidden = array_slice($issues, self::VISIBLE_LIMIT);
            $issues = array_slice($issues, 0, self::VISIBLE_LIMIT);

            // Group what is left by rule, so the summary says what kind of
            // problem repeats rather than just how many remain.
            foreach ($hidden as $issue) {
                $rule = $issue['rule'] ?? 'other';

                $overflow[$rule] ??= ['count' => 0, 'message' => $issue['message'] ?? $rule];
                $overflow[$rule]['count']++;
            }

            arsort($overflow);
        }

        echo '<ul class="cqg-issues">';

        foreach ($issues as $issue) {
            printf(
                '<li class="cqg-issue cqg-issue--%s">',
                esc_attr($issue['severity'] ?? 'notice')
            );

            printf('<p class="cqg-issue__message">%s</p>', esc_html($issue['message'] ?? ''));

            if (!empty($issue['context'])) {
                printf('<p class="cqg-issue__context"><code>%s</code></p>', esc_html($issue['context']));
            }

            if (!empty($issue['fix'])) {
                printf('<p class="cqg-issue__fix">%s</p>', esc_html($issue['fix']));
            }

            if (!empty($issue['standard'])) {
                printf('<p class="cqg-issue__standard">%s</p>', esc_html($issue['standard']));
            }

            // Every checker is wrong sometimes: a table used for layout inside
            // an embed, a heading level the theme dictates. Without a way to
            // say so, the same wrong answer appears on every visit and the
            // panel stops being read at all.
            if (!empty($issue['rule'])) {
                printf(
                    '<button type="button" class="button-link cqg-issue__dismiss" data-rule="%s">%s</button>',
                    esc_attr($issue['rule']),
                    esc_html__('Not relevant here', 'content-quality-guard')
                );
            }

            echo '</li>';
        }

        echo '</ul>';

        if ($overflow !== []) {
            echo '<div class="cqg-overflow">';

            printf(
                '<p class="cqg-overflow__title">%s</p>',
                esc_html(sprintf(
                    /* translators: 1: number of further findings, 2: total findings. */
                    _n(
                        '%1$d more finding, %2$d in total:',
                        '%1$d more findings, %2$d in total:',
                        $total - self::VISIBLE_LIMIT,
                        'content-quality-guard'
                    ),
                    $total - self::VISIBLE_LIMIT,
                    $total
                ))
            );

            echo '<ul class="cqg-overflow__list">';

            foreach ($overflow as $summary) {
                printf(
                    '<li><strong>%d</strong> %s</li>',
                    (int) $summary['count'],
                    esc_html((string) $summary['message'])
                );
            }

            echo '</ul></div>';
        }

        printf(
            '<p><button type="button" class="button cqg-recheck">%s</button></p>',
            esc_html__('Check again', 'content-quality-guard')
        );
    }

    /**
     * @param array<string,mixed>|null $speed
     */
    private static function renderSpeed(?array $speed): void
    {
        echo '<div class="cqg-speed">';
        echo '<h4>' . esc_html__('Page speed', 'content-quality-guard') . '</h4>';

        if ($speed === null) {
            echo '<p class="cqg-issue__fix">'
                . esc_html__('Not measured yet. Measurement is done by Google against the live page, so the page has to be published and publicly reachable.', 'content-quality-guard')
                . '</p>';
        } else {
            $score = $speed['score'];

            printf(
                '<p class="cqg-speed__score cqg-speed__score--%1$s">%2$s <span>/100</span></p>',
                esc_attr($score === null ? 'unknown' : ($score >= 90 ? 'good' : ($score >= 50 ? 'fair' : 'poor'))),
                esc_html($score === null ? '?' : (string) $score)
            );

            echo '<ul class="cqg-metrics">';

            foreach ([
                'lcp' => __('Largest paint', 'content-quality-guard'),
                'cls' => __('Layout shift', 'content-quality-guard'),
                'tbt' => __('Blocking time', 'content-quality-guard'),
            ] as $key => $label) {
                $metric = $speed[$key] ?? [];

                if (empty($metric['value'])) {
                    continue;
                }

                printf(
                    '<li><span>%s</span><strong>%s</strong></li>',
                    esc_html($label),
                    esc_html((string) $metric['value'])
                );
            }

            echo '</ul>';

            if (!empty($speed['opportunities'])) {
                echo '<p class="cqg-issue__fix">' . esc_html__('Biggest wins:', 'content-quality-guard') . '</p><ul class="cqg-opportunities">';

                foreach ($speed['opportunities'] as $opportunity) {
                    printf(
                        '<li>%s <em>%ss</em></li>',
                        esc_html((string) $opportunity['title']),
                        esc_html((string) $opportunity['saving'])
                    );
                }

                echo '</ul>';
            }

            if (!empty($speed['measured_at'])) {
                $timestamp = strtotime((string) $speed['measured_at'] . ' UTC');

                printf(
                    '<p class="cqg-issue__standard">%s</p>',
                    esc_html(sprintf(
                        /* translators: %s: date and time of the measurement. */
                        __('Measured %s', 'content-quality-guard'),
                        $timestamp ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $timestamp) : ''
                    ))
                );
            }
        }

        printf(
            '<p><button type="button" class="button cqg-measure">%s</button></p>',
            esc_html__('Measure speed', 'content-quality-guard')
        );

        echo '<p class="cqg-status" role="status" aria-live="polite"></p>';
        echo '</div>';
    }
}
