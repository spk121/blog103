# Blog admin

The content-creation half of a single-author blog: PHP, SQLite, no framework,
no build step, no dependencies to install.

It gives you four screens behind one login — a list of entries, an editor, a
media library, and a publish button that renders everything into a static
[gopher](#the-gopher-site) site.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite`, `fileinfo`, `session` and `json`
- `mbstring` is used when present but is not required
- Apache with `.htaccess` enabled, or the nginx equivalent (see [Locking down
  the directories](#locking-down-the-directories))

## Install

1. Copy the files into your web root.
2. Create your account from the command line:

   ```
   php setup.php
   ```

   It prompts for a username and password, hashes the password with
   `password_hash()`, and writes `data/author.auth` with mode 0600. It also
   creates the database and, along the way, creates `data/` and `uploads/`
   (owned by whichever user ran the command).

3. Create `gopher/` and make it, `data/`, and `uploads/` writable by the web
   server user. Publishing creates release directories and swaps the `current`
   symlink inside `gopher/`:

   ```
   mkdir -p gopher/releases
   chown -R www-data data uploads gopher     # or whatever user PHP runs as
   chmod 755 data uploads gopher gopher/releases
   ```

4. Open `login.php`.

There is no registration page and no user table, by design. The only way to
create or change the account is to run `setup.php` on the server.

Once step 3 has handed `data/` to the web server, run `setup.php` as that
user from then on, for example `sudo -u www-data php setup.php`. The
credentials file is readable by its owner only, so a copy written by anyone
else would lock the web server out. `setup.php` checks this and refuses to run
as a user who does not own `data/`, `author.auth` or `blog.sqlite`.

## Docker

Build and run the app with its bundled Apache HTTP server and gopher server:

```bash
docker build -t blog103 .
docker volume create blog103-data
docker volume create blog103-uploads
docker volume create blog103-gopher
docker run -d --name blog103 -p 80:80 -p 70:70 \
    -v blog103-data:/var/www/html/data \
    -v blog103-uploads:/var/www/html/uploads \
    -v blog103-gopher:/var/www/html/gopher \
    blog103
```

Named volumes keep the credentials file, SQLite database, uploaded media and
rendered gopher site outside the container's writable layer, so stopping or
removing the container (`docker rm`) never deletes them. Skipping the `-v`
flags — or using `--rm` without volumes — means all of that state is lost the
moment the container is removed.

The SQLite database is `blog.sqlite` in the `blog103-data` volume. You can
stop, rebuild, remove, and recreate the container without losing it, provided
the replacement `docker run` command mounts the same named volumes shown
above. Do not remove `blog103-data` with `docker volume rm`, or prune unused
named volumes with `docker volume prune --all`, unless you intend to delete the
database. Keep the other two volumes as well to preserve the login credentials,
uploads, and rendered gopher site.

Back up the live SQLite database with SQLite's backup command, which includes
uncheckpointed WAL changes. Store the resulting file somewhere protected:

```bash
docker exec blog103 sqlite3 /var/www/html/data/blog.sqlite \
  ".backup '/var/www/html/data/blog.sqlite.backup'"
docker cp blog103:/var/www/html/data/blog.sqlite.backup ./blog.sqlite.backup
docker exec blog103 rm /var/www/html/data/blog.sqlite.backup
```

Publishing never renames `/var/www/html/gopher` itself — each publish writes
into a fresh `releases/<token>` directory underneath it and atomically swaps
a `current` symlink onto it — so mounting a volume there does not break the
publish button; gophernicus is configured to serve `gopher/current`.

The HTTP admin lives at `http://localhost/login.php`, and the rendered gopher
site is served on `gopher://localhost/` (port 70). To create the initial admin
account inside the container, run `setup.php` as the web server's user, not
as root:

```bash
docker exec -it -u www-data blog103 php /var/www/html/setup.php
```

Run as root, it would write a credentials file and database that Apache
cannot read, so `setup.php` refuses and tells you which user to use.

The image turns `display_errors` off and logs PHP errors instead, so read them
with `docker logs blog103`.

## Files

```
config.php      paths, limits, upload whitelist, session hardening
helpers.php     escaping, CSRF, flash messages, UTC/local dates, URL cleaning
db.php          connection, schema, every query
auth.php        credentials file, login throttle, session guard
layout.php      shared page chrome
setup.php       CLI account creation (refuses to run over HTTP)
paginate.php    lays out auto-wrapped entry text as numbered gopher pages

gopher.php      the static gopher renderer
login.php       sign in
logout.php      sign out
index.php       entry list: create, edit, show/hide, delete
entry.php       the editor
media.php       upload, view, delete media
publish.php     the publish button and its report

admin.css
data/           blog.sqlite, author.auth, login-throttle.json  (deny all)
uploads/        media files                                    (no scripts)
gopher/         the rendered gopher site: releases/<token>/ and a current symlink
```

`data/` and `uploads/` are created on first run, each with an `.htaccess`
guard. Every request checks that the guard is in place. If it cannot be
written, the request fails with an error rather than leaving the directory
unprotected. Guards the app wrote itself start with a `# Written by blog admin`
line and are kept up to date. Delete that line if you edit the file and want
your version left alone.

## Settings

All in `config.php`:

| Constant | Default | Notes |
| --- | --- | --- |
| `APP_NAME` | `Blog admin` | Shown in the top bar and page titles |
| `APP_TIMEZONE` | `America/Los_Angeles` | **Change this.** Times are stored UTC and shown in this zone |
| `MAX_MEDIA_PER_ENTRY` | 4 | Also enforced by a CHECK constraint, so changing it needs a migration |
| `MAX_LINKS_PER_ENTRY` | 4 | Same |
| `MAX_UPLOAD_BYTES` | 32 MB | Must be under PHP's `upload_max_filesize` and `post_max_size` |
| `MAX_TITLE_CHARS` | 200 | Checked on the server as well as in the form |
| `MAX_BODY_CHARS` | 200,000 | Same |
| `MAX_LINK_LABEL_CHARS` | 120 | Same |
| `MAX_LINK_URL_BYTES` | 2,000 | Same |
| `SESSION_IDLE_SECONDS` | 8 hours | Idle timeout |
| `LOGIN_MAX_FAILURES` | 8 | Then a 15-minute lockout |
| `ALLOWED_UPLOAD_TYPES` | jpeg, png, gif, webp, avif, mp3, ogg, wav, flac, m4a, mp4, webm, ogv, mov | Keyed by the MIME type `fileinfo` reports |

Gopher output, same file:

| Constant | Default | Notes |
| --- | --- | --- |
| `GOPHER_DIR` | `<blog>/gopher` | Each publish writes `releases/<token>/` and repoints the `current` symlink. Serve `current` |
| `GOPHER_HOST` | `localhost` | **Change this.** Written into every menu line |
| `GOPHER_PORT` | 70 | Same |
| `GOPHER_SELECTOR_BASE` | `''` | Set to `/blog` if the site is not at your gopher root |
| `GOPHER_TITLE` | `A gopher blog` | Heading at the top of every menu |
| `GOPHER_ENTRIES_PER_PAGE` | 30 | Past this, menus gain next/previous links |
| `GOPHER_LINK_PREVIEW_CHARS` | 40 | Characters of the text shown in its menu line |
| `GOPHER_DISPLAY_WIDTH` | 70 | Menu display strings truncated to this |
| `GOPHER_DATE_FORMAT` | `Y-m-d H:i` | Date shown against each entry |
| `GOPHER_TEXT_CRLF` | `true` | Line endings in generated text files |

## Data model

```
entries
  id           INTEGER PRIMARY KEY AUTOINCREMENT
  title        TEXT      nullable
  body         TEXT      nullable
  body_format  TEXT      NOT NULL, CHECK IN ('wrap', 'pre')
  visible      INTEGER   NOT NULL, CHECK IN (0, 1)     0 = draft, 1 = public
  created_at   TEXT      NOT NULL   UTC 'YYYY-MM-DD HH:MM:SS'
  updated_at   TEXT      NOT NULL   UTC, rewritten on every save

media
  id, filename (unique, generated), original_name, mime,
  kind CHECK IN ('image','audio','video'), bytes, uploaded_at

entry_media
  entry_id -> entries(id) ON DELETE CASCADE
  media_id -> media(id)   ON DELETE CASCADE
  position INTEGER CHECK (position BETWEEN 0 AND 3)
  PRIMARY KEY (entry_id, position)

entry_links
  entry_id -> entries(id) ON DELETE CASCADE
  position INTEGER CHECK (position BETWEEN 0 AND 3)
  url TEXT NOT NULL, label TEXT nullable
  PRIMARY KEY (entry_id, position)
```

Two things worth knowing about this shape:

**The four-slot limit is a database constraint, not just a form check.** The
primary key on `(entry_id, position)` plus `CHECK (position BETWEEN 0 AND 3)`
means nothing can write a fifth attachment, whatever calls the database.
`position` doubles as display order.

**Deleting a media file detaches it from entries automatically** through
`ON DELETE CASCADE`, and the entry survives. Deleting an entry does *not*
delete its files — they stay in the library for reuse. `PRAGMA foreign_keys`
is enabled per connection in `db()`; without that line SQLite ignores the
cascades entirely.

## Entry text

`body_format` records how the text should be rendered:

- `wrap` — plain text, wrapped by the reader's browser, blank lines separate
  paragraphs. Leading and trailing whitespace on the whole field is trimmed.
- `pre` — plain text, every space, tab and line break preserved. Nothing is
  trimmed.

In both modes CRLF and lone CR become LF, and other control characters are
removed. The title and link text are single lines: tabs and line breaks in
them become spaces. Text that is not valid UTF-8 is refused with an error
rather than saved.

The editor's textarea mirrors the choice: serif and soft-wrapped for `wrap`,
monospace with horizontal scrolling for `pre`, so you can see what the reader
gets while you type.

## Security notes

- Passwords go through `password_hash()` / `password_verify()`, and are
  rehashed on sign-in if PHP's default algorithm has moved on.
- Every state-changing action is a POST carrying a per-session CSRF token.
- Every query is a prepared statement.
- Upload types come from `finfo` reading the file's own bytes, never the
  browser's `Content-Type`. Stored filenames are generated
  (`YYYYMMDD-<random hex>.<ext>`), so a hostile original name can't influence
  the path.
- `uploads/.htaccess` refuses to execute anything as a script, as a second
  layer behind the type check. With `mod_headers` enabled (the Docker image
  enables it), it also sends `X-Content-Type-Options: nosniff` and
  `Content-Security-Policy: sandbox`. Uploads share the admin's origin, so
  this stops a browser from ever running a file from there as a page with
  your session.
- `data/author.auth` is created owner-only (mode 0600) from the first byte,
  through a temporary file and an atomic rename.
- Sessions are `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS,
  and cookie-only. Strict mode is on, so the server ignores session ids it did
  not issue. On login the id is regenerated and the CSRF token is replaced.
- Eight failed sign-ins trigger a 15-minute lockout recorded in
  `data/login-throttle.json`. Each attempt is counted under a file lock
  *before* the password is checked, so parallel guesses cannot slip past the
  limit. If the throttle file cannot be read or written, sign-in fails
  instead of going unthrottled. The lockout applies to everyone, so someone
  guessing can keep you locked out too. A long password is the real defence.
- Uncaught errors are logged and the browser sees only a generic 500 page.
  `config.php` turns `display_errors` off for web requests, so paths and SQL
  never reach the page.

### Locking down the directories

`data/` holds your database and password hash. The generated `.htaccess`
denies web access, but **that file does nothing on nginx.** Either move the
directory outside the web root and repoint `DATA_DIR` in `config.php`:

```php
define('DATA_DIR', dirname(APP_ROOT) . '/blog-data');
```

or add the rule to your nginx server block:

```nginx
location ^~ /data/ { deny all; return 404; }

location ^~ /uploads/ {
    add_header X-Content-Type-Options nosniff always;
    add_header Content-Security-Policy sandbox always;
    location ~ \.(php|phtml|phar|cgi|pl|py)$ { deny all; return 404; }
}
```

Moving `data/` out of the web root is the better of the two. `uploads/` has to
stay reachable, since the public blog serves files from it.

## The gopher site

Press **Publish the site** on the Publish page (or the button on the entries
list). Every public entry is rendered into `GOPHER_DIR/current` as plain files that any
gopher server can hand out. Drafts, and any media attached only to drafts, stay
behind.

Set `GOPHER_HOST` and `GOPHER_PORT` before your first publish. They are written
into every menu line, and `localhost:70` will not serve anyone but you.

### The output tree

```
gopher/
  current -> releases/<token>     what the gopher server serves
  releases/<token>/
    gophermap            page 1          selector  /
    p2/gophermap         page 2          selector  /p2
    p3/gophermap         page 3          selector  /p3
    entries/0007.txt     entry bodies    selector  /entries/0007.txt
    entries/gophermap    a menu, so servers do not auto-list the directory
    media/<file>         attachments     selector  /media/<file>
    media/gophermap      likewise
```

Each entry appears in the menu as an information line carrying its creation
date and title, followed by its contents as links: attached media first in the
order you arranged the slots, then the text, then the external links.

```
i2026-08-28 07:15  Harbour notes, late August
I  harbour at dusk.png      /media/20260828-1f3a....png
s  halyards.wav             /media/20260828-9c02....wav
0  The tide was out for m...  /entries/0007.txt
h  Tide tables for the month  URL:https://example.org/tide-tables
```

An entry with no title of its own shows just its creation date, which then
serves as the title. Newest entries come first. Past
`GOPHER_ENTRIES_PER_PAGE` entries the menu gains next and previous links to
further menus.

### Entry text

Every entry with a body gets a file under `entries/`, named for its id:

- **Preformatted** entries are written exactly as typed.
- **Auto-wrapped** entries are word-wrapped and laid out as numbered,
  box-drawn pages. Words longer than the limit are left whole rather than
  split, so a long URL stays clickable.

The menu line for a text file shows the first `GOPHER_LINK_PREVIEW_CHARS`
characters of the file, with whitespace flattened to fit on one line.

### Item types

| Content | Type | Notes |
| --- | --- | --- |
| Menu | `1` | Pages and directory menus |
| Entry text | `0` | |
| Image | `I`, or `g` for GIF | |
| Sound | `s` | |
| Video | `;` | Conventional rather than in RFC 1436; clients that don't know it still offer a download |
| External link | `h` with a `URL:` selector | The usual way to put a web link in a gopher menu |
| Heading and spacing | `i` | Not in RFC 1436 either, but universally supported |

Change `GOPHER_MEDIA_TYPES` in `config.php` if you would rather serve media as
plain binary (`9`), which every client understands.

### Serving it

The renderer writes canonical RFC 1436 menus — four tab-separated fields per
line, CRLF endings, terminated by a lone period — with a `gophermap` file in
each directory. That is what **bucktooth**, **gophernicus** and **pygopherd**
expect, and they will serve the tree with no further configuration:

```
gophernicus -h your.host -p 70 -r /path/to/gopher/current
```

**geomyidae is the exception.** It reads its own `index.gph` format rather than
raw gophermaps, so point it at the tree only if you convert the menus first.

Because host and port are baked into every line, republish after changing
either one.

### How publishing behaves

Each publish rebuilds the site from scratch in a new
`GOPHER_DIR/releases/<token>/` directory. When the build succeeds, a fresh
symlink is renamed over `GOPHER_DIR/current`, which swaps the site in one
atomic step. Only then is the previous release deleted. A failure part way
through removes the half-built release and leaves the previous site
untouched and serving.

`GOPHER_DIR` itself is never renamed, so it can be a bind mount or Docker
volume. Publishes take turns through a lock on `data/publish.lock`, so a
double-click just publishes twice in a row. After each swap, every other
release directory is deleted, including any left behind by a publish that
crashed, but only directories whose names look like ones the renderer
generated. The `empty` placeholder and anything else you put in
`GOPHER_DIR` are left alone. Keep
handwritten phlog pages outside `releases/`, though, because `current`
only ever shows the generated tree.

### One gopher quirk worth knowing

A line containing nothing but a single period marks end of transmission. If one
appears inside an entry, some servers will truncate the file there. The
renderer does not alter your text, but it does warn you: the publish report
names any entry containing such a line. Add a space after the period, or use
two.

## Reading the entries yourself

If you also want an HTML blog, the queries are in `db.php`:

```php
$stmt = db()->query(
    'SELECT * FROM entries WHERE visible = 1 ORDER BY created_at DESC LIMIT 20'
);

$entry = entry_find($id);
if ($entry === null || (int) $entry['visible'] !== 1) {
    http_response_code(404);
    exit;
}
$media = entry_media($id);   // position => media row
$links = entry_links($id);   // position => ['url' => ..., 'label' => ...]
```

The body must respect `body_format`:

```php
if ($entry['body_format'] === 'pre') {
    echo '<pre>' . h($entry['body']) . '</pre>';
} else {
    echo '<p>' . nl2br(h($entry['body'])) . '</p>';
}
```

Escape first, then `nl2br` — the other order lets the `<br>` tags get escaped.
