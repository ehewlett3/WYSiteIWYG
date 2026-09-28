// html-fidelity.js
//
// Schema and (de)serialization for the drop-in editor, built so that editing a
// block in place never changes how existing content looks or silently drops
// markup:
//
// - Every node and mark keeps *all* of its HTML attributes (class, style, id,
//   data-*, aria-*, width/height/srcset/loading, target/rel, ...), and <b>/<i>
//   stay <b>/<i> rather than becoming <strong>/<em>.
// - Elements the schema doesn't model (iframe, video, audio, picture, form,
//   details, svg, custom elements, ...) become read-only `raw_block` /
//   `raw_inline` atoms that serialize back verbatim.
// - Inline content sitting directly in a block container (<div>text</div>,
//   <li>text</li>, <figure><img></figure>) is wrapped in an *implicit* paragraph
//   while editing and unwrapped again on save, so no <p> is invented.
// - Top-level blocks the user didn't change are written back from their
//   original markup (see createDocument / serializeDocument).
import * as pm from "./pm-bundle.js";

const { Schema, DOMParser: PMDOMParser, DOMSerializer, Fragment } = pm.model;
const { schema: basicSchema } = pm.basic;
const { addListNodes } = pm.list;
const { tableNodes } = pm.tables;

const IMPLICIT = "data-wysite-implicit";
const ORIGINAL_P = "data-wysite-p";
const RAW_MARK = "data-wysite-raw";
const SECTIONS = "data-wysite-sections";

/** Always kept verbatim as read-only atoms. */
const RAW_TAGS = new Set([
  "iframe", "video", "audio", "picture", "svg", "canvas", "object", "embed", "form", "details", "dialog",
  "input", "button", "select", "textarea", "math", "ruby", "map", "noscript", "template", "script", "style",
  "fieldset", "meter", "progress", "output", "marquee", "slot", "portal", "label", "wbr", "link", "meta",
]);

/** Block containers: content is block*, inline runs inside get an implicit <p>. */
const CONTAINER_TAGS = [
  "div", "section", "article", "aside", "header", "footer", "nav", "main", "address", "hgroup", "center", "search", "figure",
];

/** Inline formatting tags modeled as marks (strong/em/code/a/span handled separately). */
const INLINE_MARK_TAGS = [
  "sub", "sup", "u", "s", "strike", "del", "ins", "mark", "small", "big", "abbr", "cite", "q", "time",
  "kbd", "var", "samp", "dfn", "bdi", "bdo", "data", "tt", "font",
];

const PHRASING_TAGS = new Set([
  "a", "span", "b", "strong", "i", "em", "code", "img", "br", ...INLINE_MARK_TAGS,
]);

/** Elements whose children are block content in the schema (so inline runs need wrapping). */
const BLOCK_HOST_TAGS = new Set([...CONTAINER_TAGS, "blockquote", "li", "td", "th", "dd"]);

/** Everything the schema understands structurally (not raw). */
const KNOWN_TAGS = new Set([
  ...CONTAINER_TAGS, ...INLINE_MARK_TAGS, ...PHRASING_TAGS,
  "p", "h1", "h2", "h3", "h4", "h5", "h6", "pre", "blockquote", "hr", "ul", "ol", "li", "dl", "dt", "dd",
  "table", "thead", "tbody", "tfoot", "tr", "td", "th", "caption", "colgroup", "col", "figcaption",
]);

// ---------------------------------------------------------------------------
// Attribute bag helpers

/**
 * All attributes of `dom`, in source order and with their exact values. Modeled
 * attributes (src, href, start, ...) are included too so that they are written
 * back in their original position; domAttrs() overrides their values.
 */
function readHtmlAttrs(dom) {
  const out = {};
  let any = false;
  for (const attr of Array.from(dom.attributes || [])) {
    if (attr.name === RAW_MARK || attr.name === SECTIONS) {
      continue;
    }
    out[attr.name] = attr.value;
    any = true;
  }
  return any ? out : null;
}

/**
 * Build an element with exact attribute values. ProseMirror's own array specs
 * route `style` through the CSSOM, which rewrites "color:red" as "color: red;".
 */
