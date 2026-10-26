<?php
/**
 * Abertura padrão das páginas do painel: <head>, menu lateral, cabeçalho e avisos.
 * Antes de incluir, a página define $titulo, $subtitulo e $paginaAtual.
 */
require_once __DIR__ . '/../../../backend/lib/bd.php';
require_once __DIR__ . '/../../../backend/lib/web.php';
$avisoLeitura = $_SESSION['flash_leitura'] ?? null;
unset($_SESSION['flash_leitura']);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($titulo) ?> · XTEC</title>
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

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1><?= e($titulo) ?></h1>
            <p><?= e($subtitulo) ?></p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <?php if ($flash = flash_ler()): ?>
          <div class="aviso aviso-<?= $flash['tipo'] === 'ok' ? 'ok' : 'erro' ?>" role="<?= $flash['tipo'] === 'ok' ? 'status' : 'alert' ?>">
            <i class="fa-solid <?= $flash['tipo'] === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" aria-hidden="true"></i>
            <span><?= e($flash['mensagem']) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($avisoLeitura): ?>
          <div class="aviso aviso-erro" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?= e($avisoLeitura) ?></span></div>
        <?php endif; ?>
