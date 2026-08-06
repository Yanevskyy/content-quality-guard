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
        $issues = get_post_meta($post->ID, Plugin::META_ISSUES, true);
        $issues = is_array($issues) ? $issues : [];

        $speed = Plugin::instance()->pageSpeed()->lastResult($post->ID);

        echo '<div class="cqg-panel" data-post="' . (int) $post->ID . '">';

        self::renderScore($issues);
        self::renderIssues($issues);
        self::renderSpeed($speed);

        echo '</div>';
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

            echo '</li>';
        }

        echo '</ul>';

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
