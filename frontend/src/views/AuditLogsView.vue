<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import CompatDateTimeInput from '../components/CompatDateTimeInput.vue'
import StatusPill from '../components/StatusPill.vue'
import type { AuditLog, AuditLogResponse } from '../types/api'
import { apiError, todayIso } from '../utils/format'
import { i18n } from '../stores/i18n'

const result = ref<AuditLogResponse | null>(null)
const search = ref('')
const username = ref('')
const category = ref('')
const action = ref('')
const outcome = ref('')
const dateFrom = ref('')
const dateTo = ref(todayIso())
const loading = ref(true)
const error = ref('')
const expandedId = ref<number | null>(null)

const logs = computed(() => result.value?.data.data ?? [])

function dateTime(value: string | null): string {
  if (!value) return '-'
  return new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'medium' }).format(new Date(value))
}

function readableTarget(row: AuditLog): string {
  const document = row.request_data?.document_download as { quotation_no?: string; revision_no?: number } | undefined
  if (document?.quotation_no) return `${document.quotation_no} · R${document.revision_no || 1}`
  return row.target_type && row.target_id ? `${row.target_type} #${row.target_id}` : '-'
}

function requestDetails(row: AuditLog): string {
  return row.request_data ? JSON.stringify(row.request_data, null, 2) : 'ไม่มีข้อมูลที่กรอกเพิ่มเติม'
}

