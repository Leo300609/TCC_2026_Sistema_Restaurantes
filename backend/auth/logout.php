<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/sessao.php';

iniciar_sessao();

if (isset($_SESSION['usuario_id'])) {
    registrar_atividade('sessao', 'logout', 'Saiu do sistema');
}

// Apaga os dados e o cookie da sessão no servidor e no navegador.
encerrar_sessao();

header('Location: ../../frontend/html/login.html?ok=saiu');
exit;
