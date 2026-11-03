<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { listAuthorCommits } from '../api/client'
import type { AuthorCommits } from '../api/types'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import LoadingState from '../components/LoadingState.vue'
import PaginationNav from '../components/PaginationNav.vue'
import { useAsyncState } from '../composables/useAsyncState'
import { formatDateTime } from '../format'

const props = defineProps<{ repositoryId: string; authorId: string }>()
const route = useRoute()
const router = useRouter()

// The page lives in the query string, so a refresh stays on it.
const page = computed(() => Math.max(1, Number.parseInt(String(route.query.page ?? ''), 10) || 1))

const commits = useAsyncState<AuthorCommits>(() => listAuthorCommits(props.repositoryId, props.authorId, page.value), {
  isEmpty: (result) => result.total === 0,
})

watch(
  () => route.name === 'contributor-detail' && route.fullPath,
  (path) => {
    if (path) {
      void commits.run()
    }
  },
)

function goToPage(next: number): void {
  void router.push({ query: next === 1 ? {} : { page: String(next) } })
}
</script>

<template>
  <p>
    <RouterLink :to="{ name: 'contributors', params: { repositoryId: props.repositoryId } }">
      Back to contributors
    </RouterLink>
  </p>
  <h1>{{ commits.data.value?.author.displayName ?? 'Commits' }}</h1>
  <p v-if="commits.data.value" class="text-muted">
    <template v-if="commits.data.value.author.email">{{ commits.data.value.author.email }}, </template>
    {{ commits.data.value.total }} commits in this repository
  </p>

  <LoadingState v-if="commits.loading.value && !commits.data.value" label="Loading commits…" />
  <ErrorState v-else-if="commits.error.value" :message="commits.error.value.message" @retry="commits.run()" />
  <EmptyState v-else-if="commits.isEmpty.value" title="No commits for this author">
    <p>This contributor has no commits in this repository.</p>
  </EmptyState>
  <template v-else-if="commits.data.value">
    <p v-if="commits.loading.value" class="text-muted" role="status">Updating…</p>
    <table class="data-table">
      <thead>
        <tr>
          <th scope="col">Commit</th>
          <th scope="col">Authored</th>
          <th scope="col">Recorded as</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="commit in commits.data.value.items" :key="commit.sha">
          <td>
            <a :href="commit.htmlUrl" target="_blank" rel="noopener noreferrer"><code>{{ commit.sha.slice(0, 7) }}</code></a>
          </td>
          <td>{{ formatDateTime(commit.authoredAt) }}</td>
          <td>{{ commit.authorName }} &lt;{{ commit.authorEmail }}&gt;</td>
        </tr>
      </tbody>
    </table>
    <PaginationNav
      :page="commits.data.value.page"
      :per-page="commits.data.value.perPage"
      :total="commits.data.value.total"
      @change="goToPage"
    />
  </template>
</template>