function el(tag, attrs, content = true, innerTag = null, innerAttrs = null) {
  const dom = document.createElement(tag);
  Object.entries(attrs || {}).forEach(([name, value]) => dom.setAttribute(name, String(value)));
  if (!content) {
    return dom;
  }
  if (innerTag) {
    const inner = el(innerTag, innerAttrs, false);
    dom.appendChild(inner);
    return { dom, contentDOM: inner };
  }
  return { dom, contentDOM: dom };
}

function withHtmlAttrs(attrs = {}) {
  return { ...attrs, htmlAttrs: { default: null } };
}

/** DOM attributes for output: the preserved bag in source order, modeled values applied. */
function domAttrs(node, modeledValues = {}) {
  const out = { ...(node.attrs.htmlAttrs || {}) };
  Object.entries(modeledValues).forEach(([key, value]) => {
    if (value !== null && value !== undefined) {
      // Keep the source spelling when the value is unchanged (e.g. start="03").
      out[key] = key in out && String(out[key]) === String(value) ? out[key] : value;
    } else {
      delete out[key];
    }
  });
  return out;
}

/** Wrap a spec's tag-based parse rules so they also capture the attribute bag. */
function keepAttrs(spec) {
  const parseDOM = (spec.parseDOM || []).map((rule) => {
    if (!rule.tag) {
      return rule;
    }
    return {
      ...rule,
      getAttrs(dom) {
        const base = typeof rule.getAttrs === "function" ? rule.getAttrs(dom) : (rule.attrs ?? {});
        if (base === false) {
          return false;
        }
        return { ...(base || {}), htmlAttrs: readHtmlAttrs(dom) };
      },
    };
  });
  return { ...spec, attrs: withHtmlAttrs(spec.attrs), parseDOM };
}

// ---------------------------------------------------------------------------
// Schema

