<script setup lang="ts">
import axios from 'axios'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import CompatDateTimeInput from '../components/CompatDateTimeInput.vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import UiIcon from '../components/UiIcon.vue'
import { api, downloadFile } from '../api/client'
import { auth } from '../stores/auth'
import { i18n } from '../stores/i18n'
import { preferredRowsPerPage } from '../utils/preferences'
import type { Paginated, SalesCallActivity, SalesCallDashboard, SalesCallOptions } from '../types/api'
// import { money } from '../utils/format' // ใช้เมื่อเปิดคอลัมน์ Actual Value กลับมา

const props = withDefaults(defineProps<{ mode?: 'entry' | 'report' }>(), { mode: 'entry' })
const phoneMedia = window.matchMedia('(max-width: 640px)')
const phoneLayout = ref(phoneMedia.matches)
function syncPhoneLayout(event: MediaQueryListEvent) { phoneLayout.value = event.matches }

type DailyRow = Pick<SalesCallActivity, 'call_id' | 'place_of_work' | 'customer_name' | 'specialty' | 'department' | 'customer_group' | 'product_1' | 'call_detail_objective' | 'call_detail_outcome' | 'next_follow_up_date' | 'notes' | 'sm_comment'> & {
  objective: string
  outcome: string
  status: string
}

type OptionKey = 'specialties' | 'departments' | 'groups' | 'products' | 'objectives' | 'outcomes' | 'statuses'
type LookupCategory = 'specialty' | 'department' | 'group' | 'product' | 'objective' | 'outcome' | 'status'
type LookupOption = {
  id: number
  category: LookupCategory
  label: string
  is_active: boolean
  sort_order: number
  creator?: { username: string; first_name?: string | null; last_name?: string | null } | null
}

type ImportPreviewRow = Omit<DailyRow, 'call_id'> & { activity_date: string }
type ImportPreview = {
  summary: {
    file_name: string
    sheets_scanned: number
    sheets_used: number
    total_rows: number
    counted_calls: number
    dates_count: number
    date_from: string | null
    date_to: string | null
    preview_rows: number
    existing_rows_to_replace: number
  }
  quality: {
    detected_columns: string[]
    missing_columns: string[]
    blank_important_fields: Array<{ field: string; label: string; count: number }>
    invalid_required_rows: Array<{ date: string; sheet: string; row: number; missing_fields: string[] }>
    invalid_required_rows_count: number
    skipped_without_specialty: number
    skipped_without_date: number
  }
  preview: ImportPreviewRow[]
  warnings: string[]
}

type SalesCallSubmission = {
  seller_user_id: number
  username: string
  name: string
  department: string | null
  submitted: boolean
  archived_only: boolean
  activity_count: number
  call_count: number
  submission_method: 'excel_import' | 'website' | 'excel_import_and_website' | null
  first_submitted_at: string | null
  last_edited_at: string | null
  files: Array<{ id: number; original_filename: string; file_size: number; imported_rows: number; source_activity_rows: number; data_deleted_at: string | null; created_at: string | null }>
}

const lookupCategories: Array<{ value: LookupCategory; key: OptionKey; label: string }> = [
  { value: 'specialty', key: 'specialties', label: 'Specialty' },
  { value: 'department', key: 'departments', label: 'Department' },
  { value: 'group', key: 'groups', label: 'Group' },
  { value: 'product', key: 'products', label: 'Product-1' },
  { value: 'objective', key: 'objectives', label: 'Call Objective' },
  { value: 'outcome', key: 'outcomes', label: 'Call Outcome' },
  { value: 'status', key: 'statuses', label: 'Status' },
]

const managerView = computed(() => props.mode === 'report')
const canManageLookupOptions = computed(() => auth.can('approve_bills') || auth.can('system_admin'))
const loading = ref(true)
const saving = ref(false)
const lookupLoading = ref(false)
const lookupSavingId = ref<number | 'new' | null>(null)
const error = ref('')
const notice = ref('')
const options = ref<SalesCallOptions>({ sellers: [], seller_departments: [], specialties: [], departments: [], groups: [], products: [], objectives: [], outcomes: [], stages: [], statuses: [] })
const lookupOptions = ref<LookupOption[]>([])
const selectedLookupCategory = ref<LookupCategory>('specialty')
const newLookupLabel = ref('')
const dropdownModalOpen = ref(false)
const importInput = ref<HTMLInputElement | null>(null)
const importFile = ref<File | null>(null)
const importPreview = ref<ImportPreview | null>(null)
const importModalOpen = ref(false)
const importLoading = ref(false)
const importSaving = ref(false)
const selectedLookupRows = computed(() => lookupOptions.value.filter(item => item.category === selectedLookupCategory.value))

const selectedDate = ref(localDate(new Date()))
const loadedPeriodDate = ref(selectedDate.value)
const periodRows = ref<Array<DailyRow & { activity_date: string }>>([])
const history = ref<Paginated<SalesCallActivity> | null>(null)
const historyLoading = ref(false)
const historyLoadingMore = ref(false)
const historyActionLoading = ref(false)
const selectedHistoryIds = ref<number[]>([])
const historyLoadSentinel = ref<HTMLElement | null>(null)
const historyFilters = reactive({
  date_from: localDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)),
  date_to: localDate(new Date()),
  per_page: preferredRowsPerPage(100),
})

const dashboard = ref<SalesCallDashboard | null>(null)
const activities = ref<Paginated<SalesCallActivity> | null>(null)
const activitiesLoadingMore = ref(false)
const activitiesLoadSentinel = ref<HTMLElement | null>(null)
const submissionMonth = ref(localDate(new Date()).slice(0, 7))
const submissionDepartment = ref('')
const submissions = ref<SalesCallSubmission[]>([])
const submissionsLoading = ref(false)
const selectedSubmissionSellerIds = ref<number[]>([])
const editingManagedCall = ref<SalesCallActivity | null>(null)
const managerSaving = ref(false)
const filters = reactive({
  seller_ids: [] as number[],
  seller_departments: [] as string[],
  date_from: localDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1)),
  date_to: localDate(new Date()),
  products: [] as string[],
  outcomes: [] as string[],
  stages: [] as string[],
  statuses: [] as string[],
  customer: '',
  per_page: preferredRowsPerPage(100),
})

type AutosaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'waiting' | 'error'
const autosaveStatuses = reactive<Record<string, AutosaveStatus>>({})
const autosaveErrors = reactive<Record<string, string>>({})
const autosaveTimers = new Map<string, ReturnType<typeof setTimeout>>()
const autosaveVersions = new Map<string, number>()
const autosaveRequests = new Map<string, Promise<void>>()
let infiniteScrollObserver: IntersectionObserver | null = null

const maxSeller = computed(() => Math.max(...(dashboard.value?.by_seller.map(row => Number(row.total)) ?? [1]), 1))
const sellerAxisTicks = [0, 25, 50, 75, 100]
const submissionDepartments = computed(() => [...new Set(
  submissions.value.map(row => row.department?.trim() || 'ไม่ระบุแผนก'),
)].sort((a, b) => a.localeCompare(b, 'th')))
const filteredSubmissions = computed(() => submissionDepartment.value
  ? submissions.value.filter(row => (row.department?.trim() || 'ไม่ระบุแผนก') === submissionDepartment.value)
  : submissions.value)
const maxSellerDepartment = computed(() => Math.max(...(dashboard.value?.by_seller_department.map(row => Number(row.total)) ?? [1]), 1))
const chartColors = ['#22c55e', '#ef4444', '#94a3b8', '#2563eb', '#7c3aed', '#f59e0b', '#06b6d4', '#db2777', '#65a30d', '#ea580c', '#475569', '#c92d3e']
type ProductRow = SalesCallDashboard['by_product'][number]
type ProductTreemapItem = { row: ProductRow; weight: number; color: string }
type ProductTreemapRect = ProductTreemapItem & { x: number; y: number; width: number; height: number }

function layoutProductTreemap(items: ProductTreemapItem[], x = 0, y = 0, width = 100, height = 100): ProductTreemapRect[] {
  if (!items.length) return []
  if (items.length === 1) return [{ ...items[0], x, y, width, height }]

  const total = items.reduce((sum, item) => sum + item.weight, 0)
  const target = total / 2
  let running = 0
  let splitAt = 1
  let smallestDifference = Number.POSITIVE_INFINITY

  for (let index = 1; index < items.length; index += 1) {
    running += items[index - 1].weight
    const difference = Math.abs(target - running)
    if (difference < smallestDifference) {
      smallestDifference = difference
      splitAt = index
    }
  }

  const first = items.slice(0, splitAt)
  const second = items.slice(splitAt)
  const firstWeight = first.reduce((sum, item) => sum + item.weight, 0)
  const ratio = firstWeight / total

  if (width >= height) {
    const firstWidth = width * ratio
    return [
      ...layoutProductTreemap(first, x, y, firstWidth, height),
      ...layoutProductTreemap(second, x + firstWidth, y, width - firstWidth, height),
    ]
  }

  const firstHeight = height * ratio
  return [
    ...layoutProductTreemap(first, x, y, width, firstHeight),
    ...layoutProductTreemap(second, x, y + firstHeight, width, height - firstHeight),
  ]
}

const productTreemap = computed(() => layoutProductTreemap(
  (dashboard.value?.by_product ?? [])
    .filter(row => Number(row.total) > 0)
    .map((row, index) => ({
      row,
      weight: Number(row.total),
      color: chartColors[index % chartColors.length],
    })),
))
const outcomeRows = computed(() => {
  const rows = dashboard.value?.by_outcome ?? []
  const totalFor = (label: string) => Number(rows.find(row => row.label === label)?.total ?? 0)
  return [
    { label: 'Success', total: totalFor('Success') },
    { label: 'Failure + No Call', total: totalFor('Failure') + totalFor('No Call') },
  ]
})
const outcomeAttemptTotal = computed(() => outcomeRows.value.reduce((sum, row) => sum + Number(row.total), 0))
const outcomeDonut = computed(() => donutStyle(outcomeRows.value, ['#22c55e', '#ef4444']))
const specialtyDonut = computed(() => donutStyle(dashboard.value?.by_specialty.slice(0, 10) ?? [], chartColors))
const objectiveOutcomeTotal = computed(() => (dashboard.value?.by_objective ?? []).reduce(
  (sum, row) => sum + Number(row.success_calls) + Number(row.failure_calls) + Number(row.no_calls),
  0,
))
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
const trendChartWidth = computed(() => Math.max(1000, trendPoints.value.length * 56))
const reportTotal = computed(() => dashboard.value?.summary.total_activities ?? 0)
const periodBounds = computed(() => halfMonthBounds(selectedDate.value))
const periodDays = computed(() => dateRange(periodBounds.value.date_from, periodBounds.value.date_to).map(date => ({
  date,
  rows: periodRows.value.filter(row => row.activity_date === date),
})))
const periodCallCount = computed(() => periodRows.value.filter(isCountedCallRow).length)
const allHistoryPageSelected = computed(() => Boolean(history.value?.data.length) && history.value!.data.every(row => selectedHistoryIds.value.includes(row.call_id)))
const autosaveSummary = computed(() => {
  const states = Object.values(autosaveStatuses)
  if (states.includes('error')) return { label: 'บันทึกอัตโนมัติไม่สำเร็จ', className: 'error' }
  if (states.includes('waiting')) return { label: 'รอกรอก Call Outcome ให้ครบ', className: 'waiting' }
  if (states.includes('saving')) return { label: 'กำลังบันทึกอัตโนมัติ…', className: 'saving' }
  if (states.includes('pending')) return { label: 'กำลังรอบันทึก…', className: 'pending' }
  if (states.includes('saved')) return { label: 'บันทึกอัตโนมัติแล้ว', className: 'saved' }
  return { label: 'ระบบบันทึกให้อัตโนมัติ', className: 'idle' }
})
const hasUnsavedAutosave = computed(() => Object.values(autosaveStatuses)
  .some(status => ['pending', 'saving', 'waiting', 'error'].includes(status)))

function blankRow(activityDate = selectedDate.value): DailyRow & { activity_date: string } {
  return {
    activity_date: activityDate,
    call_id: 0, place_of_work: '', customer_name: '', specialty: '', department: '', customer_group: '',
    product_1: '', objective: options.value.objectives.includes('Awareness') ? 'Awareness' : (options.value.objectives[0] ?? 'Awareness'), call_detail_objective: '',
    outcome: '', call_detail_outcome: '',
    next_follow_up_date: null, notes: '', sm_comment: '', status: options.value.statuses.includes('ทำงาน') ? 'ทำงาน' : (options.value.statuses[0] ?? 'ทำงาน'),
  }
}

function isCountedCallRow(row: DailyRow): boolean {
  return Boolean(row.specialty?.trim()) && row.outcome !== 'No Call'
}

function activityToPlannerRow(row: SalesCallActivity): DailyRow & { activity_date: string } {
  return {
    call_id: row.call_id, activity_date: row.activity_date.slice(0, 10), place_of_work: row.place_of_work ?? '', customer_name: row.customer_name ?? '',
    specialty: row.specialty ?? '', department: row.department ?? '', customer_group: row.customer_group ?? '',
    product_1: row.product_1 ?? '', objective: row.objective, call_detail_objective: row.call_detail_objective ?? '',
    outcome: row.outcome, call_detail_outcome: row.call_detail_outcome ?? '', next_follow_up_date: row.next_follow_up_date,
    notes: row.notes ?? '', sm_comment: row.sm_comment ?? '', status: row.status,
  }
}

function halfMonthBounds(value: string): { date_from: string; date_to: string } {
  const [year, month, day] = value.split('-').map(Number)
  const fromDay = day <= 15 ? 1 : 16
  const toDay = day <= 15 ? 15 : new Date(year, month, 0).getDate()
  return {
    date_from: `${year}-${String(month).padStart(2, '0')}-${String(fromDay).padStart(2, '0')}`,
    date_to: `${year}-${String(month).padStart(2, '0')}-${String(toDay).padStart(2, '0')}`,
  }
}

function dateRange(from: string, to: string): string[] {
  const dates: string[] = []
  const current = new Date(`${from}T12:00:00`)
  const end = new Date(`${to}T12:00:00`)
  while (current <= end) {
    dates.push(localDate(current))
    current.setDate(current.getDate() + 1)
  }
  return dates
}

function percent(value: number | string, total = reportTotal.value): string {
  return total ? `${(Number(value) / total * 100).toFixed(1)}%` : '0.0%'
}

function sellerPlotX(total: number | string): number {
  return 80 + Number(total) / maxSeller.value * 870
}

function sellerPlotY(successRate: number | string): number {
  return 200 - Number(successRate) / 100 * 160
}

function sellerCallTick(percentValue: number): number {
  return Math.round(maxSeller.value * percentValue / 100)
}

function optionValues(field: OptionKey, currentValue = ''): string[] {
  const values = [...options.value[field], currentValue]
  const unique = new Map<string, string>()
  values.map(value => String(value || '').trim()).filter(Boolean).forEach((value) => {
    const key = value.toLocaleLowerCase('th-TH')
    if (!unique.has(key)) unique.set(key, value)
  })
  return [...unique.values()]
}
function searchableOptions(field: OptionKey, currentValue = '') {
  return optionValues(field, currentValue).map(value => ({ value, label: value }))
}

function syncActiveLookupOptions() {
  lookupCategories.forEach(({ value, key }) => {
    options.value[key] = lookupOptions.value
      .filter(item => item.category === value && item.is_active)
      .sort((a, b) => a.sort_order - b.sort_order || a.label.localeCompare(b.label, 'th'))
      .map(item => item.label)
  })
}

async function loadLookupOptions() {
  lookupLoading.value = true
  try {
    const response = await api.get<{ data: LookupOption[] }>('/api/v1/sales-call-options')
    lookupOptions.value = response.data.data
    syncActiveLookupOptions()
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลดรายการ Dropdown ส่วนกลางไม่สำเร็จ กรุณารัน sales_call_lookup_options.sql')
  } finally {
    lookupLoading.value = false
  }
}

async function addLookupOption() {
  const label = newLookupLabel.value.trim()
  if (!label) return
  lookupSavingId.value = 'new'
  error.value = ''
  notice.value = ''
  try {
    const response = await api.post<{ message: string }>('/api/v1/sales-call-options', {
      category: selectedLookupCategory.value,
      label,
    })
    newLookupLabel.value = ''
    notice.value = response.data.message
    await loadLookupOptions()
  } catch (cause) {
    error.value = apiMessage(cause, 'เพิ่มตัวเลือกไม่สำเร็จ')
  } finally {
    lookupSavingId.value = null
  }
}

