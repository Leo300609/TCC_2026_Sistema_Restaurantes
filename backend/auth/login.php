<?php
declare(strict_types=1);

/**
 * Login: senha conferida com password_verify, bloqueio após várias tentativas
 * erradas (por e-mail e por IP), sessão nova a cada entrada e atualização
 * automática de senhas antigas (texto puro) para hash bcrypt.
 */
require_once __DIR__ . '/../lib/sessao.php';
require_once __DIR__ . '/../lib/bd.php';

iniciar_sessao();

$voltar = static function (string $erro, string $email = ''): never {
    $q = ['erro' => $erro];
    if ($email !== '') {
        $q['email'] = $email;
    }
    header('Location: ../../frontend/html/login.html?' . http_build_query($q));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ../../frontend/html/login.html');
    exit;
}

$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$senha = (string) ($_POST['senha'] ?? '');
$ip = ip_cliente();

if ($email === '' || $senha === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $voltar('dados', $email);
}

try {
    $falhas = chamar_um('sp_login_falhas', [$email, $ip]) ?? ['falhas_email' => 0, 'falhas_ip' => 0];
    $max = (int) config('login_max_falhas');
    if ((int) $falhas['falhas_email'] >= $max || (int) $falhas['falhas_ip'] >= $max * 4) {
        registrar_atividade('sessao', 'bloqueio', "Login bloqueado temporariamente para {$email}", 0, $email);
        $voltar('bloqueado', $email);
    }

    $usuario = chamar_um('sp_login_buscar', [$email]);
    $guardada = (string) ($usuario['senha'] ?? '');
    $hashValido = password_get_info($guardada)['algo'] !== null;

    // senhas legadas (texto puro no banco antigo) ainda entram, e são convertidas em seguida
    $confere = $usuario !== null && ($hashValido ? password_verify($senha, $guardada) : hash_equals($guardada, $senha));

    if (!$confere) {
        // gasta o mesmo tempo de um hash para não revelar se o e-mail existe
        if ($usuario === null) {
            password_verify($senha, '$2y$10$f9eLz9kh/hbzcyR4Ss/2ne8uLAXaCChK5Aluiy6w4VHg1UI/yUnUK');
        }
        chamar('sp_login_registrar', [$email, $ip, 0]);
        $restantes = max(0, $max - (int) $falhas['falhas_email'] - 1);
        $voltar($restantes > 0 && $restantes <= 2 ? 'restam' . $restantes : 'credenciais', $email);
    }

    if ($usuario['ativo'] !== 'ATIVO') {
        chamar('sp_login_registrar', [$email, $ip, 0]);
        $voltar('inativo', $email);
    }

    $novoHash = (!$hashValido || password_needs_rehash($guardada, PASSWORD_BCRYPT)) ? password_hash($senha, PASSWORD_BCRYPT) : '';
    chamar('sp_login_sucesso', [(int) $usuario['id'], $novoHash]);
    chamar('sp_login_registrar', [$email, $ip, 1]);

    // sessão nova antes de gravar a identidade (evita fixação de sessão)
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_nivel'] = $usuario['nivel'];
    $_SESSION['usuario_cargo'] = $usuario['cargo'];
    $_SESSION['id_empresa'] = 1;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['ultimo_uso'] = time();
    $_SESSION['conferido_em'] = time();

    registrar_atividade('sessao', 'login', 'Entrou no sistema');

    // cozinheiro cai direto na tela da cozinha; os demais, na Visão Geral
    $destino = $usuario['cargo'] === 'Cozinheiro' ? 'cozinha.php' : 'dashboard.php';
    header('Location: ../../frontend/painel/' . $destino);
    exit;
} catch (Throwable $e) {
    error_log('[tcc] login: ' . $e->getMessage());
    $voltar('banco', $email);
}
