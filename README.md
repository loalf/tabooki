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

### Recipe extraction (Amazon Bedrock and OpenRouter)

Saved links are turned into recipes by an LLM on Amazon Bedrock. When a reel's caption isn't
enough, the app watches the video: Whisper (through OpenRouter) transcribes what is said, ffmpeg
takes stills, and the same model reads both, estimating amounts nobody gives ("~380 g"). Recipes
are written in the language of whoever adds them, and the same model translates each one into
the other language in the background, so everyone reads them in theirs.
Create `backend/.env.local` (git-ignored) with both API keys, then restart the worker:

```sh
AWS_BEARER_TOKEN_BEDROCK=ABSK...
AWS_REGION=eu-west-1          # the region your key works in
OPENROUTER_API_KEY=sk-or-...  # OpenRouter key: Whisper transcribes what is said in videos
# Optional, defaults in backend/.env:
# BEDROCK_TEXT_MODEL=...       # reads captions, transcripts, pages and video stills
# EXTRACTION_THINKING=0        # Claude answers without thinking first: faster, fewer tokens
# OPENROUTER_TRANSCRIPTION_MODEL=...  # the Whisper model (openai/whisper-large-v3)
```

```sh
docker compose restart worker
make console c=app:bedrock:check          # lists the models your key can use, runs a test extraction
make console c="app:extract --all"        # extract links saved before the key was set
make console c="app:extract <id> --sync"  # extract one link here and print the result
make console c=app:translate              # translate recipes not yet in both languages
```

After switching `BEDROCK_TEXT_MODEL` to a newer model, extract the old
recipes again (in place: ratings, favorites and planned meals stay). One recipe at a time from its
page (**Extract again**), or all of them:

```sh
make console c="app:reextract --dry-run"   # list recipes made with another model
make console c="app:reextract"             # queue the unreviewed ones; --reviewed also redoes
                                           # reviewed ones (their edits are replaced)
```

What it all costs is in **Settings → AI costs** (prices in `config/services.yaml`, `app.llm_prices`).

Useful commands (`make` lists all of them):

| Command | What it does |
|---|---|
| `make logs s=worker` | follow one service's logs |
| `make console c="debug:router"` | run a Symfony console command |
| `make migration` / `make migrate` | create / apply Doctrine migrations |
| `make api-types` | regenerate `frontend/src/api/schema.d.ts` from the OpenAPI spec |
| `make check` | PHPStan, PHP-CS-Fixer, PHPUnit, TS typecheck, oxlint |

To offer **Continue with Google** on the sign-in page, put `GOOGLE_CLIENT_ID` and
`GOOGLE_CLIENT_SECRET` in `backend/.env.local` and run `docker compose restart php`. Setting up the
client is in [docs/DEPLOY.md](docs/DEPLOY.md#sign-in-with-google).

The API docs (Swagger UI) are at https://localhost/api/docs once you're signed in.
