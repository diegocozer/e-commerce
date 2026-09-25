<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Customer;

use App\Modules\Customers\Actions\ChangeCustomerPassword;
use App\Modules\Customers\Http\Requests\Customer\ChangePasswordRequest;
use Illuminate\Http\Response;

final class PasswordController
{
    public function update(ChangePasswordRequest $request, ChangeCustomerPassword $change): Response
    {
        $change->handle(ProfileController::customer($request), (string) $request->validated('password'));
        $request->session()->regenerate();

        return response()->noContent();
    }
}
