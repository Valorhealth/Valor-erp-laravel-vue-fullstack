<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from "vue";
import { api } from "../api/client";
import AppShell from "../components/AppShell.vue";
import CompatDateTimeInput from "../components/CompatDateTimeInput.vue";
import SearchableSelect from "../components/SearchableSelect.vue";
import UiIcon from "../components/UiIcon.vue";
import StatusPill from "../components/StatusPill.vue";
import type {
  BillingRecord,
  CustomerAddress,
  Paginated,
  Quotation,
  ReferenceData,
  ThaiLocations,
} from "../types/api";
import { apiError, money, todayIso } from "../utils/format";
import { preferredRowsPerPage } from "../utils/preferences";

interface SaleItemForm {
  product: string;
  main_category: string;
  sub_category: string;
  customer_product_name: string;
  unit_type: string;
  quantity: number;
  unit_price: number;
  sale_discount_unit: number;
}
interface PurchaseOrderItem {
  source_code: string;
  source_name: string;
  unit: string;
  quantity: number;
  unit_price: number;
  discount: number;
  matched_sku: string | null;
  matched_name: string | null;
  suggested_sku: string | null;
  suggested_name: string | null;
  match_confidence: number;
  match_status: "matched" | "review" | "unmatched";
}
interface PurchaseOrderScan {
  po_number: string;
  po_date: string;
  customer_confirmed_at: string;
  buyer_name: string;
  tax_id: string;
  address: string;
  province: string;
  district: string;
  sub_district: string;
  zipcode: string;
  contact_name: string;
  contact_channel: string;
  payment_terms: string;
  delivery_due_date: string;
  delivery_terms: string;
  quotation_valid_until: string;
  note: string;
  confidence: number;
  warnings: string[];
  items: PurchaseOrderItem[];
  matched_customer_code: string | null;
}
const refs = ref<ReferenceData | null>(null);
const locations = ref<ThaiLocations>({});
const quotations = ref<Quotation[]>([]);
const quotationPage = ref<Paginated<Quotation> | null>(null);
const rejectedBills = ref<BillingRecord[]>([]);
const quotationSearch = ref("");
const loading = ref(true);
const saving = ref(false);
const error = ref("");
const loadError = ref("");
const success = ref("");
const manualCustomer = ref(false);
const showCreditForm = ref(false);
const showPurchaseConfirmation = ref(false);
const submissionDialogOpen = ref(false);
const submissionError = ref("");
const pendingQuotation = ref<Quotation | null>(null);
const submissionDocuments = reactive<Record<string, boolean>>({
  quotation: true,
  power_of_attorney: false,
  company_affidavit: false,
  vat_registration: false,
  grantor_id_copies: false,
  representative_id_copy: false,
  product_documents: false,
  other: false,
});
const submissionDocumentOptions = [
  {
    key: "quotation",
    label: "ใบเสนอราคา",
    help: "เอกสารหลักของการยื่นเสนอราคา (บังคับ)",
  },
  {
    key: "power_of_attorney",
    label: "หนังสือมอบอำนาจ",
    help: "Draft สำหรับตรวจสอบก่อนนำไปลงลายมือชื่อจริง",
  },
  {
    key: "company_affidavit",
    label: "หนังสือรับรองบริษัท",
    help: "สำเนารับรองนิติบุคคลตามอายุเอกสารที่หน่วยงานกำหนด",
  },
  {
    key: "vat_registration",
    label: "ใบทะเบียนภาษีมูลค่าเพิ่ม (ภ.พ.20)",
    help: "หลักฐานการจดทะเบียนภาษีมูลค่าเพิ่ม",
  },
  {
    key: "grantor_id_copies",
    label: "สำเนาบัตรประชาชนผู้มอบอำนาจ",
    help: "ใช้เมื่อรายการนี้ต้องมีหนังสือมอบอำนาจ",
  },
  {
    key: "representative_id_copy",
    label: "สำเนาบัตรประชาชนผู้รับมอบอำนาจ/ผู้แทน",
    help: "เตรียมพร้อมรับรองสำเนาถูกต้อง",
  },
  {
    key: "product_documents",
    label: "เอกสารสินค้า/ใบอนุญาต/แค็ตตาล็อก",
    help: "เช่น อย., มอก., Specification หรือหนังสือแต่งตั้งตัวแทน",
  },
  {
    key: "other",
    label: "เอกสารอื่นตามเงื่อนไขหน่วยงาน",
    help: "ตรวจ TOR หรือประกาศของหน่วยงานเพิ่มเติม",
  },
];
const purchaseConfirmationPanel = ref<HTMLElement | null>(null);
const purchaseOrderFile = ref<File | null>(null);
const documentMode = ref<"quotation" | "po_direct">("quotation");
const poScanning = ref(false);
const poScan = ref<PurchaseOrderScan | null>(null);
let loadSequence = 0;
const creditFiles = reactive<Record<string, File | null>>({
  affidavit: null,
  vat: null,
  director_id: null,
  bank_statement: null,
});
const credit = reactive({
  company_name: "",
  tax_id: "",
  registered_address: "",
  authorized_directors: "",
  requested_credit_limit: 0,
  requested_credit_term: 0,
  expected_monthly_volume: 0,
  justification: "",
});
const form = reactive({
  qt_date: todayIso(),
  customer: "",
  bplus_customer_code: "",
  buyer_name: "",
  tax_id: "",
  price_vat_mode: "in_vat" as "in_vat" | "ex_vat",
  billing_address_id: null as number | null,
  delivery_address_id: null as number | null,
  billing_address: "",
  delivery_address: "",
  standard_show_billing_address: true,
  standard_show_delivery_address: false,
  province: "",
  district: "",
  sub_district: "",
  zipcode: "",
  house_no: "",
  moo: "",
  soi: "",
  road: "",
  address_extra: "",
  shipping_type: "",
  shipping_text: "",
  bill_note: "",
  sale_discount_total: 0,
  sale_discount_note: "",
  requested_credit_term: 0,
  workflow_type: "existing_customer",
  customer_po_no: "",
  customer_confirmed_at: "",
  customer_contact_name: "",
  customer_contact_channel: "",
  quotation_valid_until: "",
  payment_terms: "",
  delivery_due_date: "",
  delivery_terms: "",
  items: [newItem()] as SaleItemForm[],
});
function newItem(): SaleItemForm {
  return {
    product: "",
    main_category: "",
    sub_category: "",
    customer_product_name: "",
    unit_type: "ชิ้น",
    quantity: 1,
    unit_price: 0,
    sale_discount_unit: 0,
  };
}
function normalizeReferenceData(data: ReferenceData): ReferenceData {
  return {
    ...data,
    customers: Array.isArray(data.customers) ? data.customers : [],
    products: Array.isArray(data.products) ? data.products : [],
    salesmen: Array.isArray(data.salesmen) ? data.salesmen : [],
    credit_profiles: Array.isArray(data.credit_profiles)
      ? data.credit_profiles
      : [],
    units: Array.isArray(data.units) ? data.units : ["ชิ้น"],
    shipping_types: Array.isArray(data.shipping_types)
      ? data.shipping_types
      : [],
  };
}
const districts = computed(() =>
  form.province ? Object.keys(locations.value[form.province] || {}) : [],
);
const subDistricts = computed(() =>
  form.province && form.district
    ? Object.keys(locations.value[form.province]?.[form.district] || {})
    : [],
);
const selectedCredit = computed(() =>
  refs.value?.credit_profiles.find(
    (profile) => profile.ar_code === form.bplus_customer_code,
  ),
);
const selectedCustomer = computed(() =>
  refs.value?.customers.find((item) => item.ar_code === form.customer),
);
const billingAddresses = computed(() =>
  (selectedCustomer.value?.addresses || []).filter(
    (address) =>
      address.is_active && ["billing", "both"].includes(address.address_type),
  ),
);
const deliveryAddresses = computed(() =>
  (selectedCustomer.value?.addresses || []).filter(
    (address) =>
      address.is_active && ["delivery", "both"].includes(address.address_type),
  ),
);
const grossTotal = computed(() =>
  form.items.reduce(
    (sum, item) =>
      sum +
      Math.max(item.quantity * item.unit_price - item.sale_discount_unit, 0),
    0,
  ),
);
const netTotal = computed(() =>
  Math.max(grossTotal.value - form.sale_discount_total, 0),
);
const vatAmount = computed(() =>
  form.price_vat_mode === "ex_vat"
    ? netTotal.value * 0.07
    : (netTotal.value * 7) / 107,
);
const grandTotal = computed(() =>
  form.price_vat_mode === "ex_vat"
    ? netTotal.value + vatAmount.value
    : netTotal.value,
);

