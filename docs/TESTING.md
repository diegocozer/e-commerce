# Estratégia de Testes

> Documento do **Software Architect**. Complementa `IMPLEMENTATION_PLAN.md` (quem implementa
> cada teste, em qual onda) e consolida as matrizes de `SECURITY.md §22` e `SHIPPING.md §13`.
> Expectativas de HTTP (status, `code`, paths, permissões) seguem **API.md** (ADR-028);
> números de referência seguem o **seed** (DATABASE.md §7, ADR-028).

## Sumário

1. [Pirâmide e princípios](#1-pirâmide-e-princípios)
2. [Ferramentas](#2-ferramentas)
3. [Convenções](#3-convenções)
4. [Fakes e dados de teste](#4-fakes-e-dados-de-teste)
5. [Como rodar (local e CI)](#5-como-rodar-local-e-ci)
6. [Matriz obrigatória](#6-matriz-obrigatória)
7. [Cenário E2E de aceite](#7-cenário-e2e-de-aceite)
8. [Metas de cobertura](#8-metas-de-cobertura)
9. [Checklist de revisão de testes](#9-checklist-de-revisão-de-testes)

---

## 1. Pirâmide e princípios

```text
            ▲  E2E (Playwright, stack docker)       ~10 cenários — fluxo de aceite, smoke do painel
           ▲▲  Frontend (Vitest + Testing Library + MSW)   componentes e hooks críticos
         ▲▲▲▲  Feature backend (PHPUnit + HTTP + Postgres)  endpoints, segurança, concorrência, frete
      ▲▲▲▲▲▲▲  Unit backend (PHPUnit, sem HTTP)              VOs, preço, quantidade, frete, desconto, estoque
```

- **Regras de negócio são provadas embaixo** (unit): aritmética de dinheiro/quantidade/área,
  `PriceResolver`, `SaleQuantityResolver`, motor de frete, cupom, máquina de estados.
- **Contrato HTTP é provado em feature** (Postgres real): status, `code`, formato de API.md,
  efeitos no banco ("sem efeito" = assert explícito de que nada mudou).
- **Concorrência** só em feature com **processos separados** no Postgres (nunca simulada).
- **E2E** cobre o que só o stack integrado prova: cookies/CSRF, proxy/nginx, fila, webhook
  assinado, SPAs reais.
- Nada de rede externa em testes: `Http::preventStrayRequests()` global; fakes para gateway,
  transportadora e CEP.
- Testes determinísticos: relógio congelado (`$this->travelTo()`), UUIDs/sequências nunca
  assumidos por valor, ordenação explícita.

## 2. Ferramentas

| Camada | Ferramenta | Observações |
|---|---|---|
| Backend unit/feature | **PHPUnit** (`php artisan test`, `--parallel` no CI) | **PostgreSQL 16 real** (ADR-002) com `unaccent`, `pg_trgm`; Redis não é necessário (`CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`) |
| Dados | Factories por model (`Database\Factories\<Module>\*`), estados nomeados (`->squareMeter()`, `->outOfStock()`, `->company()`), seeders de referência | factories nunca dependem do seed; testes de aceite usam o seed |
| HTTP externo | `Http::fake()` + `Http::preventStrayRequests()` | Mercado Pago, ViaCEP, HIBP (`uncompromised`) |
| Integrações | `FakePaymentGateway`, `FakeCarrier` / `FailingCarrier`, `FakePostalCodeLookup`, `NullWhatsAppClient` | §4 |
| Efeitos | `Mail::fake`, `Notification::fake`, `Event::fake([...])` (com cuidado: listeners síncronos fazem parte da consistência), `Queue::fake` para provar `afterCommit`, `Storage::fake('s3')` | |
| Arquitetura | Suite `tests/Architecture` (PHPUnit) | grafo de módulos (ADR-018), `$fillable`, sem `$request->all()`, rotas admin com permissão, inventário de rotas, rotas `dev` só em local/testing |
| Frontend | **Vitest** + **@testing-library/react** + `user-event` + **MSW** (node) + `jsdom` | handlers MSW derivados de API.md §4; `vitest --coverage` (v8) |
| E2E | **Playwright** (Chromium) + `@axe-core/playwright` | contra `docker compose` em `http://localhost:8080` |
| Estática | `pint --test`, ESLint (+ jsx-a11y, `react/no-danger`), `tsc --noEmit` | no CI |

## 3. Convenções

### 3.1 Pastas e nomes (backend)

```text
backend/tests/
├── TestCase.php                 # RefreshDatabase, preventStrayRequests, travelTo padrão, helpers
├── Concerns/                    # ActsAsCustomer, ActsAsAdmin(perms), BuildsCart, SignsWebhooks, RunsConcurrently
├── Fakes/                       # FakePaymentGateway, FakeCarrier, FailingCarrier, FakePostalCodeLookup
├── Unit/<Module>/               # sem HTTP; banco só quando a regra é SQL (ex.: InventoryService)
├── Feature/<Module>/            # HTTP + Postgres
├── Feature/Security/            # SECURITY §22 (um arquivo por grupo: IdorTest, MassAssignmentTest…)
├── Feature/Shipping/            # ShippingMatrixTest (SHIPPING §13) + ShippingScenariosTest
├── Feature/Concurrency/         # RC-* (grupo @group concurrency)
├── Feature/Acceptance/          # AcceptanceFlowTest (CA-001…019 sem browser)
└── Architecture/                # ModuleDependencyTest, FillableTest, AdminRoutesRequirePermissionTest, RouteInventoryTest
```

- Classe `<Assunto>Test`; métodos `test_<comportamento_em_snake_case>` ou `#[Test]`, com o
  **ID da matriz** no nome ou em `#[Group('SEC-IDOR-01')]` — ex.:
  `test_customer_cannot_view_other_customer_order` + `#[Group('SEC-IDOR-01')]`.
- Um arquivo por endpoint/caso de uso em Feature (`Feature/Checkout/PlaceCheckoutTest.php`).
- Arrange/Act/Assert explícitos; datasets com `#[DataProvider]` para matrizes (unidades,
  permissões, CEPs).
- Asserções de contrato: status **e** `code` **e** forma (`assertJsonStructure`) **e** efeito
  no banco (`assertDatabaseHas/Missing/Count`).

### 3.2 Banco por agente

- Cada agente roda com seu banco: `DB_DATABASE=ecommerce_test_<agente>` (ADR-017), criado
  por `docker/postgres/init.sql` (`ecommerce_test`, `_a`, `_b`, `_c`, `_d`, `_e`, `_qa`, `_sec`, `_rev`).
- `phpunit.xml` só define defaults (sem `force`), então a variável do shell vence:
  `DB_DATABASE=ecommerce_test_b php artisan test`.
- `RefreshDatabase` em todos os testes, **exceto** `Feature/Concurrency` (usa
  `DatabaseMigrations` + limpeza explícita, pois processos filhos não enxergam a transação
  do teste).
- `--parallel` cria `ecommerce_test_<agente>_<n>` automaticamente (usuário precisa de `CREATEDB`
  no ambiente de teste).

### 3.3 Frontend

- Testes ao lado do código: `src/features/<f>/**/X.test.tsx`; utilitários em `src/shared/**/x.test.ts`.
- `renderWithProviders()` (QueryClient sem retry, Router, tema, MSW) em `src/test/`.
- Consultas por papel/rótulo acessível (`getByRole`, `getByLabelText`); nada de seletor CSS.
- MSW: `src/test/handlers/*.ts` por feature, com respostas copiadas de API.md §4 e variantes
  de erro por `code` (`server.use(...)` no teste).

### 3.4 E2E

- `e2e/` na raiz: `playwright.config.ts`, `tests/acceptance.spec.ts`, `tests/admin-roles.spec.ts`,
  `fixtures/` (login por API, aprovação de pagamento via `POST /api/v1/dev/payments/{uuid}/approve`).
- Dados: `migrate:fresh --seed` antes da suíte; cada teste cria cliente novo (e-mail único).

## 4. Fakes e dados de teste

| Fake | Onde | Comportamento |
|---|---|---|
| `FakePaymentGateway` | `tests/Fakes` (binding em `TestCase`) | em memória; `approveNext()`, `failNextWith()`, `throwTimeout()`, `amountOverride()`; registra chamadas (assert "0 chamadas dentro de transação") |
| Driver `sandbox` | produção de código | usado em E2E e `AcceptanceFlowTest` com webhook **assinado** por `SANDBOX_WEBHOOK_SECRET` |
| `mercadopago` | produção de código | testado só com `Http::fake` (criação, consulta, estorno, assinatura `x-signature`) |
| `FakeCarrier` / `FailingCarrier` | `tests/Fakes` | modos `ok`, `timeout`, `error`, `slow(ms)`; preço/prazo determinísticos por CEP |
| `FakePostalCodeLookup` | `tests/Fakes` (implementa `Shared\Contracts\PostalCodeLookup`) | mapa fixo: `89010000`/`89012000`/`89015200` Blumenau 4202404; `89110000` Gaspar; `89130000` Indaial; `89107000` Pomerode; `89201000` Joinville; `88010000` Florianópolis; `01310100` São Paulo; `99999999` inexistente; modo `unavailable` |
| `SignsWebhooks` | `tests/Concerns` | monta headers `x-signature: ts=…,v1=…` e `x-request-id` válidos/inválidos/fora da janela |
| `RunsConcurrently` | `tests/Concerns` | dispara N processos `php artisan test:race <cenário>` com barreira (§6.5) |

Seed de referência (DATABASE §7): `VIN-BR-122-BR` R$ 15,90/m, 250 g/m, 500 m; entrega própria
Blumenau R$ 20,00 (1 dia), grátis ≥ R$ 500; regional Blumenau até 5 kg R$ 15; cliente PF
`maria@example.com` / PJ `compras@graficaexemplo.com.br` (tabela `reseller`); admin
`admin@example.com` (`super-admin`).

## 5. Como rodar (local e CI)

### 5.1 Local

```bash
docker compose up -d postgres redis mailpit            # infraestrutura
cd backend
composer install
DB_DATABASE=ecommerce_test_a php artisan test                       # tudo
DB_DATABASE=ecommerce_test_a php artisan test --testsuite=Unit
DB_DATABASE=ecommerce_test_a php artisan test tests/Feature/Security
DB_DATABASE=ecommerce_test_a php artisan test --group=SEC-IDOR-01
DB_DATABASE=ecommerce_test_qa php artisan test --group=concurrency  # RC-* (sem --parallel)
DB_DATABASE=ecommerce_test_a php artisan test --parallel --coverage --min=80

cd ../storefront && npm ci && npm run lint && npm run typecheck && npm run test -- --run --coverage
cd ../admin      && npm ci && npm run lint && npm run typecheck && npm run test -- --run --coverage

# E2E (stack completo)
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed --force
cd e2e && npm ci && npx playwright install chromium && npx playwright test
```

### 5.2 CI (`.github/workflows/ci.yml`)

| Job | Executa | Bloqueia merge |
|---|---|---|
| `backend` | Postgres 16 + Redis 7 como services; `composer audit`; `pint --test`; `php artisan test --parallel` (suites Unit, Feature, Architecture) com cobertura (Clover) e JUnit; depois `php artisan test --group=concurrency` sem paralelismo | sim |
| `storefront`, `admin` | `npm ci`, `npm audit --audit-level=high --omit=dev`, lint, typecheck, `vitest --run --coverage`, build | sim |
| `security` | gitleaks | sim |
| `e2e` | `needs` dos anteriores; `docker compose up` com builds; seed; Playwright; traces como artifact. Em `push` na `main`, `workflow_dispatch` e PR com label `e2e`; **obrigatório no gate da Onda 7** | no gate final |

## 6. Matriz obrigatória

Cada linha = ao menos um teste com o ID em `#[Group]`. "Dono" = agente da Onda 2/3 que
escreve o teste junto com a implementação; QA (Onda 4) completa o que faltar.

### 6.1 Unit

**Preço (`Unit/Pricing`, dono B-B)**

| ID | Caso | Esperado |
|---|---|---|
| UT-PRC-01 | Visitante, `VIN-BR-122-BR` 5 m | unit 1590, linha 7950, `base` |
| UT-PRC-02 | Faixa base: 10 m e 50 m | 1490 (`tier`) / 1390 (`tier`) |
| UT-PRC-03 | Faixa pela **soma da variante no carrinho** (2 linhas de 6 m) | ambas a 1490 (ADR-019) |
| UT-PRC-04 | Tabela `wholesale` do cliente e herdada da empresa | `price_list`; empresa vence default |
| UT-PRC-05 | `discount_bp` da tabela como fallback sem faixa | `round_half_up` |
| UT-PRC-06 | Preço promocional da variante dentro/fora da vigência | `variant_promo` / ignorado |
| UT-PRC-07 | Promoção por categoria (inclui descendentes), marca e produto; percent e fixed | `promotion`, nunca < 1 centavo |
| UT-PRC-08 | Preço de cliente/empresa com vigência | `customer_price` |
| UT-PRC-09 | Menor vence entre todos; empate → rótulo pela prioridade de RN-PRC-003 | conforme tabela |
| UT-PRC-10 | Visitante não recebe tabela/preço de cliente | `base`/`tier`/promo |
| UT-PRC-11 | Arredondamento de linha `round_half_up` (5,35 m × 1590 = 8506,5) | 8507 |
| UT-PRC-12 | Seed: promoção "Semana do Vinil" **não** altera `vinil-adesivo-branco-122m` | 5 m = 7950 |

**Metro quadrado (`Unit/Catalog`, dono B-B; kernel B-W1)**

| ID | Caso | Esperado |
|---|---|---|
| UT-M2-01 | 1,20 × 2,50 × 1 | área 3,000; R$ 90,00 (preço 3000) |
| UT-M2-02 | 1,20 × 2,50 × 3 | 9,000; R$ 270,00 |
| UT-M2-03 | Área por peça não inteira em milésimos (1,225 × 1,001 via painel 3 casas) | `round_half_up(w×h/1000)` por peça (ADR-019) |
| UT-M2-04 | Área mínima **por peça**: 0,40 × 0,50 × 1 e × 3 com mínimo 0,500 | faturada 0,500 / 1,500; `stock` 0,200 / 0,600; `minimumAreaApplied` |
| UT-M2-05 | Largura fixa (`fixed_width_mm` produto e override de variante `LON-FL-440-120`) | cliente informa só altura; largura diferente → 422 |
| UT-M2-06 | Faixas min/max de largura e altura; largura > máx com altura cabendo | 422 com sugestão de inverter |
| UT-M2-07 | Dimensão com 3 casas pelo cliente (1,205 m) | 422 (RN-QTD-034) |
| UT-M2-08 | `min/max_quantity`/`step` referem-se a **peças** | peças fora → 422 |

**Metro linear (`Unit/Catalog`)**

| ID | Caso | Esperado |
|---|---|---|
| UT-ML-01 | 5 m, step 0,1 | válido, 7950 |
| UT-ML-02 | 5,05 m, step 0,1 | 422 com `details.quantity.suggestions = [5, 5.1]` |
| UT-ML-03 | abaixo do mínimo, acima do máximo (50) | 422 |
| UT-ML-04 | step 0,5 (`VIN-TR-100`): 2,5 ok; 2,7 → sugestões 2,5/3,0 | |

**Quantidade (`Unit/Shared` + `Unit/Catalog`)**

| ID | Caso | Esperado |
|---|---|---|
| UT-QTY-01 | `Quantity::fromDecimal` "5.5" → 5500; "5,5"; 4 casas; notação científica; 0; negativo | milésimos / exceção |
| UT-QTY-02 | UNIT/ROLL/BOX só inteiros; `ILH-0-LAT` mínimo 10 step 10 | 15 → 422 |
| UT-QTY-03 | KG `TIN-PLA-BR` 0,5 step 0,5 | 1,5 ok; 1,2 → 422 |
| UT-MON-01 | `Money`: soma, subtração, `multiplyByQuantity`, `applyBasisPoints` half-up, `min` | inteiros, nunca float |

**Frete (`Unit/Shipping`, dono B-C)** — SHIPPING §13 T39–T47 (arredondamento `per_kg`,
percentual, mínimo, cubagem, logística por unidade) + `ZoneMatcher`, `RuleEvaluator`,
`DeliveryLabelFormatter`, `WeekendBusinessDayCalculator` (UT-SHP-01…08).

**Desconto (`Unit/Pricing`, dono B-B)**

| ID | Caso | Esperado |
|---|---|---|
| UT-DESC-01 | `percent` 10% com teto (`BEMVINDO10`, teto R$ 50) | half-up; limitado ao teto |
| UT-DESC-02 | `fixed` maior que subtotal | desconto = subtotal; total nunca negativo |
| UT-DESC-03 | `min_order_cents` (`DESC20` ≥ R$ 150): 149,99 × 150,00 | inválido / válido |
| UT-DESC-04 | vigência, inativo, soft-deleted, limite total e por cliente | `reasonCode` correspondente |
| UT-DESC-05 | `free_shipping` só em métodos `accepts_free_shipping_coupon` | frete 0 / inalterado |
| UT-DESC-06 | Rateio por linha, sobra de centavos na maior linha | soma = desconto |
| UT-DESC-07 | Base de frete grátis = subtotal após cupom | RN-FRT-003 |

**Estoque (`Unit/Inventory`, dono B-B — usa Postgres)**

| ID | Caso | Esperado |
|---|---|---|
| UT-EST-01 | `reserve` → `commit` → saldos | `reserved`/`on_hand` corretos; movimentos `reserve`, `out` |
| UT-EST-02 | `reserve` → `release` (idempotente 2×) | volta ao anterior; 1 movimento |
| UT-EST-03 | `commit` → `restock` | movimento `return` |
| UT-EST-04 | `reserve` acima do disponível (soma de linhas da mesma variante) | `InsufficientStock` com itens |
| UT-EST-05 | `adjust` com `expectedOnHand` divergente; `adjust` abaixo de `reserved` | 409 `stale_resource` / CHECK impede |
| UT-EST-06 | CHECKs do banco (`on_hand >= 0`, `reserved <= on_hand`) com UPDATE direto | exceção de banco |
| UT-EST-07 | `StockLow` ao cruzar o limiar | evento 1× |
| UT-SM-01 | `OrderStateMachine`: todas as transições válidas/ inválidas (ADR-008 + `cancelled → paid` só sistema) (dono B-D) | matriz completa |

### 6.2 Feature

| ID | Área | Casos mínimos | Dono |
|---|---|---|---|
| FT-AUTH-01…08 | **Cadastro** | PF válido 201 + sessão + `cart_merge`; PJ com CNPJ alfanumérico e IE/ISENTO; CPF/CNPJ inválido; duplicidade (mensagem por campo); termos desatualizados; `prohibited` (SEC-MA-01); senha comprometida (Http fake HIBP); 429 | B-A |
| FT-AUTH-10…16 | **Login** | ok (sessão regenerada, `GET /me`); credencial errada (mensagem neutra); `403 account_disabled`; merge do carrinho visitante (soma de linhas iguais); token inválido → relatório; logout invalida; admin login + `admin.fresh` | B-A (+ merge B-D) |
| FT-CART-01…12 | **Carrinho** | visitante cria (`X-Cart-Token` na resposta); adicionar UNIT/ML/M²/KG; mesma variante+dimensões soma; `PATCH`/`DELETE` item; limite 50 linhas; `insufficient_stock` 409; variante inativa → linha bloqueada; preço mudou → `last_seen` + acknowledge; cupom 200/422/`login_required`; cotação por CEP e por `address_uuid`; cotação invalida ao mudar carrinho | B-D |
| FT-CHK-01…12 | **Checkout** | preview 200 com `blocking[]`; 201 com PIX (`CheckoutResult`); replay 200 `replayed`; ordem de validação de API.md §3.E (um teste por passo 1–9); 503 gateway → pedido criado → retry mesma chave → PIX; carrinho convertido; cupom consumido; `accept_terms` | B-D |
| FT-ORD-01…12 | **Pedido** | lista/detalhe do cliente (sem ids internos); polling de status; painel: filtros, `status-counts`, detalhe com `allowed_transitions` por permissão; transições válidas (tracking obrigatório só `carrier`; retirada com nome+documento); inválida 409 com `allowed_transitions`; notas/rastreio por permissão; `reveal-document` auditado; recompra (RN-PED-040…045) | B-D |
| FT-PAY-01…10 | **Pagamento** | webhook sandbox assinado aprova (commit de estoque, `paid_at`, `estimated_delivery_date`, e-mail 1×); Mercado Pago via `Http::fake` (create/get/refund, assinatura); `getPayment` pending mantém; valor divergente; reconciliação cobre webhook perdido; expiração 30 min (`payment_expired`, release, cupom devolvido); `POST …/payment` gera novo PIX; pagamento tardio com/sem estoque (ADR-022); dev endpoints só local/testing | B-D |
| FT-CAN-01…08 | **Cancelamento** | cliente cancela `pending_payment` (release + cupom); cliente em `paid` → 409, usa `cancellation-request`; admin `pending_payment` com `orders.cancel_unpaid`; admin `paid`/`processing` com `orders.cancel_paid` → `return` + `payment_refunds` pendente → job → `refunded` + e-mail; falha definitiva de estorno → alerta financeiro, pedido segue `cancelled`; `shipped` → 409; dismiss da solicitação | B-D |
| FT-CAT/INV/PRC/SHP-ADM | Painel de catálogo, preços, estoque, frete | CRUDs de API.md §3.G com `stale_resource`, `resource_in_use`, auditoria | B-B / B-C |
| FT-REP-01…05 | Dashboard e relatórios | blocos por permissão; `summary` de cada relatório; CSV (BOM, `;`, fórmula) | B-E |

### 6.3 Frete (cenários do briefing → casos)

`tests/Feature/Shipping/ShippingMatrixTest` implementa **T01–T55 de SHIPPING §13** (dono
B-C, completado por QA-SHP-01). Mapa dos itens exigidos:

| ID | Cenário | Casos SHIPPING §13 | Verificação adicional |
|---|---|---|---|
| SH-01 | CEP válido | T01, T37 | `GET /postal-codes/89010000` 200 com IBGE 4202404 |
| SH-02 | CEP inválido | T02, T03 | 422 `errors.postal_code`; 404 `not_found` para CEP inexistente no lookup |
| SH-03 | Região sem cobertura | T04, T05, T11 | São Paulo → só retirada; sem retirada → `options: []` + mensagem |
| SH-04 | Peso no limite | T06 | 5000 g → regra "até 5 kg" |
| SH-05 | Peso acima do limite | T07, T08 | 5001 g → próxima faixa; > todas → `weight_above_limit` |
| SH-06 | Frete grátis | T09, T10, T25, T26 | 499,99 × 500,00; cupom `FRETEGRATIS` |
| SH-07 | Entrega própria | T12, e seed Blumenau R$ 20 | Gaspar 30, Indaial 35 |
| SH-08 | Retirada | T13, T14 | endereço das colunas `pickup_*`; `pickup_only` |
| SH-09 | Transportadora | T15–T20, T51 | timeout/erro não derrubam cotação |
| SH-10 | Múltiplas regras | T21, T24 | Joinville 7,2 kg = 2500 + 8 × 150 |
| SH-11 | Conflito | T22, T23 | especificidade; empate por id + warning no simulador |
| SH-12 | Checkout com frete divergente | T27–T33 | 409 `shipping_*` com nova cotação; aceite silencioso com mesmo preço; 422 com preço no corpo |

### 6.4 Segurança

`tests/Feature/Security/*` implementa **todos os IDs de SECURITY.md §22** (SEC-IDOR-01…09,
SEC-AUTH-01…16, SEC-MA-01…05, SEC-PRICE-01…10, SEC-SHIP-01…06, SEC-DISC-01…08,
SEC-IDEM-01…08, SEC-WH-01…10, SEC-CSRF-01, SEC-XSS-01…03, SEC-SQL-01, SEC-SORT-01,
SEC-UP-01/02, SEC-RL-01, SEC-LOG-01, SEC-HDR-01, SEC-AUD-01, SEC-HEALTH-01). Resumo dos itens
do briefing:

| Tema | IDs | Expectativa-chave (API.md) |
|---|---|---|
| IDOR | SEC-IDOR-01…09 | 404 `not_found` para recurso de outro cliente; nada muda |
| Autorização | SEC-AUTH-01…07, 14–16; `AdminPermissionMatrixTest` | 401 entre guards; 403 `forbidden` por permissão (API.md §6.3), inclusive por campo |
| Mass assignment | SEC-MA-01…05 | campos de API.md §1.7 → **422 `prohibited`, sem efeito**; desconhecidos ignorados |
| Manipulação de preço | SEC-PRICE-01…10 | 422 para campos de preço; `price_changed` 409 com `expected_total_cents` antigo |
| Manipulação de frete | SEC-SHIP-01…06 | 422 para preço de frete; 409 `shipping_*` |
| Manipulação de desconto | SEC-DISC-01…08 | 422 ao aplicar; **409 `coupon_invalid`** no checkout; 429 enumeração |
| Duplicação de pedido | SEC-IDEM-01…08 | replay 200 mesmo pedido; `idempotency_conflict` 409 |
| Replay de webhook | SEC-WH-01…10 | 401 corpo vazio; duplicado 200 `{"status":"ok"}` sem reprocessar |

### 6.5 Condições de corrida (Postgres)

Abordagem (`Feature/Concurrency`, `#[Group('concurrency')]`, dono QA com apoio de B-B/B-D):

1. O teste prepara os dados com `DatabaseMigrations` (commit real, sem transação envolvente).
2. `RunsConcurrently` inicia **N processos PHP** (`Symfony\Process`) executando um comando
   de teste (`php artisan test:race <cenário> --run-id=…`, registrado só em `testing`), cada
   um com sua **própria conexão** Postgres.
3. Barreira: todos aguardam `pg_advisory_lock` compartilhado liberado pelo pai (ou arquivo
   sinalizador) para começar juntos; cada processo grava o resultado (status HTTP/exceção) em
   JSON.
4. O pai espera todos (timeout 30 s) e verifica invariantes no banco.
5. Cada teste roda 20× no CI noturno (`--repeat`) para detectar flakiness; lock_timeout de 5 s.

| ID | Cenário | Invariante |
|---|---|---|
| RC-INV-01 | 10 processos reservam 1 unidade de variante com `on_hand = 5` | exatamente 5 sucessos, 5 `InsufficientStock`; `reserved = 5`; `reserved <= on_hand` |
| RC-INV-02 | Reservas cruzadas A+B e B+A (ordem inversa) | sem deadlock (locks ordenados por `variant_id`) |
| RC-CHK-01 | 2 checkouts HTTP de clientes diferentes pelo último item | 1 × 201, 1 × 409 `insufficient_stock` |
| RC-CHK-02 | Mesma `Idempotency-Key` 5× em paralelo | 1 pedido, 1 reserva, 1 pagamento |
| RC-CUP-01 | Cupom `usage_limit = 1`, 2 checkouts paralelos | 1 pedido com cupom, 1 × 409 `coupon_invalid` |
| RC-PAY-01 | Webhook de aprovação × job de expiração no mesmo pedido | estado final consistente (`paid` com `out` **ou** `cancelled` + reativação/estorno), nunca estoque comitado 2× |
| RC-PAY-02 | Mesmo webhook entregue 3× em paralelo | 1 `webhook_events`, 1 transição |
| RC-REF-01 | 2 cancelamentos de pedido pago em paralelo | 1 estorno ativo (índice parcial) |
| RC-ADJ-01 | Ajuste de estoque × reserva simultâneos | movimentos consistentes com saldo final |

### 6.6 Frontend (Vitest)

| ID | Caso |
|---|---|
| UT-FE-MONEY-01…04 | `formatBRL`, `parseBRLToCents("1.234,56")`, `toMilli("5,5")`, `formatQuantity` por unidade |
| FE-ST-T01 | Configurador ML: 5 m exibe total do servidor (MSW) R$ 79,50; 5,05 → erro com sugestões; botão desabilitado |
| FE-ST-T02 | Configurador m²: largura fixa, faixas, área mínima por peça exibida |
| FE-ST-T03 | Carrinho: 409 `insufficient_stock` ajusta stepper; aviso de preço alterado + acknowledge |
| FE-ST-T04 | Checkout: mesma `Idempotency-Key` em retries; nova após mudança; dialog por `code` (`price_changed`, `shipping_*`, `coupon_invalid`); 503 repete |
| FE-ST-T05 | Página PIX: polling para ao `paid`; contador |
| FE-ST-T06 | Cliente HTTP: 419 → renova CSRF e repete 1×; 401 limpa `['auth','me']` |
| FE-ST-T07 | `SafeHtml` remove `<script>`/`onerror`/`javascript:` (SEC-XSS-03) |
| FE-AD-T01 | `useCan`/menu: itens e botões ausentes sem permissão |
| FE-AD-T02 | Formulário de produto: 422 mapeado para campos de variantes (`variants.0.sku`); `stale_resource` |
| FE-AD-T03 | Transição de pedido: dialog de retirada exige nome e documento; tracking obrigatório em `carrier` |
| FE-AD-T04 | Simulador de frete renderiza trace (reasons, warnings) |

## 7. Cenário E2E de aceite

`e2e/tests/acceptance.spec.ts` (Playwright, stack docker com seed; espelho sem browser em
`Feature/Acceptance/AcceptanceFlowTest`). Critérios de BUSINESS_RULES §9 com números do seed:

| Passo | Ação | Verificação |
|---|---|---|
| 1 (CA-001) | Visitante busca "vinil branco" | produto `vinil-adesivo-branco-122m` com "R$ 15,90 /m" e "Em estoque" |
| 2 (CA-002) | Abre produto, informa 5 m; depois 5,05 | "Total R$ 79,50"; erro de passo com sugestões 5,00/5,10 e CTA desabilitado |
| 3 (CA-003) | CEP 89010-000 na página | "Retirada na empresa — Grátis", "Entrega própria — R$ 20,00 — 1 dia útil" |
| 4 (CA-004) | Adiciona ao carrinho | 1 linha, 5,00 m, subtotal R$ 79,50, peso 1,25 kg; `cv_cart_token` em localStorage |
| 5 (CA-005) | Finalizar → cadastro PF (CPF válido; antes, CPF inválido rejeitado) | volta ao checkout com carrinho mesclado |
| 6 (CA-006) | Endereço CEP 89010-000 | Blumenau/SC, IBGE 4202404; endereço padrão |
| 7 (CA-007) | Entrega própria + PIX | Revisão: subtotal R$ 79,50, desconto R$ 0,00, frete R$ 20,00, **total R$ 99,50** |
| 8 (CA-008) | Duplo clique em "Finalizar pedido" | **um** pedido `CV-…`, `pending_payment`/`pending`, `total_cents = 9950`; carrinho vazio (verificado via API do admin) |
| 9 (CA-009/010) | Tela PIX | QR, copia-e-cola, R$ 99,50, contador 30 min; estoque `on_hand 500`, `reserved 5`; e-mail no Mailpit |
| 10 (CA-011) | `POST /api/v1/dev/payments/{uuid}/approve` (ou botão DEV); reenviar o mesmo webhook | página vira "Pagamento confirmado"; `on_hand 495`, `reserved 0`; reenvio sem novo movimento |
| 11 (CA-012/013) | Admin `warehouse`: separação → enviado → entregue | status e histórico com operador; e-mails ao cliente |
| 12 (CA-014) | Admin `seller` tenta `shipped`; transição inválida | 403 / 409 |
| 13 (CA-015/016) | Cliente vê timeline completa; "Comprar novamente" | 5,00 m de `VIN-BR-122-BR` no carrinho a preço atual |
| 14 (CA-017) | Outro cliente acessa o uuid | 404 |
| 15 (CA-018) | Relatório de vendas do dia (`finance`) | R$ 99,50, 1 pedido, ticket R$ 99,50; `VIN-BR-122-BR` 5,000 m |
| 16 (CA-019) | Variante: pedido não pago + expiração (`travel` no feature test; no E2E, `php artisan orders:expire-pending` após ajustar `expires_at` via comando de teste) | `cancelled` (`payment_expired`), `reserved` liberado, e-mail com "Comprar novamente" |

Complementos: axe sem violações sérias em home, produto, carrinho, checkout, PIX, lista de
pedidos do admin; headers de segurança (SEC-HDR-01) em `/`, `/admin/` e `/api/v1/settings/public`.

## 8. Metas de cobertura

| Escopo | Meta (linhas) | Observação |
|---|---|---|
| `app/Shared/Domain`, `Pricing`, `Inventory`, `Shipping` (engine/handlers), `Orders` (state machine), `Checkout` | **≥ 90 %** | regras de dinheiro/estoque/frete |
| Backend geral (`app/`) | **≥ 80 %** | `--min=80` no CI a partir da Onda 4 |
| Storefront `features/{product,cart,checkout}` e `shared/` | **≥ 70 %** | |
| Admin `features/{orders,products,shipping}` e `shared/` | **≥ 60 %** | |
| Matrizes | **100 % dos IDs** de §6 presentes (checagem por `--group` em script `scripts/check-test-ids`) | cobertura de requisitos > cobertura de linhas |

## 9. Checklist de revisão de testes

- [ ] O teste referencia o ID da matriz (`#[Group]`).
- [ ] Assert de status **e** `code` **e** efeito no banco (ou "sem efeito").
- [ ] Nenhuma chamada de rede real; relógio controlado.
- [ ] Dinheiro/quantidade comparados como inteiros (centavos/milésimos) ou strings decimais, nunca float.
- [ ] Caso de permissão negativa para toda rota admin nova.
- [ ] Recurso de outro cliente → 404 para toda rota `/me` nova.
- [ ] Teste de concorrência para qualquer novo caminho que altere `inventory`, `coupons`, `payments`.
- [ ] Handlers MSW atualizados quando API.md muda (e vice-versa).
