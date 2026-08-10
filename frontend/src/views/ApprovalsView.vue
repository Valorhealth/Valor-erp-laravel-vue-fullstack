<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { BillingRecord, CreditRequest, Paginated } from '../types/api'
import { apiError, money, shortDate } from '../utils/format'

const bills = ref<BillingRecord[]>([])
const credits = ref<CreditRequest[]>([])
const creditPage = ref<Paginated<CreditRequest> | null>(null)
const loading = ref(true)
const error = ref('')
const message = ref('')
const rejectBillReasons = reactive<Record<number, string>>({})
const rejectCreditReasons = reactive<Record<number, string>>({})

async function load(creditPageNumber = creditPage.value?.current_page || 1) {
  loading.value = true
  error.value = ''
  try {
    const [billResponse, creditResponse] = await Promise.all([
      api.get<{ data: BillingRecord[] }>('/api/v1/bills/pending-approval'),
      api.get<Paginated<CreditRequest>>('/api/v1/credit-requests', {
        params: { status: 'รออนุมัติวงเงิน', page: creditPageNumber, per_page: 20 },
      }),
    ])
    bills.value = billResponse.data.data
    creditPage.value = creditResponse.data
    credits.value = creditResponse.data.data
  } catch (cause) {
    error.value = apiError(cause)
  } finally {
    loading.value = false
  }
}

async function action(url: string, data: unknown, success: string) {
  error.value = ''
  message.value = ''
  try {
    await api.post(url, data)
    message.value = success
    await load()
  } catch (cause) {
    error.value = apiError(cause)
  }
}

function display(value: unknown): string {
  const text = String(value ?? '').trim()
  return text || '-'
}

function workflowLabel(workflow?: string | null): string {
  if (workflow === 'credit_first') return 'ขอเปิดวงเงินก่อนขาย'
  if (workflow === 'existing_customer') return 'ขายจากใบเสนอราคา'
  return display(workflow)
}

function risk(bill: BillingRecord) {
  const limit = Number(bill.credit_limit || 0)
  const remain = bill.credit_remain === null || bill.credit_remain === undefined ? limit : Number(bill.credit_remain)
  const after = remain - Number(bill.total_amount || 0)

  if (!limit || after < 0) {
    return { text: `เสี่ยงสูง: เครดิตหลังบิล ${money(after)}`, type: 'danger' }
  }
  if (after / limit < 0.2) {
    return { text: `เสี่ยงน้อย: เครดิตหลังบิล ${money(after)}`, type: 'warning' }
  }
  return { text: `เครดิตผ่าน: คงเหลือหลังบิล ${money(after)}`, type: 'success' }
}

function totalDiscount(bill: BillingRecord): number {
  const lineDiscount = bill.items?.reduce((sum, item) => sum + Number(item.sale_discount_unit || 0), 0) || 0
  return lineDiscount + Number(bill.sale_discount_total || 0)
}

onMounted(load)
</script>

