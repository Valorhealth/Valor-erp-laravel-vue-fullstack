<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import QuotationDownloadDialog from '../components/QuotationDownloadDialog.vue'
import { auth } from '../stores/auth'
import type { BillingRecord, CreditRequest, Paginated, Quotation } from '../types/api'
import { apiError, money, shortDate, todayIso } from '../utils/format'
import { copyText } from '../utils/browser'
import { preferredRowsPerPage } from '../utils/preferences'

const tab = ref<'quotations' | 'authorizations' | 'bills' | 'purchase-orders' | 'credit-documents'>('quotations')
const canManageAuthorizations = computed(() => auth.can('document_preparation_queue') || auth.can('system_admin'))
const router = useRouter()
const authorizations = ref<Paginated<Quotation> | null>(null)
const authorizationPreview = ref<string | null>(null)
const purchaseOrders = ref<Paginated<BillingRecord> | null>(null)
const downloadingPo = ref<number | null>(null)
const quotations = ref<Paginated<Quotation> | null>(null)
const quotationDownload = ref<{ id?: number; billId?: number; mode: 'original' | 'copy' | 'revised' } | null>(null)
const bills = ref<Paginated<BillingRecord> | null>(null)
const creditRequests = ref<Paginated<CreditRequest> | null>(null)
const downloadingCreditDocument = ref<number | null>(null)
const search = ref('')
const error = ref('')
const message = ref('')
const evidenceTypes = reactive<Record<number, 'signed_quotation' | 'customer_po'>>({})
const evidenceFiles = reactive<Record<number, File | null>>({})
const poNumberDialog = ref<Quotation | null>(null)
const poNumber = ref('')
const evidenceUploading = ref(false)
const blockedStatuses = ['ฉบับร่าง', 'รออนุมัติ', 'รอหัวหน้าอนุมัติ', 'รอแก้ไข']
async function loadQuotes(page = 1) {
  const response = await api.get<Paginated<Quotation>>('/api/v1/quotations', { params: { page, search: search.value || undefined, per_page: preferredRowsPerPage(25) } })
  quotations.value = response.data
}
async function loadAuthorizations(page = 1) {
  const response = await api.get<Paginated<Quotation>>('/api/v1/quotations', {
    params: { page, search: search.value || undefined, has_power_of_attorney: 1, per_page: preferredRowsPerPage(25) },
  })
  authorizations.value = response.data
}
async function previewAuthorization(billId: number) {
  error.value = ''
  try {
    const response = await api.get<Blob>(`/api/v1/bills/${billId}/power-of-attorney-preview`, { responseType: 'blob' })
    closeAuthorizationPreview()
    authorizationPreview.value = URL.createObjectURL(response.data)
  } catch (cause) { error.value = apiError(cause, 'เปิดหนังสือมอบอำนาจไม่สำเร็จ') }
}
async function downloadAuthorization(qt: Quotation) {
  const billId = qt.billing_record?.bill_id
  if (!billId) return
  error.value = ''
  try {
    await downloadFile(`/api/v1/bills/${billId}/power-of-attorney`, qt.billing_record?.quotation_document_version?.power_of_attorney?.filename)
  } catch (cause) { error.value = apiError(cause, 'ดาวน์โหลดหนังสือมอบอำนาจไม่สำเร็จ') }
}
function closeAuthorizationPreview() {
  if (authorizationPreview.value) URL.revokeObjectURL(authorizationPreview.value)
  authorizationPreview.value = null
}
async function loadBills(page = 1) {
  const response = await api.get<Paginated<BillingRecord>>('/api/v1/billing-records', { params: { page, search: search.value || undefined, per_page: preferredRowsPerPage(25) } })
  bills.value = response.data
}
async function loadCreditRequests(page = 1) {
  const response = await api.get<Paginated<CreditRequest>>('/api/v1/credit-requests', {
    params: { page, search: search.value || undefined, per_page: preferredRowsPerPage(25) },
  })
  creditRequests.value = response.data
}
async function load() {
  error.value = ''
  try {
    if (tab.value === 'quotations') await loadQuotes()
    else if (tab.value === 'authorizations' && canManageAuthorizations.value) await loadAuthorizations()
    else if (tab.value === 'bills') await loadBills()
    else if (tab.value === 'purchase-orders') await loadPurchaseOrders()
    else if (tab.value === 'credit-documents') await loadCreditRequests()
  }
  catch (cause) { error.value = apiError(cause) }
}
async function downloadCreditDocument(request: CreditRequest, document: NonNullable<CreditRequest['documents']>[number]) {
  downloadingCreditDocument.value = document.doc_id
  error.value = ''
  try {
    await downloadFile(`/api/v1/credit-requests/${request.request_id}/documents/${document.doc_id}`, document.file_name)
  } catch (cause) {
    error.value = apiError(cause, 'ดาวน์โหลดเอกสารวงเงินไม่สำเร็จ')
  } finally {
    downloadingCreditDocument.value = null
  }
}
async function loadPurchaseOrders(page = 1) {
  try {
    const response = await api.get<Paginated<BillingRecord>>('/api/v1/billing-records', { params: { page, has_po: 1, search: search.value || undefined, per_page: preferredRowsPerPage(25) } })
    purchaseOrders.value = response.data
  } catch (cause) { error.value = apiError(cause, 'โหลดประวัติ PO ไม่สำเร็จ') }
}
async function downloadPo(bill: BillingRecord) {
  downloadingPo.value = bill.bill_id
  error.value = ''
  try {
    await downloadFile(`/api/v1/bills/${bill.bill_id}/purchase-order`, bill.documents?.find(document => document.document_type === 'customer_po')?.file_name)
  } catch (cause) { error.value = apiError(cause, 'ดาวน์โหลด PO ไม่สำเร็จ') }
  finally { downloadingPo.value = null }
}
function canIssue(bill: BillingRecord) {
  return !blockedStatuses.includes(bill.status || '') && (!bill.bill_date || bill.bill_date <= todayIso())
}
function issueReason(bill: BillingRecord) {
  if (blockedStatuses.includes(bill.status || '')) return 'บิลยังไม่ผ่านการอนุมัติ'
  if (bill.bill_date && bill.bill_date > todayIso()) return 'ยังไม่ถึงวันที่ของบิล'
  return ''
}
function quotationStatus(qt: Quotation) {
  return qt.billing_record?.status || qt.status || '-'
}
function quotePdf(qt: Quotation, mode: 'original' | 'copy' | 'revised' = 'original') {
  const confirmedTemplate = qt.billing_record?.quotation_document_version?.template_id
  if (confirmedTemplate) {
    router.push({ name: 'quotation-editor', params: { quotationId: qt.qt_id }, query: { template: confirmedTemplate, mode } })
    return
  }
  if (!auth.can('sales_documents')) {
    error.value = 'Sale ยังไม่ได้ยืนยันรูปแบบใบเสนอราคา จึงยังไม่สามารถเปิดเอกสารได้'
    return
  }
  quotationDownload.value = { id: qt.qt_id, mode }
}
function printPoQuotation(bill: BillingRecord) {
  const confirmedTemplate = bill.quotation_document_version?.template_id
  if (bill.ref_qt_id && confirmedTemplate) {
    router.push({ name: 'quotation-editor', params: { quotationId: bill.ref_qt_id }, query: { template: confirmedTemplate, mode: 'original' } })
    return
  }
  if (bill.ref_qt_id && !auth.can('sales_documents')) {
    error.value = 'Sale ยังไม่ได้ยืนยันรูปแบบใบเสนอราคา จึงยังไม่สามารถเปิดเอกสารได้'
    return
  }
  quotationDownload.value = bill.ref_qt_id
    ? { id: bill.ref_qt_id, mode: 'original' }
    : { billId: bill.bill_id, mode: 'original' }
}
function closeQuotationDialog() {
  quotationDownload.value = null
}
function approvalCertificate(qt: Quotation) {
  if (!qt.billing_record?.bill_id) return
  return downloadFile(`/api/v1/bills/${qt.billing_record.bill_id}/approval-certificate`)
}
function currentDocument(qt: Quotation, type: string) {
  return qt.billing_record?.documents?.find((document) => document.document_type === type)
}
function acceptanceLink(qt: Quotation) {
  const token = qt.billing_record?.customer_acceptance_token
  return token ? `${window.location.origin}/quotation/accept/${token}` : ''
}
async function copyAcceptanceLink(qt: Quotation) {
  const link = acceptanceLink(qt)
  if (!link) return
  try {
    await copyText(link)
    message.value = 'คัดลอกลิงก์ให้ลูกค้าเรียบร้อยแล้ว'
  } catch {
    error.value = 'Browser ไม่อนุญาตให้คัดลอกอัตโนมัติ กรุณาเปิดลิงก์แล้วคัดลอกจาก Address Bar'
  }
}
function selectEvidenceFile(qt: Quotation, event: Event) {
  evidenceFiles[qt.qt_id] = (event.target as HTMLInputElement).files?.[0] || null
  evidenceTypes[qt.qt_id] ||= 'signed_quotation'
}
async function uploadCustomerEvidence(qt: Quotation) {
  if (evidenceTypes[qt.qt_id] === 'customer_po') {
    poNumber.value = String(qt.billing_record?.customer_po_no || qt.customer_po_no || '')
    poNumberDialog.value = qt
    return
  }
  await storeCustomerEvidence(qt)
}
async function storeCustomerEvidence(qt: Quotation, customerPoNumber = '') {
  const billId = qt.billing_record?.bill_id
  const file = evidenceFiles[qt.qt_id]
  if (!billId || !file) return
  if (evidenceTypes[qt.qt_id] === 'customer_po' && !customerPoNumber.trim()) {
    error.value = 'กรุณาระบุเลขที่ใบ PO ของลูกค้า'
    return
  }
  error.value = ''
  message.value = ''
  evidenceUploading.value = true
  try {
    const data = new FormData()
    data.append('file', file)
    if (evidenceTypes[qt.qt_id] === 'customer_po') data.append('customer_po_no', customerPoNumber.trim())
    const endpoint = evidenceTypes[qt.qt_id] === 'customer_po' ? 'purchase-order' : 'signed-quotation'
    await api.post(`/api/v1/bills/${billId}/${endpoint}`, data)
    message.value = 'เก็บหลักฐานต้นฉบับพร้อม SHA-256 และส่งรายการต่อ Admin แล้ว'
    evidenceFiles[qt.qt_id] = null
    poNumberDialog.value = null
    poNumber.value = ''
    await loadQuotes(quotations.value?.current_page || 1)
  } catch (cause) {
    error.value = apiError(cause)
  } finally {
    evidenceUploading.value = false
  }
}
async function confirmPoEvidence() {
  if (!poNumberDialog.value || !poNumber.value.trim()) return
  await storeCustomerEvidence(poNumberDialog.value, poNumber.value)
}
function billPdf(bill: BillingRecord, mode: 'original' | 'copy' | 'canceled' = 'original') {
  const query = mode === 'copy' ? '?copy=1' : mode === 'canceled' ? '?canceled=1' : ''
  return downloadFile(`/api/v1/bills/${bill.bill_id}/pdf${query}`)
}

