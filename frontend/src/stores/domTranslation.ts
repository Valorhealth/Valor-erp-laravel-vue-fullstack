import { nextTick, watch } from 'vue'
import { i18n } from './i18n'

type TranslationMap = Record<string, string>

const manualTranslations: TranslationMap = {
  'อนุมัติ': 'Approve',
  'ไม่อนุมัติ': 'Reject',
  'ผู้อนุมัติ': 'Approver',
  'ฝ่ายขาย': 'Sales',
  'ลา': 'Leave',
  'วันหยุดบริษัท': 'Company holiday',
  'ทำงาน': 'Working',
  'เดือน': 'Month',
  'แผนก': 'Department',
  'ทุกแผนก': 'All departments',
  'รีเฟรช': 'Refresh',
  'เลือก': 'Select',
  'สถานะ': 'Status',
  'วิธีส่ง': 'Submission method',
  'เวลาที่ส่ง': 'Submitted at',
  'แก้ไขล่าสุด': 'Last edited',
  'จัดการ': 'Manage',
  'รายการ': 'items',
  'จันทร์': 'Monday',
  'อังคาร': 'Tuesday',
  'พุธ': 'Wednesday',
  'พฤหัสบดี': 'Thursday',
  'ศุกร์': 'Friday',
  'เสาร์': 'Saturday',
  'อาทิตย์': 'Sunday',
  'Vector Search พร้อมใช้งาน': 'Vector Search available',
  'วงเงิน': 'Credit limit',
  'ใบเสนอราคา': 'Quotation',
  'เปิดบิล': 'Create bill',
  'บันทึก Call Sale': 'Call Sale Entry',
  'ตารางจองเครื่อง FibroScan': 'FibroScan Booking Schedule',
}
let translations: TranslationMap = { ...manualTranslations }
let dictionaryPromise: Promise<void> | null = null

function loadDictionary(): Promise<void> {
  dictionaryPromise ??= import('../locales/auto-en.json').then(module => {
    translations = { ...(module.default as TranslationMap), ...manualTranslations }
  })
  return dictionaryPromise
}

const thaiPattern = /[ก-๙]/
const originalText = new WeakMap<Text, string>()
const renderedText = new WeakMap<Text, string>()
const trackedTextNodes = new Set<Text>()
const originalAttributes = new WeakMap<Element, Map<string, string>>()
const trackedElements = new Set<Element>()
const translatedAttributes = ['placeholder', 'title', 'aria-label'] as const
const thaiMonths: Record<string, string> = {
  มกราคม: 'January', กุมภาพันธ์: 'February', มีนาคม: 'March', เมษายน: 'April', พฤษภาคม: 'May', มิถุนายน: 'June',
  กรกฎาคม: 'July', สิงหาคม: 'August', กันยายน: 'September', ตุลาคม: 'October', พฤศจิกายน: 'November', ธันวาคม: 'December',
}
const dynamicTranslations: Array<[RegExp, (...matches: string[]) => string]> = [
  [/^รวม\s+(.+)\s+Call$/i, (_match, value) => `Total ${value} Calls`],
  [/^รวม\s+(.+)\s+รายการติดต่อ$/i, (_match, value) => `Total ${value} contacts`],
  [/^รวม\s+(.+)\s+รายการ$/i, (_match, value) => `Total ${value} items`],
  [/^(.+)\s+รายการตามตัวกรอง$/i, (_match, value) => `${value} filtered items`],
  [/^(.+)\s+รายการจอง$/i, (_match, value) => `${value} bookings`],
  [/^(.+)\s+รายการ$/i, (_match, value) => `${value} items`],
  [/^หน้า\s+(.+)\s+\/\s+(.+)$/i, (_match, current, total) => `Page ${current} / ${total}`],
  [/^แสดง\s+(.+)\s+จาก\s+(.+)\s+รายการ$/i, (_match, range, total) => `Showing ${range} of ${total} items`],
  [/^นำเข้า\s+(.+)\s+แถว$/i, (_match, value) => `Imported ${value} rows`],
  [/^วันที่\s+(.+)$/i, (_match, value) => `Date ${value}`],
  [/^ตาราง Call Sale วันที่\s+(.+)\s+[–-]\s+(.+)$/i, (_match, from, to) => `Call Sale table: ${from} – ${to}`],
  [/^แสดงเฉพาะข้อมูลที่บัญชีของคุณบันทึกไว้\s+(.+)\s+รายการ$/i, (_match, value) => `Showing ${value} items recorded by your account`],
  [/^แสดงครบ\s+(.+)\s+รายการแล้ว$/i, (_match, value) => `All ${value} items displayed`],
  [/^Credit Term เฉลี่ย\s+(.+)\s+วัน$/i, (_match, value) => `Average credit term: ${value} days`],
  [/^คะแนนความเหมือนขั้นต่ำ:\s*(.+)$/i, (_match, value) => `Minimum similarity score: ${value}`],
  [/^(.+)%\s+ของทั้งหมด$/i, (_match, value) => `${value}% of total`],
  [/^(Success.+)\s+·\s+สัดส่วนทั้งหมด\s+(.+)$/i, (_match, result, share) => `${result} · Total share ${share}`],
  [/^(\d{1,2})\s+([ก-๙]+)\s+(\d{4})$/i, (_match, day, month, year) => `${day} ${thaiMonths[month] ?? month} ${year}`],
  [/^([ก-๙]+)\s+(\d{4})$/i, (_match, month, year) => `${thaiMonths[month] ?? month} ${year}`],
  [/^ยืนยันลบ\s+(.+)$/i, (_match, value) => `Confirm deletion of ${value}`],
  [/^ยืนยันยกเลิก\s+(.+)$/i, (_match, value) => `Confirm cancellation of ${value}`],
]

