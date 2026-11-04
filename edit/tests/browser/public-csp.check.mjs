// SEC-3a: a public page's meta CSP blocks handlers that slipped through, while
// the page's own inline scripts (raw-HTML blocks, the live-edit bridge) run.
import { execFileSync } from "node:child_process";
import { join } from "node:path";

export default async function ({ browser, here }) {
  const problems = [];
  const html = execFileSync("php", [join(here, "csp-page.php")]).toString();
  if (!/<head[^>]*><meta http-equiv="Content-Security-Policy"/.test(html)) problems.push({ check: "CSP meta is the first element of <head>" });
  const page = await browser.newPage();
  let fired = false;
  page.on("dialog", async (dialog) => { fired = true; await dialog.dismiss(); });
  await page.route("http://wysite.test/**", (route) => route.fulfill({ contentType: "text/html", body: html }));
  await page.goto("http://wysite.test/csp/");
  await page.waitForTimeout(200);
  if (fired) problems.push({ check: "injected inline handler is blocked" });
  if (await page.evaluate(() => window.adminScriptRan) !== 1) problems.push({ check: "raw-HTML block script still runs" });
  await page.close();
  return problems;
}
