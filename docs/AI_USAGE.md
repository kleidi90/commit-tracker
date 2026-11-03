# AI usage

## Tools

- Claude (chat): reading the requirements, planning, data model review, project skeleton.
- Claude Code (CLI, Claude Opus 5.5): implementing the backend import, API and frontend screens, writing the PHPUnit tests, running commands in the Docker stack (Composer, migrations, PHPUnit, PHPStan, PHP-CS-Fixer, frontend build) and debugging failures.
- Claude Code skills:
  - gstack `/browse`: a headless browser to click through the running app and check the screens end to end.
  - `ponytail`: whole-repository audits for bugs, risks, missing tests and code that could be deleted.
  - `caveman`: shorter chat replies, so long sessions stayed readable.
- Project instructions in `CLAUDE.md`: the data model, API conventions and rules the agent had to follow (for example one SQL statement per page, no real GitHub calls in tests, never edit my write-ups).

## How I split the work between myself and the agent

My rule: I own the decisions and the review, the agent writes first drafts and does the typing.

**What I did myself**
- Chose the stack and the overall design from options the agent laid out.
- Project context: a CLAUDE.md with the architecture rules, code quality rules and a definition of done, so every Claude Code session followed the same conventions.
- Made every call on the ambiguous parts of the requirements (author identity, what "last synced" means, rate limits as a partial sync, when a stuck sync counts as abandoned). Often I asked the agent for options and trade-offs first, then decided.
- Challenged the agent's first data model instead of accepting it (see the next section).
- Reviewed every change before committing it, and clicked through each screen's loading, empty and error states myself.
- Wrote the final versions of DECISIONS.md, CONCEPTUAL.md and this file. 

**What the agent did**
- Planning (Claude): read the requirements with me, proposed a work plan, a first data model and a list of edge cases.
- Scaffold (Claude): Docker setup, Symfony and Vue skeleton, entities and the migration, which it checked against a real PostgreSQL before handing it over.
- Implementation (Claude Code): one feature at a time.

**How I kept control**
- CLAUDE.md requires the agent to propose a plan before touching the import, the schema or the contributors query. I approved or changed each plan before any code was written.
- A change only counted as done when the tests passed and I had reviewed the diff.


## One thing the agent produced that I rejected or rewrote, and why

The first data model. The agent's first draft handled the happy path: one import at a time, clean data from GitHub. Before writing any code, I asked it to review the schema against what breaks in real use. I rejected the first version and adopted a revised one with these changes:

- **Concurrent syncs.** Nothing stopped a double click on "re-sync" from starting two imports of the same repository. A partial unique index now allows only one queued or running sync run per repository, and a second request gets a 409.
- **Status stored twice.** The sync status lived on both `repositories` and `sync_runs`, two sources of truth that drift apart when a worker crashes between updates. Now only `sync_runs` holds it; `repositories` keeps just `last_synced_at`, set only by a fully successful run.
- **Case-sensitive repository names.** GitHub treats `Symfony/symfony` and `symfony/symfony` as the same repository. The idempotency test would have passed while real users created duplicates. Uniqueness is now on a lowercased `lookup_key`.
- **Authors keyed on login.** Logins can be renamed. The identity is now GitHub's numeric user id, with the email as fallback.
- **Raw commit data thrown away.** Each commit now keeps the author name and email exactly as git recorded them, so the "one person, two emails" rule can change without a re-import.
- **Idempotency you could not see.** One counter could not tell "1000 new" from "1000 duplicates skipped". `commits_inserted` next to `commits_fetched` makes a re-sync visibly show "0 new".
- **Smaller fixes.** A second index for the date-filtered contributors query, `sha` as `varchar(64)` so SHA-256 repositories fit, and `timestamptz` for all dates.

Why it mattered: in this project the data model and correctness matter most, and each of these would have passed a happy-path test but failed in real use. My takeaway: the agent writes a plausible first draft fast, but I have to ask what breaks before building on it.


## Working log

| When | What the agent did | What I decided or changed |
|---|---|---|
| Planning | Proposed the first data model | Asked for a review; it found a race on concurrent re-syncs, sync status duplicated in two tables, case-sensitive repository names and login-based author identity. Accepted the revised model |
| Scaffold | Generated the Docker setup, Symfony and Vue skeleton, entities and migration | |
| Author identity | Checked the entities against the requirements: commits with an empty email would all merge into one author that looks like a real person | Chose the order `github:<id>`, then `email:`, then `name:`, then `unknown`, and had it added to the code |
| Docker | Diagnosed "Symfony LazyGhost is not available" on start: symfony/var-exporter 8 dropped LazyGhost. Switched Doctrine to PHP 8.4 native lazy objects and required PHP 8.4 | Reported the error; accepted the fix |
| Import | Wrote the GitHub provider, author identity upsert, import service (one transaction and one `INSERT ... ON CONFLICT` per page), sync starter with stale-run handling, and the repository API | Gave the agent the list of six classes to build (GitHubProvider, ImportService, AuthorIdentityResolver,  ImportRepositoryHandler, SyncStarter, RepositoryController), told it to adapt the tests where needed and not to touch any md file|
| Audit | Ran a whole-repository audit and listed findings by severity | Chose which findings to fix |
| Contributors | Wrote the contributors query (search, UTC date range, sort whitelist, pagination in SQL), the commits-per-author endpoint, and the three Vue screens with loading, empty and error states and polling during a sync. Checked the screens in a headless browser | Picked this from the audit |
| Tests | Wrote unit, integration and functional tests with a fake provider and `MockHttpClient`: idempotent re-import, rate limit on page 3, GitHub mapping, contributors query, API contract | Took care that the idempotency was covered by tests |
| Tooling | Added PHPStan (level 8, Symfony and Doctrine extensions) and PHP-CS-Fixer, and fixed what they found |  Requested the add of these tools|
| Lean pass | Audited for code that could be deleted. Removed unused entity getters and constructors (Author and Commit became read-only mappings), four unused Symfony packages, a duplicated client-side repository check, the API health footer and a duplicated 404 helper. Capped identity keys at 300 characters so an overlong git email cannot fail an import | Asked the agent to inspect the code for what could be made smaller, using the ponytail skill, as a plan only with no changes. Than acceptes some  |
