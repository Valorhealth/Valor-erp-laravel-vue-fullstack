<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord, Paginated, Quotation, ReferenceData, ThaiLocations } from '../types/api'
import { apiError, money, todayIso } from '../utils/format'

interface SaleItemForm { product: string; main_category: string; sub_category: string; unit_type: string; quantity: number; unit_price: number; sale_discount_unit: number }
const refs = ref<ReferenceData | null>(null); const locations = ref<ThaiLocations>({}); const quotations = ref<Quotation[]>([])
const quotationPage = ref<Paginated<Quotation> | null>(null); const rejectedBills = ref<BillingRecord[]>([]); const quotationSearch = ref('')
const loading = ref(true); const saving = ref(false); const error = ref(''); const success = ref(''); const manualCustomer = ref(false); const showCreditForm = ref(false)
const ocrFile = ref<File | null>(null); const ocrRunning = ref(false)
let quotationRefreshTimer: number | null = null
const creditFiles = reactive<Record<string, File | null>>({ affidavit: null, vat: null, director_id: null, bank_statement: null })
const credit = reactive({ company_name: '', tax_id: '', registered_address: '', authorized_directors: '', requested_credit_limit: 0, requested_credit_term: 0, expected_monthly_volume: 0, justification: '' })
const form = reactive({ qt_date: todayIso(), customer: '', bplus_customer_code: '', buyer_name: '', tax_id: '', province: '', district: '', sub_district: '', zipcode: '', house_no: '', moo: '', soi: '', road: '', address_extra: '', shipping_type: '', shipping_text: '', bill_note: '', sale_discount_total: 0, sale_discount_note: '', requested_credit_term: 0, workflow_type: 'existing_customer', items: [newItem()] as SaleItemForm[] })
function newItem(): SaleItemForm { return { product: '', main_category: '', sub_category: '', unit_type: 'ชิ้น', quantity: 1, unit_price: 0, sale_discount_unit: 0 } }
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
function onOcrFile(event: Event) { ocrFile.value = (event.target as HTMLInputElement).files?.[0] || null }