<template>
  <AppShell title="พิจารณาอนุมัติบิล" subtitle="ตรวจคำขอวงเงินและบิลที่รอหัวหน้าพิจารณา">
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <div v-if="loading" class="loading-state">กำลังโหลดคิวอนุมัติ…</div>

    <template v-else>
      <section>
        <div class="section-heading">
          <div>
            <p class="eyebrow eyebrow--red">Credit requests</p>
            <h2>คำขอวงเงินลูกค้า</h2>
          </div>
          <span class="count-badge">{{ credits.length }}</span>
        </div>

        <div class="list-stack">
          <article
            v-for="request in credits"
            :key="request.request_id"
            class="content-card request-card"
          >
            <div class="section-heading">
              <div>
                <h2>
                  #{{ request.request_id }} ·
                  {{ request.request_type === 'change_existing' ? 'แก้ไขวงเงินเดิม' : 'เปิดวงเงินใหม่' }}
                </h2>
                <p>{{ request.company_name }} · ผู้ขอ {{ request.created_by }}</p>
              </div>
              <StatusPill :status="request.status" />
            </div>

            <div class="detail-grid">
              <p><span>วงเงินเดิม</span><strong>{{ money(request.current_credit_limit) }}</strong></p>
              <p><span>วงเงินที่ขอ</span><strong>{{ money(request.requested_credit_limit) }}</strong></p>
              <p><span>Term เดิม</span><strong>{{ request.current_credit_term || 0 }} วัน</strong></p>
              <p><span>Term ที่ขอ</span><strong>{{ request.requested_credit_term }} วัน</strong></p>
              <p><span>ยอดคาดการณ์/เดือน</span><strong>{{ money(request.expected_monthly_volume) }}</strong></p>
              <p><span>Tax ID</span><strong>{{ display(request.tax_id) }}</strong></p>
            </div>

            <div v-if="request.request_type === 'new_customer'" class="detail-copy">
              <p><strong>ที่อยู่จดทะเบียน:</strong> {{ display(request.registered_address) }}</p>
              <p><strong>กรรมการผู้มีอำนาจ:</strong> {{ display(request.authorized_directors) }}</p>
            </div>
            <p v-else><strong>รหัสลูกหนี้เดิม:</strong> {{ display(request.ar_code) }}</p>
            <p><strong>เหตุผล:</strong> {{ request.justification }}</p>
            <div v-if="request.documents?.length" class="document-links">
              <button
                v-for="doc in request.documents"
                :key="doc.doc_id"
                class="secondary-button"
                @click="downloadFile(`/api/v1/credit-requests/${request.request_id}/documents/${doc.doc_id}`, doc.file_name)"
              >
                {{ doc.doc_type }}
              </button>
            </div>

            <div class="approval-actions">
              <button
                class="primary-button"
                @click="action(`/api/v1/credit-requests/${request.request_id}/approve`, {}, `อนุมัติคำขอ #${request.request_id} แล้ว`)"
              >
                อนุมัติวงเงิน
              </button>
              <label>
                <span>เหตุผลที่ไม่อนุมัติ</span>
                <input v-model="rejectCreditReasons[request.request_id]">
              </label>
              <button
                class="danger-button"
                :disabled="!rejectCreditReasons[request.request_id]"
                @click="action(`/api/v1/credit-requests/${request.request_id}/reject`, { reason: rejectCreditReasons[request.request_id] }, `ไม่อนุมัติคำขอ #${request.request_id}`)"
              >
                ไม่อนุมัติ
              </button>
            </div>
          </article>

          <div v-if="credits.length === 0" class="content-card empty-state">
            ไม่มีคำขอวงเงินค้างอนุมัติ
          </div>
        </div>
        <div v-if="creditPage && creditPage.last_page > 1" class="pagination">
          <span>คำขอ {{ creditPage.from || 0 }}–{{ creditPage.to || 0 }} จาก {{ creditPage.total }}</span>
          <div><button class="secondary-button" :disabled="!creditPage.prev_page_url" @click="load(creditPage.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ creditPage.current_page }} / {{ creditPage.last_page }}</span><button class="secondary-button" :disabled="!creditPage.next_page_url" @click="load(creditPage.current_page + 1)">ถัดไป</button></div>
        </div>
      </section>

      <section class="section-gap">
        <div class="section-heading">
          <div>
            <p class="eyebrow eyebrow--red">Billing approval</p>
            <h2>บิลที่รอหัวหน้าอนุมัติ</h2>
          </div>
          <span class="count-badge">{{ bills.length }}</span>
        </div>

        <div class="list-stack">
          <article
            v-for="bill in bills"
            :key="bill.bill_id"
            class="content-card approval-card"
          >
            <div class="section-heading approval-card__header">
              <div>
                <h2>บิล #{{ bill.bill_id }} · {{ bill.buyer_name }}</h2>
                <p>{{ shortDate(bill.bill_date) }} · ผู้ขอ {{ bill.created_by }}</p>
              </div>
              <div class="approval-card__status">
                <StatusPill :status="bill.status" />
                <strong class="approval-total">{{ money(bill.total_amount) }}</strong>
              </div>
            </div>

            <section class="approval-detail-section">
              <h3>ข้อมูลลูกค้าและเอกสาร</h3>
              <div class="approval-info-grid">
                <div><span>ชื่อลูกค้า</span><strong>{{ display(bill.buyer_name) }}</strong></div>
                <div><span>รหัสลูกค้า Bplus</span><strong>{{ display(bill.bplus_customer_code) }}</strong></div>
                <div><span>เลขผู้เสียภาษี</span><strong>{{ display(bill.tax_id) }}</strong></div>
                <div><span>วันที่บิล</span><strong>{{ shortDate(bill.bill_date) }}</strong></div>
                <div><span>ใบเสนอราคาอ้างอิง</span><strong>{{ bill.ref_qt_id ? `#${bill.ref_qt_id}` : '-' }}</strong></div>
                <div><span>รูปแบบการขาย</span><strong>{{ workflowLabel(bill.workflow_type) }}</strong></div>
              </div>
            </section>

            <section class="approval-detail-section">
              <h3>ที่อยู่และการจัดส่ง</h3>
              <div class="approval-info-grid">
                <div class="approval-info-grid__wide">
                  <span>ที่อยู่เต็ม</span>
                  <strong>{{ display(bill.address_note) }}</strong>
                </div>
                <div><span>จังหวัด</span><strong>{{ display(bill.province) }}</strong></div>
                <div><span>อำเภอ/เขต</span><strong>{{ display(bill.district) }}</strong></div>
                <div><span>ตำบล/แขวง</span><strong>{{ display(bill.sub_district) }}</strong></div>
                <div><span>รหัสไปรษณีย์</span><strong>{{ display(bill.zipcode) }}</strong></div>
                <div><span>ประเภทการจัดส่ง</span><strong>{{ display(bill.shipping_type) }}</strong></div>
                <div class="approval-info-grid__wide">
                  <span>หมายเหตุการจัดส่ง</span>
                  <strong>{{ display(bill.shipping_text) }}</strong>
                </div>
              </div>
            </section>

            <section class="approval-detail-section">
              <h3>เครดิต ส่วนลด และรายละเอียดบิล</h3>
              <div class="approval-info-grid">
                <div><span>วงเงิน</span><strong>{{ money(bill.credit_limit) }}</strong></div>
                <div><span>ค้างชำระ</span><strong>{{ money(bill.credit_outstanding) }}</strong></div>
                <div><span>คงเหลือ</span><strong>{{ money(bill.credit_remain) }}</strong></div>
                <div><span>Credit Term ในระบบ</span><strong>{{ bill.credit_term || 0 }} วัน</strong></div>
                <div><span>Credit Term ที่ขอ</span><strong>{{ bill.requested_credit_term || 0 }} วัน</strong></div>
                <div><span>ส่วนลดท้ายบิล</span><strong>{{ money(bill.sale_discount_total) }}</strong></div>
                <div><span>ส่วนลดรวมทั้งหมด</span><strong>{{ money(totalDiscount(bill)) }}</strong></div>
                <div class="approval-info-grid__wide">
                  <span>หมายเหตุส่วนลด</span>
                  <strong>{{ display(bill.sale_discount_note) }}</strong>
                </div>
                <div class="approval-info-grid__wide">
                  <span>รายละเอียดบิล</span>
                  <strong>{{ display(bill.bill_note) }}</strong>
                </div>
              </div>
            </section>

            <p class="risk-banner" :class="`risk-banner--${risk(bill).type}`">
              {{ risk(bill).text }}
            </p>

            <section class="approval-detail-section approval-detail-section--items">
              <h3>รายการสินค้าที่ Sale กรอก</h3>
              <div class="table-wrap">
                <table class="approval-items-table">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>สินค้า</th>
                      <th>หน่วย</th>
                      <th>จำนวน</th>
                      <th>ราคาต่อหน่วย</th>
                      <th>ยอดก่อนลด</th>
                      <th>ส่วนลดรายการ</th>
                      <th>ยอดสุทธิ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in bill.items" :key="item.item_id">
                      <td>{{ display(item.main_category) }}</td>
                      <td>{{ display(item.sub_category) }}</td>
                      <td>{{ display(item.unit_type) }}</td>
                      <td>{{ item.quantity || 0 }}</td>
                      <td>{{ money(item.unit_price) }}</td>
                      <td>{{ money(item.line_total_before_discount) }}</td>
                      <td>{{ money(item.sale_discount_unit) }}</td>
                      <td class="strong">{{ money(item.line_total) }}</td>
                    </tr>
                    <tr v-if="!bill.items?.length">
                      <td colspan="8" class="empty-cell">ไม่พบรายการสินค้า</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>

            <div class="approval-actions approval-actions--bill">
              <button
                class="primary-button"
                @click="action(`/api/v1/bills/${bill.bill_id}/approve`, {}, `อนุมัติบิล #${bill.bill_id} แล้ว`)"
              >
                อนุมัติบิลนี้
              </button>
              <button
                class="danger-button"
                :disabled="!rejectBillReasons[bill.bill_id]"
                @click="action(`/api/v1/bills/${bill.bill_id}/reject`, { reason: rejectBillReasons[bill.bill_id] }, `ตีกลับบิล #${bill.bill_id} แล้ว`)"
              >
                ตีกลับให้แก้ไข
              </button>
              <label>
                <span>เหตุผลตีกลับ</span>
                <input
                  v-model="rejectBillReasons[bill.bill_id]"
                  placeholder="ระบุจุดที่ต้องการให้ Sale แก้ไข"
                >
              </label>
            </div>
          </article>

          <div v-if="bills.length === 0" class="content-card empty-state">
            ไม่มีบิลค้างอนุมัติ
          </div>
        </div>
      </section>
    </template>
  </AppShell>
</template>
