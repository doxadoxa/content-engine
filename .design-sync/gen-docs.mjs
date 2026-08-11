/*
 * Seeds one doc file per component, so the component picker has sections.
 *
 * The converter groups a component by the directory its source sits in. Every
 * one of these lives in the same flat `components/ui/` folder, so without
 * help all 124 exports land in a single "general" pile — which is not a
 * design system anyone can find anything in.
 *
 * A doc file's `category:` frontmatter overrides that grouping. A file with
 * *only* frontmatter still gets its body synthesized from the .d.ts, the
 * source JSDoc and the authored preview, so these seeds buy grouping without
 * costing documentation.
 *
 * Existing files are never overwritten: once a stub grows a real body (usage
 * notes, composition guidance), that body is the durable thing and this script
 * must not clobber it. Re-run it after adding components — it only fills gaps.
 */

import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '..');
const UI = join(REPO, 'app', 'resources', 'js', 'components', 'ui');
const DOCS = join(HERE, 'docs');

/** Source module → picker section. */
const CATEGORY = {
    alert: 'Feedback',
    avatar: 'Data Display',
    badge: 'Data Display',
    breadcrumb: 'Navigation',
    button: 'Actions',
    card: 'Layout',
    checkbox: 'Forms',
    collapsible: 'Disclosure',
    dialog: 'Overlays',
    'dropdown-menu': 'Overlays',
    icon: 'Data Display',
    input: 'Forms',
    label: 'Forms',
    'navigation-menu': 'Navigation',
    'placeholder-pattern': 'Utilities',
    progress: 'Feedback',
    select: 'Forms',
    separator: 'Layout',
    sheet: 'Overlays',
    sidebar: 'Navigation',
    skeleton: 'Feedback',
    sonner: 'Feedback',
    spinner: 'Feedback',
    table: 'Data Display',
    textarea: 'Forms',
    toggle: 'Forms',
    'toggle-group': 'Forms',
    tooltip: 'Overlays',
};

mkdirSync(DOCS, { recursive: true });

let written = 0;
let kept = 0;
const uncategorized = [];

for (const file of readdirSync(UI).filter((f) => f.endsWith('.tsx'))) {
    const mod = file.replace(/\.tsx$/, '');
    const category = CATEGORY[mod];
    if (!category) {
        uncategorized.push(mod);
        continue;
    }

    // Every PascalCase value export in the module gets its own card, so each
    // one needs its own doc file for the category to reach it.
    const src = readFileSync(join(UI, file), 'utf8');
    const names = new Set();
    // `export { A, B as C }` — the trailing-list form most of these use.
    for (const m of src.matchAll(/^export\s*\{([^}]*)\}/gm)) {
        for (const raw of m[1].split(',')) {
            const name = raw.trim().split(/\s+as\s+/).pop()?.trim();
            if (name && /^[A-Z][A-Za-z0-9]*$/.test(name)) names.add(name);
        }
    }
    // `export function Foo` / `export const Foo` — Icon and PlaceholderPattern
    // declare their export inline rather than listing it at the bottom.
    for (const m of src.matchAll(/^export\s+(?:default\s+)?(?:function|const|let|var|class)\s+([A-Z][A-Za-z0-9]*)/gm)) {
        names.add(m[1]);
    }

    for (const name of names) {
        const path = join(DOCS, `${name}.md`);
        if (existsSync(path)) {
            kept++;
            continue;
        }
        writeFileSync(path, `---\ncategory: ${category}\n---\n`, 'utf8');
        written++;
    }
}

console.error(`» docs: ${written} seeded, ${kept} left alone → ${DOCS}`);
if (uncategorized.length) {
    console.error(`  ! no category mapped for: ${uncategorized.join(', ')} — add them to CATEGORY in gen-docs.mjs`);
}
