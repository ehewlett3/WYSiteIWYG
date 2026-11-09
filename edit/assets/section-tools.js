// section-tools.js — EDT-A/C/D: arrange sections of a page in the preview.
//
// "Arrange" mode lets you pick any section inside an editable block (a card, a
// column, a paragraph, a list) and duplicate, move, delete, copy or paste it,
// save it as a reusable section, or insert one. A section pasted somewhere
// else keeps its original look: the CSS rules that styled it are captured on
// copy, and any that no longer apply in the new place are re-scoped to classes
// of the pasted copy (wy-c-…) and saved to /assets/wysite-components.css.
import { dedupeDomIds } from "./block-commands.js";

const STRUCTURAL = "section, article, aside, div, li, figure, blockquote, table, ul, ol, dl, header, footer, p, h1, h2, h3, h4, h5, h6, img, iframe, video, form, details, hr, pre";
const CLIPBOARD_KEY = "wysite.clipboard.v1";
const MAX_CAPTURED = 200;
const PROPS = [
  "display", "position", "top", "right", "bottom", "left", "float", "clear", "box-sizing",
  "flex-direction", "flex-wrap", "flex-grow", "flex-shrink", "flex-basis", "order", "align-items", "align-self", "align-content", "justify-content", "justify-items",
  "grid-template-columns", "grid-template-rows", "grid-column-start", "grid-column-end", "grid-row-start", "grid-row-end", "column-gap", "row-gap",
  "width", "min-width", "max-width", "height", "min-height", "max-height", "aspect-ratio",
  "margin-top", "margin-right", "margin-bottom", "margin-left", "padding-top", "padding-right", "padding-bottom", "padding-left",
  "border-top-width", "border-right-width", "border-bottom-width", "border-left-width", "border-top-style", "border-top-color", "border-bottom-color",
  "border-top-left-radius", "border-top-right-radius", "border-bottom-left-radius", "border-bottom-right-radius", "box-shadow",
  "background-color", "background-image", "background-size", "background-position", "color", "opacity",
  "font-family", "font-size", "font-weight", "font-style", "line-height", "letter-spacing", "text-align", "text-transform", "text-decoration-line",
  "overflow-x", "overflow-y", "object-fit", "list-style-type", "transform", "filter",
];

