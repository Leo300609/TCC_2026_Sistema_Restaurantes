<?php
declare(strict_types=1);

/**
 * Base das APIs internas do painel (respostas JSON).
 * Exige usuário logado e expõe $perm com as permissões do perfil.
 */
require_once __DIR__ . '/../lib/sessao.php';
require_once __DIR__ . '/../lib/bd.php';
require_once __DIR__ . '/../lib/web.php';

ini_set('display_errors', '0');
iniciar_sessao();

if (!isset($_SESSION['usuario_id'])) {
    json_saida(['erro' => 'Sessão expirada. Entre novamente.'], 401);
}

$perm = permissoes((string) ($_SESSION['usuario_nivel'] ?? ''), (string) ($_SESSION['usuario_cargo'] ?? ''));

// a API só lê dados; libera a sessão para não travar outras abas
session_write_close();
