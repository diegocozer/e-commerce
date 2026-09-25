<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Modules\Customers\DTOs\AddressData;
use App\Modules\Customers\DTOs\CustomerData;
use App\Modules\Customers\DTOs\CustomerPricingProfile;

/** Read access to customers for other modules (IMPLEMENTATION_PLAN §5.4). */
interface CustomerDirectory
{
    /** Name, e-mail, type, document, phone and company. */
    public function find(int $customerId): ?CustomerData;

    /** {customerId, ?companyId, ?priceListId effective (customer → company → default)}. */
    public function pricingProfile(int $customerId): CustomerPricingProfile;

    /** Scoped to the customer (IDOR): another customer's address → null. */
    public function addressForCustomer(int $customerId, string $addressUuid): ?AddressData;

    /** PF: CPF; PJ: CNPJ, legal name and IE (or exempt). */
    public function isProfileCompleteForCheckout(int $customerId): bool;
}
