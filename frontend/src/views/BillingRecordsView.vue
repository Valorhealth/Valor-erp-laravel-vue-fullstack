<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord, Paginated } from '../types/api'
import { money, shortDate } from '../utils/format'

const props = withDefaults(defineProps<{ pageTitle?: string; initialStatus?: string; statusGroup?: 'approved' | 'rejected' | ''; ownerOnly?: boolean }>(), {
  pageTitle: 'รายการบิล',
  initialStatus: '',
  statusGroup: '',
  ownerOnly: false,
})
const records = ref<Paginated<BillingRecord> | null>(null)
const search = ref('')
const status = ref(props.initialStatus)
const perPage = ref(25)
const expandedBillId = ref<number | null>(null)
const loading = ref(false)
const error = ref('')

const statuses = ['ฉบับร่าง', 'รอหัวหน้าอนุมัติ', 'รอลูกค้ายืนยัน', 'รอแอดมินตรวจสอบ', 'รอแก้ไข', 'เตรียมส่งออก', 'ดำเนินการเสร็จสิ้น', 'อนุมัติแล้ว', 'ไม่อนุมัติ', 'ยกเลิกบิล']
const groupFilterLabel = computed(() => props.statusGroup === 'approved' ? 'ทุกสถานะที่ผ่านการอนุมัติ' : props.statusGroup === 'rejected' ? 'รายการที่ถูกตีกลับ/ไม่อนุมัติ' : 'ทุกสถานะ')
const expandedBill = computed(() => records.value?.data.find((bill) => bill.bill_id === expandedBillId.value) ?? null)

