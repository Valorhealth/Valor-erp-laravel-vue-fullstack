<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { api } from "../api/client";
import AppShell from "../components/AppShell.vue";
import type {
  Customer,
  CustomerAddress,
  Paginated,
  Product,
  ProductUnit,
  Salesman,
} from "../types/api";
import { apiError } from "../utils/format";
import { preferredRowsPerPage } from "../utils/preferences";

type Tab = "customers" | "salesmen" | "products" | "units";
const tab = ref<Tab>("customers");
const customers = ref<Paginated<Customer> | null>(null);
const salesmen = ref<Paginated<Salesman> | null>(null);
const products = ref<Paginated<Product> | null>(null);
const units = ref<ProductUnit[]>([]);
const search = ref("");
const error = ref("");
const message = ref("");
const customerForm = reactive({
  ar_code: "",
  ar_name: "",
  tax_id: "",
  billing_address: "",
  delivery_address: "",
  province: "",
  district: "",
  sub_district: "",
  zipcode: "",
});
const addressForm = reactive({
  address_id: 0,
  customer_id: 0,
  bplus_address_code: "",
  address_name: "",
  address_type: "both" as CustomerAddress["address_type"],
  full_address: "",
  province: "",
  district: "",
  sub_district: "",
  zipcode: "",
  tax_id: "",
  tax_branch_code: "",
  contact_name: "",
  contact_phone: "",
  delivery_note: "",
  is_default_billing: false,
  is_default_delivery: false,
  is_active: true,
});
const salesmanForm = reactive({ salesman_code: "", salesman_name: "" });
const productForm = reactive({
  sku_code: "",
  sku_name: "",
  sku_e_name: "",
  unit_ids: [] as number[],
  default_unit_id: 0,
});
const unitForm = reactive({ unit_name: "" });
const editingSku = ref("");

async function load(kind: Tab, page = 1) {
  error.value = "";
  try {
    if (kind === "units") {
      const response = await api.get<{ data: ProductUnit[] }>(
        "/api/v1/master-data/units",
      );
      units.value = response.data.data;
      return;
    }
    const endpoint =
      kind === "products"
        ? "/api/v1/catalog/products"
        : `/api/v1/master-data/${kind}`;
    const response = await api.get<Paginated<Customer | Salesman | Product>>(
      endpoint,
      {
        params: {
          page,
          search: search.value || undefined,
          per_page: preferredRowsPerPage(25),
        },
      },
    );
    if (kind === "customers")
      customers.value = response.data as Paginated<Customer>;
    else if (kind === "salesmen")
      salesmen.value = response.data as Paginated<Salesman>;
    else products.value = response.data as Paginated<Product>;
  } catch (cause) {
    error.value = apiError(cause);
  }
}