function selectCustomer() {
  const customer = refs.value?.customers.find(
    (item) => item.ar_code === form.customer,
  );
  if (!customer) return;
  Object.assign(form, {
    bplus_customer_code: customer.ar_code,
    buyer_name: customer.ar_name,
    tax_id: customer.tax_id || "",
    billing_address_id: null,
    delivery_address_id: null,
    billing_address: customer.billing_address || "",
    delivery_address:
      customer.delivery_address || customer.billing_address || "",
    province: customer.province || "",
    district: customer.district || "",
    sub_district: customer.sub_district || "",
    zipcode: customer.zipcode || "",
    requested_credit_term:
      refs.value?.credit_profiles.find(
        (profile) => profile.ar_code === customer.ar_code,
      )?.credit_term || 0,
  });
  const billing =
    customer.addresses?.find((address) => address.is_default_billing) ||
    billingAddresses.value[0];
  const delivery =
    customer.addresses?.find((address) => address.is_default_delivery) ||
    deliveryAddresses.value[0];
  if (billing) {
    form.billing_address_id = billing.address_id;
    applyBillingAddress();
  }
  if (delivery) {
    form.delivery_address_id = delivery.address_id;
    applyDeliveryAddress();
  }
}
function addressLabel(address: CustomerAddress): string {
  return `${address.address_name}${address.bplus_address_code ? ` [${address.bplus_address_code}]` : ""} — ${address.full_address}`;
}
function applyBillingAddress() {
  const address = billingAddresses.value.find(
    (item) => item.address_id === Number(form.billing_address_id),
  );
  if (!address) return;
  form.billing_address = address.full_address;
  form.tax_id = address.tax_id || selectedCustomer.value?.tax_id || form.tax_id;
  form.province = address.province || "";
  form.district = address.district || "";
  form.sub_district = address.sub_district || "";
  form.zipcode = address.zipcode || "";
}
function applyDeliveryAddress() {
  const address = deliveryAddresses.value.find(
    (item) => item.address_id === Number(form.delivery_address_id),
  );
  if (!address) return;
  form.delivery_address = address.full_address;
  if (address.delivery_note) form.shipping_text = address.delivery_note;
  if (address.contact_name) form.customer_contact_name = address.contact_name;
  if (address.contact_phone)
    form.customer_contact_channel = address.contact_phone;
}
function copyBillingAddress() {
  form.delivery_address_id = deliveryAddresses.value.some(
    (address) => address.address_id === Number(form.billing_address_id),
  )
    ? form.billing_address_id
    : null;
  form.delivery_address = form.billing_address;
}
function selectProduct(item: SaleItemForm) {
  const product = refs.value?.products.find(
    (entry) => entry.sku_code === item.product,
  );
  if (product)
    Object.assign(item, {
      main_category: product.sku_code,
      sub_category: product.sku_name,
      unit_type:
        product.units?.find((unit) => unit.pivot?.is_default)?.unit_name ||
        product.units?.[0]?.unit_name ||
        "ชิ้น",
    });
}
function unitsFor(item: SaleItemForm): string[] {
  const product = refs.value?.products.find(
    (entry) => entry.sku_code === (item.product || item.main_category),
  );
  return (
    product?.units?.map((unit) => unit.unit_name) ||
    refs.value?.units || ["ชิ้น"]
  );
}
function selectProvince() {
  form.district = "";
  form.sub_district = "";
  form.zipcode = "";
}
function selectDistrict() {
  form.sub_district = "";
  form.zipcode = "";
}
function selectSubDistrict() {
  form.zipcode =
    locations.value[form.province]?.[form.district]?.[form.sub_district] || "";
}
function onFile(key: string, event: Event) {
  creditFiles[key] = (event.target as HTMLInputElement).files?.[0] || null;
}
function onPurchaseOrder(event: Event) {
  purchaseOrderFile.value =
    (event.target as HTMLInputElement).files?.[0] || null;
}

function applyPurchaseOrder(result: PurchaseOrderScan) {
  if (result.po_date) form.qt_date = result.po_date;
  if (
    result.matched_customer_code &&
    refs.value?.customers.some(
      (customer) => customer.ar_code === result.matched_customer_code,
    )
  ) {
    manualCustomer.value = false;
    form.customer = result.matched_customer_code;
    selectCustomer();
  } else {
    manualCustomer.value = true;
    form.customer = "";
    form.bplus_customer_code = "";
    if (result.buyer_name) form.buyer_name = result.buyer_name;
    if (result.tax_id) form.tax_id = result.tax_id;
  }
  if (result.po_number) form.customer_po_no = result.po_number;
  form.customer_confirmed_at =
    result.customer_confirmed_at ||
    result.po_date ||
    form.customer_confirmed_at;
  if (result.contact_name) form.customer_contact_name = result.contact_name;
  if (result.contact_channel)
    form.customer_contact_channel = result.contact_channel;
  if (result.payment_terms) form.payment_terms = result.payment_terms;
  if (result.delivery_due_date)
    form.delivery_due_date = result.delivery_due_date;
  if (result.delivery_terms) form.delivery_terms = result.delivery_terms;
  if (result.quotation_valid_until)
    form.quotation_valid_until = result.quotation_valid_until;
  if (result.note) form.bill_note = result.note;
  if (result.address) form.address_extra = result.address;
  if (result.province && locations.value[result.province]) {
    form.province = result.province;
    if (
      result.district &&
      locations.value[result.province]?.[result.district]
    ) {
      form.district = result.district;
      if (
        result.sub_district &&
        locations.value[result.province]?.[result.district]?.[
          result.sub_district
        ]
      )
        form.sub_district = result.sub_district;
    }
    form.zipcode =
      result.zipcode ||
      locations.value[form.province]?.[form.district]?.[form.sub_district] ||
      "";
  }
  if (result.items.length) {
    form.items = result.items.map((item) => ({
      product: item.matched_sku || "",
      main_category: item.matched_sku || "",
      sub_category: item.matched_name || item.source_name,
      customer_product_name: item.source_name,
      unit_type: refs.value?.units.includes(item.unit)
        ? item.unit
        : refs.value?.units[0] || "ชิ้น",
      quantity: Math.max(1, Number(item.quantity || 1)),
      unit_price: Math.max(0, Number(item.unit_price || 0)),
      sale_discount_unit: Math.max(0, Number(item.discount || 0)),
    }));
  }
}

