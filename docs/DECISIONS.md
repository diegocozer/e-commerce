# Decisões Arquiteturais (ADR log) — Coordenação

Este documento é a **fonte da verdade** para decisões transversais. Todos os agentes
(PO, Architect, Database, Backend, Frontend, Admin, Shipping, QA, Security, Review)
devem segui-lo. Qualquer divergência deve ser registrada aqui como nova ADR antes de
ser implementada.

Idioma: documentação em **pt-BR**; código, nomes de tabelas, colunas, classes e
endpoints em **inglês**.

---

## ADR-001 — Modular monolith Laravel + 2 SPAs React

- Um único backend Laravel (API REST), organizado em módulos (`app/Modules/<Module>`).
- Duas SPAs independentes: `storefront/` (loja) e `admin/` (painel). Separadas para
  isolar bundle, dependências e superfície de ataque do painel.
- Sem microservices, Kubernetes, event sourcing, CQRS ou Elasticsearch no MVP.

Estrutura do repositório:

```text
/backend          Laravel (API)
/storefront       React + TS + Vite (loja pública)
/admin            React + TS + Vite (painel)
/docker           Dockerfiles, nginx, php.ini
/docs             Documentação
docker-compose.yml
.github/workflows/ci.yml
```

## ADR-002 — Stack

| Camada | Escolha |
|---|---|
| Backend | PHP 8.4, Laravel (última estável), PostgreSQL 16, Redis 7 (cache, filas, rate limit) |
| Auth | Sessão via Laravel Sanctum *stateful SPA* (cookies httpOnly + CSRF) |
| RBAC | `spatie/laravel-permission` com guard `admin` |
| Storage | Disco `s3` (MinIO em dev) para imagens; `local` em testes |
| Filas | Redis (`queue:work`); `sync` nos testes |
| E-mail dev | Mailpit |
| Frontend | React 18+, TypeScript strict, Vite, MUI, TanStack Query, React Hook Form, Zod, React Router |
| Testes | PHPUnit (Postgres real), Vitest + Testing Library, Playwright (E2E) |

**Por que PostgreSQL:** `SELECT ... FOR UPDATE` confiável para estoque, CHECK
constraints, índices parciais, full-text search em português (`unaccent` + `tsvector`)
suficiente para o MVP sem motor de busca externo.

## ADR-003 — Dinheiro e quantidades (sem float)

- **Dinheiro:** inteiro em centavos (`bigint`), colunas com sufixo `_cents`
  (`price_cents`, `total_cents`). API trafega centavos (inteiros). Frontend formata BRL.
- **Quantidades:** `numeric(12,3)` no banco. No domínio PHP, o value object
  `Quantity` guarda **milésimos inteiros** (`5.5 m` → `5500`). Nunca usar float
  para calcular.
- **Dimensões de produto vendável** (largura/altura de material): em **milímetros**
  inteiros no domínio (`width_mm`), `numeric(10,3)` em metros na API (`width_m`) para
  clareza ao usuário. Conversão sempre via string → inteiro.
- **Logística:** peso em **gramas** (`integer`), dimensões de embalagem em
  **centímetros** (`numeric(8,1)`).
- **Arredondamento:** total da linha = `round_half_up(unit_price_cents × quantity_milli / 1000)`.
  Área (m²) = `width_mm × height_mm × pieces / 1000` em milésimos de m² (exato).

## ADR-004 — Produto, variante e unidade de venda

- Todo produto tem **≥ 1 variante** (`product_variants`). Carrinho, estoque, preço e
  pedido referenciam **variante**. Produto simples = 1 variante "padrão".
- Unidade de venda (`sale_unit`) é definida no **produto** (todas as variantes iguais):

| sale_unit | Entrada do cliente | Quantidade faturada | Unidade de estoque |
|---|---|---|---|
| `UNIT` | quantidade inteira | quantidade | unidade |
| `LINEAR_METER` | metros (decimal, `quantity_step`) | metros | metro |
| `SQUARE_METER` | largura (m) × altura (m) × peças | área em m² | m² |
| `ROLL` | quantidade inteira de rolos | rolos | rolo |
| `KG` | kg (decimal, `quantity_step`) | kg | kg |
| `BOX` | quantidade inteira de caixas | caixas | caixa |

- Regras por produto/variante: `min_quantity`, `max_quantity` (opcional),
  `quantity_step`, `min_billable_area` (m², para `SQUARE_METER`), largura fixa
  (`fixed_width_mm`, ex.: vinil 1,22 m) **ou** faixa de largura/altura
  (`min_width_mm`/`max_width_mm`, `min_height_mm`/`max_height_mm`).
