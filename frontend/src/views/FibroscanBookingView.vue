<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import { auth } from '../stores/auth'
import type { FibroscanBooking, FibroscanBookingStatus, FibroscanMachine, FibroscanMachineStatus } from '../types/api'
import { apiError } from '../utils/format'

const bookings = ref<FibroscanBooking[]>([])
const calendarBookings = ref<FibroscanBooking[]>([])
const machines = ref<FibroscanMachine[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const editingId = ref<number | null>(null)
const selectedBooking = ref<FibroscanBooking | null>(null)
const selectedBookingStatus = ref<FibroscanBookingStatus>('จองแล้ว')
const availabilityConflicts = ref<Array<Pick<FibroscanBooking, 'booking_id' | 'machine_id' | 'hospital_name' | 'reserved_from' | 'reserved_until'>>>([])
let availabilityTimer: ReturnType<typeof setTimeout> | null = null
let availabilityRequest = 0
const canManageMachines = computed(() => auth.can('system_admin'))
const purposes = ['ทดลองใช้', 'Event', 'เช่า', 'สาธิต', 'อื่นๆ'] as const
const bookingStatuses: FibroscanBookingStatus[] = ['จองแล้ว', 'กำลังใช้งาน', 'คืนแล้ว', 'ยกเลิก']
const machineStatuses: FibroscanMachineStatus[] = ['พร้อมใช้งาน', 'ซ่อมบำรุง', 'เลิกใช้งาน']

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
const filters = reactive({ date_from: bounds.from, date_to: bounds.to, machine_id: '', status: '' })
const bookingForm = reactive({
  machine_id: '', hospital_name: '', purpose: 'เช่า' as FibroscanBooking['purpose'],
  reserved_from: localDateTime(new Date()),
  reserved_until: localDateTime(new Date(Date.now() + 24 * 60 * 60 * 1000)),
  contact_name: '', contact_phone: '', note: '',
})
const machineForm = reactive({ machine_code: '', machine_name: '', serial_number: '', note: '' })

const selectedFrom = computed(() => new Date(bookingForm.reserved_from).getTime())
const selectedUntil = computed(() => new Date(bookingForm.reserved_until).getTime())
const machineAvailability = computed(() => machines.value.map((machine) => {
  const conflict = availabilityConflicts.value.find((booking) => booking.machine_id === machine.machine_id)
  return { machine, conflict, available: machine.status === 'พร้อมใช้งาน' && !conflict }
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
    const dayBookings = calendarBookings.value.filter((booking) =>
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
  const segments = calendarBookings.value
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
  loadCalendar()
}

function goCurrentMonth() {
  calendarMonth.value = localIso(new Date()).slice(0, 7)
  loadCalendar()
}

function openBooking(booking: FibroscanBooking) {
  selectedBooking.value = booking
  selectedBookingStatus.value = booking.status
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
    success.value = editingId.value ? 'แก้ไขรายการจองแล้ว' : 'จองเครื่องเรียบร้อยแล้ว Sale ทุกคนสามารถเห็นรายการนี้ได้'
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

async function addMachine() {
  error.value = ''; success.value = ''
  try {
    await api.post('/api/v1/fibroscan-machines', machineForm)
    Object.assign(machineForm, { machine_code: '', machine_name: '', serial_number: '', note: '' })
    success.value = 'เพิ่มเครื่องเข้าทะเบียนแล้ว'
    await load()
  } catch (cause) { error.value = apiError(cause, 'เพิ่มเครื่องไม่สำเร็จ') }
}

async function changeMachineStatus(machine: FibroscanMachine, status: FibroscanMachineStatus) {
  try {
    await api.put(`/api/v1/fibroscan-machines/${machine.machine_id}`, {
      machine_code: machine.machine_code, machine_name: machine.machine_name,
      serial_number: machine.serial_number, note: machine.note, status,
    })
    await load()
  } catch (cause) { error.value = apiError(cause, 'เปลี่ยนสถานะเครื่องไม่สำเร็จ') }
}

watch([() => bookingForm.reserved_from, () => bookingForm.reserved_until, editingId], scheduleAvailabilityCheck, { immediate: true })
watch(selectedBooking, (booking) => { if (booking) selectedBookingStatus.value = booking.status })
onMounted(() => { load(); loadCalendar() })
onBeforeUnmount(() => { if (availabilityTimer) clearTimeout(availabilityTimer) })
</script>

<template>
  <AppShell title="ตารางจองเครื่อง FibroScan" subtitle="Sale ทุกคนเห็นคิวร่วมกัน จองเครื่องที่ว่างได้ทันทีโดยไม่ต้องส่งคำขออนุมัติ">
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
              <article v-for="day in week.days" :key="day.iso" class="equipment-calendar-day" :class="{ muted: !day.current, today: day.today }">
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

      <aside class="content-card equipment-booking-detail">
        <template v-if="selectedBooking">
          <div class="section-heading"><div><p class="eyebrow eyebrow--red">Booking detail</p><h2>{{ selectedBooking.machine.machine_code }}</h2></div><StatusPill :status="selectedBooking.status" /></div>
          <dl class="equipment-booking-detail__grid">
            <div><dt>เครื่อง</dt><dd>{{ selectedBooking.machine.machine_name }}</dd></div>
            <div><dt>โรงพยาบาล</dt><dd>{{ selectedBooking.hospital_name }}</dd></div>
            <div><dt>ลักษณะงาน</dt><dd>{{ selectedBooking.purpose }}</dd></div>
            <div><dt>ช่วงใช้งาน</dt><dd>{{ dateTime(selectedBooking.reserved_from) }}<br>ถึง {{ dateTime(selectedBooking.reserved_until) }}</dd></div>
            <div><dt>Sale ผู้จอง</dt><dd>{{ selectedBooking.owner?.username || selectedBooking.booked_by }}<br><small>{{ selectedBooking.owner?.department || selectedBooking.booked_by_department || '-' }}</small></dd></div>
            <div><dt>ผู้ประสานงานลูกค้า</dt><dd>{{ selectedBooking.contact_name || '-' }}<br><small>{{ selectedBooking.contact_phone || '-' }}</small></dd></div>
            <div class="equipment-booking-detail__wide"><dt>รายละเอียดงาน/หมายเหตุ</dt><dd>{{ selectedBooking.note || '-' }}</dd></div>
          </dl>
          <div v-if="selectedBooking.can_edit && !['คืนแล้ว','ยกเลิก'].includes(selectedBooking.status)" class="equipment-detail-actions">
            <label><span>อัปเดตสถานะงาน</span><select v-model="selectedBookingStatus"><option v-for="status in bookingStatuses" :key="status">{{ status }}</option></select></label>
            <button type="button" class="primary-button" :disabled="selectedBookingStatus === selectedBooking.status" @click="changeBookingStatus(selectedBooking, selectedBookingStatus)">บันทึกสถานะ</button>
            <button type="button" class="secondary-button" @click="editBooking(selectedBooking)">แก้ไขรายละเอียดงาน</button>
          </div>
          <p v-else-if="selectedBooking.can_edit" class="muted">รายการนี้สิ้นสุดแล้วและเก็บไว้เป็นประวัติ</p>
          <p v-else class="muted">คุณสามารถดูรายละเอียดได้ แต่แก้ไขได้เฉพาะผู้จองหรือผู้ดูแลเท่านั้น</p>
        </template>
        <div v-else class="equipment-detail-empty"><span>🩺</span><strong>เลือกรายการจากปฏิทิน</strong><p>กดที่เครื่องในแต่ละวันเพื่อดูผู้จอง ลูกค้า ลักษณะงาน และสถานะ</p></div>
      </aside>
    </div>

    <form class="content-card form-grid" @submit.prevent="saveBooking">
      <div class="span-2 section-heading">
        <div><p class="eyebrow eyebrow--red">Shared booking</p><h2>{{ editingId ? 'แก้ไขรายการจอง' : 'เพิ่มรายการจองเครื่อง' }}</h2></div>
        <button v-if="editingId" type="button" class="secondary-button" @click="resetBookingForm">ยกเลิกการแก้ไข</button>
      </div>
      <label><span>เครื่อง *</span><select v-model="bookingForm.machine_id" required><option value="" disabled>เลือกเครื่อง</option><option v-for="machine in machines.filter((item) => item.status === 'พร้อมใช้งาน' || item.machine_id === Number(bookingForm.machine_id))" :key="machine.machine_id" :value="String(machine.machine_id)">{{ machine.machine_code }} · {{ machine.machine_name }}</option></select></label>
      <label><span>โรงพยาบาล *</span><input v-model.trim="bookingForm.hospital_name" required maxlength="500" placeholder="ชื่อโรงพยาบาล"></label>
      <label><span>เริ่มใช้/นำเครื่องออก *</span><input v-model="bookingForm.reserved_from" type="datetime-local" required></label>
      <label><span>สิ้นสุด/เครื่องกลับถึงบริษัท *</span><input v-model="bookingForm.reserved_until" type="datetime-local" required></label>
      <label><span>วัตถุประสงค์ *</span><select v-model="bookingForm.purpose"><option v-for="purpose in purposes" :key="purpose">{{ purpose }}</option></select></label>
      <label><span>ผู้ประสานงานโรงพยาบาล</span><input v-model.trim="bookingForm.contact_name" maxlength="255"></label>
      <label><span>เบอร์โทรศัพท์</span><input v-model.trim="bookingForm.contact_phone" maxlength="100"></label>
      <label class="span-2"><span>หมายเหตุ</span><textarea v-model.trim="bookingForm.note" rows="3" maxlength="5000" placeholder="รายละเอียด Event, สถานที่จัดงาน หรือข้อมูลที่ Sale คนอื่นควรรู้"></textarea></label>

      <div class="span-2 equipment-availability">
        <strong>ตรวจสอบเครื่องในช่วงเวลาที่เลือก</strong>
        <div class="equipment-availability__grid">
          <article v-for="item in machineAvailability" :key="item.machine.machine_id" :class="{ 'is-unavailable': !item.available }">
            <b>{{ item.machine.machine_code }}</b><span>{{ item.machine.machine_name }}</span>
            <small>{{ item.available ? 'ว่าง — สามารถจองได้' : item.machine.status !== 'พร้อมใช้งาน' ? item.machine.status : `ไม่ว่าง · ${item.conflict?.hospital_name}` }}</small>
          </article>
        </div>
      </div>
      <button class="primary-button span-2" :disabled="saving || !machines.length">{{ saving ? 'กำลังบันทึก…' : editingId ? 'บันทึกการแก้ไข' : 'จองเครื่อง' }}</button>
    </form>

    <section class="content-card section-gap">
      <div class="section-heading"><div><p class="eyebrow">Booking schedule</p><h2>ตารางการใช้งานเครื่อง</h2></div><button class="secondary-button" :disabled="loading" @click="load">{{ loading ? 'กำลังโหลด…' : 'โหลดข้อมูลใหม่' }}</button></div>
      <div class="equipment-filters">
        <label><span>ตั้งแต่</span><input v-model="filters.date_from" type="date"></label>
        <label><span>ถึง</span><input v-model="filters.date_to" type="date"></label>
        <label><span>เครื่อง</span><select v-model="filters.machine_id"><option value="">ทุกเครื่อง</option><option v-for="machine in machines" :key="machine.machine_id" :value="String(machine.machine_id)">{{ machine.machine_code }}</option></select></label>
        <label><span>สถานะ</span><select v-model="filters.status"><option value="">ทุกสถานะ</option><option v-for="status in bookingStatuses" :key="status">{{ status }}</option></select></label>
        <button class="secondary-button" @click="load">ค้นหา</button>
      </div>
      <div class="table-wrap equipment-booking-table">
        <table><thead><tr><th>เครื่อง</th><th>ช่วงเวลา</th><th>โรงพยาบาล/วัตถุประสงค์</th><th>Sale ผู้จอง</th><th>ผู้ประสานงาน</th><th>หมายเหตุ</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
          <tbody>
            <tr v-for="booking in bookings" :key="booking.booking_id" :class="{ 'booking-row--canceled': booking.status === 'ยกเลิก' }">
              <td><strong>{{ booking.machine.machine_code }}</strong><small>{{ booking.machine.machine_name }}</small></td>
              <td>{{ dateTime(booking.reserved_from) }}<small>ถึง {{ dateTime(booking.reserved_until) }}</small></td>
              <td><strong>{{ booking.hospital_name }}</strong><small>{{ booking.purpose }}</small></td>
              <td>{{ booking.owner?.username || booking.booked_by }}<small>{{ booking.owner?.department || booking.booked_by_department || '-' }}</small></td>
              <td>{{ booking.contact_name || '-' }}<small>{{ booking.contact_phone || '-' }}</small></td>
              <td class="equipment-note">{{ booking.note || '-' }}</td>
              <td><StatusPill :status="booking.status" /></td>
              <td><div v-if="booking.can_edit" class="booking-actions"><button v-if="!['คืนแล้ว','ยกเลิก'].includes(booking.status)" class="secondary-button" @click="editBooking(booking)">แก้ไข</button><select v-if="!['คืนแล้ว','ยกเลิก'].includes(booking.status)" :value="booking.status" @change="changeBookingStatus(booking, ($event.target as HTMLSelectElement).value as FibroscanBookingStatus)"><option v-for="status in bookingStatuses" :key="status">{{ status }}</option></select></div><span v-else class="muted">ดูอย่างเดียว</span></td>
            </tr>
            <tr v-if="!bookings.length"><td colspan="8" class="empty-state">{{ loading ? 'กำลังโหลดข้อมูล…' : 'ไม่พบรายการจองในช่วงที่เลือก' }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="canManageMachines" class="content-card section-gap">
      <div class="section-heading"><div><p class="eyebrow eyebrow--red">Equipment registry</p><h2>ทะเบียนเครื่อง FibroScan</h2></div><small class="muted">เครื่องที่ขายออกให้เปลี่ยนเป็น “เลิกใช้งาน” เพื่อเก็บประวัติเดิม</small></div>
      <form class="equipment-machine-form" @submit.prevent="addMachine">
        <label><span>รหัสเครื่อง *</span><input v-model.trim="machineForm.machine_code" required maxlength="50" placeholder="เช่น FIBRO-01"></label>
        <label><span>ชื่อเครื่อง *</span><input v-model.trim="machineForm.machine_name" required maxlength="255" placeholder="เช่น FibroScan Compact 530"></label>
        <label><span>Serial Number</span><input v-model.trim="machineForm.serial_number" maxlength="255"></label>
        <label><span>หมายเหตุ</span><input v-model.trim="machineForm.note" maxlength="5000"></label>
        <button class="primary-button">เพิ่มเครื่อง</button>
      </form>
      <div class="equipment-machine-list">
        <article v-for="machine in machines" :key="machine.machine_id"><div><strong>{{ machine.machine_code }} · {{ machine.machine_name }}</strong><small>Serial: {{ machine.serial_number || '-' }} · {{ machine.note || 'ไม่มีหมายเหตุ' }}</small></div><select :value="machine.status" @change="changeMachineStatus(machine, ($event.target as HTMLSelectElement).value as FibroscanMachineStatus)"><option v-for="status in machineStatuses" :key="status">{{ status }}</option></select></article>
        <p v-if="!machines.length" class="empty-state">ยังไม่มีเครื่องในทะเบียน กรุณาเพิ่มเครื่องก่อนเริ่มจอง</p>
      </div>
    </section>
  </AppShell>
</template>
