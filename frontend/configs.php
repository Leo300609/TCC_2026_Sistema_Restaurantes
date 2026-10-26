<?php
// ==========================================
// 1. LÓGICA DE SESSÃO E PERMISSÃO (NOVO)
// ==========================================
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Configurações · XTEC</title>
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
          <a href="clientes.php" class="nav-item">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            <span>Clientes</span>
          </a>

          <a href="empresa.php" class="nav-item">
            <i class="fa-solid fa-city" aria-hidden="true"></i>
            <span>Empresa</span>
          </a>

          <a href="configs.php" class="nav-item active" aria-current="page">
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
            <h1>Configurações</h1>
            <p>Preferências da conta e da operação</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <div class="config-grid">

          <nav class="config-indice" aria-label="Seções de configurações">
            <a href="#conta">Conta</a>
            <a href="#notificacoes">Notificações</a>
            <a href="#operacao">Operação</a>
            <a href="#seguranca">Segurança</a>
          </nav>

          <form class="config-secoes" id="form-configs" action="#" method="post">

            <section class="painel-card" id="conta" aria-labelledby="titulo-conta">
              <div class="painel-header">
                <h2 id="titulo-conta">Conta</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome">Nome</label>
                  <input type="text" id="nome" name="nome" class="input-dash" maxlength="255" required autocomplete="name">
                </div>
                <div class="grupo-input">
                  <label for="email">E-mail</label>
                  <input type="email" id="email" name="email" class="input-dash" maxlength="255" required autocomplete="email">
                </div>
                <div class="grupo-input">
                  <label for="senha_atual">Senha atual</label>
                  <input type="password" id="senha_atual" name="senha_atual" class="input-dash" autocomplete="current-password">
                </div>
                <div class="grupo-input">
                  <label for="senha_nova">Nova senha</label>
                  <input type="password" id="senha_nova" name="senha_nova" class="input-dash" minlength="8" autocomplete="new-password" aria-describedby="dica-senha">
                  <small id="dica-senha">Use ao menos 8 caracteres.</small>
                </div>
              </div>
            </section>

            <section class="painel-card" id="notificacoes" aria-labelledby="titulo-notificacoes">
              <div class="painel-header">
                <h2 id="titulo-notificacoes">Notificações</h2>
              </div>
              <ul class="config-linhas">
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="n-pedido">Novo pedido recebido</strong>
                    <small>Mostra um aviso e toca um som quando chega uma comanda.</small>
                  </div>
                  <label class="switch"><input type="checkbox" role="switch" name="notif_pedido" aria-labelledby="n-pedido" checked><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="n-minimo">Estoque abaixo do mínimo</strong>
                    <small>Avisa quando um item chega ao nível mínimo cadastrado.</small>
                  </div>
                  <label class="switch"><input type="checkbox" role="switch" name="notif_minimo" aria-labelledby="n-minimo" checked><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="n-validade">Produtos perto do vencimento</strong>
                    <small>Antecedência do aviso de validade.</small>
                  </div>
                  <div class="config-controle">
                    <label class="sr-only" for="dias_validade">Dias de antecedência</label>
                    <select id="dias_validade" name="dias_validade" class="input-dash">
                      <option value="1">1 dia</option>
                      <option value="2">2 dias</option>
                      <option value="3" selected>3 dias</option>
                      <option value="7">7 dias</option>
                    </select>
                    <label class="switch"><input type="checkbox" role="switch" name="notif_validade" aria-labelledby="n-validade" checked><span class="switch-trilho" aria-hidden="true"></span></label>
                  </div>
                </li>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="n-contas">Contas a pagar vencendo</strong>
                    <small>Lembra dos pagamentos do Fluxo de Caixa na véspera.</small>
                  </div>
                  <label class="switch"><input type="checkbox" role="switch" name="notif_contas" aria-labelledby="n-contas"><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
              </ul>
            </section>

            <section class="painel-card" id="operacao" aria-labelledby="titulo-operacao">
              <div class="painel-header">
                <h2 id="titulo-operacao">Operação</h2>
              </div>
              <p class="painel-nota" id="aviso-operacao" hidden>Somente administradores e gerentes podem alterar a operação.</p>
              <ul class="config-linhas">
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="o-delivery">Receber pedidos de delivery</strong>
                    <small>Desligue para pausar novos pedidos de entrega sem fechar a loja.</small>
                  </div>
                  <label class="switch"><input type="checkbox" role="switch" name="aceita_delivery" aria-labelledby="o-delivery" checked><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong><label for="tempo_preparo">Tempo médio de preparo</label></strong>
                    <small>Pedidos acima disso aparecem como atrasados no quadro.</small>
                  </div>
                  <select id="tempo_preparo" name="tempo_preparo" class="input-dash config-select">
                    <option value="15">15 minutos</option>
                    <option value="20" selected>20 minutos</option>
                    <option value="30">30 minutos</option>
                    <option value="45">45 minutos</option>
                  </select>
                </li>
                <li class="config-linha config-linha-coluna">
                  <div class="config-texto">
                    <strong id="o-pagamentos">Formas de pagamento aceitas</strong>
                    <small>Aparecem na hora de fechar a conta.</small>
                  </div>
                  <div class="opcoes" role="group" aria-labelledby="o-pagamentos">
                    <label class="opcao"><input type="checkbox" name="pagamentos[]" value="PIX" checked><span><i class="fa-brands fa-pix" aria-hidden="true"></i> Pix</span></label>
                    <label class="opcao"><input type="checkbox" name="pagamentos[]" value="CREDITO" checked><span><i class="fa-regular fa-credit-card" aria-hidden="true"></i> Crédito</span></label>
                    <label class="opcao"><input type="checkbox" name="pagamentos[]" value="DEBITO" checked><span><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Débito</span></label>
                    <label class="opcao"><input type="checkbox" name="pagamentos[]" value="DINHEIRO" checked><span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i> Dinheiro</span></label>
                    <label class="opcao"><input type="checkbox" name="pagamentos[]" value="BOLETO"><span><i class="fa-solid fa-barcode" aria-hidden="true"></i> Boleto</span></label>
                  </div>
                </li>
              </ul>
            </section>

            <section class="painel-card" id="seguranca" aria-labelledby="titulo-seguranca">
              <div class="painel-header">
                <h2 id="titulo-seguranca">Segurança</h2>
              </div>
              <ul class="config-linhas">
                <li class="config-linha">
                  <div class="config-texto">
                    <strong>Encerrar sessão</strong>
                    <small>Sai da sua conta neste dispositivo.</small>
                  </div>
                  <a href="../backend/logout.php" class="btn btn-perigo"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sair agora</a>
                </li>
              </ul>
            </section>

            <div class="barra-salvar">
              <button type="button" class="btn btn-fantasma" id="btn-descartar">Descartar alterações</button>
              <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
            </div>
          </form>

        </div>

      </div>
    </main>
  </div>
  <script src="js/comum.js"></script>
  <script src="js/configs.js"></script>
</body>

</html>
