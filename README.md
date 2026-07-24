# Moodle on Coolify

This stack builds Moodle on `moodlehq/moodle-php-apache`. Moodle core and repository-managed
extensions are versioned in the image. PostgreSQL, Redis, `moodledata`, and the runtime code
tree are persistent so plugins and themes installed through Moodle's web interface survive
container replacement.

## Coolify setup

1. Put this directory in a Git repository.
2. Create a Coolify resource using the **Docker Compose** build pack.
3. Coolify creates `SERVICE_URL_MOODLE_80` and assigns a generated domain to the `moodle`
   service. Replace that domain in the service's **Domains** field if you want a custom one.
4. Set the required `MOODLE_ADMIN_EMAIL` variable.
5. Web plugin/theme installation and GUI uninstall are enabled by default. If this
   resource was created from an older revision, explicitly set:
   - `MOODLE_DISABLE_WEB_PLUGIN_INSTALL=false`
   - `MOODLE_UNINSTALL_CLI_ONLY=false`
6. Optionally customize `MOODLE_SITE_FULLNAME` and `MOODLE_SITE_SHORTNAME`.
7. Deploy.
8. Retrieve the generated initial admin password from
   `SERVICE_PASSWORD_64_MOODLEADMIN` in Coolify.

Do not expose PostgreSQL or Redis with a domain or host port.

If this Coolify resource was created from an older revision:

- Delete the obsolete `MOODLE_URL` variable; Coolify now manages
  `SERVICE_URL_MOODLE_80`.
- Delete `SERVICE_PASSWORDWITHSYMBOLS_64_MOODLEADMIN`; this stack uses
  `SERVICE_PASSWORD_64_MOODLEADMIN`. Symbol passwords containing `$` can be interpreted as
  variable references unless marked **Literal**.

## Coolify environment variables

Coolify automatically creates these magic variables and keeps their values stable between
deployments:

- `SERVICE_URL_MOODLE_80`: public URL and proxy route for the `moodle` service on port 80.
- `SERVICE_PASSWORD_64_MOODLEDB`: PostgreSQL password.
- `SERVICE_PASSWORD_64_MOODLEREDIS`: Redis password.
- `SERVICE_PASSWORD_64_MOODLEADMIN`: initial Moodle administrator password.
- `SERVICE_PASSWORD_64_MOODLEUPGRADE`: Moodle web-upgrade key.

`COOLIFY_RESOURCE_UUID` is a predefined application variable. The Compose file uses it to
give this stack's locally built Moodle image a collision-free name. It does not need to be
created manually.

The only required user-supplied variable is `MOODLE_ADMIN_EMAIL`; Coolify highlights it
when empty. Other `MOODLE_*`, cron-monitoring, and Redis variables have editable defaults.
Secret values and normal application settings are runtime configuration; only
`MOODLE_PHP_IMAGE`, `MOODLE_VERSION`, `MOODLE_SERIES`, and `MOODLE_SHA256` affect the image
build.

## Plugins and themes

### Web installation

With `MOODLE_DISABLE_WEB_PLUGIN_INSTALL=false`, administrators can install and update
plugins and themes from Moodle's web interface. The `moodle_code` volume preserves those
files across restarts and deployments. The image synchronization service refreshes only
files managed by the previous image, leaving web-installed extension directories intact.

To enforce image/CLI-only extension management instead, set
`MOODLE_DISABLE_WEB_PLUGIN_INSTALL=true` and `MOODLE_UNINSTALL_CLI_ONLY=true`, then
redeploy. This changes the persistent code volume back to root-owned read-only mode.

Do not manage the same extension both through the web interface and `moodle-overlay/`.
Image-managed code wins during deployment and will overwrite that extension's web changes.

Allowing Moodle to write PHP code means a compromised administrator account or vulnerable
installer can lead to server-side code execution. Keep web installation disabled unless it
is required, restrict administrator access and MFA, install only trusted compatible
packages, and take a backup before every extension change.

### Repository-managed extensions

Place extensions in `moodle-overlay/` using their final Moodle paths, for example:

- `moodle-overlay/public/mod/customactivity/`
- `moodle-overlay/public/local/customplugin/`
- `moodle-overlay/public/theme/customtheme/`

Use the plugin directory name required by its `version.php`, and do not leave zip files,
renamed old copies, or nested duplicate plugin folders in the overlay.

For a new plugin/theme or an extension update on an existing site:

