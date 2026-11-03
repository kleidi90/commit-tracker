<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQueryValue } from 'vue-router'
import { getRepository, listContributors } from '../api/client'
import type { ContributorSort, SortDirection } from '../api/types'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import LoadingState from '../components/LoadingState.vue'
import PaginationNav from '../components/PaginationNav.vue'
import { useAsyncState } from '../composables/useAsyncState'
import { isSyncActive, usePolling } from '../composables/usePolling'
import { formatDateTime } from '../format'

const props = defineProps<{ repositoryId: string }>()
const route = useRoute()
const router = useRouter()

const PER_PAGE = 20
const SORTS: ContributorSort[] = ['commits', 'name', 'lastCommit']

function single(value: LocationQueryValue | LocationQueryValue[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value) ?? ''
}

// The query string is the single source of truth, so a refresh or a shared link keeps the view.
const query = computed(() => {
  const sort = single(route.query.sort) as ContributorSort
  return {
    search: single(route.query.search),
    from: single(route.query.from),
    to: single(route.query.to),
    sort: SORTS.includes(sort) ? sort : 'commits',
    dir: (single(route.query.dir) === 'asc' ? 'asc' : 'desc') as SortDirection,
    page: Math.max(1, Number.parseInt(single(route.query.page), 10) || 1),
  }
})

const state = useAsyncState(
  async () => {
    const [repository, contributors] = await Promise.all([
      getRepository(props.repositoryId),
      listContributors(props.repositoryId, { ...query.value, perPage: PER_PAGE }),
    ])
    return { repository, contributors }
  },
  { isEmpty: (result) => result.contributors.total === 0 },
)

// Reload when the query changes, but not while navigating away to another screen.
watch(
  () => route.name === 'contributors' && route.fullPath,
  (path) => {
    if (path) {
      void state.run()
    }
  },
)

// While an import runs, the counts grow; refresh until it has finished.
usePolling(state.data, (result) => isSyncActive(result.repository), () => state.run({ silent: true }))

const search = ref('')
const from = ref('')
const to = ref('')
const filterError = ref<string | null>(null)
watch(
  query,
  (q) => {
    search.value = q.search
    from.value = q.from
    to.value = q.to
  },
  { immediate: true },
)

const hasFilters = computed(() => query.value.search !== '' || query.value.from !== '' || query.value.to !== '')

/** Any change other than the page itself starts again at page 1. Defaults stay out of the URL. */
function navigate(changes: Partial<typeof query.value>): void {
  const next: Record<string, string | number> = { ...query.value, page: 1, ...changes }
  const cleaned = Object.fromEntries(
    Object.entries(next)
      .filter(([key, value]) => value !== '' && !(key === 'page' && value === 1))
      .map(([key, value]) => [key, String(value)]),
  )
  void router.push({ query: cleaned })
}

function applyFilters(): void {
  // ISO dates compare correctly as strings.
  if (from.value !== '' && to.value !== '' && from.value > to.value) {
    filterError.value = 'The start date must not be after the end date.'
    return
  }
  filterError.value = null
  navigate({ search: search.value.trim(), from: from.value, to: to.value })
}

function clearFilters(): void {
  filterError.value = null
  navigate({ search: '', from: '', to: '' })
}

function sortBy(sort: ContributorSort): void {
  const dir: SortDirection =
    query.value.sort === sort ? (query.value.dir === 'asc' ? 'desc' : 'asc') : sort === 'name' ? 'asc' : 'desc'
  navigate({ sort, dir })
}

function ariaSort(sort: ContributorSort): 'ascending' | 'descending' | 'none' {
  if (query.value.sort !== sort) {
    return 'none'
  }
  return query.value.dir === 'asc' ? 'ascending' : 'descending'
}

function arrow(sort: ContributorSort): string {
  return query.value.sort !== sort ? '' : query.value.dir === 'asc' ? ' ▲' : ' ▼'
}
</script>

<template>
  <p><RouterLink to="/">Back to repositories</RouterLink></p>
  <h1>Contributors</h1>
  <p class="text-muted">
    <template v-if="state.data.value">
      {{ state.data.value.repository.fullName }}, {{ state.data.value.repository.commitCount }} commits imported
    </template>
    <template v-else>Repository #{{ props.repositoryId }}</template>
  </p>
  <p v-if="state.data.value && isSyncActive(state.data.value.repository)" role="status">
    A sync is running. This page updates when new commits arrive.
  </p>

  <form class="filters" novalidate @submit.prevent="applyFilters">
    <label>
      Search
      <input v-model="search" type="search" placeholder="Name, login or email" />
    </label>
    <label>
      From
      <input v-model="from" type="date" :max="to || undefined" />
    </label>
    <label>
      To
      <input v-model="to" type="date" :min="from || undefined" />
    </label>
    <button type="submit">Apply</button>
    <button v-if="hasFilters" type="button" @click="clearFilters">Clear</button>
  </form>
  <p v-if="filterError" class="text-error" role="alert">{{ filterError }}</p>

  <LoadingState v-if="state.loading.value && !state.data.value" label="Loading contributors…" />
  <ErrorState v-else-if="state.error.value" :message="state.error.value.message" @retry="state.run()" />
  <EmptyState v-else-if="state.isEmpty.value && hasFilters" title="No contributors match these filters">
    <p>Widen the search or the date range.</p>
  </EmptyState>
  <EmptyState v-else-if="state.isEmpty.value" title="No commits imported yet">
    <p>Contributors appear once an import has stored commits. Start one from the repository list.</p>
  </EmptyState>
  <template v-else-if="state.data.value">
    <p v-if="state.loading.value" class="text-muted" role="status">Updating…</p>
    <table class="data-table">
      <thead>
        <tr>
          <th scope="col" :aria-sort="ariaSort('name')">
            <button type="button" class="link-button" @click="sortBy('name')">Contributor{{ arrow('name') }}</button>
          </th>
          <th scope="col" class="num" :aria-sort="ariaSort('commits')">
            <button type="button" class="link-button" @click="sortBy('commits')">Commits{{ arrow('commits') }}</button>
          </th>
          <th scope="col" :aria-sort="ariaSort('lastCommit')">
            <button type="button" class="link-button" @click="sortBy('lastCommit')">
              Last commit{{ arrow('lastCommit') }}
            </button>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="contributor in state.data.value.contributors.items" :key="contributor.authorId">
          <td>
            <RouterLink
              :to="{
                name: 'contributor-detail',
                params: { repositoryId: props.repositoryId, authorId: contributor.authorId },
              }"
            >
              {{ contributor.displayName }}
            </RouterLink>
            <br v-if="contributor.email && contributor.email !== contributor.displayName" />
            <small v-if="contributor.email && contributor.email !== contributor.displayName" class="text-muted">
              {{ contributor.email }}
            </small>
          </td>
          <td class="num">{{ contributor.commitCount }}</td>
          <td>{{ formatDateTime(contributor.lastCommitAt) }}</td>
        </tr>
      </tbody>
    </table>
    <PaginationNav
      :page="state.data.value.contributors.page"
      :per-page="state.data.value.contributors.perPage"
      :total="state.data.value.contributors.total"
      @change="(page) => navigate({ page })"
    />
  </template>
</template>