async function load(page = 1) {
  loading.value = true
  error.value = ''
  expandedBillId.value = null
  try {
    const response = await api.get<Paginated<BillingRecord>>('/api/v1/billing-records', {
      params: { page, per_page: perPage.value, search: search.value || undefined, status: status.value || undefined, status_group: props.statusGroup || undefined, scope: props.ownerOnly ? 'mine' : 'all' },
    })
    records.value = response.data
  } catch {
    error.value = 'โหลดรายการบิลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  search.value = ''
  status.value = props.initialStatus
  load()
}

function toggleDetails(billId: number) {
  expandedBillId.value = expandedBillId.value === billId ? null : billId
}

onMounted(() => load())
watch(() => [props.initialStatus, props.statusGroup] as const, ([nextStatus]) => {
  status.value = nextStatus
  search.value = ''
  load()
})
</script>

<template>
  <AppShell :title="pageTitle" subtitle="ค้นหา กรอง และตรวจรายละเอียดข้อมูลจาก PostgreSQL โดยไม่รันหน้าเว็บใหม่ทั้งระบบ">
    <div class="metrics-grid">
      <article class="metric-card"><span>จำนวนบิลตามตัวกรอง</span><strong>{{ records?.summary?.total_bills ?? records?.total ?? 0 }}</strong></article>
      <article class="metric-card metric-card--primary"><span>ยอดเงินรวม</span><strong class="metric-money">{{ money(records?.summary?.total_amount ?? 0) }}</strong></article>
      <article class="metric-card"><span>ข้อมูลหน้าปัจจุบัน</span><strong>{{ records?.data.length ?? 0 }} รายการ</strong></article>
    </div>

    <section class="content-card section-gap">
      <form class="filter-bar" @submit.prevent="load(1)">
        <label><span>ค้นหา</span><input v-model="search" placeholder="ชื่อลูกค้า เลขภาษี หรือผู้สร้าง"></label>
        <label><span>สถานะ</span><select v-model="status" :disabled="Boolean(initialStatus || statusGroup)"><option value="">{{ groupFilterLabel }}</option><option v-for="item in statuses" :key="item">{{ item }}</option></select></label>
        <label><span>แถวต่อหน้า</span><select v-model.number="perPage" @change="load(1)"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label>
        <button class="primary-button primary-button--compact" type="submit">ค้นหา</button>
        <button class="secondary-button" type="button" @click="clearFilters">ล้างค่า</button>
      </form>

      <p v-if="error" class="form-error">{{ error }}</p>
      <div class="table-wrap" :class="{ 'is-loading': loading }">
        <table>
          <thead><tr><th>เลขที่</th><th>วันที่</th><th>ลูกค้า</th><th>Sale</th><th>ยอดรวม</th><th>สินค้า</th><th>สถานะ</th><th></th></tr></thead>
          <tbody>
            <tr v-for="bill in records?.data" :key="bill.bill_id">
              <td class="strong">#{{ bill.bill_id }}</td><td>{{ shortDate(bill.bill_date) }}</td><td>{{ bill.buyer_name || '-' }}</td><td>{{ bill.created_by || '-' }}</td>
              <td class="number">{{ money(bill.total_amount) }}</td><td>{{ bill.items?.length || 0 }} รายการ</td><td><StatusPill :status="bill.status" /></td>
              <td><button class="secondary-button" type="button" @click="toggleDetails(bill.bill_id)">{{ expandedBillId === bill.bill_id ? 'ปิด' : 'รายละเอียด' }}</button></td>
            </tr>
            <tr v-if="!loading && records?.data.length === 0"><td colspan="8" class="empty-cell">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
          </tbody>
        </table>
      </div>

      <div v-if="records" class="pagination">
        <span>แสดง {{ records.from || 0 }}–{{ records.to || 0 }} จาก {{ records.total }} รายการ</span>
        <div><button class="secondary-button" :disabled="!records.prev_page_url || loading" @click="load(records.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ records.current_page }} / {{ records.last_page }}</span><button class="secondary-button" :disabled="!records.next_page_url || loading" @click="load(records.current_page + 1)">ถัดไป</button></div>
      </div>
    </section>

    <section v-if="expandedBill" class="content-card section-gap record-detail">
      <div class="section-heading"><div><p class="eyebrow eyebrow--red">Billing record</p><h2>รายละเอียดบิล #{{ expandedBill.bill_id }}</h2></div><StatusPill :status="expandedBill.status" /></div>
      <div class="detail-grid">
        <p><span>ลูกค้า</span><strong>{{ expandedBill.buyer_name || '-' }}</strong></p><p><span>เลขผู้เสียภาษี</span><strong>{{ expandedBill.tax_id || '-' }}</strong></p>
        <p><span>รหัสลูกหนี้ Bplus</span><strong>{{ expandedBill.bplus_customer_code || '-' }}</strong></p><p><span>วันที่สร้าง</span><strong>{{ shortDate(expandedBill.created_at) }}</strong></p>
        <p><span>วงเงินเครดิต</span><strong>{{ money(expandedBill.credit_limit) }}</strong></p><p><span>Credit Term / ที่ขอ</span><strong>{{ expandedBill.credit_term || 0 }} / {{ expandedBill.requested_credit_term || 0 }} วัน</strong></p>
        <p><span>พิมพ์เอกสารล่าสุด</span><strong>{{ shortDate(expandedBill.document_printed_at) }} · {{ expandedBill.document_printed_by || '-' }}</strong></p><p><span>Export ล่าสุด</span><strong>{{ shortDate(expandedBill.excel_exported_at) }} · {{ expandedBill.excel_exported_by || '-' }}</strong></p>
      </div>
      <div class="detail-copy"><p><strong>ที่อยู่:</strong> {{ expandedBill.address_note || '-' }} {{ expandedBill.sub_district || '' }} {{ expandedBill.district || '' }} {{ expandedBill.province || '' }} {{ expandedBill.zipcode || '' }}</p><p><strong>การจัดส่ง:</strong> {{ expandedBill.shipping_type || '-' }} {{ expandedBill.shipping_text || '' }}</p><p><strong>หมายเหตุ:</strong> {{ expandedBill.bill_note || '-' }}</p><p v-if="expandedBill.reject_reason" class="form-error"><strong>เหตุผลที่ตีกลับ/ไม่อนุมัติ:</strong> {{ expandedBill.reject_reason }}</p></div>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>SKU</th><th>สินค้า</th><th>จำนวน</th><th>ราคา/หน่วย</th><th>ส่วนลด</th><th>รวม</th></tr></thead><tbody><tr v-for="(item, index) in expandedBill.items" :key="item.item_id"><td>{{ index + 1 }}</td><td class="strong">{{ item.main_category }}</td><td>{{ item.sub_category }}</td><td>{{ item.quantity }} {{ item.unit_type }}</td><td>{{ money(item.unit_price) }}</td><td>{{ money(item.sale_discount_unit) }}</td><td>{{ money(item.line_total) }}</td></tr></tbody></table></div>
    </section>
  </AppShell>
</template>
