<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { CreditRequest, Paginated } from '../types/api'
import { apiError, money, shortDate } from '../utils/format'
import { preferredRowsPerPage } from '../utils/preferences'

interface CreditRequestPage extends Paginated<CreditRequest> { status_summary: Record<string, number> }
const requests = ref<CreditRequestPage | null>(null)
const error = ref('')
const count = (status: string) => requests.value?.status_summary?.[status] || 0

async function load(page = 1) {
  error.value = ''
  try {
    const response = await api.get<CreditRequestPage>('/api/v1/credit-requests', { params: { page, per_page: preferredRowsPerPage(20) } })
    requests.value = response.data
  } catch (cause) { error.value = apiError(cause) }
}

onMounted(() => load())
</script>

<template>
  <AppShell title="ติดตามสถานะอนุมัติวงเงิน" subtitle="ติดตามคำขอเปิดหรือแก้ไขวงเงินที่ส่งให้ Approve และ Admin">
    <p v-if="error" class="form-error">{{ error }}</p>
    <div class="metrics-grid">
      <article class="metric-card"><span>ทั้งหมด</span><strong>{{ requests?.total || 0 }}</strong></article>
      <article class="metric-card"><span>รอ Approve</span><strong>{{ count('รออนุมัติวงเงิน') }}</strong></article>
      <article class="metric-card"><span>รอ Admin</span><strong>{{ count('อนุมัติวงเงินแล้ว') }}</strong></article>
      <article class="metric-card metric-card--primary"><span>พร้อมเปิดบิล</span><strong>{{ count('เปิดวงเงินแล้ว') }}</strong></article>
    </div>

    <section class="list-stack">
      <article v-for="item in requests?.data" :key="item.request_id" class="content-card request-card">
        <div class="section-heading">
          <div><p class="eyebrow eyebrow--red">Request #{{ item.request_id }}</p><h2>{{ item.company_name }}</h2><p>{{ item.request_type === 'change_existing' ? 'ขอแก้ไขวงเงินลูกค้าเดิม' : 'ขอเปิดวงเงินลูกค้าใหม่' }}</p></div>
          <StatusPill :status="item.status" />
        </div>
        <div class="detail-grid">
          <p v-if="item.request_type === 'change_existing'"><span>วงเงินเดิม</span><strong>{{ money(item.current_credit_limit) }}</strong></p>
          <p><span>วงเงินที่ขอ</span><strong>{{ money(item.requested_credit_limit) }}</strong></p>
          <p v-if="item.request_type === 'change_existing'"><span>Term เดิม</span><strong>{{ item.current_credit_term || 0 }} วัน</strong></p>
          <p><span>Credit Term ที่ขอ</span><strong>{{ item.requested_credit_term }} วัน</strong></p>
          <p><span>ยอดคาดการณ์/เดือน</span><strong>{{ money(item.expected_monthly_volume) }}</strong></p>
          <p><span>Tax ID</span><strong>{{ item.tax_id || '-' }}</strong></p>
          <p><span>วันที่ส่ง</span><strong>{{ shortDate(item.created_at) }}</strong></p>
          <p><span>รหัส Bplus</span><strong>{{ item.ar_code || '-' }}</strong></p>
          <p><span>ผู้อนุมัติ / วันที่</span><strong>{{ item.approved_by || '-' }} · {{ shortDate(item.approved_at) }}</strong></p>
          <p><span>ผู้เปิดวงเงิน / วันที่</span><strong>{{ item.opened_by || '-' }} · {{ shortDate(item.opened_at) }}</strong></p>
        </div>
        <p><strong>เหตุผล:</strong> {{ item.justification || '-' }}</p>
        <p v-if="item.status === 'เปิดวงเงินแล้ว'" class="success-message">พร้อมเปิดบิลแล้วด้วยรหัสลูกหนี้ {{ item.ar_code || '-' }}</p>
        <p v-else-if="item.status === 'อนุมัติวงเงินแล้ว'" class="risk-banner risk-banner--warning">หัวหน้าอนุมัติแล้ว กำลังรอ Admin เปิดวงเงินและกำหนดรหัส Bplus</p>
        <p v-else-if="item.status === 'ไม่อนุมัติวงเงิน'" class="form-error">ไม่อนุมัติ: {{ item.reject_reason || '-' }}</p>
        <p v-else class="muted">กำลังรอหัวหน้าพิจารณา</p>
      </article>
      <div v-if="!requests?.data.length" class="content-card empty-state">ยังไม่มีคำขอวงเงิน</div>
    </section>

    <div v-if="requests" class="pagination">
      <span>แสดง {{ requests.from || 0 }}–{{ requests.to || 0 }} จาก {{ requests.total }} รายการ</span>
      <div><button class="secondary-button" :disabled="!requests.prev_page_url" @click="load(requests.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ requests.current_page }} / {{ requests.last_page }}</span><button class="secondary-button" :disabled="!requests.next_page_url" @click="load(requests.current_page + 1)">ถัดไป</button></div>
    </div>
  </AppShell>
</template>
