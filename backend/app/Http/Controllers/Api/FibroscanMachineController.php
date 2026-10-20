<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FibroscanMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FibroscanMachineController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateMachine($request);
        $machine = FibroscanMachine::query()->create([
            ...$data,
            'status' => 'พร้อมใช้งาน',
            'created_by_user_id' => $request->user()->id,
            'created_by' => $request->user()->username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $machine], 201);
    }

    public function update(Request $request, FibroscanMachine $machine): JsonResponse
    {
        $data = $this->validateMachine($request, $machine);
        if (($data['status'] ?? $machine->status) !== 'พร้อมใช้งาน') {
            $hasActiveBooking = $machine->bookings()
                ->whereIn('status', ['จองแล้ว', 'กำลังใช้งาน'])
                ->where('reserved_until', '>', now())
                ->exists();
            abort_if($hasActiveBooking, 422, 'เครื่องนี้ยังมีรายการจองที่ไม่สิ้นสุด กรุณายกเลิกรายการจองก่อนเปลี่ยนสถานะเครื่อง');
        }

        $machine->forceFill([...$data, 'updated_at' => now()])->save();

        return response()->json(['data' => $machine->fresh()]);
    }

    public function destroy(FibroscanMachine $machine): JsonResponse
    {
        abort_if(
            $machine->bookings()->exists(),
            422,
            'ไม่สามารถลบเครื่องที่มีประวัติการจองได้ กรุณาเปลี่ยนสถานะเป็น “เลิกใช้งาน” เพื่อเก็บประวัติเดิม',
        );

        $machine->delete();

        return response()->json(['message' => 'ลบเครื่องออกจากทะเบียนแล้ว']);
    }

    /** @return array<string, mixed> */
    private function validateMachine(Request $request, ?FibroscanMachine $machine = null): array
    {
        $machineId = $machine?->machine_id;

        $data = $request->validate([
            'machine_code' => ['required', 'string', 'max:50'],
            'machine_name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'status' => [$machine ? 'required' : 'sometimes', Rule::in(['พร้อมใช้งาน', 'ซ่อมบำรุง', 'เลิกใช้งาน'])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['machine_code'] = mb_strtoupper(trim($data['machine_code']));
        $data['machine_name'] = trim($data['machine_name']);
        $data['serial_number'] = trim((string) ($data['serial_number'] ?? '')) ?: null;
        $duplicateCode = FibroscanMachine::query()
            ->whereRaw('LOWER(BTRIM(machine_code)) = ?', [mb_strtolower($data['machine_code'])])
            ->when($machineId, fn ($query) => $query->where('machine_id', '<>', $machineId))
            ->exists();
        if ($duplicateCode) {
            throw ValidationException::withMessages(['machine_code' => 'รหัสเครื่องนี้มีอยู่ในทะเบียนแล้ว']);
        }
        if ($data['serial_number'] !== null) {
            $duplicateSerial = FibroscanMachine::query()
                ->whereRaw('LOWER(BTRIM(serial_number)) = ?', [mb_strtolower($data['serial_number'])])
                ->when($machineId, fn ($query) => $query->where('machine_id', '<>', $machineId))
                ->exists();
            if ($duplicateSerial) {
                throw ValidationException::withMessages(['serial_number' => 'Serial Number นี้มีอยู่ในทะเบียนแล้ว']);
            }
        }

        return $data;
    }
}
