// dropin-wysiwyg.js
import * as pm from "./pm-bundle.js";
import { buildSchema, createDocument, serializeDocument, schemaLoss } from "./html-fidelity.js";

function buildUI({ onSave, onCancel }) {
  const root = document.createElement("div");
  root.className = "pm-dropin";

  const toolbar = document.createElement("div");
  toolbar.className = "pm-toolbar";

  const editorHost = document.createElement("div");
  editorHost.className = "pm-editorHost";

  const sourceHost = document.createElement("div");
  sourceHost.className = "pm-sourceHost";

  const sourceEditor = document.createElement("textarea");
  sourceEditor.className = "pm-sourceEditor";
  sourceEditor.spellcheck = false;
  sourceHost.appendChild(sourceEditor);

  const footer = document.createElement("div");
  footer.className = "pm-footer";

  const btn = (label, title, onClick, className = "") => {
    const b = document.createElement("button");
    b.type = "button";
    b.className = ["pm-btn", className].filter(Boolean).join(" ");
    b.textContent = label;
    b.title = title;
    b.addEventListener("click", onClick);
    return b;
  };

  footer.append(
    btn("Save", "Save HTML back into the page", onSave, "pm-btn--save"),
    btn("Cancel", "Discard changes", onCancel, "pm-btn--cancel")
  );

  root.append(toolbar, editorHost, sourceHost, footer);

  const style = document.createElement("style");
  style.textContent = `
    .pm-dropin { margin-top: 12px; border: 1px solid rgba(0,0,0,.15); border-radius: 12px; overflow: hidden; background: transparent; }
    /* Toolbar/footer get a defined light surface so controls stay legible over any
       theme; the editor body stays transparent so you still see the block's theme. */
    .pm-dropin .pm-toolbar { display:flex !important; flex-wrap:wrap !important; align-items:center !important; gap:6px !important; padding:10px !important; border-bottom: 1px solid rgba(0,0,0,.12); background: #f3f5f8 !important; }
    .pm-editorHost { padding: 12px; background: transparent; }
    .pm-dropin .pm-sourceHost { display:none; padding: 12px; border-top: 1px solid rgba(0,0,0,.08); background: #f3f5f8; }
    .pm-dropin .pm-sourceHost.is-active { display:block; }
    .pm-dropin .pm-sourceEditor { width: 100%; min-height: 320px; box-sizing: border-box; border: 1px solid rgba(0,0,0,.16); border-radius: 12px; padding: 12px; font: 13px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #ffffff; color: #111827; resize: vertical; }
    .pm-dropin .pm-footer { display:flex !important; justify-content:flex-end !important; gap:8px !important; padding:10px !important; border-top: 1px solid rgba(0,0,0,.12); background: #f3f5f8 !important; }

    /* Theme-proof controls: lock every property a page theme might override (color,
       font, sizing, text-indent, fill, visibility…) so the icons/labels are always
       readable regardless of the theme the edited block inherits. */
    .pm-dropin .pm-btn {
      box-sizing: border-box !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 5px !important;
      min-width: 34px !important;
      height: 34px !important;
      padding: 0 9px !important;
      margin: 0 !important;
      border: 1px solid rgba(0,0,0,.2) !important;
      border-radius: 8px !important;
      background: #ffffff !important;
      color: #1f2937 !important;
      -webkit-text-fill-color: #1f2937 !important;
      font: 600 13px/1 system-ui, -apple-system, "Segoe UI", sans-serif !important;
      letter-spacing: 0 !important;
      text-transform: none !important;
      text-indent: 0 !important;
      text-shadow: none !important;
      white-space: nowrap !important;
      opacity: 1 !important;
      visibility: visible !important;
      box-shadow: none !important;
      cursor: pointer !important;
      vertical-align: middle !important;
    }
    .pm-dropin .pm-btn:hover { background: #eef2f7 !important; }
    .pm-dropin .pm-btn svg { width: 18px !important; height: 18px !important; display: block !important; stroke: #1f2937 !important; fill: none !important; stroke-width: 2 !important; }
    .pm-dropin select.pm-btn option { color: #1f2937 !important; background: #ffffff !important; }
    .pm-dropin .pm-btn.pm-btn--save { background: #16a34a !important; border-color: #15803d !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; font-weight: 800 !important; padding: 0 14px !important; }
    .pm-dropin .pm-btn.pm-btn--save:hover { background: #15803d !important; }
    .pm-dropin .pm-btn.pm-btn--cancel { background: #ffffff !important; border-color: #dc2626 !important; color: #b91c1c !important; -webkit-text-fill-color: #b91c1c !important; font-weight: 800 !important; padding: 0 14px !important; }
    .pm-dropin .pm-btn.pm-btn--cancel:hover { background: #fef2f2 !important; }
    .pm-dropin .pm-btn:disabled { opacity: .5 !important; cursor: not-allowed !important; }
    .pm-dropin .pm-btn[aria-pressed="true"] { background: #dbeafe !important; border-color: #3b82f6 !important; color: #1e3a8a !important; -webkit-text-fill-color: #1e3a8a !important; }
    .pm-dropin .pm-btn[aria-pressed="true"] svg { stroke: #1e3a8a !important; }
    .pm-image-upload-placeholder { font-size: 12px; opacity: .85; }
    .pm-dropin .pm-link-dialog { display: grid; gap: 8px; padding: 10px; border-bottom: 1px solid rgba(0,0,0,.12); background: #f8fafc; color: #1f2937; font: 14px/1.4 system-ui, sans-serif; }
    .pm-dropin .pm-link-dialog input[type="text"] { box-sizing: border-box; width: 100%; padding: 8px 10px; border: 1px solid rgba(0,0,0,.2); border-radius: 8px; font: inherit; color: #111827; background: #fff; }
    .pm-dropin .pm-link-dialog__row { display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
    .pm-dropin .pm-link-dialog__row label { margin-right: auto; color: #1f2937; }
    .pm-dropin .pm-raw { position: relative; outline: 1px dashed rgba(59,130,246,.55); outline-offset: 2px; }
    .pm-dropin .pm-raw--inline { display: inline-block; }
    .pm-dropin .pm-raw.is-selected { outline: 2px solid #3b82f6; }
    .pm-dropin .pm-raw__content { pointer-events: none; }
    .pm-dropin .pm-raw__label { position: absolute; top: -10px; left: 6px; padding: 1px 6px; border-radius: 6px; background: #1e3a8a; color: #fff; -webkit-text-fill-color: #fff; font: 600 10px/1.5 system-ui, sans-serif; letter-spacing: 0; text-transform: none; opacity: 0; transition: opacity .15s; pointer-events: none; white-space: nowrap; }
    .pm-dropin .pm-raw:hover .pm-raw__label, .pm-dropin .pm-raw.is-selected .pm-raw__label { opacity: 1; }
    /* ProseMirror requires the editable surface to use pre-wrap for correct
       caret/whitespace handling; scoped to the live editor only, so published
       output (which has no .ProseMirror element) keeps the surrounding CSS. */
    .ProseMirror { outline: none; background: transparent; white-space: pre-wrap; }
    .ProseMirror img { max-width: 100%; height: auto; }
    /* A lone paragraph inside a list item / table cell is unwrapped on save, so
       render it tight here too — the live editor then matches the published HTML. */
    .ProseMirror li > p:only-of-type,
    .ProseMirror td > p:only-of-type,
    .ProseMirror th > p:only-of-type { margin: 0; }

    /* --- Table styling override (match site/output look) --- */
    .pm-dropin .ProseMirror table {
      width: 100%;
      border-collapse: collapse;
      margin: 10px 0;
    }
    .pm-dropin .ProseMirror td,
    .pm-dropin .ProseMirror th {
      border: 1px solid var(--border, rgba(0,0,0,.18));
      padding: 8px;
      vertical-align: top;
    }
    .pm-dropin .ProseMirror th {
      background: rgba(255,255,255,0.06);
      text-align: left;
    }
    .pm-dropin .ProseMirror .tableWrapper {
      margin: 10px 0;
      overflow-x: auto;
    }
    .pm-dropin .ProseMirror .selectedCell:after {
      background: rgba(122,162,255,.18);
    }
    .pm-dropin .ProseMirror .column-resize-handle {
      background: rgba(122,162,255,.55);
    }
  `;
  root.appendChild(style);

  return { root, toolbar, editorHost, sourceHost, sourceEditor };
}

