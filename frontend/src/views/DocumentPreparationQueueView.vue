<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { Paginated } from '../types/api'
import { apiError, shortDate } from '../utils/format'
import { preferredRowsPerPage } from '../utils/preferences'

type PreparationStatus = 'pending' | 'preparing' | 'prepared'
type PreparationItem = {
  preparation_item_id: number
  bill_id: number
  document_type: string
  status: PreparationStatus
  admin_note?: string | null
  requested_at?: string | null
  prepared_at?: string | null
  prepared_by_user?: { username: string; first_name?: string | null; last_name?: string | null } | null
}

type PreparationBill = {
  bill_id: number
  buyer_name: string
  created_by: string
  status: string
  submitted_at?: string | null
  quotation?: { qt_id: number; qt_no: string } | null
  document_preparation_items: PreparationItem[]
}

const queue = ref<Paginated<PreparationBill> | null>(null)
const notes = reactive<Record<number, string>>({})
const search = ref('')
const loading = ref(true)
const saving = reactive<Record<number, boolean>>({})
const error = ref('')
const message = ref('')
const labels: Record<string, string> = {
  quotation: 'ใบเสนอราคา', power_of_attorney: 'Draft หนังสือมอบอำนาจ', company_affidavit: 'หนังสือรับรองบริษัท',
  vat_registration: 'ภ.พ.20', grantor_id_copies: 'สำเนาบัตรผู้มอบอำนาจ', representative_id_copy: 'สำเนาบัตรผู้แทน',
  product_documents: 'เอกสารสินค้า / ใบอนุญาต / Catalog', other: 'เอกสารอื่นตามข้อกำหนด',
}
const documentCount = computed(() => queue.value?.data.reduce((total, bill) => total + bill.document_preparation_items.length, 0) || 0)

async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<Paginated<PreparationBill>>('/api/v1/document-preparation-queue', {
      params: { page, search: search.value || undefined, per_page: preferredRowsPerPage(25) },
    })
    queue.value = response.data
    response.data.data.forEach((bill) => bill.document_preparation_items.forEach((item) => {
      notes[item.preparation_item_id] = item.admin_note || ''
    }))
  } catch (cause) { error.value = apiError(cause, 'โหลดคิวจัดเตรียมเอกสารไม่สำเร็จ') }
  finally { loading.value = false }
}

async function update(item: PreparationItem, status = item.status) {
  if (saving[item.preparation_item_id]) return
  const previous = { status: item.status, prepared_at: item.prepared_at, prepared_by_user: item.prepared_by_user }
  saving[item.preparation_item_id] = true
  item.status = status
  if (status !== 'prepared') {
    item.prepared_at = null
    item.prepared_by_user = null
  }
  error.value = ''
  message.value = ''
  try {
    const response = await api.patch<{ data: PreparationItem }>(`/api/v1/document-preparation-queue/${item.preparation_item_id}`, {
      status,
      admin_note: notes[item.preparation_item_id] || null,
    })
    Object.assign(item, response.data.data)
    message.value = status === 'prepared' ? 'ทำเครื่องหมายว่าเอกสารพร้อมแล้ว' : 'ทำเครื่องหมายว่าเอกสารยังไม่พร้อมแล้ว'
  } catch (cause) {
    Object.assign(item, previous)
    error.value = apiError(cause, 'บันทึกสถานะเอกสารไม่สำเร็จ')
  } finally { delete saving[item.preparation_item_id] }
}

function download(item: PreparationItem) {
  if (item.document_type === 'quotation') return downloadFile(`/api/v1/bills/${item.bill_id}/approval-quotation`)
  if (item.document_type === 'power_of_attorney') return downloadFile(`/api/v1/bills/${item.bill_id}/power-of-attorney`)
}

function billReady(bill: PreparationBill): boolean {
  return bill.document_preparation_items.length > 0 && bill.document_preparation_items.every((item) => item.status === 'prepared')
}

onMounted(() => load())
</script>