async function scanPurchaseOrder() {
  if (!purchaseOrderFile.value) {
    error.value = "กรุณาเลือกไฟล์ PO ก่อนอ่านข้อมูล";
    return;
  }
  poScanning.value = true;
  error.value = "";
  success.value = "";
  poScan.value = null;
  try {
    const payload = new FormData();
    payload.append("document", purchaseOrderFile.value);
    const response = await api.post<{ data: PurchaseOrderScan }>(
      "/api/v1/purchase-orders/scan",
      payload,
      { timeout: 190_000 },
    );
    poScan.value = response.data.data;
    applyPurchaseOrder(response.data.data);
    showPurchaseConfirmation.value = true;
    await nextTick();
    purchaseConfirmationPanel.value?.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
    const unmatched = response.data.data.items.filter(
      (item) => item.match_status === "unmatched",
    ).length;
    success.value = `อ่าน PO และช่วยกรอกแล้ว ${response.data.data.items.length} รายการ${unmatched ? ` · มี ${unmatched} รายการที่ Sale ต้องเลือกสินค้าหลักเอง` : ""}`;
  } catch (cause) {
    error.value = apiError(cause, "AI อ่านใบ PO ไม่สำเร็จ");
  } finally {
    poScanning.value = false;
  }
}
function poRiskClass(result: PurchaseOrderScan): string {
  if (result.items.some((item) => item.match_status === "unmatched"))
    return "risk-banner--danger";
  if (
    result.confidence < 0.8 ||
    result.items.some((item) => item.match_status === "review")
  )
    return "risk-banner--warning";
  return "risk-banner--success";
}

async function load(page = quotationPage.value?.current_page || 1) {
  const sequence = ++loadSequence;
  loading.value = true;
  loadError.value = "";
  const backgroundResults = Promise.allSettled([
    api.get<{ data: ThaiLocations }>("/api/v1/reference-data/locations", {
      timeout: 20_000,
      skipGlobalLoading: true,
    }),
    api.get<Paginated<Quotation>>("/api/v1/quotations", {
      timeout: 20_000,
      skipGlobalLoading: true,
      params: {
        page,
        search: quotationSearch.value || undefined,
        available_for_billing: 1,
        per_page: preferredRowsPerPage(20),
      },
    }),
    api.get<Paginated<BillingRecord>>("/api/v1/billing-records", {
      timeout: 20_000,
      skipGlobalLoading: true,
      params: { status: "รอแก้ไข", per_page: preferredRowsPerPage(100) },
    }),
  ] as const);

  try {
    const referenceResponse = await api.get<{ data: ReferenceData }>(
      "/api/v1/reference-data",
      { timeout: 20_000 },
    );
    if (sequence === loadSequence)
      refs.value = normalizeReferenceData(referenceResponse.data.data);
  } catch (cause) {
    if (sequence === loadSequence)
      loadError.value = `ข้อมูลลูกค้า/สินค้า: ${apiError(cause, "โหลดข้อมูลอ้างอิงไม่สำเร็จ")}`;
  } finally {
    if (sequence === loadSequence) loading.value = false;
  }

  const failed: string[] = [];
  const [locationResult, quotationResult, rejectedResult] =
    await backgroundResults;
  if (sequence !== loadSequence) return;
  if (locationResult.status === "fulfilled")
    locations.value = locationResult.value.data.data;
  else failed.push(`ข้อมูลที่อยู่: ${apiError(locationResult.reason)}`);
  if (quotationResult.status === "fulfilled") {
    quotationPage.value = quotationResult.value.data;
    quotations.value = quotationResult.value.data.data;
  } else failed.push(`รายการใบเสนอราคา: ${apiError(quotationResult.reason)}`);
  if (rejectedResult.status === "fulfilled")
    rejectedBills.value = rejectedResult.value.data.data;
  else failed.push(`รายการตีกลับ: ${apiError(rejectedResult.reason)}`);

  if (failed.length) {
    const backgroundError = `โหลดข้อมูลบางส่วนไม่สำเร็จ (${failed.join(" · ")})`;
    loadError.value = loadError.value
      ? `${loadError.value} · ${backgroundError}`
      : backgroundError;
  }
}

async function refreshQuotations(
  page = quotationPage.value?.current_page || 1,
) {
  try {
    const response = await api.get<Paginated<Quotation>>("/api/v1/quotations", {
      params: {
        page,
        search: quotationSearch.value || undefined,
        available_for_billing: 1,
        per_page: preferredRowsPerPage(20),
      },
    });
    quotationPage.value = response.data;
    quotations.value = response.data.data;
  } catch (cause) {
    error.value = apiError(cause, "อัปเดตสถานะใบเสนอราคาไม่สำเร็จ");
  }
}

async function submitCredit() {
  error.value = "";
  success.value = "";
  saving.value = true;
  try {
    const data = new FormData();
    Object.entries(credit).forEach(([key, value]) =>
      data.append(key, String(value)),
    );
    data.append("request_type", "new_customer");
    Object.entries(creditFiles).forEach(([key, file]) => {
      if (file) data.append(`documents[${key}]`, file);
    });
    const response = await api.post<{ data: { request_id: number } }>(
      "/api/v1/credit-requests",
      data,
    );
    success.value = `ส่งคำขอเปิดวงเงิน #${response.data.data.request_id} ให้ Approve แล้ว`;
    showCreditForm.value = false;
  } catch (cause) {
    error.value = apiError(cause);
  } finally {
    saving.value = false;
  }
}

