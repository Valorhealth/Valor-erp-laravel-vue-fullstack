<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import CompatDateTimeInput from '../components/CompatDateTimeInput.vue'
import StatusPill from '../components/StatusPill.vue'
import UiButton from '../components/UiButton.vue'
import UiDrawer from '../components/UiDrawer.vue'
import UiIcon from '../components/UiIcon.vue'
import UiState from '../components/UiState.vue'
import { auth } from '../stores/auth'
import { toast } from '../stores/toast'
import type { Notice } from '../types/api'
import { apiError, shortDate, todayIso } from '../utils/format'

const notices = ref<Notice[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const mode = ref<'list' | 'create' | 'manage'>('list')
const filter = ref<'all' | 'pinned' | 'general'>('all')
const selectedNotice = ref<Notice | null>(null)
const canManage = computed(() => auth.can('approve_bills') || auth.can('system_admin'))
const form = reactive({ title: '', body: '', notice_type: 'ประกาศทั่วไป', meeting_date: todayIso(), meeting_time: '09:00', location: '', is_pinned: false })
const activeNotices = computed(() => notices.value.filter((notice) => notice.is_active))
const sortedNotices = computed(() => [...activeNotices.value].sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned) || String(b.created_at || '').localeCompare(String(a.created_at || ''))))
const filteredNotices = computed(() => sortedNotices.value.filter((notice) => filter.value === 'all' || (filter.value === 'pinned' ? notice.is_pinned : !notice.is_pinned)))
const todayCount = computed(() => activeNotices.value.filter((notice) => notice.meeting_date === todayIso()).length)
const pinnedCount = computed(() => activeNotices.value.filter((notice) => notice.is_pinned).length)

function relativeTime(value: string | null) {
  if (!value) return ''
  const diff = Date.now() - new Date(value).getTime()
  const days = Math.floor(diff / 86_400_000)
  if (days > 0) return `${days} วันที่แล้ว`
  const hours = Math.max(1, Math.floor(diff / 3_600_000))
  return `${hours} ชั่วโมงที่แล้ว`
}
async function load() {
  loading.value = true; error.value = ''
  try {
    const response = await api.get<{ data: Notice[] }>('/api/v1/notices', { params: { include_inactive: canManage.value ? 1 : undefined } })
    notices.value = response.data.data
  } catch (cause) { error.value = apiError(cause, 'โหลดประกาศไม่สำเร็จ') }
  finally { loading.value = false }
}
async function createNotice() {
  saving.value = true; error.value = ''
  try {
    await api.post('/api/v1/notices', form)
    Object.assign(form, { title: '', body: '', notice_type: 'ประกาศทั่วไป', meeting_date: todayIso(), meeting_time: '09:00', location: '', is_pinned: false })
    mode.value = 'list'; await load(); toast.show('บันทึกประกาศเรียบร้อยแล้ว', 'success')
  } catch (cause) { error.value = apiError(cause, 'บันทึกประกาศไม่สำเร็จ'); toast.show(error.value, 'error') }
  finally { saving.value = false }
}
async function toggle(notice: Notice) {
  try { await api.patch(`/api/v1/notices/${notice.notice_id}/active`, { is_active: !notice.is_active }); await load(); toast.show(notice.is_active ? 'ปิดประกาศแล้ว' : 'เปิดประกาศแล้ว', 'success') }
  catch (cause) { error.value = apiError(cause); toast.show(error.value, 'error') }
}
onMounted(load)
</script>

