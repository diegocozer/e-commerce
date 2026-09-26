# Comunika Suprimentos · Painel (admin SPA)

Painel administrativo da loja de suprimentos para comunicação visual. SPA React servida em
`/admin` e que consome **somente** a API documentada em [`docs/API.md`](../docs/API.md)
(contrato canônico — ADR-028), grupo `/api/v1/admin`, guard `admin`, sessão Sanctum stateful.

## Stack

Vite 8 · React 19 · TypeScript (strict, sem `any`) · MUI 9 (CSS variables, tema claro/escuro
seguindo o sistema) · TanStack Query 5 · React Router 7 (data router) · React Hook Form + Zod 4 ·
Recharts · Vitest + Testing Library + MSW 2 · oxlint.

## Scripts

| Comando | O que faz |
|---|---|
| `npm run dev` | Dev server em `http://localhost:5174/admin/`, proxy de `/api` e `/sanctum` para `VITE_API_PROXY_TARGET` (padrão `http://localhost:8000`) |
| `npm run dev:mocks` | Igual, com `VITE_USE_MOCKS=true`: MSW no navegador, painel funciona **sem backend** |
| `npm run lint` | oxlint (react, typescript, jsx-a11y, vitest) com `--deny-warnings` |
| `npm run typecheck` | `tsc -b --noEmit` |
| `npm test` | `vitest run` (jsdom + MSW em Node) |
| `npm run build` | Typecheck + build de produção em `dist/` (base `/admin/`, code-split por rota) |

## Variáveis de ambiente (`.env.local`, ver `.env.example`)

- `VITE_API_PROXY_TARGET` — backend Laravel para o proxy de desenvolvimento.
- `VITE_USE_MOCKS` — `true` liga os mocks (login: qualquer e-mail/senha; `errada` como senha → 422;
  `bloqueado@comunika.test` → 403 `account_disabled`). Sessão mockada é super-admin.
- `VITE_APP_ENV_LABEL` — chip de ambiente na barra superior (ex.: `HOMOLOGAÇÃO`); vazio em produção.

## Estrutura

```text
src/
├── app/            providers, tema (tokens UX §2), rotas lazy, layout (sidebar/topbar/breadcrumbs),
│                   guards (RequireAuth, RequirePermission → 403), SessionHandlers (401/403)
├── shared/
│   ├── api/        client.ts (fetch + credentials + X-XSRF-TOKEN + retry único em 419),
│   │               errors.ts (ApiError por `code`), types.ts (copiado de API.md §2), extraTypes.ts, lookups.ts
│   ├── auth/       useMe, useCan('orders.fulfill'), <Can>, catálogo de permissões
│   ├── formatters/ dinheiro (centavos inteiros, parse por string), quantidade (3 casas), datas (America/Sao_Paulo),
│   │               documentos, rótulos/cores de status (UX §6.1)
│   ├── hooks/      useListParams (estado de lista na URL), useUnsavedChangesGuard, useFormSubmit, …
│   └── ui/         DataTable, ListToolbar, PageHeader, ConfirmDialog, MoneyField, QuantityField, IntField,
│                   VariantPicker, KeyValue, estados (Empty/Error/Loading), snackbars;
│                   form/: FormPage, FormSection, FormDialog, FormErrorSummary, applyServerErrors, adaptadores RHF
├── features/<feature>/{api,components,pages,schemas}
│                   auth, dashboard, products, categories, brands, inventory, orders, customers, pricing,
│                   promotions (promoções + cupons), shipping, users (usuários + papéis), settings, audit, reports
├── mocks/          seed, db em memória, handlers MSW por domínio (browser.ts / server.ts)
└── test/           setup (MSW, polyfills jsdom), utils (renderApp com rotas reais, loginAs)
```

## Telas e rotas (`basename /admin`)

