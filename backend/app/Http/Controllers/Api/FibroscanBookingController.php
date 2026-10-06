<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FibroscanBooking;
use App\Models\FibroscanBookingChange;
use App\Models\FibroscanMachine;
use App\Services\Authorization\RoleAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FibroscanBookingController extends Controller
{
    private const ACTIVE_STATUSES = ['จองแล้ว', 'กำลังใช้งาน'];
    private const APPROVAL_STATUSES = ['รออนุมัติ', 'อนุมัติแล้ว', 'ไม่อนุมัติ'];
    private const TRACKED_FIELDS = [
        'machine_id', 'hospital_name', 'purpose', 'reserved_from', 'reserved_until',
        'contact_name', 'contact_phone', 'note', 'approval_status', 'review_note', 'status',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'machine_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['จองแล้ว', 'กำลังใช้งาน', 'คืนแล้ว', 'ยกเลิก'])],
            'approval_status' => ['nullable', Rule::in(self::APPROVAL_STATUSES)],
        ]);

        $bookings = FibroscanBooking::query()
            ->with([
                'machine:machine_id,machine_code,machine_name,status',
                'owner:id,username,first_name,last_name,department',
                'reviewer:id,username,first_name,last_name',
                'changes.actor:id,username,first_name,last_name',
            ])
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->where('reserved_until', '>=', Carbon::parse($date, 'Asia/Bangkok')->startOfDay()->utc()))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->where('reserved_from', '<', Carbon::parse($date, 'Asia/Bangkok')->addDay()->startOfDay()->utc()))
            ->when($data['machine_id'] ?? null, fn ($query, $machineId) => $query->where('machine_id', $machineId))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['approval_status'] ?? null, fn ($query, $status) => $query->where('approval_status', $status))
            ->orderByRaw("CASE approval_status WHEN 'รออนุมัติ' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('booking_id')
            ->get()
            ->map(fn (FibroscanBooking $booking) => $this->present($request, $booking));

        return response()->json(['data' => [
            'bookings' => $bookings,
            'machines' => FibroscanMachine::query()->orderBy('machine_code')->get(),
        ]]);
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reserved_from' => ['required', 'date'],
            'reserved_until' => ['required', 'date', 'after:reserved_from'],
            'except_booking_id' => ['nullable', 'integer'],
        ]);
        $conflicts = FibroscanBooking::query()
            ->select(['booking_id', 'machine_id', 'hospital_name', 'reserved_from', 'reserved_until'])
            ->where('approval_status', 'อนุมัติแล้ว')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($data['except_booking_id'] ?? null, fn ($query, $id) => $query->where('booking_id', '<>', $id))
            ->where('reserved_from', '<', Carbon::parse($data['reserved_until']))
            ->where('reserved_until', '>', Carbon::parse($data['reserved_from']))
            ->get();

        return response()->json(['data' => $conflicts]);
    }

    public function store(Request $request): JsonResponse
    {
        $booking = $this->saveBooking($request, $this->validatedBooking($request));
        $this->recordChange($request, $booking, 'ส่งคำขอจอง', [], $booking->only(self::TRACKED_FIELDS));

        return response()->json(['data' => $this->present($request, $this->loadRelations($booking))], 201);
    }

    public function update(Request $request, FibroscanBooking $booking): JsonResponse
    {
        $this->authorizeChange($request, $booking);
        abort_if(
            in_array($booking->status, ['คืนแล้ว', 'ยกเลิก'], true) && ! $this->canManageAllBookings($request),
            422,
            'รายการที่คืนเครื่องหรือยกเลิกแล้วไม่สามารถแก้ไขได้',
        );

        $before = $booking->only(self::TRACKED_FIELDS);
        $updated = $this->saveBooking($request, $this->validatedBooking($request), $booking);
        $this->recordChange($request, $updated, 'แก้ไขคำขอ/การจอง', $before, $updated->only(self::TRACKED_FIELDS));

        return response()->json(['data' => $this->present($request, $this->loadRelations($updated))]);
    }

    public function review(Request $request, FibroscanBooking $booking): JsonResponse
    {
        abort_unless($this->canManageAllBookings($request), 403, 'เฉพาะ Admin หรือ Approver เท่านั้นที่พิจารณาคำขอได้');
        $data = $request->validate([
            'approval_status' => ['required', Rule::in(['อนุมัติแล้ว', 'ไม่อนุมัติ'])],
            'review_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $before = $booking->only(self::TRACKED_FIELDS);

        try {
            $updated = DB::transaction(function () use ($request, $booking, $data): FibroscanBooking {
                $locked = FibroscanBooking::query()->whereKey($booking->booking_id)->lockForUpdate()->firstOrFail();
                if ($data['approval_status'] === 'อนุมัติแล้ว') {
                    $machine = FibroscanMachine::query()->whereKey($locked->machine_id)->lockForUpdate()->firstOrFail();
                    abort_unless($machine->status === 'พร้อมใช้งาน', 422, 'เครื่องนี้ไม่พร้อมใช้งาน กรุณาแก้ไขคำขอและเลือกเครื่องอื่น');
                    $this->ensureAvailable(
                        (int) $locked->machine_id,
                        Carbon::parse($locked->reserved_from),
                        Carbon::parse($locked->reserved_until),
                        (int) $locked->booking_id,
                    );
                }

                $locked->forceFill([
                    'approval_status' => $data['approval_status'],
                    'review_note' => $this->nullableText($data['review_note'] ?? null),
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_by' => $request->user()->username,
                    'reviewed_at' => now(),
                    'status' => $data['approval_status'] === 'อนุมัติแล้ว' ? 'จองแล้ว' : $locked->status,
                    'updated_at' => now(),
                ])->save();

                return $locked->fresh();
            });
        } catch (QueryException $exception) {
            $this->throwFriendlyConflict($exception);
        }

        $action = $data['approval_status'] === 'อนุมัติแล้ว' ? 'อนุมัติคำขอ' : 'ไม่อนุมัติคำขอ';
        $this->recordChange($request, $updated, $action, $before, $updated->only(self::TRACKED_FIELDS));

        return response()->json(['data' => $this->present($request, $this->loadRelations($updated))]);
    }

    public function updateStatus(Request $request, FibroscanBooking $booking): JsonResponse
    {
        abort_unless($this->canManageAllBookings($request), 403, 'เฉพาะ Admin หรือ Approver เท่านั้นที่เปลี่ยนสถานะการใช้งานได้');
        abort_unless($booking->approval_status === 'อนุมัติแล้ว', 422, 'ต้องอนุมัติคำขอก่อนเปลี่ยนสถานะการใช้งาน');
        $data = $request->validate([
            'status' => ['required', Rule::in(['จองแล้ว', 'กำลังใช้งาน', 'คืนแล้ว', 'ยกเลิก'])],
        ]);
        $before = $booking->only(self::TRACKED_FIELDS);

        try {
            DB::transaction(function () use ($booking, $data): void {
                $machine = FibroscanMachine::query()->whereKey($booking->machine_id)->lockForUpdate()->firstOrFail();
                if (in_array($data['status'], self::ACTIVE_STATUSES, true)) {
                    abort_unless($machine->status === 'พร้อมใช้งาน', 422, 'เครื่องนี้ไม่พร้อมใช้งาน กรุณาเลือกเครื่องอื่น');
                    $this->ensureAvailable(
                        (int) $booking->machine_id,
                        Carbon::parse($booking->reserved_from),
                        Carbon::parse($booking->reserved_until),
                        (int) $booking->booking_id,
                    );
                }
                $booking->forceFill([...$data, 'updated_at' => now()])->save();
            });
        } catch (QueryException $exception) {
            $this->throwFriendlyConflict($exception);
        }

        $updated = $booking->fresh();
        $this->recordChange($request, $updated, 'เปลี่ยนสถานะการใช้งาน', $before, $updated->only(self::TRACKED_FIELDS));

        return response()->json(['data' => $this->present($request, $this->loadRelations($updated))]);
    }

    /** @return array<string, mixed> */
    private function validatedBooking(Request $request): array
    {
        return $request->validate([
            'machine_id' => ['required', 'integer', 'exists:fibroscan_machines,machine_id'],
            'hospital_name' => ['required', 'string', 'max:500'],
            'purpose' => ['required', Rule::in(['ทดลองใช้', 'Event', 'เช่า', 'สาธิต', 'อื่นๆ'])],
            'reserved_from' => ['required', 'date'],
            'reserved_until' => ['required', 'date', 'after:reserved_from'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function saveBooking(Request $request, array $data, ?FibroscanBooking $booking = null): FibroscanBooking
    {
        $from = Carbon::parse($data['reserved_from']);
        $until = Carbon::parse($data['reserved_until']);

        try {
            return DB::transaction(function () use ($request, $data, $from, $until, $booking): FibroscanBooking {
                $machine = FibroscanMachine::query()->lockForUpdate()->findOrFail($data['machine_id']);
                $occupiesSchedule = $booking?->approval_status === 'อนุมัติแล้ว'
                    && in_array($booking->status, self::ACTIVE_STATUSES, true);
                if ($occupiesSchedule) {
                    abort_unless($machine->status === 'พร้อมใช้งาน', 422, 'เครื่องนี้ไม่พร้อมใช้งาน กรุณาเลือกเครื่องอื่น');
                    $this->ensureAvailable((int) $machine->machine_id, $from, $until, $booking?->booking_id);
                }

                $values = [...$data, 'reserved_from' => $from, 'reserved_until' => $until, 'updated_at' => now()];
                if ($booking === null) {
                    $values = [
                        ...$values,
                        'approval_status' => 'รออนุมัติ',
                        'status' => 'จองแล้ว',
                        'booked_by_user_id' => $request->user()->id,
                        'booked_by' => $request->user()->username,
                        'booked_by_department' => $request->user()->department,
                        'created_at' => now(),
                    ];
                    $booking = FibroscanBooking::query()->create($values);
                } else {
                    $booking->forceFill($values)->save();
                }

                return $booking->fresh();
            });
        } catch (QueryException $exception) {
            $this->throwFriendlyConflict($exception);
        }
    }

    private function ensureAvailable(int $machineId, Carbon $from, Carbon $until, ?int $exceptBookingId = null): void
    {
        $conflict = FibroscanBooking::query()
            ->where('machine_id', $machineId)
            ->where('approval_status', 'อนุมัติแล้ว')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($exceptBookingId, fn ($query) => $query->where('booking_id', '<>', $exceptBookingId))
            ->where('reserved_from', '<', $until)
            ->where('reserved_until', '>', $from)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['reserved_from' => 'เครื่องนี้ถูกจองในช่วงเวลาที่เลือกแล้ว กรุณาเลือกเครื่องหรือช่วงเวลาอื่น']);
        }
    }

    private function authorizeChange(Request $request, FibroscanBooking $booking): void
    {
        if ($this->canManageAllBookings($request)) {
            return;
        }

        $ownsPendingRequest = (int) $booking->booked_by_user_id === (int) $request->user()->id
            && $booking->approval_status === 'รออนุมัติ';
        abort_unless($ownsPendingRequest, 403, 'Sale แก้ไขได้เฉพาะคำขอของตนเองที่ยังรอพิจารณา');
    }

    private function canManageAllBookings(Request $request): bool
    {
        return RoleAccess::canUser($request->user(), 'approve_bills', 'system_admin');
    }

    /** @return array<string, mixed> */
    private function present(Request $request, FibroscanBooking $booking): array
    {
        $ownsBooking = (int) $booking->booked_by_user_id === (int) $request->user()->id;
        $managesAll = $this->canManageAllBookings($request);

        return [
            ...$booking->toArray(),
            'can_edit' => $managesAll || ($ownsBooking && $booking->approval_status === 'รออนุมัติ'),
            'can_manage_all' => $managesAll,
            'can_review' => $managesAll,
        ];
    }

    private function loadRelations(FibroscanBooking $booking): FibroscanBooking
    {
        return $booking->load([
            'machine:machine_id,machine_code,machine_name,status',
            'owner:id,username,first_name,last_name,department',
            'reviewer:id,username,first_name,last_name',
            'changes.actor:id,username,first_name,last_name',
        ]);
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function recordChange(Request $request, FibroscanBooking $booking, string $action, array $before, array $after): void
    {
        $changes = [];
        foreach (self::TRACKED_FIELDS as $field) {
            $old = $this->historyValue($before[$field] ?? null);
            $new = $this->historyValue($after[$field] ?? null);
            if ($old !== $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        FibroscanBookingChange::query()->create([
            'booking_id' => $booking->booking_id,
            'changed_by_user_id' => $request->user()->id,
            'changed_by' => $request->user()->username,
            'action' => $action,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    private function historyValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function throwFriendlyConflict(QueryException $exception): never
    {
        if ($exception->getCode() === '23P01') {
            throw ValidationException::withMessages(['reserved_from' => 'เครื่องนี้เพิ่งถูกอนุมัติให้ผู้ใช้อื่นในช่วงเวลาเดียวกัน กรุณาโหลดข้อมูลและเลือกเครื่องหรือเวลาใหม่']);
        }

        throw $exception;
    }
}
