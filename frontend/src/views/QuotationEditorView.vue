<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import { api, downloadFile } from '../api/client'
import { apiError } from '../utils/format'
import { auth } from '../stores/auth'

type EditorItem = {
  row_key: string
  sku: string
  description: string
  quantity: number
  quantity_display?: string
  unit: string
  pack: string
  unit_price: number
  unit_price_display?: string
  discount: number
  discount_display?: string
  remark: string
  origin_details: string
  image: string
}
type TableColumn = {
  id: string
  field: 'sequence' | 'sku' | 'description' | 'quantity' | 'unit' | 'pack' | 'unit_price' | 'discount' | 'amount' | 'remark' | 'origin_details'
  label: string
  width: number
  align: 'left' | 'center' | 'right'
}
type EditorPayload = {
  template: string
  mode: 'original' | 'copy' | 'revised'
  price_vat_mode: 'in_vat' | 'ex_vat'
  document: {
    number: string
    date: string
    title: string
    english_title: string
    company_name: string
    company_address: string
    company_contact: string
    company_tax_id: string
    branch: string
    subject: string
    recipient: string
    intro: string
    salesperson: string
    signature_role: string
    contact: string
    confirmation_terms: string
    document_note: string
  }
  customer: {
    code: string
    address: string
    billing_address: string
    delivery_address: string
    show_billing_address: boolean
    show_delivery_address: boolean
    tax_id: string
  }
  items: EditorItem[]
  table_layout: TableColumn[]
  layout: {
    show_summary: boolean
    show_customer_details: boolean
    show_confirmation_terms: boolean
    show_document_note: boolean
    show_company_signature: boolean
    show_customer_signature: boolean
    title_alignment: 'left' | 'center' | 'right'
    logo_alignment: 'left' | 'center' | 'right'
  }
  bill_discount: number
  power_of_attorney: {
    enabled: boolean
    template_id: 'general-company' | 'anandamahidol' | 'egp' | 'grantee-company-form' | 'anandamahidol-ebidding'
    authorization_no: string
    issue_date: string
    place: string
    company_name: string
    company_address: string
    grantor_text: string
    grantor_one_name: string
    grantor_two_name: string
    grantee_name: string
    grantee_job_title: string
    grantee_address: string
    project: string
    organization: string
    sale_identity_no: string
    national_id_issued_at: string
    national_id_expires_at: string
    authority_note: string
    acceptance_text: string
    body_intro: string
    body_scope: string
    body_closing: string
    witness_one_name: string
    witness_two_name: string
    footer_text: string
    stamp_text: string
    authority_quote: boolean
    authority_documents: boolean
    authority_negotiate: boolean
    authority_modify: boolean
    authority_contract: boolean
    authority_order: boolean
  }
  terms: string[]
}
type Version = { document_version_id: number; revision_no: number; filename: string; pdf_sha256: string; confirmed_by: string; created_at: string }
type HelpRequest = { help_request_id: number; request_note: string; status: 'pending' | 'in_progress' | 'resolved' | 'cancelled'; requested_by: string; assigned_to: string | null; resolution_note: string | null; requested_at: string; accepted_at: string | null; resolved_at: string | null }
type EditorResponse = { payload: EditorPayload; lock_version: number; saved_at: string | null; versions: Version[]; locked: boolean; status: string | null; approved_version_id: number | null; help_request: HelpRequest | null; can_manage_help: boolean }

const route = useRoute()
const router = useRouter()
const quotationId = Number(route.params.quotationId)
const template = String(route.query.template || 'standard')
const mode = String(route.query.mode || 'original') as EditorPayload['mode']
const payload = ref<EditorPayload | null>(null)
const lockVersion = ref(0)
const versions = ref<Version[]>([])
const locked = ref(false)
const documentStatus = ref('')
const approvedVersionId = ref<number | null>(null)
const loading = ref(true)
const saving = ref(false)
const previewing = ref(false)
const confirming = ref(false)
const error = ref('')
const saveMessage = ref('')
const previewUrl = ref('')
const exactHtml = ref('')
const exactFrame = ref<HTMLIFrameElement | null>(null)
const authorizationPreviewUrl = ref('')
const authorizationExactHtml = ref('')
const authorizationFrame = ref<HTMLIFrameElement | null>(null)
const authorizationPreviewing = ref(false)
const authorizationSaving = ref(false)
const mobilePane = ref<'edit' | 'preview'>('edit')
const products = ref<{ sku_code: string; sku_name: string }[]>([])
const productToAdd = ref('')
const undoStack = ref<EditorPayload[]>([])
const redoStack = ref<EditorPayload[]>([])
const helpRequest = ref<HelpRequest | null>(null)
const canManageHelp = ref(false)
const requestHelpChecked = ref(false)
const helpNote = ref('')
const helpActionNote = ref('')
const helpSaving = ref(false)
const activeHelpRequest = computed(() => helpRequest.value && ['pending', 'in_progress'].includes(helpRequest.value.status) ? helpRequest.value : null)
const canManagePowerOfAttorney = computed(() => auth.can('document_preparation_queue') || auth.can('system_admin'))
let saveTimer: number | null = null
let previewTimer: number | null = null
let historyTimer: number | null = null
let ignoreChanges = true
let requestSequence = 0
let htmlRequestSequence = 0
let authorizationRequestSequence = 0
let authorizationHtmlRequestSequence = 0
let changeSequence = 0
const AUTO_SAVE_DELAY_MS = 2_500
const AUTO_PREVIEW_DELAY_MS = 3_500
const CHANGE_HISTORY_DELAY_MS = 900

