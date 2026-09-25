# Segurança — E-commerce de Suprimentos para Comunicação Visual

> Documento do **Agent 2 (Software Architect)**. Obedece a [`DECISIONS.md`](./DECISIONS.md)
> e complementa [`ARCHITECTURE.md`](./ARCHITECTURE.md). Itens marcados **🔧** são
> refinamentos propostos (ARCHITECTURE.md §12.2). A seção 22 é o contrato com o
> **agente de QA**: cada item vira ao menos um teste automatizado.

## Sumário

1. [Escopo e princípios](#1-escopo-e-princípios)
2. [Modelo de ameaças (STRIDE-lite)](#2-modelo-de-ameaças-stride-lite)
3. [Autenticação](#3-autenticação)
4. [Autorização](#4-autorização)
5. [IDOR](#5-idor)
6. [Mass assignment e "nunca confiar no frontend"](#6-mass-assignment-e-nunca-confiar-no-frontend)
7. [CSRF e CORS](#7-csrf-e-cors)
8. [Validação e sanitização de entrada](#8-validação-e-sanitização-de-entrada)
9. [XSS no React e no shell SEO](#9-xss-no-react-e-no-shell-seo)
10. [SQL injection](#10-sql-injection)
11. [Idempotência de pedido e pagamento](#11-idempotência-de-pedido-e-pagamento)
12. [Webhooks](#12-webhooks)
13. [Condições de corrida](#13-condições-de-corrida)
14. [Headers de segurança (nginx)](#14-headers-de-segurança-nginx)
15. [Upload de arquivos](#15-upload-de-arquivos)
16. [Gestão de segredos](#16-gestão-de-segredos)
17. [Regras de log](#17-regras-de-log)
18. [Rate limiting](#18-rate-limiting)
19. [Auditoria](#19-auditoria)
20. [LGPD](#20-lgpd)
21. [Dependências e cadeia de suprimentos](#21-dependências-e-cadeia-de-suprimentos)
22. [Checklist de testes de segurança (QA)](#22-checklist-de-testes-de-segurança-qa)

---

## 1. Escopo e princípios

- **Superfícies:** API pública (`/api/v1`), área do cliente (`/api/v1/me`), painel
  (`/api/v1/admin` + SPA `/admin`), webhooks (`/api/v1/webhooks/{provider}`), shell SEO,
  uploads, integrações de saída (Mercado Pago, ViaCEP, transportadoras, SMTP).
- **Princípios:** o backend é a única autoridade (ADR-012); negar por padrão (toda rota
  admin exige permissão explícita); menor privilégio (papéis, credenciais, bucket);
  defesa em profundidade (validação + Policy + escopo de query + constraint no banco);
  falhar fechado em segurança (assinatura inválida → 401) e aberto só em conveniência
  (CEP indisponível → fallback); nada sensível em logs.
- **Fora do escopo do MVP:** dados de cartão (tokenização no gateway — ADR-010, PCI SAQ-A),
  2FA do admin (prioridade alta na fase 2), WAF dedicado.

---

## 2. Modelo de ameaças (STRIDE-lite)

S = Spoofing, T = Tampering, R = Repudiation, I = Information disclosure,
D = Denial of service, E = Elevation of privilege.

### 2.1 Ativos e atores

| Ativo | Valor | Onde vive |
|---|---|---|
| Preços, promoções, cupons | Margem; manipulação = prejuízo direto | `product_variants`, `price_*`, `promotions`, `coupons` |
| Estoque | Venda sem produto; bloqueio de estoque por reservas falsas | `inventory`, `inventory_movements` |
| Pedidos | Receita, logística, histórico fiscal | `orders`, `order_items`, `order_status_history` |
| Pagamentos | Dinheiro; confirmação falsa = mercadoria sem pagamento | `payments`, `payment_transactions`, `webhook_events`, credenciais do gateway |
| PII de clientes | LGPD; CPF/CNPJ, endereço, telefone, e-mail | `customers`, `companies`, `customer_addresses`, snapshot em `orders` |
| Painel admin | Controle total da loja | `admin_users`, papéis/permissões, sessão admin |

Atores de ameaça: visitante anônimo/bot, cliente mal-intencionado (autenticado), operador
admin com permissão limitada (insider), atacante externo forjando webhook, dependência
comprometida.

### 2.2 Ameaças × controles por ativo

| Ativo | Ameaça (STRIDE) | Cenário | Controles (seção) |
|---|---|---|---|
| **Preços** | T | Cliente envia `price`, `unit_price_cents`, `discount`, `total` no carrinho/checkout | regra `prohibited` → 422 sem efeito (API.md §1.7); preço sempre do `PriceResolver` (§6) |
| | T | Quantidade/dimensões que causam overflow ou área mínima burlada (0,0001 m) | Limites de `quantity`/`width`/`height`, `SaleQuantityResolver`, `min_billable_area` (§8) |
| | E | Operador sem `prices.manage` altera preço via endpoint admin | Permissão por rota + Policy (§4) |
| | R | Alteração de preço sem rastro | `audit_logs` com diff (§19) |
| **Cupons** | T | Aplicar cupom expirado/de outro cliente; uso acima do limite com requests paralelos | `CouponService::redeem` sob lock + recálculo no checkout (§13) |
| | I | Enumeração de códigos de cupom | rate limit no endpoint de cupom; códigos longos aleatórios; mensagem genérica (§18) |
| **Estoque** | D | Bot cria muitos pedidos PIX para reservar estoque | checkout exige login; `throttle:checkout`; limite de pedidos `pending_payment` por cliente (máx. 3, ADR-021); expiração 30 min (§18) |
| | T | Corrida entre dois checkouts pelo último item | `SELECT … FOR UPDATE` ordenado + CHECK `reserved <= on_hand` (§13) |
| | T | Ajuste manual indevido | `inventory.adjust` + motivo obrigatório + auditoria |
| **Pedidos** | I | Cliente lê pedido de outro (IDOR) via id sequencial | rotas por `uuid` + query escopada pelo cliente → 404 (§5) |
| | T | Cliente muda status/`customer_id` do pedido | campos proibidos; status só por Actions com máquina de estados (§6) |
| | T | Pedido duplicado por duplo clique/retry | `Idempotency-Key` (§11) |
| | R | Admin nega ter cancelado pedido | `order_status_history` + `audit_logs` com ator, IP, `request_id` |
| **Pagamentos** | S | Webhook forjado aprovando pagamento | HMAC + timestamp + consulta `getPayment` ao gateway (§12) |
| | T | Replay de webhook legítimo | `webhook_events unique(provider, external_id)` + transição idempotente (§12) |
| | T | Pagamento de valor menor aprovado | comparação de `amount`/moeda com o esperado → `PaymentAmountMismatch` (§12) |
| | E | Estorno por operador sem permissão / estorno duplo | `orders.cancel_paid` + lock + índice de estorno ativo + chave idempotente no gateway (§4, §13) |
| | I | Vazamento do access token do gateway | segredos fora do repo, redator de logs (§16, §17) |
| **PII** | I | Listagem de clientes expõe CPF completo; logs com CPF | máscara em listas e logs; `customers.view` para detalhe (§17, §20) |
| | I | Enumeração de e-mails no login/recuperação | mensagens genéricas + rate limit (§3) |
| | T/I | XSS roubando dados da sessão | cookies `httpOnly`; CSP; React escapando; sanitização (§9, §14) |
| **Painel admin** | S | Força bruta/credential stuffing no login admin | throttle 5/min IP+e-mail, senha forte + `uncompromised()`, alerta de falhas (§3) |
| | E | Operador concede a si mesmo `super-admin` | `admin_users.manage` não pode alterar os próprios papéis nem conceder `super-admin` (só `super-admin`) (§4) |
| | S | CSRF a partir de site malicioso | Sanctum stateful + CSRF + `SameSite=Lax` (§7) |
| | S | Clickjacking do painel | `frame-ancestors 'none'` / `X-Frame-Options: DENY` (§14) |
| | D | Exports/relatórios pesados em loop | `throttle:admin-heavy`, exports síncronos limitados a 50 000 linhas (§18) |
| **Geral** | D | Flood em catálogo/busca/CEP | rate limiting Redis por grupo de rota; timeouts; cache (§18) |

---

## 3. Autenticação

### 3.1 Modelo (ADR-006)

- Guards de sessão separados: `customer` (provider `customers`) e `admin` (provider
  `admin_users`). Sanctum *stateful SPA* (`$middleware->statefulApi()`); **não** usamos
  tokens Bearer no MVP.
- Um único cookie de sessão (`cv_session`) para ambos os guards (mesma origem) 🔧 P7.
- Endpoints: `POST /api/v1/auth/{register,login,logout,forgot-password,reset-password}`,
  `GET /api/v1/me`; admin: `POST /api/v1/admin/auth/{login,logout,forgot-password,reset-password}`,
  `GET /api/v1/admin/me` (retorna permissões para a SPA).

### 3.2 Controles

| Controle | Decisão |
|---|---|
| Hash de senha | `bcrypt` (driver padrão do Laravel), `BCRYPT_ROUNDS=12` (produção), rehash automático no login quando o custo mudar. Validação `max:72` (limite do bcrypt). Nunca MD5/SHA/"encrypt". |
| Política de senha — cliente | `Password::min(8)->letters()->numbers()->uncompromised()`; não pode conter o e-mail. |
| Política de senha — admin | `Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()`; definida em `Password::defaults()` por contexto. `uncompromised()` (HIBP k-anonimato) com timeout 3 s e *fail-open* só nesse check. |
| Throttling de login | `RateLimiter` `login`: 5/min por `sha1(lower(email)).'|'.ip` **e** 20/min por IP; resposta 429 com `Retry-After`. Mesmo para registro, recuperação e reset (ADR-006). Falhas logadas no canal `security` (e-mail mascarado). |
| Mensagens | Login: sempre "E-mail ou senha inválidos." Recuperação: sempre 200 "Se o e-mail existir, enviaremos instruções." Registro revela e-mail já usado (inevitável) — mitigado por rate limit. |
| Regeneração de sessão | `$request->session()->regenerate()` após login bem-sucedido e após troca de senha/papéis (evita *session fixation*). |
| Logout | `Auth::guard($g)->logout(); $request->session()->invalidate(); $request->session()->regenerateToken();` → 204. |
| Troca/reset de senha | Invalida outras sessões: middleware `auth.session` nos grupos autenticados (verifica hash de senha na sessão por guard). Tokens de reset: broker por guard (`customers`, `admin_users`), expiração 60 min (admin 30 min), uso único, `throttle` 60 s entre envios. |
| Cookies | `cv_session`: `HttpOnly`, `Secure` (prod), `SameSite=Lax`, `Path=/`, host-only (sem `Domain`). `SESSION_ENCRYPT=true`. `XSRF-TOKEN`: legível por JS (necessário), `Secure`, `SameSite=Lax`. |
| Sessão | Redis; cliente: inatividade 120 min (`SESSION_LIFETIME`). Admin: middleware `EnsureAdminSessionIsFresh` — inatividade 30 min e absoluto 8 h (timestamps na sessão); expirado → logout do guard admin + 401 `admin_session_expired` 🔧 P7. |
| Conta desativada | `admin_users.is_active=false` / `customers.is_active=false` (não existe `blocked_at` — API.md D-15) → login recusado com `403 account_disabled` e sessão existente derrubada no próximo request (`401 unauthenticated`, checagem no middleware). |
| Verificação de e-mail | Recomendada, **não** bloqueante para compra no MVP (PIX confirma intenção). Troca de e-mail pelo cliente fora do MVP (`email` em `PATCH /me` → 422 — API.md A-14). |
| 2FA admin | Fase 2 (TOTP). Até lá: senha forte, sessão curta, allowlist de IP opcional para `/api/v1/admin` e `/admin` no nginx (`ADMIN_ALLOWED_CIDRS`). |
| Alertas | ≥ 10 falhas de login admin em 10 min (mesmo e-mail ou IP) → log `security.warning` + e-mail aos `super-admin`. |

---

## 4. Autorização

### 4.1 Cliente

- Toda rota `/api/v1/me/*` usa `auth:customer`.
- Policies por recurso do cliente: `OrderPolicy` (`view`, `cancel` só `pending_payment`),
  `CustomerAddressPolicy` (`view`, `update`, `delete`), `CartPolicy` (dono do carrinho).
  Policies checam `$model->customer_id === $customer->id` **e** a query já vem escopada (§5).

### 4.2 Admin — spatie/laravel-permission com guard `admin`

- `AdminUser` usa `HasRoles` com `protected string $guard_name = 'admin'`. **Permissões** são
  criadas **somente** por seeder (a partir do enum `Permission`) — a API só as lista.
  **Papéis** customizados podem ser criados/editados via API por quem tem
  `admin_users.manage`; papéis do sistema (`super-admin` etc.) são protegidos (API.md D-16,
  §3.G.12). Toda rota admin declara permissão (middleware
  `permission:<name>,admin` **ou** `->can()` com Policy que chama `$admin->can('<name>')`).
  Teste automatizado garante que **nenhuma** rota `admin.*` fica sem middleware de permissão
  (exceto `admin.auth.*` e `admin.me`).
- `Gate::before`: `super-admin` → `true`.
- **Convenção de nome:** `<resource>.<action>` em `snake_case`, recurso no plural.

> **Catálogo canônico (ver ADR-023/027/028):** a lista de permissões, a atribuição aos papéis
> do seed (`super-admin`, `manager`, `seller`, `warehouse`, `finance`) e o mapa
> endpoint → permissão estão em **API.md §6.1–§6.3** e prevalecem. As tabelas abaixo foram
> reescritas para refletir essa lista (a versão anterior usava `products.create/update/delete`,
> `orders.update_status`, `orders.cancel`, `orders.refund`, `users.manage`, `audit.view` e papéis
> `sales/catalog/stock/viewer`, todos **substituídos**).

| Permissão | Concede (resumo — semântica completa em API.md §6.1) |
|---|---|
| `dashboard.view` | dashboard e notificações do painel |
| `products.view` / `products.manage` | ver / gerir produtos, variantes, imagens, categorias, marcas (campos não-preço) |
| `prices.manage` | preço base/promocional/custo da variante e faixas base |
| `pricing.manage` | tabelas de preço, preços por cliente/empresa, atribuição de tabela |
| `promotions.manage` / `coupons.manage` | promoções e cupons / somente cupons |
| `inventory.view` / `inventory.move` / `inventory.adjust` | ver / entrada (`in`) / ajuste (`adjust`) e limiar |
| `orders.view` / `orders.fulfill` / `orders.pickup` | ver / transições operacionais / só `ready_for_pickup → picked_up` |
| `orders.cancel_unpaid` / `orders.cancel_paid` | cancelar `pending_payment` / cancelar pago com estorno |
| `orders.notes` | notas internas |
| `payments.view` / `payments.reconcile` | transações / reconsultar gateway |
| `customers.view` / `customers.view_sensitive` | ver (mascarado) / revelar CPF/CNPJ (auditado) |
| `customers.update` / `customers.manage` | editar, bloquear, reset de senha / corrigir documento, anonimizar (LGPD) |
| `shipping.manage` | tudo de frete (inclui simulador) |
| `reports.view` / `reports.sales` / `reports.inventory` / `reports.export` | relatórios / só vendas / só estoque / CSV (contém PII) |
| `admin_users.manage` | usuários, papéis e lista de permissões |
| `settings.manage` | configurações da loja |
| `audit_logs.view` | auditoria e jobs com falha |

Papéis do seed: ver API.md §6.2 (`super-admin` = todas via `Gate::before`; `manager` = todas
exceto `admin_users.manage` — ADR-027; `seller`, `warehouse`, `finance` conforme a tabela).

Regras anti-escalonamento: um admin **não** altera os próprios papéis/permissões nem se
desativa; só `super-admin` concede/remove `super-admin`; o último `super-admin` ativo não
pode ser removido/desativado; toda mudança de papel é auditada e regenera o cache de
permissões.

- A SPA admin recebe a lista de permissões em `GET /api/v1/admin/me` apenas para esconder
  UI; **o backend sempre revalida** (403).

---

## 5. IDOR

| Controle | Detalhe |
|---|---|
| Escopo na query | Recursos do cliente são buscados **a partir do cliente autenticado**: `$customer->orders()->where('uuid', $uuid)->firstOrFail()` — nunca `Order::find($id)` seguido de checagem. Resultado de outro cliente → **404** (não revela existência). |
| Route keys | Pedidos, endereços e pagamentos expostos ao cliente usam `uuid` (v4 aleatório) via `getRouteKeyName()`; ids inteiros nunca aparecem nos Resources do cliente. `orders.number` (sequencial) é exibido mas **não** é chave de rota do cliente. |
| Binding escopado | Rotas aninhadas usam `->scopeBindings()`; binding customizado `Route::bind('customerOrder', …)` já filtra pelo cliente. |
| Carrinho | `X-Cart-Token` (UUID v4) só funciona para carrinho **sem dono**; token de carrinho com dono, convertido ou desconhecido → `404 cart_not_found`; com cliente logado o token é ignorado em `/cart/*` (API.md §1.3). Itens do carrinho referenciados por id são buscados via `$cart->items()`. |
| Endereço no checkout | `CustomerDirectory::addressForCustomer($customerId, $uuid)` — `address_uuid` de outro cliente = 422 `errors.address_uuid` genérico. |
| Admin | IDs internos permitidos (painel), mas protegidos por permissão. |
| Arquivos | Exports CSV são gerados de forma **síncrona** e baixados na própria resposta (`Content-Disposition: attachment`) apenas por quem tem `reports.export` (API.md §3.G.15, A-15); nada é gravado em bucket. |

---

## 6. Mass assignment e "nunca confiar no frontend"

- Models: `$fillable` **explícito**; proibido `$guarded = []` e `Model::unguard()` (regra no
  code review + teste de arquitetura que varre `app/Modules/*/Models`).
  Campos sensíveis (`status`, `payment_status`, `customer_id`, `*_cents` calculados,
  `idempotency_key`, `is_active` de admin, `email_verified_at`) **não** entram em `$fillable`;
  são atribuídos explicitamente nas Actions.
- `Model::preventSilentlyDiscardingAttributes()` fora de produção (via `shouldBeStrict`) —
  ajuda a detectar tentativas de atribuir campo não permitido nos testes.
- Controllers usam **somente** `$request->validated()` / `$request->safe()->only([...])`
  → DTO. Proibido `$request->all()`, `$request->input()` sem validação e `fill($request->all())`.
- Campos **perigosos** vindos do cliente (ADR-012): `price`, `price_cents`,
  `unit_price_cents`, `*_total_cents`, `subtotal_cents`, `discount*`, `shipping_*` de preço,
  `customer_id`, `company_id`, `status`, `payment_status`, `price_list_id`, `is_active`,
  `email_verified_at`, `roles`, `permissions`, `items` (checkout), `uuid`/`id` no corpo.
  **Decisão (substitui "ignorados" — ver ADR-028 e API.md §1.7):** nos endpoints da loja,
  carrinho, auth, `/me` e checkout esses campos têm a regra `prohibited` → **422** com
  `errors.<campo>` e **nenhum efeito** no banco. Demais campos desconhecidos continuam
  ignorados (não entram em `validated()`). No painel, só os campos derivados listados em
  cada endpoint são `prohibited`; o resto é ignorado.
- Recalculado sempre no servidor: preço unitário (PriceResolver), quantidade faturável/área
  (SaleQuantityResolver), desconto (CouponService), frete (`ShippingQuoteService::revalidate`
  — `shipping_quote_id` + `shipping_option_id` só apontam para uma cotação **persistida no
  servidor**, do próprio cliente/carrinho, válida, com mesmo CEP e mesmo `cart_hash`), total, dono do pedido (`auth('customer')->id()`).
- `expected_total_cents` (obrigatório no checkout — ADR-021) é usado apenas para **comparar** e nunca como valor.

---

## 7. CSRF e CORS

### 7.1 CSRF

- Sanctum stateful: requests de origens em `SANCTUM_STATEFUL_DOMAINS` recebem sessão e
  passam pelo `ValidateCsrfToken`. A SPA obtém `XSRF-TOKEN` em `GET /sanctum/csrf-cookie`
  e envia `X-XSRF-TOKEN` (axios `withXSRFToken`).
- Requests sem Origin/Referer de domínio stateful **não** recebem sessão → não autenticam
  por cookie (logo CSRF não se aplica a elas).
- `SameSite=Lax` no cookie de sessão como camada extra.
- Exceção única: `api/v1/webhooks/*` (sem sessão; protegido por HMAC — §12).
- GET nunca altera estado (regra de revisão).
- `SANCTUM_STATEFUL_DOMAINS` produção = apenas o domínio da loja (sem curingas).

### 7.2 CORS

- Produção: mesma origem → **CORS desnecessário**. `config/cors.php`:
  `paths: ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins` = `CORS_ALLOWED_ORIGINS`
  (lista explícita por env; vazio em produção), `supports_credentials: true`,
  `allowed_methods: [GET, POST, PUT, PATCH, DELETE, OPTIONS]`,
  `allowed_headers: [Content-Type, X-XSRF-TOKEN, X-Requested-With, X-Cart-Token, Idempotency-Key, X-Request-Id, Accept]`,
  `exposed_headers: [X-Cart-Token, X-Request-Id, Retry-After]`, `max_age: 600`.
- Proibido `*` com credenciais. Webhooks não precisam de CORS.

---

## 8. Validação e sanitização de entrada

| Regra | Detalhe |
|---|---|
| Form Request em toda rota com entrada | Tipos explícitos, `required`/`nullable`, `max` em toda string, `Rule::enum`, `uuid`, `integer` com `min`/`max`. |
| Middlewares globais | `TrimStrings` (exceto `password*`), `ConvertEmptyStringsToNull`. |
| Tamanhos | Nome ≤ 120, e-mail ≤ 191 (`email:rfc,strict`), endereço ≤ 120/campo, observações de pedido ≤ 500, busca `q` ≤ 100, descrição de produto ≤ 20 000. Corpo JSON ≤ 1 MB (nginx) exceto upload (10 MB). |
| Numéricos | `quantity`: até 3 casas (`^\d{1,6}(\.\d{1,3})?$`), `> 0`; `width_m`/`height_m` do cliente: **até 2 casas** (1 cm), 0,01–100 (painel: 3 casas) — API.md §1.5/D-12; `pieces`: inteiro 1–1 000; paginação `per_page` ≤ 100. Limites evitam overflow em `bigint`. |
| Documentos BR | Regras `Cpf`, `Cnpj` (dígito verificador), CEP `^\d{5}-?\d{3}$`, telefone E.164 BR. Armazenados só dígitos. |
| Texto livre (cliente e admin) | **Texto puro**: rule/cast `PlainText` aplica `strip_tags`, remove caracteres de controle (exceto `\n`), normaliza Unicode NFC. Vale para nomes, endereços, observações, motivos, nomes de produto. |
| Texto rico (admin) — **decisão ADR-024** | Somente descrições de produto/categoria: HTML **allowlist** sanitizado no backend ao salvar com `ezyang/htmlpurifier` (ver ADR-024; substitui `symfony/html-sanitizer`) — tags `p, br, strong, em, ul, ol, li, h2, h3, a[href], table` básica (`thead, tbody, tr, th, td`); atributos só `href` (esquemas `https`, `mailto`), `rel`/`target` forçados (`noopener noreferrer`); sem `style`, `class`, `img`, `iframe`, `script`, eventos `on*`. Frontend renderiza com `<SafeHtml>` (DOMPurify com a mesma allowlist). |
| Sort/filtros | Allowlist (`in:name,price,created_at`) mapeada para colunas; nunca nome de coluna vindo do cliente. |
| Slugs | `^[a-z0-9]+(?:-[a-z0-9]+)*$`, tamanho conforme API.md §3.G, não pode colidir com slugs reservados da loja (ADR-015 + lista ampliada da ADR-026a). |

---

## 9. XSS no React e no shell SEO

- React escapa por padrão. **Proibido** `dangerouslySetInnerHTML` (ESLint `react/no-danger`
  como erro) **exceto** no componente `shared/ui/SafeHtml.tsx`, que sempre passa por
  `DOMPurify.sanitize` com a allowlist de §8.
- URLs dinâmicas (`href`, `src`) de dados do backend passam por `safeUrl()` (só `https:`,
  `mailto:`, relativas) — bloqueia `javascript:`.
- Nada de `eval`, `new Function`, `innerHTML` direto.
- Tokens: sessão em cookie `HttpOnly` (inacessível a JS); `localStorage` guarda só o
  `cv_cart_token` de visitante (baixo valor).
- **Shell SEO (Laravel)**: meta tags montadas com escape HTML (`e()`); JSON-LD com
  `json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)`
  para impedir `</script>` injetado por nome de produto; conteúdo inserido apenas nos
  marcadores `<!--seo:head-->` do `index.html`.
- E-mails: templates Blade com `{{ }}` (escape); nenhum `{!! !!}` com dado de usuário.
- CSP (§14) como segunda barreira.

---

## 10. SQL injection

- Somente Eloquent/Query Builder com bindings. `DB::raw`/`whereRaw`/`orderByRaw` só com
  literais do código ou placeholders `?` + array de bindings; revisão bloqueia interpolação.
- Nomes de coluna/direção de ordenação vêm de allowlist (§8).
- Busca: `websearch_to_tsquery('portuguese', unaccent(?))` com binding; prefixo de SKU com
  `ILIKE ? ESCAPE '\'` após escapar `\`, `%`, `_` (`Str::of($q)->replace(...)`); `q` ≤ 100.
- Usuário do banco da aplicação **sem** superuser e sem `CREATE ROLE`; migrations rodam com
  o mesmo usuário no MVP (dono do schema) — evolução: usuário de migração separado.
- Teste: payloads clássicos (`' OR 1=1 --`, `%`, `_`, `\`) na busca retornam 200 sem erro
  e sem vazamento.

---

## 11. Idempotência de pedido e pagamento

| Ponto | Mecanismo |
|---|---|
| Checkout (ADR-009) | `Idempotency-Key` UUID **obrigatório** (ausente/ inválido → 422). `unique(customer_id, idempotency_key)` + `pg_advisory_xact_lock(1001, customer_id)`. Repetição → mesmo pedido (200, `replayed: true`). Mesma chave + corpo diferente (`checkout_fingerprint`) → 409 `idempotency_conflict` (ver ADR-021/028). Chaves de clientes diferentes não colidem. |
| Criação da cobrança | `X-Idempotency-Key = payments.uuid` no gateway; replay do checkout reutiliza o mesmo `payments` (não cria segunda cobrança). |
| Webhook | `webhook_events unique(provider, external_id)`; duplicado → 200 sem reprocessar. |
| Transições de pagamento | Sob lock; aprovar já aprovado = no-op; commit de estoque só ocorre na transição `pending_payment → paid` (nunca 2×). |
| Estorno | Um estorno ativo por pagamento (índice único parcial em `payment_refunds` — ADR-026a/DATABASE); chave idempotente = uuid do estorno; processamento assíncrono com retry (ADR-028). |
| Ações admin | Mudança para o mesmo status → 409 `invalid_status_transition` (sem efeito); cancelamento de pedido já cancelado → 409. |
| Frontend | Botão "Finalizar" desabilitado durante envio; a chave é gerada **uma vez por tentativa de checkout** (guardada em memória da página) e reutilizada em retries. |

---

## 12. Webhooks

Fluxo: ARCHITECTURE.md §4.4.

1. Rota `POST /api/v1/webhooks/{provider}` com `provider` em allowlist (`mercadopago`,
   `sandbox` — este último **desabilitado em produção**). Grupo `webhook`: sem sessão, sem
   CSRF, `throttle:webhooks`, corpo ≤ 64 KB.
2. **Assinatura antes de tudo** (ADR-009): `PaymentWebhookVerifier` recalcula HMAC-SHA256
   (Mercado Pago: manifesto `id:{data.id};request-id:{x-request-id};ts:{ts};` com
   `MERCADOPAGO_WEBHOOK_SECRET`) e compara com `hash_equals`. Inválida → 401, log
   `security.warning` (sem payload), nada gravado.
3. **Tolerância de timestamp:** `abs(now - ts) ≤ 300 s`; fora → 401 (anti-replay de
   capturas antigas).
4. **Dedupe:** `INSERT … ON CONFLICT DO NOTHING` em `webhook_events`; duplicado → 200.
5. **Payload não é fonte da verdade:** o job consulta `getPayment(data.id)` no gateway com
   nossas credenciais; confere que `external_reference`/metadata = nosso `payments.uuid`,
   `transaction_amount` = `payments.amount_cents`, moeda `BRL`. Divergência →
   `PaymentAmountMismatch` (pedido para revisão, alerta) — **nunca** aprova.
6. **Allowlist de IP (opcional):** `WEBHOOK_ALLOWED_CIDRS[mercadopago]` checado após
   `TrustProxies` corretamente configurado (IP real). Desligado por padrão (IPs do provedor
   mudam); a assinatura é o controle principal.
7. Respostas não revelam detalhes (`{"status":"ok"}` / 401 vazio).
8. Segredo rotacionável: aceitar `*_WEBHOOK_SECRET` e `*_WEBHOOK_SECRET_PREVIOUS` durante a
   rotação.

---

## 13. Condições de corrida

| Situação | Controle |
|---|---|
| Último item disputado por dois checkouts | `InventoryService::lockForUpdate` (`SELECT … FOR UPDATE ORDER BY variant_id`), verificação sob lock, CHECK `reserved <= on_hand`, `on_hand >= 0`, `reserved >= 0` (ADR-008). Perdedor recebe 409 `insufficient_stock`. |
| Deadlock | Ordem global de locks (DATABASE.md §4.2) `advisory checkout → carts → orders → payments → coupons → inventory(variant_id)`; transações curtas, sem HTTP dentro. Deadlock detectado (`40P01`) → retry automático 1× na Action (`DB::transaction($fn, attempts: 2)`). |
| Limite de uso de cupom | `SELECT coupons … FOR UPDATE` em `redeem()`; conta `coupon_redemptions` (total e por cliente) dentro do lock; `unique(coupon_id, order_id)`. |
| Número do pedido | `nextval('order_number_seq')` (atômico; lacunas aceitáveis) → `CV-` + zero-padding 6; `unique(number)`. Nunca `MAX(number)+1`. |
| Pagamento aprovado × expiração | Ambos travam a linha de `orders` primeiro; expiração re-checa status sob lock e usa `SKIP LOCKED`; aprovação tardia → pedido reativado (`cancelled → paid`) se houver estoque, senão estorno automático (ver ADR-022). |
| Estorno duplo | Lock em `payments` + índice único parcial (§11). |
| Merge de carrinho concorrente (duas abas logando) | `SELECT carts FOR UPDATE` nos dois carrinhos (ordem por id); carrinho visitante é **apagado** após o merge (não existe `merged_at` — API.md D-15); token reutilizado → `404 cart_not_found`. |
| Cadastro simultâneo com mesmo e-mail/documento | Índices únicos (`citext` e-mail; documento) → 422. |
| Ajuste de estoque concorrente | `adjust()` trava a linha e grava movimento com `on_hand` antes/depois. |
| Job de expiração em 2 servidores | `onOneServer()` + `withoutOverlapping()` + `SKIP LOCKED`. |

---

## 14. Headers de segurança (nginx)

Snippet `docker/nginx/snippets/security-headers.conf` incluído em todos os `location`
que servem conteúdo (lembrar: `add_header` em um `location` anula os herdados — por isso
`include` em cada bloco):

```nginx
server_tokens off;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;   # só com TLS (prod/staging); preload após estabilizar
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()" always;
add_header Cross-Origin-Opener-Policy "same-origin" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://${ASSETS_HOST}; font-src 'self' data:; connect-src 'self' ${SENTRY_CONNECT}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests" always;
```

- `style-src 'unsafe-inline'` é necessário para o Emotion/MUI; evolução: nonce via
  `<meta property="csp-nonce">`. `script-src` **sem** `unsafe-inline`/`unsafe-eval`
  (Vite build não precisa). JSON-LD (`application/ld+json`) não é executado e não exige
  exceção.
- `img-src data:` para o QR code PIX em base64.
- Admin: mesma CSP + `X-Robots-Tag: noindex, nofollow`.
- API: `Cache-Control: no-store` em `/api/v1/me/*`, `/api/v1/admin/*`, `/api/v1/auth/*`,
  checkout; `X-Content-Type-Options` também nas respostas JSON.
- PHP: `expose_php=Off`, `fastcgi_hide_header X-Powered-By`.
- Futuro (cartão): liberar domínios do SDK do Mercado Pago em `script-src`/`frame-src`.
- Teste: E2E/HTTP verifica presença dos headers na página inicial, `/admin/` e numa resposta `/api`.

---

## 15. Upload de arquivos

| Controle | Detalhe |
|---|---|
| Quem | Só admin com `products.manage`. Clientes **não** fazem upload no MVP (arte de impressão fica para evolução, com antivírus). |
| Validação | `file|image|mimes:jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:5120|dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000`. MIME real por conteúdo (finfo), não por extensão. **SVG, GIF, HEIC, PDF proibidos.** |
| Bomba de descompressão | `getimagesize()` antes de decodificar; rejeita > 40 MP; `memory_limit` do worker dimensionado. |
| Re-encode | Job `ProcessProductImage` (fila `default`) decodifica e **re-codifica** para WebP (Intervention Image) em 300/800/1600 px — remove EXIF/GPS, metadados e conteúdo poliglota. Original guardado em prefixo **privado**. |
| Caminhos | Gerados pelo servidor: `public/products/{product_uuid}/{uuid}-{size}.webp` e `private/originals/{uuid}.{ext}`. Nome original do arquivo **nunca** usado em path (só gravado sanitizado como metadado). Sem `../`, sem path vindo do cliente. |
| Bucket | Política pública de leitura **só** para `public/*`; `private/*` acessível apenas por URL temporária (15 min). Credenciais S3 da aplicação restritas ao bucket (`s3:GetObject/PutObject/DeleteObject` no bucket). `Content-Type` definido explicitamente; `Content-Disposition: attachment` para exports. |
| Servir | Imagens servidas do S3/CDN (outro host) — nunca executadas pelo PHP; nginx não tem `location` que execute PHP fora de `public/index.php`. |
| Exports CSV | Gerados em memória e devolvidos na resposta (síncrono — API.md A-15); células iniciadas por `= + - @` prefixadas com `'` (CSV/formula injection). |

---

## 16. Gestão de segredos

- Segredos **somente** em variáveis de ambiente. `.env` no `.gitignore`; `.env.example`
  com placeholders (`MERCADOPAGO_ACCESS_TOKEN=`), nunca valores reais.
- Produção/staging: GitHub Environments (secrets do deploy) → arquivo `.env` na VM com
  permissão `600` ou secrets do provedor; nunca embutidos em imagem Docker (build args
  não contêm segredos; `.dockerignore` exclui `.env*`).
- Inventário: `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `AWS_ACCESS_KEY_ID/SECRET`,
  `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET`, `SANDBOX_WEBHOOK_SECRET`,
  `MAIL_PASSWORD`, `SENTRY_LARAVEL_DSN`, credenciais de transportadoras.
- Separados por ambiente (sandbox ≠ produção). Rotação: `APP_KEY` via `APP_PREVIOUS_KEYS`;
  webhook secret com período de sobreposição (§12); rotação imediata em caso de vazamento
  + revisão de logs.
- `config:cache` em produção (sem leitura de `.env` em runtime); `APP_DEBUG=false` (evita
  vazamento de env na página de erro).
- CI: `gitleaks` em todo PR; GitHub secret scanning/push protection ativado.
- Frontend: variáveis `VITE_*` são **públicas** — nunca colocar segredo.

---

## 17. Regras de log

- **Nunca logar:** senha (inclusive tentativa), tokens (sessão, CSRF, reset, API,
  `Authorization`, cookies), segredos, dados de cartão, payload bruto de gateway com dados
  do pagador, CPF/CNPJ completo, corpo de requests de `/auth/*`.
- **Mascarar:** e-mail (`jo***@g***.com`), CPF (`***.456.789-**`), CNPJ
  (`**.345.678/0001-**`), telefone (`(11) *****-4321`), CEP pode ser logado (não é
  identificador sozinho), IP permitido (interesse legítimo de segurança; retenção limitada).
- `App\Shared\Support\Mask` + `RedactSensitiveDataProcessor` (Monolog) que substitui por
  `[REDACTED]` valores de chaves (case-insensitive, em qualquer nível do contexto):
  `password*`, `current_password`, `token`, `*_token`, `secret`, `*_secret`, `authorization`,
  `cookie`, `x-xsrf-token`, `x-signature`, `card*`, `cvv`, `document`, `cpf`, `cnpj`,
  `access_token`, `api_key`.
- `webhook_events.payload` guarda só a notificação (ids/tipo); `payment_transactions`
  guarda subconjunto sanitizado da resposta do gateway (status, ids, valores, datas).
- Exceções: `dontFlash` inclui `password`, `password_confirmation`, `current_password`;
  Sentry com `send_default_pii=false` e mesmo redator.
- Retenção: logs de aplicação 90 dias; canal `security` 1 ano.
- Teste: log capturado (`Log::spy`/handler de teste) após login falho e após webhook não
  contém a senha/assinatura/CPF.

---

## 18. Rate limiting

Definidos com `RateLimiter::for()` (Redis). Chave de
cliente = `customer:{id}` quando logado, senão IP (IP real via `TrustProxies`).

> **Tabela canônica:** API.md §1.8 (ver ADR-028). A tabela abaixo foi alinhada a ela
> (paths antigos `/search`, `/price-quotes`, `/cart/shipping-quotes`, `/me/checkout` e
> `POST /cart/coupon` foram substituídos).

| Limiter | Rotas | Limite | Chave |
|---|---|---|---|
| `login` | `POST /auth/login`, `/admin/auth/login` | 5/min **e** 20/min | IP+e-mail **e** IP |
| `register` | `POST /auth/register` | 5/min, 20/h | IP |
| `password-reset` | `forgot-password`, `reset-password` (cliente/admin) | 5/min | IP+e-mail |
| `catalog` (grupo store) | GET settings/public, pages, categorias, marcas, produtos (sem `q`), related | 120/min | IP |
| `search` | `GET /products?q=…`, `GET /products/autocomplete` | 60/min | IP |
| `price-preview` | `POST /products/{slug}/price-preview` | 60/min | cliente/IP |
| `shipping-estimate` | `POST /shipping/quote` (página de produto) | 30/min | cliente/IP |
| `cart` | `GET/POST/PATCH/DELETE /cart*` (exceto cupom e frete) | 60/min | cart token/cliente/IP |
| `coupon` | `PUT /cart/coupon` | 10/min, 30/h | cliente/IP |
| `postal-code` | `GET /postal-codes/{cep}` | 20/min | IP |
| `shipping-quote` | `POST /cart/shipping-quote` | 10/min | cliente/IP |
| `checkout` | `POST /checkout`, `POST /me/orders/{uuid}/payment` | 5/min, 30/h | cliente |
| `checkout-limit` (regra de negócio, ADR-021) | checkout | máx. 3 pedidos `pending_payment` simultâneos por cliente → 409 `too_many_pending_orders` | cliente |
| `customer` (grupo `/me`) | demais rotas do cliente, `POST /checkout/preview` | 60/min | cliente |
| `admin` (grupo admin) | rotas admin | 300/min | admin |
| `admin-heavy` | relatórios/exports CSV, simulador de frete, teste de transportadora, dashboard (30/min) | 10/min | admin |
| `uploads` | upload de imagem | 30/min | admin |
| `webhooks` | `/webhooks/*` | 300/min | IP |
| `health` | `/api/health*` | 60/min | IP |
| `seo` | shell, sitemap | 300/min | IP |

Resposta 429 JSON com `Retry-After`; ocorrências de `login`/`checkout`/`webhooks`
logadas no canal `security`. nginx aplica `limit_req` grosso (ex.: 20 r/s por IP com burst)
como primeira barreira contra flood.

---

## 19. Auditoria

- `audit_logs` (ADR-016): `actor_type`, `actor_id`, `actor_label` (snapshot), `action`
  (`<resource>.<verb>` no passado: `products.updated`), `entity_type`, `entity_id`,
  `changes` jsonb (`{campo: [antes, depois]}` por **allowlist** de campos auditáveis de cada
  model — sem senha, token, documento completo), `ip`, `user_agent` (truncado),
  `request_id`, `created_at`.
- **Imutável:** sem rotas de edição/remoção; trigger Postgres `BEFORE UPDATE OR DELETE` →
  `RAISE EXCEPTION`; sem soft delete (ADR-016). Retenção 5 anos (limpeza futura só por
  comando de manutenção explícito, fora da aplicação).
- **O que é auditado (mínimo):** login/logout/falha de login admin; CRUD de admin users e
  papéis; alterações de preço (variante, faixas, tabelas, preço de cliente), promoções e
  cupons; CRUD de produtos/categorias (campos-chave); ajustes/entradas de estoque;
  mudanças de status, cancelamentos e estornos de pedidos; alterações de frete; settings;
  bloqueio/anonimização de cliente; revelação de documento (`reveal-document`); exports de
  relatório (quem exportou o quê); aprovação sandbox (`payments:sandbox-approve` em staging).
- Consulta: `GET /api/v1/admin/audit-logs` com filtros (ator, entidade, ação, período),
  permissão `audit_logs.view` (API.md §3.G.14).

---

## 20. LGPD

| Tema | Decisão |
|---|---|
| Papéis | Loja = controladora; gateway, provedor de e-mail, cloud, transportadoras = operadores (contratos/DPA). Encarregado (DPO) com contato publicado na política de privacidade. |
| Bases legais | Execução de contrato (cadastro, pedido, entrega); obrigação legal/regulatória (dados fiscais do pedido, NF-e); legítimo interesse (prevenção a fraude, logs de segurança); **consentimento** (marketing por e-mail, WhatsApp — `marketing_opt_in_at`, `whatsapp_opt_in_at`, revogável na conta). |
| Minimização | Coletar só o necessário: nome, e-mail, telefone, CPF/CNPJ (nota fiscal), endereços. Sem data de nascimento, sem gênero. PJ: razão social, IE opcional. |
| Proteção | TLS em trânsito; criptografia em repouso no disco do banco/backups e bucket (provedor). CPF/CNPJ armazenados em texto (necessários para unicidade e NF-e) com acesso restrito por permissão e mascarados em listagens, logs e e-mails. Evolução: cifra de coluna + blind index. |
| Direitos do titular | Acesso/portabilidade: `GET /api/v1/me/data-export` (JSON dos dados cadastrais e pedidos) 🔧; correção: edição na conta; eliminação: `POST /admin/customers/{id}/anonymize` (admin com `customers.manage` — API.md §6.3, a pedido do titular) — substitui nome/e-mail/telefone/documento por marcadores, remove endereços e sessões, mantém pedidos com snapshot pelo prazo fiscal (obrigação legal) e registra auditoria. Prazo de resposta: 15 dias. |
| Retenção | Pedidos e dados fiscais: 5 anos após o exercício (prazo fiscal); carrinhos: 30/90 dias; cotações: 24 h após expirar; `webhook_events`: 180 dias; logs de app: 90 dias; logs de segurança: 1 ano; audit: 5 anos; contas inativas sem pedidos: anonimizar após 5 anos (job futuro). |
| Cookies | Apenas estritamente necessários (sessão, `XSRF-TOKEN`) → sem banner de consentimento no MVP. Qualquer analytics/pixel futuro exige consentimento prévio e atualização da política. |
| Incidentes | Plano de resposta: conter, avaliar, registrar; comunicar ANPD e titulares em até 3 dias úteis quando houver risco relevante (Resolução CD/ANPD nº 15/2024). Logs com `request_id` + auditoria suportam a investigação. |
| Acesso interno | CPF/CNPJ completo só via `reveal-document` com `customers.view_sensitive` (auditado); listagens e detalhe mascarados; exports com PII exigem `reports.export` e são auditados. |
| Terceiros | Só os dados necessários vão ao gateway (nome, e-mail, CPF para PIX) e às transportadoras (CEP, peso, dimensões — sem nome no momento da cotação). |

---

## 21. Dependências e cadeia de suprimentos

- CI (ARCHITECTURE.md §9.4): `composer audit` e `npm audit --audit-level=high --omit=dev`
  falham o build; `gitleaks`; Dependabot semanal (composer, npm ×2, docker, actions) 🔧 P10.
- `composer.lock` e `package-lock.json` versionados; `npm ci`/`composer install` (nunca
  `update`) no CI e no build.
- Actions do GitHub fixadas por versão maior (ideal: SHA); permissões mínimas no workflow
  (`permissions: contents: read`).
- Imagens base oficiais com tag fixa (`php:8.4-fpm-alpine`, `postgres:16-alpine`) e rebuild
  semanal; `trivy` opcional no release.
- SLA de correção: crítica 48 h, alta 7 dias, média no próximo ciclo.
- Pacotes novos exigem justificativa no PR (manutenção ativa, popularidade, licença).

---

## 22. Checklist de testes de segurança (QA)

Testes em `backend/tests/Feature/Security/` (PHPUnit, Postgres real) salvo indicação.
Cada linha = ao menos um teste; nome sugerido entre parênteses. "Sem efeito" = assert no
banco de que nada mudou.

> **Expectativas alinhadas a API.md (ver ADR-028):** paths, códigos de erro (`code`) e nomes
> de permissões desta seção seguem API.md §1.6, §1.7 e §6. Campos perigosos ⇒ **422
> `prohibited` + nenhum efeito** (não mais "ignorado"); idempotência ⇒ `idempotency_conflict`;
> cupom inválido ao aplicar no carrinho ⇒ 422 `errors.code`, no checkout ⇒ **409
> `coupon_invalid`**. A matriz completa de testes (e o dono de cada ID) está em TESTING.md.

### 22.1 IDOR

| ID | Teste | Esperado |
|---|---|---|
| SEC-IDOR-01 | Cliente B faz `GET /me/orders/{uuid de A}` (`OrderIdorTest`) | 404 |
| SEC-IDOR-02 | Cliente B `POST /me/orders/{uuid de A}/cancel` | 404; pedido intacto |
| SEC-IDOR-03 | Cliente B `GET/PATCH/DELETE /me/addresses/{uuid de A}` | 404; endereço intacto |
| SEC-IDOR-04 | Checkout (e `POST /checkout/preview`) de B com `address_uuid` de A | 422 `errors.address_uuid`; nenhum pedido criado |
| SEC-IDOR-05 | Carrinho: token de carrinho que pertence ao cliente A usado por visitante via `X-Cart-Token` | `404 cart_not_found`; itens de A não expostos (com B logado o token é ignorado e vale o carrinho de B) |
| SEC-IDOR-06 | Recursos do cliente não expõem `id` interno (assert no JSON) | só `uuid`/`number` |
| SEC-IDOR-07 | Listagem `/me/orders` só retorna pedidos do próprio cliente | contagem = pedidos de A |
| SEC-IDOR-08 | Cliente B em `GET /me/orders/{uuid de A}/status`, `POST …/payment`, `…/reorder`, `…/cancellation-request` e `POST /dev/payments/{uuid de A}/approve` | 404; nada muda |
| SEC-IDOR-09 | Cliente B `PATCH /cart/items/{id}` / `DELETE /cart/items/{id}` com id de item do carrinho de A | 404; item de A intacto |

### 22.2 Autenticação e autorização

| ID | Teste | Esperado |
|---|---|---|
| SEC-AUTH-01 | Rotas `/me/*` sem login | 401 |
| SEC-AUTH-02 | Cliente logado acessa `/admin/*` | 401 (guard admin não autenticado) |
| SEC-AUTH-03 | Admin logado acessa `/me/*` sem sessão de cliente | 401 |
| SEC-AUTH-04 | **Matriz de permissões** (`AdminPermissionMatrixTest`, data provider): para cada rota admin × permissão, admin sem a permissão → `403 forbidden`, com → 2xx; inclui permissões condicionais a campos (`prices.manage`, `inventory.move` p/ `initial_stock`, `pricing.manage` p/ `price_list_id`, `customers.manage` p/ `cpf`) | conforme API.md §6.3 |
| SEC-AUTH-05 | Toda rota nomeada `admin.*` (exceto auth/me) tem middleware de permissão (`AdminRoutesRequirePermissionTest` varrendo `Route::getRoutes()`) | 0 rotas sem permissão |
| SEC-AUTH-06 | Admin com `admin_users.manage` (sem ser `super-admin`) tenta atribuir `super-admin` a si/outro, alterar os próprios papéis, editar/excluir papel do sistema, desativar o último `super-admin` | 403/422; sem efeito |
| SEC-AUTH-07 | Cancelar pedido **pago** com `orders.cancel_unpaid` sem `orders.cancel_paid` | `403 forbidden`; pedido intacto |
| SEC-AUTH-08 | Login com 6 tentativas/min (mesmo IP+e-mail) | 6ª → 429 |
| SEC-AUTH-09 | Login falho e recuperação com e-mail inexistente | mensagens idênticas às de e-mail existente |
| SEC-AUTH-10 | Sessão regenerada no login (id de sessão muda) e invalidada no logout (request seguinte → 401) | — |
| SEC-AUTH-11 | Senha fraca/comprometida (`Password::uncompromised` com Http fake) no cadastro | 422 |
| SEC-AUTH-12 | Sessão admin ociosa > 30 min (`travel`) | 401 `admin_session_expired` |
| SEC-AUTH-13 | Após reset de senha, sessão antiga do mesmo usuário | 401 |
| SEC-AUTH-14 | Admin desativado com sessão ativa | 401 no próximo request |
| SEC-AUTH-15 | Login de cliente/admin com `is_active=false` | `403 account_disabled` |
| SEC-AUTH-16 | `manager` acessa `/admin/users*`, `/admin/roles*` | 403 (ADR-027) |

### 22.3 Mass assignment

| ID | Teste | Esperado |
|---|---|---|
| SEC-MA-01 | Cadastro com `price_list_id`, `company_id`, `is_active`, `email_verified_at`, `roles` | **422** (`errors.<campo>` "não é permitido"); nenhum cliente criado. Campo desconhecido (`is_admin`) sozinho → ignorado |
| SEC-MA-02 | `PATCH /me` com `id`, `customer_id`, `status`, `email`, `type`, `company`; `PATCH /me/company` com `cnpj` | **422**; nada alterado |
| SEC-MA-03 | Admin update de produto com `id`, `created_at`, campos inexistentes | ignorados/sem efeito; `on_hand`/`reserved` e outros derivados listados no endpoint → 422 (API.md §1.7) |
| SEC-MA-04 | Teste de arquitetura: nenhum Model com `$guarded = []` e nenhum uso de `$request->all()` em `app/Modules` | passa |
| SEC-MA-05 | Checkout com `customer_id` de outro cliente, `status: paid`, `payment_status: approved` | **422**; nenhum pedido, reserva ou pagamento criado |

### 22.4 Manipulação de preço

| ID | Teste | Esperado |
|---|---|---|
| SEC-PRICE-01 | `POST /cart/items` (e `PATCH /cart/items/{id}`, `POST /products/{slug}/price-preview`) com `price`, `unit_price_cents`, `total_cents` | **422**; carrinho inalterado. Sem esses campos, preço = `PriceResolver` |
| SEC-PRICE-02 | Checkout com `total_cents: 1`, `subtotal_cents`, `items` | **422**; nenhum pedido |
| SEC-PRICE-03 | Preço muda entre carrinho e checkout (admin altera) | com `expected_total_cents` antigo → 409 `price_changed` com `summary` novo; reenviando o novo total → 201 com preço novo |
| SEC-PRICE-04 | Quantidade fora do `step`, abaixo do mínimo, negativa, 0, > máx., com 4 casas | 422 |
| SEC-PRICE-05 | `SQUARE_METER`: área **por peça** abaixo de `min_billable_area` (1 e N peças) | cobra `max(área da peça, mínimo) × peças` (ADR-019); estoque baixa só a área real |
| SEC-PRICE-06 | Largura diferente da `fixed_width_mm` ou fora da faixa | 422 |
| SEC-PRICE-07 | Valores enormes (`quantity: 1e15`, `width_m: 99999`) | 422, sem overflow/500 |
| SEC-PRICE-08 | Variante inativa/soft-deleted no carrinho no momento do checkout | 422 `cart_invalid` |
| SEC-PRICE-09 | Preço de cliente/tabela de A não aplicado para B | preço de B correto (`price_source` coerente) |
| SEC-PRICE-10 | `expected_total_cents` ausente no checkout | 422 |

### 22.5 Manipulação de frete

| ID | Teste | Esperado |
|---|---|---|
| SEC-SHIP-01 | Checkout com `shipping_price`/`shipping_cents: 0`/`shipping_price_cents` | **422**; nenhum pedido |
| SEC-SHIP-02 | `shipping_quote_id` inexistente, de outro cliente/carrinho ou aleatório; `shipping_option_id` inexistente | 409 `shipping_quote_invalid` / `shipping_option_invalid` (com nova `shipping_quote`); nenhum pedido; nada da cotação alheia vazado |
| SEC-SHIP-03 | Cotação expirada (> 30 min, `travel`) com preço mudado | 409 `shipping_quote_expired`/`shipping_price_changed` (preço igual → aceita com nova cotação, SHIPPING §7) |
| SEC-SHIP-04 | Cotar com CEP A (frete grátis/barato), trocar para endereço com CEP B | 409 `shipping_postal_code_changed` |
| SEC-SHIP-05 | Cotar, depois aumentar quantidade (peso/valor) e fechar com a mesma opção | 409 `shipping_quote_changed` (ou aceita se o preço recalculado for igual) |
| SEC-SHIP-06 | Método desativado pelo admin após a cotação | 409 `shipping_option_unavailable` |

### 22.6 Manipulação de desconto

| ID | Teste | Esperado |
|---|---|---|
| SEC-DISC-01 | Checkout com `discount`/`discount_cents`/`coupon_code` no corpo | **422**; nenhum pedido (cupom só via `PUT /cart/coupon`) |
| SEC-DISC-02 | `PUT /cart/coupon` com cupom expirado, futuro, inativo, soft-deleted | 422 `errors.code`; cupom não gravado |
| SEC-DISC-02b | Cupom válido no carrinho que expira/é desativado antes do checkout | 409 `coupon_invalid` com `coupon` e `summary` sem desconto; nenhum pedido |
| SEC-DISC-03 | Cupom abaixo do valor mínimo do pedido | `PUT /cart/coupon` → 422 `errors.code`; no checkout (subtotal caiu) → 409 `coupon_invalid` |
| SEC-DISC-04 | Cupom com limite por cliente = 1 usado 2× | 2º `PUT /cart/coupon` → 422; se aplicado antes do 1º pedido, 2º checkout → 409 `coupon_invalid` |
| SEC-DISC-05 | Cupom com limite total = 1, dois checkouts **concorrentes** (duas conexões/processos) | exatamente 1 pedido com cupom; o outro → 409 `coupon_invalid` |
| SEC-DISC-06 | Desconto maior que subtotal | total nunca negativo (mín. 0 + frete) |
| SEC-DISC-07 | Cupom liberado após expiração/cancelamento do pedido não pago | pode ser usado de novo |
| SEC-DISC-08 | Enumeração: 11 `PUT /cart/coupon`/min | 11ª → 429 `too_many_requests` |

### 22.7 Pedido duplicado e idempotência

| ID | Teste | Esperado |
|---|---|---|
| SEC-IDEM-01 | Checkout sem `Idempotency-Key` ou com valor não-UUID | 422 |
| SEC-IDEM-02 | Mesmo request 2× com a mesma chave | 1 pedido, 1 reserva, 1 pagamento; 2ª resposta 200 `replayed: true` com mesmo `uuid` |
| SEC-IDEM-03 | Mesma chave, corpo diferente (outro endereço) | 409 `idempotency_conflict` com `order: {uuid, number}` |
| SEC-IDEM-04 | Mesma chave usada por clientes diferentes | 2 pedidos independentes |
| SEC-IDEM-05 | Gateway falha (fake timeout) → 503; retry com a mesma chave e gateway OK | 1 pedido, 1 cobrança criada, PIX retornado |
| SEC-IDEM-06 | Dois checkouts concorrentes pelo último item (chaves diferentes) | 1 sucesso, 1 409; `reserved <= on_hand` |
| SEC-IDEM-07 | Limite de pedidos `pending_payment` por cliente (ADR-021) | 4º → 409 `too_many_pending_orders` |
| SEC-IDEM-08 | `POST /me/orders/{uuid}/payment` 2× para o mesmo pedido | no máximo 1 pagamento ativo |

### 22.8 Webhook replay e falsificação

| ID | Teste | Esperado |
|---|---|---|
| SEC-WH-01 | Webhook sem assinatura / assinatura inválida | 401; nada em `webhook_events`; pedido não pago |
| SEC-WH-02 | Assinatura válida com `ts` fora da tolerância (> 5 min) | 401 |
| SEC-WH-03 | Mesmo webhook válido enviado 2× (replay) | 2ª → 200 `{"status":"ok"}`, sem reprocessar; estoque comitado 1×; 1 `OrderPaid` (`Event::fake`), 1 e-mail |
| SEC-WH-04 | Webhook válido, mas `getPayment` (fake) retorna `pending` | pedido continua `pending_payment` |
| SEC-WH-05 | `getPayment` retorna valor menor que o pedido | pedido **não** pago; `PaymentAmountMismatch`; alerta |
| SEC-WH-06 | Webhook de pagamento de outro pedido/`external_reference` desconhecido | ignorado com log; nenhuma alteração |
| SEC-WH-07 | Aprovação chegando após expiração do pedido **com** estoque | pedido reativado `cancelled → paid` (ator system, histórico) (ADR-022) |
| SEC-WH-07b | Aprovação chegando após expiração **sem** estoque | pedido segue `cancelled`; estorno automático em `payment_refunds`; alerta |
| SEC-WH-08 | Provider fora da allowlist (`/webhooks/foo`) e `sandbox` em produção | 404 |
| SEC-WH-09 | Webhook não exige CSRF/sessão (assinado, sem cookie) | 200 |
| SEC-WH-10 | Rotas `/api/v1/dev/*` com `APP_ENV=production`/`staging` (teste de arquitetura sobre o registro de rotas) | não registradas (404) |

### 22.9 Outros

| ID | Teste | Esperado |
|---|---|---|
| SEC-CSRF-01 | `POST` stateful (Origin da SPA, com sessão) sem `X-XSRF-TOKEN` | 419 |
| SEC-XSS-01 | Descrição de produto com `<script>`, `onerror=`, `javascript:` salva pelo admin | armazenada sanitizada |
| SEC-XSS-02 | Nome de produto com `</script><script>alert(1)</script>` no shell SEO | JSON-LD/meta escapados (assert no HTML) |
| SEC-XSS-03 | (Vitest) `SafeHtml` remove scripts/handlers; ESLint `react/no-danger` ativo | passa |
| SEC-SQL-01 | Busca com `' OR 1=1 --`, `%`, `_`, `\` | 200, sem erro/vazamento |
| SEC-SORT-01 | `sort=password` / coluna inexistente | 422 |
| SEC-UP-01 | Upload de SVG, PHP renomeado para `.jpg`, arquivo > 5 MB, imagem 20000×20000 | 422 |
| SEC-UP-02 | Upload válido → path gerado pelo servidor, WebP re-codificado sem EXIF | passa |
| SEC-RL-01 | Rate limits de `checkout`, `shipping-quote`, `postal-code`, `webhooks` (data provider) | 429 no limite+1 |
| SEC-LOG-01 | Após login falho, cadastro e webhook: logs sem senha, token, assinatura, CPF completo | passa |
| SEC-HDR-01 | (E2E/Playwright ou teste de config nginx) headers CSP, HSTS (prod), XFO, nosniff, Referrer-Policy | presentes |
| SEC-AUD-01 | Alteração de preço, ajuste de estoque, mudança de status e cancelamento geram `audit_logs` com ator, `request_id`, sem dados sensíveis; UPDATE em `audit_logs` falha | passa |
| SEC-HEALTH-01 | `/api/health` não expõe versões/hosts/exceções | passa |
