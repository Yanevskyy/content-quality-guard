<?php
/**
 * Plugin bootstrap and analysis pipeline.
 *
 * Content is analysed when it is saved, and the result is stored with the post.
 * Analysing on save rather than on view keeps the front end free of work that
 * belongs in the editor, and it means the overview screen can list problems
 * across the whole site without re-parsing every page.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard;

use ClarityWeb\ContentQualityGuard\Analyser\ContentAnalyser;
use ClarityWeb\ContentQualityGuard\Analyser\Issue;
use ClarityWeb\ContentQualityGuard\Speed\PageSpeedClient;

defined('ABSPATH') || exit;

final class Plugin
{
    public const META_ISSUES  = '_cqg_issues';
    public const META_SUMMARY = '_cqg_summary';

    /**
     * Link verdicts live in their own key.
     *
     * They are produced by the nightly sweep, not on save, because checking
     * them means waiting on other people's servers. Keeping them separate is
     * what stops an editor saving a page and watching its broken links vanish
     * from the report until the next sweep puts them back.
     */
    public const META_LINK_ISSUES = '_cqg_link_issues';

    /**
     * Rules an editor has said do not apply to this page.
     *
     * Every checker produces findings that are wrong in context: a table used
     * for layout in an embed, a heading level dictated by the theme, a link
     * whose text really is the whole point. Without a way to say so, the panel
     * shows the same wrong answer on every visit, and an editor who is
     * contradicted twice stops reading it. Dismissals are per post and per
     * rule, so silencing one thing does not silence the check everywhere.
     */
    public const META_DISMISSED = '_cqg_dismissed';

    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('save_post', [$this, 'analyseOnSave'], 20, 2);
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('admin_menu', [Admin\OverviewPage::class, 'addMenu']);
        add_action('admin_init', [Admin\OverviewPage::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'registerRoutes']);

        Maintenance\Sweep::register();
    }

    public function analyser(): ContentAnalyser
    {
        return new ContentAnalyser();
    }

    public function pageSpeed(): PageSpeedClient
    {
        return new PageSpeedClient();
    }

    /**
     * Runs on every save so the overview screen reflects reality rather than
     * whatever was true the last time somebody opened a page.
     */
    public function analyseOnSave(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId) || $post->post_status === 'trash') {
            return;
        }

        if (!in_array($post->post_type, $this->analysedPostTypes(), true)) {
            return;
        }

        $this->analyseAndStore($post);
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function analyseAndStore(\WP_Post $post, bool $checkLinks = false): array
    {
        // Blocks are rendered before analysis. Reusable blocks, dynamic blocks
        // and shortcodes are markers in post_content, so reading it raw means
        // checking the marker rather than the image, heading or table it turns
        // into. On a block-built site that is most of the page.
        $rendered = (string) $post->post_content;

        if (function_exists('do_blocks')) {
            $rendered = do_blocks($rendered);
        }

        $rendered = do_shortcode($rendered);

        $issues = $this->analyser()->analyse(
            $rendered,
            (string) $post->post_title,
            $this->describedAs($post)
        );

        $stored = array_map(static fn(Issue $issue): array => $issue->toArray(), $issues);

        update_post_meta($post->ID, self::META_ISSUES, $stored);

        if ($checkLinks) {
            $linkIssues = array_map(
                static fn(Issue $issue): array => $issue->toArray(),
                (new Analyser\LinkChecker())->check($rendered)
            );

            update_post_meta($post->ID, self::META_LINK_ISSUES, $linkIssues);
        }

        update_post_meta($post->ID, self::META_SUMMARY, $this->summarise($this->allIssues($post->ID)));

        // Stamped here rather than only in the sweep. A page analysed on save
        // has just been checked, and leaving the stamp alone would send it
        // straight back to the front of the oldest-first queue.
        update_post_meta($post->ID, Maintenance\Sweep::META_CHECKED, gmdate('Y-m-d H:i:s'));

        return $stored;
    }

    /**
     * Content issues and link issues together, which is what a reader of the
     * report cares about. They are stored apart only because they are produced
     * at different times.
     *
     * @return array<int,array<string,string>>
     */
    public function allIssues(int $postId, bool $includeDismissed = false): array
    {
        $content = get_post_meta($postId, self::META_ISSUES, true);
        $links   = get_post_meta($postId, self::META_LINK_ISSUES, true);

        $issues = array_merge(
            is_array($content) ? $content : [],
            is_array($links) ? $links : []
        );

        if ($includeDismissed) {
            return $issues;
        }

        $dismissed = $this->dismissedRules($postId);

        if ($dismissed === []) {
            return $issues;
        }

        return array_values(array_filter(
            $issues,
            static fn(array $issue): bool => !in_array($issue['rule'] ?? '', $dismissed, true)
        ));
    }

    /**
     * @return array<int,string>
     */
    public function dismissedRules(int $postId): array
    {
        $stored = get_post_meta($postId, self::META_DISMISSED, true);

        return is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : [];
    }

    public function dismissRule(int $postId, string $rule, bool $dismissed = true): void
    {
        $rules = $this->dismissedRules($postId);

        $rules = $dismissed
            ? array_values(array_unique(array_merge($rules, [$rule])))
            : array_values(array_diff($rules, [$rule]));

        update_post_meta($postId, self::META_DISMISSED, $rules);

        // The summary counts what is shown, so hiding a rule has to change the
        // number on the overview too. A panel saying "no problems" beside a
        // table saying "3 must fix" is a bug report waiting to happen.
        update_post_meta($postId, self::META_SUMMARY, $this->summarise($this->allIssues($postId)));
    }

    /**
     * Reads the description from whichever SEO plugin is present, falling back
     * to the excerpt. Sites rarely store it in one predictable place, and
     * reporting "no description" when one exists would train editors to ignore
     * the warning.
     */
    private function describedAs(\WP_Post $post): string
    {
        $candidates = [
            get_post_meta($post->ID, 'rank_math_description', true),
            get_post_meta($post->ID, '_yoast_wpseo_metadesc', true),
            get_post_meta($post->ID, '_aioseo_description', true),
            $post->post_excerpt,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,string>> $issues
     * @return array<string,int>
     */
    private function summarise(array $issues): array
    {
        $summary = ['error' => 0, 'warning' => 0, 'notice' => 0, 'total' => count($issues)];

        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? Issue::SEVERITY_NOTICE;

            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }

        return $summary;
    }

    /**
     * @return array<int,string>
     */
    public function analysedPostTypes(): array
    {
        return (array) apply_filters('cqg_post_types', ['post', 'page']);
    }

    public function registerMetaBox(): void
    {
        foreach ($this->analysedPostTypes() as $type) {
            add_meta_box(
                'cqg-panel',
                __('Content Quality', 'content-quality-guard'),
                [Admin\EditorPanel::class, 'render'],
                $type,
                'side',
                'high'
            );
        }
    }

    public function enqueue(string $hook): void
    {
        $screens = ['post.php', 'post-new.php', 'toplevel_page_content-quality'];

        if (!in_array($hook, $screens, true)) {
            return;
        }

        $version = static function (string $path): string {
            $file = CQG_DIR . $path;

            return is_readable($file) ? (string) filemtime($file) : VERSION;
        };

        wp_enqueue_style('cqg-admin', CQG_URL . 'assets/admin.css', [], $version('assets/admin.css'));
        wp_enqueue_script('cqg-admin', CQG_URL . 'assets/admin.js', [], $version('assets/admin.js'), true);

        wp_localize_script('cqg-admin', 'cqgAdmin', [
            'root'  => esc_url_raw(rest_url('content-quality/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n'  => [
                'measuring' => __('Measuring, this takes up to a minute', 'content-quality-guard'),
                'failed'    => __('Measurement failed', 'content-quality-guard'),
                'rechecked' => __('Rechecked', 'content-quality-guard'),
            ],
        ]);
    }

    public function registerRoutes(): void
    {
        register_rest_route('content-quality/v1', '/analyse/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restAnalyse'],
            'permission_callback' => static fn(\WP_REST_Request $r): bool =>
                current_user_can('edit_post', (int) $r->get_param('id')),
        ]);

        register_rest_route('content-quality/v1', '/dismiss/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restDismiss'],
            'permission_callback' => static fn(\WP_REST_Request $r): bool =>
                current_user_can('edit_post', (int) $r->get_param('id')),
        ]);

        register_rest_route('content-quality/v1', '/speed/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restSpeed'],
            'permission_callback' => static fn(\WP_REST_Request $r): bool =>
                current_user_can('edit_post', (int) $r->get_param('id')),
        ]);
    }

    public function restAnalyse(\WP_REST_Request $request): \WP_REST_Response
    {
        $post = get_post((int) $request->get_param('id'));

        if (!$post instanceof \WP_Post) {
            return new \WP_REST_Response(['error' => 'not_found'], 404);
        }

        $this->analyseAndStore($post);

        return new \WP_REST_Response([
            'issues'  => $this->allIssues($post->ID),
            'summary' => get_post_meta($post->ID, self::META_SUMMARY, true),
        ]);
    }

    public function restDismiss(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $rule   = sanitize_key((string) $request->get_param('rule'));

        if ($rule === '') {
            return new \WP_REST_Response(['error' => 'rule required'], 400);
        }

        $this->dismissRule($postId, $rule, $request->get_param('restore') ? false : true);

        return new \WP_REST_Response([
            'issues'    => $this->allIssues($postId),
            'dismissed' => $this->dismissedRules($postId),
            'summary'   => get_post_meta($postId, self::META_SUMMARY, true),
        ]);
    }

    public function restSpeed(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $url    = get_permalink($postId);

        if (!is_string($url) || $url === '') {
            return new \WP_REST_Response(['ok' => false, 'message' => 'No public URL for this content.'], 400);
        }

        // One measurement at a time per page.
        //
        // PageSpeed takes twenty to forty seconds and holds a PHP worker for
        // the whole wait. An impatient editor clicking three times occupies
        // three workers on a host that may only have a handful, and the site
        // stops answering for everyone else. The lock is short lived and self
        // clearing, so a crashed request does not block the button for ever.
        $lock = 'cqg_measuring_' . $postId;

        if (get_transient($lock)) {
            return new \WP_REST_Response([
                'ok'      => false,
                'message' => __('A measurement is already running for this page. It takes up to a minute.', 'content-quality-guard'),
                'data'    => null,
            ], 429);
        }

        set_transient($lock, 1, 2 * MINUTE_IN_SECONDS);

        try {
            $result = $this->pageSpeed()->measure(
                $url,
                $postId,
                (string) ($request->get_param('strategy') ?: 'mobile')
            );
        } finally {
            delete_transient($lock);
        }

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 502);
    }
}
