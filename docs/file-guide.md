# แผนที่ไฟล์ Valor ERP

## ถ้าต้องการแก้ฟีเจอร์ ให้เริ่มจากไฟล์เหล่านี้

| ฟีเจอร์ | Backend | Frontend |
|---|---|---|
| Login / Role / Sidebar | `app/Http/Controllers/Api/AuthController.php`, `app/Services/Authorization/RoleAccess.php` | `src/stores/auth.ts`, `src/components/AppShell.vue` |
| เปิดบิลและใบเสนอราคา | `QuotationController.php`, `BillingWorkflowController.php`, `Services/Billing/BillingWorkflow.php` | `SaleOrderView.vue`, `EditBillView.vue` |
| อนุมัติและตีกลับ | `BillingWorkflowController.php` | `ApprovalsView.vue`, `BillingRecordsView.vue` |
| คิวพิมพ์และ Bplus | `BillingWorkflowController.php`, `Services/Documents/DocumentService.php` | `PrintQueueView.vue`, `SystemAdminView.vue` |
| PDF / Excel / เอกสาร | `Services/Documents/DocumentService.php` | `DocumentCenterView.vue` |
| Dashboard | `Services/Dashboard/DashboardService.php`, `DashboardController.php` | `DashboardView.vue` |
| Call Sale Report | `SalesCallController.php`, `Services/SalesCalls/SalesCallReportService.php`, `Models/SalesCallActivity.php` | `SalesCallReportView.vue` |
| Audit Log | `Services/Audit/AuditLogger.php`, `AuditLogController.php`, `Middleware/AuditRequest.php` | `AuditLogsView.vue` |
| เครดิตลูกค้า | `CreditRequestController.php`, `CreditProfileController.php` | `CreditRequestStatusView.vue`, `CreditProfilesView.vue` |
| Master Data / Users | `MasterDataController.php`, `UserManagementController.php` | `MasterDataView.vue`, `UserManagementView.vue` |
| ประกาศ / ตารางงาน | `NoticeController.php`, `TaskController.php` | `NoticeBoardView.vue`, `CalendarView.vue` |
| OCR / วิเคราะห์งบ | `ReceiptOcrController.php`, `BudgetAnalysisController.php`, `backend/scripts/` | `BudgetAnalysisView.vue` |

Backend paths ในตารางอยู่ภายใต้ `backend/app/Http/Controllers/Api/` หากไม่ได้ระบุเต็ม และ Frontend paths อยู่ภายใต้ `frontend/src/views/`

## ไฟล์ส่วนกลาง

- `backend/routes/api.php` — API routes และสิทธิ์ของแต่ละ endpoint
- `backend/app/Services/Authorization/RoleAccess.php` — เมนูและ ability ของแต่ละ Role
- `frontend/src/router/index.ts` — URL ของหน้า Vue และ route guard
- `frontend/src/types/api.ts` — TypeScript contract ของ API
- `frontend/src/style.css` — design system ส่วนกลาง
- `database/schema/*.sql` — schema update ของ Supabase
- `backend/resources/` — assets และข้อมูล static ที่ Backend ใช้งาน
- `backend/scripts/requirements.txt` — Python dependencies เฉพาะ ERP

`vendor`, `node_modules`, `dist`, `.runtime` และ `.tools` เป็น dependency/build/runtime จึงถูกซ่อนจาก VS Code Explorer และ Search ผ่าน `.vscode/settings.json`
