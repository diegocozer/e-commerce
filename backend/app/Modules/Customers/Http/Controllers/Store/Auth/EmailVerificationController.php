<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Store\Auth;

use App\Modules\Customers\Http\Requests\Store\VerifyEmailRequest;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Notifications\CustomerVerifyEmailNotification;
use App\Modules\Customers\Support\EmailVerificationSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EmailVerificationController
{
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $data = $request->validated();
        $valid = EmailVerificationSignature::isValid((string) $data['uuid'], (string) $data['hash'], (int) $data['expires'], (string) $data['signature']);
        $customer = $valid ? Customer::query()->where('uuid', $data['uuid'])->first() : null;

        if ($customer === null || ! hash_equals(sha1($customer->email), (string) $data['hash'])) {
            throw ValidationException::withMessages(['signature' => 'Este link expirou ou é inválido.']);
        }

        if ($customer->email_verified_at === null) {
            $customer->email_verified_at = now();
            $customer->save();
        }

        return new JsonResponse(['data' => ['verified' => true]]);
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        if ($customer->email_verified_at === null) {
            $customer->notify(new CustomerVerifyEmailNotification);
        }

        return new JsonResponse(['data' => ['message' => 'Enviamos um novo link de confirmação.']]);
    }
}
