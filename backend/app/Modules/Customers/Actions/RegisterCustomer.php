<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Events\CustomerRegistered;
use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Notifications\CustomerVerifyEmailNotification;
use Illuminate\Support\Facades\DB;

/** RN-CLI-001..006 / RN-LGPD-002: creates a PF or PJ customer with terms acceptance. */
final class RegisterCustomer
{
    /** @param  array<string, mixed>  $data  validated RegisterRequest data */
    public function handle(array $data, ?string $ip): Customer
    {
        $customer = DB::transaction(function () use ($data, $ip): Customer {
            $type = CustomerType::from((string) $data['type']);
            $company = null;

            if ($type === CustomerType::Company) {
                /** @var array<string, mixed> $c */
                $c = $data['company'];
                $exempt = (bool) $c['state_registration_exempt'];
                $company = Company::query()->create([
                    'legal_name' => trim((string) $c['legal_name']),
                    'trade_name' => isset($c['trade_name']) && $c['trade_name'] !== '' ? trim((string) $c['trade_name']) : null,
                    'cnpj' => (string) $c['cnpj'],
                    'state_registration' => $exempt ? null : ($c['state_registration'] ?? null),
                    'state_registration_exempt' => $exempt,
                ]);
            }

            $marketing = (bool) ($data['marketing_opt_in'] ?? false);
            $customer = new Customer([
                'name' => trim((string) $data['name']),
                'email' => (string) $data['email'],
                'password' => (string) $data['password'],
                'cpf' => isset($data['cpf']) && $data['cpf'] !== '' ? (string) $data['cpf'] : null,
                'phone' => (string) $data['phone'],
                'marketing_opt_in' => $marketing,
            ]);
            $customer->type = $type;
            $customer->company_id = $company?->id;
            $customer->terms_version = (string) $data['terms_version'];
            $customer->terms_accepted_at = now();
            $customer->terms_accepted_ip = $ip;
            $customer->marketing_opt_in_at = $marketing ? now() : null;
            $customer->is_active = true;
            $customer->save();

            return $customer;
        });

        $customer->notify(new CustomerVerifyEmailNotification);
        event(new CustomerRegistered($customer->id, $customer->type, now()->toImmutable()));

        return $customer->refresh();
    }
}
