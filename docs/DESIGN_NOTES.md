# Design questions

## 1. How I debugged this project, and with which tools

I debugged through Claude Code: I described the problem or pasted the error, the agent ran the commands inside the Docker containers and read the output, and I decided on the fix. The tools it used:

- **Container logs first.** `docker compose logs php` and `docker compose logs -f worker`. The first start failed with "Symfony LazyGhost is not available": the stack trace pointed at Doctrine's proxy setting, and `composer show symfony/var-exporter` showed version 8, which dropped LazyGhost. The fix was PHP 8.4 native lazy objects (`enable_native_lazy_objects: true`).
- **Symfony console.** `doctrine:schema:validate` compares the entities with the real schema. It is how the partial unique index predicate was found: PostgreSQL stores `status IN (...)` in a normalised form, so the mapping has to use exactly that string or Doctrine reports a diff forever. `messenger:failed:show` shows messages that failed after all retries.
- **The database directly.** `psql` to count commits and authors after a re-import, and to look at `sync_runs` when a run ended partial or failed. The run itself stores the error and the rate limit reset time, so most import problems are visible without logs.
- **The API with curl,** to check status codes and error shapes (202, 409, 422, 404) independently of the frontend.
- **Browser developer tools,** the network tab, to watch polling and the query string on the contributors page.
- **Tests as a debugging tool.** A failing integration test against `app_test` reproduces an import problem in seconds, with a fake provider instead of GitHub.
- **Static analysis.** PHPStan level 8 found two real issues (missing generic types on `InputBag`, a redundant `array_values`).
- **Audits.** I asked the agent for whole-repository reviews. They found real bugs before they shipped, for example an overlong git email that would fail the same import page on every sync.

## 2. How I test this project, and what I left untested

**Approach**
- Test where data can go wrong: the import, deduplication, partial failure and the contributors query. Data model and correctness matter most in this project.
- **Idempotent re-import (the core guarantee).** `ImportServiceTest` runs the real import twice with the same commits against PostgreSQL and asserts that the commit and author row counts are unchanged, and that the second run reports "6 fetched, 0 new". It exercises the real `INSERT ... ON CONFLICT DO NOTHING` and the author upsert, not a mock, so it proves the guarantee the database gives. Related tests check that a re-sync still inserts genuinely new commits, that differently cased or padded emails do not create a second author, and that re-adding a repository in another letter case reuses it.
- A real PostgreSQL database (`app_test`), not mocks. The guarantees live in SQL (`ON CONFLICT`, the partial unique index, `GROUP BY`), so a mock would prove nothing.
- Never the real GitHub API. A fake provider feeds the import; GitHub's JSON mapping is tested separately with Symfony's `MockHttpClient`. Tests are fast and never hit rate limits.
- HTTP contract tests pin status codes and response shapes, because `types.ts` is the contract with the frontend.

**Left untested, and why**
- Frontend: no tests. The risky logic is in the backend; the views are thin. The two composables (`useAsyncState`, `usePolling`) would be next.
- The state components and pagination: one line of logic each; a test would repeat the template.
- Browser end to end tests: too heavy for the value they add here; the backend tests cover the risky logic.
- Concurrency between two real workers: the guarantees come from database constraints, which are tested directly, not by racing processes.
- The real GitHub API: covered by the mapping tests on recorded response shapes.

## 3. Supporting GitLab and Bitbucket: what changes in the code and what doesn't

**Does not change**
- The schema. `repositories` and `authors` already have a `provider` column, and uniqueness is per provider.
- The import. `ImportService` picks the provider by name from every class tagged `app.commit_provider`, and only sees `CommitDto` objects. Pages, transactions, deduplication and partial failure stay the same.
- Author identity. The account rule is already `<provider>:<account id>`, so `gitlab:123` never collides with `github:123`.
- Sync runs, rate limit handling (a provider throws `RateLimitedException` with a reset time), the contributors query and all screens.

**Changes**
- One class per host in `src/Provider/GitLab/` and `src/Provider/Bitbucket/`, implementing `CommitProviderInterface`, plus a scoped HTTP client and token each. Only these classes know the host's JSON:
  - GitLab: `GET /projects/:id/repository/commits`, page based like GitHub; rate limit via `429` and `RateLimit-Reset`. Commits carry no account id, so identity falls back to the email rule.
  - Bitbucket: `GET /2.0/repositories/{workspace}/{repo}/commits`, cursor pagination through the `next` URL (the interface yields pages, so that fits); the author arrives as one `"Name <email>"` string to parse, and a linked account has a UUID.
- `RepoRef::fromString()` assumes GitHub: it hardcodes `github` and accepts exactly `owner/repo`. GitLab allows nested groups (`group/subgroup/project`), so the parsing and the `owner` column length change.
- The API's add endpoint takes a provider, and the add form gets a provider select. `types.ts` already returns `provider` on each repository.

The same person on GitHub and GitLab stays two authors. Contributors are listed per repository, so that only matters for cross-provider statistics, which nothing needs yet.


## 4. A repository with 500,000 commits makes the contributors page unusable: where to look first, and the options

Where to look first. Ask PostgreSQL how it runs the query, with EXPLAIN ANALYZE. It shows which step takes the time:
- the counting of all commits;
- the total count for the paginator;
- the text search (ILIKE '%ada%' can't use a normal index);
- deep pages (OFFSET 10000 still reads the 10,000 rows it skips).

1. **Better indexes.** The date and author index already exists. Add a trigram index (`pg_trgm`) so the text search stops scanning every author.
2. **Count ahead of time (the main fix).** Keep a small table: "author X made N commits in repository Y on day Z". Update it while importing, in the same transaction, adding only the commits actually inserted (`INSERT ... ON CONFLICT DO NOTHING RETURNING`), so a re-sync never counts a commit twice. The page then adds up a few thousand daily totals instead of counting 500,000 commits. The date filter still works because the API already filters by whole days.
3. **Cheaper paging.** Use a cursor, "give me authors after this one", instead of skipping rows with `OFFSET`, and don't recount the total on every click.
   - Request: `GET /contributors?after=eyJjIjo0MiwiaWQiOjEyM30` (the last row seen: commit count 42, author id 123).
   - SQL: `... GROUP BY a.id HAVING (count(*), a.id) < (42, 123) ORDER BY count(*) DESC, a.id LIMIT 20`.
   - The response returns `nextCursor` instead of `page` and `total`. On a computed count the cursor saves the skipping, not the counting, so it pays off most on top of the daily table from option 2.

## 5. What I would not ship to production as-is, and why

- **No authentication.** Anyone who can reach the API can add repositories and start imports, which also spends the GitHub token's quota. Production needs login and per-user or per-team repositories.
- **The runtime configuration is a development setup.** The containers run with `APP_ENV=dev` and debug on, `APP_SECRET` is a committed default, Composer installs dependencies when the container starts, and the backend code is a bind mount. Production needs an image with dependencies baked in, `APP_ENV=prod`, secrets from the environment and opcache tuned.
- **Abandoned runs are only cleaned up lazily.** A run whose worker died stays "running" until someone re-syncs that repository after 15 minutes. Production needs a scheduled job that marks stale runs failed, plus alerting on the failed messages queue.
- **No automatic retry after a rate limit,** and no observability beyond container logs: no Monolog channel, no metrics on import duration or failures.
- **Polling every two seconds per open tab** is fine for a few users; at scale I would switch to server-sent events.

The import logic and the schema I would ship: idempotency and partial failure are enforced by the database and covered by tests.