onMounted(load)
onBeforeUnmount(() => {
  closeAuthorizationPreview()
})
</script>

<template>
  <AppShell title="ศูนย์จัดการเอกสาร" subtitle="ดาวน์โหลดใบเสนอราคา หนังสือมอบอำนาจ หลักฐาน PO และเอกสารประกอบการขอวงเงินย้อนหลัง">
    <div class="tabs"><button :class="{ active: tab === 'quotations' }" @click="tab = 'quotations'; loadQuotes()">ใบเสนอราคา</button>
    <button v-if="canManageAuthorizations" :class="{ active: tab === 'authorizations' }" @click="tab = 'authorizations'; loadAuthorizations()">หนังสือมอบอำนาจ</button>
    <!-- <button :class="{ active: tab === 'bills' }" @click="tab = 'bills'">ใบกำกับภาษี / ใบขนส่ง</button> -->
    <button :class="{ active: tab === 'purchase-orders' }" @click="tab = 'purchase-orders'; loadPurchaseOrders()">ประวัติ PO</button>
    <button :class="{ active: tab === 'credit-documents' }" @click="tab = 'credit-documents'; loadCreditRequests()">เอกสารวงเงิน</button>
  </div>
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <form class="content-card search-row" @submit.prevent="load"><label><span>ค้นหาเลขใบเสนอราคา เลขรายการในระบบ เลข PO ลูกค้า หรือผู้สร้าง</span><input v-model="search" placeholder="เช่น QT-2610-0001, #123 หรือเลข PO"></label><button class="secondary-button">ค้นหา</button><button class="secondary-button" type="button" @click="load">รีเฟรชรายการ</button></form>

    <section v-if="tab === 'quotations'" class="content-card section-gap">
      <div class="table-wrap"><table>
        <thead><tr><th>เลขใบเสนอราคา</th><th>เลขรายการในระบบ</th><th>เลข PO</th><th>Revision</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>ยอดรวม</th><th>สถานะ</th><th>ดาวน์โหลด / หลักฐานลูกค้า</th></tr></thead>
        <tbody>
          <tr v-for="qt in quotations?.data" :key="qt.qt_id">
            <td class="strong">{{ qt.qt_no || '-' }}</td><td>{{ qt.billing_record?.bill_id ? `#${qt.billing_record.bill_id}` : '-' }}</td><td>{{ qt.customer_po_no || '-' }}</td><td>R{{ qt.revision_no || 1 }}</td><td>{{ shortDate(qt.workflow_type === 'customer_po_direct' ? qt.qt_date : (qt.billing_record?.bill_date || qt.qt_date)) }}</td><td>{{ qt.billing_record?.buyer_name || qt.buyer_name }}</td><td>{{ qt.created_by }}</td><td>{{ money(qt.billing_record?.total_amount || qt.total_amount) }}</td><td><StatusPill :status="quotationStatus(qt)" /></td>
