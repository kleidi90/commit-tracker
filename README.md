# Commit Tracker

Track contributor activity across GitHub repositories. Add a repository as `owner/repo`, and the app imports its most recent commits in the background, then shows who contributed, how much and when.

The project is deliberately small. Most of the effort went into a correct data model, safe re-imports and honest handling of partial failure.

## Features

- **Add a repository.** Imports up to 1000 of the most recent commits (hash, author, date) from the GitHub API. Re-importing never creates duplicates.
- **Repository list.** Every imported repository with its commit count, its last successful sync and the outcome of the latest attempt. Each one can be re-synced; the list updates live while an import runs.
- **Contributors.** Authors and their commit counts per repository: searchable, sortable, filterable by date range and paginated, all on the server. Filters live in the URL, so a refresh keeps them.
- **Contributor detail.** One author's commits, paginated, each linking to the commit on GitHub.
- **Explicit loading, empty and error states** on every screen.

## How it works

- **Background imports.** The API answers `202` with a queued sync run; a Symfony Messenger worker does the import. A partial unique index allows only one active sync per repository, so a double click gets a `409`.
- **Idempotent by construction.** Each page of commits is one `INSERT ... ON CONFLICT DO NOTHING`, and the affected row count becomes the "new commits" figure. A re-sync of an unchanged repository shows "1000 fetched, 0 new".
- **Partial failure is a first class outcome.** Every page is stored in its own transaction. A GitHub rate limit halfway through keeps the pages already stored, marks the run `partial` and shows when a retry will work. "Last synced" only moves on a fully successful sync.
- **Stable author identity.** Authors are keyed on the GitHub account id, then email, then name, so renamed logins and commits without an email are handled.
- **Ready for other providers.** Only one class knows GitHub's JSON; the import works on provider neutral DTOs, and the schema already carries a `provider` column.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.4, Symfony 7.4 LTS, Doctrine ORM 3 and DBAL, Symfony Messenger |
| Database | PostgreSQL 16 |
| Frontend | Vue 3, TypeScript, Vite, Vue Router |
| Quality | PHPUnit against a real PostgreSQL test database, PHPStan level 8, PHP-CS-Fixer |
| Runtime | Docker Compose: `db`, `php`, `worker`, `web` (nginx) |

## Quick start

```bash
docker compose up --build
```

Then open http://localhost:8080. Tests, static checks, the optional GitHub token and frontend hot reload are described in [SETUP.md](SETUP.md).

## Status

A working side project, not production ready yet:

- No authentication. Anyone who can reach the API can add repositories and spend the GitHub token's quota.
- The Docker setup is for development: `APP_ENV=dev`, the backend code is mounted from the host and dependencies install on start.
- A run whose worker died is only cleaned up on the next re-sync of that repository.
- No automatic retry after a rate limit, and no metrics beyond container logs.

The roadmap is at the end of [DECISIONS.md](DECISIONS.md).

## Documentation

- [SETUP.md](SETUP.md): running, testing and useful commands.
- [DECISIONS.md](DECISIONS.md): the data model, the design questions and edge cases, what is out of scope and the roadmap.

## Layout

```
source/backend/    Symfony API, import worker, PHPUnit tests
source/frontend/   Vue 3 single page app
source/docker/     Dockerfiles, nginx config, PHP entrypoint
```
