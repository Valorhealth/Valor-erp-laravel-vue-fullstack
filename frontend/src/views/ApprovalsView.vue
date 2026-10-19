<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import { auth } from '../stores/auth'
import type { BillingRecord, CreditRequest, Paginated } from '../types/api'
import { apiError, money, shortDate } from '../utils/format'
import { preferredRowsPerPage } from '../utils/preferences'

const bills = ref<BillingRecord[]>([])
const quotationReview = ref<{ bill: BillingRecord; quotationUrl: string; authorizationUrl: string } | null>(null)
const quotationReviewLoading = ref<number | null>(null)
const credits = ref<CreditRequest[]>([])
const creditPage = ref<Paginated<CreditRequest> | null>(null)
const loading = ref(true)
const error = ref('')
const message = ref('')
const rejectBillReasons = reactive<Record<number, string>>({})
const rejectCreditReasons = reactive<Record<number, string>>({})
const approvalPasswords = reactive<Record<number, string>>({})
const approvalConsents = reactive<Record<number, boolean>>({})
const quotationApprovalIntent = 'ข้าพเจ้าได้ตรวจสอบใบเสนอราคาและชุดเอกสารประกอบ Revision นี้ และอนุมัติให้ดำเนินการตามข้อมูลดังกล่าว'
const legacyQuotationApprovalIntent = 'ข้าพเจ้าได้ตรวจสอบใบเสนอราคา Revision นี้และอนุมัติให้บริษัทดำเนินการตามข้อมูลดังกล่าว'
const poDirectApprovalIntent = 'ข้าพเจ้าได้ตรวจสอบใบสั่งซื้อของลูกค้าและข้อมูลเปิดบิลรายการนี้แล้ว และอนุมัติให้บริษัทดำเนินการตามข้อมูลดังกล่าว'
const requiresSubmissionDocument = (bill: BillingRecord, document: string) =>
  (bill.submission_document_requirements || []).includes(document)
const approvalIntent = (bill: BillingRecord) => bill.workflow_type === 'customer_po_direct'
  ? poDirectApprovalIntent
  : ((bill.submission_document_requirements || []).length ? quotationApprovalIntent : legacyQuotationApprovalIntent)
const canSignQuotation = () => Boolean(auth.user.value?.quotation_signing_authorized)
const submissionDocumentLabels: Record<string, string> = {
  quotation: 'ใบเสนอราคา', power_of_attorney: 'Draft หนังสือมอบอำนาจ', company_affidavit: 'หนังสือรับรองบริษัท',
  vat_registration: 'ภ.พ.20', grantor_id_copies: 'สำเนาบัตรผู้มอบอำนาจ', representative_id_copy: 'สำเนาบัตรผู้แทน',
  product_documents: 'เอกสารสินค้า/ใบอนุญาต', other: 'เอกสารอื่นตามข้อกำหนด',
}

async function load(creditPageNumber = creditPage.value?.current_page || 1) {
  loading.value = true
  error.value = ''
  try {
    const [billResponse, creditResponse] = await Promise.all([
      api.get<{ data: BillingRecord[] }>('/api/v1/bills/pending-approval'),
      api.get<Paginated<CreditRequest>>('/api/v1/credit-requests', {
        params: { status: 'รออนุมัติวงเงิน', page: creditPageNumber, per_page: preferredRowsPerPage(20) },
      }),
    ])
    bills.value = billResponse.data.data
    creditPage.value = creditResponse.data
    credits.value = creditResponse.data.data
  } catch (cause) {
    error.value = apiError(cause)
  } finally {
    loading.value = false
  }
}

async function action(url: string, data: unknown, success: string) {
  error.value = ''
  message.value = ''
  try {
    await api.post(url, data)
    message.value = success
    await load()
  } catch (cause) {
    error.value = apiError(cause)
  }
}

