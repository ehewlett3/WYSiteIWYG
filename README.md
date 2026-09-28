# WYSiteIWYG

WYSiteIWYG is a PHP-powered static site editor that lives entirely inside the site's `/edit/` directory while editing the real HTML files in the parent web root. Pages stay plain static HTML; editable regions are marked with HTML comments.

## What it does

**Editing**
- Edits the real `.html` files in place with a bundled ProseMirror editor, either on marked blocks or on any section picked with **Select section**.
- Preserves existing markup and styling. Blocks you don't touch are saved back byte-for-byte. Every attribute (`class`, `style`, `data-*`, `width`/`srcset`, `target`/`rel`, …) survives. Elements the visual editor can't model (iframes, video/audio, forms, `<details>`, SVG, custom elements) are kept verbatim as read-only "embedded content", editable in HTML mode. Before a save would drop anything, the editor asks first.
- Selector edits splice only the edited element's bytes into the file, so doctypes, template tokens and HTML5 markup elsewhere are never rewritten.
- Unsaved-changes guard, Ctrl/Cmd+S to save, Esc to cancel, and a link picker that lists the site's pages.
- Refuses a save if someone else changed the page since you opened it.

**Pages and blog**
- Creates pages and posts (optionally as private **drafts**, optionally added to the menu). Drafts are published now or on a scheduled date. Pages can be moved (the old address redirects), deleted, or unpublished.
- Explicit page types (Home / Page / Blog post). Hashtags put posts on `/blog/` and on per-tag landing pages, paginated (`/blog/page/2/`).
- RSS feeds (`/blog/feed.xml`, `/<tag>/feed.xml`), `sitemap.xml`, a default `robots.txt`, and SEO/Open Graph metadata on every page.
- Revision history for every page (view/restore in Page details) and a full backup before every bulk operation (Manager → Backups).

**Site, navigation, themes**
- **Site settings**: site name, tagline, language, logo, favicon, footer, public URL, URL style, post permalinks. These survive theme changes.
- One shared menu, with the current page's link marked `aria-current="page"`.
- Theme preview (any page) and apply. Per-theme colour/font **customization**, an optional **Home** template, and blog templates synthesized from a theme's page template when it has none. A theme's `assets/` folder is published to `/assets/theme/<id>/`.
- Builds a theme from imported pages by tagging their menu/content regions (optionally AI-assisted with your own API key).

**Migration from WordPress**
- **Import a WordPress export** (Tools → Export → All content): posts, pages, dates, tags, authors, excerpts, drafts and permalinks, with media mirrored locally.
- Or crawl a live site. The crawl runs in resumable batches and removes source scripts by default. It mirrors assets, detects posts (date, tags, body) when importing, keeps `/feed/` working, redirects old category/tag archives to tag pages, and redirects `?p=123`-style links.
- A **Site report** lists broken links, missing assets, forms, search/comment forms and embeds, with one-click fixes.

**Security**
- First-run install requires a setup token from the server's filesystem.
- A server-side sanitizer removes scripts, event handlers and `javascript:` URLs from everything saved or imported; admins can mark specific blocks as raw HTML.
- Hardened sessions, throttled sign-in, re-authentication for account changes, a strict CSP, SSRF protection with DNS pinning on every outbound fetch, `0600` storage files, and an `.htaccess` that stops `/assets/` from ever executing PHP.

## Getting started

