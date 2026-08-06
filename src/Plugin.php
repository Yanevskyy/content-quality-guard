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
    public function analyseAndStore(\WP_Post $post): array
    {
        $issues = $this->analyser()->analyse(
            (string) $post->post_content,
            (string) $post->post_title,
            $this->describedAs($post)
        );

        $stored = array_map(static fn(Issue $issue): array => $issue->toArray(), $issues);

        update_post_meta($post->ID, self::META_ISSUES, $stored);
        update_post_meta($post->ID, self::META_SUMMARY, $this->summarise($stored));

        return $stored;
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

        $issues = $this->analyseAndStore($post);

        return new \WP_REST_Response([
            'issues'  => $issues,
            'summary' => get_post_meta($post->ID, self::META_SUMMARY, true),
        ]);
    }

    public function restSpeed(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $url    = get_permalink($postId);

        if (!is_string($url) || $url === '') {
            return new \WP_REST_Response(['ok' => false, 'message' => 'No public URL for this content.'], 400);
        }

        $result = $this->pageSpeed()->measure(
            $url,
            $postId,
            (string) ($request->get_param('strategy') ?: 'mobile')
        );

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 502);
    }
}