async function saveLookupOption(option: LookupOption) {
  lookupSavingId.value = option.id
  error.value = ''
  notice.value = ''
  try {
    const response = await api.put<{ message: string }>(`/api/v1/sales-call-options/${option.id}`, {
      label: option.label,
      is_active: option.is_active,
      sort_order: option.sort_order,
    })
    notice.value = response.data.message
    await loadLookupOptions()
  } catch (cause) {
    error.value = apiMessage(cause, 'บันทึกตัวเลือกไม่สำเร็จ')
    await loadLookupOptions()
  } finally {
    lookupSavingId.value = null
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

function isMeaningfulPlannerRow(row: DailyRow): boolean {
  return [
    row.place_of_work, row.customer_name, row.specialty, row.department, row.customer_group,
    row.product_1, row.call_detail_objective, row.call_detail_outcome,
    row.next_follow_up_date, row.notes, row.sm_comment,
  ].some(value => String(value ?? '').trim() !== '')
}

function cancelAutosaveTimers() {
  autosaveTimers.forEach(timer => clearTimeout(timer))
  autosaveTimers.clear()
}

function scheduleDayAutosave(date: string, delay = 1500) {
  const version = (autosaveVersions.get(date) ?? 0) + 1
  autosaveVersions.set(date, version)
  autosaveStatuses[date] = 'pending'
  delete autosaveErrors[date]
  const existing = autosaveTimers.get(date)
  if (existing) clearTimeout(existing)
  autosaveTimers.set(date, setTimeout(() => {
    autosaveTimers.delete(date)
    void autosaveDay(date, version)
  }, delay))
}

async function autosaveDay(date: string, requestedVersion = autosaveVersions.get(date) ?? 0): Promise<void> {
  const activeRequest = autosaveRequests.get(date)
  if (activeRequest) {
    await activeRequest
    if ((autosaveVersions.get(date) ?? 0) >= requestedVersion) scheduleDayAutosave(date, 250)
    return
  }

  const dayRows = periodRows.value.filter(row => row.activity_date === date)
  if (dayRows.some(row => row.specialty?.trim() && !row.outcome?.trim())) {
    autosaveStatuses[date] = 'waiting'
    autosaveErrors[date] = 'กรุณาเลือก Call Outcome ให้ครบก่อนบันทึก'
    return
  }

  const meaningfulRows = dayRows.filter(isMeaningfulPlannerRow)
  autosaveStatuses[date] = 'saving'
  const request = (async () => {
    try {
      const response = await api.put<{ data: { date: string; rows: SalesCallActivity[] } }>('/api/v1/sales-calls/day', {
        date,
        rows: dayRows.map(row => ({ ...row, call_id: row.call_id || null })),
      })
      response.data.data.rows.forEach((saved, index) => {
        const target = meaningfulRows[index]
        if (target && periodRows.value.includes(target)) target.call_id = saved.call_id
      })
      delete autosaveErrors[date]
      autosaveStatuses[date] = (autosaveVersions.get(date) ?? 0) === requestedVersion ? 'saved' : 'pending'
    } catch (cause) {
      autosaveStatuses[date] = 'error'
      autosaveErrors[date] = apiMessage(cause, `บันทึกอัตโนมัติวันที่ ${displayDate(date)} ไม่สำเร็จ`)
    }
  })()

  autosaveRequests.set(date, request)
  await request.finally(() => autosaveRequests.delete(date))
  const statusAfterRequest = autosaveStatuses[date] as AutosaveStatus | undefined
  if (statusAfterRequest === 'pending') scheduleDayAutosave(date, 250)
}

async function flushPendingAutosaves(): Promise<void> {
  const dates = [...new Set([
    ...autosaveTimers.keys(),
    ...Object.entries(autosaveStatuses).filter(([, status]) => status === 'pending').map(([date]) => date),
  ])]
  dates.forEach((date) => {
    const timer = autosaveTimers.get(date)
    if (timer) clearTimeout(timer)
    autosaveTimers.delete(date)
  })
  await Promise.all(dates.map(date => autosaveDay(date, autosaveVersions.get(date) ?? 0)))
  await Promise.all([...autosaveRequests.values()])
  const pendingAfterActive = Object.entries(autosaveStatuses)
    .filter(([, status]) => status === 'pending')
    .map(([date]) => date)
  pendingAfterActive.forEach((date) => {
    const timer = autosaveTimers.get(date)
    if (timer) clearTimeout(timer)
    autosaveTimers.delete(date)
  })
  await Promise.all(pendingAfterActive.map(date => autosaveDay(date, autosaveVersions.get(date) ?? 0)))
}

function handleBeforeUnload(event: BeforeUnloadEvent) {
  if (!hasUnsavedAutosave.value) return
  event.preventDefault()
  event.returnValue = ''
}

function handleVisibilityChange() {
  if (document.visibilityState === 'hidden') void flushPendingAutosaves()
}

async function loadPeriod() {
  cancelAutosaveTimers()
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await api.get<{ data: { date_from: string; date_to: string; rows: SalesCallActivity[] }; options: SalesCallOptions }>('/api/v1/sales-calls/period', { params: periodBounds.value })
    options.value = response.data.options
    const loaded = response.data.data.rows.map(activityToPlannerRow)
    periodRows.value = dateRange(response.data.data.date_from, response.data.data.date_to)
      .flatMap(date => loaded.some(row => row.activity_date === date) ? loaded.filter(row => row.activity_date === date) : [blankRow(date)])
    loadedPeriodDate.value = selectedDate.value
    Object.keys(autosaveStatuses).forEach(key => delete autosaveStatuses[key])
    Object.keys(autosaveErrors).forEach(key => delete autosaveErrors[key])
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลดตาราง Call Sale ช่วงครึ่งเดือนไม่สำเร็จ')
    periodRows.value = dateRange(periodBounds.value.date_from, periodBounds.value.date_to).map(date => blankRow(date))
  } finally {
    loading.value = false
  }
}

async function savePeriod() {
  cancelAutosaveTimers()
  await Promise.all([...autosaveRequests.values()])
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await api.put<{ data: { date_from: string; date_to: string; rows: SalesCallActivity[] }; message: string }>('/api/v1/sales-calls/period', {
      ...periodBounds.value,
      rows: periodRows.value.map(row => ({ ...row, call_id: row.call_id || null })),
    })
    const saved = response.data.data.rows.map(activityToPlannerRow)
    periodRows.value = dateRange(response.data.data.date_from, response.data.data.date_to)
      .flatMap(date => saved.some(row => row.activity_date === date) ? saved.filter(row => row.activity_date === date) : [blankRow(date)])
    dateRange(response.data.data.date_from, response.data.data.date_to).forEach((date) => {
      autosaveStatuses[date] = 'saved'
      delete autosaveErrors[date]
    })
    notice.value = response.data.message
    await loadHistory()
  } catch (cause) {
    error.value = apiMessage(cause, 'บันทึกตาราง Call Sale ช่วงครึ่งเดือนไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function chooseImportFile() {
  importInput.value?.click()
}

async function previewImport(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  if (!file) return

  importFile.value = file
  importPreview.value = null
  importModalOpen.value = true
  importLoading.value = true
  error.value = ''
  notice.value = ''
  try {
    const form = new FormData()
    form.append('file', file)
    const response = await api.post<{ data: ImportPreview }>('/api/v1/sales-calls/import/preview', form)
    importPreview.value = response.data.data
  } catch (cause) {
    error.value = apiMessage(cause, 'ตรวจสอบไฟล์ Excel ไม่สำเร็จ')
    closeImportModal()
  } finally {
    importLoading.value = false
  }
}

function closeImportModal() {
  importModalOpen.value = false
  importPreview.value = null
  importFile.value = null
  if (importInput.value) importInput.value.value = ''
}

async function confirmImport() {
  if (!importFile.value || !importPreview.value?.summary.total_rows) return

  importSaving.value = true
  error.value = ''
  notice.value = ''
  try {
    const form = new FormData()
    form.append('file', importFile.value)
    form.append('replace_existing_dates', '1')
    const response = await api.post<{ data: { imported_rows: number; counted_calls: number; imported_dates: number; replaced_rows: number; date_from: string | null; date_to: string | null }; message: string }>('/api/v1/sales-calls/import', form)
    const result = response.data.data
    const successMessage = `${response.data.message}${result.replaced_rows ? ` และแทนที่ข้อมูลเดิม ${result.replaced_rows} รายการ` : ''}`
    // Import บันทึกทุกวันที่พบในไฟล์ทั้งเดือน ส่วนหน้าตารางแบ่งแสดงครั้งละครึ่งเดือนเท่านั้น
    if (result.date_from) selectedDate.value = result.date_from
    if (result.date_from) historyFilters.date_from = result.date_from
    if (result.date_to) historyFilters.date_to = result.date_to
    closeImportModal()
    await Promise.all([loadPeriod(), loadHistory()])
    notice.value = successMessage
  } catch (cause) {
    error.value = apiMessage(cause, 'นำเข้าข้อมูล Call Sale ไม่สำเร็จ')
  } finally {
    importSaving.value = false
  }
}

async function loadHistory(page = 1, append = false) {
  if (append) historyLoadingMore.value = true
  else historyLoading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: Paginated<SalesCallActivity> }>('/api/v1/sales-calls/history', {
      params: { ...historyFilters, page },
    })
    const nextPage = response.data.data
    if (append && history.value) {
      const rows = new Map(history.value.data.map(row => [row.call_id, row]))
      nextPage.data.forEach(row => rows.set(row.call_id, row))
      history.value = { ...nextPage, data: [...rows.values()] }
    } else {
      history.value = nextPage
      selectedHistoryIds.value = []
    }
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลดประวัติ Call Plan ไม่สำเร็จ')
  } finally {
    if (append) historyLoadingMore.value = false
    else historyLoading.value = false
  }
}

async function loadMoreHistory() {
  if (!history.value?.next_page_url || historyLoading.value || historyLoadingMore.value) return
  await loadHistory(history.value.current_page + 1, true)
}

function addRow(date: string) {
  const rowsForDate = periodRows.value.filter(row => row.activity_date === date)
  if (rowsForDate.length >= 100) {
    error.value = 'เพิ่มได้สูงสุด 100 Call ต่อวัน'
    return
  }
  const lastIndex = periodRows.value.reduce((found, row, index) => row.activity_date === date ? index : found, -1)
  periodRows.value.splice(lastIndex + 1, 0, blankRow(date))
}

function removeRow(row: DailyRow & { activity_date: string }) {
  const date = row.activity_date
  const index = periodRows.value.indexOf(row)
  if (index >= 0) periodRows.value.splice(index, 1)
  if (!periodRows.value.some(item => item.activity_date === row.activity_date)) periodRows.value.splice(index, 0, blankRow(row.activity_date))
  scheduleDayAutosave(date)
}

async function changePeriod(direction: number) {
  await flushPendingAutosaves()
  if (hasUnsavedAutosave.value && !confirm('ยังมีข้อมูลที่บันทึกอัตโนมัติไม่สำเร็จหรือกรอกไม่ครบ ต้องการเปลี่ยนช่วงและละทิ้งข้อมูลนั้นหรือไม่?')) return
  const [year, month, day] = selectedDate.value.split('-').map(Number)
  if (direction > 0) {
    selectedDate.value = day <= 15
      ? `${year}-${String(month).padStart(2, '0')}-16`
      : localDate(new Date(year, month, 1))
  } else {
    selectedDate.value = day > 15
      ? `${year}-${String(month).padStart(2, '0')}-01`
      : localDate(new Date(year, month - 2, 16))
  }
  await loadPeriod()
}

async function changePeriodFromPicker() {
  const nextDate = selectedDate.value
  selectedDate.value = loadedPeriodDate.value
  await flushPendingAutosaves()
  if (hasUnsavedAutosave.value && !confirm('ยังมีข้อมูลที่บันทึกอัตโนมัติไม่สำเร็จหรือกรอกไม่ครบ ต้องการเปิดช่วงใหม่หรือไม่?')) return
  selectedDate.value = nextDate
  await loadPeriod()
}

function toggleHistoryPage(checked: boolean) {
  const pageIds = history.value?.data.map(row => row.call_id) ?? []
  selectedHistoryIds.value = checked
    ? [...new Set([...selectedHistoryIds.value, ...pageIds])]
    : selectedHistoryIds.value.filter(id => !pageIds.includes(id))
}

