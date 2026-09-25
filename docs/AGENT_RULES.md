# Regras para agentes de implementação (backend)

1. **Contratos entre módulos**: use EXATAMENTE as interfaces/DTOs de
   `docs/ARCHITECTURE.md` §2 e §7 (ex.: `InventoryService`, `PriceResolver`,
   `CouponService`, `SaleQuantityResolver`, `ShippingEngine`, `ShippingQuoteService`,
   `PaymentService`, `PaymentGatewayInterface`, `CartService`, `OrderPlacement`).
   Ficam em `app/Modules/<Dono>/Contracts` (interfaces) e `app/Modules/<Dono>/DTOs`.
   - O agente **dono** cria as interfaces e DTOs **como primeiro passo**.
   - Se você **consome** um contrato cujo arquivo ainda não existe, crie-o
     literalmente conforme ARCHITECTURE.md (mesmo namespace/assinatura) e reporte.
     Se já existe, não altere a assinatura; adapte-se e reporte divergências.
2. **Contrato HTTP**: `docs/API.md` é canônico (ADR-028). Paths, payloads, códigos
   de erro, permissões e formatos exatamente como lá.
3. **Escopo de arquivos**: edite somente os módulos que são seus
   (`app/Modules/<Modulo>/**`, `tests/**/<Modulo>/**`). Não edite migrations,
   seeders, `app/Shared`, `config`, `bootstrap` de outro agente. Se faltar coluna/
   tabela, crie uma **nova migration** `YYYY_MM_DD_HHMMSS_<modulo>_<descricao>.php`
   aditiva e reporte. Ajustes pequenos em Models do seu módulo são permitidos.
4. **Sem novos pacotes Composer.**
5. **Arquitetura**: controllers finos → Form Request (whitelist; campos perigosos
   `prohibited`) → Action/Service (transações) → Resource. Policies para o
   cliente (IDOR), permissões spatie (guard `admin`) no admin. Eventos com
   listeners registrados explicitamente no ServiceProvider do módulo; efeitos
   externos em listeners `ShouldQueue` + `afterCommit`.
6. **Testes**: PHPUnit em Postgres com o SEU banco:
   `DB_DATABASE=ecommerce_test_<x> php artisan test --filter=<Modulo>` (ou pastas
   do seu módulo). Unit + Feature cobrindo regras, autorização e casos de erro.
   Outros agentes trabalham em paralelo: falhas fora do seu módulo não são suas —
   reporte. Rode `vendor/bin/pint` somente nos seus arquivos
   (`vendor/bin/pint app/Modules/<Modulo> tests/.../<Modulo>`).
7. **Não faça commit.** O coordenador integra.
8. Relatório final (≤25 linhas): endpoints entregues, contratos criados/consumidos,
   migrations novas, testes (quantidade/resultado), divergências e pendências.
