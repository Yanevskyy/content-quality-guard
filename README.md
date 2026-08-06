# Content Quality Guard

A WordPress plugin that checks content for accessibility and search problems as
it is written, and measures real page speed through the Google PageSpeed
Insights API.

Built for tender **WCCC26/432** as a demonstration of bespoke WordPress
development. It is not a past client project.

Answers two lines of the brief directly:

> "Accessibility optimisation of supplied content (Alt tags for images, labels
> for links, etc.)"
> "Editing/Uploading/formatting and search engine optimisation of supplied
> content"

---

## What it does

**In the editor.** A panel beside the content lists every problem found, grouped
by whether it must be fixed, should be fixed, or could be improved. Each finding
says what is wrong, shows the offending element, gives the fix in one sentence,
and names the standard behind it.

**Across the site.** An overview screen lists every page with its counts, worst
first, so a content manager knows which three pages to open rather than reading
the whole list.

**Speed.** A button measures the live page through PageSpeed Insights and stores
the score, the core metrics and the largest opportunities.

## What it checks

**Accessibility**

| Rule | Standard |
|---|---|
| Image without an alt attribute | WCAG 1.1.1 (A) |
| Alt text that is a file name | WCAG 1.1.1 (A) |
| Alt text that says only "image" or "photo" | WCAG 1.1.1 (A) |
| Alt text longer than a sentence | WCAG 1.1.1 (A) |
| Link with no text at all | WCAG 2.4.4 (A) |
| Link text like "click here" or "read more" | WCAG 2.4.4 (A) |
| Label that does not contain the visible text | WCAG 2.5.3 (A) |
| Heading levels skipped, for example H2 straight to H4 | WCAG 1.3.1 (A) |
| Empty heading | WCAG 1.3.1 (A) |
| Table without header cells | WCAG 1.3.1 (A) |
| Embedded frame without a title | WCAG 4.1.2 (A) |

**Search**

Missing or oversized page title, missing or oversized meta description, more
than one H1 in the content.

**Security**

Links opening in a new tab without `rel="noopener"`.

An empty `alt=""` is **not** flagged. It is the correct markup for a decorative
image, and a tool that cannot tell deliberate from missing trains editors to
ignore it.

---

## Design notes

**DOM parsing, not regular expressions.** A pattern looking for an image without
alt text breaks on the first attribute containing an angle bracket, and cannot
distinguish `alt=""` from a missing attribute. Parsing into a tree gives real
structure, so heading order, table markup and link context can be judged
properly. That is the difference between a tool that nags at the wrong moment
and one an editor trusts.

**Analysis runs on save, not on view.** The front end does no work, and the
overview screen can report across the whole site without re-parsing every page.

**Meta description is read from whichever SEO plugin is installed** (Rank Math,
Yoast, All in One SEO) before falling back to the excerpt. Reporting "no
description" when one exists is exactly how a warning gets ignored forever.

**The PageSpeed integration answers three questions explicitly**, because they
are the questions every real integration has to answer:

1. *What if it is slow?* An explicit sixty second timeout, and the button says
   what it is doing rather than sitting silent.
2. *What if the quota runs out?* That is reported as a quota message, not as a
   failed measurement. They call for completely different actions, and conflating
   them sends someone hunting for a fault in their own site.
3. *What if there is no result yet?* The panel says so plainly instead of
   showing a zero, which would read as "your page scores nothing".

---

## Verified behaviour

Checked against a running instance with content containing deliberate mistakes:

- 9 planted problems, 9 found, correctly classified by severity
- image without alt reported as blocking; alt text set to a file name reported
  separately as a warning
- "click here" flagged with the destination shown
- heading jump from H2 to H4 detected
- table without headers and iframe without title detected
- quota exhaustion on the PageSpeed API produced the quota message, not a
  failure message or a zero score
- site-wide overview correctly aggregated 9 pages, worst first

## Requirements

WordPress 6.5+, PHP 8.2+. No external dependencies, no build step, no vendor
directory. The PageSpeed API works without a key at a low daily limit; a free
key raises it.

## Licence

GPL-2.0-or-later. Built by [ClarityWeb](https://clarityweb.ie).
