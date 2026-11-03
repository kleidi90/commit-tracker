// The API contract as the screens need it. Shape the backend responses to match
// these types, not the other way round.

export type SyncStatus = 'queued' | 'running' | 'succeeded' | 'partial' | 'failed'

export interface SyncRun {
  id: number
  status: SyncStatus
  commitsFetched: number
  commitsInserted: number
  error: string | null
  rateLimitResetAt: string | null
  createdAt: string
  startedAt: string | null
  finishedAt: string | null
}

export interface Repository {
  id: number
  provider: string
  fullName: string
  commitCount: number
  /** Last fully successful sync. A partial or failed attempt never moves it. */
  lastSyncedAt: string | null
  /** The most recent attempt, whatever its outcome. */
  latestRun: SyncRun | null
}

export interface Contributor {
  authorId: number
  displayName: string
  login: string | null
  email: string | null
  commitCount: number
  lastCommitAt: string
}

export interface Commit {
  sha: string
  authoredAt: string
  authorName: string
  authorEmail: string
  htmlUrl: string
}

export interface Paginated<T> {
  items: T[]
  total: number
  page: number
  perPage: number
}

export interface Author {
  id: number
  displayName: string
  login: string | null
  email: string | null
}

/** One author's commits, with the author so the screen can name them. */
export interface AuthorCommits extends Paginated<Commit> {
  author: Author
}

export type SortDirection = 'asc' | 'desc'
export type ContributorSort = 'commits' | 'name' | 'lastCommit'

/** Everything here is applied server-side. */
export interface ContributorQuery {
  search?: string
  from?: string
  to?: string
  sort?: ContributorSort
  dir?: SortDirection
  page?: number
  perPage?: number
}
