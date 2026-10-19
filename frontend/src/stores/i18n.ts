import { computed, ref } from 'vue'
import type { UserPreferences } from '../types/api'

export type UiLanguage = 'th' | 'en'

const storedLanguage = localStorage.getItem('valor-ui-language')
const language = ref<UiLanguage>(storedLanguage === 'en' ? 'en' : 'th')
const dateFormat = ref<UserPreferences['date_format']>('DD/MM/YYYY')
const calendarSystem = ref<UserPreferences['calendar_system']>('buddhist')
const timezone = ref<UserPreferences['timezone']>('Asia/Bangkok')
const timeFormat = ref<UserPreferences['time_format']>('24h')
const numberFormat = ref<UserPreferences['number_format']>('en-US')

const english: Record<string, string> = {
  'บอร์ดประกาศ': 'Notice Board',
  'เมนู': 'Menu',
  'เมนูหลัก': 'Main menu',
  'ออกจากระบบ': 'Sign out',
  'เปิดเมนู': 'Open menu',
  'ปิดเมนู': 'Close menu',
  'ไม่ระบุ Role': 'Role not specified',
  'ผู้ดูแลระบบสูงสุด': 'Super Admin',
  'ฝ่ายขาย': 'Sales',
  'ผู้อนุมัติ': 'Approver',
  'ผู้ดูแลระบบ': 'Administrator',
  'ผู้ใช้งานทั่วไป': 'User',
  'สร้างใบเสนอราคา/เปิดบิลใหม่': 'Create Quotation / New Bill',
  'แก้ไขบิล/Draft(ใบเสนอราคา)': 'Edit Bill / Quotation Draft',
  'ศูนย์จัดการเอกสาร': 'Document Center',
  'ติดตามสถานะเอกสาร': 'Document Preparation Status',
  'ตรวจสอบเอกสารที่ Admin กำลังจัดเตรียมสำหรับบิลของคุณได้จากหน้านี้โดยตรง': 'Track documents that Admin is preparing for your bills directly from this page.',
  'ประวัติการเปิดบิลของฉัน': 'My Billing History',
  'ติดตามอนุมัติวงเงิน': 'Credit Approval Tracking',
  'บันทึก Call Sale': 'Call Sale Entry',
  'ตารางงาน': 'Work Calendar',
  'แดชบอร์ดสถิติ': 'Statistics Dashboard',
  'พิจารณาอนุมัติบิล': 'Bill Approval',
  'รายการที่อนุมัติแล้ว': 'Approved Bills',
  'รายการที่ไม่อนุมัติ': 'Rejected Bills',
  'รายการบิลทั้งหมด': 'All Bills',
  'วิเคราะห์งบประมาณ': 'Budget Analysis',
  'จัดการระบบส่วนกลาง': 'System Administration',
  'จัดการข้อมูลหลัก': 'Master Data',
  'จัดการเครดิตลูกค้า': 'Customer Credit',
  'จัดการผู้ใช้งาน': 'User Management',
  'บันทึกกิจกรรมระบบ': 'Audit Logs',
  'การตั้งค่า': 'Settings',
  'ปฏิทินตารางงานทีมขาย': 'Sales Team Work Calendar',
  'ดูงานตามวัน มอบหมายงาน และอัปเดตสถานะจากปฏิทินเดียว': 'View, assign, and update work from one calendar.',
  'ตารางจองเครื่อง FibroScan': 'FibroScan Booking Schedule',
  'Sale ตรวจสอบเครื่องว่างและส่งคำขอ จากนั้น Admin หรือ Approver ตรวจสอบ แก้ไข และอนุมัติก่อนลงปฏิทินกลาง': 'Sales checks availability and submits a request. Admin or Approver reviews, edits, and approves it before it appears on the shared calendar.',
  'แดชบอร์ดสถิติภาพรวม': 'Overview Dashboard',
  'วิเคราะห์ยอดขาย ลูกค้า สินค้า ส่วนลด และผลงานของ Sale': 'Analyze sales, customers, products, discounts, and sales performance.',
  'บอร์ดประกาศทีม': 'Team Notice Board',
  'ข่าวสาร นัดประชุม และเรื่องที่ทีมต้องเห็นพร้อมกันในวันนี้': 'Company news, meetings, and updates the team needs today.',
  'ตั้งค่าบัญชีและการแสดงผล': 'Account and Display Settings',
  'กำหนดภาษา เอกสาร วันที่ ตาราง และการแจ้งเตือนสำหรับบัญชีนี้': 'Choose language, documents, date display, tables, and notifications for this account.',
  'วิเคราะห์ Book แดง / งบประมาณรัฐบาล': 'Government Budget / Red Book Analysis',
  'วิเคราะห์ Excel ด้วย Python Vector Search และ Ollama แบบ Optional': 'Analyze Excel with Python vector search and optional Ollama.',
  'ค้นหา กรอง และตรวจรายละเอียดข้อมูลจาก PostgreSQL โดยไม่รันหน้าเว็บใหม่ทั้งระบบ': 'Search, filter, and inspect PostgreSQL records without reloading the application.',
  'ตรวจสอบว่าใครทำอะไร เมื่อใด กับข้อมูลหรือเอกสารใด พร้อมผลลัพธ์ของการทำรายการ': 'Review who performed each action, when it happened, the affected record, and its result.',
  'ตรวจคำขอวงเงินและบิลที่รอหัวหน้าพิจารณา': 'Review credit requests and bills awaiting manager approval.',
  'ติดตามสถานะอนุมัติวงเงิน': 'Credit Approval Status',
  'ติดตามคำขอเปิดหรือแก้ไขวงเงินที่ส่งให้ Approve และ Admin': 'Track credit-limit requests submitted to Approvers and Admins.',
  'ดาวน์โหลดใบเสนอราคา หลักฐาน PO และเอกสารประกอบการขอวงเงินย้อนหลัง': 'Download quotations, PO evidence, and historical credit documents.',
  'ค้นหาและแก้ไขบิล/Draft': 'Find and Edit Bills / Drafts',
  'แก้ไข Draft เดิมได้ไม่จำกัดครั้งโดยใช้เลขเดิม และบันทึก Edit Log อัตโนมัติ': 'Edit an existing draft under the same number with automatic edit history.',
  'ข้อมูลวงเงินและ Credit Term จากฐานข้อมูลกลางของ Valor ERP': 'Credit limits and terms from the central Valor ERP database.',
  'ศูนย์จัดการข้อมูลหลัก': 'Master Data Center',
  'เพิ่ม ค้นหา และลบลูกค้า/พนักงานขาย พร้อมดู Master Product': 'Add, search, and remove customers or salespeople, and review master products.',
  'ระบบเปิดบิล Product': 'Product Billing',
  'สร้างใบเสนอราคาก่อน แล้วเปิดบิลจากใบเสนอราคาที่บันทึกไว้': 'Create a quotation, then open a bill from the saved quotation.',
  'สร้าง แก้ไข ลบบัญชี และกำหนด Role ซึ่งควบคุมเมนู Sidebar และ API จริง': 'Create, edit, and remove accounts and assign roles that control both sidebar and API access.',
  'ส่งออก Bplus, ดูฐานข้อมูล และแก้ไข/ลบบิลแบบละเอียด': 'Export to Bplus, inspect records, and manage bills in detail.',
  'วิเคราะห์กิจกรรมของทีมขายและดูข้อมูลดิบ': 'Analyze team activities and inspect raw sales-call data.',
  'บันทึกแผนและผลการเข้าพบลูกค้าครั้งละครึ่งเดือน': 'Record customer visit plans and results in half-month periods.',
  'ข้อมูลลูกค้า': 'Customer Data',
  'ข้อมูลสินค้า': 'Product Data',
  'ค้นหาจากรหัสหรือชื่อลูกค้า': 'Search by customer code or name.',
  'ค้นหาจาก SKU หรือชื่อสินค้า': 'Search by SKU or product name.',
  'รายการบิล': 'Billing Records',
}

