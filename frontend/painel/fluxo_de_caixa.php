<?php
require __DIR__ . '/_partials/auth.php';

if (!$verFinanceiro) {
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
  <title>Fluxo de Caixa · XTEC</title>
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

    <?php $paginaAtual = 'fluxo_de_caixa'; include __DIR__ . '/_partials/sidebar.php'; ?>

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
            <span class="card-titulo">Saldo do período</span>
            <div class="card-valor" id="res-saldo">–</div>
            <span class="card-detalhe neutro" id="res-saldo-detalhe">&nbsp;</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Entradas pagas</span>
            <div class="card-valor" id="res-entradas">–</div>
            <span class="card-detalhe neutro" id="res-entradas-detalhe">&nbsp;</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Saídas pagas</span>
            <div class="card-valor" id="res-saidas">–</div>
            <span class="card-detalhe neutro" id="res-saidas-detalhe">&nbsp;</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Em atraso</span>
            <div class="card-valor" id="res-atraso">–</div>
            <span class="card-detalhe alerta" id="res-atraso-detalhe">&nbsp;</span>
          </div>
        </section>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" class="input-dash" name="periodo">
                <option value="mes">Este mês</option>
                <option value="passado">Mês passado</option>
                <option value="30dias">Últimos 30 dias</option>
                <option value="ano">Este ano</option>
              </select>
            </div>
            <div class="filtros" id="filtros" role="group" aria-label="Tipo de lançamento"></div>
          </div>
          <div class="toolbar-grupo">
            <div class="grupo-input seletor">
              <label class="sr-only" for="situacao">Situação</label>
              <select id="situacao" class="input-dash" name="situacao">
                <option value="TODAS">Todas as situações</option>
                <option value="PENDENTE">Pendente</option>
                <option value="PAGO">Pago</option>
                <option value="ATRASADO">Atrasado</option>
                <option value="CANCELADO">Cancelado</option>
              </select>
            </div>
          </div>
        </div>

        <div class="fluxo-grid">

          <section class="painel-card painel-tabela" aria-label="Lançamentos">
            <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de lançamentos, role para os lados se necessário" aria-live="polite"></div>
            <nav class="paginacao" id="paginacao" aria-label="Paginação" hidden></nav>
          </section>

          <div class="fluxo-lateral">

            <section class="painel-card" aria-labelledby="titulo-novo-lancamento">
              <div class="painel-header">
                <h2 id="titulo-novo-lancamento">Novo lançamento</h2>
              </div>
              <form class="form-lancamento" id="form-lancamento" action="#" method="post">
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
                <span class="painel-meta">Últimas 4 semanas</span>
              </div>
              <div class="barras" id="barras" role="img" aria-label="Entradas e saídas por semana"></div>
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
  <script src="../js/painel/comum.js"></script>
  <script src="../js/painel/fluxo.js"></script>
</body>

</html>
