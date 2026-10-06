export type RoleGroup = 'super_admin' | 'sale' | 'approve' | 'admin' | 'default'

export interface MenuItem {
  key: string
  label: string
  path: string
  icon: string
}

export interface User {
  id: number
  username: string
  first_name: string | null
  last_name: string | null
  email: string | null
  department: string | null
  role: string | null
  quotation_signing_authorized: boolean
  permission_overrides?: Record<string, boolean>
  base_abilities?: string[]
  role_group: RoleGroup
  abilities: string[]
  menu: MenuItem[]
}

export interface BillItem {
  item_id: number
  bill_id: number
  main_category: string | null
  sub_category: string | null
  customer_product_name?: string | null
  unit_type: string | null
  quantity: number | null
  unit_price: string | null
  line_total: string | null
  sale_discount_unit?: string | null
  line_total_before_discount?: string | null
  admin_lot?: string | null
  admin_serial?: string | null
  admin_storage?: string | null
  admin_free_qty?: number | null
  admin_disocount_unit?: string | null
  admin_weight_unit?: string | null
}

export interface BillingRecord {
  bill_id: number
  bill_date: string | null
  buyer_name: string | null
  tax_id?: string | null
  total_amount: string | null
  status: string | null
  created_by: string | null
  creator_user_id?: number | null
  created_at: string | null
  workflow_type?: string | null
  items?: BillItem[]
  address_note?: string | null
  province?: string | null
  district?: string | null
  sub_district?: string | null
  zipcode?: string | null
  shipping_type?: string | null
  shipping_text?: string | null
  bill_note?: string | null
  sale_discount_total?: string | null
  sale_discount_note?: string | null
  bplus_customer_code?: string | null
  bplus_salesman_code?: string | null
  bplus_sale_type_code?: string | null
  admin_branch?: string | null
  admin_dept?: string | null
  admin_project?: string | null
  admin_discount_final?: string | null
  warehouse_code?: string | null
  credit_limit?: string | null
  credit_outstanding?: string | null
  credit_remain?: string | null
  credit_term?: number | null
  requested_credit_term?: number | null
  reject_reason?: string | null
  ref_qt_id?: number | null
  tax_info_filled?: boolean | null
  tax_cust_name?: string | null
  tax_cust_address?: string | null
  tax_cust_tax_id?: string | null
  tax_invoice_no?: string | null
  tax_qr_token?: string | null
  document_printed_at?: string | null
  document_printed_by?: string | null
  excel_exported_at?: string | null
  excel_exported_by?: string | null
  submitted_at?: string | null
  issued_at?: string | null
  customer_po_no?: string | null
  customer_confirmed_at?: string | null
  customer_contact_name?: string | null
  customer_contact_channel?: string | null
  quotation_valid_until?: string | null
  payment_terms?: string | null
  delivery_due_date?: string | null
  delivery_terms?: string | null
  revision_no?: number
  canceled_at?: string | null
  canceled_by?: string | null
  cancellation_reason?: string | null
  customer_acceptance_token?: string | null
  customer_acceptance_expires_at?: string | null
  customer_acceptance_used_at?: string | null
  documents?: BillingDocument[]
  creator?: Pick<User, 'id' | 'username' | 'department'> | null
}

export interface BillingDocument {
  doc_id: number
  bill_id: number
  document_type: string
  file_name: string
  mime_type: string | null
  version_no?: number
  sha256?: string | null
  uploaded_by: string
  uploaded_at: string
}

export interface QuotationItem {
  qt_item_id: number
  qt_id: number
  main_category: string
  sub_category: string
  unit_type: string
  quantity: number
  unit_price: string
  sale_discount_unit: string | null
  line_total_before_discount: string | null
  line_total: string
}

