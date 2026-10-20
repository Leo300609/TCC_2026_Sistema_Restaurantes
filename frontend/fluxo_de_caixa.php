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
  <title>Fluxo de Caixa · XTEC</title>
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
            <a href="fluxo_de_caixa.php" class="nav-item active" aria-current="page">
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
            <h1>Fluxo de Caixa</h1>
            <p>Entradas, saídas e contas em aberto</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <section class="grid-cards" aria-label="Resumo do período">
          <div class="card-metrica">
            <span class="card-titulo">Saldo do mês</span>
            <div class="card-valor">R$ 12.480,00</div>
            <span class="card-detalhe positivo">+8% sobre setembro</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Entradas pagas</span>
            <div class="card-valor">R$ 38.920,00</div>
            <span class="card-detalhe neutro">R$ 4.310,00 a receber</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Saídas pagas</span>
            <div class="card-valor">R$ 26.440,00</div>
            <span class="card-detalhe neutro">R$ 5.150,00 a pagar</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Em atraso</span>
            <div class="card-valor">R$ 1.760,00</div>
            <span class="card-detalhe alerta">2 lançamentos</span>
          </div>
        </section>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" class="input-dash" name="periodo">
                <option>Este mês</option>
                <option>Mês passado</option>
                <option>Últimos 30 dias</option>
                <option>Este ano</option>
              </select>
            </div>
            <div class="filtros" role="group" aria-label="Tipo de lançamento">
              <button type="button" class="filtro ativo" aria-pressed="true">Todos <span>42</span></button>
              <button type="button" class="filtro" aria-pressed="false">Entradas <span>27</span></button>
              <button type="button" class="filtro" aria-pressed="false">Saídas <span>15</span></button>
            </div>
          </div>
          <div class="toolbar-grupo">
            <div class="grupo-input seletor">
              <label class="sr-only" for="situacao">Situação</label>
              <select id="situacao" class="input-dash" name="situacao">
                <option>Todas as situações</option>
                <option>Pendente</option>
                <option>Pago</option>
                <option>Atrasado</option>
                <option>Cancelado</option>
              </select>
            </div>
          </div>
        </div>

        <div class="fluxo-grid">

          <section class="painel-card painel-tabela" aria-label="Lançamentos">
            <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de lançamentos, role para os lados se necessário">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Vencimento</th>
                    <th scope="col">Lançamento</th>
                    <th scope="col" class="num">Valor</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="data">22/10</td>
                    <td><span class="celula-dupla"><strong>Aluguel do salão</strong><small><span class="origem">Manual</span> Despesas fixas</small></span></td>
                    <td class="num saida">− R$ 3.200,00</td>
                    <td><span class="status pendente">Pendente</span></td>
                    <td class="acoes">
                      <button type="button" class="btn btn-pequeno btn-fantasma" aria-label="Editar aluguel do salão"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                      <button type="button" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: aluguel do salão</span></button>
                    </td>
                  </tr>
                  <tr>
                    <td class="data">20/10</td>
                    <td><span class="celula-dupla"><strong>Frigorífico Boa Carne</strong><small><span class="origem">Compra</span> Pedido #213</small></span></td>
                    <td class="num saida">− R$ 1.950,00</td>
                    <td><span class="status agendado">Agendado</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: Frigorífico Boa Carne</span></button></td>
                  </tr>
                  <tr>
                    <td class="data">19/10</td>
                    <td><span class="celula-dupla"><strong>Lucas Andrade</strong><small><span class="origem">Venda</span> Pedido #1048</small></span></td>
                    <td class="num entrada">+ R$ 68,00</td>
                    <td><span class="status pago">Pago</span></td>
                    <td class="acoes"></td>
                  </tr>
                  <tr>
                    <td class="data">19/10</td>
                    <td><span class="celula-dupla"><strong>Mariana Costa</strong><small><span class="origem">Venda</span> Pedido #1047</small></span></td>
                    <td class="num entrada">+ R$ 42,50</td>
                    <td><span class="status pendente">Pendente</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: Mariana Costa</span></button></td>
                  </tr>
                  <tr>
                    <td class="data">18/10</td>
                    <td><span class="celula-dupla"><strong>Distribuidora Sul Bebidas</strong><small><span class="origem">Compra</span> Pedido #212</small></span></td>
                    <td class="num saida">− R$ 1.140,00</td>
                    <td><span class="status pago">Pago</span></td>
                    <td class="acoes"></td>
                  </tr>
                  <tr>
                    <td class="data">17/10</td>
                    <td><span class="celula-dupla"><strong>Gorjetas do salão</strong><small><span class="origem">Manual</span> Receitas extras</small></span></td>
                    <td class="num entrada">+ R$ 320,00</td>
                    <td><span class="status pago">Pago</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno btn-fantasma" aria-label="Editar gorjetas do salão"><i class="fa-solid fa-pen" aria-hidden="true"></i></button></td>
                  </tr>
                  <tr>
                    <td class="data">15/10</td>
                    <td><span class="celula-dupla"><strong>Hortifruti Central</strong><small><span class="origem">Compra</span> Pedido #209</small></span></td>
                    <td class="num saida">− R$ 480,00</td>
                    <td><span class="status atrasado">Atrasado</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: Hortifruti Central</span></button></td>
                  </tr>
                  <tr>
                    <td class="data">12/10</td>
                    <td><span class="celula-dupla"><strong>Padaria Boa Massa Ltda</strong><small><span class="origem">Venda</span> Pedido #1021</small></span></td>
                    <td class="num entrada">+ R$ 1.280,00</td>
                    <td><span class="status atrasado">Atrasado</span></td>
                    <td class="acoes"><button type="button" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: Padaria Boa Massa Ltda</span></button></td>
                  </tr>
                </tbody>
              </table>
            </div>

            <nav class="paginacao" aria-label="Paginação">
              <span class="painel-meta">Mostrando 1 a 8 de 42 lançamentos</span>
              <div class="paginacao-botoes">
                <button type="button" class="btn btn-pequeno" disabled><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Anterior</button>
                <button type="button" class="btn btn-pequeno">Próxima <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
              </div>
            </nav>
          </section>

          <div class="fluxo-lateral">

            <section class="painel-card" aria-labelledby="titulo-novo-lancamento">
              <div class="painel-header">
                <h2 id="titulo-novo-lancamento">Novo lançamento</h2>
              </div>
              <form class="form-lancamento" action="#" method="post">
                <fieldset class="segmentado">
                  <legend class="sr-only">Tipo</legend>
                  <label>
                    <input type="radio" name="tipo" value="ENTRADA" checked>
                    <span><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Entrada</span>
                  </label>
                  <label>
                    <input type="radio" name="tipo" value="SAIDA">
                    <span><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Saída</span>
                  </label>
                </fieldset>

                <div class="grupo-input">
                  <label for="descricao">Descrição</label>
                  <input type="text" id="descricao" name="descricao" class="input-dash" maxlength="255" placeholder="Ex.: Conta de energia" required>
                </div>

                <div class="grupo-input">
                  <label for="categoria">Categoria</label>
                  <input type="text" id="categoria" name="categoria" class="input-dash" maxlength="100" list="categorias-caixa" placeholder="Ex.: Despesas fixas" required>
                  <datalist id="categorias-caixa">
                    <option value="Despesas fixas">
                    <option value="Receitas extras">
                    <option value="Impostos">
                    <option value="Manutenção">
                  </datalist>
                </div>

                <div class="linha-dupla">
                  <div class="grupo-input">
                    <label for="valor">Valor (R$)</label>
                    <input type="number" id="valor" name="valor" class="input-dash" min="0.01" step="0.01" inputmode="decimal" placeholder="0,00" required>
                  </div>
                  <div class="grupo-input">
                    <label for="data_movimentacao">Data</label>
                    <input type="date" id="data_movimentacao" name="data_movimentacao" class="input-dash" required>
                  </div>
                </div>

                <div class="grupo-input">
                  <label for="status">Situação</label>
                  <select id="status" name="status" class="input-dash">
                    <option value="PENDENTE">Pendente</option>
                    <option value="PAGO">Pago</option>
                  </select>
                </div>

                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Registrar lançamento</button>
              </form>
            </section>

            <section class="painel-card" aria-labelledby="titulo-semanas">
              <div class="painel-header">
                <h2 id="titulo-semanas">Entradas e saídas</h2>
                <span class="painel-meta">Por semana</span>
              </div>
              <div class="barras" role="img" aria-label="Entradas e saídas por semana em outubro. Semana 1: 8,4 mil e 6,0 mil. Semana 2: 9,1 mil e 5,9 mil. Semana 3: 10,2 mil e 7,1 mil. Semana 4: 11,2 mil e 7,4 mil.">
                <div class="barras-semana">
                  <div class="barras-par"><span class="barra entrada" style="--h: 75%"></span><span class="barra saida" style="--h: 54%"></span></div>
                  <span class="barras-rotulo">Sem 1</span>
                </div>
                <div class="barras-semana">
                  <div class="barras-par"><span class="barra entrada" style="--h: 81%"></span><span class="barra saida" style="--h: 53%"></span></div>
                  <span class="barras-rotulo">Sem 2</span>
                </div>
                <div class="barras-semana">
                  <div class="barras-par"><span class="barra entrada" style="--h: 91%"></span><span class="barra saida" style="--h: 63%"></span></div>
                  <span class="barras-rotulo">Sem 3</span>
                </div>
                <div class="barras-semana">
                  <div class="barras-par"><span class="barra entrada" style="--h: 100%"></span><span class="barra saida" style="--h: 66%"></span></div>
                  <span class="barras-rotulo">Sem 4</span>
                </div>
              </div>
              <ul class="legenda">
                <li><span class="ponto entrada" aria-hidden="true"></span> Entradas</li>
                <li><span class="ponto saida" aria-hidden="true"></span> Saídas</li>
              </ul>
            </section>

          </div>
        </div>

      </div>
    </main>
  </div>
</body>

</html>
