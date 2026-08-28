<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord, Paginated, Quotation } from '../types/api'
import { apiError, money, shortDate, todayIso } from '../utils/format'

const tab = ref<'quotations' | 'bills'>('quotations')
const quotations = ref<Paginated<Quotation> | null>(null)
const bills = ref<Paginated<BillingRecord> | null>(null)
const search = ref('')
const error = ref('')
const message = ref('')
const evidenceTypes = reactive<Record<number, 'signed_quotation' | 'customer_po'>>({})
const evidenceFiles = reactive<Record<number, File | null>>({})
let documentRefreshTimer: number | null = null
const blockedStatuses = ['ฉบับร่าง', 'รออนุมัติ', 'รอหัวหน้าอนุมัติ', 'รอแก้ไข']

async function loadQuotes(page = 1) {
  const response = await api.get<Paginated<Quotation>>('/api/v1/quotations', { params: { page, search: search.value || undefined, per_page: 25 } })
  quotations.value = response.data
}
async function loadBills(page = 1) {
  const response = await api.get<Paginated<BillingRecord>>('/api/v1/billing-records', { params: { page, search: search.value || undefined, per_page: 25 } })
  bills.value = response.data
}
async function load() {
  error.value = ''
  try { await Promise.all([loadQuotes(), loadBills()]) }
  catch (cause) { error.value = apiError(cause) }
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
  const query = mode === 'copy' ? '?copy=1' : mode === 'revised' ? '?revised=1' : ''
  return downloadFile(`/api/v1/quotations/${qt.qt_id}/pdf${query}`)
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
  await navigator.clipboard.writeText(link)
  message.value = 'คัดลอกลิงก์ให้ลูกค้าเรียบร้อยแล้ว'
}
function selectEvidenceFile(qt: Quotation, event: Event) {
  evidenceFiles[qt.qt_id] = (event.target as HTMLInputElement).files?.[0] || null
  evidenceTypes[qt.qt_id] ||= 'signed_quotation'
}
async function uploadCustomerEvidence(qt: Quotation) {
  const billId = qt.billing_record?.bill_id
  const file = evidenceFiles[qt.qt_id]
  if (!billId || !file) return
  error.value = ''
  message.value = ''
  try {
    const data = new FormData()
    data.append('file', file)
    const endpoint = evidenceTypes[qt.qt_id] === 'customer_po' ? 'purchase-order' : 'signed-quotation'
    await api.post(`/api/v1/bills/${billId}/${endpoint}`, data)
    message.value = 'เก็บหลักฐานต้นฉบับพร้อม SHA-256 และส่งรายการต่อ Admin แล้ว'
    evidenceFiles[qt.qt_id] = null
    await loadQuotes(quotations.value?.current_page || 1)
  } catch (cause) {
    error.value = apiError(cause)
  }
}
function billPdf(bill: BillingRecord, mode: 'original' | 'copy' | 'canceled' = 'original') {
  const query = mode === 'copy' ? '?copy=1' : mode === 'canceled' ? '?canceled=1' : ''
  return downloadFile(`/api/v1/bills/${bill.bill_id}/pdf${query}`)
}

onMounted(() => {
  load()
  documentRefreshTimer = window.setInterval(() => {
    if (!document.hidden) {
      Promise.all([
        loadQuotes(quotations.value?.current_page || 1),
        loadBills(bills.value?.current_page || 1),
      ]).catch((cause) => { error.value = apiError(cause, 'อัปเดตสถานะเอกสารไม่สำเร็จ') })
    }
  }, 15_000)
})
onBeforeUnmount(() => {
  if (documentRefreshTimer !== null) window.clearInterval(documentRefreshTimer)
})
</script>