export interface Quotation {
  qt_id: number
  qt_no: string | null
  qt_date: string | null
  buyer_name: string | null
  tax_id: string | null
  total_amount: string | null
  province: string | null
  district: string | null
  sub_district: string | null
  zipcode: string | null
  address_note: string | null
  shipping_type: string | null
  shipping_text: string | null
  bill_note: string | null
  bplus_customer_code: string | null
  created_by: string | null
  creator_user_id?: number | null
  creator?: Pick<User, 'id' | 'username' | 'department'> | null
  status: string | null
  workflow_type: string | null
  sale_discount_total: string | null
  sale_discount_note: string | null
  credit_limit: string | null
  credit_term: number | null
  requested_credit_term: number | null
  quotation_printed_at?: string | null
  quotation_printed_by?: string | null
  customer_po_no?: string | null
  customer_confirmed_at?: string | null
  customer_contact_name?: string | null
  customer_contact_channel?: string | null
  quotation_valid_until?: string | null
  payment_terms?: string | null
  delivery_due_date?: string | null
  delivery_terms?: string | null
  revision_no?: number
  items_count?: number
  items?: QuotationItem[]
  billing_record?: {
    bill_id: number
    ref_qt_id: number
    bill_date: string | null
    status: string | null
    buyer_name?: string | null
    total_amount?: string | null
    internal_approval_exists?: boolean
    customer_acceptance_token?: string | null
    customer_acceptance_expires_at?: string | null
    customer_acceptance_used_at?: string | null
    documents?: BillingDocument[]
  } | null
}

export interface CreditDocument {
  doc_id: number
  request_id: number
  doc_type: string
  file_name: string
  mime_type: string | null
  uploaded_at: string | null
}

export interface CreditRequest {
  request_id: number
  request_type: 'new_customer' | 'change_existing'
  company_name: string
  tax_id: string
  registered_address: string
  authorized_directors: string
  requested_credit_limit: string
  requested_credit_term: number
  expected_monthly_volume: string | null
  justification: string | null
  status: string
  created_by: string | null
  created_at: string | null
  approved_by: string | null
  approved_at: string | null
  opened_by: string | null
  opened_at: string | null
  reject_reason: string | null
  ar_code: string | null
  ar_name: string | null
  current_credit_limit: string | null
  current_credit_term: number | null
  documents?: CreditDocument[]
}

export interface CreditProfile {
  ar_code: string
  ar_name: string
  credit_limit: string
  credit_outstanding?: string | null
  credit_term: number
  updated_at?: string | null
}

export interface Notice {
  notice_id: number
  title: string
  body: string | null
  notice_type: 'ประกาศทั่วไป' | 'นัดประชุม' | 'แจ้งเตือนด่วน'
  meeting_date: string | null
  meeting_time: string | null
  location: string | null
  is_pinned: boolean
  is_active: boolean
  created_by: string | null
  created_at: string | null
}

export interface SalesTask {
  task_id: number
  title: string
  description: string | null
  assigned_to: string
  assigned_by: string
  assigned_to_user_id?: number | null
  assigned_by_user_id?: number | null
  start_date: string
  end_date: string
  start_time: string | null
  end_time: string | null
  all_day: boolean
  priority: 'ปกติ' | 'ด่วน' | 'สำคัญมาก'
  status: 'รอดำเนินการ' | 'กำลังดำเนินการ' | 'สำเร็จ' | 'ยกเลิก'
  progress_updates?: SalesTaskUpdate[]
}

export interface SalesTaskUpdate {
  update_id: number
  task_id: number
  status: SalesTask['status']
  progress_note: string
  updated_by: string
  created_at: string
}

export type FibroscanMachineStatus = 'พร้อมใช้งาน' | 'ซ่อมบำรุง' | 'เลิกใช้งาน'
export type FibroscanBookingStatus = 'จองแล้ว' | 'กำลังใช้งาน' | 'คืนแล้ว' | 'ยกเลิก'
export type FibroscanApprovalStatus = 'รออนุมัติ' | 'อนุมัติแล้ว' | 'ไม่อนุมัติ'

