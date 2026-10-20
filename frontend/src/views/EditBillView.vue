<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute } from "vue-router";
import { api, downloadFile } from "../api/client";
import AppShell from "../components/AppShell.vue";
import CompatDateTimeInput from "../components/CompatDateTimeInput.vue";
import SearchableSelect from "../components/SearchableSelect.vue";
import StatusPill from "../components/StatusPill.vue";
import type {
  BillingRecord,
  CreditProfile,
  Paginated,
  ReferenceData,
  ThaiLocations,
} from "../types/api";
import { apiError, money } from "../utils/format";
import { preferredRowsPerPage } from "../utils/preferences";

const route = useRoute();
const tab = ref<"bill" | "credit">("bill");
const billId = ref<number | null>(null);
const bill = ref<BillingRecord | null>(null);
const drafts = ref<BillingRecord[]>([]);
const draftPage = ref<Paginated<BillingRecord> | null>(null);
const refs = ref<ReferenceData | null>(null);
const locations = ref<ThaiLocations>({});
const error = ref("");
const success = ref("");
const saving = ref(false);
const creditCode = ref("");
const creditProfile = computed(
  () =>
    refs.value?.credit_profiles.find(
      (item) => item.ar_code === creditCode.value,
    ) || null,
);
const creditForm = reactive({
  requested_credit_limit: 0,
  requested_credit_term: 0,
  justification: "",
});
const purchaseOrderFile = ref<File | null>(null);
const showPurchaseConfirmation = ref(false);
const totalItems = computed(
  () =>
    bill.value?.items?.reduce(
      (sum, item) =>
        sum +
        Math.max(
          Number(item.quantity) * Number(item.unit_price) -
            Number(item.sale_discount_unit || 0),
          0,
        ),
      0,
    ) || 0,
);
const netTotal = computed(() =>
  Math.max(totalItems.value - Number(bill.value?.sale_discount_total || 0), 0),
);
const vatAmount = computed(() =>
  bill.value?.price_vat_mode === "ex_vat"
    ? netTotal.value * 0.07
    : (netTotal.value * 7) / 107,
);
const grandTotal = computed(() =>
  bill.value?.price_vat_mode === "ex_vat"
    ? netTotal.value + vatAmount.value
    : netTotal.value,
);

