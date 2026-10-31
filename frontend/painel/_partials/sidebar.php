<?php
/**
 * Menu lateral do painel, agrupado por área. Vira gaveta em telas pequenas
 * e pode ser recolhido (só ícones) no computador.
 * Usa $paginaAtual, $perm, $nomeLoja e $etapasMenu (definidos em topo.php/auth.php).
 */
$paginaAtual = $paginaAtual ?? '';
$emAndamento = (int) $etapasMenu['novos'] + (int) $etapasMenu['preparo'] + (int) $etapasMenu['prontos'];
$naCozinha = (int) $etapasMenu['novos'] + (int) $etapasMenu['preparo'];

$gruposMenu = [
  'Operação' => [
    ['dashboard', 'dashboard.php', 'fa-chart-line', 'Visão geral', true, 0],
    ['pdv', 'pdv.php', 'fa-cash-register', 'PDV', $perm['pdv'], 0],
    ['pedidos', 'pedidos.php', 'fa-receipt', 'Pedidos', true, $emAndamento],
    ['mesas', 'mesas.php', 'fa-chair', 'Mesas', $perm['mesas'], 0],
    ['cozinha', 'cozinha.php', 'fa-fire-burner', 'Cozinha', $perm['cozinha'], $naCozinha],
  ],
  'Gestão' => [
    ['cardapios', 'cardapios.php', 'fa-utensils', 'Cardápio', true, 0],
    ['estoque', 'estoque.php', 'fa-boxes-stacked', 'Estoque', $perm['estoque'], 0],
    ['compras', 'compras.php', 'fa-cart-flatbed', 'Compras', $perm['compras'], 0],
    ['fornecedores', 'fornecedores.php', 'fa-truck-field', 'Fornecedores', $perm['compras'], 0],
    ['clientes', 'clientes.php', 'fa-users', 'Clientes', true, 0],
  ],
  'Financeiro' => [
    ['fluxo_de_caixa', 'fluxo_de_caixa.php', 'fa-wallet', 'Fluxo de caixa', $perm['financeiro'], 0],
    ['relatorios', 'relatorios.php', 'fa-chart-pie', 'Relatórios', $perm['relatorios'], 0],
  ],
  'Administração' => [
    ['empresa', 'empresa.php', 'fa-store', 'Empresa e equipe', true, 0],
    ['atividades', 'atividades.php', 'fa-clock-rotate-left', 'Atividades', $perm['auditoria'], 0],
    ['configs', 'configs.php', 'fa-gear', 'Configurações', true, 0],
  ],
];
?>
<header class="topo-movel">
  <button type="button" class="btn-menu" id="btn-menu" aria-controls="menu-lateral" aria-expanded="false" aria-label="Abrir menu">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>
  <span class="topo-movel-marca">
    <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
    <strong class="nome-empresa"><?= e(config('app_nome')) ?></strong>
  </span>
</header>
<div class="menu-fundo" id="menu-fundo" hidden></div>

<aside class="sidebar" id="menu-lateral" aria-label="Menu principal">
  <a class="sidebar-header" href="dashboard.php" title="<?= e($nomeLoja) ?>">
    <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
    <span class="marca-texto">
      <span class="nome-empresa"><?= e(config('app_nome')) ?></span>
      <span class="marca-sub"><?= e($nomeLoja) ?></span>
    </span>
  </a>

  <nav class="sidebar-nav" aria-label="Navegação principal">
    <?php foreach ($gruposMenu as $_grupoMenu => $_itensMenu):
      $_visiveisMenu = array_filter($_itensMenu, static fn($i) => $i[4]);
      if (!$_visiveisMenu) continue; ?>
      <div class="nav-grupo">
        <span class="nav-grupo-titulo"><?= $_grupoMenu ?></span>
        <?php foreach ($_visiveisMenu as [$_chave, $_arquivo, $_icone, $_rotulo, , $_contador]): $_ativo = ($_chave === $paginaAtual); ?>
          <a href="<?= $_arquivo ?>" class="nav-item<?= $_ativo ? ' active' : '' ?>"<?= $_ativo ? ' aria-current="page"' : '' ?> title="<?= e($_rotulo) ?>" data-busca-pagina="<?= e($_rotulo) ?>" data-icone="<?= $_icone ?>">
            <i class="fa-solid <?= $_icone ?>" aria-hidden="true"></i>
            <span><?= $_rotulo ?></span>
            <?php if ($_contador > 0): ?><span class="nav-contador" aria-label="<?= $_contador ?> em andamento"><?= $_contador ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <button type="button" class="btn-recolher" id="btn-recolher" aria-label="Recolher menu">
      <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
      <span>Recolher menu</span>
    </button>
  </div>
</aside>
