# Storefront — Comunika Suprimentos

Loja (SPA) em **Vite + React 19 + TypeScript strict + MUI + TanStack Query + React Hook Form + Zod + React Router**.
Implementa o contrato de `docs/API.md` (canônico — ADR-028) e as telas de `docs/UX.md`.

## Rodar

```bash
cd storefront
npm install
npm run dev          # http://localhost:5173 — proxy de /api e /sanctum → VITE_API_PROXY_TARGET
npm run dev:mock     # sem backend: MSW no navegador (VITE_USE_MOCKS=true)
```

Login de demonstração nos mocks: `maria@example.com` / `senha1234`. Cupons: `PROMO10`, `FRETEGRATIS`.
CEP `99999999` simula "CEP não encontrado".

| Script | O que faz |
|---|---|
| `npm run lint` | oxlint (react, react-hooks, typescript, jsx-a11y, import, vitest) — zero warnings |
| `npm run typecheck` | `tsc -b` (strict) |
| `npm test` | Vitest + Testing Library + MSW (handlers espelham os exemplos da API.md) |
| `npm run build` | typecheck + `vite build` (rotas com code-splitting) |
| `npm run e2e` | Playwright (`e2e/`) contra a stack **real** (Laravel :8000 + Vite :5173) |

## Stack real + E2E (ADR-035)

```bash
# backend (terminal 1) — .env com PAYMENTS_DRIVER=sandbox e, sem acesso ao ViaCEP,
# SHIPPING_POSTAL_LOOKUP=fake (CEPs de Blumenau/Joinville/etc. resolvem offline)
cd backend && php artisan migrate:fresh --seed && php artisan serve --port=8000
# fila (terminal 2) — processa o webhook sandbox que marca o PIX como pago
cd backend && php artisan queue:work --queue=webhooks,default,notifications
# loja (terminal 3)
cd storefront && npm run dev
# E2E (reaproveita os servidores acima; sobe os que faltarem + um worker de fila)
cd storefront && npm run e2e
```

Login do seeder: `maria@example.com` / `password`. Pagamento PIX em dev: botão "Simular
pagamento (dev)" na tela do pedido ou `POST /api/v1/dev/payments/{uuid}/approve` (sessão do
cliente dono; **202**). Cenários: `purchase` (busca → 5 m R$ 79,50 → frete 89010-000 → cadastro
PF → checkout entrega própria + PIX R$ 99,50 → aprovar → pago/timeline), `lona` (1,20 × 2,50 →
3,00 m² / R$ 90,00), `auth` (login inválido 422, cadastro duplicado 422), `price-changed`
(409 com `CheckoutSummary` completo) e `smoke` (páginas principais sem erro de API/JS).
O `price-changed` altera preço direto no banco `ecommerce` via `psql` (variáveis `E2E_DB_*`).

## Variáveis de ambiente

| Variável | Padrão | Uso |
|---|---|---|
| `VITE_API_PROXY_TARGET` | `http://localhost:8000` | Destino do proxy `/api` e `/sanctum` no dev (`changeOrigin: false` para o Sanctum stateful). |
| `VITE_USE_MOCKS` | `false` | `true` liga o Service Worker do MSW (`public/mockServiceWorker.js`). |
| `VITE_SITE_URL` | — | Opcional; origem absoluta para metadados. |

## Arquitetura (ARCHITECTURE §8)

```text
src/
  app/        providers, router (rotas lazy), theme (tokens UX §2), layouts, header/footer
  shared/
    api/        client.ts (axios: cookies Sanctum, X-XSRF-TOKEN, X-Cart-Token, X-Request-Id,
                419→renova CSRF e repete 1x, 404 cart_not_found→apaga token e repete),
                errors.ts (ApiError + mensagens pt-BR por code), types.ts (API.md §2)
    formatters/ BRL, quantidade/unidade, peso, CEP, CPF/CNPJ (inclui CNPJ alfanumérico), telefone, datas
    saleUnit/   aritmética inteira (milésimos, round half up) espelho do backend + validação do configurador
    ui/         Price, StockBadge, QuantityStepper, SummaryPanel, ShippingOptions, Seo, SafeHtml (DOMPurify)…
  features/<catalog|cart|checkout|auth|account|institutional|errors>/{api,components,hooks,schemas,pages}
  mocks/      fixtures + handlers MSW (browser e testes)
```

Regras respeitadas: o front **nunca** calcula preço como verdade — a prévia local (inteiros) é
substituída pela resposta de `POST /products/{slug}/price-preview` (debounce 300 ms); o checkout
envia só a whitelist de `POST /checkout` com `Idempotency-Key` estável por tentativa
(`sessionStorage`), reutilizada em retries (rede/503) e renovada quando o corpo muda.
