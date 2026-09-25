# Motor de Frete (Shipping Engine)

> Documento do **Agente 7 — Shipping/Freight**. Vinculado às ADRs de `DECISIONS.md`
> (principalmente ADR-003 unidades, ADR-004 unidades de venda, ADR-011 frete,
> ADR-012 nunca confiar no frontend, ADR-013 API, ADR-016 logs).
> Este documento será **implementado literalmente**. Onde há "Decisão", ela é vinculante
> para Backend, Admin, Frontend e QA. Divergências devem virar nova ADR.

Idioma: texto em pt-BR; classes, tabelas, colunas, enums e endpoints em inglês.

Sumário:

1. [Objetivos e não-objetivos](#1-objetivos-e-não-objetivos)
2. [Entrada: `ShippingRequest` e dados logísticos do carrinho](#2-entrada-shippingrequest-e-dados-logísticos-do-carrinho)
3. [Saída: `ShippingOption` e `ShippingQuoteResult`](#3-saída-shippingoption-e-shippingquoteresult)
4. [Componentes e interfaces PHP](#4-componentes-e-interfaces-php)
5. [Modelo de dados e exemplos de regras](#5-modelo-de-dados-e-exemplos-de-regras)
6. [Algoritmo](#6-algoritmo)
7. [Validação no checkout](#7-validação-no-checkout)
8. [Prazo de entrega](#8-prazo-de-entrega)
9. [Retirada na loja](#9-retirada-na-loja)
10. [Operações do painel (admin)](#10-operações-do-painel-admin)
11. [Logs e observabilidade](#11-logs-e-observabilidade)
12. [Extensibilidade: novas transportadoras e rastreamento](#12-extensibilidade-novas-transportadoras-e-rastreamento)
13. [Matriz de testes](#13-matriz-de-testes)
14. [Configuração (`config/shipping.php`)](#14-configuração-configshippingphp)
15. [Resumo de decisões](#15-resumo-de-decisões)

---

## 1. Objetivos e não-objetivos

### Objetivos

- **Desacoplamento total:** o checkout e o carrinho conhecem apenas `ShippingEngine`,
  `ShippingRequest`, `ShippingOption` e `ShippingQuoteResult`. Nenhum código fora do
  módulo `Shipping` sabe o que é zona, regra, transportadora ou ViaCEP.
- **Configurável pelo operador:** retirada, entrega própria, tabela de frete por
  peso/CEP/cidade/UF e frete grátis são **dados** (tabelas), não código.
- **Extensível:** nova transportadora = implementar `ShippingCarrierInterface` +
  registrar um driver no `CarrierRegistry` + cadastrar no painel. Nenhuma alteração no
  engine, no checkout ou no frontend.
- **Determinístico:** mesma entrada + mesma configuração ⇒ mesmas opções, mesmos preços,
  mesma ordem. Conflitos de regra têm desempate explícito.
- **Seguro:** o preço do frete é sempre calculado e revalidado no backend (ADR-012). O
  cliente só envia `shipping_quote_id` + `shipping_option_id`.
- **Resiliente:** falha de transportadora externa ou do ViaCEP **nunca** quebra o
  checkout; a opção é omitida e o fato é registrado no canal de log `shipping`.
- **Explicável:** o simulador do painel devolve o *trace* completo (quais regras
  casaram/foram rejeitadas e por quê).

### Não-objetivos (MVP)

- Otimização de empacotamento (bin packing / cubagem 3D). Cada unidade/linha vira um
  volume conforme regras da seção 2.
- Cálculo de diâmetro real de bobina por metragem.
- Calendário de feriados (tabela prevista, não implementada — seção 8).
- Integração real com Correios/Jadlog/Melhor Envio (apenas a interface + driver `fake`;
  a seção 12 descreve como adicionar).
- Emissão de etiqueta, coleta, rastreamento automático (apenas interface futura).
- Múltiplos pontos de retirada com escolha de loja no checkout (o modelo já permite vários métodos `pickup`; o MVP cadastra um).
- Frete por cliente/tabela de preço/tipo de cliente (o DTO já carrega `customer` para
  evolução futura; nenhuma regra usa isso no MVP).
- Divisão de pedido em múltiplos envios/métodos.

---

## 2. Entrada: `ShippingRequest` e dados logísticos do carrinho

### 2.1 Unidades (ADR-003)

| Grandeza | Banco | Domínio PHP (Shipping) | API |
|---|---|---|---|
| Peso | `integer` gramas (`weight_grams`) | `int` gramas | gramas (int) |
| Dimensão de embalagem | `numeric(8,1)` cm (`package_length_cm`...) | `int` **milímetros** (`lengthMm`) — conversão exata de string "12.5" → `125` | cm (número com 1 casa) |
| Volume | `integer` cm³ (`max_volume_cm3`) | `int` cm³ (arredondado para **cima**) | cm³ (int) |
| Dinheiro | `bigint` centavos | `int` centavos | centavos (int) |
| Quantidade | `numeric(12,3)` | `int` milésimos (`Quantity`) | decimal |
| Percentual | `integer` basis points | `int` bp (`1000` = 10,00%) | bp (int) |

**Decisão:** dentro do módulo Shipping as dimensões são inteiros em milímetros para
nunca usar float. A conversão `cm (string numeric(8,1)) → mm` é feita por
`Dimension::cmToMm(string $cm): int` (`"12.5"` → `125`, `"130"` → `1300`), sem float.
Pesos derivados de quantidade fracionária são arredondados **para cima** (conservador
para frete): `ceil(weight_grams × quantity_milli / 1000)` com aritmética inteira
`intdiv(a + 999, 1000)`.

### 2.2 Dados logísticos exigidos no catálogo

Colunas esperadas (documento do Database agent deve contê-las):

| Tabela | Coluna | Tipo | Observação |
|---|---|---|---|
| `product_variants` | `weight_grams` | `integer null` | Por **unidade de venda** (por unidade, por rolo, por caixa, por metro linear, por m²). Para `KG` é ignorado. |
| `product_variants` | `package_length_cm` | `numeric(8,1) null` | Comprimento da embalagem (UNIT/ROLL/BOX/KG). Ignorado para `LINEAR_METER`/`SQUARE_METER` (calculado). |
| `product_variants` | `package_width_cm` | `numeric(8,1) null` | Para `LINEAR_METER`/`SQUARE_METER`: **diâmetro** do material enrolado. |
| `product_variants` | `package_height_cm` | `numeric(8,1) null` | Para `LINEAR_METER`/`SQUARE_METER`: **diâmetro** do material enrolado (usa-se `max(width, height)`). |
| `product_variants` | `units_per_package` | `integer null` | **Opcional/futuro.** `null` = 1 unidade por volume. Se a coluna existir, o algoritmo abaixo já a respeita. |
| `products` | `pickup_only` | `boolean not null default false` | **Decisão MVP: sim.** Itens volumosos/frágeis (chapa ACM 3 m, placa de vidro) que só podem ser retirados. |
| `products` | `sale_unit` | enum ADR-004 | Já existente. |
| `product_variants` | `fixed_width_mm` | `integer null` | Já existente (ADR-004). Largura do material para `LINEAR_METER`. |

**Decisão (dados incompletos):** o painel **não permite ativar** uma variante sem
`weight_grams > 0` (exceto `KG`; o valor `0` — default do DATABASE.md — é tratado como ausente) e dimensões de embalagem (`package_length_cm` para UNIT/ROLL/BOX/KG;
`package_width_cm`/`package_height_cm` para todos; `fixed_width_mm` para
`LINEAR_METER`). Se mesmo assim faltar dado em tempo de execução (dados legados), o
`CartLogisticsCalculator` marca `logistics.missingData = true`, loga `warning` no canal
`shipping` com `variant_id`, e **somente métodos `pickup` são oferecidos** (os demais
ficam indisponíveis com motivo `logistics_data_missing`). Nunca se usa peso padrão
inventado.

### 2.3 Conversão de item do carrinho em dados logísticos

Entrada por linha do carrinho (fornecida por um adapter do módulo Cart, com a
**quantidade faturável** já resolvida — ex.: área mínima de ADR-004 aplicada):

```php
namespace App\Modules\Shipping\Domain\Logistics;

final readonly class CartLineLogisticsInput
{
    public function __construct(
        public int $variantId,
        public SaleUnit $saleUnit,             // enum ADR-004 (UNIT, LINEAR_METER, SQUARE_METER, ROLL, KG, BOX)
        public int $billableQuantityMilli,     // quantidade faturada em milésimos (UNIT 3 → 3000; 5,5 m → 5500; 2,44 m² → 2440; 1,25 kg → 1250)
        public ?int $widthMm,                  // SQUARE_METER: largura da peça
        public ?int $heightMm,                 // SQUARE_METER: altura da peça
        public ?int $pieces,                   // SQUARE_METER: nº de peças
        public ?int $fixedWidthMm,             // largura fixa do material (LINEAR_METER obrigatório)
        public ?int $weightGrams,              // product_variants.weight_grams (por unidade de venda)
        public ?int $packageLengthMm,          // convertido de package_length_cm
        public ?int $packageWidthMm,
        public ?int $packageHeightMm,
        public ?int $unitsPerPackage,          // null = 1
        public bool $pickupOnly,               // products.pickup_only
        public int $lineTotalCents,            // valor da linha (para valor declarado/seguro de transportadora)
        public string $sku,
    ) {}
}
```

Regras de conversão (configuração `shipping.roll_margin_cm`, padrão **10 cm** → 100 mm):

| sale_unit | Peso da linha (g) | Volumes | Dimensões de cada volume |
|---|---|---|---|
| `UNIT`, `ROLL`, `BOX` | `weight_grams × q` onde `q = billableQuantityMilli / 1000` (inteiro) | `ceil(q / (unitsPerPackage ?? 1))` | `packageLengthMm × packageWidthMm × packageHeightMm` (embalagem cheia; MVP `unitsPerPackage = 1` ⇒ 1 volume por unidade) |
| `LINEAR_METER` | `ceil(weight_grams × billableQuantityMilli / 1000)` | **1 por linha** | comprimento = `fixedWidthMm + margem`; largura = altura = `max(packageWidthMm, packageHeightMm)` (diâmetro) |
| `SQUARE_METER` | `ceil(weight_grams × billableQuantityMilli / 1000)` (área faturável, inclui `min_billable_area`) | **1 por linha** (todas as peças enroladas juntas) | comprimento = `min(widthMm, heightMm) + margem` (material enrola pelo lado menor; com largura fixa 1,22 m e altura ≥ 1,22 m ⇒ 1220 + 100 mm); largura = altura = diâmetro |
| `KG` | `billableQuantityMilli` (1 kg = 1000 g ⇒ milésimos de kg **são** gramas) | **1 por linha** | `packageLengthMm × packageWidthMm × packageHeightMm` |

Observações:

- `weight_grams` de `UNIT/ROLL/BOX` é o peso **da unidade embalada**. Com
  `unitsPerPackage > 1` (futuro), o peso continua `weight_grams × q` e as dimensões da
  variante representam a embalagem cheia.
- Diâmetro fixo por variante é uma simplificação do MVP (não cresce com a metragem).
- Se qualquer linha tem `pickupOnly = true`, `CartLogistics.hasPickupOnlyItems = true`.

### 2.4 Totais (`CartLogistics`)

```text
volume_cm3(v)         = ceil(v.lengthMm × v.widthMm × v.heightMm / 1000)      // mm³ → cm³, inteiro, arredonda p/ cima
total_weight_grams    = Σ peso das linhas
total_volume_cm3      = Σ volume_cm3(v) para todo volume v
volumes_count         = Σ volumes
largest_dimension_mm  = max(v.lengthMm, v.widthMm, v.heightMm) sobre todos os volumes
cubic_weight_grams(d) = ceil(total_volume_cm3 × 1000 / d)   // d = divisor cúbico (padrão 6000 → 1 m³ = 166,67 kg)
chargeable_weight(d)  = max(total_weight_grams, cubic_weight_grams(d))
```

**Decisão:** peso cubado e peso taxável são **funções** de `CartLogistics` recebendo o
divisor, porque cada método/transportadora declara o seu:

- `shipping_methods.weight_basis`: `real` (padrão) ou `chargeable`.
- `shipping_methods.cubic_divisor`: `integer null` (null ⇒ `shipping.default_cubic_divisor` = 6000).
- Transportadoras externas recebem os volumes e calculam por conta própria (ou usam
  `chargeableWeightGrams()` se o driver declarar).

As regras (`shipping_rules`) comparam `min/max_weight_grams` com o **peso efetivo do
método** = `real` ou `chargeable` conforme `weight_basis`. O `per_kg` também usa o peso
efetivo.

```php
namespace App\Modules\Shipping\Domain\Logistics;

final readonly class ShippingPackage   // um volume físico
{
    public function __construct(
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $weightGrams,       // peso rateado do volume (linha / nº de volumes, resto no 1º volume)
        public int $variantId,
    ) {}

    public function volumeCm3(): int;  // ceil(l*w*h / 1000)
    public function largestDimensionMm(): int;
}

final readonly class LogisticsItem     // agregado por linha (valor declarado, SKU) para transportadoras
{
    public function __construct(
        public int $variantId,
        public string $sku,
        public SaleUnit $saleUnit,
        public int $billableQuantityMilli,
        public int $weightGrams,
        public int $declaredValueCents,
        public bool $pickupOnly,
    ) {}
}

final readonly class CartLogistics
{
    /** @param list<ShippingPackage> $packages @param list<LogisticsItem> $items */
    public function __construct(
        public array $packages,
        public array $items,
        public int $totalWeightGrams,
        public int $totalVolumeCm3,
        public int $volumesCount,
        public int $largestDimensionMm,
        public bool $hasPickupOnlyItems,
        public bool $missingData,
        /** @var list<int> */ public array $variantsMissingData,
    ) {}

    public function cubicWeightGrams(int $divisor): int;       // intdiv(totalVolumeCm3 * 1000 + divisor - 1, divisor)
    public function chargeableWeightGrams(int $divisor): int;  // max(real, cúbico)
    public function effectiveWeightGrams(WeightBasis $basis, int $divisor): int;
}

final class CartLogisticsCalculator
{
    public function __construct(private readonly int $rollMarginMm) {}

    /** @param iterable<CartLineLogisticsInput> $lines */
    public function calculate(iterable $lines): CartLogistics;
}
```

Rateio de peso por volume: `intdiv(linePeso, n)` em cada volume e o resto
(`linePeso % n`) somado ao primeiro volume, para que Σ pesos dos volumes = peso da linha.

### 2.5 Destino e cliente

```php
namespace App\Modules\Shipping\Domain;

final readonly class Destination
{
    public function __construct(
        public string $postalCode,       // exatamente 8 dígitos, sem máscara
        public ?string $cityIbgeCode,    // 7 dígitos; null se lookup falhou
        public ?string $city,
        public ?string $state,           // UF (2 letras); pode vir do fallback por faixa de CEP
        public bool $resolved,           // true = PostalCodeLookup respondeu com sucesso
        public ?string $stateSource,     // 'lookup' | 'cep_range' | null
    ) {}

    public function postalPrefix(): string; // 5 primeiros dígitos (para logs)
}

final readonly class ShippingCustomer
{
    public function __construct(
        public int $id,
        public CustomerType $type,       // individual | company (alinhar com customers.type)
        public ?int $companyId,
    ) {}
}
```

### 2.6 `ShippingRequest`

```php
namespace App\Modules\Shipping\Domain;

final readonly class ShippingRequest
{
    public function __construct(
        public Destination $destination,
        public CartLogistics $logistics,
        public int $subtotalCents,          // subtotal dos itens APÓS descontos (promoções + cupom), SEM frete
        public bool $couponFreeShipping,    // cupom válido aplicado ao carrinho concede frete grátis
        public ?ShippingCustomer $customer = null,
        public ?string $cartId = null,      // uuid/id do carrinho (null na simulação de produto/admin)
        public ?string $requestId = null,   // X-Request-Id (ADR-013)
    ) {}
}
```

Construção: `ShippingRequestFactory::fromCart(Cart $cart, string $postalCode, ?Customer $customer): ShippingRequest`
(normaliza CEP, chama `DestinationResolver`, `CartLogisticsCalculator`, obtém o
subtotal pós-desconto e o flag de cupom do módulo Cart/Coupon). O módulo Cart expõe um
adapter `CartShippingLinesProvider` que devolve `list<CartLineLogisticsInput>`.

---

## 3. Saída: `ShippingOption` e `ShippingQuoteResult`

```php
namespace App\Modules\Shipping\Domain;

final readonly class CarrierInfo
{
    public function __construct(
        public string $code,          // shipping_carriers.code, ex.: 'correios'
        public string $name,
        public ?string $serviceCode,  // ex.: 'SEDEX', '03220'
        public ?string $serviceName,
    ) {}
}

final readonly class PickupAddress
{
    public function __construct(
        public string $street, public string $number, public ?string $complement,
        public string $district, public string $city, public string $state,
        public string $postalCode, public ?string $openingHours, public ?string $instructions,
    ) {}
}

enum FreeShippingReason: string { case Rule = 'rule'; case Coupon = 'coupon'; }

final readonly class ShippingOption
{
    public function __construct(
        public string $optionId,             // estável entre cotações (ver 3.1): "2:103" | "5:SEDEX" | "1:pickup"
        public int $methodId,
        public string $methodCode,
        public ShippingMethodType $methodType,
        public string $name,                 // shipping_methods.name
        public ?string $description,
        public int $priceCents,              // valor cobrado
        public int $originalPriceCents,      // valor antes de frete grátis (== priceCents se não grátis)
        public bool $isFree,
        public ?FreeShippingReason $freeReason,
        public int $deliveryDaysMin,         // dias úteis
        public int $deliveryDaysMax,
        public string $deliveryLabel,        // "2 a 3 dias úteis" / "Disponível em 1 dia útil após o pagamento"
        public ?CarrierInfo $carrier,
        public ?PickupAddress $pickupAddress,
        public ?int $ruleId,                 // interno (não exposto na API pública)
        public int $methodPosition,          // interno, desempate de ordenação
    ) {}
}

enum UnavailableReason: string
{
    case DestinationUnresolved = 'destination_unresolved';   // zona só por cidade e lookup falhou
    case OutOfCoverage         = 'out_of_coverage';          // nenhuma zona do método casa com o destino
    case NoRuleMatched         = 'no_rule_matched';          // cobertura ok, nenhuma regra satisfez condições
    case WeightAboveLimit      = 'weight_above_limit';       // todas as regras rejeitadas apenas por peso máximo
    case VolumeAboveLimit      = 'volume_above_limit';       // idem, por volume ou comprimento
    case LogisticsDataMissing  = 'logistics_data_missing';
    case PickupOnlyItems       = 'pickup_only_items';
    case CarrierNotRegistered  = 'carrier_not_registered';
    case CarrierInactive       = 'carrier_inactive';
    case CarrierUnsupported    = 'carrier_unsupported';      // supports() = false
    case CarrierTimeout        = 'carrier_timeout';
    case CarrierError          = 'carrier_error';
    case CarrierServiceMissing = 'carrier_service_missing';  // resposta não contém o service_code do método
    case CarrierBudgetExceeded = 'carrier_budget_exceeded';  // orçamento total de tempo esgotado
}

final readonly class UnavailableMethod
{
    public function __construct(
        public int $methodId,
        public string $methodCode,
        public UnavailableReason $reason,
        public ?string $detail,              // texto técnico p/ admin/log; nunca exposto na API pública
    ) {}
}

final readonly class ShippingQuoteResult
{
    /** @param list<ShippingOption> $options já ordenadas @param list<UnavailableMethod> $unavailable */
    public function __construct(
        public ?string $quoteId,             // uuid de shipping_quotes (null quando não persistido: simulação)
        public ?\DateTimeImmutable $expiresAt,
        public Destination $destination,
        public array $options,
        public array $unavailable,
        public string $requestHash,
        public ?EvaluationTrace $trace = null, // só no simulador do admin
    ) {}
}
```

### 3.1 `option_id`

**Decisão (alinhada a `DATABASE.md` §3.8.5):** `option_id` é uma chave **estável entre
cotações**, única dentro da cotação:

```text
métodos com regra (own_delivery, table_rate): "{method_id}:{rule_id}"        ex.: "2:103"
transportadora:                               "{method_id}:{service_code}"   ex.: "5:SEDEX"
retirada:                                     "{method_id}:pickup"           ex.: "1:pickup"
```

- É o `shipping_option_id` enviado no checkout e gravado em `orders.shipping_option_id`.
- Por ser estável, o checkout compara a opção escolhida com a recotação pelo mesmo
  `option_id`. A autenticidade vem do `quote_id` (uuid) + verificação de dono/hash, não
  da opacidade do id. Expor `rule_id` dentro do id não é problema de segurança (preço
  sempre recalculado no servidor).

### 3.2 API pública

`POST /api/v1/cart/shipping-quote` — rate limit `shipping-quote` (**10/min**) (path e limites
conforme API.md §3.B/§1.8 — ver ADR-028; substitui `POST /shipping/quotes` 30/min).
Carrinho identificado por sessão (cliente logado) ou `X-Cart-Token` (ADR-007). A resposta
vem dentro do `Cart` (`shipping_quote: ShippingQuote`, API.md §2.5/§2.6); o formato de
`ShippingQuote`/`ShippingOption` abaixo é o mesmo.

Request (um de `postal_code` ou `address_uuid`):

```json
{ "postal_code": "89010-100" }
```

Response `200`:

```json
{
  "data": {
    "quote_id": "0b8f7c2e-6a61-4f3a-9d0e-3c9a4a1f2b10",
    "expires_at": "2026-09-24T15:30:00Z",
    "destination": { "postal_code": "89010100", "city": "Blumenau", "state": "SC" },
    "options": [
      {
        "option_id": "1:pickup",
        "method_code": "pickup-store",
        "method_type": "pickup",
        "name": "Retirada na loja",
        "description": "Rua XV de Novembro, 1000 — Centro, Blumenau/SC",
        "price_cents": 0,
        "original_price_cents": 0,
        "is_free": true,
        "delivery_days_min": 1,
        "delivery_days_max": 1,
        "delivery_label": "Disponível em 1 dia útil após o pagamento",
        "carrier": null,
        "pickup_address": {
          "street": "Rua XV de Novembro", "number": "1000", "complement": null,
          "district": "Centro", "city": "Blumenau", "state": "SC", "postal_code": "89010000",
          "opening_hours": "Seg a Sex, 8h às 18h", "instructions": "Apresente o número do pedido."
        }
      },
      {
        "option_id": "3:201",
        "method_code": "table-regional",
        "method_type": "table_rate",
        "name": "Transportadora regional",
        "description": null,
        "price_cents": 2000,
        "original_price_cents": 2000,
        "is_free": false,
        "delivery_days_min": 2,
        "delivery_days_max": 3,
        "delivery_label": "2 a 3 dias úteis",
        "carrier": null,
        "pickup_address": null
      },
      {
        "option_id": "2:100",
        "method_code": "own-delivery",
        "method_type": "own_delivery",
        "name": "Entrega própria",
        "description": "Entregamos com veículo próprio",
        "price_cents": 2500,
        "original_price_cents": 2500,
        "is_free": false,
        "delivery_days_min": 2,
        "delivery_days_max": 2,
        "delivery_label": "2 dias úteis",
        "carrier": null,
        "pickup_address": null
      }
    ]
  }
}
```

Forma resumida (equivalente ao exemplo do briefing), apenas ilustrativa:
`[{"method":"Retirada na loja","price":0,"delivery_days":1},{"method":"Transportadora regional","price":2000,"delivery_days":3},{"method":"Entrega própria","price":2500,"delivery_days":2}]`.

- `unavailable`, `rule_id`, `detail` e `trace` **não** aparecem na API
  pública. Quando `options` é vazio, a resposta é `200` com `options: []` e
  `message: "Não há opções de entrega para este CEP."`.
- Campo `notice` (string ou `null`): único motivo exposto publicamente, pois é
  acionável pelo cliente — `"pickup_only_items"` quando o carrinho tem item
  `pickup_only` (seção 9).
- CEP com formato inválido ⇒ `422 {errors: {postal_code: ["CEP inválido."]}}`.
- Carrinho vazio ⇒ `422 {errors: {cart: ["Carrinho vazio."]}}`.

Estimativa na página de produto (mesma engine): `POST /api/v1/shipping/quote` (rate limit
`shipping-estimate`, 30/min — API.md §3.A, ADR-028)
com `{variant_id, quantity, width_m?, height_m?, pieces?, postal_code}`; monta
um `ShippingRequest` de uma linha com `subtotalCents` = total da linha resolvido pelo
`PriceResolver`, `couponFreeShipping = false`, **não persiste** cotação (`quote_id: null`).

---

## 4. Componentes e interfaces PHP

### 4.1 Estrutura do módulo

```text
backend/app/Modules/Shipping/
  Domain/
    Destination.php, ShippingCustomer.php, ShippingRequest.php
    ShippingOption.php, ShippingQuoteResult.php, UnavailableMethod.php
    CarrierInfo.php, PickupAddress.php
    Enums/ ShippingMethodType.php, ShippingPriceType.php, WeightBasis.php,
           ZoneLocationType.php, UnavailableReason.php, FreeShippingReason.php
    Logistics/ CartLineLogisticsInput.php, ShippingPackage.php, LogisticsItem.php,
               CartLogistics.php, CartLogisticsCalculator.php, Dimension.php
    Config/ MethodConfig.php, RuleConfig.php, ZoneConfig.php, ShippingConfigSnapshot.php
    Trace/ EvaluationTrace.php, MethodTrace.php, RuleTrace.php
  Engine/
    ShippingEngine.php
    Handlers/ ShippingMethodHandlerInterface.php, AbstractRuleBasedHandler.php,
              PickupHandler.php, OwnDeliveryHandler.php, TableRateHandler.php, CarrierHandler.php
    ZoneMatcher.php, RuleEvaluator.php, RulePriceCalculator.php, DeliveryLabelFormatter.php
  Carriers/
    ShippingCarrierInterface.php, ShippingQuote.php, CarrierServiceQuote.php,
    CarrierConfig.php, CarrierRegistry.php, CarrierException.php, CarrierTimeoutException.php
    Fake/FakeCarrier.php
  PostalCode/
    PostalCodeLookup.php, PostalCodeInfo.php, PostalCodeLookupException.php,
    PostalCodeNotFoundException.php, ViaCepPostalCodeLookup.php, CachedPostalCodeLookup.php,
    FakePostalCodeLookup.php, PostalCodeStateResolver.php, PostalCodeNormalizer.php,
    DestinationResolver.php
  Quotes/
    ShippingQuoteRepository.php, EloquentShippingQuoteRepository.php,
    QuoteHasher.php, ShippingQuoteService.php, ShippingCheckoutValidator.php
  Delivery/ BusinessDayCalculatorInterface.php, WeekendBusinessDayCalculator.php
  Tracking/ TrackingProviderInterface.php, TrackingResult.php, TrackingEvent.php  (futuro)
  Models/ ShippingCarrier.php, ShippingMethod.php, ShippingZone.php, ShippingZonePostalRange.php,
          ShippingZoneCity.php, ShippingZoneState.php, ShippingRule.php, ShippingQuote.php, IbgeCity.php
  Http/ Controllers/ (Store + Admin), Requests/, Resources/
  Console/ PruneShippingQuotesCommand.php
  ShippingServiceProvider.php
```

### 4.2 Enums

```php
enum ShippingMethodType: string {
    case Pickup = 'pickup'; case OwnDelivery = 'own_delivery';
    case TableRate = 'table_rate'; case Carrier = 'carrier';
}

enum ShippingPriceType: string {
    case Fixed = 'fixed'; case PerKg = 'per_kg'; case FixedPlusPerKg = 'fixed_plus_per_kg';
    case PercentageOfSubtotal = 'percentage_of_subtotal'; case Free = 'free';
}

enum WeightBasis: string { case Real = 'real'; case Chargeable = 'chargeable'; }

/** Valor = especificidade (maior = mais específico) */
enum ZoneLocationType: int {
    case Global = 0;       // regra com zone_id null
    case State = 1;
    case City = 2;
    case PostalRange = 3;
}
```

### 4.3 `ShippingEngine` (orquestrador)

```php
namespace App\Modules\Shipping\Engine;

final class ShippingEngine
{
    /** @param iterable<ShippingMethodHandlerInterface> $handlers */
    public function __construct(
        private readonly ShippingConfigRepository $config,   // métodos/regras/zonas ativos (cache Redis versionado)
        private readonly ZoneMatcher $zoneMatcher,
        private readonly iterable $handlers,
        private readonly LoggerInterface $logger,            // Log::channel('shipping')
        private readonly ClockInterface $clock,
    ) {}

    /** Contrato de ADR-011: devolve apenas as opções ordenadas. */
    public function quote(ShippingRequest $request): array; // list<ShippingOption> == evaluate($request)->options

    /** Avaliação completa (opções + indisponíveis + trace opcional). Não persiste. */
    public function evaluate(ShippingRequest $request, bool $withTrace = false, ?array $onlyMethodIds = null): ShippingQuoteResult;
}
```

Persistência fica no serviço de aplicação:

```php
final class ShippingQuoteService
{
    public function __construct(
        private readonly ShippingEngine $engine,
        private readonly ShippingRequestFactory $requests,
        private readonly ShippingQuoteRepository $quotes,
        private readonly QuoteHasher $hasher,
    ) {}

    /** Loja: cota o carrinho e persiste (TTL 30 min). */
    public function quoteCart(Cart $cart, string $rawPostalCode, ?Customer $customer): ShippingQuoteResult;
}
```

### 4.4 Handlers por tipo de método

```php
namespace App\Modules\Shipping\Engine\Handlers;

interface ShippingMethodHandlerInterface
{
    public function type(): ShippingMethodType;

    /**
     * Decide se o método gera opção para o request. Nunca lança exceção para o engine:
     * erros viram MethodResult::unavailable(...).
     */
    public function handle(
        MethodConfig $method,
        ShippingRequest $request,
        ZoneMatchSet $zoneMatches,     // zonas que casam com o destino (calculado 1x pelo engine)
        ?MethodTrace $trace,
    ): MethodResult;
}

final readonly class MethodResult
{
    private function __construct(public ?ShippingOptionDraft $option, public ?UnavailableMethod $unavailable) {}
    public static function option(ShippingOptionDraft $o): self;
    public static function unavailable(MethodConfig $m, UnavailableReason $r, ?string $detail = null): self;
}
```

`ShippingOptionDraft` = `ShippingOption` sem `optionId` (atribuído após o `quote_id`
existir) e ainda sem aplicação do cupom (feita pelo engine, ver 6.3).

| Handler | Comportamento |
|---|---|
| `PickupHandler` | Sempre disponível se o método está ativo. Preço 0, `original_price_cents` 0, `isFree = true`, `freeReason = null`. Prazo = `shipping_methods.delivery_days_min/max`. Endereço das colunas `pickup_*` do método (seção 9). **Não** usa regras nem zonas. Disponível mesmo com `missingData` e com lookup falho. |
| `OwnDeliveryHandler` | Estende `AbstractRuleBasedHandler`. Mesma lógica de `TableRateHandler`; difere em semântica de fulfillment (sem código de rastreio, entrega pela equipe) e no padrão `accepts_free_shipping_coupon = true`. |
| `TableRateHandler` | Estende `AbstractRuleBasedHandler` (zonas + regras, seção 6.2). |
| `CarrierHandler` | Resolve o driver no `CarrierRegistry`, chama `quote()` (uma vez por transportadora por request — memoizado), escolhe o serviço `carrier_service_code` do método, soma `handling_days`. Sem regras no MVP. |

Regras comuns a `OwnDeliveryHandler`, `TableRateHandler`, `CarrierHandler`:
se `logistics.hasPickupOnlyItems` ⇒ `pickup_only_items`; se `logistics.missingData` ⇒
`logistics_data_missing`.

### 4.5 Transportadoras

```php
namespace App\Modules\Shipping\Carriers;

interface ShippingCarrierInterface
{
    /** Código do driver, ex.: 'fake', 'correios', 'melhor_envio'. */
    public function code(): string;

    /** Pré-checagem barata e local (ex.: exige destino resolvido, limite de peso/volume do serviço). */
    public function supports(ShippingRequest $request): bool;

    /**
     * Cota todos os serviços da transportadora para o request.
     * DEVE respeitar CarrierConfig::$timeoutMs em toda chamada HTTP (connect + total).
     * @throws CarrierTimeoutException  timeout/conexão
     * @throws CarrierException         resposta inválida, 4xx/5xx, credencial
     */
    public function quote(ShippingRequest $request): ShippingQuote;
}

final readonly class CarrierServiceQuote
{
    public function __construct(
        public string $serviceCode,
        public string $serviceName,
        public ?int $priceCents,         // null quando o serviço retornou erro
        public ?int $deliveryDaysMin,
        public ?int $deliveryDaysMax,
        public ?string $error = null,
    ) {}
}

final readonly class ShippingQuote
{
    /** @param list<CarrierServiceQuote> $services */
    public function __construct(public string $carrierCode, public array $services) {}
    public function service(string $serviceCode): ?CarrierServiceQuote;
}

final readonly class CarrierConfig
{
    public function __construct(
        public int $carrierId,
        public string $code,            // shipping_carriers.code
        public string $driver,          // shipping_carriers.driver
        public string $name,
        public array $settings,         // shipping_carriers.settings (jsonb, não sensível)
        public array $credentials,      // shipping_carriers.credentials (texto criptografado → array)
        public int $timeoutMs,          // settings.timeout_ms (padrão config carrier_default_timeout_ms = 5000; 500..15000)
        public int $cubicDivisor,       // settings.cubic_divisor (padrão 6000)
        public string $originPostalCode,// settings.origin_postal_code ?? setting da loja `store.postal_code` (Blumenau)
    ) {}
}

final class CarrierRegistry
{
    /** @param \Closure(CarrierConfig): ShippingCarrierInterface $factory */
    public function register(string $driver, \Closure $factory): void;
    public function has(string $driver): bool;
    /** @throws CarrierNotRegisteredException */
    public function make(CarrierConfig $config): ShippingCarrierInterface; // instância memoizada por carrierId
}
```

Registro em `ShippingServiceProvider::boot()`:

```php
$this->app->singleton(CarrierRegistry::class, function () {
    $registry = new CarrierRegistry();
    $registry->register('fake', fn (CarrierConfig $c) => new FakeCarrier($c));
    // $registry->register('melhor_envio', fn (CarrierConfig $c) => new MelhorEnvioCarrier($c, app(HttpFactory::class)));
    return $registry;
});
```

`FakeCarrier` (usado em dev e testes) lê `settings`:
`{"mode": "ok" | "timeout" | "error", "services": [{"code":"EXP","name":"Expresso","price_cents":3990,"days_min":3,"days_max":5}]}`.
`timeout` lança `CarrierTimeoutException` sem dormir; `error` lança `CarrierException`.

### 4.6 `ZoneMatcher`

```php
final class ZoneMatcher
{
    /** @param list<ZoneConfig> $zones zonas ativas com locations carregadas */
    public function match(Destination $destination, array $zones): ZoneMatchSet;
}

final readonly class ZoneMatch
{
    public function __construct(public int $zoneId, public ZoneLocationType $specificity, public string $matchedBy) {}
    // matchedBy: "postal_range:89010000-89012999" | "city:4202404" | "state:SC"
}

final readonly class ZoneMatchSet
{
    /** @param array<int, ZoneMatch> $byZoneId */
    public function __construct(public array $byZoneId) {}
    public function get(int $zoneId): ?ZoneMatch;
    public function has(int $zoneId): bool;
}
```

Uma zona casa se **qualquer** de suas localizações casa. A especificidade da zona para
este destino é a **maior** entre as localizações que casaram:

| Localização | Casa quando | Requer lookup |
|---|---|---|
| faixa de CEP | `start_postal_code <= cep <= end_postal_code` (comparação de string de 8 dígitos = numérica) | não |
| cidade | `destination.cityIbgeCode === city_ibge_code` | **sim** (`resolved = true`) |
| UF | `destination.state === state` | não, se `stateSource = cep_range` (fallback) |

### 4.7 `RuleEvaluator` e `RulePriceCalculator`

```php
final class RuleEvaluator
{
    /**
     * Avalia uma regra contra o request. Não considera zona (já filtrada).
     * @return RuleEvaluation  matched=true ou lista de RejectionReason (todas as condições violadas, não só a 1ª)
     */
    public function evaluate(RuleConfig $rule, ShippingRequest $request, int $effectiveWeightGrams, \DateTimeImmutable $now): RuleEvaluation;
}

enum RejectionCode: string {
    case Inactive = 'inactive'; case NotYetValid = 'not_yet_valid'; case Expired = 'expired';
    case WeightBelowMin = 'weight_below_min'; case WeightAboveMax = 'weight_above_max';
    case SubtotalBelowMin = 'subtotal_below_min'; case SubtotalAboveMax = 'subtotal_above_max';
    case VolumeAboveMax = 'volume_above_max'; case LengthAboveMax = 'length_above_max';
}

final class RulePriceCalculator
{
    /** @return int centavos */
    public function price(RuleConfig $rule, int $effectiveWeightGrams, int $subtotalCents): int;
}
```

### 4.8 `PostalCodeLookup`

```php
namespace App\Modules\Shipping\PostalCode;

final readonly class PostalCodeInfo
{
    public function __construct(
        public string $postalCode, public ?string $street, public ?string $district,
        public string $city, public string $state, public string $cityIbgeCode,
    ) {}
}

interface PostalCodeLookup
{
    /**
     * @throws PostalCodeNotFoundException  CEP inexistente (ViaCEP {"erro": true})
     * @throws PostalCodeLookupException    timeout, rede, 5xx, JSON inválido
     */
    public function lookup(string $postalCode): PostalCodeInfo;
}
```

- `ViaCepPostalCodeLookup`: `GET https://viacep.com.br/ws/{cep}/json/`, `timeout(3)`,
  `connectTimeout(2)`, sem retry. Mapeia `localidade`→city, `uf`→state, `ibge`→cityIbgeCode.
- `CachedPostalCodeLookup` (decorator): chave `shipping:cep:{cep}`; sucesso cacheado
  **30 dias**; "não encontrado" cacheado **24 h** (`shipping.postal_lookup.not_found_cache_hours`);
  falhas **não** são cacheadas.
- `FakePostalCodeLookup`: mapa em memória `cep => PostalCodeInfo`, com
  `failFor(string $cep)` e `notFound(string $cep)`; binding padrão em `testing`.
  Driver via `shipping.postal_lookup.driver` (`viacep` | `fake`).
- `PostalCodeStateResolver`: tabela estática CEP→UF (fallback sem rede, seção 6.1).
- `DestinationResolver::resolve(string $normalizedCep): Destination` combina tudo.
  **Decisão:** CEP não encontrado no ViaCEP **não** é erro 422 (a base do ViaCEP tem
  lacunas); é tratado como lookup falho (`resolved = false`).

`PostalCodeNormalizer::normalize(string $raw): string` remove tudo que não é dígito;
válido sse resultado tem exatamente 8 dígitos e não é `00000000`. Senão
`InvalidPostalCodeException` → 422.

### 4.9 `ShippingQuoteRepository` e `QuoteHasher`

```php
interface ShippingQuoteRepository
{
    public function save(StoredQuote $quote): void;
    public function find(string $quoteId): ?StoredQuote;
    public function deleteExpiredBefore(\DateTimeImmutable $threshold): int;
}

final readonly class StoredQuote
{
    public function __construct(
        public string $id,                    // uuid
        public ?int $cartId,
        public ?int $customerId,
        public string $postalCode,
        public string $requestHash,
        public int $subtotalCents,
        public bool $couponFreeShipping,
        public array $options,                // list<array> serializado de ShippingOption (inclui option_id, rule_id)
        public array $unavailable,
        public array $logisticsSummary,       // total_weight_grams, total_volume_cm3, volumes_count, largest_dimension_mm
        public \DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $createdAt,
    ) {}
}

final class QuoteHasher
{
    public function hash(ShippingRequest $request, array $cartItemConfigs): string; // sha256 hex (64)
}
```

Hash (JSON canônico, chaves ordenadas, `JSON_UNESCAPED_SLASHES`):

```text
request_hash = sha256(json_encode({
  "v": 1,                                   // versão do formato
  "items": sort_by(variant_id, width_mm, height_mm)([
      {"variant_id", "quantity_milli", "width_mm", "height_mm", "pieces"} ...   // o que o cliente escolheu (cart_items)
  ]),
  "postal_code": "89010100",
  "subtotal_cents": 32000,
  "coupon_free_shipping": false
}))
```

O hash **não** inclui a configuração de frete: mudanças no painel são capturadas pela
recotação obrigatória dos métodos locais no checkout (seção 7).

---

## 5. Modelo de dados e exemplos de regras

### 5.1 Tabelas

Base: `DATABASE.md` §3.8 (fonte do schema). Esta seção repete o necessário ao motor e
marca com **[SHIP-Δ]** cada coluna/regra que o motor exige e que precisa ser
reconciliada no `DATABASE.md` (lista consolidada em 5.1.1). Convenções: `ts` =
`created_at`/`updated_at` (`timestamptz`); `sd` = `deleted_at` (soft delete).

**`shipping_carriers`** (sem soft delete; desativar com `is_active`)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint identity` | |
| `code` | `varchar(40) unique` | `^[a-z0-9_]+$`, ex.: `correios`, `fake` |
| `name` | `varchar(100)` | |
| `driver` | `varchar(40)` | chave no `CarrierRegistry` (validado contra `CarrierRegistry::has()`) |
| `credentials` | `text null` | cast `encrypted:array`; nunca retornado pela API (só `has_credentials`) |
| `settings` | `jsonb default '{}'` | não sensível. Chaves lidas pelo motor: `timeout_ms` (int, 500..15000, padrão 5000), `cubic_divisor` (int > 0, padrão 6000), `origin_postal_code` (8 dígitos; padrão setting `store.postal_code`) + chaves próprias do driver |
| `is_active` | `boolean default false` | |
| `ts` | | |

**`shipping_methods`** (`ts`, `sd`)

| Coluna | Tipo | Notas |
|---|---|---|
| `code` | `varchar(60)` | `^[a-z0-9-]+$`, único entre não excluídos; ex.: `pickup-store`, `own-delivery`, `table-regional` |
| `name` | `varchar(120)` | exibido ao cliente |
| `description` | `varchar(500) null` | |
| `type` | `varchar(20)` CHECK in (`pickup`,`own_delivery`,`table_rate`,`carrier`) | imutável após criação |
| `carrier_id` | `bigint null` FK `shipping_carriers` RESTRICT | CHECK `(type = 'carrier') = (carrier_id IS NOT NULL)` |
| `carrier_service_code` | `varchar(40) null` | obrigatório (validação app) quando `type = carrier` |
| `delivery_days_min` | `smallint default 0` | padrão do método (regra pode sobrescrever) |
| `delivery_days_max` | `smallint default 0` | `>= delivery_days_min` |
| `pickup_street`, `pickup_number`, `pickup_complement`, `pickup_district`, `pickup_city`, `pickup_state`, `pickup_postal_code`, `pickup_instructions` | conforme DATABASE.md | só `type = pickup` (obrigatórios street/city/state/postal_code) |
| `is_active` | `boolean default true` | |
| `position` | `integer default 0` | ordem de avaliação e desempate de exibição |
| `weight_basis` | `varchar(20) default 'real'` CHECK in (`real`,`chargeable`) | **[SHIP-Δ]** |
| `cubic_divisor` | `integer null` CHECK > 0 | **[SHIP-Δ]** null ⇒ `shipping.default_cubic_divisor` (6000) |
| `handling_days` | `smallint default 0` CHECK >= 0 | **[SHIP-Δ]** somado ao prazo de transportadoras |
| `accepts_free_shipping_coupon` | `boolean not null` | **[SHIP-Δ]** padrão na criação: `true` p/ `own_delivery`/`table_rate`; `false` p/ `carrier`/`pickup` |
| `pickup_opening_hours` | `varchar(200) null` | **[SHIP-Δ]** opcional; pode ficar dentro de `pickup_instructions` |

**`shipping_zones`** (`ts`, sem soft delete — zona inativa não casa; RESTRICT a partir de
`shipping_rules.zone_id`): `name varchar(120)`, `description varchar(255) null`,
`is_active boolean default true`.

**`shipping_zone_postal_ranges`** (`ts`) — `zone_id` FK CASCADE, `start_postal_code char(8)`,
`end_postal_code char(8)`; CHECK `^[0-9]{8}$` em ambos e `start_postal_code <= end_postal_code`.
Índice `(start_postal_code, end_postal_code)`.

**`shipping_zone_cities`** (`ts`) — `zone_id` FK CASCADE, `city_ibge_code char(7)`,
`city_name varchar(100)` (snapshot p/ exibição), `state char(2)`;
`unique(zone_id, city_ibge_code)`; índice `(city_ibge_code)`.

**`shipping_zone_states`** (`ts`) — `zone_id` FK CASCADE, `state char(2)`;
`unique(zone_id, state)`; índice `(state)`.

**`ibge_cities`** **[SHIP-Δ]** (referência, seed com os 5.570 municípios do IBGE; sem
`ts`) — `ibge_code char(7) primary key`, `name varchar(100)`, `state char(2)`; índice
funcional em `lower(unaccent(name))`. Usada pelo seletor de cidades do painel.

**`shipping_rules`** (`ts`, `sd`)

| Coluna | Tipo | Notas |
|---|---|---|
| `method_id` | `bigint` FK `shipping_methods` RESTRICT | só métodos `own_delivery`/`table_rate` (validação app) |
| `zone_id` | `bigint null` FK `shipping_zones` RESTRICT | null = qualquer destino **coberto** pelo método (6.2) |
| `name` | `varchar(150)` | ex.: "Blumenau até 5 kg" |
| `priority` | `integer default 100` | menor = avaliada primeiro |
| `min_weight_grams` | `integer null` | inclusivo |
| `max_weight_grams` | `integer null` | inclusivo ("até 5 kg" ⇒ 5000) |
| `min_subtotal_cents` | `bigint null` | inclusivo; subtotal após descontos, sem frete **[SHIP-Δ nome: DATABASE.md usa `min_order_cents`]** |
| `max_subtotal_cents` | `bigint null` | inclusivo **[SHIP-Δ nome: `max_order_cents`]** |
| `min_volume_cm3` | `bigint null` | inclusivo, sobre `total_volume_cm3` (aceito do DATABASE.md) |
| `max_volume_cm3` | `bigint null` | inclusivo, sobre `total_volume_cm3` |
| `max_package_length_cm` | `numeric(8,1) null` | inclusivo, sobre `largest_dimension` **[SHIP-Δ nome: `max_item_length_cm`]** |
| `price_type` | `varchar(30)` CHECK in (`fixed`,`per_kg`,`fixed_plus_per_kg`,`percentage_of_subtotal`,`free`) | **[SHIP-Δ domínio: DATABASE.md usa `fixed`,`per_kg`,`percentage`,`free`,`carrier`]** |
| `price_cents` | `bigint default 0` | `fixed`; parte fixa de `fixed_plus_per_kg`; CHECK `price_type <> 'free' OR price_cents = 0` |
| `per_kg_cents` | `bigint default 0` | `per_kg`, `fixed_plus_per_kg` **[SHIP-Δ nome: `price_per_kg_cents`]** |
| `percentage_bp` | `integer default 0` | `percentage_of_subtotal` (1000 = 10%), 1..10000 quando usado |
| `min_price_cents` | `bigint null` | piso (ignorado quando `free`) |
| `max_price_cents` | `bigint null` | teto (aceito do DATABASE.md; ignorado quando `free`) |
| `delivery_days_min` | `smallint null` | sobrescreve o método |
| `delivery_days_max` | `smallint null` | sobrescreve o método |
| `is_active` | `boolean default true` | |
| `valid_from` | `timestamptz null` | inclusivo **[SHIP-Δ]** |
| `valid_until` | `timestamptz null` | exclusivo; CHECK `valid_from < valid_until` **[SHIP-Δ]** |

CHECKs: valores ≥ 0; `min_* <= max_*` quando ambos não nulos.
Índice `(method_id, priority, id) WHERE is_active AND deleted_at IS NULL`; `(zone_id)`.

**`shipping_quotes`** (sem soft delete; podada)

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint identity` | interno |
| `uuid` | `uuid unique` | é o `quote_id` público |
| `cart_id` | `bigint` FK `carts` CASCADE | |
| `customer_id` | `bigint null` FK `customers` CASCADE | |
| `postal_code` | `char(8)` | |
| `city_ibge_code` | `char(7) null` | resolvido |
| `state` | `char(2) null` | resolvido (lookup ou fallback por faixa) |
| `request_hash` | `char(64)` | ver 4.9 |
| `subtotal_cents` | `bigint` | |
| `total_weight_grams` | `integer` | |
| `total_volume_cm3` | `bigint` | |
| `coupon_free_shipping` | `boolean default false` | **[SHIP-Δ]** |
| `options` | `jsonb` (array) | opções completas (formato abaixo) |
| `unavailable` | `jsonb default '[]'` | **[SHIP-Δ]** `[{method_id, method_code, reason, detail}]` para suporte |
| `expires_at` | `timestamptz` | `created_at + 30 min` |
| `created_at` | `timestamptz default now()` | sem `updated_at` |

Índices: `(cart_id, request_hash, expires_at desc)`, `(expires_at)`.
Job diário `shipping:prune-quotes` apaga cotações com `expires_at < now() - 7 dias`
(o pedido guarda snapshot e `shipping_quote_uuid` sem FK).

Formato de cada elemento de `options` (superset do contrato do DATABASE.md):

```json
{
  "id": "2:100", "method_id": 2, "method_code": "own-delivery", "method_type": "own_delivery",
  "rule_id": 100, "carrier_code": null, "service_code": null, "name": "Entrega própria",
  "description": null, "price_cents": 2000, "original_price_cents": 2000, "is_free": false,
  "free_reason": null, "delivery_days_min": 1, "delivery_days_max": 1, "method_position": 10
}
```

Snapshot no pedido (colunas de `orders` já definidas no DATABASE.md) — mapeamento:

| `orders` | Origem |
|---|---|
| `shipping_method_id`, `shipping_rule_id` | `option.method_id`, `option.rule_id` |
| `shipping_option_id` | `option.id` |
| `shipping_quote_uuid` | `quote_id` validado (seção 7) |
| `shipping_method_name`, `shipping_method_type` | `option.name`, `option.method_type` |
| `shipping_carrier_code`, `shipping_service_code` | `option.carrier_code`, `option.service_code` |
| `shipping_delivery_days_min/max` | `option.delivery_days_min/max` |
| `total_weight_grams` | `logistics.total_weight_grams` |
| `shipping_cents` | `option.original_price_cents` |
| `shipping_discount_cents` | `original_price_cents − price_cents` quando `free_reason = coupon`; senão `0` |

Com isso, frete grátis **por regra** grava `shipping_cents = 0` (é o preço da tabela) e
frete grátis **por cupom** grava `shipping_cents = original`, `shipping_discount_cents = original`
(relatório de custo de cupom). Em ambos os casos o cliente paga `price_cents`.
Colunas adicionais sugeridas **[SHIP-Δ]**: `orders.tracking_code varchar(100) null`,
`orders.estimated_delivery_date date null`, `orders.picked_up_by_name varchar(150) null`.

#### 5.1.1 Itens a reconciliar com `DATABASE.md`

> **Resolvido:** aceito integralmente pela ADR-025; DATABASE.md já foi alinhado. Lista mantida
> como histórico.

1. `shipping_methods`: + `weight_basis`, `cubic_divisor`, `handling_days`,
   `accepts_free_shipping_coupon` (e opcional `pickup_opening_hours`).
2. `shipping_rules`: nomes `min_subtotal_cents`/`max_subtotal_cents`,
   `max_package_length_cm`, `per_kg_cents` (conforme briefing do coordenador) vs.
   `min_order_cents`/`max_order_cents`, `max_item_length_cm`, `price_per_kg_cents`;
   `price_type` com `fixed_plus_per_kg` e `percentage_of_subtotal` em vez de
   `percentage`; **sem** `carrier` e sem `per_kg_after_grams` no MVP (transportadoras
   não usam regras — seção 6.4); + `valid_from`, `valid_until`.
3. `shipping_quotes`: + `coupon_free_shipping`, `unavailable`.
4. `products.pickup_only boolean not null default false`.
5. `product_variants.units_per_package` (opcional/futuro; DATABASE.md tem
   `units_per_box`, que pode ser reutilizado com essa semântica para `BOX`).
6. Nova tabela `ibge_cities`.
7. `orders`: + `tracking_code`, `estimated_delivery_date`, `picked_up_by_name`.
8. `product_variants.weight_grams` é `NOT NULL DEFAULT 0` no DATABASE.md: o motor
   trata `0` como **dado ausente** (seção 2.2) — exceto para `KG`, que não usa o campo.

### 5.2 Semântica da regra (resumo)

Uma regra casa quando **todas** as condições não nulas são satisfeitas (limites
inclusivos), está ativa e vigente. O preço é:

| `price_type` | Preço (centavos) |
|---|---|
| `fixed` | `price_cents` |
| `per_kg` | `kg × per_kg_cents` |
| `fixed_plus_per_kg` | `price_cents + kg × per_kg_cents` |
| `percentage_of_subtotal` | `round_half_up(subtotal_cents × percentage_bp / 10000)` = `intdiv(subtotal × bp + 5000, 10000)` |
| `free` | `0` |

- **Decisão (arredondamento do `per_kg`): cobra-se por kg iniciado.**
  `kg = max(1, intdiv(effective_weight_grams + 999, 1000))` ⇒ 5000 g = 5 kg; 5001 g = 6 kg;
  1 g = 1 kg.
- Depois (exceto `free`): `price = max(price, min_price_cents ?? 0)` e, se `max_price_cents` não nulo, `price = min(price, max_price_cents)`.

### 5.3 Exemplos concretos (linhas a cadastrar)

Códigos IBGE: Blumenau `4202404`, Gaspar `4205902`, Indaial `4207502`,
Pomerode `4213203`, Joinville `4209102` (conferir no seed `ibge_cities`).
Faixas de CEP abaixo são **ilustrativas**.

#### Métodos

| id | code | name | type | position | days min/max | accepts_free_shipping_coupon |
|---|---|---|---|---|---|---|
| 1 | `pickup-store` | Retirada na loja | pickup | 0 | 1/1 | false |
| 2 | `own-delivery` | Entrega própria | own_delivery | 10 | 1/2 | true |
| 3 | `table-regional` | Transportadora regional | table_rate | 20 | 2/4 | true |
| 4 | `table-cep` | Frete por CEP | table_rate | 30 | 3/6 | true |

#### Zonas

| id | name | localizações |
|---|---|---|
| 10 | Blumenau | cidade `4202404` |
| 11 | Gaspar | cidade `4205902` |
| 12 | Indaial | cidade `4207502` |
| 13 | Pomerode | cidade `4213203` |
| 14 | Joinville | cidade `4209102` |
| 20 | CEP Blumenau (faixa) | faixa `89000000`–`89099999` |
| 21 | CEP Grande Florianópolis | faixa `88000000`–`88139999` |
| 22 | CEP Curitiba | faixa `80000000`–`82999999` |
| 30 | Santa Catarina | UF `SC` |

#### (a) Retirada R$ 0

Método 1 (`pickup`), **sem regras**. Preço sempre 0; prazo "Disponível em 1 dia útil
após o pagamento".

#### (b) Entrega própria por cidade (Blumenau R$ 20, Gaspar R$ 30, Indaial R$ 35)

| id | method | zone | name | priority | max_weight_grams | price_type | price_cents | days |
|---|---|---|---|---|---|---|---|---|
| 100 | 2 | 10 | Entrega Blumenau | 100 | 100000 | fixed | 2000 | 1/1 |
| 101 | 2 | 11 | Entrega Gaspar | 100 | 100000 | fixed | 3000 | 1/2 |
| 102 | 2 | 12 | Entrega Indaial | 100 | 100000 | fixed | 3500 | 2/2 |

Cobertura do método 2 = zonas {10, 11, 12}. Destino em Joinville ⇒ `out_of_coverage`.

#### (c) Frete grátis para pedido ≥ R$ 500 (entrega própria)

| id | method | zone | name | priority | min_subtotal_cents | max_weight_grams | price_type |
|---|---|---|---|---|---|---|---|
| 103 | 2 | **null** | Grátis acima de R$ 500 | **10** | 50000 | 100000 | free |

- Zona nula ⇒ vale para qualquer destino **coberto** pelo método 2 (Blumenau, Gaspar,
  Indaial) — nunca para São Paulo.
- Prioridade 10 < 100 ⇒ avaliada antes das regras de preço. Subtotal 49999 ⇒ rejeitada
  (`subtotal_below_min`), cai na regra da cidade. Subtotal 50000 ⇒ grátis.
- `original_price_cents` = preço da primeira regra **não-free** que também casa (ex.: 2000
  em Blumenau), exibido "de R$ 20,00 por grátis".

#### (d) Tabela por peso por cidade (Blumenau até 5 kg R$ 15, até 10 kg R$ 20, até 20 kg R$ 28)

| id | method | zone | name | priority | max_weight_grams | price_type | price_cents |
|---|---|---|---|---|---|---|---|
| 200 | 3 | 10 | Blumenau até 5 kg | 10 | 5000 | fixed | 1500 |
| 201 | 3 | 10 | Blumenau até 10 kg | 20 | 10000 | fixed | 2000 |
| 202 | 3 | 10 | Blumenau até 20 kg | 30 | 20000 | fixed | 2800 |

- 5000 g ⇒ regra 200 (R$ 15). 5001 g ⇒ 200 rejeitada (`weight_above_max`), 201 (R$ 20).
  20000 g ⇒ 202. 20001 g ⇒ todas rejeitadas só por peso ⇒ `weight_above_limit`.
- `min_weight_grams` é desnecessário porque a prioridade ordena as faixas; pode ser
  preenchido (5001, 10001) para deixar a tabela autoexplicativa — o resultado é o mesmo.

#### (e) Frete por cidade (Blumenau, Gaspar, Indaial, Pomerode, Joinville) — mesmo método 3

| id | method | zone | name | priority | max_weight_grams | price_type | price_cents | days |
|---|---|---|---|---|---|---|---|---|
| 210 | 3 | 11 | Gaspar até 30 kg | 50 | 30000 | fixed | 2200 | 2/3 |
| 211 | 3 | 12 | Indaial até 30 kg | 50 | 30000 | fixed | 2400 | 2/3 |
| 212 | 3 | 13 | Pomerode até 30 kg | 50 | 30000 | fixed | 2600 | 2/3 |
| 213 | 3 | 14 | Joinville até 30 kg | 50 | 30000 | fixed_plus_per_kg | 2500 | 3/4 |

(`213`: `per_kg_cents = 150` ⇒ 7,2 kg ⇒ 8 kg ⇒ 2500 + 1200 = R$ 37,00.)
Blumenau usa as regras 200–202 do exemplo (d). Alternativa equivalente: uma zona
"Vale do Itajaí" com as 4 cidades e **uma** regra.

#### (f) Frete por faixa de CEP (CEP inicial / CEP final / valor / peso máximo)

Cada linha da planilha do operador vira **1 zona + 1 faixa + 1 regra** no método 4:

| planilha | zona | faixa | regra |
|---|---|---|---|
| 89000000 / 89099999 / R$ 18,00 / 30 kg | 20 | 89000000–89099999 | id 300, method 4, zone 20, priority 100, max_weight 30000, fixed 1800 |
| 88000000 / 88139999 / R$ 45,00 / 30 kg | 21 | 88000000–88139999 | id 301, method 4, zone 21, priority 100, max_weight 30000, fixed 4500 |
| 80000000 / 82999999 / R$ 60,00 / 20 kg | 22 | 80000000–82999999 | id 302, method 4, zone 22, priority 100, max_weight 20000, fixed 6000 |

Faixas funcionam **mesmo com o ViaCEP fora do ar**.

#### (g) Conflito e especificidade

Se o operador cria no método 3 a regra `id 220, zone 20 (faixa CEP Blumenau), priority 20, fixed 1700`,
para um destino em Blumenau com 7 kg casam 201 (zona cidade, prioridade 20) e 220
(zona faixa, prioridade 20). Mesma prioridade ⇒ vence a mais específica (faixa > cidade)
⇒ **220 (R$ 17)**. Se ambas tivessem a mesma especificidade ⇒ vence o **menor id**, e o
simulador exibe o aviso `tie_broken_by_id`.

#### Resultado para Blumenau (CEP 89010100), 7,2 kg

| Subtotal | Retirada | Entrega própria | Transp. regional | Frete por CEP |
|---|---|---|---|---|
| R$ 320,00 | 0 | 2000 (regra 100) | 2000 (regra 201) | 1800 (regra 300) |
| R$ 499,99 | 0 | 2000 | 2000 | 1800 |
| R$ 500,00 | 0 | **0**, original 2000 (regra 103) | 2000 | 1800 |

---

## 6. Algoritmo

### 6.1 Resolução do destino

```text
function resolveDestination(rawCep):
    cep = digitsOnly(rawCep)
    if length(cep) != 8 or cep == "00000000": throw InvalidPostalCode   // → 422
    try:
        info = PostalCodeLookup.lookup(cep)                                // cache 30d, timeout 3s
        return Destination(cep, info.cityIbgeCode, info.city, info.state, resolved=true, stateSource='lookup')
    catch PostalCodeNotFound | PostalCodeLookupFailure as e:
        log.warning('shipping.postal_lookup.failed', {cep_prefix: cep[0..5], reason: e.class})
        uf = PostalCodeStateResolver.stateFor(cep)                          // tabela estática, pode ser null
        return Destination(cep, null, null, uf, resolved=false, stateSource = uf ? 'cep_range' : null)
```

Consequência: faixas de CEP e UF continuam funcionando; zonas por cidade não casam. Um
método cuja cobertura depende só de cidades fica `destination_unresolved` (em vez de
`out_of_coverage`) quando `resolved = false`.

Tabela `PostalCodeStateResolver` (prefixo de 5 dígitos, inclusivo; conferir com a tabela
oficial dos Correios na implementação):

```text
SP 01000–19999 | RJ 20000–28999 | ES 29000–29999 | MG 30000–39999 | BA 40000–48999
SE 49000–49999 | PE 50000–56999 | AL 57000–57999 | PB 58000–58999 | RN 59000–59999
CE 60000–63999 | PI 64000–64999 | MA 65000–65999 | PA 66000–68899 | AP 68900–68999
AM 69000–69299, 69400–69899 | RR 69300–69399 | AC 69900–69999
DF 70000–72799, 73000–73699 | GO 72800–72999, 73700–76799 | RO 76800–76999
TO 77000–77999 | MT 78000–78899 | MS 79000–79999 | PR 80000–87999
SC 88000–89999 | RS 90000–99999
```

### 6.2 Engine

```text
function evaluate(request, withTrace):
    cfg      = ShippingConfigRepository.snapshot()     // métodos ativos (position ASC, id ASC), regras ativas, zonas ativas
    now      = clock.now()
    zones    = ZoneMatcher.match(request.destination, cfg.zones)
    options  = []; unavailable = []
    carrierBudgetStart = monotonicNow()

    for method in cfg.activeMethods ordered by (position ASC, id ASC):
        handler = handlers[method.type]
        trace   = withTrace ? new MethodTrace(method) : null
        result  = handler.handle(method, request, zones, trace)   // nunca lança; ver 6.4 p/ carrier
        if result.option: options.append(result.option)
        else: unavailable.append(result.unavailable)

    options = applyCouponFreeShipping(options, request)           // 6.3
    options = sort(options, by: priceCents ASC, deliveryDaysMax ASC, deliveryDaysMin ASC,
                                methodPosition ASC, methodId ASC)
    log.info('shipping.quote.evaluated', {...})                   // seção 11
    return ShippingQuoteResult(quoteId=null, ..., options, unavailable, hash, trace)
```

`AbstractRuleBasedHandler.handle` (entrega própria e tabela):

```text
function handle(method, request, zones, trace):
    L = request.logistics
    if L.hasPickupOnlyItems: return unavailable(PICKUP_ONLY_ITEMS)
    if L.missingData:        return unavailable(LOGISTICS_DATA_MISSING, variants)

    rules = cfg.rulesOf(method) where isActive                         // vigência avaliada no RuleEvaluator
    zonedRules = rules where zone_id != null and zone is active

    // --- cobertura do método ---
    if zonedRules is empty:
        covered = true                                                 // método "global": só regras com zone_id null
    else:
        covered = any(zones.has(r.zone_id) for r in zonedRules where validAt(r, now))
    if not covered:
        if not request.destination.resolved and anyZoneOfMethodHasCityLocations(method):
            return unavailable(DESTINATION_UNRESOLVED)
        return unavailable(OUT_OF_COVERAGE)

    // --- candidatas ---
    candidates = []
    for r in rules:
        if r.zone_id == null:        spec = GLOBAL(0)
        elif zones.has(r.zone_id):   spec = zones.get(r.zone_id).specificity
        else:                        trace?.rule(r, 'zone_not_matched'); continue
        candidates.append((r, spec))

    sort candidates by (r.priority ASC, spec DESC, r.id ASC)           // resolução determinística de conflitos

    weight = L.effectiveWeightGrams(method.weightBasis, method.cubicDivisor ?? cfg.defaultCubicDivisor)
    winner = null; rejections = []
    for (r, spec) in candidates:
        ev = RuleEvaluator.evaluate(r, request, weight, now)
        trace?.rule(r, spec, ev)
        if ev.matched: winner = r; break
        rejections.append(ev.reasons)

    if winner == null:
        if rejections not empty and every(set ⊆ {WEIGHT_ABOVE_MAX} for set in rejections that are non-temporal):
            return unavailable(WEIGHT_ABOVE_LIMIT, "peso {weight} g")
        if rejections not empty and every(set ⊆ {VOLUME_ABOVE_MAX, LENGTH_ABOVE_MAX, WEIGHT_ABOVE_MAX} ...):
            return unavailable(VOLUME_ABOVE_LIMIT)
        return unavailable(NO_RULE_MATCHED)

    price = RulePriceCalculator.price(winner, weight, request.subtotalCents)
    if winner.priceType == FREE:
        // preço "original" = 1ª regra não-free seguinte (mesma ordem) que também casa
        original = first r' after winner in candidates where r'.priceType != FREE and evaluate(r').matched
        originalPrice = original ? RulePriceCalculator.price(original, weight, subtotal) : 0
        isFree = true; freeReason = RULE
    else:
        originalPrice = price; isFree = (price == 0); freeReason = isFree ? RULE : null

    daysMin = winner.deliveryDaysMin ?? method.deliveryDaysMin
    daysMax = winner.deliveryDaysMax ?? method.deliveryDaysMax
    return option(ShippingOptionDraft(optionId = "{method.id}:{winner.id}", ...))
```

Notas vinculantes:

- **Primeira regra que casa vence, por método.** Ordem: `priority ASC`, depois
  especificidade (`postal_range 3 > city 2 > state 1 > global 0`) `DESC`, depois `id ASC`.
  Prioridade é o critério principal (controle explícito do operador); especificidade só
  desempata prioridades iguais. Isso cumpre ADR-011 ("vence a regra de maior prioridade
  que casar") e gera **no máximo uma opção por método**.
- "Rejeitada apenas por peso" ignora regras rejeitadas por vigência/`inactive`.
- Frete grátis por valor é só uma regra de prioridade mais alta com `price_type = free` e
  `min_subtotal_cents` — sem código especial.
- Uma regra `fixed` com `price_cents = 0` também produz `is_free = true`,
  `free_reason = rule`, `original_price_cents = 0`.

### 6.3 Cupom de frete grátis

**Decisão:** quando `request.couponFreeShipping = true`, **todas** as opções cujo método
tem `accepts_free_shipping_coupon = true` ficam grátis (padrão: `own_delivery` e
`table_rate`; `carrier` e `pickup` = false, configurável por método no painel). Motivo:
regra previsível para o cliente ("frete grátis nas entregas da loja"), sem depender de
qual é a mais barata, e o operador controla o custo desligando o flag em métodos caros.

```text
function applyCouponFreeShipping(options, request):
    if not request.couponFreeShipping: return options
    for o in options where methodOf(o).acceptsFreeShippingCoupon and not o.isFree:
        o = o.with(priceCents = 0, originalPriceCents = o.originalPriceCents, isFree = true, freeReason = COUPON)
    return options
```

(Se a opção já é grátis por regra, mantém `freeReason = rule`.) Restrições do cupom
(mínimo, validade) são responsabilidade do módulo Coupon; Shipping só recebe o flag.

### 6.4 `CarrierHandler`

```text
function handle(method, request, zones, trace):
    L = request.logistics
    if L.hasPickupOnlyItems: return unavailable(PICKUP_ONLY_ITEMS)
    if L.missingData:        return unavailable(LOGISTICS_DATA_MISSING)
    carrierCfg = cfg.carrier(method.carrierId)
    if carrierCfg is null or not carrierCfg.isActive: return unavailable(CARRIER_INACTIVE)
    if not registry.has(carrierCfg.driver):              return unavailable(CARRIER_NOT_REGISTERED)
    carrier = registry.make(carrierCfg)
    if not carrier.supports(request):                    return unavailable(CARRIER_UNSUPPORTED)

    quote = memo[carrierCfg.id]                                      // 1 chamada por transportadora por request
    if quote is unset:
        if elapsed(carrierBudgetStart) > config('shipping.carrier_total_budget_ms'):
            return unavailable(CARRIER_BUDGET_EXCEEDED)
        t0 = now()
        try:
            quote = carrier.quote(request)
            memo[carrierCfg.id] = quote
            log.info('shipping.carrier.quoted', {carrier, duration_ms})
        catch CarrierTimeoutException as e:
            memo[carrierCfg.id] = FAILED(TIMEOUT)
            log.warning('shipping.carrier.failed', {carrier, reason:'timeout', duration_ms, cep_prefix})
        catch Throwable as e:                                         // qualquer erro, inclusive bugs do driver
            memo[carrierCfg.id] = FAILED(ERROR)
            log.error('shipping.carrier.failed', {carrier, reason:'error', exception: class, message (sanitizada), duration_ms})
    if memo is FAILED(x): return unavailable(x == TIMEOUT ? CARRIER_TIMEOUT : CARRIER_ERROR)

    s = quote.service(method.carrierServiceCode)
    if s is null or s.error or s.priceCents is null: return unavailable(CARRIER_SERVICE_MISSING, s?.error)
    return option(ShippingOptionDraft(
        optionId = "{method.id}:{s.serviceCode}",
        priceCents = s.priceCents, originalPriceCents = s.priceCents, isFree = s.priceCents == 0,
        deliveryDaysMin = (s.deliveryDaysMin ?? method.deliveryDaysMin) + method.handlingDays,
        deliveryDaysMax = (s.deliveryDaysMax ?? method.deliveryDaysMax) + method.handlingDays,
        carrier = CarrierInfo(carrierCfg.code, carrierCfg.name, s.serviceCode, s.serviceName)))
```

- O PHP não interrompe chamadas; o driver **deve** usar
  `Http::connectTimeout(min(2, t))->timeout(t)` com `t = timeoutMs/1000`. O orçamento total
  (`carrier_total_budget_ms`, padrão 10000) evita somar timeouts de muitas transportadoras.
- Falha de transportadora **nunca** vira exceção fora do handler; o checkout segue com as
  demais opções.

### 6.5 Persistência (loja)

```text
function quoteCart(cart, rawCep, customer):
    request = ShippingRequestFactory.fromCart(cart, rawCep, customer)   // 422 se CEP inválido / carrinho vazio
    result  = engine.evaluate(request)
    quoteId = uuid()                                                    // shipping_quotes.uuid
    hash    = QuoteHasher.hash(request, cart.itemConfigs())
    options = result.options
    repo.save(StoredQuote(quoteId, cart.id, customer?.id, request.destination.postalCode, hash,
                          request.subtotalCents, request.couponFreeShipping, options, result.unavailable,
                          request.logistics.summary(), expiresAt = now + 30 min, createdAt = now))
    return result.with(quoteId, expiresAt, options, hash)
```

A cotação é persistida **mesmo com zero opções** (útil para suporte).

### 6.6 Configuração em cache

`ShippingConfigRepository::snapshot()` carrega métodos, regras, zonas (com localizações)
e transportadoras ativos em um `ShippingConfigSnapshot` imutável, cacheado no Redis
(`shipping:config:v{version}`, TTL 10 min). Todo write do painel em tabelas de frete
incrementa `shipping:config:version` (observer nos models). Testes usam cache `array`.

---

## 7. Validação no checkout

O cliente envia em `POST /api/v1/checkout` (com `Idempotency-Key`, ADR-009):
`shipping_quote_id`, `shipping_option_id` e o endereço salvo (`address_uuid`; endereço novo é
criado antes via `POST /me/addresses`). **Qualquer campo de preço de frete enviado pelo
cliente é rejeitado com 422 `prohibited`, sem efeito** (ADR-012; API.md §1.7 — ver ADR-028).

```text
function validateShippingForCheckout(cart, customer, address, quoteId, optionId):
    stored = repo.find(quoteId)
    currentRequest = ShippingRequestFactory.fromCart(cart, address.postalCode, customer)
    currentHash    = QuoteHasher.hash(currentRequest, cart.itemConfigs())

    reason = null
    if stored is null:                                      reason = 'shipping_quote_invalid'
    elif stored.customerId not in (null, customer.id):      reason = 'shipping_quote_invalid'   // não revela existência
    elif stored.cartId != cart.id:                          reason = 'shipping_quote_invalid'   // ex.: merge de carrinho
    elif stored.expiresAt <= now:                           reason = 'shipping_quote_expired'
    elif stored.postalCode != currentRequest.destination.postalCode: reason = 'shipping_postal_code_changed'
    elif stored.requestHash != currentHash:                 reason = 'shipping_quote_changed'

    chosen = stored ? stored.options.find(o => o.optionId == optionId) : null
    if stored and not reason and chosen is null:            reason = 'shipping_option_invalid'

    if reason == null:
        if chosen.methodType == CARRIER:
            return chosen                                   // cotação válida e íntegra: usa o preço persistido
        fresh = engine.evaluate(currentRequest, onlyMethodIds: [chosen.methodId])  // métodos locais: SEMPRE recalcula (ADR-011); só o método escolhido
        f = fresh.options.find(o => o.optionId == chosen.optionId)
        if f and f.priceCents == chosen.priceCents and f.deliveryDaysMax == chosen.deliveryDaysMax:
            return f
        reason = 'shipping_price_changed'

    // Divergência: recalcula e oferece nova cotação
    newQuote = ShippingQuoteService.persist(currentRequest, engine.evaluate(currentRequest))
    if chosen is not null
       and reason in ('shipping_quote_expired', 'shipping_quote_changed', 'shipping_quote_invalid')
       and stored.customerId in (null, customer.id):         // nunca reaproveita escolha de cotação alheia
        g = newQuote.options.find(o => o.optionId == chosen.optionId)
        if g and g.priceCents == chosen.priceCents:
            return g                                        // mesmo preço: aceita silenciosamente com a nova cotação
    if reason == 'shipping_price_changed' and newQuote.options.find(o => o.optionId == chosen.optionId) is null:
        reason = 'shipping_option_unavailable'
    throw ShippingConflict(409, reason, newQuote)
```

Regra de aceitação silenciosa: se a cotação estava expirada/inválida/hash diferente mas a
opção equivalente (mesmo `option_id`) existe na recotação com **o mesmo preço**, o checkout
prossegue usando a nova cotação. Se o CEP do endereço mudou, sempre 409 (o cliente
precisa ver as opções do novo CEP).

Resposta 409:

```json
{
  "message": "O valor do frete foi atualizado. Escolha novamente a forma de entrega.",
  "code": "shipping_price_changed",
  "shipping_quote": { "quote_id": "…", "expires_at": "…", "options": [ … ] }
}
```

Códigos possíveis: `shipping_quote_invalid`, `shipping_quote_expired`,
`shipping_quote_changed`, `shipping_postal_code_changed`, `shipping_option_invalid`,
`shipping_price_changed`, `shipping_option_unavailable` (opção sumiu na recotação).

A validação ocorre **antes** da reserva de estoque e dentro do fluxo de criação do pedido;
o pedido grava o snapshot de frete (seção 5.1) e `shipping_quote_uuid`. Log
`shipping.checkout.mismatch` (warning) com `reason`, `quote_id`, `cep_prefix`.

---

## 8. Prazo de entrega

**Decisão MVP:** exibir apenas "X a Y dias úteis" a partir de
`delivery_days_min/max` (regra ⇒ método ⇒ transportadora + `handling_days`). Sem data
calculada na cotação.

`DeliveryLabelFormatter`:

| Caso | Texto |
|---|---|
| entrega, min == max == 1 | "1 dia útil" |
| entrega, min == max = N | "N dias úteis" |
| entrega, min < max | "X a Y dias úteis" |
| entrega, 0 | "Entrega no mesmo dia útil" |
| retirada, 0 | "Disponível no mesmo dia útil após o pagamento" |
| retirada, N ≥ 1 | "Disponível em N dia(s) útil(eis) após o pagamento" |

Contagem após o pagamento aprovado. Ao aprovar o pagamento, o módulo Order grava
`orders.estimated_delivery_date` usando:

```php
interface BusinessDayCalculatorInterface
{
    /** Soma N dias úteis a partir do instante de pagamento, respeitando o horário de corte. */
    public function addBusinessDays(\DateTimeImmutable $paidAt, int $days): \DateTimeImmutable;
}
```

`WeekendBusinessDayCalculator` (MVP): timezone `America/Sao_Paulo`; se `paidAt` é
fim de semana ou após o corte `shipping.cutoff_time` (padrão `14:00`), a contagem começa
no próximo dia útil; exclui sábados e domingos. Feriados: tabela futura
`shipping_holidays (date, name, state null, city_ibge_code null)` e
`HolidayAwareBusinessDayCalculator` — **fora do MVP**.

---

## 9. Retirada na loja

- Endereço, horário e instruções vêm das colunas `pickup_*` do próprio método
  (`shipping_methods.pickup_street` … `pickup_instructions`, alinhado ao DATABASE.md),
  preenchidas no painel com o endereço da loja em Blumenau/SC. Cada método `pickup`
  ativo é um ponto de retirada (MVP: um). O CEP de origem das transportadoras vem de
  `shipping_carriers.settings.origin_postal_code` ou do setting `store.postal_code`.
- Preço sempre R$ 0; prazo = `delivery_days_min/max` do método ("Disponível em 1 dia útil
  após o pagamento").
- Disponível para qualquer CEP (inclusive com lookup falho e itens `pickup_only`).
  O checkout continua exigindo endereço (dados fiscais).
- Se o carrinho tem item `pickup_only` e o método de retirada está inativo, a cotação
  retorna `options: []` e a mensagem pública
  "Um ou mais itens do carrinho estão disponíveis somente para retirada." — o
  storefront mostra essa mensagem quando a API devolve `notice: "pickup_only_items"`
  (único motivo exposto publicamente, pois é acionável pelo cliente).
- Fluxo de status (ADR-008): `paid → processing → ready_for_pickup → picked_up`.
  - Admin "Marcar pronto para retirada" ⇒ `ready_for_pickup`, e-mail ao cliente com
    endereço, horário e número do pedido.
  - Admin "Confirmar retirada" ⇒ `picked_up`, com `picked_up_by_name` **e**
    `picked_up_by_document` **obrigatórios** (documento exibido mascarado; revelação
    auditada) — API.md §3.G.9, D-13 (ver ADR-028).
  - Pedidos de retirada **não** passam por `shipped/delivered`; pedidos de entrega não
    passam por `ready_for_pickup`. A máquina de estados do Order valida pelo
    `orders.shipping_method_type`.

---

## 10. Operações do painel (admin)

Permissão (`spatie/laravel-permission`, guard `admin`): **`shipping.manage`** cobre leitura,
escrita e simulador (API.md §6.1/§6.3 — ver ADR-028; `shipping.view`/`shipping.simulate` não
existem). Todas as alterações geram `audit_logs` (credenciais de transportadora **nunca**
entram no diff). Paths e payloads canônicos: API.md §3.G.11 (edição por `PATCH`).

| Endpoint | Descrição |
|---|---|
| `GET/POST /api/v1/admin/shipping/carriers`, `GET/PATCH/DELETE …/{id}`, `POST …/{id}/test` | CRUD. `credentials` write-only (resposta traz `has_credentials: true`). `driver` validado contra `CarrierRegistry::has()`. |
| `GET …/carriers/drivers` | Lista drivers registrados. |
| `GET/POST /api/v1/admin/shipping/methods`, `GET/PATCH/DELETE …/{id}` | CRUD. `type` imutável após criação. CHECKs de carrier. |
| `PUT …/methods/reorder` | `{ids: [...]}` define `position`. |
| `GET/POST /api/v1/admin/shipping/zones`, `GET/PATCH/DELETE …/{id}`, `POST …/{id}/test` | Zona com localizações aninhadas no payload (nomes de campo em API.md §3.G.11); coleções enviadas no PATCH substituem as localizações (sync). Aceita CEP com máscara. Aviso (não erro) para faixas sobrepostas. |
| `GET /api/v1/admin/shipping/cities?search=blum&state=SC` | Busca em `ibge_cities` (unaccent). |
| `GET/POST /api/v1/admin/shipping/rules?method_id=`, `GET/PATCH/DELETE …/{id}`, `POST …/reorder` | CRUD. Valores monetários em centavos, pesos em gramas. Validação: `method.type ∈ {own_delivery, table_rate}`; campos exigidos por `price_type`. |
| `POST /api/v1/admin/shipping/rules/{id}/duplicate` | Facilita tabelas por faixa. |
| `POST /api/v1/admin/shipping/simulate` | **Simulador de frete** (abaixo). Não persiste. |
| `GET /api/v1/admin/shipping/quotes/{uuid}` | Consulta de cotação (suporte), inclui `unavailable`. |

Exclusão de zona referenciada por qualquer regra (FK RESTRICT) ⇒ 409 `resource_in_use` com `blockers`; transportadora referenciada por método ⇒ 409 `resource_in_use` (desativar com `is_active`).

### 10.1 Simulador

Request (exatamente um de `items`, `order_id` ou `logistics_override` — API.md §3.G.11, ver ADR-028):

```json
{
  "postal_code": "89010100",
  "items": [
    { "variant_id": 55, "quantity": 3 },
    { "variant_id": 81, "width_m": 1.22, "height_m": 2.5, "pieces": 2 }
  ],
  "subtotal_cents": 32000,            // opcional; se ausente usa o PriceResolver
  "coupon_free_shipping": false,
  "at": "2026-12-01T12:00:00Z",       // opcional: testar vigência de regras
  "method_ids": [2, 3]                // opcional: filtra
}
```

`logistics_override: {total_weight_grams, total_volume_cm3, largest_dimension_cm}` testa
tabelas sem montar carrinho; `order_id` reproduz a logística de um pedido existente.

Resposta: o `ShippingQuoteResult` completo **com** `unavailable` e `trace`:

```json
{
  "destination": { "postal_code": "89010100", "city": "Blumenau", "city_ibge_code": "4202404", "state": "SC", "resolved": true, "state_source": "lookup" },
  "logistics": { "total_weight_grams": 7200, "total_volume_cm3": 48210, "cubic_weight_grams_6000": 8035, "volumes_count": 4, "largest_dimension_cm": 132.0, "has_pickup_only_items": false, "missing_data": false },
  "zones_matched": [ { "zone_id": 10, "name": "Blumenau", "matched_by": "city:4202404", "specificity": "city" },
                     { "zone_id": 20, "name": "CEP Blumenau (faixa)", "matched_by": "postal_range:89000000-89099999", "specificity": "postal_range" } ],
  "methods": [
    {
      "method_id": 3, "code": "table-regional", "status": "option",
      "coverage": { "covered": true, "via_zones": [10] },
      "effective_weight_grams": 7200, "weight_basis": "real",
      "rules": [
        { "rule_id": 200, "priority": 10, "specificity": "city", "result": "rejected", "reasons": [ { "code": "weight_above_max", "detail": "7200 g > 5000 g" } ] },
        { "rule_id": 201, "priority": 20, "specificity": "city", "result": "matched" },
        { "rule_id": 202, "priority": 30, "specificity": "city", "result": "not_evaluated" },
        { "rule_id": 210, "result": "zone_not_matched" }
      ],
      "winner_rule_id": 201,
      "price_breakdown": { "price_type": "fixed", "price_cents": 2000, "min_price_cents": 0, "final_cents": 2000 },
      "warnings": []
    },
    { "method_id": 4, "code": "table-cep", "status": "unavailable", "reason": "weight_above_limit", "detail": "…" }
  ],
  "options": [ … ],
  "unavailable": [ … ]
}
```

`warnings` possíveis: `tie_broken_by_id` (duas regras candidatas com mesma prioridade e
especificidade), `free_rule_without_coverage_limit` (regra `free` com `zone_id` nulo em
método sem regras zonadas ⇒ grátis para o Brasil todo), `rule_never_reachable` (regra
sempre ofuscada por outra de prioridade menor sem condições).

O trace é construído pelos mesmos handlers (`?MethodTrace`), garantindo que o simulador
reflita exatamente o cálculo real. Na loja, `withTrace = false` (custo zero).

---

## 11. Logs e observabilidade

Canal `shipping` (JSON, ADR-016). Todo registro inclui `request_id` (ADR-013) e
`cep_prefix` (5 primeiros dígitos). **Nunca**: CEP completo, logradouro, número, nome,
CPF/CNPJ, credenciais ou corpo de resposta de transportadora com dados pessoais.

| Evento | Nível | Campos |
|---|---|---|
| `shipping.quote.evaluated` | info | `quote_id`, `cart_id`, `customer_id`, `cep_prefix`, `state`, `resolved`, `items_count`, `total_weight_grams`, `volumes_count`, `options_count`, `option_ids`, `unavailable` (`method_code:reason`), `duration_ms` |
| `shipping.quote.no_options` | warning | idem acima (operador descobre regiões sem cobertura) |
| `shipping.postal_lookup.failed` | warning | `cep_prefix`, `reason` (`timeout`/`not_found`/`http_5xx`/`invalid_json`), `duration_ms` |
| `shipping.carrier.quoted` | info | `carrier`, `services_count`, `duration_ms` |
| `shipping.carrier.failed` | warning (timeout) / error | `carrier`, `reason`, `http_status`, `exception`, `duration_ms`, `cep_prefix` |
| `shipping.logistics.missing_data` | warning | `variant_ids` |
| `shipping.checkout.mismatch` | warning | `quote_id`, `reason`, `cep_prefix`, `old_price_cents`, `new_price_cents` |
| `shipping.config.changed` | info | `entity`, `id`, `admin_id`, nova `version` |

Mensagens de exceção de drivers são sanitizadas (truncadas a 300 caracteres, sem query
string com tokens). Métricas futuras (Prometheus/StatsD): latência de cotação, taxa de
falha por transportadora, cotações sem opção por UF.

---

## 12. Extensibilidade: novas transportadoras e rastreamento

### 12.1 Passo a passo — exemplo Melhor Envio (vale para Correios, Jadlog)

1. Criar `backend/app/Modules/Shipping/Carriers/MelhorEnvio/MelhorEnvioCarrier.php`
   implementando `ShippingCarrierInterface`:
   - `code()` ⇒ `'melhor_envio'`.
   - `supports()` ⇒ `false` se `volumesCount > limite` ou `largestDimensionMm` acima do
     permitido; caso contrário `true`.
   - `quote()` ⇒ monta payload a partir de `CarrierConfig::$originPostalCode`,
     `request->destination->postalCode`, `request->logistics->packages` (converter mm→cm e
     g→kg **como string decimal**, sem float), valor declarado de `logistics->items`;
     `Http::withToken($config->credentials['token'])->connectTimeout(2)->timeout($config->timeoutMs/1000)->post(...)`;
     converte reais → centavos a partir da **string** da resposta
     (`Money::fromDecimalString("23.45")` ⇒ 2345); mapeia cada serviço para
     `CarrierServiceQuote`. HTTP 4xx/5xx/JSON inválido ⇒ `CarrierException`;
     `ConnectionException` ⇒ `CarrierTimeoutException`.
2. Registrar no `ShippingServiceProvider`:
   `$registry->register('melhor_envio', fn (CarrierConfig $c) => new MelhorEnvioCarrier($c, app(HttpFactory::class)));`
3. Adicionar variáveis de ambiente só se necessário para sandbox/URL base
   (`MELHOR_ENVIO_BASE_URL`); credenciais ficam em `shipping_carriers.credentials`
   (criptografadas), cadastradas no painel.
4. No painel: criar a transportadora (`driver = melhor_envio`, `settings.timeout_ms`,
   credenciais) e **um método `carrier` por serviço** a oferecer
   (ex.: `carrier_service_code = "1"` PAC, `"2"` SEDEX), com `handling_days`,
   `position` e `accepts_free_shipping_coupon`.
5. Testes: `MelhorEnvioCarrierTest` com `Http::fake()` (sucesso, 500, timeout
   simulado via `Http::fake(fn () => throw new ConnectionException())`, JSON inválido,
   serviço com erro) e um teste de integração do engine com o driver registrado.
6. Nenhuma alteração em `ShippingEngine`, checkout, storefront ou admin SPA.

### 12.2 Rastreamento (futuro)

```php
namespace App\Modules\Shipping\Tracking;

interface TrackingProviderInterface
{
    public function code(): string;                          // mesmo code do carrier
    /** @throws TrackingException */
    public function track(string $trackingCode): TrackingResult;
}

final readonly class TrackingEvent
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $status,           // normalizado: posted|in_transit|out_for_delivery|delivered|exception
        public string $description,
        public ?string $location,
    ) {}
}

final readonly class TrackingResult
{
    /** @param list<TrackingEvent> $events ordem cronológica */
    public function __construct(public string $trackingCode, public string $currentStatus, public array $events) {}
}
```

Registro análogo em `TrackingProviderRegistry`. Um job futuro consulta pedidos `shipped`
e move para `delivered` quando `currentStatus = delivered`. MVP: `orders.tracking_code`
preenchido manualmente pelo admin ao marcar `shipped`.

---

## 13. Matriz de testes

Implementação obrigatória pelo QA (PHPUnit, Postgres real; `FakePostalCodeLookup`,
`FakeCarrier`, clock congelado). Salvo indicação, usar os dados da seção 5.3 e destino
Blumenau `89010100` resolvido.

| # | Cenário | Setup | Esperado |
|---|---|---|---|
| T01 | CEP válido | `"89010-100"` | normaliza para `89010100`; 200; opções retirada, entrega própria, tabela |
| T02 | CEP inválido — curto | `"8901010"` | 422 `postal_code` |
| T03 | CEP inválido — letras / zeros | `"8901A100"`, `"00000000"` | 422 |
| T04 | Região sem cobertura | CEP de SP `01310100` (lookup SP) | apenas retirada; `unavailable` contém `own-delivery:out_of_coverage` e `table-regional:out_of_coverage`; log `shipping.quote.no_options` **não** (há retirada) |
| T05 | Sem nenhuma opção | SP + retirada inativa | 200, `options: []`, mensagem; log `no_options` |
| T06 | Peso no limite | 5000 g | tabela = regra 200, 1500 |
| T07 | Peso logo acima | 5001 g | regra 201, 2000 |
| T08 | Peso acima de todas | 20001 g no método 3 | `weight_above_limit` |
| T09 | Frete grátis 499,99 | subtotal 49999 | entrega própria 2000, `is_free=false` |
| T10 | Frete grátis 500,00 | subtotal 50000 | entrega própria 0, `is_free=true`, `free_reason=rule`, `original_price_cents=2000` |
| T11 | Grátis não vaza cobertura | subtotal 50000, CEP de SP | entrega própria `out_of_coverage` |
| T12 | Entrega própria por cidade | Gaspar / Indaial | 3000 / 3500 |
| T13 | Retirada | qualquer CEP | preço 0, endereço das colunas `pickup_*` do método (§9), label "Disponível em 1 dia útil após o pagamento" |
| T14 | Item `pickup_only` | um produto `pickup_only=true` | só retirada; demais `pickup_only_items` |
| T15 | Transportadora ok | `FakeCarrier mode=ok` | opção com preço do fake, prazo + `handling_days`, `carrier` preenchido |
| T16 | Transportadora timeout | `mode=timeout` | opção omitida, `carrier_timeout`, log warning; demais opções presentes; HTTP 200 |
| T17 | Transportadora erro | `mode=error` | `carrier_error`, log error, 200 |
| T18 | Serviço ausente | fake sem o `service_code` | `carrier_service_missing` |
| T19 | Driver não registrado | `driver='xyz'` | `carrier_not_registered`, sem exceção |
| T20 | Memoização | 2 métodos do mesmo carrier | `quote()` chamado 1 vez |
| T21 | Múltiplas regras | 7,2 kg Joinville (regra 213) | `2500 + 8×150 = 3700` |
| T22 | Conflito — especificidade | regras 201 e 220 (mesma prioridade) | vence 220 (faixa CEP) |
| T23 | Conflito — empate total | duas regras mesma prioridade/zona | vence menor id; simulador `tie_broken_by_id` |
| T24 | Prioridade > especificidade | regra global prioridade 5 vs cidade prioridade 10 | vence a global |
| T25 | Cupom frete grátis | `couponFreeShipping=true` | entrega própria e tabela 0, `free_reason=coupon`, `original_price_cents` preservado; carrier e retirada inalterados |
| T26 | Cupom + método com flag false | `accepts_free_shipping_coupon=false` na tabela | tabela mantém preço |
| T27 | Quote expirada | checkout 31 min depois, preço igual | aceita silenciosamente com nova cotação |
| T28 | Quote expirada, preço mudou | alterar regra entre cotação e checkout + expirar | 409 `shipping_price_changed` com nova cotação |
| T29 | Hash mismatch | alterar quantidade no carrinho após cotar | recotação; 409 se preço diferente |
| T30 | CEP do endereço ≠ CEP cotado | endereço em Gaspar, cotação Blumenau | 409 `shipping_postal_code_changed` |
| T31 | Quote de outro cliente | `quote_id` de outro customer | 409 `shipping_quote_invalid`; nenhum dado da outra cotação vazado |
| T32 | Preço vindo do cliente | body com `shipping_price_cents: 1` | **422 `prohibited`**, nenhum pedido (API.md §1.7 — ver ADR-028) |
| T33 | Regra alterada com quote válida | quote válida, admin altera regra 100 para 2200 | 409 `shipping_price_changed` (métodos locais sempre recalculados) |
| T34 | Lookup falho — faixa | ViaCEP falha; método 4 | regra 300 aplica (1800) |
| T35 | Lookup falho — cidade | ViaCEP falha; métodos 2 e 3 | `destination_unresolved`; retirada disponível |
| T36 | Lookup falho — UF | zona UF SC, lookup falho | casa via `PostalCodeStateResolver` (`state_source=cep_range`) |
| T37 | Cache de CEP | 2 cotações mesmo CEP | 1 chamada HTTP (`Http::fake` + contagem) |
| T38 | ViaCEP `{"erro":true}` | CEP inexistente | tratado como não resolvido; cache 24 h |
| T39 | Arredondamento per_kg | `per_kg` 150: 1 g, 1000 g, 1001 g, 5000 g, 5001 g | 150, 150, 300, 750, 900 |
| T40 | Percentual | 10% (1000 bp) sobre 12345 | 1235 (half-up) |
| T41 | Preço mínimo | per_kg 100, min 1500, 3 kg | 1500 |
| T42 | Peso cubado | método `weight_basis=chargeable`, volume 60000 cm³, real 5 kg | efetivo 10000 g ⇒ regra de 10 kg |
| T43 | Logística LINEAR_METER | 5,5 m, 180 g/m, fixed 1220 mm, diâmetro 10 cm | peso 990 g; volume 132×10×10 cm; 1 volume |
| T44 | Logística SQUARE_METER com área mínima | peça 0,3×0,3 m, min 1 m², 250 g/m² | peso 250 g; comprimento 30+10 cm |
| T45 | Logística KG | 1,25 kg | 1250 g |
| T46 | Logística UNIT | 3 un × 400 g | 1200 g, 3 volumes |
| T47 | Dados faltando | variante sem `weight_grams` | só retirada; `logistics_data_missing`; log |
| T48 | Vigência | regra com `valid_until` no passado / `valid_from` futuro | ignorada |
| T49 | Ordenação | opções empatadas em preço | ordena por `delivery_days_max`, depois `position` |
| T50 | Determinismo | mesma entrada 2× | mesmas opções, mesma ordem e mesmos `option_id`; `quote_id` diferentes |
| T51 | Orçamento de carriers | 3 carriers lentos (fake com clock) | após estourar orçamento: `carrier_budget_exceeded` |
| T52 | Simulador | POST simulate com permissão / sem permissão | trace com reasons / 403 |
| T53 | Logs sem PII | qualquer cotação | log contém `cep_prefix` de 5 dígitos, não contém CEP completo |
| T54 | Rate limit | 11 `POST /cart/shipping-quote`/min; 31 `POST /shipping/quote`/min | 429 `too_many_requests` (API.md §1.8) |
| T55 | Poda | `shipping:prune-quotes` | remove expiradas há > 7 dias |

---

## 14. Configuração (`config/shipping.php`)

```php
return [
    'quote_ttl_minutes'          => env('SHIPPING_QUOTE_TTL_MINUTES', 30),
    'default_cubic_divisor'      => 6000,
    'roll_margin_cm'             => 10,
    'carrier_default_timeout_ms' => 5000,
    'carrier_total_budget_ms'    => env('SHIPPING_CARRIER_BUDGET_MS', 10000),
    'cutoff_time'                => '14:00',
    'timezone'                   => 'America/Sao_Paulo',
    'config_cache_ttl_seconds'   => 600,
    'postal_lookup' => [
        'driver'                => env('SHIPPING_POSTAL_LOOKUP', 'viacep'), // viacep | fake
        'base_url'              => env('VIACEP_BASE_URL', 'https://viacep.com.br/ws'),
        'timeout_seconds'       => 3,
        'cache_days'            => 30,
        'not_found_cache_hours' => 24,
    ],
    // rate limits ficam nos limiters `shipping-quote` (10/min) e `shipping-estimate` (30/min) — API.md §1.8
];
```

---

## 15. Resumo de decisões

| Tema | Decisão |
|---|---|
| Conflito de regras | Por método, 1ª regra que casa vence; ordem `priority ASC`, especificidade `DESC` (faixa CEP > cidade > UF > global), `id ASC`. No máximo 1 opção por método. |
| Regra com `zone_id` nulo | Vale para destinos **cobertos** pelo método (algum zoned rule do método casa); método sem regras zonadas = cobertura total. |
| Limites | Todos inclusivos ("até 5 kg" = `max_weight_grams 5000`); `valid_until` exclusivo. |
| `per_kg` | Por kg iniciado, mínimo 1 kg. |
| Peso cubado | `ceil(volume_cm3 × 1000 / divisor)`, só quando `weight_basis = chargeable`. |
| Cupom frete grátis | Zera todas as opções de métodos com `accepts_free_shipping_coupon = true` (padrão: own_delivery e table_rate). |
| `pickup_only` | Flag em `products` no MVP; só retirada é oferecida. |
| Dados logísticos ausentes | Só retirada; nunca peso inventado. |
| Lookup de CEP falho | Faixas de CEP e UF (fallback estático) funcionam; zonas por cidade não. CEP não encontrado ≠ 422. |
| Transportadoras | Falha ⇒ opção omitida + log; checkout nunca quebra; orçamento total de tempo. Sem regras de preço no MVP. |
| Checkout | Métodos locais sempre recalculados; carrier usa cotação persistida se válida e hash igual; divergência de preço ⇒ 409 com nova cotação; mesmo preço ⇒ aceita. |
| Prazo | "X a Y dias úteis"; data estimada só após pagamento (fins de semana + corte 14h; feriados futuro). |
| Assinatura do engine | `quote(ShippingRequest): list<ShippingOption>` (ADR-011) + `evaluate()` para resultado completo/trace. |
