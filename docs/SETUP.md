# Setup

## Requirements

Docker with Compose v2 (Docker Desktop 4.27 or newer). Nothing else needs to be installed on your machine.

### On Windows

- Install Docker Desktop with the WSL 2 backend (the default).
- For the best speed, keep the project inside the Linux file system of WSL, for example `\\wsl$\Ubuntu\home\<you>\commit-tracker`, and run the commands below from a WSL terminal. Open it in VS Code with the WSL extension, or in PhpStorm through its WSL support.
- A project on `C:\` also works, but every PHP request reads its files through a slow Windows mount, so pages and tests run noticeably slower.
- The repository has a `.gitattributes` that keeps Linux line endings, so Git for Windows does not break the shell scripts.
- If port 8080 or 5433 is already taken, set `WEB_PORT` or `DB_PORT` in `.env`.

## Start the application

```bash
docker compose up --build
```

Then open http://localhost:8080.

The first start takes a few minutes: Composer installs the PHP dependencies into `source/backend/vendor`, then the database migrations run. The application is ready when `docker compose ps` shows the `php` service as healthy; the page then shows the repository list.

Services:

| Service | What it does |
|---|---|
| `db` | PostgreSQL 16 |
| `php` | Symfony API on PHP-FPM; installs dependencies and runs migrations on start |
| `worker` | Symfony Messenger consumer that runs the imports |
| `web` | nginx serving the built Vue app and passing `/api` to PHP |

## GitHub token (optional)

Without a token GitHub allows 60 requests per hour, and importing 1000 commits costs 10 requests. To raise the limit to 5000 per hour:

```bash
cp .env.example .env
# put a GitHub token with read-only access to public repositories into GITHUB_TOKEN
docker compose up -d
```

## Run the tests

```bash
docker compose exec php vendor/bin/phpunit
```

Tests run against a separate `app_test` database, which the `php` container creates on start.

## Static checks

```bash
docker compose exec php bin/console cache:warmup --env=test             # once, so PHPStan can read the test container
docker compose exec php vendor/bin/phpstan analyse --memory-limit=1G    # level 8
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff    # code style (@Symfony)
```

On a project stored on `C:\`, PHPStan takes several minutes because of the slow Windows mount.

## Frontend with hot reload

The Docker setup serves a production build. For frontend work with hot reload:

```bash
cd source/frontend
npm install
npm run dev
```

Open http://localhost:5173. API calls are proxied to the Docker stack on port 8080, so keep `docker compose up` running.

## Useful commands

```bash
docker compose logs -f worker                               # follow imports
docker compose logs -f php                                  # API errors (Symfony logs to stderr)
docker compose exec php bin/console messenger:failed:show   # inspect failed messages
docker compose exec php bin/console doctrine:schema:validate
docker compose exec db psql -U app app                      # SQL shell
docker compose down -v                                      # stop and delete all data
```

The database is also reachable from your machine on `localhost:5433` (user `app`, password `app`).
