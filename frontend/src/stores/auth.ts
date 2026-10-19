import { computed, readonly, ref } from 'vue'
import { api } from '../api/client'
import type { User } from '../types/api'
import { i18n } from './i18n'

const currentUser = ref<User | null>(null)
const initialized = ref(false)

function applyPreferences(user: User): void {
  i18n.applyPreferences(user.preferences)
}

async function restoreSession(): Promise<void> {
  try {
    const response = await api.get<{ data: User }>('/api/v1/auth/me')
    currentUser.value = response.data.data
    applyPreferences(response.data.data)
  } catch {
    currentUser.value = null
  } finally {
    initialized.value = true
  }
}

async function login(username: string, password: string): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
  const response = await api.post<{ data: User }>('/api/v1/auth/login', { username, password })
  currentUser.value = response.data.data
  applyPreferences(response.data.data)
  initialized.value = true
}

async function logout(): Promise<void> {
  try {
    await api.post('/api/v1/auth/logout')
  } finally {
    currentUser.value = null
  }
}

function can(ability: string): boolean {
  return currentUser.value?.abilities.includes(ability) ?? false
}

function defaultPath(): string {
  const preferred = currentUser.value?.preferences?.default_path
  if (preferred === '/notice-board' && can('notice_board')) return preferred
  if (preferred && currentUser.value?.menu.some(item => item.path === preferred)) return preferred
  return can('notice_board') ? '/notice-board' : (currentUser.value?.menu[0]?.path ?? '/login')
}

function replaceUser(user: User): void {
  currentUser.value = user
  applyPreferences(user)
}

export const auth = {
  user: readonly(currentUser),
  initialized: readonly(initialized),
  isAuthenticated: computed(() => currentUser.value !== null),
  can,
  defaultPath,
  restoreSession,
  login,
  logout,
  replaceUser,
}
