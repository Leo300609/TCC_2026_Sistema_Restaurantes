# 🍽️ XTEC · Sistema de Gestão e Automação Comercial para Restaurantes

![Status](https://img.shields.io/badge/Status-TCC%20final-brightgreen)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-procedures-00758f)
![Licença](https://img.shields.io/badge/Licença-MIT-blue)

> Plataforma web que substitui comandas de papel e planilhas por um fluxo digital único: do pedido no salão ou no balcão até a cozinha, o caixa, o estoque e o resultado financeiro do mês.

---

## 📋 Sobre o projeto

O XTEC centraliza a operação de um restaurante em um painel que se atualiza enquanto o salão funciona. Cada venda dá baixa automática nos insumos da ficha técnica, cada compra alimenta o estoque e as contas a pagar, e tudo aparece no fluxo de caixa e nos relatórios gerenciais.

Toda a regra de negócio fica no banco, em **functions, views e stored procedures**. O PHP valida o formato da entrada, confere a permissão do usuário e chama `CALL sp_...()` com *prepared statements*.

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
- **Back-end:** PHP 8.1+ (mysqli), Composer com Dompdf, PhpSpreadsheet e PhpWord.
- **Banco:** MySQL 8 / MariaDB 10.6+ com functions, views e stored procedures.
- **Ambiente:** WampServer (Apache + PHP + MariaDB).

---

## 💻 Como executar

### Pré-requisitos
- WampServer (ou XAMPP) com PHP 8.1+ e MySQL/MariaDB.
- Git.

### Passo a passo
1. **Clone o repositório** dentro da pasta `www` do WampServer:
   ```bash
   git clone https://github.com/Leo300609/TCC_2026_Sistema_Restaurantes.git
   ```
2. **Ligue o WampServer** (ícone verde).
3. **Instale o banco** abrindo no navegador:
   `http://localhost/TCC_2026_Sistema_Restaurantes/instalar.php`

   O instalador confere o ambiente, cria o banco `tcc` com todas as tabelas e procedures e, se você quiser, carrega os **dados de demonstração** (60 dias de vendas, pedidos em andamento, mesas ocupadas, compras e despesas). Depois ele se trava; para reinstalar, apague `backend/config/instalado.lock`.
4. **Acesse o sistema:** `http://localhost/TCC_2026_Sistema_Restaurantes/`

> O banco usa por padrão o MariaDB do WampServer na **porta 3307**, usuário `root` sem senha. Para mudar, crie `backend/config/config.local.php`:
> ```php
> <?php return ['db_porta' => 3306, 'db_senha' => 'minha-senha'];
> ```

### Instalação manual (sem o instalador)
Execute os scripts de `database/` nesta ordem, por exemplo no phpMyAdmin:
1. `bancofeitonamoraltmjamovcs.sql`
2. `migracao_dashboard.sql`
3. `migracao_crud.sql`
4. `migracao_modulos.sql`
5. `procedures_crud.sql`
6. `procedures_modulos.sql`
7. `dados_demonstracao.sql` *(opcional)*

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
instalar.php            instalador do banco (só funciona em localhost)
backend/
  api/                  JSON do painel: notificações e busca global
  auth/                 login, logout e cadastro com convite
  config/               config.php (porta, fuso, sessão) e conexão
  lib/                  bd.php (procedures), web.php (CSRF, filtros, formatação), sessao.php (sessão, permissões, auditoria)
  modulos/              regras compartilhadas entre telas (categorias)
  relatorios/           exportação em PDF, Excel, Word e CSV
database/               scripts SQL (estrutura, migrações, procedures e demonstração)
frontend/
  html/                 páginas públicas (site, login, cadastro)
  painel/               telas do sistema em PHP e _partials/ (cabeçalho, menu, rodapé)
  css/                  painel/app.css (design system) e publico/
  js/                   painel/app.js (comportamento comum), painel/pdv.js e publico/
  assets/               imagens e ícones
vendor/                 dependências do Composer
```

---

## 🧭 Arquitetura em uma frase por camada

- **Banco:** cada operação é uma procedure (`sp_pedido_criar`, `sp_compra_finalizar`, `sp_mesa_fechar_conta`…) que valida a regra e devolve uma mensagem clara com `SIGNAL SQLSTATE '45000'` quando algo não pode ser feito.
- **PHP:** `tratar_post()` confere o CSRF, executa a ação, grava o aviso, registra a auditoria e redireciona; `ler()` busca os dados da tela.
- **Telas:** HTML gerado no servidor; formulários abrem em gavetas laterais e confirmações em janelas modais, sem depender de JavaScript.

---

## 📌 Escopo

- **Incluso:** PDV, pedidos, mesas, cozinha, cardápio com ficha técnica, estoque, compras, fornecedores, clientes, fluxo de caixa, relatórios, auditoria e controle de acesso por perfil.
- **Fora de escopo:** emissão fiscal (NFC-e/SAT), folha de pagamento e aplicativo nativo de delivery.
