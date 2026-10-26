<?php
/**
 * Menu lateral do painel (vira gaveta com botão "hambúrguer" em telas pequenas).
 * Antes de incluir, a página define $paginaAtual (ex.: 'clientes').
 * Usa as variáveis de auth.php: $verFinanceiro, $verEstoque, $nomeUsuario, $cargo.
 */
$paginaAtual = $paginaAtual ?? '';

$itensMenu = [
  ['dashboard',      'dashboard.php',      'fa-chart-line',     'Visão Geral',    true],
  ['fluxo_de_caixa', 'fluxo_de_caixa.php', 'fa-wallet',         'Fluxo de Caixa', $verFinanceiro],
  ['estoque',        'estoque.php',        'fa-boxes-stacked',  'Estoque',        $verEstoque],
  ['pedidos',        'pedidos.php',        'fa-receipt',        'Pedidos',        true],
  ['cardapios',      'cardapios.php',      'fa-utensils',       'Cardápio',       true],
  ['clientes',       'clientes.php',       'fa-users',          'Clientes',       true],
  ['empresa',        'empresa.php',        'fa-city',           'Empresa',        true],
  ['configs',        'configs.php',        'fa-gear',           'Configurações',  true],
];
?>
<header class="topo-movel">
  <button type="button" class="btn-menu" id="btn-menu" aria-controls="menu-lateral" aria-expanded="false" aria-label="Abrir menu">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>
  <span class="topo-movel-marca">
    <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
    <strong class="nome-empresa">XTEC</strong>
  </span>
</header>
<div class="menu-fundo" id="menu-fundo" hidden></div>

<aside class="sidebar" id="menu-lateral">
  <div>
    <div class="sidebar-header">
      <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
      <h2 class="nome-empresa">XTEC</h2>
    </div>
    <nav class="sidebar-nav" aria-label="Navegação principal">
      <?php foreach ($itensMenu as [$chave, $arquivo, $icone, $rotulo, $visivel]): ?>
        <?php if (!$visivel) continue; ?>
        <?php $ativo = ($chave === $paginaAtual); ?>
        <a href="<?= $arquivo ?>" class="nav-item<?= $ativo ? ' active' : '' ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
          <i class="fa-solid <?= $icone ?>" aria-hidden="true"></i>
          <span><?= $rotulo ?></span>
        </a>
      <?php endforeach; ?>
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
    <a href="../../backend/auth/logout.php" class="btn-sair">
      <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
      <span>Sair</span>
    </a>
  </div>
</aside>
<script src="../js/painel/menu.js" defer></script>
<script src="../js/painel/tabelas.js" defer></script>
