<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import CompatDateTimeInput from '../components/CompatDateTimeInput.vue'
import StatusPill from '../components/StatusPill.vue'
import UiIcon from '../components/UiIcon.vue'
import { auth } from '../stores/auth'
import type { FibroscanApprovalStatus, FibroscanBooking, FibroscanBookingStatus, FibroscanMachine, FibroscanMachineStatus } from '../types/api'
import { apiError } from '../utils/format'

const bookings = ref<FibroscanBooking[]>([])
const calendarBookings = ref<FibroscanBooking[]>([])
const machines = ref<FibroscanMachine[]>([])
const loading = ref(false)
const saving = ref(false)
const machineSaving = ref(false)
const machineDeletingId = ref<number | null>(null)
const machineEditingId = ref<number | null>(null)
const error = ref('')
const success = ref('')
const editingId = ref<number | null>(null)
const selectedBooking = ref<FibroscanBooking | null>(null)
const selectedBookingStatus = ref<FibroscanBookingStatus>('จองแล้ว')
const selectedDate = ref(localIso(new Date()))
const bookingDetailRef = ref<HTMLElement | null>(null)
const bookingFormRef = ref<HTMLFormElement | null>(null)
const reviewNote = ref('')
const availabilityConflicts = ref<Array<Pick<FibroscanBooking, 'booking_id' | 'machine_id' | 'hospital_name' | 'reserved_from' | 'reserved_until'>>>([])
let availabilityTimer: ReturnType<typeof setTimeout> | null = null
let availabilityRequest = 0
const canManageMachines = computed(() => auth.can('system_admin'))
const canReviewBookings = computed(() => auth.can('approve_bills') || auth.can('system_admin'))
const purposes = ['ทดลองใช้', 'Event', 'เช่า', 'สาธิต', 'อื่นๆ'] as const
const bookingStatuses: FibroscanBookingStatus[] = ['จองแล้ว', 'กำลังใช้งาน', 'คืนแล้ว', 'ยกเลิก']
const approvalStatuses: FibroscanApprovalStatus[] = ['รออนุมัติ', 'อนุมัติแล้ว', 'ไม่อนุมัติ']
const machineStatuses: FibroscanMachineStatus[] = ['พร้อมใช้งาน', 'ซ่อมบำรุง', 'เลิกใช้งาน']

function canChangeBooking(booking: FibroscanBooking): boolean {
  return booking.can_edit && (booking.can_manage_all || !['คืนแล้ว', 'ยกเลิก'].includes(booking.status))
}

function canChangeOperationalStatus(booking: FibroscanBooking): boolean {
  return booking.can_manage_all && booking.approval_status === 'อนุมัติแล้ว'
}

function localDateTime(date: Date): string {
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

function monthBounds(): { from: string; to: string } {
  const now = new Date()
  return {
    from: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`,
    to: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()).padStart(2, '0')}`,
  }
}

const bounds = monthBounds()
const calendarMonth = ref(bounds.from.slice(0, 7))
const filters = reactive({ date_from: '', date_to: '', machine_id: '', status: '', approval_status: '' })
const bookingForm = reactive({
  machine_id: '', hospital_name: '', purpose: 'เช่า' as FibroscanBooking['purpose'],
  reserved_from: localDateTime(new Date()),
  reserved_until: localDateTime(new Date(Date.now() + 24 * 60 * 60 * 1000)),
  contact_name: '', contact_phone: '', note: '',
})
const machineForm = reactive({ machine_code: '', machine_name: '', serial_number: '', note: '', status: 'พร้อมใช้งาน' as FibroscanMachineStatus })

const selectedFrom = computed(() => new Date(bookingForm.reserved_from).getTime())
const selectedUntil = computed(() => new Date(bookingForm.reserved_until).getTime())
const machineAvailability = computed(() => machines.value.map((machine) => {
  const conflict = availabilityConflicts.value.find((booking) => booking.machine_id === machine.machine_id)
  return { machine, conflict, available: machine.status === 'พร้อมใช้งาน' && !conflict }
}))

const approvedCalendarBookings = computed(() => calendarBookings.value.filter((booking) =>
  booking.approval_status === 'อนุมัติแล้ว' && booking.status !== 'ยกเลิก',
))

const selectedDayBookings = computed(() => {
  const start = new Date(`${selectedDate.value}T00:00:00`).getTime()
  const end = new Date(`${selectedDate.value}T00:00:00`)
  end.setDate(end.getDate() + 1)
  return approvedCalendarBookings.value.filter((booking) =>
    new Date(booking.reserved_from).getTime() < end.getTime()
    && new Date(booking.reserved_until).getTime() > start,
  )
})

const selectedDayMachines = computed(() => machines.value.map((machine) => {
  const booking = selectedDayBookings.value.find(item => item.machine_id === machine.machine_id)
  return {
    machine,
    booking,
    available: machine.status === 'พร้อมใช้งาน' && !booking,
  }
}))

function localIso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function calendarRange(month: string) {
  const [year, value] = month.split('-').map(Number)
  const first = new Date(year, value - 1, 1)
  const offset = (first.getDay() + 6) % 7
  const start = new Date(year, value - 1, 1 - offset)
  const end = new Date(start)
  end.setDate(start.getDate() + 41)
  return { start, end, currentMonth: value - 1 }
}

const calendarDays = computed(() => {
  const { start, currentMonth } = calendarRange(calendarMonth.value)
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start)
    date.setDate(start.getDate() + index)
    const iso = localIso(date)
    const dayStart = new Date(`${iso}T00:00:00`).getTime()
    const dayEnd = new Date(`${iso}T00:00:00`)
    dayEnd.setDate(dayEnd.getDate() + 1)
    const dayBookings = approvedCalendarBookings.value.filter((booking) =>
      new Date(booking.reserved_from).getTime() < dayEnd.getTime()
      && new Date(booking.reserved_until).getTime() > dayStart,
    )
    return {
      iso,
      day: date.getDate(),
      current: date.getMonth() === currentMonth,
      today: iso === localIso(new Date()),
      bookings: dayBookings,
      machineCount: new Set(dayBookings.map(booking => booking.machine_id)).size,
    }
  })
})

