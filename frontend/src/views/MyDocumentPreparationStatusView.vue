<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { Paginated } from '../types/api'
import { apiError, shortDate } from '../utils/format'
import { preferredRowsPerPage } from '../utils/preferences'

type PreparationItem = {
  preparation_item_id: number
  document_type: string
  status: 'pending' | 'preparing' | 'prepared'
  admin_note?: string | null
  requested_at?: string | null
  prepared_at?: string | null
  prepared_by_user?: { username: string; first_name?: string | null; last_name?: string | null } | null
  attachment_document?: { doc_id: number; file_name: string; mime_type?: string | null; version_no: number; uploaded_by: string; uploaded_at: string } | null
}

type PreparationBill = {
  bill_id: number
  buyer_name: string
  status: string
  submitted_at?: string | null
  quotation?: { qt_id: number; qt_no: string } | null
  document_preparation_items: PreparationItem[]
}

const result = ref<Paginated<PreparationBill> | null>(null)
const search = ref('')
const loading = ref(true)
const error = ref('')

const labels: Record<string, string> = {
  quotation: 'ใบเสนอราคา', power_of_attorney: 'Draft หนังสือมอบอำนาจ', company_affidavit: 'หนังสือรับรองบริษัท',
  vat_registration: 'ภ.พ.20', grantor_id_copies: 'สำเนาบัตรผู้มอบอำนาจ', representative_id_copy: 'สำเนาบัตรผู้แทน',
  product_documents: 'เอกสารสินค้า / ใบอนุญาต / Catalog', other: 'เอกสารอื่นตามข้อกำหนด',
}

async function load(page = 1, background = false) {
  if (!background) loading.value = true
  error.value = ''
  try {
    const response = await api.get<Paginated<PreparationBill>>('/api/v1/my-document-preparation-status', {
      params: { page, search: search.value || undefined, per_page: preferredRowsPerPage(25) },
    })
    result.value = response.data
  } catch (cause) {
    error.value = apiError(cause, 'โหลดสถานะการจัดเตรียมเอกสารไม่สำเร็จ')
  } finally {
    if (!background) loading.value = false
  }
}

function readyCount(bill: PreparationBill): number {
  return bill.document_preparation_items.filter((item) => item.status === 'prepared').length
}

async function downloadPrepared(item: PreparationItem) {
  if (item.status !== 'prepared') return
  error.value = ''
  try {
    await downloadFile(`/api/v1/document-preparation-queue/${item.preparation_item_id}/attachment`, item.attachment_document?.file_name, 'get', undefined, true)
  } catch (cause) { error.value = apiError(cause, 'ดาวน์โหลดเอกสารไม่สำเร็จ') }
}

onMounted(load)
</script>

