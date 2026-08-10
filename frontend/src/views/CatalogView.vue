<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import type { Customer, Paginated, Product } from '../types/api'

const props = defineProps<{ catalog: 'customers' | 'products' }>()
const results = ref<Paginated<Customer | Product> | null>(null)
const search = ref('')
const loading = ref(false)
const error = ref('')
const isCustomer = computed(() => props.catalog === 'customers')
const title = computed(() => isCustomer.value ? 'ข้อมูลลูกค้า' : 'ข้อมูลสินค้า')
const subtitle = computed(() => isCustomer.value ? 'ค้นหาจากรหัสหรือชื่อลูกค้า' : 'ค้นหาจาก SKU หรือชื่อสินค้า')
const customerRows = computed(() => (results.value?.data ?? []) as Customer[])
const productRows = computed(() => (results.value?.data ?? []) as Product[])

async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<Paginated<Customer | Product>>(`/api/v1/catalog/${props.catalog}`, {
      params: { page, search: search.value || undefined, per_page: 20 },
    })
    results.value = response.data
  } catch {
    error.value = `โหลด${title.value}ไม่สำเร็จ`
  } finally {
    loading.value = false
  }
}

watch(() => props.catalog, () => { search.value = ''; load() })
onMounted(() => load())
</script>

<template>
  <AppShell :title="title" :subtitle="subtitle">
    <section class="content-card">
      <form class="filter-bar filter-bar--simple" @submit.prevent="load(1)">
        <label><span>ค้นหา</span><input v-model="search" :placeholder="isCustomer ? 'เช่น VH001 หรือชื่อโรงพยาบาล' : 'เช่น SKU หรือชื่อสินค้า'"></label>
        <button class="primary-button primary-button--compact" type="submit">ค้นหา</button>
      </form>
      <p v-if="error" class="form-error">{{ error }}</p>
      <div class="table-wrap" :class="{ 'is-loading': loading }">
        <table v-if="isCustomer">
          <thead><tr><th>รหัสลูกค้า</th><th>ชื่อลูกค้า</th><th>เลขประจำตัวผู้เสียภาษี</th></tr></thead>
          <tbody><tr v-for="row in customerRows" :key="row.id"><td class="strong">{{ row.ar_code }}</td><td>{{ row.ar_name }}</td><td>{{ row.tax_id || '-' }}</td></tr></tbody>
        </table>
        <table v-else>
          <thead><tr><th>SKU</th><th>ชื่อสินค้า</th><th>ชื่อภาษาอังกฤษ</th></tr></thead>
          <tbody><tr v-for="row in productRows" :key="row.sku_code"><td class="strong">{{ row.sku_code }}</td><td>{{ row.sku_name }}</td><td>{{ row.sku_e_name || '-' }}</td></tr></tbody>
        </table>
      </div>
      <div v-if="results" class="pagination">
        <span>ทั้งหมด {{ results.total }} รายการ</span>
        <div><button class="secondary-button" :disabled="!results.prev_page_url || loading" @click="load(results.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ results.current_page }} / {{ results.last_page }}</span><button class="secondary-button" :disabled="!results.next_page_url || loading" @click="load(results.current_page + 1)">ถัดไป</button></div>
      </div>
    </section>
  </AppShell>
</template>
