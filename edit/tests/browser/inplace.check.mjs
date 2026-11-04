// EDT-E: editing in place keeps the element's own styles while typing, saves
// the edited HTML, and cancel restores the exact markup and attributes.
import { readFileSync } from "node:fs";
import { join } from "node:path";

const FIXTURE = `<!doctype html><html><head><style>
  .grid > .card { background-color: rgb(10, 120, 200); padding: 30px; }
  .grid > .card h3 { color: rgb(255, 255, 0); }
</style></head><body><div class="grid"><div class="card" id="c1" data-x="1"><h3>Title</h3><p>Body text</p></div></div></body></html>`;

export default async function ({ browser, here }) {
  const problems = [];
  const editRoot = join(here, "..", "..");
  const page = await browser.newPage();
  page.on("pageerror", (error) => problems.push({ check: "page error", message: error.message }));
  await page.route("http://wysite.test/**", (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/") return route.fulfill({ contentType: "text/html", body: FIXTURE });
    return route.fulfill({ contentType: "text/javascript", body: readFileSync(join(editRoot, path.slice("/edit/".length))) });
  });
  await page.goto("http://wysite.test/");
  const result = await page.evaluate(async () => {
    const { DropInWysiwyg } = await import("/edit/assets/dropin-wysiwyg.js");
    const card = document.getElementById("c1");
    const before = { html: card.innerHTML, attrs: Array.from(card.attributes, (a) => `${a.name}=${a.value}`).join(" ") };
    const look = () => [getComputedStyle(card).backgroundColor, getComputedStyle(card).paddingTop, getComputedStyle(card.querySelector("h3")).color].join(" ");
    const lookBefore = look();

    let saved = null;
    let instance = await DropInWysiwyg.mount(card, { inPlace: true, restoreHTML: card.innerHTML, onSave: async ({ html }) => { saved = html; } });
    const lookDuring = look();
    const editable = card.getAttribute("contenteditable");
    await instance.cancel();
    const afterCancel = { html: card.innerHTML, attrs: Array.from(card.attributes, (a) => `${a.name}=${a.value}`).join(" ") };

    instance = await DropInWysiwyg.mount(card, { inPlace: true, restoreHTML: card.innerHTML, onSave: async ({ html }) => { saved = html; } });
    const { state } = instance.view;
    instance.view.dispatch(state.tr.insertText(" more", state.doc.content.size - 1));
    await instance.save();
    return { before, lookBefore, lookDuring, editable, afterCancel, saved, afterSave: card.innerHTML, attrsAfterSave: Array.from(card.attributes, (a) => `${a.name}=${a.value}`).join(" ") };
  });

  if (result.lookDuring !== result.lookBefore) problems.push({ check: "card keeps its look while editing", before: result.lookBefore, during: result.lookDuring });
  if (result.editable !== "true") problems.push({ check: "the card itself is editable", editable: result.editable });
  if (result.afterCancel.html !== result.before.html || result.afterCancel.attrs !== result.before.attrs) problems.push({ check: "cancel restores markup and attributes", ...result });
  if (!result.saved || !result.saved.includes("Body text more") || !result.saved.includes("<h3>Title</h3>")) problems.push({ check: "save returns the edited HTML", saved: result.saved });
  if (result.attrsAfterSave !== result.before.attrs || result.afterSave !== result.saved) problems.push({ check: "save leaves the element with its own attributes", ...result });
  await page.close();
  return problems;
}
