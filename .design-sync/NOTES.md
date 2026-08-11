# design-sync notes — Avyo Design System

Repo-specific gotchas for syncing `app/resources/js/components/ui` to
claude.ai/design. Read this before a re-sync.

Project: <https://claude.ai/design/p/a0791e48-723c-4ea9-b13b-60c38792594b>

## The shape of this sync

This is not a published component library. It is a Laravel + Inertia app whose
UI kit lives in `app/resources/js/components/ui` (28 modules, 124 exports,
shadcn/ui "new-york" over Radix + Tailwind v4 + CVA). Three things had to be
built to present it to the converter, and all three live in `.design-sync/`:

| Script | What it does | When to re-run |
|---|---|---|
| `build-css.mjs` | Generates `.ds-source.css` and compiles it with Tailwind | Called by `prepare-pkg.mjs`; run alone only when debugging CSS |
| `gen-docs.mjs` | Seeds `docs/<Name>.md` category stubs for new components | Called by `prepare-pkg.mjs`; safe to run any time (never overwrites) |
| `prepare-pkg.mjs` | Builds the whole `app/node_modules/avyo` shim | **Every re-sync, and after every `npm ci`** |

**The one command before the converter is `node .design-sync/prepare-pkg.mjs`.**
It runs the other two.

## Why the shim exists

`app/node_modules/avyo` is generated, gitignored, and rebuilt from scratch each
run. It exists because the converter expects an npm package and this is not one:

- **`app/package.json` has no `name`.** The `.d.ts` extraction walks up from the
  types root looking for a *named* package.json; with none it walks past the
  repo root and crashes reading `/package.json`. The shim's own package.json
  supplies the name.
- **Nothing emits declarations.** Without them every `<Name>Props` interface
  comes out empty, and those interfaces are the API contract the design agent
  codes against. `prepare-pkg.mjs` runs `tsc --emitDeclarationOnly` over the ui
  tree; declaration emit is clean (exit 0) and preserves the CVA variant unions
  (`variant?: "default" | "destructive" | …`), which is most of their value.
- **`cfg.cssEntry` and `cfg.docsDir` are bounded to the *real* package
  directory.** `resources/` inside the shim is a symlink out to the app, so
  anything reached through it is rejected as escaping the package. That is why
  the stylesheet, its fonts, and the docs are **copied** into
  `assets/{css,fonts,docs}` rather than linked. `srcDir` and `tsconfig` are not
  bounded that way, so those stay symlinks and the components are the real
  files.

## The palette is not on `:root`

The single most important thing about this design system: in `app.css` the real
Avyo palette (warm paper `#f3ecdd`, dark forest `#17352f`, tomato `#d6533c`,
cobalt `#3155a5`) lives on **`.product-shell`**, a class applied exactly once —
on `SidebarProvider` in `layouts/app/app-sidebar-layout.tsx`. `:root` keeps the
stock shadcn neutrals so the auth screens and transactional email can make their
own contrast decisions.

A design system that renders grey unless you remember a wrapper class is a
design system that renders grey. So `build-css.mjs` **copies the
`.product-shell` declarations up to `:root`** at build time (38 tokens), plus
`.dark .product-shell` → `.dark` and the pill-button rule
`.product-shell [data-slot='button']` → `[data-slot='button']`.

It copies rather than restates them: a hand-written second copy of 38 colour
tokens goes stale the first time someone retunes the palette, and nothing
anywhere fails. If `ruleBody()` throws `selector ".product-shell" not found`,
someone renamed or restructured that rule — fix the selector in
`build-css.mjs`, do not inline the values.

**`--destructive` is deliberately not in that set.** `.product-shell` never
overrides it, so destructive buttons and badges are the stock shadcn red against
the warm palette. That is what the app does. Do not "fix" it here.

## Tailwind is JIT, so the vocabulary is generated

Tailwind v4 only emits a utility it has seen in a scanned file. Correct for the
app; wrong for a design system, whose whole job is to be built with by someone
whose markup does not exist yet. `.design-sync/safelist.css` generates the
vocabulary up front — semantic theme colours across the families that carry
colour work, plus the layout/spacing/type families a page needs. Compiled
stylesheet is ~1.2 MB.

Two things learned the hard way, both already fixed in `safelist.css`:

- **Opacity modifiers must be safelisted per family.** `PlaceholderPattern` is a
  hatch whose only colour comes from a `stroke-*` utility; `stroke-border/70`
  rendered completely invisible until `{stroke,fill}-…/{…}` was added.
- **Arbitrary values can never be safelisted** (`bg-[#abc]`, `w-[37px]`). They
  compile only from scanned sources. This is why `conventions.md` points the
  agent at token names rather than raw hex.

