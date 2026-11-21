<!--
The page of tredmann/tory on Docker Hub. Paste this file as the repository overview.
Short description (100 characters at most):
Household inventory that answers one question: where is that thing? Laravel, Filament, Postgres.
-->

# Tory

A household inventory that answers one question: where is that thing?

![The overview page of Tory: search field, locations and the most recently added items](https://raw.githubusercontent.com/tredmann/tory/master/docs/images/overview.png)

You photograph an item, an AI model writes its title, description and search terms, and you put it on a spot such as a shelf in the cellar. Later you search by keyword or by meaning and Tory tells you where the item lies. The interface is German.

Source, documentation and issues: https://github.com/tredmann/tory

## Tags

- `1.0.3`, `1.0.4`, ...: one tag per release, for amd64 and arm64
- `latest`: the newest release
- `<version>-amd64`, `<version>-arm64`: the single-platform images behind a release; you normally do not need them

## What you need

- Postgres 18 with the pgvector extension, for example the image `pgvector/pgvector:pg18-trixie`
- A reverse proxy that terminates TLS. The container speaks plain HTTP on port 80, and the session cookie is only sent over HTTPS.
- An AI provider for object recognition: a local Ollama or an API key for Claude, OpenAI or Mistral. You set it up in the app under "Eigene KI".
- Optionally an embedding model with 1024 dimensions for search by meaning, such as `bge-m3` on Ollama. Without one, search works by keyword.

## Quick start

Generate the three secrets once and keep them. Changing `APP_KEY` later makes stored provider keys unreadable; changing the Passport keys ends every Claude connection.

```sh
docker run --rm --entrypoint php tredmann/tory artisan key:generate --show
openssl genrsa -out oauth-private.key 4096
openssl rsa -in oauth-private.key -pubout -out oauth-public.key
```

```yaml
services:
  tory:
    image: tredmann/tory:1.0.3
    stop_grace_period: 4m
    environment:
      APP_KEY: "base64:..."
      APP_URL: "https://tory.example.com"
      PASSPORT_PRIVATE_KEY: "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
      PASSPORT_PUBLIC_KEY: "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
      AUTO_MIGRATE: "true"
      TRUSTED_PROXIES: "172.16.0.0/12"
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: "5432"
      DB_DATABASE: tory
      DB_USERNAME: tory
      DB_PASSWORD: "change-me"
    volumes:
      - ./storage:/app/storage
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

  db:
    image: pgvector/pgvector:pg18-trixie
    environment:
      POSTGRES_DB: tory
      POSTGRES_USER: tory
      POSTGRES_PASSWORD: "change-me"
    volumes:
      - db-data:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -h 127.0.0.1 -U tory -d tory"]
      interval: 5s
      timeout: 5s
      retries: 10
    restart: unless-stopped

volumes:
  db-data:
```

Point your reverse proxy at port 80 of the `tory` service. The storage directory must be writable for uid 33 (`www-data`).

## First user

There is no registration page. Create the first user and make them administrator:

```sh
docker compose exec tory php artisan make:filament-user --name=Admin --email=you@example.com --password='a long password'
docker compose exec tory php artisan users:make-admin you@example.com
```

The user still has to confirm their email address. Unless you configure a mail server, the verification link is written to the container log on first login (`docker compose logs tory`). Further users are invited by an administrator in the app.

## Environment

| Variable | Required | Meaning |
|---|---|---|
| `APP_KEY` | yes | Encryption key; the container refuses to start without it |
| `APP_URL` | yes | Public address; also printed into the QR codes on spot labels |
| `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` | yes | PEM contents on one line with `\n`; used by the MCP server |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | yes | Postgres 18 with pgvector |
| `AUTO_MIGRATE` | no | `true` runs the migrations on start |
| `TRUSTED_PROXIES` | behind a proxy | Address range of your proxy; without it links come out as `http` |
| `MAIL_*` | no | Laravel mail settings; default is the log |

## License

MIT
