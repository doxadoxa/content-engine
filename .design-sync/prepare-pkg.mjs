/*
 * Presents this Laravel app's UI kit to the converter as if it were a
 * published package.
 *
 * The converter is built for design systems that ship as an npm package: it
 * resolves `<pkg>` under node_modules, reads the component list out of the
 * shipped `.d.ts` tree, and bundles the published entry. None of that exists
 * here — the components live in `resources/js/components/ui`, the app's
 * package.json has no `name` (which sends the type-extraction walk past the
 * repo root and into `/package.json`), and nothing emits declarations.
 *
 * Rather than bend the app to suit the tool, this builds a shim package at
 * `app/node_modules/avyo` that points back at the real source:
 *
 *   package.json   name + `types`, so the converter has a named package to
 *                  anchor on and a declaration tree to read
 *   resources/     symlink to the real source — `cfg.srcDir` resolves through
 *                  it, so components are the actual files, never copies
 *   tsconfig.json  symlink, so `@/*` path aliases resolve during bundling
 *   dts/           declarations emitted from the .tsx source
 *
 * The declarations are the point. Without them the emitted `<Name>Props`
 * interfaces collapse to nothing, and those interfaces are the API contract
 * the claude.ai/design agent codes against — a component with no props
 * documented is a component it will only ever use bare.
 *
 * Everything this writes lives inside node_modules, so it is gitignored and
 * has to be rebuilt after a fresh clone or `npm ci`. That is what
 * `.design-sync/NOTES.md` means by the prepare step being part of re-sync.
 */

import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, lstatSync, mkdirSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '..');
const APP = join(REPO, 'app');
const UI = join(APP, 'resources', 'js', 'components', 'ui');
const SHIM = join(APP, 'node_modules', 'avyo');

// A previous run may have left a bare symlink here (or a stale tree).
if (existsSync(SHIM) || lstatSync(SHIM, { throwIfNoEntry: false })) {
    rmSync(SHIM, { recursive: true, force: true });
}
mkdirSync(join(SHIM, 'dts'), { recursive: true });

symlinkSync(join('..', '..', 'resources'), join(SHIM, 'resources'));
symlinkSync(join('..', '..', 'tsconfig.json'), join(SHIM, 'tsconfig.json'));

// ── barrel ────────────────────────────────────────────────────────────────
// One `export *` per ui module. tsc resolves these against the emitted tree,
// so a name exported from two modules would surface here as an ambiguity
// rather than silently picking one.
const modules = readdirSync(UI)
    .filter((f) => f.endsWith('.tsx'))
    .map((f) => f.replace(/\.tsx$/, ''))
    .sort();

writeFileSync(
    join(SHIM, 'src-index.ts'),
    modules.map((m) => `export * from './resources/js/components/ui/${m}';`).join('\n') + '\n',
    'utf8',
);

// ── declarations ──────────────────────────────────────────────────────────
const tsconfig = join(SHIM, 'tsconfig.dts.json');
writeFileSync(
    tsconfig,
    JSON.stringify(
        {
            extends: join(APP, 'tsconfig.json'),
            compilerOptions: {
                noEmit: false,
                declaration: true,
                emitDeclarationOnly: true,
                skipLibCheck: true,
                outDir: join(SHIM, 'dts'),
                rootDir: APP,
            },
            include: [join(SHIM, 'src-index.ts'), `${UI}/**/*.tsx`],
        },
        null,
        2,
    ),
    'utf8',
);

execFileSync(join(APP, 'node_modules', '.bin', 'tsc'), ['-p', tsconfig], { stdio: 'inherit' });

// rootDir is the app dir, so emitted paths mirror the source layout. The
// barrel itself lands beside node_modules/avyo — normalize to a stable entry.
const emittedBarrel = join(SHIM, 'dts', 'node_modules', 'avyo', 'src-index.d.ts');
if (!existsSync(emittedBarrel)) {
    throw new Error(`prepare-pkg: expected barrel declarations at ${emittedBarrel}`);
}
writeFileSync(
    join(SHIM, 'dts', 'index.d.ts'),
    modules.map((m) => `export * from './resources/js/components/ui/${m}';`).join('\n') + '\n',
    'utf8',
);

// ── stylesheet + fonts ────────────────────────────────────────────────────
// The converter bounds `cfg.cssEntry` to the *real* package directory, and
// `resources/` here is a symlink pointing out of it — so a cssEntry reached
// through that symlink is rejected as escaping the package. Copy the compiled
// stylesheet in instead, along with the woff2 files its @font-face rules
// reference, keeping their `../fonts/…` relative paths intact.
execFileSync(process.execPath, [join(HERE, 'build-css.mjs')], { stdio: 'inherit' });

mkdirSync(join(SHIM, 'assets', 'css'), { recursive: true });
cpSync(join(APP, 'resources', 'css', '.ds-compiled.css'), join(SHIM, 'assets', 'css', 'ds.css'));
cpSync(join(APP, 'resources', 'fonts'), join(SHIM, 'assets', 'fonts'), { recursive: true });

// ── component docs ────────────────────────────────────────────────────────
// Same containment rule as the stylesheet: `cfg.docsDir` has to resolve
// inside the package, so the durable docs in .design-sync/docs are copied in.
// gen-docs only fills gaps, so hand-written bodies survive.
execFileSync(process.execPath, [join(HERE, 'gen-docs.mjs')], { stdio: 'inherit' });
cpSync(join(HERE, 'docs'), join(SHIM, 'assets', 'docs'), { recursive: true });

writeFileSync(
    join(SHIM, 'package.json'),
    JSON.stringify(
        {
            name: 'avyo',
            version: '0.0.0',
            private: true,
            description: 'design-sync shim over app/resources/js/components/ui — generated, not published',
            types: './dts/index.d.ts',
        },
        null,
        2,
    ) + '\n',
    'utf8',
);

const count = readdirSync(join(SHIM, 'dts', 'resources', 'js', 'components', 'ui')).filter((f) =>
    f.endsWith('.d.ts'),
).length;
console.error(`» shim at ${SHIM} — ${modules.length} modules, ${count} declaration files`);
