<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import type { Customer, Paginated, Product, ProductUnit, Salesman } from '../types/api'
import { apiError } from '../utils/format'
import { preferredRowsPerPage } from '../utils/preferences'

type Tab = 'customers' | 'salesmen' | 'products' | 'units'
const tab = ref<Tab>('customers')
const customers = ref<Paginated<Customer> | null>(null)
const salesmen = ref<Paginated<Salesman> | null>(null)
const products = ref<Paginated<Product> | null>(null)
const units = ref<ProductUnit[]>([])
const search = ref('')
const error = ref('')
const message = ref('')
const customerForm = reactive({ ar_code: '', ar_name: '', tax_id: '' })
const salesmanForm = reactive({ salesman_code: '', salesman_name: '' })
const productForm = reactive({ sku_code: '', sku_name: '', sku_e_name: '', unit_ids: [] as number[], default_unit_id: 0 })
const unitForm = reactive({ unit_name: '' })
const editingSku = ref('')

async function load(kind: Tab, page = 1) {
  error.value = ''
  try {
    if (kind === 'units') { const response = await api.get<{ data: ProductUnit[] }>('/api/v1/master-data/units'); units.value = response.data.data; return }
    const endpoint = kind === 'products' ? '/api/v1/catalog/products' : `/api/v1/master-data/${kind}`
    const response = await api.get<Paginated<Customer | Salesman | Product>>(endpoint, { params: { page, search: search.value || undefined, per_page: preferredRowsPerPage(25) } })
    if (kind === 'customers') customers.value = response.data as Paginated<Customer>
    else if (kind === 'salesmen') salesmen.value = response.data as Paginated<Salesman>
    else products.value = response.data as Paginated<Product>
  } catch (cause) { error.value = apiError(cause) }
}

async function searchAll() { await Promise.all([load('customers'), load('salesmen'), load('products'), load('units')]) }
async function addCustomer() {
  try { await api.post('/api/v1/master-data/customers', customerForm); Object.assign(customerForm, { ar_code: '', ar_name: '', tax_id: '' }); message.value = 'เพิ่มลูกค้าแล้ว'; await load('customers') }
  catch (cause) { error.value = apiError(cause) }
}
async function addSalesman() {
  try { await api.post('/api/v1/master-data/salesmen', salesmanForm); Object.assign(salesmanForm, { salesman_code: '', salesman_name: '' }); message.value = 'เพิ่มพนักงานขายแล้ว'; await load('salesmen') }
  catch (cause) { error.value = apiError(cause) }
}
async function addProduct() {
  try {
    if (editingSku.value) await api.put(`/api/v1/master-data/products/${encodeURIComponent(editingSku.value)}`, productForm)
    else await api.post('/api/v1/master-data/products', productForm)
    message.value = editingSku.value ? 'แก้ไขสินค้าและหน่วยแล้ว' : 'เพิ่มสินค้าแล้ว'; clearProductForm(); await load('products')
  }
  catch (cause) { error.value = apiError(cause) }
}
function clearProductForm() { editingSku.value = ''; Object.assign(productForm, { sku_code: '', sku_name: '', sku_e_name: '', unit_ids: [] as number[], default_unit_id: 0 }); applyDefaultUnit() }
function applyDefaultUnit() { const piece = units.value.find(unit => unit.unit_name === 'ชิ้น') || units.value[0]; if (piece && !productForm.unit_ids.length) { productForm.unit_ids = [piece.unit_id]; productForm.default_unit_id = piece.unit_id } }
function editProduct(product: Product) { editingSku.value = product.sku_code; productForm.sku_code = product.sku_code; productForm.sku_name = product.sku_name; productForm.sku_e_name = product.sku_e_name || ''; productForm.unit_ids = (product.units || []).map(unit => unit.unit_id); productForm.default_unit_id = product.units?.find(unit => unit.pivot?.is_default)?.unit_id || productForm.unit_ids[0] || 0; window.scrollTo({ top: 0, behavior: 'smooth' }) }
function toggleProductUnit(unitId: number, checked: boolean) { productForm.unit_ids = checked ? [...new Set([...productForm.unit_ids, unitId])] : productForm.unit_ids.filter(id => id !== unitId); if (!productForm.unit_ids.includes(productForm.default_unit_id)) productForm.default_unit_id = productForm.unit_ids[0] || 0 }
async function addUnit() { try { await api.post('/api/v1/master-data/units', unitForm); unitForm.unit_name = ''; message.value = 'เพิ่มหน่วยสินค้าแล้ว'; await load('units'); applyDefaultUnit() } catch (cause) { error.value = apiError(cause) } }
async function removeUnit(unit: ProductUnit) { if (!confirm(`ลบหน่วย ${unit.unit_name} หรือไม่?`)) return; try { await api.delete(`/api/v1/master-data/units/${unit.unit_id}`); message.value = 'ลบหน่วยแล้ว'; await Promise.all([load('units'), load('products')]) } catch (cause) { error.value = apiError(cause) } }
async function remove(kind: 'customers' | 'salesmen', id: number | string, label: string) {
  if (!confirm(`ลบ ${label} หรือไม่?`)) return
  try { await api.delete(`/api/v1/master-data/${kind}/${id}`); message.value = 'ลบข้อมูลแล้ว'; await load(kind) }
  catch (cause) { error.value = apiError(cause) }
}

