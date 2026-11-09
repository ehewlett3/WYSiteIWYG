// Exercise the real endpoints: saving a copied section's CSS must not change
// the page hash before the following block save. Mocks missed this conflict.
import { execFileSync, spawn } from "node:child_process";
import { rmSync } from "node:fs";
import { join } from "node:path";
import { createServer } from "node:net";

export default async function ({ browser, here }) {
  const root = execFileSync("php", [join(here, "save-site.php")]).toString();
  const listener = createServer();
  await new Promise((resolve) => listener.listen(0, "127.0.0.1", resolve));
  const port = listener.address().port;
  await new Promise((resolve) => listener.close(resolve));
  const server = spawn("php", ["-d", `session.save_path=${root}/sessions`, "-S", `127.0.0.1:${port}`, "-t", root], { stdio: "ignore" });
  const context = await browser.newContext();
  const api = context.request;
  const url = (action) => `http://127.0.0.1:${port}/edit/index.php?action=${action}`;
  try {
    let login;
    for (let i = 0; i < 100; i++) {
      try {
        login = await api.get(url("login"));
        break;
      } catch {
        await new Promise((resolve) => setTimeout(resolve, 50));
      }
    }
    if (!login) throw new Error("Test server did not start");
    const token = (await login.text()).match(/name="csrf_token" value="([^"]+)"/)[1];
    await api.post(url("login"), { form: { csrf_token: token, username: "testadmin", password: "disposable-test-password" } });
    const preview = await (await api.get(url("preview&path=paste/index.html"))).text();
    const savedContext = JSON.parse(preview.match(/window.WYSITE_PREVIEW_CONTEXT = (.*?);<\/script>/s)[1]);
    const data = { csrfToken: savedContext.csrfToken, path: savedContext.pagePath, baseHash: savedContext.fileHash };
    const css = await api.post(url("save-component-css"), { data: { ...data, id: "wy-c-abcdef", css: ".wy-c-abcdef { color: red; }" } });
    if (!(await css.json()).ok) throw new Error("CSS save failed");
    const block = { ...data, name: "page-content", html: '<section class="wy-c-abcdef"><p>Pasted section</p></section>' };
    const saved = await (await api.post(url("save-block"), { data: block })).json();
    if (!saved.ok) throw new Error(`Block save failed after CSS save: ${saved.message}`);
    const html = await (await api.get(`http://127.0.0.1:${port}/paste/index.html`)).text();
    if (!html.includes("wysite-components.css") || !html.includes("Pasted section")) throw new Error("Saved section or stylesheet is missing");
    const stale = await api.post(url("save-block"), { data: block });
    if (stale.status() !== 409) throw new Error("Stale block save must still be rejected");
    return [];
  } finally {
    await context.close();
    const stopped = new Promise((resolve) => server.once("exit", resolve));
    if (server.exitCode === null) {
      server.kill();
      await stopped;
    }
    rmSync(root, { recursive: true, force: true });
  }
}