const calendarWeeks = computed(() => Array.from({ length: 6 }, (_, weekIndex) => {
  const days = calendarDays.value.slice(weekIndex * 7, weekIndex * 7 + 7)
  const weekStart = new Date(`${days[0].iso}T00:00:00`).getTime()
  const weekEndDate = new Date(`${days[6].iso}T00:00:00`)
  weekEndDate.setDate(weekEndDate.getDate() + 1)
  const weekEnd = weekEndDate.getTime()
  const tracks: number[] = []
  const segments = approvedCalendarBookings.value
    .map((booking) => {
      const from = new Date(booking.reserved_from).getTime()
      const until = new Date(booking.reserved_until).getTime()
      if (from >= weekEnd || until <= weekStart) return null
      const occupiedDays = days
        .map((day, index) => {
          const dayStart = new Date(`${day.iso}T00:00:00`).getTime()
          const dayEnd = new Date(`${day.iso}T00:00:00`)
          dayEnd.setDate(dayEnd.getDate() + 1)
          return from < dayEnd.getTime() && until > dayStart ? index : -1
        })
        .filter(index => index >= 0)
      if (!occupiedDays.length) return null
      return {
        booking,
        start: occupiedDays[0],
        end: occupiedDays[occupiedDays.length - 1],
        continuesBefore: from < weekStart,
        continuesAfter: until > weekEnd,
        track: 0,
      }
    })
    .filter((segment): segment is NonNullable<typeof segment> => segment !== null)
    .sort((a, b) => a.start - b.start || b.end - a.end || a.booking.booking_id - b.booking.booking_id)

  segments.forEach((segment) => {
    const freeTrack = tracks.findIndex(lastDay => lastDay < segment.start)
    segment.track = freeTrack >= 0 ? freeTrack : tracks.length
    tracks[segment.track] = segment.end
  })

  return { days, segments, trackCount: tracks.length }
}))

const bookingColors = [
  { background: '#dff3e7', border: '#2eaf5f', text: '#145c32' },
  { background: '#e5f0ff', border: '#2f80ed', text: '#174f91' },
  { background: '#fff0d0', border: '#d39b00', text: '#705100' },
  { background: '#f0e8ff', border: '#805ad5', text: '#553096' },
  { background: '#ffe7ed', border: '#d94b6a', text: '#8f243d' },
  { background: '#e2f6f5', border: '#199b96', text: '#12635f' },
]

function calendarEventStyle(segment: { booking: FibroscanBooking; start: number; end: number; track: number }) {
  const color = bookingColors[segment.booking.booking_id % bookingColors.length]
  return {
    gridColumn: `${segment.start + 1} / ${segment.end + 2}`,
    gridRow: segment.track + 1,
    '--booking-background': color.background,
    '--booking-border': color.border,
    '--booking-text': color.text,
  }
}

const calendarMonthLabel = computed(() => {
  const [year, month] = calendarMonth.value.split('-').map(Number)
  return new Intl.DateTimeFormat('th-TH', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1))
})

function dateTime(value: string): string {
  return new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function resetBookingForm() {
  editingId.value = null
  Object.assign(bookingForm, {
    machine_id: '', hospital_name: '', purpose: 'เช่า',
    reserved_from: localDateTime(new Date()),
    reserved_until: localDateTime(new Date(Date.now() + 24 * 60 * 60 * 1000)),
    contact_name: '', contact_phone: '', note: '',
  })
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: { bookings: FibroscanBooking[]; machines: FibroscanMachine[] } }>('/api/v1/fibroscan-bookings', { params: filters })
    bookings.value = response.data.data.bookings
    machines.value = response.data.data.machines
  } catch (cause) {
    error.value = apiError(cause, 'โหลดตารางจองเครื่องไม่สำเร็จ กรุณาตรวจสอบว่าได้รันไฟล์ฐานข้อมูลแล้ว')
  } finally {
    loading.value = false
  }
}

function clearRequestFilters() {
  Object.assign(filters, { date_from: '', date_to: '', machine_id: '', status: '', approval_status: '' })
  void load()
}

