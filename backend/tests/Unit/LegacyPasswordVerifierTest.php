<?php

namespace Tests\Unit;

use App\Services\Auth\LegacyPasswordVerifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LegacyPasswordVerifierTest extends TestCase
{
    #[Test]
    public function it_verifies_the_existing_streamlit_sha256_format(): void
    {
        $verifier = new LegacyPasswordVerifier;

        $this->assertTrue($verifier->verify('correct-password', hash('sha256', 'correct-password')));
        $this->assertFalse($verifier->verify('wrong-password', hash('sha256', 'correct-password')));
        $this->assertFalse($verifier->verify('correct-password', 'not-a-sha256-hash'));
    }
}