- `SQUARE_METER` com largura fixa: cliente informa só a altura.
- Se área calculada < `min_billable_area`, cobra-se a área mínima (exibido ao cliente).
- Estoque de `SQUARE_METER` é controlado em m² no MVP (evolução futura: metro linear
  de bobina + aproveitamento).

## ADR-005 — Preço (resolução)

`PriceResolver` calcula o preço unitário **sempre no backend**. Candidatos aplicáveis:

1. Preço base da variante, considerando **faixas por quantidade** (`price_tiers`).
2. Preço de **tabela de preço** (`price_lists`: varejo, atacado, revendedor, específica)
   atribuída à empresa/cliente, também com faixas.
3. **Preço promocional** da variante (com vigência) ou **promoção** ativa
   (percentual/fixo por produto/categoria/marca).
4. **Preço específico do cliente/empresa** (`customer_prices`).

**Regra:** vence o **menor** preço entre os candidatos aplicáveis. O resultado informa
a origem (`price_source`) para exibição ("preço atacado", "promoção").
Cupons são aplicados **depois**, sobre o subtotal (nível pedido).

## ADR-006 — Autenticação e usuários

- Dois tipos de usuário, tabelas e guards separados:
  - `customers` → guard `customer` (sessão)
  - `admin_users` → guard `admin` (sessão)
- SPAs usam Sanctum stateful: `GET /sanctum/csrf-cookie`, depois login. CSRF obrigatório.
- Em dev, o Vite faz proxy de `/api` e `/sanctum` para o backend (mesma origem).
  Em produção, nginx serve loja em `/`, painel em `/admin` e API em `/api`
  (mesma origem → sem CORS em produção; CORS restrito por env para outros domínios).
- Cliente PJ: `customers.type = 'company'` + registro em `companies`
  (`customers.company_id`). Preparado para múltiplos usuários por empresa no futuro.
- Rate limiting: login/cadastro/recuperação (5/min por IP+email), cotação de frete,
  checkout, webhooks.

## ADR-007 — Carrinho

- Carrinho de visitante permitido, identificado por `carts.token` (UUID) enviado no
  header `X-Cart-Token`. Ao logar, o carrinho do visitante é **mesclado** ao carrinho do
  cliente.
- **Checkout exige login** (CPF/CNPJ necessário para pedido/nota).
- `cart_items` guarda apenas o que o cliente escolheu (variante, quantidade, largura,
  altura, peças). Preços exibidos no carrinho são **recalculados** a cada leitura.

## ADR-008 — Pedido, estoque e máquina de estados

Status do pedido (`orders.status`):

```text
pending_payment ─► paid ─► processing ─► shipped ─► delivered
       │            │           │     └─► ready_for_pickup ─► picked_up
       ▼            ▼           ▼
   cancelled     cancelled   cancelled        (paid/processing → cancelled gera estorno)
```

`payment_status` separado: `pending`, `approved`, `failed`, `refunded`, `expired`.

Estoque (`inventory`: `on_hand`, `reserved`; disponível = `on_hand − reserved`):

| Evento | Movimento | Efeito |
|---|---|---|
| Pedido criado | `reserve` | reserved += q |
| Pagamento aprovado | `out` (commit) | on_hand −= q; reserved −= q |
| Pedido não pago cancelado/expirado | `release` | reserved −= q |
| Pedido pago cancelado (antes de envio) | `return` | on_hand += q (após `out`) |
| Entrada de mercadoria | `in` | on_hand += q |
| Ajuste manual | `adjust` | on_hand = novo valor (com motivo) |

- Toda alteração em transação com `SELECT ... FOR UPDATE` nas linhas de `inventory`,
  **ordenadas por `variant_id`** (evita deadlock). CHECKs: `on_hand >= 0`,
  `reserved >= 0`, `reserved <= on_hand`.
- Sem venda a descoberto (backorder) no MVP.
- Pedidos `pending_payment` expiram (PIX: 30 min, configurável) via job agendado →
  `cancelled` + `release`.

## ADR-009 — Idempotência

- `POST /api/v1/checkout` exige header `Idempotency-Key` (UUID). Único por cliente
  (`orders.idempotency_key` + `customer_id`). Repetição retorna o mesmo pedido.
- Webhooks: tabela `webhook_events` com `unique(provider, external_id)`; evento repetido
  → 200 sem reprocessar. Assinatura HMAC validada antes de qualquer processamento.
- Transições de pagamento são idempotentes (aprovar pagamento já aprovado = no-op).

