<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';

$uid = (int) $_SESSION['usuario_id'];
$podeOperacao = $verFinanceiro; // administrador ou gerente
$formasPagamento = ['PIX' => ['fa-brands fa-pix', 'Pix'], 'CREDITO' => ['fa-regular fa-credit-card', 'Crédito'], 'DEBITO' => ['fa-solid fa-credit-card', 'Débito'], 'DINHEIRO' => ['fa-solid fa-money-bill-wave', 'Dinheiro'], 'BOLETO' => ['fa-solid fa-barcode', 'Boleto']];

// ---------- ações (POST) ----------
tratar_post(function () use ($uid, $podeOperacao, $formasPagamento) {
    $nome = entrada('nome');
    $email = entrada('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ErroNegocio('Informe um e-mail válido.');
    }

    // senha: só troca se a nova foi preenchida (a conferência usa o mesmo critério do login)
    $novoHash = '';
    $senhaNova = (string) ($_POST['senha_nova'] ?? '');
    if ($senhaNova !== '') {
        $atual = (string) ($_POST['senha_atual'] ?? '');
        $guardada = (string) chamar_valor('sp_config_senha_atual', [$uid]);
        if ($atual === '' || !(password_verify($atual, $guardada) || hash_equals($guardada, $atual))) {
            throw new ErroNegocio('A senha atual está incorreta.');
        }
        if (mb_strlen($senhaNova) < 8) {
            throw new ErroNegocio('A nova senha precisa ter ao menos 8 caracteres.');
        }
        $novoHash = password_hash($senhaNova, PASSWORD_BCRYPT);
    }

    $dias = entrada_int('dias_validade', 3);
    if ($dias < 1 || $dias > 60) {
        throw new ErroNegocio('Informe de 1 a 60 dias de antecedência para a validade.');
    }

    $pagamentos = null;
    if ($podeOperacao) {
        $formas = array_values(array_unique(array_filter((array) ($_POST['pagamentos'] ?? []), 'is_string')));
        if (!$formas || array_diff($formas, array_keys($formasPagamento))) {
            throw new ErroNegocio('Escolha ao menos uma forma de pagamento válida.');
        }
        $pagamentos = implode(',', $formas);
    }

    transacao(function () use ($uid, $nome, $email, $novoHash, $dias, $pagamentos, $podeOperacao) {
        chamar('sp_config_conta_salvar', [$uid, $nome, $email, $novoHash]);
        foreach (['notif_pedido', 'notif_minimo', 'notif_validade', 'notif_contas'] as $chave) {
            chamar('sp_config_definir', [$uid, $chave, isset($_POST[$chave]) ? '1' : '0']);
        }
        chamar('sp_config_definir', [$uid, 'dias_validade', (string) $dias]);

        if ($podeOperacao) {
            chamar('sp_config_operacao_salvar', [isset($_POST['aceita_delivery']) ? 1 : 0, entrada_int('tempo_preparo', 20), $pagamentos]);
        }
    });

    $_SESSION['usuario_nome'] = $nome;
    return $novoHash === '' ? 'Configurações salvas.' : 'Configurações salvas e senha alterada.';
});

// ---------- dados (GET) ----------
$conta = ler(fn() => chamar_um('sp_config_conta', [$uid]), null) ?? ['nome' => $_SESSION['usuario_nome'] ?? '', 'email' => ''];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), null) ?? [
    'notif_pedido' => '1', 'notif_minimo' => '1', 'notif_validade' => '1', 'notif_contas' => '0', 'dias_validade' => '3',
    'aceita_delivery' => '1', 'tempo_preparo' => '20', 'pagamentos' => 'PIX,CREDITO,DEBITO,DINHEIRO',
];
$pagamentosAtivos = explode(',', (string) $cfg['pagamentos']);

/** Opções de um <select> que sempre incluem o valor salvo. */
function opcoes_select(array $padrao, string $atual, string $unidade): string
{
    $valores = array_unique(array_merge($padrao, [(int) $atual]));
    sort($valores);
    $h = '';
    foreach ($valores as $v) {
        $h .= '<option value="' . $v . '"' . ((string) $v === $atual ? ' selected' : '') . '>' . $v . ' ' . ($unidade === 'dia' ? ($v === 1 ? 'dia' : 'dias') : $unidade) . '</option>';
    }
    return $h;
}

