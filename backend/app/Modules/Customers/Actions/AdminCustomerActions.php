<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Contracts\CustomerStatsProvider;
use App\Modules\Customers\Exceptions\ResourceInUse;
use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Notifications\CustomerResetPasswordNotification;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Panel operations on customers and companies (API.md §3.G.10). Every write is audited. */
final class AdminCustomerActions
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CustomerStatsProvider $stats,
        private readonly UpdateCompanyData $companyData,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function update(ActorRef $actor, Customer $customer, array $data): Customer
    {
        if (array_key_exists('cpf', $data) && $data['cpf'] !== $customer->cpf && $this->stats->hasPaidOrders($customer->id)) {
            throw ValidationException::withMessages(['cpf' => 'O CPF não pode ser corrigido: o cliente já tem pedido pago.']);
        }

        return DB::transaction(function () use ($actor, $customer, $data): Customer {
            $fields = ['name', 'phone', 'price_list_id', 'cpf'];
            $before = $customer->only($fields);
            if (array_key_exists('name', $data)) {
                $customer->name = trim((string) $data['name']);
            }
            if (array_key_exists('phone', $data)) {
                $customer->phone = $data['phone'] === null || $data['phone'] === '' ? null : (string) $data['phone'];
            }
            if (array_key_exists('price_list_id', $data)) {
                $customer->price_list_id = $data['price_list_id'] === null ? null : (int) $data['price_list_id'];
            }
            if (array_key_exists('cpf', $data)) {
                $customer->cpf = (string) $data['cpf'];
            }
            $customer->save();
            $this->recordDiff($actor, 'customer.updated', 'customer', $customer->id, $before, $customer->only($fields));

            return $customer;
        });
    }

    public function block(ActorRef $actor, Customer $customer, string $reason): Customer
    {
        if ($customer->is_active) {
            $customer->is_active = false;
            $customer->setRememberToken(Str::random(60));
            $customer->save();
            $this->audit->record(new AuditEntry($actor, 'customer.blocked', 'customer', $customer->id, ['is_active' => true], ['is_active' => false, 'reason' => $reason]));
        }

        return $customer;
    }

    public function unblock(ActorRef $actor, Customer $customer): Customer
    {
        if (! $customer->is_active && $customer->anonymized_at === null) {
            $customer->is_active = true;
            $customer->save();
            $this->audit->record(new AuditEntry($actor, 'customer.unblocked', 'customer', $customer->id, ['is_active' => false], ['is_active' => true]));
        }

        return $customer;
    }

    public function sendPasswordReset(ActorRef $actor, Customer $customer): void
    {
        /** @var \Illuminate\Auth\Passwords\PasswordBroker $broker */
        $broker = Password::broker('customers');
        $customer->notify(new CustomerResetPasswordNotification($broker->getRepository()->create($customer)));
        $this->audit->record(new AuditEntry($actor, 'customer.password_reset_sent', 'customer', $customer->id));
    }

    /** @return array{cpf: ?string, cnpj: ?string} */
    public function revealDocument(ActorRef $actor, Customer $customer): array
    {
        $this->audit->record(new AuditEntry($actor, 'customer.document_revealed', 'customer', $customer->id));

        return ['cpf' => $customer->cpf, 'cnpj' => $customer->company?->cnpj];
    }

    /** LGPD (DATABASE DB-15 / RN-LGPD-005): anonymize + soft delete; orders keep their snapshots. */
    public function anonymize(ActorRef $actor, Customer $customer): Customer
    {
        if ($this->stats->hasOrdersInProgress($customer->id)) {
            throw new ResourceInUse('O cliente tem pedidos em andamento.', details: [
                'blockers' => [['type' => 'order', 'id' => 0, 'label' => 'Pedidos em andamento']],
            ]);
        }

        return DB::transaction(function () use ($actor, $customer): Customer {
            foreach ($customer->addresses()->get() as $address) {
                $address->forceFill([
                    'label' => null, 'recipient_name' => 'Removido', 'phone' => null, 'street' => 'Removido',
                    'number' => 'S/N', 'complement' => null, 'reference' => null, 'is_default' => false,
                ])->save();
                $address->delete();
            }

            $customer->name = 'Cliente removido';
            $customer->email = 'removed+'.$customer->uuid.'@invalid.local';
            $customer->cpf = null;
            $customer->phone = null;
            $customer->password = Str::password(64);
            $customer->setRememberToken(Str::random(60));
            $customer->marketing_opt_in = false;
            $customer->marketing_opt_in_at = now();
            $customer->is_active = false;
            $customer->anonymized_at = now();
            $customer->save();
            $customer->delete();

            $this->audit->record(new AuditEntry($actor, 'customer.anonymized', 'customer', $customer->id));

            return $customer;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateCompany(ActorRef $actor, Company $company, array $data): Company
    {
        if (array_key_exists('cnpj', $data) && $data['cnpj'] !== $company->cnpj) {
            foreach ($company->customers()->pluck('id') as $customerId) {
                if ($this->stats->hasPaidOrders((int) $customerId)) {
                    throw ValidationException::withMessages(['cnpj' => 'O CNPJ não pode ser corrigido: há pedido pago.']);
                }
            }
        }

        return DB::transaction(function () use ($actor, $company, $data): Company {
            $before = $company->only(['cnpj', 'price_list_id']);
            [$old, $new] = $this->companyData->handle($company, $data);
            if (array_key_exists('cnpj', $data)) {
                $company->cnpj = (string) $data['cnpj'];
            }
            if (array_key_exists('price_list_id', $data)) {
                $company->price_list_id = $data['price_list_id'] === null ? null : (int) $data['price_list_id'];
            }
            $company->save();
            $this->recordDiff($actor, 'company.updated', 'company', $company->id, [...$old, ...$before], [...$new, ...$company->only(['cnpj', 'price_list_id'])]);

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordDiff(ActorRef $actor, string $action, string $type, int $id, array $before, array $after): void
    {
        $entry = AuditEntry::diff($actor, $action, $type, $id, $before, $after);
        if ($entry->newValues !== null) {
            $this->audit->record($entry);
        }
    }
}
