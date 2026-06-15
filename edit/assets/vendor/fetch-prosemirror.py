#!/usr/bin/env python3
"""Recursively vendor the ProseMirror esm.sh module graph into local .mjs files.

Dedupes one copy per package (keyed by package name) so all packages share a
single prosemirror-model / -transform / -state etc. -> no cross-instance bugs.
Rewrites every import specifier to a local relative ./<name>.mjs path so the
result is fully self-contained / offline.
"""
import os, re, sys, json, urllib.request, urllib.parse

BASE = "https://esm.sh"
OUT = os.path.dirname(os.path.abspath(__file__))

ENTRY_PKGS = [
    "prosemirror-model", "prosemirror-state", "prosemirror-view",
    "prosemirror-schema-basic", "prosemirror-schema-list",
    "prosemirror-commands", "prosemirror-history", "prosemirror-keymap",
    "prosemirror-inputrules", "prosemirror-tables",
    "prosemirror-dropcursor", "prosemirror-gapcursor",
]

UA = {"User-Agent": "Mozilla/5.0 (vendor-fetch)"}

def fetch(url):
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8"), r.geturl()

# specifier regex: matches  from"..."  and  import"..."  (esm.sh is minified, no spaces)
SPEC_RE = re.compile(r'(\bfrom|\bimport)\s*("([^"]+)"|\'([^\']+)\')')

def pkg_name_from_abs(path):
    # path like /prosemirror-model@^1.0.0?target=es2022  or /@scope/name@1.0.0/...
    p = path.lstrip("/")
    if p.startswith("@"):
        # scoped: @scope/name@ver
        m = re.match(r'(@[^/]+/[^@?/]+)', p)
        return m.group(1)
    m = re.match(r'([^@?/]+)', p)
    return m.group(1)

# queue items: ("pkg", name) for absolute packages, ("rel", abs_url) for chunks
seen_pkgs = {}          # pkg name -> local filename
seen_chunks = {}        # abs chunk url -> local filename
manifest = {}

def local_for_pkg(name):
    # .js (not .mjs) so the files get a JavaScript MIME type on any Apache /
    # shared host out of the box -- .mjs is not reliably mapped and ES modules
    # are rejected when served with the wrong content-type.
    return name.replace("/", "__") + ".js"

def resolve_impl_url(pkg):
    """Fetch the esm.sh stub for a package and return the impl .mjs absolute URL."""
    stub, final = fetch(f"{BASE}/{pkg}")
    # find  export * from "<impl>.mjs"  (impl path)
    m = re.search(r'from\s*"([^"]+\.mjs)"', stub)
    if not m:
        raise RuntimeError(f"no impl url in stub for {pkg}:\n{stub[:200]}")
    impl = m.group(1)
    if impl.startswith("/"):
        impl = BASE + impl
    return impl, stub

def rewrite(content, base_url, enqueue):
    """Rewrite specifiers in `content`; enqueue(kind, key) for each dep found."""
    def repl(m):
        spec = m.group(3) or m.group(4)
        kw = m.group(1)
        if spec.startswith("http") or spec.startswith("/"):
            name = pkg_name_from_abs(spec if spec.startswith("/") else "/" + spec.split("//",1)[1].split("/",1)[1])
            enqueue("pkg", name)
            return f'{kw}"./{local_for_pkg(name)}"'
        elif spec.startswith("./") or spec.startswith("../"):
            abs_url = urllib.parse.urljoin(base_url, spec)
            enqueue("rel", abs_url)
            return f'{kw}"./{seen_chunks.setdefault(abs_url, chunk_name(abs_url))}"'
        return m.group(0)
    return SPEC_RE.sub(repl, content)

def chunk_name(abs_url):
    base = abs_url.split("?")[0].rsplit("/", 1)[-1]
    base = re.sub(r'\.mjs$', '.js', base)
    name = "chunk-" + base if not base.startswith("chunk") else base
    return name if name.endswith(".js") else name + ".js"

queue = [("pkg", p) for p in ENTRY_PKGS]
processed = set()

while queue:
    kind, key = queue.pop(0)
    tag = (kind, key)
    if tag in processed:
        continue
    processed.add(tag)
    pending = []
    enqueue = lambda k, v: pending.append((k, v))
    try:
        if kind == "pkg":
            if key in seen_pkgs:
                continue
            impl_url, stub = resolve_impl_url(key)
            content, final = fetch(impl_url)
            local = local_for_pkg(key)
            seen_pkgs[key] = local
            base_url = impl_url
        else:  # rel chunk
            content, final = fetch(key)
            local = seen_chunks.setdefault(key, chunk_name(key))
            base_url = key
        new_content = rewrite(content, base_url, enqueue)
        with open(os.path.join(OUT, local), "w") as f:
            f.write(new_content)
        manifest[local] = {"kind": kind, "key": key}
        print(f"saved {local}  ({len(new_content)} bytes)  deps+={len(pending)}")
        for d in pending:
            if d not in processed:
                queue.append(d)
    except Exception as e:
        print(f"ERROR {kind} {key}: {e}", file=sys.stderr)
        sys.exit(1)

with open(os.path.join(OUT, "manifest.json"), "w") as f:
    json.dump(manifest, f, indent=2)
print(f"\nDONE: {len(seen_pkgs)} packages, {len(seen_chunks)} chunks")
print("packages:", ", ".join(sorted(seen_pkgs)))