export function buildSchema() {
  let nodes = basicSchema.spec.nodes;

  nodes = nodes.update("paragraph", keepAttrs({
    content: "inline*",
    group: "block",
    parseDOM: [{ tag: "p" }],
    toDOM: (node) => el("p", domAttrs(node)),
  }));

  nodes = nodes.update("heading", keepAttrs({
    attrs: { level: { default: 1 } },
    content: "inline*",
    group: "block",
    defining: true,
    parseDOM: [1, 2, 3, 4, 5, 6].map((level) => ({ tag: `h${level}`, attrs: { level } })),
    toDOM: (node) => el(`h${node.attrs.level}`, domAttrs(node)),
  }));

  nodes = nodes.update("blockquote", keepAttrs({
    content: "block+",
    group: "block",
    defining: true,
    parseDOM: [{ tag: "blockquote" }],
    toDOM: (node) => el("blockquote", domAttrs(node)),
  }));

  // <pre> keeps whether it had an inner <code> (and that element's attributes).
  nodes = nodes.update("code_block", keepAttrs({
    attrs: { codeAttrs: { default: {} } },
    content: "text*",
    marks: "",
    group: "block",
    code: true,
    defining: true,
    parseDOM: [{
      tag: "pre",
      preserveWhitespace: "full",
      getAttrs(dom) {
        const code = dom.children.length === 1 && dom.firstElementChild?.tagName === "CODE" ? dom.firstElementChild : null;
        return { codeAttrs: code ? (readHtmlAttrs(code) || {}) : false };
      },
    }],
    toDOM: (node) => node.attrs.codeAttrs === false
      ? el("pre", domAttrs(node))
      : el("pre", domAttrs(node), true, "code", node.attrs.codeAttrs),
  }));

  nodes = nodes.update("horizontal_rule", keepAttrs({
    group: "block",
    parseDOM: [{ tag: "hr" }],
    toDOM: (node) => el("hr", domAttrs(node), false),
  }));

  nodes = nodes.update("image", keepAttrs({
    inline: true,
    attrs: { src: {}, alt: { default: null }, title: { default: null } },
    group: "inline",
    draggable: true,
    parseDOM: [{
      tag: "img[src]",
      getAttrs: (dom) => ({ src: dom.getAttribute("src"), alt: dom.getAttribute("alt"), title: dom.getAttribute("title") }),
    }],
    toDOM: (node) => el("img", domAttrs(node, { src: node.attrs.src, alt: node.attrs.alt, title: node.attrs.title }), false),
  }));

  nodes = nodes.update("hard_break", keepAttrs({
    inline: true,
    group: "inline",
    selectable: false,
    parseDOM: [{ tag: "br" }],
    toDOM: (node) => el("br", domAttrs(node), false),
  }));

  // Generic block containers (div, section, header, figure, ...), tag preserved.
  nodes = nodes.addBefore("image", "container", keepAttrs({
    attrs: { tag: { default: "div" } },
    content: "block*",
    group: "block",
    defining: true,
    parseDOM: CONTAINER_TAGS.map((tag) => ({ tag, attrs: { tag } })),
    toDOM: (node) => el(node.attrs.tag, domAttrs(node)),
  }));

  nodes = nodes.addBefore("image", "figcaption", keepAttrs({
    content: "inline*",
    group: "block",
    defining: true,
    parseDOM: [{ tag: "figcaption" }],
    toDOM: (node) => el("figcaption", domAttrs(node)),
  }));

  nodes = nodes.addBefore("image", "definition_list", keepAttrs({
    content: "(definition_term | definition_desc)+",
    group: "block",
    parseDOM: [{ tag: "dl" }],
    toDOM: (node) => el("dl", domAttrs(node)),
  }));
  nodes = nodes.addBefore("image", "definition_term", keepAttrs({
    content: "inline*",
    defining: true,
    parseDOM: [{ tag: "dt" }],
    toDOM: (node) => el("dt", domAttrs(node)),
  }));
  nodes = nodes.addBefore("image", "definition_desc", keepAttrs({
    content: "block*",
    defining: true,
    parseDOM: [{ tag: "dd" }],
    toDOM: (node) => el("dd", domAttrs(node)),
  }));

  // Verbatim atoms for anything the schema doesn't model.
  const rawSpec = (inline) => ({
    attrs: { html: { default: "" } },
    atom: true,
    selectable: true,
    draggable: false,
    ...(inline ? { inline: true, group: "inline" } : { group: "block" }),
    parseDOM: [{
      tag: `[${RAW_MARK}]`,
      // The context-restricted inline rule must be tried before the block one.
      priority: inline ? 101 : 100,
      ...(inline ? { context: "paragraph/|heading/|figcaption/|definition_term/" } : {}),
      getAttrs: (dom) => ({ html: rawRegistry.get(dom.getAttribute(RAW_MARK)) ?? "" }),
    }],
    toDOM: (node) => rawPlaceholder(node.attrs.html),
  });
  nodes = nodes.addBefore("image", "raw_block", rawSpec(false));
  nodes = nodes.append({ raw_inline: rawSpec(true) });

  nodes = addListNodes(nodes, "paragraph block*", "block");
  nodes = nodes.update("bullet_list", keepAttrs({
    content: "list_item+",
    group: "block",
    parseDOM: [{ tag: "ul" }],
    toDOM: (node) => el("ul", domAttrs(node)),
  }));
  nodes = nodes.update("ordered_list", keepAttrs({
    attrs: { order: { default: 1 } },
    content: "list_item+",
    group: "block",
    parseDOM: [{ tag: "ol", getAttrs: (dom) => ({ order: dom.hasAttribute("start") ? Number(dom.getAttribute("start")) : 1 }) }],
    toDOM: (node) => el("ol", domAttrs(node, {
      start: node.attrs.order === 1 && !("start" in (node.attrs.htmlAttrs || {})) ? null : node.attrs.order,
    })),
  }));
  nodes = nodes.update("list_item", keepAttrs({
    content: "paragraph block*",
    defining: true,
    parseDOM: [{ tag: "li" }],
    toDOM: (node) => el("li", domAttrs(node)),
  }));

  nodes = nodes.append(tableNodes({ tableGroup: "block", cellContent: "block+", cellAttributes: {} }));
  const table = nodes.get("table");
  nodes = nodes.update("table", {
    ...keepAttrs({ ...table, parseDOM: [{ tag: "table", getAttrs: (dom) => ({ sections: dom.getAttribute(SECTIONS) || null }) }] }),
    attrs: { ...withHtmlAttrs(table.attrs), sections: { default: null } },
    toDOM: (node) => el("table", domAttrs(node, { [SECTIONS]: node.attrs.sections }), true, "tbody"),
  });
  nodes = nodes.update("table_row", {
    ...keepAttrs({ ...nodes.get("table_row"), parseDOM: [{ tag: "tr" }] }),
    toDOM: (node) => el("tr", domAttrs(node)),
  });
  for (const [name, tag] of [["table_cell", "td"], ["table_header", "th"]]) {
    const spec = nodes.get(name);
    const originalRule = spec.parseDOM[0];
    const originalToDOM = spec.toDOM;
    nodes = nodes.update(name, {
      ...keepAttrs({ ...spec, parseDOM: [{ ...originalRule, tag }] }),
      toDOM(node) {
        const [, cellAttrs] = originalToDOM(node);
        return el(tag, { ...(node.attrs.htmlAttrs || {}), ...cellAttrs });
      },
    });
  }

  // ---- marks ----
  let marks = basicSchema.spec.marks;

  marks = marks.update("link", keepAttrs({
    attrs: { href: {}, title: { default: null } },
    inclusive: false,
    parseDOM: [{ tag: "a[href]", getAttrs: (dom) => ({ href: dom.getAttribute("href"), title: dom.getAttribute("title") }) }],
    toDOM: (mark) => el("a", domAttrs(mark, { href: mark.attrs.href, title: mark.attrs.title })),
  }));

  // strong/em/code remember the exact tag they came from.
  const tagMark = (tags, defaultTag, extraRule = {}) => keepAttrs({
    attrs: { tag: { default: defaultTag } },
    parseDOM: tags.map((tag) => ({ tag, attrs: { tag }, ...extraRule[tag] })),
    toDOM: (mark) => el(mark.attrs.tag, domAttrs(mark)),
  });
  marks = marks.update("em", tagMark(["em", "i"], "em"));
  marks = marks.update("strong", tagMark(["strong", "b"], "strong", {
    // Google Docs wraps pasted content in <b style="font-weight:normal">.
    b: { getAttrs: (dom) => dom.style.fontWeight !== "normal" && { tag: "b" } },
  }));
  marks = marks.update("code", { ...tagMark(["code"], "code"), code: true });

  marks = marks.addToEnd("styled_span", keepAttrs({
    inclusive: true,
    excludes: "",
    parseDOM: [{ tag: "span" }],
    toDOM: (mark) => el("span", domAttrs(mark)),
  }));

  marks = marks.addToEnd("inline_tag", keepAttrs({
    attrs: { tag: { default: "span" } },
    excludes: "",
    parseDOM: INLINE_MARK_TAGS.map((tag) => ({ tag, attrs: { tag } })),
    toDOM: (mark) => el(mark.attrs.tag, domAttrs(mark)),
  }));

  return new Schema({ nodes, marks });
}

