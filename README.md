# WYSiteIWYG

WYSiteIWYG is a PHP-powered static site editor that lives entirely inside the site's `/edit/` directory while editing the real HTML files in the parent web root.

## What it does

- Uses the included enhanced ProseMirror drop-in editor for marked content blocks.
- Adds a selector editing mode in the page preview, so editors can click a page section and open the same ProseMirror editor even when they did not start from a block badge.
- Reads and writes the actual `.html` files from the site root.
- Keeps the editor runtime, bundled ProseMirror assets, themes, active templates, auth, and storage inside `/edit/`.
- Edits the shared menu directly as a nested list through the same ProseMirror drop-in, then syncs it across pages and templates.
- Supports multiple editable targets on a page by honoring every `WYSITE:BEGIN/END` block pair it finds.
- Creates new pages and root-level blog posts from included HTML templates.
- Rebuilds `/blog/` and any other hashtag landing pages from metadata stored in page comment markers.
- Supports theme preview and theme application, rewriting the site and active templates to match the selected theme.
- Imports an external page as a template source, then lets an admin select menu/content/archive regions to promote it into `/edit/templates/`.
- Crawls source-site HTML pages from an external site into local static files, saves feeds/static resources, rewrites source-domain page links, and mirrors referenced assets and feed media into `/assets/imported/`.
- Allows individual pages to opt out of template rebuilds and theme-apply rewrites through page details.
- Keeps authentication self-contained with one-way password hashes stored in `edit/storage/users.local.php`.

## Marker format

Editable blocks are delimited with HTML comments:

```html
<article class="content-block">
  <div class="content-stack">
    <!-- WYSITE:BEGIN name="page-content" type="page" label="Page Content" -->
    <p>This section is editable.</p>
    <!-- WYSITE:END name="page-content" -->
  </div>
</article>
```

Optional file metadata is stored with:

```html
<!-- WYSITE:META title="About" kind="page" excerpt="Intro text" hashtags="#blog #launch" exclude_template="1" -->
```

## Folder layout

```text
/assets/site.css            Shared front-end styling
/*.html                     Static pages and root-level post files
/blog/index.html            Default #blog landing page
/<tag>/index.html           Generated landing pages for other hashtags
/edit/index.php             PHP front controller and dashboard
/edit/assets/*.js           Editor assets and ProseMirror bundle loader
/edit/templates/*.html      Active page, blog, and blog-post scaffolds
/edit/themes/*              Theme packages for preview/apply
/edit/storage/users.local.php  Live password-hash user store
/edit/storage/users.example.php Sample empty user store for the repo
```

## Runtime notes

- The active editor runtime currently imports ProseMirror modules from `esm.sh`. A previous attempt to switch to fully local bundled copies caused module-graph issues and was reverted.
- This project was built to be dropped into a web root with PHP enabled for the `/edit/` directory.
- Posts are discovered by hashtags in the `WYSITE:META` comment. The create-post flow defaults to `#blog`, and the dashboard's page-details screen lets you add or remove hashtags on existing pages.
- The included public pages use absolute `/...` links, which is ideal when the site is served from a domain root. If your site lives in a subdirectory, update those links in the templates to match your deployment base path.
- The external site importer saves source-site HTML pages as a first migration pass. Page links from the imported host are rewritten to local paths, RSS/XML/JSON-style static resources are imported, media URLs referenced by those resources are mirrored, and WordPress-specific API/admin endpoints are reported as non-essential skips instead of failures. Source-host `/wp-content/` URLs are treated as essential assets when they are linked from imported HTML or feeds, including feed attributes, text nodes, and CDATA. The maximum-pages setting limits HTML pages; discovered static resources are still drained from the queue.
- Large imports can take several minutes because assets are mirrored as the crawl discovers them. The dashboard streams progress while the crawl runs, including throttled progress updates for large individual assets. The importer extends PHP's execution budget, streams large media directly to disk, fixes imported asset file permissions for public serving, and caps individual HTML/CSS-style assets to avoid exhausting memory. Any skipped asset URLs can be pasted into the dashboard's Backfill specific assets tool for a follow-up pass.

## Getting started

1. Serve the project with PHP enabled for `/edit/`.
2. Visit `/edit/index.php?action=install`.
3. Create the first administrator account.
4. Open a page preview from the dashboard.
5. Click a block badge, or use **Select section** in the preview toolbar to edit a specific section directly.
