<?php
declare(strict_types=1);

/**
 * Sessão segura, permissões por perfil e registro de atividades (auditoria).
 */

require_once __DIR__ . '/../config/config.php';

/** Abre a sessão com cookie HttpOnly/SameSite e modo estrito (uma vez por requisição). */
function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('XTECSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function ip_cliente(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Encerra a sessão por completo (dados e cookie). */
function encerrar_sessao(): void
{
    iniciar_sessao();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Permissões de cada perfil. ADMIN vê tudo; o cargo define o restante.
 * @return array<string, bool>
 */
function permissoes(string $nivel, string $cargo): array
{
    $admin = $nivel === 'ADMIN';
    $gerente = $admin || $cargo === 'Gerente';
    $cozinha = $cargo === 'Cozinheiro';
    $entregador = $cargo === 'Entregador';

    return [
        'admin' => $admin,
        'gerente' => $gerente,
        'financeiro' => $gerente,
        'estoque' => $gerente || $cozinha,
        'compras' => $gerente,
        'relatorios' => $gerente,
        'auditoria' => $gerente,
        'pdv' => !$cozinha && !$entregador,
        'mesas' => !$cozinha && !$entregador,
        'cozinha' => !$entregador,
    ];
}

/** Rótulo amigável do cargo. */
function rotulo_cargo(string $cargo, string $nivel = ''): string
{
    return match (true) {
        $cargo === 'ADMIN', $cargo === 'FUNCIONARIO' && $nivel === 'ADMIN' => 'Administrador',
        $cargo === 'FUNCIONARIO' => 'Funcionário',
        default => $cargo,
    };
}

/**
 * Grava uma linha na auditoria. Nunca interrompe a página: se a tabela ainda
 * não existir (banco sem migracao_modulos.sql) o erro só vai para o log.
 */
function registrar_atividade(string $modulo, string $acao, string $descricao, ?int $usuarioId = null, ?string $usuarioNome = null): void
{
    try {
        require_once __DIR__ . '/bd.php';
        chamar('sp_atividade_registrar', [
            $usuarioId ?? (int) ($_SESSION['usuario_id'] ?? 0),
            $usuarioNome ?? (string) ($_SESSION['usuario_nome'] ?? ''),
            $modulo,
            $acao,
            mb_substr($descricao, 0, 500),
            ip_cliente(),
        ]);
    } catch (Throwable $e) {
        error_log('[tcc] auditoria: ' . $e->getMessage());
    }
}