async function scanReceipt() {
  if (!ocrFile.value) { error.value = 'กรุณาเลือกรูปใบเสร็จก่อนสแกน'; return }
  ocrRunning.value = true; error.value = ''; success.value = ''
  try {
    const payload = new FormData(); payload.append('image', ocrFile.value)
    const response = await api.post<{ data: Record<string, string | number> }>('/api/v1/receipt-ocr', payload, { timeout: 190_000 })
    const result = response.data.data
    manualCustomer.value = true
    form.buyer_name = String(result.buyer_name || form.buyer_name); form.tax_id = String(result.tax_id || form.tax_id)
    form.province = String(result.province || ''); form.district = String(result.district || ''); form.sub_district = String(result.sub_district || '')
    form.zipcode = String(result.zipcode || locations.value[form.province]?.[form.district]?.[form.sub_district] || '')
    form.address_extra = String(result.address_note || ''); form.bill_note = String(result.bill_note || '')
    const hint = `${result.main_category || ''} ${result.sub_category || ''}`.toLowerCase()
    const matched = refs.value?.products.find((product) => hint.includes(product.sku_code.toLowerCase()) || hint.includes(product.sku_name.toLowerCase()))
    const item = form.items[0]
    if (matched) { item.product = matched.sku_code; selectProduct(item) }
    item.unit_type = String(result.unit_type || item.unit_type); item.unit_price = Number(result.unit_price || 0); item.quantity = Math.max(Number(result.quantity || 1), 1)
    success.value = 'AI สแกนและกรอกข้อมูลให้แล้ว กรุณาตรวจสอบซ้ำก่อนบันทึก'
  } catch (cause) { error.value = apiError(cause, 'AI สแกนเอกสารไม่สำเร็จ') } finally { ocrRunning.value = false }
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
    success.value = `บันทึกใบเสนอราคา ${response.data.data.qt_no} เป็น Draft #${response.data.data.billing_record?.bill_id} เรียบร้อยแล้ว`; resetQuotation(); await load()
  } catch (cause) { error.value = apiError(cause) } finally { saving.value = false }
}
function resetQuotation() { Object.assign(form, { qt_date: todayIso(), customer: '', bplus_customer_code: '', buyer_name: '', tax_id: '', province: '', district: '', sub_district: '', zipcode: '', house_no: '', moo: '', soi: '', road: '', address_extra: '', shipping_type: '', shipping_text: '', bill_note: '', sale_discount_total: 0, sale_discount_note: '', requested_credit_term: 0, items: [newItem()] }); manualCustomer.value = false }
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
      <section class="content-card ocr-panel"><div><p class="eyebrow eyebrow--red">AI OCR</p><h2>สแกนใบเสร็จเพื่อกรอกฟอร์มอัตโนมัติ</h2><p class="muted">รองรับ PNG/JPG และประมวลผล Gemini ผ่าน Backend เท่านั้น</p></div><label><span>รูปเอกสาร</span><input type="file" accept="image/png,image/jpeg" @change="onOcrFile"></label><button class="secondary-button" :disabled="ocrRunning || !ocrFile" @click="scanReceipt">{{ ocrRunning ? 'AI กำลังสแกน…' : 'สแกนและกรอกฟอร์ม' }}</button></section>
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

      <form class="content-card form-grid section-gap" @submit.prevent="submitQuotation">
        <div class="span-2 section-heading form-section-title"><div><p class="eyebrow eyebrow--red">Quotation</p><h2>1. ข้อมูลลูกค้าและใบเสนอราคา</h2><p class="muted">บันทึกลงฐานข้อมูลและกลับมาพิมพ์ PDF ได้ภายหลัง</p></div><label><span>วันที่</span><input v-model="form.qt_date" type="date" required></label></div>
        <label><span>ค้นหาและเลือกลูกค้า *</span><select v-model="form.customer" :disabled="manualCustomer" @change="selectCustomer"><option value="">-- กรุณาเลือกลูกค้า --</option><option v-for="customer in refs?.customers" :key="customer.ar_code" :value="customer.ar_code">{{ customer.ar_code }} | {{ customer.ar_name }}</option></select></label>
        <label class="check-field"><input v-model="manualCustomer" type="checkbox"> กรอกลูกค้าใหม่เอง</label>
        <label><span>รหัสลูกหนี้ *</span><input v-model="form.bplus_customer_code" :readonly="!manualCustomer" required></label><label><span>ชื่อลูกค้า *</span><input v-model="form.buyer_name" :readonly="!manualCustomer" required></label>
        <label><span>เลขผู้เสียภาษี(เฉพาะกรอกลูกค้าใหม่)</span><input v-model="form.tax_id" maxlength="30"></label>
        <br>
        <label><span>Credit Term ที่ลูกค้าขอ</span><input v-model.number="form.requested_credit_term" type="number" min="0"><small>ระบบ: {{ selectedCredit?.credit_term || 0 }} วัน · วงเงิน {{ money(selectedCredit?.credit_limit) }}</small></label>
        <br>
        
        <div class="span-2 form-section-block"><div class="section-heading"><h2>2. รายการสินค้า</h2><button class="secondary-button" type="button" @click="form.items.push(newItem())">+ เพิ่มรายการ</button></div>
          <article v-for="(item, index) in form.items" :key="index" class="line-item">
            <label class="line-item__product"><span>สินค้า *</span><select v-model="item.product" required @change="selectProduct(item)"><option value="">-- เลือกสินค้า --</option><option v-for="product in refs?.products" :key="product.sku_code" :value="product.sku_code">{{ product.sku_code }} | {{ product.sku_name }}</option></select></label>
            <label><span>หน่วย</span><select v-model="item.unit_type"><option v-for="unit in refs?.units" :key="unit">{{ unit }}</option></select></label><label><span>จำนวน</span><input v-model.number="item.quantity" type="number" min="1" required></label><label><span>ราคา Ex VAT</span><input v-model.number="item.unit_price" type="number" min="0" step="0.01" required></label><label><span>ส่วนลดรายการ</span><input v-model.number="item.sale_discount_unit" type="number" min="0" step="0.01"></label><div class="line-total"><span>รวม</span><strong>{{ money(Math.max(item.quantity * item.unit_price - item.sale_discount_unit, 0)) }}</strong></div><button v-if="form.items.length > 1" class="remove-line" type="button" @click="form.items.splice(index, 1)">×</button>
          </article>
        </div>
        <!-- <label><span>ส่วนลดท้ายบิล</span><input v-model.number="form.sale_discount_total" type="number" min="0" step="0.01"></label><label><span>หมายเหตุส่วนลด</span><input v-model="form.sale_discount_note"></label> -->
        <div class="span-2 total-summary"><span>ยอดก่อนส่วนลดท้ายบิล <b>{{ money(grossTotal) }}</b></span><strong>ยอดสุทธิ {{ money(netTotal) }}</strong></div>
        <div class="span-2 form-section-title"><h2>3. ที่อยู่และการจัดส่ง</h2><p class="muted">กรอกข้อมูลตั้งแต่บ้านเลขที่จนถึงรายละเอียดการจัดส่ง</p></div><label><span>บ้านเลขที่ *</span><input v-model="form.house_no" required></label><label><span>หมู่</span><input v-model="form.moo"></label><label><span>ซอย</span><input v-model="form.soi"></label><label><span>ถนน</span><input v-model="form.road"></label>
        <label><span>จังหวัด *</span><select v-model="form.province" required @change="selectProvince"><option value="">-- เลือกจังหวัด --</option><option v-for="province in Object.keys(locations)" :key="province">{{ province }}</option></select></label><label><span>อำเภอ/เขต *</span><select v-model="form.district" required @change="selectDistrict"><option value="">-- เลือกอำเภอ --</option><option v-for="district in districts" :key="district">{{ district }}</option></select></label><label><span>ตำบล/แขวง *</span><select v-model="form.sub_district" required @change="selectSubDistrict"><option value="">-- เลือกตำบล --</option><option v-for="sub in subDistricts" :key="sub">{{ sub }}</option></select></label><label><span>รหัสไปรษณีย์</span><input v-model="form.zipcode" readonly></label>
        <label class="span-2"><span>จุดสังเกตเพิ่มเติม</span><textarea v-model="form.address_extra"></textarea></label><label><span>ประเภทการจัดส่ง *</span><select v-model="form.shipping_type" required><option value="">-- เลือก --</option><option v-for="shipping in refs?.shipping_types" :key="shipping">{{ shipping }}</option></select></label><label><span>หมายเหตุการจัดส่ง</span><input v-model="form.shipping_text"></label><label class="span-2"><span>รายละเอียดบิล</span><textarea v-model="form.bill_note"></textarea></label>
        <button class="primary-button span-2" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : 'ยืนยันบันทึกใบเสนอราคา' }}</button>
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
