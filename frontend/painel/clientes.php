<?php
require __DIR__ . '/_partials/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Clientes · XTEC</title>
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

    <?php $paginaAtual = 'clientes'; include __DIR__ . '/_partials/sidebar.php'; ?>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1>Clientes</h1>
            <p>Quem compra com você e quanto já consumiu</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar cliente</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" id="busca" class="input-dash" name="busca" placeholder="Buscar por nome, CPF/CNPJ ou telefone" autocomplete="off">
            </label>
            <div class="filtros" id="filtros" role="group" aria-label="Situação do cliente"></div>
          </div>
          <button type="button" class="btn btn-primario" id="btn-novo"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Novo cliente</button>
        </div>

        <section class="painel-card painel-tabela" aria-label="Lista de clientes">
          <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de clientes, role para os lados se necessário" aria-live="polite"></div>
          <nav class="paginacao" id="paginacao" aria-label="Paginação" hidden></nav>
        </section>

      </div>
    </main>
  </div>
  <script src="../js/painel/comum.js"></script>
  <script src="../js/painel/clientes.js"></script>
</body>

</html>
