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
  <title>Pedidos · XTEC</title>
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

          <a href="pedidos.php" class="nav-item active" aria-current="page">
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
            <h1>Pedidos</h1>
            <p>Comandas do dia, da cozinha até a entrega</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar pedido</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" class="input-dash" name="busca" placeholder="Buscar por número ou cliente">
            </label>
            <div class="filtros" role="group" aria-label="Tipo de pedido">
              <button type="button" class="filtro ativo" aria-pressed="true">Todos <span>14</span></button>
              <button type="button" class="filtro" aria-pressed="false">Salão <span>5</span></button>
              <button type="button" class="filtro" aria-pressed="false">Balcão <span>3</span></button>
              <button type="button" class="filtro" aria-pressed="false">Delivery <span>6</span></button>
            </div>
          </div>
          <button type="button" class="btn btn-primario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo pedido</button>
        </div>

        <div class="quadro">

          <section class="coluna" aria-labelledby="col-novos">
            <header class="coluna-topo">
              <h2 id="col-novos">Novos</h2>
              <span class="contagem">2</span>
            </header>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1052</strong>
                <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> há 2 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Mesa 4</p>
              <ul class="comanda-itens">
                <li><span class="qtd">1x</span> X-Tudo</li>
                <li><span class="qtd">1x</span> Coca-Cola 2L</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 41,00</strong>
                  <small>Pix</small>
                </div>
                <button type="button" class="btn btn-pequeno btn-primario">Aceitar</button>
              </footer>
            </article>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1051</strong>
                <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> há 5 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · Camila Rocha</p>
              <ul class="comanda-itens">
                <li><span class="qtd">1x</span> Combo Artesanal</li>
                <li><span class="qtd">1x</span> Batata média <em>sem sal</em></li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 64,50</strong>
                  <small>Crédito</small>
                </div>
                <button type="button" class="btn btn-pequeno btn-primario">Aceitar</button>
              </footer>
            </article>
          </section>

          <section class="coluna" aria-labelledby="col-preparo">
            <header class="coluna-topo">
              <h2 id="col-preparo">Em preparo</h2>
              <span class="contagem">3</span>
            </header>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1050</strong>
                <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> há 11 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão · Henrique Dias</p>
              <ul class="comanda-itens">
                <li><span class="qtd">3x</span> Misto Quente</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 31,50</strong>
                  <small>Dinheiro</small>
                </div>
                <button type="button" class="btn btn-pequeno">Marcar pronto</button>
              </footer>
            </article>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1049</strong>
                <span class="comanda-tempo atrasado"><i class="fa-regular fa-clock" aria-hidden="true"></i> há 24 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Mesa 7</p>
              <ul class="comanda-itens">
                <li><span class="qtd">1x</span> Parmegiana de frango</li>
                <li><span class="qtd">2x</span> Suco de laranja</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 57,90</strong>
                  <small>Débito</small>
                </div>
                <button type="button" class="btn btn-pequeno">Marcar pronto</button>
              </footer>
            </article>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1048</strong>
                <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> há 9 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · Lucas Andrade</p>
              <ul class="comanda-itens">
                <li><span class="qtd">2x</span> X-Tudo</li>
                <li><span class="qtd">1x</span> Coca-Cola 2L</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 68,00</strong>
                  <small>Pix</small>
                </div>
                <button type="button" class="btn btn-pequeno">Enviar para entrega</button>
              </footer>
            </article>
          </section>

          <section class="coluna" aria-labelledby="col-caminho">
            <header class="coluna-topo">
              <h2 id="col-caminho">A caminho</h2>
              <span class="contagem">2</span>
            </header>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1047</strong>
                <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> saiu há 8 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · Mariana Costa</p>
              <ul class="comanda-itens">
                <li><span class="qtd">1x</span> Combo Artesanal</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 42,50</strong>
                  <small>Pix</small>
                </div>
                <button type="button" class="btn btn-pequeno">Confirmar entrega</button>
              </footer>
            </article>

            <article class="comanda">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1045</strong>
                <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> saiu há 19 min</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · Pedro Nunes</p>
              <ul class="comanda-itens">
                <li><span class="qtd">2x</span> Parmegiana de frango</li>
                <li><span class="qtd">1x</span> Guaraná 2L</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 93,80</strong>
                  <small>Crédito</small>
                </div>
                <button type="button" class="btn btn-pequeno">Confirmar entrega</button>
              </footer>
            </article>
          </section>

          <section class="coluna" aria-labelledby="col-entregues">
            <header class="coluna-topo">
              <h2 id="col-entregues">Entregues</h2>
              <span class="contagem">7</span>
            </header>

            <article class="comanda concluida">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1046</strong>
                <span class="status entregue">Entregue</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · Roberto Silva</p>
              <ul class="comanda-itens">
                <li><span class="qtd">3x</span> Misto Quente</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 31,50</strong>
                  <small>Dinheiro</small>
                </div>
                <span class="comanda-hora">19:42</span>
              </footer>
            </article>

            <article class="comanda concluida">
              <header class="comanda-topo">
                <strong class="comanda-numero">#1044</strong>
                <span class="status entregue">Entregue</span>
              </header>
              <p class="comanda-origem"><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Mesa 2</p>
              <ul class="comanda-itens">
                <li><span class="qtd">2x</span> Filé à cavalo</li>
                <li><span class="qtd">2x</span> Cerveja long neck</li>
              </ul>
              <footer class="comanda-rodape">
                <div class="comanda-total">
                  <strong>R$ 112,00</strong>
                  <small>Débito</small>
                </div>
                <span class="comanda-hora">19:30</span>
              </footer>
            </article>
          </section>

        </div>

      </div>
    </main>
  </div>
</body>

</html>
