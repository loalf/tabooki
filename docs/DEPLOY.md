# Deploying Tabooki

Tabooki runs at **https://tabooki.xyz** on a shared Ubuntu server (`demo.rootware.xyz` in
`~/.ssh/config`, 13.140.143.9). It's public: sign-in protects everything but the sign-in page,
with login throttling. Anyone with a Google account can sign up (see "Sign in with Google"). Images are built on the server, so the Mac's ARM vs the server's x86
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
   OPENROUTER_API_KEY=...         # OpenRouter API key (Whisper: what is said in videos)
   # Optional (defaults in backend/.env): BEDROCK_TEXT_MODEL, EXTRACTION_THINKING=0,
   # OPENROUTER_TRANSCRIPTION_MODEL
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

Deploy a commit, never the working folder: work in progress (changed or new files you haven't
committed) stays on the Mac. From the repo on the Mac, unpack the commit over `/opt/tabooki`,
note which one it is, then rebuild on the server:

```sh
git archive HEAD | ssh demo.rootware.xyz 'tar -x -C /opt/tabooki'
git rev-parse --short HEAD | ssh demo.rootware.xyz 'cat > /opt/tabooki/REVISION'
ssh demo.rootware.xyz 'cd /opt/tabooki && docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait'
```

Replace `HEAD` with a commit id or tag to deploy another one (for example to go back to the
previous one). `ssh demo.rootware.xyz cat /opt/tabooki/REVISION` says which commit is live.
Only committed files travel, so `.env.local`, cookies and builds never do, and the server's own
`/opt/tabooki/.env` is left alone. Unpacking doesn't delete anything: a file you deleted in git
stays on the server until you remove it there too.

