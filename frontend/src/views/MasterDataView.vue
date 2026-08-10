<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import type { Customer, Paginated, Product, Salesman } from '../types/api'
import { apiError } from '../utils/format'

type Tab = 'customers' | 'salesmen' | 'products'
const tab = ref<Tab>('customers')
const customers = ref<Paginated<Customer> | null>(null)
const salesmen = ref<Paginated<Salesman> | null>(null)
const products = ref<Paginated<Product> | null>(null)
const search = ref('')
const error = ref('')
const message = ref('')
const customerForm = reactive({ ar_code: '', ar_name: '', tax_id: '' })
const salesmanForm = reactive({ salesman_code: '', salesman_name: '' })
const productForm = reactive({ sku_code: '', sku_name: '', sku_e_name: '' })

async function load(kind: Tab, page = 1) {
  error.value = ''
  try {
    const endpoint = kind === 'products' ? '/api/v1/catalog/products' : `/api/v1/master-data/${kind}`
    const response = await api.get<Paginated<Customer | Salesman | Product>>(endpoint, { params: { page, search: search.value || undefined, per_page: 25 } })
    if (kind === 'customers') customers.value = response.data as Paginated<Customer>
    else if (kind === 'salesmen') salesmen.value = response.data as Paginated<Salesman>
    else products.value = response.data as Paginated<Product>
  } catch (cause) { error.value = apiError(cause) }
}

async function searchAll() { await Promise.all([load('customers'), load('salesmen'), load('products')]) }
async function addCustomer() {
  try { await api.post('/api/v1/master-data/customers', customerForm); Object.assign(customerForm, { ar_code: '', ar_name: '', tax_id: '' }); message.value = 'เพิ่มลูกค้าแล้ว'; await load('customers') }
  catch (cause) { error.value = apiError(cause) }
}
async function addSalesman() {
  try { await api.post('/api/v1/master-data/salesmen', salesmanForm); Object.assign(salesmanForm, { salesman_code: '', salesman_name: '' }); message.value = 'เพิ่มพนักงานขายแล้ว'; await load('salesmen') }
  catch (cause) { error.value = apiError(cause) }
}
async function addProduct() {
  try { await api.post('/api/v1/master-data/products', productForm); Object.assign(productForm, { sku_code: '', sku_name: '', sku_e_name: '' }); message.value = 'เพิ่มสินค้าแล้ว'; await load('products') }
  catch (cause) { error.value = apiError(cause) }
}
async function remove(kind: 'customers' | 'salesmen', id: number | string, label: string) {
  if (!confirm(`ลบ ${label} หรือไม่?`)) return
  try { await api.delete(`/api/v1/master-data/${kind}/${id}`); message.value = 'ลบข้อมูลแล้ว'; await load(kind) }
  catch (cause) { error.value = apiError(cause) }
}

onMounted(searchAll)
</script>

