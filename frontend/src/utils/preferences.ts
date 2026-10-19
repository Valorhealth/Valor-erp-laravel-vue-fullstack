import { auth } from '../stores/auth'

export function preferredRowsPerPage(fallback: 20 | 25 | 50 | 100 = 25): number {
  return auth.user.value?.preferences?.rows_per_page ?? fallback
}
