<?php
// ==========================================
// 1. LÓGICA DE SESSÃO E PERMISSÃO (NOVO)
// ==========================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
  header("Location: login.html");
  exit;
}

$cargo = $_SESSION['usuario_cargo'] ?? 'FUNCIONARIO';
$nivel = $_SESSION['usuario_nivel'] ?? 'FUNCIONARIO';
$nomeUsuario = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário');

// Define o que cada um pode ver
$verFinanceiro = ($nivel === 'ADMIN' || $cargo === 'Gerente');
$verEstoque = ($nivel === 'ADMIN' || $cargo === 'Gerente' || $cargo === 'Cozinheiro');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Estoque · XTEC</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="stylesheet" href="css/modulos.css">
</head>

<body>
  <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>

  <div class="dashboard-layout">

    <aside class="sidebar">
      <div>
        <div class="sidebar-header">
          <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
          <h2 class="nome-empresa">XTEC</h2>
        </div>
        <nav class="sidebar-nav" aria-label="Navegação principal">
          <a href="dashboard.php" class="nav-item">
            <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
            <span>Visão Geral</span>
          </a>
          <?php if ($verFinanceiro): ?>
            <a href="fluxo_de_caixa.php" class="nav-item">
              <i class="fa-solid fa-wallet" aria-hidden="true"></i>
              <span>Fluxo de Caixa</span>
            </a>
          <?php endif; ?>

          <?php if ($verEstoque): ?>
            <a href="estoque.php" class="nav-item active" aria-current="page">
              <i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i>
              <span>Estoque</span>
            </a>
          <?php endif; ?>

          <a href="pedidos.php" class="nav-item">
            <i class="fa-solid fa-receipt" aria-hidden="true"></i>
            <span>Pedidos</span>
          </a>
          <a href="cardapios.php" class="nav-item">
            <i class="fa-solid fa-utensils" aria-hidden="true"></i>
            <span>Cardápio</span>
          </a>
          <a href="clientes.php" class="nav-item">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            <span>Clientes</span>
          </a>

          <a href="empresa.php" class="nav-item">
            <i class="fa-solid fa-city" aria-hidden="true"></i>
            <span>Empresa</span>
          </a>

          <a href="configs.php" class="nav-item">
            <i class="fa-solid fa-gear" aria-hidden="true"></i>
            <span>Configurações</span>
          </a>
        </nav>
      </div>

      <div class="sidebar-footer">
        <div class="usuario-chip">
          <span class="usuario-avatar" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
          <span class="usuario-dados">
            <strong><?= $nomeUsuario ?></strong>
            <small><?= htmlspecialchars($cargo) ?></small>
          </span>
        </div>
        <a href="../backend/logout.php" class="btn-sair">
          <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
          <span>Sair</span>
        </a>
      </div>
    </aside>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1>Estoque</h1>
            <p>Insumos, validade e níveis mínimos</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <section class="grid-cards" aria-label="Resumo do estoque">
          <div class="card-metrica">
            <span class="card-titulo">Itens cadastrados</span>
            <div class="card-valor">86</div>
            <span class="card-detalhe neutro">em 9 categorias</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Abaixo do mínimo</span>
            <div class="card-valor">1</div>
            <span class="card-detalhe alerta">Repor com urgência</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Vencem em 3 dias</span>
            <div class="card-valor">2</div>
            <span class="card-detalhe alerta">Use primeiro</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Valor em estoque</span>
            <div class="card-valor">R$ 12.480</div>
            <span class="card-detalhe neutro">a preço de custo</span>
          </div>
        </section>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar produto</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" class="input-dash" name="busca" placeholder="Buscar por nome ou código de barras">
            </label>
            <div class="filtros" role="group" aria-label="Situação do estoque">
              <button type="button" class="filtro ativo" aria-pressed="true">Todos <span>86</span></button>
              <button type="button" class="filtro" aria-pressed="false">Críticos <span>1</span></button>
              <button type="button" class="filtro" aria-pressed="false">Vencendo <span>2</span></button>
              <button type="button" class="filtro" aria-pressed="false">Em dia <span>83</span></button>
            </div>
          </div>
          <div class="toolbar-grupo">
            <button type="button" class="btn"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Registrar saída</button>
            <button type="button" class="btn btn-primario"><i class="fa-solid fa-download" aria-hidden="true"></i> Registrar entrada</button>
          </div>
        </div>

        <div class="estoque-grid">
          <section class="painel-card painel-tabela" aria-label="Produtos em estoque">
            <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de produtos, role para os lados se necessário">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Produto</th>
                    <th scope="col">Em estoque</th>
                    <th scope="col" class="num">Mínimo</th>
                    <th scope="col">Validade</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><span class="celula-dupla"><strong>Queijo mussarela</strong><small>Frios</small></span></td>
                    <td>
                      <div class="nivel critico" style="--nivel: 20%">
                        <span class="nivel-valor">2 kg</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">5 kg</td>
                    <td class="data">28/10/2026</td>
                    <td><span class="status critico">Estoque baixo</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno">Repor<span class="sr-only"> Queijo mussarela</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Molho de tomate</strong><small>Molhos</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 75%">
                        <span class="nivel-valor">6 un</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">4 un</td>
                    <td class="data">21/10/2026</td>
                    <td><span class="status baixo">Vence em 2 dias</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Molho de tomate</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Pão brioche</strong><small>Padaria</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 75%">
                        <span class="nivel-valor">30 un</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">20 un</td>
                    <td class="data">22/10/2026</td>
                    <td><span class="status baixo">Vence em 3 dias</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Pão brioche</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Carne para hambúrguer 180 g</strong><small>Carnes</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 80%">
                        <span class="nivel-valor">48 un</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">30 un</td>
                    <td class="data">05/11/2026</td>
                    <td><span class="status ok">Em dia</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Carne para hambúrguer 180 g</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Batata congelada</strong><small>Congelados</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 100%">
                        <span class="nivel-valor">22 kg</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">10 kg</td>
                    <td class="data">14/01/2027</td>
                    <td><span class="status ok">Em dia</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Batata congelada</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Coca-Cola 2L</strong><small>Bebidas</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 75%">
                        <span class="nivel-valor">36 un</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">24 un</td>
                    <td class="data">Sem validade</td>
                    <td><span class="status ok">Em dia</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Coca-Cola 2L</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Alface americana</strong><small>Hortifrúti</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 67%">
                        <span class="nivel-valor">8 un</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">6 un</td>
                    <td class="data">24/10/2026</td>
                    <td><span class="status ok">Em dia</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Alface americana</span></button></td>
                  </tr>
                  <tr>
                    <td><span class="celula-dupla"><strong>Óleo de soja</strong><small>Mercearia</small></span></td>
                    <td>
                      <div class="nivel" style="--nivel: 75%">
                        <span class="nivel-valor">12 L</span>
                        <span class="nivel-barra" aria-hidden="true"><span></span></span>
                      </div>
                    </td>
                    <td class="num">8 L</td>
                    <td class="data">30/03/2027</td>
                    <td><span class="status ok">Em dia</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma">Detalhes<span class="sr-only"> Óleo de soja</span></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="painel-card">
            <div class="painel-header">
              <h2>Movimentações de hoje</h2>
            </div>
            <ul class="movimentos">
              <li class="movimento">
                <span class="movimento-qtd saida">−12 un</span>
                <span class="celula-dupla"><strong>Carne para hambúrguer 180 g</strong><small>Paulo Lima · 19:10 · Produção do jantar</small></span>
              </li>
              <li class="movimento">
                <span class="movimento-qtd entrada">+36 un</span>
                <span class="celula-dupla"><strong>Coca-Cola 2L</strong><small>Marina Souza · 16:42 · Compra #212</small></span>
              </li>
              <li class="movimento">
                <span class="movimento-qtd saida">−3 kg</span>
                <span class="celula-dupla"><strong>Queijo mussarela</strong><small>Paulo Lima · 15:30</small></span>
              </li>
              <li class="movimento">
                <span class="movimento-qtd entrada">+20 kg</span>
                <span class="celula-dupla"><strong>Batata congelada</strong><small>Marina Souza · 11:05 · Compra #211</small></span>
              </li>
              <li class="movimento">
                <span class="movimento-qtd saida">−5 un</span>
                <span class="celula-dupla"><strong>Alface americana</strong><small>Paulo Lima · 10:20</small></span>
              </li>
            </ul>
          </section>
        </div>

      </div>
    </main>
  </div>
</body>

</html>