## ADR-010 — Pagamentos

- `PaymentGatewayInterface` (`createPayment`, `getPayment`, `refund`) + `PaymentGatewayManager`.
- Drivers MVP: `sandbox` (PIX simulado: gera QR/copia-e-cola fake; aprovação
  simulável em dev via webhook assinado) e `mercadopago` (PIX real via HTTP,
  testado com `Http::fake`). Driver configurado por env.
- Métodos: `pix` no MVP; `credit_card`, `boleto`, `invoice` (faturado PJ) previstos
  no enum e na interface.
- Dados de cartão **nunca** passam pelo nosso backend (tokenização no gateway).

## ADR-011 — Frete

Motor de frete desacoplado (ver `SHIPPING.md`). Pontos fixos:

- `ShippingEngine::quote(ShippingRequest): ShippingOption[]`.
- Métodos (`shipping_methods.type`): `pickup`, `own_delivery`, `table_rate`, `carrier`.
- Zonas (`shipping_zones`) cobrem por faixa de CEP, cidade (código IBGE) ou UF.
- Regras (`shipping_rules`) com condições (peso, valor, volume) e **prioridade**
  (menor número = maior prioridade); por método+zona vence a regra de maior
  prioridade que casar. Frete grátis é uma regra com preço 0.
- Transportadoras externas implementam `ShippingCarrierInterface`.
- Cotação persistida em `shipping_quotes` (TTL 30 min, hash do carrinho+CEP).
  No checkout o cliente envia `shipping_option_id`; o backend **recalcula** e valida.
- CEP → cidade/UF via `PostalCodeLookup` (ViaCEP com cache; fake nos testes).

## ADR-012 — Nunca confiar no frontend

O backend ignora/rejeita `price`, `discount`, `shipping_price`, `total`,
`customer_id`, `status` vindos do cliente. Form Requests só aceitam campos
permitidos (`validated()`), models usam `$fillable` explícito. Policies para todo
recurso do cliente (IDOR). IDs públicos de pedido: `orders.number` (ex.: `CV-000123`)
+ `uuid`; rotas do cliente usam `uuid`.

## ADR-013 — Convenções de API

- Prefixo `/api/v1`. Loja: `/api/v1/...`; cliente autenticado: `/api/v1/me/...`;
  painel: `/api/v1/admin/...`; webhooks: `/api/v1/webhooks/{provider}`.
- JSON via API Resources. Coleções paginadas no formato padrão Laravel
  (`data`, `links`, `meta`).
- Erros: 422 `{message, errors:{campo:[...]}}`; 401, 403, 404, 409 (conflito:
  estoque insuficiente, cotação expirada), 429.
- Datas ISO-8601 UTC. Dinheiro em centavos. Quantidade como número decimal.
- Todo request recebe `X-Request-Id` (gerado se ausente), propagado em logs.

## ADR-014 — Busca

- `ProductSearch` interface; implementação `PostgresProductSearch` (tsvector com
  `unaccent` em nome/descrição/marca/categoria + SKU por prefixo, GIN index).
  Troca futura para Meilisearch/OpenSearch sem alterar controllers.

## ADR-015 — SEO sem SSR

- URLs: `/{category-slug}` e `/{category-slug}/{product-slug}`; rotas estáticas da loja
  (`/busca`, `/carrinho`, `/checkout`, `/conta`, `/entrar`, `/cadastro`) são slugs
  reservados.
- SPA usa `react-helmet-async` para title/description/canonical/JSON-LD.
- Backend expõe `/sitemap.xml` e `/robots.txt`, e um endpoint de "shell" que serve o
  `index.html` da loja com meta tags e JSON-LD (Product, BreadcrumbList) injetados
  para rotas de produto/categoria (nginx encaminha essas rotas). SSR completo fica
  como evolução futura.

## ADR-016 — Auditoria, soft delete, observabilidade

- Soft delete: produtos, variantes, categorias, marcas, clientes, cupons, promoções,
  métodos/regras de frete, admin users. **Nunca** em pedidos, pagamentos, movimentos
  de estoque, audit logs (imutáveis).
- Histórico: `order_status_history`, `inventory_movements`, `payment_transactions`.
- `audit_logs`: ator (admin/customer/system), ação, entidade, diff (sem dados
  sensíveis), IP, request_id.
- Logs estruturados JSON com `request_id`; canais dedicados `payments` e `shipping`.
  Nunca logar senha, token, dados de cartão, CPF/CNPJ completo.
- Snapshot no pedido: `order_items` copiam nome, SKU, unidade, preço, dimensões e
  peso; `orders` copia endereço e dados do cliente no momento da compra.