1. Copy `/edit/` into your site's web root (PHP 8.1+ with `ext-dom`; `ext-curl`, `ext-fileinfo`, `ext-zip` recommended).
2. Visit `/edit/`. The install page runs a **server check** and asks for a **setup token**. Open `edit/storage/install-token.local.php` on the server (SSH, SFTP or your host's file manager) and copy the token, or set the `WYSITE_INSTALL_TOKEN` environment variable instead.
3. Create the first administrator. Tick "install the demo site" only on an empty site; existing pages are never overwritten.
4. Visit **Settings** to set the site name and public URL, then **Theme** to pick a look.
5. Open a page from the dashboard and click a block badge (or **Select section**) to edit.

## URLs and servers

- New pages are written as folders (`about/index.html`), which work on any static server with no rewrite rules. Sites with flat files (`about.html`) can switch with **Manager → Convert to folder URLs**. Public addresses don't change, and the old files redirect.
- Pages are location-agnostic (relative `<base href>` and base-relative links), so the same files work at the domain root or in any subdirectory.
- On Apache, the root `.htaccess` adds clean URLs for flat files and hides `.git/`, `doc/` and handoff notes. The `edit/storage/`, `edit/tests/` and `edit/views/` folders carry their own `Require all denied` files.
- **nginx, Caddy, `php -S` and other servers ignore `.htaccess`.** Deny these paths yourself, e.g. for nginx:

  ```nginx
  location ~ ^/(\.git|doc)(/|$)                      { return 404; }
  location ~ ^/edit/(storage|tests|views)(/|$)        { return 404; }
  location ~ ^/assets/.*\.(php\d?|phtml|phar|phps)$  { return 403; }
  location ~ ^/edit/.*\.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass php; }
  location = /edit/ { index index.php; }
  location /feed/ { index index.xml; }
  ```

  **Manager → System status → Run HTTP checks** warns if `edit/storage/` is publicly readable.
- Sessions follow the editor's 8-hour idle timeout. On Debian/Ubuntu, PHP's session clean-up cron uses `php.ini`'s `session.gc_maxlifetime` (24 minutes by default); raise it if editors get signed out early.

## Marker format

Editable blocks are delimited with HTML comments:

```html
<!-- WYSITE:BEGIN name="page-content" type="page" label="Page Content" -->
<p>This section is editable.</p>
<!-- WYSITE:END name="page-content" -->
```

File metadata:

```html
<!-- WYSITE:META title="About" kind="page" excerpt="Intro text" hashtags="#blog #launch" exclude_template="1" -->
```

Posts also carry `date`, and optionally `author` and `image`. Drafts carry `status="draft"` (and `publish_at`); `admin_blocks` lists raw-HTML blocks.

### Template tokens

Scalar tokens are HTML-escaped: `{{TITLE}}`, `{{EXCERPT}}`, `{{DATE}}`, `{{DATE_HUMAN}}`, `{{AUTHOR}}`, `{{HASHTAGS}}`, `{{TAG}}`, `{{TAG_LABEL}}`, `{{BODY_CLASS}}`, `{{SITE_NAME}}`, `{{SITE_TAGLINE}}`, `{{SITE_LANG}}`.

HTML tokens: `{{MAIN_MENU}}`, `{{PAGE_CONTENT_BLOCK}}`/`{{PAGE_CONTENT_BLOCKS}}`, `{{BLOG_POST_CONTENT}}`, `{{BLOG_INDEX_CONTENT}}`, `{{BLOG_ITEMS}}`, `{{PAGINATION}}`, `{{TAG_LINKS}}`, `{{SITE_LOGO}}`, `{{FOOTER}}`, `{{HEAD_META}}` (injected before `</head>` when a theme omits it), and `{{WYSITE_PUBLIC_BRIDGE}}`.

### Themes

A theme is a folder in `edit/themes/<id>/` with `theme.json`, `site.css`, and `page.html`. `home.html`, `blog-post.html`, `blog-index.html` and an `assets/` folder are optional. `theme.json` may declare customizable CSS variables:

```json
{ "name": "My Theme", "variables": [ { "name": "site-accent", "label": "Accent colour", "type": "color", "default": "#2d624a" } ] }
```

Legacy `theme.php` manifests are still read, as plain data; they are never executed.

## Folder layout

```text
/assets/site.css                 Published theme stylesheet (+ customizations)
/assets/theme/<id>/              Published theme assets
/assets/uploads/, /assets/imported/  Uploads and mirrored media (PHP disabled by assets/.htaccess)
/<page>/index.html               Pages and posts (folder style; flat <page>.html also supported)
/blog/, /<tag>/                  Generated landing pages, feeds and pagination
/edit/index.php                  Front controller; views in /edit/views/
/edit/src/                       PHP classes
/edit/assets/                    Editor, dashboard JS/CSS, vendored ProseMirror
/edit/templates/                 Active templates (published from the applied theme)
/edit/themes/                    Theme packages
/edit/storage/                   Per-install state (git-ignored): users, settings, drafts, revisions, backups
/edit/tests/                     Test suites
```

## Tests

```sh
php edit/tests/run.php                   # PHP suite (uses throwaway site roots)
cd edit/tests/js && npm install --no-save jsdom@24 && node run-node.mjs   # editor round-trip
```

CI runs both, plus `php -l` on every file, on PHP 8.1 and 8.3.

## Runtime notes

- ProseMirror is vendored under `edit/assets/vendor/` (one deduplicated module graph), so the editor works offline. Regenerate it with `edit/assets/vendor/fetch-prosemirror.py`.
- The public "Signed in — Edit" banner only loads for browsers that have signed in (a non-secret `wysite_editor` hint cookie). Ordinary visitors get no cookie and make no request to `/edit/`. It can be turned off in Settings.
- Imports have a per-run storage budget (Settings) and stop when the disk is nearly full.
