<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api, downloadFile } from '../api/client'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord } from '../types/api'
import { apiError, money, shortDate } from '../utils/format'

const route = useRoute()
const token = computed(() => String(route.params.token))
const bill = ref<BillingRecord | null>(null)
const loading = ref(true)
const error = ref('')
const message = ref('')
const form = reactive({ tax_cust_name: '', tax_cust_tax_id: '', tax_cust_address: '' })
const isCanceled = computed(() => ['ยกเลิกบิล', 'ไม่อนุมัติ', 'Canceled'].includes(bill.value?.status || ''))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: BillingRecord }>(`/api/v1/public/tax/${token.value}`)
    bill.value = response.data.data
    Object.assign(form, {
      tax_cust_name: bill.value.tax_cust_name || bill.value.buyer_name || '',
      tax_cust_tax_id: bill.value.tax_cust_tax_id || '',
      tax_cust_address: bill.value.tax_cust_address || '',
    })
  } catch {
    error.value = 'ไม่พบเอกสารหรือลิงก์ไม่ถูกต้อง กรุณาติดต่อพนักงานขาย'
  } finally {
    loading.value = false
  }
}

async function ensureCsrf() { await api.get('/sanctum/csrf-cookie') }
async function confirmTax() {
  error.value = ''
  try {
    await ensureCsrf()
    await api.put(`/api/v1/public/tax/${token.value}`, form)
    message.value = 'ข้อมูลของท่านถูกส่งเข้าระบบเรียบร้อยแล้ว'
    await load()
  } catch (cause) { error.value = apiError(cause) }
}
async function cancelBill() {
  if (!confirm('ยืนยันขอยกเลิกเอกสารและแจ้งข้อมูลผิดหรือไม่?')) return
  error.value = ''
  try {
    await ensureCsrf()
    await api.post(`/api/v1/public/tax/${token.value}/cancel`)
    message.value = 'ส่งเรื่องแจ้งยกเลิกเอกสารเข้าสู่ระบบหลังบ้านแล้ว'
    await load()
  } catch (cause) { error.value = apiError(cause) }
}
function pdf() { return downloadFile(`/api/v1/public/tax/${token.value}/pdf`) }
onMounted(load)
</script>

<template>
  <main class="public-page">
    <header class="public-header"><div class="portal-brand"><span class="portal-brand__mark">V</span><span><strong>VALOR</strong><small>Customer Document Portal</small></span></div></header>
    <section v-if="loading" class="public-card">กำลังโหลดเอกสาร…</section>
    <section v-else-if="error && !bill" class="public-card"><h1>ไม่สามารถเปิดเอกสารได้</h1><p class="form-error">{{ error }}</p></section>
    <template v-else-if="bill">
      <section v-if="isCanceled" class="public-card public-state-card">
        <span class="public-state-icon">×</span><h1>ใบกำกับภาษีฉบับนี้ถูกยกเลิกแล้ว</h1>
        <p>หากต้องการแก้ไขข้อมูลหรือออกใบใหม่ กรุณาติดต่อพนักงานขายหรือฝ่ายบัญชีเพื่อตรวจสอบ</p>
        <p v-if="message" class="success-message">{{ message }}</p>
      </section>
      <template v-else>
        <section class="public-card">
          <div class="section-heading"><div><p class="eyebrow eyebrow--red">E-Tax Invoice</p><h1>แบบฟอร์มขอรับใบกำกับภาษี</h1></div><StatusPill :status="bill.status" /></div>
          <div class="detail-grid"><span>รหัสอ้างอิง <b>#{{ bill.bill_id }}</b></span><span>วันที่ <b>{{ shortDate(bill.bill_date) }}</b></span><span>ลูกค้า <b>{{ bill.buyer_name }}</b></span><span>ยอดรวม <b>{{ money(bill.total_amount) }}</b></span></div>
          <p v-if="error" class="form-error section-gap-sm">{{ error }}</p><p v-if="message" class="success-message section-gap-sm">{{ message }}</p>
        </section>

        <section v-if="bill.tax_info_filled" class="public-card public-state-card section-gap">
          <span class="public-state-icon public-state-icon--success">✓</span><h2>ยืนยันข้อมูลใบกำกับภาษีเรียบร้อยแล้ว</h2>
          <p>สามารถดาวน์โหลดใบขนส่ง / ใบกำกับภาษีอิเล็กทรอนิกส์ได้ทันที</p>
          <div class="public-actions"><button class="primary-button" @click="pdf">ดาวน์โหลดใบกำกับภาษี PDF</button><button class="danger-button" @click="cancelBill">ขอยกเลิกและแจ้งข้อมูลผิด</button></div>
        </section>

        <form v-else class="public-card form-grid section-gap" @submit.prevent="confirmTax">
          <div class="span-2"><h2>กรุณากรอกข้อมูลสำหรับออกใบกำกับภาษี</h2><p class="muted">ตรวจสอบข้อมูลให้ถูกต้องก่อนกดยืนยัน</p></div>
          <label class="span-2"><span>ชื่อบริษัท / ชื่อ-นามสกุล *</span><input v-model="form.tax_cust_name" required></label>
          <label><span>เลขผู้เสียภาษี 13 หลัก *</span><input v-model="form.tax_cust_tax_id" inputmode="numeric" pattern="\d{13}" minlength="13" maxlength="13" required></label>
          <label class="span-2"><span>ที่อยู่จดทะเบียน *</span><textarea v-model="form.tax_cust_address" placeholder="บ้านเลขที่ ซอย ถนน แขวง/ตำบล เขต/อำเภอ จังหวัด และรหัสไปรษณีย์" required></textarea></label>
          <button class="primary-button span-2">ยืนยันข้อมูลออกใบกำกับภาษี</button>
        </form>

        <section class="public-card section-gap"><h2>รายการสินค้า</h2><div class="table-wrap"><table><thead><tr><th>สินค้า</th><th>รายละเอียด</th><th>จำนวน</th><th>ราคา</th><th>รวม</th></tr></thead><tbody><tr v-for="item in bill.items" :key="item.item_id"><td class="strong">{{ item.main_category }}</td><td>{{ item.sub_category }}</td><td>{{ item.quantity }} {{ item.unit_type }}</td><td>{{ money(item.unit_price) }}</td><td>{{ money(item.line_total) }}</td></tr></tbody></table></div></section>
      </template>
    </template>
  </main>
</template>
