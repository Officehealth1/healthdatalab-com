# HealthDataLab — DESIGN.md

Records the theme the product already has. It is not a new design. Brand and
status colours come from `CLAUDE.md` ("Brand + Status palette"); the table
measurements were taken from the practitioner Clients page
(`stby.healthdatalab.net/clients/`, 1440 wide, computed styles, 2026-10-05).

## Overview

Clinical, quiet, white. One teal for action, dark navy for text, hairlines
instead of boxes. Practitioner screens are dense product UI: tables, pills,
one primary button per screen region. No gradients, no shadows beyond the
table container's faint one, no accent rails beside rows or cards.

## Colors

### Brand
- Teal (primary): `#3d8da0` — CTAs, links, primary buttons, focus rings
- Dark teal: `#004F59` — headings, hover on teal
- Dark navy: `#2c3e50` — body emphasis, secondary headings
- Muted grey: `#888` — secondary text, captions
- Border grey: `#e4e6ea` — dividers, input borders
- Soft fill: `#f8f9fb` / `#fafbfc` — card backgrounds, hover

### Status (text / background / border) — the only status colours
- Success: `#10b981` / `#ecfdf5` / `#a7f3d0`
- Warning: `#d97706` / `#fffbeb` / `#fde68a`
- Error: `#dc2626` / `#fef2f2` / `#fecaca`
- Info: `#3b82f6` / `#eff6ff` / `#bfdbfe`

Never the Flat UI defaults (`#2ecc71`, `#e67e22`, `#e74c3c`, `#c0392b`).

## Typography

### Font Family
- Display (`h1`, `h2`, big numbers): Poppins 600/700
- Body: Inter 400/500/600
- Email-safe stack: `'Poppins', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`

Note: the older V1 Clients page renders in the system stack. V2 surfaces use
Poppins/Inter; match the Clients page's sizes and weights, not its font.

### Hierarchy (practitioner pages)
- Page title: 32px / 700, line-height 1, colour `#1d1d1f`, 8px below
- Sub-line under title: 18px, `#86868b`
- Table header: 14px / 700, upper case, letter-spacing 0.5px, `#555`
- Table cell: 15px; the name cell 600, `#1d1d1f`
- Pill: 11px / 600, upper case, letter-spacing 0.5px

## Layout

### Page gutters
- > 1024px: 64px left/right, 48px top
- 641–1024px: 32px
- ≤ 640px: 24px left/right, 32px top

### Table container
- Radius 12px, border `1px solid #e5e5e7`, background white,
  shadow `0 1px 3px rgba(0,0,0,0.1)`, overflow hidden
- Header row: fill `#f5f7fa`, bottom border `1px solid #e5e5e7`,
  cell padding `9px 24px`, height about 60px
- Body rows: about 60px tall, cell padding `6px 24px`,
  divider `1px solid #f5f7fa`, no row cards, no avatars

## Elevation

Only the table container carries the faint `0 1px 3px` shadow. Nothing else
floats.

## Components

### Buttons
- Primary: teal fill, white text, 14px / 500, padding `10px 20px`
  (about 37px tall), radius 8px, no border. One style; used sparingly.
- Secondary: same size and radius, white fill, teal text,
  `1px solid #e4e6ea` border.
- Focus: visible 2px teal outline with offset.

### Status pills
- Padding `3px 8px`, radius 12px, 1px border, 11px / 600 upper case.
- Colours from the status palette only. No dot before the label.

## Responsive Behavior

- The Clients table keeps its columns and scrolls inside its container on
  phones.
- Lists that must read on a phone stack each row instead: name and pill,
  then the secondary facts, then the action full width.
- Touch targets at least 40px tall.

## Motion

The small looping pictures on the Stage 1 questions and the second form's
tests (`hdl-longevity-v2/assets/images/motion/`). One author, one rig, one
palette, one timing grid; `tests/question-motion/test-motion-files.php`
checks every file against this section.

### Rig
- The same figure wherever a person is drawn (Q8 draws two, the Q9 plate
  none): head `circle r="9"` filled `#004F59`, torso one stroke, each leg
  two strokes (thigh 16, shin 16), each arm two strokes (upper 13, fore
  13); stroke-width 6, `#004F59`, round caps.
