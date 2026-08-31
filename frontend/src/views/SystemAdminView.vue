<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord, Paginated } from '../types/api'
import { apiError, money, shortDate } from '../utils/format'

const ready = ref<BillingRecord[]>([])
const records = ref<Paginated<BillingRecord> | null>(null)
const selectedId = ref<number | null>(null)
const selected = ref<BillingRecord | null>(null)
const tab = ref<'export' | 'history' | 'edit'>('export')
const search = ref('')
const error = ref('')
const message = ref('')
const saving = ref(false)
const statusSaving = ref(false)
const persistedStatus = ref('')
const selectedExportIds = ref<number[]>([])

const statuses = ['ฉบับร่าง', 'รออนุมัติ', 'รอหัวหน้าอนุมัติ', 'รอลูกค้ายืนยัน', 'รอแอดมินส่งออก', 'รอแก้ไข', 'อนุมัติแล้ว', 'ดำเนินการเสร็จสิ้น', 'ไม่อนุมัติ', 'ยกเลิกบิล']
const editableFields = [
  'bill_date', 'buyer_name', 'tax_id', 'address_note', 'province', 'district', 'sub_district',
  'zipcode', 'shipping_type', 'shipping_text', 'bill_note', 'sale_discount_total',
  'sale_discount_note', 'admin_branch', 'admin_dept', 'admin_project', 'admin_discount_final',
  'bplus_customer_code', 'bplus_salesman_code', 'warehouse_code', 'credit_limit',
  'credit_outstanding', 'credit_remain', 'credit_term', 'requested_credit_term', 'reject_reason',
  'customer_po_no', 'customer_confirmed_at', 'customer_contact_name', 'customer_contact_channel',
  'quotation_valid_until', 'payment_terms', 'delivery_due_date', 'delivery_terms',
] as const
const fieldLabels: Record<(typeof editableFields)[number], string> = {
  bill_date: 'วันที่บิล', buyer_name: 'ชื่อลูกค้า', tax_id: 'เลขผู้เสียภาษี',
  address_note: 'ที่อยู่', province: 'จังหวัด', district: 'อำเภอ/เขต', sub_district: 'ตำบล/แขวง',
  zipcode: 'รหัสไปรษณีย์', shipping_type: 'ประเภทการจัดส่ง', shipping_text: 'หมายเหตุจัดส่ง',
  bill_note: 'รายละเอียดบิล', sale_discount_total: 'ส่วนลดท้ายบิล Sale',
  sale_discount_note: 'หมายเหตุส่วนลด', admin_branch: 'รหัสสาขา', admin_dept: 'รหัสแผนก',
  admin_project: 'รหัสโครงการ', admin_discount_final: 'ส่วนลดท้ายบิล Admin สำหรับ Bplus',
  bplus_customer_code: 'รหัสลูกหนี้ Bplus', bplus_salesman_code: 'รหัสพนักงานขาย Bplus',
  warehouse_code: 'รหัสคลังสินค้า', credit_limit: 'วงเงินเครดิต', credit_outstanding: 'เครดิตค้างชำระ',
  credit_remain: 'เครดิตคงเหลือ', credit_term: 'Credit Term ระบบ',
  requested_credit_term: 'Credit Term ที่ขอ', reject_reason: 'เหตุผลตีกลับ/ไม่อนุมัติ',
  customer_po_no: 'เลขที่ PO / หนังสือสั่งซื้อ', customer_confirmed_at: 'วันที่ลูกค้ายืนยัน',
  customer_contact_name: 'ผู้ติดต่อ', customer_contact_channel: 'ช่องทางยืนยัน',
  quotation_valid_until: 'วันหมดอายุใบเสนอราคา', payment_terms: 'เงื่อนไขการชำระเงิน',
  delivery_due_date: 'กำหนดส่งสินค้า', delivery_terms: 'เงื่อนไขการส่งสินค้า',
}
const numericFields = new Set(['sale_discount_total', 'admin_discount_final', 'credit_limit', 'credit_outstanding', 'credit_remain', 'credit_term', 'requested_credit_term'])
const textAreaFields = new Set(['address_note', 'shipping_text', 'bill_note', 'sale_discount_note', 'reject_reason', 'payment_terms', 'delivery_terms'])
const dateFields = new Set(['bill_date', 'customer_confirmed_at', 'quotation_valid_until', 'delivery_due_date'])
const totalReady = computed(() => ready.value.reduce((sum, bill) => sum + Number(bill.total_amount || 0), 0))
const itemSubtotal = computed(() => selected.value?.items?.reduce((sum, item) => sum + Math.max(Number(item.quantity || 0) * Number(item.unit_price || 0) - Number(item.sale_discount_unit || 0), 0), 0) || 0)
const calculatedTotal = computed(() => Math.max(itemSubtotal.value - Number(selected.value?.sale_discount_total || 0), 0))
const allReadySelected = computed({
  get: () => ready.value.length > 0 && selectedExportIds.value.length === ready.value.length,
  set: (checked: boolean) => { selectedExportIds.value = checked ? ready.value.map((bill) => bill.bill_id) : [] },
})
const selectedReadyBills = computed(() => ready.value.filter((bill) => selectedExportIds.value.includes(bill.bill_id)))
const canExportQuotationPdf = computed(() => selectedReadyBills.value.length > 0 && selectedReadyBills.value.every((bill) => Boolean(bill.ref_qt_id)))

