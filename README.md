# WYSiteIWYG

WYSiteIWYG is a PHP-powered static site editor that lives entirely inside the site's `/edit/` directory while editing the real HTML files in the parent web root.

## What it does

- Uses the included enhanced ProseMirror drop-in editor for marked content blocks.
- Reads and writes the actual `.html` files from the site root.
- Edits the shared menu directly as a nested list through the same ProseMirror drop-in, then syncs it across pages and templates.
- Supports multiple editable targets on a page by honoring every `WYSITE:BEGIN/END` block pair it finds.
- Creates new pages and root-level blog posts from included HTML templates.
- Rebuilds `/blog/` and any other hashtag landing pages from metadata stored in page comment markers.
- Supports theme preview and theme application, rewriting the site and active templates to match the selected theme.
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
<!-- WYSITE:META title="About" kind="page" excerpt="Intro text" hashtags="#blog #launch" -->
```

## Folder layout

```text
/assets/site.css            Shared front-end styling
/templates/*.html           Sample page, blog, and blog-post templates
/*.html                     Static pages and root-level post files
/blog/index.html            Default #blog landing page
/<tag>/index.html           Generated landing pages for other hashtags
/edit/index.php             PHP front controller and dashboard
/edit/assets/*.js           Editor assets and ProseMirror bundle loader
/edit/themes/*              Theme packages for preview/apply
/edit/storage/users.local.php  Live password-hash user store
/edit/storage/users.example.php Sample empty user store for the repo
```

## Runtime notes

- The active editor runtime currently imports ProseMirror modules from `esm.sh`. A previous attempt to switch to fully local bundled copies caused module-graph issues and was reverted.
- This project was built to be dropped into a web root with PHP enabled for the `/edit/` directory.
- Posts are discovered by hashtags in the `WYSITE:META` comment. The create-post flow defaults to `#blog`, and the dashboard's page-details screen lets you add or remove hashtags on existing pages.
- The included public pages use absolute `/...` links, which is ideal when the site is served from a domain root. If your site lives in a subdirectory, update those links in the templates to match your deployment base path.

## Getting started

1. Serve the project with PHP enabled for `/edit/`.
2. Visit `/edit/index.php?action=install`.
3. Create the first administrator account.
4. Open a page preview from the dashboard and click the edit badges on marked blocks.
