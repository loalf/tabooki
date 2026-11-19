# Deploying Tabooki

Tabooki runs at **https://tabooki.xyz** on a shared Ubuntu server (`demo.rootware.xyz` in
`~/.ssh/config`, 13.140.143.9). It's public: sign-in protects everything but the sign-in page,
with login throttling. Images are built on the server, so the Mac's ARM vs the server's x86
doesn't matter.

## How the server is shared

Other apps run on the same server, so Tabooki doesn't own ports 80/443:

| What | Where | Ports |
|---|---|---|
| **edge**: HAProxy, the front door | `/opt/edge` (from `deploy/edge/`) | public 80, 443 (TCP) |
| rootware demo (`demo.rootware.xyz`, behind Cloudflare) | `/opt/rootware/deploy/demo` | `127.0.0.1:8080`, `127.0.0.1:8443` (TCP and UDP) |
| Tabooki | `/opt/tabooki` | `127.0.0.1:9080`, `127.0.0.1:9443` |
| gamen | `/opt/gamen` | 8787, untouched |

HAProxy reads the hostname of each connection (the TLS SNI name, or the `Host` header on port
80) and hands it over: `tabooki.xyz` to Tabooki, everything else to rootware. HTTPS stays
encrypted end to end, so each app's Caddy gets and renews its own Let's Encrypt certificate as
if it had the ports itself. Tabooki also receives the visitor's IP address (PROXY protocol);
rootware sees connections from 127.0.0.1.

rootware's ports are moved by two lines in its `/opt/rootware/deploy/demo/.env` (which its own
deploys keep):

```sh
HTTP_PORT=127.0.0.1:8080
HTTPS_PORT=127.0.0.1:8443
```

To add another app later: give it localhost ports, add a `use_backend` line and a backend to
`/opt/edge/haproxy.cfg`, then `docker compose restart` in `/opt/edge`.

## First install (done 2026-11-19)

1. DNS (GoDaddy): one A record for `tabooki.xyz` → 13.140.143.9. Delete GoDaddy's default
   parking A records (`3.33.130.190`, `15.197.148.33`): with them, most visitors and Let's
   Encrypt land on GoDaddy instead.
2. Copy the project (see Update), then create `/opt/tabooki/.env` (mode 600, never committed):

   ```sh
   SERVER_NAME=tabooki.xyz
   APP_SECRET=...                 # openssl rand -hex 32
   CADDY_MERCURE_JWT_SECRET=...   # openssl rand -hex 32
   CADDY_MERCURE_JWT_ISSUER=https://tabooki.xyz
   POSTGRES_PASSWORD=...          # openssl rand -hex 24
   AWS_BEARER_TOKEN_BEDROCK=...   # Amazon Bedrock API key (recipe extraction)
   AWS_REGION=eu-west-1
   # Optional (defaults in backend/.env): BEDROCK_TEXT_MODEL, BEDROCK_VIDEO_MODEL, EXTRACTION_THINKING=0
   ```

   Keep a copy in your password manager.
3. Start Tabooki: `cd /opt/tabooki && docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait`.
4. Edge: copy `deploy/edge/` to `/opt/edge`, check it with
   `docker run --rm -v /opt/edge/haproxy.cfg:/c.cfg:ro haproxy:3.2-alpine haproxy -c -f /c.cfg`,
   move rootware's ports (`.env` above, then
   `docker compose -f deploy/demo/compose.yaml up -d --no-deps --no-build web` in `/opt/rootware`)
   and right after `docker compose up -d` in `/opt/edge`. rootware is unreachable for the few
   seconds in between.
5. Your account: `docker compose -f compose.yaml -f compose.prod.yaml exec -it php php bin/console app:user:create you@example.com --name="You" --household="Casa"`
   (it asks for the password).
6. Check extraction can reach Bedrock: `docker compose -f compose.yaml -f compose.prod.yaml exec php php bin/console app:bedrock:check`.

**Undo the edge** (rootware back on 80/443): `docker compose down` in `/opt/edge`, delete the two
port lines from rootware's `.env`, and recreate its web container as in step 4.

## Update

From the repo on the Mac, copy what git tracks (never `.env.local`, cookies or builds), then
rebuild on the server:

```sh
git ls-files -co --exclude-standard | rsync -az --files-from=- ./ demo.rootware.xyz:/opt/tabooki/
ssh demo.rootware.xyz 'cd /opt/tabooki && docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait'
```

That builds the image, runs migrations (php's entrypoint) and restarts php and worker. The
server has no `make`, so the Makefile's `prod-*` targets are for a server that does. Logs:
`docker compose -f compose.yaml -f compose.prod.yaml logs -f worker`.

## Phones

Open https://tabooki.xyz, sign in, and install it: **Share → Add to Home Screen** (iPhone Safari)
or **⋮ → Install app** (Android Chrome). Then **Settings → Save from your phone** explains the
Android share menu and the iPhone Shortcut.

## Backups (to do before real data goes in)

Nightly database dump plus the media volume (`tabooki_media_data`: cached thumbnails, later
uploads), copied **off the server** with restic (object storage or a home NAS). Sketch:

```sh
docker compose -f compose.yaml -f compose.prod.yaml exec -T database \
  pg_dump -U app -Fc tabooki > /var/backups/tabooki/$(date +%F).dump
restic backup /var/backups/tabooki "$(docker volume inspect -f '{{ .Mountpoint }}' tabooki_media_data)"
```
