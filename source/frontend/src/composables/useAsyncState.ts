import { computed, ref, shallowRef } from 'vue'

export interface AsyncStateOptions<T> {
  /** Decides when loaded data counts as empty, for example a list with no items. */
  isEmpty?: (data: T) => boolean
  /** Load as soon as the component sets up. Defaults to true. */
  immediate?: boolean
}

/**
 * One consistent loading / empty / error / data state for every screen.
 *
 * Only the latest call wins, so a slow response for an old filter can never
 * overwrite a newer one. `run({ silent: true })` refreshes without showing the
 * loading state again, which is what polling during an import needs.
 */
export function useAsyncState<T>(loader: () => Promise<T>, options: AsyncStateOptions<T> = {}) {
  const data = shallowRef<T>()
  const error = shallowRef<Error | null>(null)
  const loading = ref(false)
  let latestCall = 0

  async function run({ silent = false }: { silent?: boolean } = {}): Promise<void> {
    const call = ++latestCall
    if (!silent) {
      loading.value = true
    }
    error.value = null

    try {
      const result = await loader()
      if (call === latestCall) {
        data.value = result
      }
    } catch (e) {
      if (call === latestCall) {
        error.value = e instanceof Error ? e : new Error(String(e))
      }
    } finally {
      if (call === latestCall) {
        loading.value = false
      }
    }
  }

  const isEmpty = computed(() => data.value !== undefined && (options.isEmpty?.(data.value) ?? false))

  if (options.immediate ?? true) {
    void run()
  }

  return { data, error, loading, isEmpty, run }
}