<td><details class="document-download-menu"><summary>ดาวน์โหลดเอกสาร</summary><div class="document-download-options"><button class="primary-button primary-button--compact" type="button" @click="quotePdf(qt)">ต้นฉบับ</button><button class="secondary-button" type="button" @click="quotePdf(qt, 'copy')">สำเนา</button><button v-if="qt.billing_record?.internal_approval_exists" class="secondary-button" type="button" @click="approvalCertificate(qt)">ใบรับรองการอนุมัติ</button><button v-if="qt.billing_record?.customer_acceptance_used_at || currentDocument(qt, 'signed_quotation')" class="secondary-button" type="button" @click="downloadFile(`/api/v1/bills/${qt.billing_record?.bill_id}/signed-quotation`, currentDocument(qt, 'signed_quotation')?.file_name)">ดาวน์โหลดใบที่ลูกค้าเซ็น</button><button v-if="currentDocument(qt, 'customer_po')" class="secondary-button" type="button" @click="downloadFile(`/api/v1/bills/${qt.billing_record?.bill_id}/purchase-order`, currentDocument(qt, 'customer_po')?.file_name)">ดาวน์โหลด PO</button></div></details><button v-if="auth.can('edit_bill') && quotationStatus(qt) === 'รอลูกค้ายืนยัน' && acceptanceLink(qt)" class="primary-button primary-button--compact" type="button" @click="copyAcceptanceLink(qt)">คัดลอกลิงก์ให้ลูกค้าเซ็น</button><a v-if="auth.can('edit_bill') && quotationStatus(qt) === 'รอลูกค้ายืนยัน' && acceptanceLink(qt)" class="text-link" :href="acceptanceLink(qt)" target="_blank">เปิดหน้าลูกค้า</a><div v-if="auth.can('edit_bill') && quotationStatus(qt) === 'รอลูกค้ายืนยัน'" class="evidence-upload"><select v-model="evidenceTypes[qt.qt_id]"><option value="signed_quotation">ใบเสนอราคาที่ลูกค้าลงชื่อ</option><option value="customer_po">PO ต้นฉบับจากลูกค้า</option></select><input type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx" @change="selectEvidenceFile(qt, $event)"><button class="primary-button primary-button--compact" type="button" :disabled="!evidenceFiles[qt.qt_id]" @click="uploadCustomerEvidence(qt)">เก็บหลักฐานและส่ง Admin</button></div></td>
          </tr>
          <tr v-if="!quotations?.data.length"><td colspan="10" class="empty-cell">ไม่พบใบเสนอราคา</td></tr>
        </tbody>
      </table></div>
      <div v-if="quotations" class="pagination"><span>ทั้งหมด {{ quotations.total }} รายการ</span><div><button class="secondary-button" :disabled="!quotations.prev_page_url" @click="loadQuotes(quotations.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ quotations.current_page }} / {{ quotations.last_page }}</span><button class="secondary-button" :disabled="!quotations.next_page_url" @click="loadQuotes(quotations.current_page + 1)">ถัดไป</button></div></div>
    </section>

    <section v-else-if="tab === 'authorizations'" class="content-card section-gap">
      <p>หนังสือมอบอำนาจเป็น Draft ที่ออกคู่กับใบเสนอราคา Revision เดียวกัน ใช้ตรวจสอบเนื้อหาและนำไปดำเนินการลงลายมือชื่อจริงภายนอกระบบ</p>
      <div class="table-wrap"><table>
        <thead><tr><th>เลขหนังสือ</th><th>เลขใบเสนอราคา</th><th>Revision</th><th>โรงพยาบาล / ลูกค้า</th><th>ผู้แทนขาย</th><th>สถานะ</th><th>เอกสาร</th></tr></thead>
        <tbody>
          <tr v-for="qt in authorizations?.data" :key="qt.qt_id">
            <td class="strong">{{ qt.billing_record?.quotation_document_version?.power_of_attorney?.authorization_no || '-' }}</td>
            <td>{{ qt.qt_no || '-' }}</td><td>R{{ qt.revision_no || 1 }}</td><td>{{ qt.billing_record?.buyer_name || qt.buyer_name }}</td><td>{{ qt.created_by }}</td><td><StatusPill :status="quotationStatus(qt)" /></td>
            <td><div class="po-document-actions"><a class="secondary-button" :href="`/quotations/${qt.qt_id}/editor?template=${encodeURIComponent(qt.billing_record?.quotation_document_version?.template_id || 'standard')}&mode=original`">แก้ไขและ Preview</a><button class="secondary-button" type="button" @click="previewAuthorization(qt.billing_record!.bill_id)">ดูตัวอย่าง</button><button class="primary-button primary-button--compact" type="button" @click="downloadAuthorization(qt)">ดาวน์โหลด Draft</button></div><small class="muted">ไม่มีลายเซ็นอิเล็กทรอนิกส์ · ต้องลงลายมือชื่อจริงก่อนใช้งาน</small></td>
          </tr>
          <tr v-if="!authorizations?.data.length"><td colspan="7" class="empty-cell">ไม่พบหนังสือมอบอำนาจตามคำค้นหา</td></tr>
        </tbody>
      </table></div>
      <div v-if="authorizations" class="pagination"><span>ทั้งหมด {{ authorizations.total }} รายการ</span><div><button class="secondary-button" :disabled="!authorizations.prev_page_url" @click="loadAuthorizations(authorizations.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ authorizations.current_page }} / {{ authorizations.last_page }}</span><button class="secondary-button" :disabled="!authorizations.next_page_url" @click="loadAuthorizations(authorizations.current_page + 1)">ถัดไป</button></div></div>
    </section>

    <section v-else-if="tab === 'purchase-orders'" class="content-card section-gap">
      <p>PO ที่แนบกับรายการย้อนหลังทุกสถานะ รวมทั้งรายการที่เปิดจาก PO โดยตรง ดาวน์โหลดไฟล์ฉบับปัจจุบันของรายการได้</p>
      <div class="table-wrap"><table>
        <thead><tr><th>เลขใบเสนอราคา</th><th>เลขรายการในระบบ</th><th>เลข PO</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>สถานะ</th><th>เอกสาร</th></tr></thead>
        <tbody>
          <tr v-for="bill in purchaseOrders?.data" :key="bill.bill_id">
            <td class="strong">{{ bill.quotation?.qt_no || (bill.ref_qt_id ? `QT #${bill.ref_qt_id}` : 'ยังไม่มี') }}</td><td>#{{ bill.bill_id }}</td><td>{{ bill.customer_po_no || '-' }}</td><td>{{ shortDate(bill.bill_date) }}</td>
            <td>{{ bill.buyer_name }}</td><td>{{ bill.created_by }}</td><td><StatusPill :status="bill.status" /></td>
            <td><div v-for="document in bill.documents" :key="document.doc_id"><small>{{ document.file_name }} · ฉบับ {{ document.version_no || 1 }}</small></div>
              <div class="po-document-actions"><button type="button" class="secondary-button" :disabled="downloadingPo !== null" @click="downloadPo(bill)">{{ downloadingPo === bill.bill_id ? 'กำลังดาวน์โหลด…' : 'ดาวน์โหลด PO' }}</button><button v-if="bill.ref_qt_id || bill.workflow_type === 'customer_po_direct'" type="button" class="primary-button primary-button--compact" @click="printPoQuotation(bill)">พิมพ์ใบเสนอราคา</button></div>
            </td>
          </tr>
          <tr v-if="!purchaseOrders?.data.length"><td colspan="8" class="empty-cell">ไม่พบรายการที่มี PO ตามคำค้นหา</td></tr>
        </tbody>
      </table></div>
      <div v-if="purchaseOrders" class="pagination"><span>ทั้งหมด {{ purchaseOrders.total }} รายการ</span><div>
        <button class="secondary-button" :disabled="!purchaseOrders.prev_page_url" @click="loadPurchaseOrders(purchaseOrders.current_page - 1)">ก่อนหน้า</button>
        <span>หน้า {{ purchaseOrders.current_page }} / {{ purchaseOrders.last_page }}</span>
        <button class="secondary-button" :disabled="!purchaseOrders.next_page_url" @click="loadPurchaseOrders(purchaseOrders.current_page + 1)">ถัดไป</button>
      </div></div>
    </section>

    <section v-else-if="tab === 'credit-documents'" class="content-card section-gap">
      <p>เอกสารประกอบคำขอเปิดหรือแก้ไขวงเงินทุกสถานะ โดย Sale จะเห็นเฉพาะคำขอของตนเอง</p>
      <div class="table-wrap"><table>
        <thead><tr><th>คำขอ</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>วงเงินที่ขอ</th><th>Credit Term</th><th>สถานะ</th><th>เอกสาร</th></tr></thead>
        <tbody>
          <tr v-for="request in creditRequests?.data" :key="request.request_id">
            <td class="strong">#{{ request.request_id }}</td>
            <td>{{ shortDate(request.created_at) }}</td>
            <td>{{ request.company_name }}</td>
            <td>{{ request.created_by || '-' }}</td>
            <td>{{ money(request.requested_credit_limit) }}</td>
            <td>{{ request.requested_credit_term }} วัน</td>
            <td><StatusPill :status="request.status" /></td>
            <td>
              <details v-if="request.documents?.length" class="document-download-menu">
                <summary>ดาวน์โหลดเอกสาร ({{ request.documents.length }})</summary>
                <div class="document-download-options">
                  <button
                    v-for="document in request.documents"
                    :key="document.doc_id"
                    type="button"
                    class="secondary-button"
                    :disabled="downloadingCreditDocument !== null"
                    @click="downloadCreditDocument(request, document)"
                  >
                    {{ downloadingCreditDocument === document.doc_id ? 'กำลังดาวน์โหลด…' : document.doc_type }}
                  </button>
                </div>
              </details>
              <span v-else class="muted">ไม่มีเอกสารแนบ</span>
            </td>
          </tr>
          <tr v-if="!creditRequests?.data.length"><td colspan="8" class="empty-cell">ไม่พบเอกสารวงเงินตามคำค้นหา</td></tr>
        </tbody>
      </table></div>
      <div v-if="creditRequests" class="pagination"><span>ทั้งหมด {{ creditRequests.total }} รายการ</span><div>
        <button class="secondary-button" :disabled="!creditRequests.prev_page_url" @click="loadCreditRequests(creditRequests.current_page - 1)">ก่อนหน้า</button>
        <span>หน้า {{ creditRequests.current_page }} / {{ creditRequests.last_page }}</span>
        <button class="secondary-button" :disabled="!creditRequests.next_page_url" @click="loadCreditRequests(creditRequests.current_page + 1)">ถัดไป</button>
      </div></div>
    </section>

    <section v-else class="content-card section-gap">
      <div class="table-wrap"><table><thead><tr><th>Bill</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>ยอดรวม</th><th>สถานะ</th><th>Portal</th><th>ดาวน์โหลด</th></tr></thead><tbody>
        <tr v-for="bill in bills?.data" :key="bill.bill_id">
          <td class="strong">#{{ bill.bill_id }}</td><td>{{ shortDate(bill.bill_date) }}</td><td>{{ bill.buyer_name }}</td><td>{{ bill.created_by }}</td><td>{{ money(bill.total_amount) }}</td><td><StatusPill :status="bill.status" /></td>
          <td><a v-if="bill.tax_qr_token" class="text-link" :href="`/tax/${bill.tax_qr_token}`" target="_blank">เปิดลิงก์ลูกค้า</a><span v-else>-</span></td>
          <td class="actions"><button class="primary-button primary-button--compact" :disabled="!canIssue(bill)" :title="issueReason(bill)" @click="billPdf(bill)">ต้นฉบับ</button><button class="secondary-button" :disabled="!canIssue(bill)" :title="issueReason(bill)" @click="billPdf(bill, 'copy')">สำเนา</button><button v-if="['ยกเลิกบิล', 'ไม่อนุมัติ'].includes(bill.status || '') && bill.tax_invoice_no" class="danger-link" @click="billPdf(bill, 'canceled')">ยกเลิก</button></td>
        </tr>
        <tr v-if="!bills?.data.length"><td colspan="8" class="empty-cell">ไม่พบเอกสาร</td></tr>
      </tbody></table></div>
      <div v-if="bills" class="pagination"><span>ทั้งหมด {{ bills.total }} รายการ</span><div><button class="secondary-button" :disabled="!bills.prev_page_url" @click="loadBills(bills.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ bills.current_page }} / {{ bills.last_page }}</span><button class="secondary-button" :disabled="!bills.next_page_url" @click="loadBills(bills.current_page + 1)">ถัดไป</button></div></div>
    </section>

    <QuotationDownloadDialog v-if="quotationDownload" :quotation-id="quotationDownload.id" :bill-id="quotationDownload.billId" :mode="quotationDownload.mode" @close="closeQuotationDialog" />
    <Teleport to="body"><div v-if="poNumberDialog" class="po-number-overlay" @click.self="!evidenceUploading && (poNumberDialog = null)"><form class="po-number-dialog" role="dialog" aria-modal="true" aria-labelledby="po-number-title" @submit.prevent="confirmPoEvidence"><header><div><strong id="po-number-title">ยืนยันด้วย PO ของลูกค้า</strong><small>กรอกเลขที่ PO ก่อนจัดเก็บไฟล์หลักฐานและส่งรายการต่อ Admin</small></div><button class="secondary-button" type="button" :disabled="evidenceUploading" @click="poNumberDialog = null">ปิด</button></header><label><span>เลขที่ใบ PO ของลูกค้า *</span><input v-model="poNumber" maxlength="255" autocomplete="off" autofocus required placeholder="เช่น PO-2026-00123"></label><footer><button class="secondary-button" type="button" :disabled="evidenceUploading" @click="poNumberDialog = null">ยกเลิก</button><button class="primary-button" type="submit" :disabled="evidenceUploading || !poNumber.trim()">{{ evidenceUploading ? 'กำลังบันทึก…' : 'ยืนยันและส่งหลักฐาน' }}</button></footer></form></div></Teleport>
    <Teleport to="body"><div v-if="authorizationPreview" class="authorization-preview-overlay" @click.self="closeAuthorizationPreview"><section class="authorization-preview-dialog" role="dialog" aria-modal="true" aria-label="ตัวอย่างหนังสือมอบอำนาจ"><header><strong>ตัวอย่างหนังสือมอบอำนาจ</strong><button class="secondary-button" type="button" @click="closeAuthorizationPreview">ปิด</button></header><iframe :src="authorizationPreview" title="ตัวอย่างหนังสือมอบอำนาจ"></iframe></section></div></Teleport>
  </AppShell>