async function approveBill(bill: BillingRecord) {
  await action(
    `/api/v1/bills/${bill.bill_id}/approve`,
    { password: approvalPasswords[bill.bill_id], intent: approvalIntent(bill) },
    `อนุมัติและบันทึกหลักฐานอิเล็กทรอนิกส์ของบิล #${bill.bill_id} แล้ว`,
  )
  approvalPasswords[bill.bill_id] = ''
  approvalConsents[bill.bill_id] = false
}

async function openQuotationReview(bill: BillingRecord) {
  if (!bill.quotation_document_version_id) {
    error.value = 'รายการนี้ยังไม่มีใบเสนอราคา PDF ฉบับที่ Sale ยืนยัน กรุณาตีกลับให้ Sale เลือก Template และยืนยันเอกสารก่อน'
    return
  }
  quotationReviewLoading.value = bill.bill_id
  error.value = ''
  try {
    const [quotation, authorization] = await Promise.all([
      api.get<Blob>(`/api/v1/bills/${bill.bill_id}/approval-quotation`, { responseType: 'blob' }),
      requiresSubmissionDocument(bill, 'power_of_attorney')
        ? api.get<Blob>(`/api/v1/bills/${bill.bill_id}/approval-power-of-attorney`, { responseType: 'blob' })
        : Promise.resolve(null),
    ])
    closeQuotationReview()
    quotationReview.value = {
      bill,
      quotationUrl: URL.createObjectURL(quotation.data),
      authorizationUrl: authorization ? URL.createObjectURL(authorization.data) : '',
    }
  } catch (cause) {
    error.value = apiError(cause, 'เปิดชุดเอกสารใบเสนอราคาและหนังสือมอบอำนาจไม่สำเร็จ')
  } finally {
    quotationReviewLoading.value = null
  }
}

function closeQuotationReview() {
  if (quotationReview.value?.quotationUrl) URL.revokeObjectURL(quotationReview.value.quotationUrl)
  if (quotationReview.value?.authorizationUrl) URL.revokeObjectURL(quotationReview.value.authorizationUrl)
  quotationReview.value = null
}

async function declineBill(bill: BillingRecord) {
  const reason = rejectBillReasons[bill.bill_id]?.trim()
  if (!reason || !confirm(`ยืนยันไม่อนุมัติบิล #${bill.bill_id} หรือไม่? รายการจะถูกปิดและเก็บในประวัติรายการที่ไม่อนุมัติ`)) return
  await action(
    `/api/v1/bills/${bill.bill_id}/decline`,
    { reason },
    `บันทึกผลไม่อนุมัติบิล #${bill.bill_id} แล้ว`,
  )
  rejectBillReasons[bill.bill_id] = ''
}

function display(value: unknown): string {
  const text = String(value ?? '').trim()
  return text || '-'
}

function workflowLabel(workflow?: string | null): string {
  if (workflow === 'credit_first') return 'ขอเปิดวงเงินก่อนขาย'
  if (workflow === 'existing_customer') return 'ขายจากใบเสนอราคา'
  if (workflow === 'customer_po_direct') return 'เปิดบิลจาก PO โดยตรง'
  return display(workflow)
}

function risk(bill: BillingRecord) {
  const limit = Number(bill.credit_limit || 0)
  const remain = bill.credit_remain === null || bill.credit_remain === undefined ? limit : Number(bill.credit_remain)
  const after = remain - Number(bill.total_amount || 0)

  if (!limit || after < 0) {
    return { text: `เสี่ยงสูง: เครดิตหลังบิล ${money(after)}`, type: 'danger' }
  }
  if (after / limit < 0.2) {
    return { text: `เสี่ยงน้อย: เครดิตหลังบิล ${money(after)}`, type: 'warning' }
  }
  return { text: `เครดิตผ่าน: คงเหลือหลังบิล ${money(after)}`, type: 'success' }
}

function totalDiscount(bill: BillingRecord): number {
  const lineDiscount = bill.items?.reduce((sum, item) => sum + Number(item.sale_discount_unit || 0), 0) || 0
  return lineDiscount + Number(bill.sale_discount_total || 0)
}

