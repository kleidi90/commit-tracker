# Decisions

## Stack

- **Symfony 7.4 LTS on PHP 8.4.** Symfony is my main stack, and the long term support release keeps the project on a maintained version.
- **PostgreSQL 16.** `INSERT ... ON CONFLICT DO NOTHING` makes idempotent imports a database guarantee rather than application logic, and partial unique indexes let the database enforce "one active sync per repository".
- **Vue 3 with TypeScript.** The frontend framework I am fastest in. The structure stays simple: a typed API client, one composable for async state and one view per screen.
- **Imports run asynchronously** in a Symfony Messenger worker. The API answers immediately with a queued sync run, and a 1000 commit import never blocks a request.
- **Doctrine uses PHP 8.4 native lazy objects.** symfony/var-exporter 8 no longer ships LazyGhost, so the old proxy setting failed on start.
- **Commits and authors go through Doctrine DBAL, not the ORM.** The ORM has no insert-or-ignore, a duplicate key closes the EntityManager, and check-then-insert races between workers. Author and Commit are read-only mappings; Repository and SyncRun use the ORM, where the per-object rules live.
- **PHPStan level 8 and PHP-CS-Fixer (@Symfony)** run over src and tests.

## Data model

Four tables: `repositories`, `sync_runs`, `authors`, `commits`.

- **Repository identity is case-insensitive.** GitHub treats `Symfony/symfony` and `symfony/symfony` as the same repository, so uniqueness is on `(provider, lookup_key)`, where `lookup_key` is the lowercased `owner/name`. The display spelling is kept separately.
- **Author identity**, first rule that applies: `github:<numeric user id>` when GitHub links the commit to an account; otherwise `email:<email>`; otherwise `name:<name>`; otherwise `unknown`. Values are trimmed, lowercased and whitespace-collapsed, and capped at 300 characters so an overlong git email cannot fail an import. The numeric id, not the login, because logins can be renamed. The name fallback stops every commit without an email from merging into one author that looks like a real person.
- **The raw author name and email are stored on every commit**, exactly as git recorded them. The grouping into authors can change later without a re-import.
- **Author date, not committer date.** A rebase changes the committer date; the author date is when the work was written.
- **`sha` is `varchar(64)`**, so SHA-256 repositories fit as well as today's 40 character SHA-1.
- **All timestamps are `timestamptz`.**
- **Plural table names.** `commit` is an SQL keyword, and plural names avoid quoting it in raw SQL.
- **`sync_runs.created_at` was added while building.** A run stuck in `queued` has no `started_at`, so detecting stale runs needs the time it was queued.
- **One active sync per repository** is enforced by a partial unique index on `sync_runs (repository_id)` where the status is `queued` or `running`. A second re-sync click gets a 409 instead of starting a second import.
- **`commits_fetched` and `commits_inserted` are counted separately**, so a re-sync of an unchanged repository visibly shows "1000 fetched, 0 new".


## Design questions

### What does the interface do while a long import is running?

The API returns 202 with the queued sync run. The repository list polls every two seconds while any run is queued or running, and shows the number of commits fetched so far. Polling stops once nothing is active. The contributors page also polls while a sync runs and says so. The re-sync button is disabled while a run is active, and a second request gets 409.

### What happens if the GitHub API rate-limits you halfway through an import?

Each page is stored in its own transaction, so pages fetched before the limit are kept. The run is marked `partial` with the reset time from `X-RateLimit-Reset`, and the interface says when a re-sync will work. Re-syncing is safe because imports are idempotent. No automatic retry yet (see "next"). GitHub's secondary limit (`Retry-After`) is handled the same way. Without a token GitHub allows 60 requests per hour (a 1000 commit import costs 10); setting `GITHUB_TOKEN` raises that to 5000.

### Is the same person appearing under two email addresses one contributor or two?

One, when GitHub links both emails to the same account, because the identity is the account id. Two, when the commits are not linked to an account, because nothing reliable connects them. Guessing by name would merge different people who share a name, which is worse than a split. A `.mailmap` style override would be the next step. Commits with no email at all are grouped by name, because otherwise they would all merge into one fake author. That can merge two people with the same name, but only for this rare case.

### What does "last synced" mean if the last sync failed partway?

`last_synced_at` is the end of the last fully successful sync and never moves on a partial or failed one. The interface shows the latest attempt and its outcome next to it, so the user sees both "last complete sync" and "last attempt: rate limited at 14:05".



## Edge cases

### Import
- **Which branch?** The default branch only, which is what the commits endpoint returns without a `sha` parameter.
- **Repository not found or private:** GitHub answers 404 in both cases. The run is marked `failed` with a clear message.
- **Force-pushed history:** commits that disappear from GitHub stay in the database. They really were authored, and deleting them would make re-syncs destructive.
- **The 1000 commit limit applies per sync:** each sync fetches the newest 1000. Older commits stored by earlier syncs stay.
- **Stuck runs:** an active run older than 15 minutes counts as abandoned. The next sync marks it failed ("Abandoned") and starts a new one. A full 1000 commit import takes seconds, so 15 minutes is generous. A message for a run that is no longer active is skipped by the worker.
- **Co-authored-by trailers** are ignored; only the main author counts.
- **Adding a repository that already exists** (in any letter case) queues a re-sync instead of returning an error.
- **Empty repository:** GitHub answers 409; the sync succeeds with 0 commits.
- **Bad token:** GitHub's 401 becomes a failed run that says to check GITHUB_TOKEN.
- **Overlong git values** (name, email, URL) are cut to the column size instead of failing the page.

### Contributors
- **Date range in UTC days,** and the end date is inclusive: `to=2026-02-28` includes that whole day.
- **Search** matches login, name and email, case-insensitive; `%` and `_` are taken literally.
- **Sort** by commits, name or last commit, from a fixed list; ties break on author id so a row never appears on two pages.
- **Display name** is the login, else the git name, else the email.
- **Page size** 20 by default, at most 100.
- **The commits endpoint returns the author too,** so the detail screen can name them in one request.

## Out of scope for now

- **Frontend tests.** The risky logic (deduplication, partial failure, the contributors query) lives in the backend and is tested there; the screens were checked by hand in the browser.
- **Authentication.**
- **Deleting a repository.** A mistyped repository stays in the list.
- **Pagination of the repository list.**
- **Automatic retry after a rate limit.**
- **Branch selection.**
- **Co-authored-by trailers.**

## Roadmap

- Retry rate-limited imports automatically after the reset time, using a Messenger `DelayStamp`.
- Precomputed contributor statistics for very large repositories: a per-author daily count table updated during import, plus cursor pagination instead of `OFFSET`.
- Server-sent events or WebSockets instead of polling.
- A `.mailmap` style way to merge author identities by hand.
- Authentication.
