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

Note: the older V1 Clients page renders in the system stack and a few greys
outside the palette (`#1d1d1f`, `#e5e5e7`, `#f5f7fa`, `#555`). V2 surfaces use
Poppins/Inter and map those greys to the palette: text `#2c3e50`, borders
`#e4e6ea`, fills `#f8f9fb`, header labels `#888`. Match the Clients page's
sizes and weights, not its font or greys.

### Hierarchy (practitioner pages)
- Page title: 32px / 700, line-height 1, colour `#2c3e50`, 8px below
- Sub-line under title: 18px, `#888`
- Table header: 14px / 700, upper case, letter-spacing 0.5px, `#888`
- Table cell: 15px; the name cell 600, `#2c3e50`
- Pill: 11px / 600, upper case, letter-spacing 0.5px

## Layout

### Page gutters
- > 1024px: 64px left/right, 48px top
- 641–1024px: 32px
- ≤ 640px: 24px left/right, 32px top

### Table container
- Radius 12px, border `1px solid #e4e6ea`, background white,
  shadow `0 1px 3px rgba(0,0,0,0.1)`, overflow hidden
- Header row: fill `#f8f9fb`, bottom border `1px solid #e4e6ea`,
  cell padding `9px 24px`, height about 60px
- Body rows: about 60px tall, cell padding `6px 24px`,
  divider `1px solid #e4e6ea`, no row cards, no avatars

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

## Rules that win over any reference
- No accent rails (`border-left` bars, `::before` stripes) on rows, cards or nav.
- No native `alert` / `confirm` / `prompt`.
- Loading is a skeleton in the current theme, never a spinner.