<template>
  <AppShell title="บอร์ดประกาศ" subtitle="ข่าวสาร นัดประชุม และเรื่องสำคัญของทีมในที่เดียว">
    <template #actions><UiButton v-if="canManage && mode !== 'create'" variant="primary" icon="plus" @click="mode = 'create'">เพิ่มประกาศ</UiButton><UiButton v-if="mode !== 'list'" variant="secondary" @click="mode = 'list'">กลับไปดูประกาศ</UiButton></template>

    <div class="metrics-grid notice-metrics">
      <article class="metric-card"><span class="metric-icon metric-icon--info"><UiIcon name="megaphone" :size="18" /></span><div><span>ประกาศที่เปิดอยู่</span><strong>{{ activeNotices.length }}</strong><small>รายการที่ทีมมองเห็น</small></div></article>
      <article class="metric-card"><span class="metric-icon metric-icon--success"><UiIcon name="calendar" :size="18" /></span><div><span>นัดวันนี้</span><strong>{{ todayCount }}</strong><small>{{ shortDate(todayIso()) }}</small></div></article>
      <article class="metric-card metric-card--important"><span class="metric-icon metric-icon--brand"><UiIcon name="pin" :size="18" /></span><div><span>ปักหมุดและสำคัญ</span><strong>{{ pinnedCount }}</strong><small>ควรอ่านก่อนเริ่มงาน</small></div></article>
    </div>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <UiState v-if="loading" type="loading" title="กำลังโหลดประกาศ" />

    <template v-else-if="mode === 'list'">
      <div class="notice-toolbar"><div class="tabs" role="tablist"><button :class="{ active: filter === 'all' }" @click="filter = 'all'">ทั้งหมด <span>{{ activeNotices.length }}</span></button><button :class="{ active: filter === 'pinned' }" @click="filter = 'pinned'">ปักหมุด <span>{{ pinnedCount }}</span></button><button :class="{ active: filter === 'general' }" @click="filter = 'general'">ทั่วไป <span>{{ activeNotices.length - pinnedCount }}</span></button></div><UiButton v-if="canManage" variant="ghost" icon="settings" @click="mode = 'manage'">จัดการประกาศ</UiButton></div>
      <section v-if="filteredNotices.length" class="notice-grid">
        <article v-for="notice in filteredNotices" :key="notice.notice_id" class="notice-card" :class="{ 'notice-card--pinned': notice.is_pinned }" tabindex="0" @click="selectedNotice = notice" @keydown.enter="selectedNotice = notice">
          <div class="notice-card__meta"><span class="notice-priority" :class="{ urgent: notice.is_pinned || notice.notice_type === 'แจ้งเตือนด่วน' }"><UiIcon :name="notice.is_pinned ? 'pin' : 'megaphone'" :size="14" />{{ notice.is_pinned ? `ด่วน · ปักหมุด` : notice.notice_type }}</span><time>{{ relativeTime(notice.created_at) }}</time></div>
          <h2>{{ notice.title }}</h2><p>{{ notice.body || 'ไม่มีรายละเอียดเพิ่มเติม' }}</p>
          <footer><span class="notice-avatar">{{ (notice.created_by || '?').slice(0, 1).toUpperCase() }}</span><span><strong>{{ notice.created_by || 'ไม่ระบุผู้ประกาศ' }}</strong><small>{{ shortDate(notice.meeting_date) }} {{ notice.meeting_time?.slice(0, 5) || '' }}<template v-if="notice.location"> · {{ notice.location }}</template></small></span><span class="read-more">อ่านเพิ่มเติม</span></footer>
        </article>
      </section>
      <UiState v-else type="empty" title="ยังไม่มีประกาศ" message="ประกาศที่เปิดใช้งานจะแสดงในส่วนนี้" />
    </template>

    <form v-else-if="mode === 'create'" class="content-card form-grid notice-form" @submit.prevent="createNotice">
      <div class="section-heading span-2"><div><h2>เพิ่มประกาศใหม่</h2><p class="muted">ระบุข้อมูลที่ทีมจำเป็นต้องทราบ สามารถปักหมุดเรื่องสำคัญไว้ด้านบนได้</p></div></div>
      <label class="span-2"><span>หัวข้อประกาศ *</span><input v-model="form.title" required maxlength="500"></label>
      <label><span>ประเภท</span><select v-model="form.notice_type"><option>ประกาศทั่วไป</option><option>นัดประชุม</option><option>แจ้งเตือนด่วน</option></select></label>
      <label class="span-2"><span>รายละเอียด</span><textarea v-model="form.body" rows="5"></textarea></label>
      <label><span>วันที่</span><CompatDateTimeInput v-model="form.meeting_date" mode="date" /></label><label><span>เวลา</span><CompatDateTimeInput v-model="form.meeting_time" mode="time" /></label>
      <label class="span-2"><span>สถานที่หรือช่องทางประชุม</span><input v-model="form.location"></label>
      <label class="check-field"><input v-model="form.is_pinned" type="checkbox"><span>ปักหมุดไว้ด้านบน</span></label>
      <div class="notice-form-actions span-2"><UiButton variant="ghost" @click="mode = 'list'">ยกเลิก</UiButton><UiButton type="submit" variant="primary" :loading="saving">บันทึกประกาศ</UiButton></div>
    </form>

    <section v-else class="content-card list-stack"><div class="section-heading"><div><h2>จัดการประกาศ</h2><p class="muted">เปิดหรือปิดประกาศโดยไม่ลบประวัติ</p></div></div><article v-for="notice in notices" :key="notice.notice_id" class="manage-row"><div><strong>{{ notice.title }}</strong><small>{{ notice.notice_type }}</small><StatusPill :status="notice.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน'" /></div><UiButton variant="secondary" size="sm" @click="toggle(notice)">{{ notice.is_active ? 'ปิดประกาศ' : 'เปิดอีกครั้ง' }}</UiButton></article></section>

    <UiDrawer :open="Boolean(selectedNotice)" :title="selectedNotice?.title || ''" :description="selectedNotice?.notice_type" @close="selectedNotice = null"><div v-if="selectedNotice" class="notice-detail"><span class="notice-priority" :class="{ urgent: selectedNotice.is_pinned }"><UiIcon :name="selectedNotice.is_pinned ? 'pin' : 'megaphone'" :size="14" />{{ selectedNotice.is_pinned ? 'ปักหมุด' : selectedNotice.notice_type }}</span><p>{{ selectedNotice.body || 'ไม่มีรายละเอียดเพิ่มเติม' }}</p><dl><div><dt>วันที่และเวลา</dt><dd>{{ shortDate(selectedNotice.meeting_date) }} {{ selectedNotice.meeting_time?.slice(0, 5) || '-' }}</dd></div><div><dt>สถานที่</dt><dd>{{ selectedNotice.location || '-' }}</dd></div><div><dt>ผู้ประกาศ</dt><dd>{{ selectedNotice.created_by || '-' }}</dd></div></dl></div></UiDrawer>
  </AppShell>
