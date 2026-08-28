<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, downloadFile } from '../api/client'
import { apiError, money, shortDate } from '../utils/format'
import type { BillItem } from '../types/api'

interface AcceptanceDocument {
  bill_id: number; qt_no: string; revision_no: number; bill_date: string; buyer_name: string; address_note: string
  total_amount: string; quotation_valid_until: string | null; payment_terms: string | null; delivery_due_date: string | null
  delivery_terms: string | null; status: string; items: BillItem[]; intent_text: string; accepted_at: string | null; signer_name: string | null
}

const route = useRoute()
const token = computed(() => String(route.params.token))
const document = ref<AcceptanceDocument | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const message = ref('')
const signatureMethod = ref<'drawn' | 'uploaded_png'>('drawn')
const uploadedSignature = ref('')
const drawing = ref(false)
const hasDrawing = ref(false)
const form = reactive({ signer_name: '', signer_position: '', signer_organization: '', signer_email: '', consent: false })
const alreadyAccepted = computed(() => Boolean(document.value?.accepted_at))

async function load() {
  loading.value = true; error.value = ''
  try {
    const response = await api.get<{ data: AcceptanceDocument }>(`/api/v1/public/quotation/${token.value}`)
    document.value = response.data.data
    form.signer_organization = response.data.data.buyer_name || ''
    await nextTick(); prepareCanvas()
  } catch (cause) { error.value = apiError(cause, 'ไม่พบใบเสนอราคาหรือลิงก์หมดอายุ กรุณาติดต่อ Sale') }
  finally { loading.value = false }
}
function prepareCanvas() {
  if (!canvas.value) return
  const ratio = window.devicePixelRatio || 1
  const rect = canvas.value.getBoundingClientRect()
  canvas.value.width = Math.max(1, Math.round(rect.width * ratio))
  canvas.value.height = Math.max(1, Math.round(220 * ratio))
  const context = canvas.value.getContext('2d')
  if (!context) return
  context.scale(ratio, ratio); context.lineWidth = 2.3; context.lineCap = 'round'; context.lineJoin = 'round'; context.strokeStyle = '#172033'
}
function point(event: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  return { x: event.clientX - rect.left, y: event.clientY - rect.top }
}
function begin(event: PointerEvent) {
  if (!canvas.value || alreadyAccepted.value) return
  canvas.value.setPointerCapture(event.pointerId); drawing.value = true
  const context = canvas.value.getContext('2d'); const p = point(event)
  context?.beginPath(); context?.moveTo(p.x, p.y)
}
function draw(event: PointerEvent) {
  if (!drawing.value || !canvas.value) return
  const context = canvas.value.getContext('2d'); const p = point(event)
  context?.lineTo(p.x, p.y); context?.stroke(); hasDrawing.value = true
}
function end() { drawing.value = false }
function clearSignature() {
  if (!canvas.value) return
  canvas.value.getContext('2d')?.clearRect(0, 0, canvas.value.width, canvas.value.height)
  hasDrawing.value = false
}
function uploadPng(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  uploadedSignature.value = ''
  if (!file) return
  if (file.type !== 'image/png' || file.size > 1024 * 1024) { error.value = 'กรุณาเลือกไฟล์ PNG ขนาดไม่เกิน 1 MB'; return }
  const reader = new FileReader()
  reader.onload = () => { uploadedSignature.value = String(reader.result || '') }
  reader.readAsDataURL(file)
}
async function accept() {
  if (!document.value) return
  const signatureImage = signatureMethod.value === 'drawn' ? (hasDrawing.value ? canvas.value?.toDataURL('image/png') : '') : uploadedSignature.value
  if (!signatureImage) { error.value = 'กรุณาวาดลายเซ็นหรืออัปโหลดไฟล์ PNG ก่อนยืนยัน'; return }
  submitting.value = true; error.value = ''; message.value = ''
  try {
    await api.get('/sanctum/csrf-cookie')
    await api.post(`/api/v1/public/quotation/${token.value}/accept`, {
      ...form,
      intent: document.value.intent_text,
      signature_method: signatureMethod.value,
      signature_image: signatureImage,
    })
    message.value = 'ยืนยันใบเสนอราคาและบันทึกหลักฐานอิเล็กทรอนิกส์เรียบร้อยแล้ว'
    await load()
  } catch (cause) { error.value = apiError(cause, 'ยืนยันใบเสนอราคาไม่สำเร็จ') }
  finally { submitting.value = false }
}
function pdf() { return downloadFile(`/api/v1/public/quotation/${token.value}/pdf`) }
onMounted(load)
</script>