async function loadCalendar() {
  const { start, end } = calendarRange(calendarMonth.value)
  try {
    const response = await api.get<{ data: { bookings: FibroscanBooking[]; machines: FibroscanMachine[] } }>('/api/v1/fibroscan-bookings', {
      params: { date_from: localIso(start), date_to: localIso(end) },
    })
    calendarBookings.value = response.data.data.bookings
    machines.value = response.data.data.machines
    if (selectedBooking.value) {
      selectedBooking.value = calendarBookings.value.find((item) => item.booking_id === selectedBooking.value?.booking_id)
        || bookings.value.find((item) => item.booking_id === selectedBooking.value?.booking_id)
        || null
    }
  } catch (cause) {
    error.value = apiError(cause, 'โหลดปฏิทินการจองไม่สำเร็จ')
  }
}

function shiftCalendarMonth(delta: number) {
  const [year, month] = calendarMonth.value.split('-').map(Number)
  const target = new Date(year, month - 1 + delta, 1)
  calendarMonth.value = localIso(target).slice(0, 7)
  selectedDate.value = `${calendarMonth.value}-01`
  loadCalendar()
}

function goCurrentMonth() {
  calendarMonth.value = localIso(new Date()).slice(0, 7)
  selectedDate.value = localIso(new Date())
  loadCalendar()
}