export interface FibroscanBookingChange {
  change_id: number
  booking_id: number
  changed_by: string
  action: string
  changes: Record<string, { from: unknown; to: unknown }>
  created_at: string
  actor?: Pick<User, 'id' | 'username' | 'first_name' | 'last_name'> | null
}

export interface FibroscanMachine {
  machine_id: number
  machine_code: string
  machine_name: string
  serial_number: string | null
  status: FibroscanMachineStatus
  note: string | null
  created_by: string | null
  created_at: string
  updated_at: string
}

export interface FibroscanBooking {
  booking_id: number
  machine_id: number
  hospital_name: string
  purpose: 'ทดลองใช้' | 'Event' | 'เช่า' | 'สาธิต' | 'อื่นๆ'
  reserved_from: string
  reserved_until: string
  contact_name: string | null
  contact_phone: string | null
  note: string | null
  approval_status: FibroscanApprovalStatus
  reviewed_by_user_id: number | null
  reviewed_by: string | null
  reviewed_at: string | null
  review_note: string | null
  status: FibroscanBookingStatus
  booked_by_user_id: number
  booked_by: string
  booked_by_department: string | null
  created_at: string
  updated_at: string
  can_edit: boolean
  can_manage_all: boolean
  can_review: boolean
  machine: Pick<FibroscanMachine, 'machine_id' | 'machine_code' | 'machine_name' | 'status'>
  owner?: Pick<User, 'id' | 'username' | 'first_name' | 'last_name' | 'department'> | null
  reviewer?: Pick<User, 'id' | 'username' | 'first_name' | 'last_name'> | null
  changes?: FibroscanBookingChange[]
}

export interface Salesman {
  id?: number
  salesman_code: string
  salesman_name: string
}

export interface SalesCallActivity {
  call_id: number
  call_no: number
  seller_user_id: number
  seller_username: string
  activity_date: string
  activity_time: string | null
  duration_minutes: number | null
  customer_id: number | null
  customer_name: string | null
  place_of_work: string | null
  specialty: string | null
  department: string | null
  customer_group: string | null
  product_1: string | null
  call_detail_objective: string | null
  call_detail_outcome: string | null
  sm_comment: string | null
  contact_name: string | null
  contact_position: string | null
  contact_phone: string | null
  activity_type: string
  objective: string
  outcome: string
  opportunity_stage: string
  product_interest: string | null
  estimated_value: string
  actual_value: string
  notes: string | null
  next_action: string | null
  next_follow_up_date: string | null
  status: string
  created_at: string
  updated_at: string
  seller?: Pick<User, 'id' | 'username' | 'first_name' | 'last_name' | 'department'>
}

export interface SalesCallOptions {
  sellers: Array<Pick<User, 'id' | 'username' | 'first_name' | 'last_name' | 'department'>>
  seller_departments: string[]
  specialties: string[]
  departments: string[]
  groups: string[]
  products: string[]
  objectives: string[]
  outcomes: string[]
  stages: string[]
  statuses: string[]
}

