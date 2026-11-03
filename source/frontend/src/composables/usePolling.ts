import { onUnmounted, watch, type Ref } from 'vue'
import type { Repository } from '../api/types'

const INTERVAL_MS = 2000

export function isSyncActive(repository: Repository): boolean {
  return repository.latestRun?.status === 'queued' || repository.latestRun?.status === 'running'
}

/**
 * Reloads silently every two seconds while `shouldPoll` says a sync is still active,
 * and stops on its own once nothing is. Each load schedules the next one, so slow
 * responses never pile up.
 */
export function usePolling<T>(
  data: Ref<T | undefined>,
  shouldPoll: (data: T) => boolean,
  reload: () => Promise<void>,
): void {
  let timer: ReturnType<typeof setTimeout> | undefined

  watch(data, (value) => {
    clearTimeout(timer)
    if (value !== undefined && shouldPoll(value)) {
      timer = setTimeout(() => void reload(), INTERVAL_MS)
    }
  })

  onUnmounted(() => clearTimeout(timer))
}
