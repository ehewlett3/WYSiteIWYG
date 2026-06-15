// ProseMirror module shim.
//
// Imports the locally vendored ProseMirror module graph from ./vendor/ so the
// editor works fully offline with no third-party CDN dependency (drop-in goal).
//
// The vendored files in ./vendor/*.js are a single, DEDUPLICATED module graph:
// there is exactly one copy of each package (one prosemirror-model, one
// prosemirror-transform, one prosemirror-state, etc.), so cross-package instance
// checks work. Do NOT replace them with per-package "bundle-deps" builds that
// each inline their own copy of the shared deps -- that reintroduces duplicate
// model/state classes and breaks the editor at runtime.
//
// To regenerate (requires network): re-run the fetch described in
// ./vendor/README.md, which pulls the graph from esm.sh and rewrites every
// import to a local ./<package>.js path.
import * as model from "./vendor/prosemirror-model.js";
import * as state from "./vendor/prosemirror-state.js";
import * as view from "./vendor/prosemirror-view.js";
import * as basic from "./vendor/prosemirror-schema-basic.js";
import * as list from "./vendor/prosemirror-schema-list.js";
import * as commands from "./vendor/prosemirror-commands.js";
import * as history from "./vendor/prosemirror-history.js";
import * as keymap from "./vendor/prosemirror-keymap.js";
import * as inputrules from "./vendor/prosemirror-inputrules.js";
import * as tables from "./vendor/prosemirror-tables.js";
import * as dropcursor from "./vendor/prosemirror-dropcursor.js";
import * as gapcursor from "./vendor/prosemirror-gapcursor.js";

export {
  model,
  state,
  view,
  basic,
  list,
  commands,
  history,
  keymap,
  inputrules,
  tables,
  dropcursor,
  gapcursor,
};