async function submitQuotation() {
  error.value = "";
  success.value = "";
  saving.value = true;
  try {
    const address =
      `เลขที่ ${form.house_no} ม.${form.moo} ซ.${form.soi} ถ.${form.road} จ.${form.province} อ.${form.district} ต.${form.sub_district} ${form.zipcode} ${form.address_extra}`.trim();
    const billingAddress = form.billing_address.trim() || address;
    const deliveryAddress = form.delivery_address.trim() || billingAddress;
    const response = await api.post<{ data: Quotation }>("/api/v1/quotations", {
      ...form,
      address_note: billingAddress,
      billing_address: billingAddress,
      delivery_address: deliveryAddress,
      customer: undefined,
      house_no: undefined,
      moo: undefined,
      soi: undefined,
      road: undefined,
      address_extra: undefined,
      items: form.items.map(({ product, ...item }) => item),
    });
    const billId = response.data.data.billing_record?.bill_id;
    let uploadWarning = "";
    if (purchaseOrderFile.value && billId) {
      try {
        const document = new FormData();
        document.append("file", purchaseOrderFile.value);
        await api.post(`/api/v1/bills/${billId}/purchase-order`, document);
      } catch (cause) {
        uploadWarning = ` แต่ไฟล์ PO ยังไม่ถูกแนบ (${apiError(cause)}) กรุณาแนบซ้ำในหน้าแก้ Draft`;
      }
    }
    success.value = `บันทึกใบเสนอราคา ${response.data.data.qt_no} เป็น Draft #${response.data.data.billing_record?.bill_id} เรียบร้อยแล้ว${uploadWarning}`;
    resetQuotation();
    await refreshQuotations(1);
  } catch (cause) {
    error.value = apiError(cause);
  } finally {
    saving.value = false;
  }
}
async function submitPurchaseOrderDirect() {
  if (!purchaseOrderFile.value) {
    error.value = "เส้นทาง PO Direct ต้องแนบไฟล์ PO ต้นฉบับ";
    return;
  }
  if (!form.customer_po_no || !form.customer_confirmed_at) {
    error.value = "กรุณาระบุเลขที่ PO และวันที่ลูกค้ายืนยัน";
    return;
  }
  if (
    !confirm(
      "ยืนยันสร้างรายการเปิดบิลจาก PO โดยตรงและส่งให้หัวหน้าอนุมัติ? ระบบจะไม่สร้างเลขใบเสนอราคา",
    )
  )
    return;
  error.value = "";
  success.value = "";
  saving.value = true;
  try {
    const address =
      `เลขที่ ${form.house_no} ม.${form.moo} ซ.${form.soi} ถ.${form.road} จ.${form.province} อ.${form.district} ต.${form.sub_district} ${form.zipcode} ${form.address_extra}`.trim();
    const billingAddress = form.billing_address.trim() || address;
    const deliveryAddress = form.delivery_address.trim() || billingAddress;
    const data = new FormData();
    const fields: Record<string, unknown> = {
      bill_date: form.qt_date,
      buyer_name: form.buyer_name,
      tax_id: form.tax_id,
      price_vat_mode: form.price_vat_mode,
      bplus_customer_code: form.bplus_customer_code,
      province: form.province,
      district: form.district,
      sub_district: form.sub_district,
      zipcode: form.zipcode,
      address_note: billingAddress,
      billing_address: billingAddress,
      delivery_address: deliveryAddress,
      shipping_type: form.shipping_type,
      shipping_text: form.shipping_text,
      bill_note: form.bill_note,
      sale_discount_total: form.sale_discount_total,
      sale_discount_note: form.sale_discount_note,
      requested_credit_term: form.requested_credit_term,
      customer_po_no: form.customer_po_no,
      customer_confirmed_at: form.customer_confirmed_at,
      customer_contact_name: form.customer_contact_name,
      customer_contact_channel: form.customer_contact_channel,
      payment_terms: form.payment_terms,
      delivery_due_date: form.delivery_due_date,
      delivery_terms: form.delivery_terms,
    };
    Object.entries(fields).forEach(([key, value]) => {
      if (value !== "" && value !== null && value !== undefined)
        data.append(key, String(value));
    });
    form.items.forEach((item, index) => {
      const { product: _product, ...values } = item;
      Object.entries(values).forEach(([key, value]) =>
        data.append(`items[${index}][${key}]`, String(value ?? "")),
      );
    });
    data.append("purchase_order", purchaseOrderFile.value);
    const response = await api.post<{ data: BillingRecord }>(
      "/api/v1/purchase-orders/create-bill",
      data,
    );
    success.value = `สร้างรายการเปิดบิล #${response.data.data.bill_id} จาก PO โดยไม่ออกเลขใบเสนอราคา และส่งให้หัวหน้าอนุมัติแล้ว`;
    resetQuotation();
    documentMode.value = "quotation";
    await refreshQuotations(1);
  } catch (cause) {
    error.value = apiError(cause, "สร้างรายการจาก PO ไม่สำเร็จ");
  } finally {
    saving.value = false;
  }
}
function submitCurrent() {
  return documentMode.value === "po_direct"
    ? submitPurchaseOrderDirect()
    : submitQuotation();
}
function resetQuotation() {
  Object.assign(form, {
    qt_date: todayIso(),
    customer: "",
    bplus_customer_code: "",
    buyer_name: "",
    tax_id: "",
    price_vat_mode: "in_vat",
    billing_address_id: null,
    delivery_address_id: null,
    billing_address: "",
    delivery_address: "",
    standard_show_billing_address: true,
    standard_show_delivery_address: false,
    province: "",
    district: "",
    sub_district: "",
    zipcode: "",
    house_no: "",
    moo: "",
    soi: "",
    road: "",
    address_extra: "",
    shipping_type: "",
    shipping_text: "",
    bill_note: "",
    sale_discount_total: 0,
    sale_discount_note: "",
    requested_credit_term: 0,
    customer_po_no: "",
    customer_confirmed_at: "",
    customer_contact_name: "",
    customer_contact_channel: "",
    quotation_valid_until: "",
    payment_terms: "",
    delivery_due_date: "",
    delivery_terms: "",
    items: [newItem()],
  });
  purchaseOrderFile.value = null;
  poScan.value = null;
  manualCustomer.value = false;
  showPurchaseConfirmation.value = false;
}
function openSubmissionChecklist(quotation: Quotation) {
  if (
    quotation.workflow_type === "credit_first" &&
    quotation.status !== "เปิดวงเงินแล้ว"
  ) {
    error.value = "ใบเสนอราคานี้ต้องรอ Admin เปิดวงเงินก่อนจึงจะส่งอนุมัติได้";
    return;
  }
  pendingQuotation.value = quotation;
  submissionError.value = "";
  Object.keys(submissionDocuments).forEach((key) => {
    submissionDocuments[key] = key === "quotation";
  });
  submissionDialogOpen.value = true;
}
function closeSubmissionChecklist() {
  submissionDialogOpen.value = false;
  pendingQuotation.value = null;
  submissionError.value = "";
}
async function createBill() {
  const quotation = pendingQuotation.value;
  if (!quotation) return;
  const selectedDocuments = Object.entries(submissionDocuments)
    .filter(([, checked]) => checked)
    .map(([key]) => key);
  saving.value = true;
  error.value = "";
  submissionError.value = "";
  try {
    const response = quotation.billing_record
      ? await api.post<{ data: { bill_id: number; status: string } }>(
          `/api/v1/bills/${quotation.billing_record.bill_id}/submit`,
          { submission_documents: selectedDocuments },
        )
      : await api.post<{ data: { bill_id: number; status: string } }>(
          `/api/v1/quotations/${quotation.qt_id}/create-bill`,
          {
            bill_date: quotation.qt_date || todayIso(),
            save_as_draft: false,
            submission_documents: selectedDocuments,
          },
        );
    success.value = `ส่งบิล #${response.data.data.bill_id} เข้าสู่ขั้นตอนอนุมัติแล้ว สถานะ ${response.data.data.status}`;
    closeSubmissionChecklist();
    await refreshQuotations();
  } catch (cause) {
    submissionError.value = apiError(cause);
    error.value = submissionError.value;
  } finally {
    saving.value = false;
  }
}
function quotationStatus(quotation: Quotation): string {
  return quotation.billing_record?.status || quotation.status || "-";
}
onMounted(load);
</script>

