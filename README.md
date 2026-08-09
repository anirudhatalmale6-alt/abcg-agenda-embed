# ABCG Agenda Embed

Makes the agenda iframes on abcg.ch size themselves to their content, so the
inner right-hand scrollbar disappears and nothing is cut off.

## The problem

The two agenda boxes are iframes served from `admin.abcg.ch`, a different host
than `abcg.ch`. A browser will not let a page measure the content of an iframe
from another host, so the height had to be hard-coded:

- Home page — no height at all, so the browser fell back to a default that is
  too short on phones.
- `/agenda/` — fixed at `1300px`, while the real content is roughly `2100px` on
  desktop and `2900px` on a phone. Everything past 1300px sat behind the
  scrollbar.

The content height also changes with screen width, so no fixed value can be
correct on every device.

## How it works

The plugin serves the two agenda pages from this domain (`/abcg-embed/next/`
and `/abcg-embed/list/`). Because the iframe is then same-origin, its real
height can be read and reported to the page, which resizes the frame to match.

On the way through, the plugin also:

- keeps the ASP.NET postback working (the "Lire plus ..." detail box), including
  ViewState and session cookies, so the frame re-sizes when the box opens and
  closes;
- lifts the fixed `height: 80px` on the home page cards, which was slicing long
  event titles in half;
- stacks the agenda date above the title below 780px, so rows stop spilling
  sideways on a phone.

Only those two pages can be requested — the endpoint is not a general proxy.

## Install

1. WordPress admin → Plugins → Add New → Upload Plugin.
2. Upload `abcg-agenda-embed.zip`, then Activate.
3. Edit the two pages and replace the existing `<iframe>` block with the
   shortcode:

   - Home page: `[abcg_agenda view="next"]`
   - Agenda page: `[abcg_agenda view="list"]`

4. If the embeds show a 404, go to Settings → Permalinks and click Save (this
   just refreshes the URL rules).

## Shortcode options

| Attribute | Default | Notes |
|-----------|---------|-------|
| `view`    | `next`  | `next` = home box, `list` = full agenda |
| `height`  | `300`   | Height in px used for the split second before the real height is known |

## Files

- `abcg-iframe-autoheight.php` — plugin bootstrap, endpoint, shortcode
- `includes/class-abcg-embed-core.php` — fetching and HTML rewriting
- `assets/child.js` — measures the content height inside the frame
- `assets/parent.js` — applies the reported height to the iframe
- `assets/child.css` — shared fixes inside the frame
- `assets/child-next.css` — home page card height fix
- `assets/child-list.css` — agenda mobile layout fix

## Undoing a layout tweak

The two CSS tweaks are cosmetic and independent of the resizing. Emptying
`assets/child-next.css` or `assets/child-list.css` reverts that tweak while the
auto-height keeps working.
