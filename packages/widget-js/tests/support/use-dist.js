// Runs the widget suite against the MINIFIED build instead of the source:
//
//   npm run test:dist        (node --require ./tests/support/use-dist.js --test)
//
// Every test loads the widget as ../src/wayfindr-widget.js -- by require(), or
// by reading the file to evaluate it in a page. This preload sends both to
// dist/wayfindr-widget.min.js, so the same 400-odd assertions that pin the
// source's behaviour also pin the file the server actually serves. Minifying
// renames and restructures code; this is what proves it changed nothing a
// visitor can see.
'use strict';

const fs = require('node:fs');
const Module = require('node:module');
const path = require('node:path');

const source = path.join(__dirname, '../../src/wayfindr-widget.js');
const built = path.join(__dirname, '../../dist/wayfindr-widget.min.js');

if (!fs.existsSync(built)) {
  throw new Error(`The minified widget is missing at ${built}. Run \`npm run build\` first.`);
}

const resolveFilename = Module._resolveFilename;
Module._resolveFilename = function (request, parent, ...rest) {
  const resolved = resolveFilename.call(this, request, parent, ...rest);

  return resolved === source ? built : resolved;
};

const readFileSync = fs.readFileSync;
fs.readFileSync = function (file, ...rest) {
  return readFileSync.call(this, typeof file === 'string' && path.resolve(file) === source ? built : file, ...rest);
};
