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
  <title>Clientes · XTEC</title>
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
          <a href="clientes.php" class="nav-item active" aria-current="page">
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
              <input type="search" class="input-dash" name="busca" placeholder="Buscar por nome, CPF/CNPJ ou telefone">
            </label>
            <div class="filtros" role="group" aria-label="Situação do cliente">
              <button type="button" class="filtro ativo" aria-pressed="true">Todos <span>128</span></button>
              <button type="button" class="filtro" aria-pressed="false">Ativos <span>119</span></button>
              <button type="button" class="filtro" aria-pressed="false">Inativos <span>9</span></button>
            </div>
          </div>
          <button type="button" class="btn btn-primario"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Novo cliente</button>
        </div>

        <section class="painel-card painel-tabela" aria-label="Lista de clientes">
          <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de clientes, role para os lados se necessário">
            <table class="tabela">
              <thead>
                <tr>
                  <th scope="col">Cliente</th>
                  <th scope="col">Telefone</th>
                  <th scope="col">Cidade</th>
                  <th scope="col" class="num">Pedidos</th>
                  <th scope="col" class="num">Total gasto</th>
                  <th scope="col">Situação</th>
                  <th scope="col"><span class="sr-only">Ações</span></th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">LA</span>
                      <span class="celula-dupla"><strong>Lucas Andrade</strong><small>CPF 321.654.987-00</small></span>
                    </div>
                  </td>
                  <td>(11) 98765-4321</td>
                  <td>São Paulo, SP</td>
                  <td class="num">18</td>
                  <td class="num">R$ 1.126,40</td>
                  <td><span class="status ativo">Ativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Lucas Andrade</span></button></td>
                </tr>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">MC</span>
                      <span class="celula-dupla"><strong>Mariana Costa</strong><small>CPF 123.456.789-09</small></span>
                    </div>
                  </td>
                  <td>(11) 97654-3210</td>
                  <td>Guarulhos, SP</td>
                  <td class="num">12</td>
                  <td class="num">R$ 804,00</td>
                  <td><span class="status ativo">Ativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Mariana Costa</span></button></td>
                </tr>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">PB</span>
                      <span class="celula-dupla"><strong>Padaria Boa Massa Ltda</strong><small>CNPJ 45.987.321/0001-55</small></span>
                    </div>
                  </td>
                  <td>(11) 3456-7890</td>
                  <td>Osasco, SP</td>
                  <td class="num">9</td>
                  <td class="num">R$ 2.340,00</td>
                  <td><span class="status ativo">Ativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Padaria Boa Massa Ltda</span></button></td>
                </tr>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">RS</span>
                      <span class="celula-dupla"><strong>Roberto Silva</strong><small>CPF 987.654.321-00</small></span>
                    </div>
                  </td>
                  <td>(11) 96543-2109</td>
                  <td>Osasco, SP</td>
                  <td class="num">7</td>
                  <td class="num">R$ 389,90</td>
                  <td><span class="status ativo">Ativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Roberto Silva</span></button></td>
                </tr>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">CR</span>
                      <span class="celula-dupla"><strong>Camila Rocha</strong><small>CPF 456.789.123-11</small></span>
                    </div>
                  </td>
                  <td>(11) 95432-1098</td>
                  <td>Santo André, SP</td>
                  <td class="num">5</td>
                  <td class="num">R$ 291,40</td>
                  <td><span class="status ativo">Ativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Camila Rocha</span></button></td>
                </tr>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">PN</span>
                      <span class="celula-dupla"><strong>Pedro Nunes</strong><small>CPF 654.321.987-22</small></span>
                    </div>
                  </td>
                  <td>(11) 94321-0987</td>
                  <td>São Paulo, SP</td>
                  <td class="num">3</td>
                  <td class="num">R$ 187,60</td>
                  <td><span class="status ativo">Ativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Pedro Nunes</span></button></td>
                </tr>
                <tr>
                  <td>
                    <div class="pessoa">
                      <span class="avatar" aria-hidden="true">HD</span>
                      <span class="celula-dupla"><strong>Henrique Dias</strong><small>CPF 789.123.456-33</small></span>
                    </div>
                  </td>
                  <td>(11) 93210-9876</td>
                  <td>Barueri, SP</td>
                  <td class="num">1</td>
                  <td class="num">R$ 31,50</td>
                  <td><span class="status inativo">Inativo</span></td>
                  <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Henrique Dias</span></button></td>
                </tr>
              </tbody>
            </table>
          </div>

          <nav class="paginacao" aria-label="Paginação">
            <span class="painel-meta">Mostrando 1 a 7 de 128 clientes</span>
            <div class="paginacao-botoes">
              <button type="button" class="btn btn-pequeno" disabled><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Anterior</button>
              <button type="button" class="btn btn-pequeno">Próxima <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            </div>
          </nav>
        </section>

      </div>
    </main>
  </div>
</body>

</html>