That builds the image, runs migrations (php's entrypoint) and restarts php and worker. The
server has no `make`, so the Makefile's `prod-*` targets are for a server that does. Logs:
`docker compose -f compose.yaml -f compose.prod.yaml logs -f worker`.

Recipes are translated into the other language as they are added or edited. After the deploy
that introduced translations (or if some failed), translate the rest:

```sh
ssh demo.rootware.xyz 'cd /opt/tabooki && docker compose -f compose.yaml -f compose.prod.yaml exec php bin/console app:translate'
```

The ingredient knowledge (docs/INGREDIENT-KNOWLEDGE.md) fills itself in as recipes come in. After
the deploys that introduced it, run once for the recipes already there: `app:annotate` (weights),
`app:foods` (foods, nutrition, allergens, substitutes) and `app:embed` (search by meaning):

```sh
ssh demo.rootware.xyz 'cd /opt/tabooki && docker compose -f compose.yaml -f compose.prod.yaml exec php sh -c "bin/console app:annotate && bin/console app:foods && bin/console app:embed"'
```

The database image is built here (`deploy/postgres/`: Postgres 17 alpine with pgvector), so the
first deploy with it rebuilds and restarts the database container; its data volume stays.

## Email (password resets)

"Forgot your password?" emails a link, and everyone gets a note when their password changes.
Until a provider is set, those emails are silently discarded (`MAILER_DSN=null://null`), so a
forgotten password can only be reset from the server (`app:user:password`, below).

We use [Resend](https://resend.com). Its free plan (3,000 emails a month, 100 a day, one domain)
is plenty for a household.

1. In Resend, go to **Domains** and add `tabooki.xyz`.
2. Add the DNS records Resend lists in GoDaddy: a DKIM TXT record, plus an MX and an SPF TXT
   record on its `send` subdomain. Adding a DMARC record is optional but recommended. Wait until
   Resend shows the domain as verified. Until then it only sends to your own Resend account's
   address.
3. Create an API key under **API Keys** with **Sending access** for that domain only, and save
   it on the server. This script asks for the key without showing it, writes `MAILER_DSN` and
   `MAILER_FROM` into `/opt/tabooki/.env`, restarts Tabooki and sends a test email:

   ```sh
   ssh -t demo.rootware.xyz /opt/tabooki/deploy/set-resend-key.sh you@example.com
   ```

   Doing it by hand instead, the two lines are:

   ```sh
   MAILER_DSN=smtp://resend:API_KEY@smtp.resend.com:587
   MAILER_FROM="Tabooki <no-reply@tabooki.xyz>"
   ```

   The username is literally `resend`. Port 587 uses STARTTLS. If the server's network blocks
   587, use 2587 instead (also STARTTLS), or `smtps://…@smtp.resend.com:465` (implicit TLS).
   With another provider, URL-encode special characters in the username or password
   (`/` → `%2F`, `+` → `%2B`…). Then recreate the containers
   (`docker compose -f compose.yaml -f compose.prod.yaml up -d --wait`) and send a test, from the
   verified domain (the default sender, `from@example.org`, would be rejected):
   `docker compose -f compose.yaml -f compose.prod.yaml exec php php bin/console mailer:test you@example.com --from=no-reply@tabooki.xyz`.

Emails are sent by the worker; if one fails it's retried, and its error is in
`docker compose -f compose.yaml -f compose.prod.yaml logs worker`.

Without email (or for someone locked out), set a password from the server; it asks for the new
one and signs them out everywhere:
`docker compose -f compose.yaml -f compose.prod.yaml exec -it php php bin/console app:user:password someone@example.com`.

## Sign in with Google

With a Google OAuth client set up, the sign-in page shows **Continue with Google**. Anyone can
sign up this way: someone new gets an account in a household of their own (named "Casa de Ana" or
"Ana's home"), and their recipes are extracted with Tabooki's Bedrock and OpenRouter keys, so
watch **Settings → AI costs** and the server log (`New account from Google sign-in`). One IP can
create 5 accounts a day. Someone who already has an account (made with `make user`) gets that
account, matched by email, and Google is linked to it. Their password keeps working. People who
signed up with Google can add a password in Settings.

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project (or reuse one),
   then open **Google Auth Platform**.
2. **Branding**: app name Tabooki, a support email, and `tabooki.xyz` as an authorized domain.
   **Audience**: External, then **Publish app**. While it's in "Testing" only the test users
   listed there can sign in. The scopes we ask for (`openid email profile`) need no verification.
3. **Clients → Create client**: type *Web application*, authorized redirect URIs
   `https://tabooki.xyz/api/auth/google/callback` (and `https://localhost/api/auth/google/callback`
   to try it in development, or use a second client for that).
4. Put the client ID and secret in `/opt/tabooki/.env`, then recreate the containers
   (`docker compose -f compose.yaml -f compose.prod.yaml up -d --wait`):

   ```sh
   GOOGLE_CLIENT_ID=1234567890-abc.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-...
   ```

   In development they go in `backend/.env.local`, then `docker compose restart php` (FrankenPHP
   reads the environment once, when it starts).

Without them, there is no Google button and nothing else changes. Failed Google sign-ins are
logged as `Google sign-in failed` in `docker compose -f compose.yaml -f compose.prod.yaml logs php`.

## Phones

Open https://tabooki.xyz, sign in, and install it: **Share → Add to Home Screen** (iPhone Safari)
or **⋮ → Install app** (Android Chrome). Then **Settings → Save from your phone** explains the
Android share menu and the iPhone Shortcut.

## Backups (to do before real data goes in)

Nightly database dump plus the media volume (`tabooki_media_data`: cached thumbnails, later
uploads) and `tabooki_food_tables` (the reviewed BEDCA and USDA pairings, `pairs.json`; the rest
of it is their cached answers), copied **off the server** with restic (object storage or a home
NAS). Sketch:

```sh
docker compose -f compose.yaml -f compose.prod.yaml exec -T database \
  pg_dump -U app -Fc tabooki > /var/backups/tabooki/$(date +%F).dump
restic backup /var/backups/tabooki "$(docker volume inspect -f '{{ .Mountpoint }}' tabooki_media_data)" \
  "$(docker volume inspect -f '{{ .Mountpoint }}' tabooki_food_tables)"
```
