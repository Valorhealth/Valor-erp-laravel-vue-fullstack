<script setup lang="ts">
import axios from 'axios'
import { computed, onMounted, reactive, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import { api, downloadFile } from '../api/client'
import { auth } from '../stores/auth'
import type { Paginated, SalesCallActivity, SalesCallDashboard, SalesCallOptions } from '../types/api'

type DailyRow = Pick<SalesCallActivity, 'call_id' | 'place_of_work' | 'customer_name' | 'specialty' | 'department' | 'customer_group' | 'product_1' | 'call_detail_objective' | 'call_detail_outcome' | 'next_follow_up_date' | 'notes' | 'sm_comment'> & {
  objective: string
  outcome: string
  status: string
}

const managerView = computed(() => ['approve', 'super_admin'].includes(auth.user.value?.role_group ?? ''))
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const options = ref<SalesCallOptions>({ sellers: [], specialties: [], departments: [], groups: [], products: [], objectives: [], outcomes: [], stages: [], statuses: [] })

const selectedDate = ref(localDate(new Date()))
const dailyRows = ref<DailyRow[]>([])
const history = ref<Paginated<SalesCallActivity> | null>(null)
const historyLoading = ref(false)
const historyFilters = reactive({
  date_from: localDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)),
  date_to: localDate(new Date()),
  per_page: 50,
})

const dashboard = ref<SalesCallDashboard | null>(null)
const activities = ref<Paginated<SalesCallActivity> | null>(null)
const filters = reactive({
  seller_ids: [] as number[],
  date_from: localDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)),
  date_to: localDate(new Date()),
  products: [] as string[],
  outcomes: [] as string[],
  stages: [] as string[],
  statuses: [] as string[],
  customer: '',
  per_page: 25,
})

const maxSeller = computed(() => Math.max(...(dashboard.value?.by_seller.map(row => Number(row.total)) ?? [1]), 1))
const chartColors = ['#22c55e', '#ef4444', '#94a3b8', '#2563eb', '#7c3aed', '#f59e0b', '#06b6d4', '#db2777', '#65a30d', '#ea580c', '#475569', '#c92d3e']
const outcomeRows = computed(() => ['Success', 'Failure', 'No Call'].map(label => dashboard.value?.by_outcome.find(row => row.label === label) ?? { label, total: 0 }))
const outcomeDonut = computed(() => donutStyle(outcomeRows.value, ['#22c55e', '#ef4444', '#94a3b8']))
const specialtyDonut = computed(() => donutStyle(dashboard.value?.by_specialty.slice(0, 10) ?? [], chartColors))
const trendPoints = computed(() => {
  const rows = dashboard.value?.daily_trend ?? []
  const max = Math.max(...rows.map(row => Number(row.total)), 1)
  return rows.map((row, index) => ({
    ...row,
    x: rows.length === 1 ? 500 : 35 + (index * 930 / (rows.length - 1)),
    y: 185 - (Number(row.total) / max * 150),
  }))
})
const trendLine = computed(() => trendPoints.value.map(point => `${point.x},${point.y}`).join(' '))
const trendArea = computed(() => trendPoints.value.length ? `35,190 ${trendLine.value} ${trendPoints.value.at(-1)?.x ?? 965},190` : '')

function blankRow(): DailyRow {
  return {
    call_id: 0, place_of_work: '', customer_name: '', specialty: '', department: '', customer_group: '',
    product_1: '', objective: 'Awareness', call_detail_objective: '', outcome: 'No Call',
    call_detail_outcome: '', next_follow_up_date: null, notes: '', sm_comment: '', status: 'ทำงาน',
  }
}

function donutStyle(rows: Array<{ total: number }>, colors: string[]): Record<string, string> {
  const total = rows.reduce((sum, row) => sum + Number(row.total), 0)
  if (!total) return { background: '#eef1f5' }
  let start = 0
  const stops = rows.map((row, index) => {
    const end = start + Number(row.total) / total * 360
    const stop = `${colors[index % colors.length]} ${start}deg ${end}deg`
    start = end
    return stop
  })
  return { background: `conic-gradient(${stops.join(',')})` }
}

async function loadDay() {
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await api.get<{ data: { date: string; rows: SalesCallActivity[] }; options: SalesCallOptions }>('/api/v1/sales-calls/day', { params: { date: selectedDate.value } })
    options.value = response.data.options
    dailyRows.value = response.data.data.rows.length
      ? response.data.data.rows.map(row => ({
          call_id: row.call_id, place_of_work: row.place_of_work ?? '', customer_name: row.customer_name ?? '',
          specialty: row.specialty ?? '', department: row.department ?? '', customer_group: row.customer_group ?? '',
          product_1: row.product_1 ?? '', objective: row.objective, call_detail_objective: row.call_detail_objective ?? '',
          outcome: row.outcome, call_detail_outcome: row.call_detail_outcome ?? '', next_follow_up_date: row.next_follow_up_date,
          notes: row.notes ?? '', sm_comment: row.sm_comment ?? '', status: row.status,
        }))
      : [blankRow()]
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลดตาราง Call Sale ไม่สำเร็จ')
    if (!dailyRows.value.length) dailyRows.value = [blankRow()]
  } finally {
    loading.value = false
  }
}