<template>
  <AppShell title="ศูนย์จัดการข้อมูลหลัก" subtitle="เพิ่ม ค้นหา และลบลูกค้า/พนักงานขาย พร้อมดู Master Product">
    <div class="tabs"><button :class="{ active: tab === 'customers' }" @click="tab = 'customers'">ลูกค้า</button><button :class="{ active: tab === 'salesmen' }" @click="tab = 'salesmen'">พนักงานขาย</button><button :class="{ active: tab === 'products' }" @click="tab = 'products'">สินค้า</button></div>
    <p v-if="error" class="form-error">{{ error }}</p><p v-if="message" class="success-message">{{ message }}</p>
    <form class="content-card search-row" @submit.prevent="searchAll"><label><span>ค้นหา</span><input v-model="search"></label><button class="secondary-button">ค้นหาทุกหมวด</button></form>

    <template v-if="tab === 'customers'">
      <form class="content-card form-grid section-gap" @submit.prevent="addCustomer"><div class="span-2"><h2>เพิ่มรายชื่อลูกหนี้</h2></div><label><span>AR_CODE *</span><input v-model="customerForm.ar_code" required></label><label><span>AR_NAME *</span><input v-model="customerForm.ar_name" required></label><label><span>Tax ID</span><input v-model="customerForm.tax_id"></label><button class="primary-button">บันทึกลูกค้า</button></form>
      <section class="content-card section-gap"><div class="table-wrap"><table><thead><tr><th>รหัส</th><th>ชื่อ</th><th>Tax ID</th><th></th></tr></thead><tbody><tr v-for="row in customers?.data" :key="row.id"><td class="strong">{{ row.ar_code }}</td><td>{{ row.ar_name }}</td><td>{{ row.tax_id || '-' }}</td><td><button class="danger-link" @click="remove('customers', row.id, row.ar_name)">ลบ</button></td></tr></tbody></table></div><div v-if="customers" class="pagination"><span>ทั้งหมด {{ customers.total }} รายการ</span><div><button class="secondary-button" :disabled="!customers.prev_page_url" @click="load('customers', customers.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ customers.current_page }} / {{ customers.last_page }}</span><button class="secondary-button" :disabled="!customers.next_page_url" @click="load('customers', customers.current_page + 1)">ถัดไป</button></div></div></section>
    </template>

    <template v-else-if="tab === 'salesmen'">
      <form class="content-card form-grid section-gap" @submit.prevent="addSalesman"><div class="span-2"><h2>เพิ่มพนักงานขาย</h2></div><label><span>รหัส *</span><input v-model="salesmanForm.salesman_code" required></label><label><span>ชื่อ *</span><input v-model="salesmanForm.salesman_name" required></label><button class="primary-button span-2">บันทึกพนักงานขาย</button></form>
      <section class="content-card section-gap"><div class="table-wrap"><table><thead><tr><th>รหัส</th><th>ชื่อ</th><th></th></tr></thead><tbody><tr v-for="row in salesmen?.data" :key="row.id"><td class="strong">{{ row.salesman_code }}</td><td>{{ row.salesman_name }}</td><td><button class="danger-link" @click="remove('salesmen', row.id!, row.salesman_name)">ลบ</button></td></tr></tbody></table></div><div v-if="salesmen" class="pagination"><span>ทั้งหมด {{ salesmen.total }} รายการ</span><div><button class="secondary-button" :disabled="!salesmen.prev_page_url" @click="load('salesmen', salesmen.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ salesmen.current_page }} / {{ salesmen.last_page }}</span><button class="secondary-button" :disabled="!salesmen.next_page_url" @click="load('salesmen', salesmen.current_page + 1)">ถัดไป</button></div></div></section>
    </template>

    <template v-else>
      <form class="content-card form-grid section-gap" @submit.prevent="addProduct">
        <div class="span-2"><h2>เพิ่มสินค้า</h2></div>
        <label><span>SKU *</span><input v-model="productForm.sku_code" required></label>
        <label><span>ชื่อไทย *</span><input v-model="productForm.sku_name" required></label>
        <label><span>ชื่ออังกฤษ</span><input v-model="productForm.sku_e_name"></label>
        <button class="primary-button">บันทึกสินค้า</button>
      </form>
      <section class="content-card section-gap">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>SKU</th>
                <th>ชื่อไทย</th>
                <th>ชื่ออังกฤษ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in products?.data" :key="row.sku_code">
                <td class="strong">{{ row.sku_code }}</td>
                <td>{{ row.sku_name }}</td>
                <td>{{ row.sku_e_name || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="products" class="pagination"><span>ทั้งหมด {{ products.total }} รายการ</span><div><button class="secondary-button" :disabled="!products.prev_page_url" @click="load('products', products.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ products.current_page }} / {{ products.last_page }}</span><button class="secondary-button" :disabled="!products.next_page_url" @click="load('products', products.current_page + 1)">ถัดไป</button></div></div>
      </section>
    </template>
  </AppShell>
</template>
