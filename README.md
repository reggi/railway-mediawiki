# Railway MediaWiki

Reusable Railway deployment for a private MediaWiki instance using the official `mediawiki:stable` image, Railway managed PostgreSQL, persistent uploads, and the Vector 2022 skin.

## Architecture

The Railway project is defined in `.railway/railway.ts`. It creates one MediaWiki service from this repository, one managed PostgreSQL database, one persistent volume mounted at `/var/www/html/images`, generated MediaWiki secrets, and generated initial administrator credentials.

The Dockerfile adds the PostgreSQL PHP extension, ExifTool, ImageMagick, Node.js 24, WikiSEO, and the bundled image metadata sanitizer and social-card extensions to the official image. `LocalSettings.php` reads deployment configuration from environment variables, enables uploads, selects Vector 2022, disables public account creation, and requires authentication to read or edit the wiki.

Article links use `/wiki/Page_Title` instead of exposing `index.php` in normal URLs.

Vector 2022 has responsive layouts enabled with `$wgVectorResponsive = true`, so it adapts to phone screens without switching skins. The Appearance menu and table of contents sidebar are collapsed by default. Readers can still open or pin either one; saved user preferences take precedence over these defaults.

Article headings include a small `#` permalink to their section fragment, which readers can follow or copy.

Logged-out homepage visits show one public article feed, ordered by the latest creation or edit and paginated in groups of 30. Each article appears once with a Created or Edited date. Blurbs use explicit WikiSEO descriptions or the first introductory paragraph, just like social cards. Redirects and private or unreadable pages are excluded. Signed-in users retain the normal Main Page; edit, history, and revision requests are unchanged. The feed is also available at `Special:PublicHome`.

## Deploy

Install dependencies, authenticate the Railway CLI, review the plan, and apply it:

```sh
npm ci
railway login
npm run railway:plan
npm run railway:apply
railway domain --service MediaWiki
```

The domain command creates a Railway provided `up.railway.app` domain. The first successful deployment initializes the PostgreSQL schema and creates the administrator. Railway generates `MW_ADMIN_PASSWORD`; retrieve its value from the MediaWiki service variables and sign in with the `MW_ADMIN_USER` value, which defaults to `Admin`.

## Configuration

The default nonsecret settings are declared in `.railway/railway.ts`. Change the site name, administrator username, language, or timezone there before applying the project. Railway generates `MW_ADMIN_PASSWORD`, `MW_SECRET_KEY`, and `MW_UPGRADE_KEY` server side, so no secrets are committed.