async function searchAll() {
  await Promise.all([
    load("customers"),
    load("salesmen"),
    load("products"),
    load("units"),
  ]);
}
async function addCustomer() {
  try {
    await api.post("/api/v1/master-data/customers", customerForm);
    Object.assign(customerForm, {
      ar_code: "",
      ar_name: "",
      tax_id: "",
      billing_address: "",
      delivery_address: "",
      province: "",
      district: "",
      sub_district: "",
      zipcode: "",
    });
    message.value = "เพิ่มลูกค้าแล้ว";
    await load("customers");
  } catch (cause) {
    error.value = apiError(cause);
  }
}
async function addCustomerAddress() {
  if (!addressForm.customer_id) return;
  try {
    const endpoint = `/api/v1/master-data/customers/${addressForm.customer_id}/addresses${addressForm.address_id ? `/${addressForm.address_id}` : ""}`;
    await api.request({
      url: endpoint,
      method: addressForm.address_id ? "put" : "post",
      data: addressForm,
    });
    message.value = addressForm.address_id
      ? "แก้ไขที่อยู่ลูกค้าแล้ว"
      : "เพิ่มที่อยู่ลูกค้าแล้ว";
    clearAddressForm();
    await load("customers");
  } catch (cause) {
    error.value = apiError(cause);
  }
}
function clearAddressForm() {
  Object.assign(addressForm, {
    address_id: 0,
    customer_id: 0,
    bplus_address_code: "",
    address_name: "",
    address_type: "both",
    full_address: "",
    province: "",
    district: "",
    sub_district: "",
    zipcode: "",
    tax_id: "",
    tax_branch_code: "",
    contact_name: "",
    contact_phone: "",
    delivery_note: "",
    is_default_billing: false,
    is_default_delivery: false,
    is_active: true,
  });
}
function editAddress(customer: Customer, address: CustomerAddress) {
  Object.assign(addressForm, {
    ...address,
    customer_id: customer.id,
    bplus_address_code: address.bplus_address_code || "",
    province: address.province || "",
    district: address.district || "",
    sub_district: address.sub_district || "",
    zipcode: address.zipcode || "",
    tax_id: address.tax_id || "",
    tax_branch_code: address.tax_branch_code || "",
    contact_name: address.contact_name || "",
    contact_phone: address.contact_phone || "",
    delivery_note: address.delivery_note || "",
  });
  window.scrollTo({ top: 0, behavior: "smooth" });
}
async function deactivateAddress(customer: Customer, address: CustomerAddress) {
  if (
    !confirm(
      `ปิดใช้งาน ${address.address_name} หรือไม่? เอกสารเก่าจะไม่ถูกเปลี่ยนแปลง`,
    )
  )
    return;
  try {
    await api.delete(
      `/api/v1/master-data/customers/${customer.id}/addresses/${address.address_id}`,
    );
    message.value = "ปิดใช้งานที่อยู่แล้ว";
    await load("customers");
  } catch (cause) {
    error.value = apiError(cause);
  }
}
async function addSalesman() {
  try {
    await api.post("/api/v1/master-data/salesmen", salesmanForm);
    Object.assign(salesmanForm, { salesman_code: "", salesman_name: "" });
    message.value = "เพิ่มพนักงานขายแล้ว";
    await load("salesmen");
  } catch (cause) {
    error.value = apiError(cause);
  }
}
async function addProduct() {
  try {
    if (editingSku.value)
      await api.put(
        `/api/v1/master-data/products/${encodeURIComponent(editingSku.value)}`,
        productForm,
      );
    else await api.post("/api/v1/master-data/products", productForm);
    message.value = editingSku.value
      ? "แก้ไขสินค้าและหน่วยแล้ว"
      : "เพิ่มสินค้าแล้ว";
    clearProductForm();
    await load("products");
  } catch (cause) {
    error.value = apiError(cause);
  }
}
function clearProductForm() {
  editingSku.value = "";
  Object.assign(productForm, {
    sku_code: "",
    sku_name: "",
    sku_e_name: "",
    unit_ids: [] as number[],
    default_unit_id: 0,
  });
  applyDefaultUnit();
}
function applyDefaultUnit() {
  const piece =
    units.value.find((unit) => unit.unit_name === "ชิ้น") || units.value[0];
  if (piece && !productForm.unit_ids.length) {
    productForm.unit_ids = [piece.unit_id];
    productForm.default_unit_id = piece.unit_id;
  }
}
function editProduct(product: Product) {
  editingSku.value = product.sku_code;
  productForm.sku_code = product.sku_code;
  productForm.sku_name = product.sku_name;
  productForm.sku_e_name = product.sku_e_name || "";
  productForm.unit_ids = (product.units || []).map((unit) => unit.unit_id);
  productForm.default_unit_id =
    product.units?.find((unit) => unit.pivot?.is_default)?.unit_id ||
    productForm.unit_ids[0] ||
    0;
  window.scrollTo({ top: 0, behavior: "smooth" });
}
function toggleProductUnit(unitId: number, checked: boolean) {
  productForm.unit_ids = checked
    ? [...new Set([...productForm.unit_ids, unitId])]
    : productForm.unit_ids.filter((id) => id !== unitId);
  if (!productForm.unit_ids.includes(productForm.default_unit_id))
    productForm.default_unit_id = productForm.unit_ids[0] || 0;
}
async function addUnit() {
  try {
    await api.post("/api/v1/master-data/units", unitForm);
    unitForm.unit_name = "";
    message.value = "เพิ่มหน่วยสินค้าแล้ว";
    await load("units");
    applyDefaultUnit();
  } catch (cause) {
    error.value = apiError(cause);
  }
}
async function removeUnit(unit: ProductUnit) {
  if (!confirm(`ลบหน่วย ${unit.unit_name} หรือไม่?`)) return;
  try {
    await api.delete(`/api/v1/master-data/units/${unit.unit_id}`);
    message.value = "ลบหน่วยแล้ว";
    await Promise.all([load("units"), load("products")]);
  } catch (cause) {
    error.value = apiError(cause);
  }
}
async function remove(
  kind: "customers" | "salesmen",
  id: number | string,
  label: string,
) {
  if (!confirm(`ลบ ${label} หรือไม่?`)) return;
  try {
    await api.delete(`/api/v1/master-data/${kind}/${id}`);
    message.value = "ลบข้อมูลแล้ว";
    await load(kind);
  } catch (cause) {
    error.value = apiError(cause);
  }
}

