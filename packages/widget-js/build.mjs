// Builds dist/wayfindr-widget.min.js from src/wayfindr-widget.js.
//
// The widget is the one file every visitor of every page of every install
// downloads, and the source is written to be read -- about half of it is
// comments. The server serves this minified build (WidgetScriptController); the
// source stays the thing people edit and review.
//
// Committed rather than built at deploy time, so no install -- image or Forge
// host -- needs Node to serve the widget. CI runs `--check` (`npm run
// check:build`), which builds in memory and fails when the file on disk -- in a
// CI checkout, the committed one -- is missing or differs, so a stale build
// cannot merge. That compares against the file rather than asking git, because
// `git diff` cannot see a build that was never committed at all. terser is
// pinned exactly and pure JavaScript: the same version gives byte-identical
// output on every machine, with no install script or native binary behind it.
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';

const root = dirname(fileURLToPath(import.meta.url));
const source = join(root, 'src/wayfindr-widget.js');
const output = join(root, 'dist/wayfindr-widget.min.js');

const result = await minify(await readFile(source, 'utf8'), {
  ecma: 2017,
  compress: { passes: 2 },
  // Local names only. Property names are the widget's public API
  // (window.Wayfindr, init options, server payloads) and are never mangled.
  mangle: true,
  format: {
    comments: false,
    preamble: '/*! Wayfindr widget (MIT). Built from src/wayfindr-widget.js by `npm run build` -- edit the source, not this file. */',
  },
});

const built = `${result.code}\n`;

if (process.argv.includes('--check')) {
  const committed = await readFile(output, 'utf8').catch(() => null);

  if (committed !== built) {
    console.error(committed === null
      ? 'dist/wayfindr-widget.min.js is missing. Run `npm run build` in packages/widget-js and commit the result.'
      : 'dist/wayfindr-widget.min.js does not match src/wayfindr-widget.js. Run `npm run build` in packages/widget-js and commit the result.');
    process.exit(1);
  }

  console.log('dist/wayfindr-widget.min.js matches its source.');
} else {
  await mkdir(dirname(output), { recursive: true });
  await writeFile(output, built);
}
