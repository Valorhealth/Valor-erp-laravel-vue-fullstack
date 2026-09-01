<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord, Paginated, Quotation, ReferenceData, ThaiLocations } from '../types/api'
import { apiError, money, todayIso } from '../utils/format'

interface SaleItemForm { product: string; main_category: string; sub_category: string; customer_product_name: string; unit_type: string; quantity: number; unit_price: number; sale_discount_unit: number }
interface PurchaseOrderItem {
  source_code: string; source_name: string; unit: string; quantity: number; unit_price: number; discount: number
  matched_sku: string | null; matched_name: string | null; suggested_sku: string | null; suggested_name: string | null
  match_confidence: number; match_status: 'matched' | 'review' | 'unmatched'
}
interface PurchaseOrderScan {
  po_number: string; po_date: string; customer_confirmed_at: string; buyer_name: string; tax_id: string; address: string
  province: string; district: string; sub_district: string; zipcode: string; contact_name: string; contact_channel: string
  payment_terms: string; delivery_due_date: string; delivery_terms: string; quotation_valid_until: string; note: string
  confidence: number; warnings: string[]; items: PurchaseOrderItem[]; matched_customer_code: string | null
}
const refs = ref<ReferenceData | null>(null); const locations = ref<ThaiLocations>({}); const quotations = ref<Quotation[]>([])
const quotationPage = ref<Paginated<Quotation> | null>(null); const rejectedBills = ref<BillingRecord[]>([]); const quotationSearch = ref('')
const loading = ref(true); const saving = ref(false); const error = ref(''); const success = ref(''); const manualCustomer = ref(false); const showCreditForm = ref(false)
const purchaseOrderFile = ref<File | null>(null)
const documentMode = ref<'quotation' | 'po_direct'>('quotation')
const poScanning = ref(false); const poScan = ref<PurchaseOrderScan | null>(null)
let quotationRefreshTimer: number | null = null
const creditFiles = reactive<Record<string, File | null>>({ affidavit: null, vat: null, director_id: null, bank_statement: null })
const credit = reactive({ company_name: '', tax_id: '', registered_address: '', authorized_directors: '', requested_credit_limit: 0, requested_credit_term: 0, expected_monthly_volume: 0, justification: '' })
const form = reactive({ qt_date: todayIso(), customer: '', bplus_customer_code: '', buyer_name: '', tax_id: '', province: '', district: '', sub_district: '', zipcode: '', house_no: '', moo: '', soi: '', road: '', address_extra: '', shipping_type: '', shipping_text: '', bill_note: '', sale_discount_total: 0, sale_discount_note: '', requested_credit_term: 0, workflow_type: 'existing_customer', customer_po_no: '', customer_confirmed_at: '', customer_contact_name: '', customer_contact_channel: '', quotation_valid_until: '', payment_terms: '', delivery_due_date: '', delivery_terms: '', items: [newItem()] as SaleItemForm[] })
function newItem(): SaleItemForm { return { product: '', main_category: '', sub_category: '', customer_product_name: '', unit_type: 'ชิ้น', quantity: 1, unit_price: 0, sale_discount_unit: 0 } }
const districts = computed(() => form.province ? Object.keys(locations.value[form.province] || {}) : [])
const subDistricts = computed(() => form.province && form.district ? Object.keys(locations.value[form.province]?.[form.district] || {}) : [])
const selectedCredit = computed(() => refs.value?.credit_profiles.find((profile) => profile.ar_code === form.bplus_customer_code))
const grossTotal = computed(() => form.items.reduce((sum, item) => sum + Math.max(item.quantity * item.unit_price - item.sale_discount_unit, 0), 0))
const netTotal = computed(() => Math.max(grossTotal.value - form.sale_discount_total, 0))

