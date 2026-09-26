<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\Contracts\PriceTierTable;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Modules\Pricing\DTOs\PricingSubject;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Enums\PromotionDiscountType;
use App\Modules\Pricing\Enums\PromotionScope;
use App\Modules\Pricing\Models\CustomerPrice;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceTier;
use App\Modules\Pricing\Models\Promotion;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** BUSINESS_RULES §4.5.1 — scenarios E1–E7 (Vinil 1,22 m). */
final class PriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private Category $vinis;

    private Customer $wholesaleCustomer;

    private Customer $marcos;

    private PriceList $wholesale;

    protected function setUp(): void
    {
        parent::setUp();

        PriceList::factory()->create(['code' => 'retail', 'name' => 'Varejo', 'kind' => 'retail', 'is_default' => true, 'discount_bp' => null]);
        $this->wholesale = PriceList::factory()->create(['code' => 'wholesale', 'name' => 'Atacado', 'kind' => 'wholesale', 'is_default' => false, 'discount_bp' => 1000]);

        $this->vinis = Category::factory()->create(['slug' => 'vinis']);
        $this->variant = ProductVariant::factory()->create(['price_cents' => 1590]);
        foreach ([['11', 1490], ['51', 1350]] as [$min, $price]) {
            PriceTier::query()->create(['variant_id' => $this->variant->id, 'price_list_id' => null, 'min_quantity' => $min, 'price_cents' => $price]);
        }
        foreach ([['1', 1450], ['51', 1390]] as [$min, $price]) {
            PriceTier::query()->create(['variant_id' => $this->variant->id, 'price_list_id' => $this->wholesale->id, 'min_quantity' => $min, 'price_cents' => $price]);
        }

        $promotion = Promotion::factory()->create([
            'name' => 'Semana do Vinil', 'discount_type' => PromotionDiscountType::Percent, 'value' => 1000,
            'scope' => PromotionScope::Targeted, 'starts_at' => '2026-10-01 03:00:00', 'ends_at' => '2026-10-08 03:00:00',
        ]);
        $promotion->syncTargets('category', [$this->vinis->id]);

        $this->wholesaleCustomer = Customer::factory()->create();
        $this->wholesaleCustomer->forceFill(['price_list_id' => $this->wholesale->id])->save();
        $this->marcos = Customer::factory()->create();
        $this->marcos->forceFill(['price_list_id' => $this->wholesale->id])->save();
        CustomerPrice::query()->create(['customer_id' => $this->marcos->id, 'variant_id' => $this->variant->id, 'price_cents' => 1390]);
    }

    private function subject(?Money $promo = null, ?string $promoStart = null, ?string $promoEnd = null, ?int $categoryId = null): PricingSubject
    {
        return new PricingSubject($this->variant->id, $this->variant->product_id, null, [$categoryId ?? $this->vinis->id],
            Money::ofCents(1590), $promo, $promoStart ? CarbonImmutable::parse($promoStart) : null, $promoEnd ? CarbonImmutable::parse($promoEnd) : null);
    }

    private function ctx(string $qty, ?Customer $customer, string $date, ?PricingSubject $subject = null, ?string $tierQty = null): PriceContext
    {
        return new PriceContext($subject ?? $this->subject(), Quantity::fromString($qty), $tierQty ? Quantity::fromString($tierQty) : null,
            $customer?->id, CarbonImmutable::parse($date.' 15:00:00'));
    }

    /** @return iterable<string, array{string, ?string, string, int, string, int}> */
    public static function scenarios(): iterable
    {
        yield 'E1 visitante 5 m' => ['5', null, '2026-09-20', 1590, 'base', 7950];
        yield 'E2 visitante 20 m' => ['20', null, '2026-09-20', 1490, 'tier', 29800];
        yield 'E3 atacado 5 m' => ['5', 'wholesale', '2026-09-20', 1450, 'price_list', 7250];
        yield 'E4 atacado 60 m' => ['60', 'wholesale', '2026-09-20', 1350, 'tier', 81000];
        yield 'E5 atacado 5 m promo' => ['5', 'wholesale', '2026-10-03', 1431, 'promotion', 7155];
        yield 'E6 Marcos 5 m' => ['5', 'marcos', '2026-10-03', 1390, 'customer_price', 6950];
        yield 'E7 Marcos 60 m' => ['60', 'marcos', '2026-10-03', 1215, 'promotion', 72900];
    }

    #[DataProvider('scenarios')]
    public function test_business_scenarios(string $qty, ?string $who, string $date, int $unit, string $source, int $total): void
    {
        $customer = match ($who) {
            'wholesale' => $this->wholesaleCustomer, 'marcos' => $this->marcos, default => null
        };
        $quote = app(PriceResolver::class)->resolve($this->ctx($qty, $customer, $date));

        self::assertSame($unit, $quote->unitPrice->cents());
        self::assertSame($source, $quote->source->value);
        self::assertSame($total, $quote->lineTotal->cents());
        self::assertSame(1590, $quote->baseUnitPrice->cents());
    }

    public function test_promotion_quote_carries_name_and_id(): void
    {
        $quote = app(PriceResolver::class)->resolve($this->ctx('5', null, '2026-10-03'));
        self::assertSame(PriceSource::Promotion, $quote->source);
        self::assertSame('Semana do Vinil', $quote->sourceLabel);
        self::assertNotNull($quote->promotionId);
        self::assertSame(1590, $quote->compareAt()?->cents());
    }

    public function test_tier_uses_cart_sum_of_variant(): void
    {
        $quote = app(PriceResolver::class)->resolve($this->ctx('5', null, '2026-09-20', tierQty: '12'));
        self::assertSame(1490, $quote->unitPrice->cents());
        self::assertSame(7450, $quote->lineTotal->cents());
    }

    public function test_price_list_discount_bp_fallback_without_list_tier(): void
    {
        PriceTier::query()->where('price_list_id', $this->wholesale->id)->delete();
        $quote = app(PriceResolver::class)->resolve($this->ctx('5', $this->wholesaleCustomer, '2026-09-20'));
        self::assertSame(1431, $quote->unitPrice->cents()); // round_half_up(1590 × 9000 / 10000)
        self::assertSame('price_list', $quote->source->value);
        self::assertSame('Preço Atacado', $quote->sourceLabel);
    }

    public function test_company_price_list_is_inherited_and_default_list_adds_nothing(): void
    {
        $pj = Customer::factory()->company()->create();
        DB::table('companies')->where('id', $pj->company_id)->update(['price_list_id' => $this->wholesale->id]);
        self::assertSame(1450, app(PriceResolver::class)->resolve($this->ctx('5', $pj, '2026-09-20'))->unitPrice->cents());

        $retail = Customer::factory()->create();
        $retail->forceFill(['price_list_id' => PriceList::query()->where('code', 'retail')->value('id')])->save();
        self::assertSame('base', app(PriceResolver::class)->resolve($this->ctx('5', $retail, '2026-09-20'))->source->value);
    }

    public function test_company_customer_price_applies_to_company_members(): void
    {
        $pj = Customer::factory()->company()->create();
        CustomerPrice::query()->create(['company_id' => $pj->company_id, 'variant_id' => $this->variant->id, 'price_cents' => 1200]);
        $quote = app(PriceResolver::class)->resolve($this->ctx('5', $pj, '2026-09-20'));
        self::assertSame(1200, $quote->unitPrice->cents());
        self::assertSame('Seu preço', $quote->sourceLabel);
    }

    public function test_customer_price_validity_window(): void
    {
        CustomerPrice::query()->where('customer_id', $this->marcos->id)->update(['starts_at' => '2026-11-01 00:00:00']);
        self::assertSame(1450, app(PriceResolver::class)->resolve($this->ctx('5', $this->marcos, '2026-09-20'))->unitPrice->cents());
    }

    public function test_variant_promo_price_with_validity(): void
    {
        $subject = $this->subject(Money::ofCents(990), '2026-09-01 00:00:00', '2026-09-30 00:00:00', 999999);
        $in = app(PriceResolver::class)->resolve($this->ctx('1', null, '2026-09-20', $subject));
        self::assertSame(990, $in->unitPrice->cents());
        self::assertSame('variant_promo', $in->source->value);

        $out = app(PriceResolver::class)->resolve($this->ctx('1', null, '2026-10-20', $subject));
        self::assertSame('base', $out->source->value);
    }

    public function test_fixed_promotion_by_brand_never_below_one_cent_and_store_wide(): void
    {
        Promotion::query()->delete();
        $brandPromo = Promotion::factory()->create(['discount_type' => PromotionDiscountType::Fixed, 'value' => 5000, 'starts_at' => '2026-01-01', 'ends_at' => null]);
        $brandPromo->syncTargets('brand', [$this->variant->product->brand_id]);
        $subject = new PricingSubject($this->variant->id, $this->variant->product_id, $this->variant->product->brand_id, [], Money::ofCents(1590), null, null, null);
        $quote = app(PriceResolver::class)->resolve(new PriceContext($subject, Quantity::fromString('1'), null, null, CarbonImmutable::parse('2026-09-20')));
        self::assertSame(1, $quote->unitPrice->cents());

        Promotion::factory()->storeWide()->create(['value' => 2000, 'starts_at' => '2026-01-01', 'ends_at' => null, 'is_active' => false]);
        self::assertSame(1, app(PriceResolver::class)->resolve(new PriceContext($subject, Quantity::fromString('1'), null, null, CarbonImmutable::parse('2026-09-20')))->unitPrice->cents());
    }

    public function test_resolve_many_keeps_order_and_uses_constant_queries(): void
    {
        $contexts = [];
        foreach (range(1, 10) as $i) {
            $contexts[] = $this->ctx((string) ($i * 6), $i % 2 ? $this->marcos : null, '2026-10-03');
        }
        DB::enableQueryLog();
        $quotes = app(PriceResolver::class)->resolveMany($contexts);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertCount(10, $quotes);
        self::assertSame(6000, $quotes[0]->billableQuantity->milli());
        self::assertSame(60000, $quotes[9]->billableQuantity->milli());
        self::assertLessThanOrEqual(10, $queries);
    }

    public function test_tier_table_for_display(): void
    {
        $tiers = app(PriceTierTable::class)->tiersFor($this->ctx('1', null, '2026-09-20'), Quantity::fromString('1'));
        self::assertSame([[1000, 1590, 'base'], [11000, 1490, 'tier'], [51000, 1350, 'tier']],
            array_map(fn ($t) => [$t->minQuantity->milli(), $t->unitPrice->cents(), $t->source->value], $tiers));
    }
}