</template>

<style scoped>
.notice-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.notice-metrics .metric-card{display:grid;grid-template-columns:auto 1fr;gap:14px;align-items:start}.notice-metrics .metric-card>div{display:grid}.notice-metrics .metric-card strong{margin:8px 0 4px}.metric-icon{display:grid;width:38px;height:38px;place-items:center;border-radius:50%}.metric-icon--info{background:var(--info-50);color:var(--info-700)}.metric-icon--success{background:var(--success-50);color:var(--success-600)}.metric-icon--brand{background:var(--primary-50);color:var(--primary-700)}.metric-card--important{border-left:3px solid var(--primary-600)}
.notice-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:16px}.notice-toolbar .tabs{display:flex;flex:1}.notice-toolbar .tabs span{margin-left:5px;color:var(--gray-500);font-size:var(--text-caption)}
.notice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.notice-card{position:relative;display:flex;min-height:220px;flex-direction:column;padding:20px;cursor:pointer;transition:border-color var(--motion-fast) var(--ease-out),box-shadow var(--motion-fast) var(--ease-out)}.notice-card:hover{border-color:var(--gray-300);box-shadow:var(--shadow-md)}.notice-card--pinned{border-left:3px solid var(--primary-600)}.notice-card__meta{display:flex;align-items:center;justify-content:space-between;gap:12px}.notice-card__meta time{color:var(--gray-500);font-size:var(--text-caption)}.notice-priority{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:var(--radius-pill);background:var(--gray-100);color:var(--gray-600);font-size:var(--text-caption);font-weight:600}.notice-priority.urgent{background:var(--primary-50);color:var(--primary-700)}.notice-card h2{margin:16px 0 8px;font-size:var(--text-h3)}.notice-card>p{display:-webkit-box;overflow:hidden;color:var(--gray-700);font-size:var(--text-sm);line-height:1.65;-webkit-box-orient:vertical;-webkit-line-clamp:3}.notice-card footer{display:flex;align-items:center;gap:9px;margin-top:auto;padding-top:16px;border-top:1px solid var(--gray-150)}.notice-card footer>span:nth-child(2){display:grid}.notice-card footer strong{font-size:var(--text-sm);font-weight:600}.notice-card footer small{color:var(--gray-500);font-size:var(--text-caption)}.notice-avatar{display:grid;width:32px;height:32px;place-items:center;border-radius:50%;background:var(--gray-100);color:var(--gray-700);font-weight:600}.read-more{margin-left:auto;color:var(--primary-700);font-size:var(--text-caption);font-weight:600}.notice-form{max-width:920px}.notice-form-actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:8px;padding-top:16px;border-top:1px solid var(--gray-200);background:var(--gray-0)}.notice-detail>p{white-space:pre-wrap;color:var(--gray-700);font-size:var(--text-body);line-height:1.75}.notice-detail dl{display:grid;gap:12px;margin-top:24px}.notice-detail dl>div{padding:12px;border:1px solid var(--gray-200);border-radius:var(--radius-md);background:var(--gray-50)}.notice-detail dt{color:var(--gray-500);font-size:var(--text-caption)}.notice-detail dd{margin:3px 0 0;color:var(--gray-800);font-size:var(--text-sm)}
@media(max-width:900px){.notice-grid{grid-template-columns:1fr}}@media(max-width:720px){.notice-metrics{grid-template-columns:1fr}.notice-toolbar{align-items:stretch;flex-direction:column}.notice-toolbar .tabs{overflow-x:auto}}
</style>
