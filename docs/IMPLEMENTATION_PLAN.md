# Plano de Implementação — tarefas, ondas, donos e contratos

> Documento do **Software Architect** (revisão cruzada que fecha o planejamento).
> Fontes de verdade, em ordem: `DECISIONS.md` (Rodada 2, ADR-018…ADR-028) → `API.md`
> (contrato HTTP canônico, ADR-028) → `ARCHITECTURE.md` → `DATABASE.md` → `SHIPPING.md` →
> `BUSINESS_RULES.md` → `SECURITY.md` → `UX.md`. Estratégia e matriz de testes: `TESTING.md`.
>
> Idioma: pt-BR; identificadores em inglês. Agentes **não** fazem commit (ADR-017): o
> coordenador integra e commita ao fim de cada onda.

## Sumário

1. [Visão geral das ondas](#1-visão-geral-das-ondas)
2. [Agentes, escopo de diretórios e bancos de teste](#2-agentes-escopo-de-diretórios-e-bancos-de-teste)
3. [Definition of Done](#3-definition-of-done)
4. [Propriedade dos endpoints (API.md)](#4-propriedade-dos-endpoints-apimd)
5. [Contratos de integração entre ondas (PHP)](#5-contratos-de-integração-entre-ondas-php)
6. [Onda 1 — Fundação (em andamento)](#6-onda-1--fundação-em-andamento)
7. [Onda 2 — Backend paralelo (B-A, B-B, B-C)](#7-onda-2--backend-paralelo-b-a-b-b-b-c)
8. [Onda 3 — Backend transacional (B-D, B-E)](#8-onda-3--backend-transacional-b-d-b-e)
9. [Frontend (FE-ST, FE-AD) — paralelo desde a Onda 2](#9-frontend-fe-st-fe-ad--paralelo-desde-a-onda-2)
10. [Onda 4 — QA](#10-onda-4--qa)
11. [Onda 5 — Security review](#11-onda-5--security-review)
12. [Onda 6 — Code review](#12-onda-6--code-review)
13. [Onda 7 — QA final (E2E no stack)](#13-onda-7--qa-final-e2e-no-stack)
14. [Pontos de integração e riscos](#14-pontos-de-integração-e-riscos)

---

## 1. Visão geral das ondas

```mermaid
flowchart LR
    W1[Onda 1<br/>Fundação<br/>B-W1] --> W2A[B-A Identity/Customers/<br/>Settings/Audit]
    W1 --> W2B[B-B Catalog/Pricing/<br/>Inventory/Seo]
    W1 --> W2C[B-C Shipping]
    W2A --> W3D[B-D Cart/Checkout/Orders/<br/>Payments/Notifications]
    W2B --> W3D
    W2C --> W3D
    W3D --> W3E[B-E Reports/Dashboard]
    W1 -. API.md + MSW .-> FEST[FE-ST storefront]
    W1 -. API.md + MSW .-> FEAD[FE-AD admin]
    W3D --> W4[Onda 4 QA]
    W3E --> W4
    FEST --> W4
    FEAD --> W4
    W4 --> W5[Onda 5 Security] --> W6[Onda 6 Code review] --> W7[Onda 7 QA final E2E]
```

| Onda | Agentes | Entra quando | Sai quando (gate) |
|---|---|---|---|
| 1 | B-W1 | — | migrations/seed rodam limpos em Postgres; CI verde; teste de arquitetura verde; contratos da §5 existem como interfaces/DTOs vazios |
| 2 | B-A, B-B, B-C (paralelos) | gate 1 | endpoints da §4 do agente implementados e testados; contratos da §5 com implementação real + fakes |
| 3 | B-D (depois B-E) | gate 2 (B-E pode começar quando BE-ORD-01 existir) | fluxo de aceite passa em teste de feature (sem browser) |
| FE | FE-ST, FE-AD | gate 1 (contra MSW); integração real a partir do gate 2/3 | todas as telas de UX.md do MVP contra a API real |
| 4 | QA-BE, QA-FE | gates 3 + FE | matriz de TESTING.md completa e verde; cobertura mínima |
| 5 | SEC | gate 4 | relatório de ataque + correções com teste de regressão |
| 6 | REV | gate 5 | achados de revisão corrigidos |
| 7 | QA-E2E | gate 6 | Playwright do fluxo de aceite verde no stack docker |

## 2. Agentes, escopo de diretórios e bancos de teste

| Agente | Escopo (pode alterar) | `DB_DATABASE` de teste |
|---|---|---|
| **B-W1** Fundação | `backend/` (tudo), `docker/`, `docker-compose.yml`, `.github/` | `ecommerce_test` |
| **B-A** Identity/Customers/Settings/Audit | `backend/app/Modules/{Identity,Customers,Settings,Audit}`, `backend/tests/{Unit,Feature}/{Identity,Customers,Settings,Audit}`, factories/seeders desses módulos | `ecommerce_test_a` |
| **B-B** Catalog/Pricing/Inventory/Seo | `backend/app/Modules/{Catalog,Pricing,Inventory,Seo}` + testes/factories correspondentes | `ecommerce_test_b` |
| **B-C** Shipping | `backend/app/Modules/Shipping`, `backend/app/Modules/Cart/Http/Controllers/Admin/ShippingSimulatorController.php` (+ request/rota admin do simulador) e testes | `ecommerce_test_c` |
| **B-D** Cart/Checkout/Orders/Payments/Notifications | `backend/app/Modules/{Cart,Checkout,Orders,Payments,Notifications}` (exceto o arquivo do simulador) + testes | `ecommerce_test_d` |
| **B-E** Reports | `backend/app/Modules/Reports` + testes | `ecommerce_test_e` |
| **FE-ST** Storefront | `storefront/` | — |
| **FE-AD** Admin | `admin/` | — |
| **QA-BE / QA-FE / QA-E2E** | `backend/tests/**`, `storefront/src/**/*.test.tsx`, `admin/src/**/*.test.tsx`, `e2e/` | `ecommerce_test_qa` |
| **SEC** / **REV** | qualquer diretório, somente para correções com teste | `ecommerce_test_sec` / `ecommerce_test_rev` |

Regras:

- Migrations, models, enums, factories e seeders **de todas as tabelas** são entregues na
  Onda 1. Nas ondas seguintes um agente só altera migration/model do próprio módulo, por
  **nova** migration (nunca editar uma já integrada) e registrando o motivo no PR/relatório.
- Mudança de contrato da §5 ou de API.md exige ADR nova (DECISIONS.md) aprovada pelo
  coordenador **antes** de implementar.
- Arquivos compartilhados (`bootstrap/app.php`, `bootstrap/providers.php`, `config/*.php`,
  `routes/console.php`, `AppServiceProvider`) pertencem ao B-W1; na Onda 2+ cada agente
  pede a alteração no relatório final ou usa o `ServiceProvider` do próprio módulo
  (bindings, listeners, schedule via `callAfterResolving(Schedule::class, …)`, rate limiters
  do módulo).

## 3. Definition of Done

Uma tarefa só é "feita" quando os cinco itens abaixo são verdadeiros:

| # | Critério | Como se verifica |
|---|---|---|
| 1 | **Implementado** | Código conforme API.md (paths, payloads, `code` de erro, permissões) e ARCHITECTURE (módulo, contrato, camadas: Controller fino → Form Request → Action/Service → Resource). Sem TODO bloqueante. |
| 2 | **Testado** | Testes da tarefa (IDs de TESTING.md) escritos e verdes em Postgres real no banco do agente; `php artisan test` completo verde; Vitest verde (FE). Casos de erro (401/403/404/409/422/429) cobertos, não só o caminho feliz. |
| 3 | **Revisado** | Auto-revisão com o checklist de TESTING.md §9 + `pint --test`, `tsc --noEmit`, ESLint; teste de arquitetura (grafo de módulos, `$fillable`, rotas admin com permissão) verde. |
| 4 | **Integrado** | Rodou sobre o `main` integrado mais recente sem conflito; contratos da §5 consumidos/expostos sem alteração de assinatura; CI verde (backend, storefront, admin, security). |
| 5 | **Validado** | Critério de aceite da tarefa demonstrado (teste de feature, captura de tela ou saída de comando no relatório); para tarefas com impacto no fluxo de aceite, o cenário de TESTING.md §7 continua passando. |

Relatório final de cada agente: tarefas concluídas (IDs), testes adicionados (IDs),
desvios de API.md (deve ser vazio ou com ADR), pendências e riscos.

## 4. Propriedade dos endpoints (API.md)

Cada endpoint de API.md §3 tem **exatamente um** dono. Paths relativos a `/api/v1` salvo
indicação. (Verificado contra a lista extraída de API.md §3 e §5; total: **201**
operações HTTP, contando cada método separadamente.)

### 4.1 B-W1 — Fundação (2)

`GET /api/health` · `GET /api/health/live`

### 4.2 B-A — Identity, Customers, Settings, Audit (57)

- **Sanctum/Settings/pages:** `GET /sanctum/csrf-cookie` (configuração e teste) ·
  `GET /settings/public` · `GET /pages/{slug}`
- **Auth do cliente:** `POST /auth/register` · `POST /auth/login` · `POST /auth/logout` ·
  `POST /auth/forgot-password` · `POST /auth/reset-password` · `POST /auth/email/verify` ·
  `POST /auth/email/verification-notification`
- **Área do cliente:** `GET /me` · `PATCH /me` · `PUT /me/password` · `PATCH /me/company` ·
  `POST /me/terms-acceptance`
- **Endereços:** `GET /me/addresses` · `POST /me/addresses` · `GET /me/addresses/{uuid}` ·
  `PATCH /me/addresses/{uuid}` · `DELETE /me/addresses/{uuid}` ·
  `POST /me/addresses/{uuid}/default`
- **Auth/conta do admin:** `POST /admin/auth/login` · `POST /admin/auth/logout` ·
  `POST /admin/auth/forgot-password` · `POST /admin/auth/reset-password` · `GET /admin/me` ·
  `PUT /admin/me/password`
- **Usuários/papéis:** `GET /admin/users` · `GET /admin/users/{id}` · `POST /admin/users` ·
  `PATCH /admin/users/{id}` · `DELETE /admin/users/{id}` · `POST /admin/users/{id}/activate` ·
  `POST /admin/users/{id}/deactivate` · `POST /admin/users/{id}/password-reset` ·
  `GET /admin/roles` · `GET /admin/roles/{id}` · `POST /admin/roles` · `PATCH /admin/roles/{id}` ·
  `DELETE /admin/roles/{id}` · `GET /admin/permissions`
- **Clientes/empresas (painel):** `GET /admin/customers` · `GET /admin/customers/{id}` ·
  `PATCH /admin/customers/{id}` · `POST /admin/customers/{id}/block` ·
  `POST /admin/customers/{id}/unblock` · `POST /admin/customers/{id}/password-reset` ·
  `POST /admin/customers/{id}/reveal-document` · `POST /admin/customers/{id}/anonymize` ·
  `GET /admin/companies` · `GET /admin/companies/{id}` · `PATCH /admin/companies/{id}`
- **Settings/Auditoria:** `GET /admin/settings` · `PATCH /admin/settings` ·
  `GET /admin/audit-logs` · `GET /admin/audit-logs/{id}` · `GET /admin/failed-jobs`

### 4.3 B-B — Catalog, Pricing, Inventory, Seo (72)

- **Loja:** `GET /categories` · `GET /categories/{slug}` · `GET /brands` · `GET /products` ·
  `GET /products/autocomplete` · `GET /products/{slug}` · `GET /products/{slug}/related`
- **SEO (fora de `/api/v1`):** `GET /sitemap.xml` · `GET /robots.txt` · shell
  `GET /{category}` e `GET /{category}/{product}` (via `@seo_shell`)
- **Categorias:** `GET /admin/categories` · `GET /admin/categories/{id}` · `POST /admin/categories` ·
  `PATCH /admin/categories/{id}` · `DELETE /admin/categories/{id}` · `POST /admin/categories/reorder` ·
  `POST /admin/categories/{id}/image` · `DELETE /admin/categories/{id}/image`
- **Marcas:** `GET /admin/brands` · `GET /admin/brands/{id}` · `POST /admin/brands` ·
  `PATCH /admin/brands/{id}` · `DELETE /admin/brands/{id}` · `POST /admin/brands/{id}/logo` ·
  `DELETE /admin/brands/{id}/logo`
- **Produtos/variantes/imagens:** `GET /admin/products` · `GET /admin/products/{id}` ·
  `POST /admin/products` · `PATCH /admin/products/{id}` · `DELETE /admin/products/{id}` ·
  `POST /admin/products/bulk` · `GET /admin/products/slug-availability` ·
  `DELETE /admin/products/{id}/variants/{variantId}` · `GET /admin/variants` ·
  `GET /admin/variants/sku-availability` · `POST /admin/products/{id}/images` ·
  `PATCH /admin/products/{id}/images/{imageId}` · `DELETE /admin/products/{id}/images/{imageId}` ·
  `POST /admin/products/{id}/images/reorder`
- **Preços:** `GET /admin/variants/{variantId}/price-tiers` · `PUT /admin/variants/{variantId}/price-tiers` ·
  `GET /admin/price-lists` · `GET /admin/price-lists/{id}` · `POST /admin/price-lists` ·
  `PATCH /admin/price-lists/{id}` · `DELETE /admin/price-lists/{id}` · `GET /admin/price-lists/{id}/tiers` ·
  `GET /admin/customer-prices` · `POST /admin/customer-prices` · `PATCH /admin/customer-prices/{id}` ·
  `DELETE /admin/customer-prices/{id}`
- **Promoções/cupons:** `GET /admin/promotions` · `GET /admin/promotions/{id}` · `POST /admin/promotions` ·
  `PATCH /admin/promotions/{id}` · `DELETE /admin/promotions/{id}` · `POST /admin/promotions/{id}/preview` ·
  `GET /admin/coupons` · `GET /admin/coupons/{id}` · `GET /admin/coupons/{id}/redemptions` ·
  `POST /admin/coupons` · `PATCH /admin/coupons/{id}` · `DELETE /admin/coupons/{id}` ·
  `POST /admin/coupons/generate-code`
- **Estoque:** `GET /admin/inventory` · `GET /admin/inventory/{variantId}` · `PATCH /admin/inventory/{variantId}` ·
  `GET /admin/inventory/{variantId}/movements` · `GET /admin/inventory/movements` ·
  `POST /admin/inventory/{variantId}/entries` · `POST /admin/inventory/{variantId}/adjustments`

### 4.4 B-C — Shipping (30)

- **Loja:** `GET /postal-codes/{cep}`
- **Transportadoras:** `GET /admin/shipping/carriers` · `GET /admin/shipping/carriers/{id}` ·
  `GET /admin/shipping/carriers/drivers` · `POST /admin/shipping/carriers` ·
  `PATCH /admin/shipping/carriers/{id}` · `DELETE /admin/shipping/carriers/{id}` ·
  `POST /admin/shipping/carriers/{id}/test`
- **Métodos:** `GET /admin/shipping/methods` · `GET /admin/shipping/methods/{id}` ·
  `POST /admin/shipping/methods` · `PATCH /admin/shipping/methods/{id}` ·
  `DELETE /admin/shipping/methods/{id}` · `PUT /admin/shipping/methods/reorder`
- **Zonas:** `GET /admin/shipping/zones` · `GET /admin/shipping/zones/{id}` · `POST /admin/shipping/zones` ·
  `PATCH /admin/shipping/zones/{id}` · `DELETE /admin/shipping/zones/{id}` ·
  `POST /admin/shipping/zones/{id}/test` · `GET /admin/shipping/cities`
- **Regras:** `GET /admin/shipping/rules` · `GET /admin/shipping/rules/{id}` · `POST /admin/shipping/rules` ·
  `PATCH /admin/shipping/rules/{id}` · `DELETE /admin/shipping/rules/{id}` ·
  `POST /admin/shipping/rules/{id}/duplicate` · `POST /admin/shipping/rules/reorder`
- **Simulador e cotações:** `POST /admin/shipping/simulate` (controller no módulo Cart, API.md
  §5.2 nota ²; modo `order_id` depende do contrato `OrderShippingRequestSource`, §5.6) ·
  `GET /admin/shipping/quotes/{uuid}`

### 4.5 B-D — Cart, Checkout, Orders, Payments, Notifications (38)

- **Prévia e estimativa (módulo Cart):** `POST /products/{slug}/price-preview` · `POST /shipping/quote`
- **Carrinho:** `GET /cart` · `DELETE /cart` · `POST /cart/items` · `PATCH /cart/items/{id}` ·
  `DELETE /cart/items/{id}` · `PUT /cart/coupon` · `DELETE /cart/coupon` ·
  `POST /cart/shipping-quote` · `POST /cart/acknowledge-prices`
- **Pedidos do cliente:** `GET /me/orders` · `GET /me/orders/{uuid}` · `GET /me/orders/{uuid}/status` ·
  `POST /me/orders/{uuid}/cancel` · `POST /me/orders/{uuid}/cancellation-request` ·
  `POST /me/orders/{uuid}/reorder` · `POST /me/orders/{uuid}/payment` · `GET /me/reorder-suggestions` ·
  `GET /me/data-export` · `GET /me/notifications` · `POST /me/notifications/read`
- **Checkout:** `POST /checkout/preview` · `POST /checkout`
- **Webhooks/dev:** `POST /webhooks/{provider}` · `POST /dev/payments/{order_uuid}/approve` ·
  `POST /dev/payments/{order_uuid}/fail`
- **Pedidos (painel):** `GET /admin/orders` · `GET /admin/orders/status-counts` · `GET /admin/orders/{id}` ·
  `PATCH /admin/orders/{id}` · `POST /admin/orders/{id}/transitions` · `POST /admin/orders/{id}/cancel` ·
  `POST /admin/orders/{id}/cancellation-request/dismiss` · `POST /admin/orders/{id}/reveal-document` ·
  `POST /admin/orders/{id}/payments/reconcile`
- **Notificações do painel:** `GET /admin/notifications` · `POST /admin/notifications/read`

### 4.6 B-E — Reports (2)

`GET /admin/dashboard` · `GET /admin/reports/{report}` (10 relatórios × `format=json|csv`)

### 4.7 Verificação de completude

Soma: 2 + 57 + 72 + 30 + 38 + 2 = **201**. A tarefa **QA-API-01** (Onda 4) automatiza a
verificação: teste que percorre `Route::getRoutes()` e compara com a lista desta seção
(arquivo `tests/Architecture/RouteInventoryTest.php` com a lista acima como fixture); rota
não listada ou item sem rota → falha. Em caso de endpoint novo, primeiro atualizar API.md
(ADR), depois esta seção.

## 5. Contratos de integração entre ondas (PHP)

Os contratos abaixo são criados como **interfaces + DTOs** pelo B-W1 (esqueleto) ou, no
máximo, no **primeiro dia** da Onda 2 pelo agente dono, e ficam **congelados** a partir do
gate 1+1 dia. B-D (Onda 3) programa **somente** contra eles. Todos em `Contracts/` ou `DTOs/`
do módulo dono (ARCHITECTURE §2.3). Tipos: `Money`, `Quantity`, `Dimensions`, `Weight`,
`PackageDimensions`, `PostalCode`, `ActorRef` do kernel `App\Shared\Domain`. Exceções estendem
`App\Shared\Exceptions\DomainException` e já carregam o `code` de API.md §1.6.

### 5.1 Catalog (B-B) — consumido por Cart, Checkout, Orders, Notifications, Reports, Seo

```php
namespace App\Modules\Catalog\Contracts;

interface CatalogQuery
{
    /** Variante ativa ou não (o chamador decide); null se inexistente/soft-deleted. */
    public function variant(int $variantId): ?VariantData;
    /** @param list<int> $variantIds @return array<int, VariantData> indexado por id (sem N+1) */
    public function variants(array $variantIds): array;
    public function variantBySlug(string $productSlug, int $variantId): ?VariantData; // 404 se variante não é do produto
    public function pricingSubject(int $variantId): PricingSubject;
    /** @return array<int, PricingSubject> */
    public function pricingSubjects(array $variantIds): array;
}

interface SaleQuantityResolver
{
    /** Valida min/max/step/larguras/alturas (ADR-004/019). Lança InvalidSaleQuantity
     *  (422, com campo e `details.suggestions` quando fora do passo). */
    public function resolve(VariantData $variant, SaleInput $input): BillableQuantity;
}

namespace App\Modules\Catalog\DTOs;

final readonly class VariantData {
    public function __construct(
        public int $id, public int $productId, public string $productSlug, public string $productName,
        public string $sku, public string $name, public SaleUnit $saleUnit,
        public bool $isActive, public bool $productIsActive, public bool $pickupOnly,
        public ?int $brandId, public int $primaryCategoryId, /** @var list<int> */ public array $categoryIdsWithAncestors,
        public Quantity $minQuantity, public ?Quantity $maxQuantity, public Quantity $quantityStep,
        public ?int $fixedWidthMm, public ?int $minWidthMm, public ?int $maxWidthMm,
        public ?int $minHeightMm, public ?int $maxHeightMm, public ?Quantity $minBillableArea,
        public int $weightGrams, public ?PackageDimensions $package, public ?int $unitsPerPackage,
        public ?int $unitsPerBox, public ?string $rollLengthM, public ?string $imageUrl,
    ) {}
}
final readonly class SaleInput { public function __construct(
    public ?Quantity $quantity, public ?int $widthMm, public ?int $heightMm, public ?int $pieces) {} }
final readonly class BillableQuantity { public function __construct(
    public Quantity $billable,        // faturada (área com mínimo por peça, ADR-019)
    public Quantity $stock,           // baixa de estoque (sem área mínima)
    public ?Quantity $pieceArea,      // m² por peça (SQUARE_METER)
    public bool $minimumAreaApplied) {} }
```

Contratos que Catalog **implementa** para módulos inferiores: `Inventory\Contracts\VariantLabelProvider`
(B-B, mesmo agente).

### 5.2 Pricing (B-B) — consumido por Catalog, Cart, Checkout, Orders

```php
namespace App\Modules\Pricing\Contracts;

interface PriceResolver
{
    public function resolve(PriceContext $ctx): PriceQuote;
    /** @param list<PriceContext> $contexts @return list<PriceQuote> na mesma ordem (consultas em lote) */
    public function resolveMany(array $contexts): array;
}

interface CouponService
{
    /** Sem efeitos. Usado por GET/PUT /cart/coupon e /checkout/preview. */
    public function evaluate(string $code, CouponContext $ctx): CouponEvaluation;
    /** DENTRO da transação do checkout: SELECT coupon FOR UPDATE, confere limites total/por
     *  cliente, grava coupon_redemptions. Lança CouponInvalid (409 coupon_invalid). */
    public function redeem(string $code, CouponContext $ctx, int $orderId): CouponEvaluation;
    /** Pedido não pago cancelado/expirado: devolve o uso (idempotente). */
    public function releaseForOrder(int $orderId): void;
}

namespace App\Modules\Pricing\DTOs;

final readonly class PricingSubject { public function __construct(
    public int $variantId, public int $productId, public ?int $brandId,
    /** @var list<int> */ public array $categoryIdsWithAncestors,
    public Money $basePrice, public ?Money $promoPrice,
    public ?CarbonImmutable $promoStartsAt, public ?CarbonImmutable $promoEndsAt) {} }
final readonly class PriceContext { public function __construct(
    public PricingSubject $subject,
    public Quantity $billableQuantity,          // quantidade da linha (total da linha)
    public ?Quantity $tierQuantity,             // soma da variante no carrinho (ADR-019); null = billable
    public ?int $customerId,
    public CarbonImmutable $at) {} }
final readonly class PriceQuote { public function __construct(
    public int $variantId, public Money $unitPrice, public Money $baseUnitPrice,
    public Money $lineTotal,                    // round_half_up(unit × milli / 1000)
    public Quantity $billableQuantity, public PriceSource $source,
    public ?string $sourceLabel, public ?int $promotionId, public ?int $priceListId) {} }
enum PriceSource: string { case Base='base'; case Tier='tier'; case PriceList='price_list';
    case VariantPromo='variant_promo'; case Promotion='promotion'; case CustomerPrice='customer_price'; }

final readonly class CouponContext { public function __construct(
    public ?int $customerId, public Money $subtotal,
    /** @var list<CouponLine> */ public array $lines,   // {variantId, productId, categoryIds, lineTotal}
    public ?Money $shipping) {} }
final readonly class CouponEvaluation { public function __construct(
    public bool $valid, public ?string $reasonCode,     // expired|inactive|min_subtotal|usage_limit|usage_limit_customer|login_required|not_found
    public ?string $message,                            // pt-BR pronta para 422/409
    public Money $discount, public bool $freeShipping, public ?int $couponId, public ?string $code) {} }
```

### 5.3 Inventory (B-B) — consumido por Catalog, Cart, Checkout, Orders

```php
namespace App\Modules\Inventory\Contracts;

interface InventoryService
{
    /** @param list<int> $variantIds @return array<int, Quantity> disponível = on_hand − reserved (sem lock) */
    public function availability(array $variantIds): array;
    /** Exige transação aberta; SELECT … FOR UPDATE ORDER BY variant_id. */
    public function lockForUpdate(array $variantIds): void;
    /** reserved += q por linha; soma linhas da mesma variante; lança InsufficientStock (409, items). */
    public function reserve(StockReservation $r): void;
    public function commit(StockReservation $r): void;    // pagamento aprovado: on_hand −= q, reserved −= q
    public function release(StockReservation $r): void;   // não pago cancelado/expirado: reserved −= q (idempotente por referência)
    public function restock(StockReservation $r): void;   // pago cancelado antes do envio: movimento `return`
    public function receive(int $variantId, Quantity $q, string $reason, ActorRef $actor): void;           // `in`
    public function adjust(int $variantId, Quantity $newOnHand, string $reason, ActorRef $actor, ?Quantity $expectedOnHand = null): void;
}
final readonly class StockReservation { public function __construct(
    public string $referenceType,                    // 'order'
    public int $referenceId,
    /** @var list<StockLine> */ public array $lines) {} }   // StockLine{int $variantId, Quantity $quantity}

interface VariantLabelProvider { /** @return array<int, array{sku:string,name:string,product_id:int}> */ public function labels(array $variantIds): array; }
interface MovementReferenceResolver { /** @return array<string, array{label:string, id:int}> chave "order:123" */ public function resolve(array $refs): array; }
```

Implementações: `VariantLabelProvider` por Catalog (B-B); `MovementReferenceResolver` por Orders
(B-D, **BE-ORD-10**). Até lá, B-B registra `NullMovementReferenceResolver` (rótulo = `"Pedido #id"`).

### 5.4 Customers (B-A) — consumido por Pricing, Cart, Checkout, Orders, Notifications

```php
namespace App\Modules\Customers\Contracts;

interface CustomerDirectory
{
    public function find(int $customerId): ?CustomerData;                        // nome, e-mail, tipo, documento, telefone, empresa
    public function pricingProfile(int $customerId): CustomerPricingProfile;    // {customerId, ?companyId, ?priceListId efetiva (cliente → empresa → default)}
    public function addressForCustomer(int $customerId, string $addressUuid): ?AddressData; // escopado (IDOR)
    public function isProfileCompleteForCheckout(int $customerId): bool;         // PF: CPF; PJ: CNPJ/razão/IE
}
/** Implementado por Orders (B-D, BE-ORD-11); B-A registra NullCustomerStatsProvider. */
interface CustomerStatsProvider
{
    /** @param list<int> $customerIds @return array<int, array{orders_count:int, paid_total_cents:int, last_order_at:?string}> */
    public function statsFor(array $customerIds): array;
    public function hasOrdersInProgress(int $customerId): bool;   // bloqueia anonimização (409 resource_in_use)
    public function hasPaidOrders(int $customerId): bool;         // bloqueia correção de CPF/CNPJ
}
/** Implementado por Cart (B-D, BE-CART-06); B-A registra NullGuestCartMerger (cart_merge = null). */
interface GuestCartMerger
{
    /** Síncrono, dentro do login/registro. Lança nada: token inválido → relatório com status "not_found". */
    public function merge(?string $guestCartToken, int $customerId): ?CartMergeReport;
}
```

Eventos emitidos por B-A e consumidos na Onda 3: `CustomerRegistered`, `CustomerAuthenticated`,
`CustomerPasswordReset` (payload ARCHITECTURE §5.2).

### 5.5 Settings (B-A) — consumido por todos

```php
interface SettingsRepository {
    public function get(SettingKey $key): mixed;   // default do enum; cache `settings:all`
    public function set(SettingKey $key, mixed $value, ActorRef $actor): void;
    public function all(): array;
    public function public(): array;               // GET /settings/public
}
```

Chaves: API.md §3.G.13 (ex.: `checkout.pix_expiry_minutes`, `shipping.quote_ttl_minutes`,
`inventory.default_low_stock_threshold`).

### 5.6 Shipping (B-C) — consumido por Cart, Checkout, Orders (e Customers via kernel)

```php
namespace App\Modules\Shipping\Contracts;

interface ShippingEngine                       // ADR-011
{
    /** @return list<ShippingOption> ordenadas (preço ↑, prazo máx ↑, prazo mín ↑, posição) */
    public function quote(ShippingRequest $request): array;
    /** @param list<int>|null $onlyMethodIds */
    public function evaluate(ShippingRequest $request, bool $withTrace = false, ?array $onlyMethodIds = null): ShippingQuoteResult;
}

interface ShippingRequestFactory
{
    /** Normaliza CEP (InvalidPostalCode → 422), resolve destino (lookup + fallback), calcula
     *  CartLogistics (SHIPPING §2.3/2.4). Não conhece Cart: recebe linhas já resolvidas. */
    public function fromLines(
        /** @param list<CartLineLogisticsInput> */ array $lines,
        string $rawPostalCode,
        Money $subtotalAfterDiscounts,
        bool $couponFreeShipping,
        ?ShippingCustomer $customer = null,
        ?int $cartId = null,
    ): ShippingRequest;
}

interface ShippingQuoteService
{
    /** Loja: avalia e persiste em shipping_quotes (TTL setting), hash = QuoteHasher (SHIPPING §4.9). */
    public function quoteAndStore(ShippingRequest $request, array $cartItemConfigs): ShippingQuoteData;
    /** Estimativa de produto: avalia sem persistir (quote_id null). */
    public function estimate(ShippingRequest $request): ShippingQuoteData;
    /** Checkout (SHIPPING §7). Retorna a opção com preço RECALCULADO; em divergência lança
     *  ShippingConflict (409, code ∈ shipping_quote_invalid|shipping_quote_expired|
     *  shipping_quote_changed|shipping_postal_code_changed|shipping_option_invalid|
     *  shipping_price_changed|shipping_option_unavailable, com ->newQuote: ShippingQuoteData). */
    public function validateForCheckout(string $quoteUuid, string $optionId, ShippingRequest $current,
        array $cartItemConfigs, int $cartId, int $customerId): ShippingOption;
    public function find(string $quoteUuid): ?ShippingQuoteData;
}

final readonly class CartLineLogisticsInput { public function __construct(
    public int $variantId, public SaleUnit $saleUnit, public Quantity $billable, public ?int $widthMm,
    public ?int $heightMm, public ?int $pieces, public int $weightGrams, public ?PackageDimensions $package,
    public ?int $unitsPerPackage, public ?int $fixedWidthMm, public bool $pickupOnly) {} }
// ShippingRequest, Destination, ShippingCustomer, ShippingOption, ShippingQuoteResult, ShippingQuoteData:
// campos de SHIPPING.md §2.5–§3 e API.md §2.6 (option_id "method:rule" / "method:pickup" / "method:service").
```

- **`PostalCodeLookup` (decisão desta revisão — candidata a ADR-029):** Customers (camada 1)
  precisa derivar cidade/UF/IBGE ao salvar endereço, mas não pode depender de Shipping
  (camada 2). A **interface** `PostalCodeLookup` e o DTO `PostalCodeInfo` ficam em
  `App\Shared\Contracts` (kernel, criado pelo B-W1 ou B-C no dia 1 da Onda 2); a
  implementação (`CachedPostalCodeLookup` → `ViaCepPostalCodeLookup` → fallback) e o binding
  ficam em Shipping (B-C). B-A testa com `FakePostalCodeLookup` do kernel de testes.
  ```php
  interface PostalCodeLookup { public function lookup(PostalCode $cep): ?PostalCodeInfo; } // null = inexistente; lança PostalCodeLookupUnavailable (503)
  ```
- **`OrderShippingRequestSource`** (modo `order_id` do simulador): interface em
  `App\Modules\Cart\Contracts` (B-C), implementada em Checkout por B-D (**BE-CHK-08**); até lá
  `NullOrderShippingRequestSource` → 422 `errors.order_id` "Indisponível".

### 5.7 Payments (B-D, interno à Onda 3)

Assinaturas de ARCHITECTURE §2.4 Payments/§7.1 (`PaymentService`, `PaymentGatewayInterface`,
`PaymentWebhookVerifier`, `PaymentGatewayManager`) — mesmo agente, não cruzam ondas. B-E lê
`payments`/`payment_refunds` por SQL (Reports é read model).

### 5.8 Orders → Reports

B-E consome apenas tabelas (SELECT) e os enums públicos `OrderStatus`, `OrderPaymentStatus`,
`CancelReasonCode` de `Orders\Enums` (criados no B-W1).

## 6. Onda 1 — Fundação (em andamento)

Dono: **B-W1**. As tarefas abaixo registram o escopo e os critérios que o gate verifica.

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| BE-W1-01 | Config Laravel 13 + PHP 8.4: `bootstrap/app.php` (statefulApi, grupos `api`/`webhook`/`seo`, `withEvents(discover:false)`, exceções `{message, code}`), `config/{payments,shipping,modules,cors,sanctum,session}.php`, `.env.example` | — | `php artisan about` ok; 404/401/419/422/429 no formato de API.md §1.6 (teste) |
| BE-W1-02 | Esqueleto dos 15 módulos + `ModuleServiceProvider` (bindings/listen/policies/rotas `store/customer/admin/admin_guest/webhooks/dev/web`) | 01 | rotas de cada módulo carregadas pelo provider; `dev.php` só em `local`/`testing` |
| BE-W1-03 | Kernel `Shared`: `Money`, `Quantity`, `Dimensions`, `AreaCalculator` (ADR-019), `Weight`, `PackageDimensions`, `PostalCode`, `TaxDocument` (CPF/CNPJ alfanumérico), `Rounding`, `ActorRef`, `Mask`, `HtmlSanitizer` (htmlpurifier), `PlainText`, `DomainException` + `ErrorCode`, middlewares (`RequestId`, `ForceJsonResponse`, `EnsureAdminSessionIsFresh`, `SecurityHeaders`), rate limiters de API.md §1.8, health | 01 | testes unitários UT-MON/QTY/AREA (TESTING §6.1) verdes |
| BE-W1-04 | Todas as migrations (DATABASE.md + colunas ADR-028: `customer_addresses.uuid`, `cart_items.last_seen_unit_price_cents`, `products.specifications`; `payment_refunds`; trigger `audit_logs`; `order_number_seq`; CHECKs de estoque) | 01 | `migrate:fresh` limpo em Postgres 16; `migrate:rollback` completo |
| BE-W1-05 | Models (com `$fillable` explícito, casts `QuantityCast`/enums, `getRouteKeyName`), enums dos módulos, factories por model | 04 | teste de arquitetura `$fillable`/sem `$guarded = []` verde; cada factory cria registro válido |
| BE-W1-06 | Seeders (DATABASE §7) **ajustados a API.md/ADR-028**: papéis/permissões de API.md §6.1–6.2; promoção "Semana do Vinil" que **não** atinja `vinil-adesivo-branco-122m`; chave `checkout.pix_expiry_minutes` | 05 | `db:seed` idempotente (2×); `VIN-BR-122-BR` 5 m resolve R$ 79,50 como visitante; `seller` sem `orders.fulfill` |
| BE-W1-07 | Interfaces/DTOs vazios da §5 (incl. `Shared\Contracts\PostalCodeLookup`) + `Null*` de inversão | 02 | teste de arquitetura: grafo sem arestas fora de ARCHITECTURE §2.5 |
| BE-W1-08 | Docker: `docker-compose.yml` (nginx, app, queue, scheduler, postgres com `ecommerce_test_*`, redis, minio, mailpit), `docker/` | — | `docker compose up` → `/api/health` 200 |
| BE-W1-09 | CI `.github/workflows/ci.yml` (backend com Postgres/Redis, storefront/admin matrix, security: gitleaks, `composer audit`, `npm audit`) + Dependabot | 01 | pipeline verde no PR |
| BE-W1-10 | Test kit: `tests/TestCase` (RefreshDatabase, `Http::preventStrayRequests`), traits `ActsAsCustomer`/`ActsAsAdmin(permissions)`, `tests/Fakes/*` (gateway, carrier, postal lookup, clock) | 05 | usados por um teste exemplo em cada suite |

**Gate 1:** BE-W1-01…10 concluídas e integradas pelo coordenador.

## 7. Onda 2 — Backend paralelo (B-A, B-B, B-C)

Todos começam no gate 1 e **no dia 1** confirmam (ou completam) as interfaces da §5 do seu
módulo. Testes: IDs de TESTING.md.

### 7.1 B-A — Identity, Customers, Settings, Audit

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| BE-SET-01 | `SettingsRepository` + cache + `GET /settings/public` + `GET /pages/{slug}` | W1 | chaves de API.md §3.G.13; públicos filtrados; `content.*` texto puro |
| BE-SET-02 | `GET/PATCH /admin/settings` (validação por chave, `expected_updated_at`, auditoria) | SET-01, IDN-01 | 409 `stale_resource`; `settings.manage`; audit com diff |
| BE-AUD-01 | `DatabaseAuditLogger` (ip, UA, request_id, ator) + `GET /admin/audit-logs*` + `GET /admin/failed-jobs` | W1 | UPDATE em `audit_logs` falha (trigger); filtros de API.md §3.G.14 |
| BE-IDN-01 | Login/logout/forgot/reset do admin, `GET /admin/me` (permissões), `PUT /admin/me/password`, `admin.fresh` 30 min/8 h | W1 | SEC-AUTH-08/10/12/14/15 verdes; mensagens neutras |
| BE-IDN-02 | Usuários (convite 72 h, activate/deactivate, reset, soft delete) | IDN-01 | anti-escalonamento (SEC-AUTH-06); último `super-admin` protegido |
| BE-IDN-03 | Papéis e permissões (`GET /admin/permissions` só leitura; papéis do sistema protegidos; `manager` sem `admin_users.manage`) | IDN-01 | SEC-AUTH-16; `AdminRoutesRequirePermissionTest` para as rotas de B-A |
| BE-CUS-01 | `POST /auth/register` PF/PJ (CPF/CNPJ alfanumérico, termos, `prohibited`), login/logout do cliente, sessão regenerada, `CustomerAuthenticated` + `GuestCartMerger` (Null) | W1 | FT-AUTH-01…08; SEC-MA-01; 403 `account_disabled` |
| BE-CUS-02 | Forgot/reset do cliente, verificação de e-mail não bloqueante | CUS-01 | respostas neutras; token único 60 min; sessões antigas invalidadas (SEC-AUTH-13) |
| BE-CUS-03 | `GET/PATCH /me`, `PUT /me/password`, `PATCH /me/company`, `POST /me/terms-acceptance` | CUS-01 | SEC-MA-02; `email`/`cnpj` → 422 |
| BE-CUS-04 | Endereços por `uuid` (limite 10, padrão, soft delete) usando `PostalCodeLookup` | CUS-01, contrato 5.6 | cidade/UF/IBGE derivados; 503 `postal_code_lookup_unavailable` não salva; SEC-IDOR-03 |
| BE-CUS-05 | Painel: clientes/empresas (listagem mascarada, `reveal-document` auditado, block/unblock derruba sessões, reset, anonimização LGPD, `price_list_id` exige `pricing.manage`) | IDN-01, CUS-01 | matriz de permissões por campo (API.md §6.3); `CustomerStatsProvider` Null |
| BE-CUS-06 | `CustomerDirectory` real (perfil de preço cliente → empresa → default) | CUS-01 | UT de `pricingProfile` para PF, PJ e sem tabela |

### 7.2 B-B — Catalog, Pricing, Inventory, Seo

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| BE-INV-01 | `InventoryService` completo com locks ordenados, CHECKs, movimentos, `StockLow` | W1 | UT-EST-*; **RC-INV-01** (reserva concorrente) verde |
| BE-INV-02 | Painel de estoque (listagem, detalhe, limiar, movimentos + CSV, entrada, ajuste com `expected_on_hand`) | INV-01 | 409 `stale_resource` no ajuste; permissões `inventory.*`; auditoria |
| BE-PRC-01 | `PriceResolver` (base, faixas pela soma do carrinho, tabela, preço promocional de variante, promoções, preço de cliente; menor vence; `price_source`) | W1, CUS-06 (contrato) | UT-PRC-01…12 (inclui exemplos E1–E7 ilustrativos + seed); `resolveMany` sem N+1 |
| BE-PRC-02 | `CouponService` (`evaluate`, `redeem` com lock, `releaseForOrder`) | W1 | UT-DESC-*; **RC-CUP-01** (limite total concorrente) |
| BE-PRC-03 | Painel: faixas (`PUT` base × tabela), tabelas, preços de cliente (EXCLUDE de vigência) | PRC-01 | `prices.manage` × `pricing.manage`; faixas não crescentes → 422 |
| BE-PRC-04 | Painel: promoções (+ preview) e cupons (+ redemptions, generate-code) | PRC-01/02 | `coupons.manage | promotions.manage`; `times_used` proibido |
| BE-CAT-01 | Categorias (árvore, reorder, imagem, slugs reservados ADR-026a) | W1 | 409 `resource_in_use` com `blockers` |
| BE-CAT-02 | Marcas (+ logo) | W1 | — |
| BE-CAT-03 | Produtos + variantes (upsert, `initial_stock` via `InventoryService::receive`, ativação RN-CAT-012, `sale_unit` imutável com pedido, `specifications`, HTML sanitizado) + bulk + disponibilidade de slug/SKU + picker | CAT-01/02, INV-01 | permissões condicionais a campos; SEC-XSS-01 |
| BE-CAT-04 | Imagens (upload validado, job `ProcessProductImage` WebP 300/800/1600, reorder, delete assíncrono) | CAT-03 | SEC-UP-01/02 |
| BE-CAT-05 | `SaleQuantityResolver` (UNIT/LINEAR_METER/SQUARE_METER/ROLL/KG/BOX, passo com sugestões, largura fixa/faixas, área por peça) | W1 | UT-QTD-*, UT-M2-*, UT-ML-* |
| BE-CAT-06 | Loja: categorias, marcas, listagem/busca (`PostgresProductSearch`, facets, sort allowlist), autocomplete, detalhe (disponibilidade A-09, `seo`), related | CAT-03, PRC-01, INV-01 | FT-CAT-*; SEC-SQL-01; SEC-SORT-01 |
| BE-CAT-07 | `CatalogQuery` real + `VariantLabelProvider` | CAT-03 | contrato 5.1 sem N+1 (assert de queries) |
| BE-SEO-01 | `/sitemap.xml`, `/robots.txt`, shell (200/404 `noindex`/301 categoria canônica, escape) | CAT-06 | SEC-XSS-02; cache invalidado por evento |

### 7.3 B-C — Shipping

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| BE-SHP-01 | `PostalCodeLookup` (ViaCEP + cache + circuit breaker + fallback UF) + `GET /postal-codes/{cep}` | W1 | T01–T03, T34–T38 |
| BE-SHP-02 | `ShippingRequestFactory`, `CartLogisticsCalculator` (peso/volume/cubagem por unidade) | W1 | T42–T47 |
| BE-SHP-03 | `ZoneMatcher` + `RuleEvaluator` + `RulePriceCalculator` (prioridade → especificidade → id; `per_kg` por kg iniciado; percentual; mínimo; vigência) | SHP-02 | T06–T12, T21–T24, T39–T41, T48 |
| BE-SHP-04 | Handlers `pickup`, `own_delivery`, `table_rate`, `carrier` (+ `CarrierRegistry`, `FakeCarrier`, orçamento de tempo, memoização) | SHP-03 | T13–T20, T25–T26, T51 |
| BE-SHP-05 | `ShippingEngine` (`quote`/`evaluate` com trace) + `ShippingQuoteService` (`quoteAndStore`, `estimate`, `validateForCheckout`, hash) | SHP-04 | T27–T33, T49–T50; contrato 5.6 congelado |
| BE-SHP-06 | Painel: transportadoras (credenciais write-only cifradas, teste de conexão), métodos (reorder, `type` imutável), zonas (teste de CEP), cidades IBGE, regras (duplicate, reorder) | SHP-03 | 409 `resource_in_use`; `shipping.manage`; auditoria sem credenciais |
| BE-SHP-07 | Simulador (`items`, `logistics_override`; `order_id` via contrato) + `GET /admin/shipping/quotes/{uuid}` | SHP-05 | T52; trace com reasons/warnings |
| BE-SHP-08 | `shipping:prune-quotes`, logs `shipping` sem PII | SHP-05 | T53, T55 |

**Gate 2:** tarefas de B-A, B-B, B-C concluídas; `php artisan test` completo verde no
`main` integrado; contratos 5.1–5.6 com implementação real.

## 8. Onda 3 — Backend transacional (B-D, B-E)

### 8.1 B-D — Cart, Checkout, Orders, Payments, Notifications

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| BE-CART-01 | `CartService` (resolução por sessão/`X-Cart-Token`, snapshot recalculado, faixas pela soma, `blocking_reasons`, `last_seen_unit_price_cents`) | gate 2 | FT-CART-01…; 404 `cart_not_found`; limite 50 linhas |
| BE-CART-02 | `POST /products/{slug}/price-preview` e `POST /shipping/quote` | CART-01 | exemplos API.md §4.2/§4.3 (R$ 79,50; R$ 90,00; área mínima) |
| BE-CART-03 | Itens (`POST/PATCH/DELETE`), `DELETE /cart`, merge por (variante, largura, altura) | CART-01 | SEC-PRICE-01/04/06/07; SEC-IDOR-09 |
| BE-CART-04 | Cupom no carrinho (`PUT/DELETE`) | CART-01 | 422 `errors.code`; `login_required` para visitante |
| BE-CART-05 | `POST /cart/shipping-quote` (CEP ou `address_uuid`) + `acknowledge-prices` | CART-01 | exemplo API.md §4.5 |
| BE-CART-06 | `GuestCartMerger` real (login/registro), `carts:prune` | CART-03 | FT-AUTH merge; token reutilizado → 404 |
| BE-ORD-01 | `OrderStateMachine`, `OrderPlacement` (snapshot, número `CV-` via sequence, histórico), enums públicos | gate 2 | UT-SM-*; transições de API.md §3.G.9 |
| BE-PAY-01 | `PaymentGatewayManager` + driver `sandbox` + `FakePaymentGateway`; `PaymentService` (`createPending`, `initiate` fora de TX, `syncFromGateway`, `markExpired`) | gate 2 | gateway nunca em transação (teste com spy) |
| BE-CHK-01 | `POST /checkout/preview` (`CheckoutSummary`, `blocking[]`) | CART-*, ORD-01 | sempre 200 com corpo válido |
| BE-CHK-02 | `POST /checkout` (ordem de validação de API.md §3.E, advisory lock, fingerprint, limite 3 pendentes, 503 com pedido criado + replay) | CHK-01, PAY-01 | SEC-IDEM-01…08; SEC-SHIP-*; SEC-DISC-*; **RC-CHK-01** |
| BE-PAY-02 | Webhook `/webhooks/{provider}` (HMAC, janela 300 s, dedupe, 200 `{"status":"ok"}`, job `ProcessWebhookEvent`) + `/dev/payments/*` | PAY-01 | SEC-WH-01…10 |
| BE-ORD-02 | `MarkOrderAsPaid` (commit de estoque, `estimated_delivery_date`), pagamento tardio (reativação ADR-022 ou estorno), `PaymentAmountMismatch` | PAY-02 | SEC-WH-05/07/07b; **RC-PAY-01** (webhook × expiração) |
| BE-ORD-03 | Expiração (`orders:expire-pending`: gateway fora de TX, `SKIP LOCKED`, release + cupom) + reconciliação 5 min + `webhooks:retry-unprocessed` | ORD-02 | FT-PAY-05; CA-019 |
| BE-PAY-03 | Mercado Pago (`Http::fake`), estornos assíncronos (`payment_refunds`, `ProcessRefund` com retry, índice de estorno ativo) | PAY-01 | FT-PAY-06…; SEC-IDEM (estorno duplo) |
| BE-ORD-04 | Pedidos do cliente: lista, detalhe, status (polling), cancel (`pending_payment`), cancellation-request, `POST …/payment` | ORD-01, PAY-01 | SEC-IDOR-01/02/07/08 |
| BE-ORD-05 | Painel: lista/filtros/status-counts, detalhe (`allowed_transitions` por permissão), `PATCH` (notas/rastreio), transições (retirada com nome+documento), cancel (unpaid/paid com estorno), dismiss, reveal-document, reconcile | ORD-02, PAY-03 | FT-ORD-*; SEC-AUTH-04/07; 409 `invalid_status_transition` |
| BE-CHK-03 | Recompra (`reorder`, `reorder-suggestions`) | CART-03, ORD-04 | RN-PED-040…045 |
| BE-CHK-04 | `GET /me/data-export` (LGPD) | ORD-04 | 3/h; JSON completo |
| BE-NOT-01 | Notificações e-mail (pt-BR) por evento (ARCHITECTURE §5.2) + WhatsApp stub + timeline `/me/notifications` + `/admin/notifications` + digest de estoque baixo | ORD-02 | Mail::fake por evento; 1 e-mail por webhook repetido |
| BE-ORD-10 | `MovementReferenceResolver` real | ORD-01 | rótulo "Pedido CV-000123" nos movimentos |
| BE-ORD-11 | `CustomerStatsProvider` real | ORD-01 | anonimização bloqueada com pedido em andamento |
| BE-CHK-08 | `OrderShippingRequestSource` (simulador modo `order_id`) | ORD-01 | T52 com `order_id` |

### 8.2 B-E — Reports

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| BE-REP-01 | `GET /admin/dashboard` (blocos filtrados por permissão, `null` sem permissão; America/Sao_Paulo) | BE-ORD-01 (schema já existe desde W1) | FT-REP-01 |
| BE-REP-02 | 10 relatórios JSON (`sales`, `products`, `revenue`, `customers`, `inventory`, `inventory-movements`, `orders`, `shipping`, `margin`, `coupons`) com `summary` de API.md §3.G.15 | REP-01 | números conferidos contra pedidos criados por factory; CA-018 |
| BE-REP-03 | CSV síncrono (BOM, `;`, decimal `,`, reais, proteção de fórmula, ≤ 50 000 linhas, auditoria) | REP-02 | `reports.export` exigida; célula `=cmd` prefixada |

**Gate 3:** fluxo de aceite (TESTING.md §7) passa como **teste de feature** backend
(`AcceptanceFlowTest`, sem browser).

## 9. Frontend (FE-ST, FE-AD) — paralelo desde a Onda 2

Trabalham contra **API.md** usando **MSW** (handlers gerados a partir dos exemplos de API.md
§4 e dos tipos §2) e trocam para a API real quando o dono do endpoint conclui (gate 2 ou 3).
Tipos em `src/shared/api/types.ts` copiados de API.md §2; query keys de API.md §7.

### 9.1 FE-ST — Storefront

| ID | Tarefa | Endpoints (dono) | Critérios de aceite |
|---|---|---|---|
| FE-ST-01 | Bootstrap: Vite/TS strict/MUI/Router/TanStack Query, cliente HTTP (CSRF, 419 retry 1×, `X-Cart-Token`, `X-Request-Id` no toast), `ApiError` por `code`, MSW, tema e layout (UX §1–§3) | `/sanctum/csrf-cookie` (B-A) | lint/typecheck/Vitest verdes; a11y base |
| FE-ST-02 | Utilitários de dinheiro/quantidade (`formatBRL`, `parseBRLToCents`, `toMilli`, `formatQuantity`) | — | UT-FE-MONEY-* |
| FE-ST-03 | Home, menu de categorias, settings públicas, páginas institucionais | settings, pages, categories (B-A/B-B) | Helmet por rota |
| FE-ST-04 | Listagem/categoria/busca com filtros, ordenação, paginação, autocomplete | products, autocomplete (B-B) | query key normalizada; `keepPreviousData` |
| FE-ST-05 | Página de produto + **configurador por unidade** (UNIT/ML/M²/ROLL/KG/BOX), prévia de preço debounced, faixas, estimativa de frete | products/{slug}, price-preview, shipping/quote, postal-codes (B-B/B-D/B-C) | 5 m → R$ 79,50; 5,05 → erro de passo com sugestões; área mínima por peça exibida |
| FE-ST-06 | Carrinho (linhas, avisos de preço/estoque, cupom, cotação de frete, acknowledge) | cart* (B-D) | fluxos 409/422 de UX §4.5 |
| FE-ST-07 | Auth (entrar, cadastro PF/PJ, recuperar/redefinir, verificação), merge de carrinho | auth/* (B-A) | CPF/CNPJ com máscara e DV; `queryClient.clear()` |
| FE-ST-08 | Checkout em etapas (endereço c/ CEP, frete, revisão, `expected_total_cents`, `Idempotency-Key` por tentativa, conflitos 409 por `code`, 503) | me/addresses (B-A), checkout* (B-D) | duplo clique → 1 pedido; nova chave após mudança |
| FE-ST-09 | Página PIX (`/checkout/pedido/:uuid`): QR, copia-e-cola, contador, polling 5 s, "Gerar PIX novamente", "Simular pagamento" só em DEV | me/orders/{uuid}(/status,/payment), dev/payments (B-D) | para no `paid`/`cancelled` |
| FE-ST-10 | Minha conta: dados, senha, empresa, endereços, pedidos (lista/detalhe/timeline), cancelar, solicitar cancelamento, recompra, sugestões, notificações, exportar dados | me/* (B-A/B-D) | 404 em pedido alheio → página 404 |
| FE-ST-11 | SEO/a11y/performance: Helmet + JSON-LD dos `seo`, 404 `noindex`, orçamento 250 kB gzip, `SafeHtml` (DOMPurify) | — | SEC-XSS-03; axe sem violações sérias |

### 9.2 FE-AD — Admin

| ID | Tarefa | Endpoints (dono) | Critérios de aceite |
|---|---|---|---|
| FE-AD-01 | Bootstrap (base `/admin/`), login/recuperação, sessão (aviso de expiração, 401 `admin_session_expired`), `useCan`, menu por permissão, 403 | admin/auth, admin/me (B-A) | rotas/botões ocultos sem permissão |
| FE-AD-02 | Dashboard (refetch 60 s, blocos nulos ocultos) + notificações | dashboard (B-E), admin/notifications (B-D) | — |
| FE-AD-03 | Catálogo: categorias (árvore, reorder, imagem), marcas, produtos (form com variantes upsert, regras por unidade, especificações, HTML rico, imagens, bulk, slug/SKU availability), `stale_resource` | admin/categories, brands, products, variants (B-B) | campos de preço só com `prices.manage` |
| FE-AD-04 | Preços: faixas, tabelas, preços de cliente; promoções (preview) e cupons | admin/price-*, customer-prices, promotions, coupons (B-B) | — |
| FE-AD-05 | Estoque: lista, detalhe, movimentos (+CSV), entrada, ajuste com `expected_on_hand` | admin/inventory* (B-B) | 409 → aviso de concorrência |
| FE-AD-06 | Pedidos: lista com abas (`status-counts`), detalhe, transições (dialogs de envio/retirada), cancelamento com estorno, notas/rastreio, reveal-document, reconcile, instruções de separação | admin/orders* (B-D) | ações só de `allowed_transitions` |
| FE-AD-07 | Clientes/empresas: lista mascarada, detalhe, editar, bloquear, reset, revelar documento, anonimizar | admin/customers, companies (B-A) | — |
| FE-AD-08 | Frete: transportadoras, métodos, zonas (teste de CEP, cidades IBGE), regras (reorder/duplicate), **simulador com trace**, consulta de cotação | admin/shipping/* (B-C) | trace legível (reasons, warnings) |
| FE-AD-09 | Usuários, papéis (matriz de permissões de `GET /admin/permissions`), configurações, auditoria, jobs com falha | admin/users, roles, permissions, settings, audit-logs, failed-jobs (B-A) | papéis do sistema read-only |
| FE-AD-10 | Relatórios (filtros, gráficos, CSV download) | admin/reports (B-E) | CSV só com `reports.export` |

## 10. Onda 4 — QA

| ID | Tarefa | Depende | Critérios de aceite |
|---|---|---|---|
| QA-API-01 | Inventário de rotas × §4 (`RouteInventoryTest`) + `AdminRoutesRequirePermissionTest` + `AdminPermissionMatrixTest` (API.md §6.3) | gate 3 | 201 operações mapeadas; 0 rota admin sem permissão |
| QA-UNIT-01 | Completar unitários da matriz TESTING §6.1 (preço, m², ML, quantidade, frete, desconto, estoque) | gate 3 | todos os IDs UT-* presentes e verdes |
| QA-FT-01 | Completar features TESTING §6.2 (cadastro, login, carrinho, checkout, pedido, pagamento, cancelamento) | gate 3 | todos os IDs FT-* verdes |
| QA-SHP-01 | Matriz de frete T01–T55 (SHIPPING §13) como `tests/Feature/Shipping/ShippingMatrixTest` | gate 2 | 55 casos verdes |
| QA-SHP-02 | Cenários de frete do briefing mapeados (TESTING §6.3) incl. checkout com frete divergente | QA-SHP-01, gate 3 | SH-01…SH-11 verdes |
| QA-SEC-01 | Checklist SECURITY §22 completo | gate 3 | todos os SEC-* verdes |
| QA-RC-01 | Testes de concorrência (TESTING §6.5) com processos paralelos em Postgres | gate 3 | RC-* verdes 20× seguidas (sem flakiness) |
| QA-FE-01 | Vitest + Testing Library + MSW para componentes críticos (configurador, carrinho, checkout, página PIX, formulários admin, `useCan`) | FE | cobertura FE ≥ 70 % em `features/{product,cart,checkout}` |
| QA-COV-01 | Relatório de cobertura (TESTING §8) e lacunas | todos | metas atingidas ou justificadas |

## 11. Onda 5 — Security review

| ID | Tarefa | Critérios de aceite |
|---|---|---|
| SEC-REV-01 | Ataque dirigido: IDOR (troca de uuid/ids em todas as rotas `/me` e `/cart`), escalonamento no painel, mass assignment, manipulação de preço/frete/desconto, replay/forja de webhook, CSRF, XSS (shell SEO, descrição rica, e-mails), SQLi/sort, upload, rate limits, headers, logs com PII, rotas `dev` fora de local | cada achado com severidade + teste que reproduz |
| SEC-REV-02 | Corrigir achados críticos/altos com teste de regressão; médios com decisão registrada | 0 crítico/alto aberto |
| SEC-REV-03 | Revisar dependências (`composer audit`, `npm audit`), segredos (`gitleaks`), config de produção (debug, cookies, CORS, CSP) | pipeline `security` verde |

## 12. Onda 6 — Code review

| ID | Tarefa | Critérios de aceite |
|---|---|---|
| REV-01 | Revisão por módulo: contratos da §5 respeitados, controllers finos, transações curtas sem HTTP, ordem de locks, eventos `afterCommit`, N+1, índices usados nas consultas-chave (DATABASE §6.2), dinheiro sem float | achados listados por arquivo |
| REV-02 | Revisão dos SPAs: tipos iguais a API.md, query keys de API.md §7, tratamento por `code`, sem cálculo de preço no cliente, a11y | idem |
| REV-03 | Aplicar correções + testes | suites verdes; nenhum desvio de API.md sem ADR |

## 13. Onda 7 — QA final (E2E no stack)

| ID | Tarefa | Critérios de aceite |
|---|---|---|
| QA-E2E-01 | Subir stack (`docker compose up`, `migrate:fresh --seed`, builds das SPAs), Playwright contra `http://localhost:8080` | health 200; seed carregado |
| QA-E2E-02 | Cenário de aceite TESTING §7 (CA-001…CA-018) em Chromium | verde 3× seguidas; trace/vídeo como artifact |
| QA-E2E-03 | Variante de expiração (CA-019) com relógio de teste / comando | pedido `cancelled` (`payment_expired`), estoque liberado |
| QA-E2E-04 | Smoke do painel por papel (`warehouse`, `seller`, `finance`) + axe nas páginas principais | 403/ações ocultas conforme API.md §6.2 |
| QA-E2E-05 | Relatório final ao coordenador (bugs, riscos residuais) | aceito pelo coordenador |

## 14. Pontos de integração e riscos

| # | Risco / ponto | Mitigação | Dono |
|---|---|---|---|
| R-01 | **Seed de DATABASE §7 diverge de ADR-028/API.md**: promoção "Semana do Vinil" tem alvo `vinis` (atinge `vinil-adesivo-branco-122m`, que deve sair a R$ 15,90); papéis do seed (`seller` com `promotions.manage`/`customers.manage`/`reports.view`) ≠ API.md §6.2; chave `checkout.payment_expiry_minutes` ≠ `checkout.pix_expiry_minutes` (API §3.G.13) | BE-W1-06 segue API.md/ADR-028 (ex.: promoção com alvo `vinil-transparente` ou marca, ou `ends_at` no passado) e o coordenador atualiza DATABASE §7 | B-W1 / coordenador |
| R-02 | `PostalCodeLookup` usado por Customers (camada 1) mas implementado em Shipping (camada 2) | interface no kernel `Shared\Contracts` (§5.6) — registrar ADR-029 | B-W1/B-C |
| R-03 | Simulador (Cart) no modo `order_id` precisa de Orders | contrato `OrderShippingRequestSource` implementado em Checkout (BE-CHK-08) | B-C/B-D |
| R-04 | SHIPPING §4.3 mostra `ShippingQuoteService::quoteCart(Cart $cart …)` (Shipping dependeria de Cart) | usar `ShippingRequestFactory::fromLines(...)` de §5.6; Cart monta as linhas | B-C |
| R-05 | Peso logístico de `SQUARE_METER` usa área faturada (SHIPPING §2.3/T44) enquanto o estoque usa área real (ADR-019) | manter SHIPPING (conservador) no MVP; revisar com o PO | B-C |
| R-06 | Arquivos compartilhados (`bootstrap/app.php`, `config/*`) disputados na Onda 2 | só via provider do módulo; pedidos ao coordenador | todos |
| R-07 | Frontend adiantado contra MSW diverge da API real | handlers MSW derivados de API.md §4; teste de contrato leve (Zod `parse` em dev) ao integrar | FE-ST/FE-AD |
| R-08 | Testes de concorrência intermitentes | processos PHP separados + barreira (TESTING §6.5), sem `RefreshDatabase` transacional nesses testes | QA |