function selectCustomer() {
  const customer = refs.value?.customers.find((item) => item.ar_code === form.customer)
  if (!customer) return
  Object.assign(form, { bplus_customer_code: customer.ar_code, buyer_name: customer.ar_name, tax_id: customer.tax_id || '', requested_credit_term: refs.value?.credit_profiles.find((profile) => profile.ar_code === customer.ar_code)?.credit_term || 0 })
}
function selectProduct(item: SaleItemForm) {
  const product = refs.value?.products.find((entry) => entry.sku_code === item.product)
  if (product) Object.assign(item, { main_category: product.sku_code, sub_category: product.sku_name })
}
function selectProvince() { form.district = ''; form.sub_district = ''; form.zipcode = '' }
function selectDistrict() { form.sub_district = ''; form.zipcode = '' }
function selectSubDistrict() { form.zipcode = locations.value[form.province]?.[form.district]?.[form.sub_district] || '' }
function onFile(key: string, event: Event) { creditFiles[key] = (event.target as HTMLInputElement).files?.[0] || null }
function onPurchaseOrder(event: Event) { purchaseOrderFile.value = (event.target as HTMLInputElement).files?.[0] || null }

function applyPurchaseOrder(result: PurchaseOrderScan) {
  if (result.po_date) form.qt_date = result.po_date
  if (result.matched_customer_code && refs.value?.customers.some((customer) => customer.ar_code === result.matched_customer_code)) {
    manualCustomer.value = false; form.customer = result.matched_customer_code; selectCustomer()
  } else {
    manualCustomer.value = true; form.customer = ''; form.bplus_customer_code = ''
    if (result.buyer_name) form.buyer_name = result.buyer_name
    if (result.tax_id) form.tax_id = result.tax_id
  }
  if (result.po_number) form.customer_po_no = result.po_number
  form.customer_confirmed_at = result.customer_confirmed_at || result.po_date || form.customer_confirmed_at
  if (result.contact_name) form.customer_contact_name = result.contact_name
  if (result.contact_channel) form.customer_contact_channel = result.contact_channel
  if (result.payment_terms) form.payment_terms = result.payment_terms
  if (result.delivery_due_date) form.delivery_due_date = result.delivery_due_date
  if (result.delivery_terms) form.delivery_terms = result.delivery_terms
  if (result.quotation_valid_until) form.quotation_valid_until = result.quotation_valid_until
  if (result.note) form.bill_note = result.note
  if (result.address) form.address_extra = result.address
  if (result.province && locations.value[result.province]) {
    form.province = result.province
    if (result.district && locations.value[result.province]?.[result.district]) {
      form.district = result.district
      if (result.sub_district && locations.value[result.province]?.[result.district]?.[result.sub_district]) form.sub_district = result.sub_district
    }
    form.zipcode = result.zipcode || locations.value[form.province]?.[form.district]?.[form.sub_district] || ''
  }
  if (result.items.length) {
    form.items = result.items.map((item) => ({
      product: item.matched_sku || '', main_category: item.matched_sku || '', sub_category: item.matched_name || item.source_name,
      customer_product_name: item.source_name, unit_type: refs.value?.units.includes(item.unit) ? item.unit : (refs.value?.units[0] || 'ชิ้น'),
      quantity: Math.max(1, Number(item.quantity || 1)), unit_price: Math.max(0, Number(item.unit_price || 0)), sale_discount_unit: Math.max(0, Number(item.discount || 0)),
    }))
  }
}

async function scanPurchaseOrder() {
  if (!purchaseOrderFile.value) { error.value = 'กรุณาเลือกไฟล์ PO ก่อนอ่านข้อมูล'; return }
  poScanning.value = true; error.value = ''; success.value = ''; poScan.value = null
  try {
    const payload = new FormData(); payload.append('document', purchaseOrderFile.value)
    const response = await api.post<{ data: PurchaseOrderScan }>('/api/v1/purchase-orders/scan', payload, { timeout: 190_000 })
    poScan.value = response.data.data; applyPurchaseOrder(response.data.data)
    const unmatched = response.data.data.items.filter((item) => item.match_status === 'unmatched').length
    success.value = `อ่าน PO และช่วยกรอกแล้ว ${response.data.data.items.length} รายการ${unmatched ? ` · มี ${unmatched} รายการที่ Sale ต้องเลือกสินค้าหลักเอง` : ''}`
  } catch (cause) { error.value = apiError(cause, 'AI อ่านใบ PO ไม่สำเร็จ') }
  finally { poScanning.value = false }
}
function poRiskClass(result: PurchaseOrderScan): string {
  if (result.items.some((item) => item.match_status === 'unmatched')) return 'risk-banner--danger'
  if (result.confidence < 0.8 || result.items.some((item) => item.match_status === 'review')) return 'risk-banner--warning'
  return 'risk-banner--success'
}