<template>
  <AppShell title="คิวงานจัดเตรียมเอกสาร" subtitle="รายการเอกสารที่ Sale ร้องขอ เรียงงานล่าสุดก่อน เพื่อให้ Admin จัดเตรียมและส่งมอบได้ครบถ้วน">
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <form class="content-card queue-toolbar" @submit.prevent="load(1)">
      <label><span>ค้นหา Sale ลูกค้า เลขใบเสนอราคา หรือเลขรายการ</span><input v-model="search" placeholder="เช่น Anurak, โรงพยาบาล, QT-2610-0001 หรือ #61"></label>
      <button class="secondary-button">ค้นหา</button>
    </form>

    <section class="content-card section-gap">
      <div class="queue-summary"><div><h2>รายการที่ต้องจัดเตรียม</h2><p>หนึ่งกล่องต่อหนึ่งบิล กด ✓ เมื่อพร้อมส่งหรือพร้อมให้มารับ กด ✕ เมื่อยังไม่พร้อม</p></div><strong>{{ queue?.total || 0 }} บิล · {{ documentCount }} เอกสารในหน้านี้</strong></div>
      <div v-if="loading" class="empty-cell">กำลังโหลดคิวงาน…</div>
      <div v-else class="queue-list">
        <article v-for="bill in queue?.data" :key="bill.bill_id" :class="['bill-group', { ready: billReady(bill) }]">
          <header class="bill-header">
            <div class="bill-reference"><span>เลขที่ใบเสนอราคา</span><strong>{{ bill.quotation?.qt_no || `#${bill.bill_id}` }}</strong></div>
            <div class="bill-owner"><span>Sale</span><strong>{{ bill.created_by }}</strong></div>
            <div class="bill-customer"><span>ลูกค้า</span><strong>{{ bill.buyer_name }}</strong></div>
            <StatusPill :status="bill.status" />
            <div class="bill-count"><strong>{{ bill.document_preparation_items.length }}</strong><span>เอกสาร</span></div>
          </header>
          <div class="bill-documents">
            <div v-for="(item, index) in bill.document_preparation_items" :key="item.preparation_item_id" :class="['queue-row', { ready: item.status === 'prepared' }]">
              <span class="document-index">{{ index + 1 }}</span>
              <div class="request-document"><strong>{{ labels[item.document_type] || item.document_type }}</strong><span>{{ shortDate(item.requested_at || bill.submitted_at) }}</span><button v-if="['quotation', 'power_of_attorney'].includes(item.document_type)" class="text-link" type="button" @click="download(item)">ดาวน์โหลด</button></div>
              <label class="request-note"><span>หมายเหตุ</span><input v-model="notes[item.preparation_item_id]" maxlength="2000" placeholder="รายละเอียดการจัดเตรียมหรือจุดนัดรับ" @keyup.enter="update(item)"></label>
              <div class="quick-status" :aria-label="`สถานะ ${labels[item.document_type] || item.document_type}`">
                <button class="not-ready-button" type="button" :class="{ active: item.status !== 'prepared' }" :disabled="saving[item.preparation_item_id]" title="ยังไม่พร้อม" @click="update(item, 'pending')">✕<span>ยังไม่พร้อม</span></button>
                <button class="ready-button" type="button" :class="{ active: item.status === 'prepared' }" :disabled="saving[item.preparation_item_id]" title="พร้อมส่ง / พร้อมรับ" @click="update(item, 'prepared')">✓<span>พร้อมแล้ว</span></button>
                <button class="note-save-button" type="button" :disabled="saving[item.preparation_item_id]" @click="update(item)">{{ saving[item.preparation_item_id] ? 'กำลังบันทึก…' : 'บันทึกหมายเหตุ' }}</button>
              </div>
              <small v-if="item.prepared_at" class="prepared-by">เตรียมโดย {{ item.prepared_by_user?.first_name || item.prepared_by_user?.username || '-' }} · {{ shortDate(item.prepared_at) }}</small>
            </div>
          </div>
        </article>
        <p v-if="!queue?.data.length" class="empty-cell">ไม่มีบิลที่ต้องจัดเตรียมเอกสาร</p>
      </div>
      <div v-if="queue" class="pagination"><span>ทั้งหมด {{ queue.total }} บิล</span><div><button class="secondary-button" :disabled="!queue.prev_page_url" @click="load(queue.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ queue.current_page }} / {{ queue.last_page }}</span><button class="secondary-button" :disabled="!queue.next_page_url" @click="load(queue.current_page + 1)">ถัดไป</button></div></div>
    </section>
  </AppShell>
