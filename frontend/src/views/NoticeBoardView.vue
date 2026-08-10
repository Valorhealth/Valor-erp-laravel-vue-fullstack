<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import { auth } from '../stores/auth'
import type { Notice } from '../types/api'
import { apiError, shortDate, todayIso } from '../utils/format'

const notices = ref<Notice[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const tab = ref<'view' | 'create' | 'manage'>('view')
const canManage = computed(() => auth.can('approve_bills') || auth.can('system_admin'))
const form = reactive({ title: '', body: '', notice_type: 'ประกาศทั่วไป', meeting_date: todayIso(), meeting_time: '09:00', location: '', is_pinned: false })
const activeNotices = computed(() => notices.value.filter((notice) => notice.is_active))
const todayCount = computed(() => activeNotices.value.filter((notice) => notice.meeting_date === todayIso()).length)
const pinnedCount = computed(() => activeNotices.value.filter((notice) => notice.is_pinned).length)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: Notice[] }>('/api/v1/notices', { params: { include_inactive: canManage.value ? 1 : undefined } })
    notices.value = response.data.data
  } catch (cause) { error.value = apiError(cause, 'โหลดประกาศไม่สำเร็จ') }
  finally { loading.value = false }
}

async function createNotice() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/api/v1/notices', form)
    Object.assign(form, { title: '', body: '', notice_type: 'ประกาศทั่วไป', meeting_date: todayIso(), meeting_time: '09:00', location: '', is_pinned: false })
    tab.value = 'view'
    await load()
  } catch (cause) { error.value = apiError(cause, 'บันทึกประกาศไม่สำเร็จ') }
  finally { saving.value = false }
}

async function toggle(notice: Notice) {
  try {
    await api.patch(`/api/v1/notices/${notice.notice_id}/active`, { is_active: !notice.is_active })
    await load()
  } catch (cause) { error.value = apiError(cause) }
}
onMounted(load)
</script>

<template>
  <AppShell title="บอร์ดประกาศทีม" subtitle="ข่าวสาร นัดประชุม และเรื่องที่ทีมต้องเห็นพร้อมกันในวันนี้">
    <div class="metrics-grid metrics-grid--three">
      <article class="metric-card"><span>ประกาศที่เปิดอยู่</span><strong>{{ activeNotices.length }}</strong><small>รายการ</small></article>
      <article class="metric-card"><span>นัดวันนี้</span><strong>{{ todayCount }}</strong><small>{{ shortDate(todayIso()) }}</small></article>
      <article class="metric-card metric-card--primary"><span>ปักหมุด</span><strong>{{ pinnedCount }}</strong><small>ประกาศสำคัญ</small></article>
    </div>
    <div v-if="canManage" class="tabs">
      <button :class="{ active: tab === 'view' }" @click="tab = 'view'">ดูประกาศ</button>
      <button :class="{ active: tab === 'create' }" @click="tab = 'create'">เพิ่มประกาศ</button>
      <button :class="{ active: tab === 'manage' }" @click="tab = 'manage'">จัดการ</button>
    </div>
    <p v-if="error" class="form-error">{{ error }}</p>
    <div v-if="loading" class="loading-state">กำลังโหลดประกาศ…</div>
    <section v-else-if="tab === 'view'" class="notice-grid">
      <article v-for="notice in activeNotices" :key="notice.notice_id" class="notice-card" :class="`notice-card--${notice.notice_type}`">
        <div class="notice-card__meta"><span>{{ notice.notice_type }}</span><strong v-if="notice.is_pinned">ปักหมุด</strong></div>
        <h2>{{ notice.title }}</h2><p>{{ notice.body || '-' }}</p>
        <footer>{{ shortDate(notice.meeting_date) }} {{ notice.meeting_time?.slice(0, 5) || '' }} · {{ notice.location || '-' }}<br>ผู้ประกาศ: {{ notice.created_by || '-' }}</footer>
      </article>
      <div v-if="activeNotices.length === 0" class="empty-state">ยังไม่มีประกาศจากทีม Admin</div>
    </section>
    <form v-else-if="tab === 'create'" class="content-card form-grid" @submit.prevent="createNotice">
      <label class="span-2"><span>หัวข้อประกาศ *</span><input v-model="form.title" required maxlength="500"></label>
      <label><span>ประเภท</span><select v-model="form.notice_type"><option>ประกาศทั่วไป</option><option>นัดประชุม</option><option>แจ้งเตือนด่วน</option></select></label>
      <label class="span-2"><span>รายละเอียด</span><textarea v-model="form.body" rows="5"></textarea></label>
      <label><span>วันที่</span><input v-model="form.meeting_date" type="date"></label>
      <label><span>เวลา</span><input v-model="form.meeting_time" type="time"></label>
      <label class="span-2"><span>สถานที่หรือช่องทางประชุม</span><input v-model="form.location"></label>
      <label class="check-field"><input v-model="form.is_pinned" type="checkbox"> ปักหมุดไว้ด้านบน</label>
      <button class="primary-button span-2" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : 'บันทึกประกาศ' }}</button>
    </form>
    <section v-else class="content-card list-stack">
      <article v-for="notice in notices" :key="notice.notice_id" class="manage-row">
        <div><strong>{{ notice.title }}</strong><small>{{ notice.notice_type }}</small><StatusPill :status="notice.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน'" /></div>
        <button class="secondary-button" @click="toggle(notice)">{{ notice.is_active ? 'ปิดประกาศ' : 'เปิดอีกครั้ง' }}</button>
      </article>
    </section>
  </AppShell>
</template>

<style scoped>
/* สั่งให้ Grid จัดเรียงการ์ดอัตโนมัติและขยายเต็ม 100% */
.notice-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.5rem;
  width: 100%;
}

.notice-card {
  width: 100%;
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 22px 28px;
}

/* ดันให้ Footer (วันที่/เวลา) ไปอยู่ล่างสุดของการ์ดเสมอ */
.notice-card p {
  flex-grow: 0;
}
</style>