1. Back up PostgreSQL, `moodle_data`, and `moodle_code` if web installation is enabled.
2. Add or replace the extension code in `moodle-overlay/`.
3. Confirm that exact extension release supports the pinned Moodle version.
4. Set `MOODLE_AUTO_UPGRADE=true`, commit, and redeploy.
5. Verify the extension and scheduled tasks.
6. Set `MOODLE_AUTO_UPGRADE=false` and redeploy.

Before removing repository-managed extension code, run its documented uninstall/migration
procedure. Moodle's CLI uninstaller is available as
`php admin/cli/uninstall_plugins.php`; use its dry-run mode first and take a database backup.

## Updating Moodle

1. Schedule a maintenance window and back up PostgreSQL, `moodle_data`, and `moodle_code`
   externally.
2. Confirm the target Moodle release supports upgrades from your installed release.
3. Confirm every plugin and theme supports the target release.
4. Update `MOODLE_VERSION`, `MOODLE_SERIES`, and `MOODLE_SHA256`.
5. Set `MOODLE_AUTO_UPGRADE=true` and redeploy.
6. Verify the site, scheduled tasks, plugins, and outgoing email.
7. Set `MOODLE_AUTO_UPGRADE=false` and redeploy again.

The `moodle-prepare` service checks the database before web and cron start. If an upgrade
is pending while `MOODLE_AUTO_UPGRADE=false`, deployment stops instead of starting Moodle
with mismatched code and database versions.

Before database preparation, `moodle-code-init` synchronizes the new image-managed files
into `moodle_code`. It removes files owned by the previous image, installs the new core and
overlay, and preserves web-installed extensions.

A database upgrade is normally not reversible. Rolling back only the Docker image is not
enough after the schema has changed; restore the matching PostgreSQL, `moodle_data`, and
`moodle_code` backups together.

For a base-image security update without changing Moodle itself, rebuild/redeploy the same
Moodle version. For reproducible builds, set `MOODLE_PHP_IMAGE` to an explicit image digest
and update that digest deliberately.

## Persistent and image-managed data

- PostgreSQL persists Moodle configuration, users, courses, grades, plugin state, and most
  application logs.
- `moodle_data` persists uploads, generated files, caches, temporary files, and installed or
  customized language packs. Language packs can therefore be managed through Moodle.
- `moodle_code` persists the synchronized runtime code tree and web-installed plugins/themes.
- Redis persists sessions; losing it normally signs users out without losing course data.
- Moodle core, `config.php`, PHP settings, and anything in `moodle-overlay/` remain
  image-managed. Do not manually edit those files in a running container.

## Changing domain

1. Add the new DNS record.
2. Change the domain assigned to the `moodle` service in Coolify.
3. Confirm Coolify updated `SERVICE_URL_MOODLE_80` to the exact canonical URL.
4. Redeploy so Moodle receives the new canonical URL.
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
- `moodle_code` when web plugin installation is enabled; it contains extension code that may
  not exist in Git or the image.
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
- TLS termination and certificates are handled by the Coolify domain/proxy configuration.
- The cron container records a heartbeat after successful runs and becomes unhealthy if no
  success is recorded within `MOODLE_CRON_HEARTBEAT_MAX_AGE` seconds (default 600). It exits
  and restarts after `MOODLE_CRON_MAX_FAILURES` consecutive failures (default 3).
- This stack does not provision SMTP, antivirus, object storage, external search, monitoring,
  or automated backups. Add those separately if your requirements call for them.
- Some plugins require extra PHP extensions, system packages, services, or scheduled setup;
  review each plugin's requirements and extend the Dockerfile/Compose stack when necessary.
- Monitor disk usage for PostgreSQL, `moodledata`, Docker images, and Redis AOF data.
- `REDIS_MAXMEMORY` defaults to `512mb`. `REDIS_MAXMEMORY_POLICY` defaults to
  `volatile-lru`, so memory pressure may sign out inactive users instead of causing all new
  session writes to fail. Increase the limit for a larger user base and alert on evictions.
- Redis recommends `vm.overcommit_memory=1` on the Docker host. This kernel setting is not
  container-namespaced and must be configured by the server administrator; check the Redis
  startup log and host policy before changing it.
- Redis is configured for sessions only. If you add a Moodle application-cache store, use a
  different Redis database/prefix or a separate Redis service.
- Container logs use Docker's `local` driver with rotation limits. Forward or drain them
  separately if centralized retention is required.
- Large course backups and uploads may require higher PHP limits than the included 256 MB
  upload default.
- Do not enable rolling/parallel application upgrades. Treat Moodle code/database upgrades
  as maintenance-window operations.
- This is a production-minded wrapper around an upstream image whose stated focus is Moodle
  development/testing. Validate it against your own security, observability, availability,
  and support requirements before using it for a critical institution-wide deployment.