async function editSelectedHistory() {
  if (selectedHistoryIds.value.length !== 1) {
    error.value = 'กรุณาเลือก 1 รายการสำหรับแก้ไข'
    return
  }
  const row = history.value?.data.find(item => item.call_id === selectedHistoryIds.value[0])
  if (!row) return
  selectedDate.value = row.activity_date.slice(0, 10)
  await loadPeriod()
  await nextTick()
  document.getElementById(`call-row-${row.call_id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function deleteSelectedHistory() {
  if (!selectedHistoryIds.value.length || !confirm(`ลบ Call Sale ที่เลือก ${selectedHistoryIds.value.length} รายการหรือไม่?`)) return
  historyActionLoading.value = true
  error.value = ''
  try {
    const ids = [...selectedHistoryIds.value]
    for (let index = 0; index < ids.length; index += 100) {
      await api.delete('/api/v1/sales-calls/history', { data: { call_ids: ids.slice(index, index + 100) } })
    }
    notice.value = `ลบประวัติ Call Sale ${ids.length} รายการเรียบร้อยแล้ว`
    await Promise.all([loadPeriod(), loadHistory()])
  } catch (cause) {
    error.value = apiMessage(cause, 'ลบประวัติ Call Sale ไม่สำเร็จ')
  } finally {
    historyActionLoading.value = false
  }
}

async function loadReport(page = 1, append = false) {
  if (append) activitiesLoadingMore.value = true
  else loading.value = true
  error.value = ''
  try {
    const params = { ...filters, page }
    const listParams = { ...params, include_options: 0 }
    if (append && activities.value) {
      const listResponse = await api.get<{ data: Paginated<SalesCallActivity> }>('/api/v1/sales-calls', { params: listParams })
      const nextPage = listResponse.data.data
      const rows = new Map(activities.value.data.map(row => [row.call_id, row]))
      nextPage.data.forEach(row => rows.set(row.call_id, row))
      activities.value = { ...nextPage, data: [...rows.values()] }
    } else {
      const [dashResponse, listResponse] = await Promise.all([
        api.get<{ data: SalesCallDashboard; options: SalesCallOptions }>('/api/v1/sales-calls/dashboard', { params }),
        api.get<{ data: Paginated<SalesCallActivity> }>('/api/v1/sales-calls', { params: listParams }),
      ])
      dashboard.value = dashResponse.data.data
      activities.value = listResponse.data.data
      options.value = dashResponse.data.options
    }
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลด Call Sale Report ไม่สำเร็จ')
  } finally {
    if (append) activitiesLoadingMore.value = false
    else loading.value = false
  }
}

async function loadMoreActivities() {
  if (!activities.value?.next_page_url || loading.value || activitiesLoadingMore.value) return
  await loadReport(activities.value.current_page + 1, true)
}

function setupInfiniteScroll() {
  infiniteScrollObserver?.disconnect()
  if (typeof IntersectionObserver === 'undefined') {
    void (async () => {
      while (history.value?.next_page_url) await loadMoreHistory()
      while (activities.value?.next_page_url) await loadMoreActivities()
    })()
    return
  }
  infiniteScrollObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return
      if (entry.target === historyLoadSentinel.value) void loadMoreHistory()
      if (entry.target === activitiesLoadSentinel.value) void loadMoreActivities()
    })
  }, { rootMargin: '300px 0px' })
  if (historyLoadSentinel.value) infiniteScrollObserver.observe(historyLoadSentinel.value)
  if (activitiesLoadSentinel.value) infiniteScrollObserver.observe(activitiesLoadSentinel.value)
}

async function loadSubmissions() {
  submissionsLoading.value = true
  selectedSubmissionSellerIds.value = []
  try {
    const response = await api.get<{ data: { month: string; rows: SalesCallSubmission[] } }>('/api/v1/sales-calls/submissions', { params: { month: submissionMonth.value } })
    submissions.value = response.data.data.rows
    if (submissionDepartment.value && !submissionDepartments.value.includes(submissionDepartment.value)) {
      submissionDepartment.value = ''
    }
  } catch (cause) {
    error.value = apiMessage(cause, 'โหลด Checklist การส่ง Call Sale ไม่สำเร็จ กรุณาตรวจสอบว่าได้รัน sales_call_reports.sql รุ่นล่าสุดแล้ว')
  } finally {
    submissionsLoading.value = false
  }
}

async function showSubmission(row: SalesCallSubmission) {
  const year = Number(submissionMonth.value.slice(0, 4))
  const month = Number(submissionMonth.value.slice(5, 7))
  filters.seller_ids = [row.seller_user_id]
  filters.date_from = `${submissionMonth.value}-01`
  filters.date_to = localDate(new Date(year, month, 0))
  await loadReport()
  await nextTick()
  document.getElementById('sales-call-raw-data')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function deleteSelectedSubmissionMonths() {
  if (!selectedSubmissionSellerIds.value.length || !confirm(`ลบข้อมูล Call Sale ทั้งเดือนของ Sale ที่เลือก ${selectedSubmissionSellerIds.value.length} คนหรือไม่? ไฟล์ Excel ต้นฉบับจะยังถูกเก็บไว้เป็นหลักฐาน`)) return
  submissionsLoading.value = true
  error.value = ''
  try {
    const response = await api.delete<{ message: string }>('/api/v1/sales-calls/months', {
      data: { items: selectedSubmissionSellerIds.value.map(seller_user_id => ({ seller_user_id, month: submissionMonth.value })) },
    })
    notice.value = response.data.message
    await Promise.all([loadSubmissions(), loadReport()])
  } catch (cause) {
    error.value = apiMessage(cause, 'ลบข้อมูล Call Sale รายเดือนไม่สำเร็จ')
  } finally {
    submissionsLoading.value = false
  }
}

function editManagedCall(row: SalesCallActivity) {
  editingManagedCall.value = { ...row }
}

async function saveManagedCall() {
  if (!editingManagedCall.value) return
  managerSaving.value = true
  error.value = ''
  try {
    const row = editingManagedCall.value
    const response = await api.put<{ message: string }>(`/api/v1/sales-calls/${row.call_id}`, {
      place_of_work: row.place_of_work,
      customer_name: row.customer_name,
      specialty: row.specialty,
      department: row.department,
      customer_group: row.customer_group,
      product_1: row.product_1,
      objective: row.objective,
      call_detail_objective: row.call_detail_objective,
      outcome: row.outcome,
      call_detail_outcome: row.call_detail_outcome,
      next_follow_up_date: row.next_follow_up_date,
      notes: row.notes,
      sm_comment: row.sm_comment,
      status: row.status,
    })
    editingManagedCall.value = null
    notice.value = response.data.message
    await Promise.all([loadReport(), loadSubmissions()])
  } catch (cause) {
    error.value = apiMessage(cause, 'แก้ไขข้อมูล Call Sale ไม่สำเร็จ')
  } finally {
    managerSaving.value = false
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
  return i18n.formatDate(value)
}

function displayDateTime(value: string | null): string {
  return i18n.formatDateTime(value)
}

function submissionMethodLabel(method: SalesCallSubmission['submission_method']): string {
  if (method === 'excel_import_and_website') return 'Import Excel + On Website'
  if (method === 'excel_import') return 'Import Excel'
  if (method === 'website') return 'On Website'
  return '-'
}

function objectiveBreakdown(row: SalesCallDashboard['by_objective'][number]): string {
  const total = Number(row.success_calls) + Number(row.failure_calls) + Number(row.no_calls)
  const failed = Number(row.failure_calls) + Number(row.no_calls)
  const totalShare = percent(total, objectiveOutcomeTotal.value)
  const details = `Success ${row.success_calls} (${percent(row.success_calls, total)}) · Failure + No Call ${failed} (${percent(failed, total)})`
  return i18n.isEnglish.value ? `${details} · Total share ${totalShare}` : `${details} · สัดส่วนทั้งหมด ${totalShare}`
}

function shortDate(value: string | null): string {
  return i18n.formatDate(value)
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
  if (typeof phoneMedia.addEventListener === 'function') phoneMedia.addEventListener('change', syncPhoneLayout)
  else phoneMedia.addListener(syncPhoneLayout)
  if (managerView.value) await Promise.all([loadReport(), loadSubmissions(), ...(canManageLookupOptions.value ? [loadLookupOptions()] : [])])
  else await Promise.all([loadPeriod(), loadHistory()])
  window.addEventListener('beforeunload', handleBeforeUnload)
  document.addEventListener('visibilitychange', handleVisibilityChange)
  await nextTick()
  setupInfiniteScroll()
})

onBeforeRouteLeave(async () => {
  if (managerView.value) return true
  await flushPendingAutosaves()
  if (!hasUnsavedAutosave.value) return true
  return confirm('ยังมีข้อมูลที่บันทึกอัตโนมัติไม่สำเร็จหรือกรอกไม่ครบ ต้องการออกจากหน้านี้และละทิ้งข้อมูลดังกล่าวหรือไม่?')
})

onBeforeUnmount(() => {
  if (typeof phoneMedia.removeEventListener === 'function') phoneMedia.removeEventListener('change', syncPhoneLayout)
  else phoneMedia.removeListener(syncPhoneLayout)
  cancelAutosaveTimers()
  infiniteScrollObserver?.disconnect()
  window.removeEventListener('beforeunload', handleBeforeUnload)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})
</script>

<template>
  <AppShell
    :title="managerView ? 'Call Sale Report' : 'Call Planner & Report'"
    :subtitle="managerView ? 'วิเคราะห์กิจกรรมของทีมขายและดูข้อมูลดิบ' : 'บันทึกแผนและผลการเข้าพบลูกค้าครั้งละครึ่งเดือน'"
  >
    <div v-if="error" class="alert alert-error">{{ error }}</div>
    <div v-if="notice" class="alert alert-success">{{ notice }}</div>

    <template v-if="!managerView">
      <section class="content-card daily-card period-planner">
        <div class="section-heading planner-heading">
          <div>
            <p class="eyebrow">HALF-MONTH CALL PLAN</p>
            <h2>ตาราง Call Sale วันที่ {{ displayDate(periodBounds.date_from) }} – {{ displayDate(periodBounds.date_to) }}</h2>
            <p class="muted">แสดงครั้งละครึ่งเดือน วันที่ของแต่ละแถวถูกล็อกตามตารางประจำวัน และบันทึกพร้อมกันทั้งช่วง</p>
          </div>
          <div class="planner-heading-actions">
            <input ref="importInput" class="visually-hidden" type="file" accept=".xlsx,.xls" @change="previewImport">
            <button class="btn import-button" type="button" :disabled="importLoading || saving" @click="chooseImportFile">
              {{ importLoading ? 'กำลังอ่าน Excel...' : 'นำเข้า Excel' }}
            </button>
            <span class="autosave-status" :class="`is-${autosaveSummary.className}`">{{ autosaveSummary.label }}</span>
            <div class="row-count">{{ periodCallCount }} Call</div>
          </div>
        </div>

        <div v-if="loading" class="loading-box">กำลังโหลดตาราง...</div>
        <div v-else class="period-days">
          <article v-for="day in periodDays" :key="day.date" class="planner-day" :class="{ 'is-selected-day': day.date === selectedDate }">
            <div class="planner-day-heading">
              <div><strong>{{ displayDate(day.date) }}</strong><small>{{ day.rows.filter(isCountedCallRow).length }} Call</small></div>
              <button class="btn add-row" type="button" @click="addRow(day.date)">＋ เพิ่ม Row วันนี้</button>
            </div>
            <div class="planner-scroll">
              <table class="planner-table" @input="scheduleDayAutosave(day.date)" @change="scheduleDayAutosave(day.date)">
                <thead><tr><th class="col-no">Call No.</th><th class="col-date">Date</th><th>PLACE OF WORK</th><th>CUSTOMER NAME</th><th>SPECIALTY</th><th>DEPARTMENT</th><th>GROUP</th><th>PRODUCT-1</th><th>CALL OBJECTIVE</th><th>CALL DETAIL (Obj)</th><th>CALL OUTCOME</th><th>CALL DETAIL (Out)</th><th>F/U DATE</th><th>NOTE</th><th>SM Comment</th><th>Status</th><th class="col-remove"></th></tr></thead>
                <tbody>
                  <tr v-for="(row, index) in day.rows" :id="row.call_id ? `call-row-${row.call_id}` : undefined" :key="row.call_id || `new-${day.date}-${index}`">
                    <td class="locked col-no" data-label="Call No."><strong>{{ index + 1 }}</strong></td><td class="locked col-date" data-label="วันที่">{{ displayDate(day.date) }}</td>
                    <td data-label="สถานที่ทำงาน"><textarea v-model.trim="row.place_of_work" rows="2" placeholder="สถานที่ทำงาน"></textarea></td><td data-label="ชื่อลูกค้า"><textarea v-model.trim="row.customer_name" rows="2" placeholder="ชื่อลูกค้า"></textarea></td>
                    <td data-label="Specialty"><SearchableSelect v-model="row.specialty" :options="searchableOptions('specialties', row.specialty || '')" /></td><td data-label="Department"><SearchableSelect v-model="row.department" :options="searchableOptions('departments', row.department || '')" /></td><td data-label="Group"><SearchableSelect v-model="row.customer_group" :options="searchableOptions('groups', row.customer_group || '')" /></td><td data-label="Product-1"><SearchableSelect v-model="row.product_1" :options="searchableOptions('products', row.product_1 || '')" /></td>
                    <td data-label="Call Objective"><SearchableSelect v-model="row.objective" :options="searchableOptions('objectives', row.objective)" /></td><td data-label="รายละเอียดวัตถุประสงค์"><textarea v-model.trim="row.call_detail_objective" rows="2" placeholder="รายละเอียดวัตถุประสงค์"></textarea></td><td data-label="Call Outcome"><SearchableSelect v-model="row.outcome" :options="searchableOptions('outcomes', row.outcome)" /></td><td data-label="รายละเอียดผลลัพธ์"><textarea v-model.trim="row.call_detail_outcome" rows="2" placeholder="รายละเอียดผลลัพธ์"></textarea></td>
                    <td data-label="วันติดตาม"><CompatDateTimeInput v-model="row.next_follow_up_date" mode="date" /></td><td data-label="หมายเหตุ"><textarea v-model.trim="row.notes" rows="2" placeholder="หมายเหตุ"></textarea></td><td data-label="SM Comment"><textarea :value="row.sm_comment || ''" class="sm-comment-readonly" :class="{ 'has-sm-comment': Boolean(row.sm_comment?.trim()) }" rows="2" readonly placeholder="SM ยังไม่มีความคิดเห็น" title="SM Comment แก้ไขได้จาก Call Sale Report โดยผู้มีสิทธิ์เท่านั้น"></textarea></td><td data-label="สถานะ"><SearchableSelect v-model="row.status" :options="searchableOptions('statuses', row.status)" /></td><td class="col-remove" data-label="ลบรายการ"><button class="remove-row" type="button" title="ลบแถว" :aria-label="`ลบ Call ลำดับ ${index + 1} วันที่ ${displayDate(day.date)}`" @click="removeRow(row)">×</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </article>
        </div>

        <div class="planner-footer">
          <div class="planner-actions">
            <button style="background-color: #88E788; color: black;" class="btn btn-primary save-table" type="button" :disabled="loading || saving" @click="savePeriod">{{ saving ? 'กำลังบันทึก...' : 'บันทึกทันที' }}</button>
          </div>
          <div class="day-switcher">
            <button type="button" aria-label="ครึ่งเดือนก่อนหน้า" @click="changePeriod(-1)">«</button>
            <CompatDateTimeInput v-model="selectedDate" mode="date" aria-label="เลือกช่วงครึ่งเดือน" @change="changePeriodFromPicker" />
            <strong>{{ displayDate(periodBounds.date_from) }} – {{ displayDate(periodBounds.date_to) }}</strong>
            <button type="button" aria-label="ครึ่งเดือนถัดไป" @click="changePeriod(1)">»</button>
          </div>
        </div>
      </section>


      <Teleport to="body">
        <div v-if="importModalOpen" class="lookup-modal-backdrop" role="presentation" @click.self="!importSaving && closeImportModal()" @keydown.esc="!importSaving && closeImportModal()">
          <section class="lookup-modal import-modal" role="dialog" aria-modal="true" aria-labelledby="import-modal-title">
            <div class="section-heading lookup-modal-header">
              <div>
                <p class="eyebrow">EXCEL IMPORT PREVIEW</p>
                <h2 id="import-modal-title">ตรวจสอบข้อมูลก่อนนำเข้า</h2>
                <p class="muted">ระบบนำเข้าทุกวันที่พบในไฟล์ตลอดทั้งเดือน แล้วแบ่งหน้าแสดงผลครั้งละครึ่งเดือน โดยนับเฉพาะแถวที่มี Specialty เป็น Call</p>
              </div>

              <button class="lookup-modal-close" type="button" aria-label="ปิดหน้าต่างนำเข้า Excel" :disabled="importSaving" @click="closeImportModal">×</button>
            </div>

            <div v-if="importLoading" class="loading-box">กำลังอ่านโครงสร้างและตรวจสอบข้อมูลในไฟล์...</div>
            <template v-else-if="importPreview">
              <div class="import-summary">
                <article><span>แถวกิจกรรมที่จะจัดเก็บ</span><strong>{{ importPreview.summary.total_rows }}</strong></article>
                <article><span>Call ที่นำไปคำนวณ KPI</span><strong>{{ importPreview.summary.counted_calls }}</strong></article>
                <article><span>จำนวนวันที่จะนำเข้า</span><strong>{{ importPreview.summary.dates_count }}</strong></article>
                <article><span>ช่วงวันที่ทั้งหมดในไฟล์</span><strong>{{ displayDate(importPreview.summary.date_from) }} – {{ displayDate(importPreview.summary.date_to) }}</strong></article>
                <article :class="{ warning: importPreview.summary.existing_rows_to_replace > 0 }"><span>ข้อมูลเดิมที่จะถูกแทนที่</span><strong>{{ importPreview.summary.existing_rows_to_replace }}</strong></article>
              </div>

              <div class="import-quality-grid">
                <article class="quality-card quality-good">
                  <strong>คอลัมน์ที่ตรวจพบ ({{ importPreview.quality.detected_columns.length }})</strong>
                  <p>{{ importPreview.quality.detected_columns.join(', ') || 'ไม่พบหัวคอลัมน์ที่รองรับ' }}</p>
                </article>
                <article class="quality-card" :class="{ 'quality-warning': importPreview.quality.missing_columns.length }">
                  <strong>คอลัมน์ที่ขาด ({{ importPreview.quality.missing_columns.length }})</strong>
                  <p>{{ importPreview.quality.missing_columns.join(', ') || 'ครบทุกคอลัมน์' }}</p>
                </article>
                <article class="quality-card" :class="{ 'quality-warning': importPreview.quality.blank_important_fields.length }">
                  <strong>ช่องสำคัญที่เว้นว่าง</strong>
                  <p v-if="importPreview.quality.blank_important_fields.length"><span v-for="item in importPreview.quality.blank_important_fields" :key="item.field">{{ item.label }} {{ item.count }} แถว</span></p>
                  <p v-else>ข้อมูลสำคัญครบทุกแถวที่พร้อมนำเข้า</p>
                </article>
                <article class="quality-card" :class="{ 'quality-warning': importPreview.quality.skipped_without_date }">
                  <strong>กิจกรรมที่ไม่ใช่ Call</strong>
                  <p>ไม่มี Specialty แต่ยังจัดเก็บเพื่อคำนวณวันทำงาน {{ importPreview.quality.skipped_without_specialty }} แถว · ระบุวันที่ไม่ได้และถูกข้าม {{ importPreview.quality.skipped_without_date }} แถว</p>
                </article>
              </div>

              <div v-if="importPreview.quality.invalid_required_rows_count" class="required-errors">
                <strong>ยังนำเข้าไม่ได้ — Call ที่มี Specialty ต้องกรอกข้อมูลบังคับครบ ({{ importPreview.quality.invalid_required_rows_count }} แถว)</strong>
                <p>กรุณาแก้ PLACE OF WORK, CUSTOMER NAME, SPECIALTY, DEPARTMENT, GROUP, PRODUCT-1, CALL OBJECTIVE และ CALL OUTCOME ให้ครบทุก Call</p>
                <div class="required-errors-scroll">
                  <table class="required-errors-table">
                    <thead><tr><th>วันที่</th><th>ชีต / แถว Excel</th><th>ข้อมูลที่ต้องกรอกเพิ่ม</th></tr></thead>
                    <tbody><tr v-for="row in importPreview.quality.invalid_required_rows" :key="`${row.sheet}-${row.row}`"><td>{{ displayDate(row.date) }}</td><td>{{ row.sheet }} / {{ row.row }}</td><td>{{ row.missing_fields.join(', ') }}</td></tr></tbody>
                  </table>
                </div>
              </div>

              <div v-if="importPreview.warnings.length" class="import-warnings">
                <strong>รายการที่ควรตรวจสอบ ({{ importPreview.warnings.length }})</strong>
                <ul><li v-for="warning in importPreview.warnings.slice(0, 30)" :key="warning">{{ warning }}</li></ul>
                <small v-if="importPreview.warnings.length > 30">แสดง 30 รายการแรกจากทั้งหมด {{ importPreview.warnings.length }} รายการ</small>
              </div>

              <div class="import-preview-scroll">
                <table class="import-preview-table">
                  <thead><tr><th>Date</th><th>PLACE OF WORK</th><th>CUSTOMER</th><th>SPECIALTY</th><th>DEPARTMENT</th><th>GROUP</th><th>PRODUCT</th><th>OBJECTIVE</th><th>OUTCOME</th><th>Status</th></tr></thead>
                  <tbody>
                    <tr v-for="(row, index) in importPreview.preview" :key="`${row.activity_date}-${index}`"><td>{{ displayDate(row.activity_date) }}</td><td>{{ row.place_of_work || '-' }}</td><td>{{ row.customer_name || '-' }}</td><td>{{ row.specialty || '-' }}</td><td>{{ row.department || '-' }}</td><td>{{ row.customer_group || '-' }}</td><td>{{ row.product_1 || '-' }}</td><td>{{ row.objective }}</td><td>{{ row.outcome }}</td><td>{{ row.status }}</td></tr>
                    <tr v-if="!importPreview.preview.length"><td colspan="10" class="empty-cell">ไม่พบแถวข้อมูลที่สามารถนำเข้าได้</td></tr>
                  </tbody>
                </table>
              </div>
              <p v-if="importPreview.summary.total_rows > importPreview.summary.preview_rows" class="muted import-preview-note">ตารางแสดงตัวอย่าง {{ importPreview.summary.preview_rows }} รายการแรก ระบบจะนำเข้าทั้งหมด {{ importPreview.summary.total_rows }} รายการ</p>
              <div class="import-confirmation">
                <p><strong>โปรดตรวจสอบก่อนยืนยัน:</strong> ระบบจะนำเข้าทุกวันที่พบในไฟล์พร้อมกันทั้งเดือน กิจกรรมที่ไม่มี Specialty จะช่วยคำนวณ Working Day แต่ไม่ถูกนับเป็น Call และ Outcome แบบ No Call จะไม่รวมใน Total Call</p>
                <div><button class="btn" type="button" :disabled="importSaving" @click="closeImportModal">ยกเลิก</button><button class="btn btn-primary" type="button" :disabled="importSaving || !importPreview.summary.total_rows || importPreview.quality.invalid_required_rows_count > 0" @click="confirmImport">{{ importPreview.quality.invalid_required_rows_count ? 'กรุณาแก้ข้อมูลบังคับก่อน' : (importSaving ? 'กำลังนำเข้า...' : `ยืนยันนำเข้า ${importPreview.summary.total_rows} รายการ`) }}</button></div>
              </div>
            </template>
          </section>
        </div>
      </Teleport>

      <section class="content-card section-gap history-card">
        <div class="section-heading history-heading">
          <div>
            <p class="eyebrow">MY CALL PLAN HISTORY</p>
            <h2>ประวัติ Call Plan ของฉัน</h2>
            <p class="muted">{{ i18n.isEnglish.value ? `Showing ${history?.total ?? 0} items recorded by your account` : `แสดงเฉพาะข้อมูลที่บัญชีของคุณบันทึกไว้ ${history?.total ?? 0} รายการ` }}</p>
          </div>
          <div class="history-filters">
            <label>ตั้งแต่<CompatDateTimeInput v-model="historyFilters.date_from" mode="date" /></label>
            <label>ถึง<CompatDateTimeInput v-model="historyFilters.date_to" mode="date" /></label>
            <button class="btn btn-primary" type="button" :disabled="historyLoading" @click="loadHistory()">ดูประวัติ</button>
          </div>
        </div>
        <div class="history-bulk-actions">
          <span>เลือกแล้ว <strong>{{ selectedHistoryIds.length }}</strong> รายการ</span>
          <button class="btn" type="button" :disabled="selectedHistoryIds.length !== 1 || historyActionLoading" @click="editSelectedHistory">แก้ไขรายการที่เลือก</button>
          <button class="btn danger-action" type="button" :disabled="!selectedHistoryIds.length || historyActionLoading" @click="deleteSelectedHistory">{{ historyActionLoading ? 'กำลังลบ...' : 'ลบรายการที่เลือก' }}</button>
        </div>
        <div v-if="historyLoading" class="loading-box">กำลังโหลดประวัติ...</div>
        <div v-else class="raw-scroll">
          <div v-if="phoneLayout" class="mobile-records">
            <article v-for="row in history?.data" :key="row.call_id" class="mobile-record" :class="{ 'is-selected-history': selectedHistoryIds.includes(row.call_id) }">
              <header><label><input v-model="selectedHistoryIds" type="checkbox" :value="row.call_id" :aria-label="`เลือก Call ${row.call_no} วันที่ ${displayDate(row.activity_date)}`"><strong>{{ displayDate(row.activity_date) }} · Call {{ row.call_no }}</strong></label><span class="outcome-chip" :class="(row.outcome || '').toLowerCase().replace(' ', '-')">{{ row.outcome || '-' }}</span></header>
              <p><b>{{ row.customer_name || row.place_of_work || 'กิจกรรมอื่น' }}</b><small>{{ row.place_of_work || '-' }}</small></p>
              <div class="mobile-record-meta"><span>{{ row.specialty || 'ไม่มี Specialty' }}</span><span>{{ row.product_1 || '-' }}</span><span>{{ row.status }}</span></div>
              <p v-if="row.sm_comment" class="mobile-record-comment"><strong>SM Comment:</strong> {{ row.sm_comment }}</p>
              <details><summary>ดูข้อมูลทั้งหมด</summary><dl><div><dt>Department / Group</dt><dd>{{ row.department || '-' }} / {{ row.customer_group || '-' }}</dd></div><div><dt>วัตถุประสงค์</dt><dd>{{ row.objective }} · {{ row.call_detail_objective || '-' }}</dd></div><div><dt>ผลลัพธ์</dt><dd>{{ row.call_detail_outcome || '-' }}</dd></div><div><dt>วันติดตาม</dt><dd>{{ displayDate(row.next_follow_up_date) }}</dd></div><div><dt>หมายเหตุ</dt><dd>{{ row.notes || '-' }}</dd></div></dl></details>
            </article>
            <p v-if="!history?.data.length" class="empty-cell">ยังไม่มี Call Plan ในช่วงวันที่เลือก</p>
          </div>
          <table v-else class="raw-table sale-history-table">
            <thead><tr><th class="history-check"><input type="checkbox" :checked="allHistoryPageSelected" aria-label="เลือกข้อมูลที่โหลดแล้วทั้งหมด" @change="toggleHistoryPage(($event.target as HTMLInputElement).checked)"></th><th>Date</th><th>Call No.</th><th>PLACE OF WORK</th><th>CUSTOMER NAME</th><th>SPECIALTY</th><th>DEPARTMENT</th><th>GROUP</th><th>PRODUCT-1</th><th>CALL OBJECTIVE</th><th>CALL DETAIL (Obj)</th><th>CALL OUTCOME</th><th>CALL DETAIL (Out)</th><th>F/U DATE</th><th>NOTE</th><th>SM Comment</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="row in history?.data" :key="row.call_id" :class="{ 'is-selected-history': selectedHistoryIds.includes(row.call_id) }"><td class="history-check"><input v-model="selectedHistoryIds" type="checkbox" :value="row.call_id" :aria-label="`เลือก Call ${row.call_no} วันที่ ${displayDate(row.activity_date)}`"></td><td>{{ displayDate(row.activity_date) }}</td><td>{{ row.call_no }}</td><td>{{ row.place_of_work || '-' }}</td><td>{{ row.customer_name || '-' }}</td><td>{{ row.specialty || '-' }}</td><td>{{ row.department || '-' }}</td><td>{{ row.customer_group || '-' }}</td><td>{{ row.product_1 || '-' }}</td><td>{{ row.objective }}</td><td>{{ row.call_detail_objective || '-' }}</td><td><span class="outcome-chip" :class="row.outcome.toLowerCase().replace(' ', '-')">{{ row.outcome }}</span></td><td>{{ row.call_detail_outcome || '-' }}</td><td>{{ displayDate(row.next_follow_up_date) }}</td><td>{{ row.notes || '-' }}</td><td class="sm-comment-cell" :class="{ 'has-sm-comment': Boolean(row.sm_comment?.trim()) }">{{ row.sm_comment || '-' }}</td><td><span class="status-chip" :class="row.status === 'ทำงาน' ? 'work' : 'leave'">{{ row.status }}</span></td></tr>
              <tr v-if="!history?.data.length"><td colspan="17" class="empty-cell">ยังไม่มี Call Plan ในช่วงวันที่เลือก</td></tr>
            </tbody>
          </table>
        </div>
        <div ref="historyLoadSentinel" class="infinite-load-sentinel"><span v-if="historyLoadingMore">กำลังโหลดข้อมูลเพิ่มเติม…</span><span v-else-if="history?.next_page_url">เลื่อนลงเพื่อโหลดข้อมูลต่อ</span><span v-else-if="history?.data.length">{{ i18n.isEnglish.value ? `All ${history.total} items displayed` : `แสดงครบ ${history.total} รายการแล้ว` }}</span></div>
      </section>
    </template>

    <template v-else>
      <section class="content-card section-gap submission-card">
        <div class="section-heading">
          <div><p class="eyebrow">MONTHLY SUBMISSION CHECKLIST</p><h2>Checklist การส่ง Call Sale รายเดือน</h2><p class="muted">ตรวจว่า Sale คนใดส่งข้อมูลแล้ว ดาวน์โหลดไฟล์ Excel ต้นฉบับ หรือจัดการข้อมูลทั้งเดือน</p></div>
          <div class="submission-actions"><label>เดือน<CompatDateTimeInput v-model="submissionMonth" mode="month" @change="loadSubmissions" /></label><label>แผนก<select v-model="submissionDepartment" @change="selectedSubmissionSellerIds = []"><option value="">ทุกแผนก</option><option v-for="department in submissionDepartments" :key="department" :value="department">{{ department }}</option></select></label><button class="btn" type="button" :disabled="submissionsLoading" @click="loadSubmissions">รีเฟรช</button><button class="btn danger-action" type="button" :disabled="submissionsLoading || !selectedSubmissionSellerIds.length" @click="deleteSelectedSubmissionMonths">ลบข้อมูลทั้งเดือนที่เลือก</button></div>
        </div>
        <div v-if="submissionsLoading" class="loading-box">กำลังตรวจสอบรายการส่ง Call Sale...</div>
        <div v-else class="submission-results">
          <div v-if="phoneLayout" class="mobile-submissions">
            <article v-for="row in filteredSubmissions" :key="row.seller_user_id" class="mobile-submission">
              <header><label><input v-model="selectedSubmissionSellerIds" type="checkbox" :value="row.seller_user_id" :disabled="!row.activity_count" :aria-label="`เลือกข้อมูลรายเดือนของ ${row.name}`"><strong>{{ row.name }}</strong></label><span class="submission-status" :class="row.submitted ? 'submitted' : 'missing'">{{ row.submitted ? 'ส่งแล้ว' : 'ยังไม่ส่ง' }}</span></header>
              <small>{{ row.username }} · {{ row.department || '-' }}</small>
              <dl><div><dt>Total Call</dt><dd>{{ row.call_count }}</dd></div><div><dt>วิธีส่ง</dt><dd>{{ submissionMethodLabel(row.submission_method) }}</dd></div><div><dt>เวลาที่ส่ง</dt><dd>{{ displayDateTime(row.first_submitted_at) }}</dd></div><div><dt>แก้ไขล่าสุด</dt><dd>{{ displayDateTime(row.last_edited_at) }}</dd></div></dl>
              <small v-if="row.submission_method === 'excel_import_and_website'" class="submission-method-note">ข้อมูลปัจจุบันอาจต่างจากไฟล์ต้นฉบับ</small>
              <details v-if="row.files.length"><summary>ไฟล์ Excel ต้นฉบับ ({{ row.files.length }})</summary><div class="source-files"><button v-for="file in row.files" :key="file.id" class="file-download" type="button" @click="downloadFile(`/api/v1/sales-calls/imports/${file.id}/download`, file.original_filename)"><strong><UiIcon name="download" :size="15" /> {{ file.original_filename }}</strong><small>นำเข้า {{ file.imported_rows }} แถว · กิจกรรมที่ไม่มี Specialty {{ file.source_activity_rows }} แถว<span v-if="file.data_deleted_at"> · ข้อมูลในระบบถูกลบแล้ว (เก็บไฟล์หลักฐานไว้)</span></small></button></div></details>
              <small v-else class="muted">ยังไม่มีไฟล์ Excel ที่ Import ผ่านระบบสำเร็จ</small>
              <button class="btn" type="button" :disabled="!row.activity_count" @click="showSubmission(row)">ดู/แก้ไขข้อมูล</button>
            </article>
            <p v-if="!filteredSubmissions.length" class="empty-cell">ไม่พบรายชื่อ Sale ในแผนกที่เลือก</p>
          </div>
          <div v-else class="summary-scroll"><table class="summary-table submission-table"><thead><tr><th>เลือก</th><th>สถานะ</th><th>Sale</th><th>แผนก</th><th>Total Call</th><th>วิธีส่ง</th><th>เวลาที่ส่ง</th><th>แก้ไขล่าสุด</th><th>ไฟล์ Excel ต้นฉบับ / กิจกรรมอื่น</th><th>จัดการ</th></tr></thead><tbody>
          <tr v-for="row in filteredSubmissions" :key="row.seller_user_id"><td class="history-check"><input v-model="selectedSubmissionSellerIds" type="checkbox" :value="row.seller_user_id" :disabled="!row.activity_count"></td><td><span class="submission-status" :class="row.submitted ? 'submitted' : 'missing'">{{ row.submitted ? 'ส่งแล้ว' : 'ยังไม่ส่ง' }}</span></td><td><strong>{{ row.name }}</strong><small class="seller-username">{{ row.username }}</small></td><td>{{ row.department || '-' }}</td><td class="number-total">{{ row.call_count }}</td><td><span class="submission-method" :class="`submission-method--${row.submission_method || 'none'}`">{{ submissionMethodLabel(row.submission_method) }}</span><small v-if="row.submission_method === 'excel_import_and_website'" class="submission-method-note">ข้อมูลปัจจุบันอาจต่างจากไฟล์ต้นฉบับ</small></td><td>{{ displayDateTime(row.first_submitted_at) }}</td><td>{{ displayDateTime(row.last_edited_at) }}</td><td><div v-if="row.files.length" class="source-files"><button v-for="file in row.files" :key="file.id" class="file-download" type="button" @click="downloadFile(`/api/v1/sales-calls/imports/${file.id}/download`, file.original_filename)"><strong><UiIcon name="download" :size="15" /> {{ file.original_filename }}</strong><small>นำเข้า {{ file.imported_rows }} แถว · กิจกรรมที่ไม่มี Specialty {{ file.source_activity_rows }} แถว<span v-if="file.data_deleted_at"> · ข้อมูลในระบบถูกลบแล้ว (เก็บไฟล์หลักฐานไว้)</span></small></button></div><span v-else class="muted">ยังไม่มีไฟล์ Excel ที่ Import ผ่านระบบสำเร็จ</span></td><td><button class="btn" type="button" :disabled="!row.activity_count" @click="showSubmission(row)">ดู/แก้ไขข้อมูล</button></td></tr>
          <tr v-if="!filteredSubmissions.length"><td colspan="10" class="empty-cell">ไม่พบรายชื่อ Sale ในแผนกที่เลือก</td></tr>
          </tbody></table></div>
        </div>
      </section>

      <section class="content-card">
        <div class="section-heading">
          <div><p class="eyebrow">REPORT FILTERS</p><h2>ตัวกรองรายงานทีมขาย</h2></div>
          <div class="export-actions"><button v-if="canManageLookupOptions" class="btn" type="button" @click="dropdownModalOpen = true"><UiIcon name="settings" :size="16" />จัดการ Dropdown</button><button class="btn" @click="downloadFile(exportUrl('/api/v1/sales-calls/export.xlsx'), 'Call_Sale_Report.xlsx')">Export Excel + Raw Data</button><button class="btn btn-primary" @click="downloadFile(exportUrl('/api/v1/sales-calls/dashboard.pdf'), 'Call_Sale_Dashboard.pdf')">Export Dashboard PDF</button></div>
        </div>
        <div class="filter-grid">
          <label class="desktop-multiselect">Sale (เลือกได้หลายคน)<select v-model="filters.seller_ids" multiple><option v-for="seller in options.sellers" :key="seller.id" :value="seller.id">{{ [seller.first_name, seller.last_name].filter(Boolean).join(' ') || seller.username }}</option></select></label>
          <details class="mobile-filter-checklist"><summary>Sale · เลือกแล้ว {{ filters.seller_ids.length }} คน</summary><div><label v-for="seller in options.sellers" :key="seller.id"><input v-model="filters.seller_ids" type="checkbox" :value="seller.id"><span>{{ [seller.first_name, seller.last_name].filter(Boolean).join(' ') || seller.username }}</span></label></div></details>
          <label class="desktop-multiselect">แผนกของ Sale (เลือกได้หลายแผนก)<select v-model="filters.seller_departments" multiple><option v-for="department in options.seller_departments" :key="department">{{ department }}</option></select></label>
          <details class="mobile-filter-checklist"><summary>แผนก · เลือกแล้ว {{ filters.seller_departments.length }}</summary><div><label v-for="department in options.seller_departments" :key="department"><input v-model="filters.seller_departments" type="checkbox" :value="department"><span>{{ department }}</span></label></div></details>
          <label>ตั้งแต่วันที่<CompatDateTimeInput v-model="filters.date_from" mode="date" /></label>
          <label>ถึงวันที่<CompatDateTimeInput v-model="filters.date_to" mode="date" /></label>
          <label>ค้นหาโรงพยาบาล/ลูกค้า<input v-model.trim="filters.customer" placeholder="PLACE OF WORK หรือ CUSTOMER NAME"></label>
          <label class="desktop-multiselect">Product<select v-model="filters.products" multiple><option v-for="item in options.products" :key="item">{{ item }}</option></select></label>
          <details class="mobile-filter-checklist"><summary>Product · เลือกแล้ว {{ filters.products.length }}</summary><div><label v-for="item in options.products" :key="item"><input v-model="filters.products" type="checkbox" :value="item"><span>{{ item }}</span></label></div></details>
          <label class="desktop-multiselect">Call Outcome<select v-model="filters.outcomes" multiple><option v-for="item in options.outcomes" :key="item">{{ item }}</option></select></label>
          <details class="mobile-filter-checklist"><summary>Call Outcome · เลือกแล้ว {{ filters.outcomes.length }}</summary><div><label v-for="item in options.outcomes" :key="item"><input v-model="filters.outcomes" type="checkbox" :value="item"><span>{{ item }}</span></label></div></details>
          <label class="desktop-multiselect">Status<select v-model="filters.statuses" multiple><option v-for="item in options.statuses" :key="item">{{ item }}</option></select></label>
          <details class="mobile-filter-checklist"><summary>Status · เลือกแล้ว {{ filters.statuses.length }}</summary><div><label v-for="item in options.statuses" :key="item"><input v-model="filters.statuses" type="checkbox" :value="item"><span>{{ item }}</span></label></div></details>
        </div>
        <div class="filter-actions"><button class="btn btn-primary" @click="loadReport()">ใช้ตัวกรอง</button><small class="muted desktop-filter-hint">กด Ctrl ค้างเพื่อเลือกหลายรายการ</small><small class="muted mobile-filter-hint">แตะเลือกได้หลายรายการ แล้วกดใช้ตัวกรอง</small></div>
      </section>

      <Teleport to="body">
      <div v-if="dropdownModalOpen" class="lookup-modal-backdrop" role="presentation" @click.self="dropdownModalOpen = false" @keydown.esc="dropdownModalOpen = false">
      <section class="lookup-modal" role="dialog" aria-modal="true" aria-labelledby="lookup-modal-title">
        <div class="section-heading lookup-modal-header">
          <div>
            <p class="eyebrow">CALL SALE MASTER OPTIONS</p>
            <h2 id="lookup-modal-title">จัดการ Dropdown ส่วนกลาง</h2>
            <p class="muted">รายการที่เปิดใช้งานจะเหมือนกันสำหรับ Sale ทุกคน การปิดใช้งานไม่ลบข้อมูล Call เดิม</p>
          </div>
          <button class="lookup-modal-close" type="button" aria-label="ปิดหน้าต่างจัดการ Dropdown" @click="dropdownModalOpen = false">×</button>
        </div>

        <div class="lookup-tabs" role="tablist" aria-label="หมวด Dropdown">
          <button
            v-for="category in lookupCategories"
            :key="category.value"
            type="button"
            :class="{ active: selectedLookupCategory === category.value }"
            @click="selectedLookupCategory = category.value"
          >{{ category.label }}</button>
        </div>

        <form class="lookup-add" @submit.prevent="addLookupOption">
          <label>
            เพิ่มรายการใน {{ lookupCategories.find(item => item.value === selectedLookupCategory)?.label }}
            <input v-model.trim="newLookupLabel" maxlength="255" placeholder="กรอกชื่อตัวเลือกใหม่">
          </label>
          <button class="btn btn-primary" type="submit" :disabled="lookupSavingId !== null || !newLookupLabel.trim()">
            {{ lookupSavingId === 'new' ? 'กำลังเพิ่ม...' : '＋ เพิ่มตัวเลือก' }}
          </button>
        </form>

        <div v-if="lookupLoading" class="loading-box">กำลังโหลดตัวเลือก...</div>
        <div v-else class="lookup-table-wrap">
          <table class="lookup-table">
            <thead><tr><th>ลำดับ</th><th>ชื่อตัวเลือก</th><th>สถานะ</th><th>ผู้เพิ่ม</th><th></th></tr></thead>
            <tbody>
              <tr v-for="option in selectedLookupRows" :key="option.id" :class="{ inactive: !option.is_active }">
                <td><input v-model.number="option.sort_order" class="sort-input" type="number" min="0" max="100000"></td>
                <td><input v-model.trim="option.label" maxlength="255"></td>
                <td><label class="active-toggle"><input v-model="option.is_active" type="checkbox"><span>{{ option.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span></label></td>
                <td>{{ option.creator?.username || 'ข้อมูลเริ่มต้น' }}</td>
                <td><button class="btn" type="button" :disabled="lookupSavingId !== null || !option.label.trim()" @click="saveLookupOption(option)">{{ lookupSavingId === option.id ? 'กำลังบันทึก...' : 'บันทึก' }}</button></td>
              </tr>
              <tr v-if="!selectedLookupRows.length"><td colspan="5" class="empty-cell">ยังไม่มีตัวเลือกในหมวดนี้</td></tr>
            </tbody>
          </table>
        </div>
      </section>
      </div>
      </Teleport>

      <template v-if="dashboard && !loading">
        <div class="report-metrics section-gap">
          <article><span>Total Call</span><strong>{{ i18n.formatNumber(dashboard.summary.total_activities) }}</strong><small>Call ตามตัวกรอง</small></article>
          <article class="success"><span>Success Call</span><strong>{{ i18n.formatNumber(dashboard.summary.success_calls) }}</strong><small>Outcome Success</small></article>
          <article class="danger"><span>Failure Call</span><strong>{{ i18n.formatNumber(dashboard.summary.failure_calls) }}</strong><small>Outcome Failure</small></article>
          <article class="warn"><span>No Call</span><strong>{{ i18n.formatNumber(dashboard.summary.no_calls) }}</strong><small>เก็บเป็นหลักฐาน แต่ไม่รวม Total Call</small></article>
          <article><span>Success Rate</span><strong>{{ dashboard.summary.conversion_rate }}%</strong><small>Success ÷ Total Call</small></article>
          <article><span>Working Day</span><strong>{{ i18n.formatNumber(dashboard.summary.working_days) }}</strong><small>วันที่ทำงานไม่ซ้ำ</small></article>
          <article><span>Call / Working Day</span><strong>{{ dashboard.summary.calls_per_working_day }}</strong><small>Total Call ÷ Working Day</small></article>
          <article><span>Customers Called</span><strong>{{ i18n.formatNumber(dashboard.summary.active_customers) }}</strong><small>ลูกค้าไม่ซ้ำ</small></article>
        </div>

        <div class="report-grid section-gap">
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">SALES PERFORMANCE</p><h2>Total Call × Success Rate</h2></div><div class="chart-heading-meta"><strong>รวม {{ reportTotal.toLocaleString() }} Call</strong><small class="muted">ขวา = Call มาก · สูง = Success Rate สูง</small></div></div><div v-if="dashboard.by_seller.length" class="bubble-chart"><svg viewBox="0 0 1000 255" role="img" aria-label="เปรียบเทียบ Total Call และ Success Rate ของ Sale พร้อมแกนตัวเลข"><g v-for="tick in sellerAxisTicks" :key="`y-${tick}`"><line x1="80" :y1="sellerPlotY(tick)" x2="950" :y2="sellerPlotY(tick)"/><text class="bubble-axis-label" x="68" :y="sellerPlotY(tick) + 4" text-anchor="end">{{ tick }}%</text></g><g v-for="tick in sellerAxisTicks" :key="`x-${tick}`"><line class="bubble-grid-vertical" :x1="80 + tick * 8.7" y1="40" :x2="80 + tick * 8.7" y2="200"/><text class="bubble-axis-label" :x="80 + tick * 8.7" y="219" text-anchor="middle">{{ sellerCallTick(tick) }}</text></g><line class="bubble-axis-line" x1="80" y1="200" x2="950" y2="200"/><line class="bubble-axis-line" x1="80" y1="40" x2="80" y2="200"/><text class="bubble-axis-title" x="515" y="244" text-anchor="middle">Total Call</text><text class="bubble-axis-title" x="17" y="120" text-anchor="middle" transform="rotate(-90 17 120)">Success Rate</text><g v-for="(row,index) in dashboard.by_seller" :key="row.seller_username"><title>{{ sellerName(row.seller_user_id, row.seller_username) }} · {{ row.total }} Call · {{ percent(row.total) }} ของทั้งหมด · Success {{ row.success_calls }} ({{ row.success_rate }}%) · ลูกค้า {{ row.active_customers }} ราย</title><circle :cx="sellerPlotX(row.total)" :cy="sellerPlotY(row.success_rate)" :r="15 + Math.min(Number(row.active_customers), 17)" :fill="chartColors[index % chartColors.length]"/><text class="bubble-name" :x="sellerPlotX(row.total)" :y="sellerPlotY(row.success_rate) - 5" text-anchor="middle">{{ row.seller_username }}</text><text class="bubble-value" :x="sellerPlotX(row.total)" :y="sellerPlotY(row.success_rate) + 10" text-anchor="middle">{{ row.total }} Call · {{ row.success_rate }}%</text></g></svg><div class="seller-bubble-details" aria-label="คำอธิบายจุด Sale"><div v-for="(row,index) in dashboard.by_seller" :key="`bubble-detail-${row.seller_user_id}`" :title="`${sellerName(row.seller_user_id, row.seller_username)} · ${row.total} Call · Success ${row.success_calls} (${row.success_rate}%)`"><i :style="{ background: chartColors[index % chartColors.length] }"></i><strong>{{ row.seller_name || sellerName(row.seller_user_id, row.seller_username) }}</strong><small>{{ row.seller_username }}</small><span>{{ row.total }} Call</span><span>{{ i18n.isEnglish.value ? `${percent(row.total)} of total` : `${percent(row.total)} ของทั้งหมด` }}</span><b>Success {{ row.success_rate }}%</b></div></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">CALL OUTCOME</p><h2>สัดส่วนผลลัพธ์การติดต่อ</h2></div></div><div v-if="dashboard.by_outcome.length" class="donut-layout"><div class="donut" :style="outcomeDonut" :title="outcomeRows.map(row => `${row.label}: ${row.total} (${percent(row.total, outcomeAttemptTotal)})`).join(' · ')"><div><strong>{{ outcomeAttemptTotal }}</strong><span>Contact Records</span></div></div><div class="chart-legend"><div v-for="(row,index) in outcomeRows" :key="row.label" :title="`${row.label}: ${row.total} รายการ (${percent(row.total, outcomeAttemptTotal)})`"><i :style="{ background: ['#22c55e','#ef4444'][index] }"></i><span>{{ row.label }}</span><strong>{{ row.total }}</strong><small>{{ percent(row.total, outcomeAttemptTotal) }}</small></div></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
        </div>

        <section class="content-card section-gap">
          <div class="section-heading"><div><p class="eyebrow">SALE DEPARTMENT</p><h2>Total Call แยกตามแผนกของ Sale</h2><p class="muted">อ้างอิงแผนกปัจจุบันจากบัญชีผู้ใช้ของ Sale</p></div><strong class="chart-total">รวม {{ reportTotal.toLocaleString() }} Call</strong></div>
          <div class="bar-list">
            <div v-for="row in dashboard.by_seller_department" :key="row.department" class="report-bar department-bar" :title="`${row.department}: ${row.total} Call (${percent(row.total)}) · Success ${row.success_calls} (${row.success_rate}%) · Failure + No Call ${Number(row.failure_calls) + Number(row.no_calls)} · Working Day ${row.working_days}`">
              <strong>{{ row.department }}</strong>
              <div><i class="blue" :style="{ width: `${Math.max(Number(row.total) / maxSellerDepartment * 100, 2)}%` }"></i></div>
              <b>{{ row.total }} · {{ percent(row.total) }}</b>
              <small>Success {{ row.success_calls }} ({{ row.success_rate }}%) · Failure + No Call {{ Number(row.failure_calls) + Number(row.no_calls) }}</small>
            </div>
            <p v-if="!dashboard.by_seller_department.length" class="muted">ยังไม่มีข้อมูลแผนกของ Sale ตามตัวกรอง</p>
          </div>
        </section>

        <section class="content-card section-gap"><div class="section-heading"><div><p class="eyebrow">PERFORMANCE TREND</p><h2>แนวโน้ม Total Call รายวัน</h2></div><div class="chart-heading-meta"><strong>รวม {{ reportTotal.toLocaleString() }} Call</strong><small class="muted">Line + Area Chart · เลื่อนแนวนอนเพื่อดูข้อมูลทั้งหมด</small></div></div><div v-if="trendPoints.length" class="line-chart"><svg viewBox="0 0 1000 225" preserveAspectRatio="none" :style="{ width: `${trendChartWidth}px` }" role="img" aria-label="แนวโน้ม Total Call รายวัน"><line v-for="y in [35,85,135,185]" :key="y" x1="35" :y1="y" x2="965" :y2="y"/><polygon :points="trendArea"/><polyline :points="trendLine"/><g v-for="point in trendPoints" :key="point.activity_date"><title>วันที่ {{ shortDate(point.activity_date) }} · {{ point.total }} Call</title><circle :cx="point.x" :cy="point.y" r="6"/><text :x="point.x" :y="point.y - 12" text-anchor="middle">{{ point.total }}</text></g></svg><div class="line-labels" :style="{ width: `${trendChartWidth}px` }"><span v-for="point in trendPoints" :key="point.activity_date" :style="{ left: `${point.x / 10}%` }" :title="`วันที่ ${shortDate(point.activity_date)} · ${point.total} Call`">{{ shortDate(point.activity_date) }}</span></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>

        <section class="content-card section-gap"><div class="section-heading"><div><p class="eyebrow">CALL OBJECTIVE × OUTCOME</p><h2>ผลลัพธ์แยกตามวัตถุประสงค์การ Call</h2><p class="muted">ความยาวแท่งคำนวณจากจำนวนรายการของ Objective เทียบกับรายการติดต่อทั้งหมด</p></div><div><strong class="chart-total">รวม {{ objectiveOutcomeTotal.toLocaleString() }} รายการติดต่อ</strong><div class="legend"><span class="dot success-dot"></span>Success <span class="dot failure-dot"></span>Failure + No Call</div></div></div><div class="objective-chart"><div v-for="row in dashboard.by_objective" :key="row.label" :title="`${row.label}: รวม ${Number(row.success_calls) + Number(row.failure_calls) + Number(row.no_calls)} รายการ · Success ${row.success_calls} (${percent(row.success_calls, Number(row.success_calls) + Number(row.failure_calls) + Number(row.no_calls))}) · Failure + No Call ${Number(row.failure_calls) + Number(row.no_calls)} (${percent(Number(row.failure_calls) + Number(row.no_calls), Number(row.success_calls) + Number(row.failure_calls) + Number(row.no_calls))})`"><strong>{{ row.label }}</strong><div><div class="stacked-track"><i class="stack-success" :style="{ width: `${objectiveOutcomeTotal ? Number(row.success_calls) / objectiveOutcomeTotal * 100 : 0}%` }"></i><i class="stack-failure" :style="{ width: `${objectiveOutcomeTotal ? (Number(row.failure_calls) + Number(row.no_calls)) / objectiveOutcomeTotal * 100 : 0}%` }"></i></div><small class="chart-breakdown">{{ objectiveBreakdown(row) }}</small></div><b>{{ Number(row.success_calls) + Number(row.failure_calls) + Number(row.no_calls) }}<small>รายการ</small></b></div><p v-if="!dashboard.by_objective.length" class="muted">ยังไม่มีข้อมูล</p></div></section>

        <div class="report-grid section-gap">
          <section class="content-card">
            <div class="section-heading">
              <div><p class="eyebrow">PRODUCT DASHBOARD</p><h2>Product Treemap</h2></div>
              <div class="chart-heading-meta"><strong>รวม {{ reportTotal.toLocaleString() }} Call</strong><small class="muted">พื้นที่ของแต่ละกล่องคำนวณตามสัดส่วน Call จริง</small></div>
            </div>
            <template v-if="productTreemap.length">
              <div class="treemap">
                <article
                  v-for="item in productTreemap"
                  :key="item.row.label"
                  :class="{ compact: item.width < 18 || item.height < 22, tiny: item.width < 7 || item.height < 8 }"
                  :style="{
                    left: `${item.x}%`,
                    top: `${item.y}%`,
                    width: `${item.width}%`,
                    height: `${item.height}%`,
                    background: item.color,
                  }"
                  :title="`${item.row.label}: ${item.row.total} Calls (${percent(item.row.total)})`"
                >
                  <strong>{{ item.row.label }}</strong>
                  <span>{{ item.row.total }} Calls · {{ percent(item.row.total) }}</span>
                  <small>Success {{ item.row.success_calls }} ({{ item.row.success_rate }}%)</small>
                </article>
              </div>
              <div class="treemap-details" aria-label="รายละเอียดจำนวน Call แยกตาม Product">
                <div v-for="item in productTreemap" :key="`product-detail-${item.row.label}`">
                  <i :style="{ background: item.color }"></i>
                  <strong>{{ item.row.label }}</strong>
                  <span>{{ item.row.total }} Calls</span>
                  <small>{{ percent(item.row.total) }}</small>
                </div>
              </div>
            </template>
            <p v-else class="muted">ยังไม่มีข้อมูล</p>
          </section>
          <section class="content-card"><div class="section-heading"><div><p class="eyebrow">SPECIALTY DASHBOARD</p><h2>สัดส่วน Call ตาม Specialty</h2></div><small class="muted">10 อันดับแรก</small></div><div v-if="dashboard.by_specialty.length" class="donut-layout specialty-layout"><div class="donut specialty-donut" :style="specialtyDonut" :title="dashboard.by_specialty.slice(0,10).map(row => `${row.label}: ${row.total} Call (${percent(row.total)}) · Success ${row.success_rate}%`).join(' · ')"><div><strong>{{ reportTotal }}</strong><span>Total Call</span></div></div><div class="chart-legend compact"><div v-for="(row,index) in dashboard.by_specialty.slice(0,10)" :key="row.label" :title="`${row.label}: ${row.total} Call (${percent(row.total)}) · Success ${row.success_calls} (${row.success_rate}%)`"><i :style="{ background: chartColors[index % chartColors.length] }"></i><span>{{ row.label }} · Success {{ row.success_rate }}%</span><strong>{{ row.total }}</strong><small>{{ percent(row.total) }}</small></div></div></div><p v-else class="muted">ยังไม่มีข้อมูล</p></section>
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
          <div class="section-heading"><div><p class="eyebrow">HOSPITAL PURCHASE RANKING</p><h2>อันดับโรงพยาบาลที่ซื้อสินค้ากับบริษัท</h2><p class="muted">จัดอันดับจาก Call Outcome = Success ตามตัวกรองด้านบน และใช้ PLACE OF WORK เป็นชื่อโรงพยาบาล</p></div><small class="muted">สูงสุด 20 อันดับ</small></div>
          <div class="summary-scroll">
            <table class="summary-table hospital-ranking-table">
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>Hospital / Place of Work</th>
                  <th>Success Purchase</th>
                  <th>Products</th>
                  <th>Contacts</th>
                  <!-- <th>Actual Value</th> -->
                  <th>Last Purchase</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in dashboard.hospital_purchase_ranking" :key="row.hospital_name">
                  <td><strong class="rank-badge">{{ index + 1 }}</strong></td>
                  <td><strong>{{ row.hospital_name }}</strong></td>
                  <td class="number-success">{{ row.purchase_calls }}</td>
                  <td>{{ row.products_count }}</td>
                  <td>{{ row.contacts_count }}</td>
                  <!-- <td class="number-total">{{ money(row.actual_value) }}</td> -->
                  <td>{{ shortDate(row.last_purchase_date) }}</td>
                </tr>
                <tr v-if="!dashboard.hospital_purchase_ranking.length">
                  <td colspan="6" class="empty-cell">ยังไม่มีโรงพยาบาลที่มีผลลัพธ์ Success ตามตัวกรอง</td>
                </tr>
              </tbody>
            </table>
          </div>
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

      <section id="sales-call-raw-data" class="content-card section-gap">
        <div class="section-heading"><div><p class="eyebrow">RAW DATA</p><h2>ข้อมูล Call Sale ทั้งทีม</h2><p class="muted">{{ activities?.total ?? 0 }} รายการตามตัวกรอง</p></div></div>
        <div v-if="loading" class="loading-box">กำลังประมวลผลรายงาน...</div>
        <div v-else class="raw-scroll">
          <div v-if="phoneLayout" class="mobile-records">
            <article v-for="row in activities?.data" :key="row.call_id" class="mobile-record">
              <header><strong>{{ shortDate(row.activity_date) }} · {{ row.seller_username }} · Call {{ row.call_no }}</strong><span class="outcome-chip">{{ row.outcome || '-' }}</span></header>
              <p><b>{{ row.customer_name || row.place_of_work || 'กิจกรรมอื่น' }}</b><small>{{ row.place_of_work || '-' }}</small></p>
              <div class="mobile-record-meta"><span>{{ row.specialty || 'ไม่มี Specialty' }}</span><span>{{ row.product_1 || '-' }}</span><span>{{ row.status }}</span></div>
              <p v-if="row.sm_comment" class="mobile-record-comment"><strong>SM Comment:</strong> {{ row.sm_comment }}</p>
              <details><summary>ดูข้อมูลทั้งหมด</summary><dl><div><dt>Department / Group</dt><dd>{{ row.department || '-' }} / {{ row.customer_group || '-' }}</dd></div><div><dt>วัตถุประสงค์</dt><dd>{{ row.objective }} · {{ row.call_detail_objective || '-' }}</dd></div><div><dt>รายละเอียดผลลัพธ์</dt><dd>{{ row.call_detail_outcome || '-' }}</dd></div><div><dt>วันติดตาม</dt><dd>{{ shortDate(row.next_follow_up_date) }}</dd></div><div><dt>หมายเหตุ</dt><dd>{{ row.notes || '-' }}</dd></div></dl></details>
              <button class="btn" type="button" @click="editManagedCall(row)">แก้ไข / SM Comment</button>
            </article>
            <p v-if="!activities?.data.length" class="empty-cell">ไม่พบข้อมูลตามตัวกรอง</p>
          </div>
          <table v-else class="raw-table"><thead><tr><th>จัดการ</th><th>Sale</th><th>Call No.</th><th>Date</th><th>PLACE OF WORK</th><th>CUSTOMER NAME</th><th>SPECIALTY</th><th>DEPARTMENT</th><th>GROUP</th><th>PRODUCT-1</th><th>CALL OBJECTIVE</th><th>CALL DETAIL (Obj)</th><th>CALL OUTCOME</th><th>CALL DETAIL (Out)</th><th>F/U DATE</th><th>NOTE</th><th>SM Comment</th><th>Status</th></tr></thead><tbody><tr v-for="row in activities?.data" :key="row.call_id"><td><button class="btn compact-button" type="button" @click="editManagedCall(row)">แก้ไข</button></td><td>{{ row.seller_username }}</td><td>{{ row.call_no }}</td><td>{{ shortDate(row.activity_date) }}</td><td>{{ row.place_of_work || '-' }}</td><td>{{ row.customer_name || '-' }}</td><td>{{ row.specialty || '-' }}</td><td>{{ row.department || '-' }}</td><td>{{ row.customer_group || '-' }}</td><td>{{ row.product_1 || '-' }}</td><td>{{ row.objective }}</td><td>{{ row.call_detail_objective || '-' }}</td><td>{{ row.outcome }}</td><td>{{ row.call_detail_outcome || '-' }}</td><td>{{ shortDate(row.next_follow_up_date) }}</td><td>{{ row.notes || '-' }}</td><td class="sm-comment-cell" :class="{ 'has-sm-comment': Boolean(row.sm_comment?.trim()) }">{{ row.sm_comment || '-' }}</td><td><span class="status-chip" :class="row.status === 'ทำงาน' ? 'work' : 'leave'">{{ row.status }}</span></td></tr><tr v-if="!activities?.data.length"><td colspan="18" class="empty-cell">ไม่พบข้อมูลตามตัวกรอง</td></tr></tbody></table></div>
        <div ref="activitiesLoadSentinel" class="infinite-load-sentinel"><span v-if="activitiesLoadingMore">กำลังโหลดข้อมูลเพิ่มเติม…</span><span v-else-if="activities?.next_page_url">เลื่อนลงเพื่อโหลดข้อมูลต่อ</span><span v-else-if="activities?.data.length">{{ i18n.isEnglish.value ? `All ${activities.total} items displayed` : `แสดงครบ ${activities.total} รายการแล้ว` }}</span></div>
      </section>

      <Teleport to="body">
        <div v-if="editingManagedCall" class="lookup-modal-backdrop" role="presentation" @click.self="!managerSaving && (editingManagedCall = null)" @keydown.esc="!managerSaving && (editingManagedCall = null)">
          <form class="lookup-modal manager-edit-modal" role="dialog" aria-modal="true" @submit.prevent="saveManagedCall">
            <div class="section-heading lookup-modal-header"><div><p class="eyebrow">MANAGE CALL SALE</p><h2>แก้ไข Call วันที่ {{ displayDate(editingManagedCall.activity_date) }}</h2><p class="muted">Sale: {{ editingManagedCall.seller_username }} · Call No. {{ editingManagedCall.call_no }}</p></div><button class="lookup-modal-close" type="button" :disabled="managerSaving" @click="editingManagedCall = null">×</button></div>
            <div class="manager-edit-grid">
              <label>PLACE OF WORK *<input v-model.trim="editingManagedCall.place_of_work" required></label>
              <label>CUSTOMER NAME *<input v-model.trim="editingManagedCall.customer_name" required></label>
              <label>SPECIALTY *<SearchableSelect v-model="editingManagedCall.specialty" :options="searchableOptions('specialties', editingManagedCall.specialty || '')" required /></label>
              <label>DEPARTMENT *<SearchableSelect v-model="editingManagedCall.department" :options="searchableOptions('departments', editingManagedCall.department || '')" required /></label>
              <label>GROUP *<SearchableSelect v-model="editingManagedCall.customer_group" :options="searchableOptions('groups', editingManagedCall.customer_group || '')" required /></label>
              <label>PRODUCT-1 *<SearchableSelect v-model="editingManagedCall.product_1" :options="searchableOptions('products', editingManagedCall.product_1 || '')" required /></label>
              <label>CALL OBJECTIVE *<SearchableSelect v-model="editingManagedCall.objective" :options="searchableOptions('objectives', editingManagedCall.objective)" required /></label>
              <label>CALL DETAIL (Obj)<textarea v-model.trim="editingManagedCall.call_detail_objective" rows="3" placeholder="รายละเอียดวัตถุประสงค์"></textarea></label>
              <label>CALL OUTCOME *<SearchableSelect v-model="editingManagedCall.outcome" :options="searchableOptions('outcomes', editingManagedCall.outcome)" required /></label>
              <label>CALL DETAIL (Out)<textarea v-model.trim="editingManagedCall.call_detail_outcome" rows="3" placeholder="รายละเอียดผลลัพธ์"></textarea></label>
              <label>F/U DATE<CompatDateTimeInput v-model="editingManagedCall.next_follow_up_date" mode="date" /></label>
              <label>NOTE<textarea v-model.trim="editingManagedCall.notes" rows="3" placeholder="หมายเหตุของ Sale"></textarea></label>
              <label>Status *<SearchableSelect v-model="editingManagedCall.status" :options="searchableOptions('statuses', editingManagedCall.status)" required /></label>
              <label class="manager-comment-field">SM COMMENT<textarea v-model.trim="editingManagedCall.sm_comment" rows="4" maxlength="10000" placeholder="ความคิดเห็นหรือคำแนะนำจาก SM เพื่อให้ Sale เห็นในตารางของตนเอง"></textarea></label>
            </div>
            <div class="import-confirmation"><p>การแก้ไขจะเปลี่ยนข้อมูลรายงานของ Sale รายนี้และถูกบันทึกใน Audit Log</p><div><button class="btn" type="button" :disabled="managerSaving" @click="editingManagedCall = null">ยกเลิก</button><button class="btn btn-primary" type="submit" :disabled="managerSaving">{{ managerSaving ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข' }}</button></div></div>
          </form>
        </div>
      </Teleport>
    </template>
  </AppShell>
</template>

<style scoped>
.report-grid,.report-grid>section,.specialty-layout,.chart-legend,.chart-legend>div,.chart-legend span,.chart-legend strong,.chart-legend small { min-width: 0; }
.chart-legend span { overflow-wrap: anywhere; }
.alert{padding:12px 15px;border-radius:10px;margin-bottom:12px}.alert-error{background:#fff0f1;color:#a61b2b}.alert-success{background:#ecfdf3;color:#067647}.eyebrow{margin:0 0 5px;color:#c92d3e;font-size:.72rem;font-weight:800;letter-spacing:.13em}.daily-card{padding:0;overflow:hidden;border-top:4px solid #c92d3e}.planner-heading{padding:22px 24px 18px;margin:0;border-bottom:1px solid #e3e6eb}.row-count{border-radius:99px;background:#fff0f2;color:#b82032;font-weight:800;padding:8px 14px}.planner-scroll,.raw-scroll{overflow:auto;max-width:100%;border-bottom:1px solid #e2e5ea}.planner-table,.raw-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:.78rem}.planner-table th,.raw-table th{position:sticky;top:0;z-index:3;background:#b82032;color:#fff;padding:10px 8px;border-right:1px solid #d95060;white-space:nowrap;text-align:center}.planner-table td{padding:0;border-right:1px solid #dfe2e7;border-bottom:1px solid #dfe2e7;background:#fff;vertical-align:top}.planner-table tr:nth-child(even) td{background:#fffafb}.planner-table input,.planner-table select,.planner-table textarea{display:block;width:150px;min-height:54px;border:0;border-radius:0;padding:8px;background:transparent;color:#172033;font:inherit;resize:vertical}.planner-table select{width:145px}.planner-table input[type=date]{width:132px}.planner-table textarea:focus,.planner-table select:focus,.planner-table input:focus{outline:2px solid #e05060;outline-offset:-2px;background:#fff}.planner-table .locked{padding:13px 8px;text-align:center;background:#f4f5f7!important;color:#4b5565;white-space:nowrap}.planner-table .col-no{position:sticky;left:0;z-index:2;width:68px;min-width:68px}.planner-table th.col-no{z-index:5;background:#951a29}.planner-table .col-date{position:sticky;left:68px;z-index:2;width:105px;min-width:105px}.planner-table th.col-date{z-index:5;background:#a91e2e}.planner-table .col-remove{width:40px;min-width:40px}.remove-row{border:0;background:transparent;color:#b42318;font-size:1.45rem;cursor:pointer;padding:10px}.planner-footer{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:18px 24px;background:#fafbfc;flex-wrap:wrap}.planner-actions,.day-switcher,.export-actions,.filter-actions,.pagination{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.add-row{border-style:dashed}.save-table{min-width:150px}.day-switcher{margin-left:auto;border:1px solid #d7dbe2;border-radius:12px;background:#fff;padding:5px}.day-switcher button{width:36px;height:36px;border:0;border-radius:8px;background:#f4f5f7;font-size:1.35rem;cursor:pointer}.day-switcher input{width:36px;border:0;color:transparent;background:transparent}.day-switcher strong{white-space:nowrap;min-width:100px;text-align:center}.loading-box,.empty-cell{padding:35px;text-align:center;color:#697386}.filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:15px}.filter-grid label{display:flex;flex-direction:column;gap:7px;font-weight:700;font-size:.83rem}.filter-grid input,.filter-grid select{width:100%;border:1px solid #d5d9e0;border-radius:9px;padding:9px 11px;background:#fff}.filter-grid select[multiple]{min-height:86px}.filter-actions{margin-top:14px}.report-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:13px}.report-metrics article{background:#fff;border:1px solid #e2e5ea;border-radius:14px;padding:16px;box-shadow:0 4px 14px #1822300b}.report-metrics span,.report-metrics small{display:block;color:#667085}.report-metrics strong{display:block;font-size:1.55rem;margin:8px 0}.report-metrics .warn{background:#fffaf0;border-color:#f5d59e}.report-metrics .success{background:#f0fdf4;border-color:#bbf7d0}.report-metrics .danger{background:#fff1f2;border-color:#fecdd3}.report-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.bar-list{display:grid;gap:11px}.report-bar{display:grid;grid-template-columns:120px 1fr 38px;gap:9px;align-items:center}.report-bar div{height:9px;background:#edf0f4;border-radius:99px;overflow:hidden}.report-bar i{display:block;height:100%;background:linear-gradient(90deg,#c92d3e,#f06b79)}.report-bar i.blue{background:linear-gradient(90deg,#2563eb,#60a5fa)}.report-bar i.purple{background:linear-gradient(90deg,#7c3aed,#c084fc)}.report-bar i.outcome-success{background:#22c55e}.report-bar i.outcome-failure{background:#ef4444}.report-bar i.outcome-no-call{background:#94a3b8}.metric-bar{grid-template-columns:120px 1fr 34px 48px}.metric-bar small{text-align:right;color:#667085}.trend{display:flex;min-height:195px;align-items:flex-end;gap:12px;overflow:auto}.trend>div{display:grid;justify-items:center;gap:5px;min-width:58px}.trend i{display:block;width:27px;background:linear-gradient(#f06b79,#b82032);border-radius:7px 7px 2px 2px}.trend span{font-size:.68rem;white-space:nowrap}.raw-table{min-width:2200px}.raw-table td{padding:9px;border-right:1px solid #e4e6ea;border-bottom:1px solid #e4e6ea;vertical-align:top;max-width:230px}.status-chip,.outcome-chip{display:inline-block;padding:5px 9px;border-radius:99px;white-space:nowrap;font-weight:700}.status-chip.work{background:#dcfce7;color:#166534}.status-chip.leave{background:#f3f4f6;color:#4b5563}.outcome-chip.success{background:#dcfce7;color:#166534}.outcome-chip.failure{background:#fee2e2;color:#991b1b}.outcome-chip.no-call{background:#f1f5f9;color:#475569}.pagination{justify-content:flex-end;margin-top:14px}.history-card{padding:0;overflow:hidden}.history-heading{padding:18px 22px;margin:0}.history-filters{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}.history-filters label{display:grid;gap:4px;font-size:.74rem;font-weight:700}.history-filters input,.history-filters select{border:1px solid #d5d9e0;border-radius:8px;padding:7px 9px;background:#fff}.history-scroll,.summary-scroll{overflow:auto;max-height:560px}.history-table,.summary-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.77rem}.history-table th,.summary-table th{position:sticky;top:0;z-index:2;background:#263142;color:#fff;text-align:left;padding:9px 10px;white-space:nowrap}.history-table td,.summary-table td{padding:8px 10px;border-bottom:1px solid #e7e9ed;white-space:nowrap}.history-table tbody tr:nth-child(even),.summary-table tbody tr:nth-child(even){background:#f8fafc}.compact-pagination{padding:12px 18px;margin:0}.number-success{color:#15803d;font-weight:800}.number-danger{color:#b91c1c;font-weight:800}.breakdown-list{display:grid;gap:8px}.breakdown-list>div{display:grid;grid-template-columns:90px 1fr 35px;gap:8px;align-items:center;padding:9px 10px;background:#f8fafc;border-radius:8px;font-size:.76rem}.breakdown-list span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b}
.bubble-chart svg,.line-chart svg{display:block;width:100%;height:225px;overflow:visible}.bubble-chart line,.line-chart line{stroke:#e5e7eb;stroke-width:1}.bubble-chart .bubble-grid-vertical{stroke:#eef1f5;stroke-dasharray:3 4}.bubble-chart .bubble-axis-line{stroke:#94a3b8;stroke-width:1.5}.bubble-chart circle{opacity:.82;stroke:#fff;stroke-width:3}.bubble-chart text{fill:#172033;font-size:12px;font-weight:800;pointer-events:none}.bubble-chart .bubble-axis-label{fill:#64748b;font-size:10px;font-weight:600}.bubble-chart .bubble-axis-title{fill:#475569;font-size:11px;font-weight:800}.donut-layout{display:grid;grid-template-columns:190px 1fr;gap:24px;align-items:center}.donut{width:180px;aspect-ratio:1;border-radius:50%;display:grid;place-items:center;box-shadow:inset 0 0 0 1px #ffffff55}.donut>div{width:104px;aspect-ratio:1;border-radius:50%;background:#fff;display:grid;place-content:center;text-align:center;box-shadow:0 2px 14px #0f172a1a}.donut strong{font-size:1.65rem}.donut span{color:#64748b;font-size:.72rem}.chart-legend{display:grid;gap:10px}.chart-legend>div{display:grid;grid-template-columns:10px 1fr 38px 42px;gap:8px;align-items:center;padding-bottom:8px;border-bottom:1px solid #edf0f3}.chart-legend i{width:10px;height:10px;border-radius:50%}.chart-legend small{color:#64748b;text-align:right}.line-chart{overflow-x:auto}.line-chart svg{min-width:720px}.line-chart polygon{fill:#c92d3e18}.line-chart polyline{fill:none;stroke:#c92d3e;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}.line-chart circle{fill:#fff;stroke:#c92d3e;stroke-width:4}.line-chart text{fill:#172033;font-size:12px;font-weight:800}.line-labels{display:flex;justify-content:space-between;gap:25px;min-width:720px;font-size:.67rem;color:#64748b;padding:4px 28px 0}.treemap{position:relative;width:100%;height:330px;overflow:hidden;border-radius:10px;background:#f1f5f9}.treemap article{position:absolute;display:flex;flex-direction:column;justify-content:center;overflow:hidden;padding:12px;color:#fff;border:3px solid #fff;text-shadow:0 1px 2px #0007;transition:filter .18s}.treemap article:hover{z-index:2;filter:brightness(1.08)}.treemap strong,.treemap span,.treemap small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.treemap strong{font-size:.9rem}.treemap span{font-size:1.08rem;font-weight:900}.treemap small{font-size:.69rem}.treemap article.compact{padding:6px}.treemap article.compact span,.treemap article.compact small{display:none}.treemap article.tiny>*{display:none}.treemap-details{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:6px;margin-top:10px}.treemap-details>div{display:grid;grid-template-columns:10px minmax(0,1fr) auto auto;gap:7px;align-items:center;padding:6px 8px;border-radius:7px;background:#f8fafc;font-size:.7rem}.treemap-details i{width:9px;height:9px;border-radius:2px}.treemap-details strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.treemap-details span{font-weight:800}.treemap-details small{color:#64748b}.specialty-donut{width:170px}.specialty-donut>div{width:92px}.specialty-layout{grid-template-columns:180px 1fr}.chart-legend.compact{gap:5px}.chart-legend.compact>div{padding-bottom:4px;font-size:.72rem}.legend{display:flex;align-items:center;gap:6px;color:#64748b;font-size:.76rem}.dot{width:9px;height:9px;border-radius:50%;display:inline-block;margin-left:8px}.success-dot,.stack-success{background:#22c55e}.failure-dot,.stack-failure{background:#ef4444}.nocall-dot,.stack-nocall{background:#94a3b8}.objective-chart{display:grid;gap:12px}.objective-chart>div{display:grid;grid-template-columns:130px 1fr 38px;align-items:center;gap:10px}.stacked-track{display:flex;height:13px;background:#eef1f5;border-radius:99px;overflow:hidden}.stacked-track i{display:block;height:100%}
.sale-total-table td:not(:first-child),.sale-total-table th:not(:first-child){text-align:center}.seller-username{display:block;margin-top:3px;color:#64748b;font-size:.68rem}.number-total{color:#1d4ed8;font-weight:900}
.department-bar{grid-template-columns:minmax(120px,180px) 1fr minmax(110px,auto) minmax(290px,auto)}.department-bar small{text-align:right;color:#64748b}.chart-heading-meta{display:grid;justify-items:end;gap:3px}.chart-total{display:block;text-align:right;color:#9d1d2d}.chart-breakdown{display:block;margin-top:5px;color:#64748b;font-size:.69rem}.objective-chart>div>b{display:grid;text-align:right}.objective-chart>div>b small{color:#64748b;font-size:.63rem}
.hospital-ranking-table td:first-child,.hospital-ranking-table th:first-child{text-align:center;width:62px}.rank-badge{display:inline-grid;place-items:center;min-width:30px;height:30px;border-radius:50%;background:#fff0f2;color:#b82032}.hospital-ranking-table tr:nth-child(1) .rank-badge{background:#fbbf24;color:#713f12}.hospital-ranking-table tr:nth-child(2) .rank-badge{background:#cbd5e1;color:#334155}.hospital-ranking-table tr:nth-child(3) .rank-badge{background:#d97706;color:#fff}
.period-days{display:grid;gap:14px;padding:16px;background:#f5f7fa}.planner-day{overflow:hidden;border:1px solid #dfe3e9;border-radius:12px;background:#fff;box-shadow:0 2px 8px #1822300a}.planner-day.is-selected-day{border-color:#d24959;box-shadow:0 0 0 2px #d2495920}.planner-day-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#fff8f9;border-bottom:1px solid #eadde0}.planner-day-heading>div{display:flex;align-items:center;gap:10px}.planner-day-heading small{color:#9d1d2d;font-weight:800}.planner-heading-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}.history-bulk-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:10px 18px;border-top:1px solid #e7e9ed;background:#f8fafc}.history-check{width:42px;text-align:center!important}.history-check input{width:17px;height:17px;accent-color:#b82032}.history-table tr.is-selected-history{background:#fff0f2!important}.danger-action{border-color:#efb4bb;color:#a61b2b}.danger-action:not(:disabled):hover{background:#fff0f2}.visually-hidden{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}.import-button{border-color:#b82032;color:#a61b2b;background:#fff}.import-modal{width:min(1180px,100%)}.import-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:13px}.import-summary article{padding:12px;border:1px solid #e2e5ea;border-radius:10px;background:#f8fafc}.import-summary article.warning{border-color:#f5c26b;background:#fff8e8}.import-summary span{display:block;color:#667085;font-size:.72rem}.import-summary strong{display:block;margin-top:5px;font-size:1rem}.import-quality-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-bottom:13px}.quality-card{padding:10px 12px;border:1px solid #dce4ee;border-radius:10px;background:#f8fafc;font-size:.75rem}.quality-card strong{display:block;margin-bottom:5px}.quality-card p{margin:0;color:#475569;line-height:1.45}.quality-card p span{display:inline-block;margin:0 10px 2px 0}.quality-good{border-color:#a7e2bd;background:#f0fdf4}.quality-warning{border-color:#f3c66f;background:#fff9e9}.import-warnings{max-height:150px;overflow:auto;margin-bottom:13px;padding:12px 14px;border:1px solid #f3c66f;border-radius:10px;background:#fff9e9;color:#7a4b00;font-size:.76rem}.import-warnings ul{margin:7px 0;padding-left:20px}.import-preview-scroll{min-height:180px;overflow:auto;border:1px solid #e2e5ea;border-radius:10px}.import-preview-table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;font-size:.75rem}.import-preview-table th{position:sticky;top:0;z-index:2;padding:9px 10px;background:#263142;color:#fff;text-align:left;white-space:nowrap}.import-preview-table td{max-width:220px;padding:8px 10px;border-bottom:1px solid #e7e9ed;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.import-preview-note{margin:8px 0 0}.import-confirmation{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:13px;padding-top:13px;border-top:1px solid #e2e5ea}.import-confirmation p{max-width:760px;margin:0;font-size:.78rem;color:#475569}.import-confirmation>div{display:flex;gap:8px;flex-shrink:0}
.lookup-modal-backdrop{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:24px;background:#11182799;backdrop-filter:blur(3px)}.lookup-modal{display:flex;flex-direction:column;width:min(960px,100%);max-height:calc(100vh - 48px);padding:22px;border-radius:16px;background:#fff;box-shadow:0 24px 70px #11182755;overflow:hidden}.lookup-modal-header{flex-shrink:0;margin:0 0 15px}.lookup-modal-close{width:38px;height:38px;flex-shrink:0;border:0;border-radius:10px;background:#f3f4f6;color:#475569;font-size:1.6rem;line-height:1;cursor:pointer}.lookup-modal-close:hover{background:#fee2e2;color:#991b1b}.lookup-tabs{display:flex;flex-shrink:0;gap:7px;overflow-x:auto;padding:2px 0 13px;border-bottom:1px solid #e7e9ed}.lookup-tabs button{border:1px solid #d7dbe2;border-radius:99px;background:#fff;color:#475569;padding:7px 12px;white-space:nowrap;cursor:pointer}.lookup-tabs button.active{border-color:#b82032;background:#b82032;color:#fff}.lookup-add{display:grid;grid-template-columns:minmax(240px,1fr) auto;flex-shrink:0;gap:10px;align-items:end;padding:15px 0}.lookup-add label{display:grid;gap:6px;font-size:.8rem;font-weight:700}.lookup-add input,.lookup-table input{width:100%;border:1px solid #d5d9e0;border-radius:8px;padding:8px 10px;background:#fff;color:#172033}.lookup-table-wrap{min-height:0;overflow:auto;border:1px solid #e2e5ea;border-radius:10px}.lookup-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.8rem}.lookup-table th{position:sticky;top:0;z-index:2;background:#263142;color:#fff;text-align:left;padding:9px 10px;white-space:nowrap}.lookup-table td{padding:8px 10px;border-bottom:1px solid #e7e9ed;vertical-align:middle}.lookup-table tbody tr:nth-child(even){background:#f8fafc}.lookup-table tr.inactive{background:#f5f5f5;color:#8a93a2}.lookup-table .sort-input{width:85px}.active-toggle{display:flex;align-items:center;gap:7px;white-space:nowrap}.active-toggle input{width:17px;height:17px;accent-color:#b82032}
.required-errors{margin-bottom:13px;padding:13px 14px;border:1px solid #f09aa4;border-radius:10px;background:#fff1f2;color:#8f1624;font-size:.78rem}.required-errors>p{margin:6px 0 10px}.required-errors-scroll{max-height:190px;overflow:auto;border:1px solid #fecdd3;border-radius:8px;background:#fff}.required-errors-table{width:100%;border-collapse:collapse;color:#172033}.required-errors-table th,.required-errors-table td{padding:8px 10px;border-bottom:1px solid #fee2e2;text-align:left;vertical-align:top}.required-errors-table th{position:sticky;top:0;background:#9f1d2d;color:#fff}.required-errors-table td:last-child{color:#991b1b;font-weight:700}.submission-actions{display:flex;align-items:flex-end;justify-content:flex-end;gap:8px;flex-wrap:wrap}.submission-actions label{display:grid;gap:4px;color:#475569;font-size:.74rem;font-weight:700}.submission-actions input{border:1px solid #d5d9e0;border-radius:8px;padding:8px 10px;background:#fff}.submission-table{min-width:930px}.submission-status{display:inline-block;padding:5px 9px;border-radius:99px;font-weight:800}.submission-status.submitted{background:#dcfce7;color:#166534}.submission-status.missing{background:#fff1f2;color:#9f1239}.source-files{display:grid;gap:6px;min-width:280px}.file-download{display:grid;gap:3px;width:100%;border:1px solid #dbe2ea;border-radius:8px;background:#fff;padding:8px 10px;text-align:left;color:#172033;cursor:pointer}.file-download:hover{border-color:#c92d3e;background:#fff8f9}.file-download strong{max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.file-download small{color:#64748b}.compact-button{padding:5px 9px;font-size:.72rem}.manager-edit-modal{width:min(900px,100%)}.manager-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;overflow:auto;padding:2px}.manager-edit-grid label{display:grid;gap:6px;color:#344054;font-size:.78rem;font-weight:800}.manager-edit-grid input,.manager-edit-grid select,.manager-edit-grid textarea{width:100%;border:1px solid #d5d9e0;border-radius:9px;padding:9px 11px;background:#fff;color:#172033;font:inherit;resize:vertical}.manager-comment-field{grid-column:1/-1;color:#b42318!important}.manager-comment-field textarea{border:2px solid #ef4444;background:#fff1f2;color:#991b1b;font-weight:700}.autosave-status{display:inline-flex;align-items:center;min-height:32px;padding:6px 10px;border-radius:99px;background:#f1f5f9;color:#475569;font-size:.72rem;font-weight:800;white-space:nowrap}.autosave-status.is-saved{background:#dcfce7;color:#166534}.autosave-status.is-saving,.autosave-status.is-pending{background:#eff6ff;color:#1d4ed8}.autosave-status.is-waiting{background:#fff7ed;color:#9a3412}.autosave-status.is-error{background:#fee2e2;color:#991b1b}.planner-table textarea.sm-comment-readonly{background:#f8fafc;color:#475569;cursor:default}.planner-table textarea.sm-comment-readonly.has-sm-comment{background:#fff1f2!important;color:#b42318;font-weight:800;box-shadow:inset 4px 0 0 #dc2626}.sm-comment-cell{min-width:190px;white-space:pre-wrap}.sm-comment-cell.has-sm-comment{background:#fff1f2!important;color:#b42318;font-weight:800;border-left:4px solid #dc2626}.infinite-load-sentinel{display:flex;min-height:48px;align-items:center;justify-content:center;padding:12px;color:#64748b;font-size:.78rem}.sale-history-table{min-width:2050px}
@media(max-width:1000px){.filter-grid,.report-metrics,.import-summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:720px){.planner-heading,.planner-footer{padding:16px}.planner-footer,.report-grid{display:grid;grid-template-columns:1fr}.planner-heading-actions{width:100%;justify-content:space-between}.day-switcher{margin:0;justify-content:center}.planner-actions .btn{flex:1}.filter-grid,.report-metrics,.import-summary,.manager-edit-grid{grid-template-columns:1fr}.export-actions .btn{flex:1}.objective-chart>div{grid-template-columns:90px 1fr 30px}.history-heading{display:grid}.history-filters{margin-top:12px}.donut-layout,.specialty-layout{grid-template-columns:1fr;justify-items:center}.chart-legend{width:100%}.bubble-chart svg{min-width:580px}.bubble-chart{overflow-x:auto}.section-heading{align-items:flex-start}.lookup-modal-backdrop{padding:10px}.lookup-modal{max-height:calc(100vh - 20px);padding:16px}.lookup-add{grid-template-columns:1fr}.lookup-add .btn{width:100%}.import-confirmation{display:grid}.import-confirmation>div{justify-content:flex-end}.submission-actions{justify-content:flex-start}.submission-actions .btn{flex:1}}
.submission-status.archived{background:#f1f5f9;color:#475569}
.submission-method{display:inline-flex;padding:5px 9px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:.72rem;font-weight:800;white-space:nowrap}.submission-method--excel_import{background:#ecfdf3;color:#166534}.submission-method--website{background:#eff6ff;color:#1d4ed8}.submission-method--excel_import_and_website{background:#fff7ed;color:#9a3412}.submission-method--none{background:#f1f5f9;color:#64748b}.submission-method-note{display:block;max-width:190px;margin-top:5px;color:#9a3412;white-space:normal}.submission-table{min-width:1450px}
.submission-actions select{min-width:146px;border:1px solid #d5d9e0;border-radius:8px;padding:8px 10px;background:#fff;color:#172033}
.mobile-records{display:none}
.mobile-submissions,.mobile-filter-checklist,.mobile-filter-hint{display:none}
@media(max-width:640px){
  .submission-results>.summary-scroll,.desktop-multiselect,.desktop-filter-hint{display:none!important}
  .mobile-submissions{display:grid;gap:10px}
  .mobile-submission{display:grid;gap:10px;padding:13px;border:1px solid #dfe3e9;border-radius:11px;background:#fff;overflow-wrap:anywhere}
  .mobile-submission header{display:flex;align-items:center;justify-content:space-between;gap:8px}
  .mobile-submission header label{display:flex;align-items:center;gap:9px}
  .mobile-submission header input{width:20px;height:20px;accent-color:#b82032}
  .mobile-submission>small{color:#667085}
  .mobile-submission dl{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0}
  .mobile-submission dt{color:#64748b;font-size:.72rem}.mobile-submission dd{margin:3px 0 0;font-weight:800}
  .mobile-submission details{border-top:1px solid #e5e7eb;padding-top:8px}.mobile-submission summary{min-height:36px;color:#9d1d2d;font-weight:800}
  .mobile-submission .source-files{min-width:0}.mobile-submission .file-download strong{white-space:normal;overflow-wrap:anywhere}
  .mobile-submission>.btn{min-height:44px}
  .mobile-filter-checklist{display:block;min-width:0;border:1px solid #d5d9e0;border-radius:9px;background:#fff}
  .mobile-filter-checklist summary{padding:12px;font-size:.83rem;font-weight:800;cursor:pointer}
  .mobile-filter-checklist>div{max-height:220px;overflow-y:auto;border-top:1px solid #e5e7eb}
  .filter-grid .mobile-filter-checklist label{display:flex;align-items:center;gap:10px;min-height:44px;padding:8px 12px;border-bottom:1px solid #f1f3f5;font-size:.8rem}
  .filter-grid .mobile-filter-checklist label:last-child{border-bottom:0}
  .filter-grid .mobile-filter-checklist input{width:20px;height:20px;flex:0 0 auto;accent-color:#b82032}
  .mobile-filter-hint{display:inline}
  .raw-scroll{max-height:none;overflow:visible}
  .raw-scroll>.raw-table{display:none}
  .mobile-records{display:grid;gap:10px}
  .mobile-record{display:grid;gap:9px;padding:13px;border:1px solid #dfe3e9;border-radius:11px;background:#fff;overflow-wrap:anywhere}
  .mobile-record.is-selected-history{border-color:#c92d3e;background:#fff8f9}
  .mobile-record header{display:flex;justify-content:space-between;align-items:center;gap:8px}
  .mobile-record header label{display:flex;align-items:center;gap:8px;font-size:.82rem}
  .mobile-record header input{width:20px;height:20px;accent-color:#b82032}
  .mobile-record header>strong{font-size:.82rem}
  .mobile-record p{margin:0}.mobile-record p b,.mobile-record p small{display:block}.mobile-record p small{margin-top:3px;color:#667085}
  .mobile-record-meta{display:flex;flex-wrap:wrap;gap:6px}.mobile-record-meta span{padding:5px 8px;border-radius:999px;background:#f1f5f9;font-size:.72rem}
  .mobile-record-comment{padding:10px 12px;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;background:#fff1f2;color:#b42318;font-weight:700;white-space:pre-wrap}
  .mobile-record details{border-top:1px solid #e5e7eb;padding-top:8px}.mobile-record summary{cursor:pointer;color:#9d1d2d;font-weight:800;min-height:36px}
  .mobile-record dl{display:grid;gap:8px;margin:8px 0}.mobile-record dl div{display:grid;gap:3px}.mobile-record dt{color:#64748b;font-size:.72rem}.mobile-record dd{margin:0;white-space:pre-wrap}
  .mobile-record>.btn{min-height:44px}
  .history-bulk-actions{flex-wrap:wrap;justify-content:flex-start}.history-bulk-actions button{min-height:44px}
}
@media(max-width:640px){
  .period-days{padding:8px;gap:10px}
  .planner-day-heading{padding:10px;flex-wrap:wrap}
  .planner-day-heading .add-row{min-height:44px}
  .planner-scroll{overflow:visible;border:0}
  .planner-table,.planner-table tbody,.planner-table tr{display:block;width:100%;min-width:0}
  .planner-table thead{display:none}
  .planner-table tr{display:grid;grid-template-columns:1fr 1fr;gap:0 12px;margin:10px 0;padding:12px;border:1px solid #dfe3e9;border-radius:12px;background:#fff;box-shadow:0 2px 8px #18223012}
  .planner-table td{display:block;grid-column:1/-1;min-width:0;padding:7px 0;border:0!important;background:transparent!important}
  .planner-table td::before{content:attr(data-label);display:block;margin-bottom:5px;color:#475569;font-size:12px;font-weight:800}
  .planner-table td.col-no,.planner-table td.col-date{position:static;grid-column:auto;width:auto;min-width:0;padding:3px 0 10px;text-align:left;color:#172033}
  .planner-table td.col-no::before,.planner-table td.col-date::before{color:#9d1d2d}
  .planner-table input,.planner-table select,.planner-table textarea,.planner-table input[type=date]{width:100%;min-width:0;min-height:44px;padding:10px;border:1px solid #d5d9e0;border-radius:8px;background:#fff;font-size:16px}
  .planner-table textarea{min-height:65px}
  .planner-table .sm-comment-readonly{background:#f8fafc}
  .planner-table td.col-remove{display:flex;justify-content:flex-end;align-items:center;gap:12px}
  .planner-table td.col-remove::before{margin:0}
  .planner-table .remove-row{min-width:44px;min-height:44px;border:1px solid #fecaca;border-radius:8px;background:#fff1f2}
  .planner-footer{gap:12px}
  .planner-actions,.planner-actions .save-table{width:100%}
  .day-switcher{width:100%;justify-content:space-between;min-width:0}
  .day-switcher strong{min-width:0;white-space:normal;font-size:.75rem}
}
.line-labels{position:relative;display:block;height:36px;padding:4px 0 0}.line-labels span{position:absolute;width:48px;text-align:center;transform:translateX(-50%)}
.bubble-chart .bubble-name,.bubble-chart .bubble-value{font-weight:900;paint-order:stroke;stroke:#fff;stroke-width:4px;stroke-linejoin:round}.bubble-chart .bubble-name{font-size:15px}.bubble-chart .bubble-value{font-size:12px}.seller-bubble-details{display:grid;gap:7px;margin-top:8px;padding-top:10px;border-top:1px solid #e5e7eb}.seller-bubble-details>div{display:grid;grid-template-columns:12px minmax(110px,1fr) minmax(55px,auto) minmax(60px,auto) minmax(90px,auto) minmax(105px,auto);gap:8px;align-items:center;padding:7px 9px;border-radius:8px;background:#f8fafc;font-size:.72rem}.seller-bubble-details i{width:11px;height:11px;border-radius:50%}.seller-bubble-details small{display:block;color:#64748b}.seller-bubble-details span{text-align:right;color:#475569}.seller-bubble-details b{text-align:right;color:#15803d}
.bubble-chart g,.line-chart g,.donut,.chart-legend>div,.report-bar,.objective-chart>div,.treemap article,.line-labels span,.seller-bubble-details>div{cursor:help}
@media (max-width: 1280px) {
  .report-grid { grid-template-columns: minmax(0, 1fr); }
  .department-bar { grid-template-columns: minmax(0, 1fr) auto; gap: 7px 12px; }
  .department-bar>strong { grid-column: 1; grid-row: 1; min-width: 0; overflow-wrap: anywhere; }
  .department-bar>b { grid-column: 2; grid-row: 1; text-align: right; white-space: nowrap; }
  .department-bar>div { grid-column: 1 / -1; grid-row: 2; width: 100%; height: 12px; }
  .department-bar>small { grid-column: 1 / -1; grid-row: 3; text-align: left; white-space: normal; overflow-wrap: break-word; }
}
.specialty-layout { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 20px 24px; }
.specialty-donut { flex: 0 0 170px; }
.specialty-layout .chart-legend { flex: 1 1 300px; width: auto; min-width: 0; }
.chart-legend.compact span { word-break: normal; overflow-wrap: break-word; line-height: 1.45; }
@media (max-width: 1280px) {
  .specialty-layout .chart-legend { flex-basis: 100%; }
}
.seller-bubble-details { min-width: 0; max-width: 100%; overflow-x: auto; }
@media (max-width: 720px) {
  .department-bar { grid-template-columns: minmax(0, 1fr) auto; }
  .department-bar>div,.department-bar>small { grid-column: 1 / -1; }
  .department-bar>div { grid-row: 2; }
  .department-bar>small { grid-row: 3; text-align: left; overflow-wrap: anywhere; }
  .department-bar>b { text-align: right; }
  .chart-legend>div { grid-template-columns: 10px minmax(0, 1fr) auto auto; }
  .seller-bubble-details>div { grid-template-columns: 12px minmax(0, 1fr) auto; }
  .seller-bubble-details i { grid-column: 1; grid-row: 1; }
  .seller-bubble-details strong { grid-column: 2; grid-row: 1; overflow-wrap: anywhere; }
  .seller-bubble-details b { grid-column: 3; grid-row: 1; }
  .seller-bubble-details small { grid-column: 2 / -1; grid-row: 2; }
  .seller-bubble-details span:nth-of-type(1) { grid-column: 2; grid-row: 3; text-align: left; }
  .seller-bubble-details span:nth-of-type(2) { grid-column: 3; grid-row: 3; }
}

/* Enterprise report surface: keep red for actions and emphasis, not large table fills. */
.daily-card{border-top:1px solid var(--gray-200)}
.planner-heading{border-bottom-color:var(--gray-200)}
.row-count{background:var(--primary-50);color:var(--primary-700);font-weight:600}
.planner-table th,.raw-table th{border-right:1px solid var(--gray-200);border-bottom:1px solid var(--gray-200);background:var(--gray-50);color:var(--gray-600);font-weight:600;text-transform:none}
.planner-table th.col-no,.planner-table th.col-date{background:var(--gray-100);color:var(--gray-700)}
.planner-table td{border-color:var(--gray-200)}
.planner-table tr:nth-child(even) td{background:var(--gray-50)}
.planner-table textarea:focus,.planner-table select:focus,.planner-table input:focus{outline:2px solid var(--primary-500);background:var(--primary-50)}
.planner-footer{border-top:1px solid var(--gray-200);background:var(--gray-50)}
.filter-grid label{color:var(--gray-700);font-weight:500}
.filter-grid input,.filter-grid select{border-color:var(--gray-300);border-radius:var(--radius-sm);background:var(--gray-0)}
.export-actions .btn,.file-download strong{display:inline-flex;align-items:center;gap:7px}
.file-download{border-color:var(--gray-200);border-radius:var(--radius-sm);background:var(--gray-0)}
.file-download:hover{border-color:var(--gray-300);background:var(--gray-50)}
.report-card,.chart-card{border-color:var(--gray-200);box-shadow:var(--shadow-xs)}
</style>
