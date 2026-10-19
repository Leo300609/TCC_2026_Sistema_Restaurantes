<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['usuario_id'])) {
    header('Location: html/login.html');
    exit();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$nomeUsuario = htmlspecialchars(
    $_SESSION['usuario_nome'] ?? 'Usuário',
    ENT_QUOTES,
    'UTF-8',
);
$cargo = htmlspecialchars(
    $_SESSION['usuario_cargo'] ?? 'Equipe',
    ENT_QUOTES,
    'UTF-8',
);
$activePage = $activePage ?? basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? 'Painel';
$pageSubtitle = $pageSubtitle ?? 'Gestão do restaurante';
$isAdmin = ($_SESSION['usuario_nivel'] ?? '') === 'ADMIN';
$canSeeFinance = $isAdmin || ($_SESSION['usuario_cargo'] ?? '') === 'Gerente';
$canSeeStock =
    ($_SESSION['usuario_nivel'] ?? '') === 'ADMIN' ||
    in_array($_SESSION['usuario_cargo'] ?? '', ['Gerente', 'Cozinheiro'], true);
$nav = [
    ['dashboard.php', 'Visão Geral', 'fa-chart-line', true],
    ['fluxo_de_caixa.php', 'Fluxo de Caixa', 'fa-wallet', $canSeeFinance],
    ['estoque.php', 'Estoque', 'fa-boxes-stacked', $canSeeStock],
    ['pedidos.php', 'Pedidos', 'fa-receipt', true],
    ['cardapios.php', 'Cardápio', 'fa-utensils', true],
    ['clientes.php', 'Clientes', 'fa-users', true],
    ['empresa.php', 'Empresa', 'fa-city', $isAdmin],
    ['configs.php', 'Configurações', 'fa-gear', $isAdmin],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="csrf-token" content="<?= htmlspecialchars(
            $_SESSION['csrf_token'],
            ENT_QUOTES,
            'UTF-8',
        ) ?>" />
        <title><?= htmlspecialchars(
            $pageTitle,
            ENT_QUOTES,
            'UTF-8',
        ) ?> | XTEC</title>
        <link
            rel="stylesheet"
            href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        />
        <link rel="stylesheet" href="css/dashboard.css" />
        <link rel="stylesheet" href="css/visao_geral.css" />
        <link rel="stylesheet" href="css/admin-pages.css" />
    </head>
    <body>
        <div class="dashboard-layout">
            <aside class="sidebar">
                <div>
                    <div class="sidebar-header"><h2 class="nome-empresa">XTEC</h2></div>
                    <nav class="sidebar-nav" aria-label="Navegação principal">
                        <?php foreach (
                            $nav
                            as [$file, $label, $icon, $visible]
                        ):
                            if (!$visible) {
                                continue;
                            } ?>
                        <a
                            href="<?= htmlspecialchars(
                                $file,
                                ENT_QUOTES,
                                'UTF-8',
                            ) ?>"
                            class="nav-item <?= $activePage === $file
                                ? 'active'
                                : '' ?>"
                            <?= $activePage === $file
                                ? 'aria-current="page"'
                                : '' ?>
                        >
                            <i class="fa-solid <?= htmlspecialchars(
                                $icon,
                                ENT_QUOTES,
                                'UTF-8',
                            ) ?>"></i>
                            <span><?= htmlspecialchars(
                                $label,
                                ENT_QUOTES,
                                'UTF-8',
                            ) ?></span>
                        </a>
                        <?php
                        endforeach; ?>
                    </nav>
                </div>
                <div class="sidebar-footer">
                    <span class="user-name">
                        <?= $nomeUsuario ?>
                        <br />
                        <small><?= $cargo ?></small>
                    </span>
                    <a href="../backend/logout.php" class="btn-sair">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Sair</span>
                    </a>
                </div>
            </aside>
            <main class="main-content">
                <header class="topbar">
                    <div class="boas-vindas">
                        <h1><?= htmlspecialchars(
                            $pageTitle,
                            ENT_QUOTES,
                            'UTF-8',
                        ) ?></h1>
                        <?php if ($pageSubtitle !== ''): ?>
                            <p><?= htmlspecialchars(
                                $pageSubtitle,
                                ENT_QUOTES,
                                'UTF-8',
                            ) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="usuario-perfil">
                        <span class="status-loja online">
                            <i class="fa-solid fa-circle"></i>
                            Sistema
                        </span>
                        <span class="nome-admin"><?= $nomeUsuario ?></span>
                    </div>
                </header>
                <div
                    id="page-message"
                    class="page-message"
                    role="status"
                    aria-live="polite"
                    hidden
                ></div>
                <div class="module-content">
                    <script>
                        window.XTEC = {
                            endpoint: '../backend/api/management.php',
                            csrf: document.querySelector('meta[name="csrf-token"]').content,
                        };
                        window.apiGet = async function (action) {
                            const r = await fetch(
                                `${window.XTEC.endpoint}?action=${encodeURIComponent(action)}`,
                                { headers: { Accept: 'application/json' } },
                            );
                            const d = await r.json();
                            if (!r.ok) throw new Error(d.error || 'Falha na solicitação.');
                            return d;
                        };
                        window.apiPost = async function (action, data = {}) {
                            const r = await fetch(window.XTEC.endpoint, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': window.XTEC.csrf,
                                    Accept: 'application/json',
                                },
                                body: JSON.stringify({ ...data, action }),
                            });
                            const d = await r.json();
                            if (!r.ok) throw new Error(d.error || 'Falha na solicitação.');
                            return d;
                        };
                        window.escapeHtml = function (value) {
                            return String(value ?? '').replace(
                                /[&<>"']/g,
                                (c) =>
                                    ({
                                        '&': '&amp;',
                                        '<': '&lt;',
                                        '>': '&gt;',
                                        '"': '&quot;',
                                        "'": '&#39;',
                                    })[c],
                            );
                        };
                        window.showMessage = function (message, error = false) {
                            const el = document.getElementById('page-message');
                            el.textContent = message;
                            el.className = 'page-message ' + (error ? 'error' : 'success');
                            el.hidden = false;
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                            setTimeout(() => {
                                el.hidden = true;
                            }, 7000);
                        };
                    </script>