<template>
  <main class="public-page acceptance-page">
    <header class="public-header"><div class="portal-brand"><span class="portal-brand__mark">V</span><span><strong>VALOR</strong><small>Quotation Acceptance Portal</small></span></div></header>
    <section v-if="loading" class="public-card">กำลังโหลดใบเสนอราคา…</section>
    <section v-else-if="error && !document" class="public-card"><h1>ไม่สามารถเปิดเอกสารได้</h1><p class="form-error">{{ error }}</p></section>
    <template v-else-if="document">
      <section class="public-card">
        <div class="section-heading"><div><p class="eyebrow eyebrow--red">CUSTOMER ACCEPTANCE</p><h1>ยืนยันใบเสนอราคา {{ document.qt_no }}</h1></div><button class="secondary-button" type="button" @click="pdf">ดาวน์โหลด PDF</button></div>
        <div class="detail-grid"><span>Revision <b>R{{ document.revision_no }}</b></span><span>วันที่ <b>{{ shortDate(document.bill_date) }}</b></span><span>ลูกค้า <b>{{ document.buyer_name }}</b></span><span>ยอดรวม <b>{{ money(document.total_amount) }}</b></span></div>
        <p class="legal-summary">กรุณาตรวจสอบเอกสารทั้งหมดก่อนลงนาม การวาดหรืออัปโหลดภาพลายเซ็นจะใช้ร่วมกับชื่อผู้ลงนาม ตำแหน่ง องค์กร ข้อความแสดงเจตนา วันเวลา IP และค่า SHA-256 ของ Revision นี้ เพื่อจัดเก็บเป็นหลักฐานธุรกรรมอิเล็กทรอนิกส์</p>
      </section>

      <section class="public-card section-gap"><h2>รายการและเงื่อนไข</h2><div class="table-wrap"><table><thead><tr><th>รหัสสินค้า</th><th>รายการ</th><th>จำนวน</th><th>ราคาต่อหน่วย</th><th>รวม</th></tr></thead><tbody><tr v-for="item in document.items" :key="item.item_id"><td>{{ item.main_category }}</td><td>{{ item.customer_product_name || item.sub_category }}</td><td>{{ item.quantity }} {{ item.unit_type }}</td><td>{{ money(item.unit_price) }}</td><td>{{ money(item.line_total) }}</td></tr></tbody></table></div>
        <div class="acceptance-terms"><p><b>วันหมดอายุ:</b> {{ shortDate(document.quotation_valid_until) }}</p><p><b>เงื่อนไขชำระเงิน:</b> {{ document.payment_terms || '-' }}</p><p><b>กำหนดส่ง:</b> {{ shortDate(document.delivery_due_date) }}</p><p><b>เงื่อนไขส่ง:</b> {{ document.delivery_terms || '-' }}</p></div>
      </section>

      <section v-if="alreadyAccepted" class="public-card public-state-card section-gap"><span class="public-state-icon public-state-icon--success">✓</span><h2>ยืนยันเรียบร้อยแล้ว</h2><p>{{ document.signer_name }} · {{ shortDate(document.accepted_at) }}</p><button class="primary-button" @click="pdf">ดาวน์โหลดใบเสนอราคาที่ลงนามแล้ว</button></section>

      <form v-else class="public-card form-grid section-gap" @submit.prevent="accept">
        <div class="span-2"><h2>ข้อมูลผู้มีอำนาจยืนยันคำสั่งซื้อ</h2><p class="muted">ผู้ลงนามควรเป็นผู้มีอำนาจของหน่วยงาน หรือได้รับมอบหมายให้ยืนยันรายการนี้</p></div>
        <label><span>ชื่อ-นามสกุล *</span><input v-model="form.signer_name" required maxlength="500"></label><label><span>ตำแหน่ง *</span><input v-model="form.signer_position" required maxlength="500"></label>
        <label><span>หน่วยงาน / โรงพยาบาล *</span><input v-model="form.signer_organization" required maxlength="1000"></label><label><span>อีเมล</span><input v-model="form.signer_email" type="email" maxlength="255"></label>
        <div class="span-2 signature-methods"><label><input v-model="signatureMethod" type="radio" value="drawn"> วาดลายเซ็นบนหน้าจอ</label><label><input v-model="signatureMethod" type="radio" value="uploaded_png"> อัปโหลดลายเซ็น PNG</label></div>
        <div v-show="signatureMethod === 'drawn'" class="span-2"><div class="signature-pad-wrap"><canvas ref="canvas" class="signature-pad" @pointerdown.prevent="begin" @pointermove.prevent="draw" @pointerup.prevent="end" @pointercancel.prevent="end" @pointerleave="end"></canvas><span>วาดลายเซ็นภายในกรอบ</span></div><button class="secondary-button section-gap-sm" type="button" @click="clearSignature">ล้างลายเซ็น</button></div>
        <label v-if="signatureMethod === 'uploaded_png'" class="span-2"><span>ไฟล์ PNG ลายเซ็น (ไม่เกิน 1 MB) *</span><input type="file" accept="image/png,.png" @change="uploadPng"><img v-if="uploadedSignature" class="signature-preview" :src="uploadedSignature" alt="ตัวอย่างลายเซ็น"></label>
        <div class="span-2 intent-box"><strong>ข้อความแสดงเจตนา</strong><p>{{ document.intent_text }}</p><label class="approval-consent"><input v-model="form.consent" type="checkbox" required><span>ข้าพเจ้ายืนยันว่าเป็นผู้มีอำนาจหรือได้รับมอบหมาย และยอมรับข้อความข้างต้นโดยสมัครใจ</span></label></div>
        <p v-if="error" class="form-error span-2">{{ error }}</p><p v-if="message" class="success-message span-2">{{ message }}</p>
        <button class="primary-button span-2" :disabled="submitting || !form.consent">{{ submitting ? 'กำลังบันทึกหลักฐาน…' : 'ยืนยันและลงลายมือชื่ออิเล็กทรอนิกส์' }}</button>
      </form>
    </template>
  </main>
</template>
