<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import { auth } from '../stores/auth'
import type { SalesTask, User } from '../types/api'
import { apiError, shortDate, todayIso } from '../utils/format'

const tasks = ref<SalesTask[]>([])
const sales = ref<User[]>([])
const selected = ref<SalesTask | null>(null)
const error = ref('')
const canManage = computed(() => auth.can('approve_bills') || auth.can('system_admin'))
const month = ref(todayIso().slice(0, 7))
const calendarMode = ref<'month' | 'week' | 'day' | 'list'>('month')
const selectedDate = ref(todayIso())
const form = reactive({ title: '', description: '', assigned_to: [] as string[], start_date: todayIso(), end_date: todayIso(), start_time: '09:00', end_time: '12:00', all_day: true, priority: 'ปกติ' })
const monthTasks = computed(() => {
  const [year, value] = month.value.split('-').map(Number)
  const first = `${month.value}-01`
  const last = localIso(new Date(year, value, 0))
  return tasks.value.filter((task) => task.start_date <= last && task.end_date >= first)
})
const todayTasks = computed(() => tasks.value.filter((task) => task.start_date <= todayIso() && task.end_date >= todayIso()))
const statusLegend = [{ label: 'รอดำเนินการ', color: '#f2b705' }, { label: 'กำลังทำ', color: '#2f80ed' }, { label: 'สำเร็จ', color: '#2eaf5f' }, { label: 'ยกเลิก', color: '#c94a4a' }]
function localIso(date: Date) { return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}` }
const calendarDays = computed(() => { const [year, mon] = month.value.split('-').map(Number); const first = new Date(year, mon-1, 1); const offset = (first.getDay()+6)%7; const start = new Date(year, mon-1, 1-offset); return Array.from({length:42},(_,index)=>{ const date = new Date(start); date.setDate(start.getDate()+index); const iso=localIso(date); return { iso, day: date.getDate(), current: date.getMonth()===mon-1, today: iso===todayIso(), tasks: tasks.value.filter((task)=>task.start_date<=iso&&task.end_date>=iso) } }) })
const selectedDateTasks = computed(() => tasks.value.filter((task) => task.start_date <= selectedDate.value && task.end_date >= selectedDate.value))
const weekDays = computed(() => { const current = new Date(`${selectedDate.value}T00:00:00`); const offset=(current.getDay()+6)%7; current.setDate(current.getDate()-offset); return Array.from({length:7},(_,index)=>{const date=new Date(current);date.setDate(current.getDate()+index);const iso=localIso(date);return {iso,day:date.getDate(),tasks:tasks.value.filter((task)=>task.start_date<=iso&&task.end_date>=iso)}}) })
function selectDate(iso: string) { selectedDate.value=iso; form.start_date=iso; form.end_date=iso }
function shiftMonth(delta: number) { const [year,mon]=month.value.split('-').map(Number); const date=new Date(year,mon-1+delta,1); month.value=localIso(date).slice(0,7); selectedDate.value=`${month.value}-01` }
function goToday() { month.value=todayIso().slice(0,7); selectedDate.value=todayIso() }

async function load() {
  try {
    const response = await api.get<{ data: { tasks: SalesTask[]; sales_team: User[] } }>('/api/v1/tasks')
    tasks.value = response.data.data.tasks; sales.value = response.data.data.sales_team
  } catch (cause) { error.value = apiError(cause, 'โหลดตารางงานไม่สำเร็จ') }
}
async function createTask() {
  try { await api.post('/api/v1/tasks', form); Object.assign(form, { title: '', description: '', assigned_to: [], start_date: todayIso(), end_date: todayIso(), start_time: '09:00', end_time: '12:00', all_day: true, priority: 'ปกติ' }); await load() }
  catch (cause) { error.value = apiError(cause) }
}
async function updateStatus(task: SalesTask, status: SalesTask['status']) {
  try { await api.patch(`/api/v1/tasks/${task.task_id}/status`, { status }); await load(); selected.value = tasks.value.find((item) => item.task_id === task.task_id) || null }
  catch (cause) { error.value = apiError(cause) }
}
async function removeTask(task: SalesTask) {
  if (!confirm(`ลบงาน “${task.title}” หรือไม่?`)) return
  try { await api.delete(`/api/v1/tasks/${task.task_id}`); selected.value = null; await load() }
  catch (cause) { error.value = apiError(cause) }
}
onMounted(load)
</script>

<template>
  <AppShell title="ปฏิทินตารางงานทีมขาย" subtitle="ดูงานตามวัน มอบหมายงาน และอัปเดตสถานะจากปฏิทินเดียว">
    <p v-if="error" class="form-error">{{ error }}</p>
    <div class="status-legend"><span v-for="item in statusLegend" :key="item.label"><i :style="{background:item.color}"></i>{{ item.label }}</span></div>
    <div class="calendar-layout">
      <section class="content-card">
        <div class="calendar-toolbar"><div class="actions"><button class="secondary-button" @click="shiftMonth(-1)">‹</button><button class="secondary-button" @click="shiftMonth(1)">›</button><button class="secondary-button" @click="goToday">วันนี้</button></div><input v-model="month" type="month"><div class="calendar-modes"><button v-for="mode in ['month','week','day','list']" :key="mode" :class="{active:calendarMode===mode}" @click="calendarMode=mode as typeof calendarMode">{{ {month:'เดือน',week:'สัปดาห์',day:'วัน',list:'รายการ'}[mode] }}</button></div></div>
        <template v-if="calendarMode==='month'"><div class="calendar-weekdays"><span v-for="name in ['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์','อาทิตย์']" :key="name">{{ name }}</span></div><div class="month-grid"><button v-for="day in calendarDays" :key="day.iso" class="calendar-day" :class="{muted:!day.current,today:day.today,selected:day.iso===selectedDate}" @click="selectDate(day.iso)"><b>{{ day.day }}</b><span v-for="task in day.tasks.slice(0,3)" :key="task.task_id" :class="`calendar-event event--${task.status}`" @click.stop="selected=task;selectDate(day.iso)">{{ task.priority!=='ปกติ'?'!':'' }}{{ task.assigned_to }} - {{ task.title }}</span><small v-if="day.tasks.length>3">+{{ day.tasks.length-3 }} งาน</small></button></div></template>
        <template v-else-if="calendarMode==='week'"><div class="week-grid"><section v-for="day in weekDays" :key="day.iso" :class="{today:day.iso===todayIso()}" @click="selectDate(day.iso)"><header>{{ shortDate(day.iso) }}</header><button v-for="task in day.tasks" :key="task.task_id" :class="`calendar-event event--${task.status}`" @click.stop="selected=task">{{ task.title }}<small>{{ task.assigned_to }}</small></button></section></div></template>
        <div v-else class="calendar-list"><div class="section-heading"><h2>{{ calendarMode==='day' ? `งานวันที่ ${shortDate(selectedDate)}` : 'รายการงานประจำเดือน' }}</h2><input v-if="calendarMode==='day'" v-model="selectedDate" type="date"></div><button v-for="task in (calendarMode==='day'?selectedDateTasks:monthTasks)" :key="task.task_id" class="task-card" :class="`task-card--${task.status}`" @click="selected=task"><span>{{ shortDate(task.start_date) }}{{task.end_date!==task.start_date?` – ${shortDate(task.end_date)}`:''}}</span><strong>{{task.title}}</strong><small>{{task.assigned_to}} · {{task.priority}} · {{task.all_day?'ทั้งวัน':`${task.start_time?.slice(0,5)}–${task.end_time?.slice(0,5)}`}}</small></button><p v-if="!(calendarMode==='day'?selectedDateTasks:monthTasks).length" class="empty-state">ไม่มีงานในช่วงนี้</p></div>
      </section>
      <aside class="content-card calendar-aside">
        <h2>งานวันนี้</h2><article v-for="task in todayTasks" :key="task.task_id" class="mini-task"><strong>{{ task.title }}</strong><small>{{ task.assigned_to }}</small><StatusPill :status="task.status" /></article>
        <hr><template v-if="selected"><div class="section-heading"><h2>{{ selected.title }}</h2><StatusPill :status="selected.status" /></div><p>{{ selected.description || '-' }}</p><label><span>อัปเดตสถานะ</span><select :value="selected.status" @change="updateStatus(selected, ($event.target as HTMLSelectElement).value as SalesTask['status'])"><option>รอดำเนินการ</option><option>กำลังทำ</option><option>สำเร็จ</option><option>ยกเลิก</option></select></label><button v-if="canManage" class="danger-button" @click="removeTask(selected)">ลบงานนี้</button></template><p v-else class="muted">เลือกงานเพื่อดูรายละเอียด</p>
      </aside>
    </div>
    <form v-if="canManage" class="content-card form-grid section-gap" @submit.prevent="createTask">
      <div class="span-2"><p class="eyebrow eyebrow--red">Assign task</p><h2>มอบหมายงานลงปฏิทิน</h2></div>
      <label><span>หัวข้องาน *</span><input v-model="form.title" required></label>
      <label><span>ความสำคัญ</span><select v-model="form.priority"><option>ปกติ</option><option>ด่วน</option><option>สำคัญมาก</option></select></label>
      <label class="span-2"><span>รายละเอียด</span><textarea v-model="form.description" rows="3"></textarea></label>
      <label class="span-2"><span>มอบหมายให้ *</span><select v-model="form.assigned_to" multiple required><option v-for="person in sales" :key="person.username" :value="person.username">{{ person.username }} · {{ person.department || '-' }}</option></select></label>
      <label><span>วันที่เริ่ม</span><input v-model="form.start_date" type="date" required></label><label><span>วันที่สิ้นสุด</span><input v-model="form.end_date" type="date" required></label>
      <label class="check-field"><input v-model="form.all_day" type="checkbox"> ทั้งวัน</label><div></div>
      <label v-if="!form.all_day"><span>เวลาเริ่ม</span><input v-model="form.start_time" type="time"></label><label v-if="!form.all_day"><span>เวลาสิ้นสุด</span><input v-model="form.end_time" type="time"></label>
      <button class="primary-button span-2">เพิ่มลงปฏิทิน</button>
    </form>
  </AppShell>
</template>
