// block-commands.js — EDT-B: duplicate, move and select whole blocks (a card,
// a column, a paragraph) inside the drop-in editor. Copies keep every
// attribute (class, style, data-*), so they look the same; ids are made unique
// and references to them inside the copy (label for, aria-*, #fragment links)
// follow, so the page never ends up with two elements sharing an id.
import * as pm from "./pm-bundle.js";

const { NodeSelection } = pm.state;
const { Fragment } = pm.model;

/** Attributes whose value is a list of ids. */
const ID_LIST_ATTRS = ["for", "aria-labelledby", "aria-describedby", "aria-controls", "aria-owns", "aria-activedescendant", "headers", "list", "form"];
/** Attributes whose value may be "#id". */
const HASH_ATTRS = ["href", "xlink:href", "data-target", "data-bs-target", "data-toggle-target"];

/** A fresh id: "<id>-2", "<id>-3", ... not used in the document or by this copy. */
export function uniqueId(id, taken) {
  let n = 2;
  let next = `${id}-${n}`;
  while (taken.has(next)) {
    n += 1;
    next = `${id}-${n}`;
  }
  taken.add(next);
  return next;
}

/** Rewrite id references in an attribute bag using map (old id => new id). */
export function remapAttrs(attrs, map) {
  if (!attrs) return attrs;
  const out = { ...attrs };
  if (out.id && map.has(out.id)) out.id = map.get(out.id);
  for (const name of ID_LIST_ATTRS) {
    if (typeof out[name] === "string") {
      out[name] = out[name].split(/\s+/).map((id) => map.get(id) || id).join(" ");
    }
  }
  for (const name of HASH_ATTRS) {
    if (typeof out[name] === "string" && out[name].startsWith("#") && map.has(out[name].slice(1))) {
      out[name] = `#${map.get(out[name].slice(1))}`;
    }
  }
  for (const name of Object.keys(out)) {
    if (typeof out[name] === "string" && out[name].includes("url(#")) {
      out[name] = out[name].replace(/url\(#([^)]+)\)/g, (m, id) => (map.has(id) ? `url(#${map.get(id)})` : m));
    }
  }
  if (out.type === "radio" && out.name && map.has(`radio:${out.name}`)) {
    out.name = map.get(`radio:${out.name}`);
  }
  return out;
}

/** Same rules for a live DOM subtree (section tools outside the editor). */
export function dedupeDomIds(root, doc = document) {
  const taken = new Set(Array.from(doc.querySelectorAll("[id]"), (el) => el.id));
  const map = new Map();
  const all = [root, ...root.querySelectorAll("*")];
  for (const el of all) {
    if (el.id) map.set(el.id, uniqueId(el.id, taken));
    if (el.matches?.("input[type=radio][name]") && !map.has(`radio:${el.name}`)) {
      map.set(`radio:${el.name}`, uniqueId(el.name, new Set(Array.from(doc.querySelectorAll("input[type=radio][name]"), (r) => r.name))));
    }
  }
  if (map.size === 0) return root;
  for (const el of all) {
    const before = {};
    for (const attr of Array.from(el.attributes)) before[attr.name] = attr.value;
    const after = remapAttrs(before, map);
    for (const [name, value] of Object.entries(after)) {
      if (before[name] !== value) el.setAttribute(name, value);
    }
  }
  return root;
}

function collectIds(node, ids) {
  const own = node.attrs?.htmlAttrs?.id;
  if (own) ids.push(own);
  node.forEach?.((child) => collectIds(child, ids));
  return ids;
}

function remapNode(node, map) {
  if (node.isText) return node;
  const attrs = node.attrs.htmlAttrs ? { ...node.attrs, htmlAttrs: remapAttrs(node.attrs.htmlAttrs, map) } : node.attrs;
  const children = [];
  node.forEach((child) => children.push(remapNode(child, map)));
  return node.type.create(attrs, Fragment.fromArray(children), node.marks);
}

/** A copy of a ProseMirror node with unique ids. */
export function copyWithUniqueIds(node, view) {
  const taken = new Set(view ? Array.from(view.dom.ownerDocument.querySelectorAll("[id]"), (el) => el.id) : []);
  view?.state.doc.descendants((n) => {
    const id = n.attrs?.htmlAttrs?.id;
    if (id) taken.add(id);
  });
  const map = new Map();
  for (const id of collectIds(node, [])) map.set(id, uniqueId(id, taken));
  return map.size ? remapNode(node, map) : node;
}

/** The block the commands act on: a selected node, or the block around the cursor. */
export function targetBlock(state) {
  const sel = state.selection;
  if (sel instanceof NodeSelection && sel.node.isBlock) {
    const $pos = state.doc.resolve(sel.from);
    return { node: sel.node, pos: sel.from, parent: $pos.parent, index: $pos.index() };
  }
  const $from = sel.$from;
  for (let depth = $from.depth; depth > 0; depth--) {
    const node = $from.node(depth);
    if (node.isBlock) {
      return { node, pos: $from.before(depth), parent: $from.node(depth - 1), index: $from.index(depth - 1) };
    }
  }
  return null;
}

export function duplicateBlock(state, dispatch, view) {
  const target = targetBlock(state);
  if (!target) return false;
  if (dispatch) {
    const copy = copyWithUniqueIds(target.node, view);
    const at = target.pos + target.node.nodeSize;
    const tr = state.tr.insert(at, copy);
    tr.setSelection(NodeSelection.create(tr.doc, at));
    dispatch(tr.scrollIntoView());
  }
  return true;
}

export function moveBlock(direction) {
  return (state, dispatch) => {
    const target = targetBlock(state);
    if (!target) return false;
    const { node, pos, parent, index } = target;
    if (direction < 0 && index === 0) return false;
    if (direction > 0 && index >= parent.childCount - 1) return false;
    if (dispatch) {
      const sibling = parent.child(index + direction);
      const tr = state.tr.delete(pos, pos + node.nodeSize);
      const at = direction < 0 ? pos - sibling.nodeSize : pos + sibling.nodeSize;
      tr.insert(at, node);
      tr.setSelection(NodeSelection.create(tr.doc, at));
      dispatch(tr.scrollIntoView());
    }
    return true;
  };
}

/** Select the block around the cursor, then its parent on each repeat. */
export function selectParentBlock(state, dispatch) {
  const sel = state.selection;
  let $pos = sel instanceof NodeSelection ? state.doc.resolve(sel.from) : sel.$from;
  let depth = sel instanceof NodeSelection ? $pos.depth : $pos.depth;
  if (!(sel instanceof NodeSelection)) {
    while (depth > 0 && !$pos.node(depth).isBlock) depth--;
    if (depth === 0) return false;
    if (dispatch) dispatch(state.tr.setSelection(NodeSelection.create(state.doc, $pos.before(depth))));
    return true;
  }
  if (depth === 0) return false;
  if (dispatch) dispatch(state.tr.setSelection(NodeSelection.create(state.doc, $pos.before(depth))));
  return true;
}