<template>
  <AppShell title="ศูนย์จัดการเอกสาร" subtitle="พิมพ์ต้นฉบับ สำเนา และเอกสารยกเลิกจากข้อมูลใน PostgreSQL">
    <div class="tabs"><button :class="{ active: tab === 'quotations' }" @click="tab = 'quotations'">ใบเสนอราคา</button>
    <!-- <button :class="{ active: tab === 'bills' }" @click="tab = 'bills'">ใบกำกับภาษี / ใบขนส่ง</button> -->
  </div>
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <form class="content-card search-row" @submit.prevent="load"><label><span>ค้นหาเลขที่ ลูกค้า หรือผู้สร้าง</span><input v-model="search"></label><button class="secondary-button">ค้นหา</button></form>

    <section v-if="tab === 'quotations'" class="content-card section-gap">
      <div class="table-wrap"><table><thead><tr><th>เลขที่</th><th>Revision</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>ยอดรวม</th><th>สถานะ</th><th>ดาวน์โหลด / หลักฐานลูกค้า</th></tr></thead><tbody><tr v-for="qt in quotations?.data" :key="qt.qt_id"><td class="strong">{{ qt.qt_no }}</td><td>R{{ qt.revision_no || 1 }}</td><td>{{ shortDate(qt.billing_record?.bill_date || qt.qt_date) }}</td><td>{{ qt.billing_record?.buyer_name || qt.buyer_name }}</td><td>{{ qt.created_by }}</td><td>{{ money(qt.billing_record?.total_amount || qt.total_amount) }}</td><td><StatusPill :status="quotationStatus(qt)" /></td><td class="actions"><button class="primary-button primary-button--compact" type="button" @click="quotePdf(qt)">ต้นฉบับ</button><button class="secondary-button" type="button" @click="quotePdf(qt, 'copy')">สำเนา</button><button class="secondary-button" type="button" @click="quotePdf(qt, 'revised')">ใบเสนอราคาที่ได้รับการแก้ไข</button><button v-if="qt.billing_record?.internal_approval_exists" class="secondary-button" type="button" @click="approvalCertificate(qt)">ใบรับรองการอนุมัติ</button><button v-if="quotationStatus(qt) === 'รอลูกค้ายืนยัน' && acceptanceLink(qt)" class="primary-button primary-button--compact" type="button" @click="copyAcceptanceLink(qt)">คัดลอกลิงก์ให้ลูกค้าเซ็น</button><a v-if="quotationStatus(qt) === 'รอลูกค้ายืนยัน' && acceptanceLink(qt)" class="text-link" :href="acceptanceLink(qt)" target="_blank">เปิดหน้าลูกค้า</a><button v-if="qt.billing_record?.customer_acceptance_used_at || currentDocument(qt, 'signed_quotation')" class="secondary-button" type="button" @click="downloadFile(`/api/v1/bills/${qt.billing_record?.bill_id}/signed-quotation`, currentDocument(qt, 'signed_quotation')?.file_name)">ดาวน์โหลดใบที่ลูกค้าเซ็น</button><button v-if="currentDocument(qt, 'customer_po')" class="secondary-button" type="button" @click="downloadFile(`/api/v1/bills/${qt.billing_record?.bill_id}/purchase-order`, currentDocument(qt, 'customer_po')?.file_name)">ดาวน์โหลด PO</button><div v-if="quotationStatus(qt) === 'รอลูกค้ายืนยัน'" class="evidence-upload"><select v-model="evidenceTypes[qt.qt_id]"><option value="signed_quotation">ใบเสนอราคาที่ลูกค้าลงชื่อ</option><option value="customer_po">PO ต้นฉบับจากลูกค้า</option></select><input type="file" accept=".pdf,.png,.jpg,.jpeg,.xlsx" @change="selectEvidenceFile(qt, $event)"><button class="primary-button primary-button--compact" type="button" :disabled="!evidenceFiles[qt.qt_id]" @click="uploadCustomerEvidence(qt)">เก็บหลักฐานและส่ง Admin</button></div></td></tr><tr v-if="!quotations?.data.length"><td colspan="8" class="empty-cell">ไม่พบใบเสนอราคา</td></tr></tbody></table></div>
      <div v-if="quotations" class="pagination"><span>ทั้งหมด {{ quotations.total }} รายการ</span><div><button class="secondary-button" :disabled="!quotations.prev_page_url" @click="loadQuotes(quotations.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ quotations.current_page }} / {{ quotations.last_page }}</span><button class="secondary-button" :disabled="!quotations.next_page_url" @click="loadQuotes(quotations.current_page + 1)">ถัดไป</button></div></div>
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

  </AppShell>
</template>