async function load(page = quotationPage.value?.current_page || 1) {
  loading.value = true
  try {
    const [referenceResponse, locationResponse, quoteResponse, rejectedResponse] = await Promise.all([
      api.get<{ data: ReferenceData }>('/api/v1/reference-data'),
      api.get<{ data: ThaiLocations }>('/api/v1/reference-data/locations'),
      api.get<Paginated<Quotation>>('/api/v1/quotations', { params: { page, search: quotationSearch.value || undefined, available_for_billing: 1, per_page: 20 } }),
      api.get<Paginated<BillingRecord>>('/api/v1/billing-records', { params: { status: 'รอแก้ไข', per_page: 100 } }),
    ])
    refs.value = referenceResponse.data.data; locations.value = locationResponse.data.data; quotationPage.value = quoteResponse.data; quotations.value = quoteResponse.data.data; rejectedBills.value = rejectedResponse.data.data
  } catch (cause) { error.value = apiError(cause, 'โหลดข้อมูลสำหรับเปิดบิลไม่สำเร็จ') }
  finally { loading.value = false }
}

async function refreshQuotations(page = quotationPage.value?.current_page || 1) {
  try {
    const response = await api.get<Paginated<Quotation>>('/api/v1/quotations', {
      params: { page, search: quotationSearch.value || undefined, available_for_billing: 1, per_page: 20 },
    })
    quotationPage.value = response.data
    quotations.value = response.data.data
  } catch (cause) {
    error.value = apiError(cause, 'อัปเดตสถานะใบเสนอราคาไม่สำเร็จ')
  }
}

async function submitCredit() {
  error.value = ''; success.value = ''; saving.value = true
  try {
    const data = new FormData(); Object.entries(credit).forEach(([key, value]) => data.append(key, String(value))); data.append('request_type', 'new_customer')
    Object.entries(creditFiles).forEach(([key, file]) => { if (file) data.append(`documents[${key}]`, file) })
    const response = await api.post<{ data: { request_id: number } }>('/api/v1/credit-requests', data)
    success.value = `ส่งคำขอเปิดวงเงิน #${response.data.data.request_id} ให้ Approve แล้ว`; showCreditForm.value = false
  } catch (cause) { error.value = apiError(cause) } finally { saving.value = false }
}