The MediaWiki service receives `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, and `PGPASSWORD` directly from the managed PostgreSQL resource. Uploaded files remain on the `MediaWiki uploads` volume across deployments.

## Image upload privacy

New and replacement image uploads are inspected before MediaWiki stores the original file. When an image contains EXIF, GPS, camera MakerNotes, IPTC, XMP, comments, embedded previews, or similar metadata, the bundled `ImageMetadataSanitizer` extension normalizes EXIF orientation and removes the metadata with ExifTool. ICC color profiles are retained.

The policy applies to browser uploads, API uploads, uploads from URLs, and stashed uploads because sanitization occurs in the local file storage path. Documents, audio, video, and SVG files are not modified. Existing uploaded files are not changed.

Sanitization fails closed. If required tooling is unavailable, processing times out, the image cannot be rewritten, or privacy metadata remains after processing, MediaWiki rejects the upload and logs a server-side error without logging the removed metadata values.

## Privacy

Anonymous users cannot read or edit normal pages, and public account creation is disabled. The login page remains available. Signed in users can read and edit using standard MediaWiki permissions.

Pages in the `Public:` namespace can be read anonymously but can still only be edited by signed in users. Publish a page by creating it under a title such as `Public:About` or by moving an existing page into the `Public:` namespace. Public articles use clean links such as `/wiki/About`, while remaining in the public namespace internally for access control. Canonical and social metadata use the clean URL; `/wiki/Public:About` remains valid. The visible heading and browser title show only the page name.

An existing main-namespace article with the same name keeps its URL and permissions; its public counterpart retains the explicit `Public:` URL. A direct main-namespace redirect to that same public article is treated as a clean alias instead of a collision. Edit/history links and explicit `index.php?title=...` requests keep their namespace so administrative actions remain unambiguous. Moving or deleting a public article removes its clean alias without granting anonymous access to private content.

## Custom domain

Attach a custom domain to the MediaWiki service, set `MW_SERVER` to its full HTTPS origin without a trailing slash, and redeploy.

## Article social cards

Saving an anonymously readable `Public:` article automatically generates a 1200 x 630 PNG social card. The card uses the article title without `Public:`, the first introductory paragraph, and the infobox image on a plain white canvas. The canonical host appears below the description in small, muted text; there is no type label, watermark, border, or rounded framing. Both `.infobox` tables and the first right-floating `wikitable` in the lead are supported. Articles without an infobox image use a text-only layout. Long text is bounded and clipped to the card layout.

The bundled `/generate` CLI reads one JSON payload from stdin, uses Satori and `@resvg/resvg-js`, and returns only PNG bytes on stdout. It is not an HTTP server. Local Inter fonts are supplied by the MIT/OFL-licensed `@fontsource/inter` package; its license notices are retained in the installed package. The document icon is defined in the repository template.

MediaWiki core has no standard article blurb field. By default, card text comes from the first substantive paragraph outside the infobox, before the first heading. To supply an editorial blurb instead, add `{{#seo:|description=Your short summary}}`; that description is used for both the card and SEO metadata. Removing it restores the first-paragraph fallback. Card text is normalized and bounded to 240 characters.

PHP hashes canonical render inputs, including the template/dependency/font fingerprint and normalized source image bytes. An unchanged, valid `<sha>.png` is reused without starting Node. PNGs and per-page manifests live in the existing uploads volume. Rendering runs after the article is committed in the save request; failures are logged without undoing the edit. A previously valid card may remain advertised while the article remains public. A failed first render has no article-specific card.

WikiSEO supplies Open Graph metadata, and the integration supplies an explicit Twitter image with `summary_large_image`. No dedicated queue worker is needed: WikiSEO's automatic description and built-in social-image rendering are disabled. The normal MediaWiki job queue is otherwise unchanged.

### Manual override

Upload a complete card using MediaWiki's normal upload interface, then add:

```wikitext
{{#seo:|image=Uploaded_card.png}}
```

The local uploaded file replaces the entire automatic card; it is not a background photograph for the template. Removing the parameter resumes automatic generation on the next save. PNG, JPEG, GIF, and WebP files are supported; the first frame is normalized to PNG within 1200 x 1200 while retaining its aspect ratio. Source files are limited to 32 MiB and 40 million pixels. Missing, external, or unsupported image references are logged as errors.

### Refreshing cards

Existing articles are not backfilled during startup. Run the maintenance command inside the MediaWiki container as `www-data`:

```sh
php maintenance/run.php ./extensions/SocialCards/maintenance/RefreshSocialCards.php
php maintenance/run.php ./extensions/SocialCards/maintenance/RefreshSocialCards.php --page 'Public:Example'
php maintenance/run.php ./extensions/SocialCards/maintenance/RefreshSocialCards.php --force
```

Use this after changing a transcluded infobox template, replacing an uploaded photo, editing the card template/fonts, or changing the canonical host. These changes alone do not invoke article-save hooks. The normal refresh path hashes current inputs and skips unchanged cards; `--force` also replaces valid cached images.

### Card privacy and validation

Generated storage is blocked by Apache. Advertised `/social-cards/<page-id>/<sha>.png` URLs pass through MediaWiki and check that the article still exists, remains publicly readable, and still advertises that hash. Moving a page out of `Public:` or deleting it revokes publication; old hashes and direct storage paths cannot bypass these checks. Image responses use `Cache-Control: no-store`. Copies already downloaded by third-party preview services cannot be recalled. This protection applies to the generated card copies, not MediaWiki's existing uploaded-file URLs.

Run `npm test` for repository quality and renderer tests. The Docker build runs PHP integration tests covering extraction, hashing, PNG validation, process limits, and storage. Renderer input is limited to 4 MiB, output to 8 MiB, and each PHP-managed process to 15 seconds. Subprocess failures report identifiers and safe errors rather than article payloads.
