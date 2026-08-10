<?php
/**
 * Google PageSpeed Insights integration.
 *
 * A real third party API with the awkward properties real APIs have: it takes
 * twenty to forty seconds to answer, it is rate limited, and without a key the
 * daily quota is small enough to hit during ordinary use. All three are handled
 * explicitly rather than discovered by an editor staring at a spinner.
 *
 * Three rules this class follows, and they are the rules we apply to every
 * integration:
 *
 *   1. A slow service must not hang the page. The request has an explicit
 *      timeout and runs in the background, never during a page render.
 *   2. Running out of quota is not the same as scoring badly. They produce
 *      different, honest messages.
 *   3. A stale result is served with the date it was taken. A blank panel would
 *      read as "your site has no score", which is a different and untrue claim.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Speed;

defined('ABSPATH') || exit;

final class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public const OPTION_API_KEY = 'cqg_pagespeed_key';
    private const RESULT_META   = '_cqg_pagespeed';

    /** The API is slow by nature; anything less than this times out healthy runs. */
    private const TIMEOUT = 60;

    /**
     * Measures a URL and stores the result against a post.
     *
     * @return array{ok:bool,message:string,data:array<string,mixed>|null}
     */
    public function measure(string $url, int $postId = 0, string $strategy = 'mobile'): array
    {
        $query = [
            'url'      => $url,
            'strategy' => in_array($strategy, ['mobile', 'desktop'], true) ? $strategy : 'mobile',
            'category' => 'performance',
        ];

        $key = (string) get_option(self::OPTION_API_KEY, '');

        if ($key !== '') {
            $query['key'] = $key;
        }

        $response = wp_remote_get(
            add_query_arg($query, self::ENDPOINT),
            ['timeout' => self::TIMEOUT]
        );

        if (is_wp_error($response)) {
            return $this->failure(
                sprintf(
                    /* translators: %s: error message from the HTTP layer. */
                    __('Could not reach PageSpeed Insights: %s', 'content-quality-guard'),
                    $response->get_error_message()
                )
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        // Quota exhausted is a distinct outcome. Reporting it as a failed
        // measurement would send someone hunting for a problem with their site.
        if ($code === 429) {
            return $this->failure(
                $key === ''
                    ? __('The daily quota for anonymous requests is used up. Add an API key below to raise it.', 'content-quality-guard')
                    : __('The API key has hit its request quota for today.', 'content-quality-guard')
            );
        }

        if ($code === 400) {
            return $this->failure(__('PageSpeed could not analyse that URL. It must be publicly reachable.', 'content-quality-guard'));
        }

        if ($code !== 200) {
            return $this->failure(
                sprintf(
                    /* translators: %d: HTTP status code. */
                    __('PageSpeed Insights returned HTTP %d.', 'content-quality-guard'),
                    $code
                )
            );
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($payload) || !isset($payload['lighthouseResult'])) {
            return $this->failure(__('PageSpeed returned a response we could not read.', 'content-quality-guard'));
        }

        $result = $this->extract($payload, $query['strategy']);

        if ($postId > 0) {
            update_post_meta($postId, self::RESULT_META, $result);
        }

        return ['ok' => true, 'message' => __('Measured.', 'content-quality-guard'), 'data' => $result];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extract(array $payload, string $strategy): array
    {
        $lighthouse = $payload['lighthouseResult'];
        $audits     = $lighthouse['audits'] ?? [];

        // Layout shift is a ratio, not a duration. Dividing it by a thousand
        // like the timings produced a number in a field labelled seconds that
        // meant nothing at all, and a stored figure nobody could interpret is
        // worse than an absent one.
        $metric = static function (string $id, bool $isDuration = true) use ($audits): array {
            $audit = $audits[$id] ?? [];

            return [
                'value'   => $audit['displayValue'] ?? '',
                'seconds' => $isDuration && isset($audit['numericValue'])
                    ? round(((float) $audit['numericValue']) / 1000, 2)
                    : null,
                'ratio'   => !$isDuration && isset($audit['numericValue'])
                    ? round((float) $audit['numericValue'], 3)
                    : null,
                'score'   => isset($audit['score']) ? (int) round(((float) $audit['score']) * 100) : null,
            ];
        };

        $score = $lighthouse['categories']['performance']['score'] ?? null;

        // The opportunities are the actionable part. Without them the panel is
        // a number that tells an editor to feel bad without telling them why.
        $opportunities = [];

        foreach ($audits as $id => $audit) {
            if (($audit['details']['type'] ?? '') !== 'opportunity') {
                continue;
            }

            $saving = $audit['details']['overallSavingsMs'] ?? 0;

            if ($saving < 100) {
                continue;
            }

            $opportunities[] = [
                'id'      => $id,
                'title'   => $audit['title'] ?? $id,
                'saving'  => round(((float) $saving) / 1000, 2),
            ];
        }

        usort($opportunities, static fn(array $a, array $b): int => $b['saving'] <=> $a['saving']);

        return [
            'strategy'      => $strategy,
            'score'         => $score !== null ? (int) round(((float) $score) * 100) : null,
            'lcp'           => $metric('largest-contentful-paint'),
            'cls'           => $metric('cumulative-layout-shift', false),
            'tbt'           => $metric('total-blocking-time'),
            'fcp'           => $metric('first-contentful-paint'),
            'opportunities' => array_slice($opportunities, 0, 5),
            'measured_at'   => current_time('mysql', true),
        ];
    }

    /**
     * Last stored measurement for a post, or null when there has never been one.
     *
     * @return array<string,mixed>|null
     */
    public function lastResult(int $postId): ?array
    {
        $stored = get_post_meta($postId, self::RESULT_META, true);

        return is_array($stored) && $stored !== [] ? $stored : null;
    }

    /**
     * @return array{ok:false,message:string,data:null}
     */
    private function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'data' => null];
    }
}