// ---------------------------------------------------------------------------
// Raw atoms: registry used while parsing, placeholders while serializing.

const rawRegistry = new Map();
let rawSink = null;

function rawPlaceholder(html) {
  if (rawSink) {
    rawSink.push(html);
    return el("wysite-raw", { "data-i": rawSink.length - 1 }, false);
  }
  // Outside serialization (e.g. clipboard): produce the real markup.
  const template = document.createElement("template");
  template.innerHTML = html;
  const first = template.content.firstElementChild;
  return first && template.content.childNodes.length === 1 ? first : el("span", {}, false);
}

// ---------------------------------------------------------------------------
// Pre-processing (before parse) and post-processing (after serialize)

function isRawInlineCandidate(node) {
  return node.nodeType === Node.ELEMENT_NODE && node.hasAttribute(RAW_MARK) && !["form", "details", "dialog", "fieldset", "template", "noscript", "style", "script", "link", "meta"].includes(node.tagName.toLowerCase());
}

/**
 * Prepare a DOM subtree for parsing: register raw elements, record table
 * sections, and wrap inline runs inside block hosts in implicit paragraphs.
 */
export function preprocess(root) {
  markRaw(root);
  // Tag the paragraphs that exist in the source, so postprocess() can tell them
  // apart from paragraphs the user creates next to an implicit one.
  root.querySelectorAll("p").forEach((p) => p.setAttribute(ORIGINAL_P, "1"));
  wrapInlineRuns(root);
  root.querySelectorAll(BLOCK_HOST_SELECTOR).forEach((host) => wrapInlineRuns(host));
  return root;
}

