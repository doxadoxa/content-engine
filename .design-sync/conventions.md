# Building with Avyo

Avyo is a content-operations product: editorial pipelines, approval queues,
multi-locale publishing. The look is warm paper and dark forest, not the usual
white-and-blue SaaS. Components are shadcn/ui "new-york" over Radix, styled with
Tailwind v4 utilities that resolve to semantic CSS variables.

## Setup

**No theme wrapper is needed.** The Avyo palette is on `:root`, so components are
on-brand as soon as `styles.css` is loaded. Three components do need a provider:

- `Tooltip` — must be inside `TooltipProvider`. It does **not** wrap its own,
  unlike stock shadcn; outside one it throws. Mount `TooltipProvider` once at the
  app root.
- `Sidebar` and every `Sidebar*` part — must be inside `SidebarProvider`, which
  owns the open/collapsed state and column width.
- `Toaster` — mount once at the app root, then call `toast(...)`,
  `toast.success(...)`, `toast.error(...)`. Both are on the bundle global.

Dark mode: put `class="dark"` on a wrapping element. Every token flips.

## Style with utilities over semantic tokens

Write Tailwind classes, and reach for the **semantic** colour names rather than
raw hex or Tailwind's default ramp — they are what carries the brand and what
flips in dark mode. These all exist in the shipped stylesheet:

| Family | Tokens |
|---|---|
| Surfaces | `background`, `card`, `popover`, `muted`, `accent`, `secondary` |
| Text on them | `foreground`, `card-foreground`, `popover-foreground`, `muted-foreground`, `accent-foreground`, `secondary-foreground` |
| Emphasis | `primary` / `primary-foreground`, `destructive` / `destructive-foreground` |
| Lines & focus | `border`, `input`, `ring` |
| Charts / status | `chart-1` … `chart-5` |
| Nav column | `sidebar`, `sidebar-foreground`, `sidebar-accent`, `sidebar-accent-foreground`, `sidebar-primary`, `sidebar-border`, `sidebar-ring` |

Each combines with a family prefix: `bg-`, `text-`, `border-`, `ring-`, `fill-`,
`stroke-`, plus `hover:`, `focus-visible:`, `disabled:`, `dark:`, `md:`, `lg:`
and `/50`-style opacity. So: `bg-card text-card-foreground`,
`border-border`, `text-muted-foreground`, `hover:bg-accent`, `bg-primary/90`.

Raw brand values exist as plain CSS variables for the rare case a token does not
fit — `var(--brand-ink)`, `--brand-canvas`, `--brand-surface`, `--brand-violet`,
`--brand-coral`, `--brand-violet-wash`, `--brand-border`. Prefer the tokens.

Radius: `rounded-sm` 8px, `rounded-md` 10px, `rounded-lg` / `rounded-xl` 12px —
`rounded-lg` or `rounded-xl` on containers, `rounded-full` for pills and
avatars. Type is Instrument Sans via `font-sans`; `tabular-nums` for figures in
tables.

**One constraint:** arbitrary values (`bg-[#abc]`, `w-[37px]`) do not resolve —
the stylesheet is pre-generated. Use the scale (`gap-6`, `max-w-3xl`, `p-8`,
`md:grid-cols-3`) and the token names above.

## Where the truth is

- `_ds/<folder>/styles.css` and its imports — every utility and token that
  actually exists. Grep it before inventing a class name.
- `components/<group>/<Name>/<Name>.d.ts` — the props contract, with real
  variant unions (`variant?: "default" | "destructive" | "outline" | …`).
- `components/<group>/<Name>/<Name>.prompt.md` — per-component usage.

Compound components are separate exports, not dotted: `CardHeader`, not
`Card.Header`.

## Idiomatic example

```jsx
<Card className="max-w-md">
  <CardHeader>
    <CardTitle>Weekly content plan</CardTitle>
    <CardDescription>Twelve briefs queued across three locales.</CardDescription>
  </CardHeader>
  <CardContent className="grid grid-cols-2 gap-4">
    <div>
      <p className="text-xs text-muted-foreground">Published</p>
      <p className="text-2xl font-semibold tabular-nums">128</p>
    </div>
    <div>
      <p className="text-xs text-muted-foreground">In review</p>
      <p className="text-2xl font-semibold tabular-nums">7</p>
    </div>
  </CardContent>
  <CardFooter className="gap-3">
    <Button size="sm">Review queue</Button>
    <Button size="sm" variant="ghost">Skip this week</Button>
  </CardFooter>
</Card>
```

Library components for the controls; the utilities above for your own layout
glue. Realistic copy beats lorem ipsum — this product is about articles,
locales, drafts and approvals.
