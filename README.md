# Spotweb Docker image

A small, self-maintaining Docker image for [Spotweb](https://github.com/spotweb/spotweb),
built on nginx + PHP-FPM (Alpine). It tracks the upstream `develop` branch and rebuilds only
when upstream actually changes. Deploy files are included for Coolify and for a standalone host
such as a Synology NAS.

The goal is a set-and-forget setup: new upstream commits are picked up, tested and published
automatically, and you are only notified when the build breaks.

## Images

Published to both registries, multi-arch (`linux/amd64`, `linux/arm64`):

- `ghcr.io/istoutjesdijk/spotweb`
- `docker.io/istoutjesdijk/spotweb`

Tags:

- `latest` — the most recent successful build.
- `sha-<shortsha>` — the upstream Spotweb commit the image was built from.
- `YYYYMMDD` — the build date, useful for pinning and rollback.

## How it stays up to date

- `upstream-update` runs daily. It compares the upstream `develop` HEAD against the commit
  pinned in `.upstream-ref`. If they differ, it writes the new commit id and pushes it.
- That push triggers `build`, which builds the image, runs a smoke test, and — only if the test
  passes — publishes the new image to both registries.
- If a build fails, the workflow run is marked failed. GitHub emails the repository owner, and
  the workflow also opens (or comments on) a `ci-failure` issue. Nothing is published on failure,
  so `latest` keeps pointing at the last working image.
- Dependabot keeps the workflow actions and the Alpine base image current with weekly pull
  requests.

On the deployment side, updates are pulled in one of two ways:

- Standalone with Watchtower: Watchtower polls the registry and pulls + restarts Spotweb when a
  new image appears.
- Coolify: redeploy the resource, or wire the publish step to a Coolify deploy webhook (see below).

## Standalone deployment (Synology NAS, plain Docker)

```sh
cp .env.example .env      # edit MYSQL_PASSWORD at least
docker compose -f deploy/standalone/docker-compose.yml up -d
```

The UI is served on `http://<host>:8081` by default (change `SPOTWEB_PORT` in `.env`). This
variant bundles MariaDB and does not update itself.

For automatic image updates, use the Watchtower variant instead:

```sh
docker compose -f deploy/standalone/docker-compose.watchtower.yml up -d
```

On Synology, import either compose file in Container Manager as a new project, place the values
from `.env.example` in the project's environment, and start it.

## Coolify deployment

The Coolify stack contains only Spotweb and connects to a Coolify-managed database.

1. Create a Resource → Database → MariaDB. Note its host, database name, user and password.
2. Create a Resource → Docker Compose and paste `deploy/coolify/docker-compose.yml`.
3. In the resource's environment variables, set `SPOTWEB_DB_HOST`, `SPOTWEB_DB_NAME`,
   `SPOTWEB_DB_USER` and `SPOTWEB_DB_PASS` to the database values. `SERVICE_FQDN_SPOTWEB_8080`
   makes Coolify assign a domain and proxy HTTPS to port 8080.
4. Deploy.

Health check: the image exposes `/healthz`, served directly by nginx, and the compose file
declares a matching `healthcheck`. Coolify uses it to mark the resource healthy once the web
server is up, independent of database state.

Optional auto-deploy: create a deploy webhook for the resource in Coolify and add it as a
repository secret named `COOLIFY_WEBHOOK`; you can then extend the publish job to call it after a
push.

## First run

The container creates the database schema and a default admin account automatically on first
start, so you do not need the `install.php` wizard.

- Default login: username `admin`, password `admin`. Change the password immediately under
  Settings after logging in.
- Configure a Usenet (NNTP) provider under Settings before spots can be retrieved.

To reset the admin password from the host if needed:

```sh
docker compose exec spotweb su-exec www-data php /var/www/spotweb/bin/upgrade-db.php --reset-password admin
```

This sets it back to `spotweb`.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `TZ` | `Etc/UTC` | Container timezone. |
| `SPOTWEB_DB_TYPE` | `pdo_mysql` | Database engine: `pdo_mysql` or `pdo_pgsql`. |
| `SPOTWEB_DB_HOST` | — | Database host. |
| `SPOTWEB_DB_PORT` | engine default | Database port (3306 for MySQL, 5432 for PostgreSQL). |
| `SPOTWEB_DB_NAME` | — | Database name. |
| `SPOTWEB_DB_USER` | — | Database user. |
| `SPOTWEB_DB_PASS` | — | Database password. |
| `SPOTWEB_CRON_RETRIEVE` | `*/15 * * * *` | Cron schedule for retrieving spots. Empty disables it. |
| `SPOTWEB_CRON_CACHE_CHECK` | `0 4 * * *` | Cron schedule for the cache check. Empty disables it. |

A `/config` volume is mounted for persistence. If it contains `dbsettings.inc.php` or
`ownsettings.php`, those files take precedence over the environment variables.

## Data and backups

- Standalone: back up the `spotweb-db` volume (the database holds all spots, comments and
  settings) and, if you customised it, `spotweb-config`.
- Coolify: use the managed database resource's backup feature.

## Repository setup (maintainer)

One-time settings in the GitHub repository:

- Settings → Secrets and variables → Actions:
  - Variable `DOCKERHUB_USERNAME` — your Docker Hub username.
  - Secret `DOCKERHUB_TOKEN` — a Docker Hub access token with write access.
- Settings → Actions → General → Workflow permissions: Read and write (needed for the
  `.upstream-ref` bump commit and the failure issue).
- GitHub Container Registry access is handled by the built-in `GITHUB_TOKEN`; no extra secret is
  needed. Set the `spotweb` package visibility to public if you want unauthenticated pulls.

## Updating and rollback

- Watchtower and `:latest` deployments update on their own.
- To pin a specific version, use a `YYYYMMDD` or `sha-<shortsha>` tag as the image instead of
  `latest`.
- To roll back, deploy an earlier date tag; every published build stays available.

## Local build and test

```sh
docker build -t spotweb:test .
SPOTWEB_IMAGE=spotweb:test ./test/smoke.sh
```

The smoke test boots the standalone stack, waits for the container to become healthy, and checks
`/healthz`, the main page and a clean schema bootstrap.

## Credits

Spotweb is developed at [spotweb/spotweb](https://github.com/spotweb/spotweb). This repository
only packages and maintains a container image around it.