async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<AuditLogResponse>('/api/v1/audit-logs', {
      params: {
        page,
        search: search.value || undefined,
        username: username.value || undefined,
        category: category.value || undefined,
        action: action.value || undefined,
        result: outcome.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
      },
    })
    result.value = response.data
  } catch (cause) {
    error.value = apiError(cause, 'โหลดบันทึกกิจกรรมระบบไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  search.value = ''
  username.value = ''
  category.value = ''
  action.value = ''
  outcome.value = ''
  dateFrom.value = ''
  dateTo.value = todayIso()
  void load(1)
}

onMounted(() => load())
</script>

<template>
  <AppShell title="บันทึกกิจกรรมระบบ" subtitle="ตรวจสอบว่าใครทำอะไร เมื่อใด กับข้อมูลหรือเอกสารใด พร้อมผลลัพธ์ของการทำรายการ">
    <div v-if="result" class="metrics-grid metrics-grid--three">
      <article class="metric-card metric-card--primary"><span>กิจกรรมตามตัวกรอง</span><strong>{{ i18n.formatNumber(result.summary.total) }}</strong><small>วันนี้ทั้งระบบ {{ i18n.formatNumber(result.summary.today) }} รายการ</small></article>
      <article class="metric-card"><span>สำเร็จ</span><strong>{{ i18n.formatNumber(result.summary.successful) }}</strong><small>HTTP response ต่ำกว่า 400</small></article>
      <article class="metric-card"><span>ไม่สำเร็จ</span><strong>{{ i18n.formatNumber(result.summary.failed) }}</strong><small>ใช้ตรวจสอบข้อผิดพลาดหรือรายการที่ถูกปฏิเสธ</small></article>
      <article class="metric-card"><span>ผู้ใช้งานที่มีกิจกรรม</span><strong>{{ i18n.formatNumber(result.summary.active_users) }}</strong><small>เฉพาะข้อมูลตามตัวกรองปัจจุบัน</small></article>
    </div>

    <section class="content-card filter-panel">
      <form class="form-grid" @submit.prevent="load(1)">
        <label class="span-2"><span>ค้นหา</span><input v-model.trim="search" placeholder="Username, รายละเอียด หรือเลขอ้างอิง"></label>
        <label><span>ผู้ใช้งาน</span><select v-model="username"><option value="">ทุกคน</option><option v-for="item in result?.filters.users" :key="item" :value="item">{{ item }}</option></select></label>
        <label><span>หมวดหมู่</span><select v-model="category"><option value="">ทุกหมวดหมู่</option><option v-for="item in result?.filters.categories" :key="item" :value="item">{{ item }}</option></select></label>
        <label><span>การกระทำ</span><select v-model="action"><option value="">ทุกการกระทำ</option><option v-for="item in result?.filters.actions" :key="item" :value="item">{{ item }}</option></select></label>
        <label><span>ผลลัพธ์</span><select v-model="outcome"><option value="">ทั้งหมด</option><option value="success">สำเร็จ</option><option value="failed">ไม่สำเร็จ</option></select></label>
        <label><span>ตั้งแต่วันที่</span><CompatDateTimeInput v-model="dateFrom" mode="date" :max="dateTo || undefined" /></label>
        <label><span>ถึงวันที่</span><CompatDateTimeInput v-model="dateTo" mode="date" :min="dateFrom || undefined" /></label>
        <div class="actions span-2"><button class="primary-button" :disabled="loading">ค้นหา</button><button class="secondary-button" type="button" @click="clearFilters">ล้างตัวกรอง</button><button class="secondary-button" type="button" :disabled="loading" @click="load(result?.data.current_page || 1)">รีเฟรช</button></div>
      </form>
    </section>

    <div v-if="loading" class="loading-state">กำลังโหลดบันทึกกิจกรรม…</div>
    <div v-else-if="error" class="error-state">{{ error }} <button @click="load(1)">ลองอีกครั้ง</button></div>

    <section v-else class="content-card">
      <p class="muted">ประวัติดาวน์โหลดบันทึกเมื่อระบบสร้างไฟล์ตอบกลับสำเร็จ ไม่ยืนยันการบันทึกไฟล์หรือพิมพ์กระดาษจริง ดู Revision และรายการเอกสารที่ส่งออกรวมได้ใน “ดูข้อมูล”</p>
      <div class="table-wrap audit-table-wrap">
        <table>
          <thead><tr><th>วันเวลา</th><th>ผู้ใช้งาน</th><th>กิจกรรม</th><th>รายการอ้างอิง</th><th>ผลลัพธ์</th><th>รายละเอียด</th></tr></thead>
          <tbody>
            <template v-for="row in logs" :key="row.audit_id">
              <tr>
                <td class="audit-time">{{ dateTime(row.occurred_at) }}<br><small>{{ row.ip_address || '-' }} · {{ row.duration_ms || '0' }} ms</small></td>
                <td><strong>{{ row.username || 'ไม่ทราบผู้ใช้' }}</strong><br><small>{{ row.role || '-' }} · {{ row.department || '-' }}</small></td>
                <td><StatusPill :status="row.category" /><br><strong>{{ row.description }}</strong><br><small>{{ row.action }} · {{ row.http_method }}</small></td>
                <td>{{ readableTarget(row) }}<br><small>{{ row.route_uri || '-' }}</small></td>
                <td><span class="audit-result" :class="row.was_successful ? 'audit-result--success' : 'audit-result--failed'">{{ row.was_successful ? 'สำเร็จ' : 'ไม่สำเร็จ' }}</span><br><small>HTTP {{ row.response_status || '-' }}</small></td>
                <td><button class="secondary-button" type="button" @click="expandedId = expandedId === row.audit_id ? null : row.audit_id">{{ expandedId === row.audit_id ? 'ซ่อน' : 'ดูข้อมูล' }}</button></td>
              </tr>
              <tr v-if="expandedId === row.audit_id" class="audit-detail-row"><td colspan="6"><div><strong>ข้อมูลที่ส่งเข้าระบบ (ตัดรหัสผ่านและ Token ออกแล้ว)</strong><pre>{{ requestDetails(row) }}</pre><small>User agent: {{ row.user_agent || '-' }}</small></div></td></tr>
            </template>
            <tr v-if="logs.length === 0"><td colspan="6" class="empty-cell">ไม่พบกิจกรรมตามตัวกรอง</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="result" class="pagination"><span>แสดง {{ result.data.from || 0 }}–{{ result.data.to || 0 }} จาก {{ i18n.formatNumber(result.data.total) }} รายการ</span><div><button class="secondary-button" :disabled="!result.data.prev_page_url" @click="load(result.data.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ result.data.current_page }} / {{ result.data.last_page }}</span><button class="secondary-button" :disabled="!result.data.next_page_url" @click="load(result.data.current_page + 1)">ถัดไป</button></div></div>
    </section>
  </AppShell>
</template>