$desabilitaOperacao = $podeOperacao ? '' : ' disabled';

$titulo = 'Configurações';
$subtitulo = 'Sua conta, notificações e a operação da loja';
$paginaAtual = 'configs';
include __DIR__ . '/_partials/topo.php';
?>

        <div class="config-grid">

          <nav class="config-indice" aria-label="Seções de configurações">
            <a href="#conta">Conta</a>
            <a href="#notificacoes">Notificações</a>
            <a href="#operacao">Operação</a>
            <a href="#aparencia">Aparência</a>
            <a href="#seguranca">Segurança</a>
          </nav>

          <form class="config-secoes" method="post" action="<?= e(url()) ?>">
            <?= csrf_campo() ?>

            <section class="painel-card" id="conta" aria-labelledby="titulo-conta">
              <div class="painel-header">
                <h2 id="titulo-conta">Conta</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome">Nome</label>
                  <input type="text" id="nome" name="nome" class="input-dash" maxlength="255" required autocomplete="name" value="<?= e($conta['nome']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="email">E-mail</label>
                  <input type="email" id="email" name="email" class="input-dash" maxlength="255" required autocomplete="email" value="<?= e($conta['email']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="senha_atual">Senha atual</label>
                  <input type="password" id="senha_atual" name="senha_atual" class="input-dash" autocomplete="current-password">
                </div>
                <div class="grupo-input">
                  <label for="senha_nova">Nova senha</label>
                  <input type="password" id="senha_nova" name="senha_nova" class="input-dash" minlength="8" autocomplete="new-password" aria-describedby="dica-senha">
                  <small id="dica-senha">Deixe em branco para manter a atual. Use ao menos 8 caracteres.</small>
                </div>
              </div>
            </section>

            <section class="painel-card" id="notificacoes" aria-labelledby="titulo-notificacoes">
              <div class="painel-header">
                <h2 id="titulo-notificacoes">Notificações</h2>
              </div>
              <ul class="config-linhas">
                <?php foreach ([
                    ['notif_pedido', 'n-pedido', 'Novo pedido recebido', 'Avisa quando um pedido entra no quadro.'],
                    ['notif_minimo', 'n-minimo', 'Estoque abaixo do mínimo', 'Avisa quando um item chega no nível mínimo.'],
                ] as [$nomeCampo, $idRotulo, $rotulo, $dica]): ?>
                  <li class="config-linha">
                    <div class="config-texto"><strong id="<?= $idRotulo ?>"><?= $rotulo ?></strong><small><?= $dica ?></small></div>
                    <label class="switch"><input type="checkbox" role="switch" name="<?= $nomeCampo ?>" aria-labelledby="<?= $idRotulo ?>"<?= $cfg[$nomeCampo] === '1' ? ' checked' : '' ?>><span class="switch-trilho" aria-hidden="true"></span></label>
                  </li>
                <?php endforeach; ?>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="n-validade">Produtos perto do vencimento</strong>
                    <small>Antecedência do aviso de validade.</small>
                  </div>
                  <div class="config-controle">
                    <label class="sr-only" for="dias_validade">Dias de antecedência</label>
                    <select id="dias_validade" name="dias_validade" class="input-dash"><?= opcoes_select([1, 2, 3, 7], (string) (int) $cfg['dias_validade'], 'dia') ?></select>
                    <label class="switch"><input type="checkbox" role="switch" name="notif_validade" aria-labelledby="n-validade"<?= $cfg['notif_validade'] === '1' ? ' checked' : '' ?>><span class="switch-trilho" aria-hidden="true"></span></label>
                  </div>
                </li>
                <li class="config-linha">
                  <div class="config-texto"><strong id="n-contas">Contas a pagar vencendo</strong><small>Avisa sobre contas perto do vencimento.</small></div>
                  <label class="switch"><input type="checkbox" role="switch" name="notif_contas" aria-labelledby="n-contas"<?= $cfg['notif_contas'] === '1' ? ' checked' : '' ?>><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
              </ul>
            </section>

            <section class="painel-card" id="operacao" aria-labelledby="titulo-operacao">
              <div class="painel-header">
                <h2 id="titulo-operacao">Operação</h2>
              </div>
              <?php if (!$podeOperacao): ?><p class="painel-nota">Somente administradores e gerentes podem alterar a operação.</p><?php endif; ?>
              <ul class="config-linhas">
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="o-delivery">Receber pedidos de delivery</strong>
                    <small>Desligue para pausar o delivery.</small>
                  </div>
                  <label class="switch"><input type="checkbox" role="switch" name="aceita_delivery" aria-labelledby="o-delivery"<?= $cfg['aceita_delivery'] === '1' ? ' checked' : '' ?><?= $desabilitaOperacao ?>><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong><label for="tempo_preparo">Tempo médio de preparo</label></strong>
                    <small>Pedidos acima disso aparecem como atrasados no quadro.</small>
                  </div>
                  <select id="tempo_preparo" name="tempo_preparo" class="input-dash config-select"<?= $desabilitaOperacao ?>><?= opcoes_select([15, 20, 30, 45], (string) (int) $cfg['tempo_preparo'], 'minutos') ?></select>
                </li>
                <li class="config-linha config-linha-coluna">
                  <div class="config-texto">
                    <strong id="o-pagamentos">Formas de pagamento aceitas</strong>
                    <small>Aparecem na hora de fechar a conta.</small>
                  </div>
                  <div class="opcoes" role="group" aria-labelledby="o-pagamentos">
                    <?php foreach ($formasPagamento as $valor => [$icone, $rotulo]): ?>
                      <label class="opcao"><input type="checkbox" name="pagamentos[]" value="<?= $valor ?>"<?= in_array($valor, $pagamentosAtivos, true) ? ' checked' : '' ?><?= $desabilitaOperacao ?>><span><i class="<?= $icone ?>" aria-hidden="true"></i> <?= $rotulo ?></span></label>
                    <?php endforeach; ?>
                  </div>
                </li>
              </ul>
            </section>

            <section class="painel-card" id="aparencia" aria-labelledby="titulo-aparencia">
              <div class="painel-header">
                <h2 id="titulo-aparencia">Aparência</h2>
                <span class="painel-meta">Vale para este navegador</span>
              </div>
              <div class="painel-corpo">
                <div class="tema-opcoes" role="radiogroup" aria-labelledby="titulo-aparencia">
                  <label class="tema-opcao claro"><input type="radio" name="tema_visual" value="claro" form="nao-enviar"><span>Claro</span></label>
                  <label class="tema-opcao escuro"><input type="radio" name="tema_visual" value="escuro" form="nao-enviar"><span>Escuro</span></label>
                  <label class="tema-opcao sistema"><input type="radio" name="tema_visual" value="sistema" form="nao-enviar"><span>Igual ao sistema</span></label>
                </div>
              </div>
            </section>

            <section class="painel-card" id="seguranca" aria-labelledby="titulo-seguranca">
              <div class="painel-header">
                <h2 id="titulo-seguranca">Segurança</h2>
              </div>
              <ul class="config-linhas">
                <li class="config-linha">
                  <div class="config-texto">
                    <strong>Encerrar sessão</strong>
                    <small>Sai da sua conta neste dispositivo.</small>
                  </div>
                  <a href="../../backend/auth/logout.php" class="btn btn-perigo"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sair agora</a>
                </li>
              </ul>
            </section>

            <div class="barra-salvar">
              <a class="btn btn-fantasma" href="<?= e(url()) ?>">Descartar alterações</a>
              <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
            </div>
          </form>

        </div>

<?php
$rodapeExtra = <<<'HTML'
<script>
  (() => {
    let atual = 'sistema';
    try { atual = localStorage.getItem('xtec-tema') || 'sistema'; } catch (e) { /* ignora */ }
    document.querySelectorAll('input[name=tema_visual]').forEach((r) => {
      r.checked = r.value === atual;
      r.addEventListener('change', () => window.xtecDefinirTema?.(r.value));
    });
  })();
</script>
HTML;
include __DIR__ . '/_partials/rodape.php';
?>