onMounted(async () => {
  await searchAll();
  applyDefaultUnit();
});
</script>

<template>
  <AppShell
    title="ศูนย์จัดการข้อมูลหลัก"
    subtitle="เพิ่ม ค้นหา และลบลูกค้า/พนักงานขาย พร้อมดู Master Product"
  >
    <div class="tabs">
      <button
        :class="{ active: tab === 'customers' }"
        @click="tab = 'customers'"
      >
        ลูกค้า</button
      ><button
        :class="{ active: tab === 'salesmen' }"
        @click="tab = 'salesmen'"
      >
        พนักงานขาย</button
      ><button
        :class="{ active: tab === 'products' }"
        @click="tab = 'products'"
      >
        สินค้า</button
      ><button :class="{ active: tab === 'units' }" @click="tab = 'units'">
        หน่วยสินค้า
      </button>
    </div>
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <form class="content-card search-row" @submit.prevent="searchAll">
      <label><span>ค้นหา</span><input v-model="search" /></label
      ><button class="secondary-button">ค้นหาทุกหมวด</button>
    </form>

    <template v-if="tab === 'customers'">
      <form
        class="content-card form-grid section-gap"
        @submit.prevent="addCustomer"
      >
        <div class="span-2">
          <h2>เพิ่มรายชื่อลูกหนี้</h2>
          <p class="muted">
            รองรับฟิลด์ที่อยู่จาก Bplus เมื่อได้รับไฟล์ Export
          </p>
        </div>
        <label
          ><span>AR_CODE *</span
          ><input v-model="customerForm.ar_code" required /></label
        ><label
          ><span>AR_NAME *</span
          ><input v-model="customerForm.ar_name" required /></label
        ><label
          ><span>Tax ID</span><input v-model="customerForm.tax_id" /></label
        ><label
          ><span>จังหวัด</span><input v-model="customerForm.province" /></label
        ><label class="span-2"
          ><span>ที่อยู่ออกบิล</span
          ><textarea v-model="customerForm.billing_address"></textarea></label
        ><label class="span-2"
          ><span>ที่อยู่จัดส่ง</span
          ><textarea v-model="customerForm.delivery_address"></textarea></label
        ><label
          ><span>อำเภอ/เขต</span
          ><input v-model="customerForm.district" /></label
        ><label
          ><span>ตำบล/แขวง</span
          ><input v-model="customerForm.sub_district" /></label
        ><label
          ><span>รหัสไปรษณีย์</span
          ><input v-model="customerForm.zipcode" /></label
        ><button class="primary-button">บันทึกลูกค้า</button>
      </form>
      <form
        class="content-card form-grid section-gap"
        @submit.prevent="addCustomerAddress"
      >
        <div class="span-2 section-heading">
          <div>
            <h2>{{ addressForm.address_id ? "แก้ไขที่อยู่ลูกค้า" : "สมุดที่อยู่ลูกค้า" }}</h2>
            <p class="muted">เตรียมไว้สำหรับข้อมูลจาก Bplus ลูกค้าหนึ่งรายมีที่อยู่ออกบิลและจัดส่งได้หลายแห่ง</p>
          </div>
          <button v-if="addressForm.address_id" type="button" class="secondary-button" @click="clearAddressForm">ยกเลิกแก้ไข</button>
        </div>
        <label
          ><span>ลูกค้า *</span
          ><select v-model.number="addressForm.customer_id" :disabled="Boolean(addressForm.address_id)" required>
            <option :value="0">-- เลือกลูกค้า --</option>
            <option
              v-for="customer in customers?.data"
              :key="customer.id"
              :value="customer.id"
            >
              {{ customer.ar_code }} | {{ customer.ar_name }}
            </option>
          </select></label
        ><label
          ><span>รหัสที่อยู่ Bplus</span
          ><input
            v-model="addressForm.bplus_address_code"
            placeholder="รอข้อมูล Export ได้" /></label
        ><label
          ><span>ชื่อสถานที่ *</span
          ><input
            v-model="addressForm.address_name"
            placeholder="เช่น ฝ่ายการเงิน หรือคลังเวชภัณฑ์"
            required /></label
        ><label
          ><span>ประเภท *</span
          ><select v-model="addressForm.address_type">
            <option value="both">ออกบิลและจัดส่ง</option>
            <option value="billing">ออกบิล</option>
            <option value="delivery">จัดส่ง</option>
          </select></label
        ><label class="span-2"
          ><span>ที่อยู่ *</span
          ><textarea
            v-model="addressForm.full_address"
            required
          ></textarea></label
        ><label
          ><span>จังหวัด</span><input v-model="addressForm.province" /></label
        ><label
          ><span>อำเภอ/เขต</span><input v-model="addressForm.district" /></label
        ><label
          ><span>ตำบล/แขวง</span
          ><input v-model="addressForm.sub_district" /></label
        ><label
          ><span>รหัสไปรษณีย์</span
          ><input v-model="addressForm.zipcode" /></label
        ><label><span>Tax ID</span><input v-model="addressForm.tax_id" /></label
        ><label
          ><span>รหัสสาขาภาษี</span
          ><input
            v-model="addressForm.tax_branch_code"
            placeholder="สำนักงานใหญ่ / 00001" /></label
        ><label
          ><span>ผู้ติดต่อ</span
          ><input v-model="addressForm.contact_name" /></label
        ><label
          ><span>โทรศัพท์</span
          ><input v-model="addressForm.contact_phone" /></label
        ><label class="span-2"
          ><span>หมายเหตุจัดส่ง</span
          ><textarea v-model="addressForm.delivery_note"></textarea></label
        ><label class="check-field"
          ><input v-model="addressForm.is_default_billing" type="checkbox" />
          ค่าเริ่มต้นออกบิล</label
        ><label class="check-field"
          ><input v-model="addressForm.is_default_delivery" type="checkbox" />
          ค่าเริ่มต้นจัดส่ง</label
        ><button class="primary-button span-2">{{ addressForm.address_id ? "บันทึกการแก้ไข" : "เพิ่มที่อยู่" }}</button>
      </form>
      <section class="content-card section-gap">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>รหัส</th>
                <th>ชื่อ</th>
                <th>Tax ID</th>
                <th>ที่อยู่ที่ใช้งาน</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in customers?.data" :key="row.id">
                <td class="strong">{{ row.ar_code }}</td>
                <td>{{ row.ar_name }}</td>
                <td>{{ row.tax_id || "-" }}</td>
                <td>
                  <div class="address-list">
                    <span v-if="!row.addresses?.length" class="muted"
                      >ยังไม่มีข้อมูลจาก Bplus</span
                    >
                    <div
                      v-for="address in row.addresses"
                      :key="address.address_id"
                      :class="[
                        'address-chip',
                        { inactive: !address.is_active },
                      ]"
                    >
                      <div>
                        <strong>{{ address.address_name }}</strong
                        ><small
                          >{{ address.address_type }} ·
                          {{ address.full_address }}</small
                        >
                      </div>
                      <div v-if="address.is_active" class="address-actions">
                        <button class="secondary-button" @click="editAddress(row, address)">แก้ไข</button>
                        <button class="danger-link" @click="deactivateAddress(row, address)">ปิดใช้งาน</button>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <button
                    class="danger-link"
                    @click="remove('customers', row.id, row.ar_name)"
                  >
                    ลบ
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="customers" class="pagination">
          <span>ทั้งหมด {{ customers.total }} รายการ</span>
          <div>
            <button
              class="secondary-button"
              :disabled="!customers.prev_page_url"
              @click="load('customers', customers.current_page - 1)"
            >
              ก่อนหน้า</button
            ><span
              >หน้า {{ customers.current_page }} /
              {{ customers.last_page }}</span
            ><button
              class="secondary-button"
              :disabled="!customers.next_page_url"
              @click="load('customers', customers.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </section>
    </template>

    <template v-else-if="tab === 'salesmen'">
      <form
        class="content-card form-grid section-gap"
        @submit.prevent="addSalesman"
      >
        <div class="span-2"><h2>เพิ่มพนักงานขาย</h2></div>
        <label
          ><span>รหัส *</span
          ><input v-model="salesmanForm.salesman_code" required /></label
        ><label
          ><span>ชื่อ *</span
          ><input v-model="salesmanForm.salesman_name" required /></label
        ><button class="primary-button span-2">บันทึกพนักงานขาย</button>
      </form>
      <section class="content-card section-gap">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>รหัส</th>
                <th>ชื่อ</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in salesmen?.data" :key="row.id">
                <td class="strong">{{ row.salesman_code }}</td>
                <td>{{ row.salesman_name }}</td>
                <td>
                  <button
                    class="danger-link"
                    @click="remove('salesmen', row.id!, row.salesman_name)"
                  >
                    ลบ
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="salesmen" class="pagination">
          <span>ทั้งหมด {{ salesmen.total }} รายการ</span>
          <div>
            <button
              class="secondary-button"
              :disabled="!salesmen.prev_page_url"
              @click="load('salesmen', salesmen.current_page - 1)"
            >
              ก่อนหน้า</button
            ><span
              >หน้า {{ salesmen.current_page }} / {{ salesmen.last_page }}</span
            ><button
              class="secondary-button"
              :disabled="!salesmen.next_page_url"
              @click="load('salesmen', salesmen.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </section>
    </template>

    <template v-else-if="tab === 'products'">
      <form
        class="content-card form-grid section-gap"
        @submit.prevent="addProduct"
      >
        <div class="span-2 section-heading">
          <h2>
            {{ editingSku ? `แก้ไขสินค้า ${editingSku}` : "เพิ่มสินค้า" }}
          </h2>
          <button
            v-if="editingSku"
            type="button"
            class="secondary-button"
            @click="clearProductForm"
          >
            ยกเลิก
          </button>
        </div>
        <label
          ><span>SKU *</span
          ><input
            v-model="productForm.sku_code"
            :disabled="Boolean(editingSku)"
            required
        /></label>
        <label
          ><span>ชื่อไทย *</span><input v-model="productForm.sku_name" required
        /></label>
        <label
          ><span>ชื่ออังกฤษ</span><input v-model="productForm.sku_e_name"
        /></label>
        <fieldset class="span-2 unit-picker">
          <legend>หน่วยที่ใช้กับสินค้านี้ *</legend>
          <label v-for="unit in units" :key="unit.unit_id"
            ><input
              type="checkbox"
              :checked="productForm.unit_ids.includes(unit.unit_id)"
              @change="
                toggleProductUnit(
                  unit.unit_id,
                  ($event.target as HTMLInputElement).checked,
                )
              "
            /><span>{{ unit.unit_name }}</span
            ><input
              v-model.number="productForm.default_unit_id"
              type="radio"
              name="default-unit"
              :value="unit.unit_id"
              :disabled="!productForm.unit_ids.includes(unit.unit_id)"
            /><small>ค่าเริ่มต้น</small></label
          >
        </fieldset>
        <button
          class="primary-button"
          :disabled="
            !productForm.unit_ids.length || !productForm.default_unit_id
          "
        >
          {{ editingSku ? "บันทึกการแก้ไข" : "บันทึกสินค้า" }}
        </button>
      </form>
      <section class="content-card section-gap">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>SKU</th>
                <th>ชื่อไทย</th>
                <th>ชื่ออังกฤษ</th>
                <th>หน่วยสินค้า</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in products?.data" :key="row.sku_code">
                <td class="strong">{{ row.sku_code }}</td>
                <td>{{ row.sku_name }}</td>
                <td>{{ row.sku_e_name || "-" }}</td>
                <td>
                  <span
                    v-for="unit in row.units"
                    :key="unit.unit_id"
                    class="unit-chip"
                    :class="{ default: unit.pivot?.is_default }"
                    >{{ unit.unit_name
                    }}{{ unit.pivot?.is_default ? " (เริ่มต้น)" : "" }}</span
                  >
                </td>
                <td>
                  <button class="secondary-button" @click="editProduct(row)">
                    จัดการหน่วย
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="products" class="pagination">
          <span>ทั้งหมด {{ products.total }} รายการ</span>
          <div>
            <button
              class="secondary-button"
              :disabled="!products.prev_page_url"
              @click="load('products', products.current_page - 1)"
            >
              ก่อนหน้า</button
            ><span
              >หน้า {{ products.current_page }} / {{ products.last_page }}</span
            ><button
              class="secondary-button"
              :disabled="!products.next_page_url"
              @click="load('products', products.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </section>
    </template>
    <template v-else>
      <form
        class="content-card form-grid section-gap"
        @submit.prevent="addUnit"
      >
        <div class="span-2"><h2>เพิ่มหน่วยสินค้า</h2></div>
        <label
          ><span>ชื่อหน่วย *</span
          ><input
            v-model.trim="unitForm.unit_name"
            maxlength="100"
            placeholder="เช่น กล่อง, แพ็ก, ชุด"
            required /></label
        ><button class="primary-button">เพิ่มหน่วย</button>
      </form>
      <section class="content-card section-gap">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ชื่อหน่วย</th>
                <th>จำนวนสินค้าที่ใช้</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="unit in units" :key="unit.unit_id">
                <td class="strong">{{ unit.unit_name }}</td>
                <td>{{ unit.products_count || 0 }}</td>
                <td>
                  <button class="danger-link" @click="removeUnit(unit)">
                    ลบ
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </AppShell>
</template>

<style scoped>
.unit-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 0;
  padding: 14px;
  border: 1px solid var(--line);
  border-radius: 10px;
}
.unit-picker label {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
}
.unit-picker input {
  width: auto;
}
.unit-picker small {
  color: #64748b;
}
.unit-chip {
  display: inline-block;
  margin: 2px 5px 2px 0;
  padding: 4px 7px;
  border-radius: 999px;
  background: #f1f5f9;
  white-space: nowrap;
}
.unit-chip.default {
  background: #dcfce7;
  color: #166534;
}
.address-list {
  display: grid;
  min-width: 360px;
  gap: 5px;
}
.address-chip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 9px;
  border: 1px solid #dbe3ec;
  border-radius: 8px;
  background: #f8fafc;
}
.address-chip > div {
  display: grid;
  gap: 2px;
}
.address-chip small {
  color: #64748b;
}
.address-chip.inactive {
  opacity: 0.5;
}
</style>