<template>
  <AppShell
    title="ระบบเปิดบิล Product"
    subtitle="สร้างใบเสนอราคาก่อน แล้วเปิดบิลจากใบเสนอราคาที่บันทึกไว้"
  >
    <p v-if="error" class="form-error section-gap-sm">{{ error }}</p>
    <div
      v-if="loadError"
      class="risk-banner risk-banner--danger section-gap-sm"
    >
      <span>{{ loadError }}</span
      ><button
        class="secondary-button"
        type="button"
        :disabled="loading"
        @click="load()"
      >
        {{ loading ? "กำลังลองใหม่…" : "ลองโหลดใหม่" }}
      </button>
    </div>
    <p v-if="success" class="success-message section-gap-sm">{{ success }}</p>
    <section v-if="rejectedBills.length" class="list-stack section-gap-sm">
      <article
        v-for="bill in rejectedBills"
        :key="bill.bill_id"
        class="risk-banner risk-banner--danger"
      >
        บิล #{{ bill.bill_id }} · {{ bill.buyer_name }} ถูกตีกลับ:
        {{ bill.reject_reason || "ไม่ได้ระบุเหตุผล" }}
        <a class="text-link" href="/bills/edit">เปิดหน้าแก้ไขบิล</a>
      </article>
    </section>
    <div v-if="loading" class="loading-state">
      กำลังโหลดลูกค้า สินค้า และข้อมูลเครดิต…
    </div>
    <template v-else>
      <section class="content-card ocr-panel">
        <div>
          <p class="eyebrow eyebrow--red">PURCHASE ORDER AI</p>
          <h2>สแกนใบ PO เพื่อช่วยกรอกฟอร์ม</h2>
          <p class="muted">
            รองรับ PDF, PNG/JPG และ XLSX · ไฟล์นี้จะถูกแนบเก็บกับ Draft
            เป็นหลักฐานเมื่อกดบันทึก
          </p>
        </div>
        <label
          ><span>ใบ PO / หลักฐานยืนยัน</span
          ><input
            type="file"
            accept=".pdf,.png,.jpg,.jpeg,.xlsx"
            @change="onPurchaseOrder" /></label
        ><button
          class="secondary-button"
          :disabled="poScanning || !purchaseOrderFile"
          @click="scanPurchaseOrder"
        >
          <UiIcon name="activity" :size="17" />{{
            poScanning ? "AI กำลังอ่าน PO…" : "สแกนและกรอกฟอร์ม"
          }}
        </button>
      </section>
      <div
        v-if="poScan"
        class="risk-banner section-gap-sm"
        :class="poRiskClass(poScan)"
      >
        ความมั่นใจเอกสาร {{ Math.round(poScan.confidence * 100) }}% ·
        จับคู่สินค้าแล้ว
        {{
          poScan.items.filter((item) => item.match_status !== "unmatched")
            .length
        }}/{{ poScan.items.length }} รายการ<span v-if="poScan.warnings.length">
          · ควรตรวจสอบ: {{ poScan.warnings.join(" · ") }}</span
        >
      </div>
      <section class="content-card section-gap-sm">
        <div class="section-heading">
          <div>
            <h2>เลือกเส้นทางเอกสาร</h2>
            <p class="muted">
              เลือกให้ตรงกับหลักฐานที่ลูกค้ามอบให้
              เพื่อไม่ออกเลขใบเสนอราคาโดยไม่จำเป็น
            </p>
          </div>
          <div class="tabs">
            <button
              type="button"
              :class="{ active: documentMode === 'quotation' }"
              @click="documentMode = 'quotation'"
            >
              สร้างใบเสนอราคา</button
            ><button
              type="button"
              :class="{ active: documentMode === 'po_direct' }"
              @click="documentMode = 'po_direct'"
            >
              มี PO แล้ว — เปิดบิลโดยตรง
            </button>
          </div>
        </div>
        <div
          v-if="documentMode === 'po_direct'"
          class="risk-banner risk-banner--success"
        >
          PO ต้นฉบับเป็นหลักฐานคำสั่งซื้อ ระบบจะสร้างรายการเปิดบิลและส่ง Approve
          โดยไม่สร้างแถวใน quotations และไม่ใช้เลข QT
        </div>
      </section>
      <section class="content-card collapsible-panel">
        <button class="panel-toggle" @click="showCreditForm = !showCreditForm">
          <span
            ><strong>ขอเปิดวงเงินลูกค้าใหม่</strong
            ><small
              >ส่งข้อมูลนิติบุคคลและเอกสาร 4 รายการให้ Approve</small
            ></span
          ><b>{{ showCreditForm ? "−" : "+" }}</b>
        </button>
        <form
          v-if="showCreditForm"
          class="form-grid panel-body"
          @submit.prevent="submitCredit"
        >
          <label
            ><span>ชื่อบริษัท / ชื่อจดทะเบียน *</span
            ><input v-model="credit.company_name" required /></label
          ><label
            ><span>Tax ID 13 หลัก *</span
            ><input
              v-model="credit.tax_id"
              pattern="\d{13}"
              maxlength="13"
              required
          /></label>
          <label class="span-2"
            ><span>ที่อยู่จดทะเบียน *</span
            ><textarea
              v-model="credit.registered_address"
              required
            ></textarea></label
          ><label class="span-2"
            ><span>กรรมการผู้มีอำนาจ *</span
            ><textarea
              v-model="credit.authorized_directors"
              required
            ></textarea>
          </label>
          <label
            ><span>วงเงินที่ขอ *</span
            ><input
              v-model.number="credit.requested_credit_limit"
              type="number"
              min="1"
              required /></label
          ><label
            ><span>Credit Term *</span
            ><input
              v-model.number="credit.requested_credit_term"
              type="number"
              min="1"
              required
          /></label>
          <label
            ><span>ยอดคาดการณ์/เดือน</span
            ><input
              v-model.number="credit.expected_monthly_volume"
              type="number"
              min="0" /></label
          ><label
            ><span>เหตุผล *</span
            ><textarea v-model="credit.justification" required></textarea>
          </label>
          <label
            ><span>หนังสือรับรองบริษัท *</span
            ><input
              type="file"
              accept=".pdf,.png,.jpg,.jpeg"
              required
              @change="onFile('affidavit', $event)" /></label
          ><label
            ><span>ภ.พ.20 *</span
            ><input
              type="file"
              accept=".pdf,.png,.jpg,.jpeg"
              required
              @change="onFile('vat', $event)"
          /></label>
          <label
            ><span>บัตรประชาชนกรรมการ *</span
            ><input
              type="file"
              accept=".pdf,.png,.jpg,.jpeg"
              required
              @change="onFile('director_id', $event)" /></label
          ><label
            ><span>Statement 3–6 เดือน *</span
            ><input
              type="file"
              accept=".pdf,.png,.jpg,.jpeg,.xlsx"
              required
              @change="onFile('bank_statement', $event)"
          /></label>
          <button class="primary-button span-2" :disabled="saving">
            ส่งคำขอเปิดวงเงิน
          </button>
        </form>
      </section>

      <form
        class="content-card form-grid section-gap"
        @submit.prevent="submitCurrent"
      >
        <section
          ref="purchaseConfirmationPanel"
          class="span-2 po-confirmation-panel"
        >
          <button
            class="panel-toggle"
            type="button"
            :aria-expanded="showPurchaseConfirmation"
            aria-controls="purchase-confirmation-fields"
            @click="showPurchaseConfirmation = !showPurchaseConfirmation"
          >
            <span
              ><strong>ข้อมูลการยืนยันการซื้อขายจาก PO</strong
              ><small
                >กด + เมื่อต้องการกรอกเอง · ระบบจะเปิดให้อัตโนมัติหลัง AI อ่าน
                PO สำเร็จ</small
              ></span
            >
            <b aria-hidden="true">{{ showPurchaseConfirmation ? "−" : "+" }}</b>
          </button>
          <div
            v-show="showPurchaseConfirmation"
            id="purchase-confirmation-fields"
            class="form-grid po-confirmation-body"
          >
            <label
              ><span>เลขที่ PO / หนังสือสั่งซื้อ</span
              ><input v-model="form.customer_po_no" maxlength="255"
            /></label>
            <label
              ><span>วันที่ลูกค้ายืนยัน</span
              ><CompatDateTimeInput
                v-model="form.customer_confirmed_at"
                mode="date"
            /></label>
            <label
              ><span>ผู้ติดต่อ</span
              ><input v-model="form.customer_contact_name" maxlength="500"
            /></label>
            <label
              ><span>ช่องทางยืนยัน</span
              ><input
                v-model="form.customer_contact_channel"
                placeholder="อีเมล / LINE / โทรศัพท์ / หนังสือ"
            /></label>
            <label v-if="documentMode === 'quotation'"
              ><span>วันหมดอายุใบเสนอราคา</span
              ><CompatDateTimeInput
                v-model="form.quotation_valid_until"
                mode="date"
                :min="form.qt_date"
            /></label>
            <label class="span-2"
              ><span>เงื่อนไขการชำระเงิน</span
              ><textarea v-model="form.payment_terms"></textarea>
            </label>
            <label
              ><span>กำหนดส่งสินค้า</span
              ><CompatDateTimeInput
                v-model="form.delivery_due_date"
                mode="date"
            /></label>
            <label
              ><span>เงื่อนไขการส่งสินค้า</span
              ><input v-model="form.delivery_terms"
            /></label>
          </div>
        </section>
        <div class="span-2 section-heading form-section-title">
          <div>
            <p class="eyebrow eyebrow--red">
              {{
                documentMode === "po_direct"
                  ? "PURCHASE ORDER DIRECT"
                  : "QUOTATION"
              }}
            </p>
            <h2>
              1.
              {{
                documentMode === "po_direct"
                  ? "ข้อมูลลูกค้าและคำสั่งซื้อ"
                  : "ข้อมูลลูกค้าและใบเสนอราคา"
              }}
            </h2>
            <p class="muted">
              {{
                documentMode === "po_direct"
                  ? "ใช้ PO ต้นฉบับเป็นหลักฐานและไม่ออกเลขใบเสนอราคา"
                  : "บันทึกลงฐานข้อมูลและกลับมาพิมพ์ PDF ได้ภายหลัง"
              }}
            </p>
          </div>
          <label
            ><span>วันที่</span
            ><CompatDateTimeInput v-model="form.qt_date" mode="date" required
          /></label>
        </div>
        <label
          ><span>ค้นหาและเลือกลูกค้า *</span
          ><SearchableSelect
            v-model="form.customer"
            :options="
              (refs?.customers || []).map((customer) => ({
                value: customer.ar_code,
                label: `${customer.ar_code} | ${customer.ar_name}`,
              }))
            "
            :disabled="manualCustomer"
            placeholder="พิมพ์รหัสหรือชื่อลูกค้า"
            @change="selectCustomer"
        /></label>
        <label class="check-field"
          ><input v-model="manualCustomer" type="checkbox" />
          กรอกลูกค้าใหม่เอง</label
        >
        <label
          ><span>รหัสลูกหนี้ *</span
          ><input
            v-model="form.bplus_customer_code"
            :readonly="!manualCustomer"
            required /></label
        ><label
          ><span>ชื่อลูกค้า *</span
          ><input
            v-model="form.buyer_name"
            :readonly="!manualCustomer"
            required
        /></label>
        <label
          ><span>เลขผู้เสียภาษี (เฉพาะกรอกลูกค้าใหม่)</span
          ><input v-model="form.tax_id" maxlength="30"
        /></label>
        <label
          ><span>Credit Term ที่ลูกค้าขอ</span
          ><input
            v-model.number="form.requested_credit_term"
            type="number"
            min="0"
          /><small
            >ระบบ: {{ selectedCredit?.credit_term || 0 }} วัน · วงเงิน
            {{ money(selectedCredit?.credit_limit) }}</small
          ></label
        >
        <fieldset class="vat-mode span-2">
          <legend>รูปแบบราคาของโรงพยาบาล *</legend>
          <label
            ><input v-model="form.price_vat_mode" type="radio" value="in_vat" />
            ราคา In VAT <small>ราคาที่กรอกรวม VAT 7% แล้ว</small></label
          ><label
            ><input v-model="form.price_vat_mode" type="radio" value="ex_vat" />
            ราคา Ex VAT <small>ระบบบวก VAT 7% ตอนสรุปยอด</small></label
          >
        </fieldset>

        <div class="span-2 form-section-block">
          <div class="section-heading">
            <h2>2. รายการสินค้า</h2>
            <button
              class="secondary-button"
              type="button"
              @click="form.items.push(newItem())"
            >
              + เพิ่มรายการ
            </button>
          </div>
          <article
            v-for="(item, index) in form.items"
            :key="index"
            class="line-item"
          >
            <label class="line-item__product"
              ><span>สินค้าหลักในระบบ *</span
              ><SearchableSelect
                v-model="item.product"
                :options="
                  (refs?.products || []).map((product) => ({
                    value: product.sku_code,
                    label: `${product.sku_code} | ${product.sku_name}`,
                  }))
                "
                placeholder="พิมพ์รหัสหรือชื่อสินค้า"
                required
                @change="selectProduct(item)" /></label
            ><label
              v-if="documentMode === 'po_direct'"
              class="line-item__product"
              ><span>ชื่อสินค้าใน PO</span
              ><input
                v-model="item.customer_product_name"
                maxlength="2000"
                placeholder="ชื่อสินค้าตามใบ PO"
            /></label>
            <label
              ><span>หน่วย</span
              ><select v-model="item.unit_type">
                <option v-for="unit in unitsFor(item)" :key="unit">
                  {{ unit }}
                </option>
              </select></label
            ><label
              ><span>จำนวน</span
              ><input
                v-model.number="item.quantity"
                type="number"
                min="1"
                required /></label
            ><label
              ><span
                >ราคา
                {{
                  form.price_vat_mode === "in_vat" ? "In VAT" : "Ex VAT"
                }}</span
              ><input
                v-model.number="item.unit_price"
                type="number"
                min="0"
                step="0.01"
                required /></label
            ><label
              ><span>ส่วนลดรายการ</span
              ><input
                v-model.number="item.sale_discount_unit"
                type="number"
                min="0"
                step="0.01"
            /></label>
            <div class="line-total">
              <span>รวม</span
              ><strong>{{
                money(
                  Math.max(
                    item.quantity * item.unit_price - item.sale_discount_unit,
                    0,
                  ),
                )
              }}</strong>
            </div>
            <button
              v-if="form.items.length > 1"
              class="remove-line"
              type="button"
              @click="form.items.splice(index, 1)"
            >
              ×
            </button>
          </article>
          <label class="span-2"
            ><span>หมายเหตุส่วนลด</span
            ><textarea
              v-model="form.sale_discount_note"
              maxlength="2000"
              placeholder="ระบุรายละเอียดหรือเงื่อนไขส่วนลดเพิ่มเติม (ถ้ามี)"
            ></textarea>
          </label>
        </div>
        <!-- <label><span>ส่วนลดท้ายบิล</span><input v-model.number="form.sale_discount_total" type="number" min="0" step="0.01"></label> -->
        <div class="span-2 total-summary">
          <!-- <span>ยอดก่อนส่วนลดท้ายบิล <b>{{ money(grossTotal) }}</b></span> -->
          <span
            >VAT 7% <b>{{ money(vatAmount) }}</b></span
          ><strong>ยอดรวมสุทธิ {{ money(grandTotal) }}</strong>
        </div>
        <div class="span-2 form-section-title">
          <h2>3. ที่อยู่ออกบิลและที่อยู่จัดส่ง</h2>
          <p class="muted">
            รองรับกรณีส่งสินค้าและวางบิลคนละสถานที่ ข้อมูลจาก Bplus
            จะเติมให้อัตโนมัติเมื่อมีการ Import
          </p>
        </div>
        <label v-if="billingAddresses.length" class="span-2"
          ><span>ดึงที่อยู่ออกบิลจากสมุดที่อยู่</span
          ><SearchableSelect
            v-model="form.billing_address_id"
            :options="
              billingAddresses.map((address) => ({
                value: address.address_id,
                label: addressLabel(address),
              }))
            "
            placeholder="ค้นหาชื่อสถานที่ รหัสสาขา หรือที่อยู่"
            @change="applyBillingAddress"
          /><small
            >เป็นตัวเลือกช่วยเติมช่องด้านล่าง ไม่ใช่ที่อยู่รายการที่สาม</small
          ></label
        >
        <label class="span-2"
          ><span>ที่อยู่สำหรับออกบิล *</span
          ><textarea
            v-model="form.billing_address"
            required
            placeholder="ที่อยู่ตามข้อมูลลูกค้า/Bplus"
          ></textarea
          ><small>ระบบจะบันทึกข้อความนี้เป็น Snapshot ของเอกสาร</small></label
        >
        <label v-if="deliveryAddresses.length" class="span-2"
          ><span>ดึงที่อยู่จัดส่งจากสมุดที่อยู่</span
          ><SearchableSelect
            v-model="form.delivery_address_id"
            :options="
              deliveryAddresses.map((address) => ({
                value: address.address_id,
                label: addressLabel(address),
              }))
            "
            placeholder="ค้นหาคลัง หน่วยงาน ผู้รับ หรือที่อยู่"
            @change="applyDeliveryAddress"
          /><small
            >เมื่อเลือกแล้วระบบจะเติมข้อมูลลงช่องที่อยู่จัดส่ง</small
          ></label
        >
        <label class="span-2"
          ><span>ที่อยู่จัดส่ง *</span
          ><textarea
            v-model="form.delivery_address"
            required
            placeholder="ระบุสถานที่รับสินค้า"
          ></textarea
          ><button
            class="text-link address-copy"
            type="button"
            @click="copyBillingAddress"
          >
            ใช้ที่อยู่ออกบิล</button
          ><small
            >แก้ข้อความเฉพาะเอกสารนี้ได้โดยไม่เปลี่ยนข้อมูลหลัก</small
          ></label
        >
        <fieldset class="span-2 standard-address-options">
          <legend>ที่อยู่ที่ต้องการแสดงในใบเสนอราคามาตรฐาน</legend>
          <label
            ><input
              v-model="form.standard_show_billing_address"
              type="checkbox"
              :disabled="
                form.standard_show_billing_address &&
                !form.standard_show_delivery_address
              "
            />
            แสดงที่อยู่ออกบิล</label
          ><label
            ><input
              v-model="form.standard_show_delivery_address"
              type="checkbox"
              :disabled="
                form.standard_show_delivery_address &&
                !form.standard_show_billing_address
              "
            />
            แสดงที่อยู่จัดส่ง</label
          ><small
            >* ตัวเลือกนี้มีผลเฉพาะใบเสนอราคารูปแบบมาตรฐานเท่านั้น Template
            ของโรงพยาบาลจะแสดงข้อมูลตามรูปแบบต้นฉบับที่กำหนดไว้</small
          >
        </fieldset>
        <details v-if="manualCustomer" class="span-2 address-details">
          <summary>รายละเอียดที่อยู่แบบแยกช่อง (สำหรับลูกค้าใหม่)</summary>
          <div class="form-grid">
            <label
              ><span>บ้านเลขที่</span><input v-model="form.house_no" /></label
            ><label><span>หมู่</span><input v-model="form.moo" /></label
            ><label><span>ซอย</span><input v-model="form.soi" /></label
            ><label><span>ถนน</span><input v-model="form.road" /></label>
            <label
              ><span>จังหวัด *</span
              ><SearchableSelect
                v-model="form.province"
                :options="
                  Object.keys(locations).map((value) => ({
                    value,
                    label: value,
                  }))
                "
                required
                @change="selectProvince" /></label
            ><label
              ><span>อำเภอ/เขต *</span
              ><SearchableSelect
                v-model="form.district"
                :options="districts.map((value) => ({ value, label: value }))"
                required
                @change="selectDistrict" /></label
            ><label
              ><span>ตำบล/แขวง *</span
              ><SearchableSelect
                v-model="form.sub_district"
                :options="
                  subDistricts.map((value) => ({ value, label: value }))
                "
                required
                @change="selectSubDistrict" /></label
            ><label
              ><span>รหัสไปรษณีย์</span><input v-model="form.zipcode" readonly
            /></label>
            <label class="span-2"
              ><span>จุดสังเกตเพิ่มเติม</span
              ><textarea v-model="form.address_extra"></textarea>
            </label>
          </div>
        </details>
        <label
          ><span>ประเภทการจัดส่ง *</span
          ><select v-model="form.shipping_type" required>
            <option value="">-- เลือก --</option>
            <option v-for="shipping in refs?.shipping_types" :key="shipping">
              {{ shipping }}
            </option>
          </select></label
        ><label
          ><span>หมายเหตุการจัดส่ง</span
          ><input v-model="form.shipping_text" /></label
        ><label class="span-2"
          ><span>รายละเอียดบิล</span
          ><textarea v-model="form.bill_note"></textarea>
        </label>
        <button class="primary-button span-2" :disabled="saving">
          {{
            saving
              ? "กำลังบันทึก…"
              : documentMode === "po_direct"
                ? "สร้างรายการจาก PO และส่งอนุมัติ"
                : "ยืนยันบันทึกใบเสนอราคา"
          }}
        </button>
      </form>

      <section class="content-card section-gap">
        <div class="section-heading">
          <div>
            <p class="eyebrow eyebrow--red">Quotation to bill</p>
            <h2>สร้างบิลจากใบเสนอราคา</h2>
            <p class="muted">
              รายการทั้งหมดเป็น Draft และจะแก้ไขได้จนกว่าจะกดสร้างและส่งอนุมัติ
            </p>
          </div>
          <form class="search-row" @submit.prevent="refreshQuotations(1)">
            <label
              ><span>ค้นหาใบเสนอราคา</span
              ><input
                v-model="quotationSearch"
                placeholder="เลขที่หรือลูกค้า" /></label
            ><button class="secondary-button">ค้นหา</button>
          </form>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>เลขที่</th>
                <th>ลูกค้า</th>
                <th>ยอดรวม</th>
                <th>สถานะ</th>
                <th>วันที่บิล</th>
                <th>จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="quote in quotations" :key="quote.qt_id">
                <td class="strong">{{ quote.qt_no }}</td>
                <td>
                  {{ quote.billing_record?.buyer_name || quote.buyer_name }}
                </td>
                <td>
                  {{
                    money(
                      quote.billing_record?.total_amount || quote.total_amount,
                    )
                  }}
                </td>
                <td><StatusPill :status="quotationStatus(quote)" /></td>
                <td>{{ quote.billing_record?.bill_date || quote.qt_date }}</td>
                <td class="actions">
                  <button
                    class="primary-button primary-button--compact"
                    @click="openSubmissionChecklist(quote)"
                  >
                    สร้างและส่งอนุมัติ
                  </button>
                </td>
              </tr>
              <tr v-if="!quotations.length">
                <td colspan="6" class="empty-cell">ไม่พบใบเสนอราคา</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="quotationPage" class="pagination">
          <span
            >แสดง {{ quotationPage.from || 0 }}–{{ quotationPage.to || 0 }} จาก
            {{ quotationPage.total }} รายการ</span
          >
          <div>
            <button
              class="secondary-button"
              :disabled="!quotationPage.prev_page_url"
              @click="refreshQuotations(quotationPage.current_page - 1)"
            >
              ก่อนหน้า</button
            ><span
              >หน้า {{ quotationPage.current_page }} /
              {{ quotationPage.last_page }}</span
            ><button
              class="secondary-button"
              :disabled="!quotationPage.next_page_url"
              @click="refreshQuotations(quotationPage.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </section>
      <Teleport to="body">
        <div
          v-if="submissionDialogOpen && pendingQuotation"
          class="submission-overlay"
          @click.self="closeSubmissionChecklist"
        >
          <section
            class="submission-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="submission-title"
          >
            <header>
              <div>
                <p class="eyebrow eyebrow--red">LEGAL SUBMISSION CHECKLIST</p>
                <h2 id="submission-title">เลือกเอกสารที่ต้องใช้ยื่นเสนอราคา</h2>
                <p>
                  {{ pendingQuotation.qt_no }} ·
                  {{ pendingQuotation.buyer_name }}
                </p>
              </div>
              <button
                class="secondary-button"
                type="button"
                aria-label="ปิด"
                @click="closeSubmissionChecklist"
              >
                ×
              </button>
            </header>
            <div class="submission-notice">
              เลือกรายการตาม TOR ประกาศ หรือข้อกำหนดของหน่วยงาน
              เอกสารที่เลือกจะถูกบันทึกไว้กับงานเพื่อให้ Approve ตรวจสอบ
            </div>
            <p v-if="submissionError" class="form-error submission-error">
              {{ submissionError }}
            </p>
            <div class="submission-options">
              <label
                v-for="option in submissionDocumentOptions"
                :key="option.key"
                :class="{ selected: submissionDocuments[option.key] }"
              >
                <input
                  v-model="submissionDocuments[option.key]"
                  type="checkbox"
                  :disabled="option.key === 'quotation'"
                />
                <span
                  ><strong>{{ option.label }}</strong
                  ><small>{{ option.help }}</small></span
                >
              </label>
            </div>
            <footer>
              <button
                class="secondary-button"
                type="button"
                :disabled="saving"
                @click="closeSubmissionChecklist"
              >
                ยกเลิก</button
              ><button
                class="primary-button"
                type="button"
                :disabled="saving"
                @click="createBill"
              >
                {{ saving ? "กำลังส่ง…" : "ยืนยันรายการเอกสารและส่งอนุมัติ" }}
              </button>
            </footer>
          </section>
        </div>
      </Teleport>
    </template>
  </AppShell>
