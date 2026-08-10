<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import type { CreditProfile, Paginated } from '../types/api'
import { apiError, money } from '../utils/format'

interface CreditProfileResponse {
  data: {
    summary: { customers: number; total_credit: string; total_outstanding: string; average_term: string }
    profiles: Paginated<CreditProfile>
  }
}

const profiles = ref<CreditProfile[]>([])
const profilePage = ref<Paginated<CreditProfile> | null>(null)
const summary = ref({ customers: 0, total_credit: '0', total_outstanding: '0', average_term: '0' })
const search = ref('')
const error = ref('')
const message = ref('')
const saving = ref(false)
const form = reactive({ ar_code: '', ar_name: '', credit_limit: 0, credit_outstanding: 0, credit_term: 0 })

async function load(page = 1) {
  error.value = ''
  try {
    const response = await api.get<CreditProfileResponse>('/api/v1/credit-profiles', { params: { page, search: search.value, per_page: 25 } })
    profilePage.value = response.data.data.profiles
    profiles.value = profilePage.value.data
    summary.value = response.data.data.summary
  } catch (cause) { error.value = apiError(cause) }
}

function edit(profile: CreditProfile) {
  Object.assign(form, {
    ar_code: profile.ar_code,
    ar_name: profile.ar_name,
    credit_limit: Number(profile.credit_limit),
    credit_outstanding: Number(profile.credit_outstanding || 0),
    credit_term: profile.credit_term,
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function save() {
  saving.value = true; error.value = ''; message.value = ''
  try {
    await api.post('/api/v1/credit-profiles', form)
    message.value = `บันทึกเครดิตลูกค้า ${form.ar_code} แล้ว`
    Object.assign(form, { ar_code: '', ar_name: '', credit_limit: 0, credit_outstanding: 0, credit_term: 0 })
    await load()
  } catch (cause) { error.value = apiError(cause) }
  finally { saving.value = false }
}

async function remove(profile: CreditProfile) {
  if (!confirm(`ลบวงเงินของ ${profile.ar_code} | ${profile.ar_name} หรือไม่?`)) return
  try {
    await api.delete(`/api/v1/credit-profiles/${encodeURIComponent(profile.ar_code)}`)
    message.value = 'ลบข้อมูลเครดิตแล้ว'; await load()
  } catch (cause) { error.value = apiError(cause) }
}

onMounted(load)
</script>

<template>
  <AppShell title="จัดการเครดิตลูกค้า" subtitle="ข้อมูลวงเงินและ Credit Term จากฐานข้อมูลกลางของ Valor ERP">
    <p v-if="error" class="form-error section-gap-sm">{{ error }}</p>
    <p v-if="message" class="success-message section-gap-sm">{{ message }}</p>
    <div class="metrics-grid metrics-grid--three">
      <article class="metric-card"><span>ลูกค้าที่มีเครดิต</span><strong>{{ Number(summary.customers) }}</strong><small>ราย</small></article>
      <article class="metric-card metric-card--primary"><span>วงเงินรวม</span><strong class="metric-money">{{ money(summary.total_credit) }}</strong></article>
      <article class="metric-card"><span>ยอดค้างชำระรวม</span><strong class="metric-money">{{ money(summary.total_outstanding) }}</strong><small>Credit Term เฉลี่ย {{ Math.round(Number(summary.average_term)) }} วัน</small></article>
    </div>
    <form class="content-card form-grid" @submit.prevent="save">
      <div class="span-2"><p class="eyebrow eyebrow--red">Create / update</p><h2>เพิ่มหรือแก้ไขวงเงิน</h2></div>
      <label><span>AR Code *</span><input v-model.trim="form.ar_code" required maxlength="100"></label>
      <label><span>ชื่อลูกค้า *</span><input v-model.trim="form.ar_name" required maxlength="1000"></label>
      <label><span>วงเงินเครดิต *</span><input v-model.number="form.credit_limit" type="number" min="0" step="0.01" required></label>
      <label><span>เครดิตค้างชำระล่าสุด *</span><input v-model.number="form.credit_outstanding" type="number" min="0" step="0.01" required></label>
      <label><span>Credit Term (วัน) *</span><input v-model.number="form.credit_term" type="number" min="0" required></label>
      <button class="primary-button span-2" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : 'บันทึกข้อมูลเครดิต' }}</button>
    </form>
    <section class="content-card section-gap">
      <form class="search-row" @submit.prevent="load(1)"><label><span>ค้นหา AR Code หรือชื่อลูกค้า</span><input v-model="search"></label><button class="secondary-button">ค้นหา</button></form>
      <div class="table-wrap section-gap-sm"><table><thead><tr><th>AR Code</th><th>ลูกค้า</th><th>วงเงิน</th><th>ค้างชำระล่าสุด</th><th>Credit Term</th><th>จัดการ</th></tr></thead><tbody>
        <tr v-for="profile in profiles" :key="profile.ar_code"><td class="strong">{{ profile.ar_code }}</td><td>{{ profile.ar_name }}</td><td class="number">{{ money(profile.credit_limit) }}</td><td class="number">{{ money(profile.credit_outstanding) }}</td><td>{{ profile.credit_term }} วัน</td><td class="actions"><button class="secondary-button" @click="edit(profile)">แก้ไข</button><button class="danger-link" @click="remove(profile)">ลบ</button></td></tr>
        <tr v-if="!profiles.length"><td colspan="6" class="empty-cell">ไม่พบข้อมูลเครดิต</td></tr>
      </tbody></table></div>
      <div v-if="profilePage" class="pagination">
        <span>แสดง {{ profilePage.from || 0 }}–{{ profilePage.to || 0 }} จาก {{ profilePage.total }} รายการ</span>
        <div><button class="secondary-button" :disabled="!profilePage.prev_page_url" @click="load(profilePage.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ profilePage.current_page }} / {{ profilePage.last_page }}</span><button class="secondary-button" :disabled="!profilePage.next_page_url" @click="load(profilePage.current_page + 1)">ถัดไป</button></div>
      </div>
    </section>
  </AppShell>
</template>
