# Tabooki

A self-hosted household meal planner. Save recipe links from anywhere (blogs, YouTube,
Instagram, TikTok). Tabooki turns them into structured recipes, suggests a weekly plan
of lunches and dinners, and builds the shopping list.

See [docs/PLAN.md](docs/PLAN.md) for the product and architecture plan, and
[docs/DEPLOY.md](docs/DEPLOY.md) for running it on the VPS.

## Stack

- **backend/**: Symfony 7.4 LTS + API Platform, Doctrine/PostgreSQL, Messenger, Mercure,
  served by FrankenPHP (Caddy)
- **frontend/**: React + Vite + TypeScript SPA (PWA), TanStack Query, React Router,
  Tailwind CSS, react-i18next (es/en)
- One origin for everything: Caddy serves `/api/*` from Symfony, `/.well-known/mercure`
  from the Mercure hub, and every other path from the SPA (Vite dev server in development,
  static build in production)

## Development

Requirements: Docker (with Compose), Node 24+, make.

```sh
make build            # first time only (several minutes on Apple Silicon)
make up               # php + worker + postgres at https://localhost
make front-install    # first time only
make front            # Vite dev server (keep it running)
make user email=you@example.com name="You" household="Casa"
```

Open **https://localhost**, not `:5174`. Caddy proxies the SPA to Vite, so cookies, the
API and HMR all share one origin. The first time, your browser will warn about Caddy's
local certificate authority. To trust it on macOS:

```sh
docker compose cp php:/data/caddy/pki/authorities/local/root.crt /tmp/caddy-root.crt
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain /tmp/caddy-root.crt
```

Useful commands (`make` lists all of them):

| Command | What it does |
|---|---|
| `make logs s=worker` | follow one service's logs |
| `make console c="debug:router"` | run a Symfony console command |
| `make migration` / `make migrate` | create / apply Doctrine migrations |
| `make api-types` | regenerate `frontend/src/api/schema.d.ts` from the OpenAPI spec |
| `make check` | PHPStan, PHP-CS-Fixer, PHPUnit, TS typecheck, oxlint |

The API docs (Swagger UI) are at https://localhost/api/docs once you're signed in.
