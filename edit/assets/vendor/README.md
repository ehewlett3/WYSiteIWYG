# Vendored ProseMirror

These `*.mjs` files are a **single, deduplicated** ProseMirror module graph used by
the editor offline (no `esm.sh` / CDN dependency at runtime). They are imported by
[`../pm-bundle.js`](../pm-bundle.js).

## Why deduplicated matters

ProseMirror packages share singletons (one `prosemirror-model`, one
`prosemirror-transform`, one `prosemirror-state`, …). If two packages each carry
their **own** copy of those deps, schema/node/state instance checks fail and the
editor breaks at runtime (this was the failure mode of the earlier per-package
"bundle-deps" vendor files that lived here).

This graph has exactly one file per package, and every cross-package import is
rewritten to a local `./<package>.mjs` path, so all packages share one copy each.

Do **not** drop in per-package self-contained bundles to replace these.

## Regenerating (requires network)

```bash
python3 fetch-prosemirror.py
```

`fetch-prosemirror.py` pulls the module graph from `esm.sh`, deduplicates one copy
per package (keyed by package name), and rewrites every import specifier to a local
`./<package>.mjs` path. `manifest.json` records what was fetched.

After regenerating, sanity-check that nothing external leaked in:

```bash
# should print nothing (all imports must be local ./*.mjs)
grep -onE '(from|import)\s*"[^"]+"' *.mjs | grep -vE '"\./[^"]+\.mjs"'
```

Then exercise the editor (mount, lists, tables, source mode, image upload) before
shipping — there is no automated test for this yet.
