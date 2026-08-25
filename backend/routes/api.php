<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingRecordController;
use App\Http\Controllers\Api\BillingWorkflowController;
use App\Http\Controllers\Api\BudgetAnalysisController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CreditProfileController;
use App\Http\Controllers\Api\CreditRequestController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PublicTaxController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReceiptOcrController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\SalesCallController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('web')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::middleware('audit')->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/auth/reset-password', PasswordResetController::class)->middleware('throttle:3,10');
        Route::get('/public/tax/{token}', [PublicTaxController::class, 'show']);
        Route::put('/public/tax/{token}', [PublicTaxController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/public/tax/{token}/cancel', [PublicTaxController::class, 'cancel'])->middleware('throttle:3,10');
        Route::get('/public/tax/{token}/pdf', [PublicTaxController::class, 'pdf'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'audit'])->group(function (): void {
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
        Route::get('/sales-calls/history', [SalesCallController::class, 'history'])->middleware('ability:sales_call_report');
        Route::get('/sales-calls/day', [SalesCallController::class, 'day'])->middleware('ability:sales_call_report');
        Route::put('/sales-calls/day', [SalesCallController::class, 'saveDay'])->middleware('ability:sales_call_report');
        Route::get('/billing-records', [BillingRecordController::class, 'index'])
            ->middleware('ability:my_billing_history,approved_bills,rejected_bills,all_bills,print_queue,system_admin,document_center');
        Route::get('/catalog/customers', [CatalogController::class, 'customers'])->middleware('ability:master_data');
        Route::get('/catalog/products', [CatalogController::class, 'products'])->middleware('ability:master_data');

        Route::get('/reference-data', [ReferenceDataController::class, 'index'])
            ->middleware('ability:create_bill,edit_bill,print_queue,master_data,credit_profiles,approve_bills');
        Route::get('/reference-data/locations', [ReferenceDataController::class, 'locations'])
            ->middleware('ability:create_bill,edit_bill');
        Route::post('/receipt-ocr', ReceiptOcrController::class)->middleware('ability:create_bill');

        Route::get('/notices', [NoticeController::class, 'index']);
        Route::post('/notices', [NoticeController::class, 'store'])->middleware('ability:approve_bills,system_admin');
        Route::patch('/notices/{notice}/active', [NoticeController::class, 'toggle'])->middleware('ability:approve_bills,system_admin');

        Route::get('/tasks', [TaskController::class, 'index'])->middleware('ability:calendar');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware('ability:approve_bills,system_admin');
        Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->middleware('ability:calendar');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->middleware('ability:approve_bills,system_admin');

        Route::get('/credit-requests', [CreditRequestController::class, 'index'])
            ->middleware('ability:credit_request_status,approve_bills,print_queue');
        Route::post('/credit-requests', [CreditRequestController::class, 'store'])->middleware('ability:create_bill,edit_bill');
        Route::post('/credit-requests/{creditRequest}/approve', [CreditRequestController::class, 'approve'])->middleware('ability:approve_bills');
        Route::post('/credit-requests/{creditRequest}/reject', [CreditRequestController::class, 'reject'])->middleware('ability:approve_bills');
        Route::post('/credit-requests/{creditRequest}/open', [CreditRequestController::class, 'open'])->middleware('ability:print_queue');
        Route::get('/credit-requests/{creditRequest}/documents/{document}', [CreditRequestController::class, 'document'])
            ->middleware('ability:approve_bills,print_queue');

        Route::get('/credit-profiles', [CreditProfileController::class, 'index'])->middleware('ability:credit_profiles,create_bill,edit_bill,print_queue');
        Route::post('/credit-profiles', [CreditProfileController::class, 'store'])->middleware('ability:credit_profiles');
        Route::delete('/credit-profiles/{creditProfile}', [CreditProfileController::class, 'destroy'])->middleware('ability:credit_profiles');

        Route::get('/quotations', [QuotationController::class, 'index'])->middleware('ability:create_bill,sales_documents,document_center');
        Route::post('/quotations', [QuotationController::class, 'store'])->middleware('ability:create_bill');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->middleware('ability:create_bill,sales_documents,document_center');
        Route::get('/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->middleware('ability:sales_documents,document_center');
        Route::post('/quotations/{quotation}/custom-name-pdf', [QuotationController::class, 'customNamePdf'])->middleware('ability:sales_documents,document_center');
        Route::post('/quotations/{quotation}/create-bill', [BillingWorkflowController::class, 'createFromQuotation'])->middleware('ability:create_bill');

        Route::get('/bills/pending-approval', [BillingWorkflowController::class, 'pendingApproval'])->middleware('ability:approve_bills');
        Route::get('/bills/admin-queue', [BillingWorkflowController::class, 'adminQueue'])->middleware('ability:print_queue');
        Route::get('/bills/ready-export', [BillingWorkflowController::class, 'readyForExport'])->middleware('ability:system_admin');
        Route::post('/bills/export', [BillingWorkflowController::class, 'export'])->middleware('ability:system_admin');
        Route::post('/bills/complete-all', [BillingWorkflowController::class, 'completeAll'])->middleware('ability:system_admin');
        Route::get('/bills/{bill}', [BillingWorkflowController::class, 'show'])
            ->middleware('ability:edit_bill,my_billing_history,approve_bills,print_queue,system_admin,document_center,sales_documents');
        Route::put('/bills/{bill}', [BillingWorkflowController::class, 'updateBySale'])->middleware('ability:edit_bill');
        Route::post('/bills/{bill}/submit', [BillingWorkflowController::class, 'submitDraft'])->middleware('ability:edit_bill');
        Route::post('/bills/{bill}/approve', [BillingWorkflowController::class, 'approve'])->middleware('ability:approve_bills');
        Route::post('/bills/{bill}/reject', [BillingWorkflowController::class, 'reject'])->middleware('ability:approve_bills');
        Route::post('/bills/{bill}/prepare', [BillingWorkflowController::class, 'prepare'])->middleware('ability:print_queue');
        Route::post('/bills/{bill}/complete', [BillingWorkflowController::class, 'complete'])->middleware('ability:system_admin');
        Route::get('/bills/{bill}/pdf', [BillingWorkflowController::class, 'pdf'])->middleware('ability:sales_documents,document_center,print_queue,system_admin');
        Route::patch('/bills/{bill}/admin-status', [BillingWorkflowController::class, 'adminUpdateStatus'])->middleware('ability:system_admin');
        Route::put('/bills/{bill}/admin', [BillingWorkflowController::class, 'adminUpdate'])->middleware('ability:system_admin');
        Route::delete('/bills/{bill}', [BillingWorkflowController::class, 'destroy'])->middleware('ability:system_admin');

        Route::get('/master-data/customers', [MasterDataController::class, 'customers'])->middleware('ability:master_data');
        Route::post('/master-data/customers', [MasterDataController::class, 'storeCustomer'])->middleware('ability:master_data');
        Route::delete('/master-data/customers/{customer}', [MasterDataController::class, 'destroyCustomer'])->middleware('ability:master_data');
        Route::get('/master-data/salesmen', [MasterDataController::class, 'salesmen'])->middleware('ability:master_data');
        Route::post('/master-data/salesmen', [MasterDataController::class, 'storeSalesman'])->middleware('ability:master_data');
        Route::delete('/master-data/salesmen/{salesman}', [MasterDataController::class, 'destroySalesman'])->middleware('ability:master_data');
        Route::post('/master-data/products', [MasterDataController::class, 'storeProduct'])->middleware('ability:master_data');

        Route::get('/users', [UserManagementController::class, 'index'])->middleware('ability:user_management');
        Route::post('/users', [UserManagementController::class, 'store'])->middleware('ability:user_management');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->middleware('ability:user_management');
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->middleware('ability:user_management');
    });
});