const BLOCK_HOST_SELECTOR = Array.from(BLOCK_HOST_TAGS).join(",");

function markRaw(root) {
  const walk = (parent) => {
    for (const child of Array.from(parent.children)) {
      const tag = child.tagName.toLowerCase();
      if (RAW_TAGS.has(tag) || !KNOWN_TAGS.has(tag)) {
        const id = `r${rawRegistry.size + 1}-${Math.random().toString(36).slice(2, 8)}`;
        rawRegistry.set(id, child.outerHTML);
        child.setAttribute(RAW_MARK, id);
        continue; // never descend into raw content
      }
      if (tag === "table") {
        recordTableSections(child);
      }
      walk(child);
    }
  };
  walk(root);
}

/** Remember thead/tfoot row counts, caption and colgroup (the table schema has none). */
function recordTableSections(table) {
  const info = {};
  const direct = (selector) => Array.from(table.children).filter((el) => el.matches(selector));
  const caption = direct("caption")[0];
  if (caption) {
    info.caption = caption.outerHTML;
    caption.remove();
  }
  const colgroups = direct("colgroup");
  if (colgroups.length) {
    info.colgroup = colgroups.map((el) => el.outerHTML).join("");
    colgroups.forEach((el) => el.remove());
  }
  const thead = direct("thead")[0];
  if (thead) {
    info.thead = thead.rows.length;
    info.theadAttrs = readHtmlAttrs(thead);
  }
  const tfoot = direct("tfoot")[0];
  if (tfoot) {
    info.tfoot = tfoot.rows.length;
    info.tfootAttrs = readHtmlAttrs(tfoot);
  }
  const tbodies = direct("tbody");
  if (tbodies.length === 1) {
    info.tbodyAttrs = readHtmlAttrs(tbodies[0]);
  }
  if (tfoot && thead && tfoot.compareDocumentPosition(thead) & Node.DOCUMENT_POSITION_FOLLOWING) {
    info.tfootFirst = true;
  }
  if (Object.keys(info).length) {
    table.setAttribute(SECTIONS, JSON.stringify(info));
  }
}

function wrapInlineRuns(host) {
  const children = Array.from(host.childNodes);
  let run = [];

  const flush = () => {
    const meaningful = run.some((node) =>
      (node.nodeType === Node.TEXT_NODE && node.data.trim() !== "")
      || (node.nodeType === Node.ELEMENT_NODE && !isRawInlineCandidate(node)));
    if (meaningful) {
      // Trim pure-whitespace text at the run edges (formatting between blocks).
      while (run.length && run[0].nodeType === Node.TEXT_NODE && run[0].data.trim() === "") run.shift();
      while (run.length && run[run.length - 1].nodeType === Node.TEXT_NODE && run[run.length - 1].data.trim() === "") run.pop();
      const p = document.createElement("p");
      p.setAttribute(IMPLICIT, "1");
      host.insertBefore(p, run[0]);
      run.forEach((node) => p.appendChild(node));
    }
    run = [];
  };

  for (const node of children) {
    const inline = node.nodeType === Node.TEXT_NODE
      || (node.nodeType === Node.ELEMENT_NODE && (PHRASING_TAGS.has(node.tagName.toLowerCase()) || isRawInlineCandidate(node)));
    if (inline) {
      run.push(node);
    } else if (node.nodeType === Node.COMMENT_NODE) {
      node.remove();
    } else {
      flush();
    }
  }
  flush();
}