- No face, hands, hair, clothes or gender cue.
- The same proportions in every file on the 120 grid: head 18, torso 30,
  leg 32, arm 26. A scene scales the whole rig, never one limb.
- Joints are nested groups: the joint's position is a `transform`
  attribute, the movement is a CSS `rotate()` on the group inside it.

### Props and ground
- Stairs, chair, bed, plate, hand outline, measuring bands: stroke 2,
  `#3d8da0` at `opacity="0.55"`, round caps, or filled `#e4e6ea`.
- Food on the Q9 plate: `#e4e6ea` fill with a stroke-2 `#3d8da0` outline at
  full strength, so it stands off the plate's 0.55 rim.
- Ground line: `#e4e6ea`, stroke 2.
- Timer arc (balance, chair stands, skin pinch): a stroke-2 `#3d8da0` arc
  that fills clockwise over the timed part with `steps(24)`.

### Frame
- `viewBox="0 0 120 120"`. First come a `#e4e6ea` disc (r 60) and a
  `#f8f9fb` disc (r 59) over it: a 1-unit ring with no stroke.
- Everything sits inside the disc, with 8 units of air to the ring.

### Colours
- Only `#004F59`, `#3d8da0`, `#e4e6ea`, `#f8f9fb`, `#ffffff`. Opacity
  attributes are allowed; no other colour, no gradient.

### Timing
- One easing: `cubic-bezier(.4,0,.2,1)`. The timer arc alone uses `steps(24)`.
- Every duration, and every keyframe stop, falls on a 400 ms grid.
- A scene lasts 4 to 8 s. It ends with at least 1.2 s of rest on its final
  pose. (Answer poses are the one exception: see Size.)
- At most two things move at once. A figure counts as one, joints and all.
- No bounce, overshoot, squash or stretch. Scenes loop forever and have no
  sound.
- Each moving group has one `animation` shorthand (name, duration, easing,
  `infinite`), with no delays. The timing lives in the keyframes.

### Reduced motion
- Every animated file carries
  `@media (prefers-reduced-motion: reduce) { * { animation: none !important } }`.
- The static rules draw the final pose, so the picture rests on it when
  movement is off. (Answer poses draw their first: see Size.)
- A picture shown through `<img>` may not see the page's motion setting
  (Chrome with DevTools emulation did not, 2026-11-04). So the root `<svg>`
  carries `id="still"` and `#still:target *{animation:none!important}`. The page
  asks for the still through
  `<picture><source srcset="…&still=1#still" media="(prefers-reduced-motion: reduce)">`.
  The extra `&still=1` gives the still its own address. A browser that already
  holds the moving picture reuses it for a bare `#still` and keeps it moving.
- A still picture (the waist and hip guide) has no animation at all.

### Words
- No words inside a file: no `<text>`, image, link, `url()` or script.
- The caption is HTML under the picture, in the page's muted caption style.
  It is one to three words and comes from the page's own copy, so it
  translates with the page.

### Size
- Shown as a 96 px circle, or 80 px under 480 px wide, on `#f8f9fb`.
- Answer poses (Q5 only): one picture on each answer, between the number and
  the text, 40 px (36 px under 480 px). No caption: the answer text says it.
  One scale and one ground line across the set, so the row reads in order.
- With a mouse, hovering an answer plays its pose once: the figure gets up to
  standing its own way in 1.2 s or 1.6 s and rests there (one iteration,
  `forwards`, no loop). Leaving the answer shows the pose again at once.
  Touch and reduced motion see the pose only.
- For a pose, the static rules draw the first keyframe (the pose on the
  button), not the final one: standing is the same on all five.
- A browser runs a one-shot SVG animation from load, even while hidden, and
  keeps one copy per address. So each hover loads the moving file on a fresh
  address (`&n=`) and shows it once loaded. One small fetch per hover.
- A file is at most 8 KB.

### Rule that wins
- No generated image, no video, no stock clip, no GIF and no animation
  library.
- A new scene is drawn with this rig, or not at all.

## Rules that win over any reference
- No accent rails (`border-left` bars, `::before` stripes) on rows, cards or nav.
- No native `alert` / `confirm` / `prompt`.
- Loading is a skeleton in the current theme, never a spinner.
