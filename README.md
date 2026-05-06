# PlainCMS

PlainCMS is a minimal SSR CMS for blogs, pages, landing pages, and small content sites. It runs on PHP and PostgreSQL, uses a modular-monolith structure, and does not require Composer or Node/npm at runtime.

## Highlights

- PHP 8.4-oriented codebase with PostgreSQL via PDO
- Server-rendered public site and admin panel
- No runtime framework dependency
- File cache by default
- Safe theme templates with escaped output by default
- Built-in pages, blog, media, menus, forms, comments, likes, analytics, SEO, and backups

## Project Layout

```txt
app/
config/
content/
database/
public/
storage/
IDEA.md
PLAN.md
README.md
```

## Requirements

- PHP 8.4 recommended
- Extensions:
  - `pdo`
  - `pdo_pgsql`
  - `session`
  - `json`
  - `fileinfo`
  - `mbstring`
  - `openssl`
- PostgreSQL 16 or compatible
- Caddy or another web server pointed at `public/`
- Writable directories:
  - `content/cache`
  - `content/uploads`
  - `content/backups`
  - `storage/logs`
  - `config`

## Caddy Setup

Serve the app from `public/`.

```caddyfile
plaincms.local {
    tls internal

    root * /var/www/plaincms/public

    php_fastcgi php:9000

    file_server
}
```

Without local TLS:

```caddyfile
plaincms.local {
    root * /var/www/plaincms/public

    php_fastcgi php:9000

    file_server
}
```

## Config

The installer creates `config/config.php`. Before installation, keep only `config/config.php.example` in place.

Important config areas after install:

- `app.url`: canonical base URL for sitemap and SEO tags
- `database.*`: PostgreSQL connection settings
- `cache.driver`: `file` or `null`
- `cache.path`: file cache path
- `uploads.path`: disk path for uploaded files
- `uploads.url`: public URL prefix for uploaded files

## Installation

1. Point Caddy at the `public/` directory.
2. Create an empty PostgreSQL database.
3. Open `/install`.
4. Run environment checks.
5. Enter database credentials.
6. Create the first admin account.
7. Enter site details.
8. Finish installation.

The installer will:

- create database tables from `database/migrations/`
- create the first admin user
- seed default pages, a contact form, and the main navigation
- write `config/config.php`
- create `storage/installed.lock`

After a successful install, open `/admin/login`.

## Login

Sign in at `/admin/login` with the admin email and password created during installation.

Included auth flows:

- login
- logout
- password reset request
- password reset confirmation
- login rate limiting

## First Page

To create the first page:

1. Sign in to `/admin/login`.
2. Open `Pages`.
3. Choose `Create page`.
4. Set the title and body.
5. Choose `Markdown` or `HTML` mode.
6. Save as draft or publish immediately.
7. Use the preview link to verify the public result.

To create a nested section such as `/<parent>/<child>`:

1. Create the parent page, for example `Blog` at `/blog`.
2. Create another page.
3. Set `Parent page` to `Blog`.
4. Save or publish and the child will render under `/blog/{slug}`.

## Media And Image Insertion

Upload images from `Admin -> Media`.

Each uploaded image provides two ready-to-paste snippets:

- Markdown: `![Alt text](/uploads/...)`
- HTML: `<img src="/uploads/..." alt="...">`

Paste either snippet into the page `Body` field.

For galleries, use the shortcode form instead:

```txt
[gallery ids="1,2,3"]
```

## Themes

Themes live in `content/themes/{theme_key}`.

The default theme includes:

```txt
content/themes/default/
  theme.json
  layout.html
  home.html
  page.html
  archive.html
  contact.html
  partials/
    header.html
    footer.html
  assets/
    style.css
    app.js
```

Switch the active theme in `Admin -> Settings`.

## Template Variables

Templates are HTML files interpreted by the internal template engine.

Common variables:

- `{{ site.title }}`
- `{{ site.description }}`
- `{{ site.theme_asset_base }}`
- `{{ page.title }}`
- `{{ page.path }}`
- `{{ page.content }}`
- `{{ menu.main }}`
- `{{ comments }}`
- `{{ likes }}`
- `{{ meta.title }}`
- `{{ meta.description }}`
- `{{ meta.robots }}`
- `{{ meta.canonical }}`

Safe HTML slots already rendered by the core:

- `page.content`
- `menu.main`
- `comments`
- `likes`
- `flash.message`

Everything else is escaped by default.

Supported include syntax:

```txt
{{ include:partials/header.html }}
```

Includes are restricted to the active theme directory.

## Shortcodes

Supported shortcodes are whitelist-only:

```txt
[contact_form id="contact"]
[button text="Contact us" url="/contact"]
[latest_pages count="5"]
[child_pages]
[gallery ids="1,2,3"]
```

Behavior:

- `contact_form`: renders a saved form by `forms.key`
- `button`: renders a simple call-to-action link
- `latest_pages`: renders recent published pages
- `child_pages`: renders published child pages for the current page or a supplied parent
- `gallery`: renders selected uploaded media items

Shortcodes do not execute PHP.

## Public Features

- Pages render through the active theme
- Comments support moderation
- Likes work with lightweight JavaScript
- SEO meta tags are rendered in the layout
- `sitemap.xml` and `robots.txt` are public endpoints
- Page views feed analytics reports

## Maintenance

From the admin panel you can:

- clear cache
- manage menus
- moderate comments
- review analytics
- create downloadable backups

Backups are written under `content/backups`.

## Runtime Notes

- Runtime does not require `composer install`
- Runtime does not require `npm install`
- Public entrypoint is `public/index.php`

## Manual Regression Checklist

Use this list for a final pass in a real PHP runtime:

- Installer opens at `/install`
- Installer writes `config/config.php` and `storage/installed.lock`
- `/admin/login` works after install
- Page CRUD works end to end
- Post CRUD works end to end
- Image upload works
- Copied image snippet renders in page/post content
- Menus render in the public header
- Forms submit and save submissions
- Comments submit and moderate correctly
- Likes toggle correctly
- Analytics dashboard updates after public page views
- `sitemap.xml` and `robots.txt` respond correctly
- Cache clears from settings
- Backup creation and download work

## Current Verification Status

The codebase includes the features above, but this workspace does not include a local PHP runtime or a project Docker/Compose definition, so final browser-level verification still needs to be performed in the target environment.
