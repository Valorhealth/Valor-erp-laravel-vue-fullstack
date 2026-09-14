<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingDocumentController;
use App\Http\Controllers\Api\BillingRecordController;
use App\Http\Controllers\Api\BillingWorkflowController;
use App\Http\Controllers\Api\BudgetAnalysisController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CreditProfileController;
use App\Http\Controllers\Api\CreditRequestController;
use App\Http\Controllers\Api\CustomerAcceptanceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FibroscanBookingController;
use App\Http\Controllers\Api\FibroscanMachineController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PublicTaxController;
use App\Http\Controllers\Api\PurchaseOrderScanController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReceiptOcrController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\SalesCallController;
use App\Http\Controllers\Api\SalesCallLookupOptionController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\UserSignatureController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('web')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::middleware('audit')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/auth/forgot-password', [PasswordResetController::class, 'requestLink'])->middleware('throttle:3,10');
        Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,10');
        Route::get('/public/tax/{token}', [PublicTaxController::class, 'show']);
        Route::put('/public/tax/{token}', [PublicTaxController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/public/tax/{token}/cancel', [PublicTaxController::class, 'cancel'])->middleware('throttle:3,10');
        Route::get('/public/tax/{token}/pdf', [PublicTaxController::class, 'pdf'])->middleware('throttle:10,1');
        Route::get('/public/quotation/{token}', [CustomerAcceptanceController::class, 'show'])->middleware('throttle:30,1');
        Route::post('/public/quotation/{token}/accept', [CustomerAcceptanceController::class, 'accept'])->middleware('throttle:5,10');
        Route::get('/public/quotation/{token}/pdf', [CustomerAcceptanceController::class, 'pdf'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'password.current', 'audit'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', DashboardController::class)->middleware('ability:dashboard_stats');
        Route::post('/dashboard/report', [DashboardController::class, 'report'])->middleware('ability:dashboard_stats');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('ability:audit_logs');
        Route::get('/budget/models', [BudgetAnalysisController::class, 'models'])->middleware('ability:budget_analysis');
        Route::post('/budget/analyze', [BudgetAnalysisController::class, 'analyze'])->middleware('ability:budget_analysis');
        Route::get('/budget/{analysisId}/status', [BudgetAnalysisController::class, 'status'])->middleware('ability:budget_analysis');
        Route::get('/budget/{analysisId}/preview', [BudgetAnalysisController::class, 'preview'])->middleware('ability:budget_analysis');
        Route::get('/budget/{analysisId}/download', [BudgetAnalysisController::class, 'download'])->middleware('ability:budget_analysis');
        Route::delete('/budget', [BudgetAnalysisController::class, 'clear'])->middleware('ability:budget_analysis');
        Route::get('/sales-calls', [SalesCallController::class, 'index'])->middleware('ability:sales_call_report');
        Route::get('/sales-calls/dashboard', [SalesCallController::class, 'dashboard'])->middleware('ability:sales_call_report');
        Route::get('/sales-calls/export.xlsx', [SalesCallController::class, 'exportExcel'])->middleware('ability:sales_call_report');
        Route::get('/sales-calls/dashboard.pdf', [SalesCallController::class, 'exportDashboard'])->middleware('ability:sales_call_report');
        Route::get('/sales-calls/history', [SalesCallController::class, 'history'])->middleware('ability:sales_call_entry');
        Route::delete('/sales-calls/history', [SalesCallController::class, 'deleteHistory'])->middleware('ability:sales_call_entry');
        Route::get('/sales-calls/period', [SalesCallController::class, 'period'])->middleware('ability:sales_call_entry');
        Route::put('/sales-calls/period', [SalesCallController::class, 'savePeriod'])->middleware('ability:sales_call_entry');
        Route::get('/sales-calls/day', [SalesCallController::class, 'day'])->middleware('ability:sales_call_entry');
        Route::put('/sales-calls/day', [SalesCallController::class, 'saveDay'])->middleware('ability:sales_call_entry');
        Route::post('/sales-calls/import/preview', [SalesCallController::class, 'importPreview'])->middleware('ability:sales_call_entry');
        Route::post('/sales-calls/import', [SalesCallController::class, 'import'])->middleware('ability:sales_call_entry');
        Route::get('/sales-calls/submissions', [SalesCallController::class, 'submissions'])->middleware('ability:sales_call_report');
        Route::delete('/sales-calls/months', [SalesCallController::class, 'deleteMonths'])->middleware('ability:sales_call_report');
        Route::get('/sales-calls/imports/{batch}/download', [SalesCallController::class, 'downloadImport'])->middleware('ability:sales_call_report');
        Route::put('/sales-calls/{salesCall}', [SalesCallController::class, 'updateManagedActivity'])->middleware('ability:sales_call_report');
        Route::get('/sales-call-options', [SalesCallLookupOptionController::class, 'index'])->middleware('ability:approve_bills,system_admin');
        Route::post('/sales-call-options', [SalesCallLookupOptionController::class, 'store'])->middleware('ability:approve_bills,system_admin');
        Route::put('/sales-call-options/{option}', [SalesCallLookupOptionController::class, 'update'])->middleware('ability:approve_bills,system_admin');
        Route::get('/billing-records', [BillingRecordController::class, 'index'])
            ->middleware('ability:my_billing_history,approved_bills,rejected_bills,all_bills,system_admin,document_center');
        Route::get('/catalog/customers', [CatalogController::class, 'customers'])->middleware('ability:master_data');
        Route::get('/catalog/products', [CatalogController::class, 'products'])->middleware('ability:master_data');

        Route::get('/reference-data', [ReferenceDataController::class, 'index'])
            ->middleware('ability:create_bill,edit_bill,system_admin,master_data,credit_profiles,approve_bills');
        Route::get('/reference-data/locations', [ReferenceDataController::class, 'locations'])
            ->middleware('ability:create_bill,edit_bill');
        Route::post('/receipt-ocr', ReceiptOcrController::class)->middleware('ability:create_bill');
        Route::post('/purchase-orders/scan', PurchaseOrderScanController::class)->middleware('ability:create_bill');

        Route::get('/notices', [NoticeController::class, 'index']);
        Route::post('/notices', [NoticeController::class, 'store'])->middleware('ability:approve_bills,system_admin');
        Route::patch('/notices/{notice}/active', [NoticeController::class, 'toggle'])->middleware('ability:approve_bills,system_admin');

        Route::get('/tasks', [TaskController::class, 'index'])->middleware('ability:calendar');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware('ability:approve_bills,system_admin');
        Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->middleware('ability:calendar');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->middleware('ability:approve_bills,system_admin');

        Route::get('/fibroscan-bookings', [FibroscanBookingController::class, 'index'])->middleware('ability:equipment_booking');
        Route::get('/fibroscan-bookings-availability', [FibroscanBookingController::class, 'availability'])->middleware('ability:equipment_booking');
        Route::post('/fibroscan-bookings', [FibroscanBookingController::class, 'store'])->middleware('ability:equipment_booking');
        Route::put('/fibroscan-bookings/{booking}', [FibroscanBookingController::class, 'update'])->middleware('ability:equipment_booking');
        Route::patch('/fibroscan-bookings/{booking}/status', [FibroscanBookingController::class, 'updateStatus'])->middleware('ability:equipment_booking');
        Route::post('/fibroscan-machines', [FibroscanMachineController::class, 'store'])->middleware('ability:system_admin');
        Route::put('/fibroscan-machines/{machine}', [FibroscanMachineController::class, 'update'])->middleware('ability:system_admin');

        Route::get('/credit-requests', [CreditRequestController::class, 'index'])
            ->middleware('ability:credit_request_status,approve_bills,system_admin,sales_documents,document_center');
        Route::post('/credit-requests', [CreditRequestController::class, 'store'])->middleware('ability:create_bill,edit_bill');
        Route::post('/credit-requests/{creditRequest}/approve', [CreditRequestController::class, 'approve'])->middleware('ability:approve_bills');
        Route::post('/credit-requests/{creditRequest}/reject', [CreditRequestController::class, 'reject'])->middleware('ability:approve_bills');
        Route::post('/credit-requests/{creditRequest}/open', [CreditRequestController::class, 'open'])->middleware('ability:system_admin');
        Route::get('/credit-requests/{creditRequest}/documents/{document}', [CreditRequestController::class, 'document'])
            ->middleware('ability:approve_bills,system_admin,sales_documents,document_center');

        Route::get('/credit-profiles', [CreditProfileController::class, 'index'])->middleware('ability:credit_profiles,create_bill,edit_bill,system_admin');
        Route::post('/credit-profiles', [CreditProfileController::class, 'store'])->middleware('ability:credit_profiles');
        Route::delete('/credit-profiles/{creditProfile}', [CreditProfileController::class, 'destroy'])->middleware('ability:credit_profiles');

        Route::get('/quotations', [QuotationController::class, 'index'])->middleware('ability:create_bill,sales_documents,document_center');
        Route::post('/quotations', [QuotationController::class, 'store'])->middleware('ability:create_bill');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->middleware('ability:create_bill,sales_documents,document_center');
        Route::get('/quotations/{quotation}/revisions', [QuotationController::class, 'revisions'])->middleware('ability:create_bill,sales_documents,document_center');
        Route::get('/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->middleware('ability:sales_documents,document_center,approve_bills');
        Route::post('/quotations/{quotation}/custom-name-pdf', [QuotationController::class, 'customNamePdf'])->middleware('ability:sales_documents,document_center');
        Route::post('/quotations/{quotation}/create-bill', [BillingWorkflowController::class, 'createFromQuotation'])->middleware('ability:create_bill');
        Route::post('/purchase-orders/create-bill', [BillingWorkflowController::class, 'createFromPurchaseOrder'])->middleware('ability:create_bill');

        Route::get('/bills/pending-approval', [BillingWorkflowController::class, 'pendingApproval'])->middleware('ability:approve_bills');
        Route::get('/bills/ready-export', [BillingWorkflowController::class, 'readyForExport'])->middleware('ability:system_admin');
        Route::post('/bills/export', [BillingWorkflowController::class, 'export'])->middleware('ability:system_admin');
        Route::post('/bills/export-quotations.pdf', [BillingWorkflowController::class, 'exportQuotationsPdf'])->middleware('ability:system_admin');
        Route::post('/bills/complete-all', [BillingWorkflowController::class, 'completeAll'])->middleware('ability:system_admin');
        Route::get('/bills/{bill}', [BillingWorkflowController::class, 'show'])
            ->middleware('ability:edit_bill,my_billing_history,approve_bills,system_admin,document_center,sales_documents');
        Route::put('/bills/{bill}', [BillingWorkflowController::class, 'updateBySale'])->middleware('ability:edit_bill');
        Route::post('/bills/{bill}/submit', [BillingWorkflowController::class, 'submitDraft'])->middleware('ability:edit_bill');
        Route::post('/bills/{bill}/purchase-order', [BillingDocumentController::class, 'store'])->middleware('ability:create_bill,edit_bill');
        Route::get('/bills/{bill}/purchase-order', [BillingDocumentController::class, 'show'])
            ->middleware('ability:create_bill,edit_bill,my_billing_history,approve_bills,system_admin,document_center,sales_documents');
        Route::post('/bills/{bill}/signed-quotation', [BillingDocumentController::class, 'storeSignedQuotation'])->middleware('ability:create_bill,edit_bill');
        Route::get('/bills/{bill}/signed-quotation', [BillingDocumentController::class, 'showSignedQuotation'])
            ->middleware('ability:create_bill,edit_bill,my_billing_history,approve_bills,system_admin,document_center,sales_documents');
        Route::post('/bills/{bill}/approve', [BillingWorkflowController::class, 'approve'])->middleware('ability:approve_bills');
        Route::get('/bills/{bill}/approval-certificate', [BillingWorkflowController::class, 'approvalCertificate'])
            ->middleware('ability:edit_bill,my_billing_history,approve_bills,system_admin,document_center,sales_documents');
        Route::post('/bills/{bill}/reject', [BillingWorkflowController::class, 'reject'])->middleware('ability:approve_bills');
        Route::post('/bills/{bill}/decline', [BillingWorkflowController::class, 'decline'])->middleware('ability:approve_bills');
        Route::post('/bills/{bill}/complete', [BillingWorkflowController::class, 'complete'])->middleware('ability:system_admin');
        Route::get('/bills/{bill}/pdf', [BillingWorkflowController::class, 'pdf'])->middleware('ability:sales_documents,document_center,system_admin');
        Route::patch('/bills/{bill}/admin-status', [BillingWorkflowController::class, 'adminUpdateStatus'])->middleware('ability:system_admin');
        Route::put('/bills/{bill}/admin', [BillingWorkflowController::class, 'adminUpdate'])->middleware('ability:system_admin');
        Route::post('/bills/{bill}/cancel', [BillingWorkflowController::class, 'cancel'])->middleware('ability:system_admin');

        Route::get('/master-data/customers', [MasterDataController::class, 'customers'])->middleware('ability:master_data');
        Route::post('/master-data/customers', [MasterDataController::class, 'storeCustomer'])->middleware('ability:master_data');
        Route::delete('/master-data/customers/{customer}', [MasterDataController::class, 'destroyCustomer'])->middleware('ability:master_data');
        Route::get('/master-data/salesmen', [MasterDataController::class, 'salesmen'])->middleware('ability:master_data');
        Route::post('/master-data/salesmen', [MasterDataController::class, 'storeSalesman'])->middleware('ability:master_data');
        Route::delete('/master-data/salesmen/{salesman}', [MasterDataController::class, 'destroySalesman'])->middleware('ability:master_data');
        Route::post('/master-data/products', [MasterDataController::class, 'storeProduct'])->middleware('ability:master_data');

        Route::get('/users', [UserManagementController::class, 'index'])->middleware('ability:user_management');
        Route::get('/users/permission-catalog', [UserManagementController::class, 'permissionCatalog'])->middleware('ability:user_management');
        Route::post('/users', [UserManagementController::class, 'store'])->middleware('ability:user_management');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->middleware('ability:user_management');
        Route::post('/users/{user}/signature', [UserSignatureController::class, 'store'])->middleware('ability:user_management');
        Route::get('/users/{user}/signature', [UserSignatureController::class, 'show'])->middleware('ability:user_management');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->middleware('ability:user_management');
    });
});