async function submitQuotation() {
  error.value = ''; success.value = ''; saving.value = true
  try {
    const address = `เลขที่ ${form.house_no} ม.${form.moo} ซ.${form.soi} ถ.${form.road} จ.${form.province} อ.${form.district} ต.${form.sub_district} ${form.zipcode} ${form.address_extra}`.trim()
    const response = await api.post<{ data: Quotation }>('/api/v1/quotations', { ...form, address_note: address, customer: undefined, house_no: undefined, moo: undefined, soi: undefined, road: undefined, address_extra: undefined, items: form.items.map(({ product, ...item }) => item) })
    const billId = response.data.data.billing_record?.bill_id
    let uploadWarning = ''
    if (purchaseOrderFile.value && billId) {
      try {
        const document = new FormData(); document.append('file', purchaseOrderFile.value)
        await api.post(`/api/v1/bills/${billId}/purchase-order`, document)
      } catch (cause) { uploadWarning = ` แต่ไฟล์ PO ยังไม่ถูกแนบ (${apiError(cause)}) กรุณาแนบซ้ำในหน้าแก้ Draft` }
    }
    success.value = `บันทึกใบเสนอราคา ${response.data.data.qt_no} เป็น Draft #${response.data.data.billing_record?.bill_id} เรียบร้อยแล้ว${uploadWarning}`; resetQuotation(); await load()
  } catch (cause) { error.value = apiError(cause) } finally { saving.value = false }
}
async function submitPurchaseOrderDirect() {
  if (!purchaseOrderFile.value) { error.value = 'เส้นทาง PO Direct ต้องแนบไฟล์ PO ต้นฉบับ'; return }
  if (!form.customer_po_no || !form.customer_confirmed_at) { error.value = 'กรุณาระบุเลขที่ PO และวันที่ลูกค้ายืนยัน'; return }
  if (!confirm('ยืนยันสร้างรายการเปิดบิลจาก PO โดยตรงและส่งให้หัวหน้าอนุมัติ? ระบบจะไม่สร้างเลขใบเสนอราคา')) return
  error.value = ''; success.value = ''; saving.value = true
  try {
    const address = `เลขที่ ${form.house_no} ม.${form.moo} ซ.${form.soi} ถ.${form.road} จ.${form.province} อ.${form.district} ต.${form.sub_district} ${form.zipcode} ${form.address_extra}`.trim()
    const data = new FormData()
    const fields: Record<string, unknown> = {
      bill_date: form.qt_date, buyer_name: form.buyer_name, tax_id: form.tax_id,
      bplus_customer_code: form.bplus_customer_code, province: form.province, district: form.district,
      sub_district: form.sub_district, zipcode: form.zipcode, address_note: address,
      shipping_type: form.shipping_type, shipping_text: form.shipping_text, bill_note: form.bill_note,
      sale_discount_total: form.sale_discount_total, sale_discount_note: form.sale_discount_note,
      requested_credit_term: form.requested_credit_term, customer_po_no: form.customer_po_no,
      customer_confirmed_at: form.customer_confirmed_at, customer_contact_name: form.customer_contact_name,
      customer_contact_channel: form.customer_contact_channel, payment_terms: form.payment_terms,
      delivery_due_date: form.delivery_due_date, delivery_terms: form.delivery_terms,
    }
    Object.entries(fields).forEach(([key, value]) => { if (value !== '' && value !== null && value !== undefined) data.append(key, String(value)) })
    form.items.forEach((item, index) => {
      const { product: _product, ...values } = item
      Object.entries(values).forEach(([key, value]) => data.append(`items[${index}][${key}]`, String(value ?? '')))
    })
    data.append('purchase_order', purchaseOrderFile.value)
    const response = await api.post<{ data: BillingRecord }>('/api/v1/purchase-orders/create-bill', data)
    success.value = `สร้างรายการเปิดบิล #${response.data.data.bill_id} จาก PO โดยไม่ออกเลขใบเสนอราคา และส่งให้หัวหน้าอนุมัติแล้ว`
    resetQuotation(); documentMode.value = 'quotation'; await load()
  } catch (cause) { error.value = apiError(cause, 'สร้างรายการจาก PO ไม่สำเร็จ') }
  finally { saving.value = false }
}
function submitCurrent() { return documentMode.value === 'po_direct' ? submitPurchaseOrderDirect() : submitQuotation() }
function resetQuotation() { Object.assign(form, { qt_date: todayIso(), customer: '', bplus_customer_code: '', buyer_name: '', tax_id: '', province: '', district: '', sub_district: '', zipcode: '', house_no: '', moo: '', soi: '', road: '', address_extra: '', shipping_type: '', shipping_text: '', bill_note: '', sale_discount_total: 0, sale_discount_note: '', requested_credit_term: 0, customer_po_no: '', customer_confirmed_at: '', customer_contact_name: '', customer_contact_channel: '', quotation_valid_until: '', payment_terms: '', delivery_due_date: '', delivery_terms: '', items: [newItem()] }); purchaseOrderFile.value = null; poScan.value = null; manualCustomer.value = false }
async function createBill(quotation: Quotation) {
  if (quotation.workflow_type === 'credit_first' && quotation.status !== 'เปิดวงเงินแล้ว') {
    error.value = 'ใบเสนอราคานี้ต้องรอ Admin เปิดวงเงินก่อนจึงจะส่งอนุมัติได้'
    return
  }
  if (!confirm(`สร้างและส่งอนุมัติ Draft จาก ${quotation.qt_no} ของ ${quotation.buyer_name}?`)) return
  try {
    const response = quotation.billing_record
      ? await api.post<{ data: { bill_id: number; status: string } }>(`/api/v1/bills/${quotation.billing_record.bill_id}/submit`)
      : await api.post<{ data: { bill_id: number; status: string } }>(
          `/api/v1/quotations/${quotation.qt_id}/create-bill`,
          { bill_date: quotation.qt_date || todayIso(), save_as_draft: false },
        )
    success.value = `ส่งบิล #${response.data.data.bill_id} เข้าสู่ขั้นตอนอนุมัติแล้ว สถานะ ${response.data.data.status}`
    await load()
  }
  catch (cause) { error.value = apiError(cause) }
}
function quotationStatus(quotation: Quotation): string {
  return quotation.billing_record?.status || quotation.status || '-'
}
onMounted(() => {
  load()
  quotationRefreshTimer = window.setInterval(() => {
    if (!document.hidden) refreshQuotations()
  }, 15_000)
})
onBeforeUnmount(() => {
  if (quotationRefreshTimer !== null) window.clearInterval(quotationRefreshTimer)
})
</script>

