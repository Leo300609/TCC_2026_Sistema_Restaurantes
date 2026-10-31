<?php
/**
 * Abertura padrão das páginas do painel: <head>, menu lateral, barra superior e avisos.
 * Antes de incluir, a página define $titulo, $subtitulo e $paginaAtual.
 * Opcionais: $cabecaExtra (HTML extra no <head>, ex.: Chart.js) e $classeCorpo.
 */
require_once __DIR__ . '/../../../backend/lib/bd.php';
require_once __DIR__ . '/../../../backend/lib/web.php';

$avisoLeitura = $_SESSION['flash_leitura'] ?? null;
unset($_SESSION['flash_leitura']);

// Empresa e horário (guardados na sessão por 5 minutos)
if (($_SESSION['cache_empresa']['em'] ?? 0) < time() - 300) {
  $_emp = ler(fn() => chamar_um('sp_empresa_obter'), null);
  $_SESSION['cache_empresa'] = [
    'em' => time(),
    'nome' => $_emp['nome_fantasia'] ?? 'XTEC',
    'horarios' => ler(fn() => chamar('sp_empresa_horarios'), []),
  ];
}
$nomeLoja = $_SESSION['cache_empresa']['nome'] ?: 'XTEC';
$lojaAberta = false;
$agoraHora = date('H:i');
foreach ($_SESSION['cache_empresa']['horarios'] as $_h) {
  if ((int) $_h['dia'] === (int) date('N') && (int) $_h['aberto'] === 1) {
    $lojaAberta = $agoraHora >= $_h['abre'] && $agoraHora <= $_h['fecha'];
  }
}

// Contadores do menu e notificações
$etapasMenu = ler(fn() => chamar_um('sp_dash_etapas'), null) ?? ['novos' => 0, 'preparo' => 0, 'prontos' => 0];
$notificacoes = ler(fn() => chamar('sp_notificacoes', [(int) $_SESSION['usuario_id'], $perm['estoque'] ? 1 : 0, $perm['financeiro'] ? 1 : 0]), []);
$qtdNotificacoes = count($notificacoes);

$iniciais = static function (string $nome): string {
  $partes = preg_split('/\s+/', trim($nome)) ?: [];
  $p = mb_substr($partes[0] ?? '?', 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : '');
  return mb_strtoupper($p);
};
$corAvatar = static fn(string $nome): string => 'cor-' . (abs(crc32($nome)) % 5 + 1);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= e($_SESSION['csrf_token'] ?? '') ?>">
  <meta name="theme-color" content="#d7262e">
  <title><?= e($titulo) ?> · <?= e(config('app_nome')) ?></title>
  <script>
    /* tema e menu recolhido antes de pintar a página (evita piscar) */
    (function () {
      var d = document.documentElement, t = null, m = null;
      try { t = localStorage.getItem('xtec-tema'); m = localStorage.getItem('xtec-menu'); } catch (e) {}
      if (t !== 'claro' && t !== 'escuro') t = matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
      d.setAttribute('data-tema', t);
      if (m === 'recolhido') d.classList.add('menu-recolhido');
    })();
  </script>
  <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../css/painel/app.css?v=<?= e(config('app_versao')) ?>">
  <?= $cabecaExtra ?? '' ?>
</head>