## ADR-017 — Regras de trabalho entre agentes

- Cada agente altera somente os diretórios do seu escopo.
- Agentes **não** fazem commit; o coordenador integra e commita ao fim de cada etapa.
- Testes de backend usam Postgres; cada agente usa seu próprio banco de teste quando
  indicado (`DB_DATABASE=ecommerce_test_<agente>`).

---

# Rodada 2 — Resoluções do coordenador após o planejamento

Decisões abaixo resolvem as questões levantadas em BUSINESS_RULES.md (Q-01…Q-16),
ARCHITECTURE.md §12.2 (P1…P13), SHIPPING.md §5.1.1 e DATABASE.md §9. Em caso de
conflito entre documentos, **esta seção prevalece**.

## ADR-018 — Módulos finais

`Shared` (kernel: Money, Quantity, Dimensions, Weight, AuditLogger, RequestId),
`Settings`, `Identity`, `Audit`, `Customers`, `Inventory`, `Pricing` (inclui
promoções, cupons, tabelas de preço), `Catalog`, `Shipping`, `Payments`, `Cart`,
`Orders`, `Checkout` (orquestrador, sem tabelas), `Notifications`, `Reports`, `Seo`.
Grafo de dependências conforme ARCHITECTURE.md (sem ciclos). Verificação por
**teste de arquitetura em PHPUnit** (varre `use` statements dos módulos) em vez de
deptrac no MVP.

## ADR-019 — Área e quantidade (corrige ADR-003/004)

- Área por peça em milésimos de m² = `round_half_up(width_mm × height_mm / 1000)`.
- Área mínima faturável aplica-se **por peça**:
  `billable_area = max(piece_area, min_billable_area) × pieces`.
- `SQUARE_METER`: `cart_items.quantity` é NULL; `width_mm`, `height_mm`, `pieces`
  obrigatórios; `min/max_quantity` e `quantity_step` referem-se a **peças**.
- `order_items.billable_quantity` (faturado) separado de `stock_quantity` (baixa de
  estoque, sem área mínima).
- Faixas de preço (`price_tiers`) usam a **soma da quantidade da variante no
  carrinho** (todas as linhas da mesma variante).

## ADR-020 — Erros com código de máquina

Respostas ≠ 422 incluem `{"message": "...", "code": "snake_case"}`. Códigos
mínimos: `insufficient_stock`, `price_changed`, `shipping_quote_expired`,
`shipping_option_unavailable`, `coupon_invalid`, `idempotency_conflict`,
`payment_gateway_unavailable`, `invalid_status_transition`, `cart_empty`,
`too_many_pending_orders`, `forbidden`, `not_found`, `unauthenticated`,
`too_many_requests`.

## ADR-021 — Checkout (complementa ADR-009)

- `orders.checkout_fingerprint` (hash do corpo relevante). Mesma `Idempotency-Key`
  com corpo diferente → 409 `idempotency_conflict`.
- Cliente envia `expected_total_cents` **apenas para comparação**; se o total
  recalculado divergir → 409 `price_changed` com o novo resumo.
- Falha do gateway ao criar o pagamento → pedido permanece `pending_payment`,
  resposta 503 `payment_gateway_unavailable`; o cliente pode repetir com a mesma
  chave (gera o pagamento que faltou) ou via `POST /me/orders/{uuid}/payment`.
- Gateway **nunca** é chamado dentro de transação de banco.
- Máximo de 3 pedidos `pending_payment` simultâneos por cliente (409
  `too_many_pending_orders`).
- Uso de cupom registrado na criação do pedido; liberado se o pedido for cancelado
  antes do pagamento.
- Cliente só cancela pedidos `pending_payment`; demais cancelamentos são do admin.

## ADR-022 — Webhooks e pagamentos (complementa ADR-009/010)

- Fluxo: validar assinatura (`PaymentWebhookVerifier`) → gravar `webhook_events`
  (dedupe por `provider+external_id`, somente eventos com assinatura válida) →
  responder 200 → processar na fila `webhooks` consultando `getPayment()` no gateway
  (fonte da verdade).
- Job de reconciliação a cada 5 min para pagamentos pendentes (webhook perdido).
- **Pagamento aprovado após expiração/cancelamento por expiração:** se houver
  estoque, o pedido é reativado (`cancelled → paid`, transição exclusiva do
  sistema, registrada no histórico); caso contrário, estorno automático e
  notificação.
- Expiração por método configurável em `settings` (PIX padrão 30 min).

