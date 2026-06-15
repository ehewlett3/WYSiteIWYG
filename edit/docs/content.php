<?php
declare(strict_types=1);

/**
 * WYSiteIWYG documentation / demo content.
 *
 * This file is the canonical source for the built-in documentation. It is shown
 * read-only through the dashboard "Documentation" view, and it can also be
 * deployed as a real, editable demo site to the web root (the folder /edit/
 * lives in) via SiteGenerator::deployDemoSite().
 *
 * Returns:
 *   'menu'  => main-menu HTML (root-relative links; localized per page on render)
 *   'pages' => list of [slug, title, excerpt, blocks[]]  (blocks = page-content slots)
 *   'posts' => list of [slug, title, excerpt, hashtags, content]
 */

return [
    'menu' => '<ul class="main-menu">'
        . '<li><a href="/">Home</a></li>'
        . '<li><a href="/features/">Features</a></li>'
        . '<li><a href="/live-editing/">Editing in Place</a></li>'
        . '<li><a href="/themes-and-imports/">Themes &amp; Imports</a></li>'
        . '<li><a href="/installation/">Installation</a></li>'
        . '<li><a href="/blog/">Journal</a></li>'
        . '</ul>',

    'pages' => [
        [
            'slug' => 'index',
            'title' => 'WYSiteIWYG',
            'excerpt' => 'A drop-in editor that lets you edit real static HTML in place — no database, no rebuild step, no lock-in.',
            'blocks' => [
                '<h2>Edit the site you already ship</h2>'
                    . '<p>WYSiteIWYG drops into any static site under <code>/edit/</code>. Your pages stay as plain HTML files on disk; the editor opens them, lets you change marked regions in a live preview, and writes the file back. What you publish is exactly what you edited.</p>'
                    . '<p>There is no database and no build pipeline. Deploy the files however you already deploy static sites.</p>',
                '<h2>Why teams choose it</h2>'
                    . '<ul>'
                    . '<li><strong>Transparent output.</strong> Edits are delimited by HTML comments, so diffs stay readable and the markup stays yours.</li>'
                    . '<li><strong>In-place editing.</strong> Click a badge on the live page and a ProseMirror editor opens right where the content lives.</li>'
                    . '<li><strong>Location-agnostic.</strong> Run it at the domain root or in a subdirectory, over http or https — links resolve correctly either way.</li>'
                    . '<li><strong>Migration ready.</strong> Import existing HTML or pull in an external WordPress/static site, assets and all.</li>'
                    . '</ul>',
                '<h2>Take the tour</h2>'
                    . '<p>Start with the <a href="/features/">feature tour</a>, learn how <a href="/live-editing/">in-place editing</a> works, explore <a href="/themes-and-imports/">themes &amp; imports</a>, then check <a href="/installation/">installation</a> when you are ready to deploy.</p>',
            ],
        ],
        [
            'slug' => 'features',
            'title' => 'Feature Tour',
            'excerpt' => 'Everything WYSiteIWYG gives you for editing, theming, and migrating a static site.',
            'blocks' => [
                '<h2>Live, in-page editing</h2>'
                    . '<p>Editable blocks are marked in the HTML with comments. In the preview, each block gets a badge; clicking it mounts a transparent ProseMirror editor with headings, lists, tables, links, and image uploads. Saving writes the change straight back into the static file.</p>',
                '<h2>Select-section editing</h2>'
                    . '<p>Beyond marked blocks, the "Select section" mode lets you click any element on the page. Edits inside a page slot update that page; edits to shared chrome update the active template and rebuild every page that uses it, after a clear confirmation.</p>'
                    . '<h2>Themes and templates</h2>'
                    . '<p>Swap the look of the whole site by applying a theme. Page, blog-post, and blog-index templates are regenerated, and pages flagged to opt out are left untouched.</p>',
                '<h2>Imports and migration</h2>'
                    . '<p>Bring in unmanaged HTML files as editable pages, promote regions of an external page into a reusable template, or crawl an entire external site — HTML, feeds, and referenced assets — into local, rewritten, editable content.</p>',
            ],
        ],
        [
            'slug' => 'live-editing',
            'title' => 'Editing in Place',
            'excerpt' => 'How the marker format and the live editor keep your HTML transparent and yours.',
            'blocks' => [
                '<h2>Marked blocks</h2>'
                    . '<p>An editable region is wrapped in a pair of HTML comments naming the block:</p>'
                    . '<pre><code>&lt;!-- WYSITE:BEGIN name="page-content" type="page" label="Page Content" --&gt;'
                    . "\n" . '&lt;p&gt;Editable content.&lt;/p&gt;'
                    . "\n" . '&lt;!-- WYSITE:END name="page-content" --&gt;</code></pre>'
                    . '<p>Only the content between the markers is editable. Everything else in the file is left exactly as written.</p>',
                '<h2>The editor</h2>'
                    . '<p>The badge on each block opens a ProseMirror editor in place. It supports rich text, lists, tables, links, and image uploads, plus a raw source mode when you need precise control. Save and cancel are always one click away.</p>'
                    . '<p>The editor draws the surrounding page CSS, so what you see while editing is what the published page looks like. It also respects your existing HTML: an unedited block is written back untouched, and clean list markup round-trips without being rewritten.</p>',
                '<h2>Round-trips you can read</h2>'
                    . '<p>Because edits are scoped to marked regions and written back as ordinary HTML, version-control diffs stay small and meaningful. Nothing is hidden in a database or a proprietary format.</p>',
            ],
        ],
        [
            'slug' => 'themes-and-imports',
            'title' => 'Themes & Imports',
            'excerpt' => 'Re-skin the whole site or pull in content from elsewhere, without leaving the editor.',
            'blocks' => [
                '<h2>Applying a theme</h2>'
                    . '<p>Each theme ships page, blog-post, and blog-index templates plus a stylesheet. Previewing a theme shows your real content in the new design; applying it regenerates managed pages and publishes the theme CSS. Stylesheet and template URLs are localized automatically, so themes work in a subdirectory too.</p>',
                '<h2>Importing existing HTML</h2>'
                    . '<p>Already have static pages? Import them to add editable blocks while preserving their structure, either one at a time with advanced placement control, or in bulk.</p>',
                '<h2>External site import</h2>'
                    . '<p>Migrating from WordPress or another static host? The importer crawls pages from a start URL, saves local HTML, imports feeds and static resources, mirrors referenced assets, and rewrites source-host links to local paths — with a live progress stream and large files streamed to disk.</p>',
            ],
        ],
        [
            'slug' => 'installation',
            'title' => 'Installation',
            'excerpt' => 'Requirements, writable paths, and deploying at the root or in a subdirectory.',
            'blocks' => [
                '<h2>Requirements</h2>'
                    . '<ul>'
                    . '<li>PHP 8.1 or newer with the <code>dom</code>/<code>libxml</code> and <code>fileinfo</code> extensions and sessions.</li>'
                    . '<li><code>curl</code> is recommended for robust large-asset imports (a stream fallback exists).</li>'
                    . '<li>The PHP process needs write access to the web root and to <code>edit/storage/</code>.</li>'
                    . '</ul>',
                '<h2>Drop it in</h2>'
                    . '<p>Copy the <code>edit/</code> folder into your site, make sure PHP is enabled for it, and visit <code>edit/index.php?action=install</code> to create the first admin account. During install you can optionally deploy this documentation as a starter demo site; leave it unchecked to keep your existing site untouched.</p>',
                '<h2>Root or subdirectory</h2>'
                    . '<p>WYSiteIWYG is location-agnostic. It works served from the domain root or from any subdirectory, over http or https. Generated pages use a per-page relative base and base-relative links, and the bundled <code>.htaccess</code> derives its own base path, so clean URLs and assets resolve correctly wherever the site lives.</p>',
            ],
        ],
    ],

    'posts' => [
        [
            'slug' => 'welcome-to-wysiteiwyg',
            'title' => 'Welcome to WYSiteIWYG',
            'excerpt' => 'A quick introduction to editing static HTML in place.',
            'hashtags' => '#blog #launch',
            'content' => '<p>WYSiteIWYG is a small editor with a simple promise: keep your site as static HTML, and let you edit it in place. This journal is itself part of the demo — every post here is an ordinary HTML file with marked, editable regions.</p>'
                . '<p>Open the editor, click a badge, and change this text. When you save, the file on disk changes and nothing else does.</p>',
        ],
        [
            'slug' => 'location-agnostic-deployment',
            'title' => 'Location-Agnostic Deployment',
            'excerpt' => 'Why this demo works the same at the domain root or in a subdirectory.',
            'hashtags' => '#blog #workflow',
            'content' => '<p>This site is generated so it does not care where it lives. Each page carries a relative base and base-relative links, the published stylesheet rewrites its own asset URLs, and the <code>.htaccess</code> figures out its deployment path on its own.</p>'
                . '<p>The practical result: the same files serve correctly from <code>/</code> or from <code>/some/subdir/</code>, over http or https, with clean URLs intact.</p>',
        ],
    ],
];
