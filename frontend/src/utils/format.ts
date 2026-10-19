import axios from 'axios'
import { i18n } from '../stores/i18n'

export const money = (value: string | number | null | undefined) => i18n.formatNumber(value, {
  style: 'currency', currency: 'THB', maximumFractionDigits: 2,
})

export const shortDate = (value: string | null | undefined) => i18n.formatDate(value)

export function apiError(error: unknown, fallback = 'ดำเนินการไม่สำเร็จ'): string {
  if (!axios.isAxiosError(error)) return fallback
  const errors = error.response?.data?.errors as Record<string, string[]> | undefined
  if (errors) return Object.values(errors).flat()[0] || fallback
  return error.response?.data?.message || fallback
}

export const todayIso = () => {
  const today = new Date()
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
}
