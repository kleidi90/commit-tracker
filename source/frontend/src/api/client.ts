import type { AuthorCommits, Contributor, ContributorQuery, Paginated, Repository } from './types'

/** Every failed request becomes an ApiError with a message that is safe to show. */
export class ApiError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

type QueryValue = string | number | null | undefined
export type Query = Record<string, QueryValue>

function buildUrl(path: string, query?: Query): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      params.set(key, String(value))
    }
  }
  const search = params.toString()
  return search ? `${path}?${search}` : path
}

/**
 * Reads the message from our error shape, {"error": {"message": "..."}}. Anything else,
 * such as Symfony's HTML page for an unexpected 500, gets a generic message.
 */
function errorMessage(body: unknown, response: Response): string {
  const message = (body as { error?: { message?: string } } | null)?.error?.message
  return message || `Request failed (${response.status} ${response.statusText})`
}

async function request<T>(method: string, path: string, options: { query?: Query; body?: unknown } = {}): Promise<T> {
  const hasBody = options.body !== undefined
  let response: Response

  try {
    response = await fetch(buildUrl(`/api${path}`, options.query), {
      method,
      headers: {
        Accept: 'application/json',
        ...(hasBody ? { 'Content-Type': 'application/json' } : {}),
      },
      body: hasBody ? JSON.stringify(options.body) : undefined,
    })
  } catch {
    throw new ApiError('Could not reach the server. Check that the backend is running.', 0)
  }

  // 204 and non-JSON responses have no usable body.
  const body: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    throw new ApiError(errorMessage(body, response), response.status)
  }

  return body as T
}

export const api = {
  get: <T>(path: string, query?: Query) => request<T>('GET', path, { query }),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, { body }),
}

export function listRepositories(): Promise<Repository[]> {
  return api.get<Repository[]>('/repositories')
}

export function getRepository(id: string | number): Promise<Repository> {
  return api.get<Repository>(`/repositories/${id}`)
}

/** Adds the repository (or finds it, in any letter case) and queues an import. */
export function addRepository(fullName: string): Promise<Repository> {
  return api.post<Repository>('/repositories', { repository: fullName })
}

export function syncRepository(id: number): Promise<Repository> {
  return api.post<Repository>(`/repositories/${id}/sync`)
}

export function listContributors(repositoryId: string | number, query: ContributorQuery): Promise<Paginated<Contributor>> {
  return api.get<Paginated<Contributor>>(`/repositories/${repositoryId}/contributors`, { ...query })
}

export function listAuthorCommits(repositoryId: string | number, authorId: string | number, page: number): Promise<AuthorCommits> {
  return api.get<AuthorCommits>(`/repositories/${repositoryId}/contributors/${authorId}/commits`, { page })
}