async function load(page = records.value?.current_page || 1) {
  error.value = ''
  try {
    const [readyResponse, allResponse] = await Promise.all([
      api.get<{ data: BillingRecord[] }>('/api/v1/bills/ready-export'),
      api.get<Paginated<BillingRecord>>('/api/v1/billing-records', { params: { page, per_page: 25, search: search.value || undefined } }),
    ])
    ready.value = readyResponse.data.data
    selectedExportIds.value = selectedExportIds.value.filter((id) => ready.value.some((bill) => bill.bill_id === id))
    records.value = allResponse.data
  } catch (cause) { error.value = apiError(cause) }
}

async function loadSelected() {
  if (!selectedId.value) { selected.value = null; persistedStatus.value = ''; return }
  error.value = ''
  try {
    const response = await api.get<{ data: BillingRecord }>(`/api/v1/bills/${selectedId.value}`)
    selected.value = response.data.data
    persistedStatus.value = selected.value.status || ''
  } catch (cause) { selected.value = null; persistedStatus.value = ''; error.value = apiError(cause) }
}

async function exportBills() {
  if (!selectedExportIds.value.length) return
  try {
    await downloadFile('/api/v1/bills/export', `Bplus_Import_${new Date().toISOString().slice(0, 10)}.xlsx`, 'post', { bill_ids: selectedExportIds.value })
    selectedExportIds.value = []
    await load()
  } catch (cause) { error.value = apiError(cause) }
}

async function exportQuotationsPdf() {
  if (!canExportQuotationPdf.value) {
    error.value = 'PDF ใบเสนอราคาใช้ได้เฉพาะรายการที่สร้างจากใบเสนอราคา กรุณานำบิลแบบ PO โดยตรงออกจากรายการที่เลือก'
    return
  }
  try {
    await downloadFile('/api/v1/bills/export-quotations.pdf', `Quotations_${new Date().toISOString().slice(0, 10)}.pdf`, 'post', { bill_ids: selectedExportIds.value })
  } catch (cause) { error.value = apiError(cause) }
}

async function complete(id: number) {
  try { await api.post(`/api/v1/bills/${id}/complete`); message.value = `ปิดงานบิล #${id} แล้ว`; await load() }
  catch (cause) { error.value = apiError(cause) }
}

async function completeAll() {
  if (!confirm('ยืนยันปิดงานบิลพร้อมส่งออกทั้งหมด?')) return
  try { await api.post('/api/v1/bills/complete-all'); message.value = 'ปิดงานบิลทั้งหมดแล้ว'; await load() }
  catch (cause) { error.value = apiError(cause) }
}

