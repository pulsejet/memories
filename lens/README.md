# Memories Lens

Natural-language search for your Memories photos. Index your library once,
then find pictures by describing them — "dog on the beach", "wedding",
"red car at sunset" — in any language.

## Setup

### 1. Nextcloud service account

Create a dedicated **non-admin** user (e.g. `lens`), then mint its token:

```bash
occ user:add lens
occ user:auth-tokens:add lens
occ config:system:set memories.lens.service_user --value="lens" --type=string
```

Save the username + token — you need them below.

### 2. Docker Compose

Add to your `compose.yaml` (fill in the values):

```yaml
services:
  lens:
    build: ./apps/memories/lens
    restart: unless-stopped
    ports:
      - "127.0.0.1:47789:47789"
    volumes:
      - lens-models:/app/models
    environment:
      MODEL_CACHE_DIR: "/app/models"
      NEXTCLOUD_URL: "https://cloud.example.com"
      NC_USER: "lens"
      NC_TOKEN: "token-from-step-1"
      QDRANT_URL: "http://qdrant:6333"
      EMBEDDING_MODEL_ID: "google/siglip2-base-patch16-256"
      EMBEDDING_MODEL_REVISION: "pinned-commit-sha-of-the-model"
    depends_on:
      - qdrant

  qdrant:
    image: qdrant/qdrant:latest
    restart: unless-stopped
    volumes:
      - lens-qdrant:/qdrant/storage

volumes:
  lens-models:
  lens-qdrant:
```

Start it:

```bash
docker compose up -d lens qdrant
```

## Development

```bash
make install
```

Create `.env` with the same values as the compose environment above, except
`QDRANT_URL=http://localhost:6333`, then:

```bash
.venv/bin/uvicorn app:app --port 47789
```

Lint:

```bash
make lint
```

Style: short docstrings on every module/function, blank line after each
docstring, blank lines between logical blocks, keep it flat. Calls that
don't fit on one line break with one argument per line, naming positional
args as kwargs. `make lint` (pylint + flake8, max line 120) must be clean.