const templateNames: Record<string, string> = {
  standard: 'ใบเสนอราคามาตรฐาน', siriraj: 'รพ.ศิริราช', 'luangpho-thaweesak': 'รพ.หลวงพ่อทวีศักดิ์',
  'police-general': 'รพ.ตำรวจ', 'charoenkrung-pracharak': 'เจริญกรุงประชารักษ์', ramathibodi: 'รพ.รามา',
  'siriraj-piyamaharajkarun': 'รพ.ศิริราชปิย',
}
function clonePayload(value: EditorPayload): EditorPayload {
  // Vue wraps the editor document in reactive proxies, which cannot be passed
  // to structuredClone in Chromium. The payload is deliberately JSON-only.
  return JSON.parse(JSON.stringify(value)) as EditorPayload
}
function checkpoint() {
  if (!payload.value) return
  const latest = undoStack.value.at(-1)
  const current = JSON.stringify(payload.value)
  if (!latest || JSON.stringify(latest) !== current) {
    undoStack.value.push(clonePayload(payload.value))
    if (undoStack.value.length > 40) undoStack.value.shift()
  }
  redoStack.value = []
}
function restore(next: EditorPayload) {
  ignoreChanges = true
  payload.value = clonePayload(next)
  nextTick(() => { ignoreChanges = false; changed(); refreshExactEditor(); refreshAuthorizationEditor() })
}
function undo() {
  if (!payload.value || undoStack.value.length < 2) return
  redoStack.value.push(clonePayload(payload.value))
  undoStack.value.pop()
  const previous = undoStack.value.at(-1)
  if (previous) restore(previous)
}
function redo() {
  if (!payload.value) return
  const next = redoStack.value.pop()
  if (!next) return
  undoStack.value.push(clonePayload(next))
  restore(next)
}
function addItem() {
  if (!payload.value || payload.value.items.length >= 100) return
  const product = products.value.find((option) => option.sku_code === productToAdd.value)
  if (!product) { error.value = 'กรุณาค้นหาและเลือกสินค้าจาก Master Product ก่อนเพิ่มรายการ'; return }
  checkpoint()
  payload.value.items.push({ row_key: `new-${Date.now()}-${Math.random().toString(36).slice(2)}`, sku: product.sku_code, description: product.sku_name, quantity: 1, unit: 'ชิ้น', pack: '1 ชิ้น', unit_price: 0, discount: 0, remark: '', origin_details: '', image: '' })
  productToAdd.value = ''
  nextTick(refreshExactEditor)
}
function removeItem(index: number) {
  if (!payload.value || payload.value.items.length <= 1) return
  checkpoint(); payload.value.items.splice(index, 1); nextTick(refreshExactEditor)
}
function addTerm() { if (payload.value && payload.value.terms.length < 30) { checkpoint(); payload.value.terms.push('เงื่อนไขเพิ่มเติม'); nextTick(refreshExactEditor) } }
function removeTerm(index: number) { if (payload.value) { checkpoint(); payload.value.terms.splice(index, 1); nextTick(refreshExactEditor) } }
function toggleSummary() {
  if (!payload.value) return
  checkpoint()
  payload.value.layout.show_summary = !payload.value.layout.show_summary
  changed()
  nextTick(refreshExactEditor)
}
function toggleCustomerDetails() {
  if (!payload.value) return
  checkpoint()
  payload.value.layout.show_customer_details = !payload.value.layout.show_customer_details
  changed()
  nextTick(refreshExactEditor)
}
function toggleDocumentSection(section: 'show_confirmation_terms' | 'show_document_note' | 'show_company_signature' | 'show_customer_signature') {
  if (!payload.value) return
  checkpoint()
  payload.value.layout[section] = !payload.value.layout[section]
  changed()
  nextTick(refreshExactEditor)
}
function toggleTitleAlignment() {
  if (!payload.value) return
  checkpoint()
  const positions: EditorPayload['layout']['title_alignment'][] = ['left', 'center', 'right']
  payload.value.layout.title_alignment = positions[(positions.indexOf(payload.value.layout.title_alignment) + 1) % positions.length]
  changed()
  nextTick(refreshExactEditor)
}
function toggleLogoAlignment() {
  if (!payload.value) return
  checkpoint()
  const positions: EditorPayload['layout']['logo_alignment'][] = ['left', 'center', 'right']
  payload.value.layout.logo_alignment = positions[(positions.indexOf(payload.value.layout.logo_alignment) + 1) % positions.length]
  changed()
  nextTick(refreshExactEditor)
}
function alignmentLabel(position: 'left' | 'center' | 'right') {
  return ({ left: 'ซ้าย', center: 'กลาง', right: 'ขวา' } as const)[position]
}
function changed() {
  if (ignoreChanges || locked.value || !payload.value) return
  changeSequence += 1
  saveMessage.value = 'มีการเปลี่ยนแปลง กำลังเตรียมบันทึก…'
  if (saveTimer !== null) window.clearTimeout(saveTimer)
  if (previewTimer !== null) window.clearTimeout(previewTimer)
  if (historyTimer !== null) window.clearTimeout(historyTimer)
  saveTimer = window.setTimeout(() => {
    saveTimer = null
    void saveDraft()
  }, AUTO_SAVE_DELAY_MS)
  previewTimer = window.setTimeout(async () => {
    previewTimer = null
    await Promise.all([refreshPreview(), refreshAuthorizationPreview()])
  }, AUTO_PREVIEW_DELAY_MS)
  historyTimer = window.setTimeout(checkpoint, CHANGE_HISTORY_DELAY_MS)
}
async function saveDraft() {
  if (!payload.value || locked.value || saving.value) return
  const savingSequence = changeSequence
  const payloadSnapshot = clonePayload(payload.value)
  saving.value = true; error.value = ''
  try {
    const response = await api.put<{ data: { lock_version: number; saved_at: string } }>(`/api/v1/quotations/${quotationId}/document-editor/draft`, {
      template, mode, lock_version: lockVersion.value, payload: payloadSnapshot,
    })
    lockVersion.value = response.data.data.lock_version
    saveMessage.value = `บันทึกอัตโนมัติแล้ว ${new Date(response.data.data.saved_at).toLocaleTimeString('th-TH')}`
  } catch (cause) { error.value = apiError(cause, 'บันทึก Draft ไม่สำเร็จ'); saveMessage.value = 'ยังไม่ได้บันทึก' }
  finally {
    saving.value = false
    if (changeSequence > savingSequence) {
      if (saveTimer !== null) window.clearTimeout(saveTimer)
      saveTimer = window.setTimeout(() => {
        saveTimer = null
        void saveDraft()
      }, AUTO_SAVE_DELAY_MS)
    }
  }
}
async function refreshPreview() {
  if (!payload.value) return
  const sequence = ++requestSequence
  const payloadSnapshot = clonePayload(payload.value)
  previewing.value = true
  try {
    if (locked.value && !approvedVersionId.value) throw new Error('รูปแบบนี้ไม่ใช่ใบเสนอราคาฉบับที่ส่งอนุมัติ กรุณาเปิดรูปแบบที่ยืนยันไว้')
    const response = locked.value
      ? await api.get<Blob>(`/api/v1/quotations/${quotationId}/document-versions/${approvedVersionId.value}/pdf`, { responseType: 'blob', timeout: 120_000 })
      : await api.post<Blob>(`/api/v1/quotations/${quotationId}/document-editor/preview`, { template, mode, payload: payloadSnapshot }, { responseType: 'blob', timeout: 120_000 })
    if (sequence !== requestSequence) return
    const nextUrl = URL.createObjectURL(response.data)
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
    previewUrl.value = nextUrl
  } catch (cause) { if (sequence === requestSequence) error.value = await previewError(cause) }
  finally { if (sequence === requestSequence) previewing.value = false }
}
async function previewError(cause: unknown): Promise<string> {
  const data = (cause as { response?: { data?: unknown } })?.response?.data
  if (data instanceof Blob) {
    try {
      const decoded = JSON.parse(await data.text()) as { message?: string; errors?: Record<string, string[]> }
      return (decoded.errors ? Object.values(decoded.errors).flat()[0] : undefined) || decoded.message || 'สร้างตัวอย่าง PDF ไม่สำเร็จ'
    } catch { /* Non-JSON responses use the standard fallback below. */ }
  }
  return apiError(cause, 'สร้างตัวอย่าง PDF ไม่สำเร็จ')
}
async function refreshExactEditor() {
  if (!payload.value || locked.value) return
  const sequence = ++htmlRequestSequence
  const payloadSnapshot = clonePayload(payload.value)
  try {
    const response = await api.post<string>(`/api/v1/quotations/${quotationId}/document-editor/preview.html`, { template, mode, payload: payloadSnapshot }, { responseType: 'text' })
    if (sequence === htmlRequestSequence) exactHtml.value = response.data
  } catch (cause) {
    if (sequence === htmlRequestSequence) error.value = apiError(cause, 'สร้างเอกสารแก้ไขตาม Template ไม่สำเร็จ')
  }
}
async function refreshAuthorizationPreview() {
  if (!canManagePowerOfAttorney.value || !payload.value || mode !== 'original' || !payload.value.power_of_attorney.enabled) return
  const sequence = ++authorizationRequestSequence
  authorizationPreviewing.value = true
  try {
    const response = await api.post<Blob>(`/api/v1/quotations/${quotationId}/document-editor/power-of-attorney/preview`, { template, mode, payload: payload.value }, { responseType: 'blob' })
    if (sequence !== authorizationRequestSequence) return
    const nextUrl = URL.createObjectURL(response.data)
    if (authorizationPreviewUrl.value) URL.revokeObjectURL(authorizationPreviewUrl.value)
    authorizationPreviewUrl.value = nextUrl
  } catch (cause) {
    if (sequence === authorizationRequestSequence) error.value = apiError(cause, 'สร้างตัวอย่างหนังสือมอบอำนาจไม่สำเร็จ')
  } finally {
    if (sequence === authorizationRequestSequence) authorizationPreviewing.value = false
  }
}
async function refreshAuthorizationEditor() {
  if (!canManagePowerOfAttorney.value || !payload.value || mode !== 'original' || !payload.value.power_of_attorney.enabled) return
  const sequence = ++authorizationHtmlRequestSequence
  try {
    const response = await api.post<string>(`/api/v1/quotations/${quotationId}/document-editor/power-of-attorney/preview.html`, { template, mode, payload: payload.value }, { responseType: 'text' })
    if (sequence === authorizationHtmlRequestSequence) authorizationExactHtml.value = response.data
  } catch (cause) {
    if (sequence === authorizationHtmlRequestSequence) error.value = apiError(cause, 'สร้างหน้าแก้ไขหนังสือมอบอำนาจไม่สำเร็จ')
  }
}
async function refreshAuthorizationWorkspace() {
  await Promise.all([refreshAuthorizationEditor(), refreshAuthorizationPreview()])
}
async function saveAuthorizationDraft() {
  if (!payload.value || !canManagePowerOfAttorney.value) return
  authorizationSaving.value = true; error.value = ''
  try {
    await api.put(`/api/v1/quotations/${quotationId}/document-editor/power-of-attorney`, { template, mode, payload: payload.value })
    saveMessage.value = 'บันทึก Draft หนังสือมอบอำนาจแล้ว'
    await refreshAuthorizationWorkspace()
  } catch (cause) { error.value = apiError(cause, 'บันทึกหนังสือมอบอำนาจไม่สำเร็จ') }
  finally { authorizationSaving.value = false }
}
async function toggleAuthorization() {
  if (!payload.value) return
  if (!payload.value.power_of_attorney.enabled) {
    authorizationExactHtml.value = ''
    if (authorizationPreviewUrl.value) URL.revokeObjectURL(authorizationPreviewUrl.value)
    authorizationPreviewUrl.value = ''
    return
  }
  await nextTick()
  await refreshAuthorizationWorkspace()
}
function updateFromExactPath(path: string, rawValue: string) {
  if (!payload.value) return
  const editableText = rawValue
    .replace(/\u00a0/g, ' ')
    .replace(/\r\n?/g, '\n')
    .replace(/\n[ \t]*\n+/g, '\n')
    .replace(/^(?:[ \t]*\n)+|(?:\n[ \t]*)+$/g, '')
  const parts = path.split('.')
  if (parts[0] === 'document' && parts[1] && parts[1] in payload.value.document) {
    ;(payload.value.document as unknown as Record<string, string>)[parts[1]] = editableText
    return
  }
  if (parts[0] === 'customer' && parts[1] && ['code', 'billing_address', 'delivery_address', 'tax_id'].includes(parts[1])) {
    ;(payload.value.customer as unknown as Record<string, string>)[parts[1]] = rawValue.trim()
    return
  }
  if (parts[0] === 'terms' && /^\d+$/.test(parts[1] || '')) {
    const index = Number(parts[1]); if (payload.value.terms[index] !== undefined) payload.value.terms[index] = editableText
    return
  }
  if (parts[0] === 'table_layout' && /^\d+$/.test(parts[1] || '') && parts[2] === 'label') {
    const column = payload.value.table_layout[Number(parts[1])]
    if (column) column.label = rawValue.trim().slice(0, 255)
    return
  }
  if (parts[0] === 'items' && /^\d+$/.test(parts[1] || '') && parts[2]) {
    const item = payload.value.items[Number(parts[1])]
    if (!item || !['sku', 'description', 'quantity', 'unit', 'pack', 'unit_price', 'discount', 'remark', 'origin_details'].includes(parts[2])) return
    if (parts[2] === 'quantity' || parts[2] === 'unit_price' || parts[2] === 'discount') {
      const lines = editableText.split('\n').map(value => Math.max(Number(value.replace(/[^0-9.,-]/g, '').replace(/,/g, '')) || 0, 0))
      const displayKey = `${parts[2]}_display`
      ;(item as unknown as Record<string, string | number>)[displayKey] = editableText
      ;(item as unknown as Record<string, string | number>)[parts[2]] = parts[2] === 'quantity'
        ? lines.reduce((sum, value) => sum + value, 0)
        : (lines[0] || 0)
    } else {
      ;(item as unknown as Record<string, string>)[parts[2]] = ['description', 'remark', 'origin_details'].includes(parts[2]) ? editableText : editableText.trim()
    }
  }
}
function mutateColumns(action: string, index: number) {
  if (!payload.value || index < 0 || index >= payload.value.table_layout.length) return
  const columns = payload.value.table_layout
  checkpoint()
  if (action === 'left' && index > 0) [columns[index - 1], columns[index]] = [columns[index], columns[index - 1]]
  if (action === 'right' && index < columns.length - 1) [columns[index + 1], columns[index]] = [columns[index], columns[index + 1]]
  if (action === 'remove' && columns.length > 3) columns.splice(index, 1)
  if (action === 'add' && columns.length < 12) columns.splice(index + 1, 0, { id: `remark-${Date.now()}`, field: 'remark', label: 'หมายเหตุ', width: 12, align: 'left' })
  nextTick(() => { changed(); refreshExactEditor() })
}
function setColumnField(index: number, field: TableColumn['field']) {
  const column = payload.value?.table_layout[index]
  if (!column) return
  checkpoint(); column.field = field
  nextTick(() => { changed(); refreshExactEditor() })
}
function setItemImage(index: number, file?: File) {
  const item = payload.value?.items[index]
  if (!item || !file) return
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 1_500_000) {
    error.value = 'รูปสินค้าต้องเป็น PNG, JPEG หรือ WebP และมีขนาดไม่เกิน 1.5 MB'
    return
  }
  const reader = new FileReader()
  reader.onload = () => {
    if (typeof reader.result !== 'string') return
    checkpoint(); item.image = reader.result; changed(); refreshExactEditor()
  }
  reader.readAsDataURL(file)
}
function bindExactEditor() {
  if (locked.value) return
  const doc = exactFrame.value?.contentDocument
  if (!doc) return
  const resize = () => { if (exactFrame.value) exactFrame.value.style.height = `${Math.max(doc.documentElement.scrollHeight, 1123)}px` }
  doc.querySelectorAll<HTMLElement>('[data-edit-path]').forEach((element) => {
    element.addEventListener('focus', checkpoint)
    element.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return
      event.preventDefault()
      const selection = doc.getSelection()
      if (!selection || selection.rangeCount === 0) return
      const range = selection.getRangeAt(0)
      range.deleteContents()
      const indentation = doc.createTextNode('    ')
      range.insertNode(indentation)
      range.setStartAfter(indentation)
      range.collapse(true)
      selection.removeAllRanges()
      selection.addRange(range)
      element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: '    ' }))
    })
    element.addEventListener('input', () => {
      const path = element.dataset.editPath || ''
      updateFromExactPath(path, element.innerText || element.textContent || '')
      changed()
    })
    element.addEventListener('blur', () => {
      const path = element.dataset.editPath || ''
      updateFromExactPath(path, element.innerText || element.textContent || '')
    })
  })
  doc.querySelectorAll<HTMLButtonElement>('[data-column-action]').forEach((button) => {
    button.addEventListener('click', () => mutateColumns(button.dataset.columnAction || '', Number(button.dataset.columnIndex)))
  })
  doc.querySelectorAll<HTMLSelectElement>('[data-column-field]').forEach((select) => {
    select.addEventListener('change', () => setColumnField(Number(select.dataset.columnField), select.value as TableColumn['field']))
  })
  doc.querySelectorAll<HTMLInputElement>('[data-item-image-index]').forEach((input) => {
    input.addEventListener('change', () => setItemImage(Number(input.dataset.itemImageIndex), input.files?.[0]))
  })
  doc.querySelectorAll<HTMLButtonElement>('[data-item-image-remove]').forEach((button) => {
    button.addEventListener('click', () => {
      const item = payload.value?.items[Number(button.dataset.itemImageRemove)]
      if (!item) return
      checkpoint(); item.image = ''; changed(); refreshExactEditor()
    })
  })
  doc.querySelectorAll<HTMLElement>('[data-column-resize]').forEach((handle) => {
    handle.addEventListener('pointerdown', (event) => {
      const index = Number(handle.dataset.columnResize)
      const columns = payload.value?.table_layout
      const table = handle.closest('table')
      if (!columns || !table || index < 0 || index >= columns.length - 1) return
      event.preventDefault(); checkpoint()
      const startX = event.clientX
      const first = columns[index].width
      const second = columns[index + 1].width
      const onMove = (move: PointerEvent) => {
        const delta = ((move.clientX - startX) / Math.max(table.getBoundingClientRect().width, 1)) * 100
        const nextFirst = Math.max(5, Math.min(first + second - 5, first + delta))
        columns[index].width = Number(nextFirst.toFixed(2))
        columns[index + 1].width = Number((first + second - nextFirst).toFixed(2))
        const header = handle.closest('th') as HTMLElement | null
        const nextHeader = header?.nextElementSibling as HTMLElement | null
        if (header) header.style.width = `${columns[index].width}%`
        if (nextHeader) nextHeader.style.width = `${columns[index + 1].width}%`
      }
      const onUp = () => {
        doc.removeEventListener('pointermove', onMove)
        doc.removeEventListener('pointerup', onUp)
        changed(); refreshExactEditor()
      }
      doc.addEventListener('pointermove', onMove)
      doc.addEventListener('pointerup', onUp)
    })
  })
  resize()
}
function updateAuthorizationFromExactPath(path: string, rawValue: string) {
  if (!payload.value || !path.startsWith('power_of_attorney.')) return
  const key = path.slice('power_of_attorney.'.length)
  const fields = payload.value.power_of_attorney as unknown as Record<string, string | boolean>
  if (!(key in fields) || typeof fields[key] !== 'string') return
  fields[key] = key === 'sale_identity_no'
    ? rawValue.replace(/\D/g, '').slice(0, 13)
    : rawValue.trim()
}
function bindAuthorizationEditor() {
  const doc = authorizationFrame.value?.contentDocument
  if (!doc) return
  const resize = () => { if (authorizationFrame.value) authorizationFrame.value.style.height = `${Math.max(doc.documentElement.scrollHeight, 1123)}px` }
  doc.querySelectorAll<HTMLElement>('[data-edit-path]').forEach((element) => {
    element.addEventListener('focus', checkpoint)
    element.addEventListener('input', () => updateAuthorizationFromExactPath(element.dataset.editPath || '', element.innerText || element.textContent || ''))
    element.addEventListener('blur', () => {
      updateAuthorizationFromExactPath(element.dataset.editPath || '', element.innerText || element.textContent || '')
      window.setTimeout(refreshAuthorizationEditor, 0)
    })
  })
  resize()
}
async function confirmDocument() {
  if (!payload.value || locked.value || confirming.value) return
  confirming.value = true; error.value = ''
  try {
    if (saveTimer !== null) window.clearTimeout(saveTimer)
    while (saving.value) await new Promise((resolve) => window.setTimeout(resolve, 40))
    await saveDraft()
    const response = await api.post<{ data: Version }>(`/api/v1/quotations/${quotationId}/document-editor/confirm`, { template, mode, payload: payload.value })
    const version = response.data.data
    versions.value.unshift(version)
    await load()
    await downloadFile(`/api/v1/quotations/${quotationId}/document-versions/${version.document_version_id}/pdf`, version.filename, 'get', undefined, true)
    saveMessage.value = `ยืนยันและจัดเก็บ Revision ${version.revision_no} แล้ว`
  } catch (cause) { error.value = apiError(cause, 'ยืนยันใบเสนอราคาไม่สำเร็จ') }
  finally { confirming.value = false }
}
async function submitHelpRequest() {
  if (!requestHelpChecked.value || helpNote.value.trim().length < 3 || helpSaving.value) return
  helpSaving.value = true; error.value = ''
  try {
    const response = await api.post<{ data: HelpRequest }>(`/api/v1/quotations/${quotationId}/document-editor/help-request`, { template, mode, note: helpNote.value.trim() })
    helpRequest.value = response.data.data
    helpNote.value = ''
    requestHelpChecked.value = false
    saveMessage.value = 'ส่งคำขอความช่วยเหลือให้ Sale COO แล้ว'
  } catch (cause) { error.value = apiError(cause, 'ส่งคำขอความช่วยเหลือไม่สำเร็จ') }
  finally { helpSaving.value = false }
}
async function updateHelpRequest(action: 'accept' | 'resolve' | 'cancel') {
  if (!helpRequest.value || helpSaving.value) return
  if (action === 'resolve' && helpActionNote.value.trim().length < 1) { error.value = 'กรุณาระบุสิ่งที่ดำเนินการก่อนปิดคำขอ'; return }
  helpSaving.value = true; error.value = ''
  try {
    if (action === 'resolve' && !locked.value) await saveDraft()
    const response = await api.patch<{ data: HelpRequest }>(`/api/v1/quotations/${quotationId}/document-editor/help-request/${helpRequest.value.help_request_id}`, { action, note: helpActionNote.value.trim() || undefined })
    helpRequest.value = response.data.data
    helpActionNote.value = ''
    saveMessage.value = action === 'accept' ? 'รับเรื่องแล้ว สามารถแก้ไข Draft แทน Sale ได้' : action === 'resolve' ? 'ปิดคำขอและแจ้ง Sale แล้ว' : 'ยกเลิกคำขอแล้ว'
  } catch (cause) { error.value = apiError(cause, 'อัปเดตคำขอความช่วยเหลือไม่สำเร็จ') }
  finally { helpSaving.value = false }
}
async function load() {
  ignoreChanges = true
  loading.value = true; error.value = ''
  try {
    const [response, references] = await Promise.all([
      api.get<{ data: EditorResponse }>(`/api/v1/quotations/${quotationId}/document-editor`, { params: { template, mode } }),
      api.get<{ data: { products: { sku_code: string; sku_name: string }[] } }>('/api/v1/reference-data'),
    ])
    products.value = references.data.data.products
    payload.value = response.data.data.payload
    lockVersion.value = response.data.data.lock_version
    versions.value = response.data.data.versions
    locked.value = response.data.data.locked
    documentStatus.value = response.data.data.status || ''
    approvedVersionId.value = response.data.data.approved_version_id
    helpRequest.value = response.data.data.help_request
    canManageHelp.value = response.data.data.can_manage_help
    undoStack.value = [clonePayload(response.data.data.payload)]
    saveMessage.value = locked.value
      ? `สถานะ ${documentStatus.value} · ${approvedVersionId.value ? 'แสดงฉบับที่ยืนยัน' : 'ไม่พบฉบับที่ยืนยันสำหรับรูปแบบนี้'}`
      : response.data.data.saved_at ? `Draft ล่าสุด ${new Date(response.data.data.saved_at).toLocaleString('th-TH')}` : 'เอกสารใหม่ ยังไม่มี Draft'
    await nextTick(); ignoreChanges = false
    if (locked.value) { exactHtml.value = ''; await refreshPreview(); if (canManagePowerOfAttorney.value && payload.value.power_of_attorney.enabled) await refreshAuthorizationWorkspace() }
    else await Promise.all([
      refreshPreview(), refreshExactEditor(),
      ...(canManagePowerOfAttorney.value && payload.value.power_of_attorney.enabled ? [refreshAuthorizationPreview(), refreshAuthorizationEditor()] : []),
    ])
  } catch (cause) { error.value = apiError(cause, 'เปิดตัวแก้ไขใบเสนอราคาไม่สำเร็จ') }
  finally { ignoreChanges = false; loading.value = false }
}
function back() { router.back() }
async function downloadCurrentVersion() {
  if (!approvedVersionId.value) return
  const current = versions.value.find(version => version.document_version_id === approvedVersionId.value)
  await downloadFile(`/api/v1/quotations/${quotationId}/document-versions/${approvedVersionId.value}/pdf`, current?.filename || 'quotation.pdf', 'get', undefined, true)
}