async function saveAdmin() {
  if (!selected.value) return
  saving.value = true; error.value = ''; message.value = ''
  try {
    const bill = Object.fromEntries([
      ...editableFields.map((key) => [key, selected.value?.[key]]),
      ['status', selected.value.status],
      ['bplus_sale_type_code', selected.value.bplus_sale_type_code],
    ])
    const response = await api.put<{ data: BillingRecord }>(`/api/v1/bills/${selected.value.bill_id}/admin`, { bill, items: selected.value.items })
    selected.value = response.data.data
    persistedStatus.value = selected.value.status || ''
    message.value = `บันทึกบิล #${selected.value.bill_id} และคำนวณยอดใหม่แล้ว`
    await load()
  } catch (cause) { error.value = apiError(cause) }
  finally { saving.value = false }
}

async function saveStatus() {
  if (!selected.value?.status || selected.value.status === persistedStatus.value) return
  if (!confirm(`ยืนยันเปลี่ยนสถานะบิล #${selected.value.bill_id} เป็น "${selected.value.status}" หรือไม่?`)) return

  statusSaving.value = true; error.value = ''; message.value = ''
  try {
    const response = await api.patch<{ data: BillingRecord }>(`/api/v1/bills/${selected.value.bill_id}/admin-status`, {
      status: selected.value.status,
    })
    selected.value = response.data.data
    persistedStatus.value = selected.value.status || ''
    message.value = `เปลี่ยนสถานะบิล #${selected.value.bill_id} เป็น ${persistedStatus.value} แล้ว`
    await load()
  } catch (cause) { error.value = apiError(cause) }
  finally { statusSaving.value = false }
}

async function cancelBill() {
  if (!selected.value) return
  const reason = prompt(`ระบุเหตุผลยกเลิกบิล #${selected.value.bill_id} (ระบบจะเก็บเอกสารและประวัติทั้งหมดไว้)`)?.trim()
  if (!reason || !confirm(`ยืนยันยกเลิกบิล #${selected.value.bill_id} โดยไม่ลบข้อมูลถาวรหรือไม่?`)) return
  try {
    const response = await api.post<{ data: BillingRecord }>(`/api/v1/bills/${selected.value.bill_id}/cancel`, { reason })
    selected.value = response.data.data; persistedStatus.value = selected.value.status || ''
    message.value = 'ยกเลิกบิลแล้ว โดยเก็บเอกสาร รายการสินค้า และเหตุผลไว้ครบ'; await load()
  } catch (cause) { error.value = apiError(cause) }
}

onMounted(() => load(1))
</script>