export function setupSectionTools({ context, toast, saveBlock, postJson, fetchJson, isEditorOpen, openEditor }) {
  const actions = document.querySelector(".wysite-admin-bar__actions");
  if (!actions) return;

  let active = false;
  let hovered = null;
  let selected = null;
  const undo = new Map(); // block name => [html, ...]

  const toggle = document.createElement("button");
  toggle.type = "button";
  toggle.className = "wysite-admin-link wysite-admin-link--button";
  toggle.textContent = "Arrange";
  toggle.title = "Duplicate, move, copy and paste sections of this page";
  actions.prepend(toggle);

  const bar = document.createElement("div");
  bar.className = "wysite-section-toolbar";
  bar.hidden = true;
  bar.setAttribute("role", "toolbar");
  bar.setAttribute("aria-label", "Section tools");
  document.body.appendChild(bar);

  toggle.addEventListener("click", () => {
    if (isEditorOpen()) {
      toast("Finish or cancel the current editor first.", true);
      return;
    }
    setActive(!active);
  });

  function setActive(next) {
    active = next;
    document.body.classList.toggle("wysite-arrange-mode", active);
    toggle.textContent = active ? "Done arranging" : "Arrange";
    if (!active) {
      select(null);
      setHover(null);
    } else {
      toast("Click a section inside an editable block. Alt+↑ selects its parent.");
    }
  }

  // ---- picking -------------------------------------------------------------
  function slotOf(el) {
    return el?.closest?.("[data-wysite-edit-slot]") || null;
  }

  function isChrome(el) {
    return Boolean(el.closest(".wysite-admin-bar, .wysite-section-toolbar, .wysite-toast, .wysite-section-choice, .wysite-component-panel, .pm-dropin, .wysite-edit-button"));
  }

  function pickable(node) {
    if (!(node instanceof Element) || isChrome(node)) return null;
    const slot = slotOf(node);
    if (!slot) return null;
    let current = node;
    while (current && current !== slot) {
      if (current.matches(STRUCTURAL)) return current;
      current = current.parentElement;
    }
    return null;
  }

  function blockInfo(el) {
    const slot = slotOf(el);
    const name = slot?.dataset.wysiteEditSlot || "";
    const info = (context.blocks || []).find((block) => block.name === name);
    return { slot, name, editable: Boolean(slot) && !(info?.adminOnly && !context.isAdmin) };
  }

  function setHover(el) {
    hovered?.classList.remove("wysite-section-hover");
    hovered = el;
    hovered?.classList.add("wysite-section-hover");
  }

  function select(el) {
    selected?.classList.remove("wysite-section-selected");
    selected = el;
    if (!el) {
      bar.hidden = true;
      return;
    }
    el.classList.add("wysite-section-selected");
    renderBar();
    placeBar();
  }

  document.addEventListener("mousemove", (event) => {
    if (active && !isEditorOpen()) setHover(pickable(event.target));
  }, true);

  document.addEventListener("click", (event) => {
    if (!active || isEditorOpen() || isChrome(event.target)) return;
    const el = pickable(event.target);
    event.preventDefault();
    event.stopPropagation();
    select(el);
  }, true);

  document.addEventListener("keydown", (event) => {
    if (!active || isEditorOpen() || event.target.closest?.("input, textarea, [contenteditable=true]")) return;
    const mod = event.metaKey || event.ctrlKey;
    if (event.key === "Escape") {
      selected ? select(null) : setActive(false);
    } else if (mod && event.key.toLowerCase() === "z") {
      event.preventDefault();
      undoLast();
    } else if (!selected) {
      return;
    } else if (event.altKey && event.key === "ArrowUp") {
      event.preventDefault();
      const parent = selected.parentElement;
      if (parent && slotOf(parent) && parent !== slotOf(selected)) select(parent.matches(STRUCTURAL) ? parent : pickable(parent));
    } else if (event.altKey && event.key === "ArrowDown") {
      event.preventDefault();
      const child = Array.from(selected.children).find((c) => c.matches(STRUCTURAL));
      if (child) select(child);
    } else if (event.key === "Tab") {
      event.preventDefault();
      const sibling = event.shiftKey ? selected.previousElementSibling : selected.nextElementSibling;
      if (sibling) select(sibling);
    } else if (mod && event.key.toLowerCase() === "d") {
      event.preventDefault();
      duplicate();
    } else if (mod && event.key.toLowerCase() === "c") {
      event.preventDefault();
      copy();
    } else if (mod && event.key.toLowerCase() === "v") {
      event.preventDefault();
      paste("after");
    } else if (event.key === "Delete" || event.key === "Backspace") {
      event.preventDefault();
      remove();
    }
  });

  window.addEventListener("scroll", () => placeBar(), { passive: true });
  window.addEventListener("resize", () => placeBar());

  // ---- toolbar -------------------------------------------------------------
  function describe(el) {
    const classes = Array.from(el.classList).filter((c) => !c.startsWith("wysite-") && !c.startsWith("wy-c-")).slice(0, 2);
    return el.localName + (el.id ? `#${el.id}` : "") + (classes.length ? `.${classes.join(".")}` : "");
  }

  function button(label, title, handler, disabled = false) {
    const b = document.createElement("button");
    b.type = "button";
    b.textContent = label;
    b.title = title;
    b.setAttribute("aria-label", title);
    b.disabled = disabled;
    b.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      handler();
    });
    return b;
  }

  function renderBar() {
    bar.innerHTML = "";
    if (!selected) return;
    const { editable, slot } = blockInfo(selected);

    const crumbs = document.createElement("div");
    crumbs.className = "wysite-section-toolbar__crumbs";
    const chain = [];
    for (let el = selected; el && el !== slot; el = el.parentElement) {
      if (el.matches(STRUCTURAL)) chain.unshift(el);
    }
    chain.slice(-4).forEach((el, index, list) => {
      const crumb = button(describe(el), `Select ${describe(el)}`, () => select(el));
      crumb.className = "wysite-section-toolbar__crumb" + (el === selected ? " is-current" : "");
      crumbs.appendChild(crumb);
      if (index < list.length - 1) crumbs.appendChild(document.createTextNode(" › "));
    });
    bar.appendChild(crumbs);

    const tools = document.createElement("div");
    tools.className = "wysite-section-toolbar__tools";
    const clip = readClipboard();
    const container = selected.matches("section, article, aside, div, ul, ol, figure, blockquote, header, footer, details, form");
    if (!editable) {
      tools.appendChild(document.createTextNode("This block holds raw HTML and is admin-only."));
    } else {
      tools.append(
        button("⧉ Duplicate", "Duplicate (Ctrl/Cmd+D)", duplicate),
        button("↑", "Move up", () => move(-1), !selected.previousElementSibling),
        button("↓", "Move down", () => move(1), !selected.nextElementSibling),
        button("⎘ Copy", "Copy, with its look (Ctrl/Cmd+C)", copy),
        button("Paste before", "Paste the copied section before this one", () => paste("before"), !clip),
        button("Paste after", "Paste the copied section after this one (Ctrl/Cmd+V)", () => paste("after"), !clip),
        button("Paste inside", "Paste the copied section at the end of this one", () => paste("inside"), !clip || !container),
        button("＋ Insert", "Insert a saved or theme section", openLibrary),
        button("★ Save", "Save this section for reuse", saveAsComponent),
        button("✎ Edit", "Edit the text of this section", () => {
          const target = selected;
          select(null);
          setActive(false);
          openEditor(target);
        }),
        button("🗑 Delete", "Delete (Delete key; Ctrl/Cmd+Z undoes)", remove),
      );
    }
    bar.appendChild(tools);
    bar.hidden = false;
  }

  function placeBar() {
    if (!selected || bar.hidden) return;
    const rect = selected.getBoundingClientRect();
    const top = Math.max(56, rect.top - bar.offsetHeight - 8);
    bar.style.transform = `translate(${Math.max(8, Math.min(rect.left, window.innerWidth - bar.offsetWidth - 8))}px, ${top}px)`;
  }

  // ---- saving ----------------------------------------------------------------
  function cleanSlotHtml(slot) {
    const clone = slot.cloneNode(true);
    clone.querySelectorAll(".wysite-section-hover, .wysite-section-selected").forEach((el) => {
      el.classList.remove("wysite-section-hover", "wysite-section-selected");
      if (el.getAttribute("class") === "") el.removeAttribute("class");
    });
    clone.querySelectorAll(".wysite-section-choice").forEach((el) => el.remove());
    return clone.innerHTML;
  }

  async function commit(el, message) {
    const { slot, name } = blockInfo(el);
    if (!slot) return;
    try {
      await saveBlock(name, cleanSlotHtml(slot));
      toast(message);
    } catch (error) {
      toast(error.message || "Saving failed.", true);
    }
  }

  function snapshot(el) {
    const { slot, name } = blockInfo(el);
    if (!slot) return;
    const stack = undo.get(name) || [];
    stack.push(cleanSlotHtml(slot));
    undo.set(name, stack.slice(-30));
  }

  async function undoLast() {
    const entry = Array.from(undo.entries()).reverse().find(([, stack]) => stack.length);
    if (!entry) {
      toast("Nothing to undo.");
      return;
    }
    const [name, stack] = entry;
    const slot = document.querySelector(`[data-wysite-edit-slot="${CSS.escape(name)}"]`);
    if (!slot) return;
    select(null);
    slot.innerHTML = stack.pop();
    try {
      await saveBlock(name, cleanSlotHtml(slot));
      toast("Undone.");
    } catch (error) {
      toast(error.message || "Undo could not be saved.", true);
    }
  }

  // ---- operations ------------------------------------------------------------
  function duplicate() {
    if (!selected) return;
    snapshot(selected);
    const copy = selected.cloneNode(true);
    copy.classList.remove("wysite-section-selected", "wysite-section-hover");
    dedupeDomIds(copy);
    selected.after(copy);
    select(copy);
    commit(copy, "Duplicated.");
  }

  function move(direction) {
    if (!selected) return;
    const sibling = direction < 0 ? selected.previousElementSibling : selected.nextElementSibling;
    if (!sibling) return;
    snapshot(selected);
    direction < 0 ? sibling.before(selected) : sibling.after(selected);
    placeBar();
    renderBar();
    commit(selected, "Moved.");
  }

  // Deleting detaches the element, so save through the slot it came from.
  function remove() {
    if (!selected) return;
    const { slot, name } = blockInfo(selected);
    if (!slot) return;
    snapshot(selected);
    const target = selected;
    select(null);
    target.remove();
    saveBlock(name, cleanSlotHtml(slot)).then(
      () => toast("Deleted. Ctrl/Cmd+Z undoes it."),
      (error) => toast(error.message || "Saving failed.", true),
    );
  }

  // ---- copy with style capture ----------------------------------------------
  function isEditorSheet(sheet) {
    const href = sheet.href || "";
    return /\/assets\/(editor|live-edit-banner)\.css/.test(href) || sheet.ownerNode?.dataset?.wysiteComponents !== undefined;
  }

  function absolutizeCss(cssText, base) {
    return cssText.replace(/url\(\s*(["']?)([^"')]+)\1\s*\)/g, (m, quote, url) => {
      if (/^(data:|#)/i.test(url)) return m;
      try {
        const absolute = new URL(url, base);
        return `url("${absolute.origin === location.origin ? absolute.pathname : absolute.href}")`;
      } catch {
        return m;
      }
    });
  }

  function matchedRules(el) {
    const out = [];
    const walk = (rules, conds, base) => {
      for (const rule of rules) {
        if (rule.selectorText !== undefined && rule.style) {
          let matches = false;
          try {
            matches = el.matches(rule.selectorText);
          } catch {}
          if (matches && rule.style.cssText) out.push({ conds, selector: rule.selectorText, css: absolutizeCss(rule.style.cssText, base) });
        } else if (rule.cssRules) {
          const cond = typeof CSSMediaRule !== "undefined" && rule instanceof CSSMediaRule ? `@media ${rule.media.mediaText}`
            : typeof CSSSupportsRule !== "undefined" && rule instanceof CSSSupportsRule ? `@supports ${rule.conditionText}`
              : null;
          walk(rule.cssRules, cond ? [...conds, cond] : conds, base);
        }
      }
    };
    for (const sheet of document.styleSheets) {
      if (isEditorSheet(sheet)) continue;
      let rules;
      try {
        rules = sheet.cssRules;
      } catch {
        continue; // cross-origin stylesheet
      }
      walk(rules, [], sheet.href || location.href);
    }
    return out;
  }

  function computedOf(el) {
    const style = getComputedStyle(el);
    const out = {};
    for (const prop of PROPS) out[prop] = style.getPropertyValue(prop);
    return out;
  }

  function capture(root) {
    const els = [root, ...root.querySelectorAll("*")].slice(0, MAX_CAPTURED);
    return els.map((el) => ({ computed: computedOf(el), rules: matchedRules(el) }));
  }

  function cleanHtml(el) {
    const clone = el.cloneNode(true);
    [clone, ...clone.querySelectorAll("*")].forEach((node) => {
      node.classList?.remove("wysite-section-hover", "wysite-section-selected");
      if (node.getAttribute?.("class") === "") node.removeAttribute("class");
    });
    return clone.outerHTML;
  }

  function copy() {
    if (!selected) return;
    const payload = { html: cleanHtml(selected), sourcePage: context.pagePath, capturedAt: Date.now(), styles: capture(selected) };
    try {
      localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(payload));
    } catch {
      toast("The section is too large to copy with its look; copy a smaller part.", true);
      return;
    }
    navigator.clipboard?.writeText?.(payload.html).catch(() => {});
    toast("Copied. Select a section on any page and choose Paste.");
    renderBar();
  }

  function readClipboard() {
    try {
      const data = JSON.parse(localStorage.getItem(CLIPBOARD_KEY) || "null");
      return data && typeof data.html === "string" ? data : null;
    } catch {
      return null;
    }
  }

  // ---- paste with style carry -------------------------------------------------
  function same(a, b) {
    if (a === b) return true;
    const pa = /^(-?[\d.]+)px$/.exec(a);
    const pb = /^(-?[\d.]+)px$/.exec(b);
    return Boolean(pa && pb && Math.abs(Number(pa[1]) - Number(pb[1])) <= 0.5);
  }

  function differences(root, styles) {
    if (!styles) return [];
    const els = [root, ...root.querySelectorAll("*")].slice(0, styles.length);
    const diffs = [];
    els.forEach((el, i) => {
      const now = computedOf(el);
      const props = PROPS.filter((prop) => !same(now[prop], styles[i].computed[prop]));
      if (props.length) diffs.push({ el, i, props });
    });
    return diffs;
  }

  function insertCopy(payload, where, anchor) {
    const template = document.createElement("template");
    template.innerHTML = payload.html.trim();
    const node = template.content.firstElementChild;
    if (!node) return null;
    dedupeDomIds(node);
    if (where === "before") anchor.before(node);
    else if (where === "inside") anchor.append(node);
    else anchor.after(node);
    return node;
  }

  function paste(where, payload = readClipboard()) {
    if (!selected || !payload) return;
    const anchor = selected;
    snapshot(anchor);
    const node = insertCopy(payload, where, anchor);
    if (!node) return;
    select(node);
    requestAnimationFrame(() => {
      const diffs = differences(node, payload.styles);
      if (!diffs.length) {
        commit(node, payload.styles ? "Pasted. It looks the same here." : "Inserted.");
        return;
      }
      askAboutLook(node, payload, diffs);
    });
  }

  function askAboutLook(node, payload, diffs) {
    const choice = document.createElement("div");
    choice.className = "wysite-section-choice";
    choice.append(
      document.createTextNode("This section looks different here. "),
      button("Keep original look", "Copy the styles that made it look the way it did", async () => {
        choice.remove();
        await keepOriginalLook(node, payload, diffs);
      }),
      button("Match this section", "Let the styles of its new place apply", () => {
        choice.remove();
        commit(node, "Pasted with this section's look.");
      }),
    );
    node.before(choice);
  }

  function randomId() {
    const chars = "abcdefghijklmnopqrstuvwxyz0123456789";
    let id = "";
    const bytes = crypto.getRandomValues(new Uint8Array(6));
    for (const byte of bytes) id += chars[byte % chars.length];
    return `wy-c-${id}`;
  }

  function wrap(conds, css) {
    return conds.reduceRight((inner, cond) => `${cond} { ${inner} }`, css);
  }

  async function keepOriginalLook(node, payload, diffs) {
    const id = randomId();
    node.classList.add(id);
    const els = [node, ...node.querySelectorAll("*")].slice(0, payload.styles.length);
    const parts = [];

    // 1. Re-scope the source rules that no longer match (keeps media queries).
    for (const { i } of diffs) {
      const el = els[i];
      const cls = `${id}-${i}`;
      el.classList.add(cls);
      for (const rule of payload.styles[i].rules) {
        let still = false;
        try {
          still = el.matches(rule.selector);
        } catch {}
        if (!still) parts.push(wrap(rule.conds, `.${cls} { ${rule.css} }`));
      }
    }
    const style = document.createElement("style");
    style.dataset.wysiteComponents = "";
    style.textContent = parts.join("\n");
    document.head.appendChild(style);

    // 2. Anything still different at this width: computed values.
    await new Promise((resolve) => requestAnimationFrame(resolve));
    const fallback = [];
    for (const { el, i, props } of differences(node, payload.styles)) {
      const cls = `${id}-${i}`;
      el.classList.add(cls);
      fallback.push(`.${cls} { ${props.map((prop) => `${prop}: ${payload.styles[i].computed[prop]}`).join("; ")} }`);
    }
    if (fallback.length) {
      parts.push(...fallback);
      style.textContent = parts.join("\n");
    }

    try {
      await postJson(`${context.appUrl}/index.php?action=save-component-css`, {
        csrfToken: context.csrfToken,
        id,
        css: parts.join("\n"),
        path: context.pagePath,
      });
      await commit(node, "Pasted with its original look.");
    } catch (error) {
      style.remove();
      toast(error.message || "The section's look could not be saved.", true);
    }
  }

  // ---- reusable sections -------------------------------------------------------
  async function saveAsComponent() {
    if (!selected) return;
    const name = window.prompt("Name this section so you can insert it on other pages:", describe(selected));
    if (!name) return;
    try {
      await postJson(`${context.appUrl}/index.php?action=component-save`, {
        csrfToken: context.csrfToken,
        name,
        html: cleanHtml(selected),
        styles: capture(selected),
        path: context.pagePath,
      });
      toast(`Saved "${name}". Use Insert to add it anywhere.`);
    } catch (error) {
      toast(error.message || "Saving the section failed.", true);
    }
  }

  async function openLibrary() {
    if (!selected) return;
    const anchor = selected;
    let data;
    try {
      data = await fetchJson(`${context.appUrl}/index.php?action=components-list`);
    } catch (error) {
      toast(error.message || "Sections could not be loaded.", true);
      return;
    }
    const panel = document.createElement("div");
    panel.className = "wysite-component-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "Insert a section");
    const close = button("Close", "Close", () => panel.remove());
    const heading = document.createElement("h2");
    heading.textContent = "Insert a section";
    panel.append(close, heading);

    const groups = [["From this theme", data.theme || []], ["Saved sections", data.saved || []]];
    for (const [title, items] of groups) {
      const h = document.createElement("h3");
      h.textContent = title;
      panel.appendChild(h);
      if (!items.length) {
        const p = document.createElement("p");
        p.textContent = title === "Saved sections" ? "Nothing saved yet. Select a section and choose Save." : "This theme ships no sections.";
        panel.appendChild(p);
        continue;
      }
      for (const item of items) {
        const card = document.createElement("div");
        card.className = "wysite-component-panel__item";
        const label = document.createElement("strong");
        label.textContent = item.name;
        const preview = document.createElement("div");
        preview.className = "wysite-component-panel__preview";
        preview.innerHTML = item.html;
        preview.querySelectorAll("[id]").forEach((el) => el.removeAttribute("id"));
        const payload = { html: item.html, styles: Array.isArray(item.styles) ? item.styles : null };
        card.append(
          label,
          preview,
          button("Before", "Insert before the selected section", () => {
            panel.remove();
            select(anchor);
            paste("before", payload);
          }),
          button("After", "Insert after the selected section", () => {
            panel.remove();
            select(anchor);
            paste("after", payload);
          }),
        );
        if (item.id && String(item.id).startsWith("cmp-")) {
          card.append(button("Delete", "Delete this saved section", async () => {
            try {
              await postJson(`${context.appUrl}/index.php?action=component-delete`, { csrfToken: context.csrfToken, id: item.id });
              card.remove();
            } catch (error) {
              toast(error.message || "Delete failed.", true);
            }
          }));
        }
        panel.appendChild(card);
      }
    }
    document.body.appendChild(panel);
  }
}