watch(payload, changed, { deep: true })
onMounted(load)
onBeforeUnmount(() => {
  if (saveTimer !== null) window.clearTimeout(saveTimer)
  if (previewTimer !== null) window.clearTimeout(previewTimer)
  if (historyTimer !== null) window.clearTimeout(historyTimer)
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  if (authorizationPreviewUrl.value) URL.revokeObjectURL(authorizationPreviewUrl.value)
})
onBeforeRouteLeave(async () => {
  if (!locked.value && saveTimer !== null && payload.value) await saveDraft()
  return true
})
</script>

<template>
  <AppShell :title="locked ? 'ใบเสนอราคาที่ส่งอนุมัติแล้ว' : 'แก้ไขใบเสนอราคา'" :subtitle="locked ? `${templateNames[template] || template} · แสดง PDF ฉบับที่ยืนยันตามสถานะ ${documentStatus}` : `${templateNames[template] || template} · คลิกข้อความหรือตัวเลขบนเอกสารเพื่อแก้ไขได้ทันที`">
    <section class="editor-toolbar">
      <div class="toolbar-group"><button class="secondary-button" type="button" @click="back">← กลับ</button><span class="save-state" :class="{ saving }"><i></i>{{ saveMessage }}</span></div>
      <div class="toolbar-group"><template v-if="locked"><button class="secondary-button" type="button" :disabled="previewing || !approvedVersionId" @click="refreshPreview">{{ previewing ? 'กำลังโหลด…' : 'รีเฟรชฉบับที่ยืนยัน' }}</button><button class="primary-button" type="button" :disabled="!approvedVersionId" @click="downloadCurrentVersion">ดาวน์โหลดฉบับที่ยืนยัน</button></template><template v-else><button class="secondary-button" type="button" :disabled="undoStack.length < 2" @click="undo">↶ Undo</button><button class="secondary-button" type="button" :disabled="!redoStack.length" @click="redo">↷ Redo</button><button class="secondary-button" type="button" :disabled="previewing" @click="refreshPreview">{{ previewing ? 'กำลังสร้าง…' : 'รีเฟรช PDF' }}</button><button class="primary-button" type="button" :disabled="confirming || loading" @click="confirmDocument">{{ confirming ? 'กำลังยืนยัน…' : 'ยืนยันและดาวน์โหลด' }}</button><small v-if="mode === 'original' && payload" class="muted">ยืนยันแล้วกด “← กลับ” จากนั้นกด “สร้างและส่งอนุมัติ” เพื่อเลือก Checklist เอกสาร</small></template></div>
    </section>
    <p v-if="error" class="form-error editor-error">{{ error }}</p>
    <section v-if="canManagePowerOfAttorney && payload && mode === 'original'" class="authorization-opt-in">
      <div><strong>ต้องการหนังสือมอบอำนาจ</strong><small>เปิดไว้ ระบบจะสร้างหนังสือมอบอำนาจคู่กับใบเสนอราคา ปิดไว้จะไม่แสดง Preview และไม่สร้างเอกสาร</small></div>
      <label class="switch-control"><input v-model="payload.power_of_attorney.enabled" type="checkbox" @change="toggleAuthorization"><span aria-hidden="true"></span><b>{{ payload.power_of_attorney.enabled ? 'เปิด' : 'ปิด' }}</b></label>
    </section>
    <section v-if="canManagePowerOfAttorney && payload && mode === 'original' && payload.power_of_attorney.enabled" class="authorization-settings">
      <div><strong>รูปแบบหนังสือมอบอำนาจ</strong><small>เอกสารนี้จะถูกสร้างและตรึงกับ Revision เดียวกับใบเสนอราคา</small></div>
      <label><span>Template</span><select v-model="payload.power_of_attorney.template_id" @change="refreshAuthorizationWorkspace"><option value="general-company">แบบทั่วไปบริษัท</option><option value="anandamahidol">โรงพยาบาลอานันทมหิดล</option><option value="egp">ยื่น e-GP</option><option value="grantee-company-form">แบบระบุชื่อผู้รับมอบอำนาจ</option><option value="anandamahidol-ebidding">รพ.อานันทมหิดล (e-bidding)</option></select></label>
      <label><span>วันที่ออกหนังสือ</span><input v-model="payload.power_of_attorney.issue_date" type="date" @change="refreshAuthorizationWorkspace"></label>
      <fieldset class="authorization-checks"><legend>ขอบเขตอำนาจ</legend><label><input v-model="payload.power_of_attorney.authority_quote" type="checkbox" @change="refreshAuthorizationWorkspace"> เสนอราคา</label><label><input v-model="payload.power_of_attorney.authority_documents" type="checkbox" @change="refreshAuthorizationWorkspace"> ลงนามเอกสาร</label><label><input v-model="payload.power_of_attorney.authority_negotiate" type="checkbox" @change="refreshAuthorizationWorkspace"> ต่อรอง</label><label><input v-model="payload.power_of_attorney.authority_modify" type="checkbox" @change="refreshAuthorizationWorkspace"> แก้ไขเอกสาร</label><label><input v-model="payload.power_of_attorney.authority_contract" type="checkbox" @change="refreshAuthorizationWorkspace"> สัญญา</label><label><input v-model="payload.power_of_attorney.authority_order" type="checkbox" @change="refreshAuthorizationWorkspace"> ใบสั่ง</label></fieldset>
    </section>
    <section v-if="!locked" class="help-request-card" :class="{ active: activeHelpRequest }">
      <template v-if="activeHelpRequest">
        <div><strong>คำขอความช่วยเหลือจาก Sale COO</strong><p>{{ activeHelpRequest.request_note }}</p><small>ผู้ร้องขอ {{ activeHelpRequest.requested_by }} · {{ activeHelpRequest.status === 'pending' ? 'รอรับเรื่อง' : `กำลังดำเนินการโดย ${activeHelpRequest.assigned_to || 'Sale COO'}` }}</small></div>
        <div v-if="canManageHelp" class="help-actions"><button v-if="activeHelpRequest.status === 'pending'" class="secondary-button" :disabled="helpSaving" @click="updateHelpRequest('accept')">รับเรื่อง</button><input v-model="helpActionNote" placeholder="สรุปสิ่งที่แก้ไขให้ Sale" maxlength="2000"><button class="primary-button" :disabled="helpSaving || !helpActionNote.trim()" @click="updateHelpRequest('resolve')">บันทึกและปิดคำขอ</button></div>
        <button v-else class="secondary-button" :disabled="helpSaving" @click="updateHelpRequest('cancel')">ยกเลิกคำขอ</button>
      </template>
      <template v-else-if="!canManageHelp && auth.can('sales_documents')">
        <label class="help-checkbox"><input v-model="requestHelpChecked" type="checkbox"><span><strong>ขอความช่วยเหลือจาก Sale COO</strong><small>Sale COO จะได้รับแจ้งเตือนและสามารถเข้ามาแก้ Draft ใบนี้แทนคุณได้</small></span></label>
        <div v-if="requestHelpChecked" class="help-compose"><textarea v-model="helpNote" maxlength="2000" placeholder="ระบุปัญหาและข้อมูลที่ต้องการให้ช่วย เช่น ไม่ทราบข้อมูลผู้ผลิต หรือต้องการให้ช่วยตรวจชื่อสินค้า"></textarea><button class="primary-button" :disabled="helpSaving || helpNote.trim().length < 3" @click="submitHelpRequest">{{ helpSaving ? 'กำลังส่ง…' : 'ส่งคำขอให้ Sale COO' }}</button></div>
      </template>
      <div v-else><strong>ไม่มีคำขอความช่วยเหลือที่รอดำเนินการ</strong><small>เมื่อ Sale ส่งคำขอ ระบบจะแสดงรายละเอียดในหน้านี้</small></div>
    </section>
    <div class="mobile-pane-switch"><button :class="{ active: mobilePane === 'edit' }" @click="mobilePane = 'edit'">แก้ไขเอกสาร</button><button :class="{ active: mobilePane === 'preview' }" @click="mobilePane = 'preview'">PDF จริง</button></div>

    <div v-if="loading" class="content-card">กำลังเตรียมเอกสาร…</div>
    <div v-else-if="payload" class="quotation-editor-grid">
      <section class="canvas-scroll" :class="{ 'mobile-hidden': mobilePane !== 'edit' }">
        <div v-if="locked" class="exact-editor"><iframe v-if="previewUrl" :key="previewUrl" :src="previewUrl" title="ใบเสนอราคาฉบับที่ยืนยัน"></iframe><div v-else class="exact-editor-loading">กำลังโหลด PDF ฉบับที่ยืนยัน…</div></div>
        <div v-else-if="exactHtml" class="exact-editor">
          <div class="exact-editor-controls">
            <label>เลขที่เอกสาร<input v-model="payload.document.number" @change="refreshExactEditor"></label><label>วันที่เอกสาร<input v-model="payload.document.date" type="date" @change="refreshExactEditor"></label><label>สินค้าใหม่<SearchableSelect v-model="productToAdd" :options="products.map(product => ({ value: product.sku_code, label: `${product.sku_code} | ${product.sku_name}` }))" placeholder="พิมพ์รหัสหรือชื่อสินค้า" /></label>
            <button class="add-row" type="button" @click="addItem">＋ เพิ่มสินค้า</button><button class="add-row" type="button" :disabled="payload.items.length <= 1" @click="removeItem(payload.items.length - 1)">− ลบสินค้าท้ายสุด</button><button class="add-row" type="button" @click="addTerm">＋ เพิ่มหมายเหตุ</button><button class="add-row" type="button" :disabled="!payload.terms.length" @click="removeTerm(payload.terms.length - 1)">− ลบหมายเหตุท้ายสุด</button>
            <button class="add-row" type="button" @click="toggleLogoAlignment">โลโก้: {{ alignmentLabel(payload.layout.logo_alignment) }}</button><button class="add-row" type="button" @click="toggleTitleAlignment">หัวข้อ: {{ alignmentLabel(payload.layout.title_alignment) }}</button><button class="add-row" type="button" @click="toggleCustomerDetails">{{ payload.layout.show_customer_details ? '− ซ่อนตารางข้อมูลลูกค้า' : '＋ แสดงตารางข้อมูลลูกค้า' }}</button>
            <button class="add-row" type="button" @click="toggleDocumentSection('show_confirmation_terms')">{{ payload.layout.show_confirmation_terms ? '− ซ่อนเงื่อนไขการยืนยัน' : '＋ แสดงเงื่อนไขการยืนยัน' }}</button><button class="add-row" type="button" @click="toggleDocumentSection('show_document_note')">{{ payload.layout.show_document_note ? '− ซ่อนหมายเหตุ' : '＋ แสดงหมายเหตุ' }}</button>
            <button class="add-row" type="button" @click="toggleDocumentSection('show_company_signature')">{{ payload.layout.show_company_signature ? '− ซ่อนลายเซ็นผู้มีอำนาจบริษัท' : '＋ แสดงลายเซ็นผู้มีอำนาจบริษัท' }}</button><button class="add-row" type="button" @click="toggleDocumentSection('show_customer_signature')">{{ payload.layout.show_customer_signature ? '− ซ่อนลายเซ็นผู้มีอำนาจลูกค้า' : '＋ แสดงลายเซ็นผู้มีอำนาจลูกค้า' }}</button>
            <button class="add-row summary-toggle" type="button" @click="toggleSummary">{{ payload.layout.show_summary ? '− ซ่อนตารางสรุปราคา' : '＋ แสดงตารางสรุปราคา' }}</button><small>รองรับการเว้นวรรคหลายช่อง การกด Tab และการขึ้นบรรทัดใหม่ · คลิกข้อความที่มีกรอบสีฟ้าบนเอกสารเพื่อแก้ไขตรงตำแหน่งจริง</small>
          </div>
          <iframe ref="exactFrame" :srcdoc="exactHtml" title="เอกสารใบเสนอราคาแบบแก้ไขตรง Template" sandbox="allow-same-origin" @load="bindExactEditor"></iframe>
        </div>
        <div v-else class="exact-editor-loading">กำลังสร้างหน้าแก้ไขจาก Template เดียวกับ PDF…</div>
      </section>

      <aside class="preview-panel" :class="{ 'mobile-hidden': mobilePane !== 'preview' }"><div class="preview-heading"><div><strong>{{ locked ? 'PDF ฉบับที่ยืนยัน' : 'PDF จริง' }}</strong><small>{{ locked ? `สถานะ ${documentStatus} · ไม่สามารถแก้ไขต้นฉบับหลังส่งอนุมัติ` : 'อัปเดตอัตโนมัติหลังหยุดพิมพ์' }}</small></div><span v-if="previewing" class="preview-loading">{{ locked ? 'กำลังโหลด…' : 'กำลังสร้าง…' }}</span></div><iframe v-if="previewUrl" :key="previewUrl" :src="previewUrl" title="ตัวอย่างใบเสนอราคา PDF"></iframe><div v-else class="preview-empty">{{ error || 'กำลังสร้างตัวอย่าง PDF…' }}</div>
        <details v-if="versions.length" class="version-history"><summary>เอกสารที่ยืนยันแล้ว ({{ versions.length }})</summary><div v-for="version in versions" :key="version.document_version_id" class="version-row"><span><b>Revision {{ version.revision_no }}{{ version.document_version_id === approvedVersionId ? ' · ฉบับปัจจุบัน' : ' · ฉบับก่อนหน้า' }}</b><small>{{ new Date(version.created_at).toLocaleString('th-TH') }} · {{ version.confirmed_by }}</small></span><button class="secondary-button" @click="downloadFile(`/api/v1/quotations/${quotationId}/document-versions/${version.document_version_id}/pdf`, version.filename)">ดาวน์โหลด</button></div></details>
      </aside>
    </div>

    <section v-if="canManagePowerOfAttorney && payload && mode === 'original' && payload.power_of_attorney.enabled" class="authorization-workspace">
      <header><div><b>หนังสือมอบอำนาจ</b><small>แก้ไขข้อความบนเอกสารด้านซ้าย และตรวจ PDF จริงด้านขวา</small></div><div class="toolbar-group"><button class="secondary-button" type="button" :disabled="authorizationPreviewing" @click="refreshAuthorizationWorkspace">{{ authorizationPreviewing ? 'กำลังสร้าง…' : 'รีเฟรชหนังสือมอบอำนาจ' }}</button><button class="primary-button" type="button" :disabled="authorizationSaving" @click="saveAuthorizationDraft">{{ authorizationSaving ? 'กำลังบันทึก…' : 'บันทึก Draft หนังสือมอบอำนาจ' }}</button></div></header>
      <div class="quotation-editor-grid">
        <section class="canvas-scroll">
          <div v-if="authorizationExactHtml" class="exact-editor"><div class="exact-editor-controls"><label>เลขที่หนังสือ<input v-model="payload.power_of_attorney.authorization_no" @change="refreshAuthorizationEditor"></label><label>วันที่ออกหนังสือ<input v-model="payload.power_of_attorney.issue_date" type="date" @change="refreshAuthorizationEditor"></label><small>คลิกข้อความที่มีกรอบสีฟ้าบนหนังสือมอบอำนาจเพื่อแก้ไขตรงตำแหน่งจริง</small></div><iframe ref="authorizationFrame" :srcdoc="authorizationExactHtml" title="หนังสือมอบอำนาจแบบแก้ไขตรง Template" sandbox="allow-same-origin" @load="bindAuthorizationEditor"></iframe></div>
          <div v-else class="exact-editor-loading">กำลังสร้างหน้าแก้ไขหนังสือมอบอำนาจ…</div>
        </section>
        <aside class="preview-panel authorization-preview"><div class="preview-heading"><div><strong>PDF หนังสือมอบอำนาจจริง</strong><small>อัปเดตอัตโนมัติหลังหยุดพิมพ์</small></div><span v-if="authorizationPreviewing" class="preview-loading">กำลังสร้าง…</span></div><iframe v-if="authorizationPreviewUrl" :key="authorizationPreviewUrl" :src="authorizationPreviewUrl" title="ตัวอย่างหนังสือมอบอำนาจ PDF"></iframe><div v-else class="preview-empty">กำลังสร้างตัวอย่าง PDF…</div></aside>
      </div>
    </section>
  </AppShell>
