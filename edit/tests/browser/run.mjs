// Browser-verified security checks. Every mXSS vector goes through the real PHP
// sanitizer and the output is loaded in Chromium: no dialog may open and no
// script or event-handler attribute may exist in the live DOM.
import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
let chromium;
try {
  ({ chromium } = require("playwright"));
} catch {
  ({ chromium } = require(join(execFileSync("npm", ["root", "-g"]).toString().trim(), "playwright")));
}

const here = dirname(fileURLToPath(import.meta.url));
const vectorsFile = join(here, "..", "fixtures", "mxss-vectors.txt");
const lines = (await import("node:fs")).readFileSync(vectorsFile, "utf8").split("\n").filter(Boolean);
const outputs = JSON.parse(execFileSync("php", [join(here, "sanitize.php"), vectorsFile]).toString());

const browser = await chromium.launch();
const failures = [];
try {
  const page = await browser.newPage();
  let fired = false;
  page.on("dialog", async (dialog) => {
    fired = true;
    await dialog.dismiss();
  });

  for (let i = 0; i < lines.length; i++) {
    fired = false;
    await page.setContent(`<!doctype html><html><body>${outputs[i]}</body></html>`);
    await page.waitForTimeout(60);
    const live = await page.evaluate(() => {
      const handlers = [];
      for (const el of document.querySelectorAll("*")) {
        for (const attr of el.attributes) {
          if (attr.name.toLowerCase().startsWith("on")) handlers.push(`${el.localName}[${attr.name}]`);
        }
      }
      return { scripts: document.scripts.length, handlers };
    });
    if (fired || live.scripts > 0 || live.handlers.length > 0) {
      failures.push({ vector: JSON.stringify(lines[i]), output: outputs[i], fired, ...live });
    }
  }

  // Extra suites (style carry, public CSP, ...) live next to this file as *.check.mjs.
  for (const file of readdirSync(here).filter((name) => name.endsWith(".check.mjs")).sort()) {
    const mod = await import(join(here, file));
    const problems = await mod.default({ browser, here });
    for (const problem of problems) failures.push({ suite: file, ...problem });
  }
} finally {
  await browser.close();
}

if (failures.length) {
  console.error(`${failures.length} browser check(s) failed:`);
  for (const failure of failures) console.error(JSON.stringify(failure));
  process.exit(1);
}
console.log(`Browser checks passed (${lines.length} sanitizer vectors).`);
