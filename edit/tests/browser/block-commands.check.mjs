// EDT-B: duplicate/move whole blocks inside the drop-in editor's document.
import { readFileSync } from "node:fs";
import { join } from "node:path";

export default async function ({ browser, here }) {
  const problems = [];
  const editRoot = join(here, "..", "..");
  const page = await browser.newPage();
  page.on("pageerror", (error) => problems.push({ check: "page error", message: error.message }));
  await page.route("http://wysite.test/**", (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/") return route.fulfill({ contentType: "text/html", body: "<!doctype html><html><body><div id=host></div></body></html>" });
    return route.fulfill({ contentType: "text/javascript", body: readFileSync(join(editRoot, path.slice("/edit/".length))) });
  });
  await page.goto("http://wysite.test/");
  const result = await page.evaluate(async () => {
    const pm = await import("/edit/assets/pm-bundle.js");
    const { buildSchema, createDocument, serializeDocument } = await import("/edit/assets/html-fidelity.js");
    const { duplicateBlock, moveBlock } = await import("/edit/assets/block-commands.js");
    const schema = buildSchema();
    const html = '<div class="card" id="c1" style="padding:4px" data-x="1"><h3>One</h3><p><label for="f1">L</label></p></div><p>After</p>';
    const parsed = createDocument(schema, html);
    let state = pm.state.EditorState.create({ schema, doc: parsed.doc });
    state = state.apply(state.tr.setSelection(pm.state.NodeSelection.create(state.doc, 0)));
    let out = null;
    duplicateBlock(state, (tr) => { state = state.apply(tr); });
    out = serializeDocument(schema, state.doc, parsed.segments).html;
    const afterDuplicate = out;
    moveBlock(1)(state, (tr) => { state = state.apply(tr); });
    const afterMove = serializeDocument(schema, state.doc, parsed.segments).html;
    return { afterDuplicate, afterMove };
  });
  if (!result.afterDuplicate.includes('id="c1-2"') || !result.afterDuplicate.includes('for="f1"') || (result.afterDuplicate.match(/class="card"/g) || []).length !== 2 || !result.afterDuplicate.includes('style="padding:4px" data-x="1"')) {
    problems.push({ check: "duplicate keeps attributes and makes ids unique", html: result.afterDuplicate });
  }
  if (!/<p>After<\/p>\s*<div class="card" id="c1-2"/.test(result.afterMove)) {
    problems.push({ check: "move down swaps with the next block", html: result.afterMove });
  }
  await page.close();
  return problems;
}