async function loadReferences() {
  const [reference, places] = await Promise.all([
    api.get<{ data: ReferenceData }>("/api/v1/reference-data"),
    api.get<{ data: ThaiLocations }>("/api/v1/reference-data/locations"),
  ]);
  refs.value = reference.data.data;
  locations.value = places.data.data;
}
async function loadDrafts(page = draftPage.value?.current_page || 1) {
  const response = await api.get<Paginated<BillingRecord>>(
    "/api/v1/billing-records",
    {
      params: { status: "ฉบับร่าง", page, per_page: preferredRowsPerPage(25) },
    },
  );
  draftPage.value = response.data;
  drafts.value = response.data.data;
}
async function searchBill() {
  if (!billId.value) return;
  error.value = "";
  success.value = "";
  bill.value = null;
  try {
    const response = await api.get<{ data: BillingRecord }>(
      `/api/v1/bills/${billId.value}`,
    );
    bill.value = response.data.data;
    bill.value.price_vat_mode ||= "in_vat";
    bill.value.billing_address ||= bill.value.address_note || "";
    bill.value.delivery_address ||= bill.value.address_note || "";
    bill.value.standard_show_billing_address ??= true;
    bill.value.standard_show_delivery_address ??= false;
    showPurchaseConfirmation.value = Boolean(
      bill.value.customer_po_no ||
      bill.value.customer_confirmed_at ||
      bill.value.customer_contact_name ||
      bill.value.customer_contact_channel ||
      bill.value.payment_terms ||
      bill.value.delivery_due_date ||
      bill.value.delivery_terms ||
      bill.value.documents?.length,
    );
  } catch (cause) {
    error.value = apiError(cause, "ไม่พบบิลหรือคุณไม่มีสิทธิ์เข้าถึง");
  }
}
function selectProduct(index: number, code: string) {
  const product = refs.value?.products.find((entry) => entry.sku_code === code);
  const item = bill.value?.items?.[index];
  if (product && item) {
    item.main_category = product.sku_code;
    item.sub_category = product.sku_name;
    item.unit_type =
      product.units?.find((unit) => unit.pivot?.is_default)?.unit_name ||
      product.units?.[0]?.unit_name ||
      "ชิ้น";
  }
}
function unitsForProduct(code: string | null): string[] {
  const product = refs.value?.products.find((entry) => entry.sku_code === code);
  return (
    product?.units?.map((unit) => unit.unit_name) ||
    refs.value?.units || ["ชิ้น"]
  );
}
function selectBillProvince() {
  if (!bill.value) return;
  bill.value.district = "";
  bill.value.sub_district = "";
  bill.value.zipcode = "";
}
function selectBillDistrict() {
  if (!bill.value) return;
  bill.value.sub_district = "";
  bill.value.zipcode = "";
}
function selectBillSubDistrict() {
  if (!bill.value) return;
  bill.value.zipcode =
    locations.value[bill.value.province || ""]?.[bill.value.district || ""]?.[
      bill.value.sub_district || ""
    ] || "";
}
function addDraftItem() {
  if (!bill.value || bill.value.status !== "ฉบับร่าง") return;
  bill.value.items ??= [];
  bill.value.items.push({
    item_id: 0,
    bill_id: bill.value.bill_id,
    main_category: "",
    sub_category: "",
    customer_product_name: "",
    unit_type: refs.value?.units[0] || "",
    quantity: 1,
    unit_price: "0",
    sale_discount_unit: "0",
    line_total_before_discount: "0",
    line_total: "0",
  });
}
async function persistBill(): Promise<void> {
  if (!bill.value) return;
  bill.value.items?.forEach((item) => {
    item.line_total_before_discount = String(
      Number(item.quantity) * Number(item.unit_price),
    );
    item.line_total = String(
      Math.max(
        Number(item.line_total_before_discount) -
          Number(item.sale_discount_unit || 0),
        0,
      ),
    );
  });
  bill.value.total_amount = String(grandTotal.value);
  bill.value.address_note =
    bill.value.billing_address || bill.value.address_note;
  const response = await api.put<{ data: BillingRecord }>(
    `/api/v1/bills/${bill.value.bill_id}`,
    bill.value,
  );
  bill.value = response.data.data;
}
async function saveBill() {
  if (!bill.value) return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await persistBill();
    success.value =
      bill.value?.status === "ฉบับร่าง"
        ? "บันทึก Draft เรียบร้อยแล้ว ยังไม่ได้ส่งให้หัวหน้า"
        : "บันทึกการแก้ไขและประวัติ Edit Log เรียบร้อยแล้ว";
    await loadDrafts();
  } catch (cause) {
    error.value = apiError(cause);
  } finally {
    saving.value = false;
  }
}
function onPurchaseOrder(event: Event) {
  purchaseOrderFile.value =
    (event.target as HTMLInputElement).files?.[0] || null;
}
async function uploadPurchaseOrder() {
  if (!bill.value || !purchaseOrderFile.value) return;
  try {
    const payload = new FormData();
    payload.append("file", purchaseOrderFile.value);
    await api.post(
      `/api/v1/bills/${bill.value.bill_id}/purchase-order`,
      payload,
    );
    await searchBill();
    purchaseOrderFile.value = null;
    success.value = "บันทึกไฟล์ PO / หลักฐานยืนยันแล้ว";
  } catch (cause) {
    error.value = apiError(cause);
  }
}
async function downloadPurchaseOrder() {
  if (!bill.value?.documents?.length) return;
  try {
    await downloadFile(
      `/api/v1/bills/${bill.value.bill_id}/purchase-order`,
      bill.value.documents[0].file_name,
    );
  } catch (cause) {
    error.value = apiError(cause);
  }
}
function chooseCredit(profile: CreditProfile | null) {
  creditForm.requested_credit_limit = Number(profile?.credit_limit || 0);
  creditForm.requested_credit_term = Number(profile?.credit_term || 0);
}
async function submitCreditChange() {
  if (!creditProfile.value) return;
  error.value = "";
  success.value = "";
  saving.value = true;
  try {
    const profile = creditProfile.value;
    const response = await api.post<{ data: { request_id: number } }>(
      "/api/v1/credit-requests",
      {
        request_type: "change_existing",
        company_name: profile.ar_name,
        tax_id: null,
        registered_address: null,
        authorized_directors: null,
        ar_code: profile.ar_code,
        ar_name: profile.ar_name,
        current_credit_limit: profile.credit_limit,
        current_credit_term: profile.credit_term,
        ...creditForm,
      },
    );
    success.value = `ส่งคำขอแก้ไขวงเงิน #${response.data.data.request_id} แล้ว`;
  } catch (cause) {
    error.value = apiError(cause);
  } finally {
    saving.value = false;
  }
}
onMounted(async () => {
  try {
    await Promise.all([loadReferences(), loadDrafts()]);
    const requestedBillId = Number(route.query.bill);
    if (Number.isInteger(requestedBillId) && requestedBillId > 0) {
      billId.value = requestedBillId;
      await searchBill();
    }
  } catch (cause) {
    error.value = apiError(cause);
  }
});
</script>