</template>
<style scoped>
.submission-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(15, 23, 42, 0.58);
  backdrop-filter: blur(3px);
}
.submission-dialog {
  width: min(720px, 100%);
  max-height: min(88vh, 850px);
  overflow: auto;
  border: 1px solid #d9dee7;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
}
.submission-dialog > header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 22px 24px 15px;
  border-bottom: 1px solid #e5e7eb;
}
.submission-dialog h2 {
  margin: 4px 0;
}
.submission-dialog header p:last-child {
  margin: 0;
  color: #64748b;
}
.submission-notice {
  margin: 16px 20px;
  padding: 12px 14px;
  border-radius: 10px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 0.88rem;
}
.submission-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding: 0 20px 20px;
}
.submission-options label {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 13px;
  border: 1px solid #dfe4ec;
  border-radius: 11px;
  cursor: pointer;
}
.submission-options label.selected {
  border-color: #c72d3a;
  background: #fff7f7;
}
.submission-options input {
  margin-top: 3px;
}
.submission-options span {
  display: grid;
  gap: 3px;
}
.submission-options small {
  color: #64748b;
  line-height: 1.35;
}
.submission-dialog > footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 15px 20px;
  border-top: 1px solid #e5e7eb;
}
@media (max-width: 640px) {
  .submission-overlay {
    padding: 8px;
    place-items: end center;
  }
  .submission-dialog {
    max-height: 92vh;
    border-radius: 16px 16px 0 0;
  }
  .submission-options {
    grid-template-columns: 1fr;
  }
  .submission-dialog > footer {
    position: sticky;
    bottom: 0;
    background: #fff;
  }
}
.vat-mode {
  display: flex;
  gap: 16px;
  margin: 0;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 10px;
}
.vat-mode label {
  display: flex;
  align-items: center;
  gap: 8px;
}
.vat-mode small {
  color: var(--muted);
}
.address-copy {
  align-self: start;
  margin-top: 5px;
  border: 0;
  background: transparent;
}
.address-details {
  padding: 10px 12px;
  border: 1px solid var(--line);
  border-radius: 10px;
}
.address-details summary {
  cursor: pointer;
  font-weight: 600;
}
.address-details .form-grid {
  margin-top: 12px;
}
.standard-address-options {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 20px;
  margin: 0;
  padding: 12px 14px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  background: #f8fafc;
}
.standard-address-options label {
  display: flex;
  align-items: center;
  gap: 7px;
}
.standard-address-options input {
  width: auto;
}
.standard-address-options small {
  flex-basis: 100%;
  color: #94a3b8;
  font-size: 12px;
}
</style>
