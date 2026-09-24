# API REST — Contrato HTTP (`/api/v1`)

> Documento do **Agent 4 — Backend Engineer (lead)**. É o **contrato** que backend, storefront
> e admin implementam **em paralelo e de forma independente**. Precisão > prosa: se algo
> aqui estiver ambíguo, trate como bug deste documento e registre no ADR log.
>
> Precedência (em caso de conflito): `DECISIONS.md` (Rodada 2 — ADR-018+ prevalece sobre
> tudo) → `ARCHITECTURE.md` → `DATABASE.md` → `SHIPPING.md` → `BUSINESS_RULES.md` →
> `SECURITY.md` → `UX.md`. Divergências encontradas e as decisões tomadas aqui estão na
> **seção 8** (candidatas a ADR).
>
> Idioma: texto em pt-BR; paths, campos JSON, enums e identificadores em inglês.

## Sumário

1. [Convenções](#1-convenções)
2. [Tipos compartilhados (TypeScript)](#2-tipos-compartilhados-typescript)
3. [Catálogo de endpoints](#3-catálogo-de-endpoints)
   - 3.A [Loja pública](#3a-loja-pública)
   - 3.B [Carrinho](#3b-carrinho)
   - 3.C [Autenticação do cliente](#3c-autenticação-do-cliente)
   - 3.D [Área do cliente `/me`](#3d-área-do-cliente-me)
   - 3.E [Checkout](#3e-checkout)
   - 3.F [Webhooks e endpoints de desenvolvimento](#3f-webhooks-e-endpoints-de-desenvolvimento)
   - 3.G [Painel `/admin`](#3g-painel-admin)
4. [Exemplos completos](#4-exemplos-completos)
5. [Mapa endpoint → módulo → controller](#5-mapa-endpoint--módulo--controller)
6. [Permissões do painel](#6-permissões-do-painel)
7. [Chaves de query (TanStack Query)](#7-chaves-de-query-tanstack-query)
8. [Decisões deste documento e divergências entre docs](#8-decisões-deste-documento-e-divergências-entre-docs)

---

## 1. Convenções

### 1.1 Base, grupos de rota e guards

| Grupo | Prefixo | Middleware (resumo) | Guard |
|---|---|---|---|
| Health | `/api/health`, `/api/health/live` | `throttle:health` | — |
| Loja pública | `/api/v1` | `api` (sessão stateful, CSRF), throttle por rota | `customer` **opcional** (se houver sessão, preço é resolvido para o cliente) |
| Carrinho | `/api/v1/cart` | `api`, `throttle:cart` | `customer` opcional + `X-Cart-Token` |
| Auth do cliente | `/api/v1/auth` | `api`, throttles de login/registro/reset | — |
| Cliente autenticado | `/api/v1/me` | `api`, `auth:customer`, `throttle:customer` | `customer` |
| Checkout | `/api/v1/checkout` | `api`, `auth:customer`, `throttle:checkout` | `customer` |
| Webhooks | `/api/v1/webhooks/{provider}` | `webhook` (**sem sessão, sem CSRF**), `throttle:webhooks` | — (HMAC) |
| Dev (só `local`/`testing`) | `/api/v1/dev` | `api`; rotas **não registradas** fora de `local`/`testing` | — |
| Painel (visitante) | `/api/v1/admin/auth/*` | `api`, throttles de login/reset | — |
| Painel | `/api/v1/admin` | `api`, `auth:admin`, `admin.fresh`, `throttle:admin`, `permission:<perm>,admin` | `admin` |
| SEO (não-JSON) | `/sitemap.xml`, `/robots.txt`, shell | `seo` (sem sessão) | — |

- Todos os paths deste documento são relativos a `/api/v1`, salvo quando começam com
  `/api/health`, `/sanctum`, `/sitemap.xml`, `/robots.txt` ou forem o shell SEO.
- Rotas nomeadas `store.*`, `cart.*`, `auth.*`, `customer.*`, `checkout.*`, `webhooks.*`,
  `dev.*`, `admin.*` (ex.: `store.products.show`, `admin.orders.transitions.store`).
- **Rotas do cliente usam identificadores públicos**: pedidos por `uuid`, endereços por
  `uuid`, pagamentos por `uuid`. Itens de carrinho por `id` (sempre buscados via
  `$cart->items()`, SECURITY §5). Catálogo público por `slug` (produto/categoria/marca)
  e variantes por `id` (dado público de catálogo).
- **Rotas do painel usam `id` inteiro** (DATABASE §1.1) — inclusive pedidos
  (`/admin/orders/{id}`); a busca por número é filtro (`?q=CV-000123`).

### 1.2 Autenticação (Sanctum stateful, cookies)

Mesma origem em produção (loja em `/`, painel em `/admin`, API em `/api`). Em dev o Vite
faz proxy de `/api` e `/sanctum` (ARCHITECTURE §8.10). **Não há tokens Bearer.**

Fluxo obrigatório (as duas SPAs):

1. `GET /sanctum/csrf-cookie` → `204`, define cookie `XSRF-TOKEN` (legível por JS) e o
   cookie de sessão `cv_session` (`HttpOnly`, `SameSite=Lax`, `Secure` em produção).
2. Toda requisição usa credenciais: axios `withCredentials: true, withXSRFToken: true`;
   `fetch(url, { credentials: 'include' })` + header `X-XSRF-TOKEN` = valor
   URL-decodificado do cookie `XSRF-TOKEN`.
3. Login: `POST /auth/login` (loja) ou `POST /admin/auth/login` (painel). A sessão é
   regenerada; chame `GET /me` / `GET /admin/me` para hidratar o estado.
4. Toda requisição **mutável** (`POST/PUT/PATCH/DELETE`) exige `X-XSRF-TOKEN`; sem ele →
   `419 csrf_token_mismatch`. O cliente HTTP, ao receber 419, chama `/sanctum/csrf-cookie`
   e repete **uma** vez.
5. Logout: `POST /auth/logout` ou `POST /admin/auth/logout` → `204`; sessão invalidada e
   token CSRF regenerado. Após login/logout a SPA faz `queryClient.clear()`.

Guards:

- `customer` (tabela `customers`) e `admin` (tabela `admin_users`) compartilham o **mesmo
  cookie** de sessão (ADR-023), mas são independentes: sessão de cliente **não** acessa
  `/admin/*` (401) e sessão de admin **não** acessa `/me/*` (401).
- Sessão admin: inatividade 30 min / absoluto 8 h (`admin.fresh`) → `401 admin_session_expired`.
  Sessão cliente: inatividade 120 min.
- Conta bloqueada/desativada (`customers.is_active=false`, `admin_users.is_active=false`):
  login → `403 account_disabled`; sessão existente → `401 unauthenticated` no próximo request.

### 1.3 Headers

**Requisição**

| Header | Onde | Regra |
|---|---|---|
| `Accept: application/json` | todas | Obrigatório (a API força JSON de qualquer forma). |
| `Content-Type: application/json` | corpo JSON | Uploads usam `multipart/form-data`. |
| `X-XSRF-TOKEN` | mutáveis com sessão | Ver 1.2. Webhooks não usam. |
| `X-Cart-Token` | somente `/cart/*`, `POST /auth/login`, `POST /auth/register` | UUID do carrinho de **visitante** (guardado em `localStorage` `cv_cart_token`). Com cliente logado é **ignorado** em `/cart/*` (vale o carrinho da sessão); em login/registro é usado para o merge. `POST /shipping/quote` (produto) não usa carrinho. |
| `Idempotency-Key` | `POST /checkout` (obrigatório); `POST /me/orders/{uuid}/payment` (opcional) | UUID (v4 ou v7). Ausente/inválido no checkout → `422` (`errors.idempotency_key`). |
| `X-Request-Id` | opcional em todas | Aceito só se for UUID/ULID válido; senão o servidor gera um. |

**Resposta**

| Header | Quando |
|---|---|
| `X-Request-Id` | Sempre. Exibir no toast de erro 5xx ("Código do erro: …"). |
| `X-Cart-Token` | Quando um carrinho de visitante é **criado** na requisição (também vem em `data.token`). |
| `Retry-After` | `429` e `503` (segundos). |
| `Cache-Control: no-store` | `/me/*`, `/auth/*`, `/checkout*`, `/cart*`, `/admin/*`. Catálogo: `private, no-cache` + `Vary: Cookie` (preço depende do cliente). |
| `Content-Disposition: attachment` | Exports CSV e `GET /me/data-export`. |

### 1.4 Envelope, paginação, filtros e ordenação

**Envelope.** Todo sucesso JSON (exceto `204`) vem em `{"data": ...}` (API Resources do
Laravel). Chaves adicionais de topo só onde indicado (`links`, `meta`, `facets`, `search`).

**Paginação (formato padrão Laravel).** Toda coleção paginada responde:

```json
{
  "data": [],
  "links": { "first": "…?page=1", "last": "…?page=5", "prev": null, "next": "…?page=2" },
  "meta": {
    "current_page": 1, "from": 1, "last_page": 5, "path": "https://…/api/v1/products",
    "per_page": 24, "to": 24, "total": 118,
    "links": [ { "url": null, "label": "&laquo; Anterior", "active": false } ]
  }
}
```

```ts
export interface Paginated<T> {
  data: T[];
  links: { first: string; last: string; prev: string | null; next: string | null };
  meta: {
    current_page: number; from: number | null; last_page: number; path: string;
    per_page: number; to: number | null; total: number;
    links: { url: string | null; label: string; active: boolean }[];
  };
}
```

- Query: `page` (≥ 1, padrão 1), `per_page` (1–100; padrão **24** na loja, **10** em
  `/me/orders`, **25** no painel). `per_page > 100` → `422`.

**Filtros — convenção única: parâmetros simples em `snake_case`** (não usamos `filter[...]`).

| Tipo | Formato | Exemplo |
|---|---|---|
| Texto de busca | `q` (2–100 caracteres; loja) / (1–100; painel) | `q=vinil branco` |
| Lista (multi-valor) | valores separados por vírgula | `status=paid,processing` · `brand=vinilsul,imprimax` |
| Booleano | `1` ou `0` (aceita também `true`/`false`) | `in_stock=1` |
| Faixa de dinheiro | `<campo>_min_cents`, `<campo>_max_cents` (inteiros, inclusivos) | `price_min_cents=1000&price_max_cents=5000` |
| Faixa de datas | `date_from`, `date_to` = `YYYY-MM-DD` **inclusivos**, interpretados em `America/Sao_Paulo` | `date_from=2026-09-01&date_to=2026-09-30` |
| Id relacionado | `<entidade>_id` | `customer_id=12` |

- Filtros desconhecidos são ignorados; valores inválidos em filtros conhecidos → `422`.

**Ordenação — convenção única:** `sort=<token>`; prefixo `-` = decrescente. Cada endpoint
declara a allowlist; token fora da allowlist → `422` (SEC-SORT-01). Tokens especiais sem
direção: `relevance`, `best_selling`.

### 1.5 Tipos de dados no fio

| Grandeza | Na API | Regra |
|---|---|---|
| Dinheiro | inteiro em **centavos**, campo `*_cents` | Nunca float. Frontend só divide por 100 para exibir. |
| Percentual | inteiro em basis points, campo `*_bp` | `1000` = 10,00 %. |
| Quantidade (venda/estoque/faturada) | **número JSON** com até **3 casas** (`5`, `5.5`, `2.35`, `0.5`) | Requisições aceitam número **ou** string numérica (`"5.5"`); regex efetiva `^\d{1,6}(\.\d{1,3})?$`; > 3 casas, notação científica, `0`, negativo → `422`. Backend converte via string para milésimos (`Quantity`). |
| Dimensões de material vendável | **metros**, número com até 3 casas: `width_m`, `height_m`, `fixed_width_m`, `min_width_m`… | Backend converte para mm inteiros. **Entrada do cliente** (`width_m`/`height_m` no carrinho/prévia): no máximo **2 casas** (múltiplo de 1 cm — RN-QTD-034), `0.01`–`100`. Painel pode cadastrar limites com 3 casas (mm). |
| Área | m², número com 3 casas: `area_m2`, `piece_area_m2`, `min_billable_area_m2` | Calculada no backend (ADR-019). |
| Peso | inteiro em **gramas**: `weight_grams` | Único campo de peso na API. |
| Dimensões de embalagem | cm, número com 1 casa: `package_length_cm`… | Só no painel. |
| Volume | inteiro `*_cm3` | Só no painel/simulador. |
| Datas-hora | ISO-8601 **UTC** com `Z`: `"2026-09-24T13:00:00Z"` | Frontend exibe em `America/Sao_Paulo`. |
| Datas | `"YYYY-MM-DD"` | Ex.: `estimated_delivery_date`, filtros. |
| CEP | resposta: 8 dígitos `"89010000"`; requisição aceita `"89010-000"` ou `"89010000"` | |
| CPF / CNPJ | resposta: só dígitos/alfanumérico maiúsculo (ou mascarado quando indicado); requisição aceita com ou sem máscara | CNPJ alfanumérico aceito (`^[0-9A-Z]{12}[0-9]{2}$`, ADR-026a). |
| Telefone | só dígitos, DDD + número (10–11 dígitos) | Requisição aceita máscara. |
| Enums | **exatamente como no banco** | `sale_unit` em MAIÚSCULAS (`LINEAR_METER`); status/tipos em `snake_case` minúsculo (`pending_payment`, `own_delivery`). |
| E-mail | minúsculo | Normalizado na escrita. |
| Cupom / SKU | MAIÚSCULO | Normalizado na escrita (`trim` + `upper`). |

### 1.6 Erros

**422 — validação** (formato padrão Laravel; mensagens em pt-BR prontas para exibição):

```json
{
  "message": "Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.",
  "errors": { "quantity": ["Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m."] },
  "details": { "quantity": { "suggestions": [5, 5.1] } }
}
```

- `errors`: chaves com notação de ponto (`company.cnpj`, `variants.0.sku`, `items.2.quantity`).
- `details` (opcional): dados estruturados por campo. Hoje só `suggestions` (quantidade
  fora do passo, RN-QTD-010).
- `code` (opcional) em 422 só nos casos marcados (ex.: `cart_invalid`).

**Demais erros** (ADR-020): `{"message": "…", "code": "snake_case", ...extras}`.

| HTTP | `code` | Quando | Extras no corpo |
|---|---|---|---|
| 401 | `unauthenticated` | Sem sessão do guard exigido | — |
| 401 | `admin_session_expired` | Sessão admin ociosa/absoluta expirada | — |
| 403 | `forbidden` | Sem permissão (painel) ou ação negada por policy | — |
| 403 | `account_disabled` | Login de conta bloqueada/desativada | — |
| 404 | `not_found` | Recurso inexistente **ou de outro cliente** (não revela existência) | — |
| 404 | `cart_not_found` | `X-Cart-Token` desconhecido, expirado, convertido ou de carrinho com dono | — |
| 409 | `insufficient_stock` | Estoque insuficiente (carrinho, checkout, recompra) | `items: StockIssue[]` |
| 409 | `price_changed` | Total recalculado ≠ `expected_total_cents` | `summary: CheckoutSummary` |
| 409 | `shipping_quote_expired` | Cotação expirada (SHIPPING §7) | `shipping_quote: ShippingQuote` |
| 409 | `shipping_option_unavailable` | Opção sumiu na recotação | `shipping_quote` |
| 409 | `shipping_quote_invalid` · `shipping_quote_changed` · `shipping_postal_code_changed` · `shipping_option_invalid` · `shipping_price_changed` | Demais divergências de frete (SHIPPING §7) | `shipping_quote` |
| 409 | `coupon_invalid` | Cupom do carrinho deixou de valer no checkout | `coupon: CouponIssue`, `summary: CheckoutSummary` |
| 409 | `idempotency_conflict` | Mesma `Idempotency-Key` com corpo diferente | `order: {uuid, number}` |
| 409 | `invalid_status_transition` | Transição fora da máquina de estados / mesmo status / pedido não cancelável | `allowed_transitions: OrderStatus[]` |
| 409 | `cart_empty` | Checkout com carrinho vazio | — |
| 409 | `too_many_pending_orders` | Já há 3 pedidos `pending_payment` do cliente | `pending_orders: {uuid, number}[]` |
| 409 | `resource_in_use` | Exclusão/desativação bloqueada por dependências (categoria com filhos, zona usada por regra…) | `blockers: {type: string; id: number; label: string}[]` |
| 409 | `stale_resource` | `expected_updated_at` diferente do atual (edição concorrente no painel) | `current_updated_at` |
| 413 | `payload_too_large` | Corpo/arquivo acima do limite | — |
| 419 | `csrf_token_mismatch` | CSRF ausente/expirado | — |
| 422 | (sem `code`) | Validação | `errors`, `details?` |
| 422 | `cart_invalid` | Checkout com item indisponível/inativo/regra inválida | `errors.cart`, `items: CartItemIssue[]` |
| 429 | `too_many_requests` | Rate limit | header `Retry-After` |
| 500 | `server_error` | Erro inesperado (mensagem genérica) | — |
| 503 | `payment_gateway_unavailable` | Gateway falhou ao criar o PIX (pedido **já** criado) | `order: {uuid, number}` |
| 503 | `postal_code_lookup_unavailable` | ViaCEP fora e sem fallback suficiente | — |
| 503 | `service_unavailable` | Manutenção / health check falho | — |

Regras para o frontend:

- Trate por `code`, nunca por `message`. Qualquer `code` iniciado por `shipping_` ⇒
  substituir a cotação local pela `shipping_quote` retornada e pedir nova escolha.
- `details`, `items`, `summary`, `shipping_quote` são opcionais por natureza; use-os quando presentes.

```ts
export interface ValidationErrorBody {
  message: string;
  errors: Record<string, string[]>;
  details?: Record<string, { suggestions?: number[] }>;
  code?: 'cart_invalid';
  items?: CartItemIssue[];
}
export interface ApiErrorBody {
  message: string;
  code: ErrorCode;
  [extra: string]: unknown;
}
export type ErrorCode =
  | 'unauthenticated' | 'admin_session_expired' | 'forbidden' | 'account_disabled'
  | 'not_found' | 'cart_not_found' | 'insufficient_stock' | 'price_changed'
  | 'shipping_quote_expired' | 'shipping_option_unavailable' | 'shipping_quote_invalid'
  | 'shipping_quote_changed' | 'shipping_postal_code_changed' | 'shipping_option_invalid'
  | 'shipping_price_changed' | 'coupon_invalid' | 'idempotency_conflict'
  | 'invalid_status_transition' | 'cart_empty' | 'cart_invalid' | 'too_many_pending_orders'
  | 'resource_in_use' | 'stale_resource' | 'payload_too_large' | 'csrf_token_mismatch'
  | 'too_many_requests' | 'server_error' | 'payment_gateway_unavailable'
  | 'postal_code_lookup_unavailable' | 'service_unavailable';
```

### 1.7 Campos proibidos (ADR-012) — decisão: **rejeitar com 422**

Para tornar os testes de segurança determinísticos, **nos endpoints da loja, do carrinho,
da autenticação, de `/me` e do checkout** os campos abaixo são declarados com a regra
`prohibited`: se presentes (mesmo `null`), a resposta é `422` com
`errors.<campo> = ["O campo <campo> não é permitido."]` e **nada** é gravado.

```text
price, price_cents, unit_price_cents, base_unit_price_cents, line_total_cents,
subtotal_cents, total_cents, discount, discount_cents, shipping_price, shipping_cents,
shipping_price_cents, shipping_discount_cents, customer_id, company_id, status,
payment_status, price_list_id, is_active, email_verified_at, roles, permissions,
items (no checkout — itens vêm do carrinho), uuid, id (no corpo)
```

- `SEC-MA-01/02`, `SEC-PRICE-01/02`, `SEC-SHIP-01`, `SEC-DISC-01`, `SEC-MA-05` passam a
  esperar **422 + nenhum efeito** (antes: "ignorado").
- Qualquer **outro** campo desconhecido é **ignorado** (não entra em `validated()`).
- **Painel:** campos calculados/derivados são `prohibited` onde listado em cada endpoint
  (ex.: `on_hand`/`reserved` em produto, `times_used` em cupom, `status` em `PATCH` de
  pedido); demais desconhecidos são ignorados (SEC-MA-03).
- `expected_total_cents` no checkout **não** é proibido: é comparado e nunca usado como valor.

### 1.8 Rate limits

Chave: `customer:{id}` / `admin:{id}` quando autenticado, senão IP real. Excesso → `429
too_many_requests` + `Retry-After`.

| Limiter | Aplicado a | Limite |
|---|---|---|
| `login` | `POST /auth/login`, `POST /admin/auth/login` | 5/min por IP+e-mail **e** 20/min por IP |
| `register` | `POST /auth/register` | 5/min e 20/h por IP |
| `password-reset` | forgot/reset (loja e painel), reenvio de verificação | 5/min por IP+e-mail |
| `catalog` | `GET` de settings/public, pages, categories, brands, products (sem `q`), related | 120/min |
| `search` | `GET /products?q=…`, `GET /products/autocomplete` | 60/min |
| `price-preview` | `POST /products/{slug}/price-preview` | 60/min |
| `shipping-estimate` | `POST /shipping/quote` (produto) | 30/min |
| `shipping-quote` | `POST /cart/shipping-quote` | 10/min |
| `postal-code` | `GET /postal-codes/{cep}` | 20/min |
| `cart` | `GET/POST/PATCH/DELETE /cart*` (exceto cupom e frete) | 60/min |
| `coupon` | `PUT /cart/coupon` | 10/min e 30/h |
| `customer` | demais `/me/*`, `POST /checkout/preview` | 60/min |
| `checkout` | `POST /checkout`, `POST /me/orders/{uuid}/payment` | 5/min e 30/h |
| `admin` | `/admin/*` | 300/min |
| `admin-heavy` | relatórios, exports CSV, simulador de frete, dashboard | 10/min (dashboard: 30/min) |
| `uploads` | uploads de imagem/logo | 30/min |
| `webhooks` | `/webhooks/*` | 300/min por IP |
| `health` | `/api/health*` | 60/min |
| `seo` | shell, sitemap, robots | 300/min |

### 1.9 Idempotência e concorrência

- `POST /checkout`: `Idempotency-Key` obrigatório; única por cliente. Mesma chave + mesmo
  corpo → `200` com o mesmo pedido (`replayed: true`); corpo diferente →
  `409 idempotency_conflict`. **Fingerprint** = `sha256` do JSON canônico de
  `{address_uuid, shipping_quote_id, shipping_option_id, payment_method, expected_total_cents, notes}`
  (conteúdo do carrinho **não** entra: após o pedido o carrinho é convertido — EC-011).
  O frontend gera **uma** chave por tentativa de confirmação e a reutiliza em retries;
  gera nova chave se carrinho/endereço/frete mudarem (UX §4.6.6).
- `POST /me/orders/{uuid}/payment`: naturalmente idempotente (no máximo um pagamento
  ativo por pedido — índice `payments_order_active_unique`); `Idempotency-Key` opcional.
- Webhooks: dedupe por `(provider, external_id)` com assinatura válida.
- Painel: `PATCH` aceita `expected_updated_at` opcional (ISO-8601) em produtos, categorias,
  marcas, promoções, cupons, métodos/zonas/regras de frete e settings; divergente →
  `409 stale_resource`. Transições de pedido não precisam (a máquina de estados já
  rejeita com `409 invalid_status_transition`).

### 1.10 Contexto de preço

Endpoints públicos de catálogo, prévia de preço, carrinho e frete resolvem preço **para o
cliente da sessão** (se houver sessão `customer`), senão para visitante (base, faixas,
promoções — RN-PRC-006). O frontend **nunca** calcula preço: pode pré-visualizar com
aritmética inteira, mas **exibe sempre o valor do servidor**.

---
## 2. Tipos compartilhados (TypeScript)

Copiáveis para `src/shared/api/types.ts` (storefront) e `src/shared/api/types.ts` (admin).
`T | null` = campo **sempre presente**, podendo ser `null`. `campo?:` = pode estar ausente
(indicado explicitamente). Recursos do cliente **não** expõem `id` interno de pedidos,
pagamentos, endereços ou clientes.

### 2.1 Primitivos e enums

```ts
export type Cents = number;          // inteiro, centavos BRL
export type BasisPoints = number;    // inteiro, 1000 = 10%
export type Decimal3 = number;       // número com até 3 casas decimais
export type Grams = number;          // inteiro
export type ISODateTime = string;    // "2026-09-24T13:00:00Z" (UTC)
export type ISODate = string;        // "2026-09-24"
export type UUID = string;

export type SaleUnit = 'UNIT' | 'LINEAR_METER' | 'SQUARE_METER' | 'ROLL' | 'KG' | 'BOX';
export type PriceSource =
  | 'base' | 'tier' | 'price_list' | 'variant_promo' | 'promotion' | 'customer_price';
export type AvailabilityStatus = 'in_stock' | 'low_stock' | 'out_of_stock';
export type CustomerType = 'individual' | 'company';
export type OrderStatus =
  | 'pending_payment' | 'paid' | 'processing' | 'shipped' | 'delivered'
  | 'ready_for_pickup' | 'picked_up' | 'cancelled';
export type OrderPaymentStatus = 'pending' | 'approved' | 'failed' | 'refunded' | 'expired';
export type PaymentRecordStatus =
  | 'pending' | 'approved' | 'failed' | 'expired' | 'cancelled' | 'refunded' | 'partially_refunded';
export type PaymentMethod = 'pix' | 'credit_card' | 'boleto' | 'invoice'; // MVP aceita só 'pix'
export type PaymentProvider = 'sandbox' | 'mercadopago';
export type CancelReasonCode = 'payment_expired' | 'customer' | 'admin' | 'payment_failed';
export type ShippingMethodType = 'pickup' | 'own_delivery' | 'table_rate' | 'carrier';
export type ShippingPriceType = 'fixed' | 'per_kg' | 'fixed_plus_per_kg' | 'percentage_of_subtotal' | 'free';
export type WeightBasis = 'real' | 'chargeable';
export type FreeShippingReason = 'rule' | 'coupon';
export type InventoryMovementType = 'in' | 'out' | 'reserve' | 'release' | 'return' | 'adjust';
export type CouponType = 'percent' | 'fixed' | 'free_shipping';
export type PromotionDiscountType = 'percent' | 'fixed';
export type PromotionScope = 'all' | 'targeted';
export type PriceListKind = 'retail' | 'wholesale' | 'reseller' | 'custom';
export type ActorType = 'admin' | 'customer' | 'system';
export type UF =
  | 'AC' | 'AL' | 'AP' | 'AM' | 'BA' | 'CE' | 'DF' | 'ES' | 'GO' | 'MA' | 'MT' | 'MS' | 'MG' | 'PA'
  | 'PB' | 'PR' | 'PE' | 'PI' | 'RJ' | 'RN' | 'RS' | 'RO' | 'RR' | 'SC' | 'SP' | 'SE' | 'TO';
```

Rótulos fixos (o backend também devolve `*_label` onde indicado; os frontends podem usar
esta tabela como fallback):

| `sale_unit` | `sale_unit_label` | `sale_unit_abbr` | unidade de estoque |
|---|---|---|---|
| `UNIT` | Unidade | `un` | un |
| `LINEAR_METER` | Metro linear | `m` | m |
| `SQUARE_METER` | Metro quadrado | `m²` | m² |
| `ROLL` | Rolo | `rolo` | rolo |
| `KG` | Quilograma | `kg` | kg |
| `BOX` | Caixa | `cx` | cx |

| `price_source` | `price_source_label` (loja) |
|---|---|
| `base` | `null` |
| `tier` | "Preço por quantidade" |
| `price_list` | "Preço {nome da tabela}" (ex.: "Preço Atacado") |
| `variant_promo` | "Promoção" |
| `promotion` | nome da promoção (ex.: "Semana do Vinil") |
| `customer_price` | "Seu preço" |

### 2.2 Referências e mídia

```ts
export interface CategoryRef { id: number; name: string; slug: string; url_path: string } // url_path = "/vinis"
export interface BrandRef { id: number; name: string; slug: string }
export interface Breadcrumb { name: string; url_path: string | null } // último item: url_path null

export interface ImageUrls { w300: string; w800: string; w1600: string } // WebP
export interface ProductImage {
  id: number;
  urls: ImageUrls | null;       // null enquanto o job ProcessProductImage não terminou
  alt: string;                  // nunca vazio (fallback = nome do produto)
  width: number | null;         // px da maior versão; null = processando
  height: number | null;
  position: number;             // 0 = capa
  variant_id: number | null;
}

export interface Seo {
  title: string;
  description: string;
  canonical_path: string;       // "/vinis/vinil-adesivo-branco-122m"
  canonical_url: string;        // absoluto (APP_URL + canonical_path)
  robots: 'index,follow' | 'noindex,follow' | 'noindex,nofollow';
  og_image_url: string | null;
  json_ld: Record<string, unknown>[]; // Product e/ou BreadcrumbList, prontos para <script type="application/ld+json">
}
```

### 2.3 Catálogo

```ts
export interface SaleUnitRules {
  sale_unit: SaleUnit;
  input: 'integer' | 'decimal' | 'dimensions'; // UNIT/ROLL/BOX: integer; LINEAR_METER/KG: decimal; SQUARE_METER: dimensions
  min_quantity: Decimal3;       // SQUARE_METER: peças (ADR-019)
  max_quantity: Decimal3 | null;
  quantity_step: Decimal3;      // SQUARE_METER: peças (sempre inteiro)
  // SQUARE_METER (e LINEAR_METER informativo):
  fixed_width_m: Decimal3 | null;   // efetivo = variante ?? produto
  min_width_m: Decimal3 | null;
  max_width_m: Decimal3 | null;
  min_height_m: Decimal3 | null;
  max_height_m: Decimal3 | null;
  min_billable_area_m2: Decimal3 | null; // por peça (ADR-019)
  dimension_decimals: 2;        // precisão aceita na entrada de largura/altura (1 cm)
  max_pieces: 1000;
}

export interface PriceTierDisplay {
  min_quantity: Decimal3;       // inclusivo, na unidade faturada (m² para SQUARE_METER)
  max_quantity: Decimal3 | null;// maior quantidade válida antes da próxima faixa; null = sem limite
  unit_price_cents: Cents;      // preço RESOLVIDO para o cliente atual nessa faixa
  price_source: PriceSource;
}

export interface VariantPrice {
  unit_price_cents: Cents;      // resolvido p/ quantidade mínima faturável e cliente atual
  base_unit_price_cents: Cents; // product_variants.price_cents
  compare_at_cents: Cents | null; // = base quando unit < base ("de R$ X por R$ Y"); senão null
  price_source: PriceSource;
  price_source_label: string | null;
  promotion: { name: string; ends_at: ISODateTime | null } | null;
  tiers: PriceTierDisplay[];    // tabela "preço por quantidade" do contexto atual; [] se preço único
}

export interface Availability {
  status: AvailabilityStatus;
  // Só preenchido quando status = 'low_stock' ("Restam 3,5 m"); unidade de estoque. Senão null.
  available_quantity: Decimal3 | null;
}

export interface ProductCard {
  id: number;
  slug: string;
  name: string;
  url_path: string;             // "/{primary_category_slug}/{slug}"
  sale_unit: SaleUnit;
  sale_unit_label: string;
  sale_unit_abbr: string;
  brand: BrandRef | null;
  primary_category: CategoryRef;
  image: ProductImage | null;   // capa
  variants_count: number;       // ativas
  default_variant: { id: number; sku: string; name: string }; // SKU exibido quando variants_count = 1
  key_attribute: string | null; // derivado: "Largura 1,22 m" | "Rolo 50 m" | "Caixa c/ 1000 un" | null
  price: {
    unit_price_cents: Cents;    // da variante padrão, na quantidade mínima, para o cliente atual
    compare_at_cents: Cents | null;
    price_source: PriceSource;
    price_source_label: string | null;
    from_price_cents: Cents | null; // menor preço possível (todas as variantes/faixas) quando < unit_price_cents
  };
  availability: { status: AvailabilityStatus }; // agregado: melhor status entre variantes ativas
  pickup_only: boolean;
  is_featured: boolean;
  quick_add: boolean;           // true se UNIT/ROLL/BOX, 1 variante, disponível e min_quantity = 1
}

export interface ProductVariant {
  id: number;
  sku: string;
  gtin: string | null;
  name: string;                 // "Brilho", "Padrão"
  attributes: Record<string, string>; // exibição: {"cor": "Branco", "acabamento": "Brilho"}
  position: number;
  is_default: boolean;
  image_ids: number[];          // imagens vinculadas à variante
  rules: SaleUnitRules;         // efetivas (produto + override da variante)
  price: VariantPrice;
  availability: Availability;
  weight_grams: Grams;          // por unidade de venda (0 = não informado)
  roll_length_m: Decimal3 | null;
  units_per_box: number | null;
}

export interface ProductDetail {
  id: number;
  slug: string;
  name: string;
  url_path: string;
  short_description: string | null; // texto puro
  description_html: string | null;  // HTML sanitizado (ADR-024) — renderizar com <SafeHtml>
  specifications: { label: string; value: string }[]; // ficha técnica (RN-CAT-015)
  sale_unit: SaleUnit;
  sale_unit_label: string;
  sale_unit_abbr: string;
  brand: BrandRef | null;
  primary_category: CategoryRef;
  categories: CategoryRef[];
  breadcrumbs: Breadcrumb[];    // Início › … › categoria principal (e ancestrais) › produto
  images: ProductImage[];
  attribute_axes: { key: string; label: string; values: string[] }[]; // eixos do seletor de variantes
  variants: ProductVariant[];   // só ativas, ordenadas por position
  default_variant_id: number;
  pickup_only: boolean;
  is_featured: boolean;
  seo: Seo;
}

export interface CategoryNode {
  id: number;
  name: string;
  slug: string;
  url_path: string;
  image_url: string | null;
  position: number;
  children: CategoryNode[];     // até 3 níveis
}
export interface CategoryDetail {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  url_path: string;
  description_html: string | null;
  image_url: string | null;
  breadcrumbs: Breadcrumb[];
  children: CategoryNode[];
  seo: Seo;
}
export interface Brand { id: number; name: string; slug: string; logo_url: string | null }

export interface ProductFacets {
  categories: { slug: string; name: string; count: number }[];
  brands: { slug: string; name: string; count: number }[];
  sale_units: { value: SaleUnit; label: string; count: number }[];
  price_range_cents: { min: Cents; max: Cents } | null; // preço base, conjunto filtrado
}
```

### 2.4 Prévia de preço

```ts
export interface LineConfiguration {
  quantity: Decimal3 | null;    // não-SQUARE_METER
  width_m: Decimal3 | null;     // SQUARE_METER (com largura fixa = fixed_width_m)
  height_m: Decimal3 | null;
  pieces: number | null;
}

export interface PricePreview {
  variant_id: number;
  sale_unit: SaleUnit;
  configuration: LineConfiguration;
  configuration_label: string;  // "5 m" | "1,20 m × 2,50 m × 1 peça" | "2 rolos"
  billable_quantity: Decimal3;  // faturada (m² após área mínima; demais = quantity)
  stock_quantity: Decimal3;     // baixa de estoque (m² real; demais = quantity)
  piece_area_m2: Decimal3 | null;   // SQUARE_METER: área calculada de 1 peça
  area_m2: Decimal3 | null;         // SQUARE_METER: área calculada total (peças)
  min_area_applied: boolean;
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  compare_at_cents: Cents | null;
  price_source: PriceSource;
  price_source_label: string | null;
  line_total_cents: Cents;      // round_half_up(unit × billable_milli / 1000)
  weight_grams: Grams;          // ceil(weight_grams × billable_milli / 1000); KG = billable em g
  applied_tier: PriceTierDisplay | null;
  next_tier: (PriceTierDisplay & { missing_quantity: Decimal3 }) | null;
  stock: { sufficient: boolean; available_quantity: Decimal3 | null }; // available só quando insuficiente
}
```

### 2.5 Carrinho

```ts
export type CartItemStatus = 'ok' | 'unavailable' | 'insufficient_stock' | 'invalid_quantity';

export type CartItemWarning =
  | { code: 'price_changed'; previous_unit_price_cents: Cents; current_unit_price_cents: Cents }
  | { code: 'unavailable'; message: string }                       // produto/variante inativa ou excluída
  | { code: 'insufficient_stock'; requested_quantity: Decimal3; available_quantity: Decimal3; message: string }
  | { code: 'invalid_quantity'; message: string; suggestions: number[] } // regra mudou (RN-CAR-031)
  | { code: 'min_area_applied'; area_m2: Decimal3; billable_area_m2: Decimal3 }; // informativo

export interface CartItem {
  id: number;
  variant_id: number;
  product: { id: number; slug: string; name: string; url_path: string; image: ProductImage | null };
  variant: { id: number; sku: string; name: string; attributes: Record<string, string> };
  sale_unit: SaleUnit;
  sale_unit_abbr: string;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3 | null;   // null se invalid_quantity/unavailable sem dados
  stock_quantity: Decimal3 | null;
  piece_area_m2: Decimal3 | null;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;
  unit_price_cents: Cents | null;       // null se unavailable/invalid_quantity
  base_unit_price_cents: Cents | null;
  compare_at_cents: Cents | null;
  price_source: PriceSource | null;
  price_source_label: string | null;
  line_total_cents: Cents | null;
  weight_grams: Grams;
  status: CartItemStatus;               // != 'ok' (exceto warnings informativos) bloqueia checkout
  warnings: CartItemWarning[];
  rules: SaleUnitRules | null;          // para edição inline (null se variante indisponível)
  availability: Availability;
}

export interface CartCoupon {
  code: string;
  description: string | null;
  type: CouponType;
  valid: boolean;
  reason_code: null | 'login_required' | 'expired' | 'inactive' | 'min_order_not_met'
             | 'usage_limit_reached' | 'customer_limit_reached' | 'not_found';
  message: string | null;               // pt-BR, quando !valid
  discount_cents: Cents;                // 0 quando !valid ou free_shipping
  free_shipping: boolean;
}

export interface Cart {
  token: UUID | null;                   // visitante: token do carrinho; cliente: null (carrinho pela sessão)
  owner: 'guest' | 'customer';
  items: CartItem[];                    // ordem de inserção
  items_count: number;                  // nº de linhas
  coupon: CartCoupon | null;
  postal_code: string | null;           // último CEP cotado
  totals: {
    subtotal_cents: Cents;              // Σ line_total_cents das linhas precificáveis (status ok | insufficient_stock)
    discount_cents: Cents;              // cupom sobre itens
    shipping_cents: Cents | null;       // só com shipping_quote_id+shipping_option_id válidos na query
    shipping_discount_cents: Cents | null;
    total_cents: Cents;                 // subtotal − discount + (shipping − shipping_discount ?? 0)
  };
  shipping_selection: {
    quote_id: UUID; option_id: string; option: ShippingOption; valid: boolean;
    issue_code: string | null;          // ex.: 'shipping_quote_expired' (então valid=false e shipping_cents=null)
  } | null;
  total_weight_grams: Grams;
  free_shipping_progress: { threshold_cents: Cents; remaining_cents: Cents; text: string } | null;
  price_list: { code: string; name: string } | null; // tabela efetiva do cliente (null = varejo/visitante)
  can_checkout: boolean;                // itens ≥ 1, todos status 'ok', cupom válido ou ausente
  blocking_reasons: ('cart_empty' | 'item_unavailable' | 'item_insufficient_stock'
                     | 'item_invalid_quantity' | 'coupon_invalid')[];
  has_price_changes: boolean;           // algum warning price_changed
  updated_at: ISODateTime | null;
}

export interface CartMergeReport {
  merged: boolean;                      // havia carrinho de visitante com itens
  lines_added: number;                  // linhas novas no carrinho do cliente
  lines_combined: number;               // linhas somadas a existentes
  adjustments: {
    variant_id: number; sku: string; product_name: string;
    previous_quantity: Decimal3;        // na unidade da linha (peças em SQUARE_METER)
    quantity: Decimal3;
    reason: 'max_quantity' | 'insufficient_stock';
  }[];
  dropped: {
    variant_id: number; sku: string; product_name: string;
    reason: 'unavailable' | 'line_limit' | 'invalid_quantity';
  }[];
  coupon: { code: string; kept: boolean; reason_code: CartCoupon['reason_code'] } | null;
}

export interface CartItemIssue {        // usado em 409/422 do checkout e da recompra
  cart_item_id: number | null;
  variant_id: number;
  sku: string;
  product_name: string;
  reason: 'unavailable' | 'invalid_quantity' | 'insufficient_stock';
  message: string;
}
export interface StockIssue {
  cart_item_id: number | null;
  variant_id: number;
  sku: string;
  product_name: string;
  requested_quantity: Decimal3;        // unidade de estoque (m² em SQUARE_METER; soma da variante)
  available_quantity: Decimal3;
}
```

### 2.6 Frete e CEP

```ts
export interface PickupAddress {
  street: string; number: string; complement: string | null; district: string;
  city: string; state: UF; postal_code: string;
  opening_hours: string | null; instructions: string | null;
}

export interface ShippingOption {       // SHIPPING.md §3 (campos internos rule_id/method_position não expostos)
  option_id: string;                    // "1:pickup" | "2:14" | "5:SEDEX" — enviar no checkout
  method_code: string;
  method_type: ShippingMethodType;
  name: string;
  description: string | null;
  price_cents: Cents;                   // valor cobrado
  original_price_cents: Cents;          // antes do frete grátis
  is_free: boolean;
  free_reason: FreeShippingReason | null;
  delivery_days_min: number;            // dias úteis
  delivery_days_max: number;
  delivery_label: string;               // "1 dia útil" | "2 a 4 dias úteis" | "Disponível em 1 dia útil após o pagamento"
  carrier: { code: string; name: string; service_code: string | null; service_name: string | null } | null;
  pickup_address: PickupAddress | null;
}

export interface ShippingQuote {
  quote_id: UUID | null;                // null em estimativa de produto (não persistida)
  expires_at: ISODateTime | null;
  destination: { postal_code: string; city: string | null; state: UF | null };
  options: ShippingOption[];            // ordenadas: preço ↑, prazo máx ↑, prazo mín ↑, posição do método
  notice: 'pickup_only_items' | null;
  message: string | null;               // "Não há opções de entrega para este CEP." quando options = []
  total_weight_grams: Grams;
}

export interface PostalCodeInfo {
  postal_code: string;                  // 8 dígitos
  street: string | null;                // null em CEP geral de cidade
  district: string | null;
  city: string;
  state: UF;
  city_ibge_code: string;               // 7 dígitos
  source: 'viacep' | 'cache';
}
```

### 2.7 Cliente, empresa, endereço

```ts
export interface Company {
  legal_name: string;
  trade_name: string | null;
  cnpj: string;                         // completo (dado do próprio cliente)
  state_registration: string | null;    // null quando isento
  state_registration_exempt: boolean;
}

export interface Customer {
  uuid: UUID;
  type: CustomerType;
  name: string;                         // PF: nome; PJ: responsável
  email: string;
  email_verified: boolean;
  phone: string | null;
  cpf: string | null;                   // PF: sempre; PJ: opcional (responsável)
  marketing_opt_in: boolean;
  company: Company | null;              // só PJ
  price_list: { code: string; name: string } | null; // efetiva (cliente → empresa); null = varejo
  terms: { accepted_version: string; current_version: string; needs_acceptance: boolean };
  profile_complete: boolean;            // dados de faturamento completos para o checkout
  missing_fields: string[];             // ex.: ["phone"]
  created_at: ISODateTime;
}

export interface Address {
  uuid: UUID;
  label: string | null;                 // "Casa", "Loja"
  recipient_name: string;
  phone: string | null;
  postal_code: string;
  street: string;
  number: string;                       // aceita "S/N"
  complement: string | null;
  district: string;
  city: string;                         // derivado do CEP (não editável)
  state: UF;                            // derivado do CEP
  city_ibge_code: string | null;        // derivado do CEP
  reference: string | null;
  is_default: boolean;
  formatted: string;                    // "Rua das Palmeiras, 123 – Victor Konder – Blumenau/SC – 89012-000"
  created_at: ISODateTime;
}
```

### 2.8 Pedido e pagamento (visão do cliente)

```ts
export interface PixData {
  qr_code_base64: string | null;        // PNG base64 SEM prefixo "data:"; usar src={`data:image/png;base64,${v}`}
  copy_paste: string;                   // payload EMV "copia e cola"
  expires_at: ISODateTime;
}

export interface OrderPayment {
  uuid: UUID;
  method: PaymentMethod;
  status: PaymentRecordStatus;
  amount_cents: Cents;
  expires_at: ISODateTime | null;
  paid_at: ISODateTime | null;
  pix: PixData | null;                  // null se o PIX ainda não foi gerado (gateway falhou) ou não é PIX
}

export interface OrderItem {
  product_id: number;
  product_name: string;                 // snapshot
  variant_name: string;
  sku: string;
  product_url_path: string | null;      // null se produto inativo/excluído hoje
  sale_unit: SaleUnit;
  sale_unit_abbr: string;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3;
  stock_quantity: Decimal3;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;            // billable_quantity > stock_quantity
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  price_source: PriceSource;
  subtotal_cents: Cents;
  discount_cents: Cents;                // rateio do cupom
  total_cents: Cents;
  weight_grams: Grams;
}

export interface OrderTimelineEntry {
  status: OrderStatus;
  status_label: string;                 // "Pedido realizado", "Pagamento aprovado", "Em separação"...
  occurred_at: ISODateTime;
  note: string | null;                  // somente notas públicas: rastreio, motivo de cancelamento
}

export interface OrderAllowedActions {
  can_pay: boolean;                     // pending_payment, não expirado, PIX disponível
  can_retry_payment: boolean;           // pending_payment, não expirado, sem PIX ativo utilizável
  can_cancel: boolean;                  // pending_payment
  can_request_cancellation: boolean;    // paid|processing e sem solicitação aberta
  can_reorder: boolean;                 // sempre true (RN-PED-045)
}

export interface OrderSummary {
  uuid: UUID;
  number: string;                       // "CV-000123"
  status: OrderStatus;
  status_label: string;
  payment_status: OrderPaymentStatus;
  payment_method: PaymentMethod;
  placed_at: ISODateTime;
  expires_at: ISODateTime | null;       // prazo de pagamento (pending_payment)
  items_count: number;
  total_cents: Cents;
  shipping_method_name: string;
  shipping_method_type: ShippingMethodType;
  tracking_code: string | null;
  allowed_actions: OrderAllowedActions;
}

export interface OrderShippingSnapshot {
  method_name: string;
  method_type: ShippingMethodType;
  carrier_code: string | null;
  service_code: string | null;
  delivery_days_min: number | null;
  delivery_days_max: number | null;
  delivery_label: string | null;
  estimated_delivery_date: ISODate | null; // gravada no pagamento
  tracking_code: string | null;
  tracking_url: string | null;
  address: {                            // null quando retirada
    recipient_name: string; phone: string | null; postal_code: string; street: string;
    number: string; complement: string | null; district: string; city: string; state: UF;
    reference: string | null; formatted: string;
  } | null;
  pickup_address: PickupAddress | null; // quando retirada (endereço atual do método)
  picked_up_at: ISODateTime | null;
  picked_up_by_name: string | null;
}

export interface OrderDetail extends OrderSummary {
  paid_at: ISODateTime | null;
  cancelled_at: ISODateTime | null;
  cancel_reason_code: CancelReasonCode | null;
  cancel_reason_label: string | null;   // texto público ("Pagamento não realizado no prazo")
  cancellation_request: { requested_at: ISODateTime; reason: string | null } | null;
  items: OrderItem[];
  totals: {
    subtotal_cents: Cents; discount_cents: Cents; shipping_cents: Cents;
    shipping_discount_cents: Cents; total_cents: Cents;
  };
  coupon_code: string | null;
  total_weight_grams: Grams;
  billing: {                            // snapshot do comprador
    customer_type: CustomerType; name: string; email: string; document: string;
    phone: string | null; company_name: string | null; state_registration: string | null;
  };
  shipping: OrderShippingSnapshot;
  payment: OrderPayment | null;         // pagamento mais recente
  timeline: OrderTimelineEntry[];       // ordem cronológica
  notes: string | null;                 // observação do cliente
}

export interface OrderStatusPoll {
  uuid: UUID;
  number: string;
  status: OrderStatus;
  payment_status: OrderPaymentStatus;
  expires_at: ISODateTime | null;
  paid_at: ISODateTime | null;
  payment: { uuid: UUID; status: PaymentRecordStatus; expires_at: ISODateTime | null; has_pix: boolean } | null;
  updated_at: ISODateTime;
}
```

### 2.9 Checkout

```ts
export interface CheckoutBlocking {
  code: 'cart_empty' | 'cart_invalid' | 'insufficient_stock' | 'coupon_invalid'
      | 'shipping_required' | 'profile_incomplete' | 'too_many_pending_orders'
      | 'shipping_quote_expired' | 'shipping_option_unavailable' | 'shipping_quote_invalid'
      | 'shipping_quote_changed' | 'shipping_postal_code_changed' | 'shipping_option_invalid'
      | 'shipping_price_changed';
  message: string;
}

export interface CheckoutSummary {
  items: CartItem[];
  coupon: CartCoupon | null;
  totals: {
    subtotal_cents: Cents; discount_cents: Cents;
    shipping_cents: Cents | null;       // null enquanto não há opção de frete válida
    shipping_discount_cents: Cents;
    total_cents: Cents;                 // valor a enviar em expected_total_cents
  };
  total_weight_grams: Grams;
  address: Address;
  shipping_option: ShippingOption | null;
  shipping_quote: ShippingQuote | null; // nova cotação quando a informada divergiu
  payment_method: PaymentMethod;
  payment_expires_in_minutes: number;   // settings checkout.pix_expiry_minutes
  billing: OrderDetail['billing'];
  can_place_order: boolean;
  blocking: CheckoutBlocking[];
}

export interface CheckoutResult {
  order: OrderDetail;
  payment: OrderPayment;
  replayed: boolean;
}

export interface CouponIssue {
  code: string;
  reason_code: Exclude<CartCoupon['reason_code'], null>;
  message: string;
}
```

### 2.10 Recompra

```ts
export interface ReorderReport {
  cart: Cart;
  summary: { total_items: number; added_items: number; adjusted_items: number; skipped_items: number };
  items: {
    sku: string;
    product_name: string;
    result: 'added' | 'adjusted' | 'unavailable' | 'invalid_rules';
    message: string;                    // pt-BR, pronto para exibir
    requested: LineConfiguration;
    added: LineConfiguration | null;
    previous_unit_price_cents: Cents;   // preço no pedido original
    current_unit_price_cents: Cents | null;
    product_url_path: string | null;
  }[];
}

export interface ReorderSuggestion {
  variant_id: number;
  product: Pick<ProductCard, 'id' | 'slug' | 'name' | 'url_path' | 'image' | 'sale_unit' | 'sale_unit_abbr'>;
  variant: { id: number; sku: string; name: string };
  last_configuration: LineConfiguration;
  last_configuration_label: string;
  last_ordered_at: ISODateTime;
  current_unit_price_cents: Cents;
  price_source: PriceSource;
  availability: { status: AvailabilityStatus };
}
```

### 2.11 Configurações públicas e notificações

```ts
export interface PublicSettings {
  store: {
    name: string;
    phone: string | null;
    whatsapp: string | null;            // dígitos; link wa.me montado no front
    email: string | null;
    address: { street: string; number: string; complement: string | null; district: string;
               city: string; state: UF; postal_code: string } | null;
    opening_hours: string | null;
    social_links: { instagram: string | null; facebook: string | null; youtube: string | null };
  };
  pickup_points: (PickupAddress & { method_code: string; name: string })[]; // métodos pickup ativos
  free_shipping_banner: { enabled: boolean; threshold_cents: Cents; text: string } | null;
  terms_version: string;
  checkout: { payment_methods: PaymentMethod[]; pix_expiry_minutes: number; min_order_cents: Cents };
  features: { show_low_stock_quantity: boolean };
}

export interface AppNotification {
  id: UUID;
  type: string;                         // ex.: "order_paid", "low_stock"
  title: string;
  body: string | null;
  link: string | null;                  // path interno da SPA
  read_at: ISODateTime | null;
  created_at: ISODateTime;
}
```

### 2.12 Painel — identidade e RBAC

```ts
export type RoleName = 'super-admin' | 'manager' | 'seller' | 'warehouse' | 'finance' | string;

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  roles: RoleName[];
  last_login_at: ISODateTime | null;
  created_at: ISODateTime;
  deleted_at: ISODateTime | null;
}

export interface AdminMe {
  user: AdminUser;
  permissions: PermissionName[];        // efetivas (super-admin recebe a lista completa)
  is_super_admin: boolean;
  session: { idle_timeout_seconds: 1800; absolute_expires_at: ISODateTime };
}

export interface Role {
  id: number;
  name: RoleName;                       // identificador ^[a-z0-9-]{3,50}$
  label: string;                        // pt-BR ("Gerente"); papéis customizados: = name
  is_system: boolean;                   // papéis do seed (super-admin não editável)
  permissions: PermissionName[];
  users_count: number;
}

export interface Permission {
  name: PermissionName;
  label: string;                        // pt-BR
  group: 'dashboard' | 'products' | 'pricing' | 'inventory' | 'orders' | 'payments'
       | 'customers' | 'shipping' | 'reports' | 'admin' | 'settings' | 'audit';
}

export type PermissionName =
  | 'dashboard.view'
  | 'products.view' | 'products.manage'
  | 'prices.manage' | 'pricing.manage' | 'promotions.manage' | 'coupons.manage'
  | 'inventory.view' | 'inventory.move' | 'inventory.adjust'
  | 'orders.view' | 'orders.fulfill' | 'orders.pickup' | 'orders.cancel_unpaid'
  | 'orders.cancel_paid' | 'orders.notes'
  | 'payments.view' | 'payments.reconcile'
  | 'customers.view' | 'customers.view_sensitive' | 'customers.update' | 'customers.manage'
  | 'shipping.manage'
  | 'reports.view' | 'reports.sales' | 'reports.inventory' | 'reports.export'
  | 'admin_users.manage' | 'settings.manage' | 'audit_logs.view';
```

### 2.13 Painel — catálogo, preços e estoque

```ts
export interface AdminCategory {
  id: number; parent_id: number | null; name: string; slug: string;
  description_html: string | null; image_url: string | null;
  meta_title: string | null; meta_description: string | null;
  position: number; is_active: boolean; depth: 1 | 2 | 3;
  products_count: number;               // produtos ativos na categoria (N:N)
  children: AdminCategory[];            // presente no endpoint de árvore
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminBrand {
  id: number; name: string; slug: string; logo_url: string | null; is_active: boolean;
  products_count: number; created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminVariant {
  id: number;
  sku: string;
  gtin: string | null;
  name: string;
  attributes: Record<string, string>;
  price_cents: Cents;
  promo_price_cents: Cents | null;
  promo_starts_at: ISODateTime | null;
  promo_ends_at: ISODateTime | null;
  cost_cents: Cents | null;
  weight_grams: Grams;
  package_length_cm: number | null;
  package_width_cm: number | null;
  package_height_cm: number | null;
  roll_length_m: Decimal3 | null;
  units_per_box: number | null;
  units_per_package: number | null;
  fixed_width_m: Decimal3 | null;       // override da variante
  is_active: boolean;
  position: number;
  has_orders: boolean;
  inventory: { on_hand: Decimal3; reserved: Decimal3; available: Decimal3;
               low_stock_threshold: Decimal3; is_low_stock: boolean };
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminProductImage extends ProductImage {
  original_filename: string | null;     // sanitizado, só informativo
  created_at: ISODateTime;
}

export interface AdminProductListItem {
  id: number; name: string; slug: string; sale_unit: SaleUnit;
  primary_category: CategoryRef; brand: BrandRef | null;
  image: ProductImage | null;
  variants_count: number;
  skus: string[];                       // até 5
  min_price_cents: Cents;               // menor preço base entre variantes ativas
  total_available: Decimal3;            // Σ disponível (mesma unidade de estoque)
  has_low_stock: boolean;
  is_active: boolean; is_featured: boolean; pickup_only: boolean;
  updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminProduct {
  id: number;
  name: string;
  slug: string;
  url_path: string;
  short_description: string | null;
  description_html: string | null;
  specifications: { label: string; value: string }[];
  sale_unit: SaleUnit;
  sale_unit_locked: boolean;            // true se já existe pedido com o produto (RN-CAT-004)
  brand_id: number | null;
  primary_category_id: number;
  category_ids: number[];               // inclui a principal
  min_quantity: Decimal3;
  max_quantity: Decimal3 | null;
  quantity_step: Decimal3;
  min_billable_area_m2: Decimal3 | null;
  fixed_width_m: Decimal3 | null;
  min_width_m: Decimal3 | null;
  max_width_m: Decimal3 | null;
  min_height_m: Decimal3 | null;
  max_height_m: Decimal3 | null;
  meta_title: string | null;
  meta_description: string | null;
  is_active: boolean;
  is_featured: boolean;
  pickup_only: boolean;
  activation_issues: string[];          // motivos pelos quais não pode ser ativado (RN-CAT-012); [] = ok
  variants: AdminVariant[];
  images: AdminProductImage[];
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminVariantPickerItem {
  id: number; sku: string; name: string; sale_unit: SaleUnit; price_cents: Cents; is_active: boolean;
  product: { id: number; name: string };
}

export interface PriceTier {
  id: number; variant_id: number; price_list_id: number | null;
  min_quantity: Decimal3; price_cents: Cents;
}

export interface PriceList {
  id: number; code: string; name: string; kind: PriceListKind;
  discount_bp: BasisPoints | null; is_default: boolean; is_active: boolean;
  customers_count: number; companies_count: number; tiers_count: number;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface CustomerPrice {
  id: number;
  customer: { id: number; name: string; email: string } | null;
  company: { id: number; legal_name: string } | null;
  variant: AdminVariantPickerItem;
  price_cents: Cents;
  starts_at: ISODateTime | null;
  ends_at: ISODateTime | null;
  created_by: { id: number; name: string } | null;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface Promotion {
  id: number; name: string; description: string | null;
  discount_type: PromotionDiscountType; value: number; // percent: bp; fixed: centavos por unidade de venda
  scope: PromotionScope;
  starts_at: ISODateTime; ends_at: ISODateTime | null;
  is_active: boolean; priority: number;
  status: 'scheduled' | 'active' | 'ended' | 'inactive';
  product_ids: number[]; category_ids: number[]; brand_ids: number[];
  targets: { products: { id: number; name: string }[]; categories: CategoryRef[]; brands: BrandRef[] };
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface Coupon {
  id: number; code: string; description: string | null;
  type: CouponType; value: number;      // percent: bp; fixed: centavos; free_shipping: 0
  min_order_cents: Cents; max_discount_cents: Cents | null;
  starts_at: ISODateTime | null; ends_at: ISODateTime | null;
  usage_limit: number | null; usage_limit_per_customer: number | null;
  times_used: number; is_active: boolean;
  status: 'scheduled' | 'active' | 'expired' | 'exhausted' | 'inactive';
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}
export interface CouponRedemption {
  id: number; order: { id: number; number: string; status: OrderStatus };
  customer: { id: number; name: string }; discount_cents: Cents;
  created_at: ISODateTime; cancelled_at: ISODateTime | null;
}

export interface InventoryItem {
  variant_id: number;
  sku: string;
  variant_name: string;
  product: { id: number; name: string; slug: string; is_active: boolean };
  sale_unit: SaleUnit;
  stock_unit_abbr: string;              // un | m | m² | rolo | kg | cx
  on_hand: Decimal3;
  reserved: Decimal3;
  available: Decimal3;
  low_stock_threshold: Decimal3;        // efetivo (override ?? setting)
  low_stock_threshold_override: Decimal3 | null;
  is_low_stock: boolean;
  low_stock_alerted_at: ISODateTime | null;
  updated_at: ISODateTime | null;
}

export interface InventoryMovement {
  id: number;
  variant_id: number;
  type: InventoryMovementType;
  quantity: Decimal3;                   // magnitude
  on_hand_delta: Decimal3;              // com sinal
  reserved_delta: Decimal3;
  on_hand_after: Decimal3;
  reserved_after: Decimal3;
  reason: string | null;
  reference: { type: 'order'; id: number; label: string } | null; // label = número do pedido
  actor: { type: ActorType; id: number | null; name: string | null };
  created_at: ISODateTime;
}
```

### 2.14 Painel — pedidos, pagamentos, clientes

```ts
export interface AdminPaymentTransaction {
  id: number;
  type: 'create' | 'approve' | 'fail' | 'refund' | 'expire' | 'cancel' | 'sync';
  status_before: PaymentRecordStatus | null;
  status_after: PaymentRecordStatus;
  amount_cents: Cents;
  external_id: string | null;
  admin_user: { id: number; name: string } | null;
  created_at: ISODateTime;
}

export interface AdminPayment {
  id: number; uuid: UUID; provider: PaymentProvider; method: PaymentMethod;
  status: PaymentRecordStatus; amount_cents: Cents; refunded_cents: Cents;
  external_id: string | null; expires_at: ISODateTime | null; paid_at: ISODateTime | null;
  failed_at: ISODateTime | null; refunded_at: ISODateTime | null; failure_reason: string | null;
  refund: { status: 'pending' | 'succeeded' | 'failed'; amount_cents: Cents; requested_at: ISODateTime } | null;
  transactions: AdminPaymentTransaction[] | null; // null sem payments.view
  created_at: ISODateTime;
}

export interface AdminOrderListItem {
  id: number; uuid: UUID; number: string;
  status: OrderStatus; payment_status: OrderPaymentStatus; payment_method: PaymentMethod;
  customer: { id: number; name: string; type: CustomerType; company_name: string | null };
  items_count: number; total_cents: Cents;
  shipping_method_name: string; shipping_method_type: ShippingMethodType;
  shipping_city: string | null; shipping_state: UF | null;
  placed_at: ISODateTime; paid_at: ISODateTime | null; expires_at: ISODateTime | null;
  has_cancellation_request: boolean;
}

export interface AdminTransition {
  to_status: OrderStatus;
  label: string;                        // "Marcar em separação", "Marcar como enviado"...
  required_fields: ('tracking_code' | 'picked_up_by_name' | 'picked_up_by_document')[];
  optional_fields: ('note' | 'tracking_code' | 'tracking_url' | 'carrier_name')[];
}

export interface AdminOrderItem extends OrderItem {
  id: number;
  variant_id: number;
  picking_instruction: string;          // "Separar: 5 m" | "Cortar: 4 peças de 1,20 × 2,50 m"
}

export interface AdminStatusHistoryEntry {
  id: number;
  from_status: OrderStatus | null;
  to_status: OrderStatus;
  actor: { type: ActorType; id: number | null; name: string | null };
  note: string | null;
  created_at: ISODateTime;
}

export interface AdminOrder {
  id: number; uuid: UUID; number: string;
  status: OrderStatus; status_label: string;
  payment_status: OrderPaymentStatus; payment_method: PaymentMethod;
  placed_at: ISODateTime; expires_at: ISODateTime | null; paid_at: ISODateTime | null;
  processing_at: ISODateTime | null; shipped_at: ISODateTime | null;
  ready_for_pickup_at: ISODateTime | null; delivered_at: ISODateTime | null;
  picked_up_at: ISODateTime | null; cancelled_at: ISODateTime | null; refunded_at: ISODateTime | null;
  cancel_reason_code: CancelReasonCode | null; cancel_reason: string | null;
  cancellation_request: { requested_at: ISODateTime; reason: string | null } | null;
  customer: {
    id: number; uuid: UUID; type: CustomerType; name: string; email: string; phone: string | null;
    document_masked: string;            // "***.456.789-**" / "**.345.678/0001-**"
    company_name: string | null; state_registration: string | null;
  };
  items: AdminOrderItem[];
  totals: OrderDetail['totals'];
  coupon: { id: number; code: string } | null;
  total_weight_grams: Grams;
  total_volume_cm3: number;
  shipping: OrderShippingSnapshot & {
    method_id: number | null; rule_id: number | null; option_id: string; quote_uuid: UUID | null;
    picked_up_by_document_masked: string | null;
  };
  payments: AdminPayment[];             // mais recente primeiro
  status_history: AdminStatusHistoryEntry[];
  notes: string | null;                 // do cliente
  internal_notes: string | null;
  allowed_transitions: AdminTransition[]; // válidas na máquina E permitidas ao admin atual
  can_cancel: boolean;                  // estado permite e admin tem a permissão correspondente
  cancel_requires_refund: boolean;      // status paid|processing
  flags: { amount_mismatch: boolean };  // PaymentAmountMismatch registrado
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface AdminCompany {
  id: number; legal_name: string; trade_name: string | null;
  cnpj_masked: string; state_registration: string | null; state_registration_exempt: boolean;
  price_list: { id: number; code: string; name: string } | null;
  customers: { id: number; name: string; email: string }[];
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface AdminCustomer {
  id: number; uuid: UUID; type: CustomerType; name: string; email: string;
  email_verified_at: ISODateTime | null; phone: string | null;
  cpf_masked: string | null;
  company: AdminCompany | null;
  price_list: { id: number; code: string; name: string } | null;        // atribuída ao cliente
  effective_price_list: { id: number; code: string; name: string } | null; // cliente → empresa → default
  is_active: boolean; marketing_opt_in: boolean;
  terms_version: string; terms_accepted_at: ISODateTime;
  last_login_at: ISODateTime | null; anonymized_at: ISODateTime | null;
  stats: { orders_count: number; paid_orders_count: number; total_spent_cents: Cents; last_order_at: ISODateTime | null };
  addresses?: Address[];                // só no detalhe
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}
```

### 2.15 Painel — frete

```ts
export interface Carrier {
  id: number; name: string; code: string; driver: string;
  settings: Record<string, string | number | boolean | null>;
  has_credentials: boolean;             // credentials nunca são retornadas
  is_active: boolean; methods_count: number;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface ShippingMethod {
  id: number; name: string; code: string; type: ShippingMethodType;
  carrier_id: number | null; carrier_service_code: string | null;
  description: string | null;
  delivery_days_min: number; delivery_days_max: number; handling_days: number;
  weight_basis: WeightBasis; cubic_divisor: number | null;
  accepts_free_shipping_coupon: boolean;
  position: number; is_active: boolean;
  pickup: {
    street: string; number: string | null; complement: string | null; district: string | null;
    city: string; state: UF; postal_code: string; instructions: string | null; opening_hours: string | null;
  } | null;                             // só type = pickup
  rules_count: number;
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface ShippingZone {
  id: number; name: string; description: string | null; is_active: boolean;
  postal_ranges: { id: number; start_postal_code: string; end_postal_code: string }[];
  cities: { id: number; city_ibge_code: string; city_name: string; state: UF }[];
  states: UF[];
  rules_count: number;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface ShippingRule {
  id: number; method_id: number; zone_id: number | null; name: string; priority: number;
  min_weight_grams: Grams | null; max_weight_grams: Grams | null;
  min_subtotal_cents: Cents | null; max_subtotal_cents: Cents | null;
  min_volume_cm3: number | null; max_volume_cm3: number | null;
  max_package_length_cm: number | null;
  price_type: ShippingPriceType;
  price_cents: Cents; per_kg_cents: Cents; percentage_bp: BasisPoints;
  min_price_cents: Cents | null; max_price_cents: Cents | null;
  delivery_days_min: number | null; delivery_days_max: number | null;
  valid_from: ISODateTime | null; valid_until: ISODateTime | null;
  is_active: boolean;
  summary: string;                      // "Blumenau · ≤ 10 kg → R$ 20,00"
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface IbgeCity { ibge_code: string; name: string; state: UF }
```

### 2.16 Painel — settings, auditoria, dashboard, relatórios

```ts
export type SettingKey =
  | 'store.name' | 'store.legal_name' | 'store.document' | 'store.address' | 'store.phone'
  | 'store.whatsapp' | 'store.email' | 'store.opening_hours' | 'store.social_links'
  | 'orders.number_prefix' | 'checkout.pix_expiry_minutes' | 'checkout.min_order_cents'
  | 'cart.guest_ttl_days' | 'shipping.quote_ttl_minutes' | 'shipping.origin_postal_code'
  | 'inventory.default_low_stock_threshold' | 'inventory.show_low_stock_quantity'
  | 'legal.terms_version' | 'storefront.free_shipping_banner'
  | 'notifications.whatsapp_enabled' | 'notifications.admin_alert_emails'
  | 'content.about' | 'content.terms' | 'content.privacy' | 'content.returns';

export interface Setting {
  key: SettingKey;
  value: unknown;                       // tipo conforme tabela da seção 3.G.13
  type: 'string' | 'integer' | 'decimal' | 'boolean' | 'object' | 'string_list' | 'text';
  group: 'store' | 'checkout' | 'shipping' | 'inventory' | 'legal' | 'storefront' | 'notifications' | 'content';
  is_public: boolean;
  description: string | null;
  updated_at: ISODateTime | null;
  updated_by: { id: number; name: string } | null;
}

export interface AuditLog {
  id: number;
  actor: { type: ActorType; id: number | null; name: string | null };
  action: string;                       // "product.updated", "order.status_changed", "inventory.adjusted"
  auditable_type: string | null;        // morph alias: "product", "order"...
  auditable_id: number | null;
  auditable_label: string | null;       // "CV-000123", "Vinil Adesivo Branco"
  old_values: Record<string, unknown> | null; // sensíveis já mascarados ("••••")
  new_values: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
  request_id: string | null;
  created_at: ISODateTime;
}

export interface KpiValue { value: number; previous: number; change_bp: number | null } // change em bp (1250 = +12,5%)

export interface Dashboard {
  generated_at: ISODateTime;
  sales: {                              // null sem reports.view|reports.sales
    today: { orders_paid: KpiValue; revenue_cents: KpiValue };
    month: { orders_paid: KpiValue; revenue_cents: KpiValue };
    avg_ticket_cents_month: KpiValue;
    revenue_series_30d: { date: ISODate; revenue_cents: Cents; orders_paid: number }[]; // 30 pontos, dias sem venda = 0
    top_products_30d: { variant_id: number; sku: string; name: string; sale_unit: SaleUnit;
                        quantity: Decimal3; revenue_cents: Cents }[]; // top 5
  } | null;
  queues: {                             // null sem orders.view
    pending_payment: number; to_pick: number;          // paid
    to_ship: number;                                    // processing com entrega
    to_prepare_pickup: number;                          // processing com retirada
    ready_for_pickup: number; shipped: number; cancellation_requests: number;
  } | null;
  todays_deliveries: {                  // null sem orders.view
    order_id: number; number: string; status: OrderStatus; shipping_method_type: ShippingMethodType;
    shipping_method_name: string; district: string | null; city: string | null;
    estimated_delivery_date: ISODate | null;
  }[] | null;
  low_stock: { items: InventoryItem[]; total: number } | null; // top 10; null sem inventory.view
}

export interface ReportResponse<Row, Summary = Record<string, number | null>> {
  report: ReportName;
  period: { date_from: ISODate; date_to: ISODate; group_by: 'day' | 'week' | 'month' | null; timezone: 'America/Sao_Paulo' };
  filters: Record<string, string | number | null>;
  summary: Summary;
  rows: Row[];
  totals: Partial<Row> | null;
}
export type ReportName =
  | 'sales' | 'products' | 'revenue' | 'customers' | 'inventory' | 'inventory-movements'
  | 'orders' | 'shipping' | 'margin' | 'coupons';
```

Linhas de relatório (definições: BUSINESS_RULES §4.15):

```ts
export interface SalesReportRow {       // report=sales (RN-REL-001/005/006)
  period_start: ISODate; orders_paid: number; orders_refunded: number;
  revenue_cents: Cents;                 // Σ total_cents pagos no período, excluindo refunded
  products_revenue_cents: Cents;        // Σ subtotal − discount
  shipping_cents: Cents; discount_cents: Cents; avg_ticket_cents: Cents | null;
}
export interface ProductsReportRow {    // report=products (RN-REL-010)
  variant_id: number; sku: string; product_name: string; variant_name: string;
  sale_unit: SaleUnit; quantity: Decimal3; revenue_cents: Cents; orders_count: number;
}
export interface RevenueReportRow {     // report=revenue
  period_start: ISODate; gross_cents: Cents; discount_cents: Cents; shipping_cents: Cents;
  refunds_cents: Cents; net_cents: Cents;
}
export interface CustomersReportRow {   // report=customers (top clientes)
  customer_id: number; name: string; type: CustomerType; orders_count: number;
  revenue_cents: Cents; last_order_at: ISODateTime;
}
export interface InventoryReportRow {   // report=inventory (posição atual)
  variant_id: number; sku: string; product_name: string; sale_unit: SaleUnit;
  on_hand: Decimal3; reserved: Decimal3; available: Decimal3; low_stock_threshold: Decimal3;
  is_low_stock: boolean; cost_cents: Cents | null; stock_value_cents: Cents | null;
  sold_quantity: Decimal3;              // vendido no período
}
export interface InventoryMovementsReportRow { // report=inventory-movements (RN-REL-015)
  variant_id: number; sku: string; product_name: string; type: InventoryMovementType;
  movements_count: number; quantity: Decimal3;
}
export interface OrdersReportRow {      // report=orders (RN-REL-007/008/012/013)
  status: OrderStatus; count: number;
}
export interface ShippingReportRow {    // report=shipping (RN-REL-017)
  shipping_method_id: number | null; method_name: string; method_type: ShippingMethodType;
  city: string | null; state: UF | null; orders_count: number; shipping_revenue_cents: Cents;
  free_shipping_orders: number; shipping_discount_cents: Cents;
}
export interface MarginReportRow {      // report=margin
  variant_id: number; sku: string; product_name: string; sale_unit: SaleUnit; quantity: Decimal3;
  revenue_cents: Cents; cost_cents: Cents | null; margin_cents: Cents | null; margin_bp: BasisPoints | null;
}
export interface CouponsReportRow {     // report=coupons (RN-REL-009)
  coupon_id: number; code: string; uses: number; discount_cents: Cents; orders_revenue_cents: Cents;
}
```

---
## 3. Catálogo de endpoints

Formato de cada endpoint: **Auth** · **Rate** · **Query** · **Body** (campos aceitos =
whitelist; tudo o mais é ignorado, exceto os proibidos da §1.7 que geram 422) ·
**Resposta** · **Erros** · **Notas**. Regras de validação em notação Laravel resumida.

### 3.A Loja pública

#### `GET /api/health/live` · `GET /api/health`

- **Auth:** nenhuma · **Rate:** `health`.
- `live` → `200 {"status":"ok"}` (sem dependências).
- `health` → `200 {"status":"ok","checks":{"database":"ok","redis":"ok","scheduler":"ok","queues":"ok"}}`
  ou `503 {"status":"fail","checks":{...,"redis":"fail"}}`. Nunca expõe versões/hosts/mensagens.
- **Não** usa envelope `data` (consumido por infraestrutura).

#### `GET /settings/public`

- **Auth:** nenhuma · **Rate:** `catalog`.
- **Resposta 200:** `{ data: PublicSettings }`. Montado a partir das settings `is_public`
  + métodos `pickup` ativos (`pickup_points`).

#### `GET /pages/{slug}`

- `slug ∈ {sobre, termos, privacidade, trocas}` → settings `content.about|terms|privacy|returns`.
- **Resposta 200:** `{ data: { slug: string; title: string; body_text: string; updated_at: ISODateTime | null } }`
  — `body_text` é **texto puro** (ADR-024); o front quebra parágrafos por `\n\n`.
- **Erros:** 404 `not_found`.

#### `GET /categories`

- **Auth:** nenhuma · **Rate:** `catalog` · cache servidor 6 h.
- **Resposta 200:** `{ data: CategoryNode[] }` — árvore de categorias **ativas**, raízes por
  `position`, filhos aninhados (máx. 3 níveis).

#### `GET /categories/{slug}`

- **Resposta 200:** `{ data: CategoryDetail }` (breadcrumbs, filhos, SEO com `BreadcrumbList`).
- **Erros:** 404 `not_found` (inexistente ou inativa).
- **Notas:** produtos da categoria vêm de `GET /products?category={slug}`.

#### `GET /brands`

- **Resposta 200:** `{ data: Brand[] }` — marcas ativas, por nome. Sem paginação.

#### `GET /products` — listagem, categoria e busca

- **Auth:** opcional (preço do cliente) · **Rate:** `catalog`; `search` quando `q` presente.
- **Query:**

| Parâmetro | Tipo / regra | Descrição |
|---|---|---|
| `q` | string 2–100 | Busca (nome, SKU por prefixo, descrição, marca, categoria; sem acento — ADR-014). |
| `category` | slugs separados por vírgula | Filtra por categoria **incluindo descendentes**; vários = união. |
| `brand` | slugs separados por vírgula | |
| `price_min_cents`, `price_max_cents` | int ≥ 0 | Sobre o **preço base** das variantes ativas (DATABASE §3.1.5). |
| `sale_unit` | lista de `SaleUnit` | |
| `in_stock` | `1` | Pelo menos uma variante com disponível ≥ `min_quantity`. |
| `featured` | `1` | `is_featured` (vitrine "Destaques"). |
| `on_sale` | `1` | Variante com `promo_price` vigente **ou** promoção ativa que atinja o produto (vitrine "Promoções"). |
| `sort` | `relevance` (padrão com `q`), `best_selling` (padrão sem `q`; vendas pagas 90 dias), `price`, `-price`, `name`, `-created_at` | `price` usa o menor preço base. |
| `page`, `per_page` | padrão 24, máx. 100 | |

- **Resposta 200:**

```ts
Paginated<ProductCard> & {
  facets: ProductFacets;               // contagens aplicando todos os filtros exceto o da própria faceta
  search: { q: string; exact_sku_match: { variant_id: number; sku: string; product_slug: string; url_path: string } | null } | null;
}
```

- **Erros:** 422 (`q` curto/longo, `sort` fora da allowlist, `per_page` > 100, slug de filtro malformado).
- **Notas:** `exact_sku_match` permite ao front redirecionar para `url_path + '?sku=' + sku`
  quando `q` é exatamente um SKU (UX §4.3). Slugs de filtro inexistentes não geram erro
  (resultado vazio). Só produtos ativos com ≥ 1 variante ativa.

#### `GET /products/autocomplete`

- **Rate:** `search` · **Query:** `q` (2–100, obrigatório).
- **Resposta 200:**

```ts
{ data: {
  products: { id: number; name: string; slug: string; url_path: string; image_url: string | null;
              matched_sku: string | null; unit_price_cents: Cents; sale_unit_abbr: string }[]; // até 8
  categories: CategoryRef[];           // até 4
  brands: BrandRef[];                  // até 3
} }
```

#### `GET /products/{slug}`

- **Auth:** opcional · **Rate:** `catalog`.
- **Resposta 200:** `{ data: ProductDetail }` com preços/faixas resolvidos para o cliente atual.
- **Erros:** 404 `not_found` (inexistente, inativo, sem variante ativa). O corpo do 404
  inclui `category: CategoryRef | null` (categoria principal, se o produto existir inativo)
  para a página 404 sugerir produtos (UX §4.4.9).
- **Notas:** se o path da SPA difere de `url_path`, a SPA faz `replace` para o canônico.
  `?sku=` na URL da SPA seleciona a variante (resolvido no front a partir de `variants`).

#### `GET /products/{slug}/related`

- **Resposta 200:** `{ data: ProductCard[] }` (até 8; mesma categoria principal + categorias
  complementares; exclui o próprio).

#### `POST /products/{slug}/price-preview`

- **Auth:** opcional · **Rate:** `price-preview` · **CSRF:** sim.
- **Body:**

| Campo | Regra |
|---|---|
| `variant_id` | `required|integer`, variante **ativa** do produto `{slug}` (senão 422). |
| `quantity` | não-`SQUARE_METER`: `required`, decimal ≤ 3 casas, > 0, ≤ 100000; inteiro para UNIT/ROLL/BOX; `min/max/step` da variante. `SQUARE_METER`: `prohibited`. |
| `width_m` | `SQUARE_METER` sem largura fixa: `required`, ≤ 2 casas, 0.01–100, dentro de `[min_width_m, max_width_m]`. Com largura fixa: opcional e, se enviado, deve ser **igual** a `fixed_width_m`. Demais unidades: `prohibited`. |
| `height_m` | `SQUARE_METER`: `required`, ≤ 2 casas, 0.01–100, dentro de `[min_height_m, max_height_m]`. Demais: `prohibited`. |
| `pieces` | `SQUARE_METER`: `required|integer|1..1000`, respeitando `min/max/step` (peças, ADR-019). Demais: `prohibited`. |

- **Resposta 200:** `{ data: PricePreview }`.
- **Erros:** 404 `not_found` (produto); 422 com mensagens de RN-QTD (inclui
  `details.<campo>.suggestions` para passo inválido, e mensagem "Tente inverter largura e
  altura" quando `width_m > max_width_m` e as medidas invertidas caberiam — RN-QTD-038).
- **Notas:** sem efeitos colaterais. A faixa de preço considera **apenas** esta linha
  (o carrinho soma a variante — ADR-019 — e pode resultar em preço menor). Estoque
  insuficiente **não** é erro aqui: `stock.sufficient = false`. O front pode calcular uma
  prévia local, mas deve **exibir** os valores desta resposta (debounce 300 ms).

#### `POST /shipping/quote` — estimativa na página de produto

- **Auth:** opcional · **Rate:** `shipping-estimate` · **CSRF:** sim.
- **Body:** `postal_code` (`required`, CEP com/sem máscara), `items` (`required|array|min:1|max:10`)
  com cada item `{variant_id, quantity? | width_m?, height_m?, pieces?}` sob as mesmas regras da prévia.
- **Resposta 200:** `{ data: ShippingQuote }` com `quote_id: null`, `expires_at: null`
  (não persistida; subtotal = total das linhas resolvido pelo `PriceResolver`; cupom não considerado).
- **Erros:** 422 (`postal_code` inválido: "CEP inválido."; itens inválidos com chaves
  `items.0.quantity`…); 503 `postal_code_lookup_unavailable` não ocorre aqui (fallback por UF).
- **Notas:** texto do front: "Valor estimado para este item; o frete final é calculado no carrinho".

#### `GET /postal-codes/{cep}`

- **Auth:** nenhuma · **Rate:** `postal-code`.
- **Resposta 200:** `{ data: PostalCodeInfo }`.
- **Erros:** 422 (formato: 8 dígitos, com ou sem hífen; `00000000` inválido); 404 `not_found`
  ("CEP não encontrado."); 503 `postal_code_lookup_unavailable` (ViaCEP fora/circuito aberto —
  o formulário de endereço **não** prossegue sem cidade/IBGE, RN-CLI-022).

#### SEO (fora de `/api/v1`, respostas não-JSON — módulo `Seo`)

| Rota | Resposta |
|---|---|
| `GET /sitemap.xml` | `application/xml`; home, categorias ativas e produtos ativos (`<loc>` absoluto canônico, `<lastmod>` = `updated_at`). Cache 6 h, invalidado por `ProductSaved/Deleted`, `CategoryTreeChanged`. |
| `GET /robots.txt` | `text/plain`: `Disallow: /carrinho`, `/checkout`, `/conta`, `/entrar`, `/cadastro`, `/recuperar-senha`, `/redefinir-senha`, `/admin`, `/api/`; `Allow: /`; `Sitemap: {APP_URL}/sitemap.xml`. Em `staging`: `Disallow: /`. |
| Shell (`@seo_shell` do nginx) | Para `GET /{category}` e `GET /{category}/{product}` que não são arquivos nem rotas reservadas, nginx encaminha ao `Seo\ShellController`, que lê o `index.html` da loja (`SEO_SHELL_INDEX_PATH`) e substitui o marcador `<!--seo:head-->` por `<title>`, `meta description`, `link rel=canonical`, OG e `<script type="application/ld+json">` (Product + BreadcrumbList; preço do JSON-LD = visitante na quantidade mínima, RN-BUS-006). Status **200** (encontrado), **404** + `noindex` (slug inexistente/inativo, mesmo HTML), **301** (produto acessado por categoria não canônica → `/{primary}/{product}`). Escape: `e()` nas metas; JSON-LD com `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`. Cache `seo:shell:{path}` 15 min. Sem sessão. |

A SPA **não** chama o shell; ela usa `GET /categories/{slug}` e `GET /products/{slug}`
(os campos `seo` alimentam o `react-helmet-async`).

---

### 3.B Carrinho

Regras comuns:

- **Identificação:** cliente logado → carrinho ativo do cliente (sessão; `X-Cart-Token`
  ignorado). Visitante → `X-Cart-Token`. Sem token → `GET` devolve carrinho vazio
  (`token: null`, nada é criado); a **primeira escrita** cria o carrinho e devolve
  `X-Cart-Token` (header) + `data.token`.
- Token desconhecido, expirado, convertido ou pertencente a um cliente → `404 cart_not_found`
  (SEC-IDOR-05). O front apaga o token local e repete a requisição **sem** o header.
- Toda resposta de carrinho é o `Cart` **recalculado** (preços, estoque, cupom, pesos) —
  ADR-007. Mutations respondem o carrinho inteiro: o front faz `setQueryData(['cart'], …)`.
- Limites: 50 linhas por carrinho (RN-CAR-005); identidade da linha = variante + largura +
  altura (DATABASE [DB-10]): mesma variante (não-m²) soma `quantity`; m² com mesmas medidas
  soma `pieces`. A soma é revalidada (max, step, estoque).
- Faixas de preço usam a **soma da variante** no carrinho (ADR-019).
- Qualquer alteração de itens invalida cotações de frete anteriores (hash muda).

#### `GET /cart`

- **Auth:** opcional · **Rate:** `cart`.
- **Query (opcional):** `shipping_quote_id` (uuid) + `shipping_option_id` (string) — quando
  ambos presentes e válidos para este carrinho, `totals.shipping_cents` e
  `shipping_selection` são preenchidos (frete estimado no resumo). Inválida/expirada →
  `shipping_selection.valid = false`, `issue_code` preenchido, `shipping_cents: null`
  (**não** é erro HTTP).
- **Resposta 200:** `{ data: Cart }`.
- **Erros:** 404 `cart_not_found`.

#### `POST /cart/items`

- **Auth:** opcional · **Rate:** `cart`.
- **Body:** `variant_id` + (`quantity` | `width_m`, `height_m`, `pieces`) — mesmas regras
  da prévia de preço. Proibidos §1.7 (ex.: `unit_price_cents` → 422).
- **Resposta:** `201 { data: Cart }` quando criou linha (e/ou carrinho); `200 { data: Cart }`
  quando somou a uma linha existente. Header `X-Cart-Token` se o carrinho foi criado.
- **Erros:** 422 (regras de quantidade; variante inativa → `errors.variant_id`
  "Produto indisponível."; limite de 50 linhas → `errors.variant_id`; soma excede
  `max_quantity` → `errors.quantity`); 409 `insufficient_stock` (`items: StockIssue[]`,
  considerando a soma da variante no carrinho; nada é alterado); 404 `cart_not_found`.
- **Notas:** grava `last_seen_unit_price_cents` da linha = preço atual (base do aviso
  `price_changed`). Checagem de estoque é "suave" (sem lock); a reserva só ocorre no checkout.

#### `PATCH /cart/items/{id}`

- **Body:** não-m²: `quantity` (required). m²: pelo menos um de `pieces`, `width_m`,
  `height_m` (os ausentes mantêm o valor atual). Mesmas regras da prévia.
- **Resposta 200:** `{ data: Cart }`. Se a nova medida coincidir com outra linha da mesma
  variante, as linhas são **mescladas** (soma de peças) e a linha `{id}` deixa de existir.
- **Erros:** 404 `not_found` (item de outro carrinho) / `cart_not_found`; 422; 409 `insufficient_stock`.

#### `DELETE /cart/items/{id}`

- **Resposta 200:** `{ data: Cart }`. **Erros:** 404.
- **Notas:** "Desfazer" do front = `POST /cart/items` com a mesma configuração.

#### `DELETE /cart`

- Remove todos os itens e o cupom. **Resposta 200:** `{ data: Cart }` (vazio, mesmo token).

#### `PUT /cart/coupon`

- **Rate:** `coupon` · **Body:** `code` (`required|string|3..40`, normalizado maiúsculo).
- **Resposta 200:** `{ data: Cart }` com `coupon` preenchido (substitui cupom anterior — RN-CUP-006).
- **Erros:** 422 `errors.code` com a mensagem do motivo ("Cupom inválido ou expirado.",
  "Pedido mínimo para este cupom: R$ 200,00.", "Limite de uso deste cupom atingido.") — o
  cupom **não** é gravado. Exceção: cupom com limite por cliente aplicado por **visitante**
  é gravado com `valid: false, reason_code: 'login_required'` (200) e revalidado no login.
- **Notas:** o cupom é revalidado a cada leitura; se deixar de valer, `coupon.valid = false`
  e `blocking_reasons` inclui `coupon_invalid` (remova com `DELETE /cart/coupon`).

#### `DELETE /cart/coupon`

- **Resposta 200:** `{ data: Cart }`.

#### `POST /cart/shipping-quote`

- **Auth:** opcional · **Rate:** `shipping-quote`.
- **Body:** exatamente um de `postal_code` (CEP) ou `address_uuid` (uuid de endereço do
  cliente logado; de outro cliente → 422 `errors.address_uuid` "Endereço inválido.").
- **Resposta 200:** `{ data: ShippingQuote }` com `quote_id` e `expires_at` (TTL 30 min,
  SHIPPING §6.5); grava `carts.postal_code`. Reaproveita cotação válida idêntica (mesmo hash).
- **Erros:** 409 `cart_empty` (carrinho sem itens); 422 (CEP inválido); 404 `cart_not_found`.
- **Notas:** cupom `free_shipping` válido é considerado. O `quote_id` + `option_id`
  escolhidos são enviados ao checkout e podem ser passados em `GET /cart` para o resumo.

#### `POST /cart/acknowledge-prices`

- **Body:** vazio. **Resposta 200:** `{ data: Cart }` sem warnings `price_changed`.
- **Notas:** atualiza `last_seen_unit_price_cents` de todas as linhas para o preço atual
  (cliente viu o aviso — RN-CAR-010). Avisos de preço **não** bloqueiam o checkout.

---
### 3.C Autenticação do cliente

Todas exigem `GET /sanctum/csrf-cookie` prévio e `X-XSRF-TOKEN`. Mensagens neutras
(SECURITY §3.2). Respostas com `Cache-Control: no-store`.

#### `POST /auth/register`

- **Auth:** visitante (logado → 403 `forbidden`) · **Rate:** `register`.
- **Headers:** `X-Cart-Token` opcional (merge automático após o cadastro).
- **Body (whitelist):**

| Campo | PF (`individual`) | PJ (`company`) | Regra |
|---|---|---|---|
| `type` | `individual` | `company` | `required|in:individual,company` |
| `name` | obrigatório | obrigatório (responsável) | 3–120; PF: ≥ 2 palavras; texto puro |
| `cpf` | obrigatório | opcional | 11 dígitos, DV válido, sem sequência repetida; único entre clientes ativos |
| `email` | obrigatório | obrigatório | `email:rfc,strict`, ≤ 191, único (normalizado minúsculo) |
| `phone` | obrigatório | obrigatório | 10–11 dígitos (aceita máscara) |
| `password` | obrigatório | obrigatório | `confirmed`, 8–72, letras e números, `uncompromised`, não contém o e-mail |
| `password_confirmation` | obrigatório | obrigatório | |
| `accept_terms` | `accepted` | `accepted` | |
| `terms_version` | obrigatório | obrigatório | deve ser igual a `settings.legal.terms_version` (senão 422: recarregar termos) |
| `marketing_opt_in` | opcional | opcional | boolean, padrão `false` |
| `company.cnpj` | `prohibited` | obrigatório | 14 posições, alfanumérico, DV válido; único |
| `company.legal_name` | `prohibited` | obrigatório | 3–150 |
| `company.trade_name` | `prohibited` | opcional | ≤ 150 |
| `company.state_registration` | `prohibited` | obrigatório se não isento | 2–14 dígitos; `"ISENTO"` (qualquer caixa) ⇒ tratado como isento |
| `company.state_registration_exempt` | `prohibited` | obrigatório | boolean; `true` ⇒ `state_registration` deve ser nulo/ausente |

- **Resposta 201:** `{ data: { customer: Customer; cart_merge: CartMergeReport | null } }` —
  cliente já autenticado (sessão regenerada); e-mail de boas-vindas + verificação enfileirado.
- **Erros:** 422 (duplicidade: `errors.email` "Já existe uma conta com este e-mail.",
  `errors.cpf`, `errors["company.cnpj"]` equivalentes — SECURITY §3.2 aceita a revelação,
  mitigada por rate limit); proibidos §1.7 (`price_list_id`, `company_id`, `is_active`,
  `email_verified_at`…) → 422; 429.
- **Notas:** grava `terms_accepted_at` e `terms_accepted_ip`. Evento `CustomerRegistered`.

#### `POST /auth/login`

- **Rate:** `login` · **Headers:** `X-Cart-Token` opcional.
- **Body:** `email` (required, email), `password` (required, string ≤ 72), `remember` (boolean, opcional).
- **Resposta 200:** `{ data: { customer: Customer; cart_merge: CartMergeReport | null } }`.
- **Erros:** 422 `errors.email` = "E-mail ou senha inválidos." (sempre a mesma mensagem);
  403 `account_disabled`; 429.
- **Notas (merge — decisão: automático no login e no registro, sem endpoint explícito):**
  se `X-Cart-Token` aponta para carrinho de **visitante** válido, seus itens são mesclados
  ao carrinho ativo do cliente (DATABASE §3.5.1): soma por identidade de linha; excedentes
  são limitados ao máximo válido (múltiplo do step) e reportados em `adjustments`;
  indisponíveis vão para `dropped`; cupom do cliente prevalece, senão herda o do visitante
  revalidado. O carrinho do visitante é apagado. Após a resposta o front **apaga** o token
  local e refaz `['cart']`. Evento `CustomerAuthenticated` (listener síncrono `MergeGuestCart`).

#### `POST /auth/logout`

- **Auth:** `customer`. **Resposta 204.** Invalida a sessão inteira (se o mesmo navegador
  tinha sessão admin, ela também cai — ADR-023/P7).

#### `POST /auth/forgot-password`

- **Rate:** `password-reset` · **Body:** `email` (required, email).
- **Resposta 200** sempre: `{ data: { message: "Se o e-mail existir, enviaremos instruções." } }`.

#### `POST /auth/reset-password`

- **Rate:** `password-reset`.
- **Body:** `token` (required), `email` (required), `password` + `password_confirmation`
  (mesmas regras do cadastro).
- **Resposta 200:** `{ data: { message: "Senha alterada." } }` (não autentica; o front leva a `/entrar`).
- **Erros:** 422 `errors.token` "Este link expirou ou é inválido.".
- **Notas:** invalida as outras sessões do cliente. Link do e-mail:
  `{STOREFRONT_URL}/redefinir-senha?token=…&email=…`.

#### Verificação de e-mail (MVP: enviada, **não bloqueante** — RN-CLI-009)

- `POST /auth/email/verify` — **Body:** `uuid`, `hash`, `expires`, `signature` (vindos do
  link `{STOREFRONT_URL}/verificar-email?uuid=…&hash=…&expires=…&signature=…`; a página da
  SPA faz o POST). **200** `{ data: { verified: true } }`; link inválido/expirado → 422 `errors.signature`.
  Não exige sessão.
- `POST /auth/email/verification-notification` — **Auth:** `customer` · **Rate:** `password-reset`.
  **200** `{ data: { message: "Enviamos um novo link de confirmação." } }`; já verificado → 200 idem.

---

### 3.D Área do cliente `/me`

Todas: **Auth** `customer` (senão 401), **Rate** `customer`, `Cache-Control: no-store`.
Recursos de outro cliente → **404** (nunca 403).

#### `GET /me`

- **Resposta 200:** `{ data: Customer }`. Usado pelo `useAuth()` (401 ⇒ visitante).

#### `PATCH /me`

- **Body (whitelist):** `name` (3–120), `phone` (10–11 dígitos), `marketing_opt_in` (boolean;
  grava `marketing_opt_in_at`), `cpf` (**somente** se hoje é `null` — PJ que não informou;
  senão 422 "CPF não pode ser alterado. Fale conosco.").
- **Proibidos adicionais:** `email` (troca de e-mail fora do MVP → 422), `type`, `company`.
- **Resposta 200:** `{ data: Customer }`.

#### `PUT /me/password`

- **Body:** `current_password` (required), `password`, `password_confirmation` (regras do
  cadastro; diferente da atual).
- **Resposta 204.** Sessão atual regenerada; outras sessões invalidadas.
- **Erros:** 422 `errors.current_password` "Senha atual incorreta.".

#### `PATCH /me/company` (somente PJ)

- **Body:** `legal_name`, `trade_name`, `state_registration`, `state_registration_exempt`
  (mesmas regras do cadastro). `cnpj` → 422 (imutável, RN-CLI-005).
- **Resposta 200:** `{ data: Customer }`. **Erros:** 403 `forbidden` para PF.

#### `POST /me/terms-acceptance`

- **Body:** `terms_version` (deve ser a vigente), `accept_terms` (`accepted`).
- **Resposta 200:** `{ data: Customer }` (`terms.needs_acceptance = false`).

#### `GET /me/data-export`

- **Resposta 200:** `application/json` com `Content-Disposition: attachment; filename="meus-dados.json"`
  contendo perfil, empresa, endereços, pedidos (snapshots) e consentimentos (LGPD, RN-LGPD-004).
- **Rate:** `customer` (+ no máx. 3/h por cliente).

#### Endereços

| Endpoint | Descrição |
|---|---|
| `GET /me/addresses` | `{ data: Address[] }` — padrão primeiro, depois mais recentes. Sem paginação (máx. 10). |
| `POST /me/addresses` | Cria. **201** `{ data: Address }`. |
| `GET /me/addresses/{uuid}` | **200** `{ data: Address }`. |
| `PATCH /me/addresses/{uuid}` | Atualiza (campos parciais). **200** `{ data: Address }`. |
| `DELETE /me/addresses/{uuid}` | **204**. Soft delete; se era o padrão, o mais recente vira padrão (RN-CLI-023). |
| `POST /me/addresses/{uuid}/default` | Define como padrão. **200** `{ data: Address }`. |

**Body de criação/edição (whitelist):**

| Campo | Regra |
|---|---|
| `postal_code` | `required` (criação), CEP; o backend consulta `PostalCodeLookup` e **deriva** `city`, `state`, `city_ibge_code` |
| `street` | `required`, ≤ 200 (editável: CEP geral não traz rua) |
| `number` | `required`, ≤ 20 (ou `"S/N"`) |
| `complement` | opcional, ≤ 100 |
| `district` | `required`, ≤ 100 |
| `reference` | opcional, ≤ 200 |
| `recipient_name` | `required`, 3–150 |
| `phone` | `required`, 10–11 dígitos |
| `label` | opcional, ≤ 50 |
| `is_default` | opcional boolean (o primeiro endereço é sempre padrão) |

- `city`, `state`, `city_ibge_code` enviados → **ignorados** (sempre derivados do CEP).
- **Erros:** 422 (`errors.postal_code` "CEP não encontrado."; 11º endereço →
  `errors.address` "Limite de 10 endereços."); 503 `postal_code_lookup_unavailable`
  (endereço não é salvo); 404 (endereço de outro cliente).

#### Pedidos

##### `GET /me/orders`

- **Query:** `status` (lista de `OrderStatus`), `page`, `per_page` (padrão 10).
- **Resposta 200:** `Paginated<OrderSummary>` — mais recentes primeiro (`placed_at DESC`).

##### `GET /me/orders/{uuid}`

- **Resposta 200:** `{ data: OrderDetail }` (inclui PIX do pagamento mais recente se pendente).
- **Erros:** 404 (inexistente ou de outro cliente — SEC-IDOR-01).

##### `GET /me/orders/{uuid}/status` — polling leve (PIX)

- **Resposta 200:** `{ data: OrderStatusPoll }`. Polling de 5 s enquanto
  `payment_status = 'pending'` e aba visível; parar em `paid`/`cancelled` ou após 35 min.
- **Rate:** `customer` (60/min comporta 1 req/5 s).

##### `POST /me/orders/{uuid}/cancel`

- **Body:** `reason` (opcional, ≤ 500, texto puro).
- **Resposta 200:** `{ data: OrderDetail }` (`status: cancelled`, `cancel_reason_code: customer`).
- **Erros:** 409 `invalid_status_transition` se o status ≠ `pending_payment`; 404.
- **Efeitos:** `release` da reserva, cupom liberado, pagamento pendente marcado `cancelled`
  no gateway quando suportado, histórico (ator customer), `OrderCancelled`.

##### `POST /me/orders/{uuid}/cancellation-request` — pedido pago

- **Body:** `reason` (`required`, 5–500).
- **Resposta 200:** `{ data: OrderDetail }` com `cancellation_request` preenchido (status **não** muda — RN-PED-020).
- **Erros:** 409 `invalid_status_transition` se status ∉ {`paid`, `processing`} ou já solicitado.
- **Efeitos:** grava `cancellation_requested_at/…_reason`; notifica `seller`/`finance` (painel + e-mail).

##### `POST /me/orders/{uuid}/reorder`

- **Body:** vazio · **Rate:** `cart`.
- **Resposta 200:** `{ data: ReorderReport }` (sempre 200, mesmo com `added_items = 0`).
- **Notas:** para cada item (RN-PED-040…045): variante ativa + regras atuais + estoque
  (adiciona o máximo válido se insuficiente e ≥ mínimo → `adjusted`); preço **atual**. Adiciona
  ao carrinho ativo do cliente (somando por identidade de linha). Funciona em qualquer status.

##### `POST /me/orders/{uuid}/payment` — gerar PIX novamente

- **Headers:** `Idempotency-Key` opcional · **Rate:** `checkout`.
- **Body:** `payment_method` (`required|in:pix`).
- **Resposta:** `200 { data: OrderPayment }` quando já existe pagamento pendente com PIX
  válido (devolve o mesmo) ou quando um pagamento pendente sem PIX (gateway falhou) é
  iniciado agora; `201 { data: OrderPayment }` quando um **novo** pagamento é criado
  (anterior `failed`/`expired`).
- **Erros:** 409 `invalid_status_transition` (pedido ≠ `pending_payment` ou `expires_at`
  vencido); 503 `payment_gateway_unavailable`; 404.
- **Notas:** o novo PIX expira em `orders.expires_at` (o prazo do pedido **não** é estendido).

#### `GET /me/reorder-suggestions`

- **Query:** `limit` (1–12, padrão 6).
- **Resposta 200:** `{ data: ReorderSuggestion[] }` — variantes de pedidos **pagos**
  recentes (uma por variante, última configuração), com preço atual; exclui indisponíveis.

#### Notificações do cliente (timeline in-app)

- `GET /me/notifications` — **Query:** `unread` (`1`), `page`, `per_page` (≤ 50). **200** `Paginated<AppNotification>`.
- `POST /me/notifications/read` — **Body:** `ids` (array de uuid, opcional; ausente = todas). **204**.

---

### 3.E Checkout

Pré-requisitos: cliente logado; carrinho do cliente com itens; endereço do cliente;
cotação feita via `POST /cart/shipping-quote` para o **CEP do endereço** escolhido. O
cupom vem do carrinho (`PUT /cart/coupon`) — **não** é enviado no corpo.

#### `POST /checkout/preview` — resumo recalculado, sem efeitos de negócio

- **Auth:** `customer` · **Rate:** `customer`.
- **Body:**

| Campo | Regra |
|---|---|
| `address_uuid` | `required|uuid`, endereço do cliente (senão 422 "Endereço inválido.") |
| `shipping_quote_id` | `nullable|uuid` (ausente ⇒ resumo sem frete, `blocking` inclui `shipping_required`) |
| `shipping_option_id` | `required_with:shipping_quote_id|string|max:100` |
| `payment_method` | `required|in:pix` |

- **Resposta 200:** `{ data: CheckoutSummary }` — **sempre 200** quando o corpo é válido;
  problemas (carrinho vazio, item indisponível, estoque, cupom inválido, frete divergente,
  limite de pendentes) aparecem em `blocking[]` e `can_place_order = false`.
  Divergência de frete: `shipping_quote` traz a **nova cotação** (SHIPPING §7; única
  "escrita" permitida: persistir essa nova cotação).
- **Erros:** 422 (validação/endereço).
- **Notas:** `totals.total_cents` é o valor que o front deve enviar como `expected_total_cents`.

#### `POST /checkout` — criar pedido (PIX)

- **Auth:** `customer` · **Rate:** `checkout` · **Headers:** `Idempotency-Key` (**obrigatório**, UUID).
- **Body (whitelist):**

| Campo | Regra |
|---|---|
| `address_uuid` | `required|uuid`, do cliente |
| `shipping_quote_id` | `required|uuid` |
| `shipping_option_id` | `required|string|max:100` |
| `payment_method` | `required|in:pix` |
| `expected_total_cents` | `required|integer|min:0` — **só comparação** (ADR-021) |
| `notes` | `nullable|string|max:500` (texto puro) |
| `accept_terms` | `accepted` (termos de compra, UX §4.6.5) |

- Proibidos §1.7 (`total_cents`, `shipping_cents`, `discount_cents`, `customer_id`,
  `status`, `items`, `coupon_code`…) → 422 sem efeito.
- **Ordem de validação** (a primeira falha responde):
  1. `Idempotency-Key` ausente/inválido → 422 `errors.idempotency_key`.
  2. Pedido existente com a mesma chave: fingerprint igual → **200 replay** (inicia o PIX
     se ainda não existir); diferente → **409 `idempotency_conflict`**.
  3. ≥ 3 pedidos `pending_payment` → **409 `too_many_pending_orders`**.
  4. Carrinho vazio → **409 `cart_empty`**; item indisponível/inválido → **422 `cart_invalid`**.
  5. Endereço inválido → 422 `errors.address_uuid`; perfil incompleto → 422 `errors.profile`.
  6. Estoque (sob lock, soma por variante) → **409 `insufficient_stock`** (`items`).
  7. Frete (SHIPPING §7) → **409 `shipping_*`** (`shipping_quote` nova).
  8. Cupom (sob lock) → **409 `coupon_invalid`** (`coupon`, `summary` sem o cupom).
  9. Total recalculado ≠ `expected_total_cents` → **409 `price_changed`** (`summary`).
- **Resposta 201:** `{ data: CheckoutResult }` (`replayed: false`) — pedido
  `pending_payment`/`pending`, estoque reservado, cupom consumido, carrinho convertido, PIX gerado.
- **Resposta 200:** replay idempotente (`replayed: true`, mesmo `order.uuid`).
- **Resposta 503 `payment_gateway_unavailable`:** pedido **criado** mas PIX não gerado;
  corpo `{message, code, order: {uuid, number}}`. O front repete o **mesmo** request (mesma
  chave, backoff 2 s/4 s) → 200 com o PIX; ou envia o cliente a `/checkout/pedido/{uuid}`
  que usa `POST /me/orders/{uuid}/payment`.
- **Efeitos/eventos:** `OrderPlaced` (e-mail com PIX, alerta admin), `PaymentCreated`;
  gateway chamado **fora** da transação.

---

### 3.F Webhooks e endpoints de desenvolvimento

#### `POST /webhooks/{provider}`

- `provider ∈ {mercadopago, sandbox}` (allowlist); `sandbox` **não é registrado** em
  `production` → 404. Outro valor → 404.
- **Grupo `webhook`:** sem sessão, sem CSRF, corpo ≤ 64 KB, `throttle:webhooks`.
- **Headers:** `x-signature: ts=<unix>,v1=<hex>` e `x-request-id: <id>` (formato Mercado Pago;
  o `sandbox` usa o mesmo formato com `SANDBOX_WEBHOOK_SECRET`).
- **Assinatura:** `v1 = HMAC_SHA256(secret, "id:{data.id};request-id:{x-request-id};ts:{ts};")`
  (hex minúsculo), comparada com `hash_equals`; `|now − ts| ≤ 300 s`. Aceita também
  `*_WEBHOOK_SECRET_PREVIOUS` durante rotação.
- **Corpo (Mercado Pago, relevante):** `{ "id": <event id>, "type": "payment", "action": "payment.updated", "data": { "id": "<payment id no gateway>" }, "date_created": "…", "live_mode": true }`.
  **Sandbox:** `{ "id": "evt_…", "type": "payment", "action": "payment.updated", "data": { "id": "<payments.external_id>" }, "date_created": "…" }`.
- **Respostas:**
  - Assinatura ausente/inválida/fora da janela → **401 com corpo vazio** (exceção ao
    formato de erro; nada é gravado em `webhook_events` para dedupe — DB-12).
  - Válido (novo **ou** duplicado) → **200 `{"status":"ok"}`** (duplicado não reprocessa).
- **Processamento:** job `ProcessWebhookEvent` (fila `webhooks`) chama `getPayment(data.id)`
  (fonte da verdade) e aplica a transição idempotente (ADR-022); valor/moeda divergente →
  não aprova (`PaymentAmountMismatch`). Pagamento não encontrado localmente → job falha e
  é re-tentado (Q-16); reconciliação a cada 5 min cobre perdas.

#### Endpoints de desenvolvimento — **somente `APP_ENV ∈ {local, testing}` com `payments.driver = sandbox`**

Arquivo de rotas `routes/dev.php` do módulo Payments, carregado **apenas** nessas
condições (teste de arquitetura garante que não existem em `production`/`staging`; em
`staging` use o comando `php artisan payments:sandbox-approve {order_number}`).

| Endpoint | Efeito |
|---|---|
| `POST /dev/payments/{order_uuid}/approve` | Monta e envia internamente um webhook `sandbox` **assinado** (mesmo pipeline de produção) marcando o pagamento pendente como aprovado. Body opcional: `amount_cents` (int; para simular divergência). **202** `{ data: { status: "dispatched", webhook_event_external_id: string } }`. |
| `POST /dev/payments/{order_uuid}/fail` | Idem, com status `rejected` no gateway sandbox. **202**. |

- **Auth:** `customer` dono do pedido (o link "Simular pagamento" da storefront aparece só
  com `import.meta.env.DEV`). Pedido sem pagamento pendente → 409 `invalid_status_transition`.

---
### 3.G Painel `/admin`

Regras comuns do painel:

- **Auth:** `admin` + `admin.fresh`; cada rota declara permissão (`permission:<nome>,admin`);
  sem permissão → `403 forbidden`. `super-admin` passa em tudo (`Gate::before`). Tabela
  completa na §6.
- **Rate:** `admin` (300/min); `admin-heavy` onde indicado; `uploads` em uploads.
- **Listagens:** `Paginated<T>`, `per_page` padrão 25; `q` busca; filtros simples; `sort`
  com allowlist. `include_deleted=1` (onde houver soft delete) inclui excluídos.
- **Criação** → `201 {data}`; **edição** `PATCH` parcial → `200 {data}` (só campos enviados;
  coleções aninhadas enviadas **substituem** a coleção, exceto `variants` de produto, que é
  *upsert*); **exclusão** → `204` (soft delete onde ADR-016 prevê).
- `expected_updated_at` opcional nos `PATCH` listados na §1.9 → `409 stale_resource`.
- Toda escrita grava `audit_logs` (ator, diff sem dados sensíveis, IP, `request_id`).
- Campos de dinheiro em centavos; dimensões de material em metros (3 casas); embalagem em cm (1 casa).

#### 3.G.1 Autenticação e conta do admin

| Endpoint | Auth / Rate | Body | Resposta | Erros |
|---|---|---|---|---|
| `POST /admin/auth/login` | visitante · `login` | `email`, `password` | `200 { data: AdminMe }` | 422 "E-mail ou senha inválidos."; 403 `account_disabled`; 429 |
| `POST /admin/auth/logout` | `admin` | — | `204` | — |
| `POST /admin/auth/forgot-password` | visitante · `password-reset` | `email` | `200 { data: { message } }` (neutra) | 429 |
| `POST /admin/auth/reset-password` | visitante · `password-reset` | `token`, `email`, `password`, `password_confirmation` (min 12, maiúsc./minúsc., número, símbolo, `uncompromised`) | `200 { data: { message } }` | 422 |
| `GET /admin/me` | `admin` (sem permissão específica) | — | `200 { data: AdminMe }` | 401 |
| `PUT /admin/me/password` | `admin` | `current_password`, `password`, `password_confirmation` | `204` | 422 |

Notas: login regenera sessão, audita `admin_user.login` / falhas; link de reset →
`{APP_URL}/admin/redefinir-senha?token=…&email=…` (expira em 30 min).

#### 3.G.2 Dashboard

`GET /admin/dashboard` — **Perm:** `dashboard.view` · **Rate:** `admin-heavy` (30/min).
**Resposta 200:** `{ data: Dashboard }`. Blocos sem permissão vêm `null` (ver tipo).
Definições: "vendas" = pedidos com `paid_at` no período (hoje/mês em America/Sao_Paulo),
receita = RN-REL-001; `change_bp` compara com ontem / mês anterior até o mesmo dia;
`todays_deliveries` = pedidos `own_delivery`/`pickup` em `paid|processing|shipped|ready_for_pickup`
com `estimated_delivery_date <= hoje`. Front: `refetchInterval` 60 s.

#### 3.G.3 Categorias

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/categories` | `products.view` | `{ data: AdminCategory[] }` — **árvore** completa (inclui inativas; `include_deleted=1` inclui excluídas). |
| `GET /admin/categories/{id}` | `products.view` | `{ data: AdminCategory }` (sem `children` aninhados profundos). |
| `POST /admin/categories` | `products.manage` | Cria. **201**. |
| `PATCH /admin/categories/{id}` | `products.manage` | Edita. **200**. |
| `DELETE /admin/categories/{id}` | `products.manage` | Soft delete. **204**; **409 `resource_in_use`** se tem filhos ativos ou é categoria principal de produto ativo (`blockers` lista-os — RN-CAT-008). |
| `POST /admin/categories/reorder` | `products.manage` | Body `{ parent_id: number|null, ids: number[] }` (todos os irmãos, na nova ordem) → `position` = índice. **204**. |
| `POST /admin/categories/{id}/image` | `products.manage` · `uploads` | `multipart`: `file` (jpeg/png/webp ≤ 5 MB). **200** `{ data: AdminCategory }`. |
| `DELETE /admin/categories/{id}/image` | `products.manage` | **204**. |

**Body (criar/editar):** `name` (required, 2–120), `slug` (opcional na criação — gerado do
nome; `^[a-z0-9]+(-[a-z0-9]+)*$`, ≤ 140, único entre não excluídos, **não reservado**:
`busca, carrinho, checkout, conta, entrar, cadastro, recuperar-senha, redefinir-senha,
institucional, admin, api, sanctum, sitemap.xml, robots.txt`), `parent_id` (nullable; sem
ciclo; profundidade resultante ≤ 3), `description_html` (≤ 20 000, sanitizado — ADR-024),
`meta_title` (≤ 120), `meta_description` (≤ 320), `is_active` (boolean; desativar sujeito a
RN-CAT-008 → 409), `position` (int ≥ 0). **Proibidos:** `products_count`, `depth`.
Efeito: `CategoryTreeChanged`.

#### 3.G.4 Marcas

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/brands` | `products.view` | `Paginated<AdminBrand>`; `q`, `is_active`, `sort` ∈ `name,-name,created_at,-created_at`. |
| `GET /admin/brands/{id}` | `products.view` | `{ data: AdminBrand }` |
| `POST /admin/brands` · `PATCH /admin/brands/{id}` | `products.manage` | Body: `name` (2–120, único), `slug` (regra de slug), `is_active`. |
| `DELETE /admin/brands/{id}` | `products.manage` | Soft delete **204**. Produtos mantêm a marca (RN-CAT-009). |
| `POST /admin/brands/{id}/logo` · `DELETE …/logo` | `products.manage` | Upload como em categorias. |

#### 3.G.5 Produtos, variantes e imagens

**Decisão:** o produto é criado/editado com o array `variants` embutido (*upsert*);
exclusão de variante e imagens têm endpoints próprios. Estoque **nunca** é editado aqui
(apenas `initial_stock` na criação de variante).

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/products` | `products.view` | `Paginated<AdminProductListItem>`. Query: `q` (nome ou SKU), `category_id` (inclui descendentes), `brand_id`, `status` ∈ `active,inactive,all` (padrão `all`), `sale_unit`, `low_stock=1`, `featured=1`, `include_deleted=1`, `sort` ∈ `name,-name,created_at,-created_at,updated_at,-updated_at,min_price,-min_price`. |
| `GET /admin/products/{id}` | `products.view` | `{ data: AdminProduct }` (`cost_cents` só com `prices.manage` ou `reports.view`; senão `null`). |
| `POST /admin/products` | `products.manage` (+ `prices.manage` para campos de preço; `inventory.move` se `initial_stock` > 0) | Cria produto + variantes + linhas de `inventory`. **201** `{ data: AdminProduct }`. |
| `PATCH /admin/products/{id}` | idem | Edita; `variants` = upsert. **200**. |
| `DELETE /admin/products/{id}` | `products.manage` | Soft delete (produto e variantes). **204**. |
| `POST /admin/products/bulk` | `products.manage` | Body `{ ids: number[] (1–100), action: 'activate'|'deactivate'|'delete'|'set_primary_category', category_id?: number }`. **200** `{ data: { succeeded: number[]; failed: { id: number; errors: string[] }[] } }` (ativação respeita RN-CAT-012 por item). |
| `GET /admin/products/slug-availability` | `products.view` | Query `slug`, `ignore_id?`. **200** `{ data: { available: boolean; suggestion: string | null } }`. |
| `DELETE /admin/products/{id}/variants/{variantId}` | `products.manage` | Soft delete da variante. **204**; **409 `resource_in_use`** se for a última variante ativa de produto ativo. |
| `GET /admin/variants` | `products.view` | Picker: `Paginated<AdminVariantPickerItem>`; `q` (SKU/nome), `product_id`, `is_active`. |
| `GET /admin/variants/sku-availability` | `products.view` | Query `sku`, `ignore_id?` → `{ data: { available: boolean; used_by: { product_id: number; product_name: string } | null } }`. |
| `POST /admin/products/{id}/images` | `products.manage` · `uploads` | `multipart/form-data`: `file` (required; `image`, mimes jpeg/png/webp, MIME real, ≤ 5 MB, 200–8000 px, ≤ 40 MP), `alt` (≤ 255), `variant_id` (variante do mesmo produto). **201** `{ data: AdminProductImage }` com `urls: null` até o job terminar. Máx. 10 imagens → 422. |
| `PATCH /admin/products/{id}/images/{imageId}` | `products.manage` | Body `alt`, `variant_id` (nullable). **200**. |
| `DELETE /admin/products/{id}/images/{imageId}` | `products.manage` | **204** (arquivos removidos por job). |
| `POST /admin/products/{id}/images/reorder` | `products.manage` | Body `{ ids: number[] }` (todas as imagens do produto) → `position` = índice (0 = capa). **200** `{ data: AdminProductImage[] }`. |

**Body do produto (criar: obrigatórios marcados ✱; editar: todos opcionais):**

| Campo | Regra |
|---|---|
| `name` ✱ | 3–150 (RN-CAT-001), texto puro |
| `slug` | opcional (gerado); regra de slug, ≤ 220, único, não reservado |
| `short_description` | ≤ 500, texto puro |
| `description_html` | ≤ 20 000, sanitizado (ADR-024) |
| `specifications` | array ≤ 30 de `{label: 1–60, value: 1–200}` (texto puro) |
| `sale_unit` ✱ | `SaleUnit`; **imutável** se existe pedido (422 `errors.sale_unit`) |
| `brand_id` | nullable, marca existente |
| `primary_category_id` ✱ | categoria existente; incluída automaticamente em `category_ids` |
| `category_ids` | array de ids (substitui o conjunto) |
| `min_quantity` ✱ | decimal > 0; inteiro para UNIT/ROLL/BOX/SQUARE_METER; múltiplo de `quantity_step` |
| `max_quantity` | nullable, ≥ `min_quantity`, múltiplo do step |
| `quantity_step` ✱ | decimal > 0; inteiro para UNIT/ROLL/BOX/SQUARE_METER |
| `min_billable_area_m2` | só SQUARE_METER; > 0, 3 casas |
| `fixed_width_m` | só SQUARE_METER/LINEAR_METER; > 0; exclusivo com `min/max_width_m` |
| `min_width_m`, `max_width_m`, `min_height_m`, `max_height_m` | só SQUARE_METER; > 0; máx ≥ mín; ≤ 100 |
| `meta_title` ≤ 120 · `meta_description` ≤ 320 | |
| `is_active` | ativar exige RN-CAT-012 (≥ 1 variante ativa com preço > 0 e peso > 0 — exceto KG — e embalagem completa conforme SHIPPING §2.2; categoria principal ativa); falha → 422 `errors.is_active` com os motivos |
| `is_featured`, `pickup_only` | boolean |
| `variants` ✱ (criação ≥ 1) | array (máx. 50) de **VariantInput** — upsert: item com `id` atualiza a variante (do produto); sem `id` cria. Variantes não enviadas **não** são alteradas. |
| **Proibidos** | `on_hand`, `reserved`, `available`, `activation_issues`, `sale_unit_locked`, `url_path` |

**VariantInput:**

| Campo | Regra |
|---|---|
| `id` | opcional (update) — deve pertencer ao produto |
| `sku` ✱ | `^[A-Z0-9-]{3,40}$` após normalização; único entre não excluídos (inclui excluídos: SKU não reutilizável — RN-CAT-013) |
| `gtin` | nullable, 8–14 dígitos |
| `name` ✱ | 1–200 |
| `attributes` | objeto `{string(1–40): string(1–100)}`, ≤ 10 chaves |
| `price_cents` ✱ | int > 0 · requer `prices.manage` para criar/alterar |
| `promo_price_cents` | nullable, > 0 e < `price_cents` · `prices.manage` |
| `promo_starts_at`, `promo_ends_at` | nullable ISO-8601; fim > início · `prices.manage` |
| `cost_cents` | nullable, ≥ 0 · `prices.manage` |
| `weight_grams` ✱ | int ≥ 0 (0 = ausente; bloqueia ativação exceto KG) |
| `package_length_cm`, `package_width_cm`, `package_height_cm` | nullable, > 0, 1 casa |
| `roll_length_m` | nullable, > 0 |
| `units_per_box`, `units_per_package` | nullable int > 0 |
| `fixed_width_m` | nullable; override da largura fixa (só SQUARE_METER/LINEAR_METER) |
| `is_active` | boolean |
| `position` | int ≥ 0 |
| `initial_stock` | só em variante **nova**; decimal ≥ 0 (3 casas); > 0 gera movimento `in` "Estoque inicial" e exige `inventory.move` |
| `low_stock_threshold` | nullable decimal ≥ 0 (grava em `inventory`; exige `inventory.adjust` se alterado) |

Erros: 422 com chaves `variants.N.campo`; 403 `forbidden` se enviar campo de preço
**com valor diferente do atual** sem `prices.manage` (ou `initial_stock > 0` sem `inventory.move`).
Efeitos: `ProductSaved` (reindexação, sitemap); `ProductSlugChanged` quando o slug muda.

#### 3.G.6 Preços: faixas, tabelas, preços de cliente

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/variants/{variantId}/price-tiers` | `products.view` | `{ data: { base: PriceTier[]; price_lists: { price_list: PriceList; tiers: PriceTier[] }[] } }` |
| `PUT /admin/variants/{variantId}/price-tiers` | base: `prices.manage`; lista: `pricing.manage` | Substitui o conjunto de uma tabela. Body `{ price_list_id: number|null, tiers: {min_quantity: Decimal3 (>0, único), price_cents: int>0}[] (≤ 20) }`. Preços **não crescentes** com a quantidade (RN-PRC-005/EC-047) → senão 422 `tiers.N.price_cents`. `tiers: []` remove todas. **200** mesmo formato do GET. |
| `GET /admin/price-lists` | `products.view` | `{ data: PriceList[] }` (poucas; sem paginação). |
| `GET /admin/price-lists/{id}` | `products.view` | `{ data: PriceList }` |
| `POST /admin/price-lists` · `PATCH /admin/price-lists/{id}` | `pricing.manage` | Body: `code` (`^[a-z0-9_]+$`, ≤ 40, único; imutável após criação), `name` (≤ 120), `kind`, `discount_bp` (nullable, 1–9999), `is_default` (definir outra como default desmarca a anterior), `is_active`. |
| `DELETE /admin/price-lists/{id}` | `pricing.manage` | **204**; 409 `resource_in_use` se atribuída a clientes/empresas ou `is_default`. |
| `GET /admin/price-lists/{id}/tiers` | `products.view` | `Paginated<PriceTier & { variant: AdminVariantPickerItem }>`; `q` (SKU/nome). |
| `GET /admin/customer-prices` | `customers.view` | `Paginated<CustomerPrice>`; filtros `customer_id`, `company_id`, `variant_id`, `active_at` (ISO; vigentes nesse instante). |
| `POST /admin/customer-prices` | `pricing.manage` | Body: exatamente um de `customer_id` / `company_id`; `variant_id`; `price_cents` (>0); `starts_at`, `ends_at` (nullable; fim > início). Sobreposição de vigência (EXCLUDE) → 422 `errors.starts_at` "Já existe preço vigente neste período.". **201**. |
| `PATCH /admin/customer-prices/{id}` | `pricing.manage` | `price_cents`, `starts_at`, `ends_at`. **200**. |
| `DELETE /admin/customer-prices/{id}` | `pricing.manage` | **204** (hard delete, auditado). |

#### 3.G.7 Promoções e cupons

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/promotions` | `products.view` | `Paginated<Promotion>`; `q`, `status` ∈ `scheduled,active,ended,inactive`, `sort` ∈ `starts_at,-starts_at,name`. |
| `GET /admin/promotions/{id}` | `products.view` | `{ data: Promotion }` |
| `POST /admin/promotions` · `PATCH …/{id}` | `promotions.manage` | Body: `name` (≤ 150), `description` (≤ 500), `discount_type`, `value` (percent: 1–10000 bp; fixed: centavos > 0), `scope`, `starts_at` (required), `ends_at` (nullable, > início), `is_active`, `priority` (int), `product_ids[]`, `category_ids[]`, `brand_ids[]` (substituem os alvos; `scope=targeted` exige ≥ 1 alvo). |
| `DELETE /admin/promotions/{id}` | `promotions.manage` | Soft delete **204**. |
| `POST /admin/promotions/{id}/preview` | `products.view` | Body `{ variant_ids: number[] (≤ 20) }` → `{ data: { variant_id: number; sku: string; base_price_cents: Cents; promo_price_cents: Cents }[] }` (UX "R$ 15,90 → R$ 13,52"). |
| `GET /admin/coupons` | `coupons.manage` ou `promotions.manage` | `Paginated<Coupon>`; `q` (código), `status`, `type`. |
| `GET /admin/coupons/{id}` | idem | `{ data: Coupon }` |
| `GET /admin/coupons/{id}/redemptions` | idem | `Paginated<CouponRedemption>` |
| `POST /admin/coupons` · `PATCH …/{id}` | `coupons.manage` ou `promotions.manage` | Body: `code` (3–40, `^[A-Z0-9_-]+$` após normalizar; único), `description` (≤ 255), `type`, `value` (percent 1–10000; fixed > 0; free_shipping = 0), `min_order_cents` (≥ 0), `max_discount_cents` (nullable > 0, só percent), `starts_at`, `ends_at`, `usage_limit`, `usage_limit_per_customer` (nullable > 0), `is_active`. **Proibido:** `times_used`. |
| `DELETE /admin/coupons/{id}` | idem | Soft delete **204**. |
| `POST /admin/coupons/generate-code` | idem | **200** `{ data: { code: string } }` (8 caracteres aleatórios únicos). |

#### 3.G.8 Estoque

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/inventory` | `inventory.view` | `Paginated<InventoryItem>`. Query: `q` (SKU/nome), `category_id`, `brand_id`, `sale_unit`, `low_stock=1`, `product_active` (`1`/`0`), `sort` ∈ `sku,-sku,available,-available,updated_at,-updated_at`. |
| `GET /admin/inventory/{variantId}` | `inventory.view` | `{ data: InventoryItem }` |
| `PATCH /admin/inventory/{variantId}` | `inventory.adjust` | Body `{ low_stock_threshold: Decimal3 | null }` (null = usa o padrão). **200**. |
| `GET /admin/inventory/{variantId}/movements` | `inventory.view` | `Paginated<InventoryMovement>`; `type` (lista), `date_from`, `date_to`, `order_id`, `sort` ∈ `-created_at` (padrão), `created_at`. `format=csv` (+ `reports.export`) exporta (RN-EST-024). |
| `GET /admin/inventory/movements` | `inventory.view` | Mesmo formato, todas as variantes; filtros + `variant_id`, `actor_id`. |
| `POST /admin/inventory/{variantId}/entries` | `inventory.move` | Entrada (`in`). Body: `quantity` (decimal > 0, 3 casas, ≤ 100000; inteiro em UNIT/ROLL/BOX), `reason` (required, 3–255 — o front compõe "Compra de fornecedor — NF 4521"). **201** `{ data: { movement: InventoryMovement; inventory: InventoryItem } }`. |
| `POST /admin/inventory/{variantId}/adjustments` | `inventory.adjust` | Ajuste (`adjust`). Body: `new_on_hand` (decimal ≥ 0), `reason` (3–255), `expected_on_hand` (opcional: valor que o operador viu; se ≠ atual → **409 `stale_resource`** "O estoque mudou enquanto você editava."). `new_on_hand < reserved` → 422 `errors.new_on_hand` "Existem X reservados em pedidos pendentes." (RN-EST-021); `new_on_hand` = atual → 422. **201** `{ data: { movement; inventory } }`. |

Notas: todas as escritas passam pelo `InventoryService` (lock `FOR UPDATE`), auditadas;
podem emitir `StockLow`. Movimentos são imutáveis (não há PATCH/DELETE).

#### 3.G.9 Pedidos

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/orders` | `orders.view` | `Paginated<AdminOrderListItem>`. Query: `q` (número, nome/e-mail do cliente, CPF/CNPJ do snapshot), `status` (lista), `payment_status` (lista), `payment_method`, `shipping_method_type` (lista), `shipping_method_id`, `customer_id`, `date_from`/`date_to` (sobre `placed_at`), `paid_from`/`paid_to` (sobre `paid_at`), `cancellation_requested=1`, `sort` ∈ `-placed_at` (padrão), `placed_at`, `paid_at` (fila "a separar": `status=paid&sort=paid_at`), `-total_cents`, `total_cents`. |
| `GET /admin/orders/status-counts` | `orders.view` | Mesmos filtros exceto `status` → `{ data: Record<OrderStatus, number> & { all: number; cancellation_requests: number } }` (abas rápidas). |
| `GET /admin/orders/{id}` | `orders.view` | `{ data: AdminOrder }`. `payments[].transactions` só com `payments.view`. `allowed_transitions`/`can_cancel` já filtrados pela permissão do admin atual. |
| `PATCH /admin/orders/{id}` | `internal_notes`: `orders.notes`; `tracking_code`/`tracking_url`: `orders.fulfill` | Body: `internal_notes` (≤ 5000), `tracking_code` (≤ 100), `tracking_url` (≤ 500, https). **Proibidos:** `status`, `payment_status`, totais, itens, endereço. **200** `{ data: AdminOrder }`. |
| `POST /admin/orders/{id}/transitions` | ver tabela abaixo | Muda status operacional. **200** `{ data: AdminOrder }`. |
| `POST /admin/orders/{id}/cancel` | `orders.cancel_unpaid` (se `pending_payment`) / `orders.cancel_paid` (se `paid`/`processing`) | Cancela. **200** `{ data: AdminOrder }`. |
| `POST /admin/orders/{id}/reveal-document` | `customers.view_sensitive` | **200** `{ data: { customer_document: string; picked_up_by_document: string | null } }` — auditado (`order.sensitive_viewed`). |
| `POST /admin/orders/{id}/payments/reconcile` | `payments.reconcile` | Consulta o gateway (`syncFromGateway`) do pagamento mais recente. **200** `{ data: AdminOrder }`. 503 `payment_gateway_unavailable`. |
| `POST /admin/orders/{id}/cancellation-request/dismiss` | `orders.cancel_paid` | Recusa a solicitação do cliente sem cancelar: body `note` (3–500); limpa `cancellation_requested_at`/`cancellation_request_reason` e registra a decisão em `audit_logs` (`order.cancellation_request_dismissed`, com o motivo original e a nota). **200** `{ data: AdminOrder }`. 409 `invalid_status_transition` se não há solicitação aberta. |

**Transições (`POST /admin/orders/{id}/transitions`):**

Body:

| Campo | Regra |
|---|---|
| `to_status` | `required|in:processing,shipped,delivered,ready_for_pickup,picked_up` (`paid`/`cancelled`/`pending_payment` → 422) |
| `note` | opcional ≤ 1000 (vai para `order_status_history.note`; visível ao cliente só se `to_status = shipped`) |
| `tracking_code` | `shipped`: **obrigatório** se `shipping_method_type = carrier`, opcional nos demais; ≤ 100 |
| `tracking_url` | opcional (`shipped`), https, ≤ 500 |
| `carrier_name` | opcional (`shipped`, table_rate), ≤ 100 — anexado à nota |
| `picked_up_by_name` | `picked_up`: **obrigatório**, 3–150 |
| `picked_up_by_document` | `picked_up`: **obrigatório**, 5–20 (CPF/RG, só dígitos/letras; exibido mascarado) |

Máquina (ADR-008 + restrições do banco):

| De → Para | Permissão | Pré-condição |
|---|---|---|
| `paid → processing` | `orders.fulfill` | — |
| `processing → shipped` | `orders.fulfill` | método ≠ `pickup` |
| `processing → ready_for_pickup` | `orders.fulfill` | método = `pickup` |
| `shipped → delivered` | `orders.fulfill` | — |
| `ready_for_pickup → picked_up` | `orders.fulfill` **ou** `orders.pickup` | nome + documento |

Erros: 409 `invalid_status_transition` (transição fora da tabela, mesmo status, método
incompatível; corpo inclui `allowed_transitions`); 403 `forbidden`; 422 (campos obrigatórios).
Efeitos: timestamps (`processing_at`, `shipped_at`…), `order_status_history` (ator admin),
auditoria `order.status_changed`, `OrderStatusChanged` (e-mail em `shipped`,
`ready_for_pickup`, `delivered`, `picked_up`). `pending_payment → paid` e `cancelled → paid`
são **exclusivos do sistema** (webhook/reconciliação).

**Cancelamento (`POST /admin/orders/{id}/cancel`):**

- Body: `reason` (`required`, 3–500), `confirm_refund` (`accepted` quando status ∈ {`paid`,`processing`}; proibido caso contrário).
- `pending_payment` → `release`, pagamento `cancelled`, cupom liberado.
- `paid`/`processing` → `return` no estoque, estorno total solicitado (`ProcessRefund`
  assíncrono), cupom **não** devolvido (RN-CUP-005). Resposta imediata com
  `payments[0].refund.status = 'pending'`; conclusão → `payment_status = refunded` (via
  evento); falha definitiva → alerta ao financeiro (o pedido permanece `cancelled`).
- Outros status → 409 `invalid_status_transition`.

#### 3.G.10 Clientes e empresas

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/customers` | `customers.view` | `Paginated<AdminCustomer>` (sem `addresses`). Query: `q` (nome, e-mail, CPF/CNPJ exato), `type`, `is_active`, `price_list_id`, `company_id`, `date_from`/`date_to` (cadastro), `include_deleted=1`, `sort` ∈ `name,-created_at,created_at,-total_spent,-last_order_at`. |
| `GET /admin/customers/{id}` | `customers.view` | `{ data: AdminCustomer }` com `addresses`. |
| `PATCH /admin/customers/{id}` | `customers.update` (dados) / `pricing.manage` (`price_list_id`) / `customers.manage` (`cpf`) | Body: `name`, `phone`, `price_list_id` (nullable), `cpf` (correção; só sem pedido pago — RN-CLI-005). **Proibidos:** `email`, `type`, `password`, `is_active` (use block/unblock), `marketing_opt_in` (consentimento é do titular). **200**. |
| `POST /admin/customers/{id}/block` | `customers.update` | Body `reason` (3–500, vai para auditoria). `is_active=false`; sessões derrubadas. **200**. |
| `POST /admin/customers/{id}/unblock` | `customers.update` | **200**. |
| `POST /admin/customers/{id}/password-reset` | `customers.update` | Envia link de redefinição. **202** `{ data: { message } }`. |
| `POST /admin/customers/{id}/reveal-document` | `customers.view_sensitive` | **200** `{ data: { cpf: string | null; cnpj: string | null } }`; auditado. |
| `POST /admin/customers/{id}/anonymize` | `customers.manage` | LGPD (DB-15). Body `confirm` (`accepted`). 409 `resource_in_use` se há pedido em andamento (`pending_payment`…`ready_for_pickup`). **200**. |
| `GET /admin/companies` | `customers.view` | `Paginated<AdminCompany>`; `q` (razão/fantasia/CNPJ), `price_list_id`. |
| `GET /admin/companies/{id}` | `customers.view` | `{ data: AdminCompany }` |
| `PATCH /admin/companies/{id}` | `customers.update` / `pricing.manage` (`price_list_id`) / `customers.manage` (`cnpj`) | Body: `legal_name`, `trade_name`, `state_registration`, `state_registration_exempt`, `price_list_id`, `cnpj` (correção, só sem pedido pago). **200**. |

Pedidos e preços específicos do cliente: `GET /admin/orders?customer_id=`,
`GET /admin/customer-prices?customer_id=` / `?company_id=`.

#### 3.G.11 Frete

Todas exigem `shipping.manage` (inclusive leitura e simulador). Escritas incrementam
`shipping:config:version` e são auditadas (sem `credentials`).

| Endpoint | Descrição |
|---|---|
| `GET /admin/shipping/carriers` · `GET …/{id}` | `{ data: Carrier[] }` / `{ data: Carrier }` |
| `GET /admin/shipping/carriers/drivers` | `{ data: { driver: string; name: string; settings_schema: Record<string, 'string'|'integer'|'boolean'> }[] }` |
| `POST /admin/shipping/carriers` · `PATCH …/{id}` | Body: `name` (≤ 100), `code` (`^[a-z0-9_]+$`, ≤ 40, único; imutável), `driver` (∈ drivers registrados), `credentials` (objeto, **write-only**; `null` limpa), `settings` (objeto não sensível: `timeout_ms` 500–15000, `cubic_divisor` > 0, `origin_postal_code` 8 dígitos + chaves do driver), `is_active`. |
| `DELETE /admin/shipping/carriers/{id}` | **204**; 409 `resource_in_use` se referenciada por método. |
| `POST /admin/shipping/carriers/{id}/test` | Testa conexão com CEP de origem → destino fixo. **200** `{ data: { ok: boolean; duration_ms: number; message: string | null } }`. Rate `admin-heavy`. |
| `GET /admin/shipping/methods` · `GET …/{id}` | `{ data: ShippingMethod[] }` (por `position`) / `{ data: ShippingMethod }` |
| `POST /admin/shipping/methods` · `PATCH …/{id}` | Body: `name` (≤ 120), `code` (`^[a-z0-9-]+$`, ≤ 60, único), `type` (**imutável** após criação), `carrier_id` (obrigatório sse `carrier`), `carrier_service_code` (obrigatório se `carrier`), `description` (≤ 500), `delivery_days_min` ≥ 0, `delivery_days_max` ≥ min, `handling_days` ≥ 0, `weight_basis`, `cubic_divisor` (nullable > 0), `accepts_free_shipping_coupon` (padrão por tipo na criação), `position`, `is_active`, `pickup` (obrigatório sse `pickup`: `street`, `number`, `complement`, `district`, `city`, `state`, `postal_code`, `instructions` ≤ 500, `opening_hours` ≤ 200). |
| `DELETE /admin/shipping/methods/{id}` | Soft delete **204**. |
| `PUT /admin/shipping/methods/reorder` | Body `{ ids: number[] }` → `position`. **204**. |
| `GET /admin/shipping/zones` · `GET …/{id}` | `{ data: ShippingZone[] }` / `{ data: ShippingZone }` |
| `POST /admin/shipping/zones` · `PATCH …/{id}` | Body: `name` (≤ 120), `description` (≤ 255), `is_active`, `postal_ranges: {start_postal_code, end_postal_code}[]` (CEP com/sem máscara; início ≤ fim), `cities: {city_ibge_code}[]` (existente em `ibge_cities`; nome/UF preenchidos pelo backend), `states: UF[]`. Coleções enviadas **substituem** as atuais (sync). Resposta inclui `warnings: string[]` (faixas sobrepostas a outras zonas — aviso, não erro): **201/200** `{ data: ShippingZone, warnings: string[] }`. |
| `DELETE /admin/shipping/zones/{id}` | **204**; 409 `resource_in_use` (regras que a usam em `blockers`). |
| `POST /admin/shipping/zones/{id}/test` | Body `{ postal_code }` → **200** `{ data: { matches: boolean; matched_by: string | null; destination: { city: string | null; state: UF | null; city_ibge_code: string | null; resolved: boolean } } }`. |
| `GET /admin/shipping/cities` | Query `search` (≥ 2, sem acento), `state` → `{ data: IbgeCity[] }` (até 20). |
| `GET /admin/shipping/rules` | `{ data: ShippingRule[] }` (sem paginação); query `method_id`, `zone_id` (`null` literal = regras globais), `is_active`; ordem: método → zona → `priority` → `id`. |
| `GET /admin/shipping/rules/{id}` | `{ data: ShippingRule }` |
| `POST /admin/shipping/rules` · `PATCH …/{id}` | Body: `method_id` (método `own_delivery`/`table_rate` — senão 422), `zone_id` (nullable), `name` (≤ 150), `priority` (int), condições (`min/max_weight_grams`, `min/max_subtotal_cents`, `min/max_volume_cm3`, `max_package_length_cm`; nullable, ≥ 0, máx ≥ mín), `price_type`, `price_cents`, `per_kg_cents`, `percentage_bp`, `min_price_cents`, `max_price_cents`, `delivery_days_min/max`, `valid_from`, `valid_until` (fim > início), `is_active`. Campos exigidos por `price_type` conforme CHECKs (DATABASE §3.8.4). Resposta **201/200** `{ data: ShippingRule, warnings: ('tie_broken_by_id'|'free_rule_without_coverage_limit'|'rule_never_reachable')[] }`. |
| `DELETE /admin/shipping/rules/{id}` | Soft delete **204**. |
| `POST /admin/shipping/rules/{id}/duplicate` | **201** `{ data: ShippingRule }` (cópia inativa, `name` + " (cópia)"). |
| `POST /admin/shipping/rules/reorder` | Body `{ method_id, zone_id: number|null, ids: number[] }` → `priority = (índice + 1) × 10` dentro do grupo. **200** `{ data: ShippingRule[] }`. |
| `POST /admin/shipping/simulate` | **Rate** `admin-heavy`. Body (exatamente um de `items`, `order_id`, `logistics_override`): `postal_code` (required); `items: {variant_id, quantity? | width_m?, height_m?, pieces?}[]` (≤ 50); `order_id` (usa itens do snapshot); `logistics_override: {total_weight_grams, total_volume_cm3, largest_dimension_cm}`; `subtotal_cents` (opcional; ausente = `PriceResolver` visitante); `coupon_free_shipping` (boolean); `at` (ISO, vigência); `method_ids` (filtro). **200** `{ data: <ShippingQuoteResult completo de SHIPPING §10.1: destination, logistics, zones_matched, methods (trace), options, unavailable> }` — não persiste. |
| `GET /admin/shipping/quotes/{uuid}` | Cotação persistida (suporte), incluindo `unavailable`. **200**; 404. |

#### 3.G.12 Usuários, papéis e permissões

| Endpoint | Perm | Descrição |
|---|---|---|
| `GET /admin/users` | `admin_users.manage` | `Paginated<AdminUser>`; `q`, `role`, `is_active`, `include_deleted=1`. |
| `GET /admin/users/{id}` | `admin_users.manage` | `{ data: AdminUser }` |
| `POST /admin/users` | `admin_users.manage` | Body: `name` (3–150), `email` (único), `roles: RoleName[]` (≥ 1). Sem senha: envia **convite** (link de definição de senha, 72 h). **201**. |
| `PATCH /admin/users/{id}` | `admin_users.manage` | Body: `name`, `email`, `roles` (sync). **200**. |
| `POST /admin/users/{id}/deactivate` · `…/activate` | `admin_users.manage` | **200**. |
| `POST /admin/users/{id}/password-reset` | `admin_users.manage` | Reenvia link. **202**. |
| `DELETE /admin/users/{id}` | `admin_users.manage` | Soft delete **204**. |
| `GET /admin/roles` · `GET …/{id}` | `admin_users.manage` | `{ data: Role[] }` / `{ data: Role }` |
| `POST /admin/roles` · `PATCH …/{id}` | `admin_users.manage` | Body: `name` (`^[a-z0-9-]{3,50}$`, único; imutável em papéis do sistema), `permissions: PermissionName[]` (sync). `super-admin` não editável (403). |
| `DELETE /admin/roles/{id}` | `admin_users.manage` | **204**; 409 `resource_in_use` se tem usuários; papéis do sistema → 403. |
| `GET /admin/permissions` | `admin_users.manage` | `{ data: Permission[] }` — somente leitura (criadas por seeder). |

Anti-escalonamento (SECURITY §4.2) → **403 `forbidden`** sem efeito: alterar os próprios
papéis, desativar/excluir a si mesmo, conceder/remover `super-admin` sem ser super-admin,
remover/desativar o último `super-admin` ativo (este último → 422 `errors.roles`).

#### 3.G.13 Configurações

- `GET /admin/settings` — **Perm:** `settings.manage` → `{ data: Setting[] }` (todas as chaves da whitelist, agrupáveis por `group`).
- `PATCH /admin/settings` — **Perm:** `settings.manage` · Body `{ values: Partial<Record<SettingKey, unknown>>, expected_updated_at?: ISODateTime }`
  → **200** `{ data: Setting[] }`. Chave fora da whitelist → 422 `errors["values.<key>"]`.
  Efeito: `SettingsUpdated` (cache), auditoria (old/new).

Whitelist tipada:

| Chave | Tipo / validação | Público |
|---|---|---|
| `store.name` | string 2–120 | sim |
| `store.legal_name` | string ≤ 200 | não |
| `store.document` | CNPJ | não |
| `store.address` | `{street, number, complement?, district, city, state, postal_code, city_ibge_code}` | sim |
| `store.phone` | 10–11 dígitos | sim |
| `store.whatsapp` | 10–13 dígitos \| null | sim |
| `store.email` | e-mail | sim |
| `store.opening_hours` | string ≤ 200 | sim |
| `store.social_links` | `{instagram, facebook, youtube}` URLs https \| null | sim |
| `orders.number_prefix` | `^[A-Z]{1,5}-$` | não |
| `checkout.pix_expiry_minutes` | int 5–1440 (padrão 30) | sim (em `checkout`) |
| `checkout.min_order_cents` | int ≥ 0 | sim (em `checkout`) |
| `cart.guest_ttl_days` | int 1–90 | não |
| `shipping.quote_ttl_minutes` | int 5–120 | não |
| `shipping.origin_postal_code` | CEP | não |
| `inventory.default_low_stock_threshold` | decimal ≥ 0 | não |
| `inventory.show_low_stock_quantity` | boolean | sim (em `features`) |
| `legal.terms_version` | string ≤ 20 (mudança exige novo aceite no próximo login) | sim |
| `storefront.free_shipping_banner` | `{enabled: boolean, threshold_cents: int ≥ 0, text: ≤ 160}` | sim |
| `notifications.whatsapp_enabled` | boolean | não |
| `notifications.admin_alert_emails` | e-mails (≤ 10) | não |
| `content.about` · `content.terms` · `content.privacy` · `content.returns` | texto puro ≤ 50 000 | sim (via `/pages/{slug}`) |

#### 3.G.14 Auditoria

- `GET /admin/audit-logs` — **Perm:** `audit_logs.view` → `Paginated<AuditLog>`. Query:
  `actor_type`, `actor_id`, `action` (prefixo, ex.: `order.`), `auditable_type`,
  `auditable_id`, `request_id`, `date_from`, `date_to`, `sort` ∈ `-created_at` (padrão), `created_at`.
- `GET /admin/audit-logs/{id}` — `{ data: AuditLog }`.
- `GET /admin/failed-jobs` — **Perm:** `audit_logs.view` → `Paginated<{ uuid: string; queue: string; job: string; exception_summary: string; failed_at: ISODateTime }>` (ARCHITECTURE §10.4).
- Sem escrita (imutável).

#### 3.G.15 Relatórios

`GET /admin/reports/{report}` — **Rate:** `admin-heavy`.

- `report ∈ sales, products, revenue, customers, inventory, inventory-movements, orders, shipping, margin, coupons`.
- **Query comum:** `date_from`, `date_to` (**obrigatórios**, exceto `inventory`; intervalo
  ≤ 366 dias), `group_by` ∈ `day,week,month` (sales, revenue), `category_id`, `brand_id`
  (products, margin), `shipping_method_id` (shipping), `status` (orders), `limit` (1–500,
  padrão 100, relatórios de ranking), `format` ∈ `json` (padrão), `csv`.
- **Resposta 200 (json):** `{ data: ReportResponse<Row> }` com a linha correspondente (§2.16).
- **CSV (`format=csv`, exige também `reports.export`):** `text/csv; charset=utf-8` com BOM,
  separador `;`, decimal `,`, datas `dd/mm/aaaa`, valores em **reais** (não centavos),
  cabeçalhos em pt-BR, células iniciadas por `= + - @` prefixadas com `'`;
  `Content-Disposition: attachment; filename="{report}_{date_from}_{date_to}.csv"`.
  Síncrono, até 50 000 linhas (acima → 422 `errors.date_from` "Período muito longo para
  exportação."). Auditado (`report.exported`).
- **Permissões por relatório:** `sales`, `products` → `reports.view` **ou** `reports.sales`;
  `inventory`, `inventory-movements` → `reports.view` **ou** `reports.inventory`;
  `revenue`, `customers`, `orders`, `shipping`, `margin`, `coupons` → `reports.view`.

`summary` por relatório:

| Relatório | `summary` |
|---|---|
| sales | `orders_paid`, `revenue_cents`, `avg_ticket_cents`, `orders_refunded` |
| products | `distinct_variants`, `revenue_cents` |
| revenue | `gross_cents`, `discount_cents`, `shipping_cents`, `refunds_cents`, `net_cents` |
| customers | `new_customers`, `returning_customers`, `individual_customers`, `company_customers` |
| inventory | `variants`, `low_stock_variants`, `stock_value_cents` (null sem custo) |
| inventory-movements | `movements` |
| orders | `created`, `paid`, `cancelled`, `cancelled_payment_expired`, `cancelled_customer`, `cancelled_admin`, `conversion_bp`, `cancellation_bp`, `median_fulfillment_hours` |
| shipping | `orders`, `shipping_revenue_cents`, `free_shipping_orders` |
| margin | `revenue_cents`, `cost_cents`, `margin_cents`, `cost_coverage_bp` (fração da receita com custo cadastrado) |
| coupons | `uses`, `discount_cents` |

#### 3.G.16 Notificações do painel

- `GET /admin/notifications` — **Perm:** `dashboard.view` → `Paginated<AppNotification>` (somente do admin autenticado); `unread=1`.
- `POST /admin/notifications/read` — **Perm:** `dashboard.view` · Body `ids?: UUID[]` (ausente = todas). **204**.

---
## 4. Exemplos completos

Dados do seed (DATABASE §7). IDs, UUIDs e datas são **ilustrativos**. Premissas:
promoção "Semana do Vinil" **fora** de vigência (senão o vinil sairia a R$ 14,31 — E5);
visitante (sem tabela de preço); variantes com dados logísticos completos;
`APP_URL = https://loja.exemplo.com.br`.

### 4.1 Detalhe de produto — Vinil Adesivo Branco (`LINEAR_METER`, R$ 15,90/m, 1,22 m)

`GET /api/v1/products/vinil-adesivo-branco-122m` → **200**

```json
{
  "data": {
    "id": 1,
    "slug": "vinil-adesivo-branco-122m",
    "name": "Vinil Adesivo Branco",
    "url_path": "/vinis/vinil-adesivo-branco-122m",
    "short_description": "Vinil adesivo branco 1,22 m para plotter de recorte e impressão.",
    "description_html": "<p>Vinil adesivo branco com adesivo permanente.</p><ul><li>Largura 1,22 m</li></ul>",
    "specifications": [
      { "label": "Largura útil", "value": "1,22 m" },
      { "label": "Adesivo", "value": "Permanente" }
    ],
    "sale_unit": "LINEAR_METER",
    "sale_unit_label": "Metro linear",
    "sale_unit_abbr": "m",
    "brand": { "id": 2, "name": "VinilSul", "slug": "vinilsul" },
    "primary_category": { "id": 2, "name": "Vinis", "slug": "vinis", "url_path": "/vinis" },
    "categories": [
      { "id": 2, "name": "Vinis", "slug": "vinis", "url_path": "/vinis" },
      { "id": 11, "name": "Vinil Adesivo", "slug": "vinil-adesivo", "url_path": "/vinil-adesivo" },
      { "id": 3, "name": "Adesivos", "slug": "adesivos", "url_path": "/adesivos" }
    ],
    "breadcrumbs": [
      { "name": "Início", "url_path": "/" },
      { "name": "Vinis", "url_path": "/vinis" },
      { "name": "Vinil Adesivo Branco", "url_path": null }
    ],
    "images": [
      {
        "id": 10,
        "urls": {
          "w300": "https://cdn.exemplo.com.br/public/products/1/5f2a-300.webp",
          "w800": "https://cdn.exemplo.com.br/public/products/1/5f2a-800.webp",
          "w1600": "https://cdn.exemplo.com.br/public/products/1/5f2a-1600.webp"
        },
        "alt": "Vinil Adesivo Branco — foto 1 de 1",
        "width": 1600, "height": 1600, "position": 0, "variant_id": null
      }
    ],
    "attribute_axes": [ { "key": "acabamento", "label": "Acabamento", "values": ["Brilho", "Fosco"] } ],
    "variants": [
      {
        "id": 1,
        "sku": "VIN-BR-122-BR",
        "gtin": null,
        "name": "Brilho",
        "attributes": { "acabamento": "Brilho" },
        "position": 0,
        "is_default": true,
        "image_ids": [],
        "rules": {
          "sale_unit": "LINEAR_METER", "input": "decimal",
          "min_quantity": 1, "max_quantity": 50, "quantity_step": 0.1,
          "fixed_width_m": 1.22, "min_width_m": null, "max_width_m": null,
          "min_height_m": null, "max_height_m": null, "min_billable_area_m2": null,
          "dimension_decimals": 2, "max_pieces": 1000
        },
        "price": {
          "unit_price_cents": 1590,
          "base_unit_price_cents": 1590,
          "compare_at_cents": null,
          "price_source": "base",
          "price_source_label": null,
          "promotion": null,
          "tiers": [
            { "min_quantity": 1, "max_quantity": 9.9, "unit_price_cents": 1590, "price_source": "base" },
            { "min_quantity": 10, "max_quantity": 49.9, "unit_price_cents": 1490, "price_source": "tier" },
            { "min_quantity": 50, "max_quantity": null, "unit_price_cents": 1390, "price_source": "tier" }
          ]
        },
        "availability": { "status": "in_stock", "available_quantity": null },
        "weight_grams": 250,
        "roll_length_m": null,
        "units_per_box": null
      },
      {
        "id": 2,
        "sku": "VIN-BR-122-FO",
        "gtin": null,
        "name": "Fosco",
        "attributes": { "acabamento": "Fosco" },
        "position": 1,
        "is_default": false,
        "image_ids": [],
        "rules": {
          "sale_unit": "LINEAR_METER", "input": "decimal",
          "min_quantity": 1, "max_quantity": 50, "quantity_step": 0.1,
          "fixed_width_m": 1.22, "min_width_m": null, "max_width_m": null,
          "min_height_m": null, "max_height_m": null, "min_billable_area_m2": null,
          "dimension_decimals": 2, "max_pieces": 1000
        },
        "price": {
          "unit_price_cents": 1690, "base_unit_price_cents": 1690, "compare_at_cents": null,
          "price_source": "base", "price_source_label": null, "promotion": null, "tiers": []
        },
        "availability": { "status": "in_stock", "available_quantity": null },
        "weight_grams": 250,
        "roll_length_m": null,
        "units_per_box": null
      }
    ],
    "default_variant_id": 1,
    "pickup_only": false,
    "is_featured": true,
    "seo": {
      "title": "Vinil Adesivo Branco 1,22 m | CV Suprimentos",
      "description": "Vinil adesivo branco 1,22 m vendido por metro. Retirada em Blumenau ou entrega na região.",
      "canonical_path": "/vinis/vinil-adesivo-branco-122m",
      "canonical_url": "https://loja.exemplo.com.br/vinis/vinil-adesivo-branco-122m",
      "robots": "index,follow",
      "og_image_url": "https://cdn.exemplo.com.br/public/products/1/5f2a-1600.webp",
      "json_ld": [
        {
          "@context": "https://schema.org",
          "@type": "Product",
          "name": "Vinil Adesivo Branco",
          "sku": "VIN-BR-122-BR",
          "brand": { "@type": "Brand", "name": "VinilSul" },
          "image": ["https://cdn.exemplo.com.br/public/products/1/5f2a-1600.webp"],
          "offers": [
            {
              "@type": "Offer", "sku": "VIN-BR-122-BR", "price": "15.90", "priceCurrency": "BRL",
              "availability": "https://schema.org/InStock",
              "url": "https://loja.exemplo.com.br/vinis/vinil-adesivo-branco-122m?sku=VIN-BR-122-BR",
              "priceSpecification": { "@type": "UnitPriceSpecification", "price": "15.90", "priceCurrency": "BRL", "unitCode": "MTR" }
            },
            {
              "@type": "Offer", "sku": "VIN-BR-122-FO", "price": "16.90", "priceCurrency": "BRL",
              "availability": "https://schema.org/InStock",
              "url": "https://loja.exemplo.com.br/vinis/vinil-adesivo-branco-122m?sku=VIN-BR-122-FO",
              "priceSpecification": { "@type": "UnitPriceSpecification", "price": "16.90", "priceCurrency": "BRL", "unitCode": "MTR" }
            }
          ]
        },
        {
          "@context": "https://schema.org",
          "@type": "BreadcrumbList",
          "itemListElement": [
            { "@type": "ListItem", "position": 1, "name": "Início", "item": "https://loja.exemplo.com.br/" },
            { "@type": "ListItem", "position": 2, "name": "Vinis", "item": "https://loja.exemplo.com.br/vinis" },
            { "@type": "ListItem", "position": 3, "name": "Vinil Adesivo Branco" }
          ]
        }
      ]
    }
  }
}
```

### 4.2 Prévia de preço — vinil 5 m → R$ 79,50

`POST /api/v1/products/vinil-adesivo-branco-122m/price-preview`

```json
{ "variant_id": 1, "quantity": 5 }
```

**200**

```json
{
  "data": {
    "variant_id": 1,
    "sale_unit": "LINEAR_METER",
    "configuration": { "quantity": 5, "width_m": null, "height_m": null, "pieces": null },
    "configuration_label": "5 m",
    "billable_quantity": 5,
    "stock_quantity": 5,
    "piece_area_m2": null,
    "area_m2": null,
    "min_area_applied": false,
    "unit_price_cents": 1590,
    "base_unit_price_cents": 1590,
    "compare_at_cents": null,
    "price_source": "base",
    "price_source_label": null,
    "line_total_cents": 7950,
    "weight_grams": 1250,
    "applied_tier": { "min_quantity": 1, "max_quantity": 9.9, "unit_price_cents": 1590, "price_source": "base" },
    "next_tier": { "min_quantity": 10, "max_quantity": 49.9, "unit_price_cents": 1490, "price_source": "tier", "missing_quantity": 5 },
    "stock": { "sufficient": true, "available_quantity": null }
  }
}
```

Cálculo: `1590 × 5000 / 1000 = 7950`; peso `ceil(250 × 5000 / 1000) = 1250 g`.

Erro de passo — `{ "variant_id": 1, "quantity": 5.05 }` → **422**

```json
{
  "message": "Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.",
  "errors": { "quantity": ["Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m."] },
  "details": { "quantity": { "suggestions": [5, 5.1] } }
}
```

### 4.3 Prévia de preço — Lona m² 1,20 × 2,50 → área 3,000 m², R$ 90,00

`POST /api/v1/products/lona-frontlight-440g/price-preview` (variante `LON-FL-440-SM`, R$ 30,00/m²,
`min_billable_area_m2 = 1.000`, largura 0,30–3,20 m, altura 0,30–50,00 m)

```json
{ "variant_id": 7, "width_m": 1.2, "height_m": 2.5, "pieces": 1 }
```

**200**

```json
{
  "data": {
    "variant_id": 7,
    "sale_unit": "SQUARE_METER",
    "configuration": { "quantity": null, "width_m": 1.2, "height_m": 2.5, "pieces": 1 },
    "configuration_label": "1,20 m × 2,50 m × 1 peça",
    "billable_quantity": 3,
    "stock_quantity": 3,
    "piece_area_m2": 3,
    "area_m2": 3,
    "min_area_applied": false,
    "unit_price_cents": 3000,
    "base_unit_price_cents": 3000,
    "compare_at_cents": null,
    "price_source": "base",
    "price_source_label": null,
    "line_total_cents": 9000,
    "weight_grams": 1380,
    "applied_tier": { "min_quantity": 0.001, "max_quantity": 19.999, "unit_price_cents": 3000, "price_source": "base" },
    "next_tier": { "min_quantity": 20, "max_quantity": null, "unit_price_cents": 2700, "price_source": "tier", "missing_quantity": 17 },
    "stock": { "sufficient": true, "available_quantity": null }
  }
}
```

Cálculo (ADR-019): área/peça = `round_half_up(1200 × 2500 / 1000) = 3000` milésimos = 3,000 m²;
`billable = max(3,000; 1,000) × 1 = 3,000`; total `3000 × 3000 / 1000 = 9000`; peso
`ceil(460 × 3000 / 1000) = 1380 g`. Para `SQUARE_METER` a primeira faixa começa em `0.001`
(qualquer área).

Área mínima: `{ "variant_id": 7, "width_m": 0.4, "height_m": 0.5, "pieces": 1 }` →
`piece_area_m2: 0.2`, `area_m2: 0.2`, `billable_quantity: 1`, `stock_quantity: 0.2`,
`min_area_applied: true`, `line_total_cents: 3000`.

### 4.4 Adicionar ao carrinho (visitante, sem token)

`POST /api/v1/cart/items` (sem `X-Cart-Token`)

```json
{ "variant_id": 1, "quantity": 5 }
```

**201** · header `X-Cart-Token: 9b1f0c7e-3d2a-4e5b-8f6c-7a8b9c0d1e2f`

```json
{
  "data": {
    "token": "9b1f0c7e-3d2a-4e5b-8f6c-7a8b9c0d1e2f",
    "owner": "guest",
    "items": [
      {
        "id": 101,
        "variant_id": 1,
        "product": {
          "id": 1, "slug": "vinil-adesivo-branco-122m", "name": "Vinil Adesivo Branco",
          "url_path": "/vinis/vinil-adesivo-branco-122m",
          "image": {
            "id": 10,
            "urls": {
              "w300": "https://cdn.exemplo.com.br/public/products/1/5f2a-300.webp",
              "w800": "https://cdn.exemplo.com.br/public/products/1/5f2a-800.webp",
              "w1600": "https://cdn.exemplo.com.br/public/products/1/5f2a-1600.webp"
            },
            "alt": "Vinil Adesivo Branco — foto 1 de 1", "width": 1600, "height": 1600, "position": 0, "variant_id": null
          }
        },
        "variant": { "id": 1, "sku": "VIN-BR-122-BR", "name": "Brilho", "attributes": { "acabamento": "Brilho" } },
        "sale_unit": "LINEAR_METER",
        "sale_unit_abbr": "m",
        "configuration": { "quantity": 5, "width_m": null, "height_m": null, "pieces": null },
        "configuration_label": "5 m",
        "billable_quantity": 5,
        "stock_quantity": 5,
        "piece_area_m2": null,
        "area_m2": null,
        "min_area_applied": false,
        "unit_price_cents": 1590,
        "base_unit_price_cents": 1590,
        "compare_at_cents": null,
        "price_source": "base",
        "price_source_label": null,
        "line_total_cents": 7950,
        "weight_grams": 1250,
        "status": "ok",
        "warnings": [],
        "rules": {
          "sale_unit": "LINEAR_METER", "input": "decimal",
          "min_quantity": 1, "max_quantity": 50, "quantity_step": 0.1,
          "fixed_width_m": 1.22, "min_width_m": null, "max_width_m": null,
          "min_height_m": null, "max_height_m": null, "min_billable_area_m2": null,
          "dimension_decimals": 2, "max_pieces": 1000
        },
        "availability": { "status": "in_stock", "available_quantity": null }
      }
    ],
    "items_count": 1,
    "coupon": null,
    "postal_code": null,
    "totals": {
      "subtotal_cents": 7950,
      "discount_cents": 0,
      "shipping_cents": null,
      "shipping_discount_cents": null,
      "total_cents": 7950
    },
    "shipping_selection": null,
    "total_weight_grams": 1250,
    "free_shipping_progress": { "threshold_cents": 50000, "remaining_cents": 42050, "text": "Frete grátis na região de Blumenau acima de R$ 500" },
    "price_list": null,
    "can_checkout": true,
    "blocking_reasons": [],
    "has_price_changes": false,
    "updated_at": "2026-09-24T12:40:00Z"
  }
}
```

(`can_checkout` indica que o **carrinho** está apto; o checkout ainda exige login.)

Estoque insuficiente (ex.: pedir 600 m de uma variante com 500 disponíveis e máx. maior) → **409**

```json
{
  "message": "Estoque insuficiente para Vinil Adesivo Branco — Brilho. Disponível: 500 m.",
  "code": "insufficient_stock",
  "items": [
    { "cart_item_id": null, "variant_id": 1, "sku": "VIN-BR-122-BR", "product_name": "Vinil Adesivo Branco",
      "requested_quantity": 600, "available_quantity": 500 }
  ]
}
```

### 4.5 Cotação de frete do carrinho — CEP 89010-000 (Blumenau)

Carrinho da §4.4 (1250 g, subtotal R$ 79,50). `POST /api/v1/cart/shipping-quote` com
`X-Cart-Token: 9b1f0c7e-…`

```json
{ "postal_code": "89010-000" }
```

**200** (regras do seed DATABASE §7.8; ids de método/regra ilustrativos:
`pickup-store`=1, `own-delivery`=2 com regra "Entrega Blumenau"=2, `table-regional`=3 com
regra "Blumenau até 5 kg"=6, `table-cep`=4 com regra "CEP 89000000–89099999"=11)

```json
{
  "data": {
    "quote_id": "0b8f7c2e-6a61-4f3a-9d0e-3c9a4a1f2b10",
    "expires_at": "2026-09-24T13:12:00Z",
    "destination": { "postal_code": "89010000", "city": "Blumenau", "state": "SC" },
    "options": [
      {
        "option_id": "1:pickup",
        "method_code": "pickup-store",
        "method_type": "pickup",
        "name": "Retirada na empresa",
        "description": null,
        "price_cents": 0,
        "original_price_cents": 0,
        "is_free": true,
        "free_reason": "rule",
        "delivery_days_min": 1,
        "delivery_days_max": 1,
        "delivery_label": "Disponível em 1 dia útil após o pagamento",
        "carrier": null,
        "pickup_address": {
          "street": "Rua XV de Novembro", "number": "1000", "complement": null, "district": "Centro",
          "city": "Blumenau", "state": "SC", "postal_code": "89010001",
          "opening_hours": "Seg–Sex 8h–18h", "instructions": "Apresente o número do pedido."
        }
      },
      {
        "option_id": "3:6",
        "method_code": "table-regional",
        "method_type": "table_rate",
        "name": "Transportadora regional (tabela)",
        "description": null,
        "price_cents": 1500,
        "original_price_cents": 1500,
        "is_free": false,
        "free_reason": null,
        "delivery_days_min": 2,
        "delivery_days_max": 4,
        "delivery_label": "2 a 4 dias úteis",
        "carrier": null,
        "pickup_address": null
      },
      {
        "option_id": "4:11",
        "method_code": "table-cep",
        "method_type": "table_rate",
        "name": "Frete por CEP",
        "description": null,
        "price_cents": 1800,
        "original_price_cents": 1800,
        "is_free": false,
        "free_reason": null,
        "delivery_days_min": 3,
        "delivery_days_max": 6,
        "delivery_label": "3 a 6 dias úteis",
        "carrier": null,
        "pickup_address": null
      },
      {
        "option_id": "2:2",
        "method_code": "own-delivery",
        "method_type": "own_delivery",
        "name": "Entrega própria",
        "description": null,
        "price_cents": 2000,
        "original_price_cents": 2000,
        "is_free": false,
        "free_reason": null,
        "delivery_days_min": 1,
        "delivery_days_max": 1,
        "delivery_label": "1 dia útil",
        "carrier": null,
        "pickup_address": null
      }
    ],
    "notice": null,
    "message": null,
    "total_weight_grams": 1250
  }
}
```

Ordenação SHIPPING §6.2: preço ↑ (0, 1500, 1800, 2000). Com subtotal ≥ R$ 500,00 a
entrega própria e o frete por CEP viriam `price_cents: 0`, `is_free: true`, `free_reason: "rule"`,
`original_price_cents: 2000`/`1800`.

### 4.6 Checkout — 201 com PIX

Cliente PF "Maria da Silva" logada; carrinho com o vinil 5 m; endereço padrão "Casa"
(CEP 89012-000, Blumenau); cotação feita para esse CEP; opção "Entrega própria" (`2:2`,
R$ 20,00); `POST /checkout/preview` retornou `totals.total_cents = 9950`.

`POST /api/v1/checkout` · `Idempotency-Key: 3f6d2a8e-1b4c-4d5e-9f00-a1b2c3d4e5f6`

```json
{
  "address_uuid": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "shipping_quote_id": "5a1d7e2b-9c3f-4b8a-a6d2-0e4f1c2b3a90",
  "shipping_option_id": "2:2",
  "payment_method": "pix",
  "expected_total_cents": 9950,
  "notes": "Entregar após 14h",
  "accept_terms": true
}
```

**201**

```json
{
  "data": {
    "replayed": false,
    "order": {
      "uuid": "d2f1a3b4-5c6d-4e7f-8a9b-0c1d2e3f4a5b",
      "number": "CV-000001",
      "status": "pending_payment",
      "status_label": "Aguardando pagamento",
      "payment_status": "pending",
      "payment_method": "pix",
      "placed_at": "2026-09-24T13:00:00Z",
      "expires_at": "2026-09-24T13:30:00Z",
      "items_count": 1,
      "total_cents": 9950,
      "shipping_method_name": "Entrega própria",
      "shipping_method_type": "own_delivery",
      "tracking_code": null,
      "allowed_actions": {
        "can_pay": true, "can_retry_payment": false, "can_cancel": true,
        "can_request_cancellation": false, "can_reorder": true
      },
      "paid_at": null,
      "cancelled_at": null,
      "cancel_reason_code": null,
      "cancel_reason_label": null,
      "cancellation_request": null,
      "items": [
        {
          "product_id": 1,
          "product_name": "Vinil Adesivo Branco",
          "variant_name": "Brilho",
          "sku": "VIN-BR-122-BR",
          "product_url_path": "/vinis/vinil-adesivo-branco-122m",
          "sale_unit": "LINEAR_METER",
          "sale_unit_abbr": "m",
          "configuration": { "quantity": 5, "width_m": null, "height_m": null, "pieces": null },
          "configuration_label": "5 m",
          "billable_quantity": 5,
          "stock_quantity": 5,
          "area_m2": null,
          "min_area_applied": false,
          "unit_price_cents": 1590,
          "base_unit_price_cents": 1590,
          "price_source": "base",
          "subtotal_cents": 7950,
          "discount_cents": 0,
          "total_cents": 7950,
          "weight_grams": 1250
        }
      ],
      "totals": {
        "subtotal_cents": 7950,
        "discount_cents": 0,
        "shipping_cents": 2000,
        "shipping_discount_cents": 0,
        "total_cents": 9950
      },
      "coupon_code": null,
      "total_weight_grams": 1250,
      "billing": {
        "customer_type": "individual", "name": "Maria da Silva", "email": "maria@example.com",
        "document": "52998224725", "phone": "47999990001", "company_name": null, "state_registration": null
      },
      "shipping": {
        "method_name": "Entrega própria",
        "method_type": "own_delivery",
        "carrier_code": null,
        "service_code": null,
        "delivery_days_min": 1,
        "delivery_days_max": 1,
        "delivery_label": "1 dia útil",
        "estimated_delivery_date": null,
        "tracking_code": null,
        "tracking_url": null,
        "address": {
          "recipient_name": "Maria da Silva", "phone": "47999990001", "postal_code": "89012000",
          "street": "Rua das Palmeiras", "number": "123", "complement": null, "district": "Victor Konder",
          "city": "Blumenau", "state": "SC", "reference": null,
          "formatted": "Rua das Palmeiras, 123 – Victor Konder – Blumenau/SC – 89012-000"
        },
        "pickup_address": null,
        "picked_up_at": null,
        "picked_up_by_name": null
      },
      "payment": {
        "uuid": "8e7d6c5b-4a39-4281-9f0e-1d2c3b4a5968",
        "method": "pix",
        "status": "pending",
        "amount_cents": 9950,
        "expires_at": "2026-09-24T13:30:00Z",
        "paid_at": null,
        "pix": {
          "qr_code_base64": "iVBORw0KGgoAAAANSUhEUgAAAPAAAADwCAYAAAA+VemSAAAA...",
          "copy_paste": "00020126580014BR.GOV.BCB.PIX0136a1b2c3d4-0000-4000-8000-000000000001520400005303986540599.505802BR5915CV SUPRIMENTOS6008BLUMENAU62140510CV00000163041D3A",
          "expires_at": "2026-09-24T13:30:00Z"
        }
      },
      "timeline": [
        { "status": "pending_payment", "status_label": "Pedido realizado", "occurred_at": "2026-09-24T13:00:00Z", "note": null }
      ],
      "notes": "Entregar após 14h"
    },
    "payment": {
      "uuid": "8e7d6c5b-4a39-4281-9f0e-1d2c3b4a5968",
      "method": "pix",
      "status": "pending",
      "amount_cents": 9950,
      "expires_at": "2026-09-24T13:30:00Z",
      "paid_at": null,
      "pix": {
        "qr_code_base64": "iVBORw0KGgoAAAANSUhEUgAAAPAAAADwCAYAAAA+VemSAAAA...",
        "copy_paste": "00020126580014BR.GOV.BCB.PIX0136a1b2c3d4-0000-4000-8000-000000000001520400005303986540599.505802BR5915CV SUPRIMENTOS6008BLUMENAU62140510CV00000163041D3A",
        "expires_at": "2026-09-24T13:30:00Z"
      }
    }
  }
}
```

Replay com a mesma chave e o mesmo corpo → **200** idêntico com `"replayed": true`.
Mesma chave com `shipping_option_id: "1:pickup"` → **409**
`{"message":"Esta chave de idempotência já foi usada com outros dados.","code":"idempotency_conflict","order":{"uuid":"d2f1a3b4-…","number":"CV-000001"}}`.

Preço mudou (admin alterou para R$ 16,50/m) com `expected_total_cents: 9950` → **409**

```json
{
  "message": "Os valores do pedido mudaram. Revise e confirme novamente.",
  "code": "price_changed",
  "summary": {
    "items": [ { "id": 205, "variant_id": 1, "unit_price_cents": 1650, "line_total_cents": 8250, "warnings": [ { "code": "price_changed", "previous_unit_price_cents": 1590, "current_unit_price_cents": 1650 } ] } ],
    "totals": { "subtotal_cents": 8250, "discount_cents": 0, "shipping_cents": 2000, "shipping_discount_cents": 0, "total_cents": 10250 },
    "can_place_order": true,
    "blocking": []
  }
}
```

(Trecho: `summary` é um `CheckoutSummary` completo; campos omitidos aqui por brevidade.)

Gateway indisponível → **503**
`{"message":"Não foi possível gerar o PIX agora. Seu pedido foi criado; tente novamente.","code":"payment_gateway_unavailable","order":{"uuid":"d2f1a3b4-…","number":"CV-000001"}}`.

### 4.7 Webhook do sandbox (aprovação)

`POST /api/v1/webhooks/sandbox` (sem cookies/CSRF)

```http
POST /api/v1/webhooks/sandbox HTTP/1.1
Content-Type: application/json
x-request-id: 0f5d3c1e-8a4b-4c2d-9e7f-1a2b3c4d5e6f
x-signature: ts=1790255100,v1=12d0d0adeae8e53201a713f2b3790a7bb454d16ef8fa90e58b1f5091afdf6441

{
  "id": "evt_01J8Z3M7X4",
  "type": "payment",
  "action": "payment.updated",
  "data": { "id": "sbx_pay_01J8Z3K9Q2" },
  "date_created": "2026-09-24T13:05:00Z"
}
```

- Manifesto assinado: `id:sbx_pay_01J8Z3K9Q2;request-id:0f5d3c1e-8a4b-4c2d-9e7f-1a2b3c4d5e6f;ts:1790255100;`
  com HMAC-SHA256 e segredo `SANDBOX_WEBHOOK_SECRET` (no exemplo, `sandbox-secret-dev`).
- `ts = 1790255100` = `2026-09-24T13:05:00Z` (tolerância ±300 s).
- Resposta **200** `{"status":"ok"}`; o job consulta o gateway sandbox (`getPayment("sbx_pay_01J8Z3K9Q2")`
  → `approved`, `9950`, `BRL`) e aplica: pagamento `approved`, pedido `paid`, movimento `out`,
  histórico (ator `system`), `OrderPaid`. Reenvio idêntico → **200** `{"status":"ok"}` sem efeitos.
- Assinatura inválida → **401** corpo vazio.

Em `local`/`testing`: `POST /api/v1/dev/payments/d2f1a3b4-5c6d-4e7f-8a9b-0c1d2e3f4a5b/approve`
(sessão da Maria) gera exatamente esse webhook → **202** `{"data":{"status":"dispatched","webhook_event_external_id":"evt_01J8Z3M7X4"}}`.

### 4.8 Painel — transição para `shipped`

Pedido `id = 1` (CV-000001) em `processing`, entrega própria. Admin com `orders.fulfill`.

`POST /api/v1/admin/orders/1/transitions`

```json
{ "to_status": "shipped", "note": "Saiu com o motorista João.", "tracking_code": null }
```

**200** (campos principais; os demais seguem `AdminOrder`)

```json
{
  "data": {
    "id": 1,
    "uuid": "d2f1a3b4-5c6d-4e7f-8a9b-0c1d2e3f4a5b",
    "number": "CV-000001",
    "status": "shipped",
    "status_label": "Enviado",
    "payment_status": "approved",
    "payment_method": "pix",
    "placed_at": "2026-09-24T13:00:00Z",
    "paid_at": "2026-09-24T13:05:02Z",
    "processing_at": "2026-09-24T14:10:00Z",
    "shipped_at": "2026-09-25T09:00:00Z",
    "status_history": [
      { "id": 4, "from_status": "processing", "to_status": "shipped",
        "actor": { "type": "admin", "id": 3, "name": "Carlos (Expedição)" },
        "note": "Saiu com o motorista João.", "created_at": "2026-09-25T09:00:00Z" },
      { "id": 3, "from_status": "paid", "to_status": "processing",
        "actor": { "type": "admin", "id": 3, "name": "Carlos (Expedição)" },
        "note": null, "created_at": "2026-09-24T14:10:00Z" },
      { "id": 2, "from_status": "pending_payment", "to_status": "paid",
        "actor": { "type": "system", "id": null, "name": null },
        "note": "Pagamento PIX aprovado (webhook).", "created_at": "2026-09-24T13:05:02Z" },
      { "id": 1, "from_status": null, "to_status": "pending_payment",
        "actor": { "type": "customer", "id": 1, "name": "Maria da Silva" },
        "note": null, "created_at": "2026-09-24T13:00:00Z" }
    ],
    "allowed_transitions": [
      { "to_status": "delivered", "label": "Marcar como entregue", "required_fields": [], "optional_fields": ["note"] }
    ],
    "can_cancel": false,
    "cancel_requires_refund": false
  }
}
```

Mesma requisição de novo (já `shipped`) → **409**
`{"message":"Transição de status inválida: enviado → enviado.","code":"invalid_status_transition","allowed_transitions":["delivered"]}`.
Admin só com `orders.pickup` → **403** `{"message":"Você não tem permissão para esta ação.","code":"forbidden"}`.

---
## 5. Mapa endpoint → módulo → controller

Namespace base: `App\Modules\<Módulo>\Http\Controllers\<Store|Customer|Admin|Webhook|Dev>\`.
Arquivo de rotas: `app/Modules/<Módulo>/routes/{store,customer,admin,admin_guest,webhooks,dev,web}.php`
(ARCHITECTURE §3.2; `dev.php` é **novo**, carregado só em `local`/`testing`). Controllers
finos: Form Request → uma Action/Service → Resource. Regra de dependência (ARCHITECTURE §2.5):
quando um endpoint precisa de dados de um módulo de camada **superior**, o módulo dono define
um contrato em `Contracts/` e o módulo superior o implementa (inversão), p.ex.
`Customers\Contracts\CustomerStatsProvider` (implementado por Orders),
`Inventory\Contracts\VariantLabelProvider` (por Catalog) e
`Inventory\Contracts\MovementReferenceResolver` (por Orders).

### 5.1 Loja, carrinho, auth, `/me`, checkout, webhooks

| Endpoint | Módulo | Controller@método |
|---|---|---|
| `GET /api/health`, `/api/health/live` | Shared | `Shared\Http\Controllers\HealthController@show` / `@live` |
| `GET /settings/public` | Settings | `Store\PublicSettingsController@show` |
| `GET /pages/{slug}` | Settings | `Store\PageController@show` |
| `GET /categories` · `GET /categories/{slug}` | Catalog | `Store\CategoryController@index` · `@show` |
| `GET /brands` | Catalog | `Store\BrandController@index` |
| `GET /products` · `GET /products/{slug}` · `GET /products/{slug}/related` | Catalog | `Store\ProductController@index` · `@show` · `@related` |
| `GET /products/autocomplete` | Catalog | `Store\ProductAutocompleteController@index` |
| `POST /products/{slug}/price-preview` | Cart | `Store\PricePreviewController@store` |
| `POST /shipping/quote` | Cart | `Store\ShippingEstimateController@store` |
| `GET /postal-codes/{cep}` | Shipping | `Store\PostalCodeController@show` |
| `GET /sitemap.xml` · `GET /robots.txt` · shell | Seo | `SitemapController@show` · `RobotsController@show` · `ShellController@show` (rotas `web.php`) |
| `GET /cart` · `DELETE /cart` | Cart | `Store\CartController@show` · `@destroy` |
| `POST /cart/items` · `PATCH /cart/items/{id}` · `DELETE /cart/items/{id}` | Cart | `Store\CartItemController@store` · `@update` · `@destroy` |
| `PUT /cart/coupon` · `DELETE /cart/coupon` | Cart | `Store\CartCouponController@update` · `@destroy` |
| `POST /cart/shipping-quote` | Cart | `Store\CartShippingQuoteController@store` |
| `POST /cart/acknowledge-prices` | Cart | `Store\CartPriceAcknowledgementController@store` |
| `POST /auth/register` | Customers | `Store\Auth\RegisterController@store` |
| `POST /auth/login` · `POST /auth/logout` | Customers | `Store\Auth\SessionController@store` · `@destroy` |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | Customers | `Store\Auth\PasswordResetLinkController@store` · `Store\Auth\NewPasswordController@store` |
| `POST /auth/email/verify` · `POST /auth/email/verification-notification` | Customers | `Store\Auth\EmailVerificationController@verify` · `@resend` |
| `GET /me` · `PATCH /me` | Customers | `Customer\ProfileController@show` · `@update` |
| `PUT /me/password` | Customers | `Customer\PasswordController@update` |
| `PATCH /me/company` | Customers | `Customer\CompanyController@update` |
| `POST /me/terms-acceptance` | Customers | `Customer\TermsAcceptanceController@store` |
| `GET /me/data-export` | Checkout¹ | `Customer\DataExportController@show` |
| `GET/POST /me/addresses`, `GET/PATCH/DELETE /me/addresses/{uuid}` | Customers | `Customer\AddressController@index/store/show/update/destroy` |
| `POST /me/addresses/{uuid}/default` | Customers | `Customer\DefaultAddressController@store` |
| `GET /me/orders` · `GET /me/orders/{uuid}` | Orders | `Customer\OrderController@index` · `@show` |
| `GET /me/orders/{uuid}/status` | Orders | `Customer\OrderStatusController@show` |
| `POST /me/orders/{uuid}/cancel` | Orders | `Customer\OrderCancellationController@store` |
| `POST /me/orders/{uuid}/cancellation-request` | Orders | `Customer\CancellationRequestController@store` |
| `POST /me/orders/{uuid}/reorder` | Checkout | `Customer\ReorderController@store` |
| `POST /me/orders/{uuid}/payment` | Checkout | `Customer\OrderPaymentController@store` |
| `GET /me/reorder-suggestions` | Checkout | `Customer\ReorderSuggestionController@index` |
| `GET /me/notifications` · `POST /me/notifications/read` | Notifications | `Customer\NotificationController@index` · `@markRead` |
| `POST /checkout/preview` | Checkout | `Customer\CheckoutPreviewController@store` |
| `POST /checkout` | Checkout | `Customer\CheckoutController@store` |
| `POST /webhooks/{provider}` | Payments | `Webhook\PaymentWebhookController@handle` |
| `POST /dev/payments/{order_uuid}/approve` · `/fail` | Payments | `Dev\SandboxPaymentController@approve` · `@fail` |

¹ Exportação LGPD agrega Customers + Orders; fica em Checkout (camada 5, depende de ambos).

### 5.2 Painel

| Endpoint | Módulo | Controller |
|---|---|---|
| `POST /admin/auth/login` · `logout` | Identity | `Admin\Auth\SessionController@store` · `@destroy` |
| `POST /admin/auth/forgot-password` · `reset-password` | Identity | `Admin\Auth\PasswordResetLinkController@store` · `Admin\Auth\NewPasswordController@store` |
| `GET /admin/me` · `PUT /admin/me/password` | Identity | `Admin\MeController@show` · `Admin\MePasswordController@update` |
| `/admin/users*` | Identity | `Admin\AdminUserController` (index/show/store/update/destroy) · `Admin\AdminUserStatusController@activate/deactivate` · `Admin\AdminUserPasswordResetController@store` |
| `/admin/roles*` · `GET /admin/permissions` | Identity | `Admin\RoleController` · `Admin\PermissionController@index` |
| `GET /admin/dashboard` | Reports | `Admin\DashboardController@show` |
| `GET /admin/reports/{report}` | Reports | `Admin\ReportController@show` (despacha para `SalesReport`, `ProductsReport`… + `CsvReportWriter`) |
| `/admin/categories*` | Catalog | `Admin\CategoryController` · `Admin\CategoryReorderController@store` · `Admin\CategoryImageController@store/destroy` |
| `/admin/brands*` | Catalog | `Admin\BrandController` · `Admin\BrandLogoController@store/destroy` |
| `/admin/products*` | Catalog | `Admin\ProductController` · `Admin\ProductBulkController@store` · `Admin\ProductSlugAvailabilityController@show` |
| `DELETE /admin/products/{id}/variants/{variantId}` · `GET /admin/variants` · `GET /admin/variants/sku-availability` | Catalog | `Admin\ProductVariantController@destroy` · `Admin\VariantPickerController@index` · `Admin\SkuAvailabilityController@show` |
| `/admin/products/{id}/images*` | Catalog | `Admin\ProductImageController@store/update/destroy` · `Admin\ProductImageReorderController@store` |
| `/admin/variants/{variantId}/price-tiers` | Pricing | `Admin\VariantPriceTierController@show` · `@update` |
| `/admin/price-lists*` | Pricing | `Admin\PriceListController` · `Admin\PriceListTierController@index` |
| `/admin/customer-prices*` | Pricing | `Admin\CustomerPriceController` |
| `/admin/promotions*` | Pricing | `Admin\PromotionController` · `Admin\PromotionPreviewController@store` |
| `/admin/coupons*` | Pricing | `Admin\CouponController` · `Admin\CouponRedemptionController@index` · `Admin\CouponCodeGeneratorController@store` |
| `GET /admin/inventory` · `GET/PATCH /admin/inventory/{variantId}` | Inventory | `Admin\InventoryController@index/show/update` (nomes via `VariantLabelProvider`) |
| `GET /admin/inventory/{variantId}/movements` · `GET /admin/inventory/movements` | Inventory | `Admin\InventoryMovementController@index` |
| `POST /admin/inventory/{variantId}/entries` · `…/adjustments` | Inventory | `Admin\InventoryEntryController@store` · `Admin\InventoryAdjustmentController@store` |
| `GET /admin/orders` · `status-counts` · `GET/PATCH /admin/orders/{id}` | Orders | `Admin\OrderController@index/show/update` · `Admin\OrderStatusCountController@show` |
| `POST /admin/orders/{id}/transitions` | Orders | `Admin\OrderTransitionController@store` |
| `POST /admin/orders/{id}/cancel` | Orders | `Admin\OrderCancellationController@store` |
| `POST /admin/orders/{id}/cancellation-request/dismiss` | Orders | `Admin\CancellationRequestController@dismiss` |
| `POST /admin/orders/{id}/reveal-document` | Orders | `Admin\OrderSensitiveDataController@store` |
| `POST /admin/orders/{id}/payments/reconcile` | Payments | `Admin\PaymentReconciliationController@store` |
| `/admin/customers*` | Customers | `Admin\CustomerController@index/show/update` (stats via `CustomerStatsProvider`) · `Admin\CustomerBlockController@block/unblock` · `Admin\CustomerPasswordResetController@store` · `Admin\CustomerSensitiveDataController@store` · `Admin\CustomerAnonymizationController@store` |
| `/admin/companies*` | Customers | `Admin\CompanyController@index/show/update` |
| `/admin/shipping/carriers*` | Shipping | `Admin\CarrierController` · `Admin\CarrierDriverController@index` · `Admin\CarrierConnectionTestController@store` |
| `/admin/shipping/methods*` | Shipping | `Admin\ShippingMethodController` · `Admin\ShippingMethodReorderController@update` |
| `/admin/shipping/zones*` | Shipping | `Admin\ShippingZoneController` · `Admin\ShippingZoneTestController@store` |
| `GET /admin/shipping/cities` | Shipping | `Admin\IbgeCityController@index` |
| `/admin/shipping/rules*` | Shipping | `Admin\ShippingRuleController` · `Admin\ShippingRuleDuplicateController@store` · `Admin\ShippingRuleReorderController@store` |
| `POST /admin/shipping/simulate` | Cart² | `Admin\ShippingSimulatorController@store` |
| `GET /admin/shipping/quotes/{uuid}` | Shipping | `Admin\ShippingQuoteController@show` |
| `GET/PATCH /admin/settings` | Settings | `Admin\SettingController@index` · `@update` |
| `GET /admin/audit-logs*` | Audit | `Admin\AuditLogController@index/show` |
| `GET /admin/failed-jobs` | Audit | `Admin\FailedJobController@index` |
| `/admin/notifications*` | Notifications | `Admin\NotificationController@index/markRead` |

² O simulador monta `ShippingRequest` a partir de variantes/pedido (precisa de Catalog/Pricing);
Cart já depende de Catalog, Pricing e Shipping. Com `logistics_override` apenas, delega direto ao `ShippingEngine::evaluate(withTrace: true)`.

---

## 6. Permissões do painel

### 6.1 Catálogo de permissões (guard `admin`)

Base: matriz de BUSINESS_RULES §4.14 + adições da ADR-023 (`pricing.manage`,
`inventory.view`, `customers.update`, `reports.export`) + granularidades do seed de
DATABASE §7.2 necessárias para expressar as células parciais da matriz
(`coupons.manage` = "só cupons", `orders.pickup` = "só `picked_up`", `reports.sales` /
`reports.inventory` = "👁 vendas / estoque"). **Semântica adotada:**

| Permissão | Concede |
|---|---|
| `dashboard.view` | Dashboard (blocos filtrados por outras permissões) e notificações do painel |
| `products.view` | Ler produtos, variantes, categorias, marcas, imagens, faixas, tabelas, promoções |
| `products.manage` | Criar/editar/excluir produtos, variantes, imagens, categorias, marcas (campos não-preço) |
| `prices.manage` | Preço base/promocional/custo da variante e faixas **base** (`price_list_id = null`) |
| `pricing.manage` | Tabelas de preço (+ faixas de tabela), preços por cliente/empresa, atribuição de tabela a cliente/empresa |
| `promotions.manage` | Promoções e cupons |
| `coupons.manage` | Somente cupons |
| `inventory.view` | Ver saldos e movimentos |
| `inventory.move` | Entrada (`in`), incluindo `initial_stock` |
| `inventory.adjust` | Ajuste (`adjust`) e limiar de estoque baixo |
| `orders.view` | Listar/ver pedidos |
| `orders.fulfill` | Transições `paid→processing→shipped/ready_for_pickup→delivered/picked_up`; rastreio |
| `orders.pickup` | Somente `ready_for_pickup → picked_up` |
| `orders.cancel_unpaid` | Cancelar `pending_payment` |
| `orders.cancel_paid` | Cancelar `paid`/`processing` com estorno; arquivar solicitação de cancelamento |
| `orders.notes` | Notas internas |
| `payments.view` | Transações de pagamento no detalhe do pedido |
| `payments.reconcile` | Reconsultar gateway |
| `customers.view` | Listar/ver clientes e empresas (documentos mascarados) |
| `customers.view_sensitive` | Revelar CPF/CNPJ/documento de retirada (auditado) |
| `customers.update` | Editar dados cadastrais, bloquear/desbloquear, enviar reset de senha |
| `customers.manage` | Ações excepcionais: corrigir CPF/CNPJ, anonimizar (LGPD) |
| `shipping.manage` | Tudo de frete (inclui leitura e simulador) |
| `reports.view` | Todos os relatórios |
| `reports.sales` | Relatórios `sales` e `products` |
| `reports.inventory` | Relatórios `inventory` e `inventory-movements` |
| `reports.export` | CSV de relatórios e de movimentos de estoque |
| `admin_users.manage` | Usuários, papéis e lista de permissões |
| `settings.manage` | Configurações |
| `audit_logs.view` | Auditoria e jobs com falha |

### 6.2 Papéis do seed (ADR-023) — proposta de atribuição

| Papel (`name`) | `label` | Permissões |
|---|---|---|
| `super-admin` | Super Admin | todas (`Gate::before`) |
| `manager` | Gerente | todas exceto `admin_users.manage` |
| `seller` | Vendedor | `dashboard.view`, `products.view`, `coupons.manage`, `inventory.view`, `orders.view`, `orders.pickup`, `orders.cancel_unpaid`, `orders.notes`, `payments.view`, `customers.view`, `customers.view_sensitive`, `customers.update`, `reports.sales` |
| `warehouse` | Estoque/Expedição | `dashboard.view`, `products.view`, `inventory.view`, `inventory.move`, `inventory.adjust`, `orders.view`, `orders.fulfill`, `orders.pickup`, `orders.notes`, `reports.inventory` |
| `finance` | Financeiro | `dashboard.view`, `products.view`, `inventory.view`, `orders.view`, `orders.cancel_unpaid`, `orders.cancel_paid`, `orders.notes`, `payments.view`, `payments.reconcile`, `customers.view`, `customers.view_sensitive`, `reports.view`, `reports.export`, `audit_logs.view` |

### 6.3 Endpoint → permissão

`A | B` = qualquer uma basta (`permission:A|B,admin`). "+" = ambas.

| Endpoint | Permissão |
|---|---|
| `POST /admin/auth/*`, `GET /admin/me`, `PUT /admin/me/password` | — (apenas autenticação) |
| `GET /admin/dashboard`, `/admin/notifications*` | `dashboard.view` |
| `GET /admin/categories*`, `GET /admin/brands*`, `GET /admin/products*`, `GET /admin/variants*`, `GET /admin/variants/{id}/price-tiers`, `GET /admin/price-lists*`, `GET /admin/promotions*` | `products.view` |
| `POST/PATCH/DELETE /admin/categories*`, `/admin/brands*`, `/admin/products*` (inclui imagens, bulk, variantes) | `products.manage` (+ `prices.manage` para campos de preço; + `inventory.move` para `initial_stock > 0`; + `inventory.adjust` para `low_stock_threshold`) |
| `PUT /admin/variants/{id}/price-tiers` | `prices.manage` (`price_list_id = null`) · `pricing.manage` (tabela) |
| `POST/PATCH/DELETE /admin/price-lists*` | `pricing.manage` |
| `GET /admin/customer-prices` | `customers.view` |
| `POST/PATCH/DELETE /admin/customer-prices*` | `pricing.manage` |
| `POST/PATCH/DELETE /admin/promotions*` | `promotions.manage` |
| `POST /admin/promotions/{id}/preview` | `products.view` |
| `/admin/coupons*` (todos) | `coupons.manage | promotions.manage` |
| `GET /admin/inventory*` (inclui movimentos) | `inventory.view` (+ `reports.export` para `format=csv`) |
| `PATCH /admin/inventory/{id}`, `POST …/adjustments` | `inventory.adjust` |
| `POST /admin/inventory/{id}/entries` | `inventory.move` |
| `GET /admin/orders*` | `orders.view` |
| `PATCH /admin/orders/{id}` | `orders.notes` (campo `internal_notes`) · `orders.fulfill` (`tracking_*`) |
| `POST /admin/orders/{id}/transitions` | `orders.fulfill`; para `to_status = picked_up`: `orders.fulfill | orders.pickup` |
| `POST /admin/orders/{id}/cancel` | `orders.cancel_unpaid` (pendente) · `orders.cancel_paid` (pago/em separação) |
| `POST /admin/orders/{id}/cancellation-request/dismiss` | `orders.cancel_paid` |
| `POST /admin/orders/{id}/reveal-document`, `POST /admin/customers/{id}/reveal-document` | `customers.view_sensitive` |
| `POST /admin/orders/{id}/payments/reconcile` | `payments.reconcile` |
| `GET /admin/customers*`, `GET /admin/companies*` | `customers.view` |
| `PATCH /admin/customers/{id}`, `PATCH /admin/companies/{id}` | `customers.update` (dados) · `pricing.manage` (`price_list_id`) · `customers.manage` (`cpf`/`cnpj`) |
| `POST /admin/customers/{id}/block|unblock|password-reset` | `customers.update` |
| `POST /admin/customers/{id}/anonymize` | `customers.manage` |
| `/admin/shipping/*` (todos) | `shipping.manage` |
| `/admin/users*`, `/admin/roles*`, `GET /admin/permissions` | `admin_users.manage` |
| `GET/PATCH /admin/settings` | `settings.manage` |
| `GET /admin/audit-logs*`, `GET /admin/failed-jobs` | `audit_logs.view` |
| `GET /admin/reports/sales|products` | `reports.view | reports.sales` |
| `GET /admin/reports/inventory|inventory-movements` | `reports.view | reports.inventory` |
| `GET /admin/reports/revenue|customers|orders|shipping|margin|coupons` | `reports.view` |
| `GET /admin/reports/*?format=csv` | a do relatório **+** `reports.export` |

Permissões condicionais a campos (preço, estoque, `price_list_id`, `cpf`) são verificadas
no Form Request/Policy e retornam `403 forbidden` sem efeito (o teste
`AdminPermissionMatrixTest` cobre as duas dimensões).

---

## 7. Chaves de query (TanStack Query)

Fábricas por feature (`features/<f>/api/keys.ts`). Após login/logout: `queryClient.clear()`.
`staleTime` conforme ARCHITECTURE §8.4.

### 7.1 Storefront

| Endpoint | Query key | Observações |
|---|---|---|
| `GET /settings/public` | `['settings','public']` | `staleTime` 10 min |
| `GET /pages/{slug}` | `['pages', slug]` | |
| `GET /categories` | `['categories','tree']` | 5 min |
| `GET /categories/{slug}` | `['categories','detail', slug]` | |
| `GET /brands` | `['brands']` | |
| `GET /products` | `['products','list', filters]` | `filters` = objeto normalizado (sem `undefined`); `keepPreviousData` |
| `GET /products/autocomplete` | `['products','autocomplete', q]` | debounce 250 ms; `enabled: q.length >= 2` |
| `GET /products/{slug}` | `['products','detail', slug]` | |
| `GET /products/{slug}/related` | `['products','related', slug]` | |
| `POST /products/{slug}/price-preview` | `['price-preview', slug, variantId, configuration]` | `useQuery` (sem efeito colateral) com debounce 300 ms; `staleTime` 30 s |
| `POST /shipping/quote` | `['shipping-estimate', postalCode, items]` | `useQuery`; só após o usuário pedir |
| `GET /postal-codes/{cep}` | `['postal-codes', cep]` | `staleTime` 1 dia; `retry: false` em 404/422 |
| `GET /cart` | `['cart', { quoteId, optionId }?]` → use `['cart']` sem seleção | mutações fazem `setQueryData(['cart'], data)`; invalidar `['cart']` (prefixo) |
| mutações de carrinho | — | após sucesso: `setQueryData(['cart'])`, `removeQueries(['cart','shipping-quote'])` |
| `POST /cart/shipping-quote` | `['cart','shipping-quote', postalCodeOrAddressUuid]` | resultado guardado via `setQueryData`; invalidado a cada mudança do carrinho |
| `GET /me` | `['auth','me']` | `retry: false`; 401 ⇒ `null` |
| `GET /me/addresses` | `['me','addresses']` | |
| `GET /me/orders` | `['me','orders','list', { page, status }]` | |
| `GET /me/orders/{uuid}` | `['me','orders','detail', uuid]` | |
| `GET /me/orders/{uuid}/status` | `['me','orders','status', uuid]` | `refetchInterval: 5000` enquanto pendente; `refetchIntervalInBackground: false`; ao mudar para `paid`/`cancelled` invalidar `['me','orders','detail', uuid]` |
| `GET /me/reorder-suggestions` | `['me','reorder-suggestions']` | |
| `GET /me/notifications` | `['me','notifications', { page, unread }]` | |
| `POST /checkout/preview` | `['checkout','preview', { addressUuid, quoteId, optionId }]` | `useQuery`; `staleTime` 0 |
| `POST /checkout` | mutation | sucesso: `removeQueries(['cart'])`, `setQueryData(['me','orders','detail', uuid], order)`, navegar `replace` |

### 7.2 Admin

Prefixo `['admin', …]` em tudo; invalidação por prefixo após mutações.

| Endpoint | Query key |
|---|---|
| `GET /admin/me` | `['admin','me']` |
| `GET /admin/dashboard` | `['admin','dashboard']` (`refetchInterval` 60 s) |
| `GET /admin/notifications` | `['admin','notifications', params]` |
| `GET /admin/categories` / `{id}` | `['admin','categories','tree']` / `['admin','categories','detail', id]` |
| `GET /admin/brands` / `{id}` | `['admin','brands','list', params]` / `['admin','brands','detail', id]` |
| `GET /admin/products` / `{id}` | `['admin','products','list', params]` / `['admin','products','detail', id]` |
| `GET /admin/variants` | `['admin','variants','picker', params]` |
| slug/SKU availability | `['admin','products','slug-availability', slug, ignoreId]` / `['admin','variants','sku-availability', sku, ignoreId]` |
| `GET /admin/variants/{id}/price-tiers` | `['admin','price-tiers', variantId]` |
| `GET /admin/price-lists` / `{id}` / tiers | `['admin','price-lists']` / `['admin','price-lists','detail', id]` / `['admin','price-lists','tiers', id, params]` |
| `GET /admin/customer-prices` | `['admin','customer-prices', params]` |
| `GET /admin/promotions` / `{id}` | `['admin','promotions','list', params]` / `['admin','promotions','detail', id]` |
| `GET /admin/coupons` / `{id}` / redemptions | `['admin','coupons','list', params]` / `['admin','coupons','detail', id]` / `['admin','coupons','redemptions', id, params]` |
| `GET /admin/inventory` / `{variantId}` | `['admin','inventory','list', params]` / `['admin','inventory','detail', variantId]` |
| movimentos | `['admin','inventory','movements', variantId ?? 'all', params]` |
| `GET /admin/orders` / counts / `{id}` | `['admin','orders','list', params]` / `['admin','orders','status-counts', params]` / `['admin','orders','detail', id]` |
| `GET /admin/customers` / `{id}` | `['admin','customers','list', params]` / `['admin','customers','detail', id]` |
| `GET /admin/companies` / `{id}` | `['admin','companies','list', params]` / `['admin','companies','detail', id]` |
| frete | `['admin','shipping','carriers']`, `['admin','shipping','carrier-drivers']`, `['admin','shipping','methods']`, `['admin','shipping','zones']`, `['admin','shipping','zones', id]`, `['admin','shipping','rules', params]`, `['admin','shipping','cities', search, state]`, `['admin','shipping','quotes', uuid]` |
| `POST /admin/shipping/simulate` | mutation (resultado em estado local) |
| `GET /admin/users` / `{id}` | `['admin','users','list', params]` / `['admin','users','detail', id]` |
| `GET /admin/roles` / `permissions` | `['admin','roles']` / `['admin','permissions']` |
| `GET /admin/settings` | `['admin','settings']` |
| `GET /admin/audit-logs` / `{id}` | `['admin','audit-logs','list', params]` / `['admin','audit-logs','detail', id]` |
| `GET /admin/failed-jobs` | `['admin','failed-jobs', params]` |
| `GET /admin/reports/{report}` | `['admin','reports', report, params]` (CSV = download direto, sem cache) |

Mutações de pedido (`transitions`, `cancel`, `PATCH`, `reconcile`) → `setQueryData(['admin','orders','detail', id])`
+ `invalidateQueries(['admin','orders','list'])`, `['admin','orders','status-counts']`, `['admin','dashboard']`.
Mutações de estoque → invalidar `['admin','inventory']` e `['admin','products','detail', productId]`.

---

## 8. Decisões deste documento e divergências entre docs

### 8.1 Decisões tomadas aqui (candidatas a ADR)

| # | Decisão |
|---|---|
| A-01 | **Unidades na API:** dinheiro em centavos; quantidades como número com ≤ 3 casas (aceita string numérica); dimensões de material em **metros** (`*_m`), com entrada do cliente limitada a 2 casas (1 cm, RN-QTD-034) e cadastro do painel a 3 casas (mm); área em m² (3 casas); peso só `weight_grams`; embalagem em cm. |
| A-02 | **Filtros:** parâmetros simples `snake_case` (sem `filter[...]`), listas por vírgula, booleanos `1/0`, datas `YYYY-MM-DD` em America/Sao_Paulo; `sort` com `-` para desc + allowlist (fora → 422). Paginação Laravel (`data/links/meta`), `per_page ≤ 100`. |
| A-03 | **Campos perigosos rejeitados com 422 `prohibited`** na loja/cliente/checkout (em vez de ignorados) — testes determinísticos. |
| A-04 | **Merge de carrinho automático** em `POST /auth/login` e `POST /auth/register` usando `X-Cart-Token`; relatório `cart_merge` na resposta; sem endpoint explícito. Com cliente logado, `X-Cart-Token` é ignorado em `/cart/*`. Token inválido → `404 cart_not_found`. |
| A-05 | **Variantes no admin:** array `variants` embutido no produto com *upsert* (id = atualiza, sem id = cria; ausentes não mudam); exclusão por `DELETE /admin/products/{id}/variants/{variantId}`; imagens com endpoints multipart próprios; estoque nunca editado no produto (só `initial_stock` em variante nova). |
| A-06 | Prévia de preço em `POST /products/{slug}/price-preview` (módulo Cart); faixa considera só a linha. Frete de produto em `POST /shipping/quote` (stateless, `quote_id: null`); frete do carrinho em `POST /cart/shipping-quote` (persistido). |
| A-07 | Checkout em `/checkout` e `/checkout/preview` (não `/me/checkout`); cupom sempre do carrinho; `expected_total_cents` **obrigatório**; `accept_terms` obrigatório; fingerprint = corpo normalizado (sem conteúdo do carrinho). |
| A-08 | `coupon_invalid` no checkout = **409** (ADR-020); ao aplicar no carrinho = 422 `errors.code`. `cart_empty` = 409; `cart_invalid` = 422 com `code`. Códigos extras além da ADR-020: `cart_not_found`, `cart_invalid`, `account_disabled`, `admin_session_expired`, `resource_in_use`, `stale_resource`, `csrf_token_mismatch`, `payload_too_large`, `server_error`, `postal_code_lookup_unavailable`, `service_unavailable` e os 5 códigos `shipping_*` de SHIPPING §7. |
| A-09 | Disponibilidade: só `status`; quantidade exata apenas em `low_stock` (RN-CAT-017) ou quando a quantidade pedida é insuficiente (prévia/409). |
| A-10 | `price_changed` do carrinho: campo `cart_items.last_seen_unit_price_cents` (Q-05) + `POST /cart/acknowledge-prices`. |
| A-11 | Admin: pedidos por `id` (não `number`); transições por `POST /admin/orders/{id}/transitions` (não `PATCH …/status`); `picked_up` exige nome **e** documento; tracking obrigatório só para `carrier`. |
| A-12 | Cancelamento de pedido pago pelo admin é **efetivado imediatamente** com estorno assíncrono (ARCHITECTURE §4.7/DATABASE §4.3). |
| A-13 | Webhook: `/webhooks/{provider}` (ADR-013), resposta `{"status":"ok"}` para novo e duplicado; 401 com corpo vazio para assinatura inválida. Endpoints `/dev/payments/*` só em `local`/`testing`. |
| A-14 | Verificação de e-mail no MVP: enviada e não bloqueante; confirmação via `POST /auth/email/verify` (a SPA recebe o link). Troca de e-mail pelo cliente fora do MVP. |
| A-15 | Relatórios CSV síncronos (≤ 50 000 linhas) em vez de export assíncrono para S3 (ARCHITECTURE §2.4 Reports) — simplificação do MVP. |
| A-16 | Conteúdo institucional em settings `content.*` como **texto puro** (ADR-024) servido por `GET /pages/{slug}`. |
| A-17 | Catálogo de permissões da §6.1 (ADR-023 + `coupons.manage`, `orders.pickup`, `reports.sales`, `reports.inventory`) e semântica de `prices.manage` × `pricing.manage` e `customers.update` × `customers.manage`. |

### 8.2 Divergências entre documentos (e o que este contrato seguiu)

| # | Divergência | Seguido aqui |
|---|---|---|
| D-01 | `price_source`: ARCHITECTURE `PriceSource` usa `promotional`/`customer`; DATABASE `order_items.price_source` usa `variant_promo`/`customer_price`; BUSINESS_RULES omite `variant_promo`. | Valores do **DATABASE** (enums "como no banco"). ARCHITECTURE deve alinhar o enum PHP. |
| D-02 | Área por peça: DATABASE §3.6.3 usa `ceil_to_milli`; ADR-019 define `round_half_up`. BUSINESS_RULES RN-QTD-031 aplica mínimo por **linha** (ADR-019: por **peça**). RN-QTD-036 diz que `min/max/step` de m² se aplicam à área (ADR-019: peças). | ADR-019. |
| D-03 | Nomes de permissões: SECURITY §4.2 (`products.create/update/delete`, `orders.update_status`, `orders.cancel`, `orders.refund`, `users.manage`, `audit.view`, papéis `sales/catalog/stock/viewer`) ≠ BUSINESS_RULES/ADR-023 (`products.manage`, `orders.fulfill`, `orders.cancel_paid`, `admin_users.manage`, `audit_logs.view`; papéis `manager/seller/warehouse/finance`) ≠ DATABASE §7.2 (papéis em pt: `gerente`, `vendedor`…; permite `super-admin` com hífen). ARCHITECTURE §2.4 Identity tem outro enum de papéis (`super_admin`, `sales`, `catalog`…). | ADR-023 + §6.1. SECURITY §4.2/§22 (SEC-AUTH-06/07) e DATABASE §7.2 precisam ser atualizados; papel com hífen `super-admin`. |
| D-04 | Identificador de endereço: ARCHITECTURE/SECURITY usam `uuid` (`shipping_address_uuid`, SEC-IDOR-03/04) e proíbem `id` interno em recursos do cliente; DATABASE §1.1 (DB-01) **não** cria `customer_addresses.uuid`. | `uuid` (**requer coluna `customer_addresses.uuid`** no DATABASE). Campo no checkout: `address_uuid`. |
| D-05 | Variantes: ARCHITECTURE §4.1 usa `variant_uuid`; DATABASE não tem uuid em variantes. | `variant_id` inteiro (catálogo público). |
| D-06 | Paths: checkout `/me/checkout` (ARCHITECTURE/SECURITY) × `/checkout` (DATABASE, BUSINESS_RULES, SHIPPING); prévia de preço `/price-quotes` (ARCHITECTURE/SECURITY) × `/products/{id}/quote` (UX); cotação `/cart/shipping-quotes` (ARCHITECTURE) × `/shipping/quotes` (SHIPPING); status admin `PATCH /admin/orders/{number}/status` (ARCHITECTURE §4.6) × transições; settings públicas `/settings` (DATABASE) × `/settings/public`. | Paths deste documento; ARCHITECTURE §4.x/§8, SECURITY §18 e UX devem referenciar estes. |
| D-07 | Código de conflito de idempotência: `idempotency_key_reused` (ARCHITECTURE, SECURITY SEC-IDEM-03) × `idempotency_conflict` (ADR-020). | `idempotency_conflict`. |
| D-08 | Cupom inválido no checkout: 422 `coupon_code` (ARCHITECTURE §4.3, SECURITY SEC-DISC-02/04, BUSINESS_RULES RN-CUP-012) × código `coupon_invalid` na lista de erros não-422 (ADR-020). | 409 `coupon_invalid` (checkout); 422 no `PUT /cart/coupon`. Ajustar SEC-DISC-02/04. |
| D-09 | Campos proibidos: SECURITY §6 decide "ignorados"; este contrato decide **rejeitar com 422** (pedido do coordenador). | 422. Ajustar SEC-MA-01/02/05, SEC-PRICE-01/02, SEC-SHIP-01, SEC-DISC-01. |
| D-10 | Cancelamento de pedido pago com falha de estorno: RN-PED-021/EC-037 dizem "não efetivar"; ARCHITECTURE §4.7 e DATABASE §4.3 efetivam e estornam de forma assíncrona. | ARCHITECTURE (A-12); falha → alerta financeiro. |
| D-11 | Rate limit de cotação: 10/min (ARCHITECTURE, SECURITY) × 30/min (SHIPPING §3.2); checkout 5/min (SECURITY) × 10/min (RN-CHK-009); limite de linhas no carrinho 50 (RN-CAR-005) × 100 (DATABASE §3.5.2). | Cotação do carrinho 10/min + novo limiter `shipping-estimate` 30/min para produto; checkout 5/min; 50 linhas. |
| D-12 | Precisão de dimensões: RN-QTD-034/UX (2 casas, cm) × SECURITY §8 (`decimal:0,3`) × briefing (3 casas). | Entrada do cliente 2 casas; cadastro 3 casas. |
| D-13 | Retirada: SHIPPING §9 diz `picked_up_by_name` opcional e sem documento; RN-PED-016/024 e UX exigem nome **e** documento; DATABASE CHECK exige nome. | Nome e documento obrigatórios (documento mascarado na exibição). |
| D-14 | Settings: ARCHITECTURE `SettingKey` (`orders.pix_expiration_minutes`, `inventory.low_stock_default_threshold`, `shipping.pickup_address`…) × seed DATABASE §7.1 (`checkout.pix_expiry_minutes`, `inventory.default_low_stock_threshold`, `store.address`). Endereço de retirada: setting (ARCHITECTURE) × colunas `pickup_*` do método (SHIPPING §9/DATABASE). | Chaves do DATABASE + novas (§3.G.13); retirada pelas colunas do método. |
| D-15 | Colunas ausentes no DATABASE usadas por este contrato: `customer_addresses.uuid` (D-04), `cart_items.last_seen_unit_price_cents` (Q-05/A-10), `products.specifications jsonb` (ficha técnica RN-CAT-015/UX §4.4.8). SECURITY cita `customers.blocked_at` e `carts.merged_at`, inexistentes (DATABASE usa `is_active` e apaga o carrinho do visitante). | Pedir ao agente de Database (ADR-019..026a em andamento). |
| D-16 | Role/permissões por API: SECURITY §4.2 "papéis e permissões criados somente por seeder"; BUSINESS_RULES RN-ADM-002/UX §5.12 permitem papéis customizados. | Permissões só por seeder (leitura via API); papéis customizáveis via API (`admin_users.manage`); papéis do sistema protegidos. |
| D-17 | Tipos de cupom: BUSINESS_RULES `percentage` × DATABASE `percent`; RN-CUP-008 `max_shipping_discount_cents`, RN-CUP-013 `first_order_only` e restrição de cupom por categoria/cliente (UX §5.11) não existem no schema. RN-PRC-008 "preço do cliente com faixas" também não. | Schema do DATABASE; recursos ausentes ficam fora do MVP. |
| D-18 | Resposta de webhook duplicado: ARCHITECTURE `{status: duplicate}` × SECURITY §12 `{"status":"ok"}`; EC-008 aceita 401/403. | `{"status":"ok"}` e 401. |
| D-19 | Duplicidade no cadastro: RN-CLI-004 pede mensagem genérica; SECURITY §3.2/UX aceitam revelar. | Mensagem por campo (SECURITY), mitigada por rate limit. |
| D-20 | Seed: BUSINESS_RULES §9 usa SKU `VIN-BR-122`, 180 g/m, 100 m e entrega Blumenau R$ 15,00 com grátis ≥ R$ 300; DATABASE §7 usa `VIN-BR-122-BR`, 250 g/m, 500 m, R$ 20,00 e grátis ≥ R$ 500 (e a promoção "Semana do Vinil" ativa no seed altera o preço de 5 m). | Exemplos usam DATABASE §7 (promoção considerada fora de vigência). Critérios CA-003/007 precisam de ajuste de números. |
| D-21 | Slugs reservados: DATABASE §3.1.1 e RN-CAT-005 não incluem `recuperar-senha`, `redefinir-senha`, `institucional`, `sanctum` (ADR-026a). | Lista da ADR-026a (§3.G.3). |
