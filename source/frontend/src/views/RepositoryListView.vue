<script setup lang="ts">
import { ref } from 'vue'
import { addRepository, listRepositories, syncRepository } from '../api/client'
import type { Repository, SyncRun } from '../api/types'
import EmptyState from '../components/EmptyState.vue'
import ErrorState from '../components/ErrorState.vue'
import LoadingState from '../components/LoadingState.vue'
import { useAsyncState } from '../composables/useAsyncState'
import { isSyncActive, usePolling } from '../composables/usePolling'
import { formatDateTime } from '../format'

const repositories = useAsyncState<Repository[]>(listRepositories, {
  isEmpty: (items) => items.length === 0,
})

// Imports run in the background worker: keep the list fresh until every sync has finished.
usePolling(repositories.data, (items) => items.some(isSyncActive), () => repositories.run({ silent: true }))

const input = ref('')
const inputError = ref<string | null>(null)
const adding = ref(false)

async function submit(): Promise<void> {
  inputError.value = null
  adding.value = true
  try {
    // The backend validates owner/repo and answers 422 with a message meant for the user.
    await addRepository(input.value.trim())
    input.value = ''
    await repositories.run({ silent: true })
  } catch (e) {
    // 409 (already syncing) and 422 (invalid) both come with a message meant for the user.
    inputError.value = (e as Error).message
  } finally {
    adding.value = false
  }
}

const syncingId = ref<number | null>(null)
const syncError = ref<string | null>(null)

async function resync(repository: Repository): Promise<void> {
  syncingId.value = repository.id
  syncError.value = null
  try {
    await syncRepository(repository.id)
    await repositories.run({ silent: true })
  } catch (e) {
    syncError.value = `${repository.fullName}: ${(e as Error).message}`
  } finally {
    syncingId.value = null
  }
}

function describeRun(run: SyncRun | null): string {
  if (run === null) {
    return 'Never synced'
  }
  switch (run.status) {
    case 'queued':
      return 'Queued, waiting for the worker…'
    case 'running':
      return `Importing… ${run.commitsFetched} commits fetched so far`
    case 'succeeded':
      return `Succeeded: ${run.commitsFetched} fetched, ${run.commitsInserted} new`
    case 'partial':
      return `Stopped early: ${run.commitsFetched} fetched, ${run.commitsInserted} new, kept.`
    case 'failed':
      return 'Failed'
  }
}
</script>

<template>
  <h1>Repositories</h1>

  <form class="add-form" novalidate @submit.prevent="submit">
    <label for="repository">Add a GitHub repository</label>
    <div class="add-form-row">
      <input
        id="repository"
        v-model="input"
        type="text"
        placeholder="symfony/symfony"
        autocomplete="off"
        :aria-invalid="inputError !== null"
        aria-describedby="repository-error"
        :disabled="adding"
        @input="inputError = null"
      />
      <button type="submit" :disabled="adding">{{ adding ? 'Adding…' : 'Import' }}</button>
    </div>
    <p v-if="inputError" id="repository-error" class="text-error">{{ inputError }}</p>
  </form>

  <LoadingState v-if="repositories.loading.value && !repositories.data.value" label="Loading repositories…" />
  <ErrorState
    v-else-if="repositories.error.value"
    :message="repositories.error.value.message"
    @retry="repositories.run()"
  />
  <EmptyState v-else-if="repositories.isEmpty.value" title="No repositories yet">
    <p>Add one above to import its most recent commits.</p>
  </EmptyState>
  <template v-else-if="repositories.data.value">
    <p v-if="syncError" class="text-error" role="alert">{{ syncError }}</p>
    <table class="data-table">
      <thead>
        <tr>
          <th scope="col">Repository</th>
          <th scope="col" class="num">Commits</th>
          <th scope="col">Last complete sync</th>
          <th scope="col">Latest attempt</th>
          <th scope="col"><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="repository in repositories.data.value" :key="repository.id">
          <td>
            <RouterLink :to="{ name: 'contributors', params: { repositoryId: repository.id } }">
              {{ repository.fullName }}
            </RouterLink>
          </td>
          <td class="num">{{ repository.commitCount }}</td>
          <td>{{ formatDateTime(repository.lastSyncedAt) }}</td>
          <td>
            <span :class="{ 'text-error': repository.latestRun?.status === 'failed' }">
              {{ describeRun(repository.latestRun) }}
            </span>
            <template v-if="repository.latestRun && !isSyncActive(repository)">
              <br />
              <small class="text-muted">{{ formatDateTime(repository.latestRun.finishedAt) }}</small>
            </template>
            <template v-if="repository.latestRun?.error">
              <br />
              <small class="text-muted">{{ repository.latestRun.error }}</small>
            </template>
            <template v-if="repository.latestRun?.rateLimitResetAt">
              <br />
              <small class="text-muted">
                Re-sync after {{ formatDateTime(repository.latestRun.rateLimitResetAt) }}.
              </small>
            </template>
          </td>
          <td>
            <button
              type="button"
              :disabled="isSyncActive(repository) || syncingId === repository.id"
              @click="resync(repository)"
            >
              {{ isSyncActive(repository) ? 'Syncing…' : 'Re-sync' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </template>
</template>