</template>

<style scoped>
.authorization-opt-in{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:12px;padding:16px 18px;border:1px solid var(--primary-200);border-radius:12px;background:var(--primary-50)}.authorization-opt-in>div{display:grid;gap:3px}.authorization-opt-in small{color:#64748b}.switch-control{display:flex;align-items:center;gap:9px;cursor:pointer}.switch-control input{position:absolute;opacity:0;pointer-events:none}.switch-control span{position:relative;width:48px;height:26px;border-radius:99px;background:#94a3b8;transition:background .2s}.switch-control span:after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.3);transition:transform .2s}.switch-control input:checked+span{background:var(--primary-600)}.switch-control input:checked+span:after{transform:translateX(22px)}
.authorization-settings{display:grid;grid-template-columns:minmax(190px,.65fr) repeat(2,minmax(180px,1fr)) minmax(300px,1.4fr);gap:12px;align-items:end;margin-bottom:14px;padding:14px;border:1px solid var(--line);border-radius:12px;background:#fff}.authorization-settings>div{align-self:center}.authorization-settings strong,.authorization-settings small,.authorization-settings label span{display:block}.authorization-settings small{margin-top:3px;color:#64748b}.authorization-settings label{display:grid;gap:5px;color:#334155;font-size:13px}.authorization-settings input,.authorization-settings select{width:100%}.authorization-checks{display:flex;align-items:center;flex-wrap:wrap;gap:5px 12px;margin:0;padding:5px 10px;border:1px solid var(--line);border-radius:8px}.authorization-checks legend{padding:0 4px;color:#334155;font-size:12px}.authorization-checks label{display:flex;align-items:center;gap:4px}.authorization-checks input{width:auto}.authorization-workspace{margin-top:24px;padding-top:20px;border-top:2px solid var(--line)}.authorization-workspace>header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.authorization-workspace>header div{display:grid}.authorization-workspace>header b{font-size:20px}.authorization-workspace>header small{color:#64748b}.authorization-preview{top:78px}
.editor-toolbar{position:sticky;top:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;margin:-8px 0 14px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.96);box-shadow:0 8px 24px rgba(15,23,42,.08);backdrop-filter:blur(10px)}.toolbar-group{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.save-state{display:flex;align-items:center;gap:7px;color:#64748b;font-size:13px}.save-state i{width:8px;height:8px;border-radius:50%;background:#22c55e}.save-state.saving i{background:#f59e0b;animation:pulse 1s infinite}.editor-error{margin:0 0 12px}.quotation-editor-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(390px,.92fr);gap:16px;align-items:start}.canvas-scroll{overflow:auto;padding:18px;border-radius:14px;background:#cbd2dc}.exact-editor{width:794px;min-height:1123px;margin:0 auto;background:#fff;box-shadow:0 8px 28px rgba(15,23,42,.2)}.exact-editor iframe{display:block;width:794px;min-height:1123px;border:0;background:#fff}.exact-editor-loading{display:grid;width:794px;min-height:1123px;margin:0 auto;place-items:center;background:#fff;color:#64748b;box-shadow:0 8px 28px rgba(15,23,42,.2)}.exact-editor-controls{position:sticky;top:0;z-index:2;display:flex;align-items:end;flex-wrap:wrap;gap:7px;padding:7px 10px;border-bottom:1px solid #cbd5e1;background:rgba(255,255,255,.96)}.exact-editor-controls label{display:grid;gap:2px;font-size:11px}.exact-editor-controls input{width:145px;padding:5px;border:1px solid #cbd5e1;border-radius:5px}.exact-editor-controls .add-row{margin:0}.exact-editor-controls small{width:100%;color:#2563eb}.paper{position:relative;width:794px;min-height:1123px;margin:0 auto;padding:42px 44px 78px;background:#fff;color:#111;box-shadow:0 8px 28px rgba(15,23,42,.2);font-family:"TH Sarabun New",Tahoma,sans-serif;font-size:18px}.paper-header{position:relative;height:145px}.brand-word{position:absolute;left:18px;top:0;color:#d71920;font-size:42px;font-weight:800;font-style:italic;line-height:.8}.brand-word small{display:block;margin-left:68px;color:#56606d;font-size:12px;font-style:normal;letter-spacing:1px}.paper-header h2{margin:52px 0 0;text-align:center;font-size:26px}.paper-meta{position:absolute;right:0;top:75px;width:225px}.paper-meta label,.letter-lines label{display:flex;align-items:center;gap:8px}.paper-meta input{min-width:0;text-align:left}.letter-lines{display:grid;gap:4px;margin:0 20px 10px}.letter-lines b{width:40px}.inline-text,.paper input,.paper textarea{width:100%;border:1px solid transparent;border-radius:3px;background:transparent;color:inherit;font:inherit;line-height:1.25;resize:vertical}.paper input:hover,.paper textarea:hover{border-color:#cbd5e1;background:#f8fafc}.paper input:focus,.paper textarea:focus{outline:2px solid #3b82f6;border-color:transparent;background:#eff6ff}.intro-text{margin:5px 20px 10px;width:calc(100% - 40px);text-align:center}.editable-table-wrap{overflow:visible}.editable-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:15px}.editable-table th,.editable-table td{border:1px solid #111;padding:0;vertical-align:top}.editable-table th{padding:7px 4px;font-weight:500;text-align:center}.editable-table input,.editable-table textarea{min-height:44px;padding:7px 5px;border-radius:0}.readonly-cell{display:block;padding:9px 5px;text-align:center}.remove-heading,.remove-cell{width:28px!important;border:none!important;background:#fff}.remove-cell button,.term-row button{width:26px;height:26px;border:0;border-radius:50%;background:#fee2e2;color:#b91c1c;cursor:pointer}.add-row{margin:8px 0;padding:5px 10px;border:1px dashed #94a3b8;border-radius:6px;background:#f8fafc;color:#334155;cursor:pointer}.summary-box{width:47%;margin:16px 0 12px auto;border:1px solid #111}.summary-box div,.summary-box label{display:grid;grid-template-columns:1fr 120px;align-items:center;min-height:34px;border-bottom:1px solid #111}.summary-box div:last-child{border-bottom:0}.summary-box span{padding:4px 8px;text-align:right}.summary-box strong,.summary-box input{padding:4px 8px;text-align:right;border-left:1px solid #111;border-radius:0}.summary-total{font-weight:700}.terms-editor{margin:16px 20px}.terms-editor h3{margin:0 0 5px;font-size:18px;text-decoration:underline}.term-row{display:grid;grid-template-columns:22px 1fr 28px;gap:4px;align-items:start;margin:2px 0}.term-row textarea{min-height:28px}.signing-area{width:43%;margin:20px 3% 0 auto;text-align:center}.signing-area p{margin:0}.signature-space{height:54px;padding-top:18px;color:#94a3b8;border-bottom:1px dashed #cbd5e1;font-size:13px}.signing-area input{text-align:center}.paper-footer{position:absolute;left:44px;right:44px;bottom:23px;text-align:center;font-size:11px;color:#475569}.paper-footer span{display:block;height:8px;margin-bottom:4px;background:linear-gradient(120deg,#8d211d 0 18%,#d71920 18% 100%)}.paper-footer strong{display:block;color:#e11;font-size:13px}.paper-footer small{display:block}.preview-panel{position:sticky;top:78px;overflow:hidden;border:1px solid var(--line);border-radius:14px;background:#fff}.preview-heading{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line)}.preview-heading small{display:block;color:#64748b}.preview-loading{color:#b45309;font-size:13px}.preview-panel iframe{display:block;width:100%;height:calc(100vh - 190px);min-height:620px;border:0;background:#eef1f5}.preview-empty{display:grid;place-items:center;min-height:620px;color:#64748b}.version-history{padding:10px 14px;border-top:1px solid var(--line)}.version-history summary{cursor:pointer;font-weight:700}.version-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--line)}.version-row small{display:block;color:#64748b}.mobile-pane-switch{display:none}.mobile-hidden{display:block}@keyframes pulse{50%{opacity:.35}}
.help-request-card{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:14px;padding:14px 16px;border:1px solid #d7dee8;border-radius:12px;background:#fff}.help-request-card.active{border-color:#f59e0b;background:#fffbeb}.help-request-card p{margin:4px 0;white-space:pre-wrap}.help-request-card small{display:block;color:#64748b}.help-checkbox{display:flex;align-items:center;gap:10px}.help-checkbox input{width:18px;height:18px}.help-checkbox span{display:grid}.help-compose,.help-actions{display:flex;align-items:center;gap:8px;flex:1;justify-content:flex-end}.help-compose textarea{width:min(520px,100%);min-height:64px;padding:9px;border:1px solid #cbd5e1;border-radius:8px}.help-actions input{width:min(360px,100%);padding:9px;border:1px solid #cbd5e1;border-radius:8px}
  @media(max-width:1100px){.authorization-settings{grid-template-columns:1fr 1fr}.authorization-settings>div,.authorization-settings__wide{grid-column:1/-1}.quotation-editor-grid{grid-template-columns:1fr}.preview-panel{position:static}.preview-panel iframe{height:75vh}.mobile-pane-switch{display:flex;margin-bottom:10px;padding:3px;border-radius:9px;background:#e2e8f0}.mobile-pane-switch button{flex:1;padding:9px;border:0;border-radius:7px;background:transparent}.mobile-pane-switch button.active{background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.15)}.mobile-hidden{display:none}.editor-toolbar{align-items:flex-start}.editor-toolbar,.toolbar-group{justify-content:center}.help-request-card,.help-compose,.help-actions{align-items:stretch;flex-direction:column}}@media(max-width:640px){.authorization-settings{grid-template-columns:1fr}.authorization-settings>*{grid-column:1!important}.editor-toolbar{position:static;padding:8px}.save-state{width:100%;justify-content:center}.canvas-scroll{padding:8px}.paper{transform-origin:top left}.preview-panel iframe,.preview-empty{min-height:70vh}.quotation-editor-grid{gap:8px}}
</style>