async function saveDay() {
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await api.put<{ data: { date: string; rows: SalesCallActivity[] }; message: string }>('/api/v1/sales-calls/day', {
      date: selectedDate.value,
      rows: dailyRows.value.map(row => ({ ...row, call_id: row.call_id || null })),
    })
    dailyRows.value = response.data.data.rows.length
      ? response.data.data.rows.map(row => ({
          call_id: row.call_id, place_of_work: row.place_of_work ?? '', customer_name: row.customer_name ?? '',
          specialty: row.specialty ?? '', department: row.department ?? '', customer_group: row.customer_group ?? '',
          product_1: row.product_1 ?? '', objective: row.objective, call_detail_objective: row.call_detail_objective ?? '',
          outcome: row.outcome, call_detail_outcome: row.call_detail_outcome ?? '', next_follow_up_date: row.next_follow_up_date,
          notes: row.notes ?? '', sm_comment: row.sm_comment ?? '', status: row.status,
        }))
      : [blankRow()]
    notice.value = response.data.message
    await loadHistory(history.value?.current_page ?? 1)
  } catch (cause) {
    error.value = apiMessage(cause, 'บันทึกตาราง Call Sale ไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

async function loadHistory(page = 1) {
  historyLoading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: Paginated<SalesCallActivity> }>('/api/v1/sales-calls/history', {
      params: { ...historyFilters, page },
    })
    history.value = response.data.data
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลดประวัติ Call Plan ไม่สำเร็จ')
  } finally {
    historyLoading.value = false
  }
}

function addRow() {
  if (dailyRows.value.length >= 100) {
    error.value = 'เพิ่มได้สูงสุด 100 Call ต่อวัน'
    return
  }
  dailyRows.value.push(blankRow())
}

function removeRow(index: number) {
  dailyRows.value.splice(index, 1)
  if (!dailyRows.value.length) dailyRows.value.push(blankRow())
}

async function changeDay(days: number) {
  const date = new Date(`${selectedDate.value}T12:00:00`)
  date.setDate(date.getDate() + days)
  selectedDate.value = localDate(date)
  await loadDay()
}

async function loadReport(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const params = { ...filters, page }
    const [dashResponse, listResponse] = await Promise.all([
      api.get<{ data: SalesCallDashboard; options: SalesCallOptions }>('/api/v1/sales-calls/dashboard', { params }),
      api.get<{ data: Paginated<SalesCallActivity>; options: SalesCallOptions }>('/api/v1/sales-calls', { params }),
    ])
    dashboard.value = dashResponse.data.data
    activities.value = listResponse.data.data
    options.value = dashResponse.data.options
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลด Call Sale Report ไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function exportUrl(path: string): string {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (Array.isArray(value)) value.forEach(item => query.append(`${key}[]`, String(item)))
    else if (value !== '' && value !== null && key !== 'per_page') query.set(key, String(value))
  })
  return `${path}?${query.toString()}`
}

function localDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function displayDate(value: string | null): string {
  if (!value) return '-'
  const [year, month, day] = value.slice(0, 10).split('-')
  return `${day}/${month}/${year}`
}

function shortDate(value: string | null): string {
  if (!value) return '-'
  return new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' })
}

function sellerName(sellerId: number, username: string): string {
  const seller = options.value.sellers.find(item => item.id === Number(sellerId))
  return [seller?.first_name, seller?.last_name].filter(Boolean).join(' ') || username
}

function apiMessage(cause: unknown, fallback: string): string {
  if (!axios.isAxiosError(cause)) return fallback
  const data = cause.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
  return Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? fallback
}

onMounted(async () => {
  if (managerView.value) await loadReport()
  else await Promise.all([loadDay(), loadHistory()])
})
</script>