<template>
  <AppShell
    title="ค้นหาและแก้ไขบิล/Draft"
    subtitle="แก้ไข Draft เดิมได้ไม่จำกัดครั้งโดยใช้เลขเดิม และบันทึก Edit Log อัตโนมัติ"
  >
    <div class="tabs">
      <button :class="{ active: tab === 'bill' }" @click="tab = 'bill'">
        แก้ไขบิล</button
      ><button :class="{ active: tab === 'credit' }" @click="tab = 'credit'">
        ขอแก้ไขวงเงินลูกค้า
      </button>
    </div>
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="success" class="success-message">{{ success }}</p>
    <template v-if="tab === 'bill'">
      <form class="content-card search-row" @submit.prevent="searchBill">
        <label
          ><span>เลือก Draft ที่บันทึกไว้</span
          ><SearchableSelect
            v-model="billId"
            :options="
              drafts.map((draft) => ({
                value: draft.bill_id,
                label: `Draft #${draft.bill_id} · ${draft.buyer_name} · ${draft.bill_date}`,
              }))
            "
            placeholder="ค้นหา Draft / ชื่อลูกค้า"
            @change="searchBill" /></label
        ><label
          ><span>หรือกรอก Bill ID</span
          ><input v-model.number="billId" type="number" min="1" /></label
        ><button class="primary-button" :disabled="!billId">ค้นหาข้อมูล</button>
      </form>
      <div
        v-if="draftPage && draftPage.last_page > 1"
        class="pagination section-gap-sm"
      >
        <span
          >Draft {{ draftPage.from || 0 }}–{{ draftPage.to || 0 }} จาก
          {{ draftPage.total }}</span
        >
        <div>
          <button
            class="secondary-button"
            :disabled="!draftPage.prev_page_url"
            @click="loadDrafts(draftPage.current_page - 1)"
          >
            ก่อนหน้า</button
          ><span
            >หน้า {{ draftPage.current_page }} / {{ draftPage.last_page }}</span
          ><button
            class="secondary-button"
            :disabled="!draftPage.next_page_url"
            @click="loadDrafts(draftPage.current_page + 1)"
          >
            ถัดไป
          </button>
        </div>
      </div>
      <form
        v-if="bill"
        class="content-card form-grid section-gap"
        @submit.prevent="saveBill"
      >
        <div class="span-2 section-heading form-section-title">
          <div>
            <p class="eyebrow eyebrow--red">
              {{ bill.status === "ฉบับร่าง" ? "Draft" : "Bill" }} #{{
                bill.bill_id
              }}
            </p>
            <h2>1. ข้อมูลบิล · {{ bill.buyer_name }}</h2>
            <p v-if="bill.status === 'ฉบับร่าง'" class="muted">
              Draft เป็นเลขอ้างอิงภายใน ยังไม่มีเลขใบกำกับภาษีและแก้ไขวันที่ได้
            </p>
          </div>
          <StatusPill :status="bill.status" />
        </div>
        <label
          ><span>วันที่เปิดบิล</span
          ><CompatDateTimeInput
            v-model="bill.bill_date"
            mode="date"
            required /></label
        ><label
          ><span>ชื่อผู้ซื้อ/บริษัท</span
          ><input v-model="bill.buyer_name" required /></label
        ><label
          ><span>เลขผู้เสียภาษี</span><input v-model="bill.tax_id" /></label
        ><label
          ><span>ประเภทจัดส่ง</span
          ><select v-model="bill.shipping_type" required>
            <option>ส่งปกติ</option>
            <option>ส่งเร็ว</option>
            <option>ส่งด่วน</option>
          </select></label
        >
        <fieldset class="span-2">
          <legend>รูปแบบราคา</legend>
          <label
            ><input v-model="bill.price_vat_mode" type="radio" value="in_vat" />
            In VAT</label
          ><label
            ><input v-model="bill.price_vat_mode" type="radio" value="ex_vat" />
            Ex VAT</label
          >
        </fieldset>
        <div class="span-2 form-section-block">
          <div class="section-heading">
            <h2>2. รายการสินค้า</h2>
            <button
              v-if="bill.status === 'ฉบับร่าง'"
              class="secondary-button"
              type="button"
              @click="addDraftItem"
            >
              + เพิ่มรายการ
            </button>
          </div>
          <article
            v-for="(item, index) in bill.items"
            :key="item.item_id || `new-${index}`"
            class="line-item"
          >
            <label class="line-item__product"
              ><span>สินค้าตามระบบ</span
              ><SearchableSelect
                :model-value="item.main_category"
                :options="
                  (refs?.products || []).map((product) => ({
                    value: product.sku_code,
                    label: `${product.sku_code} | ${product.sku_name}`,
                  }))
                "
                placeholder="พิมพ์รหัสหรือชื่อสินค้า"
                required
                @update:model-value="
                  selectProduct(index, String($event))
                " /></label
            ><label
              v-if="bill.workflow_type === 'customer_po_direct'"
              class="line-item__product"
              ><span>ชื่อสินค้าใน PO</span
              ><input
                v-model.trim="item.customer_product_name"
                maxlength="2000"
                :placeholder="
                  item.sub_category || 'ชื่อสินค้าตามใบ PO'
                " /></label
            ><label
              ><span>หน่วย</span
              ><select v-model="item.unit_type">
                <option
                  v-for="unit in unitsForProduct(item.main_category)"
                  :key="unit"
                >
                  {{ unit }}
                </option>
              </select></label
            ><label
              ><span>จำนวน</span
              ><input
                v-model.number="item.quantity"
                type="number"
                min="1" /></label
            ><label
              ><span>ราคา</span
              ><input
                v-model="item.unit_price"
                type="number"
                min="0"
                step="0.01" /></label
            ><label
              ><span>ส่วนลด</span
              ><input
                v-model="item.sale_discount_unit"
                type="number"
                min="0"
                step="0.01"
            /></label>
            <div class="line-total">
              <span>รวม</span
              ><strong>{{
                money(
                  Math.max(
                    Number(item.quantity) * Number(item.unit_price) -
                      Number(item.sale_discount_unit || 0),
                    0,
                  ),
                )
              }}</strong>
            </div>
            <button
              v-if="bill.status === 'ฉบับร่าง' && (bill.items?.length || 0) > 1"
              class="remove-line"
              type="button"
              @click="bill.items?.splice(index, 1)"
            >
              ×
            </button>
          </article>
        </div>
        <label class="span-2"
          ><span>หมายเหตุส่วนลด</span
          ><textarea
            v-model="bill.sale_discount_note"
            maxlength="2000"
            placeholder="ระบุรายละเอียดหรือเงื่อนไขส่วนลดเพิ่มเติม (ถ้ามี)"
          ></textarea>
        </label>
        <!-- <label><span>ส่วนลดท้ายบิล</span><input v-model="bill.sale_discount_total" type="number" min="0" step="0.01"></label> -->
        <!-- <label><span>หมายเหตุส่วนลด</span><input v-model="bill.sale_discount_note"></label> -->
        <div class="span-2 total-summary">
          <span>รวมสินค้า {{ money(totalItems) }}</span
          ><span>VAT 7% {{ money(vatAmount) }}</span
          ><strong>ยอดรวมสุทธิ {{ money(grandTotal) }}</strong>
        </div>
        <section class="span-2 po-confirmation-panel">
          <button
            class="panel-toggle"
            type="button"
            :aria-expanded="showPurchaseConfirmation"
            aria-controls="edit-purchase-confirmation-fields"
            @click="showPurchaseConfirmation = !showPurchaseConfirmation"
          >
            <span
              ><strong
                >3. ข้อมูลการยืนยันการซื้อขาย · Revision
                {{ bill.revision_no || 1 }}</strong
              ><small
                >กด + เพื่อแสดงข้อมูล PO การชำระเงิน และกำหนดส่งสินค้า</small
              ></span
            ><b aria-hidden="true">{{
              showPurchaseConfirmation ? "−" : "+"
            }}</b>
          </button>
          <div
            v-show="showPurchaseConfirmation"
            id="edit-purchase-confirmation-fields"
            class="form-grid po-confirmation-body"
          >
            <label
              ><span>เลขที่ PO / หนังสือสั่งซื้อ</span
              ><input v-model="bill.customer_po_no" /></label
            ><label
              ><span>วันที่ลูกค้ายืนยัน</span
              ><CompatDateTimeInput
                v-model="bill.customer_confirmed_at"
                mode="date" /></label
            ><label
              ><span>ผู้ติดต่อ</span
              ><input v-model="bill.customer_contact_name" /></label
            ><label
              ><span>ช่องทางยืนยัน</span
              ><input v-model="bill.customer_contact_channel" /></label
            ><label
              ><span>วันหมดอายุใบเสนอราคา</span
              ><CompatDateTimeInput
                v-model="bill.quotation_valid_until"
                mode="date" /></label
            ><label
              ><span>กำหนดส่งสินค้า</span
              ><CompatDateTimeInput
                v-model="bill.delivery_due_date"
                mode="date" /></label
            ><label class="span-2"
              ><span>เงื่อนไขการชำระเงิน</span
              ><textarea v-model="bill.payment_terms"></textarea></label
            ><label class="span-2"
              ><span>เงื่อนไขการส่งสินค้า</span
              ><textarea v-model="bill.delivery_terms"></textarea>
            </label>
            <div class="span-2 actions">
              <button
                v-if="bill.documents?.length"
                class="secondary-button"
                type="button"
                @click="downloadPurchaseOrder"
              >
                ดาวน์โหลด {{ bill.documents[0].file_name }}</button
              ><label
                ><span>แนบ/เปลี่ยนไฟล์ PO</span
                ><input
                  type="file"
                  accept=".pdf,.png,.jpg,.jpeg,.xlsx"
                  @change="onPurchaseOrder" /></label
              ><button
                class="secondary-button"
                type="button"
                :disabled="!purchaseOrderFile"
                @click="uploadPurchaseOrder"
              >
                อัปโหลดไฟล์
              </button>
            </div>
          </div>
        </section>
        <div class="span-2 form-section-title">
          <h2>4. ที่อยู่ออกบิลและที่อยู่จัดส่ง</h2>
        </div>
        <label class="span-2"
          ><span>ที่อยู่ออกบิล</span
          ><textarea v-model="bill.billing_address" required></textarea></label
        ><label class="span-2"
          ><span>ที่อยู่จัดส่ง</span
          ><textarea v-model="bill.delivery_address" required></textarea
          ><button
            class="text-link"
            type="button"
            @click="bill.delivery_address = bill.billing_address"
          >
            ใช้ที่อยู่ออกบิล
          </button></label
        ><fieldset class="span-2 standard-address-options">
          <legend>ที่อยู่ที่ต้องการแสดงในใบเสนอราคามาตรฐาน</legend>
          <label><input v-model="bill.standard_show_billing_address" type="checkbox" :disabled="bill.standard_show_billing_address && !bill.standard_show_delivery_address" /> แสดงที่อยู่ออกบิล</label>
          <label><input v-model="bill.standard_show_delivery_address" type="checkbox" :disabled="bill.standard_show_delivery_address && !bill.standard_show_billing_address" /> แสดงที่อยู่จัดส่ง</label>
          <small>* ตัวเลือกนี้มีผลเฉพาะใบเสนอราคารูปแบบมาตรฐานเท่านั้น Template ของโรงพยาบาลจะแสดงข้อมูลตามต้นฉบับ</small>
        </fieldset><label
          ><span>จังหวัด</span
          ><SearchableSelect
            v-model="bill.province"
            :options="
              Object.keys(locations).map((value) => ({ value, label: value }))
            "
            required
            @change="selectBillProvince" /></label
        ><label
          ><span>อำเภอ/เขต</span
          ><SearchableSelect
            v-model="bill.district"
            :options="
              Object.keys(locations[bill.province || ''] || {}).map(
                (value) => ({ value, label: value }),
              )
            "
            required
            @change="selectBillDistrict" /></label
        ><label
          ><span>ตำบล/แขวง</span
          ><SearchableSelect
            v-model="bill.sub_district"
            :options="
              Object.keys(
                locations[bill.province || '']?.[bill.district || ''] || {},
              ).map((value) => ({ value, label: value }))
            "
            required
            @change="selectBillSubDistrict" /></label
        ><label
          ><span>รหัสไปรษณีย์</span
          ><input v-model="bill.zipcode" readonly /></label
        ><label
          ><span>หมายเหตุจัดส่ง</span
          ><input v-model="bill.shipping_text" /></label
        ><label
          ><span>รายละเอียดบิล</span
          ><textarea v-model="bill.bill_note"></textarea>
        </label>
        <div class="span-2 actions">
          <button
            class="primary-button"
            type="button"
            :disabled="saving"
            @click="saveBill"
          >
            {{ saving ? "กำลังบันทึก…" : "บันทึก Draft" }}</button
          ><span class="muted"
            >เมื่อตกลงข้อมูลเรียบร้อยแล้ว ให้ส่งอนุมัติจากเมนู
            “สร้างใบเสนอราคา/เปิดบิลใหม่”</span
          >
        </div>
      </form>
    </template>
    <form
      v-else
      class="content-card form-grid"
      @submit.prevent="submitCreditChange"
    >
      <div class="span-2">
        <h2>ขอแก้ไขวงเงิน / Credit Term</h2>
        <p class="muted">ส่งให้หัวหน้าอนุมัติก่อน Admin อัปเดต Master Credit</p>
      </div>
      <label class="span-2"
        ><span>เลือกลูกค้า</span
        ><SearchableSelect
          v-model="creditCode"
          :options="
            (refs?.credit_profiles || []).map((profile) => ({
              value: profile.ar_code,
              label: `${profile.ar_code} | ${profile.ar_name}`,
            }))
          "
          placeholder="พิมพ์รหัสหรือชื่อลูกค้า"
          required
          @change="chooseCredit(creditProfile)"
      /></label>
      <template v-if="creditProfile"
        ><div class="detail-grid span-2">
          <p>
            <span>วงเงินเดิม</span
            ><strong>{{ money(creditProfile.credit_limit) }}</strong>
          </p>
          <p>
            <span>Term เดิม</span
            ><strong>{{ creditProfile.credit_term }} วัน</strong>
          </p>
        </div>
        <label
          ><span>วงเงินใหม่</span
          ><input
            v-model.number="creditForm.requested_credit_limit"
            type="number"
            min="0"
            required /></label
        ><label
          ><span>Term ใหม่</span
          ><input
            v-model.number="creditForm.requested_credit_term"
            type="number"
            min="0"
            required /></label
        ><label class="span-2"
          ><span>เหตุผล *</span
          ><textarea
            v-model="creditForm.justification"
            required
          ></textarea></label
        ><button class="primary-button span-2" :disabled="saving">
          ส่งคำขอให้ Approve
        </button></template
      >
    </form>
  </AppShell>
</template>
<style scoped>
.standard-address-options{display:flex;align-items:center;flex-wrap:wrap;gap:10px 20px;margin:0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.standard-address-options label{display:flex;align-items:center;gap:7px}.standard-address-options input{width:auto}.standard-address-options small{flex-basis:100%;color:#94a3b8;font-size:12px}
</style>
