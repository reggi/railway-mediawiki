# Railway MediaWiki

Reusable Railway deployment for a private MediaWiki instance using the official `mediawiki:stable` image, Railway managed PostgreSQL, persistent uploads, and the Vector 2022 skin.

## Architecture

The Railway project is defined in `.railway/railway.ts`. It creates one MediaWiki service from this repository, one managed PostgreSQL database, one persistent volume mounted at `/var/www/html/images`, generated MediaWiki secrets, and generated initial administrator credentials.

The Dockerfile adds the PostgreSQL PHP extension to the official image. `LocalSettings.php` reads deployment configuration from environment variables, enables uploads, selects Vector 2022, disables public account creation, and requires authentication to read or edit the wiki.

Article links use `/wiki/Page_Title` instead of exposing `index.php` in normal URLs.

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

## Privacy

Anonymous users cannot read or edit normal pages, and public account creation is disabled. The login page remains available. Signed in users can read and edit using standard MediaWiki permissions.

Pages in the `Public:` namespace can be read anonymously but can still only be edited by signed in users. Publish a page by creating it under a title such as `Public:About` or by moving an existing page into the `Public:` namespace. The namespace remains in the canonical URL for access control, but the visible heading and browser title show only the page name.

## Custom domain

Attach a custom domain to the MediaWiki service, set `MW_SERVER` to its full HTTPS origin without a trailing slash, and redeploy.