</template>

<style scoped>
.document-download-menu { min-width: 180px; }
.document-download-menu summary { cursor: pointer; padding: 10px 14px; border: 1px solid var(--line); border-radius: 8px; color: var(--brand); font-weight: 700; }
.document-download-menu summary:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
.document-download-options { display: grid; gap: 6px; padding-top: 8px; }
.document-download-options button { text-align: left; }
.po-document-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.po-number-overlay{position:fixed;inset:0;z-index:2300;display:grid;place-items:center;padding:18px;background:rgba(15,23,42,.68)}
.po-number-dialog{display:grid;gap:18px;width:min(520px,100%);padding:22px;border-radius:14px;background:#fff;box-shadow:0 24px 80px rgba(0,0,0,.3)}
.po-number-dialog header,.po-number-dialog footer{display:flex;align-items:center;justify-content:space-between;gap:12px}.po-number-dialog header div,.po-number-dialog label{display:grid;gap:5px}.po-number-dialog header strong{font-size:20px}.po-number-dialog header small{color:#64748b}.po-number-dialog input{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:8px}.po-number-dialog footer{justify-content:flex-end}
.authorization-preview-overlay{position:fixed;inset:0;z-index:2200;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.72)}
.authorization-preview-dialog{display:grid;grid-template-rows:auto minmax(0,1fr);width:min(940px,96vw);height:min(95vh,1100px);background:#fff;border-radius:12px;overflow:hidden}
.authorization-preview-dialog header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--line)}
.authorization-preview-dialog iframe{width:100%;height:100%;border:0}
</style>
