// Run the editor round-trip test under jsdom (for CI and machines without a browser):
//   cd edit/tests/js && npm install --no-save jsdom@24 && node run-node.mjs
// The browser version is editor-roundtrip.html (open it through any web server).
import { JSDOM } from "jsdom";
import { cpSync, mkdirSync, mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const edit = join(here, "..", "..");

// The app's assets have no package.json, so copy them next to a {"type":"module"}
// manifest for Node to load them as ES modules.
const work = mkdtempSync(join(tmpdir(), "wysite-js-"));
mkdirSync(join(work, "edit", "tests"), { recursive: true });
cpSync(join(edit, "assets"), join(work, "edit", "assets"), { recursive: true });
cpSync(here, join(work, "edit", "tests", "js"), { recursive: true, filter: (src) => !src.includes("node_modules") });
writeFileSync(join(work, "package.json"), JSON.stringify({ type: "module" }));

const dom = new JSDOM('<!doctype html><html><body><pre id="results">running</pre><div id="stage"></div></body></html>', {
  url: "http://localhost/",
  pretendToBeVisual: true,
});
const { window } = dom;
globalThis.window = window;
for (const key of Object.getOwnPropertyNames(window)) {
  if (!(key in globalThis)) {
    try {
      Object.defineProperty(globalThis, key, { get: () => window[key], configurable: true });
    } catch {}
  }
}
window.confirm = () => true;
// jsdom has no layout engine; ProseMirror only needs these to exist.
window.HTMLElement.prototype.scrollIntoView = () => {};
window.document.elementFromPoint = () => null;
window.Element.prototype.getClientRects = function () { return []; };
window.Range.prototype.getClientRects = function () { return []; };
window.Range.prototype.getBoundingClientRect = function () { return { left: 0, right: 0, top: 0, bottom: 0, width: 0, height: 0 }; };

let exitCode = 1;
try {
  await import(join(work, "edit", "tests", "js", "editor-roundtrip.js"));
  exitCode = window.document.title === "PASS" ? 0 : 1;
} catch (error) {
  console.error(error);
}
console.log(window.document.getElementById("results").textContent);
rmSync(work, { recursive: true, force: true });
process.exit(exitCode);
