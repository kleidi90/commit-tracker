/** A timestamp from the API in the reader's own locale and time zone. */
export function formatDateTime(iso: string | null): string {
  return iso === null ? 'Never' : new Date(iso).toLocaleString()
}