onMounted(load)
onBeforeUnmount(closeQuotationReview)
</script>

<template>
  <AppShell title="พิจารณาอนุมัติบิล" subtitle="ตรวจคำขอวงเงินและบิลที่รอหัวหน้าพิจารณา">
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <div v-if="loading" class="loading-state">กำลังโหลดคิวอนุมัติ…</div>

    <template v-else>
      <section>
        <div class="section-heading">
          <div>
            <p class="eyebrow eyebrow--red">Credit requests</p>
            <h2>คำขอวงเงินลูกค้า</h2>
          </div>
          <span class="count-badge">{{ credits.length }}</span>
        </div>

        <div class="list-stack">
          <article
            v-for="request in credits"
            :key="request.request_id"
            class="content-card request-card"
          >
            <div class="section-heading">
              <div>
                <h2>
                  #{{ request.request_id }} ·
                  {{ request.request_type === 'change_existing' ? 'แก้ไขวงเงินเดิม' : 'เปิดวงเงินใหม่' }}
                </h2>
                <p>{{ request.company_name }} · ผู้ขอ {{ request.created_by }}</p>
              </div>
              <StatusPill :status="request.status" />
            </div>

            <div class="detail-grid">
              <p><span>วงเงินเดิม</span><strong>{{ money(request.current_credit_limit) }}</strong></p>
              <p><span>วงเงินที่ขอ</span><strong>{{ money(request.requested_credit_limit) }}</strong></p>
              <p><span>Term เดิม</span><strong>{{ request.current_credit_term || 0 }} วัน</strong></p>
              <p><span>Term ที่ขอ</span><strong>{{ request.requested_credit_term }} วัน</strong></p>
              <p><span>ยอดคาดการณ์/เดือน</span><strong>{{ money(request.expected_monthly_volume) }}</strong></p>
              <p><span>Tax ID</span><strong>{{ display(request.tax_id) }}</strong></p>
            </div>

            <div v-if="request.request_type === 'new_customer'" class="detail-copy">
              <p><strong>ที่อยู่จดทะเบียน:</strong> {{ display(request.registered_address) }}</p>
              <p><strong>กรรมการผู้มีอำนาจ:</strong> {{ display(request.authorized_directors) }}</p>
            </div>
            <p v-else><strong>รหัสลูกหนี้เดิม:</strong> {{ display(request.ar_code) }}</p>
            <p><strong>เหตุผล:</strong> {{ request.justification }}</p>
            <div v-if="request.documents?.length" class="document-links">
              <button
                v-for="doc in request.documents"
                :key="doc.doc_id"
                class="secondary-button"
                @click="downloadFile(`/api/v1/credit-requests/${request.request_id}/documents/${doc.doc_id}`, doc.file_name)"
              >
                {{ doc.doc_type }}
              </button>
            </div>

            <div class="approval-actions">
              <button
                class="primary-button"
                @click="action(`/api/v1/credit-requests/${request.request_id}/approve`, {}, `อนุมัติคำขอ #${request.request_id} แล้ว`)"
              >
                อนุมัติวงเงิน
              </button>
              <label>
                <span>เหตุผลที่ไม่อนุมัติ</span>
                <input v-model="rejectCreditReasons[request.request_id]">
              </label>
              <button
                class="danger-button"
                :disabled="!rejectCreditReasons[request.request_id]"
                @click="action(`/api/v1/credit-requests/${request.request_id}/reject`, { reason: rejectCreditReasons[request.request_id] }, `ไม่อนุมัติคำขอ #${request.request_id}`)"
              >
                ไม่อนุมัติ
              </button>
            </div>
          </article>

          <div v-if="credits.length === 0" class="content-card empty-state">
            ไม่มีคำขอวงเงินค้างอนุมัติ
          </div>
        </div>
        <div v-if="creditPage && creditPage.last_page > 1" class="pagination">
          <span>คำขอ {{ creditPage.from || 0 }}–{{ creditPage.to || 0 }} จาก {{ creditPage.total }}</span>
          <div><button class="secondary-button" :disabled="!creditPage.prev_page_url" @click="load(creditPage.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ creditPage.current_page }} / {{ creditPage.last_page }}</span><button class="secondary-button" :disabled="!creditPage.next_page_url" @click="load(creditPage.current_page + 1)">ถัดไป</button></div>
        </div>
      </section>

      <section class="section-gap">
        <div class="section-heading">
          <div>
            <p class="eyebrow eyebrow--red">Billing approval</p>
            <h2>บิลที่รอหัวหน้าอนุมัติ</h2>
          </div>
          <span class="count-badge">{{ bills.length }}</span>
        </div>

        <div class="list-stack">
          <article
            v-for="bill in bills"
            :key="bill.bill_id"
            class="content-card approval-card"
          >
            <div class="section-heading approval-card__header">
              <div>
                <h2>บิล #{{ bill.bill_id }} · {{ bill.buyer_name }}</h2>
                <p>{{ shortDate(bill.bill_date) }} · ผู้ขอ {{ bill.created_by }}</p>
              </div>
              <div class="approval-card__status">
                <StatusPill :status="bill.status" />
                <strong class="approval-total">{{ money(bill.total_amount) }}</strong>
              </div>
            </div>

            <section class="approval-detail-section">
              <h3>ข้อมูลลูกค้าและเอกสาร</h3>
              <div class="approval-info-grid">
                <div><span>ชื่อลูกค้า</span><strong>{{ display(bill.buyer_name) }}</strong></div>
                <div><span>รหัสลูกค้า Bplus</span><strong>{{ display(bill.bplus_customer_code) }}</strong></div>
                <div><span>เลขผู้เสียภาษี</span><strong>{{ display(bill.tax_id) }}</strong></div>
                <div><span>วันที่บิล</span><strong>{{ shortDate(bill.bill_date) }}</strong></div>
                <div><span>ใบเสนอราคาอ้างอิง</span><strong>{{ bill.ref_qt_id ? `#${bill.ref_qt_id}` : '-' }}</strong></div>
                <div><span>Revision</span><strong>R{{ bill.revision_no || 1 }}</strong></div>
                <div><span>รูปแบบการขาย</span><strong>{{ workflowLabel(bill.workflow_type) }}</strong></div>
              </div>
            </section>

            <section class="approval-detail-section">
              <h3>การยืนยันจากลูกค้า</h3>
              <div class="approval-info-grid">
                <div><span>เลขที่ PO</span><strong>{{ display(bill.customer_po_no) }}</strong></div>
                <div><span>วันที่ยืนยัน</span><strong>{{ shortDate(bill.customer_confirmed_at) }}</strong></div>
                <div><span>ผู้ติดต่อ</span><strong>{{ display(bill.customer_contact_name) }}</strong></div>
                <div><span>ช่องทางยืนยัน</span><strong>{{ display(bill.customer_contact_channel) }}</strong></div>
                <div><span>ใบเสนอราคาหมดอายุ</span><strong>{{ shortDate(bill.quotation_valid_until) }}</strong></div>
                <div><span>กำหนดส่งสินค้า</span><strong>{{ shortDate(bill.delivery_due_date) }}</strong></div>
                <div class="approval-info-grid__wide"><span>เงื่อนไขชำระเงิน</span><strong>{{ display(bill.payment_terms) }}</strong></div>
                <div class="approval-info-grid__wide"><span>เงื่อนไขส่งสินค้า</span><strong>{{ display(bill.delivery_terms) }}</strong></div>
              </div>
              <button v-if="bill.documents?.length" class="secondary-button" @click="downloadFile(`/api/v1/bills/${bill.bill_id}/purchase-order`, bill.documents[0].file_name)">ดาวน์โหลด PO / หลักฐานยืนยัน</button>
            </section>

            <section class="approval-detail-section">
              <h3>ที่อยู่และการจัดส่ง</h3>
              <div class="approval-info-grid">
                <div class="approval-info-grid__wide">
                  <span>ที่อยู่เต็ม</span>
                  <strong>{{ display(bill.address_note) }}</strong>
                </div>
                <div><span>จังหวัด</span><strong>{{ display(bill.province) }}</strong></div>
                <div><span>อำเภอ/เขต</span><strong>{{ display(bill.district) }}</strong></div>
                <div><span>ตำบล/แขวง</span><strong>{{ display(bill.sub_district) }}</strong></div>
                <div><span>รหัสไปรษณีย์</span><strong>{{ display(bill.zipcode) }}</strong></div>
                <div><span>ประเภทการจัดส่ง</span><strong>{{ display(bill.shipping_type) }}</strong></div>
                <div class="approval-info-grid__wide">
                  <span>หมายเหตุการจัดส่ง</span>
                  <strong>{{ display(bill.shipping_text) }}</strong>
                </div>
              </div>
            </section>

            <section class="approval-detail-section">
              <h3>เครดิต ส่วนลด และรายละเอียดบิล</h3>
              <div class="approval-info-grid">
                <div><span>วงเงิน</span><strong>{{ money(bill.credit_limit) }}</strong></div>
                <div><span>ยอดชำระล่าสุด</span><strong>{{ money(bill.credit_outstanding) }}</strong></div>
                <div><span>คงเหลือ</span><strong>{{ money(bill.credit_remain) }}</strong></div>
                <div><span>Credit Term ในระบบ</span><strong>{{ bill.credit_term || 0 }} วัน</strong></div>
                <div><span>Credit Term ที่ขอ</span><strong>{{ bill.requested_credit_term || 0 }} วัน</strong></div>
                <div><span>ส่วนลดท้ายบิล</span><strong>{{ money(bill.sale_discount_total) }}</strong></div>
                <div><span>ส่วนลดรวมทั้งหมด</span><strong>{{ money(totalDiscount(bill)) }}</strong></div>
                <div class="approval-info-grid__wide">
                  <span>หมายเหตุส่วนลด</span>
                  <strong>{{ display(bill.sale_discount_note) }}</strong>
                </div>
                <div class="approval-info-grid__wide">
                  <span>รายละเอียดบิล</span>
                  <strong>{{ display(bill.bill_note) }}</strong>
                </div>
              </div>
            </section>

            <p class="risk-banner" :class="`risk-banner--${risk(bill).type}`">
              {{ risk(bill).text }}
            </p>

            <section class="approval-detail-section approval-detail-section--items">
              <h3>รายการสินค้าที่ Sale กรอก</h3>
              <div class="table-wrap">
                <table class="approval-items-table">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>สินค้า</th>
                      <th>หน่วย</th>
                      <th>จำนวน</th>
                      <th>ราคาต่อหน่วย</th>
                      <th>ยอดก่อนลด</th>
                      <th>ส่วนลดรายการ</th>
                      <th>ยอดสุทธิ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in bill.items" :key="item.item_id">
                      <td>{{ display(item.main_category) }}</td>
                      <td>{{ display(item.sub_category) }}</td>
                      <td>{{ display(item.unit_type) }}</td>
                      <td>{{ item.quantity || 0 }}</td>
                      <td>{{ money(item.unit_price) }}</td>
                      <td>{{ money(item.line_total_before_discount) }}</td>
                      <td>{{ money(item.sale_discount_unit) }}</td>
                      <td class="strong">{{ money(item.line_total) }}</td>
                    </tr>
                    <tr v-if="!bill.items?.length">
                      <td colspan="8" class="empty-cell">ไม่พบรายการสินค้า</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>

            <div class="approval-actions approval-actions--bill">
              <div class="approval-document-checklist">
                <strong>เอกสารที่ Sale ระบุว่าต้องใช้ยื่นเสนอราคา</strong>
                <span v-for="document in (bill.submission_document_requirements || ['quotation'])" :key="document">✓ {{ submissionDocumentLabels[document] || document }}</span>
              </div>
              <button
                v-if="bill.ref_qt_id"
                class="secondary-button"
                type="button"
                :disabled="quotationReviewLoading === bill.bill_id || !bill.quotation_document_version_id"
                @click="openQuotationReview(bill)"
              >
                {{ quotationReviewLoading === bill.bill_id ? 'กำลังเปิด…' : 'ตรวจใบเสนอราคา + หนังสือมอบอำนาจ' }}
              </button>
              <p v-if="bill.ref_qt_id && !bill.quotation_document_version_id" class="form-error">ยังไม่มี PDF Template ที่ Sale ยืนยัน — ต้องตีกลับให้จัดทำเอกสารก่อนอนุมัติ</p>
              <div class="approval-signature-confirmation">
                <strong>ยืนยันลายมือชื่ออิเล็กทรอนิกส์ทั่วไป</strong>
                <p v-if="!canSignQuotation()" class="form-error">บัญชีนี้ยังไม่ได้รับการกำหนดสิทธิ์ลงนาม โปรดให้ Super Admin ตรวจหนังสือมอบอำนาจและเปิดสิทธิ์ในเมนูจัดการผู้ใช้งาน</p>
                <p>{{ approvalIntent(bill) }}</p>
                <label class="approval-consent">
                  <input v-model="approvalConsents[bill.bill_id]" type="checkbox">
                  <span>ยืนยันว่าข้าพเจ้าเป็นเจ้าของบัญชีและประสงค์แสดงเจตนาตามข้อความข้างต้น</span>
                </label>
                <label>
                  <span>กรอกรหัสผ่านของบัญชีอีกครั้ง</span>
                  <input
                    v-model="approvalPasswords[bill.bill_id]"
                    type="password"
                    autocomplete="current-password"
                    placeholder="รหัสผ่านสำหรับยืนยันตัวตน"
                  >
                </label>
                <small>ระบบจะเก็บผู้อนุมัติ เวลา IP Revision และ SHA-256 ของข้อมูลที่อนุมัติ ไม่ใช่ Digital Signature แบบ PKI</small>
              </div>
              <button
                class="primary-button"
                :disabled="!canSignQuotation() || (bill.workflow_type !== 'customer_po_direct' && !bill.quotation_document_version_id) || !approvalConsents[bill.bill_id] || !approvalPasswords[bill.bill_id]"
                @click="approveBill(bill)"
              >
                ยืนยันและอนุมัติบิลนี้
              </button>
              <button
                class="danger-button"
                :disabled="!rejectBillReasons[bill.bill_id]"
                @click="action(`/api/v1/bills/${bill.bill_id}/reject`, { reason: rejectBillReasons[bill.bill_id] }, `ตีกลับบิล #${bill.bill_id} แล้ว`)"
              >
                ตีกลับให้แก้ไข
              </button>
              <button
                class="danger-button"
                :disabled="!rejectBillReasons[bill.bill_id]"
                @click="declineBill(bill)"
              >
                ไม่อนุมัติบิล
              </button>
              <label>
                <span>เหตุผลตีกลับ / ไม่อนุมัติ</span>
                <input
                  v-model="rejectBillReasons[bill.bill_id]"
                  placeholder="ระบุเหตุผลเพื่อเก็บในประวัติและแจ้ง Sale"
                >
              </label>
            </div>
          </article>

          <div v-if="bills.length === 0" class="content-card empty-state">
            ไม่มีบิลค้างอนุมัติ
          </div>
        </div>
      </section>
    </template>
    <Teleport to="body">
      <div v-if="quotationReview" class="approval-document-overlay" @click.self="closeQuotationReview">
        <section class="approval-document-dialog" role="dialog" aria-modal="true" aria-label="เอกสารคู่ที่ Sale ยืนยัน">
          <header><div><p class="eyebrow">SALE CONFIRMED DOCUMENTS</p><h2>เอกสารคู่สำหรับพิจารณาอนุมัติ</h2><small>Template {{ quotationReview.bill.quotation_document_version?.template_id }} · Revision {{ quotationReview.bill.quotation_document_version?.revision_no }} · หนังสือมอบอำนาจ {{ quotationReview.bill.quotation_document_version?.power_of_attorney?.authorization_no }}</small></div><button class="secondary-button" type="button" aria-label="ปิด" @click="closeQuotationReview">×</button></header>
          <div class="approval-document-grid" :class="{ 'approval-document-grid--single': !quotationReview.authorizationUrl }"><div><h3>ใบเสนอราคา</h3><iframe :src="quotationReview.quotationUrl" title="ใบเสนอราคาที่ Sale ยืนยัน"></iframe></div><div v-if="quotationReview.authorizationUrl"><h3>Draft หนังสือมอบอำนาจ</h3><iframe :src="quotationReview.authorizationUrl" title="Draft หนังสือมอบอำนาจ"></iframe></div></div>
          <footer><span>{{ quotationReview.authorizationUrl ? 'หนังสือมอบอำนาจเป็น Draft สำหรับตรวจเนื้อหาเท่านั้น ไม่มีลายเซ็นอิเล็กทรอนิกส์' : 'รายการนี้ไม่ได้เลือกใช้หนังสือมอบอำนาจ' }}</span><div><button class="secondary-button" type="button" @click="downloadFile(`/api/v1/bills/${quotationReview.bill.bill_id}/approval-quotation`, quotationReview.bill.quotation_document_version?.filename)">ดาวน์โหลดใบเสนอราคา</button><button v-if="quotationReview.authorizationUrl" class="secondary-button" type="button" @click="downloadFile(`/api/v1/bills/${quotationReview.bill.bill_id}/approval-power-of-attorney`, quotationReview.bill.quotation_document_version?.power_of_attorney?.filename)">ดาวน์โหลด Draft หนังสือมอบอำนาจ</button></div></footer>
        </section>
      </div>
    </Teleport>
  </AppShell>