/** Undo the editing-only structure after serialization. */
export function postprocess(container) {
  // Implicit paragraphs vanish again unless the user turned them into real
  // paragraphs (split one, or added a paragraph right next to it).
  const userParagraph = (el) => el && el.tagName === "P" && !el.hasAttribute(ORIGINAL_P);
  container.querySelectorAll(`p[${IMPLICIT}]`).forEach((p) => {
    // Kept as a real <p> only when the user split it or added a paragraph beside it.
    if (userParagraph(p.previousElementSibling) || userParagraph(p.nextElementSibling)) {
      p.removeAttribute(IMPLICIT);
      return;
    }
    while (p.firstChild) {
      p.parentNode.insertBefore(p.firstChild, p);
    }
    p.remove();
  });

  // New list items / table cells made from the toolbar get a <p>; keep the
  // hand-authored <li>text</li> shape when it's a lone, attribute-free one.
  container.querySelectorAll("li, td, th").forEach((host) => {
    const paragraphs = Array.from(host.children).filter((child) => child.tagName === "P");
    if (paragraphs.length !== 1 || paragraphs[0].attributes.length > 0 || host.children.length !== 1) {
      return;
    }
    const p = paragraphs[0];
    while (p.firstChild) host.insertBefore(p.firstChild, p);
    p.remove();
  });

  container.querySelectorAll(`[${ORIGINAL_P}]`).forEach((el) => el.removeAttribute(ORIGINAL_P));
  container.querySelectorAll(`table[${SECTIONS}]`).forEach(restoreTableSections);
  return container;
}

function restoreTableSections(table) {
  let info = {};
  try {
    info = JSON.parse(table.getAttribute(SECTIONS) || "{}");
  } catch {
    info = {};
  }
  table.removeAttribute(SECTIONS);

  const tbody = table.querySelector(":scope > tbody");
  if (!tbody) return;
  const rows = Array.from(tbody.rows);
  const setAttrs = (el, attrs) => Object.entries(attrs || {}).forEach(([k, v]) => el.setAttribute(k, v));

  const headCount = Math.min(info.thead || 0, rows.length);
  const footCount = Math.min(info.tfoot || 0, rows.length - headCount);
  let thead = null;
  let tfoot = null;
  if (headCount) {
    thead = document.createElement("thead");
    setAttrs(thead, info.theadAttrs);
    rows.slice(0, headCount).forEach((row) => thead.appendChild(row));
  }
  if (footCount) {
    tfoot = document.createElement("tfoot");
    setAttrs(tfoot, info.tfootAttrs);
    rows.slice(rows.length - footCount).forEach((row) => tfoot.appendChild(row));
  }
  setAttrs(tbody, info.tbodyAttrs);

  const prefix = document.createElement("template");
  prefix.innerHTML = (info.caption || "") + (info.colgroup ? `<table>${info.colgroup}</table>` : "");
  const caption = prefix.content.querySelector("caption");
  const colgroups = prefix.content.querySelectorAll("colgroup");

  const ordered = [];
  if (caption) ordered.push(caption);
  colgroups.forEach((el) => ordered.push(el));
  if (thead) ordered.push(thead);
  if (info.tfootFirst && tfoot) ordered.push(tfoot);
  ordered.push(tbody);
  if (!info.tfootFirst && tfoot) ordered.push(tfoot);
  ordered.forEach((el) => table.appendChild(el));

  if (tbody.rows.length === 0) tbody.remove();
}

// ---------------------------------------------------------------------------
// Documents that remember their source

/**
 * Parse HTML into a document plus the per-top-level-block source "segments":
 * [{ html, nodes }] where `html` is the original markup of a top-level element
 * (or inline run) and `nodes` what it parsed into.
 */
