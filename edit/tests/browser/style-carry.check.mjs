// EDT-A/C: duplicate a card in place (same look, unique ids), then paste it
// into another section where its contextual CSS no longer applies and keep its
// original look, at a wide and a narrow viewport (re-scoped media queries).
import { readFileSync } from "node:fs";
import { join } from "node:path";

const FIXTURE = `<!doctype html><html><head><style>
  #features .grid > .card { padding: 24px; background-color: rgb(240, 200, 100); border-radius: 12px; }
  @media (min-width: 700px) { #features .grid > .card { padding: 40px; } }
  #features .card h3 { color: rgb(200, 0, 0); letter-spacing: 2px; }
</style></head><body>
<div class="wysite-admin-bar"><div class="wysite-admin-bar__actions"></div></div>
<div data-wysite-edit-slot="page-content">
  <section id="features"><div class="grid"><div class="card" id="c1"><h3>One</h3><label for="f1">Name</label><input id="f1"></div></div></section>
  <section id="other"><p>Other section</p></section>
</div>
<script type="module">
  import { setupSectionTools } from "/edit/assets/section-tools.js";
  window.calls = [];
  setupSectionTools({
    context: { appUrl: "/edit", csrfToken: "t", pagePath: "x.html", blocks: [{ name: "page-content" }], isAdmin: true },
    toast: (m) => window.calls.push(["toast", m]),
    saveBlock: async (name, html) => { window.calls.push(["save", name, html]); return {}; },
    postJson: async (url, body) => { window.calls.push(["post", url, body]); return { ok: true }; },
    fetchJson: async () => ({ theme: [], saved: [] }),
    isEditorOpen: () => false,
    openEditor: () => {},
  });
  window.ready = true;
</script></body></html>`;

export default async function ({ browser, here }) {
  const problems = [];
  const editRoot = join(here, "..", "..");
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  page.on("pageerror", (error) => problems.push({ check: "page error", message: error.message }));
  await page.route("http://wysite.test/**", (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === "/") return route.fulfill({ contentType: "text/html", body: FIXTURE });
    if (path.startsWith("/edit/assets/")) {
      return route.fulfill({ contentType: "text/javascript", body: readFileSync(join(editRoot, path.slice("/edit/".length))) });
    }
    return route.fulfill({ status: 404, body: "" });
  });
  await page.goto("http://wysite.test/");
  await page.waitForFunction(() => window.ready === true);

  const style = (selector) => page.$eval(selector, (el) => {
    const s = getComputedStyle(el);
    return [s.paddingTop, s.backgroundColor, s.borderTopLeftRadius].join(" ");
  });
  const clickButton = (label) => page.evaluate((text) => {
    const button = Array.from(document.querySelectorAll(".wysite-section-toolbar button, .wysite-section-choice button")).find((b) => b.textContent.trim() === text);
    if (!button) throw new Error(`No button ${text}`);
    button.click();
  }, label);

  await page.click("text=Arrange");
  await page.click("#c1 h3");
  await page.keyboard.press("Alt+ArrowUp");
  const selectedIsCard = await page.$eval(".wysite-section-selected", (el) => el.id === "c1");
  if (!selectedIsCard) problems.push({ check: "Alt+ArrowUp selects the card" });

  // Duplicate in place
  await clickButton("⧉ Duplicate");
  const cards = await page.$$eval("#features .card", (els) => els.map((el) => ({ id: el.id, input: el.querySelector("input").id, label: el.querySelector("label").htmlFor })));
  if (cards.length !== 2 || cards[1].id !== "c1-2" || cards[1].input !== "f1-2" || cards[1].label !== "f1-2") {
    problems.push({ check: "duplicate gives unique ids and follows label references", cards });
  }
  if ((await style("#c1")) !== (await style("#c1-2"))) problems.push({ check: "duplicate looks the same" });
  const saved = await page.evaluate(() => window.calls.filter((c) => c[0] === "save").pop());
  if (!saved || !saved[2].includes('id="c1-2"') || saved[2].includes("wysite-section")) {
    problems.push({ check: "duplicate saves the block without tool classes", saved });
  }

  // Copy the card, paste it into the other section, keep its look
  await page.click("#c1 h3");
  await page.keyboard.press("Alt+ArrowUp");
  await clickButton("⎘ Copy");
  await page.click("#other p");
  await clickButton("Paste after");
  await page.waitForSelector(".wysite-section-choice");
  await clickButton("Keep original look");
  await page.waitForFunction(() => window.calls.some((c) => c[0] === "post" && c[1].includes("save-component-css")));
  const pasted = "#other .card";
  if ((await style(pasted)) !== (await style("#c1"))) problems.push({ check: "pasted card keeps its look at 1280px", source: await style("#c1"), pasted: await style(pasted) });
  const headingColor = await page.$eval(`${pasted} h3`, (el) => getComputedStyle(el).color);
  if (headingColor !== "rgb(200, 0, 0)") problems.push({ check: "descendant styles carried", headingColor });
  const posted = await page.evaluate(() => window.calls.find((c) => c[0] === "post" && c[1].includes("save-component-css"))[2]);
  if (!/^wy-c-[a-z0-9]{6}$/.test(posted.id) || !posted.css.includes("@media (min-width: 700px)") || /#features|\.grid/.test(posted.css)) {
    problems.push({ check: "generated CSS is scoped to the copy and keeps the media query", css: posted.css });
  }

  await page.setViewportSize({ width: 375, height: 800 });
  await page.waitForTimeout(50);
  if ((await style(pasted)) !== (await style("#c1"))) problems.push({ check: "pasted card keeps its look at 375px", source: await style("#c1"), pasted: await style(pasted) });

  await page.close();
  return problems;
}
