<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['auditoria']);

const POR_PAGINA = 25;
[$de, $ate, $periodo] = periodo_filtro('7dias');
$usuarioFiltro = entrada_int('usuario');
$modulo = entrada('modulo');
$busca = entrada('q');
$pagina = max(1, entrada_int('pagina', 1));

$contagem = ler(fn() => chamar_um('sp_atividade_contagem', [$usuarioFiltro, $modulo, $busca, $de, $ate]), null) ?? ['total' => 0, 'usuarios' => 0, 'acessos' => 0];
$paginasTotal = max(1, (int) ceil((int) $contagem['total'] / POR_PAGINA));
$pagina = min($pagina, $paginasTotal);
$registros = ler(fn() => chamar('sp_atividade_listar', [$usuarioFiltro, $modulo, $busca, $de, $ate, POR_PAGINA, ($pagina - 1) * POR_PAGINA]));
$usuarios = ler(fn() => chamar('sp_equipe_listar'));
$modulos = array_column(ler(fn() => chamar('sp_atividade_modulos')), 'modulo');

// nome e ícone de cada área do sistema
$areas = [
  'sessao' => ['Acesso', 'fa-right-to-bracket'], 'pdv' => ['PDV', 'fa-cash-register'], 'pedidos' => ['Pedidos', 'fa-receipt'],
  'mesas' => ['Mesas', 'fa-chair'], 'cozinha' => ['Cozinha', 'fa-fire-burner'], 'cardapios' => ['Cardápio', 'fa-utensils'],
  'estoque' => ['Estoque', 'fa-boxes-stacked'], 'compras' => ['Compras', 'fa-cart-flatbed'], 'fornecedores' => ['Fornecedores', 'fa-truck-field'],
  'clientes' => ['Clientes', 'fa-users'], 'fluxo_de_caixa' => ['Fluxo de caixa', 'fa-wallet'], 'empresa' => ['Empresa e equipe', 'fa-store'],
  'equipe' => ['Equipe', 'fa-user-plus'], 'configs' => ['Configurações', 'fa-gear'],
];
$area = static fn(string $m) => $areas[$m] ?? [ucfirst(str_replace('_', ' ', $m)), 'fa-circle-info'];

$titulo = 'Atividades';
$subtitulo = 'Histórico de quem fez o quê no sistema';
$paginaAtual = 'atividades';
include __DIR__ . '/_partials/topo.php';
?>

        <section class="grid-cards tres" aria-label="Resumo">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span> Registros no período</span>
            <div class="card-valor"><?= numero($contagem['total']) ?></div>
            <span class="card-detalhe"><?= data_br($de) ?> a <?= data_br($ate) ?></span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span> Pessoas ativas</span>
            <div class="card-valor"><?= numero($contagem['usuarios']) ?></div>
            <span class="card-detalhe">Fizeram ao menos uma ação</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone roxo"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i></span> Acessos</span>
            <div class="card-valor"><?= numero($contagem['acessos']) ?></div>
            <span class="card-detalhe">Entradas no sistema</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="atividades.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar na descrição</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Buscar ação ou pessoa" autocomplete="off">
            </label>
            <div class="grupo-input seletor">
              <label class="sr-only" for="usuario">Pessoa</label>
              <select id="usuario" name="usuario" class="input-dash">
                <option value="0">Toda a equipe</option>
                <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $usuarioFiltro === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="modulo">Área</label>
              <select id="modulo" name="modulo" class="input-dash">
                <option value="">Todas as áreas</option>
                <?php foreach ($modulos as $m): ?><option value="<?= e($m) ?>"<?= $modulo === $m ? ' selected' : '' ?>><?= e($area($m)[0]) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" name="periodo" class="input-dash">
                <?php foreach (array_diff_key(periodos_rotulos(), ['personalizado' => 1]) as $v => $r): ?><option value="<?= $v ?>"<?= $periodo === $v ? ' selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-secundario">Filtrar</button>
          </div>
        </form>

        <section class="painel-card" aria-label="Linha do tempo">
          <?php if (!$registros): ?>
            <p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>Nenhuma atividade encontrada com esses filtros.</p>
          <?php else: ?>
            <ul class="linha-tempo">
              <?php foreach ($registros as $l): [$nomeArea, $icone] = $area($l['modulo']); ?>
                <li>
                  <span class="hora"><strong><?= e($l['hora']) ?></strong><?= e($l['data']) ?></span>
                  <span class="avatar <?= $corAvatar($l['usuario']) ?>" aria-hidden="true"><?= e($iniciais($l['usuario'] ?: '?')) ?></span>
                  <span class="celula-dupla"><strong><?= e($l['descricao']) ?></strong><small><?= e($l['usuario'] ?: 'Sistema') ?><?= $l['ip'] ? ' · IP ' . e($l['ip']) : '' ?></small></span>
                  <span class="selo"><i class="fa-solid <?= $icone ?>" aria-hidden="true"></i> <?= e($nomeArea) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <?= paginacao($pagina, $paginasTotal, (int) $contagem['total'], POR_PAGINA) ?>
          <?php endif; ?>
        </section>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