export function createDocument(schema, html) {
  const source = document.createElement("div");
  source.innerHTML = html;

  const groups = [];
  let current = null;
  for (const node of Array.from(source.childNodes)) {
    const isText = node.nodeType === Node.TEXT_NODE;
    const isBlank = isText && node.data.trim() === "";
    const tag = node.nodeType === Node.ELEMENT_NODE ? node.tagName.toLowerCase() : "";
    const inline = (isText && !isBlank) || PHRASING_TAGS.has(tag);

    if (isBlank || node.nodeType === Node.COMMENT_NODE) {
      if (current) current.trailing.push(node);
      else groups.push({ nodes: [], trailing: [node], inline: false });
      continue;
    }
    if (inline && current && current.inline && current.trailing.every((n) => n.nodeType === Node.TEXT_NODE)) {
      current.nodes.push(...current.trailing, node);
      current.trailing = [];
      continue;
    }
    current = { nodes: [node], trailing: [], inline };
    groups.push(current);
  }

  const parser = PMDOMParser.fromSchema(schema);
  const segments = [];
  const allNodes = [];
  for (const group of groups) {
    const holder = document.createElement("div");
    group.nodes.forEach((n) => holder.appendChild(n.cloneNode(true)));
    const original = holder.innerHTML;
    const trailingHolder = document.createElement("div");
    group.trailing.forEach((n) => trailingHolder.appendChild(n.cloneNode(true)));

    let nodes = [];
    if (group.nodes.length) {
      preprocess(holder);
      nodes = [];
      parser.parse(holder).content.forEach((child) => nodes.push(child));
    }
    segments.push({ html: original + trailingHolder.innerHTML, nodes });
    allNodes.push(...nodes);
  }

  const doc = allNodes.length
    ? schema.topNodeType.create(null, allNodes)
    : schema.topNodeType.createAndFill();
  return { doc, segments: segments.filter((s) => s.nodes.length || s.html.trim() !== "" || segments.length === 1) };
}

/** Serialize nodes to HTML with the editing-only structure removed. */
export function serializeNodes(schema, nodes) {
  const serializer = DOMSerializer.fromSchema(schema);
  const wrap = document.createElement("div");
  const sink = [];
  rawSink = sink;
  try {
    wrap.appendChild(serializer.serializeFragment(Fragment.fromArray(nodes)));
  } finally {
    rawSink = null;
  }
  postprocess(wrap);
  return wrap.innerHTML.replace(/<wysite-raw data-i="(\d+)"><\/wysite-raw>/g, (_, index) => sink[Number(index)] ?? "");
}

/**
 * Serialize a document, re-using the original markup of every top-level block
 * that is unchanged since createDocument(). Returns { html, rewritten } where
 * `rewritten` lists the segments whose markup had to be regenerated.
 */
export function serializeDocument(schema, doc, segments) {
  const children = [];
  doc.content.forEach((child) => children.push(child));

  const matchesAt = (position, nodes) => {
    if (nodes.length === 0 || position + nodes.length > children.length) return false;
    return nodes.every((node, offset) => children[position + offset].eq(node));
  };

  let out = "";
  let cursor = 0;
  const reused = new Set();
  segments.forEach((segment, index) => {
    if (segment.nodes.length === 0) {
      // Pure whitespace/comments between blocks: keep while neighbours are kept.
      if (cursor === children.length || reused.has(index - 1)) out += segment.html;
      return;
    }
    for (let position = cursor; position <= children.length - segment.nodes.length; position++) {
      if (matchesAt(position, segment.nodes)) {
        out += serializeNodes(schema, children.slice(cursor, position));
        out += segment.html;
        cursor = position + segment.nodes.length;
        reused.add(index);
        return;
      }
    }
  });
  out += serializeNodes(schema, children.slice(cursor));

  const rewritten = segments.filter((segment, index) => segment.nodes.length && !reused.has(index));
  return { html: out, rewritten };
}

// ---------------------------------------------------------------------------
// Loss detection for the pre-save guard

function tagCounts(html) {
  const template = document.createElement("template");
  template.innerHTML = html;
  const counts = {};
  template.content.querySelectorAll("*").forEach((el) => {
    const tag = el.tagName.toLowerCase();
    counts[tag] = (counts[tag] || 0) + 1;
  });
  return counts;
}

/**
 * Elements the schema can't carry through a re-serialization of these segments
 * (i.e. would be lost by the editor itself, not by the user's edits).
 * Returns [{ tag, count }].
 */
export function schemaLoss(schema, segments) {
  const lost = {};
  for (const segment of segments) {
    const before = tagCounts(segment.html);
    const after = tagCounts(serializeNodes(schema, segment.nodes));
    for (const [tag, count] of Object.entries(before)) {
      const missing = count - (after[tag] || 0);
      if (missing > 0) lost[tag] = (lost[tag] || 0) + missing;
    }
  }
  return Object.entries(lost).map(([tag, count]) => ({ tag, count }));
}