<template>
  <AppShell title="ติดตามสถานะเอกสาร" subtitle="ตรวจสอบเอกสารที่ Admin กำลังจัดเตรียมสำหรับบิลของคุณได้จากหน้านี้โดยตรง">
    <p v-if="error" class="form-error">{{ error }}</p>
    <form class="content-card tracking-toolbar" @submit.prevent="load(1)">
      <label><span>ค้นหาเลขใบเสนอราคา ลูกค้า หรือเลขรายการ</span><input v-model="search" placeholder="เช่น QT-2610-0001, โรงพยาบาล หรือ #61"></label>
      <button class="secondary-button">ค้นหา</button>
      <button class="secondary-button" type="button" @click="load(result?.current_page || 1)">รีเฟรชสถานะ</button>
    </form>

    <section class="content-card section-gap">
      <div class="tracking-summary"><div><h2>สถานะเอกสารที่ขอ</h2><p>✓ คือพร้อมส่งหรือพร้อมรับ · ✕ คืออยู่ระหว่างจัดเตรียม</p></div><strong>{{ result?.total || 0 }} บิล</strong></div>
      <div v-if="loading" class="empty-cell">กำลังโหลดสถานะเอกสาร…</div>
      <div v-else class="tracking-groups">
        <article v-for="bill in result?.data" :key="bill.bill_id" class="tracking-group">
          <header>
            <div><small>เลขที่ใบเสนอราคา</small><strong>{{ bill.quotation?.qt_no || `#${bill.bill_id}` }}</strong></div>
            <div class="customer"><small>ลูกค้า</small><strong>{{ bill.buyer_name }}</strong></div>
            <StatusPill :status="bill.status" />
            <span class="progress">พร้อม {{ readyCount(bill) }}/{{ bill.document_preparation_items.length }}</span>
          </header>
          <div class="tracking-items">
            <div v-for="item in bill.document_preparation_items" :key="item.preparation_item_id" class="tracking-item">
              <span :class="['tracking-mark', item.status === 'prepared' ? 'is-ready' : 'is-pending']">{{ item.status === 'prepared' ? '✓' : '✕' }}</span>
              <div class="document-name"><strong>{{ labels[item.document_type] || item.document_type }}</strong><small>ร้องขอ {{ shortDate(item.requested_at || bill.submitted_at) }}</small></div>
              <div class="tracking-result"><strong :class="item.status === 'prepared' ? 'ready-text' : 'pending-text'">{{ item.status === 'prepared' ? 'พร้อมส่ง / พร้อมรับ' : 'ยังไม่พร้อม' }}</strong><small v-if="item.admin_note">หมายเหตุจาก Admin: {{ item.admin_note }}</small><small v-if="item.attachment_document">ไฟล์: {{ item.attachment_document.file_name }}</small><small v-if="item.prepared_at">อัปเดต {{ shortDate(item.prepared_at) }}</small><button v-if="item.status === 'prepared' && (item.attachment_document || item.document_type === 'quotation')" class="secondary-button prepared-download" type="button" @click="downloadPrepared(item)">ดาวน์โหลดเอกสาร</button></div>
            </div>
          </div>
        </article>
        <p v-if="!result?.data.length" class="empty-cell">ยังไม่มีรายการเอกสารที่อยู่ระหว่างจัดเตรียม</p>
      </div>
      <div v-if="result" class="pagination"><span>ทั้งหมด {{ result.total }} บิล</span><div><button class="secondary-button" :disabled="!result.prev_page_url" @click="load(result.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ result.current_page }} / {{ result.last_page }}</span><button class="secondary-button" :disabled="!result.next_page_url" @click="load(result.current_page + 1)">ถัดไป</button></div></div>
    </section>
  </AppShell>
</template>

<style scoped>
.tracking-toolbar{display:flex;align-items:end;gap:10px}.tracking-toolbar label{display:grid;flex:1;gap:5px}.tracking-summary{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}.tracking-summary h2,.tracking-summary p{margin:0}.tracking-summary p{margin-top:4px;color:#64748b}.tracking-summary>strong{padding:7px 12px;border-radius:999px;background:#eef2ff;color:#3730a3}.tracking-groups{display:grid;gap:14px}.tracking-group{overflow:hidden;border:1px solid #cbd5e1;border-radius:11px;background:#fff}.tracking-group>header{display:grid;grid-template-columns:minmax(160px,.7fr) minmax(240px,1.4fr) auto auto;align-items:center;gap:16px;padding:13px 15px;border-bottom:1px solid #e2e8f0;background:#f8fafc}.tracking-group>header div{display:grid;gap:3px}.tracking-group small{color:#64748b}.progress{padding:6px 10px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:12px;font-weight:700}.tracking-items{display:grid}.tracking-item{display:grid;grid-template-columns:34px minmax(220px,1fr) minmax(240px,1.2fr);align-items:center;gap:12px;padding:12px 15px;border-bottom:1px solid #e2e8f0}.tracking-item:last-child{border-bottom:0}.tracking-mark{display:grid;width:30px;height:30px;place-items:center;border-radius:50%;font-size:18px;font-weight:800}.tracking-mark.is-ready{background:#dcfce7;color:#15803d}.tracking-mark.is-pending{background:#fee2e2;color:#dc2626}.document-name,.tracking-result{display:grid;gap:3px}.ready-text{color:#15803d}.pending-text{color:#b91c1c}.prepared-download{width:max-content;margin-top:5px;color:#166534;border-color:#86efac;background:#f0fdf4}@media(max-width:720px){.tracking-toolbar,.tracking-summary{align-items:stretch;flex-direction:column}.tracking-group>header,.tracking-item{grid-template-columns:1fr}.tracking-group>header .customer{grid-column:1}.tracking-mark{grid-row:1}.document-name,.tracking-result{grid-column:1}}
</style>
