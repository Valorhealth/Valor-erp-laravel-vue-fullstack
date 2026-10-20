<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { auth } from '../stores/auth'
import { i18n } from '../stores/i18n'
import { api } from '../api/client'
import UiIcon from './UiIcon.vue'
import type { MenuItem } from '../types/api'

const props = defineProps<{ title: string; subtitle?: string }>()
const router = useRouter()
const route = useRoute()
const mobileQuery = window.matchMedia('(max-width: 1024px)')
const tabletQuery = window.matchMedia('(min-width: 1025px) and (max-width: 1279px)')
const storedCollapsed = localStorage.getItem('valor.sidebar.collapsed')
const sidebarCollapsed = ref(storedCollapsed === null ? tabletQuery.matches : storedCollapsed === 'true')
const mobileMenuOpen = ref(false)
const userMenuOpen = ref(false)
const commandOpen = ref(false)
const commandQuery = ref('')
const commandInput = ref<HTMLInputElement | null>(null)
const isOnline = ref(navigator.onLine)
const notificationOpen = ref(false)
const notificationLoading = ref(false)
const unreadCount = ref(0)
const notifications = ref<Array<{ notification_id: number; title: string; body: string | null; action_url: string | null; read_at: string | null; created_at: string }>>([])

const iconByKey: Record<string, string> = {
  create_bill: 'file-plus', edit_bill: 'file-pen', sales_documents: 'folder', document_center: 'folder',
  my_billing_history: 'history', credit_request_status: 'shield-check', sales_call_entry: 'phone',
  sales_call_report: 'chart', dashboard_stats: 'dashboard', system_admin: 'settings', master_data: 'database',
  credit_profiles: 'credit-card', user_management: 'users', calendar: 'calendar', equipment_booking: 'activity',
  approve_bills: 'shield-check', approved_bills: 'check', rejected_bills: 'x', all_bills: 'list',
  budget_analysis: 'wallet', audit_logs: 'history', notice_board: 'megaphone',
  document_preparation_queue: 'list',
  my_document_preparation_status: 'search',
}
const sectionDefinitions = [
  { key: 'overview', label: 'ภาพรวม', items: ['notice_board', 'dashboard_stats'] },
  { key: 'sales', label: 'งานขาย', items: ['create_bill', 'edit_bill', 'my_billing_history', 'credit_request_status', 'approve_bills', 'approved_bills', 'rejected_bills', 'all_bills', 'budget_analysis'] },
  { key: 'call-sale', label: 'Call Sale', items: ['sales_call_entry', 'sales_call_report'] },
  { key: 'workspace', label: 'การทำงาน', items: ['calendar', 'equipment_booking'] },
  { key: 'documents', label: 'เอกสาร', items: ['sales_documents', 'my_document_preparation_status', 'document_center', 'document_preparation_queue'] },
  { key: 'admin', label: 'ผู้ดูแลระบบ', items: ['system_admin', 'master_data', 'credit_profiles', 'user_management', 'audit_logs'] },
]

