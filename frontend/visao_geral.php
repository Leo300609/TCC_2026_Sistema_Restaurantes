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
  <title>XTEC - Painel Administrativo</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/dashboard.css"> <!-- Mantido seu caminho original -->
  <link rel="stylesheet" href="css/visao_geral.css">

  <!-- NOVO: Biblioteca de Gráficos -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

  <div class="dashboard-layout">

    <!-- SIDEBAR FIXA -->
    <aside class="sidebar">
      <div>
        <div class="sidebar-header">
          <h2 class="nome-empresa">XTEC</h2>
        </div>
        <nav class="sidebar-nav">
          <a href="visao_geral" class="nav-item active">
            <i class="fa-solid fa-chart-line"></i>
            <span>Visão Geral</span>
          </a>
          <!-- NOVO: Menu condicional -->
          <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
            <a href="fluxo_de_caixa.php" class="nav-item">
              <i class="fa-solid fa-wallet"></i>
              <span>Fluxo de Caixa</span>
            </a>
          <?php endif; ?>

          <?php if ($verEstoque || $nivel === 'ADMIN'): ?>
            <a href="estoque.php" class="nav-item">
              <i class="fa-solid fa-boxes-stacked"></i>
              <span>Estoque</span>
            </a>
          <?php endif; ?>

          <a href="pedidos.php" class="nav-item">
            <i class="fa-solid fa-receipt"></i>
            <span>Pedidos</span>
          </a>
          <a href="cardapios.php" class="nav-item">
            <i class="fa-solid fa-utensils"></i>
            <span>Cardápio</span>
          </a>
          <a href="clientes.php" class="nav-item">
            <i class="fa-solid fa-users"></i>
            <span>Clientes</span>
          </a>

          <a href="empresa.php" class="nav-item">
            <i class="fa-solid fa-city"></i>
            <span>Empresa</span>
          </a>

          <a href="configs.php" class="nav-item">
            <i class="fa-solid fa-gear"></i>
            <span>Configurações</span>
          </a>
        </nav>
      </div>

      <div class="sidebar-footer">
        <!-- NOVO: Exibe o nome e cargo de quem está logado -->
        <span style="font-size:12px; color:var(--creme); opacity:0.7; margin-bottom:8px; display:block; padding: 0 10px;">
          <?= $nomeUsuario ?> <br> (<?= htmlspecialchars($cargo) ?>)
        </span>
        <a href="../backend/logout.php" class="btn-sair">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span>Sair</span>
        </a>
      </div>
    </aside>

    <!-- ÁREA PRINCIPAL COM ROLAGEM INDEPENDENTE -->
    <main class="main-content">

      <header class="topbar">
        <div class="boas-vindas">
          <h1>Visão Geral</h1>
          <p>Operação em tempo real</p>
        </div>
        <div class="usuario-perfil">
          <span class="status-loja online"><i class="fa-solid fa-circle"></i> Loja Aberta</span>
          <!-- NOVO: Nome dinâmico no topo -->
          <span class="nome-admin"><?= $nomeUsuario ?></span>
        </div>
      </header>

      <!-- NOVO: BOTÕES DE EXPORTAÇÃO (Apenas Admin/Gerente) -->
      <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
        <section class="export-actions">
          <a href="../backend/relatorios/gerar_pdf.php" class="btn-export pdf"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</a>
          <a href="../backend/relatorios/gerar_word.php" class="btn-export word"><i class="fa-solid fa-file-word"></i> Exportar Word</a>
          <a href="../backend/relatorios/gerar_excel.php" class="btn-export excel"><i class="fa-solid fa-file-excel"></i> Exportar Excel</a>
        </section>
      <?php endif; ?>

     <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
      <section class="grid-cards">
        <div class="card-metrica">
          <span class="card-titulo">Faturamento Hoje</span>
          <div class="card-valor" id="fat-hoje">R$ 1.840,50</div>
          <span class="card-detalhe positivo">+12% em relação a ontem</span>
        </div>
        <?php endif; ?>

        <div class="card-metrica">
          <span class="card-titulo">Pedidos Realizados</span>
          <div class="card-valor" id="pedidos-hoje">48</div>
          <span class="card-detalhe positivo">+5 na última hora</span>
        </div>
        <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
          <div class="card-metrica">
            <span class="card-titulo">Ticket Médio</span>
            <div class="card-valor">R$ 38,34</div>
            <span class="card-detalhe neutro">Dentro da média</span>
          </div>
        <?php endif; ?> 

        <?php if ($verEstoque): ?>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Alertas de Estoque</span>
            <div class="card-valor" id="alertas-estoque">0</div>
            <span class="card-detalhe alerta">Itens críticos ou vencendo</span>
          </div>
        <?php else: ?>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Em Entrega</span>
            <div class="card-valor">6</div>
            <span class="card-detalhe alerta">Aguardando confirmação</span>
          </div>
        <?php endif; ?>
      </section>


      <div class="dashboard-secoes">
        <section class="painel-card">
          <div class="painel-header">
            <h2>Últimos Pedidos</h2>
            <a href="#" class="btn-link">Ver todos</a>
          </div>
          <div class="tabela-container">
            <table class="tabela-pedidos">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Cliente</th>
                  <th>Itens</th>
                  <th>Total</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="id-pedido">#1048</td>
                  <td class="cliente-nome">Lucas Andrade</td>
                  <td>2x X-Tudo, 1x Coca 2L</td>
                  <td class="valor-pedido">R$ 68,00</td>
                  <td><span class="status em-preparo">Em preparo</span></td>
                </tr>
                <tr>
                  <td class="id-pedido">#1047</td>
                  <td class="cliente-nome">Mariana Costa</td>
                  <td>1x Combo Artesanal</td>
                  <td class="valor-pedido">R$ 42,50</td>
                  <td><span class="status a-caminho">A caminho</span></td>
                </tr>
                <tr>
                  <td class="id-pedido">#1046</td>
                  <td class="cliente-nome">Roberto Silva</td>
                  <td>3x Misto Quente</td>
                  <td class="valor-pedido">R$ 31,00</td>
                  <td><span class="status entregue">Entregue</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      
      <?php if ($verFinanceiro || $nivel === 'ADMIN'): ?>
        <section class="painel-card">
          <div class="painel-header">
            <h2><i class="fa-solid fa-chart-line"></i> Evolução de Pedidos (7 dias)</h2>
          </div>
          <div class="chart-container" style="height: 300px;">
            <canvas id="graficoLinha"></canvas>
          </div>
        </section>
      </div>
    <?php endif; ?>
        
    </main>
  </div>
</body>

</html>