</template>

<style scoped>
.approval-document-overlay{position:fixed;inset:0;z-index:2200;display:grid;place-items:center;padding:18px;background:rgba(15,23,42,.72)}
.approval-document-dialog{display:grid;grid-template-rows:auto minmax(0,1fr) auto;width:min(1500px,98vw);height:min(94vh,1080px);overflow:hidden;border-radius:16px;background:#fff;box-shadow:0 28px 90px rgba(0,0,0,.35)}
.approval-document-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;min-height:0;padding:12px}.approval-document-grid>div{display:grid;grid-template-rows:auto minmax(0,1fr);min-width:0;min-height:0}.approval-document-grid h3{margin:0 0 8px;font-size:15px}.approval-document-dialog footer>div{display:flex;flex-wrap:wrap;gap:8px}
.approval-document-grid--single{grid-template-columns:1fr}
.approval-document-checklist{display:flex;flex-wrap:wrap;align-items:center;gap:7px;width:100%;padding:10px 12px;border:1px solid #dbe3ec;border-radius:10px;background:#f8fafc}.approval-document-checklist strong{width:100%;font-size:.78rem}.approval-document-checklist span{padding:4px 8px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:.72rem}
.approval-document-dialog header,.approval-document-dialog footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 16px}.approval-document-dialog header{border-bottom:1px solid var(--line)}.approval-document-dialog footer{border-top:1px solid var(--line)}.approval-document-dialog h2{margin:2px 0}.approval-document-dialog small{display:block;max-width:820px;overflow-wrap:anywhere;color:#64748b}.approval-document-dialog iframe{width:100%;height:100%;border:0;background:#e5e7eb}
@media(max-width:640px){.approval-document-overlay{padding:0}.approval-document-dialog{width:100vw;height:100dvh;border-radius:0}.approval-document-dialog header,.approval-document-dialog footer{align-items:flex-start;flex-direction:column}.approval-document-dialog footer button{width:100%}}
@media(max-width:900px){.approval-document-grid{grid-template-columns:1fr;overflow-y:auto}.approval-document-grid>div{height:65vh}}
</style>