export interface SalesCallDashboard {
  summary: {
    total_activities: number
    completed_activities: number
    active_customers: number
    duration_minutes: number
    pipeline_value: number
    won_value: number
    won_activities: number
    success_calls: number
    failure_calls: number
      no_calls: number
      working_days: number
      calls_per_working_day: number
      conversion_rate: number
    follow_ups_due: number
  }
    by_seller: Array<{ seller_user_id: number; seller_username: string; seller_name: string; total: number; success_calls: number; failure_calls: number; no_calls: number; working_days: number; success_rate: number; calls_per_working_day: number; active_customers: number; follow_ups: number; duration_minutes: number; pipeline_value: string; actual_value: string }>
    by_seller_department: Array<{ department: string; total: number; success_calls: number; failure_calls: number; no_calls: number; working_days: number; success_rate: number; calls_per_working_day: number }>
    performance_matrix: Array<{ seller_username: string; place_of_work: string; total: number; success_calls: number; failure_calls: number; no_calls: number; working_days: number; success_rate: number; calls_per_working_day: number }>
  by_stage: Array<{ label: string; total: number; value: string }>
  by_outcome: Array<{ label: string; total: number }>
    by_activity_type: Array<{ label: string; total: number }>
    by_objective: Array<{ label: string; total: number; success_calls: number; failure_calls: number; no_calls: number }>
    by_product: Array<{ label: string; total: number; success_calls: number; customers_called: number; success_rate: number }>
    by_specialty: Array<{ label: string; total: number; success_calls: number; failure_calls: number; success_rate: number }>
    product_customer_matrix: Array<{ product: string; place_of_work: string; customer_name: string; total: number; success_calls: number; failure_calls: number; success_rate: number; last_call_date: string }>
    hospital_purchase_ranking: Array<{ hospital_name: string; purchase_calls: number; products_count: number; contacts_count: number; actual_value: string; last_purchase_date: string }>
    success_breakdown: Array<{ seller_username: string; product: string; specialty: string; place_of_work: string; total: number }>
    failure_breakdown: Array<{ seller_username: string; product: string; specialty: string; place_of_work: string; total: number }>
  daily_trend: Array<{ activity_date: string; total: number; pipeline_value: string }>
  top_customers: Array<{ customer_name: string; total: number; pipeline_value: string; last_activity_date: string }>
  upcoming_follow_ups: SalesCallActivity[]
}

export interface ReferenceData {
  customers: Customer[]
  products: Product[]
  salesmen: Salesman[]
  credit_profiles: CreditProfile[]
  units: string[]
  shipping_types: string[]
}

export type ThaiLocations = Record<string, Record<string, Record<string, string>>>

export interface DashboardSummary {
  total_revenue: number
  recognized_revenue: number
  gross_sales: number
  total_discount: number
  bplus_admin_discount: number
  total_bills: number
  recognized_bills: number
  completed_bills: number
  pending_approval: number
  waiting_manager: number
  waiting_export: number
  needs_revision: number
  ready_export: number
  items_sold: number
  active_customers: number
}

export interface DashboardData {
  summary: DashboardSummary
  filters: { sales: string[]; statuses: string[]; departments: string[] }
  status_breakdown: Array<{ status: string; total: number }>
  sales_by_person: Array<{ created_by: string; total: string }>
  sales_by_department: Array<{ department: string; bills: number; total: string }>
  daily_sales: Array<{ bill_date: string; total: string }>
  top_customers: Array<{ buyer_name: string; bills: number; total: string }>
  top_products: Array<{ sku: string; product_name: string; quantity: number; gross_amount: string; discount_amount: string; net_amount: string }>
  recent_bills: BillingRecord[]
}

export interface AuditLog {
  audit_id: number
  occurred_at: string
  user_id: number | null
  username: string | null
  role: string | null
  department: string | null
  action: string
  category: string
  description: string
  http_method: string
  route_uri: string | null
  target_type: string | null
  target_id: string | null
  request_data: Record<string, unknown> | null
  response_status: number | null
  was_successful: boolean
  ip_address: string | null
  user_agent: string | null
  duration_ms: string | null
}

export interface AuditLogResponse {
  data: Paginated<AuditLog>
  summary: {
    total: number
    successful: number
    failed: number
    active_users: number
    today: number
  }
  filters: {
    users: string[]
    categories: string[]
    actions: string[]
  }
}

export interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

export interface Paginated<T> {
  current_page: number
  data: T[]
  first_page_url: string
  from: number | null
  last_page: number
  last_page_url: string
  links: PaginationLink[]
  next_page_url: string | null
  path: string
  per_page: number
  prev_page_url: string | null
  to: number | null
  total: number
  summary?: {
    total_bills: number
    total_amount: number
  }
}

export interface Customer {
  id: number
  ar_code: string
  ar_name: string
  tax_id: string | null
}

export interface Product {
  sku_code: string
  sku_name: string
  sku_e_name: string | null
}