| Rota | Tela | Permissão |
|---|---|---|
| `/entrar`, `/recuperar-senha`, `/redefinir-senha` | Login e senha | — |
| `/` | Dashboard (KPIs, faturamento 30 dias, filas, entregas do dia, mais vendidos, estoque baixo) | `dashboard.view` |
| `/produtos`, `/produtos/novo`, `/produtos/:id` | Lista (filtros, bulk) e formulário (geral, unidade de venda com campos condicionais, variantes upsert, faixas, imagens, SEO com prévia Google) | `products.view` / `products.manage` |
| `/categorias`, `/marcas` | Árvore (reordenação ↑↓) e marcas | `products.view` |
| `/estoque` | Saldo em mãos/reservado/disponível, entrada, ajuste (motivo obrigatório), mínimo, histórico | `inventory.view` |
| `/pedidos`, `/pedidos/:id` | Lista com abas por status e detalhe com transições permitidas pela API | `orders.view` |
| `/clientes(/:id)`, `/empresas(/:id)` | Clientes/empresas, atribuição de tabela de preço, bloqueio, LGPD | `customers.view` |
| `/precos/tabelas(/:id)`, `/precos/clientes` | Tabelas de preço e preços por cliente/empresa | `products.view` / `customers.view` |
| `/promocoes`, `/cupons` | Promoções (prévia de preço) e cupons (usos) | `products.view` / `coupons.manage \| promotions.manage` |
| `/frete/transportadoras`, `/metodos`, `/zonas(/:id)`, `/regras`, `/simulador` | Frete completo, regras agrupadas por método › zona com chips e prioridade, simulador com trace | `shipping.manage` |
| `/usuarios`, `/papeis` | Usuários (convite) e matriz de permissões | `admin_users.manage` |
| `/configuracoes` | Settings tipados por grupo, cada seção salva separadamente | `settings.manage` |
| `/auditoria` | Logs com filtros e diff, jobs com falha | `audit_logs.view` |
| `/relatorios` | 10 relatórios, período/agrupamento/filtros, KPIs, gráfico, totais, CSV | `reports.*` |

## Convenções

- **Permissões:** o menu e as rotas usam `/admin/me.permissions`; sem permissão de ver → item oculto e
  página 403; sem permissão de agir → botão não renderizado. O backend continua sendo a autoridade:
  `403 forbidden` numa ação mostra snackbar e recarrega `/admin/me`.
- **Sessão:** `401` (inclusive `admin_session_expired`) limpa o cache e leva a
  `/entrar?expirada=1&redirect=<rota atual>`; após o login volta para a rota (só caminhos internos).
- **Transições de pedido:** os botões vêm **exclusivamente** de `allowed_transitions`/`can_cancel`; os
  campos do dialog seguem `required_fields`/`optional_fields`. Nada é otimista.
- **Dinheiro:** entrada BRL convertida para centavos por string (sem `parseFloat`); quantidades com até
  3 casas, dimensões de material em metros, embalagem em cm (1 casa), peso em gramas (regras de frete
  digitadas em kg com 3 casas = gramas exatos).
- **Formulários:** Zod espelha as regras da API; 422 é mapeado campo a campo (`variants.0.sku`), resumo
  "Corrija N campos", guarda de alterações não salvas, Ctrl+S, snackbar ao salvar; `expected_updated_at`
  enviado em produto/zona (409 `stale_resource` → aviso para recarregar).
- **Listas:** paginação/ordenação/filtros no servidor e na URL (`?q=&status=&page=&per_page=&sort=`).

## Testes

`npm test` — 12 arquivos / 70 testes: formatadores e conversão de dinheiro; `MoneyField`; cliente HTTP
(XSRF, retry em 419, 422); permissões (menu, ações ocultas, 403, redirect de sessão); detalhe do pedido
(somente transições permitidas, POST em `/transitions` com rastreio, retirada, cancelamento com estorno,
409); validação de regra de frete; ajuste/entrada de estoque exigindo motivo; login (422/403/redirect);
formulário de produto com campos condicionais por unidade e mapeamento de 422.

## Pontos do contrato interpretados (alinhar com o backend)

1. Sessão lida em `GET /admin/me` (API.md §3.G.1), não `/admin/auth/me`.
2. 422 do login ("E-mail ou senha inválidos.") esperado em `errors.email`; exibido como alerta geral.
3. Detalhe de pedido por `id` (`/pedidos/:id`), conforme API §1.1 (ARCHITECTURE §8.2 citava `:number`).
4. Campos opcionais vazios **não** são enviados em `transitions` (em vez de `null`).
5. Trace do simulador tipado a partir de SHIPPING §10.1 (`shared/api/extraTypes.ts`); o painel usa
   `methods[].name` quando presente (sugestão: incluir o nome do método no trace).
6. Settings: `PATCH /admin/settings` enviado por grupo, sem `expected_updated_at` (não há um único
   `updated_at` para a coleção).
7. Faixas de preço enviam apenas `min_quantity` (o "até" exibido é derivado da próxima faixa).
