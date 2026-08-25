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
        'PasswordResetController@__invoke' => ['auth.reset_password', 'การเข้าสู่ระบบ', 'เปลี่ยนรหัสผ่าน'],
        'QuotationController@store' => ['quotation.create', 'ใบเสนอราคา', 'สร้างใบเสนอราคา'],
        'QuotationController@show' => ['quotation.view', 'ใบเสนอราคา', 'เปิดดูรายละเอียดใบเสนอราคา'],
        'QuotationController@pdf' => ['quotation.print', 'เอกสาร', 'เปิดหรือพิมพ์ใบเสนอราคา'],
        'QuotationController@customNamePdf' => ['quotation.custom_name_print', 'เอกสาร', 'สร้างใบเสนอราคาที่เปลี่ยนชื่อสินค้า'],
        'BillingWorkflowController@createFromQuotation' => ['bill.create_from_quotation', 'บิล', 'สร้างบิลจากใบเสนอราคา'],
        'BillingWorkflowController@show' => ['bill.view', 'บิล', 'เปิดดูรายละเอียดบิล'],
        'BillingWorkflowController@updateBySale' => ['bill.sale_update', 'บิล', 'Sale แก้ไขข้อมูลบิล'],
        'BillingWorkflowController@submitDraft' => ['bill.submit', 'อนุมัติบิล', 'ส่งบิลเพื่อขออนุมัติ'],
        'BillingWorkflowController@approve' => ['bill.approve', 'อนุมัติบิล', 'อนุมัติบิล'],
        'BillingWorkflowController@reject' => ['bill.reject', 'อนุมัติบิล', 'ตีกลับบิล'],
        'BillingWorkflowController@prepare' => ['bill.prepare', 'จัดเตรียมบิล', 'Admin จัดเตรียมบิล'],
        'BillingWorkflowController@adminUpdate' => ['bill.admin_update', 'จัดเตรียมบิล', 'แก้ไขข้อมูลส่วนกลางของบิล'],
        'BillingWorkflowController@adminUpdateStatus' => ['bill.admin_status_update', 'สถานะบิล', 'Admin เปลี่ยนสถานะบิล'],
        'BillingWorkflowController@complete' => ['bill.complete', 'จัดเตรียมบิล', 'ปิดงานบิล'],
        'BillingWorkflowController@completeAll' => ['bill.complete_all', 'จัดเตรียมบิล', 'ปิดงานบิลทั้งหมด'],
        'BillingWorkflowController@export' => ['bill.export', 'เอกสาร', 'ส่งออกข้อมูลบิล'],
        'BillingWorkflowController@pdf' => ['bill.print', 'เอกสาร', 'เปิดหรือพิมพ์เอกสารบิล'],
        'BillingWorkflowController@destroy' => ['bill.delete', 'บิล', 'ลบบิล'],
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
        'TaskController@updateStatus' => ['task.update_status', 'ตารางงาน', 'เปลี่ยนสถานะงาน'],
        'TaskController@destroy' => ['task.delete', 'ตารางงาน', 'ลบงาน'],
        'MasterDataController@storeCustomer' => ['master.customer_create', 'ข้อมูลหลัก', 'เพิ่มข้อมูลลูกค้า'],
        'MasterDataController@destroyCustomer' => ['master.customer_delete', 'ข้อมูลหลัก', 'ลบข้อมูลลูกค้า'],
        'MasterDataController@storeSalesman' => ['master.salesman_create', 'ข้อมูลหลัก', 'เพิ่มข้อมูลพนักงานขาย'],
        'MasterDataController@destroySalesman' => ['master.salesman_delete', 'ข้อมูลหลัก', 'ลบข้อมูลพนักงานขาย'],
        'UserManagementController@store' => ['user.create', 'ผู้ใช้งาน', 'สร้างบัญชีผู้ใช้'],
        'UserManagementController@update' => ['user.update', 'ผู้ใช้งาน', 'แก้ไขบัญชีผู้ใช้'],
        'UserManagementController@destroy' => ['user.delete', 'ผู้ใช้งาน', 'ลบบัญชีผู้ใช้'],
        'PublicTaxController@confirm' => ['public_tax.confirm', 'ข้อมูลภาษี', 'ลูกค้ายืนยันข้อมูลภาษี'],
        'PublicTaxController@show' => ['public_tax.view', 'ข้อมูลภาษี', 'ลูกค้าเปิดหน้าตรวจสอบข้อมูลภาษี'],
        'PublicTaxController@cancel' => ['public_tax.cancel', 'ข้อมูลภาษี', 'ลูกค้ายกเลิกการยืนยันข้อมูลภาษี'],
        'PublicTaxController@pdf' => ['public_tax.print', 'เอกสาร', 'ลูกค้าเปิดหรือพิมพ์เอกสารภาษี'],
        'ReceiptOcrController@__invoke' => ['receipt.scan', 'ใบเสร็จ', 'สแกนข้อมูลใบเสร็จ'],
        'BudgetAnalysisController@analyze' => ['budget.analyze', 'วิเคราะห์งบประมาณ', 'วิเคราะห์ไฟล์งบประมาณ'],
        'BudgetAnalysisController@download' => ['budget.download', 'เอกสาร', 'ดาวน์โหลดผลวิเคราะห์งบประมาณ'],
        'BudgetAnalysisController@preview' => ['budget.preview', 'เอกสาร', 'เปิดดูตัวอย่างผลวิเคราะห์งบประมาณ'],
        'BudgetAnalysisController@clear' => ['budget.clear', 'วิเคราะห์งบประมาณ', 'ล้างผลวิเคราะห์งบประมาณ'],
        'DashboardController@report' => ['dashboard.export', 'เอกสาร', 'ส่งออก Sales Report'],
        'SalesCallController@saveDay' => ['sales_call.save_day', 'Call Sale', 'บันทึกตาราง Call Sale ประจำวัน'],
        'SalesCallController@exportExcel' => ['sales_call.export_excel', 'เอกสาร', 'ส่งออก Call Sale เป็น Excel'],
        'SalesCallController@exportDashboard' => ['sales_call.export_dashboard', 'เอกสาร', 'ส่งออก Call Sale Dashboard'],
    ];

    /** @var array<int, string> */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'secret', 'authorization',
        'cookie', 'tax_qr_token', 'file_bytes',
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
                'request_data' => $requestData ?? $this->sanitizedRequestData($request),
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
        if (str_starts_with((string) $request->route()?->uri(), 'api/v1/public/tax/')) {
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
            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true) || str_contains($normalizedKey, 'password')) {
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
