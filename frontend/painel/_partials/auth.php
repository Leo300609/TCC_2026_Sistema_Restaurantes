<?php
/**
 * Guarda de acesso do painel: sessão segura, expiração por inatividade,
 * token CSRF e permissões por perfil. Incluído no topo de todas as páginas
 * de frontend/painel/.
 *
 * Variáveis disponíveis para as páginas:
 *   $nivel, $cargo, $nomeUsuario (já escapado), $perm (array de permissões)
 *   $verFinanceiro, $verEstoque (atalhos usados pelas telas antigas)
 */
require_once __DIR__ . '/../../../backend/lib/sessao.php';
require_once __DIR__ . '/../../../backend/lib/bd.php';
require_once __DIR__ . '/../../../backend/lib/web.php';

iniciar_sessao();

if (!isset($_SESSION['usuario_id'])) {
  header('Location: ../html/login.html');
  exit;
}

// Expira a sessão depois de um tempo sem uso
$agora = time();
$limite = (int) config('sessao_minutos') * 60;
if (isset($_SESSION['ultimo_uso']) && $agora - (int) $_SESSION['ultimo_uso'] > $limite) {
  registrar_atividade('sessao', 'expirou', 'Sessão encerrada por inatividade');
  encerrar_sessao();
  header('Location: ../html/login.html?erro=sessao');
  exit;
}
$_SESSION['ultimo_uso'] = $agora;

// A cada minuto confirma no banco se o usuário continua ativo e atualiza
// nome, cargo e nível (mudanças feitas pelo gerente valem sem novo login).
if ($agora - (int) ($_SESSION['conferido_em'] ?? 0) >= 60) {
  try {
    $atual = chamar_um('sp_usuario_acesso', [(int) $_SESSION['usuario_id']]);
    if (!$atual || $atual['ativo'] !== 'ATIVO') {
      encerrar_sessao();
      header('Location: ../html/login.html?erro=inativo');
      exit;
    }
    $_SESSION['usuario_nome'] = $atual['nome'];
    $_SESSION['usuario_nivel'] = $atual['nivel'];
    $_SESSION['usuario_cargo'] = $atual['cargo'];
    $_SESSION['conferido_em'] = $agora;
  } catch (Throwable $e) {
    error_log('[tcc] acesso: ' . $e->getMessage());
  }
}

$cargo = $_SESSION['usuario_cargo'] ?? 'FUNCIONARIO';
$nivel = $_SESSION['usuario_nivel'] ?? 'FUNCIONARIO';
$nomeUsuario = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário');
$perm = permissoes($nivel, $cargo);

// O que cada perfil pode ver (nomes usados pelas telas)
$verFinanceiro = $perm['financeiro'];
$verEstoque = $perm['estoque'];

/** Bloqueia a página para quem não tem a permissão (volta para a Visão Geral com aviso). */
function exigir_permissao(bool $pode): void
{
  if (!$pode) {
    flash('erro', 'Você não tem acesso a essa área do sistema.');
    header('Location: dashboard.php');
    exit;
  }
}
