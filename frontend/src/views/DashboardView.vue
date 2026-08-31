<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { DashboardData } from '../types/api'
import { apiError, money, shortDate, todayIso } from '../utils/format'

const data = ref<DashboardData | null>(null)
const selectedSales = ref<string[]>([])
const selectedStatuses = ref<string[]>([])
const selectedDepartments = ref<string[]>([])
const dateFrom = ref('')
const dateTo = ref('')
const reportScope = ref<'all' | 'successful'>('all')
const loading = ref(true)
const exporting = ref(false)
const error = ref('')
const responseMs = ref(0)

const maxPerson = computed(() => Math.max(...(data.value?.sales_by_person.map((row) => Number(row.total)) || [1]), 1))
const maxDaily = computed(() => Math.max(...(data.value?.daily_sales.map((row) => Number(row.total)) || [1]), 1))
const maxDepartment = computed(() => Math.max(...(data.value?.sales_by_department.map((row) => Number(row.total)) || [1]), 1))
const filterPayload = computed(() => ({
  sales: selectedSales.value,
  statuses: selectedStatuses.value,
  departments: selectedDepartments.value,
  date_from: dateFrom.value || undefined,
  date_to: dateTo.value || undefined,
}))

async function loadDashboard() {
  loading.value = true
  error.value = ''
  const started = performance.now()
  try {
    const response = await api.get<{ data: DashboardData }>('/api/v1/dashboard', { params: filterPayload.value })
    data.value = response.data.data
    responseMs.value = Math.round(performance.now() - started)
  } catch (cause) {
    error.value = apiError(cause, 'โหลดข้อมูลภาพรวมไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  selectedSales.value = []
  selectedStatuses.value = []
  selectedDepartments.value = []
  dateFrom.value = ''
  dateTo.value = ''
  loadDashboard()
}

async function exportReport() {
  exporting.value = true
  error.value = ''
  try {
    await downloadFile(
      '/api/v1/dashboard/report',
      `Sales_Report_${dateFrom.value || 'ALL'}_to_${dateTo.value || todayIso()}.xlsx`,
      'post',
      { ...filterPayload.value, scope: reportScope.value },
    )
  } catch (cause) {
    error.value = apiError(cause, 'ส่งออก Sales Report ไม่สำเร็จ')
  } finally {
    exporting.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <AppShell title="แดชบอร์ดสถิติภาพรวม" subtitle="วิเคราะห์ยอดขาย ลูกค้า สินค้า ส่วนลด และผลงานของ Sale">
    <section v-if="data" class="content-card filter-panel">
      <div class="section-heading">
        <div>
          <p class="eyebrow eyebrow--red">Filters & Report</p>
          <h2>ตัวกรองข้อมูลและส่งออก Excel</h2>
        </div>
        <button class="secondary-button" @click="loadDashboard">รีเฟรชข้อมูลล่าสุด</button>
      </div>

      <div class="form-grid">
        <label>
          <span>ตั้งแต่วันที่</span>
          <input v-model="dateFrom" type="date">
        </label>
        <label>
          <span>ถึงวันที่</span>
          <input v-model="dateTo" type="date" :min="dateFrom || undefined">
        </label>
        <label>
          <span>พนักงานขาย</span>
          <select v-model="selectedSales" multiple>
            <option v-for="sale in data.filters.sales" :key="sale">{{ sale }}</option>
          </select>
        </label>
        <label>
          <span>สถานะบิล</span>
          <select v-model="selectedStatuses" multiple>
            <option v-for="status in data.filters.statuses" :key="status">{{ status }}</option>
          </select>
        </label>
        <label>
          <span>แผนก</span>
          <select v-model="selectedDepartments" multiple>
            <option v-for="department in data.filters.departments" :key="department">{{ department }}</option>
          </select>
        </label>
        <label>
          <span>ข้อมูลใน Excel</span>
          <select v-model="reportScope">
            <option value="all">ทุกสถานะและประวัติทั้งหมด</option>
            <option value="successful">เฉพาะยอดขายที่ผ่าน Workflow</option>
          </select>
        </label>
        <div class="actions dashboard-report-actions">
          <button class="primary-button" @click="loadDashboard">ใช้ตัวกรอง</button>
          <button class="secondary-button" @click="clearFilters">ล้างตัวกรอง</button>
          <button class="primary-button" :disabled="exporting" @click="exportReport">
            {{ exporting ? 'กำลังสร้าง Report…' : 'Export Sales Report (.xlsx)' }}
          </button>
        </div>
      </div>
      <p class="muted section-gap-sm">
        Excel ประกอบด้วยภาพรวม ประวัติบิล รายละเอียดสินค้า Sale × ลูกค้า ลูกค้า × สินค้า สินค้า และรายเดือน
      </p>
    </section>

    <div v-if="loading" class="loading-state">กำลังโหลดข้อมูล…</div>
    <div v-else-if="error" class="error-state">
      {{ error }}
      <button @click="loadDashboard">ลองอีกครั้ง</button>
    </div>

    <template v-else-if="data">
      <div class="metrics-grid metrics-grid--three section-gap">
        <article class="metric-card metric-card--primary">
          <span>มูลค่าบิลสุทธิทั้งหมดตามตัวกรอง</span>
          <strong class="metric-money">{{ money(data.summary.total_revenue) }}</strong>
          <small>ยอดที่ผ่าน Workflow {{ money(data.summary.recognized_revenue) }} จาก {{ data.summary.recognized_bills }} บิล</small>
        </article>
        <article class="metric-card">
          <span>มูลค่าก่อนส่วนลดของทุกบิล</span>
          <strong class="metric-money">{{ money(data.summary.gross_sales) }}</strong>
          <small>ส่วนลด Sale {{ money(data.summary.total_discount) }} · ส่วนลด Admin/Bplus {{ money(data.summary.bplus_admin_discount) }}</small>
        </article>
        <article class="metric-card">
          <span>จำนวนสินค้ารวมในทุกบิล</span>
          <strong>{{ data.summary.items_sold.toLocaleString() }}</strong>
          <small>{{ data.summary.active_customers }} ลูกค้า</small>
        </article>
        <article class="metric-card">
          <span>จำนวนบิลตามตัวกรอง</span>
          <strong>{{ data.summary.total_bills }}</strong>
          <small>รวมทุกสถานะ</small>
        </article>
        <article class="metric-card">
          <span>บิลรอผู้มีอำนาจอนุมัติ</span>
          <strong>{{ data.summary.pending_approval }}</strong>
          <small>รอหัวหน้า {{ data.summary.waiting_manager }} · รอแก้ไข {{ data.summary.needs_revision }}</small>
        </article>
        <article class="metric-card">
          <span>ค่าเฉลี่ยต่อบิลทั้งหมด</span>
          <strong class="metric-money">{{ money(data.summary.total_bills ? data.summary.total_revenue / data.summary.total_bills : 0) }}</strong>
          <small>รอแอดมินส่งออก {{ data.summary.waiting_export }} · พร้อมส่งออก {{ data.summary.ready_export }} · เสร็จสิ้น {{ data.summary.completed_bills }}</small>
        </article>
      </div>

      <div class="chart-grid">
        <section class="content-card">
          <div class="section-heading"><h2>สัดส่วนสถานะบิลทั้งหมด</h2></div>
          <div class="bar-list">
            <div v-for="row in data.status_breakdown" :key="row.status" class="bar-row">
              <StatusPill :status="row.status" />
              <div><i :style="{ width: `${Math.max((Number(row.total) / Math.max(data.summary.total_bills, 1)) * 100, 2)}%` }"></i></div>
              <b>{{ row.total }}</b>
            </div>
            <p v-if="!data.status_breakdown.length" class="muted">ยังไม่มีข้อมูล</p>
          </div>
        </section>

        <section class="content-card">
          <div class="section-heading"><h2>มูลค่าบิลแยกตามพนักงาน</h2></div>
          <div class="bar-list">
            <div v-for="row in data.sales_by_person" :key="row.created_by" class="bar-row">
              <span>{{ row.created_by || '-' }}</span>
              <div><i class="bar--blue" :style="{ width: `${(Number(row.total) / maxPerson) * 100}%` }"></i></div>
              <b>{{ money(row.total) }}</b>
            </div>
            <p v-if="!data.sales_by_person.length" class="muted">ยังไม่มีข้อมูลบิลตามตัวกรอง</p>
          </div>
        </section>
      </div>

      <section class="content-card section-gap">
        <div class="section-heading">
          <div>
            <h2>มูลค่าบิลแยกตามแผนก</h2>
            <p class="muted">อ้างอิงแผนกปัจจุบันของ Sale ผู้สร้างบิล</p>
          </div>
        </div>
        <div class="trend-chart">
          <div v-for="row in data.sales_by_department" :key="row.department" class="trend-column">
            <!-- ย้ายตัวเลขยอดเงินมาไว้ด้านบนสุด -->
            <b>{{ money(row.total) }}</b>

            <!-- เปลี่ยนจาก width เป็น height และลบ <div> ที่ครอบอยู่ออก -->
            <!-- ใช้ maxDepartment ในการคำนวณความสูง (px) ให้สัมพันธ์กับ 150px เหมือนกราฟล่าง -->
            <i :style="{ height: `${Math.max((Number(row.total) / maxDepartment) * 150, 4)}px` }"></i>

            <!-- ชื่อแผนก (และจำนวนบิลถ้าต้องการ) ไว้ด้านล่างสุด -->
            <span>{{ row.department }} <br><small>({{ row.bills }} บิล)</small></span>
          </div>
          <p v-if="!data.sales_by_department.length" class="muted">ยังไม่มีข้อมูลแผนกตามตัวกรอง</p>
        </div>
      </section>

      <section class="content-card section-gap">
        <div class="section-heading">
          <div>
            <h2>แนวโน้มมูลค่าบิลรายวัน</h2>
            <p class="muted">รวมทุกสถานะตามตัวกรองเดียวกับรายการบิล</p>
          </div>
          <span class="response-time">API {{ responseMs }} ms</span>
        </div>
        <div class="trend-chart">
          <div v-for="row in data.daily_sales" :key="row.bill_date" class="trend-column">
            <b>{{ money(row.total) }}</b>
            <i :style="{ height: `${Math.max((Number(row.total) / maxDaily) * 150, 4)}px` }"></i>
            <span>{{ shortDate(row.bill_date) }}</span>
          </div>
          <p v-if="!data.daily_sales.length" class="muted">ยังไม่มีข้อมูลยอดขายรายวัน</p>
        </div>
      </section>

      <div class="chart-grid section-gap">
        <section class="content-card">
          <div class="section-heading"><h2>บริษัทที่ซื้อสูงสุด</h2></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>บริษัท/ลูกค้า</th><th>จำนวนบิล</th><th>ยอดซื้อสุทธิ</th></tr></thead>
              <tbody>
                <tr v-for="row in data.top_customers" :key="row.buyer_name">
                  <td>{{ row.buyer_name || '-' }}</td><td>{{ row.bills }}</td><td>{{ money(row.total) }}</td>
                </tr>
                <tr v-if="!data.top_customers.length"><td colspan="3" class="empty-cell">ยังไม่มีข้อมูล</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="content-card">
          <div class="section-heading"><h2>สินค้าที่สร้างยอดขายสูงสุด</h2></div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>SKU / สินค้า</th><th>จำนวนขาย</th><th>ส่วนลด</th><th>ยอดสุทธิ</th></tr></thead>
              <tbody>
                <tr v-for="row in data.top_products" :key="row.sku">
                  <td><strong>{{ row.sku || '-' }}</strong><br><small>{{ row.product_name || '-' }}</small></td>
                  <td>{{ Number(row.quantity).toLocaleString() }}</td>
                  <td>{{ money(row.discount_amount) }}</td>
                  <td>{{ money(row.net_amount) }}</td>
                </tr>
                <tr v-if="!data.top_products.length"><td colspan="4" class="empty-cell">ยังไม่มีข้อมูล</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section class="content-card section-gap">
        <div class="section-heading"><h2>รายการบิลล่าสุด</h2></div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>เลขอ้างอิง</th><th>วันที่</th><th>ลูกค้า</th><th>ผู้สร้าง</th><th>ยอดรวม</th><th>สถานะ</th></tr></thead>
            <tbody>
              <tr v-for="bill in data.recent_bills" :key="bill.bill_id">
                <td class="strong">#{{ bill.bill_id }}</td>
                <td>{{ shortDate(bill.bill_date) }}</td>
                <td>{{ bill.buyer_name }}</td>
                <td>{{ bill.created_by }}</td>
                <td>{{ money(bill.total_amount) }}</td>
                <td><StatusPill :status="bill.status" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </AppShell>
</template>
