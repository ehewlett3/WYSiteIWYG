// PLT-2: the server's HTML5 parse must give every element the same DOM path as
// Chromium, or regions picked in the browser would land elsewhere on save.
import { execFileSync } from "node:child_process";
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

export default async function ({ browser, here }) {
  const problems = [];
  const dirs = [join(here, "..", "fixtures", "dompaths")];
  // Malformed markup only parses exactly like a browser on PHP 8.4+ (lexbor).
  if (Number(execFileSync("php", ["-r", "echo PHP_VERSION_ID;"]).toString()) >= 80400) {
    dirs.push(join(here, "..", "fixtures", "dompaths-php84"));
  }
  const page = await browser.newPage();
  const files = dirs.flatMap((dir) => readdirSync(dir).filter((file) => file.endsWith(".html")).map((file) => join(dir, file)));
  for (const file of files) {
    const name = file;
    const server = JSON.parse(execFileSync("php", [join(here, "dompaths.php"), file]).toString());
    await page.setContent(readFileSync(file, "utf8"));
    const client = await page.evaluate(() => {
      const out = [];
      const pathOf = (el) => {
        const parts = [];
        for (let cur = el; cur && cur.nodeType === 1; cur = cur.parentElement) {
          let index = 1;
          for (let sib = cur.previousElementSibling; sib; sib = sib.previousElementSibling) {
            if (sib.localName === cur.localName) index += 1;
          }
          parts.unshift(`${cur.localName}:${index}`);
          if (cur.localName === "html") break;
        }
        return parts.join("/");
      };
      const walk = (node) => {
        for (const child of node.children) {
          out.push(pathOf(child));
          walk(child);
        }
      };
      walk(document);
      return out;
    });
    const max = Math.max(server.length, client.length);
    for (let i = 0; i < max; i++) {
      if (server[i] !== client[i]) {
        problems.push({ fixture: name, index: i, server: server[i] ?? null, browser: client[i] ?? null });
        break;
      }
    }
  }
  await page.close();
  return problems;
}
