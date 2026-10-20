<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuditLogger
{
    /** @var array<string, array{0: string, 1: string, 2: string}> */
    private const EVENT_MAP = [
        'AuthController@login' => ['auth.login', 'การเข้าสู่ระบบ', 'เข้าสู่ระบบ'],
        'AuthController@logout' => ['auth.logout', 'การเข้าสู่ระบบ', 'ออกจากระบบ'],
        'PasswordResetController@requestLink' => ['auth.password_reset_request', 'การเข้าสู่ระบบ', 'ขอลิงก์ตั้งรหัสผ่านใหม่'],
        'PasswordResetController@reset' => ['auth.reset_password', 'การเข้าสู่ระบบ', 'เปลี่ยนรหัสผ่านด้วย Token'],
        'UserSettingsController@updateProfile' => ['user.profile_update', 'ผู้ใช้งาน', 'แก้ไขข้อมูลบัญชีของตนเอง'],
        'QuotationController@store' => ['quotation.create', 'ใบเสนอราคา', 'สร้างใบเสนอราคา'],
        'QuotationController@show' => ['quotation.view', 'ใบเสนอราคา', 'เปิดดูรายละเอียดใบเสนอราคา'],
        'QuotationController@pdf' => ['quotation.print', 'เอกสาร', 'ดาวน์โหลดใบเสนอราคา'],
        'QuotationController@requestEditorHelp' => ['quotation.help_request', 'ใบเสนอราคา', 'ส่งคำขอให้ Sale COO ช่วยแก้ใบเสนอราคา'],
        'QuotationController@updateEditorHelp' => ['quotation.help_update', 'ใบเสนอราคา', 'อัปเดตสถานะคำขอช่วยแก้ใบเสนอราคา'],
        'BillingWorkflowController@createFromQuotation' => ['bill.create_from_quotation', 'บิล', 'สร้างบิลจากใบเสนอราคา'],
        'BillingWorkflowController@createFromPurchaseOrder' => ['bill.create_from_purchase_order', 'บิล', 'สร้างรายการเปิดบิลจาก PO โดยตรง'],
        'BillingWorkflowController@show' => ['bill.view', 'บิล', 'เปิดดูรายละเอียดบิล'],
        'BillingWorkflowController@updateBySale' => ['bill.sale_update', 'บิล', 'Sale แก้ไขข้อมูลบิล'],
        'BillingWorkflowController@submitDraft' => ['bill.submit', 'อนุมัติบิล', 'ส่งบิลเพื่อขออนุมัติ'],
        'BillingWorkflowController@approve' => ['bill.approve', 'อนุมัติบิล', 'อนุมัติบิล'],
        'BillingWorkflowController@approvalCertificate' => ['bill.approval_evidence_download', 'เอกสาร', 'ดาวน์โหลดใบรับรองหลักฐานการอนุมัติ'],
        'BillingWorkflowController@reject' => ['bill.reject', 'อนุมัติบิล', 'ตีกลับบิล'],
        'BillingWorkflowController@decline' => ['bill.decline', 'อนุมัติบิล', 'ไม่อนุมัติบิล'],
        'BillingWorkflowController@adminUpdate' => ['bill.admin_update', 'จัดเตรียมบิล', 'แก้ไขข้อมูลส่วนกลางของบิล'],
        'BillingWorkflowController@adminUpdateStatus' => ['bill.admin_status_update', 'สถานะบิล', 'Admin เปลี่ยนสถานะบิล'],
        'BillingWorkflowController@complete' => ['bill.complete', 'จัดเตรียมบิล', 'ปิดงานบิล'],
        'BillingWorkflowController@completeAll' => ['bill.complete_all', 'จัดเตรียมบิล', 'ปิดงานบิลทั้งหมด'],
        'BillingWorkflowController@export' => ['bill.export', 'เอกสาร', 'ส่งออกข้อมูลบิล'],
        'BillingWorkflowController@exportQuotationsPdf' => ['quotation.batch_export', 'เอกสาร', 'ส่งออกใบเสนอราคาหลายฉบับเป็น PDF เดียว'],
        'BillingWorkflowController@pdf' => ['bill.print', 'เอกสาร', 'ดาวน์โหลดเอกสารบิล'],
        'BillingWorkflowController@cancel' => ['bill.cancel', 'บิล', 'ยกเลิกบิลโดยเก็บเอกสารเดิม'],
        'BillingDocumentController@store' => ['bill.purchase_order_upload', 'เอกสาร', 'แนบหรือเปลี่ยนไฟล์ PO ลูกค้า'],
        'BillingDocumentController@show' => ['bill.purchase_order_download', 'เอกสาร', 'เปิดไฟล์ PO ลูกค้า'],
        'BillingDocumentController@storeSignedQuotation' => ['bill.signed_quotation_upload', 'เอกสาร', 'แนบใบเสนอราคาที่ลูกค้าลงชื่อ'],
        'BillingDocumentController@showSignedQuotation' => ['bill.signed_quotation_download', 'เอกสาร', 'เปิดใบเสนอราคาที่ลูกค้าลงชื่อ'],
        'CreditRequestController@store' => ['credit.create', 'วงเงินเครดิต', 'สร้างคำขอวงเงินเครดิต'],
        'CreditRequestController@approve' => ['credit.approve', 'วงเงินเครดิต', 'อนุมัติวงเงินเครดิต'],
        'CreditRequestController@reject' => ['credit.reject', 'วงเงินเครดิต', 'ไม่อนุมัติวงเงินเครดิต'],
        'CreditRequestController@open' => ['credit.open', 'วงเงินเครดิต', 'เปิดวงเงินลูกค้าในระบบ'],
        'CreditRequestController@document' => ['credit.document', 'เอกสาร', 'เปิดเอกสารคำขอวงเงิน'],
        'CreditProfileController@store' => ['credit_profile.save', 'ข้อมูลหลัก', 'บันทึกข้อมูลเครดิตลูกค้า'],
        'CreditProfileController@destroy' => ['credit_profile.delete', 'ข้อมูลหลัก', 'ลบข้อมูลเครดิตลูกค้า'],
        'NoticeController@store' => ['notice.create', 'ประกาศ', 'สร้างประกาศ'],
        'NoticeController@toggle' => ['notice.toggle', 'ประกาศ', 'เปลี่ยนสถานะประกาศ'],
        'TaskController@store' => ['task.create', 'ตารางงาน', 'สร้างงาน'],
        'TaskController@updateStatus' => ['task.update_progress', 'ตารางงาน', 'อัปเดตสถานะและความคืบหน้างาน'],
        'TaskController@destroy' => ['task.delete', 'ตารางงาน', 'ลบงาน'],
        'FibroscanBookingController@store' => ['fibroscan_booking.request', 'ตารางจองเครื่อง', 'ส่งคำขอจองเครื่อง FibroScan'],
        'FibroscanBookingController@update' => ['fibroscan_booking.update', 'ตารางจองเครื่อง', 'แก้ไขรายการจองเครื่อง FibroScan'],
        'FibroscanBookingController@review' => ['fibroscan_booking.review', 'ตารางจองเครื่อง', 'พิจารณาคำขอจองเครื่อง FibroScan'],
        'FibroscanBookingController@updateStatus' => ['fibroscan_booking.status', 'ตารางจองเครื่อง', 'เปลี่ยนสถานะรายการจองเครื่อง FibroScan'],
        'FibroscanMachineController@store' => ['fibroscan_machine.create', 'ทะเบียนเครื่อง', 'เพิ่มเครื่อง FibroScan'],
        'FibroscanMachineController@update' => ['fibroscan_machine.update', 'ทะเบียนเครื่อง', 'แก้ไขทะเบียนเครื่อง FibroScan'],
        'FibroscanMachineController@destroy' => ['fibroscan_machine.delete', 'ทะเบียนเครื่อง', 'ลบเครื่อง FibroScan'],
        'MasterDataController@storeCustomer' => ['master.customer_create', 'ข้อมูลหลัก', 'เพิ่มข้อมูลลูกค้า'],
        'MasterDataController@destroyCustomer' => ['master.customer_delete', 'ข้อมูลหลัก', 'ลบข้อมูลลูกค้า'],
        'MasterDataController@storeSalesman' => ['master.salesman_create', 'ข้อมูลหลัก', 'เพิ่มข้อมูลพนักงานขาย'],
        'MasterDataController@destroySalesman' => ['master.salesman_delete', 'ข้อมูลหลัก', 'ลบข้อมูลพนักงานขาย'],
        'UserManagementController@store' => ['user.create', 'ผู้ใช้งาน', 'สร้างบัญชีผู้ใช้'],
        'UserManagementController@update' => ['user.update', 'ผู้ใช้งาน', 'แก้ไขบัญชีผู้ใช้'],
        'UserManagementController@destroy' => ['user.delete', 'ผู้ใช้งาน', 'ลบบัญชีผู้ใช้'],
        'UserSignatureController@store' => ['user.signature_upload', 'ผู้ใช้งาน', 'เพิ่ม Version ลายเซ็นประจำบัญชี'],
        'UserSignatureController@show' => ['user.signature_view', 'ผู้ใช้งาน', 'เปิดดูตัวอย่างลายเซ็นประจำบัญชี'],
        'PublicTaxController@confirm' => ['public_tax.confirm', 'ข้อมูลภาษี', 'ลูกค้ายืนยันข้อมูลภาษี'],
        'PublicTaxController@show' => ['public_tax.view', 'ข้อมูลภาษี', 'ลูกค้าเปิดหน้าตรวจสอบข้อมูลภาษี'],
        'PublicTaxController@cancel' => ['public_tax.cancel', 'ข้อมูลภาษี', 'ลูกค้ายกเลิกการยืนยันข้อมูลภาษี'],
        'PublicTaxController@pdf' => ['public_tax.print', 'เอกสาร', 'ลูกค้าดาวน์โหลดเอกสารภาษี'],
        'CustomerAcceptanceController@show' => ['customer_acceptance.view', 'ลายมือชื่ออิเล็กทรอนิกส์', 'ลูกค้าเปิดใบเสนอราคาเพื่อยืนยัน'],
        'CustomerAcceptanceController@accept' => ['customer_acceptance.sign', 'ลายมือชื่ออิเล็กทรอนิกส์', 'ลูกค้ายืนยันและลงลายมือชื่อใบเสนอราคา'],
        'CustomerAcceptanceController@pdf' => ['customer_acceptance.print', 'เอกสาร', 'ลูกค้าดาวน์โหลดใบเสนอราคาที่ลงนาม'],
        'ReceiptOcrController@__invoke' => ['receipt.scan', 'ใบเสร็จ', 'สแกนข้อมูลใบเสร็จ'],
        'PurchaseOrderScanController@__invoke' => ['purchase_order.scan', 'เอกสาร', 'ใช้ AI อ่านและช่วยกรอกใบ PO'],
        'BudgetAnalysisController@analyze' => ['budget.analyze', 'วิเคราะห์งบประมาณ', 'วิเคราะห์ไฟล์งบประมาณ'],
        'BudgetAnalysisController@download' => ['budget.download', 'เอกสาร', 'ดาวน์โหลดผลวิเคราะห์งบประมาณ'],
        'BudgetAnalysisController@preview' => ['budget.preview', 'เอกสาร', 'เปิดดูตัวอย่างผลวิเคราะห์งบประมาณ'],
        'BudgetAnalysisController@clear' => ['budget.clear', 'วิเคราะห์งบประมาณ', 'ล้างผลวิเคราะห์งบประมาณ'],
        'DashboardController@report' => ['dashboard.export', 'เอกสาร', 'ส่งออก Sales Report'],
        'SalesCallController@saveDay' => ['sales_call.save_day', 'Call Sale', 'บันทึกตาราง Call Sale ประจำวัน'],
        'SalesCallController@savePeriod' => ['sales_call.save_period', 'Call Sale', 'บันทึกตาราง Call Sale ช่วงครึ่งเดือน'],
        'SalesCallController@deleteHistory' => ['sales_call.delete_history', 'Call Sale', 'ลบประวัติ Call Sale ที่เลือก'],
        'SalesCallController@importPreview' => ['sales_call.import_preview', 'Call Sale', 'ตรวจสอบไฟล์ Excel Call Sale ก่อนนำเข้า'],
        'SalesCallController@import' => ['sales_call.import', 'Call Sale', 'นำเข้าข้อมูล Call Sale จาก Excel'],
        'SalesCallController@downloadImport' => ['sales_call.source_download', 'เอกสาร', 'ดาวน์โหลดไฟล์ Excel Call Sale ต้นฉบับ'],
        'SalesCallController@deleteMonths' => ['sales_call.delete_months', 'Call Sale', 'ลบข้อมูล Call Sale รายเดือนโดยผู้ดูแล'],
        'SalesCallController@updateManagedActivity' => ['sales_call.manager_update', 'Call Sale', 'แก้ไขข้อมูล Call Sale โดยผู้ดูแล'],
        'SalesCallController@exportExcel' => ['sales_call.export_excel', 'เอกสาร', 'ส่งออก Call Sale เป็น Excel'],
        'SalesCallController@exportDashboard' => ['sales_call.export_dashboard', 'เอกสาร', 'ส่งออก Call Sale Dashboard'],
    ];

    /** @var array<int, string> */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'secret', 'authorization',
        'cookie', 'tax_qr_token', 'customer_acceptance_token', 'file_bytes', 'signature_image', 'signature_image_base64',
        'national_id', 'sale_identity_no', 'representative_address',
    ];

    public function shouldLog(Request $request): bool
    {
        $handler = $this->handler($request);
        if ($handler === 'AuditLogController@index' || $handler === 'HealthController@__invoke') {
            return false;
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return isset(self::EVENT_MAP[$handler]);
    }

    /** @param array<string, mixed>|null $requestData */
    public function record(
        Request $request,
        Response $response,
        float $startedAt,
        mixed $actorBefore = null,
        ?array $requestData = null,
    ): void {
        try {
            $actor = $request->user() ?? $actorBefore;
            [$action, $category, $description] = $this->eventFor($request);
            [$targetType, $targetId] = $this->targetFor($request);
            $status = $response->getStatusCode();
            $payload = $this->responseRequestData($request, $response, $requestData);

            AuditLog::query()->create([
                'occurred_at' => now(),
                'user_id' => $actor?->id,
                'username' => $actor?->username ?? $this->fallbackUsername($request),
                'role' => $actor?->role,
                'department' => $actor?->department,
                'action' => $action,
                'category' => $category,
                'description' => $description,
                'http_method' => $request->method(),
                'route_uri' => $request->route()?->uri(),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'request_data' => $payload === [] ? null : $this->sanitize($payload),
                'response_status' => $status,
                'was_successful' => $status < 400,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $data */
    public function responseRequestData(Request $request, Response $response, ?array $data = null): array
    {
        $payload = $data ?? $this->sanitizedRequestData($request) ?? [];
        // This key is server-owned; callers cannot forge document download evidence.
        unset($payload['document_download']);
        $document = $request->attributes->get('document_audit');
        if (is_array($document) && $response->isSuccessful() && ! $request->isMethod('HEAD')) {
            $content = $response->getContent();
            $payload['document_download'] = [
                ...$document,
                'result' => 'response_generated',
                'content_disposition' => $response->headers->get('Content-Disposition'),
                'sha256' => is_string($content) ? hash('sha256', $content) : null,
            ];
        }

        return $payload;
    }

    /** @param array<string, mixed> $data */
    public function recordSystemEvent(
        ?User $actor,
        string $action,
        string $category,
        string $description,
        ?string $targetType = null,
        ?string $targetId = null,
        array $data = [],
        bool $successful = true,
        ?float $startedAt = null,
    ): void {
        try {
            AuditLog::query()->create([
                'occurred_at' => now(),
                'user_id' => $actor?->id,
                'username' => $actor?->username,
                'role' => $actor?->role,
                'department' => $actor?->department,
                'action' => $action,
                'category' => $category,
                'description' => $description,
                'http_method' => 'SYSTEM',
                'route_uri' => null,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'request_data' => $data === [] ? null : $this->sanitize($data),
                'response_status' => $successful ? 200 : 500,
                'was_successful' => $successful,
                'ip_address' => null,
                'user_agent' => 'Laravel Mail',
                'duration_ms' => $startedAt === null ? null : round((microtime(true) - $startedAt) * 1000, 2),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array<string, mixed>|null */
    public function sanitizedRequestData(Request $request): ?array
    {
        $payload = $request->isMethod('GET') ? $request->query() : $request->all();
        foreach ($request->allFiles() as $key => $file) {
            $payload[$key] = $this->fileMetadata($file);
        }

        $sanitized = $this->sanitize($payload);

        return $sanitized === [] ? null : $sanitized;
    }

    /** @return array{string, string, string} */
    private function eventFor(Request $request): array
    {
        $handler = $this->handler($request);
        if (isset(self::EVENT_MAP[$handler])) {
            return self::EVENT_MAP[$handler];
        }

        $method = strtolower($request->method());

        return ["http.{$method}", 'การใช้งานระบบ', "{$request->method()} {$request->route()?->uri()}"];
    }

    private function handler(Request $request): string
    {
        $action = (string) $request->route()?->getActionName();
        if (! str_contains($action, '@')) {
            return class_basename($action).'@__invoke';
        }

        [$controller, $method] = explode('@', $action, 2);

        return class_basename($controller).'@'.$method;
    }

    /** @return array{string|null, string|null} */
    private function targetFor(Request $request): array
    {
        $targets = [
            'bill' => 'bill', 'quotation' => 'quotation', 'creditRequest' => 'credit_request',
            'creditProfile' => 'credit_profile', 'notice' => 'notice', 'task' => 'task',
            'customer' => 'customer', 'salesman' => 'salesman', 'user' => 'user',
            'booking' => 'fibroscan_booking', 'machine' => 'fibroscan_machine',
            'salesCall' => 'sales_call',
            'analysisId' => 'budget_analysis', 'document' => 'document',
        ];

        foreach ($targets as $parameter => $type) {
            $value = $request->route($parameter);
            if ($value instanceof Model) {
                return [$type, (string) $value->getRouteKey()];
            }
            if (is_scalar($value) && (string) $value !== '') {
                return [$type, (string) $value];
            }
        }

        return [null, null];
    }

    private function fallbackUsername(Request $request): ?string
    {
        if ($this->handler($request) === 'AuthController@login') {
            return Str::limit((string) $request->input('username'), 255, '');
        }
        if (str_starts_with((string) $request->route()?->uri(), 'api/v1/public/')) {
            return 'Customer Portal';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function sanitize(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);
            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)
                || str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'signature_image')) {
                $clean[$key] = '[REDACTED]';

                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function fileMetadata(mixed $file): mixed
    {
        if (is_array($file)) {
            return array_map(fn (mixed $item) => $this->fileMetadata($item), $file);
        }
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $path = $file->getPathname();

        return [
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => is_file($path) ? $file->getSize() : null,
            'upload_error' => $file->getError(),
            'upload_error_message' => $file->getErrorMessage(),
        ];
    }
}
