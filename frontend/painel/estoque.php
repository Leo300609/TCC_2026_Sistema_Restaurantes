<?php
require __DIR__ . '/_partials/auth.php';

if (!$verEstoque) {
  header("Location: dashboard.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Estoque · XTEC</title>
  <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../css/painel/dashboard.css">
  <link rel="stylesheet" href="../css/painel/modulos.css">
</head>

<body>
  <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>

  <div class="dashboard-layout">

    <?php $paginaAtual = 'estoque'; include __DIR__ . '/_partials/sidebar.php'; ?>

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
            <span class="card-titulo">Itens ativos</span>
            <div class="card-valor" id="res-itens">–</div>
            <span class="card-detalhe neutro" id="res-categorias">&nbsp;</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Abaixo do mínimo</span>
            <div class="card-valor" id="res-minimo">–</div>
            <span class="card-detalhe alerta">Repor com urgência</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo" id="res-vencendo-rotulo">Vencimento próximo</span>
            <div class="card-valor" id="res-vencendo">–</div>
            <span class="card-detalhe alerta">Use primeiro</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Valor em estoque</span>
            <div class="card-valor" id="res-valor">–</div>
            <span class="card-detalhe neutro">quantidade × preço unitário</span>
          </div>
        </section>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar produto</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" id="busca" class="input-dash" name="busca" placeholder="Buscar por nome ou código de barras" autocomplete="off">
            </label>
            <div class="filtros" id="filtros" role="group" aria-label="Situação do estoque"></div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="categoria">Categoria</label>
              <select id="categoria" class="input-dash" name="categoria"><option value="">Todas as categorias</option></select>
            </div>
          </div>
          <div class="toolbar-grupo">
            <button type="button" class="btn" id="btn-categorias"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</button>
            <button type="button" class="btn" id="btn-saida"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Registrar saída</button>
            <button type="button" class="btn" id="btn-entrada"><i class="fa-solid fa-download" aria-hidden="true"></i> Registrar entrada</button>
            <button type="button" class="btn btn-primario" id="btn-novo"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo item</button>
          </div>
        </div>

        <div class="estoque-grid">
          <section class="painel-card painel-tabela" aria-label="Produtos em estoque">
            <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de produtos, role para os lados se necessário" aria-live="polite"></div>
            <nav class="paginacao" id="paginacao" aria-label="Paginação" hidden></nav>
          </section>

          <section class="painel-card">
            <div class="painel-header">
              <h2>Movimentações de hoje</h2>
            </div>
            <ul class="movimentos" id="movimentos"></ul>
          </section>
        </div>

      </div>
    </main>
  </div>
  <script src="../js/painel/comum.js"></script>
  <script src="../js/painel/estoque.js"></script>
</body>

</html>