<template>
  <AppShell
    :title="managerView ? 'Call Sale Report' : 'Call Planner & Report'"
    :subtitle="managerView ? 'วิเคราะห์กิจกรรมของทีมขายและดูข้อมูลดิบ' : 'บันทึกแผนและผลการเข้าพบลูกค้าประจำวัน'"
  >
    <div v-if="error" class="alert alert-error">{{ error }}</div>
    <div v-if="notice" class="alert alert-success">{{ notice }}</div>

    <template v-if="!managerView">
      <section class="content-card daily-card">
        <div class="section-heading planner-heading">
          <div>
            <p class="eyebrow">DAILY CALL PLAN</p>
            <h2>ตาราง Call Sale ประจำวันที่ {{ displayDate(selectedDate) }}</h2>
            <p class="muted">วันที่ในทุกแถวถูกกำหนดตามวันที่ที่เลือก และบันทึกพร้อมกันทั้งตาราง</p>
          </div>
          <div class="row-count">{{ dailyRows.length }} Call</div>
        </div>

        <div v-if="loading" class="loading-box">กำลังโหลดตาราง...</div>
        <div v-else class="planner-scroll">
          <table class="planner-table">
            <thead>
              <tr>
                <th class="col-no">Call No.</th><th class="col-date">Date</th><th>PLACE OF WORK</th><th>CUSTOMER NAME</th>
                <th>SPECIALTY</th><th>DEPARTMENT</th><th>GROUP</th><th>PRODUCT-1</th><th>CALL OBJECTIVE</th>
                <th>CALL DETAIL (Obj)</th><th>CALL OUTCOME</th><th>CALL DETAIL (Out)</th><th>F/U DATE</th>
                <th>NOTE</th><th>SM Comment</th><th>Status</th><th class="col-remove"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in dailyRows" :key="row.call_id || `new-${index}`">
                <td class="locked col-no"><strong>{{ index + 1 }}</strong></td>
                <td class="locked col-date">{{ displayDate(selectedDate) }}</td>
                <td><textarea v-model.trim="row.place_of_work" rows="2" placeholder="สถานที่ทำงาน"></textarea></td>
                <td><textarea v-model.trim="row.customer_name" rows="2" placeholder="ชื่อลูกค้า"></textarea></td>
                <td><select v-model="row.specialty"><option value="">เลือก</option><option v-for="item in options.specialties" :key="item">{{ item }}</option></select></td>
                <td><select v-model="row.department"><option value="">เลือก</option><option v-for="item in options.departments" :key="item">{{ item }}</option></select></td>
                <td><select v-model="row.customer_group"><option value="">เลือก</option><option v-for="item in options.groups" :key="item">{{ item }}</option></select></td>
                <td><select v-model="row.product_1"><option value="">เลือก</option><option v-for="item in options.products" :key="item">{{ item }}</option></select></td>
                <td><select v-model="row.objective"><option v-for="item in options.objectives" :key="item">{{ item }}</option></select></td>
                <td><textarea v-model.trim="row.call_detail_objective" rows="2" placeholder="รายละเอียดวัตถุประสงค์"></textarea></td>
                <td><select v-model="row.outcome"><option v-for="item in options.outcomes" :key="item">{{ item }}</option></select></td>
                <td><textarea v-model.trim="row.call_detail_outcome" rows="2" placeholder="รายละเอียดผลลัพธ์"></textarea></td>
                <td><input v-model="row.next_follow_up_date" type="date"></td>
                <td><textarea v-model.trim="row.notes" rows="2" placeholder="หมายเหตุ"></textarea></td>
                <td><textarea v-model.trim="row.sm_comment" rows="2" placeholder="SM Comment"></textarea></td>
                <td><select v-model="row.status"><option v-for="item in options.statuses" :key="item">{{ item }}</option></select></td>
                <td class="col-remove"><button class="remove-row" type="button" title="ลบแถว" @click="removeRow(index)">×</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="planner-footer">
          <div class="planner-actions">
            <button class="btn add-row" type="button" :disabled="loading" @click="addRow">＋ เพิ่ม Row</button>
            <button style="background-color: #88E788; color: black;" class="btn btn-primary save-table" type="button" :disabled="loading || saving" @click="saveDay">{{ saving ? 'กำลังบันทึก...' : 'บันทึกข้อมูล' }}</button>
          </div>
          <div class="day-switcher">
            <button type="button" aria-label="วันก่อนหน้า" @click="changeDay(-1)">«</button>
            <input v-model="selectedDate" type="date" aria-label="เลือกวันที่" @change="loadDay">
            <strong>{{ displayDate(selectedDate) }}</strong>
            <button type="button" aria-label="วันถัดไป" @click="changeDay(1)">»</button>
          </div>
        </div>
      </section>

      <section class="content-card section-gap history-card">
        <div class="section-heading history-heading">
          <div>
            <p class="eyebrow">MY CALL PLAN HISTORY</p>
            <h2>ประวัติ Call Plan ของฉัน</h2>
            <p class="muted">แสดงเฉพาะข้อมูลที่บัญชีของคุณบันทึกไว้ {{ history?.total ?? 0 }} รายการ</p>
          </div>
          <div class="history-filters">
            <label>ตั้งแต่<input v-model="historyFilters.date_from" type="date"></label>
            <label>ถึง<input v-model="historyFilters.date_to" type="date"></label>
            <label>แถว<select v-model="historyFilters.per_page"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label>
            <button class="btn btn-primary" type="button" :disabled="historyLoading" @click="loadHistory()">ดูประวัติ</button>
          </div>
        </div>
        <div v-if="historyLoading" class="loading-box">กำลังโหลดประวัติ...</div>
        <div v-else class="history-scroll">
          <table class="history-table">
            <thead><tr><th>Date</th><th>Call No.</th><th>PLACE OF WORK</th><th>CUSTOMER</th><th>PRODUCT</th><th>OBJECTIVE</th><th>OUTCOME</th><th>F/U DATE</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="row in history?.data" :key="row.call_id"><td>{{ displayDate(row.activity_date) }}</td><td>{{ row.call_no }}</td><td>{{ row.place_of_work || '-' }}</td><td>{{ row.customer_name || '-' }}</td><td>{{ row.product_1 || '-' }}</td><td>{{ row.objective }}</td><td><span class="outcome-chip" :class="row.outcome.toLowerCase().replace(' ', '-')">{{ row.outcome }}</span></td><td>{{ displayDate(row.next_follow_up_date) }}</td><td><span class="status-chip" :class="row.status === 'ทำงาน' ? 'work' : 'leave'">{{ row.status }}</span></td></tr>
              <tr v-if="!history?.data.length"><td colspan="9" class="empty-cell">ยังไม่มี Call Plan ในช่วงวันที่เลือก</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="history && history.last_page > 1" class="pagination compact-pagination"><button class="btn" :disabled="!history.prev_page_url" @click="loadHistory(history.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ history.current_page }} / {{ history.last_page }}</span><button class="btn" :disabled="!history.next_page_url" @click="loadHistory(history.current_page + 1)">ถัดไป</button></div>
      </section>
    </template>

    <template v-else>
      <section class="content-card">
        <div class="section-heading">
          <div><p class="eyebrow">REPORT FILTERS</p><h2>ตัวกรองรายงานทีมขาย</h2></div>
          <div class="export-actions"><button class="btn" @click="downloadFile(exportUrl('/api/v1/sales-calls/export.xlsx'), 'Call_Sale_Report.xlsx')">Export Excel + Raw Data</button><button class="btn btn-primary" @click="downloadFile(exportUrl('/api/v1/sales-calls/dashboard.pdf'), 'Call_Sale_Dashboard.pdf')">Export Dashboard PDF</button></div>
        </div>
        <div class="filter-grid">
          <label>Sale (เลือกได้หลายคน)<select v-model="filters.seller_ids" multiple><option v-for="seller in options.sellers" :key="seller.id" :value="seller.id">{{ [seller.first_name, seller.last_name].filter(Boolean).join(' ') || seller.username }}</option></select></label>
          <label>ตั้งแต่วันที่<input v-model="filters.date_from" type="date"></label>
          <label>ถึงวันที่<input v-model="filters.date_to" type="date"></label>
          <label>ค้นหาลูกค้า<input v-model.trim="filters.customer" placeholder="ชื่อลูกค้า/โรงพยาบาล"></label>
          <label>Product<select v-model="filters.products" multiple><option v-for="item in options.products" :key="item">{{ item }}</option></select></label>
          <label>Call Outcome<select v-model="filters.outcomes" multiple><option v-for="item in options.outcomes" :key="item">{{ item }}</option></select></label>
          <label>Status<select v-model="filters.statuses" multiple><option v-for="item in options.statuses" :key="item">{{ item }}</option></select></label>
        </div>
        <div class="filter-actions"><button class="btn btn-primary" @click="loadReport()">ใช้ตัวกรอง</button><small class="muted">กด Ctrl ค้างเพื่อเลือกหลายรายการ</small></div>
      </section>

      <template v-if="dashboard && !loading">
        <div class="report-metrics section-gap">
          <article><span>Total Call</span><strong>{{ dashboard.summary.total_activities.toLocaleString() }}</strong><small>Call ตามตัวกรอง</small></article>
          <article class="success"><span>Success Call</span><strong>{{ dashboard.summary.success_calls.toLocaleString() }}</strong><small>Outcome Success</small></article>
          <article class="danger"><span>Failure Call</span><strong>{{ dashboard.summary.failure_calls.toLocaleString() }}</strong><small>Outcome Failure</small></article>
          <article class="warn"><span>No Call</span><strong>{{ dashboard.summary.no_calls.toLocaleString() }}</strong><small>ไม่ได้เข้าพบ</small></article>
          <article><span>Success Rate</span><strong>{{ dashboard.summary.conversion_rate }}%</strong><small>Success ÷ Total Call</small></article>
          <article><span>Working Day</span><strong>{{ dashboard.summary.working_days.toLocaleString() }}</strong><small>วันที่ทำงานไม่ซ้ำ</small></article>
          <article><span>Call / Working Day</span><strong>{{ dashboard.summary.calls_per_working_day }}</strong><small>Total Call ÷ Working Day</small></article>
          <article><span>Customers Called</span><strong>{{ dashboard.summary.active_customers.toLocaleString() }}</strong><small>ลูกค้าไม่ซ้ำ</small></article>
        </div>

        <div class="report-grid section-gap">
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">SALES PERFORMANCE</p><h2>Total Call × Success Rate</h2></div><small class="muted">ขวา = Call มาก · สูง = Success Rate สูง</small></div><div v-if="dashboard.by_seller.length" class="bubble-chart"><svg viewBox="0 0 1000 225" role="img" aria-label="เปรียบเทียบ Total Call และ Success Rate ของ Sale"><line v-for="y in [35,85,135,185]" :key="y" x1="35" :y1="y" x2="965" :y2="y"/><g v-for="(row,index) in dashboard.by_seller" :key="row.seller_username"><circle :cx="45 + Number(row.total) / maxSeller * 900" :cy="185 - Number(row.success_rate) * 1.5" :r="12 + Math.min(Number(row.active_customers), 15)" :fill="chartColors[index % chartColors.length]"/><text :x="45 + Number(row.total) / maxSeller * 900" :y="180 - Number(row.success_rate) * 1.5" text-anchor="middle">{{ row.seller_username }}</text><text :x="45 + Number(row.total) / maxSeller * 900" :y="195 - Number(row.success_rate) * 1.5" text-anchor="middle">{{ row.total }} / {{ row.success_rate }}%</text></g></svg></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">CALL OUTCOME</p><h2>สัดส่วนผลลัพธ์การ Call</h2></div></div><div v-if="dashboard.by_outcome.length" class="donut-layout"><div class="donut" :style="outcomeDonut"><div><strong>{{ dashboard.summary.total_activities }}</strong><span>Total Call</span></div></div><div class="chart-legend"><div v-for="(row,index) in outcomeRows" :key="row.label"><i :style="{ background: ['#22c55e','#ef4444','#94a3b8'][index] }"></i><span>{{ row.label }}</span><strong>{{ row.total }}</strong><small>{{ dashboard.summary.total_activities ? Math.round(row.total / dashboard.summary.total_activities * 100) : 0 }}%</small></div></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
        </div>

        <section class="content-card section-gap"><div class="section-heading"><div><p class="eyebrow">PERFORMANCE TREND</p><h2>แนวโน้ม Total Call รายวัน</h2></div><small class="muted">Line + Area Chart</small></div><div v-if="trendPoints.length" class="line-chart"><svg viewBox="0 0 1000 225" preserveAspectRatio="none" role="img" aria-label="แนวโน้ม Total Call รายวัน"><line v-for="y in [35,85,135,185]" :key="y" x1="35" :y1="y" x2="965" :y2="y"/><polygon :points="trendArea"/><polyline :points="trendLine"/><g v-for="point in trendPoints" :key="point.activity_date"><circle :cx="point.x" :cy="point.y" r="6"/><text :x="point.x" :y="point.y - 12" text-anchor="middle">{{ point.total }}</text></g></svg><div class="line-labels"><span v-for="point in trendPoints" :key="point.activity_date">{{ shortDate(point.activity_date) }}</span></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>

        <section class="content-card section-gap"><div class="section-heading"><div><p class="eyebrow">CALL OBJECTIVE × OUTCOME</p><h2>ผลลัพธ์แยกตามวัตถุประสงค์การ Call</h2></div><div class="legend"><span class="dot success-dot"></span>Success <span class="dot failure-dot"></span>Failure <span class="dot nocall-dot"></span>No Call</div></div><div class="objective-chart"><div v-for="row in dashboard.by_objective" :key="row.label"><strong>{{ row.label }}</strong><div class="stacked-track"><i class="stack-success" :style="{ width: `${row.total ? row.success_calls / row.total * 100 : 0}%` }"></i><i class="stack-failure" :style="{ width: `${row.total ? row.failure_calls / row.total * 100 : 0}%` }"></i><i class="stack-nocall" :style="{ width: `${row.total ? row.no_calls / row.total * 100 : 0}%` }"></i></div><b>{{ row.total }}</b></div><p v-if="!dashboard.by_objective.length" class="muted">ยังไม่มีข้อมูล</p></div></section>

        <div class="report-grid section-gap">
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">PRODUCT DASHBOARD</p><h2>Product Treemap</h2></div><small class="muted">พื้นที่ใหญ่ = จำนวน Call มาก</small></div><div v-if="dashboard.by_product.length" class="treemap"><article v-for="(row,index) in dashboard.by_product" :key="row.label" :style="{ flexGrow: Number(row.total), background: chartColors[index % chartColors.length] }"><strong>{{ row.label }}</strong><span>{{ row.total }} Calls</span><small>Success {{ row.success_rate }}%</small></article></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">SPECIALTY DASHBOARD</p><h2>สัดส่วน Call ตาม Specialty</h2></div><small class="muted">10 อันดับแรก</small></div><div v-if="dashboard.by_specialty.length" class="donut-layout specialty-layout"><div class="donut specialty-donut" :style="specialtyDonut"><div><strong>{{ dashboard.by_specialty.length }}</strong><span>Specialties</span></div></div><div class="chart-legend compact"><div v-for="(row,index) in dashboard.by_specialty.slice(0,10)" :key="row.label"><i :style="{ background: chartColors[index % chartColors.length] }"></i><span>{{ row.label }}</span><strong>{{ row.total }}</strong><small>{{ row.success_rate }}%</small></div></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
        </div>

        <section class="content-card section-gap">
          <div class="section-heading"><div><p class="eyebrow">PERFORMANCE MATRIX</p><h2>สรุปผลราย Sale และสถานที่ทำงาน</h2><p class="muted">ตารางเดียวกับ Sales Performance Matrix ใน Power BI</p></div></div>
          <div class="summary-scroll"><table class="summary-table"><thead><tr><th>Sale</th><th>Place of Work</th><th>Total Call</th><th>Success</th><th>Failure</th><th>No Call</th><th>Success Rate</th><th>Working Day</th><th>Call / Day</th></tr></thead><tbody><tr v-for="row in dashboard.performance_matrix" :key="`${row.seller_username}-${row.place_of_work}`"><td><strong>{{ row.seller_username }}</strong></td><td>{{ row.place_of_work }}</td><td>{{ row.total }}</td><td class="number-success">{{ row.success_calls }}</td><td class="number-danger">{{ row.failure_calls }}</td><td>{{ row.no_calls }}</td><td><strong>{{ row.success_rate }}%</strong></td><td>{{ row.working_days }}</td><td>{{ row.calls_per_working_day }}</td></tr><tr v-if="!dashboard.performance_matrix.length"><td colspan="9" class="empty-cell">ยังไม่มีข้อมูล</td></tr></tbody></table></div>
        </section>

        <section class="content-card section-gap">
          <div class="section-heading"><div><p class="eyebrow">PRODUCT × CUSTOMER</p><h2>ผลการ Call แยกสินค้าและลูกค้า</h2></div></div>
          <div class="summary-scroll"><table class="summary-table"><thead><tr><th>Product</th><th>Place of Work</th><th>Customer</th><th>Total</th><th>Success</th><th>Failure</th><th>Success Rate</th><th>Last Call</th></tr></thead><tbody><tr v-for="row in dashboard.product_customer_matrix" :key="`${row.product}-${row.place_of_work}-${row.customer_name}`"><td><strong>{{ row.product }}</strong></td><td>{{ row.place_of_work }}</td><td>{{ row.customer_name }}</td><td>{{ row.total }}</td><td class="number-success">{{ row.success_calls }}</td><td class="number-danger">{{ row.failure_calls }}</td><td>{{ row.success_rate }}%</td><td>{{ shortDate(row.last_call_date) }}</td></tr><tr v-if="!dashboard.product_customer_matrix.length"><td colspan="8" class="empty-cell">ยังไม่มีข้อมูล</td></tr></tbody></table></div>
        </section>

        <section class="content-card section-gap">
          <div class="section-heading"><div><p class="eyebrow">SALE TOTAL SUMMARY</p><h2>สรุปผลรวมของ Sale แต่ละคน</h2><p class="muted">หนึ่งแถวต่อหนึ่ง Sale และคำนวณจากข้อมูลทั้งหมดตามตัวกรองด้านบน</p></div></div>
          <div class="summary-scroll"><table class="summary-table sale-total-table"><thead><tr><th>Name Sale</th><th>Total Call</th><th>Success Call</th><th>Failure Call</th><th>No Call</th><th>Success Rate</th><th>Working Day</th><th>Call / Working Day</th></tr></thead><tbody><tr v-for="row in dashboard.by_seller" :key="row.seller_user_id"><td><strong>{{ sellerName(row.seller_user_id, row.seller_username) }}</strong><small class="seller-username">{{ row.seller_username }}</small></td><td class="number-total">{{ row.total }}</td><td class="number-success">{{ row.success_calls }}</td><td class="number-danger">{{ row.failure_calls }}</td><td>{{ row.no_calls }}</td><td><strong>{{ row.success_rate }}%</strong></td><td>{{ row.working_days }}</td><td>{{ row.calls_per_working_day }}</td></tr><tr v-if="!dashboard.by_seller.length"><td colspan="8" class="empty-cell">ยังไม่มีข้อมูล Sale ตามตัวกรอง</td></tr></tbody></table></div>
        </section>

        <div class="report-grid section-gap">
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">SUCCESS DECOMPOSITION</p><h2>Success Breakdown</h2></div></div><div class="breakdown-list"><div v-for="row in dashboard.success_breakdown.slice(0, 15)" :key="`${row.seller_username}-${row.product}-${row.specialty}-${row.place_of_work}`"><strong>{{ row.seller_username }}</strong><span>› {{ row.product }} › {{ row.specialty }} › {{ row.place_of_work }}</span><b class="number-success">{{ row.total }}</b></div><p v-if="!dashboard.success_breakdown.length" class="muted">ยังไม่มีข้อมูล Success</p></div></section>
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">FAILURE DECOMPOSITION</p><h2>Failure Breakdown</h2></div></div><div class="breakdown-list"><div v-for="row in dashboard.failure_breakdown.slice(0, 15)" :key="`${row.seller_username}-${row.product}-${row.specialty}-${row.place_of_work}`"><strong>{{ row.seller_username }}</strong><span>› {{ row.product }} › {{ row.specialty }} › {{ row.place_of_work }}</span><b class="number-danger">{{ row.total }}</b></div><p v-if="!dashboard.failure_breakdown.length" class="muted">ยังไม่มีข้อมูล Failure</p></div></section>
        </div>
      </template>

      <section class="content-card section-gap">
        <div class="section-heading"><div><p class="eyebrow">RAW DATA</p><h2>ข้อมูล Call Sale ทั้งทีม</h2><p class="muted">{{ activities?.total ?? 0 }} รายการตามตัวกรอง</p></div></div>
        <div v-if="loading" class="loading-box">กำลังประมวลผลรายงาน...</div>
        <div v-else class="raw-scroll"><table class="raw-table"><thead><tr><th>Sale</th><th>Call No.</th><th>Date</th><th>PLACE OF WORK</th><th>CUSTOMER NAME</th><th>SPECIALTY</th><th>DEPARTMENT</th><th>GROUP</th><th>PRODUCT-1</th><th>CALL OBJECTIVE</th><th>CALL DETAIL (Obj)</th><th>CALL OUTCOME</th><th>CALL DETAIL (Out)</th><th>F/U DATE</th><th>NOTE</th><th>SM Comment</th><th>Status</th></tr></thead><tbody><tr v-for="row in activities?.data" :key="row.call_id"><td>{{ row.seller_username }}</td><td>{{ row.call_no }}</td><td>{{ shortDate(row.activity_date) }}</td><td>{{ row.place_of_work || '-' }}</td><td>{{ row.customer_name || '-' }}</td><td>{{ row.specialty || '-' }}</td><td>{{ row.department || '-' }}</td><td>{{ row.customer_group || '-' }}</td><td>{{ row.product_1 || '-' }}</td><td>{{ row.objective }}</td><td>{{ row.call_detail_objective || '-' }}</td><td>{{ row.outcome }}</td><td>{{ row.call_detail_outcome || '-' }}</td><td>{{ shortDate(row.next_follow_up_date) }}</td><td>{{ row.notes || '-' }}</td><td>{{ row.sm_comment || '-' }}</td><td><span class="status-chip" :class="row.status === 'ทำงาน' ? 'work' : 'leave'">{{ row.status }}</span></td></tr><tr v-if="!activities?.data.length"><td colspan="17" class="empty-cell">ไม่พบข้อมูลตามตัวกรอง</td></tr></tbody></table></div>
        <div v-if="activities && activities.last_page > 1" class="pagination"><button class="btn" :disabled="!activities.prev_page_url" @click="loadReport(activities.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ activities.current_page }} / {{ activities.last_page }}</span><button class="btn" :disabled="!activities.next_page_url" @click="loadReport(activities.current_page + 1)">ถัดไป</button></div>
      </section>
    </template>
  </AppShell>
