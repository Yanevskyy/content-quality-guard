<?php
/**
 * One finding about a piece of content.
 *
 * Every issue carries the same four things, because an editor who is told
 * "there is a problem" and nothing else will ignore the tool by the third time:
 *
 *   - what is wrong, in plain language,
 *   - where exactly (the offending markup),
 *   - why it matters, tied to the standard or the consequence,
 *   - what to do about it.
 *
 * @package ContentQualityGuard
 */

declare(strict_types=1);

namespace ClarityWeb\ContentQualityGuard\Analyser;

defined('ABSPATH') || exit;

final class Issue
{
    /** Blocks publication in the eyes of accessibility law or breaks the page. */
    public const SEVERITY_ERROR = 'error';

    /** Real problem, not fatal on its own. */
    public const SEVERITY_WARNING = 'warning';

    /** Worth improving. */
    public const SEVERITY_NOTICE = 'notice';

    public function __construct(
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $context = '',
        public readonly string $fix = '',
        public readonly string $standard = '',
    ) {
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'rule'     => $this->rule,
            'severity' => $this->severity,
            'message'  => $this->message,
            'context'  => $this->context,
            'fix'      => $this->fix,
            'standard' => $this->standard,
        ];
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }
}