</template>

<style scoped>
.queue-toolbar{display:flex;align-items:end;gap:10px}.queue-toolbar label{display:grid;flex:1;gap:5px}.queue-summary{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}.queue-summary h2,.queue-summary p{margin:0}.queue-summary p{margin-top:4px;color:#64748b}.queue-summary>strong{padding:7px 12px;border-radius:999px;background:#eef2ff;color:#3730a3}.queue-list{display:grid;gap:16px}.bill-group{overflow:hidden;border:1px solid #cbd5e1;border-radius:12px;background:#fff;box-shadow:0 2px 7px #0f172a0a}.bill-group.ready{border-color:#86efac}.bill-header{display:grid;grid-template-columns:minmax(150px,.7fr) minmax(130px,.7fr) minmax(220px,1.4fr) auto auto;align-items:center;gap:16px;padding:14px 16px;border-bottom:1px solid #dbe3ec;background:#f8fafc}.bill-group.ready .bill-header{background:#f0fdf4}.bill-header>div:not(.status-pill){display:grid;gap:3px}.bill-header span{font-size:12px;color:#64748b}.bill-header strong{overflow-wrap:anywhere}.bill-count{justify-items:center;min-width:64px}.bill-count strong{font-size:22px;color:#334155}.bill-documents{display:grid}.queue-row{display:grid;grid-template-columns:28px minmax(200px,.8fr) minmax(260px,1.2fr) auto;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #e2e8f0;border-left:5px solid #ef4444;background:#fff}.queue-row:last-child{border-bottom:0}.queue-row.ready{border-left-color:#16a34a;background:#f8fff9}.document-index{display:grid;width:24px;height:24px;place-items:center;border-radius:50%;background:#e2e8f0;color:#475569;font-size:12px;font-weight:700}.request-document{display:grid;gap:3px}.request-document>span,.prepared-by{color:#64748b;font-size:12px}.request-document .text-link{width:max-content;padding:0;border:0;background:none;cursor:pointer}.request-note{display:grid;gap:4px}.request-note span{font-size:12px;color:#64748b}.request-note input{width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;background:#fff}.quick-status{display:grid;grid-template-columns:repeat(2,76px);gap:6px}.quick-status button{min-height:46px;border:1px solid var(--line);border-radius:9px;background:#fff;cursor:pointer;font-size:21px;font-weight:800}.quick-status button span{display:block;font-size:10px;font-weight:600}.not-ready-button{color:#dc2626}.not-ready-button.active{border-color:#ef4444;background:#fef2f2}.ready-button{color:#16a34a}.ready-button.active{border-color:#16a34a;background:#dcfce7}.quick-status .note-save-button{grid-column:1/-1;min-height:30px;color:#334155;font-size:11px;font-weight:600}.quick-status button:disabled{cursor:wait;opacity:.6}.prepared-by{grid-column:2/-1;text-align:right}@media(max-width:1100px){.bill-header{grid-template-columns:1fr 1fr 1.5fr}.bill-count{justify-items:start}.queue-row{grid-template-columns:28px 1fr auto}.request-note{grid-column:2/3}.quick-status{grid-column:3;grid-row:1/3}}@media(max-width:720px){.queue-toolbar,.queue-summary{align-items:stretch;flex-direction:column}.bill-header{grid-template-columns:1fr 1fr}.bill-customer{grid-column:1/-1}.queue-row{grid-template-columns:28px 1fr}.request-note,.quick-status,.prepared-by{grid-column:1/-1;grid-row:auto}.quick-status{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
