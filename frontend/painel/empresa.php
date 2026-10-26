<?php
require __DIR__ . '/_partials/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Empresa · XTEC</title>
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

    <?php $paginaAtual = 'empresa'; include __DIR__ . '/_partials/sidebar.php'; ?>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1>Empresa</h1>
            <p>Dados do estabelecimento e da equipe</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <div class="empresa-grid">

          <form class="empresa-formularios" id="form-empresa" action="#" method="post">

            <section class="painel-card" aria-labelledby="titulo-dados">
              <div class="painel-header">
                <h2 id="titulo-dados">Dados do estabelecimento</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome_fantasia">Nome fantasia</label>
                  <input type="text" id="nome_fantasia" name="nome_fantasia" class="input-dash" maxlength="150" required>
                </div>
                <div class="grupo-input">
                  <label for="razao_social">Razão social</label>
                  <input type="text" id="razao_social" name="razao_social" class="input-dash">
                </div>
                <div class="grupo-input">
                  <label for="cnpj">CNPJ</label>
                  <input type="text" id="cnpj" name="cnpj" class="input-dash" maxlength="18" data-mascara="cnpj" inputmode="numeric">
                </div>
                <div class="grupo-input">
                  <label for="telefone">Telefone</label>
                  <input type="tel" id="telefone" name="telefone" class="input-dash" maxlength="15" data-mascara="telefone">
                </div>
                <div class="grupo-input campo-largo">
                  <label for="email_contato">E-mail de contato</label>
                  <input type="email" id="email_contato" name="email_contato" class="input-dash">
                </div>
              </div>
            </section>

            <section class="painel-card" aria-labelledby="titulo-endereco">
              <div class="painel-header">
                <h2 id="titulo-endereco">Endereço</h2>
              </div>
              <div class="campos">
                <div class="grupo-input campo-largo">
                  <label for="endereco">Rua e bairro</label>
                  <input type="text" id="endereco" name="endereco" class="input-dash">
                </div>
                <div class="grupo-input">
                  <label for="endereco_numero">Número</label>
                  <input type="text" id="endereco_numero" name="endereco_numero" class="input-dash">
                </div>
                <div class="grupo-input">
                  <label for="estado">Estado</label>
                  <select id="estado" name="estado" class="input-dash"></select>
                </div>
                <div class="grupo-input campo-largo">
                  <label for="cidade">Cidade</label>
                  <input type="text" id="cidade" name="cidade" class="input-dash">
                </div>
              </div>
            </section>

            <section class="painel-card" aria-labelledby="titulo-horario">
              <div class="painel-header">
                <h2 id="titulo-horario">Horário de funcionamento</h2>
              </div>
              <ul class="horarios" id="horarios"></ul>
            </section>

            <p class="painel-nota" id="aviso-leitura" hidden>Somente administradores e gerentes podem alterar os dados da empresa.</p>
            <div class="barra-salvar" id="barra-salvar">
              <button type="button" class="btn btn-fantasma" id="btn-descartar">Descartar alterações</button>
              <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
            </div>
          </form>

          <section class="painel-card equipe" aria-labelledby="titulo-equipe">
            <div class="painel-header">
              <h2 id="titulo-equipe">Equipe</h2>
              <span class="painel-meta" id="equipe-total">–</span>
            </div>
            <ul class="membros" id="membros"><li class="carregando">Carregando equipe...</li></ul>
            <?php if ($verFinanceiro): ?>
            <a href="dashboard.php#convidar" class="btn btn-pequeno equipe-convidar"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Convidar funcionário</a>
            <?php endif; ?>
          </section>

        </div>

      </div>
    </main>
  </div>
  <script src="../js/painel/comum.js"></script>
  <script src="../js/painel/empresa.js"></script>
</body>

</html>
