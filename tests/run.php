<?php
/**
 * Unit tests for the content analyser.
 *
 * Run with:  php tests/run.php
 *
 * The analyser is the part worth testing hard: it makes a claim about someone
 * else's content, and a wrong claim in either direction is costly. A false
 * positive teaches editors to ignore the panel; a false negative tells them a
 * page is fine when it is not.
 *
 * So every rule is tested twice, once on content that should trigger it and
 * once on content that must not.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ClarityWeb\ContentQualityGuard\Analyser\ContentAnalyser;

$analyser = new ContentAnalyser();

/**
 * @return array<int,string> Rule identifiers found, ignoring title and description.
 */
function rulesFor(ContentAnalyser $analyser, string $html): array
{
    $issues = $analyser->analyse(
        $html,
        'A page title of an entirely reasonable length',
        'A meta description that is comfortably long enough to satisfy the length rule.'
    );

    $rules = [];

    foreach ($issues as $issue) {
        if (str_starts_with($issue->rule, 'title-') || str_starts_with($issue->rule, 'description-')) {
            continue;
        }

        $rules[] = $issue->rule;
    }

    return $rules;
}

function assertFinds(ContentAnalyser $analyser, string $label, string $rule, string $html): void
{
    $rules = rulesFor($analyser, $html);

    TestRunner::assert(
        $label,
        in_array($rule, $rules, true),
        in_array($rule, $rules, true) ? '' : 'found: ' . (implode(', ', $rules) ?: 'nothing')
    );
}

function assertClean(ContentAnalyser $analyser, string $label, string $rule, string $html): void
{
    $rules = rulesFor($analyser, $html);

    TestRunner::assert(
        $label,
        !in_array($rule, $rules, true),
        in_array($rule, $rules, true) ? 'falsely reported ' . $rule : ''
    );
}

// ---------------------------------------------------------------------------

TestRunner::group('Images');

assertFinds($analyser, 'image without alt is reported', 'image-missing-alt', '<img src="a.jpg">');
assertClean($analyser, 'image with alt is not reported', 'image-missing-alt', '<img src="a.jpg" alt="A community walking group">');

// An empty alt is the correct markup for decoration. Reporting it would train
// editors to add pointless descriptions to spacer graphics.
assertClean($analyser, 'decorative image with empty alt is left alone', 'image-missing-alt', '<img src="line.png" alt="">');

assertFinds($analyser, 'alt that is a file name is reported', 'alt-is-filename', '<img src="a.jpg" alt="DSC_0421.jpg">');
assertFinds($analyser, 'alt saying only "photo" is reported', 'alt-is-generic', '<img src="a.jpg" alt="photo">');
assertClean($analyser, 'descriptive alt is not reported as generic', 'alt-is-generic', '<img src="a.jpg" alt="Photo of the mayor opening the centre">');
assertFinds($analyser, 'very long alt is flagged', 'alt-too-long', '<img src="a.jpg" alt="' . str_repeat('word ', 40) . '">');

TestRunner::group('Links');

assertFinds($analyser, '"click here" is reported', 'link-text-meaningless', '<a href="/x">click here</a>');
assertFinds($analyser, '"read more" is reported', 'link-text-meaningless', '<a href="/x">Read more</a>');
assertClean($analyser, 'descriptive link text is not reported', 'link-text-meaningless', '<a href="/x">Download the 2026 annual report</a>');
assertFinds($analyser, 'empty link is reported', 'link-empty', '<a href="/x"></a>');
assertClean($analyser, 'link wrapping an image is judged on the image', 'link-empty', '<a href="/x"><img src="a.jpg" alt="Annual report cover"></a>');

assertFinds(
    $analyser,
    'label not containing the visible text is reported',
    'label-name-mismatch',
    '<a href="/x" aria-label="Open the document">Annual report</a>'
);

assertClean(
    $analyser,
    'label containing the visible text is accepted',
    'label-name-mismatch',
    '<a href="/x" aria-label="Annual report, opens a PDF">Annual report</a>'
);

assertFinds($analyser, 'new tab without noopener is reported', 'target-blank-no-noopener', '<a href="https://example.ie" target="_blank">Partner</a>');
assertClean($analyser, 'new tab with noopener is accepted', 'target-blank-no-noopener', '<a href="https://example.ie" target="_blank" rel="noopener noreferrer">Partner</a>');

TestRunner::group('Headings');

assertFinds($analyser, 'skipped heading level is reported', 'heading-level-skipped', '<h2>Section</h2><h4>Subsection</h4>');
assertClean($analyser, 'sequential headings are accepted', 'heading-level-skipped', '<h2>Section</h2><h3>Subsection</h3>');
assertFinds($analyser, 'empty heading is reported', 'heading-empty', '<h2></h2>');
assertFinds($analyser, 'multiple H1 is reported', 'multiple-h1', '<h1>One</h1><h1>Two</h1>');
assertClean($analyser, 'a single H1 is accepted', 'multiple-h1', '<h1>One</h1><h2>Two</h2>');

TestRunner::group('Tables and frames');