async function scrollToOnPhone(target: HTMLElement | null) {
  if (!window.matchMedia('(max-width: 640px)').matches) return
  await nextTick()
  target?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function openBooking(booking: FibroscanBooking) {
  selectedBooking.value = booking
  selectedDate.value = localIso(new Date(booking.reserved_from))
  selectedBookingStatus.value = booking.status
  reviewNote.value = booking.review_note || ''
  void scrollToOnPhone(bookingDetailRef.value)
}

function selectCalendarDay(date: string) {
  selectedDate.value = date
  selectedBooking.value = null
  void scrollToOnPhone(bookingDetailRef.value)
}

function selectMachineForRequest(machine: FibroscanMachine) {
  bookingForm.machine_id = String(machine.machine_id)
  if (selectedDate.value >= localIso(new Date())) {
    bookingForm.reserved_from = `${selectedDate.value}T09:00`
    bookingForm.reserved_until = `${selectedDate.value}T17:00`
  }
  void scrollToOnPhone(bookingFormRef.value)
}

async function checkAvailability() {
  if (!bookingForm.reserved_from || !bookingForm.reserved_until || selectedUntil.value <= selectedFrom.value) {
    availabilityConflicts.value = []
    return
  }
  const requestNumber = ++availabilityRequest
  try {
    const response = await api.get<{ data: typeof availabilityConflicts.value }>('/api/v1/fibroscan-bookings-availability', {
      params: {
        reserved_from: new Date(bookingForm.reserved_from).toISOString(),
        reserved_until: new Date(bookingForm.reserved_until).toISOString(),
        except_booking_id: editingId.value || undefined,
      },
    })
    if (requestNumber === availabilityRequest) availabilityConflicts.value = response.data.data
  } catch {
    if (requestNumber === availabilityRequest) availabilityConflicts.value = []
  }
}

function scheduleAvailabilityCheck() {
  if (availabilityTimer) clearTimeout(availabilityTimer)
  availabilityTimer = setTimeout(checkAvailability, 250)
}

async function saveBooking() {
  error.value = ''; success.value = ''
  if (selectedUntil.value <= selectedFrom.value) { error.value = 'วันและเวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม'; return }
  saving.value = true
  try {
    const payload = {
      ...bookingForm,
      machine_id: Number(bookingForm.machine_id),
      reserved_from: new Date(bookingForm.reserved_from).toISOString(),
      reserved_until: new Date(bookingForm.reserved_until).toISOString(),
    }
    if (editingId.value) await api.put(`/api/v1/fibroscan-bookings/${editingId.value}`, payload)
    else await api.post('/api/v1/fibroscan-bookings', payload)
    success.value = editingId.value ? 'บันทึกการแก้ไขแล้ว ผู้ขอสามารถตรวจสอบประวัติได้' : 'ส่งคำขอจองแล้ว กรุณารอ Admin หรือ Approver พิจารณา'
    resetBookingForm()
    await Promise.all([load(), loadCalendar()])
  } catch (cause) {
    error.value = apiError(cause, 'บันทึกรายการจองไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function editBooking(booking: FibroscanBooking) {
  editingId.value = booking.booking_id
  Object.assign(bookingForm, {
    machine_id: String(booking.machine_id), hospital_name: booking.hospital_name,
    purpose: booking.purpose, reserved_from: localDateTime(new Date(booking.reserved_from)),
    reserved_until: localDateTime(new Date(booking.reserved_until)), contact_name: booking.contact_name || '',
    contact_phone: booking.contact_phone || '', note: booking.note || '',
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function changeBookingStatus(booking: FibroscanBooking, status: FibroscanBookingStatus) {
  if (status === 'ยกเลิก' && !confirm(`ยกเลิกการจอง ${booking.machine.machine_code} ของ ${booking.hospital_name} หรือไม่?`)) return
  try {
    await api.patch(`/api/v1/fibroscan-bookings/${booking.booking_id}/status`, { status })
    await Promise.all([load(), loadCalendar()])
    const updated = calendarBookings.value.find((item) => item.booking_id === booking.booking_id)
      || bookings.value.find((item) => item.booking_id === booking.booking_id)
    selectedBooking.value = updated || null
    if (updated) selectedBookingStatus.value = updated.status
  } catch (cause) { error.value = apiError(cause, 'เปลี่ยนสถานะไม่สำเร็จ') }
}

async function reviewBooking(booking: FibroscanBooking, approvalStatus: Exclude<FibroscanApprovalStatus, 'รออนุมัติ'>) {
  const message = approvalStatus === 'อนุมัติแล้ว'
    ? `อนุมัติคำขอ ${booking.machine.machine_code} ของ ${booking.owner?.username || booking.booked_by} หรือไม่?`
    : `ไม่อนุมัติคำขอ ${booking.machine.machine_code} ของ ${booking.owner?.username || booking.booked_by} หรือไม่?`
  if (!confirm(message)) return
  error.value = ''; success.value = ''
  try {
    await api.patch(`/api/v1/fibroscan-bookings/${booking.booking_id}/approval`, {
      approval_status: approvalStatus,
      review_note: reviewNote.value || null,
    })
    success.value = approvalStatus === 'อนุมัติแล้ว'
      ? 'อนุมัติคำขอแล้ว รายการถูกเพิ่มลงปฏิทินกลาง'
      : 'บันทึกผลไม่อนุมัติแล้ว Sale สามารถดูเหตุผลและประวัติได้'
    await Promise.all([load(), loadCalendar()])
    const updated = calendarBookings.value.find(item => item.booking_id === booking.booking_id)
      || bookings.value.find(item => item.booking_id === booking.booking_id)
    selectedBooking.value = updated || null
  } catch (cause) {
    error.value = apiError(cause, 'พิจารณาคำขอไม่สำเร็จ')
  }
}

const historyFieldLabels: Record<string, string> = {
  machine_id: 'เครื่อง', hospital_name: 'โรงพยาบาล', purpose: 'วัตถุประสงค์',
  reserved_from: 'วันเวลาเริ่ม', reserved_until: 'วันเวลาสิ้นสุด', contact_name: 'ผู้ประสานงาน',
  contact_phone: 'เบอร์โทร', note: 'หมายเหตุ', approval_status: 'ผลอนุมัติ',
  review_note: 'หมายเหตุการพิจารณา', status: 'สถานะใช้งาน',
}

function historyValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return '-'
  if (field === 'machine_id') return machines.value.find(machine => machine.machine_id === Number(value))?.machine_code || String(value)
  if (['reserved_from', 'reserved_until'].includes(field)) return dateTime(String(value))
  return String(value)
}

function resetMachineForm() {
  machineEditingId.value = null
  Object.assign(machineForm, { machine_code: '', machine_name: '', serial_number: '', note: '', status: 'พร้อมใช้งาน' })
}

function editMachine(machine: FibroscanMachine) {
  machineEditingId.value = machine.machine_id
  Object.assign(machineForm, {
    machine_code: machine.machine_code,
    machine_name: machine.machine_name,
    serial_number: machine.serial_number || '',
    note: machine.note || '',
    status: machine.status,
  })
}

async function saveMachine() {
  error.value = ''; success.value = ''
  machineSaving.value = true
  try {
    if (machineEditingId.value) {
      await api.put(`/api/v1/fibroscan-machines/${machineEditingId.value}`, machineForm)
      success.value = 'บันทึกข้อมูลเครื่องแล้ว'
    } else {
      await api.post('/api/v1/fibroscan-machines', machineForm)
      success.value = 'เพิ่มเครื่องเข้าทะเบียนแล้ว'
    }
    resetMachineForm()
    await load()
  } catch (cause) { error.value = apiError(cause, machineEditingId.value ? 'แก้ไขข้อมูลเครื่องไม่สำเร็จ' : 'เพิ่มเครื่องไม่สำเร็จ') }
  finally { machineSaving.value = false }
}

async function changeMachineStatus(machine: FibroscanMachine, status: FibroscanMachineStatus) {
  error.value = ''; success.value = ''
  try {
    await api.put(`/api/v1/fibroscan-machines/${machine.machine_id}`, {
      machine_code: machine.machine_code, machine_name: machine.machine_name,
      serial_number: machine.serial_number, note: machine.note, status,
    })
    success.value = `เปลี่ยนสถานะ ${machine.machine_code} เป็น “${status}” แล้ว`
    await load()
  } catch (cause) {
    error.value = apiError(cause, 'เปลี่ยนสถานะเครื่องไม่สำเร็จ')
    await load()
  }
}

async function deleteMachine(machine: FibroscanMachine) {
  if (!confirm(`ลบเครื่อง ${machine.machine_code} · ${machine.machine_name} ออกจากทะเบียนหรือไม่?`)) return
  error.value = ''; success.value = ''; machineDeletingId.value = machine.machine_id
  try {
    await api.delete(`/api/v1/fibroscan-machines/${machine.machine_id}`)
    if (machineEditingId.value === machine.machine_id) resetMachineForm()
    if (bookingForm.machine_id === String(machine.machine_id)) bookingForm.machine_id = ''
    if (filters.machine_id === String(machine.machine_id)) filters.machine_id = ''
    success.value = 'ลบเครื่องออกจากทะเบียนแล้ว'
    await Promise.all([load(), loadCalendar()])
  } catch (cause) { error.value = apiError(cause, 'ลบเครื่องไม่สำเร็จ') }
  finally { machineDeletingId.value = null }
}

watch([() => bookingForm.reserved_from, () => bookingForm.reserved_until, editingId], scheduleAvailabilityCheck, { immediate: true })
watch(selectedBooking, (booking) => {
  if (!booking) return
  selectedBookingStatus.value = booking.status
  reviewNote.value = booking.review_note || ''
})
onMounted(() => { load(); loadCalendar() })
onBeforeUnmount(() => { if (availabilityTimer) clearTimeout(availabilityTimer) })
</script>

<template>
  <AppShell title="ตารางจองเครื่อง FibroScan" subtitle="Sale ตรวจสอบเครื่องว่างและส่งคำขอ จากนั้น Admin หรือ Approver ตรวจสอบ แก้ไข และอนุมัติก่อนลงปฏิทินกลาง">
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="success" class="success-message">{{ success }}</p>

<div class="equipment-calendar-layout section-gap">
      <section class="content-card">
        <div class="section-heading equipment-calendar-heading">
          <div><p class="eyebrow">Monthly calendar</p><h2>ปฏิทินการจองเครื่อง</h2></div>
          <div class="equipment-calendar-nav">
            <button type="button" class="secondary-button" aria-label="เดือนก่อนหน้า" @click="shiftCalendarMonth(-1)">‹</button>
            <strong>{{ calendarMonthLabel }}</strong>
            <button type="button" class="secondary-button" aria-label="เดือนถัดไป" @click="shiftCalendarMonth(1)">›</button>
            <button type="button" class="secondary-button" @click="goCurrentMonth">เดือนนี้</button>
          </div>
        </div>
        <div class="calendar-weekdays"><span v-for="name in ['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์','อาทิตย์']" :key="name">{{ name }}</span></div>
        <div class="equipment-calendar-weeks">
          <section v-for="(week, weekIndex) in calendarWeeks" :key="weekIndex" class="equipment-calendar-week" :style="{ minHeight: `${Math.max(140, 42 + week.trackCount * 34)}px` }">
            <div class="equipment-week-days">
              <article
                v-for="day in week.days"
                :key="day.iso"
                class="equipment-calendar-day"
                :class="{ muted: !day.current, today: day.today, selected: selectedDate === day.iso }"
                role="button"
                tabindex="0"
                @click="selectCalendarDay(day.iso)"
                @keydown.enter="selectCalendarDay(day.iso)"
                @keydown.space.prevent="selectCalendarDay(day.iso)"
              >
                <header><b>{{ day.day }}</b><small v-if="day.machineCount">{{ day.machineCount }} เครื่อง</small></header>
              </article>
            </div>
            <div class="equipment-week-events">
              <button
                v-for="segment in week.segments"
                :key="`${weekIndex}-${segment.booking.booking_id}`"
                type="button"
                class="equipment-calendar-event"
                :class="[
                  `equipment-calendar-event--${segment.booking.status}`,
                  { 'continues-before': segment.continuesBefore, 'continues-after': segment.continuesAfter },
                ]"
                :style="calendarEventStyle(segment)"
                :title="`${segment.booking.machine.machine_code} · ${segment.booking.hospital_name} · จองโดย ${segment.booking.owner?.username || segment.booking.booked_by}`"
                @click="openBooking(segment.booking)"
              >
                <strong>{{ segment.booking.machine.machine_code }}</strong>
                <span>{{ segment.booking.hospital_name }} · {{ segment.booking.purpose }}</span>
              </button>
            </div>
          </section>
        </div>
      </section>

      <aside ref="bookingDetailRef" class="content-card equipment-booking-detail">
        <div class="section-heading"><div><p class="eyebrow">Daily availability</p><h2>{{ new Intl.DateTimeFormat('th-TH', { dateStyle: 'long' }).format(new Date(`${selectedDate}T00:00:00`)) }}</h2></div><small>{{ selectedDayBookings.length }} รายการจอง</small></div>
        <div class="equipment-day-machines">
          <button v-for="item in selectedDayMachines" :key="item.machine.machine_id" type="button" :class="{ available: item.available, occupied: !item.available }" :disabled="!item.booking && !item.available" @click="item.booking ? openBooking(item.booking) : selectMachineForRequest(item.machine)">
            <span><b>{{ item.machine.machine_code }}</b><small>{{ item.machine.machine_name }}</small></span>
            <strong>{{ item.available ? 'ว่าง · ขอจอง' : item.machine.status !== 'พร้อมใช้งาน' ? item.machine.status : 'มีการจอง' }}</strong>
            <small v-if="item.booking">{{ item.booking.hospital_name }}<br>{{ dateTime(item.booking.reserved_from) }} – {{ dateTime(item.booking.reserved_until) }}</small>
          </button>
        </div>

        <template v-if="selectedBooking">
          <div class="section-heading equipment-selected-heading"><div><p class="eyebrow eyebrow--red">Request / booking detail</p><h2>{{ selectedBooking.machine.machine_code }}</h2></div><span class="approval-pill" :class="`approval-${selectedBooking.approval_status}`">{{ selectedBooking.approval_status }}</span></div>
          <dl class="equipment-booking-detail__grid">
            <div><dt>เครื่อง</dt><dd>{{ selectedBooking.machine.machine_name }}</dd></div>
            <div><dt>โรงพยาบาล</dt><dd>{{ selectedBooking.hospital_name }}</dd></div>
            <div><dt>ลักษณะงาน</dt><dd>{{ selectedBooking.purpose }}</dd></div>
            <div><dt>ช่วงใช้งาน</dt><dd>{{ dateTime(selectedBooking.reserved_from) }}<br>ถึง {{ dateTime(selectedBooking.reserved_until) }}</dd></div>
            <div><dt>Sale ผู้จอง</dt><dd>{{ selectedBooking.owner?.username || selectedBooking.booked_by }}<br><small>{{ selectedBooking.owner?.department || selectedBooking.booked_by_department || '-' }}</small></dd></div>
            <div><dt>ผู้ประสานงานลูกค้า</dt><dd>{{ selectedBooking.contact_name || '-' }}<br><small>{{ selectedBooking.contact_phone || '-' }}</small></dd></div>
            <div class="equipment-booking-detail__wide"><dt>รายละเอียดงาน/หมายเหตุ</dt><dd>{{ selectedBooking.note || '-' }}</dd></div>
            <div class="equipment-booking-detail__wide"><dt>ผลการพิจารณา</dt><dd>{{ selectedBooking.review_note || '-' }}<br><small v-if="selectedBooking.reviewed_by">โดย {{ selectedBooking.reviewed_by }} · {{ selectedBooking.reviewed_at ? dateTime(selectedBooking.reviewed_at) : '-' }}</small></dd></div>
          </dl>
          <div v-if="canReviewBookings && selectedBooking.approval_status === 'รออนุมัติ'" class="equipment-review-actions">
            <label><span>หมายเหตุถึง Sale</span><textarea v-model.trim="reviewNote" rows="3" maxlength="5000" placeholder="ระบุเหตุผลหรือรายละเอียดที่แก้ไข (ถ้ามี)"></textarea></label>
            <div><button type="button" class="primary-button" @click="reviewBooking(selectedBooking, 'อนุมัติแล้ว')">อนุมัติคำขอ</button><button type="button" class="danger-button" @click="reviewBooking(selectedBooking, 'ไม่อนุมัติ')">ไม่อนุมัติ</button></div>
          </div>
          <div v-if="canChangeOperationalStatus(selectedBooking)" class="equipment-detail-actions">
            <label><span>อัปเดตสถานะงาน</span><select v-model="selectedBookingStatus"><option v-for="status in bookingStatuses" :key="status">{{ status }}</option></select></label>
            <button type="button" class="primary-button" :disabled="selectedBookingStatus === selectedBooking.status" @click="changeBookingStatus(selectedBooking, selectedBookingStatus)">บันทึกสถานะ</button>
          </div>
          <button v-if="canChangeBooking(selectedBooking)" type="button" class="secondary-button equipment-edit-button" @click="editBooking(selectedBooking)">{{ selectedBooking.can_manage_all ? 'แก้ไขข้อมูลคำขอ/การจอง' : 'แก้ไขคำขอของฉัน' }}</button>

          <div class="equipment-change-history">
            <h3>ประวัติการดำเนินการ</h3>
            <article v-for="change in selectedBooking.changes || []" :key="change.change_id">
              <header><strong>{{ change.action }}</strong><small>{{ change.changed_by }} · {{ dateTime(change.created_at) }}</small></header>
              <ul v-if="Object.keys(change.changes || {}).length">
                <li v-for="(values, field) in change.changes" :key="field"><b>{{ historyFieldLabels[field] || field }}:</b> {{ historyValue(field, values.from) }} → {{ historyValue(field, values.to) }}</li>
              </ul>
            </article>
            <p v-if="!selectedBooking.changes?.length" class="muted">ยังไม่มีประวัติการเปลี่ยนแปลง</p>
          </div>
        </template>
        <div v-else class="equipment-detail-empty"><span><UiIcon name="activity" :size="28" /></span><strong>เลือกวันที่แล้ว</strong><p>เครื่องว่างและเครื่องที่ถูกจองในวันดังกล่าวแสดงอยู่ด้านบน กดรายการเครื่องที่จองเพื่อดูรายละเอียด</p></div>
      </aside>
    </div>

    <form ref="bookingFormRef" class="content-card form-grid" @submit.prevent="saveBooking">
      <div class="span-2 section-heading">
        <div><p class="eyebrow eyebrow--red">Booking request</p><h2>{{ editingId ? 'แก้ไขคำขอ/ข้อมูลการจอง' : 'ขอจองเครื่อง' }}</h2><small class="muted">การส่งคำขอยังไม่ถือว่าได้จอง จนกว่า Admin หรือ Approver จะอนุมัติ</small></div>
        <button v-if="editingId" type="button" class="secondary-button" @click="resetBookingForm">ยกเลิกการแก้ไข</button>
      </div>
      <label><span>เครื่อง *</span><select v-model="bookingForm.machine_id" required><option value="" disabled>เลือกเครื่อง</option><option v-for="machine in machines.filter((item) => item.status === 'พร้อมใช้งาน' || item.machine_id === Number(bookingForm.machine_id))" :key="machine.machine_id" :value="String(machine.machine_id)">{{ machine.machine_code }} · {{ machine.machine_name }}</option></select></label>
      <label><span>โรงพยาบาล *</span><input v-model.trim="bookingForm.hospital_name" required maxlength="500" placeholder="ชื่อโรงพยาบาล"></label>
      <label><span>เริ่มใช้/นำเครื่องออก *</span><CompatDateTimeInput v-model="bookingForm.reserved_from" mode="datetime-local" required /></label>
      <label><span>สิ้นสุด/เครื่องกลับถึงบริษัท *</span><CompatDateTimeInput v-model="bookingForm.reserved_until" mode="datetime-local" required /></label>
      <label><span>วัตถุประสงค์ *</span><select v-model="bookingForm.purpose"><option v-for="purpose in purposes" :key="purpose">{{ purpose }}</option></select></label>
      <label><span>ผู้ประสานงานโรงพยาบาล</span><input v-model.trim="bookingForm.contact_name" maxlength="255"></label>
      <label><span>เบอร์โทรศัพท์</span><input v-model.trim="bookingForm.contact_phone" maxlength="100"></label>
      <label class="span-2"><span>หมายเหตุ</span><textarea v-model.trim="bookingForm.note" rows="3" maxlength="5000" placeholder="รายละเอียด Event, สถานที่จัดงาน หรือข้อมูลที่ Sale คนอื่นควรรู้"></textarea></label>

      <div class="span-2 equipment-availability">
        <strong>ตรวจสอบเครื่องในช่วงเวลาที่เลือก</strong>
        <div class="equipment-availability__grid">
          <article v-for="item in machineAvailability" :key="item.machine.machine_id" :class="{ 'is-unavailable': !item.available }">
            <b>{{ item.machine.machine_code }}</b><span>{{ item.machine.machine_name }}</span>
            <small>{{ item.available ? 'ว่าง — สามารถส่งคำขอได้' : item.machine.status !== 'พร้อมใช้งาน' ? item.machine.status : `มีผู้จองแล้ว · ${item.conflict?.hospital_name}` }}</small>
          </article>
        </div>
      </div>
      <button class="primary-button span-2" :disabled="saving || !machines.length">{{ saving ? 'กำลังบันทึก…' : editingId ? 'บันทึกการแก้ไข' : 'ส่งคำขอจองเครื่อง' }}</button>
    </form>

    <section v-if="canManageMachines" class="content-card section-gap">
      <div class="section-heading"><div><p class="eyebrow eyebrow--red">Equipment registry</p><h2>ทะเบียนเครื่อง FibroScan</h2></div><small class="muted">เครื่องที่ขายออกให้เปลี่ยนเป็น “เลิกใช้งาน” เพื่อเก็บประวัติเดิม</small></div>
      <form class="equipment-machine-form" :class="{ 'is-editing': machineEditingId }" @submit.prevent="saveMachine">
        <label><span>รหัสเครื่อง *</span><input v-model.trim="machineForm.machine_code" required maxlength="50" placeholder="เช่น FIBRO-01"></label>
        <label><span>ชื่อเครื่อง *</span><input v-model.trim="machineForm.machine_name" required maxlength="255" placeholder="เช่น FibroScan Compact 530"></label>
        <label><span>Serial Number</span><input v-model.trim="machineForm.serial_number" maxlength="255"></label>
        <label><span>หมายเหตุ</span><input v-model.trim="machineForm.note" maxlength="5000"></label>
        <label v-if="machineEditingId"><span>สถานะเครื่อง</span><select v-model="machineForm.status"><option v-for="status in machineStatuses" :key="status">{{ status }}</option></select></label>
        <div class="equipment-machine-form-actions"><button class="primary-button" :disabled="machineSaving">{{ machineSaving ? 'กำลังบันทึก…' : machineEditingId ? 'บันทึกการแก้ไข' : 'เพิ่มเครื่อง' }}</button><button v-if="machineEditingId" class="secondary-button" type="button" :disabled="machineSaving" @click="resetMachineForm">ยกเลิก</button></div>
      </form>
      <div class="equipment-machine-list">
        <article v-for="machine in machines" :key="machine.machine_id"><div><strong>{{ machine.machine_code }} · {{ machine.machine_name }}</strong><small>Serial: {{ machine.serial_number || '-' }} · {{ machine.note || 'ไม่มีหมายเหตุ' }}</small></div><div class="equipment-machine-actions"><select :value="machine.status" @change="changeMachineStatus(machine, ($event.target as HTMLSelectElement).value as FibroscanMachineStatus)"><option v-for="status in machineStatuses" :key="status">{{ status }}</option></select><button class="secondary-button" type="button" @click="editMachine(machine)">แก้ไข</button><button class="danger-button" type="button" :disabled="machineDeletingId === machine.machine_id" @click="deleteMachine(machine)">{{ machineDeletingId === machine.machine_id ? 'กำลังลบ…' : 'ลบ' }}</button></div></article>
        <p v-if="!machines.length" class="empty-state">ยังไม่มีเครื่องในทะเบียน กรุณาเพิ่มเครื่องก่อนเริ่มจอง</p>
      </div>
    </section>

    <section class="content-card section-gap equipment-request-queue">
      <div class="section-heading">
        <div><p class="eyebrow">Booking request queue</p><h2>คำขอและตารางการใช้งานเครื่อง</h2><small class="muted">คำขอรออนุมัติจะแสดงก่อน และเรียงจากเวลาที่ส่งคำขอล่าสุด ไม่ว่ากำหนดใช้งานจะล่วงหน้าไปกี่เดือน</small></div>
        <button class="secondary-button" :disabled="loading" @click="load">{{ loading ? 'กำลังโหลด…' : 'โหลดข้อมูลใหม่' }}</button>
      </div>
      <div class="equipment-filters">
        <label><span>วันใช้งานตั้งแต่</span><CompatDateTimeInput v-model="filters.date_from" mode="date" /></label>
        <label><span>วันใช้งานถึง</span><CompatDateTimeInput v-model="filters.date_to" mode="date" /></label>
        <label><span>เครื่อง</span><select v-model="filters.machine_id"><option value="">ทุกเครื่อง</option><option v-for="machine in machines" :key="machine.machine_id" :value="String(machine.machine_id)">{{ machine.machine_code }}</option></select></label>
        <label><span>สถานะใช้งาน</span><select v-model="filters.status"><option value="">ทุกสถานะ</option><option v-for="status in bookingStatuses" :key="status">{{ status }}</option></select></label>
        <label><span>ผลอนุมัติ</span><select v-model="filters.approval_status"><option value="">ทุกสถานะ</option><option v-for="status in approvalStatuses" :key="status">{{ status }}</option></select></label>
        <div class="equipment-filter-actions"><button class="secondary-button" @click="load">ค้นหา</button><button class="secondary-button" @click="clearRequestFilters">ล้างตัวกรอง</button></div>
      </div>
      <div class="table-wrap equipment-booking-table">
        <table><thead><tr><th>วันที่ส่งคำขอ</th><th>เครื่อง</th><th>ช่วงเวลาใช้งาน</th><th>โรงพยาบาล/วัตถุประสงค์</th><th>Sale ผู้ขอ</th><th>ผู้ประสานงาน</th><th>หมายเหตุ</th><th>ผลอนุมัติ</th><th>สถานะใช้งาน</th><th>จัดการ</th></tr></thead>
          <tbody>
            <tr v-for="booking in bookings" :key="booking.booking_id" :class="{ 'booking-row--pending': booking.approval_status === 'รออนุมัติ', 'booking-row--canceled': booking.status === 'ยกเลิก' }">
              <td data-label="ส่งคำขอ"><strong>{{ dateTime(booking.created_at) }}</strong><small>คำขอ #{{ booking.booking_id }}</small></td>
              <td data-label="เครื่อง"><strong>{{ booking.machine.machine_code }}</strong><small>{{ booking.machine.machine_name }}</small></td>
              <td data-label="ช่วงเวลา">{{ dateTime(booking.reserved_from) }}<small>ถึง {{ dateTime(booking.reserved_until) }}</small></td>
              <td data-label="โรงพยาบาล"><strong>{{ booking.hospital_name }}</strong><small>{{ booking.purpose }}</small></td>
              <td data-label="Sale ผู้ขอ">{{ booking.owner?.username || booking.booked_by }}<small>{{ booking.owner?.department || booking.booked_by_department || '-' }}</small></td>
              <td data-label="ผู้ประสานงาน">{{ booking.contact_name || '-' }}<small>{{ booking.contact_phone || '-' }}</small></td>
              <td class="equipment-note" data-label="หมายเหตุ">{{ booking.note || '-' }}</td>
              <td data-label="ผลอนุมัติ"><button type="button" class="approval-pill approval-pill--button" :class="`approval-${booking.approval_status}`" @click="openBooking(booking)">{{ booking.approval_status }}</button><small v-if="booking.review_note">{{ booking.review_note }}</small></td>
              <td data-label="สถานะใช้งาน"><StatusPill v-if="booking.approval_status === 'อนุมัติแล้ว'" :status="booking.status" /><span v-else class="muted">ยังไม่ลงคิว</span></td>
              <td data-label="จัดการ"><div class="booking-actions"><button class="secondary-button" @click="openBooking(booking)">ดูรายละเอียด</button><button v-if="canChangeBooking(booking)" class="secondary-button" @click="editBooking(booking)">แก้ไข</button><template v-if="canReviewBookings && booking.approval_status === 'รออนุมัติ'"><button class="primary-button" @click="openBooking(booking); reviewBooking(booking, 'อนุมัติแล้ว')">อนุมัติ</button><button class="danger-button" @click="openBooking(booking); reviewBooking(booking, 'ไม่อนุมัติ')">ไม่อนุมัติ</button></template><select v-if="canChangeOperationalStatus(booking)" :value="booking.status" @change="changeBookingStatus(booking, ($event.target as HTMLSelectElement).value as FibroscanBookingStatus)"><option v-for="status in bookingStatuses" :key="status">{{ status }}</option></select></div></td>
            </tr>
            <tr v-if="!bookings.length"><td colspan="10" class="empty-state">{{ loading ? 'กำลังโหลดข้อมูล…' : 'ไม่พบคำขอหรือรายการจองตามตัวกรอง' }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AppShell>
</template>
