<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FibroscanBooking;
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

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'machine_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['จองแล้ว', 'กำลังใช้งาน', 'คืนแล้ว', 'ยกเลิก'])],
        ]);

        $bookings = FibroscanBooking::query()
            ->with(['machine:machine_id,machine_code,machine_name,status', 'owner:id,username,first_name,last_name,department'])
            ->when($data['date_from'] ?? null, fn ($query, $date) => $query->where('reserved_until', '>=', Carbon::parse($date, 'Asia/Bangkok')->startOfDay()->utc()))
            ->when($data['date_to'] ?? null, fn ($query, $date) => $query->where('reserved_from', '<', Carbon::parse($date, 'Asia/Bangkok')->addDay()->startOfDay()->utc()))
            ->when($data['machine_id'] ?? null, fn ($query, $machineId) => $query->where('machine_id', $machineId))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('reserved_from')
            ->orderBy('machine_id')
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
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($data['except_booking_id'] ?? null, fn ($query, $id) => $query->where('booking_id', '<>', $id))
            ->where('reserved_from', '<', Carbon::parse($data['reserved_until']))
            ->where('reserved_until', '>', Carbon::parse($data['reserved_from']))
            ->get();

        return response()->json(['data' => $conflicts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedBooking($request);
        $booking = $this->saveBooking($request, $data);

        return response()->json(['data' => $this->present($request, $booking)], 201);
    }

    public function update(Request $request, FibroscanBooking $booking): JsonResponse
    {
        $this->authorizeChange($request, $booking);
        abort_if(in_array($booking->status, ['คืนแล้ว', 'ยกเลิก'], true), 422, 'รายการที่คืนเครื่องหรือยกเลิกแล้วไม่สามารถแก้ไขได้');

        $data = $this->validatedBooking($request);
        $updated = $this->saveBooking($request, $data, $booking);

        return response()->json(['data' => $this->present($request, $updated)]);
    }

    public function updateStatus(Request $request, FibroscanBooking $booking): JsonResponse
    {
        $this->authorizeChange($request, $booking);
        $data = $request->validate([
            'status' => ['required', Rule::in(['จองแล้ว', 'กำลังใช้งาน', 'คืนแล้ว', 'ยกเลิก'])],
        ]);

        if (in_array($booking->status, ['คืนแล้ว', 'ยกเลิก'], true) && $data['status'] !== $booking->status) {
            abort(422, 'สถานะสิ้นสุดแล้ว ไม่สามารถนำรายการกลับมาใช้งานได้ กรุณาสร้างรายการใหม่');
        }

        try {
            DB::transaction(function () use ($booking, $data): void {
                FibroscanMachine::query()->whereKey($booking->machine_id)->lockForUpdate()->firstOrFail();
                if (in_array($data['status'], self::ACTIVE_STATUSES, true)) {
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

        return response()->json(['data' => $this->present($request, $booking->fresh(['machine', 'owner']))]);
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
                abort_unless($machine->status === 'พร้อมใช้งาน', 422, 'เครื่องนี้ไม่พร้อมใช้งาน กรุณาเลือกเครื่องอื่น');
                $this->ensureAvailable((int) $machine->machine_id, $from, $until, $booking?->booking_id);

                $values = [
                    ...$data,
                    'reserved_from' => $from,
                    'reserved_until' => $until,
                    'updated_at' => now(),
                ];
                if ($booking === null) {
                    $values = [
                        ...$values,
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

                return $booking->fresh(['machine', 'owner']);
            });
        } catch (QueryException $exception) {
            $this->throwFriendlyConflict($exception);
        }
    }

    private function ensureAvailable(int $machineId, Carbon $from, Carbon $until, ?int $exceptBookingId = null): void
    {
        $conflict = FibroscanBooking::query()
            ->where('machine_id', $machineId)
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
        $ownsBooking = (int) $booking->booked_by_user_id === (int) $request->user()->id;
        $managesAll = RoleAccess::canUser($request->user(), 'approve_bills', 'system_admin');
        abort_unless($ownsBooking || $managesAll, 403, 'แก้ไขได้เฉพาะรายการที่คุณเป็นผู้จอง');
    }

    /** @return array<string, mixed> */
    private function present(Request $request, FibroscanBooking $booking): array
    {
        $ownsBooking = (int) $booking->booked_by_user_id === (int) $request->user()->id;

        return [
            ...$booking->toArray(),
            'can_edit' => $ownsBooking || RoleAccess::canUser($request->user(), 'approve_bills', 'system_admin'),
        ];
    }

    private function throwFriendlyConflict(QueryException $exception): never
    {
        if ($exception->getCode() === '23P01') {
            throw ValidationException::withMessages(['reserved_from' => 'เครื่องนี้เพิ่งถูกจองโดยผู้ใช้อื่นในช่วงเวลาเดียวกัน กรุณาโหลดข้อมูลและเลือกใหม่']);
        }

        throw $exception;
    }
}