function splitWhitespace(value: string): { leading: string; content: string; trailing: string } {
  const leading = value.match(/^\s*/)?.[0] ?? ''
  const trailing = value.match(/\s*$/)?.[0] ?? ''
  return { leading, content: value.slice(leading.length, value.length - trailing.length), trailing }
}

export function translateUiText(value: string): string {
  if (!thaiPattern.test(value)) return value
  const { leading, content, trailing } = splitWhitespace(value)
  const normalized = content.replace(/\s+/g, ' ').trim()
  if (!normalized) return value

  const curated = i18n.text(normalized)
  if (curated !== normalized) return `${leading}${curated}${trailing}`

  const exact = translations[normalized]
  if (exact) return `${leading}${exact}${trailing}`

  for (const [pattern, replacement] of dynamicTranslations) {
    const matches = normalized.match(pattern)
    if (matches) return `${leading}${replacement(...matches)}${trailing}`
  }
  return value
}

function translateTextNode(node: Text): void {
  if (node.parentElement?.closest('script, style')) return
  const current = node.data
  if (renderedText.get(node) === current) return
  if (thaiPattern.test(current)) originalText.set(node, current)
  trackedTextNodes.add(node)
  const translated = translateUiText(current)
  if (translated !== current) {
    // An <option> without an explicit value uses its visible text as the
    // submitted value. Preserve the original domain value before translating
    // the label, otherwise English UI text breaks Thai-backed enums.
    const option = node.parentElement?.closest('option')
    if (option && !option.hasAttribute('value')) option.value = option.value
    renderedText.set(node, translated)
    node.data = translated
  }
}

function translateElementAttributes(element: Element): void {
  let originals = originalAttributes.get(element)
  for (const attribute of translatedAttributes) {
    const current = element.getAttribute(attribute)
    if (!current || !thaiPattern.test(current)) continue
    originals ??= new Map<string, string>()
    originals.set(attribute, current)
    const translated = translateUiText(current)
    if (translated !== current) element.setAttribute(attribute, translated)
  }
  if (originals) {
    originalAttributes.set(element, originals)
    trackedElements.add(element)
  }
}

function translateTree(root: Node): void {
  if (root.nodeType === Node.TEXT_NODE) {
    translateTextNode(root as Text)
    return
  }
  if (!(root instanceof Element)) return
  translateElementAttributes(root)
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT)
  let node = walker.nextNode()
  while (node) {
    if (node.nodeType === Node.TEXT_NODE) translateTextNode(node as Text)
    else translateElementAttributes(node as Element)
    node = walker.nextNode()
  }
}

function restoreThai(): void {
  for (const node of trackedTextNodes) {
    const original = originalText.get(node)
    if (node.isConnected && original !== undefined) node.data = original
  }
  for (const element of trackedElements) {
    if (!element.isConnected) continue
    for (const [attribute, original] of originalAttributes.get(element) ?? []) {
      element.setAttribute(attribute, original)
    }
  }
}

function patchNativeDialogs(): void {
  const nativeAlert = window.alert.bind(window)
  const nativeConfirm = window.confirm.bind(window)
  const nativePrompt = window.prompt.bind(window)
  window.alert = message => nativeAlert(i18n.isEnglish.value ? translateUiText(String(message ?? '')) : message)
  window.confirm = message => nativeConfirm(i18n.isEnglish.value ? translateUiText(String(message ?? '')) : message)
  window.prompt = (message, defaultValue) => nativePrompt(i18n.isEnglish.value ? translateUiText(String(message ?? '')) : message, defaultValue)
}

export function startDomTranslation(): void {
  patchNativeDialogs()
  const observer = new MutationObserver(mutations => {
    if (!i18n.isEnglish.value) return
    for (const mutation of mutations) {
      if (mutation.type === 'characterData') translateTextNode(mutation.target as Text)
      if (mutation.type === 'attributes') translateElementAttributes(mutation.target as Element)
      mutation.addedNodes.forEach(translateTree)
    }
  })
  observer.observe(document.documentElement, {
    subtree: true,
    childList: true,
    characterData: true,
    attributes: true,
    attributeFilter: [...translatedAttributes],
  })

  watch(i18n.language, async language => {
    if (language === 'en') await loadDictionary()
    await nextTick()
    if (language === 'en') translateTree(document.body)
    else restoreThai()
  }, { immediate: true })
}
