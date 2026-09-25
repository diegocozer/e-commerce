# Regras de Negócio — E-commerce de Suprimentos para Comunicação Visual

> **Autor:** Agente 1 — Product Owner / Business Analyst
> **Status:** v1.0 (MVP)
> **Fonte da verdade transversal:** [`DECISIONS.md`](./DECISIONS.md). Este documento
> **detalha** as ADRs sob a ótica do negócio e nunca as contradiz. Lacunas encontradas
> estão em [Questões para o Architect](#12-questões-para-o-architect).
> **Idioma:** pt-BR; identificadores de código (tabelas, colunas, enums, permissões) em inglês.

## Sumário

1. [Visão do negócio e glossário](#1-visão-do-negócio-e-glossário)
2. [Personas](#2-personas)
3. [Jornadas principais](#3-jornadas-principais)
4. [Regras de negócio por domínio](#4-regras-de-negócio-por-domínio)
   - 4.1 Catálogo (`RN-CAT`)
   - 4.2 Unidades de venda e cálculo de quantidade (`RN-QTD`)
   - 4.3 Peso e dimensões logísticas (`RN-LOG`)
   - 4.4 Estoque (`RN-EST`)
   - 4.5 Preços (`RN-PRC`)
   - 4.6 Promoções e cupons (`RN-CUP`)
   - 4.7 Clientes (`RN-CLI`)
   - 4.8 Carrinho (`RN-CAR`)
   - 4.9 Checkout (`RN-CHK`)
   - 4.10 Pedidos (`RN-PED`)
   - 4.11 Pagamentos (`RN-PAG`)
   - 4.12 Frete — resumo (`RN-FRT`)
   - 4.13 Notificações (`RN-NOT`)
   - 4.14 Admin — papéis e permissões (`RN-ADM`)
   - 4.15 Relatórios (`RN-REL`)
   - 4.16 LGPD e privacidade (`RN-LGPD`)
   - 4.17 Busca e SEO (`RN-BUS`)
5. [Casos de borda (edge cases)](#5-casos-de-borda-edge-cases)
6. [Escopo do MVP](#6-escopo-do-mvp)
7. [Fora do MVP](#7-fora-do-mvp)
8. [Roadmap por fases](#8-roadmap-por-fases)
9. [Critérios de aceite — fluxo final de sucesso](#9-critérios-de-aceite--fluxo-final-de-sucesso)
10. [Convenções de formatação e exibição](#10-convenções-de-formatação-e-exibição)
11. [Rastreabilidade RN ↔ ADR](#11-rastreabilidade-rn--adr)
12. [Questões para o Architect](#12-questões-para-o-architect)

### Como referenciar

Cada regra tem um ID estável `RN-<DOMÍNIO>-<NNN>`. Outros agentes (Backend, Frontend,
Admin, QA) devem citar o ID em código de teste, PRs e documentação
(ex.: `test_rn_qtd_010_step_invalido_retorna_422`). IDs **não são reutilizados**; regras
removidas ficam marcadas como `(revogada)`. Casos de borda usam `EC-NNN`; critérios de
aceite usam `CA-NNN`.

---

## 1. Visão do negócio e glossário

### 1.1 Visão

Loja online B2B/B2C de **insumos para comunicação visual** (lonas, vinis, adesivos,
papéis, bobinas, tintas, fitas, ilhós, ferramentas e acessórios), sediada na região de
**Blumenau/SC**. Atende principalmente gráficas rápidas, birôs de impressão,
instaladores e empresas com compras recorrentes, além de consumidores pessoa física
ocasionais.

Diferenciais que o sistema precisa suportar:

| Diferencial | Implicação no sistema |
|---|---|
| Venda fracionada (metro linear, m², kg) | Quantidades decimais exatas, `quantity_step`, área mínima faturável (ADR-003/004) |
| Corte sob medida | Largura × altura × peças; snapshot de dimensões no pedido |
| Preço diferenciado para revenda/atacado | Faixas por quantidade, tabelas de preço, preço por cliente, "menor vence" (ADR-005) |
| Entrega própria na região (Vale do Itajaí) e retirada no balcão | Métodos `own_delivery` e `pickup` (ADR-011) |
| Recompra rápida | "Comprar novamente" a partir do pedido |
| PIX instantâneo | Aprovação via webhook, expiração de 30 min (ADR-008/010) |

Objetivos de negócio do MVP:

1. Vender online com cálculo **correto** de quantidade, preço, frete e estoque.
2. Reduzir atendimento manual por WhatsApp/telefone para pedidos simples.
3. Dar ao operador um painel único para separar, enviar/entregar e controlar estoque.
4. Base preparada para ERP/NF-e, transportadoras e condições B2B (fases seguintes).

### 1.2 Glossário

| Termo | Definição | Identificador no código |
|---|---|---|
| **Metro linear (m)** | Comprimento desenrolado de um material de largura fixa. Ex.: vinil 1,22 m de largura vendido por metro: 5 m = um pedaço de 1,22 × 5 m. | `LINEAR_METER` |
| **Metro quadrado (m²)** | Área = largura × altura × peças. Usado para materiais cortados sob medida (lona, tecido). | `SQUARE_METER` |
| **Bobina** | Rolo grande de material (papel, vinil, lona) vendido inteiro. Na loja é vendida como `ROLL`. Ex.: bobina de papel 0,914 × 50 m. | `ROLL` |
| **Rolo** | Sinônimo comercial de bobina para itens menores (fita, vinil de recorte em rolo fechado). Vendido por unidade inteira de rolo. | `ROLL` |
| **Largura útil** | Largura efetivamente imprimível/aproveitável do material (pode ser menor que a largura nominal por causa de bordas/liner). Exibida no produto como informação técnica; o cálculo de preço usa a largura **faturável** (`fixed_width_mm` ou a largura informada). | atributo de variante (`usable_width_mm`, informativo) |
| **Largura fixa** | O material só é vendido naquela largura (ex.: vinil 1,22 m, lona 3,20 m). O cliente informa apenas o comprimento/altura. | `fixed_width_mm` |
| **Faixa de dimensões** | Material cortado sob medida: cliente informa largura e altura dentro de limites. | `min_width_mm`, `max_width_mm`, `min_height_mm`, `max_height_mm` |
| **Peças** | Número de cortes iguais (mesma largura × altura) em uma linha do carrinho. | `pieces` |
| **Área mínima faturável** | Menor área cobrada numa linha `SQUARE_METER`; se a área calculada for menor, cobra-se a mínima. | `min_billable_area` |
| **Passo (step)** | Incremento permitido da quantidade (ex.: 0,10 m). | `quantity_step` |
| **Unidade de venda** | Como o produto é vendido e cobrado. | `sale_unit` |
| **SKU** | Código único da variante. | `product_variants.sku` |
| **Variante** | Combinação vendável de um produto (cor, acabamento, largura, gramatura). Estoque, preço e pedido são por variante. | `product_variants` |
| **Cubagem / peso cubado** | Peso "volumétrico" usado por transportadoras: `C × L × A (cm) / fator`. Cobra-se o **maior** entre peso real e cubado. Fator típico 6000 (aéreo) ou 300 kg/m³ (rodoviário ≈ fator 3333). Detalhes em `SHIPPING.md`. | `ShippingRequest` |
| **Gramatura** | Peso por m² do material (ex.: lona 440 g/m², papel 90 g/m²). Atributo técnico, e base para `weight_g` por m². | atributo |
| **PF** | Pessoa física (consumidor, autônomo) — identificada por CPF. | `customers.type = 'individual'` |
| **PJ** | Pessoa jurídica (empresa) — identificada por CNPJ. | `customers.type = 'company'` + `companies` |
| **IE** | Inscrição Estadual (cadastro de contribuinte de ICMS). PJ informa número ou "ISENTO". | `companies.state_registration` |
| **Razão social / Nome fantasia** | Nome jurídico / nome comercial da empresa. | `companies.legal_name` / `companies.trade_name` |
| **Tabela de preço** | Lista de preços atribuída a clientes (varejo, atacado, revendedor, específica). | `price_lists` |
| **Faixa de preço por quantidade** | Preço unitário que muda conforme a quantidade (ex.: 1–10, 11–50, 51+). | `price_tiers` |
| **Preço do cliente** | Preço negociado para um cliente/empresa específico. | `customer_prices` |
| **Promoção** | Redução de preço por produto/categoria/marca com vigência, aplicada como candidato de preço. | `promotions` / preço promocional da variante |
| **Cupom** | Código que dá desconto no **pedido** (subtotal) ou frete grátis. | `coupons` |
| **Reserva** | Quantidade comprometida por pedidos ainda não pagos. | `inventory.reserved` |
| **Disponível** | `on_hand − reserved`. É o que pode ser vendido. | — |
| **Retirada (balcão)** | Cliente busca o pedido na loja. | `pickup` |
| **Entrega própria** | Entrega com veículo da empresa nas cidades atendidas. | `own_delivery` |
| **Frete por tabela** | Preço por faixa de peso/valor por zona. | `table_rate` |
| **Dia útil** | Segunda a sexta, exceto feriados nacionais (e municipais de Blumenau, quando configurados). | — |
| **Snapshot** | Cópia imutável dos dados no momento do pedido (nome, SKU, preço, dimensões, endereço). | ADR-016 |
| **Idempotency-Key** | UUID enviado no checkout para evitar pedido duplicado. | ADR-009 |

---

## 2. Personas

### 2.1 Clientes (loja)

| # | Persona | Perfil | Objetivos | Dores atuais | O que o sistema precisa oferecer |
|---|---|---|---|---|---|
| P1 | **Marcos — dono de gráfica rápida (PJ)** | Gráfica com 2 impressoras de grande formato em Gaspar. Compra vinil, lona e tinta toda semana. | Repor material rápido, pagar preço de revenda, receber no dia seguinte. | Precisa ligar/mandar WhatsApp para saber preço e estoque; preço muda sem aviso. | Preço de tabela "revendedor" visível após login, estoque em tempo real, recompra em 1 clique, entrega própria em Gaspar. |
| P2 | **Juliana — instaladora autônoma (PF ou MEI)** | Instala adesivos e ACM em fachadas; trabalha no celular, na rua. | Comprar pequenas quantidades (3–10 m de vinil, ilhós, espátula) e retirar no balcão. | Perde tempo no balcão esperando separação. | Checkout mobile rápido, retirada com aviso "pronto para retirada", PIX. |
| P3 | **Ricardo — comprador de empresa (PJ, compras recorrentes)** | Setor de compras de uma rede de lojas em Joinville; compra bobinas e papéis mensalmente. | Pedido com CNPJ/IE corretos, histórico de compras, prever prazo. | Precisa de dados fiscais corretos e controle de gastos. | Cadastro PJ com IE, histórico de pedidos, faixas de quantidade, frete por tabela para Joinville, (futuro) faturado com limite de crédito. |
| P4 | **Ana — consumidora PF ocasional** | Quer adesivar um móvel ou fazer uma faixa de aniversário. | Entender quanto comprar e quanto vai custar. | Não sabe o que é metro linear vs m²; medo de errar a medida. | Calculadora clara (largura × altura, área faturada), explicações, preço total antes de adicionar. |

### 2.2 Operadores (painel admin)

| # | Persona | Papel (`role`) | Objetivos | Dores | Precisa |
|---|---|---|---|---|---|
| P5 | **Paulo — vendedor** | `seller` | Atender clientes, consultar pedidos, cadastrar clientes, aplicar tabela de preço. | Não sabe status real do pedido. | Visualizar pedidos/clientes, cancelar pedidos não pagos, criar cupons (se autorizado). |
| P6 | **Sérgio — estoquista/expedição** | `warehouse` | Separar, cortar, embalar, despachar/entregar; dar entrada de mercadoria; ajustar estoque. | Lista de separação em papel; divergência de estoque. | Fila de pedidos `paid`, mudar status até `delivered`/`picked_up`, movimentos de estoque com motivo, alerta de estoque baixo. |
| P7 | **Fernanda — financeiro** | `finance` | Conferir pagamentos, fazer estornos, ver faturamento. | Conciliar PIX manualmente. | Lista de pagamentos, reconsulta ao gateway, estorno, relatórios. |
| P8 | **Carla — gerente/admin** | `super-admin` ou `manager` (ver ADR-023) | Configurar loja: produtos, preços, frete, usuários e permissões. | Depende de TI para mudar preço/frete. | Acesso total, auditoria de quem fez o quê. |

---

## 3. Jornadas principais

Notação: **[L]** loja (storefront), **[A]** painel (admin), **[S]** sistema (backend/jobs).

### J01 — Compra por metro linear (vinil 1,22 m)

1. [L] Cliente abre "Vinil Adesivo Branco Brilho 1,22 m" (sale_unit `LINEAR_METER`, `min_quantity` 1,00 m, `quantity_step` 0,10 m, `max_quantity` 50,00 m).
2. [L] Informa **5** (m). A tela mostra: "1,22 m × 5,00 m — R$ 15,90/m — **Total R$ 79,50**" (RN-QTD-020).
3. [L] Se digitar 5,05 → erro "Quantidade deve ser múltiplo de 0,10 m. Sugestões: 5,00 ou 5,10" (RN-QTD-010).
4. [L] (Opcional) Calcula frete informando CEP (J09).
5. [L] Adiciona ao carrinho → segue ao checkout (J12).

### J02 — Compra por m² com largura fixa (lona backlight 3,20 m)

1. [L] Produto `SQUARE_METER` com `fixed_width_mm = 3200`. O campo largura aparece **bloqueado** em 3,20 m.
2. [L] Cliente informa altura 2,00 m e peças 1 → área 6,400 m² × R$ 32,00 = **R$ 204,80**.
3. [L] Validação de `min_height_mm`/`max_height_mm` (ex.: 0,50–50,00 m).

### J03 — Compra por m² com dimensões variáveis (lona front sob medida)

1. [L] Produto `SQUARE_METER` com faixa largura 0,30–3,20 m, altura 0,30–50,00 m, `min_billable_area` 0,50 m².
2. [L] Cliente informa 1,20 × 2,50 m, 1 peça → área 3,000 m² × R$ 30,00 = **R$ 90,00**.
3. [L] Se informar 0,40 × 0,50 m → área 0,200 m² → aviso "Área mínima faturável: 0,50 m². Será cobrado 0,50 m²" → **R$ 15,00** (RN-QTD-031).
4. [L] Pode informar peças: 1,20 × 2,50 × 3 peças = 9,000 m² → R$ 270,00.

### J04 — Compra por unidade / rolo / caixa / kg

- Ilhós nº 0 latão (UNIT, `quantity_step` 1): 100 × R$ 0,50 = **R$ 50,00**.
- Bobina papel sulfite 90 g 0,914 × 50 m (ROLL): 2 × R$ 350,00 = **R$ 700,00**.
- Lâmina de estilete 18 mm, caixa c/ 10 (BOX): 3 × R$ 24,90 = **R$ 74,70**.
- Pó hot melt DTF (KG, step 0,5): 1,5 kg × R$ 89,90 = **R$ 134,85**.

### J05 — Recompra ("Comprar novamente")

1. [L] Cliente logado → Minha conta → Pedidos → CV-000123 → **Comprar novamente**.
2. [S] Para cada item do pedido: valida produto/variante ativos, regras de quantidade atuais e disponibilidade; adiciona ao carrinho com **preço atual** (RN-PED-040).
3. [L] Exibe resumo: "4 de 5 itens adicionados. 'Lona Front 280 g' está indisponível." e destaca itens cujo preço mudou.

### J06 — Retirada no balcão

1. [L] No frete, cliente escolhe "Retirar na loja — Blumenau — Grátis — pronto em até 1 dia útil".
2. [S] Pagamento aprovado → `paid`. [A] Expedição separa → `processing` → `ready_for_pickup` (cliente recebe e-mail "Pedido pronto para retirada").
3. [A] Na retirada, operador confere nome/documento ou número do pedido e marca `picked_up`, registrando quem retirou (RN-PED-024).

### J07 — Entrega própria

1. [L] CEP de Blumenau/Gaspar/Indaial/Pomerode → opção "Entrega própria" com preço por cidade.
2. [A] `paid` → `processing` (separação) → `shipped` ("Saiu para entrega", com data prevista) → `delivered` (confirmação do motorista/operador).

### J08 — Entrega por frete de tabela (fora da área de entrega própria)

1. [L] CEP de Joinville → opções por tabela de peso (ex.: "Transporte — 3 a 5 dias úteis — R$ 60,00").
2. [A] `processing` → `shipped` (informa transportadora e código de rastreio opcional) → `delivered`.

### J09 — Cotação de frete na página do produto

1. [L] Cliente informa CEP e a quantidade/dimensões atuais do produto.
2. [S] Monta `ShippingRequest` só com esse item (peso e embalagem calculados pela quantidade) e retorna opções com preço e prazo.
3. [L] Mostra "Valor estimado para este produto; o frete final é calculado no carrinho". Cotação **não** é reservada nem vinculada ao pedido.

### J10 — Cotação de frete no carrinho

1. [L] Cliente informa CEP (ou usa endereço padrão se logado).
2. [S] Cotação para o carrinho inteiro, persistida em `shipping_quotes` (TTL 30 min, hash carrinho+CEP) (ADR-011).
3. [L] Qualquer alteração no carrinho invalida a cotação e pede recálculo.

### J11 — Cancelamento

| Situação | Quem | Como | Resultado |
|---|---|---|---|
| `pending_payment` | Cliente | Minha conta → Cancelar pedido | `cancelled` + `release` da reserva; PIX invalidado |
| `pending_payment` sem pagamento em 30 min | Sistema | Job de expiração | `cancelled` (motivo `payment_expired`), `payment_status = expired`, `release` |
| `paid` / `processing` | Cliente | "Solicitar cancelamento" (envia solicitação à loja) | Nenhuma mudança automática; admin avalia |
| `paid` / `processing` | Gerente/Financeiro | Painel → Cancelar com motivo | Estorno PIX → `payment_status = refunded`; `return` no estoque; `cancelled` |
| `shipped` em diante | — | Não cancelável no MVP | Tratado como devolução fora do sistema (ver Q-07) |

### J12 — Checkout completo (cliente)

Carrinho → Identificação → Endereço → Frete → Pagamento → Revisão → Pedido (detalhe em RN-CHK).

### J13 — Operação do admin: separar, enviar e entregar

1. [A] Expedição abre "Pedidos a separar" (filtro `status = paid`, ordenado pelo mais antigo `paid_at`).
2. [A] Imprime/visualiza lista de separação (itens com SKU, quantidade, **dimensões de corte**, peças).
3. [A] Clica "Iniciar separação" → `processing`.
4. [A] Conforme método: "Marcar como enviado/saiu para entrega" → `shipped` **ou** "Pronto para retirada" → `ready_for_pickup`.
5. [A] "Confirmar entrega" → `delivered` **ou** "Confirmar retirada" → `picked_up`.
6. [S] Cada transição grava `order_status_history` (ator, data, observação) e notifica o cliente.

### J14 — Operação do admin: entrada e ajuste de estoque

1. [A] Estoquista → Estoque → variante "VIN-BR-122" → **Entrada** (`in`): +100 m, motivo "NF fornecedor 4521".
2. [A] **Ajuste** (`adjust`) após inventário: define `on_hand = 87,5 m`, motivo obrigatório "Inventário mensal — perda por emenda".
3. [S] Rejeita ajuste se novo `on_hand < reserved` (RN-EST-021).
4. [S] Tudo registrado em `inventory_movements` (imutável) e `audit_logs`.

### J15 — Cadastro e login

1. [L] Cadastro PF (nome, CPF, e-mail, telefone, senha, aceite de termos) ou PJ (razão social, nome fantasia, CNPJ, IE/ISENTO, nome do responsável, e-mail, telefone, senha, aceite).
2. [S] Carrinho de visitante mesclado ao do cliente após login (RN-CAR-020).

---

## 4. Regras de negócio por domínio

### 4.1 Catálogo (`RN-CAT`)

| ID | Regra |
|---|---|
| RN-CAT-001 | Todo **produto** possui: nome (3–150 caracteres), `slug`, descrição (rich text sanitizado), categoria principal, marca (opcional), `sale_unit`, imagens (0–10), atributos técnicos e status ativo/inativo. |
| RN-CAT-002 | Todo produto tem **≥ 1 variante** (ADR-004). Produto simples = 1 variante "padrão". Carrinho, preço, estoque e pedido referenciam a **variante**. |
| RN-CAT-003 | Variante possui: `sku` (único global, 3–40 caracteres, `[A-Z0-9-]`, armazenado em maiúsculas), nome/rótulo da variação (ex.: "Branco Brilho 1,22 m"), atributos (cor, acabamento, largura, gramatura, espessura), preço base, regras de quantidade/dimensão, peso e embalagem, `low_stock_threshold`, status ativo/inativo. |
| RN-CAT-004 | `sale_unit` é definida no **produto** e vale para todas as variantes (ADR-004). Não pode ser alterada se já existir pedido com o produto (evita inconsistência histórica); para mudar, cria-se novo produto. |
| RN-CAT-005 | **Slug**: minúsculas, sem acento, `[a-z0-9-]`, 3–120 caracteres, gerado a partir do nome e editável. Produto: único globalmente. Categoria: único globalmente. Não pode coincidir com slugs reservados (`busca`, `carrinho`, `checkout`, `conta`, `entrar`, `cadastro`, `recuperar-senha`, `redefinir-senha`, `institucional`, `admin`, `api`, `sanctum`, `sitemap.xml`, `robots.txt`) (ADR-015, ver ADR-026a). |
| RN-CAT-006 | URL do produto: `/{category-slug}/{product-slug}` usando a **categoria principal**. Produto pode estar em categorias adicionais (listagem), mas a URL canônica é sempre a da principal. |
| RN-CAT-007 | **Categorias** em árvore com até **3 níveis** (ex.: Mídias → Vinis → Vinil Adesivo). Possuem nome, slug, descrição, imagem, ordem de exibição, meta title/description e ativo/inativo. |
| RN-CAT-008 | Não é permitido desativar/excluir categoria que seja **categoria principal** de produto ativo, nem que possua subcategorias ativas. O sistema lista os bloqueios. |
| RN-CAT-009 | **Marcas**: nome único, slug, logo opcional, ativo/inativo. Marca inativa não aparece em filtros; produtos continuam vendáveis. |
| RN-CAT-010 | **Produto inativo**: some de listagens, busca, sitemap e recomendações; página do produto retorna 404 (com sugestão de produtos da mesma categoria); não pode ser adicionado ao carrinho; itens já em carrinhos ficam marcados "indisponível" (RN-CAR-030). Pedidos existentes não são afetados (snapshot). |
| RN-CAT-011 | **Variante inativa**: não é exibida no seletor; se todas as variantes estiverem inativas, o produto é tratado como indisponível (não aparece na loja). |
| RN-CAT-012 | Produto só pode ser **ativado** se tiver: ≥ 1 variante ativa com preço base > 0, categoria principal ativa, peso > 0 por unidade de venda e regras de quantidade consistentes (RN-QTD-003). |
| RN-CAT-013 | Exclusão é **soft delete** (ADR-016). Produto/variante já vendidos nunca são excluídos fisicamente. SKU de variante excluída não pode ser reutilizado. |
| RN-CAT-014 | Imagens: JPG/PNG/WebP, até 5 MB cada, mínimo 800×800 px recomendado; a primeira é a principal; texto alternativo (alt) obrigatório para SEO/acessibilidade (padrão = nome do produto). |
| RN-CAT-015 | Ficha técnica exibida na loja: largura útil, gramatura, espessura, acabamento, adesivo (permanente/removível), durabilidade externa, compatibilidade de tinta (solvente, eco-solvente, UV, látex, sublimação). Campos livres chave/valor por produto. |
| RN-CAT-016 | Exibição de preço na listagem: "R$ 15,90 /m" (preço resolvido para a quantidade mínima e o cliente atual — RN-PRC-001); se houver faixas, exibir "a partir de R$ 13,50 /m" com o menor preço de faixa. |
| RN-CAT-017 | Selo de disponibilidade na loja: **Em estoque** (disponível > `low_stock_threshold`), **Últimas unidades** (0 < disponível ≤ threshold, mostra "Restam 3,5 m"), **Indisponível** (disponível < `min_quantity`). Não exibir número exato de estoque fora do caso "Últimas unidades". |

### 4.2 Unidades de venda e cálculo de quantidade (`RN-QTD`)

#### 4.2.1 Tabela de unidades (ADR-004)

| `sale_unit` | Entrada do cliente | Quantidade faturada | Unidade de estoque | Rótulo | Exemplo |
|---|---|---|---|---|---|
| `UNIT` | inteiro | quantidade | un | "/un" | Ilhós, espátula, estilete |
| `LINEAR_METER` | metros decimais (step) | metros | m | "/m" | Vinil 1,22 m, fita de LED por metro |
| `SQUARE_METER` | largura × altura × peças | área m² | m² | "/m²" | Lona front, tecido, lona backlight |
| `ROLL` | inteiro | rolos | rolo | "/rolo" | Bobina de papel, fita dupla face rolo |
| `KG` | kg decimais (step) | kg | kg | "/kg" | Pó hot melt DTF |
| `BOX` | inteiro | caixas | cx | "/cx" | Caixa de lâminas, caixa de rebites |

#### 4.2.2 Regras gerais

| ID | Regra |
|---|---|
| RN-QTD-001 | Quantidades e dimensões **nunca** usam float. Quantidade em milésimos inteiros (`5,5 m → 5500`), dimensões em mm inteiros, dinheiro em centavos (ADR-003). Entrada do usuário é convertida via string. |
| RN-QTD-002 | Entrada aceita vírgula ou ponto como separador decimal na loja (`5,5` e `5.5`); a API trafega número decimal (ADR-013). Máximo de **3 casas decimais** em quantidade; mais que isso → 422. |
| RN-QTD-003 | Regras por variante: `min_quantity` (> 0), `max_quantity` (opcional, ≥ min), `quantity_step` (> 0). Consistência exigida no cadastro: `min_quantity` deve ser múltiplo de `quantity_step`; `max_quantity`, se definido, também. |
| RN-QTD-004 | `UNIT`, `ROLL`, `BOX`: quantidade **inteira** ≥ 1; `quantity_step` inteiro ≥ 1 (padrão 1). Um step > 1 representa múltiplo de embalagem (ex.: ilhós só de 50 em 50 → 100 ✔, 120 ✘). |
| RN-QTD-005 | `LINEAR_METER`, `KG`: quantidade decimal; `quantity_step` padrão 0,10 m / 0,5 kg (configurável). |
| RN-QTD-006 | Limite de sanidade global por linha (independente de `max_quantity`): quantidade ≤ 100.000 na unidade de venda; largura/altura ≤ 100 m; peças ≤ 1.000. Acima disso → 422 (proteção contra valores absurdos). |
| RN-QTD-007 | Zero, negativo, vazio, `NaN`, notação científica (`1e3`) → **422**. |

#### 4.2.3 Validação de passo (step)

| ID | Regra |
|---|---|
| RN-QTD-010 | A quantidade é válida se `quantity_milli % step_milli == 0` (múltiplo do passo contado a partir de zero), `quantity ≥ min_quantity` e `quantity ≤ max_quantity` (se houver). Inválida → 422 com mensagem e **sugestão** dos dois valores válidos mais próximos (arredondado para baixo e para cima, respeitando min/max). |
| RN-QTD-011 | O sistema **nunca arredonda silenciosamente** a quantidade do cliente; sempre rejeita e sugere. |

**Exemplos de step:**

| Produto | min | step | max | Entrada | Cálculo | Resultado |
|---|---|---|---|---|---|---|
| Vinil 1,22 m | 1,00 | 0,10 | 50,00 | 5,00 | 5000 % 100 = 0 | ✔ válido |
| Vinil 1,22 m | 1,00 | 0,10 | 50,00 | **5,05** | 5050 % 100 = **50** | ✘ 422 — "Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m" |
| Vinil 1,22 m | 1,00 | 0,10 | 50,00 | 0,50 | < min | ✘ 422 — "Quantidade mínima: 1,00 m" |
| Vinil 1,22 m | 1,00 | 0,10 | 50,00 | 60,00 | > max | ✘ 422 — "Quantidade máxima: 50,00 m" |
| Ilhós (pacote 50) | 50 | 50 | — | 120 | 120 % 50 = 20 | ✘ 422 — "Sugestões: 100 ou 150" |
| Ilhós unitário | 1 | 1 | — | 2,5 | não inteiro | ✘ 422 — "Quantidade deve ser inteira" |
| Pó DTF | 0,5 | 0,5 | 25 | 1,5 | 1500 % 500 = 0 | ✔ |

#### 4.2.4 Metro quadrado (SQUARE_METER)

| ID | Regra |
|---|---|
| RN-QTD-020 | Total da linha = `round_half_up(unit_price_cents × quantity_milli / 1000)` (ADR-003), para **todas** as unidades. |
| RN-QTD-030 | Área **por peça** (milésimos de m²) = `round_half_up(width_mm × height_mm / 1000)`; área da linha = área por peça × `pieces` (ver ADR-019, que resolve Q-01). Ex.: 1200 × 2500 / 1000 = 3000 → 3,000 m² por peça. |
| RN-QTD-031 | A área mínima aplica-se **por peça** (ver ADR-019, que resolve Q-02): `área faturada = max(área da peça, min_billable_area) × pieces`. A tela exibe as duas áreas: "Área calculada 0,200 m² · Área faturada 0,500 m² (mínimo)". O pedido guarda ambas (snapshot). |
| RN-QTD-032 | **Largura fixa** (`fixed_width_mm` definido): cliente informa somente altura (e peças); largura = `fixed_width_mm`. Valida `min_height_mm`/`max_height_mm`. |
| RN-QTD-033 | **Faixa de dimensões**: cliente informa largura e altura, cada uma validada contra `min_*_mm`/`max_*_mm`. `fixed_width_mm` e faixa de largura são mutuamente exclusivos no cadastro. |
| RN-QTD-034 | Dimensões aceitas com até **2 casas decimais em metros** (precisão de 1 cm = múltiplo de 10 mm) no MVP. Ex.: 1,205 m → 422. (Ver Q-04.) |
| RN-QTD-035 | Peças: inteiro 1–1.000 (padrão 1). |
| RN-QTD-036 | Para `SQUARE_METER`, `quantity_step`/`min_quantity`/`max_quantity` referem-se a **peças** (ver ADR-019); `cart_items.quantity` é NULL e `width_mm`/`height_mm`/`pieces` são obrigatórios. |
| RN-QTD-037 | O estoque de `SQUARE_METER` é controlado em m² (ADR-004) e baixado pela **área real** (`order_items.stock_quantity` = área da peça × peças, **sem** área mínima), separada da quantidade faturada (`billable_quantity`) (ver ADR-019). |
| RN-QTD-038 | Largura e altura são exibidas e registradas como "L × A" (largura primeiro). Não há rotação automática: se o cliente informar largura maior que a largura máxima, recebe erro sugerindo inverter as medidas. |

#### 4.2.5 Exemplos trabalhados (valores oficiais para testes)

| # | Produto | Unidade | Entrada | Quantidade (milésimos) | Preço unit. (cents) | Cálculo | Total |
|---|---|---|---|---|---|---|---|
| X1 | Vinil Adesivo Branco 1,22 m | LINEAR_METER | 5 m | 5000 | 1590 | 1590 × 5000 / 1000 = 7950 | **R$ 79,50** |
| X2 | Vinil Adesivo Branco 1,22 m | LINEAR_METER | 5,35 m | 5350 | 1590 | 1590 × 5350 / 1000 = 8506,5 → round_half_up | **R$ 85,07** |
| X3 | Lona Front sob medida | SQUARE_METER | 1,20 × 2,50 × 1 | 1200×2500×1/1000 = 3000 | 3000 | 3000 × 3000 / 1000 = 9000 | **R$ 90,00** |
| X4 | Lona Front sob medida | SQUARE_METER | 1,20 × 2,50 × 3 | 9000 | 3000 | 3000 × 9000 / 1000 = 27000 | **R$ 270,00** |
| X5 | Lona Front sob medida (mín. 0,50 m²) | SQUARE_METER | 0,40 × 0,50 × 1 | calc. 200 → faturada **500** | 3000 | 3000 × 500 / 1000 = 1500 | **R$ 15,00** |
| X6 | Lona Backlight 3,20 m (largura fixa) | SQUARE_METER | altura 2,00 | 3200×2000/1000 = 6400 | 3200 | 3200 × 6400 / 1000 = 20480 | **R$ 204,80** |
| X7 | Ilhós nº 0 latão | UNIT | 100 | 100000 | 50 | 50 × 100000 / 1000 = 5000 | **R$ 50,00** |
| X8 | Bobina papel 90 g 0,914 × 50 m | ROLL | 2 | 2000 | 35000 | 35000 × 2000 / 1000 = 70000 | **R$ 700,00** |
| X9 | Lâmina estilete cx c/10 | BOX | 3 | 3000 | 2490 | 7470 | **R$ 74,70** |
| X10 | Pó hot melt DTF | KG | 1,5 kg | 1500 | 8990 | 8990 × 1500 / 1000 = 13485 | **R$ 134,85** |
| X11 | Vinil 1,22 m | LINEAR_METER | 5,05 m (step 0,10) | — | — | 5050 % 100 ≠ 0 | **422 inválido** |

> `round_half_up` sobre valor positivo: ,5 arredonda para cima (8506,5 → 8507).
> Subtotal do pedido = soma dos totais de linha (cada linha já arredondada); nunca
> arredondar a soma de valores não arredondados.

### 4.3 Peso e dimensões logísticas (`RN-LOG`)

| ID | Regra |
|---|---|
| RN-LOG-001 | Cada variante tem `weight_g` = peso em **gramas por unidade de venda** (por un, por m, por m², por rolo, por kg, por caixa). Deve ser > 0 para ativar o produto. Ex.: vinil 1,22 m ≈ 180 g/m; lona 440 g/m² = 440 g/m²; bobina papel 0,914×50 m 90 g/m² ≈ 4.200 g/rolo (inclui tubete); KG = 1.000 g/kg + embalagem. |
| RN-LOG-002 | Peso da linha (g) = `ceil(weight_g × quantity_milli / 1000)` usando a **quantidade faturada** (para m², a área faturada). Arredonda para cima ao grama. |
| RN-LOG-003 | Peso do carrinho = soma dos pesos das linhas + peso de embalagem configurável por pedido (`packaging_weight_g`, padrão 0). |
| RN-LOG-004 | Dimensões de embalagem (cm, `numeric(8,1)`) por variante: `package_length_cm`, `package_width_cm`, `package_height_cm`. Para `UNIT`/`ROLL`/`BOX`: embalagem **por unidade**. Para `LINEAR_METER`/`SQUARE_METER`: embalagem do **volume enrolado** (tubo) — o comprimento do tubo corresponde à largura do material (ex.: vinil 1,22 m → tubo 130 cm) e o diâmetro é o cadastrado (MVP: um volume por linha; cálculo fino de diâmetro por metragem fica fora do MVP). |
| RN-LOG-005 | Volume (cm³) da linha: `UNIT/ROLL/BOX` = C × L × A × quantidade; `LINEAR_METER/SQUARE_METER/KG` = C × L × A (um volume). A maior dimensão do carrinho (`max_length_cm`) é enviada ao motor de frete para regras de restrição. |
| RN-LOG-006 | Peso cubado (quando o método usar) = `C × L × A / fator` (fator por método/transportadora, padrão 6000). Peso taxável = `max(peso real, peso cubado)`. Detalhes e fatores: `SHIPPING.md`. |
| RN-LOG-007 | Peso e dimensões usados na cotação são copiados no snapshot do `order_item` (ADR-016). |

**Exemplo de peso do carrinho:**

| Item | weight_g/unidade | Quantidade faturada | Peso da linha |
|---|---|---|---|
| Vinil 1,22 m | 180 g/m | 5,000 m | 900 g |
| Lona front 440 g | 440 g/m² | 3,000 m² | 1.320 g |
| Ilhós nº 0 | 1 g/un | 100 | 100 g |
| Bobina papel | 4.200 g/rolo | 2 | 8.400 g |
| **Total** | | | **10.720 g (10,72 kg)** |

### 4.4 Estoque (`RN-EST`)

| ID | Regra |
|---|---|
| RN-EST-001 | Estoque é por **variante** na unidade de estoque da `sale_unit` (m, m², un, rolo, kg, cx), em `numeric(12,3)`. Registro `inventory`: `on_hand`, `reserved`; **disponível = on_hand − reserved** (ADR-008). |
| RN-EST-002 | Restrições invioláveis: `on_hand ≥ 0`, `reserved ≥ 0`, `reserved ≤ on_hand`. |
| RN-EST-003 | **Sem venda a descoberto** (backorder) no MVP: não se cria pedido com quantidade > disponível. |
| RN-EST-004 | Estoque **não é reservado no carrinho**, apenas na criação do pedido (checkout). O carrinho apenas informa disponibilidade atual. |
| RN-EST-005 | Toda alteração de estoque ocorre em transação com `SELECT ... FOR UPDATE` nas linhas de `inventory` **ordenadas por `variant_id`** e gera um `inventory_movement` imutável (tipo, quantidade, saldo antes/depois, ator, motivo, referência ao pedido quando houver). |
| RN-EST-006 | Se o mesmo pedido tiver várias linhas da mesma variante (ex.: dois cortes de lona), a reserva é feita pela **soma** das quantidades da variante. |

#### 4.4.1 Tipos de movimento (ADR-008)

| Tipo | Gatilho | Efeito | Ator | Motivo obrigatório |
|---|---|---|---|---|
| `reserve` | Pedido criado (`pending_payment`) | `reserved += q` | sistema | não (referência ao pedido) |
| `out` | Pagamento aprovado (commit) | `on_hand −= q`; `reserved −= q` | sistema | não |
| `release` | Pedido não pago cancelado/expirado | `reserved −= q` | sistema/cliente/admin | não |
| `return` | Pedido pago cancelado antes do envio | `on_hand += q` | admin | sim (motivo do cancelamento) |
| `in` | Entrada de mercadoria | `on_hand += q` | estoquista | sim (ex.: nº NF fornecedor) |
| `adjust` | Ajuste manual (inventário, perda, avaria) | `on_hand = novo valor` | estoquista/gerente | sim |

#### 4.4.2 Exemplo passo a passo (variante VIN-BR-122, vinil em metros)

| Passo | Evento | Movimento | on_hand | reserved | disponível |
|---|---|---|---|---|---|
| 0 | Saldo inicial | — | 100,000 | 0,000 | 100,000 |
| 1 | Pedido CV-000001 criado, 5 m | `reserve` 5 | 100,000 | 5,000 | 95,000 |
| 2 | Pedido CV-000002 criado, 10 m | `reserve` 10 | 100,000 | 15,000 | 85,000 |
| 3 | PIX do CV-000001 aprovado | `out` 5 | 95,000 | 10,000 | 85,000 |
| 4 | CV-000002 expira (30 min) | `release` 10 | 95,000 | 0,000 | 95,000 |
| 5 | Entrada NF 4521 | `in` 50 | 145,000 | 0,000 | 145,000 |
| 6 | CV-000003 criado e pago, 20 m | `reserve` 20 → `out` 20 | 125,000 | 0,000 | 125,000 |
| 7 | CV-000003 cancelado pelo gerente antes do envio (estorno) | `return` 20 | 145,000 | 0,000 | 145,000 |
| 8 | Inventário: contado 143,5 m | `adjust` → 143,5 (Δ −1,5, "perda por emenda") | 143,500 | 0,000 | 143,500 |

#### 4.4.3 Estoque baixo e demais regras

| ID | Regra |
|---|---|
| RN-EST-010 | Cada variante tem `low_stock_threshold` (na unidade de estoque; padrão configurável por loja, ex.: 10). Quando **disponível ≤ threshold** após qualquer movimento, gera alerta (lista "Estoque baixo" no painel + e-mail diário consolidado para `warehouse`). Um alerta por variante até o estoque voltar acima do limite. |
| RN-EST-011 | Variante com disponível < `min_quantity` é exibida como **Indisponível** e não pode ser adicionada ao carrinho. |
| RN-EST-020 | Entrada (`in`) exige quantidade > 0 e motivo (texto 3–255). |
| RN-EST-021 | Ajuste (`adjust`) exige motivo e novo valor ≥ `reserved` atual; caso contrário 422 "Existem X reservados em pedidos pendentes". |
| RN-EST-022 | Movimentos são imutáveis: correção de um lançamento errado é feita por **novo** movimento (`adjust`/`in`), nunca editando/excluindo o anterior. |
| RN-EST-023 | Retalhos/sobras de corte não são rastreados no MVP; perdas são registradas via `adjust` com motivo "sobra de corte/perda". |
| RN-EST-024 | Histórico de movimentos por variante filtrável por período, tipo, ator e pedido; exportável em CSV. |

### 4.5 Preços (`RN-PRC`)

| ID | Regra |
|---|---|
| RN-PRC-001 | O preço unitário é calculado **sempre no backend** pelo `PriceResolver` (ADR-005), para (variante, quantidade, cliente, data/hora). Valores de preço vindos do frontend são ignorados (ADR-012). |
| RN-PRC-002 | Candidatos aplicáveis: (1) preço base da variante com **faixas por quantidade** (`price_tiers`); (2) **tabela de preço** do cliente/empresa, também com faixas; (3) **preço promocional** da variante vigente ou **promoção** ativa (percentual/valor fixo por produto/categoria/marca); (4) **preço específico do cliente/empresa** (`customer_prices`). |
| RN-PRC-003 | **Vence o menor** preço entre os candidatos aplicáveis. Empate: vence pela ordem de prioridade de exibição `customer_price` > `price_list` > `promotion`/`variant_promo` > `tier` > `base` (apenas para definir o rótulo `price_source`). |
| RN-PRC-004 | A resposta informa `price_source` (`base`, `tier`, `price_list`, `variant_promo`, `promotion`, `customer_price` — valores de DATABASE, ver ADR-028) e o preço "de" (preço base da faixa 1) quando o resultado for menor, para exibir "de R$ 15,90 por R$ 13,90 — Preço atacado". |
| RN-PRC-005 | **Faixas por quantidade**: definidas por quantidade mínima (inclusive), na unidade de venda. Padrão sugerido: **1–10**, **11–50**, **51+**. A faixa é escolhida pela quantidade faturada **somada da mesma variante no carrinho** (ver Q-03). Faixas devem ter preços **não crescentes** conforme aumenta a quantidade (validação no cadastro). |
| RN-PRC-006 | **Tabela de preço**: tipos `retail` (varejo), `wholesale` (atacado), `reseller` (revendedor), `custom` (específica; enum de API.md §2.1). Cada cliente tem **no máximo uma** tabela; cliente PJ herda a da empresa. Visitante não logado vê apenas base/faixas/promoções. Itens não presentes na tabela usam os demais candidatos. |
| RN-PRC-007 | **Promoção**: tem vigência (`starts_at`/`ends_at`, fuso America/Sao_Paulo), alvo (produto, variante, categoria incluindo subcategorias, marca) e tipo (percentual em basis points, ex.: 1000 = 10%, ou valor fixo em centavos por unidade de venda). Preço promocional = `round_half_up`; nunca < 1 centavo. Várias promoções aplicáveis → cada uma é um candidato; vence o menor. |
| RN-PRC-008 | **Preço do cliente** (`customer_prices`): valor fixo por variante para um cliente ou empresa, com vigência opcional. Faixas por preço de cliente ficam **fora do MVP** (schema não prevê — API.md D-17). |
| RN-PRC-009 | Preços não se **acumulam**: promoção não é aplicada sobre tabela de preço; cada candidato é calculado independentemente sobre o preço de referência e o menor vence. |
| RN-PRC-010 | **Cupons** são aplicados **depois**, sobre o subtotal (nível pedido) — ver RN-CUP (ADR-005). |
| RN-PRC-011 | Preço base > 0 obrigatório. Preço resolvido mínimo = R$ 0,01. |
| RN-PRC-012 | O preço exibido é sempre **por unidade de venda** com o rótulo ("/m", "/m²"...). O total da linha segue RN-QTD-020. |
| RN-PRC-013 | Alteração de preço no painel vale imediatamente para carrinhos (recalculados a cada leitura — ADR-007) e **não afeta** pedidos criados (snapshot). Toda alteração de preço é auditada (valor anterior e novo). |
| RN-PRC-014 | Preços exibidos são finais ao consumidor (impostos inclusos). Destaque de tributos (Lei 12.741/2012 — "valor aproximado dos tributos") fica para a fase NF-e. |

#### 4.5.1 Exemplo de resolução — Vinil Adesivo Branco 1,22 m (VIN-BR-122)

> **Ilustrativo** (ver ADR-028): os números de referência para testes e aceite são os do seed
> (DATABASE.md §7 — SKU `VIN-BR-122-BR`, R$ 15,90/m, faixas ≥ 10 m R$ 14,90 e ≥ 50 m R$ 13,90).

Configuração:

| Candidato | 1–10 m | 11–50 m | 51+ m | Observação |
|---|---|---|---|---|
| Base + faixas | R$ 15,90 | R$ 14,90 | R$ 13,50 | todos |
| Tabela "Atacado" | R$ 14,50 | R$ 14,50 | R$ 13,90 | clientes com tabela atacado |
| Promoção "Semana do Vinil" (−10%) sobre base | R$ 14,31 | R$ 13,41 | R$ 12,15 | vigente 01/10–07/10 |
| Preço do cliente "Gráfica Marcos" | R$ 13,90 | R$ 13,90 | R$ 13,90 | só esse cliente |

Cenários:

| # | Cliente | Data | Quantidade | Candidatos | Vence | `price_source` | Total |
|---|---|---|---|---|---|---|---|
| E1 | Visitante | 20/09 | 5 m | base 15,90 | R$ 15,90 | `base` | R$ 79,50 |
| E2 | Visitante | 20/09 | 20 m | faixa 14,90 | R$ 14,90 | `tier` | R$ 298,00 |
| E3 | Cliente atacado | 20/09 | 5 m | base 15,90; atacado 14,50 | R$ 14,50 | `price_list` | R$ 72,50 |
| E4 | Cliente atacado | 20/09 | 60 m | faixa 13,50; atacado 13,90 | R$ 13,50 | `tier` | R$ 810,00 |
| E5 | Cliente atacado | 03/10 | 5 m | 15,90; 14,50; promo 14,31 | R$ 14,31 | `promotion` | R$ 71,55 |
| E6 | Gráfica Marcos (atacado + preço próprio) | 03/10 | 5 m | 15,90; 14,50; 14,31; 13,90 | R$ 13,90 | `customer_price` | R$ 69,50 |
| E7 | Gráfica Marcos | 03/10 | 60 m | 13,50; 13,90; promo 12,15; 13,90 | R$ 12,15 | `promotion` | R$ 729,00 |

> E5: 10% de 1590 = 159 → 1431. E7: 10% de 1350 = 135 → 1215; 1215 × 60000 / 1000 = 72900.

### 4.6 Promoções e cupons (`RN-CUP`)

Promoções (preço) estão em RN-PRC-007. Esta seção trata de **cupons** (nível pedido).

| ID | Regra |
|---|---|
| RN-CUP-001 | Tipos: `percent` (percentual em basis points, 1–10000, com teto opcional `max_discount_cents`), `fixed` (valor em centavos), `free_shipping` (zera o frete). (Enum de DATABASE/API.md — ver ADR-028.) |
| RN-CUP-002 | Código: 3–30 caracteres `[A-Z0-9_-]`, único, **case-insensitive** (armazenado em maiúsculas, espaços nas bordas removidos). |
| RN-CUP-003 | Vigência `starts_at`/`ends_at` (fuso America/Sao_Paulo); cupom fora da vigência ou inativo → "Cupom inválido ou expirado". |
| RN-CUP-004 | **Valor mínimo do pedido** (`min_subtotal_cents`): comparado ao **subtotal de produtos** (após resolução de preço, antes do cupom, sem frete). |
| RN-CUP-005 | Limites de uso: total (`usage_limit`) e por cliente (`usage_limit_per_customer`). Uso é contado na **criação do pedido** e **devolvido** se o pedido for cancelado sem pagamento (expirado/cancelado em `pending_payment`). Pedido pago e depois cancelado **não** devolve o uso. Verificação e incremento com lock na linha do cupom (concorrência). |
| RN-CUP-006 | **Não cumulativos**: no máximo **1 cupom por pedido**. Aplicar outro substitui o anterior. |
| RN-CUP-007 | Cupom de desconto (`percent`/`fixed`) incide **somente sobre o subtotal de produtos**, nunca sobre o frete. |
| RN-CUP-008 | Cupom `free_shipping` zera o valor do frete escolhido; vale para métodos com `accepts_free_shipping_coupon = true` (padrão: `own_delivery`/`table_rate`); teto de frete coberto (`max_shipping_discount_cents`) fica **fora do MVP** (API.md D-17). Se o frete escolhido não for elegível, mensagem "Cupom de frete grátis não se aplica ao método selecionado". Retirada (R$ 0) → cupom aplicável porém sem efeito (aviso). |
| RN-CUP-009 | O desconto **nunca** leva o subtotal abaixo de zero: `discount = min(calculado, subtotal)`. Percentual: `round_half_up(subtotal × bp / 10000)`, limitado ao teto. |
| RN-CUP-010 | O cupom é aplicado **sobre o preço já resolvido** (inclusive promoção/tabela) — ele se soma a promoções (preço) mas não a outros cupons. Flag opcional `exclude_promotional_items` fica fora do MVP. |
| RN-CUP-011 | Rateio do desconto entre itens (para estorno parcial/NF-e futura): proporcional ao total de cada linha, com arredondamento; a diferença de centavos vai para a linha de maior valor. Guardado em `order_items.discount_cents`. |
| RN-CUP-012 | Cupom é **revalidado** no checkout (vigência, limites, mínimo). Ao **aplicar** no carrinho, cupom inválido → 422; se deixou de valer no **checkout** → **409 `coupon_invalid`** com o resumo sem desconto, e o cliente reconfirma (ver ADR-028). |
| RN-CUP-013 | Cupom restrito a "primeira compra" (`first_order_only`) — **fora do MVP** (schema não prevê — API.md D-17); no seed, `BEMVINDO10` usa `usage_limit_per_customer = 1`. |
| RN-CUP-014 | Total do pedido: `total = subtotal − discount + shipping` (shipping após cupom de frete). Nunca negativo. |

**Exemplos de cupom** (subtotal de produtos R$ 200,00, frete R$ 25,00):

| Cupom | Configuração | Desconto | Frete | Total |
|---|---|---|---|---|
| `BEMVINDO10` | 10% (1000 bp) | R$ 20,00 | R$ 25,00 | **R$ 205,00** |
| `DESC30` | fixo R$ 30,00, mínimo R$ 150 | R$ 30,00 | R$ 25,00 | **R$ 195,00** |
| `DESC30` com subtotal R$ 120,00 | mínimo R$ 150 | ✘ não aplicado ("Pedido mínimo R$ 150,00") | R$ 25,00 | R$ 145,00 |
| `VALE500` | fixo R$ 500,00 | R$ 200,00 (limitado ao subtotal) | R$ 25,00 | **R$ 25,00** |
| `FRETEGRATIS` | frete grátis, teto R$ 40 | R$ 0,00 | R$ 0,00 | **R$ 200,00** |
| `MEGA15` | 15%, teto R$ 20 | R$ 20,00 (15% = 30 → teto) | R$ 25,00 | **R$ 205,00** |

### 4.7 Clientes (`RN-CLI`)

#### 4.7.1 Cadastro

| Campo | PF (`individual`) | PJ (`company`) | Regra |
|---|---|---|---|
| Nome completo | ✔ obrigatório | ✔ (nome do responsável/comprador) | 3–120 caracteres, ao menos 2 palavras para PF |
| CPF | ✔ obrigatório | opcional (do responsável) | 11 dígitos, dígitos verificadores válidos |
| Razão social | — | ✔ | 3–150 |
| Nome fantasia | — | opcional | até 150 |
| CNPJ | — | ✔ | 14 dígitos, dígitos verificadores válidos |
| IE | — | ✔ número **ou** "ISENTO" | ver RN-CLI-006 |
| E-mail | ✔ | ✔ | único entre clientes, normalizado em minúsculas |
| Telefone/celular | ✔ | ✔ | DDD + número (10–11 dígitos) |
| Senha | ✔ | ✔ | ≥ 8 caracteres, não pode estar em lista de senhas vazadas comuns |
| Aceite termos + privacidade | ✔ | ✔ | obrigatório, com versão e data (RN-LGPD-002) |
| Opt-in marketing | opcional | opcional | padrão desmarcado |

| ID | Regra |
|---|---|
| RN-CLI-001 | Tipos: `individual` (PF) e `company` (PJ). PJ cria `customers` + `companies` (`customers.company_id`) (ADR-006). |
| RN-CLI-002 | **CPF** válido: 11 dígitos, dígitos verificadores corretos (módulo 11), rejeitar sequências repetidas (000.000.000-00 … 999.999.999-99). Armazenado só com dígitos; exibido mascarado `***.456.789-**` fora da área do próprio cliente. |
| RN-CLI-003 | **CNPJ** válido: 14 dígitos, dígitos verificadores corretos, rejeitar sequências repetidas. (CNPJ alfanumérico — vigente a partir de 07/2026 — deve ser aceito: ver Q-13.) |
| RN-CLI-004 | Unicidade: **e-mail** único entre clientes; **CPF** único entre clientes PF; **CNPJ** único entre empresas. Mensagem genérica em caso de duplicidade na tela de cadastro para evitar enumeração ("Não foi possível concluir o cadastro. Se você já tem conta, faça login ou recupere a senha."). |
| RN-CLI-005 | CPF/CNPJ **não podem ser alterados pelo cliente** após cadastro (vínculo fiscal). Correção somente por gerente, se não houver pedido pago, com auditoria. |
| RN-CLI-006 | **IE**: aceita "ISENTO" (case-insensitive, armazenado `ISENTO`) ou 2–14 dígitos. Validação de dígito por UF fica fora do MVP. |
| RN-CLI-007 | Troca de tipo PF ↔ PJ não é self-service. Gerente pode converter se o cliente **não tiver pedidos**; caso contrário, cria-se nova conta (outro e-mail). |
| RN-CLI-008 | Login por e-mail + senha; bloqueio temporário após 5 tentativas/min por IP+e-mail (ADR-006). Recuperação de senha por e-mail com link de uso único válido por 60 min; mensagem sempre neutra ("Se o e-mail existir, enviaremos instruções"). |
| RN-CLI-009 | Confirmação de e-mail enviada no cadastro; **não bloqueia** checkout no MVP (apenas lembrete). |
| RN-CLI-010 | Cliente pode ser **bloqueado** pelo gerente (`is_active = false`): não loga; pedidos existentes seguem o fluxo normal. |

#### 4.7.2 Endereços

| ID | Regra |
|---|---|
| RN-CLI-020 | Endereço: apelido (ex.: "Loja", "Obra"), destinatário, CEP (8 dígitos), logradouro, número (ou "S/N"), complemento (opcional), bairro, cidade, UF, código IBGE, telefone de contato, referência (opcional). |
| RN-CLI-021 | Ao informar o CEP, o sistema preenche logradouro/bairro/cidade/UF/IBGE via `PostalCodeLookup` (ADR-011). Cidade/UF/IBGE **não são editáveis** manualmente (derivam do CEP); logradouro e bairro são editáveis (CEPs gerais de cidade não trazem rua). |
| RN-CLI-022 | CEP inexistente → "CEP não encontrado"; serviço indisponível → permitir tentar novamente (sem cadastrar endereço sem cidade/IBGE). |
| RN-CLI-023 | Máximo de 10 endereços por cliente. Exatamente **um endereço padrão** quando existir ao menos um; o primeiro cadastrado vira padrão; excluir o padrão promove o mais recente. |
| RN-CLI-024 | Editar/excluir endereço **não altera pedidos** (snapshot em `orders` — ADR-016). Endereço usado em pedido é soft-deleted. |

### 4.8 Carrinho (`RN-CAR`)

| ID | Regra |
|---|---|
| RN-CAR-001 | Visitante pode ter carrinho, identificado por `carts.token` (UUID) no header `X-Cart-Token` (ADR-007). Carrinho de visitante expira após **30 dias** sem atividade; carrinho de cliente não expira (limpeza de itens após 180 dias sem atividade). |
| RN-CAR-002 | `cart_items` guarda **somente a escolha**: variante, quantidade, largura, altura, peças (ADR-007). Nenhum preço é confiado do cliente. |
| RN-CAR-003 | Preços, subtotal, pesos e disponibilidade são **recalculados a cada leitura** do carrinho. |
| RN-CAR-004 | Identidade da linha: (variante + largura + altura). Adicionar item idêntico **soma** a quantidade (UNIT/LINEAR/KG/ROLL/BOX) ou as **peças** (SQUARE_METER com mesmas medidas); medidas diferentes geram linhas separadas. A soma é revalidada (max, step, estoque). |
| RN-CAR-005 | Máximo de **50 linhas** por carrinho. |
| RN-CAR-006 | Ao adicionar/alterar item: valida produto/variante ativos, regras de quantidade (RN-QTD) e disponível ≥ quantidade (considerando a soma da variante no carrinho). Falha → 422/409 com mensagem; o item não é alterado. |
| RN-CAR-010 | **Mudança de preço**: se o preço unitário atual difere do último preço visto pelo cliente, a linha exibe aviso "Preço alterado de R$ 15,90 para R$ 16,50" até o cliente visualizar/confirmar (ver Q-05). |
| RN-CAR-020 | **Mesclagem no login**: itens do carrinho de visitante são mesclados ao carrinho do cliente aplicando RN-CAR-004; se a soma exceder `max_quantity` ou disponível, limita ao máximo válido (múltiplo do step) e avisa. Após mesclar, o carrinho de visitante é descartado. Cupom do visitante é revalidado para o cliente. |
| RN-CAR-021 | Após login, o preço passa a considerar tabela de preço/preço do cliente (pode diminuir; nunca aumenta por causa do login, pois "menor vence"). |
| RN-CAR-030 | **Item indisponível** (produto/variante inativa, ou disponível < quantidade): linha permanece no carrinho marcada com status (`unavailable` / `insufficient_stock` com disponível atual) e **bloqueia o checkout** até o cliente remover ou ajustar. Não há remoção automática silenciosa. |
| RN-CAR-031 | Regra de quantidade alterada no cadastro (ex.: novo step) que invalide a linha → linha marcada `invalid_quantity` com sugestão; bloqueia checkout. |
| RN-CAR-040 | Carrinho exibe: linhas (nome, variante, medidas, área calculada/faturada, quantidade, preço unit. com `price_source`, total), subtotal, desconto do cupom, frete (se cotado), total, peso total estimado. |
| RN-CAR-041 | Cupom pode ser aplicado no carrinho (visitante ou logado); é revalidado a cada leitura e no checkout. |

### 4.9 Checkout (`RN-CHK`)

Etapas: **Carrinho → Identificação → Endereço → Frete → Pagamento → Revisão → Pedido**.

| Etapa | Regras |
|---|---|
| Carrinho | Sem linhas bloqueadas (RN-CAR-030/031); ≥ 1 item. |
| Identificação | **Login obrigatório** (ADR-007). Visitante é enviado a Entrar/Cadastrar e volta ao checkout com carrinho mesclado. Cadastro deve estar completo (PF: CPF; PJ: CNPJ, razão social, IE/ISENTO). |
| Endereço | Seleciona endereço existente ou cadastra novo. Para retirada, endereço de cobrança ainda é exigido (dados do comprador) mas não é usado para frete. |
| Frete | Lista opções cotadas para CEP + carrinho (`shipping_option_id`). Nenhuma opção → "Não entregamos neste CEP. Você pode retirar na loja em Blumenau" (se `pickup` ativo). |
| Pagamento | MVP: somente PIX. |
| Revisão | Exibe itens, medidas, preços, subtotal, cupom, frete, total, endereço, prazo estimado; aceite dos termos de venda. Botão "Finalizar pedido" desabilitado após o clique. |
| Pedido | `POST /api/v1/checkout` com `Idempotency-Key`; cria pedido `pending_payment`, reserva estoque, gera PIX; exibe QR + copia e cola + contador de 30 min. |

| ID | Regra |
|---|---|
| RN-CHK-001 | O backend **recalcula tudo** no `POST /checkout`: preços (PriceResolver), quantidades/áreas, pesos, cupom, frete (recalcula a opção pelo `shipping_option_id` e valida — ADR-011), totais. Campos de preço/total/desconto/status/customer_id enviados pelo cliente são **rejeitados com 422** sem efeito (ADR-012; API.md §1.7 — ver ADR-028). |
| RN-CHK-002 | Dentro de **uma transação**: valida carrinho → trava estoque (`FOR UPDATE`, ordenado por `variant_id`) → verifica disponível → `reserve` → trava e incrementa uso do cupom → cria `orders`/`order_items` com snapshot → cria `order_status_history` → confirma. Falha em qualquer passo → rollback total. |
| RN-CHK-003 | Estoque insuficiente no momento do checkout → **409** com a lista de itens e disponível atual; nenhum pedido criado. |
| RN-CHK-004 | Cotação de frete expirada (TTL 30 min) ou carrinho alterado após a cotação → **409** "Frete precisa ser recalculado"; cliente reescolhe. |
| RN-CHK-005 | Se o total recalculado for diferente do exibido na revisão (preço, cupom ou frete mudou), o pedido **não** é criado: 409 com os novos valores para nova confirmação (ver Q-05). |
| RN-CHK-006 | **Idempotência** (ADR-009): `Idempotency-Key` UUID obrigatório; mesma chave + mesmo cliente + mesmo corpo → retorna o **mesmo pedido** (200, `replayed: true`) sem criar outro; mesma chave com corpo diferente → 409 `idempotency_conflict` (ADR-021). Chave ausente/inválida → 422. |
| RN-CHK-007 | Geração do PIX acontece após o commit do pedido. Se o gateway falhar, o pedido permanece `pending_payment` e a tela oferece "Gerar PIX novamente" (idempotente por pedido) até a expiração. |
| RN-CHK-008 | Após criar o pedido, o carrinho é **esvaziado** (itens convertidos). Se o pedido for cancelado/expirar, o cliente pode usar "Comprar novamente". |
| RN-CHK-009 | Rate limit no checkout (ADR-006): **5/min e 30/h** por cliente (API.md §1.8 — ver ADR-028); máximo de 3 pedidos `pending_payment` simultâneos (409 `too_many_pending_orders`, ADR-021). |
| RN-CHK-010 | Valor mínimo de pedido configurável (`min_order_cents`, padrão R$ 0,00). |
| RN-CHK-011 | O pedido copia: dados do cliente (nome, CPF/CNPJ, razão social, IE, e-mail, telefone), endereço, método/prazo/preço de frete, e por item: nome, SKU, unidade, preço unit., `price_source`, quantidade, largura, altura, peças, área calculada e faturada, peso, desconto rateado, total (ADR-016). |

### 4.10 Pedidos (`RN-PED`)

| ID | Regra |
|---|---|
| RN-PED-001 | Número público `orders.number` = `CV-` + sequência com **6 dígitos zero-padded** (`CV-000123`), crescendo para 7+ dígitos após 999999. Gerado por sequence do banco; lacunas (por rollback) são aceitáveis. Rotas do cliente usam `uuid` (ADR-012). |
| RN-PED-002 | Status (`orders.status`): `pending_payment`, `paid`, `processing`, `shipped`, `delivered`, `ready_for_pickup`, `picked_up`, `cancelled` (ADR-008). `payment_status` separado: `pending`, `approved`, `failed`, `refunded`, `expired`. |
| RN-PED-003 | Toda transição é registrada em `order_status_history` (de, para, ator tipo/ID, data, observação) e gera notificação ao cliente (RN-NOT). Transições inválidas → 409. |

#### 4.10.1 Máquina de estados e quem pode transicionar

```text
pending_payment ─► paid ─► processing ─► shipped ─► delivered
       │            │           │     └─► ready_for_pickup ─► picked_up
       ▼            ▼           ▼
   cancelled     cancelled   cancelled        (paid/processing → cancelled gera estorno)
```

| ID | De → Para | Ator permitido | Pré-condições | Efeitos |
|---|---|---|---|---|
| RN-PED-010 | `pending_payment` → `paid` | **Sistema** (webhook/reconciliação) | Pagamento aprovado com valor = total | `out` no estoque; `payment_status = approved`; `paid_at` |
| RN-PED-011 | `pending_payment` → `cancelled` | Cliente (dono), `seller`, `finance`, `admin`, Sistema (expiração) | Pagamento não aprovado | `release`; PIX cancelado no gateway quando suportado; `payment_status = expired` (expiração) ou `failed`/`pending`→cancelado; devolução de uso do cupom |
| RN-PED-012 | `paid` → `processing` | `warehouse`, `admin` | — | "Em separação" |
| RN-PED-013 | `processing` → `shipped` | `warehouse`, `admin` | Método `own_delivery`, `table_rate` ou `carrier` | Opcional: transportadora, código de rastreio, data prevista |
| RN-PED-014 | `processing` → `ready_for_pickup` | `warehouse`, `admin` | Método `pickup` | Aviso ao cliente com endereço/horário da loja |
| RN-PED-015 | `shipped` → `delivered` | `warehouse`, `admin` (Sistema no futuro via rastreio) | — | `delivered_at` |
| RN-PED-016 | `ready_for_pickup` → `picked_up` | `warehouse`, `seller`, `admin` | Registrar nome e documento de quem retirou | `picked_up_at` |
| RN-PED-017 | `paid` / `processing` → `cancelled` | `finance`, `manager`/`super-admin` (`orders.cancel_paid`) | Motivo obrigatório | cancelamento efetivado de imediato; `return` no estoque; estorno total **assíncrono** com retry (`payment_refunds`) → `payment_status = refunded` (ver ADR-028) |
| RN-PED-018 | Qualquer outro caminho (ex.: `shipped` → `cancelled`, `delivered` → qualquer, `cancelled` → qualquer) | ninguém | — | 409 (exceção controlada em RN-PAG-012, ver Q-06) |

| ID | Regra |
|---|---|
| RN-PED-020 | Cliente só pode cancelar pedido **`pending_payment`**. Em `paid`/`processing` vê "Solicitar cancelamento" (gera registro/notificação para a loja; não altera status). |
| RN-PED-021 | Cancelamento de pedido pago: o cancelamento é **efetivado** e o estorno é processado de forma **assíncrona com retry**; falha definitiva do estorno fica visível ao `finance` (alerta + painel) para tratamento (ver ADR-028; substitui "não efetivar"). |
| RN-PED-022 | Pedido `shipped`/`delivered` não pode ser cancelado no MVP. Devoluções e direito de arrependimento (CDC art. 49 — 7 dias do recebimento) são tratados manualmente pelo atendimento (estorno manual + `in`/`adjust` no estoque) — ver Q-07. |
| RN-PED-023 | Observações: cliente pode deixar observação no checkout (até 500 caracteres, ex.: "cortar em 2 peças"); operador pode registrar notas internas (não visíveis ao cliente). |
| RN-PED-024 | Retirada: exige conferência do número do pedido e nome/documento de quem retira (cliente ou terceiro autorizado). |
| RN-PED-025 | Pedidos **nunca** são excluídos (ADR-016); pedidos cancelados permanecem para histórico e relatórios. |
| RN-PED-026 | Pedido `ready_for_pickup` não retirado em 30 dias gera alerta ao `seller` para contato (sem mudança automática de status). |

#### 4.10.2 Recompra ("Comprar novamente")

| ID | Situação do item original | Comportamento |
|---|---|---|
| RN-PED-040 | Produto/variante ativos e disponíveis | Adiciona com mesma quantidade/medidas/peças e **preço atual** (não o do pedido). |
| RN-PED-041 | Produto ou variante inativo/excluído | Não adiciona; lista "Indisponível: <nome>". |
| RN-PED-042 | Preço mudou | Adiciona; destaca "Preço atual R$ X (no pedido anterior: R$ Y)". |
| RN-PED-043 | Estoque insuficiente | Se disponível ≥ `min_quantity`, adiciona o máximo válido (múltiplo do step) e avisa; senão não adiciona. |
| RN-PED-044 | Regras de quantidade/dimensão mudaram e a quantidade antiga é inválida | Não adiciona; informa o motivo e link para o produto. |
| RN-PED-045 | Resultado | Mensagem-resumo "N de M itens adicionados" e redireciona ao carrinho. Recompra funciona para qualquer status (inclusive cancelado). |

### 4.11 Pagamentos (`RN-PAG`)

| ID | Regra |
|---|---|
| RN-PAG-001 | MVP: **PIX** apenas, via `PaymentGatewayInterface` com drivers `sandbox` e `mercadopago` (ADR-010). |
| RN-PAG-002 | PIX gerado com valor = `orders.total_cents`, expiração de **30 min** (configurável) igual à expiração do pedido. Exibe QR Code, copia e cola, valor, contador regressivo e instrução. |
| RN-PAG-003 | **Aprovação** via webhook assinado (HMAC validado antes de qualquer processamento); idempotente por `unique(provider, external_id)` (ADR-009). Evento repetido → 200 sem reprocessar. |
| RN-PAG-004 | O webhook é tratado como **notificação**: o backend confirma o status consultando `getPayment` no gateway antes de aprovar (defesa contra payload forjado/stale). |
| RN-PAG-005 | Aprovação de pagamento já aprovado = no-op (ADR-009). |
| RN-PAG-006 | Valor pago ≠ total do pedido → **não aprova**; marca para revisão do `finance` (alerta) e registra `payment_transactions`. |
| RN-PAG-007 | **Reconciliação**: job a cada 5 min consulta gateway para pedidos `pending_payment` com PIX gerado há > 2 min (cobre webhook perdido). `finance` pode forçar "Reconsultar pagamento". |
| RN-PAG-008 | **Expiração**: job agendado cancela pedidos `pending_payment` com `expires_at` vencido → `cancelled` (motivo `payment_expired`), `payment_status = expired`, `release` (ADR-008). Antes de expirar, o job faz uma última consulta ao gateway. |
| RN-PAG-009 | **Falha**: gateway reporta rejeitado/cancelado → `payment_status = failed`; pedido segue `pending_payment` até a expiração, permitindo "Gerar novo PIX" (novo `payment_transaction`, mesmo pedido, mesmo total). |
| RN-PAG-010 | **Estorno**: somente total no MVP, em cancelamento de pedido pago (RN-PED-017). Resultado → `payment_status = refunded`, `refunded_at`, e-mail ao cliente. Estorno parcial fora do MVP. |
| RN-PAG-011 | Todas as chamadas/respostas do gateway registradas em `payment_transactions` e log canal `payments` (sem dados sensíveis). |
| RN-PAG-012 | **PIX pago após a expiração** (pedido já `cancelled` por `payment_expired`): se **todos** os itens tiverem disponível suficiente, o sistema **reativa** o pedido: `reserve`+`out`, `payment_status = approved`, status `paid` (histórico registra "reativado por pagamento tardio"), notifica cliente e loja. Se faltar estoque em qualquer item → **estorno automático** total, `payment_status = refunded`, pedido permanece `cancelled`, e-mail explicando. Nunca reativa pedido cancelado manualmente (pelo cliente/admin) — nesse caso, estorno. (Exige exceção à máquina de estados — ver Q-06.) |
| RN-PAG-013 | Dados de cartão nunca passam pelo backend (ADR-010) — relevante para fase futura. |

**Métodos futuros (fora do MVP, já previstos no enum):**

| Método | Regras previstas |
|---|---|
| `credit_card` | Tokenização no gateway; parcelamento (ex.: até 6× sem juros acima de R$ 300); aprovação síncrona ou via webhook; antifraude do gateway. |
| `boleto` | Vencimento em 3 dias úteis; exige reserva de estoque mais longa (configurável por método — ver Q-10). |
| `invoice` (faturado PJ) | Só PJ aprovado pelo `finance`, com `credit_limit_cents` e prazo (ex.: 28 dias). Pedido é aprovado se `saldo em aberto + total ≤ limite`; senão vai para aprovação manual. Pedido vai a `paid` por aprovação de crédito (pagamento "a receber"). |

### 4.12 Frete — resumo (`RN-FRT`)

> Detalhamento técnico (zonas, regras, prioridades, fatores de cubagem, transportadoras)
> pertence a `SHIPPING.md`. Aqui ficam as regras de negócio e os exemplos de referência.
> **Valores abaixo são ilustrativos/seed** — todos configuráveis no painel.

| ID | Regra |
|---|---|
| RN-FRT-001 | Métodos: `pickup` (retirada, **R$ 0,00**), `own_delivery` (entrega própria por cidade), `table_rate` (tabela por peso/valor por zona), `carrier` (transportadoras externas — fora do MVP) (ADR-011). |
| RN-FRT-002 | Zonas cobrem faixa de CEP, cidade (IBGE) ou UF. Por método+zona vence a regra de **maior prioridade** (menor número) que casar. **Frete grátis** é uma regra com preço 0 (ex.: acima de um valor) (ADR-011). |
| RN-FRT-003 | Condição de valor para frete grátis usa o **subtotal de produtos após desconto do cupom** (sem frete). |
| RN-FRT-004 | Prazo exibido em **dias úteis**: `handling_days` (separação; padrão 1) + `transit_days` do método/zona. Contagem começa no dia útil seguinte à aprovação do pagamento; pagamentos após 14h contam a partir do próximo dia útil. Formato: "Entrega em até 2 dias úteis após a confirmação do pagamento". |
| RN-FRT-005 | Peso acima de todas as faixas da tabela → método não é oferecido; se nenhum método sobrar, exibir "Frete sob consulta — fale conosco" (WhatsApp/e-mail) e oferecer retirada se disponível. |
| RN-FRT-006 | CEP fora de todas as zonas → apenas retirada (se ativa); mensagem clara. |
| RN-FRT-007 | Retirada: endereço e horário da loja exibidos; "pronto para retirada em até 1 dia útil". |
| RN-FRT-008 | Cotação persistida com TTL 30 min, invalidada por qualquer alteração do carrinho ou CEP (ADR-011). |
| RN-FRT-009 | Itens com dimensão acima do limite de um método (ex.: tubo > 150 cm para transportadora) excluem esse método para o carrinho inteiro (regra de volume — `SHIPPING.md`). |
| RN-FRT-010 | Opções ordenadas por preço crescente; em empate, menor prazo. |

**Exemplos de referência (seed):**

| Cidade (IBGE) | CEP exemplo | Retirada | Entrega própria | Tabela por peso | Prazo |
|---|---|---|---|---|---|
| Blumenau (4202404) | 89010-000 | R$ 0,00 | R$ 20,00 (grátis a partir de R$ 500,00) | regional: até 5 kg R$ 15 · até 10 kg R$ 20 · até 20 kg R$ 28; por CEP R$ 18 | própria: 1 dia útil |
| Gaspar (4205902) | 89110-000 | R$ 0,00 | R$ 30,00 (grátis a partir de R$ 500,00) | SC: R$ 30 + R$ 2/kg (mín. R$ 35) | própria: 1–2 dias úteis |
| Indaial (4207502) | 89130-000 | R$ 0,00 | R$ 35,00 | SC: R$ 30 + R$ 2/kg | própria: 2 dias úteis |
| Pomerode (4213203) | 89107-000 | R$ 0,00 | R$ 35,00 | SC: R$ 30 + R$ 2/kg | própria: 2 dias úteis |
| Joinville (4209102) | 89201-000 | R$ 0,00 | — (não atendida) | regional: R$ 25,00 + R$ 1,50/kg iniciado (até 30 kg) · > 30 kg: sob consulta | tabela: 3–4 dias úteis |

> Valores **alinhados ao seed** (DATABASE.md §7.8 — ver ADR-028; a versão anterior usava
> Blumenau R$ 15,00 e grátis acima de R$ 300,00).

Exemplos numéricos:

| # | Cenário | Resultado |
|---|---|---|
| F1 | Blumenau, subtotal R$ 79,50, 1,25 kg (5 m de `VIN-BR-122-BR`) | Retirada R$ 0,00; Entrega própria R$ 20,00; regional R$ 15,00; por CEP R$ 18,00 |
| F2 | Blumenau, subtotal R$ 500,00 | Entrega própria **R$ 0,00** (regra de frete grátis prioridade 10 casa antes da regra padrão) |
| F3 | Blumenau, subtotal R$ 520,00 com cupom de R$ 30,00 → R$ 490,00 | Entrega própria R$ 20,00 (base para frete grátis é após cupom) |
| F4 | Joinville, 10,72 kg | Regional R$ 25,00 + 11 × R$ 1,50 = R$ 41,50; retirada R$ 0,00 |
| F5 | Joinville, 150 kg (ex.: 30 bobinas) | Apenas retirada + "frete sob consulta" |
| F6 | CEP de Porto Alegre (fora das zonas) | Apenas retirada |

### 4.13 Notificações (`RN-NOT`)

| ID | Regra |
|---|---|
| RN-NOT-001 | Canal MVP: **e-mail** (fila Redis; Mailpit em dev) + timeline na área do cliente. WhatsApp/SMS/push fora do MVP. |
| RN-NOT-002 | Falha de envio não bloqueia o fluxo de negócio; reenvio automático até 3 vezes com backoff. |
| RN-NOT-003 | E-mails transacionais não dependem de opt-in de marketing; e-mails promocionais somente com opt-in (RN-LGPD-003). |

| Evento | Destinatário | Canal | Conteúdo mínimo |
|---|---|---|---|
| Cadastro concluído | Cliente | e-mail | Boas-vindas + confirmar e-mail |
| Recuperação de senha | Cliente | e-mail | Link único (60 min) |
| Pedido criado (`pending_payment`) | Cliente | e-mail + timeline | Número, itens, total, PIX copia e cola, validade |
| Pagamento aprovado (`paid`) | Cliente; `warehouse` | e-mail | Confirmação + prazo / "novo pedido a separar" |
| Pedido expirado/cancelado | Cliente | e-mail | Motivo, link "Comprar novamente" |
| Em separação (`processing`) | Cliente | timeline (e-mail opcional, configurável) | — |
| Enviado/saiu para entrega (`shipped`) | Cliente | e-mail | Método, rastreio (se houver), previsão |
| Pronto para retirada | Cliente | e-mail | Endereço, horário, documento necessário |
| Entregue / retirado | Cliente | e-mail | Agradecimento |
| Estorno realizado | Cliente; `finance` | e-mail | Valor, prazo do banco |
| Pagamento tardio reativado / estornado (RN-PAG-012) | Cliente; `finance`; `warehouse` | e-mail | Explicação |
| Solicitação de cancelamento pelo cliente | `seller`, `finance` | e-mail + painel | Pedido, motivo |
| Estoque baixo | `warehouse` | painel + e-mail diário consolidado | Variantes abaixo do limite |
| Divergência de valor PIX / falha de webhook/estorno | `finance`, `admin` | e-mail + painel | Pedido, detalhes |

### 4.14 Admin — papéis e permissões (`RN-ADM`)

| ID | Regra |
|---|---|
| RN-ADM-001 | Usuários do painel em `admin_users`, guard `admin`, RBAC via `spatie/laravel-permission` (ADR-002/006). Sem auto-cadastro: criados (por convite) por quem tem `admin_users.manage`. |
| RN-ADM-002 | Papéis do seed (ver ADR-023/027): `super-admin` (Super Admin, tudo via `Gate::before`), `manager` (Gerente, tudo exceto `admin_users.manage`), `seller` (Vendedor), `warehouse` (Estoque/Expedição), `finance` (Financeiro). Um usuário pode ter mais de um papel. Permissões são atribuídas a papéis; somente `super-admin` administra usuários e papéis e pode criar papéis customizados. |
| RN-ADM-003 | Deve existir sempre **≥ 1 `super-admin` ativo**; o sistema impede remover/desativar o último. Usuário não pode alterar os próprios papéis. |
| RN-ADM-004 | Senha ≥ 12 caracteres; bloqueio após 5 tentativas/min; sessão expira após **30 min de inatividade** ou **8 h** absolutas (ver ADR-023). 2FA (TOTP) recomendado — fora do MVP (ver roadmap). |
| RN-ADM-005 | Toda ação de escrita no painel gera `audit_logs` (ator, ação, entidade, diff sem dados sensíveis, IP, request_id) (ADR-016). |
| RN-ADM-006 | Operador vê dados pessoais completos (CPF/CNPJ) apenas com `customers.view_sensitive`; demais veem mascarado. |

**Matriz de permissões** (✔ = permitido; 👁 = somente leitura; — = negado):

> **Canônico (ver ADR-023/027/028):** o catálogo final de permissões e a atribuição por papel
> estão em **API.md §6.1–§6.2**. A coluna `admin` abaixo corresponde a `manager` (e
> `super-admin`); células parciais foram materializadas como permissões próprias:
> `coupons.manage` ("só cupons"), `orders.pickup` ("só `picked_up`"), `reports.sales` /
> `reports.inventory` ("👁 vendas / estoque"); `pricing.manage` (tabelas e preços de cliente)
> separa-se de `prices.manage` (preço base/faixas); `customers.update` (editar/bloquear) separa-se
> de `customers.manage` (corrigir documento/anonimizar).

| Permissão (`name`) | Descrição | admin | seller | warehouse | finance |
|---|---|---|---|---|---|
| `dashboard.view` | Painel inicial | ✔ | ✔ | ✔ | ✔ |
| `products.view` | Ver produtos/variantes | ✔ | ✔ | ✔ | ✔ |
| `products.manage` | Criar/editar/ativar/excluir produtos, categorias, marcas | ✔ | — | — | — |
| `prices.manage` | Preços base, faixas, tabelas de preço, preços por cliente | ✔ | — | — | — |
| `promotions.manage` | Promoções e cupons | ✔ | ✔ (só cupons) | — | — |
| `inventory.view` | Ver estoque e movimentos | ✔ | ✔ | ✔ | ✔ |
| `inventory.move` | Entrada (`in`) | ✔ | — | ✔ | — |
| `inventory.adjust` | Ajuste (`adjust`) | ✔ | — | ✔ | — |
| `orders.view` | Ver pedidos | ✔ | ✔ | ✔ | ✔ |
| `orders.fulfill` | `paid→processing→shipped/ready_for_pickup→delivered/picked_up` | ✔ | — (só `picked_up`) | ✔ | — |
| `orders.cancel_unpaid` | Cancelar `pending_payment` | ✔ | ✔ | — | ✔ |
| `orders.cancel_paid` | Cancelar `paid`/`processing` com estorno | ✔ | — | — | ✔ |
| `orders.notes` | Notas internas | ✔ | ✔ | ✔ | ✔ |
| `payments.view` | Transações de pagamento | ✔ | 👁 status | — | ✔ |
| `payments.reconcile` | Reconsultar gateway | ✔ | — | — | ✔ |
| `customers.view` | Ver clientes (mascarado) | ✔ | ✔ | — | ✔ |
| `customers.view_sensitive` | Ver CPF/CNPJ completos | ✔ | ✔ | — | ✔ |
| `customers.manage` | Editar, bloquear, atribuir tabela de preço | ✔ | ✔ (sem tabela de preço) | — | — |
| `shipping.manage` | Métodos, zonas, regras de frete | ✔ | — | — | — |
| `reports.view` | Relatórios | ✔ | 👁 vendas | 👁 estoque | ✔ |
| `admin_users.manage` | Usuários e papéis | ✔ | — | — | — |
| `settings.manage` | Configurações gerais (expiração PIX, limites, loja) | ✔ | — | — | — |
| `audit_logs.view` | Auditoria | ✔ | — | — | ✔ |

### 4.15 Relatórios (`RN-REL`)

Regras gerais: períodos agrupados no fuso **America/Sao_Paulo** (datas armazenadas em
UTC — ADR-013); valores em centavos exibidos em BRL; exportação CSV.

| ID | Métrica | Definição exata |
|---|---|---|
| RN-REL-001 | **Faturamento (bruto)** | Soma de `orders.total_cents` dos pedidos com pagamento aprovado (`paid_at` no período), **excluindo** pedidos com `payment_status = refunded`. Inclui frete; já líquido de cupom. |
| RN-REL-002 | **Receita de produtos** | Soma de `subtotal_cents − discount_cents` dos mesmos pedidos de RN-REL-001. |
| RN-REL-003 | **Frete cobrado** | Soma de `shipping_cents` dos mesmos pedidos. |
| RN-REL-004 | **Estornos** | Soma de `total_cents` dos pedidos com `refunded_at` no período (e quantidade). |
| RN-REL-005 | **Pedidos pagos** | Contagem de pedidos com `paid_at` no período (incl. estornados depois; coluna separada). |
| RN-REL-006 | **Ticket médio** | Faturamento (RN-REL-001) ÷ nº de pedidos pagos não estornados. |
| RN-REL-007 | **Taxa de conversão de pagamento** | Pedidos pagos ÷ pedidos criados no período (mede abandono no PIX). |
| RN-REL-008 | **Taxa de cancelamento** | Pedidos `cancelled` ÷ pedidos criados, separado por motivo (`payment_expired`, `customer`, `admin`). |
| RN-REL-009 | **Descontos concedidos** | Soma de `discount_cents` de pedidos pagos, por cupom (nº de usos e valor). |
| RN-REL-010 | **Produtos mais vendidos** | Por variante: soma de quantidade faturada (na unidade de venda — não somar unidades diferentes entre si) e receita da linha (`line_total − discount`) em pedidos pagos não estornados. |
| RN-REL-011 | **Vendas por categoria/marca** | Receita de produtos agrupada pela categoria principal/marca do snapshot. |
| RN-REL-012 | **Pedidos por status** | Contagem atual por status (fila operacional). |
| RN-REL-013 | **Tempo de expedição** | Mediana de `paid_at → shipped_at/ready_for_pickup_at` em horas úteis. |
| RN-REL-014 | **Estoque baixo** | Variantes com disponível ≤ `low_stock_threshold`. |
| RN-REL-015 | **Movimentação de estoque** | Somas por tipo de movimento e variante no período. |
| RN-REL-016 | **Novos clientes / recorrentes** | Novos: cadastrados no período. Recorrentes: com ≥ 2 pedidos pagos (acumulado) e ao menos 1 no período. |
| RN-REL-017 | **Vendas por método de frete/cidade** | Faturamento e nº de pedidos por `shipping_method` e cidade do snapshot. |
| RN-REL-018 | **Valor de estoque / margem** | Fora do MVP (requer custo — ver Q-09). |

### 4.16 LGPD e privacidade (`RN-LGPD`)

| ID | Regra |
|---|---|
| RN-LGPD-001 | Bases legais: execução de contrato (pedido, entrega), obrigação legal (dados fiscais), consentimento (marketing), legítimo interesse (prevenção a fraude, logs de segurança). |
| RN-LGPD-002 | Aceite de Termos de Uso e Política de Privacidade obrigatório no cadastro, registrando **versão**, data/hora e IP. Nova versão relevante → solicitar novo aceite no próximo login. |
| RN-LGPD-003 | Consentimento de marketing separado, opcional, desmarcado por padrão, revogável a qualquer momento na área do cliente e por link no e-mail. |
| RN-LGPD-004 | Direitos do titular na área do cliente: **acesso/portabilidade** (exportar dados em JSON/CSV), **correção** (exceto CPF/CNPJ — RN-CLI-005), **exclusão** (solicitação). |
| RN-LGPD-005 | Exclusão: conta é **anonimizada** (nome, e-mail, telefone, endereços substituídos; login desativado). Dados de pedidos exigidos por obrigação fiscal/contábil são retidos pelo prazo legal (5 anos) e depois anonimizados. Pedidos em andamento impedem a exclusão até a conclusão. |
| RN-LGPD-006 | Minimização: não coletar dados além dos listados em RN-CLI; nunca armazenar dados de cartão (ADR-010). |
| RN-LGPD-007 | Logs nunca contêm senha, tokens, dados de cartão, CPF/CNPJ completo (ADR-016). Exibição mascarada no painel conforme permissão (RN-ADM-006). |
| RN-LGPD-008 | Carrinho de visitante não guarda dados pessoais. Cookies: apenas essenciais (sessão, CSRF, token de carrinho) no MVP; analytics/marketing só com banner de consentimento (fora do MVP). |
| RN-LGPD-009 | Encarregado (DPO) e canal de contato divulgados na Política de Privacidade. |
| RN-LGPD-010 | Incidente de segurança com dados pessoais → registro e avaliação para comunicação à ANPD/titulares (processo operacional). |

### 4.17 Busca e SEO (`RN-BUS`)

| ID | Regra |
|---|---|
| RN-BUS-001 | Busca por nome, descrição, marca, categoria (sem acento, português) e **SKU por prefixo** (ADR-014). Só produtos ativos com ≥ 1 variante ativa. |
| RN-BUS-002 | Termo com 2–100 caracteres; resultados paginados (24 por página). |
| RN-BUS-003 | Ordenação: relevância (padrão), menor preço, maior preço, nome A–Z, mais recentes. Filtros: categoria, marca, faixa de preço, "somente em estoque", unidade de venda. |
| RN-BUS-004 | Sem resultados → sugestões (categorias principais) e link de contato. |
| RN-BUS-005 | SEO: title/description/canonical/JSON-LD Product e BreadcrumbList; produtos inativos fora do sitemap (ADR-015). Meta title padrão: "<Nome> — <Categoria> | <Loja>". |
| RN-BUS-006 | Preço no JSON-LD = preço para visitante na quantidade mínima (sem tabela/cliente). |

---

## 5. Casos de borda (edge cases)

| ID | Situação | Comportamento esperado | Regras |
|---|---|---|---|
| EC-001 | Preço mudou entre carrinho e checkout | Carrinho mostra novo preço com aviso; se mudou após a Revisão, `POST /checkout` retorna 409 com novos valores; cliente reconfirma. | RN-CAR-010, RN-CHK-005 |
| EC-002 | Estoque esgotou enquanto o cliente preenchia o checkout | 409 no checkout listando itens/disponível; nenhum pedido criado. | RN-CHK-003 |
| EC-003 | Estoque "esgota" enquanto o cliente paga o PIX | Não ocorre: estoque já está reservado desde a criação do pedido. | RN-EST-004, ADR-008 |
| EC-004 | PIX pago após expiração, com estoque | Pedido reativado para `paid` (`reserve`+`out`). | RN-PAG-012 |
| EC-005 | PIX pago após expiração, sem estoque | Estorno automático total; pedido segue `cancelled`; e-mail. | RN-PAG-012 |
| EC-006 | PIX pago em pedido cancelado pelo cliente/admin | Estorno automático; não reativa. | RN-PAG-012 |
| EC-007 | Webhook duplicado | `unique(provider, external_id)` → 200 sem reprocessar. | RN-PAG-003 |
| EC-008 | Webhook com assinatura inválida | 401/403, nada processado, log de segurança. | ADR-009 |
| EC-009 | Webhook chega antes da resposta do checkout (corrida) | Processamento ocorre após commit do pedido; se o pedido ainda não existir, responde erro retryable (5xx) para o gateway reenviar; reconciliação cobre. | RN-PAG-007 |
| EC-010 | Duplo clique em "Finalizar pedido" / retry de rede | Mesma `Idempotency-Key` → mesmo pedido; botão desabilitado no front. | RN-CHK-006 |
| EC-011 | Mesma chave de idempotência com carrinho diferente | Retorna o pedido original (a chave identifica a intenção); front gera nova chave a cada nova tentativa após alteração do carrinho. | ADR-009 |
| EC-012 | CEP não atendido por entrega/tabela | Só retirada; se retirada inativa, "Não entregamos neste CEP". | RN-FRT-006 |
| EC-013 | CEP inexistente ou ViaCEP fora do ar | "CEP não encontrado" / "Serviço indisponível, tente novamente"; não permite prosseguir sem cidade. | RN-CLI-022 |
| EC-014 | Peso acima de todas as faixas da tabela | Método não ofertado; "Frete sob consulta" + retirada. | RN-FRT-005 |
| EC-015 | Cupom expira durante o checkout | Revalidado no `POST /checkout` → 422 "Cupom expirado"; cliente vê total sem desconto e reconfirma. | RN-CUP-012 |
| EC-016 | Cupom atinge limite total por pedidos simultâneos | Lock na linha do cupom; o que excede recebe 422. | RN-CUP-005 |
| EC-017 | Cupom fixo maior que o subtotal | Desconto limitado ao subtotal; total = frete. | RN-CUP-009 |
| EC-018 | Tentativa de aplicar dois cupons | Segundo substitui o primeiro. | RN-CUP-006 |
| EC-019 | Produto desativado com itens em carrinhos | Linha marcada "indisponível", bloqueia checkout até remover. | RN-CAR-030 |
| EC-020 | Produto desativado com pedido `pending_payment` | Pedido segue normalmente (snapshot e reserva); pagamento aprovado → `out`. | RN-CAT-010 |
| EC-021 | Cliente quer mudar de PF para PJ | Não self-service; gerente converte se não houver pedidos; senão nova conta. | RN-CLI-007 |
| EC-022 | Endereço excluído que foi usado em pedido | Pedido mantém snapshot; endereço soft-deleted. | RN-CLI-024 |
| EC-023 | Quantidade fracionária para produto `UNIT` (ex.: 2,5) | 422 "Quantidade deve ser inteira". | RN-QTD-004 |
| EC-024 | Quantidade zero, negativa, vazia, `1e3`, texto | 422. | RN-QTD-007 |
| EC-025 | Dimensões extremamente grandes (ex.: 999 × 999 m) | 422 por `max_*_mm` da variante e limite global (100 m). | RN-QTD-006 |
| EC-026 | Largura maior que a máxima mas altura cabe (medidas invertidas) | 422 sugerindo inverter L × A; sem rotação automática. | RN-QTD-038 |
| EC-027 | Quantidade não múltipla do step (5,05 m, step 0,10) | 422 com sugestões 5,00/5,10. | RN-QTD-010 |
| EC-028 | Área abaixo do mínimo faturável | Cobra área mínima e exibe ambas as áreas. | RN-QTD-031 |
| EC-029 | Mais de 3 casas decimais (5,1234 m) ou medida com mm (1,205 m) | 422. | RN-QTD-002, RN-QTD-034 |
| EC-030 | Mesma variante em duas linhas (dois cortes de lona) e estoque suficiente só para uma | Validação e reserva pela soma → 409 no checkout; carrinho sinaliza "estoque insuficiente para o total". | RN-EST-006, RN-CAR-006 |
| EC-031 | Admin ajusta estoque para abaixo do reservado | 422 "Existem X reservados". | RN-EST-021 |
| EC-032 | Dois operadores ajustando o mesmo estoque ao mesmo tempo | `FOR UPDATE` serializa; segundo ajuste usa saldo atualizado; ambos registrados. | RN-EST-005 |
| EC-033 | Mesclagem de carrinho excede estoque/máximo | Limita ao máximo válido e avisa. | RN-CAR-020 |
| EC-034 | Cliente loga e o preço cai (tabela atacado) | Carrinho mostra novo preço com `price_source`. | RN-CAR-021 |
| EC-035 | Promoção termina entre carrinho e checkout | Preço recalculado sobe; EC-001 se aplica. | RN-PRC-007 |
| EC-036 | Valor pago no PIX diferente do total | Não aprova; alerta `finance`. | RN-PAG-006 |
| EC-037 | Estorno falha no gateway ao cancelar pedido pago | Cancelamento não efetivado; erro ao operador; retry. | RN-PED-021 |
| EC-038 | Tentativa de cancelar pedido já `shipped` | 409; orientar fluxo de devolução manual. | RN-PED-022 |
| EC-039 | Cliente tenta acessar pedido de outro (troca de UUID/ID) | 404 (policy; não revelar existência). | ADR-012 |
| EC-040 | Frontend envia `price`, `total`, `status`, `customer_id` | Campos ignorados/rejeitados; totais do servidor. | ADR-012 |
| EC-041 | Cotação de frete expirada (> 30 min) no checkout | 409 "Recalcule o frete". | RN-CHK-004 |
| EC-042 | Carrinho alterado após escolher frete | Cotação invalidada; novo cálculo exigido. | RN-FRT-008 |
| EC-043 | Frete grátis por valor deixa de valer após cupom reduzir subtotal | Frete volta a ser cobrado; exibido antes da confirmação. | RN-FRT-003 |
| EC-044 | Cancelamento de pedido `processing` com material já cortado sob medida | Estorno + `return` (ADR-008); estoquista registra perda com `adjust` se o corte não for reaproveitável. | RN-EST-023, Q-08 |
| EC-045 | Categoria desativada com produtos ativos | Bloqueado; lista produtos a mover. | RN-CAT-008 |
| EC-046 | Variante com preço base 0 ou peso 0 | Produto não pode ser ativado. | RN-CAT-012 |
| EC-047 | Faixa de preço crescente (51+ mais cara que 1–10) | Rejeitada no cadastro. | RN-PRC-005 |
| EC-048 | Pedido `ready_for_pickup` nunca retirado | Alerta após 30 dias; sem mudança automática. | RN-PED-026 |
| EC-049 | Gateway indisponível ao gerar PIX | Pedido criado em `pending_payment`; "Gerar PIX novamente". | RN-CHK-007 |
| EC-050 | Job de expiração e webhook de aprovação simultâneos | Ambos travam o pedido (`FOR UPDATE`); o primeiro vence; se a expiração venceu, aplica-se RN-PAG-012. | RN-PAG-008/012 |
| EC-051 | Visitante com carrinho em dois dispositivos | Carrinhos distintos (tokens distintos); após login em ambos, são mesclados ao carrinho do cliente. | RN-CAR-020 |
| EC-052 | Último admin tenta se desativar | Bloqueado. | RN-ADM-003 |
| EC-053 | Cliente bloqueado com pedido pago | Pedido segue fluxo; cliente não loga (contato via e-mail). | RN-CLI-010 |
| EC-054 | Arredondamento de meio centavo (5,35 m × R$ 15,90) | `round_half_up` → R$ 85,07. | RN-QTD-020 |

---

## 6. Escopo do MVP

Checklist de aceitação de escopo (todos obrigatórios para o go-live):

**Loja (storefront)**

- [ ] **Cadastro** PF e PJ (CPF/CNPJ válidos, IE/ISENTO, aceite LGPD) — RN-CLI, RN-LGPD
- [ ] **Login**, logout, recuperação de senha — RN-CLI-008
- [ ] **Produtos**: página com variantes, ficha técnica, imagens, calculadora de quantidade/área, preço resolvido, disponibilidade — RN-CAT, RN-QTD, RN-PRC
- [ ] **Categorias**: árvore até 3 níveis, listagem com filtros e ordenação — RN-CAT-007
- [ ] **Busca** sem acento + SKU — RN-BUS
- [ ] **Venda por unidade** (UNIT/ROLL/BOX), **metro linear**, **m²** (largura fixa e faixa, peças, área mínima) e KG — RN-QTD
- [ ] **Carrinho** visitante + cliente, mesclagem, revalidação — RN-CAR
- [ ] **CEP**: consulta e preenchimento de endereço — RN-CLI-021
- [ ] **Cotação de frete** no produto e no carrinho — J09/J10
- [ ] **Checkout** em etapas, login obrigatório, idempotente — RN-CHK
- [ ] **PIX** (sandbox + Mercado Pago), expiração 30 min, webhook, reconciliação — RN-PAG
- [ ] **Pedido**: número CV-, e-mails, timeline — RN-PED, RN-NOT
- [ ] **Área do cliente**: dados, endereços, pedidos, acompanhamento, cancelar pendente, comprar novamente, exportar dados/solicitar exclusão — RN-PED-020/040, RN-LGPD-004

**Frete**

- [ ] **Frete por tabela** (peso/valor por zona) — RN-FRT
- [ ] **Entrega própria** por cidade — RN-FRT
- [ ] **Retirada** no balcão — RN-FRT
- [ ] Frete grátis por regra — RN-FRT-002/003

**Painel admin**

- [ ] **Gestão de produtos** (produtos, variantes, categorias, marcas, imagens, preços, faixas)
- [ ] Tabelas de preço, preços por cliente, promoções, cupons
- [ ] **Gestão de estoque** (entrada, ajuste, histórico, estoque baixo) — RN-EST
- [ ] **Gestão de pedidos** (filas por status, transições, cancelamento com estorno, notas) — RN-PED
- [ ] **Gestão de clientes** (consulta, edição, bloqueio, tabela de preço) — RN-CLI
- [ ] **Gestão de fretes e regras de frete** (métodos, zonas, regras, prioridade) — RN-FRT
- [ ] **Usuários admin** e **permissões** (papéis padrão + customizados) — RN-ADM
- [ ] Relatórios essenciais (RN-REL-001 a 017) e auditoria

**Qualidade e segurança**

- [ ] **Testes**: unitários (Quantity, PriceResolver, cálculos de área/step/arredondamento com os exemplos X1–X11 e E1–E7), integração (checkout, estoque concorrente, webhook idempotente, expiração), E2E do fluxo da seção 9
- [ ] **Segurança**: CSRF, rate limiting, policies (IDOR), validação estrita, HMAC em webhooks, logs sem dados sensíveis, headers de segurança, RBAC — ADR-006/009/012/016

## 7. Fora do MVP

| Item | Observação |
|---|---|
| Emissão de NF-e / integração ERP | Pedido já guarda snapshot fiscal (CPF/CNPJ/IE, rateio de desconto) |
| Transportadoras reais (Correios, Jadlog, Braspress, Melhor Envio…) e rastreamento automático | `ShippingCarrierInterface` pronto |
| Cartão de crédito, boleto, faturado PJ com limite de crédito | Enum e interface previstos |
| Estorno parcial, devolução/troca (RMA) no sistema | Tratado manualmente |
| Múltiplos usuários por empresa (PJ) com aprovação de compras | Modelo `companies` preparado |
| WhatsApp/SMS/push | — |
| Estoque de m² por bobina/metro linear com aproveitamento de retalhos | ADR-004 |
| Custo do produto, margem, valor de estoque | Q-09 |
| Validação de IE por UF; consulta de CNPJ na Receita | — |
| Avaliações de produto, lista de desejos, comparador | — |
| 2FA no painel, SSO | Recomendado na fase 2 |
| Upload de arte/arquivo de impressão, serviço de impressão | Loja vende insumos, não impressos |
| Multi-loja/multi-estoque (filiais) | — |
| Busca com motor externo (Meilisearch/OpenSearch), SSR | ADR-014/015 |
| Banner de cookies com analytics/marketing | RN-LGPD-008 |

## 8. Roadmap por fases

| Fase | Tema | Entregas principais |
|---|---|---|
| **0 — MVP** | Vender online com segurança | Seção 6 |
| **1 — Operação fiscal e logística** | Integrar com o back-office | ERP (produtos, estoque, clientes, pedidos), **NF-e** (emissão/DANFE/XML por e-mail, destaque de tributos), **transportadoras reais** com cotação e etiqueta, **rastreamento** automático (`shipped→delivered`), estorno parcial, RMA/devoluções, 2FA admin |
| **2 — B2B** | Fidelizar empresas | **Tabelas B2B** avançadas (por segmento/região), múltiplos compradores por empresa com aprovação, **crédito/faturado PJ** com limite e prazo, boleto, cartão com parcelamento, orçamento/cotação formal (PDF), pedido mínimo por tabela |
| **3 — Relacionamento** | Aumentar recorrência | **WhatsApp** (notificações e atendimento), **recorrência/assinaturas** (reposição programada de insumos), programa de **fidelidade**/cashback, recomendações ("quem comprou vinil também comprou…"), carrinho abandonado |
| **4 — Expansão** | Novos canais | **Marketplace** (Mercado Livre, Shopee, Amazon — sincronização de estoque/preço), **app mobile** (React Native reutilizando API), multi-filial/estoque, SSR/motor de busca |

---

## 9. Critérios de aceite — fluxo final de sucesso

**Dados de seed do cenário:**

| Item | Valor |
|---|---|
| Produto | "Vinil Adesivo Branco Brilho 1,22 m" — categoria Mídias › Vinis — `sale_unit = LINEAR_METER` |
| Variante | SKU `VIN-BR-122`, `fixed_width_mm = 1220`, `min_quantity = 1`, `quantity_step = 0,1`, `max_quantity = 50`, preço base **R$ 15,90/m** (sem faixas aplicáveis a 5 m, sem promoção), `weight_g = 180`/m |
| Estoque inicial | `on_hand = 100,000 m`, `reserved = 0` |
| Frete | Retirada R$ 0,00; Entrega própria Blumenau R$ 15,00 (1 dia útil); frete grátis Blumenau acima de R$ 300,00 |
| Cliente | Novo cliente PF, CEP 89010-000 (Blumenau/SC) |
| Pagamento | Driver `sandbox` (PIX) |

**Critérios (Given/When/Then):**

| ID | Critério |
|---|---|
| CA-001 | **Dado** um visitante na loja, **quando** busca "vinil branco" (ou "VIN-BR"), **então** o produto aparece nos resultados com "R$ 15,90 /m" e selo "Em estoque". |
| CA-002 | **Quando** abre o produto e informa **5** m, **então** vê "1,22 m × 5,00 m" e **Total R$ 79,50**; ao informar 5,05 vê erro de step com sugestões 5,00/5,10 e o botão "Adicionar" fica desabilitado. |
| CA-003 | **Quando** informa CEP 89010-000 na página do produto, **então** vê "Retirar na loja — Grátis" e "Entrega própria Blumenau — R$ 15,00 — 1 dia útil". |
| CA-004 | **Quando** adiciona ao carrinho como visitante, **então** o carrinho mostra 1 linha, 5,00 m, R$ 15,90/m, subtotal **R$ 79,50**, peso estimado 0,90 kg; o `X-Cart-Token` é persistido. |
| CA-005 | **Quando** clica em "Finalizar compra", **então** é levado a Entrar/Cadastrar; **quando** cadastra-se como PF com CPF válido e aceita os termos, **então** volta ao checkout com o carrinho mesclado (mesma linha, 5,00 m). CPF inválido é rejeitado com mensagem. |
| CA-006 | **Quando** cadastra endereço com CEP 89010-000, **então** cidade "Blumenau", UF "SC" e IBGE 4202404 são preenchidos automaticamente; o endereço vira padrão. |
| CA-007 | **Quando** escolhe "Entrega própria — R$ 15,00" e PIX, **então** a Revisão mostra: subtotal R$ 79,50, desconto R$ 0,00, frete R$ 15,00, **total R$ 94,50**, prazo "até 1 dia útil após a confirmação do pagamento". |
| CA-008 | **Quando** clica "Finalizar pedido" (inclusive com duplo clique), **então** exatamente **um** pedido é criado, com número no formato `CV-000001`, status `pending_payment`, `payment_status = pending`, `total_cents = 9450`, e o carrinho fica vazio. |
| CA-009 | **Então** o estoque da variante fica `on_hand = 100,000`, `reserved = 5,000` (disponível 95,000), com movimento `reserve` referenciando o pedido. |
| CA-010 | **Então** a tela exibe QR Code PIX, copia e cola, valor R$ 94,50 e contador de 30 min; o cliente recebe e-mail "Pedido CV-000001 recebido". |
| CA-011 | **Quando** o webhook assinado do sandbox aprova o pagamento, **então** o pedido vai para `paid`, `payment_status = approved`, estoque `on_hand = 95,000`, `reserved = 0` (movimento `out`), e cliente recebe e-mail "Pagamento confirmado". Reenviar o mesmo webhook **não** altera nada (200, sem novo movimento). |
| CA-012 | **Dado** um operador `warehouse` logado no painel, **quando** abre "Pedidos a separar", **então** vê CV-000001 com item "VIN-BR-122 — 5,00 m (1,22 × 5,00 m)" e endereço de entrega. |
| CA-013 | **Quando** clica "Iniciar separação", **então** status `processing`; **quando** "Saiu para entrega", `shipped`; **quando** "Confirmar entrega", `delivered`. Cada transição aparece em `order_status_history` com o operador e data, e o cliente recebe as notificações correspondentes. |
| CA-014 | **Dado** um operador `seller`, **quando** tenta marcar o pedido como `shipped`, **então** recebe 403. Transição inválida (ex.: `delivered → processing`) retorna 409. |
| CA-015 | **Dado** o cliente logado, **quando** acessa Minha conta → Pedidos → CV-000001, **então** vê itens, valores (R$ 79,50 + R$ 15,00 = R$ 94,50), endereço, e a **timeline** completa (Pedido recebido → Pagamento confirmado → Em separação → Saiu para entrega → Entregue) com datas. |
| CA-016 | **Quando** clica "Comprar novamente", **então** o carrinho recebe 5,00 m de VIN-BR-122 com o preço atual. |
| CA-017 | **Dado** outro cliente autenticado, **quando** tenta acessar o UUID do pedido CV-000001, **então** recebe 404. |
| CA-018 | **Relatórios**: o faturamento do dia inclui **R$ 94,50**, 1 pedido pago, ticket médio R$ 94,50; "Produtos mais vendidos" mostra VIN-BR-122 com 5,000 m e R$ 79,50. |

**Variante do fluxo (expiração)** — CA-019: **Dado** um pedido `pending_payment` não pago, **quando** passam 30 min, **então** o job o cancela (`payment_expired`), `payment_status = expired`, `reserved` volta ao valor anterior (movimento `release`) e o cliente recebe e-mail com "Comprar novamente".

---

## 10. Convenções de formatação e exibição

| Tipo | Armazenamento/API | Exibição na loja/painel |
|---|---|---|
| Dinheiro | centavos inteiros (`7950`) | `R$ 79,50` (pt-BR, `Intl.NumberFormat('pt-BR', {style:'currency', currency:'BRL'})`) |
| Quantidade m / kg | decimal (`5.5`) / milésimos no domínio | `5,50 m`, `1,5 kg` (2 casas para m, até 3 para kg) |
| Área | milésimos de m² | `3,000 m²` (3 casas) |
| Dimensões | mm no domínio, `width_m` na API | `1,20 × 2,50 m` |
| Unidade | `sale_unit` | "/un", "/m", "/m²", "/rolo", "/kg", "/cx" |
| Peso | gramas | `10,72 kg` |
| Datas | ISO-8601 UTC | `24/09/2026 14:35` (America/Sao_Paulo) |
| CPF/CNPJ | só dígitos | `123.456.789-09` / `12.345.678/0001-95` (mascarado conforme RN-CLI-002) |
| CEP | 8 dígitos | `89010-000` |
| Pedido | `number` | `CV-000123` |

## 11. Rastreabilidade RN ↔ ADR

| ADR | Regras relacionadas |
|---|---|
| ADR-003 Dinheiro/quantidades | RN-QTD-001/002/020/030, RN-LOG-002, RN-PRC-007 |
| ADR-004 Produto/variante/unidade | RN-CAT-002/004, RN-QTD-003…038 |
| ADR-005 Preço | RN-PRC-001…014, RN-CUP-010 |
| ADR-006 Auth | RN-CLI-001/008, RN-ADM-001 |
| ADR-007 Carrinho | RN-CAR-001…041, RN-CHK (Identificação) |
| ADR-008 Pedido/estoque | RN-EST-*, RN-PED-010…018, RN-PAG-008 |
| ADR-009 Idempotência | RN-CHK-006, RN-PAG-003/005 |
| ADR-010 Pagamentos | RN-PAG-* |
| ADR-011 Frete | RN-FRT-*, RN-CHK-004 |
| ADR-012 Nunca confiar no frontend | RN-PRC-001, RN-CHK-001, EC-039/040 |
| ADR-014/015 Busca/SEO | RN-BUS-*, RN-CAT-005/006 |
| ADR-016 Auditoria/snapshot | RN-CHK-011, RN-EST-022, RN-ADM-005, RN-CLI-024 |

---

## 12. Questões para o Architect

Lacunas identificadas; cada uma traz uma **proposta** do PO. Até decisão em nova ADR,
os agentes devem seguir a proposta.

| ID | Questão | Proposta do PO |
|---|---|---|
| Q-01 | ADR-003 diz que a área `width_mm × height_mm × pieces / 1000` é "exata", mas o resultado nem sempre é inteiro em milésimos (ex.: 550 × 850 × 3 / 1000 = 1402,5). Qual arredondamento? | Arredondar a área para o milésimo com `round_half_up` (1402,5 → 1403 = 1,403 m²) **antes** de aplicar área mínima, preço e estoque; usar o mesmo valor em todos os lugares. Com a precisão de 1 cm (Q-04) o resultado é sempre inteiro — outra saída é adotar Q-04 e manter "exato". |
| Q-02 | `min_billable_area` vale por **linha** (área total) ou por **peça**? Gráficas costumam cobrar mínimo por peça. | MVP: por **linha** (texto literal da ADR-004). Avaliar `min_billable_area_per_piece` como flag futura. |
| Q-03 | Faixas por quantidade (`price_tiers`) consideram a quantidade da **linha** ou a **soma da variante** no carrinho (ex.: dois cortes de lona)? | Soma da mesma variante no carrinho/pedido (mais justo e evita dividir linhas para "perder" desconto ou ganhar). |
| Q-04 | ADR-004 define `quantity_step` mas nada sobre precisão de **dimensões** em `SQUARE_METER`. | Precisão de 1 cm (múltiplo de 10 mm) no MVP; opcional campo `dimension_step_mm` (padrão 10) por variante. |
| Q-05 | ADR-007 guarda só a escolha no `cart_item`, e ADR-012 ignora `total` do cliente. Como detectar "preço mudou" (carrinho) e "total mudou desde a Revisão" (checkout)? | (a) Campo informativo `cart_items.last_seen_unit_price_cents` atualizado quando o cliente visualiza; (b) `POST /checkout` aceita `expected_total_cents` **apenas para comparação** (nunca para cálculo) e responde 409 se diferente. |
| Q-06 | PIX pago após expiração: ADR-008 não prevê `cancelled → paid`. | Exceção controlada: transição permitida **somente** ao ator sistema, quando `cancel_reason = payment_expired` e há estoque para todos os itens (novo `reserve`+`out`); senão estorno automático. Registrar em nova ADR. |
| Q-07 | Devolução pós-entrega/arrependimento (CDC art. 49) e estorno de pedido `delivered` não estão na máquina de estados; `payment_status = refunded` num pedido `delivered` é permitido? | MVP: processo manual, mas permitir `finance` registrar estorno em pedido `delivered` (payment_status `refunded`, status inalterado) + movimento `in` manual. Fase 1: status `returned`/RMA. Também validar juridicamente arrependimento para itens cortados sob medida. |
| Q-08 | Cancelamento de pedido `processing` com material já cortado: `return` recoloca m² que pode não ser revendável. | Manter `return` (ADR-008) e exigir que o estoquista registre `adjust` de perda quando aplicável; opcional: checkbox "material cortado — não retornar ao estoque" que troca `return` por nenhum movimento + registro de perda. |
| Q-09 | Não há `cost_cents` para margem/valor de estoque. | Adicionar `product_variants.cost_cents` (opcional, visível só a `admin`/`finance`) já no schema do MVP; relatórios na fase 1. |
| Q-10 | Expiração/reserva de 30 min é global; boleto/faturado exigirão prazos maiores. | Expiração configurável **por método de pagamento** (`payment_methods.expires_in_minutes`). |
| Q-11 | Calendário de feriados para prazos em dias úteis. | Tabela `holidays` (nacionais + municipais Blumenau/SC) mantida no painel; seed com nacionais do ano. |
| Q-12 | Mudança de slug (produto/categoria) quebra URLs indexadas. | Tabela `slug_redirects` com 301 automático ao alterar slug. |
| Q-13 | CNPJ alfanumérico (Receita Federal, a partir de jul/2026) — `companies.cnpj` só com dígitos e validação módulo 11 numérica não cobrem. | Armazenar CNPJ como `char(14)` alfanumérico em maiúsculas e validar DV pelo algoritmo novo (valor ASCII − 48), compatível com CNPJs numéricos. |
| Q-14 | Contagem de uso de cupom: ADR não define quando conta/devolve. | Conforme RN-CUP-005 (conta na criação, devolve em cancelamento sem pagamento). |
| Q-15 | Restrições de volume (tubos de 3,20 m, bobinas pesadas) por método de frete. | `SHIPPING.md` deve prever `max_length_cm`/`max_weight_g` por método e regra; item que excede remove o método (RN-FRT-009). |
| Q-16 | Webhook que chega antes do commit do pedido (corrida). | Responder 5xx retryable quando o pagamento externo não é encontrado localmente e confiar na reconciliação (RN-PAG-007); não registrar o evento como processado nesse caso. |
