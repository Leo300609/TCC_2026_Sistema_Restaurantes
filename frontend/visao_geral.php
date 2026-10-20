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
  <title>Visão Geral · XTEC</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="stylesheet" href="css/visao_geral.css">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
          <a href="dashboard.php" class="nav-item active" aria-current="page">
            <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
            <span>Visão Geral</span>
          </a>
          <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
            <a href="fluxo_de_caixa.php" class="nav-item">
              <i class="fa-solid fa-wallet" aria-hidden="true"></i>
              <span>Fluxo de Caixa</span>
            </a>
          <?php endif; ?>

          <?php if ($verEstoque || $nivel === 'ADMIN'): ?>
            <a href="estoque.php" class="nav-item">
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
            <h1>Visão Geral</h1>
            <p>Operação em tempo real</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
          <section class="export-actions" aria-label="Exportar relatórios">
            <a href="../backend/relatorios/gerar_pdf.php" class="btn-export pdf"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Exportar PDF</a>
            <a href="../backend/relatorios/gerar_word.php" class="btn-export word"><i class="fa-solid fa-file-word" aria-hidden="true"></i> Exportar Word</a>
            <a href="../backend/relatorios/gerar_excel.php" class="btn-export excel"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Exportar Excel</a>
          </section>
        <?php endif; ?>

        <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
        <section class="grid-cards" aria-label="Indicadores do dia">
          <div class="card-metrica">
            <span class="card-titulo">Faturamento hoje</span>
            <div class="card-valor" id="fat-hoje">R$ 1.840,50</div>
            <span class="card-detalhe positivo">+12% em relação a ontem</span>
          </div>
        <?php endif; ?>

          <div class="card-metrica">
            <span class="card-titulo">Pedidos realizados</span>
            <div class="card-valor" id="pedidos-hoje">48</div>
            <span class="card-detalhe positivo">+5 na última hora</span>
          </div>
          <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
            <div class="card-metrica">
              <span class="card-titulo">Ticket médio</span>
              <div class="card-valor">R$ 38,34</div>
              <span class="card-detalhe neutro">Dentro da média</span>
            </div>
          <?php endif; ?>

          <?php if ($verEstoque): ?>
            <div class="card-metrica alerta-box">
              <span class="card-titulo">Alertas de estoque</span>
              <div class="card-valor" id="alertas-estoque">0</div>
              <span class="card-detalhe alerta">Itens críticos ou vencendo</span>
            </div>
          <?php else: ?>
            <div class="card-metrica alerta-box">
              <span class="card-titulo">Em entrega</span>
              <div class="card-valor">6</div>
              <span class="card-detalhe alerta">Aguardando confirmação</span>
            </div>
          <?php endif; ?>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card">
            <div class="painel-header">
              <h2>Últimos pedidos</h2>
              <a href="pedidos.php" class="btn-link">Ver todos</a>
            </div>
            <div class="tabela-container">
              <table class="tabela-pedidos">
                <thead>
                  <tr>
                    <th scope="col">Pedido</th>
                    <th scope="col">Cliente</th>
                    <th scope="col">Itens</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="id-pedido">#1048</td>
                    <td class="cliente-nome">Lucas Andrade</td>
                    <td>2x X-Tudo, 1x Coca 2L</td>
                    <td class="num valor-pedido">R$ 68,00</td>
                    <td><span class="status em-preparo">Em preparo</span></td>
                  </tr>
                  <tr>
                    <td class="id-pedido">#1047</td>
                    <td class="cliente-nome">Mariana Costa</td>
                    <td>1x Combo Artesanal</td>
                    <td class="num valor-pedido">R$ 42,50</td>
                    <td><span class="status a-caminho">A caminho</span></td>
                  </tr>
                  <tr>
                    <td class="id-pedido">#1046</td>
                    <td class="cliente-nome">Roberto Silva</td>
                    <td>3x Misto Quente</td>
                    <td class="num valor-pedido">R$ 31,50</td>
                    <td><span class="status entregue">Entregue</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
          <section class="painel-card">
            <div class="painel-header">
              <h2>Evolução de pedidos</h2>
              <span class="painel-meta">Últimos 7 dias</span>
            </div>
            <div class="chart-container alto">
              <canvas id="graficoLinha" role="img" aria-label="Gráfico de linha com os pedidos dos últimos 7 dias"></canvas>
            </div>
          </section>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</body>

</html>