<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * Partial update of legal name, trade name and IE/exempt, keeping the
 * invariant "IE xor exempt" (RN-CLI-006). Used by /me/company and the panel.
 */
final class UpdateCompanyData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [old, new] changed values
     */
    public function handle(Company $company, array $data): array
    {
        $before = $company->only(['legal_name', 'trade_name', 'state_registration', 'state_registration_exempt']);

        if (array_key_exists('legal_name', $data)) {
            $company->legal_name = trim((string) $data['legal_name']);
        }
        if (array_key_exists('trade_name', $data)) {
            $trade = $data['trade_name'] === null ? '' : trim((string) $data['trade_name']);
            $company->trade_name = $trade === '' ? null : $trade;
        }
        if (array_key_exists('state_registration_exempt', $data)) {
            $company->state_registration_exempt = (bool) $data['state_registration_exempt'];
            if ($company->state_registration_exempt) {
                $company->state_registration = null;
            }
        }
        if (array_key_exists('state_registration', $data) && $data['state_registration'] !== null) {
            $company->state_registration = (string) $data['state_registration'];
            $company->state_registration_exempt = false;
        }

        if (! $company->state_registration_exempt && $company->state_registration === null) {
            throw ValidationException::withMessages([
                'state_registration' => 'Informe a inscrição estadual ou marque como isento.',
            ]);
        }

        $company->save();

        return [$before, $company->only(array_keys($before))];
    }
}
