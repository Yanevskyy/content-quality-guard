<?php
/**
 * Checks that the links in a page still lead somewhere.
 *
 * This is the one check that cannot run on save, and the reason the nightly
 * sweep exists. Every other rule reads the content: the alt text either is
 * there or it is not, and it cannot change while nobody is editing. A link is
 * different. It was fine in March, the other end was reorganised in June, and
 * the page has not been touched since. Nothing about the content changed, and
 * yet the page is now broken.
 *
 * Three things keep this affordable on a site with thousands of links.
 *
 * Verdicts are cached by URL, not by page. A footer link repeated across four
 * hundred pages is one request, not four hundred, and the cache is shared
 * between pages so the sweep gets cheaper as it goes.
 *
 * HEAD is tried first and GET only as a fallback, because a good number of
 * servers answer HEAD with 405 while serving the page perfectly well. Treating
 * that as broken would fill the report with false alarms, which is how an
 * editor learns to ignore it.
 *
 * A timeout is reported as a warning, never as broken. A slow server is not a
 * dead one, and calling it dead in a report a public body publishes is a claim
 * we cannot support.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Analyser;

defined('ABSPATH') || exit;

final class LinkChecker
{
    /** How long a verdict is trusted before the link is tried again. */
    private const CACHE_TTL = DAY_IN_SECONDS;

    /** Seconds to wait. Beyond this the answer is "slow", not "broken". */
    private const TIMEOUT = 8;

    /** Links examined per page, so one enormous page cannot stall a sweep. */
    private const MAX_PER_PAGE = 40;

    /**
     * Checks every external link in the content.
     *
     * @return array<int,Issue>
     */
    public function check(string $html): array
    {
        $urls = $this->extractUrls($html);

        if ($urls === []) {
            return [];
        }

        $issues = [];

        foreach (array_slice($urls, 0, self::MAX_PER_PAGE) as $url) {
            $verdict = $this->verdict($url);

            if ($verdict === null) {
                continue;
            }

            $issues[] = $verdict;
        }

        return $issues;
    }

    /**
     * External http(s) links, deduplicated.
     *
     * Internal links are left alone deliberately. WordPress already keeps them
     * working through redirects, and requesting our own site from inside itself
     * during a sweep is a good way to deadlock a single worker host.
     *
     * @return array<int,string>
     */
    private function extractUrls(string $html): array
    {
        if (!preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches)) {
            return [];
        }

        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $urls = [];

        foreach ($matches[1] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (!str_starts_with(strtolower($href), 'http')) {
                continue;
            }

            $host = wp_parse_url($href, PHP_URL_HOST);

            if (!is_string($host) || $host === '' || $host === $home) {
                continue;
            }

            $urls[$href] = true;
        }

        return array_keys($urls);
    }

    /**
     * One link's verdict, from cache when it is fresh.
     */
    private function verdict(string $url): ?Issue
    {
        $key    = 'cqg_link_' . md5($url);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $this->issueFor($url, (string) $cached['state'], (int) $cached['code']);
        }

        $result = $this->probe($url);

        set_transient($key, $result, self::CACHE_TTL);

        return $this->issueFor($url, $result['state'], $result['code']);
    }

    /**
     * @return array{state:string,code:int}
     */
    private function probe(string $url): array
    {
        $args = [
            'timeout'     => self::TIMEOUT,
            'redirection' => 5,
            // Some hosts refuse anything that does not look like a browser.
            // Identifying honestly, with a contactable reason, is the polite
            // version of that: it tells an administrator seeing us in their
            // logs exactly what we are and why.
            'user-agent'  => 'ContentQualityGuard/1.0 (+link check; WordPress)',
        ];

        $response = wp_remote_head($url, $args);

        // A 405 means the server understood us and refuses the method, which
        // says nothing about whether the page exists.
        if (is_wp_error($response) || in_array(wp_remote_retrieve_response_code($response), [403, 405, 501], true)) {
            $response = wp_remote_get($url, $args + ['limit_response_size' => 2048]);
        }

        if (is_wp_error($response)) {
            $message = $response->get_error_message();

            $timedOut = stripos($message, 'timed out') !== false
                || stripos($message, 'timeout') !== false;

            return ['state' => $timedOut ? 'slow' : 'unreachable', 'code' => 0];
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 404 || $code === 410) {
            return ['state' => 'gone', 'code' => $code];
        }

        if ($code >= 500) {
            return ['state' => 'server_error', 'code' => $code];
        }

        // 401 and 403 are deliberately not failures.
        //
        // They mean "not for you", which says nothing about whether the page
        // exists. Measured against the sites this client actually links to:
        // gov.ie answers 403 to everything automated, including pages that are
        // perfectly alive. Reporting those as broken would put a false alarm on
        // the most linked domain on the site, and an editor who is wrong-footed
        // twice stops reading the report at all.
        //
        // The cost is that a dead page behind bot protection goes unnoticed.
        // That is the right side to be wrong on: a missed problem is worse than
        // a false alarm only until the false alarms make every real one
        // invisible.
        return ['state' => 'ok', 'code' => $code];
    }

    private function issueFor(string $url, string $state, int $code): ?Issue
    {
        $short = mb_strlen($url) > 70 ? mb_substr($url, 0, 70) . '...' : $url;

        return match ($state) {
            'gone' => new Issue(
                rule: 'link-broken',
                severity: Issue::SEVERITY_ERROR,
                message: sprintf(
                    /* translators: %d: HTTP status code, 404 or 410. */
                    __('Link is dead (HTTP %d).', 'content-quality-guard'),
                    $code
                ),
                context: $short,
                fix: __('The page at the other end has gone. Point the link somewhere current, or remove it. Checked once a day, so a fix shows up here within a day.', 'content-quality-guard'),
                standard: __('Link integrity', 'content-quality-guard')
            ),
            'server_error' => new Issue(
                rule: 'link-server-error',
                severity: Issue::SEVERITY_WARNING,
                message: sprintf(
                    /* translators: %d: HTTP status code. */
                    __('Link returns a server error (HTTP %d).', 'content-quality-guard'),
                    $code
                ),
                context: $short,
                fix: __('The other site is having trouble rather than having moved. Worth rechecking tomorrow before changing anything.', 'content-quality-guard'),
                standard: __('Link integrity', 'content-quality-guard')
            ),
            'unreachable' => new Issue(
                rule: 'link-unreachable',
                severity: Issue::SEVERITY_WARNING,
                message: __('Link could not be reached.', 'content-quality-guard'),
                context: $short,
                fix: __('The address may be wrong, or the site may be gone. Open it yourself before deciding.', 'content-quality-guard'),
                standard: __('Link integrity', 'content-quality-guard')
            ),
            // Slow is recorded but not reported. It is not a fault the editor
            // can fix, and a report that cries wolf gets ignored wholesale.
            default => null,
        };
    }
}