## ADR-023 — Sessão e papéis administrativos

- Um cookie de sessão; guards `customer` e `admin`. Sessão admin: timeout por
  inatividade 30 min, limite absoluto 8 h.
- Papéis (seed), código em inglês + nome pt-BR:
  `super-admin` (Super Admin — todas as permissões, via Gate::before),
  `manager` (Gerente), `seller` (Vendedor), `warehouse` (Estoque/Expedição),
  `finance` (Financeiro). A matriz de permissões de BUSINESS_RULES.md vale,
  acrescida de `pricing.manage`, `inventory.view`, `customers.update`,
  `reports.export`.
- Permissões nomeadas `<resource>.<action>`; listeners registrados explicitamente
  (sem auto-discovery).

## ADR-024 — Conteúdo rico e sanitização

Descrições de produto/categoria aceitam HTML limitado (p, br, strong, em, ul, ol,
li, h2, h3, a[href], table básica), sanitizado no backend com
`ezyang/htmlpurifier` e no frontend com DOMPurify antes de renderizar. Todo o resto
é texto puro.

## ADR-025 — Frete (reconcilia SHIPPING.md × DATABASE.md)

SHIPPING.md §5.1.1 é aceito integralmente e DATABASE.md já foi alinhado:
`min/max_subtotal_cents`, `max_package_length_cm`, `per_kg_cents`,
`price_type ∈ {fixed, per_kg, fixed_plus_per_kg, percentage_of_subtotal, free}`,
`valid_from/valid_until`, regras só para `own_delivery`/`table_rate`,
ordem: prioridade ASC → especificidade (CEP > cidade > UF > global) → id; primeira
regra que casa vence (uma opção por método). `per_kg` cobra por kg iniciado.
Estimativa de prazo em dias úteis considerando apenas fins de semana no MVP
(feriados: evolução).

## ADR-026 — Escopo adiado (documentado, fora do MVP)

`url_redirects` (redirecionamento de slug), exportação LGPD self-service,
devolução pós-entrega (registrada manualmente pelo admin), feriados no prazo,
Horizon, deptrac, S3 adapter instalado (config pronta; em dev usa disco `public`),
múltiplos usuários por empresa, pagamento faturado/cartão/boleto (interfaces
prontas).

## ADR-026a — Incluídos no MVP a partir das revisões

`orders.checkout_fingerprint`; índice parcial garantindo um estorno ativo por
pagamento; trigger que impede UPDATE/DELETE em `audit_logs`; slugs reservados
adicionais: `recuperar-senha`, `redefinir-senha`, `institucional`, `admin`, `api`,
`sanctum`, `sitemap.xml`, `robots.txt`; validação de CNPJ alfanumérico; CI com
`composer audit`, `npm audit --audit-level=high` e Dependabot.

## ADR-027 — Ajuste de papéis

`manager` recebe todas as permissões **exceto** `admin_users.manage` (e gestão de
papéis). Somente `super-admin` administra usuários e papéis. `prices.manage`
(preço base/faixas da variante) e `pricing.manage` (tabelas de preço, preços por
cliente, atribuição de tabela) coexistem.

## ADR-028 — API.md é o contrato canônico

Após a revisão de API.md §8.2:

- **Contrato HTTP:** `API.md` prevalece sobre ARCHITECTURE/SECURITY/SHIPPING/UX
  para paths, payloads, códigos de erro, nomes de permissões e query params.
- **`price_source`:** valores de DATABASE.md (`base`, `tier`, `price_list`,
  `variant_promo`, `promotion`, `customer_price`).
- **Permissões:** lista de API.md (ADR-023 + extras do seed de DATABASE.md).
- **Idempotência:** código `idempotency_conflict` (não `idempotency_key_reused`).
- **Cupom:** inválido ao aplicar no carrinho → 422; inválido/expirado no checkout →
  409 `coupon_invalid`.
- **Cancelamento de pedido pago:** cancela e estorna de forma assíncrona com retry
  (`payment_refunds`); falha de estorno fica visível ao financeiro.
- **Seed é a referência numérica:** exemplos de BUSINESS_RULES §9 são ilustrativos.
  A promoção "Semana do Vinil" do seed **não** pode afetar
  `vinil-adesivo-branco-122m`, que deve sair a R$ 15,90/m (fluxo de aceite:
  5 m = R$ 79,50).
- **Colunas adicionadas:** `customer_addresses.uuid` (rotas do cliente usam uuid),
  `cart_items.last_seen_unit_price_cents` (aviso de preço alterado),
  `products.specifications jsonb` (tabela de especificações técnicas).