If a future preview or design comes out unstyled, check the class against the
compiled CSS before assuming a bundle problem:
`grep -c 'gap-6 {' app/resources/css/.ds-compiled.css`.

## Known render warns (checked on every re-sync — an unrecorded warn is new)

- **`[FONT_MISSING] "Cambria"`** — from Tailwind's default `--font-serif` stack
  (`ui-serif, Georgia, Cambria, …`). A system font, not a brand font, and
  nothing references it in practice. The brand family, Instrument Sans, ships
  complete: 6 woff2 files, weights 400/500/600, latin + latin-ext. Accepted, no
  action.
- **`Select` sm and default triggers look identical.** The source sets
  `data-[size=default]:h-11` *and* `data-[size=sm]:h-11`. Faithful, not a
  preview fault. Worth raising with the app team separately.
- **`SidebarMenuSkeleton` is very low contrast.** Skeleton tone sits close to
  `--sidebar` in the dark forest column. That is how it looks in the app.

## Component gotchas found while authoring previews

- **`Tooltip` does not wrap its own provider**, unlike stock shadcn. It throws
  ``` `Tooltip` must be used within `TooltipProvider` ``` outside one. Mount
  `TooltipProvider` once near the app root.
- **`Toaster` needs `sonner` in `cfg.extraEntries`.** Without it a preview
  importing `toast` from `'sonner'` bundles a *second* copy of sonner with its
  own store, and toasts fired against it never reach the bundled `Toaster` — no
  error, just nothing. The build prints
  `[EXPORT_COLLISION] sonner exports … Toaster`, which is expected and harmless:
  we want the DS's `Toaster`, and only `toast` from sonner itself.
- **`Icon` takes the lucide component as `iconNode`, not an element**, and
  returns `null` when it is missing — a bare `<Icon />` renders nothing.
- **Overlays need a card mode.** `Dialog`, `Sheet`, `DropdownMenu` and `Tooltip`
  portal to `document.body` and position `fixed`, so they escape a grid cell.
  All are `cardMode: single` with a viewport. Keep a visible trigger in the
  composition — it is what keeps the card's own root non-empty.
- **Wide compositions** (`Card`, `Table`, `Sidebar`, `Select`,
  `SidebarMenuSkeleton`) are `cardMode: column`.
- **Inline SVG data URIs**: write plain `#` in the markup and let
  `encodeURIComponent` escape it. Pre-escaping to `%23` double-encodes and every
  fill silently resolves black (cost one Avatar iteration).

## Not synced

- The 21 app-level components in `components/` (not `components/ui/`). 13 import
  Inertia or the generated `@/routes/*` wayfinder helpers and cannot render
  without a page context; the other 8 are app furniture, not design system.
- Interaction-only states (hover, drag, focus-follows-pointer). Where a state
  could be forced statically it was (`defaultOpen`, `defaultPressed`,
  `defaultChecked`, `aria-invalid`).

## Re-sync risks — what can silently go stale

- **`prepare-pkg.mjs` must run first.** `node_modules` is gitignored, so on a
  fresh clone or after `npm ci` the shim is simply gone and the build fails at
  `[NO_DIST]` or the `/package.json` crash. Not subtle, but the fix is one
  command and worth knowing before debugging.
- **The `:root` promotion is a build-time copy of `app.css`.** Retuning the
  palette flows through automatically; *renaming or restructuring*
  `.product-shell` breaks `ruleBody()` loudly. Both are fine. The bad case would
  be someone inlining the values here — do not.
- **The safelist is a fixed guess at a vocabulary.** New utility families the
  app starts using (a new colour token, a wider spacing step) are picked up
  automatically for *components*, because Tailwind scans the source. They are
  **not** picked up for the design agent's own markup unless added to
  `safelist.css`. Adding a token to `@theme` is the moment to check.
- **Authored previews reference real props.** Renaming a prop or dropping a
  variant makes a preview render wrong rather than fail. The grades are keyed to
  the preview source, so a changed `.tsx` re-grades — but a changed *component*
  with an unchanged preview will carry its old grade forward. On a major refactor
  of `ui/`, re-grade deliberately with
  `package-capture.mjs --components <picks> --spot-check-components <picks>`.
- **Toolchain assumed:** node 26, Tailwind 4.3.3 (`@source inline()` needs
  ≥ 4.1), playwright chromium in `~/Library/Caches/ms-playwright`. Converter deps
  are installed into `.ds-sync/`, isolated from the app's lockfile.
- **94 components ship the floor card** — every compound sub-part
  (`DialogContent`, `SidebarMenuButton`, `TableCell`, …). They are fully
  importable and carry real `.d.ts` + `.prompt.md`; only the preview card is
  unauthored. Authoring any of them on a later re-sync is purely additive.
