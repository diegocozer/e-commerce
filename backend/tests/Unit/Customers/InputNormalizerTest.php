<?php

declare(strict_types=1);

namespace Tests\Unit\Customers;

use App\Modules\Customers\Support\EmailVerificationSignature;
use App\Modules\Customers\Support\InputNormalizer;
use Tests\TestCase;

final class InputNormalizerTest extends TestCase
{
    public function test_normalizes_masked_input(): void
    {
        self::assertSame('47999990001', InputNormalizer::digits('(47) 99999-0001'));
        self::assertSame('ana@example.com', InputNormalizer::email('  Ana@Example.COM '));
        self::assertSame('52998224725', InputNormalizer::cpf('529.982.247-25'));
        self::assertSame('12ABC34501DE35', InputNormalizer::cnpj('12.abc.345/01de-35'));
        self::assertSame(123, InputNormalizer::digits(123), 'non-strings are left for the validator');
    }

    public function test_state_registration_isento(): void
    {
        self::assertSame(['state_registration' => null, 'state_registration_exempt' => true], InputNormalizer::stateRegistration(['state_registration' => ' Isento ']));
        self::assertSame(['state_registration' => '123456789'], InputNormalizer::stateRegistration(['state_registration' => '123.456.789']));
        self::assertSame(['legal_name' => 'X'], InputNormalizer::stateRegistration(['legal_name' => 'X']));
    }

    public function test_email_verification_signature_depends_on_every_part(): void
    {
        $sig = EmailVerificationSignature::sign('u', 'h', 100);
        self::assertSame($sig, EmailVerificationSignature::sign('u', 'h', 100));
        self::assertNotSame($sig, EmailVerificationSignature::sign('u', 'h', 101));
        self::assertNotSame($sig, EmailVerificationSignature::sign('v', 'h', 100));
    }
}