</template>

<style scoped>
.alert{padding:12px 15px;border-radius:10px;margin-bottom:12px}.alert-error{background:#fff0f1;color:#a61b2b}.alert-success{background:#ecfdf3;color:#067647}.eyebrow{margin:0 0 5px;color:#c92d3e;font-size:.72rem;font-weight:800;letter-spacing:.13em}.daily-card{padding:0;overflow:hidden;border-top:4px solid #c92d3e}.planner-heading{padding:22px 24px 18px;margin:0;border-bottom:1px solid #e3e6eb}.row-count{border-radius:99px;background:#fff0f2;color:#b82032;font-weight:800;padding:8px 14px}.planner-scroll,.raw-scroll{overflow:auto;max-width:100%;border-bottom:1px solid #e2e5ea}.planner-table,.raw-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:.78rem}.planner-table th,.raw-table th{position:sticky;top:0;z-index:3;background:#b82032;color:#fff;padding:10px 8px;border-right:1px solid #d95060;white-space:nowrap;text-align:center}.planner-table td{padding:0;border-right:1px solid #dfe2e7;border-bottom:1px solid #dfe2e7;background:#fff;vertical-align:top}.planner-table tr:nth-child(even) td{background:#fffafb}.planner-table input,.planner-table select,.planner-table textarea{display:block;width:150px;min-height:54px;border:0;border-radius:0;padding:8px;background:transparent;color:#172033;font:inherit;resize:vertical}.planner-table select{width:145px}.planner-table input[type=date]{width:132px}.planner-table textarea:focus,.planner-table select:focus,.planner-table input:focus{outline:2px solid #e05060;outline-offset:-2px;background:#fff}.planner-table .locked{padding:13px 8px;text-align:center;background:#f4f5f7!important;color:#4b5565;white-space:nowrap}.planner-table .col-no{position:sticky;left:0;z-index:2;width:68px;min-width:68px}.planner-table th.col-no{z-index:5;background:#951a29}.planner-table .col-date{position:sticky;left:68px;z-index:2;width:105px;min-width:105px}.planner-table th.col-date{z-index:5;background:#a91e2e}.planner-table .col-remove{width:40px;min-width:40px}.remove-row{border:0;background:transparent;color:#b42318;font-size:1.45rem;cursor:pointer;padding:10px}.planner-footer{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:18px 24px;background:#fafbfc;flex-wrap:wrap}.planner-actions,.day-switcher,.export-actions,.filter-actions,.pagination{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.add-row{border-style:dashed}.save-table{min-width:150px}.day-switcher{margin-left:auto;border:1px solid #d7dbe2;border-radius:12px;background:#fff;padding:5px}.day-switcher button{width:36px;height:36px;border:0;border-radius:8px;background:#f4f5f7;font-size:1.35rem;cursor:pointer}.day-switcher input{width:36px;border:0;color:transparent;background:transparent}.day-switcher strong{white-space:nowrap;min-width:100px;text-align:center}.loading-box,.empty-cell{padding:35px;text-align:center;color:#697386}.filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:15px}.filter-grid label{display:flex;flex-direction:column;gap:7px;font-weight:700;font-size:.83rem}.filter-grid input,.filter-grid select{width:100%;border:1px solid #d5d9e0;border-radius:9px;padding:9px 11px;background:#fff}.filter-grid select[multiple]{min-height:86px}.filter-actions{margin-top:14px}.report-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:13px}.report-metrics article{background:#fff;border:1px solid #e2e5ea;border-radius:14px;padding:16px;box-shadow:0 4px 14px #1822300b}.report-metrics span,.report-metrics small{display:block;color:#667085}.report-metrics strong{display:block;font-size:1.55rem;margin:8px 0}.report-metrics .warn{background:#fffaf0;border-color:#f5d59e}.report-metrics .success{background:#f0fdf4;border-color:#bbf7d0}.report-metrics .danger{background:#fff1f2;border-color:#fecdd3}.report-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.bar-list{display:grid;gap:11px}.report-bar{display:grid;grid-template-columns:120px 1fr 38px;gap:9px;align-items:center}.report-bar div{height:9px;background:#edf0f4;border-radius:99px;overflow:hidden}.report-bar i{display:block;height:100%;background:linear-gradient(90deg,#c92d3e,#f06b79)}.report-bar i.blue{background:linear-gradient(90deg,#2563eb,#60a5fa)}.report-bar i.purple{background:linear-gradient(90deg,#7c3aed,#c084fc)}.report-bar i.outcome-success{background:#22c55e}.report-bar i.outcome-failure{background:#ef4444}.report-bar i.outcome-no-call{background:#94a3b8}.metric-bar{grid-template-columns:120px 1fr 34px 48px}.metric-bar small{text-align:right;color:#667085}.trend{display:flex;min-height:195px;align-items:flex-end;gap:12px;overflow:auto}.trend>div{display:grid;justify-items:center;gap:5px;min-width:58px}.trend i{display:block;width:27px;background:linear-gradient(#f06b79,#b82032);border-radius:7px 7px 2px 2px}.trend span{font-size:.68rem;white-space:nowrap}.raw-table{min-width:2200px}.raw-table td{padding:9px;border-right:1px solid #e4e6ea;border-bottom:1px solid #e4e6ea;vertical-align:top;max-width:230px}.status-chip,.outcome-chip{display:inline-block;padding:5px 9px;border-radius:99px;white-space:nowrap;font-weight:700}.status-chip.work{background:#dcfce7;color:#166534}.status-chip.leave{background:#f3f4f6;color:#4b5563}.outcome-chip.success{background:#dcfce7;color:#166534}.outcome-chip.failure{background:#fee2e2;color:#991b1b}.outcome-chip.no-call{background:#f1f5f9;color:#475569}.pagination{justify-content:flex-end;margin-top:14px}.history-card{padding:0;overflow:hidden}.history-heading{padding:18px 22px;margin:0}.history-filters{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}.history-filters label{display:grid;gap:4px;font-size:.74rem;font-weight:700}.history-filters input,.history-filters select{border:1px solid #d5d9e0;border-radius:8px;padding:7px 9px;background:#fff}.history-scroll,.summary-scroll{overflow:auto;max-height:560px}.history-table,.summary-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.77rem}.history-table th,.summary-table th{position:sticky;top:0;z-index:2;background:#263142;color:#fff;text-align:left;padding:9px 10px;white-space:nowrap}.history-table td,.summary-table td{padding:8px 10px;border-bottom:1px solid #e7e9ed;white-space:nowrap}.history-table tbody tr:nth-child(even),.summary-table tbody tr:nth-child(even){background:#f8fafc}.compact-pagination{padding:12px 18px;margin:0}.number-success{color:#15803d;font-weight:800}.number-danger{color:#b91c1c;font-weight:800}.breakdown-list{display:grid;gap:8px}.breakdown-list>div{display:grid;grid-template-columns:90px 1fr 35px;gap:8px;align-items:center;padding:9px 10px;background:#f8fafc;border-radius:8px;font-size:.76rem}.breakdown-list span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b}
.bubble-chart svg,.line-chart svg{display:block;width:100%;height:225px;overflow:visible}.bubble-chart line,.line-chart line{stroke:#e5e7eb;stroke-width:1}.bubble-chart circle{opacity:.82;stroke:#fff;stroke-width:3}.bubble-chart text{fill:#172033;font-size:12px;font-weight:800;pointer-events:none}.donut-layout{display:grid;grid-template-columns:190px 1fr;gap:24px;align-items:center}.donut{width:180px;aspect-ratio:1;border-radius:50%;display:grid;place-items:center;box-shadow:inset 0 0 0 1px #ffffff55}.donut>div{width:104px;aspect-ratio:1;border-radius:50%;background:#fff;display:grid;place-content:center;text-align:center;box-shadow:0 2px 14px #0f172a1a}.donut strong{font-size:1.65rem}.donut span{color:#64748b;font-size:.72rem}.chart-legend{display:grid;gap:10px}.chart-legend>div{display:grid;grid-template-columns:10px 1fr 38px 42px;gap:8px;align-items:center;padding-bottom:8px;border-bottom:1px solid #edf0f3}.chart-legend i{width:10px;height:10px;border-radius:50%}.chart-legend small{color:#64748b;text-align:right}.line-chart{overflow-x:auto}.line-chart svg{min-width:720px}.line-chart polygon{fill:#c92d3e18}.line-chart polyline{fill:none;stroke:#c92d3e;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}.line-chart circle{fill:#fff;stroke:#c92d3e;stroke-width:4}.line-chart text{fill:#172033;font-size:12px;font-weight:800}.line-labels{display:flex;justify-content:space-between;gap:25px;min-width:720px;font-size:.67rem;color:#64748b;padding:4px 28px 0}.treemap{display:flex;align-content:stretch;flex-wrap:wrap;min-height:260px;gap:5px}.treemap article{min-width:105px;min-height:92px;padding:13px;color:#fff;border-radius:10px;display:flex;flex:1 1 125px;flex-direction:column;justify-content:flex-end;text-shadow:0 1px 2px #0006;transition:transform .18s}.treemap article:hover{transform:translateY(-2px)}.treemap strong{font-size:.9rem}.treemap span{font-size:1.15rem;font-weight:900}.treemap small{font-size:.69rem}.specialty-donut{width:170px}.specialty-donut>div{width:92px}.specialty-layout{grid-template-columns:180px 1fr}.chart-legend.compact{gap:5px}.chart-legend.compact>div{padding-bottom:4px;font-size:.72rem}.legend{display:flex;align-items:center;gap:6px;color:#64748b;font-size:.76rem}.dot{width:9px;height:9px;border-radius:50%;display:inline-block;margin-left:8px}.success-dot,.stack-success{background:#22c55e}.failure-dot,.stack-failure{background:#ef4444}.nocall-dot,.stack-nocall{background:#94a3b8}.objective-chart{display:grid;gap:12px}.objective-chart>div{display:grid;grid-template-columns:130px 1fr 38px;align-items:center;gap:10px}.stacked-track{display:flex;height:13px;background:#eef1f5;border-radius:99px;overflow:hidden}.stacked-track i{display:block;height:100%}
.sale-total-table td:not(:first-child),.sale-total-table th:not(:first-child){text-align:center}.seller-username{display:block;margin-top:3px;color:#64748b;font-size:.68rem}.number-total{color:#1d4ed8;font-weight:900}
@media(max-width:1000px){.filter-grid,.report-metrics{grid-template-columns:repeat(2,1fr)}}@media(max-width:720px){.planner-heading,.planner-footer{padding:16px}.planner-footer,.report-grid{display:grid;grid-template-columns:1fr}.day-switcher{margin:0;justify-content:center}.planner-actions .btn{flex:1}.filter-grid,.report-metrics{grid-template-columns:1fr}.export-actions .btn{flex:1}.objective-chart>div{grid-template-columns:90px 1fr 30px}.history-heading{display:grid}.history-filters{margin-top:12px}.donut-layout,.specialty-layout{grid-template-columns:1fr;justify-items:center}.chart-legend{width:100%}.bubble-chart svg{min-width:580px}.bubble-chart{overflow-x:auto}.section-heading{align-items:flex-start}}
</style>