const menuEnglish: Record<string, string> = {
  create_bill: 'Create Quotation / New Bill', edit_bill: 'Edit Bill / Draft', sales_documents: 'Document Center',
  my_document_preparation_status: 'Document Preparation Status',
  my_billing_history: 'My Billing History', credit_request_status: 'Credit Approval Tracking', sales_call_entry: 'Call Sale Entry',
  calendar: 'Work Calendar', equipment_booking: 'FibroScan Booking', dashboard_stats: 'Statistics Dashboard',
  approve_bills: 'Bill Approval', approved_bills: 'Approved Bills', rejected_bills: 'Rejected Bills', all_bills: 'All Bills',
  sales_call_report: 'Call Sale Report', budget_analysis: 'Budget Analysis', document_center: 'Document Center',
  system_admin: 'System Administration', master_data: 'Master Data', credit_profiles: 'Customer Credit',
  user_management: 'User Management', audit_logs: 'Audit Logs', user_settings: 'Settings',
}

const statusEnglish: Record<string, string> = {
  'ฉบับร่าง': 'Draft', 'รออนุมัติ': 'Pending approval', 'รอหัวหน้าอนุมัติ': 'Pending manager approval',
  'รอลูกค้ายืนยัน': 'Pending customer confirmation', 'รอแอดมินส่งออก': 'Pending admin export', 'รอแก้ไข': 'Revision required',
  'อนุมัติแล้ว': 'Approved', 'ดำเนินการเสร็จสิ้น': 'Completed', 'ไม่อนุมัติ': 'Rejected', 'ยกเลิกบิล': 'Cancelled',
  'รอดำเนินการ': 'Pending', 'กำลังดำเนินการ': 'In progress', 'สำเร็จ': 'Completed', 'ยกเลิก': 'Cancelled',
  'จองแล้ว': 'Booked', 'กำลังใช้งาน': 'In use', 'คืนแล้ว': 'Returned', 'รออนุมัติวงเงิน': 'Pending credit approval',
  'ทำงาน': 'Working', 'ลา': 'Leave', 'วันหยุดบริษัท': 'Company holiday', 'ส่งแล้ว': 'Submitted', 'ยังไม่ส่ง': 'Not submitted',
  'Ollama พร้อมใช้งาน': 'Ollama available', 'Vector Search พร้อมใช้งาน': 'Vector Search available',
}