<body class="<?= e($classeCorpo ?? '') ?>" data-pagina="<?= e($paginaAtual) ?>">
  <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>

  <div class="dashboard-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1><?= e($titulo) ?></h1>
            <p><?= e($subtitulo) ?></p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja <?= $lojaAberta ? 'online' : 'fechada' ?>" title="Segundo o horário cadastrado em Empresa">
              <i class="fa-solid fa-circle" aria-hidden="true"></i> <?= $lojaAberta ? 'Loja aberta' : 'Loja fechada' ?>
            </span>

            <button type="button" class="topo-busca" data-abrir-paleta aria-label="Buscar no sistema (Ctrl + K)">
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <span>Buscar…</span>
              <kbd>Ctrl K</kbd>
            </button>

            <div class="suspenso">
              <button type="button" class="topo-icone" id="btn-notificacoes" data-suspenso="painel-notificacoes" aria-expanded="false" aria-controls="painel-notificacoes" aria-label="Notificações<?= $qtdNotificacoes ? ": {$qtdNotificacoes}" : '' ?>">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <?php if ($qtdNotificacoes): ?><span class="bolinha" id="notif-contagem"><?= $qtdNotificacoes > 9 ? '9+' : $qtdNotificacoes ?></span><?php endif; ?>
              </button>
              <div class="suspenso-painel largo" id="painel-notificacoes" hidden>
                <div class="notif-topo">
                  <h2>Notificações</h2>
                  <a class="btn-link" href="configs.php#notificacoes">Preferências</a>
                </div>
                <ul class="notif-lista" id="notif-lista">
                  <?php if (!$notificacoes): ?>
                    <li class="notif-vazio"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Tudo em dia por aqui.</li>
                  <?php endif; ?>
                  <?php foreach (array_slice($notificacoes, 0, 25) as $_n):
                    $_iconeNotif = ['PEDIDO' => 'fa-receipt', 'ESTOQUE' => 'fa-box-open', 'VALIDADE' => 'fa-calendar-xmark', 'CONTA' => 'fa-file-invoice-dollar'][$_n['tipo']] ?? 'fa-bell'; ?>
                    <li class="notif-item <?= e($_n['nivel']) ?>">
                      <a href="<?= e($_n['link']) ?>">
                        <span class="notif-icone"><i class="fa-solid <?= $_iconeNotif ?>" aria-hidden="true"></i></span>
                        <span><strong><?= e($_n['titulo']) ?></strong><small><?= e($_n['detalhe']) ?></small></span>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

            <button type="button" class="topo-icone" id="btn-tema" aria-label="Alternar tema claro e escuro">
              <i class="fa-solid fa-moon" aria-hidden="true"></i>
            </button>

            <div class="suspenso">
              <button type="button" class="usuario-botao" data-suspenso="menu-usuario" aria-expanded="false" aria-controls="menu-usuario">
                <span class="avatar <?= $corAvatar($_SESSION['usuario_nome'] ?? '') ?>" aria-hidden="true"><?= e($iniciais($_SESSION['usuario_nome'] ?? '')) ?></span>
                <strong><?= $nomeUsuario ?></strong>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
              </button>
              <div class="suspenso-painel" id="menu-usuario" hidden>
                <div class="menu-cabeca">
                  <strong><?= $nomeUsuario ?></strong>
                  <small><?= e(rotulo_cargo($cargo, $nivel)) ?></small>
                </div>
                <a class="menu-opcao" href="configs.php#conta"><i class="fa-regular fa-user" aria-hidden="true"></i> Minha conta</a>
                <a class="menu-opcao" href="configs.php#aparencia"><i class="fa-solid fa-palette" aria-hidden="true"></i> Aparência</a>
                <button type="button" class="menu-opcao" data-abrir-paleta><i class="fa-regular fa-keyboard" aria-hidden="true"></i> Atalhos e busca</button>
                <a class="menu-opcao perigo" href="../../backend/auth/logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sair</a>
              </div>
            </div>
          </div>
        </header>

        <div class="toasts" id="toasts" aria-live="polite">
          <?php if ($flash = flash_ler()): ?>
            <div class="aviso aviso-<?= $flash['tipo'] === 'ok' ? 'ok' : 'erro' ?>" role="<?= $flash['tipo'] === 'ok' ? 'status' : 'alert' ?>">
              <i class="fa-solid <?= $flash['tipo'] === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" aria-hidden="true"></i>
              <span><?= e($flash['mensagem']) ?></span>
              <button type="button" class="aviso-fechar" aria-label="Fechar aviso"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($avisoLeitura): ?>
          <div class="aviso aviso-erro" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?= e($avisoLeitura) ?></span></div>
        <?php endif; ?>