const noticeItem: MenuItem = { key: 'notice_board', label: 'บอร์ดประกาศ', path: '/notice-board', icon: '' }
const visibleLinks = computed(() => {
  const source = auth.can('notice_board') ? [noticeItem, ...(auth.user.value?.menu ?? [])] : (auth.user.value?.menu ?? [])
  const seenLabels = new Set<string>()
  return source.filter((item) => {
    const normalized = item.label.trim().replace(/\s+/g, ' ')
    if (seenLabels.has(normalized)) return false
    seenLabels.add(normalized)
    return true
  })
})
const groupedLinks = computed(() => {
  const knownKeys = new Set(sectionDefinitions.flatMap((section) => section.items))
  const groups = sectionDefinitions.map((section) => ({ ...section, links: visibleLinks.value.filter((link) => section.items.includes(link.key)) })).filter((section) => section.links.length)
  const ungrouped = visibleLinks.value.filter((link) => !knownKeys.has(link.key))
  return ungrouped.length ? [...groups, { key: 'other', label: 'อื่นๆ', items: [], links: ungrouped }] : groups
})
const activeLink = computed(() => visibleLinks.value.find((link) => route.path === link.path || route.path.startsWith(`${link.path}/`)))
const activeSection = computed(() => groupedLinks.value.find((section) => section.links.some((link) => link.key === activeLink.value?.key)))
const localizedTitle = computed(() => i18n.text(props.title))
const localizedSubtitle = computed(() => props.subtitle ? i18n.text(props.subtitle) : '')
const roleLabels = { super_admin: 'ผู้ดูแลระบบสูงสุด', sale: 'ฝ่ายขาย', approve: 'ผู้อนุมัติ', admin: 'ผู้ดูแลระบบ', default: 'ผู้ใช้งานทั่วไป' }
const roleLabel = computed(() => i18n.text(roleLabels[auth.user.value?.role_group ?? 'default']))
const displayName = computed(() => [auth.user.value?.first_name, auth.user.value?.last_name].filter(Boolean).join(' ') || auth.user.value?.username || '-')
const filteredCommands = computed(() => {
  const needle = commandQuery.value.trim().toLocaleLowerCase('th')
  return visibleLinks.value.filter((link) => !needle || `${link.label} ${link.key}`.toLocaleLowerCase('th').includes(needle))
})

function iconName(key: string) { return iconByKey[key] ?? 'list' }
function toggleSidebar() {
  if (mobileQuery.matches) mobileMenuOpen.value = !mobileMenuOpen.value
  else {
    sidebarCollapsed.value = !sidebarCollapsed.value
    localStorage.setItem('valor.sidebar.collapsed', String(sidebarCollapsed.value))
  }
}
function closeOverlays() { mobileMenuOpen.value = false; userMenuOpen.value = false; notificationOpen.value = false }
function openCommand() { commandOpen.value = true; commandQuery.value = ''; nextTick(() => commandInput.value?.focus()) }
function closeCommand() { commandOpen.value = false; commandQuery.value = '' }
async function goTo(link: MenuItem) { closeCommand(); closeOverlays(); await router.push(link.path) }
function handleKeydown(event: KeyboardEvent) {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openCommand() }
  if (event.key === 'Escape') { closeCommand(); closeOverlays() }
}
function syncViewport() {
  if (!mobileQuery.matches) mobileMenuOpen.value = false
  if (tabletQuery.matches) sidebarCollapsed.value = true
}
function syncConnection() { isOnline.value = navigator.onLine }
async function loadNotifications(): Promise<void> {
  if (!auth.isAuthenticated.value || notificationLoading.value) return
  notificationLoading.value = true
  try {
    const response = await api.get<{ data: typeof notifications.value; unread_count: number }>('/api/v1/notifications', { skipGlobalLoading: true })
    notifications.value = response.data.data
    unreadCount.value = response.data.unread_count
  } catch { /* การแจ้งเตือนไม่ควรขัดขวางการใช้งานหน้าหลัก */ }
  finally { notificationLoading.value = false }
}
async function toggleNotifications(): Promise<void> {
  notificationOpen.value = !notificationOpen.value
  userMenuOpen.value = false
  if (notificationOpen.value) await loadNotifications()
}
async function openNotification(notification: typeof notifications.value[number]): Promise<void> {
  if (!notification.read_at) {
    await api.patch(`/api/v1/notifications/${notification.notification_id}/read`)
    notification.read_at = new Date().toISOString()
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  }
  notificationOpen.value = false
  if (notification.action_url) await router.push(notification.action_url)
}
async function markAllNotificationsRead(): Promise<void> {
  await api.patch('/api/v1/notifications/read-all')
  const now = new Date().toISOString()
  notifications.value.forEach((notification) => { notification.read_at ||= now })
  unreadCount.value = 0
}
function notificationTime(value: string): string {
  return new Intl.DateTimeFormat(i18n.language.value === 'en' ? 'en-US' : 'th-TH', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}
async function signOut() { await auth.logout(); await router.replace({ name: 'login' }) }

watch(mobileMenuOpen, (open) => document.body.classList.toggle('mobile-menu-open', open))
watch(() => auth.user.value?.preferences?.sidebar_collapsed, (preferred) => {
  if (typeof preferred !== 'boolean' || mobileQuery.matches) return
  sidebarCollapsed.value = preferred
  localStorage.setItem('valor.sidebar.collapsed', String(preferred))
}, { immediate: true })
onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
  mobileQuery.addEventListener('change', syncViewport)
  tabletQuery.addEventListener('change', syncViewport)
  window.addEventListener('online', syncConnection)
  window.addEventListener('offline', syncConnection)
  loadNotifications()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleKeydown)
  mobileQuery.removeEventListener('change', syncViewport)
  tabletQuery.removeEventListener('change', syncViewport)
  window.removeEventListener('online', syncConnection)
  window.removeEventListener('offline', syncConnection)
  document.body.classList.remove('mobile-menu-open')
})
</script>