<template>
  <AppShell title="ระบบเปิดบิล Product" subtitle="สร้างใบเสนอราคาก่อน แล้วเปิดบิลจากใบเสนอราคาที่บันทึกไว้">
    <p v-if="error" class="form-error section-gap-sm">{{ error }}</p><p v-if="success" class="success-message section-gap-sm">{{ success }}</p>
    <section v-if="rejectedBills.length" class="list-stack section-gap-sm">
      <article v-for="bill in rejectedBills" :key="bill.bill_id" class="risk-banner risk-banner--danger">
        บิล #{{ bill.bill_id }} · {{ bill.buyer_name }} ถูกตีกลับ: {{ bill.reject_reason || 'ไม่ได้ระบุเหตุผล' }}
        <a class="text-link" href="/bills/edit">เปิดหน้าแก้ไขบิล</a>
      </article>
    </section>
    <div v-if="loading" class="loading-state">กำลังโหลดลูกค้า สินค้า และข้อมูลเครดิต…</div>
    <template v-else>
      <section class="content-card ocr-panel"><div><p class="eyebrow eyebrow--red">PURCHASE ORDER AI</p><h2>สแกนใบ PO เพื่อช่วยกรอกฟอร์ม</h2><p class="muted">รองรับ PDF, PNG/JPG และ XLSX · ไฟล์นี้จะถูกแนบเก็บกับ Draft เป็นหลักฐานเมื่อกดบันทึก</p></div><label><span>ใบ PO / หลักฐานยืนยัน</span><input type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx" @change="onPurchaseOrder"></label><button class="secondary-button" :disabled="poScanning || !purchaseOrderFile" @click="scanPurchaseOrder">{{ poScanning ? 'AI กำลังอ่าน PO…' : '✨ สแกนและกรอกฟอร์ม' }}</button></section>
      <div v-if="poScan" class="risk-banner section-gap-sm" :class="poRiskClass(poScan)">ความมั่นใจเอกสาร {{ Math.round(poScan.confidence * 100) }}% · จับคู่สินค้าแล้ว {{ poScan.items.filter((item) => item.match_status !== 'unmatched').length }}/{{ poScan.items.length }} รายการ<span v-if="poScan.warnings.length"> · ควรตรวจสอบ: {{ poScan.warnings.join(' · ') }}</span></div>
      <section class="content-card section-gap-sm"><div class="section-heading"><div><h2>เลือกเส้นทางเอกสาร</h2><p class="muted">เลือกให้ตรงกับหลักฐานที่ลูกค้ามอบให้ เพื่อไม่ออกเลขใบเสนอราคาโดยไม่จำเป็น</p></div><div class="tabs"><button type="button" :class="{ active: documentMode === 'quotation' }" @click="documentMode = 'quotation'">สร้างใบเสนอราคา</button><button type="button" :class="{ active: documentMode === 'po_direct' }" @click="documentMode = 'po_direct'">มี PO แล้ว — เปิดบิลโดยตรง</button></div></div><div v-if="documentMode === 'po_direct'" class="risk-banner risk-banner--success">PO ต้นฉบับเป็นหลักฐานคำสั่งซื้อ ระบบจะสร้างรายการเปิดบิลและส่ง Approve โดยไม่สร้างแถวใน quotations และไม่ใช้เลข QT</div></section>
      <section class="content-card collapsible-panel">
        <button class="panel-toggle" @click="showCreditForm = !showCreditForm"><span><strong>ขอเปิดวงเงินลูกค้าใหม่</strong><small>ส่งข้อมูลนิติบุคคลและเอกสาร 4 รายการให้ Approve</small></span><b>{{ showCreditForm ? '−' : '+' }}</b></button>
        <form v-if="showCreditForm" class="form-grid panel-body" @submit.prevent="submitCredit">
          <label><span>ชื่อบริษัท / ชื่อจดทะเบียน *</span><input v-model="credit.company_name" required></label><label><span>Tax ID 13 หลัก *</span><input v-model="credit.tax_id" pattern="\d{13}" maxlength="13" required></label>
          <label class="span-2"><span>ที่อยู่จดทะเบียน *</span><textarea v-model="credit.registered_address" required></textarea></label><label class="span-2"><span>กรรมการผู้มีอำนาจ *</span><textarea v-model="credit.authorized_directors" required></textarea></label>
          <label><span>วงเงินที่ขอ *</span><input v-model.number="credit.requested_credit_limit" type="number" min="1" required></label><label><span>Credit Term *</span><input v-model.number="credit.requested_credit_term" type="number" min="1" required></label>
          <label><span>ยอดคาดการณ์/เดือน</span><input v-model.number="credit.expected_monthly_volume" type="number" min="0"></label><label><span>เหตุผล *</span><textarea v-model="credit.justification" required></textarea></label>
          <label><span>หนังสือรับรองบริษัท *</span><input type="file" accept=".pdf,.png,.jpg,.jpeg" required @change="onFile('affidavit', $event)"></label><label><span>ภ.พ.20 *</span><input type="file" accept=".pdf,.png,.jpg,.jpeg" required @change="onFile('vat', $event)"></label>
          <label><span>บัตรประชาชนกรรมการ *</span><input type="file" accept=".pdf,.png,.jpg,.jpeg" required @change="onFile('director_id', $event)"></label><label><span>Statement 3–6 เดือน *</span><input type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx" required @change="onFile('bank_statement', $event)"></label>
          <button class="primary-button span-2" :disabled="saving">ส่งคำขอเปิดวงเงิน</button>
        </form>
      </section>

      <form class="content-card form-grid section-gap" @submit.prevent="submitCurrent">
        <div class="span-2 section-heading form-section-title"><div><p class="eyebrow eyebrow--red">{{ documentMode === 'po_direct' ? 'PURCHASE ORDER DIRECT' : 'QUOTATION' }}</p><h2>1. {{ documentMode === 'po_direct' ? 'ข้อมูลลูกค้าและคำสั่งซื้อ' : 'ข้อมูลลูกค้าและใบเสนอราคา' }}</h2><p class="muted">{{ documentMode === 'po_direct' ? 'ใช้ PO ต้นฉบับเป็นหลักฐานและไม่ออกเลขใบเสนอราคา' : 'บันทึกลงฐานข้อมูลและกลับมาพิมพ์ PDF ได้ภายหลัง' }}</p></div><label><span>วันที่</span><input v-model="form.qt_date" type="date" required></label></div>
        <label><span>ค้นหาและเลือกลูกค้า *</span><select v-model="form.customer" :disabled="manualCustomer" @change="selectCustomer"><option value="">-- กรุณาเลือกลูกค้า --</option><option v-for="customer in refs?.customers" :key="customer.ar_code" :value="customer.ar_code">{{ customer.ar_code }} | {{ customer.ar_name }}</option></select></label>
        <label class="check-field"><input v-model="manualCustomer" type="checkbox"> กรอกลูกค้าใหม่เอง</label>
        <label><span>รหัสลูกหนี้ *</span><input v-model="form.bplus_customer_code" :readonly="!manualCustomer" required></label><label><span>ชื่อลูกค้า *</span><input v-model="form.buyer_name" :readonly="!manualCustomer" required></label>
        <label><span>เลขผู้เสียภาษี (เฉพาะกรอกลูกค้าใหม่)</span><input v-model="form.tax_id" maxlength="30"></label>
        <label><span>Credit Term ที่ลูกค้าขอ</span><input v-model.number="form.requested_credit_term" type="number" min="0"><small>ระบบ: {{ selectedCredit?.credit_term || 0 }} วัน · วงเงิน {{ money(selectedCredit?.credit_limit) }}</small></label>
        
        <div class="span-2 form-section-block"><div class="section-heading"><h2>2. รายการสินค้า</h2><button class="secondary-button" type="button" @click="form.items.push(newItem())">+ เพิ่มรายการ</button></div>
          <article v-for="(item, index) in form.items" :key="index" class="line-item">
            <label class="line-item__product"><span>สินค้าหลักในระบบ *</span><select v-model="item.product" required @change="selectProduct(item)"><option value="">-- เลือกสินค้า{{ item.customer_product_name ? 'ให้ตรงกับ PO' : '' }} --</option><option v-for="product in refs?.products" :key="product.sku_code" :value="product.sku_code">{{ product.sku_code }} | {{ product.sku_name }}</option></select></label><label class="line-item__product"><span>ชื่อสินค้าใน PO / ตามที่ รพ. ต้องการ</span><input v-model="item.customer_product_name" maxlength="2000" placeholder="ระบบจะกรอกจาก PO หรือ Sale กรอกเอง"></label>
            <label><span>หน่วย</span><select v-model="item.unit_type"><option v-for="unit in refs?.units" :key="unit">{{ unit }}</option></select></label><label><span>จำนวน</span><input v-model.number="item.quantity" type="number" min="1" required></label><label><span>ราคา Ex VAT</span><input v-model.number="item.unit_price" type="number" min="0" step="0.01" required></label><label><span>ส่วนลดรายการ</span><input v-model.number="item.sale_discount_unit" type="number" min="0" step="0.01"></label><div class="line-total">
            <span>รวม</span><strong>{{ money(Math.max(item.quantity * item.unit_price - item.sale_discount_unit, 0)) }}</strong></div><button v-if="form.items.length > 1" class="remove-line" type="button" @click="form.items.splice(index, 1)">×</button>
          </article>
            <label class="span-2"><span>หมายเหตุส่วนลด</span><textarea v-model="form.sale_discount_note" maxlength="2000" placeholder="ระบุรายละเอียดหรือเงื่อนไขส่วนลดเพิ่มเติม (ถ้ามี)"></textarea></label>

        </div>
        <!-- <label><span>ส่วนลดท้ายบิล</span><input v-model.number="form.sale_discount_total" type="number" min="0" step="0.01"></label> -->
        <div class="span-2 total-summary">
        <!-- <span>ยอดก่อนส่วนลดท้ายบิล <b>{{ money(grossTotal) }}</b></span> -->
        <strong>ยอดสุทธิ {{ money(netTotal) }}</strong></div>
        <div class="span-2 form-section-title"><h2>3. ข้อมูลยืนยันการซื้อขาย</h2><p class="muted">ข้อมูลแก้ไขต่อได้ใน Draft และระบบจะเก็บเป็น Revision</p></div>
        <label><span>เลขที่ PO / หนังสือสั่งซื้อ</span><input v-model="form.customer_po_no" maxlength="255"></label>
        <label><span>วันที่ลูกค้ายืนยัน</span><input v-model="form.customer_confirmed_at" type="date"></label><label><span>ผู้ติดต่อ</span><input v-model="form.customer_contact_name" maxlength="500"></label>
        <label><span>ช่องทางยืนยัน</span><input v-model="form.customer_contact_channel" placeholder="อีเมล / LINE / โทรศัพท์ / หนังสือ"></label><label v-if="documentMode === 'quotation'"><span>วันหมดอายุใบเสนอราคา</span><input v-model="form.quotation_valid_until" type="date" :min="form.qt_date"></label>
        <label class="span-2"><span>เงื่อนไขการชำระเงิน</span><textarea v-model="form.payment_terms"></textarea></label><label><span>กำหนดส่งสินค้า</span><input v-model="form.delivery_due_date" type="date"></label><label><span>เงื่อนไขการส่งสินค้า</span><input v-model="form.delivery_terms"></label>
        <div class="span-2 form-section-title"><h2>4. ที่อยู่และการจัดส่ง</h2><p class="muted">กรอกข้อมูลตั้งแต่บ้านเลขที่จนถึงรายละเอียดการจัดส่ง</p></div><label><span>บ้านเลขที่ *</span><input v-model="form.house_no" required></label><label><span>หมู่</span><input v-model="form.moo"></label><label><span>ซอย</span><input v-model="form.soi"></label><label><span>ถนน</span><input v-model="form.road"></label>
        <label><span>จังหวัด *</span><select v-model="form.province" required @change="selectProvince"><option value="">-- เลือกจังหวัด --</option><option v-for="province in Object.keys(locations)" :key="province">{{ province }}</option></select></label><label><span>อำเภอ/เขต *</span><select v-model="form.district" required @change="selectDistrict"><option value="">-- เลือกอำเภอ --</option><option v-for="district in districts" :key="district">{{ district }}</option></select></label><label><span>ตำบล/แขวง *</span><select v-model="form.sub_district" required @change="selectSubDistrict"><option value="">-- เลือกตำบล --</option><option v-for="sub in subDistricts" :key="sub">{{ sub }}</option></select></label><label><span>รหัสไปรษณีย์</span><input v-model="form.zipcode" readonly></label>
        <label class="span-2"><span>จุดสังเกตเพิ่มเติม</span><textarea v-model="form.address_extra"></textarea></label><label><span>ประเภทการจัดส่ง *</span><select v-model="form.shipping_type" required><option value="">-- เลือก --</option><option v-for="shipping in refs?.shipping_types" :key="shipping">{{ shipping }}</option></select></label><label><span>หมายเหตุการจัดส่ง</span><input v-model="form.shipping_text"></label><label class="span-2"><span>รายละเอียดบิล</span><textarea v-model="form.bill_note"></textarea></label>
        <button class="primary-button span-2" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : documentMode === 'po_direct' ? 'สร้างรายการจาก PO และส่งอนุมัติ' : 'ยืนยันบันทึกใบเสนอราคา' }}</button>
      </form>

      <section class="content-card section-gap">
        <div class="section-heading"><div><p class="eyebrow eyebrow--red">Quotation to bill</p><h2>สร้างบิลจากใบเสนอราคา</h2><p class="muted">รายการทั้งหมดเป็น Draft และจะแก้ไขได้จนกว่าจะกดสร้างและส่งอนุมัติ</p></div><form class="search-row" @submit.prevent="load(1)"><label><span>ค้นหาใบเสนอราคา</span><input v-model="quotationSearch" placeholder="เลขที่หรือลูกค้า"></label><button class="secondary-button">ค้นหา</button></form></div>
        <div class="table-wrap"><table><thead><tr><th>เลขที่</th><th>ลูกค้า</th><th>ยอดรวม</th><th>สถานะ</th><th>วันที่บิล</th><th>จัดการ</th></tr></thead><tbody>
          <tr v-for="quote in quotations" :key="quote.qt_id"><td class="strong">{{ quote.qt_no }}</td><td>{{ quote.billing_record?.buyer_name || quote.buyer_name }}</td><td>{{ money(quote.billing_record?.total_amount || quote.total_amount) }}</td><td><StatusPill :status="quotationStatus(quote)" /></td><td>{{ quote.billing_record?.bill_date || quote.qt_date }}</td><td class="actions"><button class="primary-button primary-button--compact" @click="createBill(quote)">สร้างและส่งอนุมัติ</button></td></tr>
          <tr v-if="!quotations.length"><td colspan="6" class="empty-cell">ไม่พบใบเสนอราคา</td></tr>
        </tbody></table></div>
        <div v-if="quotationPage" class="pagination"><span>แสดง {{ quotationPage.from || 0 }}–{{ quotationPage.to || 0 }} จาก {{ quotationPage.total }} รายการ</span><div><button class="secondary-button" :disabled="!quotationPage.prev_page_url" @click="load(quotationPage.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ quotationPage.current_page }} / {{ quotationPage.last_page }}</span><button class="secondary-button" :disabled="!quotationPage.next_page_url" @click="load(quotationPage.current_page + 1)">ถัดไป</button></div></div>
      </section>
    </template>
  </AppShell>
</template>
