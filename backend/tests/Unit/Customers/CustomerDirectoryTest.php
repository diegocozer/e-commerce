<?php

declare(strict_types=1);

namespace Tests\Unit\Customers;

use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function directory(): CustomerDirectory
    {
        return app(CustomerDirectory::class);
    }

    public function test_pricing_profile_customer_then_company_then_default(): void
    {
        $default = PriceList::factory()->create(['is_default' => true, 'code' => 'varejo_t']);
        $companyList = PriceList::factory()->create();
        $customerList = PriceList::factory()->create();

        $pf = Customer::factory()->create();
        self::assertSame($default->id, $this->directory()->pricingProfile($pf->id)->priceListId);
        self::assertNull($this->directory()->pricingProfile($pf->id)->companyId);

        $pj = Customer::factory()->company()->create();
        $pj->company->forceFill(['price_list_id' => $companyList->id])->save();
        $profile = $this->directory()->pricingProfile($pj->id);
        self::assertSame($companyList->id, $profile->priceListId);
        self::assertSame($pj->company_id, $profile->companyId);

        $pj->forceFill(['price_list_id' => $customerList->id])->save();
        self::assertSame($customerList->id, $this->directory()->pricingProfile($pj->id)->priceListId);
    }

    public function test_pricing_profile_without_any_list(): void
    {
        $pf = Customer::factory()->create();
        self::assertNull($this->directory()->pricingProfile($pf->id)->priceListId);
    }

    public function test_find_returns_document_by_type(): void
    {
        $pj = Customer::factory()->company()->create();
        $data = $this->directory()->find($pj->id);
        self::assertSame(CustomerType::Company, $data->type);
        self::assertSame($pj->company->cnpj, $data->document());
        self::assertNull($this->directory()->find(999999));
    }

    public function test_address_lookup_is_scoped_to_the_customer(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $address = CustomerAddress::factory()->create(['customer_id' => $a->id]);

        self::assertSame($address->id, $this->directory()->addressForCustomer($a->id, $address->uuid)?->id);
        self::assertNull($this->directory()->addressForCustomer($b->id, $address->uuid));
        self::assertNull($this->directory()->addressForCustomer($a->id, 'not-a-uuid'));
    }

    public function test_profile_completeness(): void
    {
        $complete = Customer::factory()->create();
        $noPhone = Customer::factory()->create(['phone' => null]);
        $pjNoIe = Customer::factory()->company()->create();
        $pjNoIe->company->forceFill(['state_registration' => null, 'state_registration_exempt' => true])->save();

        self::assertTrue($this->directory()->isProfileCompleteForCheckout($complete->id));
        self::assertFalse($this->directory()->isProfileCompleteForCheckout($noPhone->id));
        self::assertTrue($this->directory()->isProfileCompleteForCheckout($pjNoIe->id));
    }
}
