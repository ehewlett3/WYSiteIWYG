// dropin-wysiwyg.js
import * as pm from "./pm-bundle.js";

function buildUI({ onSave, onCancel }) {
  const root = document.createElement("div");
  root.className = "pm-dropin";

  const toolbar = document.createElement("div");
  toolbar.className = "pm-toolbar";

  const editorHost = document.createElement("div");
  editorHost.className = "pm-editorHost";

  const footer = document.createElement("div");
  footer.className = "pm-footer";

  const btn = (label, title, onClick) => {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "pm-btn";
    b.textContent = label;
    b.title = title;
    b.addEventListener("click", onClick);
    return b;
  };

  footer.append(
    btn("Save", "Save HTML back into the page", onSave),
    btn("Cancel", "Discard changes", onCancel)
  );

  root.append(toolbar, editorHost, footer);

  const style = document.createElement("style");
  style.textContent = `
    .pm-dropin { margin-top: 12px; border: 1px solid rgba(0,0,0,.12); border-radius: 12px; overflow: hidden; }
    .pm-toolbar { display:flex; flex-wrap:wrap; gap:6px; padding:10px; border-bottom: 1px solid rgba(0,0,0,.10); background: rgba(255,255,255,.06); }
    .pm-editorHost { padding: 12px; }
    .pm-footer { display:flex; justify-content:flex-end; gap:8px; padding:10px; border-top: 1px solid rgba(0,0,0,.10); background: rgba(255,255,255,.04); }
    .pm-btn { border: 1px solid rgba(0,0,0,.18); background: rgba(255,255,255,.08); padding: 6px 10px; border-radius: 10px; cursor: pointer; }
    .pm-btn[aria-pressed="true"] { outline: 2px solid rgba(120,160,255,.55); }
    .pm-image-upload-placeholder { font-size: 12px; opacity: .85; }
    .ProseMirror { outline: none; }
    .ProseMirror img { max-width: 100%; height: auto; }

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

  return { root, toolbar, editorHost };
}

export const DropInWysiwyg = {
  async mount(target, opts = {}) {
    const el = typeof target === "string" ? document.querySelector(target) : target;
    if (!el) throw new Error("DropInWysiwyg.mount: target not found");
    if (el.__pmDropInMounted) return el.__pmDropInMounted;

    const { Schema, DOMParser: PMDOMParser, DOMSerializer } = pm.model;
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
      selectParentNode,
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
    function buildSchema() {
      let nodes = addListNodes(basicSchema.spec.nodes, "paragraph block*", "block");
      nodes = nodes.append(
        tableNodes({
          tableGroup: "block",
          cellContent: "block+",
        })
      );
      return new Schema({ nodes, marks: basicSchema.spec.marks });
    }

    const schema = buildSchema();
    const originalHTML = el.innerHTML;

    function parseHTML(element) {
      const wrap = document.createElement("div");
      wrap.innerHTML = element.innerHTML;
      return PMDOMParser.fromSchema(schema).parse(wrap);
    }

    function serializeToHTML(doc) {
      const serializer = DOMSerializer.fromSchema(schema);
      const wrap = document.createElement("div");
      wrap.appendChild(serializer.serializeFragment(doc.content));
      return wrap.innerHTML;
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

    function promptLink(view) {
      const link = schema.marks.link;
      if (markActive(view.state, link)) {
        toggleMark(link)(view.state, view.dispatch);
        view.focus();
        return;
      }
      const href = window.prompt("Link URL:");
      if (!href) return;
      toggleMark(link, { href })(view.state, view.dispatch);
      view.focus();
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
      onCancel: async () => instance.cancel(),
    });

    if (opts.rootClassName) {
      ui.root.classList.add(opts.rootClassName);
    }

    el.style.display = "none";
    el.insertAdjacentElement("afterend", ui.root);

    const state = EditorState.create({
      schema,
      doc: parseHTML(el),
      plugins: [
        history(),
        inputRules({ rules: smartQuotes.concat(ellipsis, emDash) }),

        // Rich list bindings (must be before baseKeymap)
        richListKeymapPlugin(),

        keymap({
          "Mod-z": undo,
          "Shift-Mod-z": redo,
          "Mod-y": redo,
          "Alt-ArrowUp": joinUp,
          "Alt-ArrowDown": joinDown,
          "Mod-BracketLeft": lift,
          Escape: selectParentNode,
          "Mod-Enter": exitCode,
        }),

        // Base keymap last so our overrides win
        keymap(baseKeymap),

        dropCursor(),
        gapCursor(),

        columnResizing(),
        tableEditing(),

        placeholderPlugin(),
        imagePasteDropPlugin(),
      ],
    });

    let view;

    function buildToolbar() {
      const addSep = () => {
        const s = document.createElement("span");
        s.style.width = "10px";
        ui.toolbar.appendChild(s);
      };

      const add = (label, title, commandFactory) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "pm-btn";
        b.textContent = label;
        b.title = title;
        b.addEventListener("click", () => {
          const cmd = commandFactory();
          cmd(view.state, view.dispatch, view);
          view.focus();
          updateToolbar();
        });
        ui.toolbar.appendChild(b);
        return b;
      };

      const btnBold = add("B", "Bold", () => toggleMark(schema.marks.strong));
      const btnItalic = add("I", "Italic", () => toggleMark(schema.marks.em));
      const btnCode = add("</>", "Inline code", () => toggleMark(schema.marks.code));
      addSep();

      const btnH2 = add("H2", "Heading 2", () => setBlockType(schema.nodes.heading, { level: 2 }));
      const btnP = add("P", "Paragraph", () => setBlockType(schema.nodes.paragraph));
      addSep();

      add("• List", "Toggle bullet list", () => toggleListRich(schema.nodes.bullet_list));
      add("1. List", "Toggle ordered list", () => toggleListRich(schema.nodes.ordered_list));
      addSep();

      const btnLink = document.createElement("button");
      btnLink.type = "button";
      btnLink.className = "pm-btn";
      btnLink.textContent = "Link";
      btnLink.title = "Add/remove link";
      btnLink.addEventListener("click", () => promptLink(view));
      ui.toolbar.appendChild(btnLink);

      addSep();

      add("Table", "Insert 3×3 table", () => insertTableCmd({ rows: 3, cols: 3 }));
      add("+Row", "Add row after", () => addRowAfter);
      add("-Row", "Delete row", () => deleteRow);
      add("+Col", "Add column after", () => addColumnAfter);
      add("-Col", "Delete column", () => deleteColumn);
      add("Hdr Row", "Toggle header row", () => toggleHeaderRow);
      add("Hdr Col", "Toggle header column", () => toggleHeaderColumn);
      add("Merge", "Merge cells", () => mergeCells);
      add("Split", "Split cell", () => splitCell);
      add("Del Tbl", "Delete table", () => deleteTable);

      addSep();

      const imgBtn = document.createElement("button");
      imgBtn.type = "button";
      imgBtn.className = "pm-btn";
      imgBtn.textContent = "Image…";
      imgBtn.title = "Upload/insert an image";
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

      function updateToolbar() {
        btnBold.setAttribute("aria-pressed", markActive(view.state, schema.marks.strong) ? "true" : "false");
        btnItalic.setAttribute("aria-pressed", markActive(view.state, schema.marks.em) ? "true" : "false");
        btnCode.setAttribute("aria-pressed", markActive(view.state, schema.marks.code) ? "true" : "false");
        btnH2.setAttribute("aria-pressed", nodeActive(view.state, schema.nodes.heading, { level: 2 }) ? "true" : "false");
        btnP.setAttribute("aria-pressed", nodeActive(view.state, schema.nodes.paragraph) ? "true" : "false");
        btnLink.setAttribute("aria-pressed", markActive(view.state, schema.marks.link) ? "true" : "false");
      }

      return updateToolbar;
    }

    view = new EditorView(ui.editorHost, {
      state,
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

      async save() {
        const html = serializeToHTML(view.state.doc);
        el.innerHTML = html;
        await opts.onSave?.({ html, target: el });
        instance.destroy();
        el.style.display = "";
      },

      async cancel() {
        el.innerHTML = originalHTML;
        await opts.onCancel?.({ target: el });
        instance.destroy();
        el.style.display = "";
      },

      destroy() {
        try {
          view.destroy();
        } catch {}
        ui.root.remove();
        delete el.__pmDropInMounted;
      },
    };

    instance.updateToolbar = buildToolbar();
    instance.updateToolbar();

    el.__pmDropInMounted = instance;
    return instance;
  },
};
