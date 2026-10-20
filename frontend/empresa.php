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
  <title>Empresa · XTEC</title>
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

          <a href="empresa.php" class="nav-item active" aria-current="page">
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
            <h1>Empresa</h1>
            <p>Dados do estabelecimento e da equipe</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <div class="empresa-grid">

          <form class="empresa-formularios" action="#" method="post">

            <section class="painel-card" aria-labelledby="titulo-dados">
              <div class="painel-header">
                <h2 id="titulo-dados">Dados do estabelecimento</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome_fantasia">Nome fantasia</label>
                  <input type="text" id="nome_fantasia" name="nome_fantasia" class="input-dash" value="Brasa &amp; Chapa Hamburgueria">
                </div>
                <div class="grupo-input">
                  <label for="razao_social">Razão social</label>
                  <input type="text" id="razao_social" name="razao_social" class="input-dash" value="Brasa e Chapa Alimentos Ltda">
                </div>
                <div class="grupo-input">
                  <label for="cnpj">CNPJ</label>
                  <input type="text" id="cnpj" name="cnpj" class="input-dash" value="12.345.678/0001-90" inputmode="numeric">
                </div>
                <div class="grupo-input">
                  <label for="telefone">Telefone</label>
                  <input type="tel" id="telefone" name="telefone" class="input-dash" value="(11) 4002-8922">
                </div>
                <div class="grupo-input campo-largo">
                  <label for="email_contato">E-mail de contato</label>
                  <input type="email" id="email_contato" name="email_contato" class="input-dash" value="contato@brasaechapa.com.br">
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
                  <input type="text" id="endereco" name="endereco" class="input-dash" value="Rua das Acácias, Vila Madalena">
                </div>
                <div class="grupo-input">
                  <label for="endereco_numero">Número</label>
                  <input type="text" id="endereco_numero" name="endereco_numero" class="input-dash" value="218">
                </div>
                <div class="grupo-input">
                  <label for="estado">Estado</label>
                  <select id="estado" name="estado" class="input-dash">
                    <option value="SP" selected>São Paulo</option>
                    <option value="RJ">Rio de Janeiro</option>
                    <option value="MG">Minas Gerais</option>
                  </select>
                </div>
                <div class="grupo-input campo-largo">
                  <label for="cidade">Cidade</label>
                  <input type="text" id="cidade" name="cidade" class="input-dash" value="São Paulo">
                </div>
              </div>
            </section>

            <section class="painel-card" aria-labelledby="titulo-horario">
              <div class="painel-header">
                <h2 id="titulo-horario">Horário de funcionamento</h2>
              </div>
              <ul class="horarios">
                <li class="horario fechado">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_seg"><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Segunda</span>
                  </label>
                  <span class="horario-status">Fechado</span>
                </li>
                <li class="horario">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_ter" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Terça</span>
                  </label>
                  <span class="horario-faixa">
                    <input type="time" class="input-dash" name="abre_ter" value="18:00" aria-label="Terça, abre às">
                    <span aria-hidden="true">às</span>
                    <input type="time" class="input-dash" name="fecha_ter" value="23:00" aria-label="Terça, fecha às">
                  </span>
                </li>
                <li class="horario">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_qua" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Quarta</span>
                  </label>
                  <span class="horario-faixa">
                    <input type="time" class="input-dash" name="abre_qua" value="18:00" aria-label="Quarta, abre às">
                    <span aria-hidden="true">às</span>
                    <input type="time" class="input-dash" name="fecha_qua" value="23:00" aria-label="Quarta, fecha às">
                  </span>
                </li>
                <li class="horario">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_qui" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Quinta</span>
                  </label>
                  <span class="horario-faixa">
                    <input type="time" class="input-dash" name="abre_qui" value="18:00" aria-label="Quinta, abre às">
                    <span aria-hidden="true">às</span>
                    <input type="time" class="input-dash" name="fecha_qui" value="23:00" aria-label="Quinta, fecha às">
                  </span>
                </li>
                <li class="horario">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_sex" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Sexta</span>
                  </label>
                  <span class="horario-faixa">
                    <input type="time" class="input-dash" name="abre_sex" value="18:00" aria-label="Sexta, abre às">
                    <span aria-hidden="true">às</span>
                    <input type="time" class="input-dash" name="fecha_sex" value="23:59" aria-label="Sexta, fecha às">
                  </span>
                </li>
                <li class="horario">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_sab" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Sábado</span>
                  </label>
                  <span class="horario-faixa">
                    <input type="time" class="input-dash" name="abre_sab" value="12:00" aria-label="Sábado, abre às">
                    <span aria-hidden="true">às</span>
                    <input type="time" class="input-dash" name="fecha_sab" value="23:59" aria-label="Sábado, fecha às">
                  </span>
                </li>
                <li class="horario">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="aberto_dom" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span class="horario-dia">Domingo</span>
                  </label>
                  <span class="horario-faixa">
                    <input type="time" class="input-dash" name="abre_dom" value="12:00" aria-label="Domingo, abre às">
                    <span aria-hidden="true">às</span>
                    <input type="time" class="input-dash" name="fecha_dom" value="22:00" aria-label="Domingo, fecha às">
                  </span>
                </li>
              </ul>
            </section>

            <div class="barra-salvar">
              <button type="button" class="btn btn-fantasma">Descartar alterações</button>
              <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
            </div>
          </form>

          <section class="painel-card equipe" aria-labelledby="titulo-equipe">
            <div class="painel-header">
              <h2 id="titulo-equipe">Equipe</h2>
              <span class="painel-meta">4 pessoas</span>
            </div>
            <ul class="membros">
              <li class="membro">
                <span class="avatar" aria-hidden="true">LC</span>
                <span class="celula-dupla"><strong>Leonardo Canto</strong><small>Gerente · você</small></span>
                <span class="presenca online">Online</span>
              </li>
              <li class="membro">
                <span class="avatar" aria-hidden="true">MS</span>
                <span class="celula-dupla"><strong>Marina Souza</strong><small>Atendente / Caixa</small></span>
                <span class="presenca online">Online</span>
              </li>
              <li class="membro">
                <span class="avatar" aria-hidden="true">PL</span>
                <span class="celula-dupla"><strong>Paulo Lima</strong><small>Cozinheiro</small></span>
                <span class="presenca online">Online</span>
              </li>
              <li class="membro">
                <span class="avatar" aria-hidden="true">RT</span>
                <span class="celula-dupla"><strong>Rafael Teixeira</strong><small>Entregador</small></span>
                <span class="presenca">Ontem</span>
              </li>
            </ul>
            <a href="dashboard.php#convidar" class="btn btn-pequeno equipe-convidar"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Convidar funcionário</a>
          </section>

        </div>

      </div>
    </main>
  </div>
</body>

</html>
