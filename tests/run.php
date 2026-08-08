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

// ---------------------------------------------------------------------------
// History series
// ---------------------------------------------------------------------------

TestRunner::group('History series');

/**
 * Mirrors Sweep::recordHistory. One point per day, replacing the day if it
 * already ran, capped to a rolling window.
 *
 * @param array<string,array<string,int>> $history
 * @return array<string,array<string,int>>
 */
function recordDay(array $history, string $day, int $total, int $limit = 365): array
{
    $history[$day] = ['total' => $total];

    ksort($history);

    if (count($history) > $limit) {
        $history = array_slice($history, -$limit, null, true);
    }

    return $history;
}

$series = recordDay([], '2026-08-06', 14);
$series = recordDay($series, '2026-08-07', 11);

TestRunner::same('each day adds a point', 2, count($series));

$series = recordDay($series, '2026-08-07', 9);

TestRunner::same('a second run on the same day replaces it', 2, count($series));
TestRunner::same('the later figure wins', 9, $series['2026-08-07']['total']);

$series = recordDay($series, '2026-08-05', 20);

TestRunner::same('a late arrival is sorted into place', '2026-08-05', (string) array_key_first($series));

$capped = [];

foreach (range(1, 12) as $day) {
    $capped = recordDay($capped, sprintf('2026-01-%02d', $day), $day, 10);
}

TestRunner::same('the window is capped', 10, count($capped));
TestRunner::same('the oldest points fall off, not the newest', '2026-01-03', (string) array_key_first($capped));
TestRunner::same('the most recent point is kept', '2026-01-12', (string) array_key_last($capped));

// ---------------------------------------------------------------------------
// Trend
// ---------------------------------------------------------------------------

TestRunner::group('Trend');

/**
 * Mirrors Sweep::trend. One measurement is not a trend.
 *
 * @param array<string,array<string,int>> $history
 */
function trendChange(array $history): ?int
{
    if (count($history) < 2) {
        return null;
    }

    $first = reset($history);
    $last  = end($history);

    return (int) $last['total'] - (int) $first['total'];
}

TestRunner::same('no history produces no trend', null, trendChange([]));
TestRunner::same('a single point produces no trend', null, trendChange(['2026-08-08' => ['total' => 10]]));

TestRunner::same(
    'an improvement reads as a fall',
    -6,
    trendChange(['2026-08-06' => ['total' => 14], '2026-08-08' => ['total' => 8]])
);

TestRunner::same(
    'a regression reads as a rise',
    5,
    trendChange(['2026-08-06' => ['total' => 3], '2026-08-08' => ['total' => 8]])
);

TestRunner::same(
    'no net change is reported as zero, not as no data',
    0,
    trendChange(['2026-08-06' => ['total' => 8], '2026-08-08' => ['total' => 8]])
);

// ---------------------------------------------------------------------------
// Chart geometry
// ---------------------------------------------------------------------------

TestRunner::group('Chart geometry');

/**
 * Mirrors the polyline built in OverviewPage::renderHistory.
 *
 * @param array<int,int> $values
 * @return array<int,array{float,float}>
 */
function polyline(array $values, int $width = 640, int $height = 120): array
{
    $peak  = max($values) ?: 1;
    $count = count($values);
    $step  = $count > 1 ? $width / ($count - 1) : $width;

    $points = [];

    foreach ($values as $index => $value) {
        $points[] = [
            round($index * $step, 1),
            round($height - (($value / $peak) * ($height - 10)) - 5, 1),
        ];
    }

    return $points;
}

$line = polyline([10, 5, 0]);

TestRunner::same('the first point sits on the left edge', 0.0, $line[0][0]);
TestRunner::same('the last point sits on the right edge', 640.0, $line[2][0]);
TestRunner::same('the peak sits at the top, inside the padding', 5.0, $line[0][1]);
TestRunner::same('zero sits at the bottom, inside the padding', 115.0, $line[2][1]);

TestRunner::assert(
    'a flat series does not divide by zero',
    (static function (): bool {
        $flat = polyline([0, 0, 0]);

        return is_finite($flat[0][1]) && is_finite($flat[2][1]);
    })()
);

TestRunner::assert(
    'every point stays inside the box',
    (static function (): bool {
        foreach (polyline([3, 19, 7, 12, 1]) as [$x, $y]) {
            if ($x < 0 || $x > 640 || $y < 0 || $y > 120) {
                return false;
            }
        }

        return true;
    })()
);

// ---------------------------------------------------------------------------
// Sweep queue
// ---------------------------------------------------------------------------

TestRunner::group('Sweep queue');

/**
 * Mirrors Sweep::oldestFirst: never-checked pages ahead of stale ones, and the
 * batch filled from the stale queue only if room is left.
 *
 * @param array<int,array{id:int,checked:?string}> $posts
 * @return array<int,int>
 */
function sweepQueue(array $posts, int $batch): array
{
    $never = array_values(array_filter($posts, static fn(array $p): bool => $p['checked'] === null));
    $stale = array_values(array_filter($posts, static fn(array $p): bool => $p['checked'] !== null));

    usort($never, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    usort($stale, static fn(array $a, array $b): int => strcmp((string) $a['checked'], (string) $b['checked']));

    $queue = array_slice($never, 0, $batch);

    if (count($queue) < $batch) {
        $queue = array_merge($queue, array_slice($stale, 0, $batch - count($queue)));
    }

    return array_map(static fn(array $p): int => $p['id'], $queue);
}

$library = [
    ['id' => 1, 'checked' => '2026-08-01 09:00:00'],
    ['id' => 2, 'checked' => null],
    ['id' => 3, 'checked' => '2026-07-01 09:00:00'],
    ['id' => 4, 'checked' => null],
    ['id' => 5, 'checked' => '2026-08-07 09:00:00'],
];

TestRunner::same(
    'never-checked pages go first, in id order',
    [2, 4],
    array_slice(sweepQueue($library, 4), 0, 2)
);

TestRunner::same(
    'the batch is then filled with the stalest',
    [2, 4, 3, 1],
    sweepQueue($library, 4)
);

TestRunner::same(
    'a small batch never reaches the stale queue',
    [2, 4],
    sweepQueue($library, 2)
);

TestRunner::same(
    'the most recently checked page is last to be revisited',
    5,
    sweepQueue($library, 5)[4]
);

TestRunner::same(
    'a fully checked site still cycles oldest first',
    [3, 1, 5],
    sweepQueue([
        ['id' => 1, 'checked' => '2026-08-01 09:00:00'],
        ['id' => 3, 'checked' => '2026-07-01 09:00:00'],
        ['id' => 5, 'checked' => '2026-08-07 09:00:00'],
    ], 3)
);

exit(TestRunner::summary());
