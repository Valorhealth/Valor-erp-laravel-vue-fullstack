<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FibroscanBookingController;
use App\Models\FibroscanBooking;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class FibroscanBookingWorkflowTest extends TestCase
{
    #[Test]
    public function a_sale_can_edit_only_their_own_pending_request(): void
    {
        $sale = (new User)->forceFill(['id' => 9, 'username' => 'sale9', 'role' => 'Sale Executive']);

        $pending = $this->present($sale, $this->booking(9, 'รออนุมัติ'));
        $approved = $this->present($sale, $this->booking(9, 'อนุมัติแล้ว'));
        $otherSale = $this->present($sale, $this->booking(10, 'รออนุมัติ'));

        $this->assertTrue($pending['can_edit']);
        $this->assertFalse($approved['can_edit']);
        $this->assertFalse($otherSale['can_edit']);
        $this->assertFalse($pending['can_review']);
    }

    #[Test]
    public function approvers_and_admins_can_review_and_edit_every_request(): void
    {
        foreach (['Approve Manager', 'Admin'] as $role) {
            $manager = (new User)->forceFill(['id' => 1, 'username' => 'manager', 'role' => $role]);
            $presented = $this->present($manager, $this->booking(9, 'รออนุมัติ'));

            $this->assertTrue($presented['can_edit']);
            $this->assertTrue($presented['can_manage_all']);
            $this->assertTrue($presented['can_review']);
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user, FibroscanBooking $booking): array
    {
        $request = Request::create('/api/v1/fibroscan-bookings', 'GET');
        $request->setUserResolver(fn (): User => $user);
        $method = new ReflectionMethod(FibroscanBookingController::class, 'present');

        return $method->invoke(new FibroscanBookingController, $request, $booking);
    }

    private function booking(int $ownerId, string $approvalStatus): FibroscanBooking
    {
        return (new FibroscanBooking)->forceFill([
            'booking_id' => 1,
            'booked_by_user_id' => $ownerId,
            'approval_status' => $approvalStatus,
            'status' => 'จองแล้ว',
        ]);
    }
}
