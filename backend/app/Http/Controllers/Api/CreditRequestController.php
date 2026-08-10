<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditProfile;
use App\Models\CreditRequest;
use App\Services\Authorization\RoleAccess;
use App\Services\Notifications\WorkflowEmailNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $group = RoleAccess::groupForRole($request->user()->role);
        $baseQuery = CreditRequest::query()
            ->when(in_array($group, [RoleAccess::GROUP_SALE, RoleAccess::GROUP_DEFAULT], true), fn ($query) => $query->where('created_by', $request->user()->username));
        $statusSummary = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total);
        $requests = $baseQuery
            ->select('*')
            ->with(['documents:doc_id,request_id,doc_type,file_name,mime_type,uploaded_at'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 20), 5), 100));

        return response()->json([
            ...$requests->toArray(),
            'status_summary' => $statusSummary,
        ]);
    }

    public function store(Request $request, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $baseRules = [
            'request_type' => ['required', 'in:new_customer,change_existing'],
            'company_name' => ['required', 'string', 'max:1000'],
            'tax_id' => ['required_if:request_type,new_customer', 'nullable', 'regex:/^\d{13}$/'],
            'registered_address' => ['required_if:request_type,new_customer', 'nullable', 'string'],
            'authorized_directors' => ['required_if:request_type,new_customer', 'nullable', 'string'],
            'ar_code' => ['required_if:request_type,change_existing', 'nullable', 'string', 'max:100'],
            'ar_name' => ['nullable', 'string', 'max:1000'],
            'current_credit_limit' => ['nullable', 'numeric', 'min:0'],
            'current_credit_term' => ['nullable', 'integer', 'min:0'],
            'requested_credit_limit' => ['required', 'numeric', 'gt:0'],
            'requested_credit_term' => ['required', 'integer', 'gt:0'],
            'expected_monthly_volume' => ['nullable', 'numeric', 'min:0'],
            'justification' => ['required', 'string', 'max:10000'],
            'documents' => ['required_if:request_type,new_customer', 'array'],
            'documents.*' => ['file', 'mimes:pdf,png,jpg,jpeg,xlsx', 'max:10240'],
        ];
        $data = $request->validate($baseRules);
        if ($data['request_type'] === 'new_customer' && count($request->file('documents', [])) < 4) {
            throw ValidationException::withMessages(['documents' => 'กรุณาแนบเอกสารให้ครบ 4 รายการ']);
        }

        $creditRequest = DB::transaction(function () use ($request, $data): CreditRequest {
            $creditRequest = CreditRequest::query()->create([
                ...collect($data)->except('documents')->all(),
                'tax_id' => $data['tax_id'] ?: '-',
                'registered_address' => $data['registered_address'] ?: '-',
                'authorized_directors' => $data['authorized_directors'] ?: '-',
                'expected_monthly_volume' => $data['expected_monthly_volume'] ?? 0,
                'status' => 'รออนุมัติวงเงิน',
                'created_by' => $request->user()->username,
                'created_at' => now(),
            ]);

            $documentLabels = [
                'affidavit' => 'หนังสือรับรองบริษัท',
                'vat' => 'ใบทะเบียนภาษีมูลค่าเพิ่ม ภ.พ.20',
                'director_id' => 'สำเนาบัตรประชาชนกรรมการ',
                'bank_statement' => 'Statement บัญชีธนาคาร',
            ];
            foreach ($request->file('documents', []) as $key => $file) {
                DB::insert(
                    "INSERT INTO credit_request_documents (request_id, doc_type, file_name, mime_type, file_bytes, uploaded_at) VALUES (?, ?, ?, ?, decode(?, 'base64'), CURRENT_TIMESTAMP)",
                    [$creditRequest->request_id, $documentLabels[$key] ?? $key, $file->getClientOriginalName(), $file->getMimeType(), base64_encode((string) file_get_contents($file->getRealPath()))],
                );
            }

            return $creditRequest;
        });
        $notifier->creditRequested($creditRequest, $request->user());

        return response()->json(['data' => $creditRequest->load('documents')], 201);
    }

    public function approve(Request $request, CreditRequest $creditRequest, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $creditRequest = $this->transition($creditRequest, 'รออนุมัติวงเงิน', [
            'status' => 'อนุมัติวงเงินแล้ว',
            'approved_by' => $request->user()->username,
            'approved_at' => now(),
            'reject_reason' => null,
        ]);
        $notifier->creditApproved($creditRequest, $request->user());

        return response()->json(['data' => $creditRequest]);
    }

    public function reject(Request $request, CreditRequest $creditRequest, WorkflowEmailNotifier $notifier): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $creditRequest = $this->transition($creditRequest, 'รออนุมัติวงเงิน', [
            'status' => 'ไม่อนุมัติวงเงิน',
            'reject_reason' => $data['reason'],
        ]);
        $notifier->creditRejected($creditRequest, $request->user(), $data['reason']);

        return response()->json(['data' => $creditRequest]);
    }

    public function open(Request $request, CreditRequest $creditRequest): JsonResponse
    {
        $data = $request->validate([
            'ar_code' => ['required', 'string', 'max:100'],
            'ar_name' => ['required', 'string', 'max:1000'],
            'credit_limit' => ['required', 'numeric', 'gt:0'],
            'credit_term' => ['required', 'integer', 'gt:0'],
        ]);
        $creditRequest = DB::transaction(function () use ($request, $creditRequest, $data): CreditRequest {
            $creditRequest = CreditRequest::query()->lockForUpdate()->findOrFail($creditRequest->request_id);
            if ($creditRequest->status !== 'อนุมัติวงเงินแล้ว') {
                throw ValidationException::withMessages(['status' => 'คำขอนี้ยังไม่ผ่านการอนุมัติ หรือถูกดำเนินการแล้ว']);
            }
            CreditProfile::query()->updateOrCreate(['ar_code' => $data['ar_code']], [
                'ar_name' => $data['ar_name'],
                'credit_limit' => $data['credit_limit'],
                'credit_term' => $data['credit_term'],
                'source_file' => 'admin_credit_request',
                'updated_at' => now(),
            ]);
            $creditRequest->forceFill([
                'status' => 'เปิดวงเงินแล้ว',
                'opened_by' => $request->user()->username,
                'opened_at' => now(),
                'ar_code' => $data['ar_code'],
                'ar_name' => $data['ar_name'],
            ])->save();

            return $creditRequest;
        });

        return response()->json(['data' => $creditRequest]);
    }

    public function document(CreditRequest $creditRequest, int $document): mixed
    {
        $row = DB::selectOne("SELECT file_name, mime_type, encode(file_bytes, 'base64') AS encoded FROM credit_request_documents WHERE request_id = ? AND doc_id = ?", [$creditRequest->request_id, $document]);
        abort_unless($row, 404);

        return response(base64_decode($row->encoded), 200, [
            'Content-Type' => $row->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.rawurlencode($row->file_name).'"',
        ]);
    }

    /** @param array<string, mixed> $updates */
    private function transition(CreditRequest $request, string $expected, array $updates): CreditRequest
    {
        return DB::transaction(function () use ($request, $expected, $updates): CreditRequest {
            $request = CreditRequest::query()->lockForUpdate()->findOrFail($request->request_id);
            if ($request->status !== $expected) {
                throw ValidationException::withMessages(['status' => "สถานะปัจจุบันคือ {$request->status}"]);
            }
            $request->forceFill($updates)->save();

            return $request->fresh('documents');
        });
    }
}