<template>
  <AppShell title="จัดการระบบส่วนกลาง" subtitle="ส่งออก Bplus, ดูฐานข้อมูล และแก้ไข/ลบบิลแบบละเอียด">
    <div class="tabs">
      <button :class="{ active: tab === 'export' }" @click="tab = 'export'">ศูนย์ Export</button>
      <button :class="{ active: tab === 'history' }" @click="tab = 'history'">ประวัติทั้งหมด</button>
      <button :class="{ active: tab === 'edit' }" @click="tab = 'edit'">แก้ไข / ยกเลิก</button>
    </div>
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>

    <template v-if="tab === 'export'">
      <div class="metrics-grid metrics-grid--three">
        <article class="metric-card"><span>บิลพร้อม Export</span><strong>{{ ready.length }}</strong></article>
        <article class="metric-card metric-card--primary"><span>มูลค่ารวม</span><strong class="metric-money">{{ money(totalReady) }}</strong></article>
        <article class="metric-card"><span>รายการสินค้า</span><strong>{{ ready.reduce((sum, bill) => sum + (bill.items?.length || 0), 0) }}</strong></article>
      </div>
      <section class="content-card">
        <div class="section-heading"><div><h2>Bplus Import Center</h2><p class="muted">เลือกบิลหนึ่งหรือหลายใบ แล้ว Export Excel หรือรวมใบเสนอราคาเป็น PDF เดียว</p><p v-if="selectedExportIds.length && !canExportQuotationPdf" class="form-hint">รายการที่สร้างจาก PO โดยตรงไม่มีใบเสนอราคา จึง Export ได้เฉพาะ Excel</p></div><div class="actions"><button class="secondary-button" :disabled="!selectedExportIds.length" @click="exportBills">Export Excel ({{ selectedExportIds.length }})</button><button class="secondary-button" :disabled="!canExportQuotationPdf" title="ใช้ได้เฉพาะบิลที่สร้างจากใบเสนอราคา" @click="exportQuotationsPdf">Export PDF ใบเสนอราคา ({{ selectedExportIds.length }})</button><button class="primary-button" :disabled="!ready.length" @click="completeAll">ปิดงานทั้งหมด</button></div></div>
        <div class="table-wrap"><table><thead><tr><th><input v-model="allReadySelected" type="checkbox" aria-label="เลือกบิลทั้งหมด"></th><th>Bill</th><th>ลูกค้า</th><th>Sale / แผนก</th><th>ยอดรวม</th><th>สถานะ</th><th>Export ล่าสุด</th><th>จัดการ</th></tr></thead><tbody><tr v-for="bill in ready" :key="bill.bill_id"><td><input v-model="selectedExportIds" type="checkbox" :value="bill.bill_id" :aria-label="`เลือกบิล ${bill.bill_id}`"></td><td class="strong">#{{ bill.bill_id }}</td><td>{{ bill.buyer_name }}</td><td>{{ bill.created_by }}<br><small>{{ bill.creator?.department || '-' }}</small></td><td>{{ money(bill.total_amount) }}</td><td><StatusPill :status="bill.status" /></td><td>{{ shortDate(bill.excel_exported_at) }}</td><td><button class="primary-button primary-button--compact" @click="complete(bill.bill_id)">ปิดงาน</button></td></tr><tr v-if="!ready.length"><td colspan="8" class="empty-cell">ไม่มีบิลพร้อมส่งออก</td></tr></tbody></table></div>
      </section>
    </template>

    <section v-else-if="tab === 'history'" class="content-card">
      <div class="section-heading"><h2>รายการบิลทุกสถานะ</h2><form class="search-row" @submit.prevent="load(1)"><label><span>ค้นหาลูกค้า/Sale/Tax ID</span><input v-model="search"></label><button class="secondary-button">ค้นหา</button></form></div>
      <div class="table-wrap"><table><thead><tr><th>ID</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>ยอดรวม</th><th>สถานะ</th><th>พิมพ์</th><th>Export</th></tr></thead><tbody><tr v-for="bill in records?.data" :key="bill.bill_id"><td class="strong">#{{ bill.bill_id }}</td><td>{{ shortDate(bill.bill_date) }}</td><td>{{ bill.buyer_name }}</td><td>{{ bill.created_by }}</td><td>{{ money(bill.total_amount) }}</td><td><StatusPill :status="bill.status" /></td><td>{{ shortDate(bill.document_printed_at) }}</td><td>{{ shortDate(bill.excel_exported_at) }}</td></tr><tr v-if="!records?.data.length"><td colspan="8" class="empty-cell">ไม่พบข้อมูล</td></tr></tbody></table></div>
      <div v-if="records" class="pagination"><span>แสดง {{ records.from || 0 }}–{{ records.to || 0 }} จาก {{ records.total }}</span><div><button class="secondary-button" :disabled="!records.prev_page_url" @click="load(records.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ records.current_page }} / {{ records.last_page }}</span><button class="secondary-button" :disabled="!records.next_page_url" @click="load(records.current_page + 1)">ถัดไป</button></div></div>
    </section>

    <section v-else>
      <div class="content-card search-row">
        <label><span>กรอก Bill ID</span><input v-model.number="selectedId" type="number" min="1" @change="loadSelected"></label>
        <button class="primary-button primary-button--compact" :disabled="!selectedId" @click="loadSelected">โหลดบิล</button>
      </div>
      <form v-if="selected" class="content-card form-grid section-gap" @submit.prevent="saveAdmin">
        <div class="span-2 section-heading"><div><h2>แก้ไขบิล #{{ selected.bill_id }}</h2><p class="muted">การยกเลิกจะเก็บเอกสารและ Audit Log ไว้ ไม่ลบข้อมูลทางบัญชี</p></div><button class="danger-button" type="button" :disabled="selected.status === 'ยกเลิกบิล'" @click="cancelBill">ยกเลิกบิล</button></div>
        <p v-if="selected.status === 'ยกเลิกบิล'" class="span-2 form-error">ยกเลิกโดย {{ selected.canceled_by || '-' }} · เหตุผล: {{ selected.cancellation_reason || '-' }}</p>
        <div class="span-2 admin-status-editor">
          <div class="admin-status-editor__current"><span>สถานะปัจจุบัน</span><StatusPill :status="persistedStatus" /></div>
          <label><span>เปลี่ยนสถานะเป็น</span><select v-model="selected.status"><option v-for="status in statuses" :key="status" :disabled="status === 'ยกเลิกบิล'">{{ status }}</option></select></label>
          <button class="primary-button primary-button--compact" type="button" :disabled="statusSaving || selected.status === persistedStatus" @click="saveStatus">
            {{ statusSaving ? 'กำลังบันทึก…' : 'บันทึกสถานะบิล' }}
          </button>
        </div>
        <label><span>รหัสซื้อขาย</span><select v-model="selected.bplus_sale_type_code"><option value=""></option><option>สินเชื่อ</option><option>เงินสด</option><option>ผ่อนชำระ</option></select></label>
        <label v-for="field in editableFields" :key="field">
          <span>{{ fieldLabels[field] }}</span>
          <textarea v-if="textAreaFields.has(field)" v-model="selected[field]"></textarea>
          <input v-else-if="dateFields.has(field)" v-model="selected[field]" type="date">
          <input v-else-if="numericFields.has(field)" v-model="selected[field]" type="number" min="0" step="0.01">
          <input v-else v-model="selected[field]">
        </label>

        <div class="span-2 form-section-block">
          <h2>รายการสินค้า</h2>
          <article v-for="item in selected.items" :key="item.item_id" class="warehouse-item">
            <strong>#{{ item.item_id }}</strong>
            <label><span>SKU</span><input v-model="item.main_category" required></label>
            <label><span>ชื่อสินค้า</span><input v-model="item.sub_category" required></label>
            <label><span>หน่วย</span><input v-model="item.unit_type" required></label>
            <label><span>จำนวน</span><input v-model.number="item.quantity" type="number" min="1" required></label>
            <label><span>ราคา</span><input v-model="item.unit_price" type="number" min="0" step="0.01" required></label>
            <label><span>ส่วนลด Sale</span><input v-model="item.sale_discount_unit" type="number" min="0" step="0.01"></label>
            <label><span>ส่วนลด Admin สำหรับ Bplus</span><input v-model="item.admin_disocount_unit" type="number" min="0" step="0.01"></label>
            <label><span>จำนวนแถม</span><input v-model.number="item.admin_free_qty" type="number" min="0"></label>
            <label><span>น้ำหนัก/หน่วย</span><input v-model="item.admin_weight_unit" type="number" min="0" step="0.01"></label>
            <label><span>Lot</span><input v-model="item.admin_lot"></label>
            <label><span>Serial</span><input v-model="item.admin_serial"></label>
            <label><span>Storage</span><input v-model="item.admin_storage"></label>
            <div class="line-total"><span>ยอดสุทธิรายการ</span><strong>{{ money(Math.max(Number(item.quantity || 0) * Number(item.unit_price || 0) - Number(item.sale_discount_unit || 0), 0)) }}</strong></div>
          </article>
        </div>
        <div class="span-2 total-summary"><span>รวมสินค้า {{ money(itemSubtotal) }}</span><strong>ยอดสุทธิหลังส่วนลดท้ายบิล {{ money(calculatedTotal) }}</strong></div>
        <button class="primary-button span-2" :disabled="saving || selected.status === 'ยกเลิกบิล'">{{ saving ? 'กำลังตรวจสอบและคำนวณ…' : 'บันทึกการแก้ไขทั้งหมด' }}</button>
      </form>
    </section>
  </AppShell>
</template>
