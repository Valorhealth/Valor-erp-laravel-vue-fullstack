<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureRoleAbility;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureRoleAbilityTest extends TestCase
{
    #[Test]
    public function protected_api_returns_json_unauthorized_response(): void
    {
        $response = $this->get('/api/v1/dashboard');

        $response->assertUnauthorized();
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function it_rejects_sale_from_an_approve_only_ability(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้');

        $this->runMiddleware('Sale (กรอกใบขอเปิดบิล)', 'dashboard_stats');
    }

    #[Test]
    public function it_allows_approve_to_use_an_approve_ability(): void
    {
        $response = $this->runMiddleware('Approve (หัวหน้าอนุมัติ)', 'dashboard_stats');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function runMiddleware(string $role, string $ability): Response
    {
        $request = Request::create('/_test/role-protected');
        $request->setUserResolver(fn () => new User([
            'username' => 'role-test',
            'role' => $role,
        ]));

        return (new EnsureRoleAbility)->handle(
            $request,
            fn () => new Response(status: Response::HTTP_OK),
            $ability,
        );
    }
}
