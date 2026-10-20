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
  <title>Cardápio · XTEC</title>
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
          <a href="cardapios.php" class="nav-item active" aria-current="page">
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
            <h1>Cardápio</h1>
            <p>Pratos, bebidas e adicionais que você vende</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar item do cardápio</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" class="input-dash" name="busca" placeholder="Buscar item do cardápio">
            </label>
            <div class="filtros" role="group" aria-label="Categoria">
              <button type="button" class="filtro ativo" aria-pressed="true">Todos <span>14</span></button>
              <button type="button" class="filtro" aria-pressed="false">Lanches <span>4</span></button>
              <button type="button" class="filtro" aria-pressed="false">Pratos <span>3</span></button>
              <button type="button" class="filtro" aria-pressed="false">Porções <span>2</span></button>
              <button type="button" class="filtro" aria-pressed="false">Bebidas <span>5</span></button>
            </div>
          </div>
          <button type="button" class="btn btn-primario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo item</button>
        </div>

        <div class="painel-card cardapio">

          <section class="categoria" aria-labelledby="cat-lanches">
            <header class="categoria-topo">
              <h2 id="cat-lanches">Lanches</h2>
              <span class="painel-meta">4 itens</span>
            </header>
            <ul class="itens-cardapio">
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">X-Tudo</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 27,00</span>
                </div>
                <p class="item-descricao">Hambúrguer artesanal, bacon, ovo, queijo, alface e tomate no pão brioche.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> X-Tudo</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Combo Artesanal</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 42,50</span>
                </div>
                <p class="item-descricao">Hambúrguer de 180 g, batata média e refrigerante em lata.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Combo Artesanal</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Misto Quente</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 10,50</span>
                </div>
                <p class="item-descricao">Pão de forma, presunto e queijo na chapa.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Misto Quente</span></button>
                </div>
              </li>
              <li class="item-cardapio indisponivel">
                <div class="item-linha">
                  <h3 class="item-nome">X-Salada</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 24,00</span>
                </div>
                <p class="item-descricao">Hambúrguer, queijo, alface, tomate e maionese da casa.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]"><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Indisponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> X-Salada</span></button>
                </div>
              </li>
            </ul>
          </section>

          <section class="categoria" aria-labelledby="cat-pratos">
            <header class="categoria-topo">
              <h2 id="cat-pratos">Pratos</h2>
              <span class="painel-meta">3 itens</span>
            </header>
            <ul class="itens-cardapio">
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Parmegiana de frango</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 39,90</span>
                </div>
                <p class="item-descricao">Filé empanado com molho e queijo gratinado, arroz, batata frita e salada.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Parmegiana de frango</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Filé à cavalo</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 44,00</span>
                </div>
                <p class="item-descricao">Bife acebolado com ovo frito, arroz, feijão e batata frita.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Filé à cavalo</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Feijoada completa</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 38,00</span>
                </div>
                <p class="item-descricao">Servida aos sábados, com arroz, couve, farofa e laranja.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Feijoada completa</span></button>
                </div>
              </li>
            </ul>
          </section>

          <section class="categoria" aria-labelledby="cat-porcoes">
            <header class="categoria-topo">
              <h2 id="cat-porcoes">Porções</h2>
              <span class="painel-meta">2 itens</span>
            </header>
            <ul class="itens-cardapio">
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Batata frita média</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 22,00</span>
                </div>
                <p class="item-descricao">Porção de 300 g, crocante, com sal e orégano.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Batata frita média</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Isca de frango</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 36,00</span>
                </div>
                <p class="item-descricao">Tiras de frango empanadas com molho barbecue.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Isca de frango</span></button>
                </div>
              </li>
            </ul>
          </section>

          <section class="categoria" aria-labelledby="cat-bebidas">
            <header class="categoria-topo">
              <h2 id="cat-bebidas">Bebidas</h2>
              <span class="painel-meta">5 itens</span>
            </header>
            <ul class="itens-cardapio">
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Coca-Cola 2L</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 14,00</span>
                </div>
                <p class="item-descricao">Garrafa de 2 litros, gelada.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Coca-Cola 2L</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Guaraná 2L</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 14,00</span>
                </div>
                <p class="item-descricao">Garrafa de 2 litros, gelada.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Guaraná 2L</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Suco de laranja</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 9,00</span>
                </div>
                <p class="item-descricao">Copo de 400 ml, espremido na hora.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Suco de laranja</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Cerveja long neck</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 12,00</span>
                </div>
                <p class="item-descricao">Garrafa de 330 ml. Venda somente para maiores de 18 anos.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Cerveja long neck</span></button>
                </div>
              </li>
              <li class="item-cardapio">
                <div class="item-linha">
                  <h3 class="item-nome">Água sem gás</h3>
                  <span class="item-pontos" aria-hidden="true"></span>
                  <span class="item-preco">R$ 4,50</span>
                </div>
                <p class="item-descricao">Garrafa de 500 ml.</p>
                <div class="item-acoes">
                  <label class="switch-rotulo">
                    <span class="switch"><input type="checkbox" role="switch" name="disponivel[]" checked><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>Disponível</span>
                  </label>
                  <button type="button" class="btn btn-pequeno btn-fantasma"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> Água sem gás</span></button>
                </div>
              </li>
            </ul>
          </section>

        </div>

      </div>
    </main>
  </div>
</body>

</html>