assertFinds($analyser, 'table without headers is reported', 'table-no-headers', '<table><tr><td>A</td></tr></table>');
assertClean($analyser, 'table with headers is accepted', 'table-no-headers', '<table><tr><th>Name</th></tr><tr><td>A</td></tr></table>');
assertFinds($analyser, 'iframe without title is reported', 'iframe-no-title', '<iframe src="https://example.ie"></iframe>');
assertClean($analyser, 'iframe with title is accepted', 'iframe-no-title', '<iframe src="https://example.ie" title="Programme launch video"></iframe>');

TestRunner::group('Media');

assertFinds($analyser, 'audio without a track is reported', 'audio-no-alternative', '<audio src="talk.mp3" controls></audio>');
assertClean($analyser, 'audio with captions is accepted', 'audio-no-alternative', '<audio src="talk.mp3" controls><track kind="captions" src="c.vtt"></audio>');
assertFinds($analyser, 'video without captions is reported', 'video-no-captions', '<video src="clip.mp4" controls></video>');
assertClean($analyser, 'video with captions is accepted', 'video-no-captions', '<video src="clip.mp4" controls><track kind="captions" src="c.vtt"></video>');

TestRunner::group('Vector graphics');

assertFinds($analyser, 'svg without a name is reported', 'svg-no-name', '<svg class="icon"><path d="M0 0"/></svg>');
assertClean($analyser, 'svg hidden from assistive tech is accepted', 'svg-no-name', '<svg aria-hidden="true"><path d="M0 0"/></svg>');
assertClean($analyser, 'svg with a title is accepted', 'svg-no-name', '<svg><title>Programme logo</title><path d="M0 0"/></svg>');
assertClean($analyser, 'svg with aria-label is accepted', 'svg-no-name', '<svg aria-label="Programme logo"><path d="M0 0"/></svg>');

TestRunner::group('Form controls');

assertFinds($analyser, 'input without a label is reported', 'input-no-label', '<input type="text" name="email">');
assertClean($analyser, 'input with a matching label is accepted', 'input-no-label', '<label for="e">Email</label><input type="text" id="e">');
assertClean($analyser, 'input wrapped in a label is accepted', 'input-no-label', '<label>Email <input type="text" name="e"></label>');
assertClean($analyser, 'input with aria-label is accepted', 'input-no-label', '<input type="text" aria-label="Email address">');
assertClean($analyser, 'hidden input needs no label', 'input-no-label', '<input type="hidden" name="nonce" value="x">');
assertClean($analyser, 'submit button needs no label', 'input-no-label', '<input type="submit" value="Send">');

TestRunner::group('Titles and descriptions');

$titleIssues = static function (ContentAnalyser $a, string $title, string $description): array {
    $rules = [];

    foreach ($a->analyse('<p>Body</p>', $title, $description) as $issue) {
        $rules[] = $issue->rule;
    }

    return $rules;
};

TestRunner::assert('missing title is reported', in_array('title-missing', $titleIssues($analyser, '', 'A description of adequate length for the rule.'), true));
TestRunner::assert('overlong title is reported', in_array('title-too-long', $titleIssues($analyser, str_repeat('Long title ', 12), 'A description of adequate length for the rule.'), true));
TestRunner::assert('short title is reported', in_array('title-too-short', $titleIssues($analyser, 'Short', 'A description of adequate length for the rule.'), true));
TestRunner::assert('missing description is reported', in_array('description-missing', $titleIssues($analyser, 'A title of a perfectly reasonable length', ''), true));
TestRunner::assert(
    'a good title and description produce nothing',
    $titleIssues($analyser, 'Healthy Ireland programme resources for 2026', 'Guidance, toolkits and reports for local authority teams delivering Healthy Ireland programmes.') === []
);

TestRunner::group('Robustness');

$hostile = [
    'empty string'          => '',
    'whitespace only'       => "  \n\t ",
    'plain text'            => str_repeat('Just text. ', 200),
    'broken markup'         => '<div><p>unclosed <img src=x <a href=>>><table><tr>',
    'unclosed quotes'       => '<img src="x alt="y">',
    'html entities'         => '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    'comments only'         => '<!-- <img src=x> -->',
    'nested anchors'        => '<a href="1"><a href="2">double</a></a>',
    'null byte'             => "<p>text\x00hidden</p>",
    'emoji and rtl'         => '<h2>Отчёт 📊 مرحبا 日本語</h2>',
    'deep nesting'          => str_repeat('<div>', 400) . '<img src="x">' . str_repeat('</div>', 400),
    'many images'           => str_repeat('<img src="a.jpg">', 300),
];

foreach ($hostile as $label => $html) {
    $threw = false;

    try {
        $analyser->analyse($html, 'A reasonable title for the robustness test', 'A description of adequate length for these tests.');
    } catch (\Throwable $e) {
        $threw = true;
    }

    TestRunner::assert('survives: ' . $label, !$threw);
}

// The nesting limit is the one that silently hid content before, so it gets
// its own explicit check rather than relying on "did not throw".
$deep = str_repeat('<div>', 400) . '<img src="hidden.jpg">' . str_repeat('</div>', 400);

TestRunner::assert(
    'content nested 400 levels deep is still examined',
    in_array('image-missing-alt', rulesFor($analyser, $deep), true)
);

exit(TestRunner::summary());