function setLanguage(nextLanguage: UiLanguage) {
  language.value = nextLanguage
  localStorage.setItem('valor-ui-language', nextLanguage)
  document.documentElement.lang = nextLanguage
}

function applyPreferences(preferences?: Partial<UserPreferences>) {
  setLanguage(preferences?.language === 'en' ? 'en' : 'th')
  dateFormat.value = preferences?.date_format ?? 'DD/MM/YYYY'
  calendarSystem.value = preferences?.calendar_system ?? 'buddhist'
  timezone.value = preferences?.timezone ?? 'Asia/Bangkok'
  timeFormat.value = preferences?.time_format ?? '24h'
  const savedNumberFormat = preferences?.number_format as string | undefined
  // The former th-TH option renders the same separators and digits as en-US.
  // Preserve existing accounts by mapping it to the international Arabic-digit format.
  numberFormat.value = savedNumberFormat === 'th-TH' || !savedNumberFormat
    ? 'en-US'
    : savedNumberFormat as UserPreferences['number_format']
  document.documentElement.dataset.tableDensity = preferences?.table_density ?? 'comfortable'
  document.documentElement.dataset.fontScale = preferences?.font_scale ?? 'normal'
  document.documentElement.dataset.highContrast = preferences?.high_contrast ? 'true' : 'false'
  document.documentElement.dataset.reduceMotion = preferences?.reduce_motion ? 'true' : 'false'
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '-'
  const [rawYear, month = '', day = ''] = value.slice(0, 10).split('-')
  const numericYear = Number(rawYear)
  if (!numericYear || !month || !day) return value
  const year = String(calendarSystem.value === 'buddhist' ? numericYear + 543 : numericYear)
  if (dateFormat.value === 'MM/DD/YYYY') return `${month}/${day}/${year}`
  if (dateFormat.value === 'YYYY-MM-DD') return `${year}-${month}-${day}`
  return `${day}/${month}/${year}`
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  const dateParts = new Intl.DateTimeFormat('en-CA', {
    year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timezone.value,
  }).formatToParts(date)
  const part = (type: Intl.DateTimeFormatPartTypes) => dateParts.find(item => item.type === type)?.value ?? ''
  const localDate = `${part('year')}-${part('month')}-${part('day')}`
  const time = new Intl.DateTimeFormat(language.value === 'en' ? 'en-GB' : 'th-TH', {
    hour: '2-digit', minute: '2-digit', hour12: timeFormat.value === '12h', timeZone: timezone.value,
  }).format(date)
  return `${formatDate(localDate)} ${time}`
}

function formatNumber(value: string | number | null | undefined, options?: Intl.NumberFormatOptions): string {
  return new Intl.NumberFormat(numberFormat.value, options).format(Number(value || 0))
}

function text(value: string): string {
  return language.value === 'en' ? (english[value] ?? value) : value
}

function menu(key: string, fallback: string): string {
  return language.value === 'en' ? (menuEnglish[key] ?? english[fallback] ?? fallback) : fallback
}

function status(value: string): string {
  return language.value === 'en' ? (statusEnglish[value] ?? value) : value
}

setLanguage(language.value)

export const i18n = {
  language: computed(() => language.value),
  isEnglish: computed(() => language.value === 'en'),
  setLanguage,
  text,
  menu,
  status,
  applyPreferences,
  formatDate,
  formatDateTime,
  formatNumber,
}
