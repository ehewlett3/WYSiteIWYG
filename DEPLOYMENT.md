# Deploying WYSiteIWYG

WYSiteIWYG is one folder, `/edit/`. Put it in your site's web root, open `/edit/` in a browser, and follow the installer. You don't need a database, a build step, or Composer.

## 1. Check the server

- PHP 8.2 or newer with `ext-dom`. PHP 8.4 is recommended: its HTML5 parser lets the sanitizer keep untouched markup byte-for-byte.
- Recommended: `ext-curl`, `ext-openssl`, `ext-fileinfo`, `ext-zip`, and `ext-mbstring`.
- The web server's PHP user must be able to write to the web root, `/assets/`, and `/edit/storage/`, `/edit/templates/`, and `/edit/themes/`.

The installer runs these checks for you and tells you what to fix.

## 2. Copy `/edit/` into the web root

Pick whichever method suits your host:

```sh
# From a download: unzip the repository archive and upload its edit/ folder
# to the web root (SFTP, or your host's file manager).

# From Git, on the server:
git clone --depth 1 https://github.com/ehewlett3/WYSiteIWYG.git /tmp/wysite
cp -r /tmp/wysite/edit /path/to/webroot/edit
cp /tmp/wysite/.htaccess /path/to/webroot/   # Apache only, optional (see step 4)
rm -rf /tmp/wysite
```

The result should look like this:

```text
webroot/
  index.html        ← your existing pages (if any) stay where they are
  edit/             ← WYSiteIWYG
```

The site can live at the domain root or in a subdirectory; pages use relative links either way.

## 3. Fix permissions (if the installer asks)

On a typical Linux host where PHP runs as `www-data`:

```sh
cd /path/to/webroot
sudo chown -R www-data: . && sudo find . -type d -exec chmod 775 {} +
```

On shared hosting, PHP usually runs as your own account, so you rarely need this.

## 4. Web server rules

- **Apache**: `edit/` ships its own `.htaccess` files that block `storage/`, `tests/`, and `views/`. The repository's root `.htaccess` is optional; it adds clean URLs for flat `page.html` files and hides `.git/`. It needs `AllowOverride All`.
- **nginx, Caddy, or others**: these ignore `.htaccess`, so you must deny those paths yourself. The README's [URLs and servers](README.md#urls-and-servers) section has an nginx example. This step is required: `edit/storage/` holds account data.

Afterwards, **Manager → System status → Run HTTP checks** confirms that `edit/storage/` is not publicly readable.

## 5. Install

1. Visit `https://your-site/edit/`.
2. The installer asks for a **setup token**. Open `edit/storage/install-token.local.php` on the server and copy it. Alternatively, set the `WYSITE_INSTALL_TOKEN` environment variable before installing.
3. Create the administrator account.
4. **New site**: tick "install the demo site" to start with sample pages.
   **Existing site**: leave it unticked. Your files are never overwritten on install.

## 6. Get started

**New site**: go to **Settings** (site name, public URL, logo), then **Theme** (pick a look), then edit pages from the dashboard.

**Existing site**: the Manager lists your HTML files under "HTML files not yet added". Importing a file wraps its content in editable blocks and renders it with the *current theme*. To keep your own design:

1. **Manager → Template manager**: create a theme, for example "My Site".
2. On one of your pages, choose **Use as template** and click the menu and the content area. Save.
3. On the **Theme** page, apply "My Site".
4. Back in the Manager, **Import All**.

Moving from WordPress? Use **Manager → Import a WordPress export** instead.

Take a snapshot first (**Manager → Site snapshots**). Every bulk operation also makes its own backup.

## Optional: scheduled publishing and long imports

Drafts scheduled for a date are published when someone opens the dashboard. To publish them on time, add a cron job:

```sh
17 * * * * php /path/to/webroot/edit/cli.php publish-due
```

Large sites import more reliably from the command line than through the browser, with no request time limits:

```sh
php /path/to/webroot/edit/cli.php import https://old-site.example/ --max-pages=2000
```

Run `php edit/cli.php` for every command. Crawled pages are staged; review them in **Manager → Import map**.

## Optional: run the editor on its own origin

Public pages and `/edit/` normally share an origin. For sites with several editors, or that keep third-party scripts, serve the same `edit/` folder from its own host (for example `https://edit.example.com/edit/`) and set:

```sh
WYSITE_EDITOR_ORIGIN=https://edit.example.com
WYSITE_COOKIE_DOMAIN=.example.com   # lets public pages see the "signed in" hint
```

Set **Settings → Public URL** to the public site's address. The editor's session cookie then never reaches the public site; public pages load the live-edit banner from the editor origin and ask it for the session status with CORS.

## Optional: AI assistance

On the **AI** page, add an Anthropic or OpenAI-compatible API key. Then click **Load models → Suggest models → Save**. This turns on automatic menu and content detection, and **Manager → Design a theme with AI**.

Theme design can take a few minutes, so raise these limits if they are lower:

- PHP's `max_execution_time` (the app raises it itself where allowed).
- Your web server's or proxy's request timeout (Apache `Timeout`, nginx `fastcgi_read_timeout`) to about 600 seconds.
- `post_max_size` to 12M or more, if you'll upload screenshots.

## Updating

Replace everything in `/edit/` **except `edit/storage/`**, which holds your accounts, settings, and backups. Themes you created live in `edit/themes/`, so keep those folders too.

```sh
rsync -a --exclude storage/ --exclude 'themes/*/' /tmp/wysite/edit/ /path/to/webroot/edit/
rsync -a /tmp/wysite/edit/themes/ /path/to/webroot/edit/themes/   # refresh built-in themes, keep yours
```
