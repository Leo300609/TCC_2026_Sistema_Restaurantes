<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';

$podeEditar = $verFinanceiro;                 // administrador ou gerente
$souAdmin = $nivel === 'ADMIN';
$meuId = (int) $_SESSION['usuario_id'];
$cargosEquipe = ['Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente', 'FUNCIONARIO'];
$diasSemana = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

// ---------- ações (POST) ----------
tratar_post(function () use ($podeEditar, $souAdmin, $meuId, $diasSemana) {
    if (!$podeEditar) {
        throw new ErroNegocio('Somente administradores e gerentes podem alterar a empresa e a equipe.');
    }
    $acao = entrada('acao');

    if ($acao === 'salvar_empresa') {
        transacao(function () use ($diasSemana) {
            chamar('sp_empresa_salvar', [
                entrada('nome_fantasia'), entrada('razao_social'), entrada('cnpj'), entrada('telefone'), entrada('email_contato'),
                entrada('endereco'), entrada('endereco_numero'), strtoupper(entrada('estado')), entrada('cidade'),
            ]);
            $abertos = (array) ($_POST['aberto'] ?? []);
            $abre = (array) ($_POST['abre'] ?? []);
            $fecha = (array) ($_POST['fecha'] ?? []);
            foreach (array_keys($diasSemana) as $dia) {
                $hora = static fn($v, $padrao) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? (string) $v : $padrao;
                chamar('sp_empresa_horario_salvar', [$dia, isset($abertos[$dia]) ? 1 : 0, $hora($abre[$dia] ?? '', '08:00'), $hora($fecha[$dia] ?? '', '22:00')]);
            }
        });
        return 'Dados da empresa salvos.';
    }

    if ($acao === 'salvar_membro') {
        $id = entrada_int('id');
        $atual = chamar_um('sp_equipe_obter', [$id]);
        if (!$atual) {
            throw new ErroNegocio('Funcionário não encontrado.');
        }
        // quem não é admin não envia o nível (campo desabilitado): mantém o atual
        $nivelNovo = $souAdmin && in_array(entrada('nivel'), ['ADMIN', 'FUNCIONARIO'], true) ? entrada('nivel') : $atual['nivel'];
        chamar('sp_equipe_atualizar', [$id, entrada('nome'), entrada('cargo'), $nivelNovo, entrada('ativo'), $meuId, $souAdmin ? 1 : 0]);
        return ['Funcionário atualizado.', url(['membro' => null])];
    }

    if ($acao === 'convidar') {
        $nivelConvite = $souAdmin && entrada('nivel') === 'ADMIN' ? 'ADMIN' : 'FUNCIONARIO';
        // sem letras parecidas (O/0, I/1) para o código ser lido em voz alta sem erro
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codigo = 'XTEC-';
        for ($i = 0; $i < 6; $i++) {
            $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $c = chamar_um('sp_convite_criar', [$codigo, entrada('cargo'), $nivelConvite, $meuId, entrada_int('dias', 7)]);
        $_SESSION['convite_novo'] = $codigo;
        return ["Convite {$codigo} criado para " . entrada('cargo') . ". Vale até {$c['expira']}.", url(['convite' => 1]) . '#convites'];
    }

    if ($acao === 'revogar_convite') {
        chamar('sp_convite_revogar', [entrada_int('id')]);
        return ['Convite revogado. O código não pode mais ser usado.', url() . '#convites'];
    }

    if ($acao === 'remover_membro') {
        $r = chamar_valor('sp_equipe_remover', [entrada_int('id'), $meuId, $souAdmin ? 1 : 0]);
        return [$r === 'INATIVADO' ? 'O funcionário tem histórico no sistema, então teve o acesso desativado em vez de ser removido.' : 'Funcionário removido.', url(['membro' => null, 'remover' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
$empresa = ler(fn() => chamar_um('sp_empresa_obter'), null) ?? [
    'nome_fantasia' => '', 'razao_social' => '', 'cnpj' => '', 'telefone' => '', 'email_contato' => '',
    'endereco' => '', 'endereco_numero' => '', 'estado' => 'SP', 'cidade' => '',
];
$horarios = [];
foreach (ler(fn() => chamar('sp_empresa_horarios')) as $h) {
    $horarios[(int) $h['dia']] = $h;
}
$estados = ler(fn() => chamar('sp_estado_listar'));
$equipe = ler(fn() => chamar('sp_equipe_listar'));
$ativos = count(array_filter($equipe, static fn($m) => $m['ativo'] !== 'INATIVO'));

$membroId = entrada_int('membro');
$membro = $podeEditar && $membroId > 0 ? ler(fn() => chamar_um('sp_equipe_obter', [$membroId]), null) : null;
$removerId = entrada_int('remover');
$alvoRemover = $podeEditar && $removerId > 0 ? ler(fn() => chamar_um('sp_equipe_obter', [$removerId]), null) : null;

$convites = $podeEditar ? ler(fn() => chamar('sp_convite_listar')) : [];
$conviteNovo = $_SESSION['convite_novo'] ?? null;
unset($_SESSION['convite_novo']);

$desabilitado = $podeEditar ? '' : ' disabled';

$titulo = 'Empresa';
$subtitulo = 'Dados do estabelecimento e da equipe';
$paginaAtual = 'empresa';
include __DIR__ . '/_partials/topo.php';
?>

        <?php if ($alvoRemover): ?>
          <section class="painel-card confirmar-card" aria-labelledby="t-remover">
            <h2 id="t-remover">Remover funcionário</h2>
            <p>Remover <strong><?= e($alvoRemover['nome']) ?></strong>? Se ele já tiver histórico no sistema, apenas o acesso será desativado.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="remover_membro">
              <input type="hidden" name="id" value="<?= (int) $alvoRemover['id'] ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remover</button>
              <a class="btn btn-secundario" href="<?= e(url(['remover' => null])) ?>">Cancelar</a>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($membro): ?>
          <section class="painel-card form-card" id="membro" aria-labelledby="t-membro">
            <div class="painel-header"><h2 id="t-membro">Editar <?= e($membro['nome']) ?></h2><a class="gaveta-fechar" href="<?= e(url(['membro' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="salvar_membro">
              <input type="hidden" name="id" value="<?= (int) $membro['id'] ?>">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="m-nome">Nome</label>
                  <input type="text" id="m-nome" name="nome" class="input-dash" maxlength="255" required value="<?= e($membro['nome']) ?>">
                </div>
                <div class="grupo-input largo">
                  <label for="m-email">E-mail de acesso</label>
                  <input type="text" id="m-email" class="input-dash" value="<?= e($membro['email']) ?>" disabled>
                </div>
                <div class="grupo-input">
                  <label for="m-cargo">Cargo</label>
                  <select id="m-cargo" name="cargo" class="input-dash">
                    <?php foreach ($cargosEquipe as $c): ?>
                      <option value="<?= e($c) ?>"<?= $c === $membro['cargo'] ? ' selected' : '' ?>><?= $c === 'FUNCIONARIO' ? 'Sem cargo definido' : e($c) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="m-nivel">Nível de acesso</label>
                  <select id="m-nivel" name="nivel" class="input-dash"<?= $souAdmin ? '' : ' disabled' ?>>
                    <option value="FUNCIONARIO"<?= $membro['nivel'] === 'FUNCIONARIO' ? ' selected' : '' ?>>Funcionário</option>
                    <option value="ADMIN"<?= $membro['nivel'] === 'ADMIN' ? ' selected' : '' ?>>Administrador</option>
                  </select>
                </div>
                <div class="grupo-input largo">
                  <label for="m-ativo">Acesso ao sistema</label>
                  <select id="m-ativo" name="ativo" class="input-dash">
                    <option value="ATIVO"<?= $membro['ativo'] === 'ATIVO' ? ' selected' : '' ?>>Ativo</option>
                    <option value="INATIVO"<?= $membro['ativo'] === 'INATIVO' ? ' selected' : '' ?>>Inativo (não consegue entrar)</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">Salvar alterações</button>
                <a class="btn btn-secundario" href="<?= e(url(['membro' => null])) ?>">Cancelar</a>
                <a class="btn btn-perigo" href="<?= e(url(['remover' => $membro['id'], 'membro' => null])) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remover</a>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <div class="empresa-grid">

          <form class="empresa-formularios" method="post" action="<?= e(url()) ?>">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="salvar_empresa">

            <section class="painel-card" aria-labelledby="titulo-dados">
              <div class="painel-header">
                <h2 id="titulo-dados">Dados do estabelecimento</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome_fantasia">Nome fantasia</label>
                  <input type="text" id="nome_fantasia" name="nome_fantasia" class="input-dash" maxlength="150" required value="<?= e($empresa['nome_fantasia']) ?>"<?= $desabilitado ?>>
                </div>
                <div class="grupo-input">
                  <label for="razao_social">Razão social</label>
                  <input type="text" id="razao_social" name="razao_social" class="input-dash" maxlength="200" value="<?= e($empresa['razao_social']) ?>"<?= $desabilitado ?>>
                </div>
                <div class="grupo-input">
                  <label for="cnpj">CNPJ</label>
                  <input type="text" id="cnpj" name="cnpj" class="input-dash" maxlength="18" inputmode="numeric" data-mascara="documento" value="<?= e($empresa['cnpj']) ?>"<?= $desabilitado ?>>
                </div>
                <div class="grupo-input">
                  <label for="telefone">Telefone</label>
                  <input type="tel" id="telefone" name="telefone" class="input-dash" maxlength="16" data-mascara="telefone" value="<?= e(telefone_br($empresa['telefone'])) ?>"<?= $desabilitado ?>>
                </div>
                <div class="grupo-input campo-largo">
                  <label for="email_contato">E-mail de contato</label>
                  <input type="email" id="email_contato" name="email_contato" class="input-dash" maxlength="255" value="<?= e($empresa['email_contato']) ?>"<?= $desabilitado ?>>
                </div>
              </div>
            </section>

            <section class="painel-card" aria-labelledby="titulo-endereco">
              <div class="painel-header">
                <h2 id="titulo-endereco">Endereço</h2>
              </div>
              <div class="campos">
                <div class="grupo-input campo-largo">
                  <label for="endereco">Rua e bairro</label>
                  <input type="text" id="endereco" name="endereco" class="input-dash" maxlength="255" value="<?= e($empresa['endereco']) ?>"<?= $desabilitado ?>>
                </div>
                <div class="grupo-input">
                  <label for="endereco_numero">Número</label>
                  <input type="text" id="endereco_numero" name="endereco_numero" class="input-dash" maxlength="20" value="<?= e($empresa['endereco_numero']) ?>"<?= $desabilitado ?>>
                </div>
                <div class="grupo-input">
                  <label for="estado">Estado</label>
                  <select id="estado" name="estado" class="input-dash"<?= $desabilitado ?>>
                    <?php foreach ($estados as $uf): ?>
                      <option value="<?= e($uf['sigla']) ?>"<?= $uf['sigla'] === $empresa['estado'] ? ' selected' : '' ?>><?= e($uf['nome']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input campo-largo">
                  <label for="cidade">Cidade</label>
                  <input type="text" id="cidade" name="cidade" class="input-dash" maxlength="200" value="<?= e($empresa['cidade']) ?>"<?= $desabilitado ?>>
                </div>
              </div>
            </section>

            <section class="painel-card" aria-labelledby="titulo-horario">
              <div class="painel-header">
                <h2 id="titulo-horario">Horário de funcionamento</h2>
              </div>
              <ul class="horarios">
                <?php foreach ($diasSemana as $dia => $nomeDia):
                  $h = $horarios[$dia] ?? ['aberto' => 0, 'abre' => '08:00', 'fecha' => '22:00'];
                ?>
                  <li class="horario<?= $h['aberto'] ? '' : ' fechado' ?>">
                    <label class="switch-rotulo">
                      <span class="switch"><input type="checkbox" role="switch" name="aberto[<?= $dia ?>]" value="1"<?= $h['aberto'] ? ' checked' : '' ?><?= $desabilitado ?>><span class="switch-trilho" aria-hidden="true"></span></span>
                      <span class="horario-dia"><?= $nomeDia ?></span>
                    </label>
                    <span class="horario-faixa">
                      <input type="time" class="input-dash" name="abre[<?= $dia ?>]" value="<?= e($h['abre'] ?: '08:00') ?>" aria-label="<?= $nomeDia ?>, abre às"<?= $desabilitado ?>>
                      <span aria-hidden="true">às</span>
                      <input type="time" class="input-dash" name="fecha[<?= $dia ?>]" value="<?= e($h['fecha'] ?: '22:00') ?>" aria-label="<?= $nomeDia ?>, fecha às"<?= $desabilitado ?>>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <p class="painel-meta" style="padding: 0 20px 16px">Marque os dias em que a loja abre. Dias desmarcados aparecem como fechados.</p>
            </section>

            <?php if ($podeEditar): ?>
              <div class="barra-salvar">
                <a class="btn btn-fantasma" href="<?= e(url()) ?>">Descartar alterações</a>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
              </div>
            <?php else: ?>
              <p class="painel-nota">Somente administradores e gerentes podem alterar os dados da empresa.</p>
            <?php endif; ?>
          </form>

          <section class="painel-card equipe" aria-labelledby="titulo-equipe">
            <div class="painel-header">
              <h2 id="titulo-equipe">Equipe</h2>
              <span class="painel-meta"><?= $ativos ?> <?= $ativos === 1 ? 'pessoa' : 'pessoas' ?></span>
            </div>
            <ul class="membros">
              <?php foreach ($equipe as $m):
                $eu = (int) $m['id'] === $meuId;
                $inativo = $m['ativo'] === 'INATIVO';
                $rotuloCargo = rotulo_cargo($m['cargo'], $m['nivel']) . ($m['nivel'] === 'ADMIN' && $m['cargo'] !== 'ADMIN' ? ' · admin' : '');
                $podeMexer = $podeEditar && !$eu && ($souAdmin || $m['nivel'] !== 'ADMIN');
              ?>
                <li class="membro<?= $inativo ? ' membro-inativo' : '' ?>">
                  <span class="avatar <?= $corAvatar($m['nome']) ?>" aria-hidden="true"><?= e($iniciais($m['nome'])) ?></span>
                  <span class="celula-dupla"><strong><?= e($m['nome']) ?></strong><small><?= e($rotuloCargo) ?><?= $eu ? ' · você' : '' ?></small></span>
                  <span class="membro-lado">
                    <?php if ($inativo): ?>
                      <span class="presenca">Inativo</span>
                    <?php else: ?>
                      <span class="presenca<?= $m['online'] ? ' online' : '' ?>"><?= $m['online'] ? 'Online' : e($m['ultimo_login'] ?: 'Nunca entrou') ?></span>
                    <?php endif; ?>
                    <?php if ($podeMexer): ?>
                      <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['membro' => $m['id'], 'remover' => null])) ?>#membro" aria-label="Editar <?= e($m['nome']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                    <?php endif; ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($podeEditar): ?>
              <div id="convites" style="border-top:1px solid var(--fio)">
                <div class="painel-header"><h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Convidar para a equipe</h2></div>
                <?php if ($conviteNovo): ?>
                  <div class="box-codigo">
                    <span><small class="muted" style="display:block">Envie este código ao funcionário</small><strong><?= e($conviteNovo) ?></strong></span>
                    <button type="button" class="btn btn-pequeno" data-copiar="<?= e($conviteNovo) ?>"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copiar</button>
                  </div>
                <?php endif; ?>
                <form method="post" action="<?= e(url(['convite' => null])) ?>" class="painel-corpo" style="display:flex;flex-direction:column;gap:10px">
                  <?= csrf_campo() ?>
                  <input type="hidden" name="acao" value="convidar">
                  <div class="linha-dupla">
                    <div class="grupo-input">
                      <label for="cv-cargo">Cargo</label>
                      <select id="cv-cargo" name="cargo" class="input-dash">
                        <?php foreach (['Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente'] as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div class="grupo-input">
                      <label for="cv-dias">Validade</label>
                      <select id="cv-dias" name="dias" class="input-dash">
                        <option value="1">1 dia</option><option value="3">3 dias</option><option value="7" selected>7 dias</option><option value="30">30 dias</option>
                      </select>
                    </div>
                  </div>
                  <?php if ($souAdmin): ?>
                    <label class="switch-rotulo"><span class="switch"><input type="checkbox" name="nivel" value="ADMIN"><span class="switch-trilho" aria-hidden="true"></span></span><span>Acesso de administrador</span></label>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primario"><i class="fa-solid fa-key" aria-hidden="true"></i> Gerar código de convite</button>
                  <p class="painel-meta">O funcionário cria a própria conta em <strong>Cadastro</strong> usando o código. Cargo e acesso vêm do convite.</p>
                </form>
                <?php if ($convites): ?>
                  <ul class="lista-linhas">
                    <?php foreach ($convites as $cv):
                      $situacao = $cv['status'] === 'pendente' ? ((int) $cv['expirado'] ? ['inativo', 'Expirado'] : ['pendente', 'Aguardando']) : ($cv['status'] === 'usado' ? ['ativo', 'Usado'] : ['inativo', 'Revogado']); ?>
                      <li>
                        <span class="celula-dupla" style="flex:1">
                          <strong class="codigo-convite"><?= e($cv['codigo']) ?></strong>
                          <small><?= e($cv['cargo']) ?><?= $cv['nivel'] === 'ADMIN' ? ' · admin' : '' ?> · <?= $cv['status'] === 'usado' ? 'por ' . e($cv['usado_por'] ?? '—') : 'vale até ' . e($cv['expira_em']) ?></small>
                        </span>
                        <span class="status <?= $situacao[0] ?>"><?= $situacao[1] ?></span>
                        <?php if ($cv['status'] === 'pendente' && !(int) $cv['expirado']): ?>
                          <button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-copiar="<?= e($cv['codigo']) ?>" aria-label="Copiar <?= e($cv['codigo']) ?>"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                          <form class="em-linha" method="post" action="<?= e(url(['convite' => null])) ?>">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="revogar_convite">
                            <input type="hidden" name="id" value="<?= (int) $cv['id'] ?>">
                            <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="Revogar" aria-label="Revogar <?= e($cv['codigo']) ?>"><i class="fa-solid fa-ban" aria-hidden="true"></i></button>
                          </form>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </section>

        </div>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
