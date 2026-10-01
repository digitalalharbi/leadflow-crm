# Design system

The product is looked at for eight hours a day by people whose job is somewhere
else. Everything below follows from that.

## Three rules

**1. Contrast is spent on content, never on chrome.** Borders are hairlines
(`--color-line`, a colour-mix at 8% rather than a solid grey). Shadows are a
tight contact shadow plus a wide faint ambient one — light falling on a surface,
not a drop shadow. The page sits a half-step below its cards, not a full one.

**2. One accent colour, and it belongs to the customer.** `--brand` is written
at runtime from the tenant's branding. Everything else is neutral or semantic,
so the only colour that shouts in a workspace is that company's own.

**3. Nothing moves without a reason, and everything moves on one curve.**
`--ease-out-soft` starts fast and settles, which is what makes an interface feel
answered rather than animated. `prefers-reduced-motion` turns all of it off.

## Tokens

Every token lives in `frontend/src/index.css`. No component carries a raw hex or
a Tailwind palette class — `bg-red-50` cannot follow a theme, and a sweep of the
codebase will find none.

| Group | What it is for |
| --- | --- |
| `--color-neutral-*` | The greys, warmed very slightly off neutral. A pure-grey interface reads cold next to the warm whites screens actually show. |
| `--color-surface*`, `--color-ink*`, `--color-line` | Semantic surfaces and text. Dark mode swaps this group wholesale. |
| `--color-status-*` | One hue per pipeline stage, desaturated from the framework defaults: six saturated hues on one board is a fruit salad. |
| `--color-temp-*` | Hot / warm / cold. |
| `--brand*` | The tenant's colour, plus the soft tints and the readable contrast colour derived from it. |
| `--shadow-card/raised/pop` | Three steps of elevation. |
| `--radius-control/card/panel` | Radii step with the size of the thing, so nothing looks over-rounded. |
| `--ease-out-soft`, `--duration-*` | The motion system. |

## Dark mode

A different set of surfaces, not an inversion: cards lift by becoming *lighter*
than the page, borders become light at low opacity, and the status hues are
raised so they still read on a dark field. It is applied to `<html>` before
React mounts — a flash of white on the way in is the one thing people notice
about a dark mode.

Three settings: follow the device, always light, always dark. An explicit choice
is never overridden by the laptop deciding it is sunset.

## The brand colour is not trusted blindly

A tenant can pick any colour, including a pale one. `--brand-contrast` is
computed from the brand's own relative luminance, so the text on a primary
button is black or white depending on what a person can actually read — rather
than white because buttons are usually dark.

The soft tints are mixed toward `--color-surface` rather than toward white, so
the active navigation item is still visible in dark mode.

## Arabic is the first language, and it is typeset

Three things that are wrong in most bilingual interfaces, and are right here:

* **No letter-spacing on Arabic.** Tracking pulls apart the cursive joins and
  turns a word into disconnected shapes. `.label-caps` uppercases and tracks in
  Latin and does neither in Arabic.
* **More leading.** Arabic sits lower in its em box; `[dir='rtl']` gets 1.8
  against 1.6.
* **Signed numbers are forced LTR.** The bidi algorithm reorders `+25` in an
  Arabic paragraph into `25+`, which turns a bonus into something that reads
  like a penalty. Score deltas carry `dir="ltr"`.

Numerals stay Latin and tabular in both locales, so a column of money lines up
and a changing figure does not jitter.

## Materials

`.material` is the frosted chrome used by anything that floats over content —
the top bar today. It degrades to a solid surface where `backdrop-filter` is
unsupported, because translucent text over scrolling content is unreadable.

## Where the rules are enforced

* `frontend/src/index.css` — the tokens and the base layer.
* `frontend/src/components/ui/` — the primitives. If a pattern is not here, it
  is probably being reinvented.
* `useBrandingTheme` — the only place that writes brand variables.