onMounted(async () => { await searchAll(); applyDefaultUnit() })
</script>

<template>
  <AppShell title="ศูนย์จัดการข้อมูลหลัก" subtitle="เพิ่ม ค้นหา และลบลูกค้า/พนักงานขาย พร้อมดู Master Product">
    <div class="tabs"><button :class="{ active: tab === 'customers' }" @click="tab = 'customers'">ลูกค้า</button><button :class="{ active: tab === 'salesmen' }" @click="tab = 'salesmen'">พนักงานขาย</button><button :class="{ active: tab === 'products' }" @click="tab = 'products'">สินค้า</button><button :class="{ active: tab === 'units' }" @click="tab = 'units'">หน่วยสินค้า</button></div>
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

    <template v-else-if="tab === 'products'">
      <form class="content-card form-grid section-gap" @submit.prevent="addProduct">
        <div class="span-2 section-heading"><h2>{{ editingSku ? `แก้ไขสินค้า ${editingSku}` : 'เพิ่มสินค้า' }}</h2><button v-if="editingSku" type="button" class="secondary-button" @click="clearProductForm">ยกเลิก</button></div>
        <label><span>SKU *</span><input v-model="productForm.sku_code" :disabled="Boolean(editingSku)" required></label>
        <label><span>ชื่อไทย *</span><input v-model="productForm.sku_name" required></label>
        <label><span>ชื่ออังกฤษ</span><input v-model="productForm.sku_e_name"></label>
        <fieldset class="span-2 unit-picker"><legend>หน่วยที่ใช้กับสินค้านี้ *</legend><label v-for="unit in units" :key="unit.unit_id"><input type="checkbox" :checked="productForm.unit_ids.includes(unit.unit_id)" @change="toggleProductUnit(unit.unit_id, ($event.target as HTMLInputElement).checked)"><span>{{ unit.unit_name }}</span><input v-model.number="productForm.default_unit_id" type="radio" name="default-unit" :value="unit.unit_id" :disabled="!productForm.unit_ids.includes(unit.unit_id)"><small>ค่าเริ่มต้น</small></label></fieldset>
        <button class="primary-button" :disabled="!productForm.unit_ids.length || !productForm.default_unit_id">{{ editingSku ? 'บันทึกการแก้ไข' : 'บันทึกสินค้า' }}</button>
      </form>
      <section class="content-card section-gap">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>SKU</th>
                <th>ชื่อไทย</th>
                <th>ชื่ออังกฤษ</th>
                <th>หน่วยสินค้า</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in products?.data" :key="row.sku_code">
                <td class="strong">{{ row.sku_code }}</td>
                <td>{{ row.sku_name }}</td>
                <td>{{ row.sku_e_name || '-' }}</td>
                <td><span v-for="unit in row.units" :key="unit.unit_id" class="unit-chip" :class="{ default: unit.pivot?.is_default }">{{ unit.unit_name }}{{ unit.pivot?.is_default ? ' (เริ่มต้น)' : '' }}</span></td><td><button class="secondary-button" @click="editProduct(row)">จัดการหน่วย</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="products" class="pagination"><span>ทั้งหมด {{ products.total }} รายการ</span><div><button class="secondary-button" :disabled="!products.prev_page_url" @click="load('products', products.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ products.current_page }} / {{ products.last_page }}</span><button class="secondary-button" :disabled="!products.next_page_url" @click="load('products', products.current_page + 1)">ถัดไป</button></div></div>
      </section>
    </template>
    <template v-else>
      <form class="content-card form-grid section-gap" @submit.prevent="addUnit"><div class="span-2"><h2>เพิ่มหน่วยสินค้า</h2></div><label><span>ชื่อหน่วย *</span><input v-model.trim="unitForm.unit_name" maxlength="100" placeholder="เช่น กล่อง, แพ็ก, ชุด" required></label><button class="primary-button">เพิ่มหน่วย</button></form>
      <section class="content-card section-gap"><div class="table-wrap"><table><thead><tr><th>ชื่อหน่วย</th><th>จำนวนสินค้าที่ใช้</th><th></th></tr></thead><tbody><tr v-for="unit in units" :key="unit.unit_id"><td class="strong">{{ unit.unit_name }}</td><td>{{ unit.products_count || 0 }}</td><td><button class="danger-link" @click="removeUnit(unit)">ลบ</button></td></tr></tbody></table></div></section>
    </template>
  </AppShell>
</template>

<style scoped>
.unit-picker{display:flex;flex-wrap:wrap;gap:10px;margin:0;padding:14px;border:1px solid var(--line);border-radius:10px}.unit-picker label{display:flex;align-items:center;gap:6px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fff}.unit-picker input{width:auto}.unit-picker small{color:#64748b}.unit-chip{display:inline-block;margin:2px 5px 2px 0;padding:4px 7px;border-radius:999px;background:#f1f5f9;white-space:nowrap}.unit-chip.default{background:#dcfce7;color:#166534}
</style>
