import { createRouter, createWebHistory } from 'vue-router'
import { auth } from '../stores/auth'

// Split each screen into its own chunk. Users download only the page they open
// instead of every ERP module during login and the first page load.
const BillingRecordsView = () => import('../views/BillingRecordsView.vue')
const BudgetAnalysisView = () => import('../views/BudgetAnalysisView.vue')
const ApprovalsView = () => import('../views/ApprovalsView.vue')
const AuditLogsView = () => import('../views/AuditLogsView.vue')
const CalendarView = () => import('../views/CalendarView.vue')
const CatalogView = () => import('../views/CatalogView.vue')
const CreditProfilesView = () => import('../views/CreditProfilesView.vue')
const CreditRequestStatusView = () => import('../views/CreditRequestStatusView.vue')
const DashboardView = () => import('../views/DashboardView.vue')
const DocumentCenterView = () => import('../views/DocumentCenterView.vue')
const DocumentPreparationQueueView = () => import('../views/DocumentPreparationQueueView.vue')
const MyDocumentPreparationStatusView = () => import('../views/MyDocumentPreparationStatusView.vue')
const EditBillView = () => import('../views/EditBillView.vue')
const FibroscanBookingView = () => import('../views/FibroscanBookingView.vue')
const LoginView = () => import('../views/LoginView.vue')
const ResetPasswordView = () => import('../views/ResetPasswordView.vue')
const MasterDataView = () => import('../views/MasterDataView.vue')
const NoticeBoardView = () => import('../views/NoticeBoardView.vue')
const PublicTaxView = () => import('../views/PublicTaxView.vue')
const CustomerQuotationAcceptanceView = () => import('../views/CustomerQuotationAcceptanceView.vue')
const SaleOrderView = () => import('../views/SaleOrderView.vue')
const SalesCallReportView = () => import('../views/SalesCallReportView.vue')
const SystemAdminView = () => import('../views/SystemAdminView.vue')
const UserManagementView = () => import('../views/UserManagementView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const QuotationEditorView = () => import('../views/QuotationEditorView.vue')

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView },
    { path: '/reset-password', name: 'reset-password', component: ResetPasswordView },
    { path: '/tax/:token', name: 'public-tax', component: PublicTaxView },
    { path: '/quotation/accept/:token', name: 'customer-quotation-acceptance', component: CustomerQuotationAcceptanceView },
    { path: '/', redirect: '/notice-board' },
    { path: '/notice-board', name: 'notice-board', component: NoticeBoardView, meta: { requiresAuth: true, ability: 'notice_board' } },
    { path: '/bills/new', name: 'create-bill', component: SaleOrderView, meta: { requiresAuth: true, ability: 'create_bill' } },
    { path: '/bills/edit', name: 'edit-bill', component: EditBillView, meta: { requiresAuth: true, ability: 'edit_bill' } },
    { path: '/sales-documents', name: 'sales-documents', component: DocumentCenterView, meta: { requiresAuth: true, ability: 'sales_documents' } },
    { path: '/my-document-preparation-status', name: 'my-document-preparation-status', component: MyDocumentPreparationStatusView, meta: { requiresAuth: true, ability: 'my_document_preparation_status' } },
    { path: '/quotations/:quotationId/editor', name: 'quotation-editor', component: QuotationEditorView, meta: { requiresAuth: true } },
    { path: '/billing-records/my', name: 'my-billing-records', component: BillingRecordsView, props: { pageTitle: 'ประวัติการเปิดบิลของฉัน', ownerOnly: true }, meta: { requiresAuth: true, ability: 'my_billing_history' } },
    { path: '/credit-requests', name: 'credit-requests', component: CreditRequestStatusView, meta: { requiresAuth: true, ability: 'credit_request_status' } },
    { path: '/calendar', name: 'calendar', component: CalendarView, meta: { requiresAuth: true, ability: 'calendar' } },
    { path: '/fibroscan-bookings', name: 'fibroscan-bookings', component: FibroscanBookingView, meta: { requiresAuth: true, ability: 'equipment_booking' } },
    { path: '/sales-call-entry', name: 'sales-call-entry', component: SalesCallReportView, props: { mode: 'entry' }, meta: { requiresAuth: true, ability: 'sales_call_entry' } },
    { path: '/sales-call-report', name: 'sales-call-report', component: SalesCallReportView, props: { mode: 'report' }, meta: { requiresAuth: true, ability: 'sales_call_report' } },
    { path: '/dashboard', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true, ability: 'dashboard_stats' } },
    { path: '/audit-logs', name: 'audit-logs', component: AuditLogsView, meta: { requiresAuth: true, ability: 'audit_logs' } },
    { path: '/billing-approvals', name: 'billing-approvals', component: ApprovalsView, meta: { requiresAuth: true, ability: 'approve_bills' } },
    { path: '/billing-records/approved', name: 'approved-billing-records', component: BillingRecordsView, props: { pageTitle: 'รายการที่อนุมัติแล้ว', statusGroup: 'approved' }, meta: { requiresAuth: true, ability: 'approved_bills' } },
    { path: '/billing-records/rejected', name: 'rejected-billing-records', component: BillingRecordsView, props: { pageTitle: 'รายการที่ไม่อนุมัติ', statusGroup: 'rejected' }, meta: { requiresAuth: true, ability: 'rejected_bills' } },
    { path: '/billing-records/all', name: 'all-billing-records', component: BillingRecordsView, props: { pageTitle: 'รายการบิลทั้งหมด' }, meta: { requiresAuth: true, ability: 'all_bills' } },
    { path: '/budget-analysis', name: 'budget-analysis', component: BudgetAnalysisView, meta: { requiresAuth: true, ability: 'budget_analysis' } },
    { path: '/system-admin', name: 'system-admin', component: SystemAdminView, meta: { requiresAuth: true, ability: 'system_admin' } },
    { path: '/master-data', name: 'master-data', component: MasterDataView, meta: { requiresAuth: true, ability: 'master_data' } },
    { path: '/customers', name: 'customers', component: CatalogView, props: { catalog: 'customers' }, meta: { requiresAuth: true, ability: 'master_data' } },
    { path: '/products', name: 'products', component: CatalogView, props: { catalog: 'products' }, meta: { requiresAuth: true, ability: 'master_data' } },
    { path: '/credit-profiles', name: 'credit-profiles', component: CreditProfilesView, meta: { requiresAuth: true, ability: 'credit_profiles' } },
    { path: '/users', name: 'user-management', component: UserManagementView, meta: { requiresAuth: true, ability: 'user_management' } },
    { path: '/document-center', name: 'document-center', component: DocumentCenterView, meta: { requiresAuth: true, ability: 'document_center' } },
    { path: '/document-preparation-queue', name: 'document-preparation-queue', component: DocumentPreparationQueueView, meta: { requiresAuth: true, ability: 'document_preparation_queue' } },
    { path: '/settings', name: 'settings', component: SettingsView, meta: { requiresAuth: true, ability: 'user_settings' } },
    { path: '/:pathMatch(.*)*', redirect: '/notice-board' },
  ],
})

router.beforeEach(async (to) => {
  if (!auth.initialized.value) {
    await auth.restoreSession()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated.value) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.name === 'login' && auth.isAuthenticated.value) {
    return auth.defaultPath()
  }

  const requiredAbility = typeof to.meta.ability === 'string' ? to.meta.ability : null
  if (auth.isAuthenticated.value && requiredAbility && !auth.can(requiredAbility)) {
    return { path: auth.defaultPath(), query: { denied: to.fullPath } }
  }
})

export default router
