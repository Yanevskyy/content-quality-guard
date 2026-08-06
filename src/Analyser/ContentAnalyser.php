<?php
/**
 * Checks published content for accessibility and search problems.
 *
 * Parses the content into a DOM rather than matching patterns with regular
 * expressions. That matters more than it sounds: a regular expression looking
 * for an image without alt text breaks on the first attribute containing an
 * angle bracket, and it cannot tell alt="" (deliberately empty, correct for a
 * decorative image) from a missing attribute. A parsed tree gives real
 * structure, so heading order, table markup and link context can be judged
 * properly. The difference is between a tool that nags at the wrong moment and
 * a tool an editor trusts.
 *
 * Rules map to the tender's own words: "Accessibility optimisation of supplied
 * content (Alt tags for images, labels for links, etc.)" and "search engine
 * optimisation of supplied content".
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Analyser;

defined('ABSPATH') || exit;

final class ContentAnalyser
{
    /**
     * Link text that tells a screen reader user nothing when read out of
     * context, which is how links are commonly navigated.
     */
    private const MEANINGLESS_LINK_TEXT = [
        'click here', 'here', 'read more', 'more', 'link', 'this', 'this link',
        'click', 'download', 'learn more', 'find out more', 'continue',
        'see more', 'go', 'page', 'website', 'info',
    ];

    /** Recommended bounds for a page title in search results. */
    private const TITLE_MIN = 25;
    private const TITLE_MAX = 60;

    /** Recommended bounds for a meta description. */
    private const DESCRIPTION_MIN = 70;
    private const DESCRIPTION_MAX = 160;

    /**
     * @return array<int,Issue>
     */
    public function analyse(string $html, string $title = '', string $description = ''): array
    {
        $issues = [];

        $document = $this->parse($html);

        if ($document !== null) {
            $issues = array_merge(
                $issues,
                $this->checkParseIntegrity(),
                $this->checkImages($document),
                $this->checkLinks($document),
                $this->checkHeadings($document),
                $this->checkTables($document),
                $this->checkIframes($document),
                $this->checkMedia($document),
                $this->checkVectors($document),
                $this->checkForms($document),
            );
        }

        $issues = array_merge(
            $issues,
            $this->checkTitle($title),
            $this->checkDescription($description),
        );

        return $issues;
    }

    /** Set when the parser could not build the whole tree. */
    private bool $parseTruncated = false;

    private function parse(string $html): ?\DOMDocument
    {
        $this->parseTruncated = false;

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $document = new \DOMDocument();

        // Content is a fragment, not a document, and it is written by people
        // rather than generated. Suppressing libxml's complaints about markup
        // style is deliberate: we are judging the content, not validating it.
        $previous = libxml_use_internal_errors(true);

        // LIBXML_PARSEHUGE lifts the hard nesting limit of 256.
        //
        // Without it, anything deeper than 256 elements is silently dropped and
        // this class reports "no problems" for content it never saw. A tool
        // that quietly skips work is worse than one that fails loudly, because
        // the editor believes the page was checked. Page builders reach 30 to
        // 60 levels routinely and old nested-table content goes further.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="cqg-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE
        );

        // Errors that mean the tree is incomplete, as opposed to the usual
        // HTML5 grumbling. If the parser gave up, the editor has to be told.
        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                $this->parseTruncated = true;

                break;
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Reports that the check itself was incomplete.
     *
     * The alternative is to say nothing, which reads as "this content is fine"
     * when the truth is "this content was not fully examined". Those are
     * different statements and only one of them is honest.
     *
     * @return array<int,Issue>
     */
    private function checkParseIntegrity(): array
    {
        if (!$this->parseTruncated) {
            return [];
        }

        return [new Issue(
            rule: 'content-not-fully-parsed',
            severity: Issue::SEVERITY_WARNING,
            message: __('Part of this content could not be read, so the checks below may be incomplete.', 'content-quality-guard'),
            fix: __('This usually means very deeply nested or badly broken markup. Simplify the structure, then check again.', 'content-quality-guard'),
            standard: __('Tool limitation, reported rather than hidden', 'content-quality-guard')
        )];
    }

    /**
     * @return array<int,Issue>
     */
    private function checkImages(\DOMDocument $document): array
    {
        $issues = [];

        foreach ($document->getElementsByTagName('img') as $image) {
            $src = $image->getAttribute('src');
            $snippet = $this->snippet($src !== '' ? basename($src) : 'image');

            if (!$image->hasAttribute('alt')) {
                $issues[] = new Issue(
                    rule: 'image-missing-alt',
                    severity: Issue::SEVERITY_ERROR,
                    message: __('Image has no alt attribute.', 'content-quality-guard'),
                    context: $snippet,
                    fix: __('Describe what the image shows or what it is for. If it is purely decorative, use alt="" so it is skipped deliberately.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 1.1.1 Non-text Content (Level A)'
                );

                continue;
            }

            $alt = trim($image->getAttribute('alt'));

            // An empty alt is correct for decorative images, so it is not
            // flagged. Judging that intent is the editor's job, not ours.
            if ($alt === '') {
                continue;
            }

            if (preg_match('/\.(jpe?g|png|gif|webp|svg|avif)$/i', $alt) === 1) {
                $issues[] = new Issue(
                    rule: 'alt-is-filename',
                    severity: Issue::SEVERITY_WARNING,
                    message: __('Alt text is a file name.', 'content-quality-guard'),
                    context: $this->snippet($alt),
                    fix: __('Replace it with a description of the image. A file name read aloud tells nobody anything.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 1.1.1 Non-text Content (Level A)'
                );
            }

            if (preg_match('/^(image|picture|photo|graphic|icon)( of)?$/i', $alt) === 1) {
                $issues[] = new Issue(
                    rule: 'alt-is-generic',
                    severity: Issue::SEVERITY_WARNING,
                    message: __('Alt text says only that this is an image.', 'content-quality-guard'),
                    context: $this->snippet($alt),
                    fix: __('Screen readers already announce that it is an image. Describe the content instead.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 1.1.1 Non-text Content (Level A)'
                );
            }

            if (mb_strlen($alt) > 150) {
                $issues[] = new Issue(
                    rule: 'alt-too-long',
                    severity: Issue::SEVERITY_NOTICE,
                    message: __('Alt text is very long.', 'content-quality-guard'),
                    context: $this->snippet($alt),
                    fix: __('Keep it to roughly one sentence. If the image needs a longer explanation, put it in the surrounding text where everyone can read it.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 1.1.1 Non-text Content (Level A)'
                );
            }
        }

        return $issues;
    }

    /**
     * @return array<int,Issue>
     */
    private function checkLinks(\DOMDocument $document): array
    {
        $issues = [];

        foreach ($document->getElementsByTagName('a') as $link) {
            if (!$link->hasAttribute('href')) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
            $label = $link->getAttribute('aria-label');
            $effective = $label !== '' ? trim($label) : $text;

            // A link whose only content is an image is judged on that image's
            // alt text, which the image rules already cover.
            if ($effective === '') {
                $hasImage = $link->getElementsByTagName('img')->length > 0;

                if ($hasImage) {
                    continue;
                }

                $issues[] = new Issue(
                    rule: 'link-empty',
                    severity: Issue::SEVERITY_ERROR,
                    message: __('Link has no text.', 'content-quality-guard'),
                    context: $this->snippet($link->getAttribute('href')),
                    fix: __('Give the link readable text saying where it goes.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 2.4.4 Link Purpose (Level A)'
                );

                continue;
            }

            if (in_array(mb_strtolower(rtrim($effective, '.!:')), self::MEANINGLESS_LINK_TEXT, true)) {
                $issues[] = new Issue(
                    rule: 'link-text-meaningless',
                    severity: Issue::SEVERITY_WARNING,
                    message: sprintf(
                        /* translators: %s: the link text found. */
                        __('Link text "%s" does not say where it goes.', 'content-quality-guard'),
                        $effective
                    ),
                    context: $this->snippet($link->getAttribute('href')),
                    fix: __('Many people navigate by jumping between links, hearing only the link text. Name the destination: "Download the 2026 annual report" rather than "click here".', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 2.4.4 Link Purpose (Level A)'
                );
            }

            // A label that does not contain the visible text breaks voice
            // control: the spoken command has to match the accessible name.
            if ($label !== '' && $text !== '' && stripos($label, $text) === false) {
                $issues[] = new Issue(
                    rule: 'label-name-mismatch',
                    severity: Issue::SEVERITY_WARNING,
                    message: __('The link label does not include its visible text.', 'content-quality-guard'),
                    context: $this->snippet($text . ' / ' . $label),
                    fix: __('Someone using voice control says the words they see. Make the label start with the visible text.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 2.5.3 Label in Name (Level A)'
                );
            }

            if (str_starts_with($link->getAttribute('href'), 'http') && $link->getAttribute('target') === '_blank') {
                $rel = $link->getAttribute('rel');

                if (!str_contains($rel, 'noopener')) {
                    $issues[] = new Issue(
                        rule: 'target-blank-no-noopener',
                        severity: Issue::SEVERITY_NOTICE,
                        message: __('Link opens in a new tab without rel="noopener".', 'content-quality-guard'),
                        context: $this->snippet($link->getAttribute('href')),
                        fix: __('Add rel="noopener noreferrer" so the opened page cannot reach back into this one.', 'content-quality-guard'),
                        standard: 'Security best practice'
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<int,Issue>
     */
    private function checkHeadings(\DOMDocument $document): array
    {
        $issues = [];
        $levels = [];

        $xpath = new \DOMXPath($document);
        $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headings === false) {
            return $issues;
        }

        foreach ($headings as $heading) {
            $level = (int) substr($heading->nodeName, 1);
            $text = trim($heading->textContent);

            if ($text === '') {
                $issues[] = new Issue(
                    rule: 'heading-empty',
                    severity: Issue::SEVERITY_WARNING,
                    message: sprintf(
                        /* translators: %s: heading tag name. */
                        __('Empty %s heading.', 'content-quality-guard'),
                        strtoupper($heading->nodeName)
                    ),
                    context: '',
                    fix: __('Remove it, or give it text. An empty heading is announced as a heading with nothing in it.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 1.3.1 Info and Relationships (Level A)'
                );

                continue;
            }

            // Headings form the outline people navigate by. Skipping a level
            // makes that outline lie about the structure.
            if ($levels !== []) {
                $previous = end($levels);

                if ($level > $previous + 1) {
                    $issues[] = new Issue(
                        rule: 'heading-level-skipped',
                        severity: Issue::SEVERITY_WARNING,
                        message: sprintf(
                            /* translators: 1: previous heading level, 2: current heading level. */
                            __('Heading jumps from H%1$d to H%2$d.', 'content-quality-guard'),
                            $previous,
                            $level
                        ),
                        context: $this->snippet($text),
                        fix: __('Use the next level down. Many people move through a page by its heading outline, and a gap makes that outline misleading.', 'content-quality-guard'),
                        standard: 'WCAG 2.1 - 1.3.1 Info and Relationships (Level A)'
                    );
                }
            }

            $levels[] = $level;
        }

        $h1Count = count(array_filter($levels, static fn(int $l): bool => $l === 1));

        if ($h1Count > 1) {
            $issues[] = new Issue(
                rule: 'multiple-h1',
                severity: Issue::SEVERITY_NOTICE,
                message: sprintf(
                    /* translators: %d: number of H1 headings found. */
                    __('%d H1 headings in the content.', 'content-quality-guard'),
                    $h1Count
                ),
                context: '',
                fix: __('The page title is usually the H1 already. Use H2 for sections inside the content.', 'content-quality-guard'),
                standard: 'SEO and document structure'
            );
        }

        return $issues;
    }

    /**
     * @return array<int,Issue>
     */
    private function checkTables(\DOMDocument $document): array
    {
        $issues = [];

        foreach ($document->getElementsByTagName('table') as $table) {
            $headerCells = $table->getElementsByTagName('th')->length;

            if ($headerCells === 0) {
                $issues[] = new Issue(
                    rule: 'table-no-headers',
                    severity: Issue::SEVERITY_WARNING,
                    message: __('Table has no header cells.', 'content-quality-guard'),
                    context: '',
                    fix: __('Mark the header row with th cells. Without them a screen reader reads a grid of values with nothing to say which column is which.', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 1.3.1 Info and Relationships (Level A)'
                );
            }
        }

        return $issues;
    }

    /**
     * @return array<int,Issue>
     */
    private function checkIframes(\DOMDocument $document): array
    {
        $issues = [];

        foreach ($document->getElementsByTagName('iframe') as $frame) {
            if (trim($frame->getAttribute('title')) === '') {
                $issues[] = new Issue(
                    rule: 'iframe-no-title',
                    severity: Issue::SEVERITY_WARNING,
                    message: __('Embedded frame has no title.', 'content-quality-guard'),
                    context: $this->snippet($frame->getAttribute('src')),
                    fix: __('Add a title attribute saying what it contains, for example title="Programme launch video".', 'content-quality-guard'),
                    standard: 'WCAG 2.1 - 4.1.2 Name, Role, Value (Level A)'
                );
            }
        }

        return $issues;
    }

    /**
     * Audio and video.
     *
     * Recorded speech is unusable to a deaf visitor without a transcript or
     * captions, in the same way an image is unusable to a blind visitor without
     * alt text. Local government publishes a lot of recorded material, so this
     * is not a corner case here.
     *
     * @return array<int,Issue>
     */
    private function checkMedia(\DOMDocument $document): array
    {
        $issues = [];

        foreach (['audio', 'video'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $media) {
                $source = $media->getAttribute('src');

                if ($source === '') {
                    $sources = $media->getElementsByTagName('source');
                    $source  = $sources->length > 0 ? $sources->item(0)->getAttribute('src') : '';
                }

                $snippet = $this->snippet($source !== '' ? basename($source) : $tag);

                // A track element is the machine readable answer. Its absence
                // does not prove there are no captions elsewhere on the page,
                // so this is a warning rather than an error.
                $hasTrack = $media->getElementsByTagName('track')->length > 0;

                if ($hasTrack) {
                    continue;
                }

                $issues[] = new Issue(
                    rule: $tag === 'audio' ? 'audio-no-alternative' : 'video-no-captions',
                    severity: Issue::SEVERITY_WARNING,
                    message: $tag === 'audio'
                        ? __('Audio has no captions track or transcript link.', 'content-quality-guard')
                        : __('Video has no captions track.', 'content-quality-guard'),
                    context: $snippet,
                    fix: $tag === 'audio'
                        ? __('Add a transcript on the page, or a track element with captions. Recorded speech is unusable without one for anyone who cannot hear it.', 'content-quality-guard')
                        : __('Add a track element with captions. Auto-generated captions from the hosting platform do not count towards conformance.', 'content-quality-guard'),
                    standard: $tag === 'audio'
                        ? 'WCAG 2.1 - 1.2.1 Audio-only and Video-only (Level A)'
                        : 'WCAG 2.1 - 1.2.2 Captions (Level A)'
                );
            }
        }

        return $issues;
    }

    /**
     * Inline vector graphics.
     *
     * An SVG carrying meaning needs an accessible name; a decorative one needs
     * to be hidden from assistive technology. Doing neither leaves a screen
     * reader announcing "graphic" with no idea what it shows.
     *
     * @return array<int,Issue>
     */
    private function checkVectors(\DOMDocument $document): array
    {
        $issues = [];

        foreach ($document->getElementsByTagName('svg') as $svg) {
            $hidden = $svg->getAttribute('aria-hidden') === 'true';

            if ($hidden) {
                continue;
            }

            $hasTitle = $svg->getElementsByTagName('title')->length > 0;
            $hasLabel = trim($svg->getAttribute('aria-label')) !== ''
                || trim($svg->getAttribute('aria-labelledby')) !== ''
                || trim($svg->getAttribute('role')) === 'presentation';

            if ($hasTitle || $hasLabel) {
                continue;
            }

            $issues[] = new Issue(
                rule: 'svg-no-name',
                severity: Issue::SEVERITY_WARNING,
                message: __('Inline graphic has no accessible name and is not hidden.', 'content-quality-guard'),
                context: $this->snippet($svg->getAttribute('class') ?: 'svg'),
                fix: __('If it carries meaning, add a title element inside it. If it is decorative, add aria-hidden="true" so it is skipped deliberately.', 'content-quality-guard'),
                standard: 'WCAG 2.1 - 1.1.1 Non-text Content (Level A)'
            );
        }

        return $issues;
    }

    /**
     * Form controls placed inside content.
     *
     * Editors paste embedded forms and sign-up boxes into pages regularly, and
     * an input without a label is unusable with a screen reader.
     *
     * @return array<int,Issue>
     */
    private function checkForms(\DOMDocument $document): array
    {
        $issues = [];
        $xpath  = new \DOMXPath($document);

        $controls = $xpath->query('//input|//select|//textarea');

        if ($controls === false) {
            return $issues;
        }

        foreach ($controls as $control) {
            $type = strtolower($control->getAttribute('type'));

            // Hidden fields and buttons carry their own name or need none.
            if (in_array($type, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
                continue;
            }

            $id = $control->getAttribute('id');

            $hasLabel = trim($control->getAttribute('aria-label')) !== ''
                || trim($control->getAttribute('aria-labelledby')) !== ''
                || trim($control->getAttribute('title')) !== '';

            if (!$hasLabel && $id !== '') {
                $labels   = $xpath->query(sprintf('//label[@for="%s"]', addslashes($id)));
                $hasLabel = $labels !== false && $labels->length > 0;
            }

            // A control wrapped in a label needs no for attribute.
            if (!$hasLabel) {
                $parent = $control->parentNode;

                while ($parent instanceof \DOMElement) {
                    if ($parent->nodeName === 'label') {
                        $hasLabel = true;

                        break;
                    }

                    $parent = $parent->parentNode;
                }
            }

            if ($hasLabel) {
                continue;
            }

            $issues[] = new Issue(
                rule: 'input-no-label',
                severity: Issue::SEVERITY_ERROR,
                message: sprintf(
                    /* translators: %s: the form control tag name. */
                    __('Form field (%s) has no label.', 'content-quality-guard'),
                    $control->nodeName . ($type !== '' ? ' type=' . $type : '')
                ),
                context: $this->snippet($control->getAttribute('name') ?: $id),
                fix: __('Add a label element pointing at this field, or an aria-label. Placeholder text alone disappears as soon as someone starts typing.', 'content-quality-guard'),
                standard: 'WCAG 2.1 - 3.3.2 Labels or Instructions (Level A)'
            );
        }

        return $issues;
    }

    /**
     * @return array<int,Issue>
     */
    private function checkTitle(string $title): array
    {
        $title = trim($title);

        if ($title === '') {
            return [new Issue(
                rule: 'title-missing',
                severity: Issue::SEVERITY_ERROR,
                message: __('The page has no title.', 'content-quality-guard'),
                fix: __('Give it a title describing the page.', 'content-quality-guard'),
                standard: 'SEO'
            )];
        }

        $length = mb_strlen($title);

        if ($length > self::TITLE_MAX) {
            return [new Issue(
                rule: 'title-too-long',
                severity: Issue::SEVERITY_NOTICE,
                message: sprintf(
                    /* translators: 1: current length, 2: recommended maximum. */
                    __('Title is %1$d characters; search results usually cut off around %2$d.', 'content-quality-guard'),
                    $length,
                    self::TITLE_MAX
                ),
                context: $this->snippet($title),
                fix: __('Put the important words first so the meaning survives being truncated.', 'content-quality-guard'),
                standard: 'SEO'
            )];
        }

        if ($length < self::TITLE_MIN) {
            return [new Issue(
                rule: 'title-too-short',
                severity: Issue::SEVERITY_NOTICE,
                message: sprintf(
                    /* translators: %d: current length. */
                    __('Title is only %d characters.', 'content-quality-guard'),
                    $length
                ),
                context: $this->snippet($title),
                fix: __('Add enough detail that the page is recognisable in a list of search results.', 'content-quality-guard'),
                standard: 'SEO'
            )];
        }

        return [];
    }

    /**
     * @return array<int,Issue>
     */
    private function checkDescription(string $description): array
    {
        $description = trim($description);

        if ($description === '') {
            return [new Issue(
                rule: 'description-missing',
                severity: Issue::SEVERITY_WARNING,
                message: __('No meta description.', 'content-quality-guard'),
                fix: __('Write one or two sentences summarising the page. Search engines often show it under the title.', 'content-quality-guard'),
                standard: 'SEO'
            )];
        }

        $length = mb_strlen($description);

        if ($length > self::DESCRIPTION_MAX) {
            return [new Issue(
                rule: 'description-too-long',
                severity: Issue::SEVERITY_NOTICE,
                message: sprintf(
                    /* translators: 1: current length, 2: recommended maximum. */
                    __('Description is %1$d characters; usually cut off around %2$d.', 'content-quality-guard'),
                    $length,
                    self::DESCRIPTION_MAX
                ),
                context: $this->snippet($description),
                fix: __('Shorten it so the whole sentence is visible.', 'content-quality-guard'),
                standard: 'SEO'
            )];
        }

        if ($length < self::DESCRIPTION_MIN) {
            return [new Issue(
                rule: 'description-too-short',
                severity: Issue::SEVERITY_NOTICE,
                message: sprintf(
                    /* translators: %d: current length. */
                    __('Description is only %d characters.', 'content-quality-guard'),
                    $length
                ),
                context: $this->snippet($description),
                fix: __('Say a little more about what the page offers.', 'content-quality-guard'),
                standard: 'SEO'
            )];
        }

        return [];
    }

    private function snippet(string $text, int $length = 70): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text;
    }
}
