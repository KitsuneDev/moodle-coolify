# Moodle on Coolify

This stack builds Moodle into an immutable image based on `moodlehq/moodle-php-apache`.
Only PostgreSQL, Redis, and `moodledata` are persistent; Moodle core, plugins, themes,
`config.php`, and PHP settings are versioned in the image.

## Coolify setup

1. Put this directory in a Git repository.
2. Create a Coolify resource using the **Docker Compose** build pack.
3. Set the required variables:
   - `MOODLE_URL=https://learn.example.com`
   - `MOODLE_ADMIN_EMAIL=admin@example.com`
4. Optionally customize `MOODLE_SITE_FULLNAME` and `MOODLE_SITE_SHORTNAME`.
5. Assign the public domain only to the `moodle` service, using container port 80.
6. Deploy.
7. Retrieve the generated initial admin password from
   `SERVICE_PASSWORDWITHSYMBOLS_64_MOODLEADMIN` in Coolify.

Do not expose PostgreSQL or Redis with a domain or host port.

## Plugins and themes

Place extensions in `moodle-overlay/` using their final Moodle paths, for example:

- `moodle-overlay/mod/customactivity/`
- `moodle-overlay/local/customplugin/`
- `moodle-overlay/theme/customtheme/`

Commit and redeploy. Web-based plugin installation and GUI uninstall are disabled by
default because the application code is immutable. Remove a plugin from the image only
after following that plugin's supported uninstall/migration procedure.

## Updating Moodle

1. Schedule a maintenance window and back up PostgreSQL and `moodle_data` externally.
2. Confirm the target Moodle release supports upgrades from your installed release.
3. Confirm every plugin and theme supports the target release.
4. Update `MOODLE_VERSION`, `MOODLE_SERIES`, and `MOODLE_SHA256`.
5. Set `MOODLE_AUTO_UPGRADE=true` and redeploy.
6. Verify the site, scheduled tasks, plugins, and outgoing email.
7. Set `MOODLE_AUTO_UPGRADE=false` and redeploy again.

The `moodle-prepare` service checks the database before web and cron start. If an upgrade
is pending while `MOODLE_AUTO_UPGRADE=false`, deployment stops instead of starting Moodle
with mismatched code and database versions.

A database upgrade is normally not reversible. Rolling back only the Docker image is not
enough after the schema has changed; restore the matching PostgreSQL and `moodle_data`
backups as a pair.

For a base-image security update without changing Moodle itself, rebuild/redeploy the same
Moodle version. For reproducible builds, set `MOODLE_PHP_IMAGE` to an explicit image digest
and update that digest deliberately.

## Changing domain

1. Add the new DNS record.
2. Change the domain assigned to the `moodle` service in Coolify.
3. Change `MOODLE_URL` to the exact canonical URL, with no trailing slash.
4. Redeploy.
5. After a database backup, replace stored absolute URLs:

```bash
docker compose exec -u 33 moodle php admin/tool/replace/cli/replace.php \
  --search='https://old.example.com' \
  --replace='https://new.example.com' \
  --shorten \
  --non-interactive

docker compose exec -u 33 moodle php admin/cli/purge_caches.php
```

Keep the old hostname redirected to the new hostname while old links and browser bookmarks
remain in circulation. Update OAuth/SAML/LTI callback URLs and any external integrations.

## Backups

Back up at least:

- PostgreSQL (`postgres_data`), using a database-aware dump or Coolify backup workflow.
- `moodle_data`, preserving file ownership and permissions.
- This Git repository and the exact image/version metadata used by the deployment.

Redis contains sessions in this stack. Losing it normally logs users out but should not lose
course content. It is not a substitute for database or `moodledata` backups.

After restoring `moodle_data`, make sure it is owned by UID/GID 33. For a large restored
volume, perform this once during maintenance rather than adding a recursive `chown` to every
deployment:

```bash
docker run --rm -u 0:0 -v YOUR_MOODLE_DATA_VOLUME:/data alpine:3.22 \
  chown -R 33:33 /data
```

## Operational notes

- Configure SMTP under Moodle's outgoing mail settings and test delivery.
- Monitor disk usage for PostgreSQL, `moodledata`, Docker images, and Redis AOF data.
- `REDIS_MAXMEMORY` defaults to `256mb` with `noeviction`; increase it for a larger user base.
- Redis is configured for sessions only. If you add a Moodle application-cache store, use a
  different Redis database/prefix or a separate Redis service.
- Large course backups and uploads may require higher PHP limits than the included 256 MB
  upload default.
- Do not enable rolling/parallel application upgrades. Treat Moodle code/database upgrades
  as maintenance-window operations.
- This is a production-minded wrapper around an upstream image whose stated focus is Moodle
  development/testing. Validate it against your own security, observability, availability,
  and support requirements before using it for a critical institution-wide deployment.
