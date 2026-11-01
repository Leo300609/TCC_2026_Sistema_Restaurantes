# 🍽️ XTEC · Sistema de Gestão e Automação Comercial para Restaurantes

![Status](https://img.shields.io/badge/Status-TCC%20final-brightgreen)
![Node](https://img.shields.io/badge/Node.js-20%2B-339933)
![Postgres](https://img.shields.io/badge/PostgreSQL-Neon-336791)
![Licença](https://img.shields.io/badge/Licença-MIT-blue)

> Plataforma web que substitui comandas de papel e planilhas por um fluxo digital único: do pedido no salão ou no balcão até a cozinha, o caixa, o estoque e o resultado financeiro do mês.

---

## 📋 Sobre o projeto

O XTEC centraliza a operação de um restaurante em um painel que se atualiza enquanto o salão funciona. Cada venda dá baixa automática nos insumos da ficha técnica, cada compra alimenta o estoque e as contas a pagar, e tudo aparece no fluxo de caixa e nos relatórios gerenciais.

Toda a regra de negócio fica no banco, em **funções, views e procedures PL/pgSQL**. O Node valida o formato da entrada, confere a permissão do usuário e chama as funções com parâmetros.

---

## 🚀 Funcionalidades

### Operação
| Módulo | O que faz |
|---|---|
| **Visão geral** | Faturamento, pedidos, ticket médio e ocupação com comparação ao dia anterior, vendas por hora (hoje × semana passada), pedidos em andamento, mais vendidos, caixa do mês, alertas de estoque e equipe online. |
| **PDV (frente de caixa)** | Catálogo com busca e código de barras, comanda com observações por item, desconto, troco automático, venda direta ("Cobrar agora") ou envio para a cozinha, e comprovante para impressora térmica de 80 mm. |
| **Pedidos** | Quadro kanban (Novo → Em preparo → Pronto → Entregue) para salão, balcão e delivery, com atraso destacado, edição, cancelamento e estorno. |
| **Mesas** | Mapa do salão por área (livre, ocupada, aguardando conta), comanda somada da mesa, divisão da conta, fechamento com troco e troca de mesa. |
| **Cozinha (KDS)** | Comandas grandes em ordem de chegada, cronômetro ao vivo, resumo "produzir agora", aviso sonoro opcional e atualização automática. |

### Gestão
| Módulo | O que faz |
|---|---|
| **Cardápio** | Itens por categoria, disponibilidade, **ficha técnica** (insumos por unidade), custo, margem e quantas unidades o estoque ainda rende. |
| **Estoque** | Insumos, validade, estoque mínimo, entradas e saídas, e **baixa automática** dos insumos quando o pedido é entregue (com estorno no cancelamento). |
| **Compras** | Compra com vários itens, desconto e parcelamento mensal; dá entrada no estoque, atualiza o custo dos insumos e gera as contas a pagar. |
| **Fornecedores** | Cadastro com CNPJ, contato, total comprado e tabela de preços praticados. |
| **Clientes** | Cadastro com CPF/CNPJ, histórico de pedidos e total gasto. |

### Financeiro e administração
| Módulo | O que faz |
|---|---|
| **Fluxo de caixa** | Entradas (vendas), saídas (compras) e lançamentos manuais, com situação pendente, paga, atrasada ou cancelada. |
| **Relatórios** | Período livre, comparação com o período anterior, DRE simplificada (receita, custo dos insumos, lucro bruto, despesas e resultado), gráficos por dia, hora, dia da semana e forma de pagamento, rankings de produtos, categorias, canais, equipe e clientes. Exporta em **PDF, Excel, Word e CSV**. |
| **Previsões** | Produtos **em alta**, **entrando em tendência** e **perdendo força** (comparação de períodos e regressão linear), faturamento e pedidos previstos para os próximos 7 dias com faixa de confiança, mapa de calor dos horários de pico e **reposição prevista** dos insumos (quando acabam e quanto comprar). |
| **Empresa e equipe** | Dados do estabelecimento, horário de funcionamento, equipe com presença online e **convites com validade** para novos funcionários. |
| **Atividades (auditoria)** | Registro de toda ação concluída: quem fez, o quê, quando e de qual IP, com filtros. |
| **Configurações** | Conta e senha, notificações, operação (delivery, tempo de preparo, formas de pagamento) e aparência (tema claro, escuro ou do sistema). |

### Recursos transversais
- **Busca global (Ctrl + K)**: páginas, ações rápidas, clientes, produtos, pedidos e fornecedores.
- **Notificações**: pedidos atrasados, estoque abaixo do mínimo, validade e contas a vencer, conforme as preferências de cada usuário.
- **Tema claro e escuro**, menu recolhível e layout responsivo (tabelas viram cartões no celular).
- Funciona **sem JavaScript** nos cadastros (formulários comuns com padrão POST → redirecionamento → GET); o JavaScript só melhora a experiência.

---

## 🔐 Segurança

- Senhas com **bcrypt** (`password_hash`); senhas antigas em texto puro são convertidas no primeiro login.
- **Bloqueio** após 5 tentativas erradas em 15 minutos (por e-mail e por IP).
- Sessão com cookie `HttpOnly` e `SameSite`, modo estrito, nova sessão a cada login e expiração por inatividade.
- Token **CSRF** em todos os formulários do painel.
- Consultas sempre por *prepared statements* e procedures; saída sempre escapada (`htmlspecialchars`).
- **Permissões por cargo**, conferidas no servidor em cada página, API e exportação.
- Usuário desativado pela gerência perde o acesso em até um minuto, mesmo com a sessão aberta.
- Convites de equipe só podem ser gerados por gerentes, expiram e não podem ser reutilizados.

### Perfis de acesso
| Perfil | Acesso |
|---|---|
| Administrador | Tudo, incluindo promover outros administradores. |
| Gerente | Tudo, exceto alterar administradores. |
| Atendente / Caixa | PDV, pedidos, mesas, cozinha, cardápio e clientes. |
| Cozinheiro | Cozinha, pedidos, cardápio (disponibilidade) e estoque. Entra direto na tela da cozinha. |
| Entregador | Pedidos, cardápio e clientes. |

---

## 🛠️ Tecnologias

- **Front-end:** HTML5, CSS3 (design system próprio, sem framework), JavaScript puro, Chart.js 4, Font Awesome 6.
- **Back-end:** Node.js 20+ (funções serverless na Vercel), páginas geradas no servidor, `pg`, `bcryptjs`, ExcelJS, docx e pdf-lib.
- **Banco:** PostgreSQL (Neon) com funções PL/pgSQL, views e dados de demonstração.
- **Hospedagem:** Vercel + Neon.

---

## 🌐 Online

O sistema completo roda na Vercel, sem PHP e sem instalar nada: **https://xtec-restaurantes.vercel.app**. Entre com uma das contas de demonstração abaixo.

## 💻 Como executar localmente

Pré-requisito: Node.js 20 ou superior.

```bash
git clone https://github.com/Leo300609/TCC_2026_Sistema_Restaurantes.git
cd TCC_2026_Sistema_Restaurantes
git checkout tcc_final
npm install
npm run banco:instalar   # cria o banco local (Postgres embutido em .dados-locais/)
npm run banco:demo       # carrega 60 dias de vendas, pedidos, mesas e compras
npm run dev              # http://localhost:3000
```

Para usar um Postgres próprio (Neon, Supabase etc.), defina `DATABASE_URL` antes dos comandos. Em produção defina também `SESSION_SECRET` (texto aleatório longo).

### Contas de demonstração
| E-mail | Perfil | Senha |
|---|---|---|
| admin@tcc.com | Administrador | `Admin@2026` |
| func3@tcc.com | Gerente | `Equipe@2026` |
| func1@tcc.com | Atendente / Caixa | `Equipe@2026` |
| func4@tcc.com | Cozinheiro | `Equipe@2026` |
| func5@tcc.com | Entregador | `Equipe@2026` |

---

## 🗂️ Estrutura de pastas

```text
api/                 funções da Vercel (entrada das rotas dinâmicas)
servidor/            app.js (rotas), sessao.js, bd.js, web.js, layout.js, previsao.js, relatorios.js
  paginas/           uma tela do painel por arquivo
database/postgres/   esquema, views, funções (regras de negócio) e dados de demonstração
public/frontend/     site, login, cadastro, CSS e JavaScript do painel
scripts/             instalação do banco e testes das funções
servidor.js          servidor local
```

## 🧭 Arquitetura

- **Banco:** cada operação é uma função (`sp_pedido_criar`, `sp_compra_finalizar`, `sp_mesa_fechar_conta`…) que valida a regra e devolve mensagem clara com `RAISE EXCEPTION`.
- **Servidor:** a página confere permissão e CSRF, chama `SELECT * FROM sp_...()`, grava a auditoria e redireciona (POST → redirecionar → GET).
- **Sessão:** cookie assinado, `HttpOnly` e `SameSite`, expira por inatividade.

## 📌 Escopo

- **Incluso:** PDV, pedidos, mesas, cozinha, cardápio com ficha técnica, estoque, compras, fornecedores, clientes, fluxo de caixa, relatórios, previsões, auditoria e controle de acesso por perfil.
- **Fora de escopo:** emissão fiscal (NFC-e/SAT), folha de pagamento e aplicativo nativo de delivery.