export const DropInWysiwyg = {
  async mount(target, opts = {}) {
    const el = typeof target === "string" ? document.querySelector(target) : target;
    if (!el) throw new Error("DropInWysiwyg.mount: target not found");
    if (el.__pmDropInMounted) return el.__pmDropInMounted;

    const { EditorState, Plugin, PluginKey } = pm.state;
    const { EditorView, Decoration, DecorationSet } = pm.view;

    const { schema: basicSchema } = pm.basic;
    const {
      addListNodes,
      wrapInList,
      splitListItem,
      sinkListItem,
      liftListItem,
    } = pm.list;

    const {
      baseKeymap,
      toggleMark,
      setBlockType,
      exitCode,
      joinUp,
      joinDown,
      lift,
      joinBackward,
    } = pm.commands;

    const { history, undo, redo } = pm.history;
    const { keymap } = pm.keymap;
    const { inputRules, smartQuotes, ellipsis, emDash } = pm.inputrules;

    const {
      tableNodes,
      tableEditing,
      columnResizing,
      addRowAfter,
      deleteRow,
      addColumnAfter,
      deleteColumn,
      deleteTable,
      mergeCells,
      splitCell,
      toggleHeaderRow,
      toggleHeaderColumn,
      isInTable,
      goToNextCell,
    } = pm.tables;

    const { dropCursor } = pm.dropcursor;
    const { gapCursor } = pm.gapcursor;

    // ---------- Schema ----------
    // See html-fidelity.js: every attribute is preserved, unknown elements become
    // verbatim atoms, and untouched top-level blocks keep their original markup.
    const schema = buildSchema();
    const originalHTML = opts.restoreHTML ?? el.innerHTML;

    // Read-only view for verbatim atoms (iframes, video, forms, ...): the real
    // markup is shown so the block looks as it will be published.
    function rawNodeView(node) {
      const inline = node.type.name === "raw_inline";
      const dom = document.createElement(inline ? "span" : "div");
      dom.className = "pm-raw" + (inline ? " pm-raw--inline" : "");
      dom.contentEditable = "false";
      dom.title = "Embedded content — edit it in HTML mode (the </> button). It is saved exactly as-is.";
      const content = document.createElement(inline ? "span" : "div");
      content.className = "pm-raw__content";
      content.innerHTML = node.attrs.html;
      const label = document.createElement("span");
      label.className = "pm-raw__label";
      label.textContent = "Embedded content — edit in HTML mode";
      dom.append(content, label);
      return {
        dom,
        ignoreMutation: () => true,
        stopEvent: () => false,
        selectNode: () => dom.classList.add("is-selected"),
        deselectNode: () => dom.classList.remove("is-selected"),
      };
    }

    // ---------- Image placeholder plugin ----------
    const placeholderKey = new PluginKey("imageUploadPlaceholder");

    function placeholderPlugin() {
      return new Plugin({
        key: placeholderKey,
        state: {
          init() {
            return DecorationSet.empty;
          },
          apply(tr, set) {
            set = set.map(tr.mapping, tr.doc);
            const meta = tr.getMeta(this);

            if (meta?.add) {
              const { id, pos, previewUrl } = meta.add;
              const dom = document.createElement("span");
              dom.className = "pm-image-upload-placeholder";
              dom.textContent = "Uploading…";

              if (previewUrl) {
                const img = document.createElement("img");
                img.src = previewUrl;
                img.alt = "upload preview";
                img.style.maxWidth = "100%";
                img.style.display = "block";
                img.style.marginTop = "6px";
                img.style.borderRadius = "10px";
                dom.appendChild(img);
              }

              const deco = Decoration.widget(pos, dom, { id });
              set = set.add(tr.doc, [deco]);
            } else if (meta?.remove) {
              const { id } = meta.remove;
              set = set.remove(set.find(null, null, (spec) => spec.id === id));
            }
            return set;
          },
        },
        props: {
          decorations(state) {
            return this.getState(state);
          },
        },
      });
    }

    function findPlaceholder(state, id) {
      const decos = placeholderKey.getState(state);
      const found = decos.find(null, null, (spec) => spec.id === id);
      return found.length ? found[0].from : null;
    }

    const uploadImage =
      opts.uploadImage ||
      (async (file) => URL.createObjectURL(file)); // demo default

    async function startImageUpload({ view, file }) {
      const previewUrl = URL.createObjectURL(file);
      const id = Math.floor(Math.random() * 0xffffffff).toString(16);

      let tr = view.state.tr;
      if (!tr.selection.empty) tr = tr.deleteSelection();
      const pos = tr.selection.from;

      tr = tr.setMeta(placeholderKey, { add: { id, pos, previewUrl } });
      view.dispatch(tr);

      try {
        const url = await uploadImage(file);
        const insertPos = findPlaceholder(view.state, id);
        if (insertPos == null) return;

        const imageNode = schema.nodes.image.create({ src: url, alt: file.name });
        const tr2 = view.state.tr
          .replaceWith(insertPos, insertPos, imageNode)
          .setMeta(placeholderKey, { remove: { id } })
          .scrollIntoView();

        view.dispatch(tr2);
      } catch (error) {
        view.dispatch(view.state.tr.setMeta(placeholderKey, { remove: { id } }));
        opts.onError?.(error?.message || "Image upload failed.");
      } finally {
        URL.revokeObjectURL(previewUrl);
      }
    }

    function imagePasteDropPlugin() {
      return new Plugin({
        props: {
          handlePaste(view, event) {
            const items = event.clipboardData?.items;
            if (!items) return false;

            const files = [];
            for (const item of items) {
              if (item.kind === "file") {
                const f = item.getAsFile();
                if (f && /^image\//.test(f.type)) files.push(f);
              }
            }
            if (!files.length) return false;

            for (const f of files) startImageUpload({ view, file: f });
            return true;
          },

          handleDrop(view, event) {
            const dt = event.dataTransfer;
            if (!dt?.files?.length) return false;

            const files = [...dt.files].filter((f) => /^image\//.test(f.type));
            if (!files.length) return false;

            event.preventDefault();
            for (const f of files) startImageUpload({ view, file: f });
            return true;
          },
        },
      });
    }

    // ---------- Helpers ----------
    function markActive(state, markType) {
      const { from, $from, to, empty } = state.selection;
      if (empty) return !!markType.isInSet(state.storedMarks || $from.marks());
      return state.doc.rangeHasMark(from, to, markType);
    }

    function nodeActive(state, nodeType, attrs = {}) {
      const { $from, to, node } = state.selection;
      if (node) return node.hasMarkup(nodeType, attrs);
      return to <= $from.end() && $from.parent.hasMarkup(nodeType, attrs);
    }

    // Link dialog with a searchable list of the site's pages (NAV-1). Falls back
    // to typing any URL. opts.linkSuggestions() resolves to [{ title, url }].
    let linkSuggestionsPromise = null;
    function promptLink(view) {
      const link = schema.marks.link;
      if (markActive(view.state, link)) {
        toggleMark(link)(view.state, view.dispatch);
        view.focus();
        return;
      }

      const { from, to } = view.state.selection;
      const dialog = document.createElement("div");
      dialog.className = "pm-link-dialog";
      const listId = `pm-link-pages-${Math.random().toString(36).slice(2, 8)}`;
      const input = document.createElement("input");
      input.type = "text";
      input.placeholder = "Start typing a page title, or paste a URL";
      input.setAttribute("list", listId);
      input.setAttribute("aria-label", "Link address");
      const datalist = document.createElement("datalist");
      datalist.id = listId;
      const newTab = document.createElement("label");
      const newTabBox = document.createElement("input");
      newTabBox.type = "checkbox";
      newTab.append(newTabBox, document.createTextNode(" Open in a new tab"));
      const ok = document.createElement("button");
      ok.type = "button";
      ok.className = "pm-btn pm-btn--save";
      ok.textContent = "Add link";
      const cancel = document.createElement("button");
      cancel.type = "button";
      cancel.className = "pm-btn";
      cancel.textContent = "Cancel";
      const row = document.createElement("div");
      row.className = "pm-link-dialog__row";
      row.append(newTab, ok, cancel);
      dialog.append(input, datalist, row);
      ui.toolbar.insertAdjacentElement("afterend", dialog);
      input.focus();

      const titleToUrl = new Map();
      if (opts.linkSuggestions) {
        linkSuggestionsPromise ??= Promise.resolve(opts.linkSuggestions()).catch(() => []);
        linkSuggestionsPromise.then((pages) => {
          (pages || []).forEach(({ title, url }) => {
            const option = document.createElement("option");
            option.value = title;
            option.label = url;
            titleToUrl.set(title, url);
            datalist.appendChild(option);
          });
        });
      }

      const close = () => {
        dialog.remove();
        view.focus();
      };
      const apply = () => {
        const typed = input.value.trim();
        const href = titleToUrl.get(typed) ?? typed;
        if (href) {
          const attrs = { href };
          if (newTabBox.checked) {
            attrs.htmlAttrs = { target: "_blank", rel: "noopener" };
          }
          const tr = view.state.tr;
          if (from === to) {
            // No selection: insert the page title (or URL) as the link text.
            const text = titleToUrl.has(typed) ? typed : href;
            tr.insertText(text, from).addMark(from, from + text.length, link.create(attrs));
          } else {
            tr.addMark(from, to, link.create(attrs));
          }
          view.dispatch(tr);
        }
        close();
      };
      ok.addEventListener("click", apply);
      cancel.addEventListener("click", close);
      input.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          apply();
        } else if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          close();
        }
      });
    }

    function insertTableCmd({ rows = 3, cols = 3 } = {}) {
      return (state, dispatch) => {
        const tableType = schema.nodes.table;
        const rowType = schema.nodes.table_row;
        const cellType = schema.nodes.table_cell;
        if (!tableType || !rowType || !cellType) return false;

        const rowNodes = [];
        for (let r = 0; r < rows; r++) {
          const cells = [];
          for (let c = 0; c < cols; c++) {
            const cell = cellType.createAndFill();
            if (cell) cells.push(cell);
          }
          rowNodes.push(rowType.create(null, cells));
        }
        const table = tableType.create(null, rowNodes);
        if (!table) return false;

        if (dispatch) dispatch(state.tr.replaceSelectionWith(table).scrollIntoView());
        return true;
      };
    }

    function inListItem(state) {
      const { $from } = state.selection;
      const li = schema.nodes.list_item;
      for (let d = $from.depth; d > 0; d--) {
        if ($from.node(d).type === li) return true;
      }
      return false;
    }

    function atStartOfTextblock(state) {
      const sel = state.selection;
      if (!sel.empty) return false;
      const { $from } = sel;
      return $from.parent.isTextblock && $from.parentOffset === 0;
    }

    function emptyTextblockAtCursor(state) {
      const sel = state.selection;
      if (!sel.empty) return false;
      const { $from } = sel;
      const p = $from.parent;
      return p.isTextblock && p.content.size === 0;
    }

    // Find nearest ancestor list node (bullet_list or ordered_list)
    function nearestListAncestor($from) {
      const bullet = schema.nodes.bullet_list;
      const ordered = schema.nodes.ordered_list;
      for (let d = $from.depth; d > 0; d--) {
        const node = $from.node(d);
        if (node.type === bullet || node.type === ordered) {
          return { depth: d, node };
        }
      }
      return null;
    }

    // Convert nearest list type in place (bullet <-> ordered), preserving items/nesting
    function setNearestListType(listType) {
      return (state, dispatch) => {
        const { $from } = state.selection;
        const found = nearestListAncestor($from);
        if (!found) return false;
        if (found.node.type === listType) return false;

        if (dispatch) {
          const pos = $from.before(found.depth);
          const tr = state.tr.setNodeMarkup(pos, listType, found.node.attrs, found.node.marks);
          dispatch(tr.scrollIntoView());
        }
        return true;
      };
    }

    // TipTap-ish toggle:
    // - if inside same list type => lift out
    // - if inside other list type => convert that list type
    // - else => wrap selection in list
    function toggleListRich(listType) {
      const li = schema.nodes.list_item;
      const bullet = schema.nodes.bullet_list;
      const ordered = schema.nodes.ordered_list;

      return (state, dispatch, view) => {
        const { $from } = state.selection;
        const found = nearestListAncestor($from);

        if (found) {
          if (found.node.type === listType) {
            return liftListItem(li)(state, dispatch, view);
          }
          if (found.node.type === bullet || found.node.type === ordered) {
            return setNearestListType(listType)(state, dispatch, view);
          }
        }

        return wrapInList(listType)(state, dispatch, view);
      };
    }

    function richListKeymapPlugin() {
      const li = schema.nodes.list_item;

      const splitLI = splitListItem(li);
      const sinkLI = sinkListItem(li);
      const liftLI = liftListItem(li);

      return keymap({
        // Enter in list => new list item
        Enter: (state, dispatch, view) => {
          if (isInTable(state)) return false;
          if (!inListItem(state)) return false;
          return splitLI(state, dispatch, view);
        },

        // Tab/Shift-Tab:
        // - table: next/prev cell
        // - list: indent/outdent
        Tab: (state, dispatch, view) => {
          if (isInTable(state)) return goToNextCell(1)(state, dispatch, view);
          if (!inListItem(state)) return false;
          return sinkLI(state, dispatch, view);
        },
        "Shift-Tab": (state, dispatch, view) => {
          if (isInTable(state)) return goToNextCell(-1)(state, dispatch, view);
          if (!inListItem(state)) return false;
          return liftLI(state, dispatch, view);
        },

        // Mod-[ and Mod-] for list indent/outdent
        "Mod-]": (state, dispatch, view) => {
          if (isInTable(state)) return false;
          if (!inListItem(state)) return false;
          return sinkLI(state, dispatch, view);
        },
        "Mod-[": (state, dispatch, view) => {
          if (isInTable(state)) return false;
          if (!inListItem(state)) return false;
          return liftLI(state, dispatch, view);
        },

        // Backspace behavior:
        // - empty list item => lift out (TipTap-like)
        // - at start of list item => join with previous item if possible
        Backspace: (state, dispatch, view) => {
          if (isInTable(state)) return false;
          if (!inListItem(state)) return false;

          if (emptyTextblockAtCursor(state)) {
            return liftLI(state, dispatch, view);
          }

          if (atStartOfTextblock(state)) {
            // Prefer joining with previous item; if not possible, try lift.
            if (joinBackward(state, dispatch, view)) return true;
            return liftLI(state, dispatch, view);
          }

          return false;
        },

        // Useful list toggles
        "Mod-Shift-8": toggleListRich(schema.nodes.bullet_list),
        "Mod-Shift-7": toggleListRich(schema.nodes.ordered_list),
      });
    }

    // ---------- Mount ----------
    let instance;
    const ui = buildUI({
      onSave: async () => instance.save(),
      onCancel: async () => instance.cancel({ confirmIfDirty: true }),
    });

    if (opts.rootClassName) {
      ui.root.classList.add(opts.rootClassName);
    }

    if (opts.rootStyle && typeof opts.rootStyle === "object") {
      Object.entries(opts.rootStyle).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== "") {
          ui.root.style.setProperty(key, value);
        }
      });
    }

    const mountAfterTarget = opts.mountAfterTarget || el;

    el.style.display = "none";
    mountAfterTarget.insertAdjacentElement("afterend", ui.root);

    const plugins = [
      history(),
      inputRules({ rules: smartQuotes.concat(ellipsis, emDash) }),

      richListKeymapPlugin(),

      keymap({
        "Mod-z": undo,
        "Shift-Mod-z": redo,
        "Mod-y": redo,
        "Alt-ArrowUp": joinUp,
        "Alt-ArrowDown": joinDown,
        "Mod-BracketLeft": lift,
        "Mod-Enter": exitCode,
      }),

      keymap(baseKeymap),

      dropCursor(),
      gapCursor(),

      columnResizing(),
      tableEditing(),

      placeholderPlugin(),
      imagePasteDropPlugin(),
    ];

    // The exact markup we started from, and the parsed document it produced.
    // If the user never changes the document, we save this back verbatim; if they
    // do, every top-level block they didn't touch is still written from its
    // original markup (serializeDocument), so only edited blocks are regenerated.
    const initialSourceHTML = opts.initialHTML ?? originalHTML;
    let parsed = createDocument(schema, initialSourceHTML);
    let initialDoc = parsed.doc;

    const state = EditorState.create({
      schema,
      doc: parsed.doc,
      plugins,
    });

    let view;
    let sourceMode = false;
    let sourceDirty = false;

    function currentHTML() {
      if (sourceMode) {
        return ui.sourceEditor.value;
      }
      if (view.state.doc.eq(initialDoc)) {
        return parsed.html ?? initialSourceHTML;
      }
      return serializeDocument(schema, view.state.doc, parsed.segments).html;
    }

    function isDirty() {
      return sourceDirty || !view.state.doc.eq(initialDoc) || (parsed.html !== undefined && parsed.html !== initialSourceHTML);
    }

    function setSourceMode(nextMode) {
      if (nextMode) {
        ui.sourceEditor.value = currentHTML();
      } else {
        // Re-parse the edited source; it becomes the new "original" so blocks the
        // user didn't touch in rich mode stay exactly as typed in HTML mode.
        const html = ui.sourceEditor.value;
        const wasDirty = isDirty();
        parsed = createDocument(schema, html);
        parsed.html = html;
        initialDoc = parsed.doc;
        sourceDirty = wasDirty || html !== initialSourceHTML;
        view.updateState(EditorState.create({ schema, doc: parsed.doc, plugins }));
      }
      sourceMode = nextMode;
      ui.sourceHost.classList.toggle("is-active", sourceMode);
      ui.editorHost.style.display = sourceMode ? "none" : "";
      (sourceMode ? ui.sourceEditor : view).focus();
      instance?.updateToolbar?.();
    }

    function buildToolbar() {
      const controls = [];

      // Inline SVG icons so labels never disappear into a theme's typography/colour.
      const svg = (paths) =>
        `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
      const ICONS = {
        bold: svg('<path d="M14 12a4 4 0 0 0 0-8H6v8"/><path d="M15 20a4 4 0 0 0 0-8H6v8Z"/>'),
        italic: svg('<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>'),
        code: svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'),
        bulletList: svg('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'),
        orderedList: svg('<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>'),
        link: svg('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'),
        image: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>'),
        source: svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/><line x1="13" y1="4" x2="11" y2="20"/>'),
      };

      const registerControl = (control, allowWhenSource = false) => {
        controls.push({ control, allowWhenSource });
        return control;
      };

      const addSep = () => {
        const s = document.createElement("span");
        s.style.width = "10px";
        ui.toolbar.appendChild(s);
      };

      const add = (iconName, title, commandFactory) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "pm-btn";
        b.innerHTML = ICONS[iconName] || "";
        b.title = title;
        b.setAttribute("aria-label", title);
        b.addEventListener("click", () => {
          const cmd = commandFactory();
          cmd(view.state, view.dispatch, view);
          view.focus();
          updateToolbar();
        });
        ui.toolbar.appendChild(b);
        return registerControl(b);
      };

      const btnBold = add("bold", "Bold", () => toggleMark(schema.marks.strong));
      const btnItalic = add("italic", "Italic", () => toggleMark(schema.marks.em));
      const btnCode = add("code", "Inline code", () => toggleMark(schema.marks.code));
      addSep();

      const headingSelect = document.createElement("select");
      headingSelect.className = "pm-btn";
      headingSelect.title = "Text style";
      [
        ["", "Style..."],
        ["paragraph", "Paragraph"],
        ["h1", "Heading 1"],
        ["h2", "Heading 2"],
        ["h3", "Heading 3"],
        ["h4", "Heading 4"],
        ["h5", "Heading 5"],
        ["h6", "Heading 6"],
      ].forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        headingSelect.appendChild(option);
      });
      headingSelect.addEventListener("change", () => {
        const value = headingSelect.value;
        if (!value) {
          return;
        }
        if (value === "paragraph") {
          setBlockType(schema.nodes.paragraph)(view.state, view.dispatch, view);
        } else {
          setBlockType(schema.nodes.heading, { level: Number(value.slice(1)) })(view.state, view.dispatch, view);
        }
        view.focus();
        headingSelect.value = "";
        updateToolbar();
      });
      ui.toolbar.appendChild(headingSelect);
      registerControl(headingSelect);
      addSep();

      add("bulletList", "Bullet list", () => toggleListRich(schema.nodes.bullet_list));
      add("orderedList", "Numbered list", () => toggleListRich(schema.nodes.ordered_list));
      addSep();

      const btnLink = document.createElement("button");
      btnLink.type = "button";
      btnLink.className = "pm-btn";
      btnLink.innerHTML = ICONS.link;
      btnLink.title = "Add/remove link";
      btnLink.setAttribute("aria-label", "Add or remove link");
      btnLink.addEventListener("click", () => promptLink(view));
      ui.toolbar.appendChild(btnLink);
      registerControl(btnLink);

      addSep();

      const tableSelect = document.createElement("select");
      tableSelect.className = "pm-btn";
      tableSelect.title = "Table actions";
      [
        ["", "Table..."],
        ["insert", "Insert 3x3"],
        ["add-row", "Add Row"],
        ["delete-row", "Delete Row"],
        ["add-col", "Add Column"],
        ["delete-col", "Delete Column"],
        ["header-row", "Toggle Header Row"],
        ["header-col", "Toggle Header Column"],
        ["merge", "Merge Cells"],
        ["split", "Split Cell"],
        ["delete", "Delete Table"],
      ].forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        tableSelect.appendChild(option);
      });
      tableSelect.addEventListener("change", () => {
        const actions = {
          insert: insertTableCmd({ rows: 3, cols: 3 }),
          "add-row": addRowAfter,
          "delete-row": deleteRow,
          "add-col": addColumnAfter,
          "delete-col": deleteColumn,
          "header-row": toggleHeaderRow,
          "header-col": toggleHeaderColumn,
          merge: mergeCells,
          split: splitCell,
          delete: deleteTable,
        };
        const command = actions[tableSelect.value];
        if (command) {
          command(view.state, view.dispatch, view);
          view.focus();
          updateToolbar();
        }
        tableSelect.value = "";
      });
      ui.toolbar.appendChild(tableSelect);
      registerControl(tableSelect);

      addSep();

      const sourceBtn = document.createElement("button");
      sourceBtn.type = "button";
      sourceBtn.className = "pm-btn";
      sourceBtn.innerHTML = ICONS.source;
      sourceBtn.title = "Toggle raw HTML source editing";
      sourceBtn.setAttribute("aria-label", "Toggle HTML source");
      sourceBtn.addEventListener("click", () => {
        setSourceMode(!sourceMode);
      });
      ui.toolbar.appendChild(sourceBtn);
      registerControl(sourceBtn, true);

      addSep();

      const imgBtn = document.createElement("button");
      imgBtn.type = "button";
      imgBtn.className = "pm-btn";
      imgBtn.innerHTML = ICONS.image;
      imgBtn.title = "Upload/insert an image";
      imgBtn.setAttribute("aria-label", "Insert image");
      imgBtn.addEventListener("click", () => {
        const input = document.createElement("input");
        input.type = "file";
        input.accept = "image/*";
        input.onchange = () => {
          const file = input.files?.[0];
          if (file) startImageUpload({ view, file });
        };
        input.click();
      });
      ui.toolbar.appendChild(imgBtn);
      registerControl(imgBtn);

      function updateToolbar() {
        controls.forEach(({ control, allowWhenSource }) => {
          control.disabled = sourceMode && !allowWhenSource;
        });

        if (sourceMode) {
          sourceBtn.setAttribute("aria-pressed", "true");
          return;
        }

        btnBold.setAttribute("aria-pressed", markActive(view.state, schema.marks.strong) ? "true" : "false");
        btnItalic.setAttribute("aria-pressed", markActive(view.state, schema.marks.em) ? "true" : "false");
        btnCode.setAttribute("aria-pressed", markActive(view.state, schema.marks.code) ? "true" : "false");
        btnLink.setAttribute("aria-pressed", markActive(view.state, schema.marks.link) ? "true" : "false");
        sourceBtn.setAttribute("aria-pressed", "false");

      headingSelect.value = "";
      for (let level = 1; level <= 6; level += 1) {
        if (nodeActive(view.state, schema.nodes.heading, { level })) {
          headingSelect.value = `h${level}`;
          break;
        }
      }
      }

      return updateToolbar;
    }

    view = new EditorView(ui.editorHost, {
      state,
      nodeViews: {
        raw_block: (node) => rawNodeView(node),
        raw_inline: (node) => rawNodeView(node),
      },
      dispatchTransaction(tr) {
        const newState = view.state.apply(tr);
        view.updateState(newState);
        instance?.updateToolbar?.();
      },
    });

    instance = {
      target: el,
      root: ui.root,
      view,
      updateToolbar: null,

      isDirty,

      async save() {
        let html;
        if (sourceMode) {
          html = ui.sourceEditor.value;
        } else if (view.state.doc.eq(initialDoc)) {
          // Unedited: preserve the original markup exactly.
          html = parsed.html ?? initialSourceHTML;
        } else {
          const result = serializeDocument(schema, view.state.doc, parsed.segments);
          // Pre-save guard: if regenerating the edited blocks would drop elements
          // the rich editor can't represent, say so before anything is written.
          const lost = schemaLoss(schema, result.rewritten);
          if (lost.length) {
            const list = lost.map(({ tag, count }) => `<${tag}> ×${count}`).join(", ");
            const proceed = window.confirm(
              `Saving would remove markup the visual editor can't represent in the blocks you changed: ${list}.\n\n` +
              "Cancel, then use HTML mode (the </> button) to make this edit without losing it — or OK to save anyway."
            );
            if (!proceed) {
              return false;
            }
          }
          html = result.html;
        }
        el.innerHTML = html;
        await opts.onSave?.({ html, target: el });
        instance.destroy();
        el.style.display = "";
        return true;
      },

      async cancel({ confirmIfDirty = false } = {}) {
        if (confirmIfDirty && isDirty() && !window.confirm("Discard your unsaved changes to this section?")) {
          return false;
        }
        el.innerHTML = originalHTML;
        sourceMode = false;
        await opts.onCancel?.({ target: el });
        instance.destroy();
        el.style.display = "";
        return true;
      },

      destroy() {
        window.removeEventListener("beforeunload", beforeUnload);
        ui.root.removeEventListener("keydown", shortcutKeys, true);
        try {
          view.destroy();
        } catch {}
        ui.root.remove();
        delete el.__pmDropInMounted;
      },
    };

    // Unsaved-changes guard and shortcuts: Mod-S saves, Esc cancels (asking
    // first when there are unsaved changes). Captured on the editor root so they
    // work in both rich and HTML mode.
    function beforeUnload(event) {
      if (isDirty()) {
        event.preventDefault();
        event.returnValue = "";
      }
    }
    function shortcutKeys(event) {
      if (event.target instanceof Element && event.target.closest(".pm-link-dialog")) {
        return; // the link dialog handles its own Enter/Escape
      }
      if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === "s") {
        event.preventDefault();
        instance.save();
      } else if (event.key === "Escape" && !event.defaultPrevented) {
        event.preventDefault();
        instance.cancel({ confirmIfDirty: true });
      }
    }
    window.addEventListener("beforeunload", beforeUnload);
    ui.root.addEventListener("keydown", shortcutKeys, true);

    instance.updateToolbar = buildToolbar();
    instance.updateToolbar();

    el.__pmDropInMounted = instance;
    return instance;
  },
};