<template>
  <div class="app-layout enterprise-shell" :class="{ 'app-layout--sidebar-open': mobileMenuOpen, 'app-layout--sidebar-collapsed': sidebarCollapsed && !mobileMenuOpen }">
    <button class="sidebar-backdrop" :class="{ 'sidebar-backdrop--visible': mobileMenuOpen }" type="button" aria-label="ปิดเมนู" @click="mobileMenuOpen = false"></button>
    <aside class="sidebar" :class="{ 'sidebar--open': mobileMenuOpen }">
      <div class="sidebar-top">
        <RouterLink class="sidebar-brand" to="/notice-board" aria-label="Valor Health" @click="closeOverlays"><img src="/valorhealth-removebg.png" alt="Valor Health"></RouterLink>
        <button class="sidebar-collapse" type="button" :aria-label="sidebarCollapsed ? 'ขยายเมนู' : 'ย่อเมนู'" :title="sidebarCollapsed ? 'ขยายเมนู' : 'ย่อเมนู'" @click="toggleSidebar"><UiIcon class="sidebar-collapse__icon" :class="{ 'is-expanded': !sidebarCollapsed }" name="chevron" :size="19" /></button>
      </div>
      <div class="menu-scroll-area">
        <nav class="nav" aria-label="เมนูหลัก">
          <section v-for="section in groupedLinks" :key="section.key" class="nav-section">
            <p class="nav__heading">{{ section.label }}</p>
            <RouterLink v-for="link in section.links" :key="link.key" :to="link.path" :title="sidebarCollapsed ? i18n.menu(link.key, link.label) : undefined" @click="closeOverlays"><UiIcon :name="iconName(link.key)" :size="19" /><span class="nav-label">{{ i18n.menu(link.key, link.label) }}</span></RouterLink>
          </section>
        </nav>
      </div>
      <div class="account-fixed-bottom">
        <button class="profile" type="button" :aria-expanded="userMenuOpen" @click="userMenuOpen = !userMenuOpen"><span class="profile__avatar">{{ displayName.slice(0, 1).toUpperCase() }}</span><span class="profile__text"><strong :title="displayName">{{ displayName }}</strong><small :title="`${roleLabel} · ${auth.user.value?.department || '-'}`">{{ roleLabel }} · {{ auth.user.value?.department || '-' }}</small></span><UiIcon class="profile-chevron" name="chevron" :size="16" /></button>
        <div v-if="userMenuOpen" class="profile-menu"><RouterLink to="/settings" @click="closeOverlays"><UiIcon name="user" :size="17" /> โปรไฟล์และการตั้งค่า</RouterLink><button type="button" @click="signOut"><UiIcon name="logout" :size="17" /> ออกจากระบบ</button></div>
      </div>
    </aside>

    <main class="main-content">
      <div class="topbar">
        <button class="menu-button" type="button" :aria-label="sidebarCollapsed ? 'เปิดเมนู' : 'ปิดเมนู'" @click="toggleSidebar"><UiIcon name="menu" :size="20" /></button>
        <div class="breadcrumbs"><span>{{ activeSection?.label || 'ระบบ' }}</span><UiIcon name="chevron" :size="14" /><strong>{{ activeLink ? i18n.menu(activeLink.key, activeLink.label) : localizedTitle }}</strong></div>
        <button class="command-trigger" type="button" @click="openCommand"><UiIcon name="search" :size="17" /><span>ค้นหาเมนูและไปยังหน้า…</span><kbd>Ctrl K</kbd></button>
        <div class="topbar-actions"><span class="online-status" :class="{ 'is-offline': !isOnline }"><i></i>{{ isOnline ? 'Online' : 'Offline' }}</span><div class="notification-anchor"><button class="topbar-icon" type="button" aria-label="การแจ้งเตือน" :aria-expanded="notificationOpen" title="การแจ้งเตือน" @click="toggleNotifications"><UiIcon name="bell" :size="19" /><b v-if="unreadCount" class="notification-badge">{{ unreadCount > 99 ? '99+' : unreadCount }}</b></button><section v-if="notificationOpen" class="notification-panel"><header><div><strong>การแจ้งเตือน</strong><small>{{ unreadCount }} รายการที่ยังไม่อ่าน</small></div><button v-if="unreadCount" type="button" @click="markAllNotificationsRead">อ่านทั้งหมด</button></header><div class="notification-list"><button v-for="notification in notifications" :key="notification.notification_id" type="button" :class="{ unread: !notification.read_at }" @click="openNotification(notification)"><i></i><span><strong>{{ notification.title }}</strong><small>{{ notification.body?.split('\n').filter(Boolean).slice(0, 2).join(' · ') || 'เปิดเพื่อดูรายละเอียดงาน' }}</small><time>{{ notificationTime(notification.created_at) }}</time></span></button><p v-if="notificationLoading && !notifications.length">กำลังโหลดการแจ้งเตือน…</p><p v-else-if="!notifications.length">ยังไม่มีการแจ้งเตือน</p></div><footer><RouterLink to="/settings" @click="notificationOpen = false">ตั้งค่าการแจ้งเตือนทางอีเมล</RouterLink></footer></section></div><button class="topbar-avatar" type="button" :title="displayName" @click="userMenuOpen = !userMenuOpen; notificationOpen = false">{{ displayName.slice(0, 1).toUpperCase() }}</button></div>
      </div>
      <header class="page-header"><div><h1>{{ localizedTitle }}</h1><p v-if="localizedSubtitle">{{ localizedSubtitle }}</p></div><div class="page-header-actions"><slot name="actions" /></div></header>
      <slot />
    </main>

    <Teleport to="body">
      <div v-if="commandOpen" class="command-overlay" @click.self="closeCommand">
        <section class="command-palette" role="dialog" aria-modal="true" aria-label="ค้นหาเมนู">
          <div class="command-search"><UiIcon name="search" :size="20" /><input ref="commandInput" v-model="commandQuery" placeholder="พิมพ์ชื่อเมนู…" aria-label="ค้นหาเมนู"></div>
          <div class="command-results"><button v-for="link in filteredCommands" :key="link.key" type="button" @click="goTo(link)"><UiIcon :name="iconName(link.key)" :size="18" /><span><strong>{{ i18n.menu(link.key, link.label) }}</strong><small>{{ link.path }}</small></span><kbd>↵</kbd></button><p v-if="!filteredCommands.length">ไม่พบเมนูที่ค้นหา</p></div>
          <footer><span><kbd>↑↓</kbd> เลือก</span><span><kbd>Esc</kbd> ปิด</span></footer>
        </section>
      </div>
    </Teleport>
  </div>
</template>
