'use strict';
const crypto = require('crypto');
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina, corAvatar } = require('../layout');
const {
  h, ler, e, entrada, entradaInt, csrfCampo, tratarPost, url, telefoneBr, iniciais, sel, chk, dis,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const CARGOS_EQUIPE = ['Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente', 'FUNCIONARIO'];
const DIAS_SEMANA = { 1: 'Segunda', 2: 'Terça', 3: 'Quarta', 4: 'Quinta', 5: 'Sexta', 6: 'Sábado', 7: 'Domingo' };

module.exports = async function empresa(ctx) {
  const u = await sessao.autenticar(ctx);
  const podeEditar = u.perm.financeiro;           // administrador ou gerente
  const souAdmin = u.nivel === 'ADMIN';
  const meuId = u.id;

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    if (!podeEditar) throw new bd.ErroNegocio('Somente administradores e gerentes podem alterar a empresa e a equipe.');
    const acao = entrada(ctx, 'acao');

    if (acao === 'salvar_empresa') {
      await bd.transacao(async (tx) => {
        await tx.chamar('sp_empresa_salvar', [
          entrada(ctx, 'nome_fantasia'), entrada(ctx, 'razao_social'), entrada(ctx, 'cnpj'), entrada(ctx, 'telefone'), entrada(ctx, 'email_contato'),
          entrada(ctx, 'endereco'), entrada(ctx, 'endereco_numero'), entrada(ctx, 'estado').toUpperCase(), entrada(ctx, 'cidade'),
        ]);
        for (const dia of Object.keys(DIAS_SEMANA)) {
          const hora = (v, padrao) => (/^\d{2}:\d{2}$/.test(String(v ?? '')) ? String(v) : padrao);
          await tx.chamar('sp_empresa_horario_salvar', [Number(dia), `aberto[${dia}]` in ctx.corpo ? 1 : 0, hora(ctx.corpo[`abre[${dia}]`], '08:00'), hora(ctx.corpo[`fecha[${dia}]`], '22:00')]);
        }
      });
      return 'Dados da empresa salvos.';
    }

    if (acao === 'salvar_membro') {
      const id = entradaInt(ctx, 'id');
      const atual = await bd.chamarUm('sp_equipe_obter', [id]);
      if (!atual) throw new bd.ErroNegocio('Funcionário não encontrado.');
      // quem não é admin não envia o nível (campo desabilitado): mantém o atual
      const nivelNovo = souAdmin && ['ADMIN', 'FUNCIONARIO'].includes(entrada(ctx, 'nivel')) ? entrada(ctx, 'nivel') : atual.nivel;
      await bd.chamar('sp_equipe_atualizar', [id, entrada(ctx, 'nome'), entrada(ctx, 'cargo'), nivelNovo, entrada(ctx, 'ativo'), meuId, souAdmin ? 1 : 0]);
      return ['Funcionário atualizado.', url(ctx, { membro: null })];
    }

    if (acao === 'convidar') {
      const nivelConvite = souAdmin && entrada(ctx, 'nivel') === 'ADMIN' ? 'ADMIN' : 'FUNCIONARIO';
      // sem letras parecidas (O/0, I/1) para o código ser lido em voz alta sem erro
      const alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      let codigo = 'XTEC-';
      for (let i = 0; i < 6; i++) codigo += alfabeto[crypto.randomInt(0, alfabeto.length)];
      const c = await bd.chamarUm('sp_convite_criar', [codigo, entrada(ctx, 'cargo'), nivelConvite, meuId, entradaInt(ctx, 'dias', 7)]);
      ctx.sessao.convite_novo = codigo;
      return [`Convite ${codigo} criado para ${entrada(ctx, 'cargo')}. Vale até ${c.expira}.`, url(ctx, { convite: 1 }) + '#convites'];
    }

    if (acao === 'revogar_convite') {
      await bd.chamar('sp_convite_revogar', [entradaInt(ctx, 'id')]);
      return ['Convite revogado. O código não pode mais ser usado.', url(ctx) + '#convites'];
    }

    if (acao === 'remover_membro') {
      const r = await bd.chamarValor('sp_equipe_remover', [entradaInt(ctx, 'id'), meuId, souAdmin ? 1 : 0]);
      return [r === 'INATIVADO' ? 'O funcionário tem histórico no sistema, então teve o acesso desativado em vez de ser removido.' : 'Funcionário removido.', url(ctx, { membro: null, remover: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const [empBd, horariosBd, estados, equipe] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_empresa_obter'), null),
    ler(ctx, () => bd.chamar('sp_empresa_horarios')),
    ler(ctx, () => bd.chamar('sp_estado_listar')),
    ler(ctx, () => bd.chamar('sp_equipe_listar')),
  ]);
  const emp = empBd || {
    nome_fantasia: '', razao_social: '', cnpj: '', telefone: '', email_contato: '', endereco: '', endereco_numero: '', estado: 'SP', cidade: '',
  };
  const horarios = {};
  for (const x of horariosBd) horarios[N(x.dia)] = x;
  const ativos = equipe.filter((m) => m.ativo !== 'INATIVO').length;

  const membroId = entradaInt(ctx, 'membro');
  const membro = podeEditar && membroId > 0 ? await ler(ctx, () => bd.chamarUm('sp_equipe_obter', [membroId]), null) : null;
  const removerId = entradaInt(ctx, 'remover');
  const alvoRemover = podeEditar && removerId > 0 ? await ler(ctx, () => bd.chamarUm('sp_equipe_obter', [removerId]), null) : null;

  const convites = podeEditar ? await ler(ctx, () => bd.chamar('sp_convite_listar')) : [];
  const conviteNovo = ctx.sessao.convite_novo || null;
  delete ctx.sessao.convite_novo;

  const desabilitado = dis(!podeEditar);

  const conteudo = h`
        ${alvoRemover ? h`
          <section class="painel-card confirmar-card" aria-labelledby="t-remover">
            <h2 id="t-remover">Remover funcionário</h2>
            <p>Remover <strong>${alvoRemover.nome}</strong>? Se ele já tiver histórico no sistema, apenas o acesso será desativado.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="remover_membro">
              <input type="hidden" name="id" value="${N(alvoRemover.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remover</button>
              <a class="btn btn-secundario" href="${url(ctx, { remover: null })}">Cancelar</a>
            </form>
          </section>` : ''}

        ${membro ? h`
          <section class="painel-card form-card" id="membro" aria-labelledby="t-membro">
            <div class="painel-header"><h2 id="t-membro">Editar ${membro.nome}</h2><a class="gaveta-fechar" href="${url(ctx, { membro: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="salvar_membro">
              <input type="hidden" name="id" value="${N(membro.id)}">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="m-nome">Nome</label>
                  <input type="text" id="m-nome" name="nome" class="input-dash" maxlength="255" required value="${membro.nome}">
                </div>
                <div class="grupo-input largo">
                  <label for="m-email">E-mail de acesso</label>
                  <input type="text" id="m-email" class="input-dash" value="${membro.email}" disabled>
                </div>
                <div class="grupo-input">
                  <label for="m-cargo">Cargo</label>
                  <select id="m-cargo" name="cargo" class="input-dash">
                    ${CARGOS_EQUIPE.map((c) => h`<option value="${c}"${sel(c === membro.cargo)}>${c === 'FUNCIONARIO' ? 'Sem cargo definido' : c}</option>`)}
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="m-nivel">Nível de acesso</label>
                  <select id="m-nivel" name="nivel" class="input-dash"${dis(!souAdmin)}>
                    <option value="FUNCIONARIO"${sel(membro.nivel === 'FUNCIONARIO')}>Funcionário</option>
                    <option value="ADMIN"${sel(membro.nivel === 'ADMIN')}>Administrador</option>
                  </select>
                </div>
                <div class="grupo-input largo">
                  <label for="m-ativo">Acesso ao sistema</label>
                  <select id="m-ativo" name="ativo" class="input-dash">
                    <option value="ATIVO"${sel(membro.ativo === 'ATIVO')}>Ativo</option>
                    <option value="INATIVO"${sel(membro.ativo === 'INATIVO')}>Inativo (não consegue entrar)</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">Salvar alterações</button>
                <a class="btn btn-secundario" href="${url(ctx, { membro: null })}">Cancelar</a>
                <a class="btn btn-perigo" href="${url(ctx, { remover: membro.id, membro: null })}"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remover</a>
              </div>
            </form>
          </section>` : ''}

        <div class="empresa-grid">

          <form class="empresa-formularios" method="post" action="${url(ctx)}">
            ${csrfCampo(ctx)}
            <input type="hidden" name="acao" value="salvar_empresa">

            <section class="painel-card" aria-labelledby="titulo-dados">
              <div class="painel-header">
                <h2 id="titulo-dados">Dados do estabelecimento</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome_fantasia">Nome fantasia</label>
                  <input type="text" id="nome_fantasia" name="nome_fantasia" class="input-dash" maxlength="150" required value="${emp.nome_fantasia}"${desabilitado}>
                </div>
                <div class="grupo-input">
                  <label for="razao_social">Razão social</label>
                  <input type="text" id="razao_social" name="razao_social" class="input-dash" maxlength="200" value="${emp.razao_social}"${desabilitado}>
                </div>
                <div class="grupo-input">
                  <label for="cnpj">CNPJ</label>
                  <input type="text" id="cnpj" name="cnpj" class="input-dash" maxlength="18" inputmode="numeric" data-mascara="documento" value="${emp.cnpj}"${desabilitado}>
                </div>
                <div class="grupo-input">
                  <label for="telefone">Telefone</label>
                  <input type="tel" id="telefone" name="telefone" class="input-dash" maxlength="16" data-mascara="telefone" value="${telefoneBr(emp.telefone)}"${desabilitado}>
                </div>
                <div class="grupo-input campo-largo">
                  <label for="email_contato">E-mail de contato</label>
                  <input type="email" id="email_contato" name="email_contato" class="input-dash" maxlength="255" value="${emp.email_contato}"${desabilitado}>
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
                  <input type="text" id="endereco" name="endereco" class="input-dash" maxlength="255" value="${emp.endereco}"${desabilitado}>
                </div>
                <div class="grupo-input">
                  <label for="endereco_numero">Número</label>
                  <input type="text" id="endereco_numero" name="endereco_numero" class="input-dash" maxlength="20" value="${emp.endereco_numero}"${desabilitado}>
                </div>
                <div class="grupo-input">
                  <label for="estado">Estado</label>
                  <select id="estado" name="estado" class="input-dash"${desabilitado}>
                    ${estados.map((uf) => h`<option value="${uf.sigla}"${sel(uf.sigla === emp.estado)}>${uf.nome}</option>`)}
                  </select>
                </div>
                <div class="grupo-input campo-largo">
                  <label for="cidade">Cidade</label>
                  <input type="text" id="cidade" name="cidade" class="input-dash" maxlength="200" value="${emp.cidade}"${desabilitado}>
                </div>
              </div>
            </section>

            <section class="painel-card" aria-labelledby="titulo-horario">
              <div class="painel-header">
                <h2 id="titulo-horario">Horário de funcionamento</h2>
              </div>
              <ul class="horarios">
                ${Object.entries(DIAS_SEMANA).map(([dia, nomeDia]) => {
                  const hr = horarios[Number(dia)] || { aberto: 0, abre: '08:00', fecha: '22:00' };
                  const aberto = N(hr.aberto) === 1 || hr.aberto === true;
                  return h`<li class="horario${aberto ? '' : ' fechado'}">
                    <label class="switch-rotulo">
                      <span class="switch"><input type="checkbox" role="switch" name="aberto[${dia}]" value="1"${chk(aberto)}${desabilitado}><span class="switch-trilho" aria-hidden="true"></span></span>
                      <span class="horario-dia">${nomeDia}</span>
                    </label>
                    <span class="horario-faixa">
                      <input type="time" class="input-dash" name="abre[${dia}]" value="${String(hr.abre || '08:00').slice(0, 5)}" aria-label="${nomeDia}, abre às"${desabilitado}>
                      <span aria-hidden="true">às</span>
                      <input type="time" class="input-dash" name="fecha[${dia}]" value="${String(hr.fecha || '22:00').slice(0, 5)}" aria-label="${nomeDia}, fecha às"${desabilitado}>
                    </span>
                  </li>`;
                })}
              </ul>
              <p class="painel-meta" style="padding: 0 20px 16px">Marque os dias em que a loja abre. Dias desmarcados aparecem como fechados.</p>
            </section>

            ${podeEditar ? h`
              <div class="barra-salvar">
                <a class="btn btn-fantasma" href="${url(ctx)}">Descartar alterações</a>
                <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
              </div>` : h`
              <p class="painel-nota">Somente administradores e gerentes podem alterar os dados da empresa.</p>`}
          </form>

          <section class="painel-card equipe" aria-labelledby="titulo-equipe">
            <div class="painel-header">
              <h2 id="titulo-equipe">Equipe</h2>
              <span class="painel-meta">${ativos} ${ativos === 1 ? 'pessoa' : 'pessoas'}</span>
            </div>
            <ul class="membros">
              ${equipe.map((m) => {
                const eu = N(m.id) === meuId;
                const inativo = m.ativo === 'INATIVO';
                const rotulo = sessao.rotuloCargo(m.cargo, m.nivel) + (m.nivel === 'ADMIN' && m.cargo !== 'ADMIN' ? ' · admin' : '');
                const podeMexer = podeEditar && !eu && (souAdmin || m.nivel !== 'ADMIN');
                return h`<li class="membro${inativo ? ' membro-inativo' : ''}">
                  <span class="avatar ${corAvatar(m.nome)}" aria-hidden="true">${iniciais(m.nome)}</span>
                  <span class="celula-dupla"><strong>${m.nome}</strong><small>${rotulo}${eu ? ' · você' : ''}</small></span>
                  <span class="membro-lado">
                    ${inativo ? h`<span class="presenca">Inativo</span>` : h`<span class="presenca${m.online ? ' online' : ''}">${m.online ? 'Online' : m.ultimo_login || 'Nunca entrou'}</span>`}
                    ${podeMexer ? h`<a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { membro: m.id, remover: null })}#membro" aria-label="Editar ${m.nome}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>` : ''}
                  </span>
                </li>`;
              })}
            </ul>
            ${podeEditar ? h`
              <div id="convites" style="border-top:1px solid var(--fio)">
                <div class="painel-header"><h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Convidar para a equipe</h2></div>
                ${conviteNovo ? h`
                  <div class="box-codigo">
                    <span><small class="muted" style="display:block">Envie este código ao funcionário</small><strong>${conviteNovo}</strong></span>
                    <button type="button" class="btn btn-pequeno" data-copiar="${conviteNovo}"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copiar</button>
                  </div>` : ''}
                <form method="post" action="${url(ctx, { convite: null })}" class="painel-corpo" style="display:flex;flex-direction:column;gap:10px">
                  ${csrfCampo(ctx)}
                  <input type="hidden" name="acao" value="convidar">
                  <div class="linha-dupla">
                    <div class="grupo-input">
                      <label for="cv-cargo">Cargo</label>
                      <select id="cv-cargo" name="cargo" class="input-dash">
                        ${['Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente'].map((c) => h`<option value="${c}">${c}</option>`)}
                      </select>
                    </div>
                    <div class="grupo-input">
                      <label for="cv-dias">Validade</label>
                      <select id="cv-dias" name="dias" class="input-dash">
                        <option value="1">1 dia</option><option value="3">3 dias</option><option value="7" selected>7 dias</option><option value="30">30 dias</option>
                      </select>
                    </div>
                  </div>
                  ${souAdmin ? h`<label class="switch-rotulo"><span class="switch"><input type="checkbox" name="nivel" value="ADMIN"><span class="switch-trilho" aria-hidden="true"></span></span><span>Acesso de administrador</span></label>` : ''}
                  <button type="submit" class="btn btn-primario"><i class="fa-solid fa-key" aria-hidden="true"></i> Gerar código de convite</button>
                  <p class="painel-meta">O funcionário cria a própria conta em <strong>Cadastro</strong> usando o código. Cargo e acesso vêm do convite.</p>
                </form>
                ${convites.length ? h`
                  <ul class="lista-linhas">
                    ${convites.map((cv) => {
                      const expirado = N(cv.expirado) === 1 || cv.expirado === true;
                      const situacao = cv.status === 'pendente' ? (expirado ? ['inativo', 'Expirado'] : ['pendente', 'Aguardando']) : cv.status === 'usado' ? ['ativo', 'Usado'] : ['inativo', 'Revogado'];
                      return h`<li>
                        <span class="celula-dupla" style="flex:1">
                          <strong class="codigo-convite">${cv.codigo}</strong>
                          <small>${cv.cargo}${cv.nivel === 'ADMIN' ? ' · admin' : ''} · ${cv.status === 'usado' ? 'por ' + (cv.usado_por ?? '—') : 'vale até ' + cv.expira_em}</small>
                        </span>
                        <span class="status ${situacao[0]}">${situacao[1]}</span>
                        ${cv.status === 'pendente' && !expirado ? h`
                          <button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-copiar="${cv.codigo}" aria-label="Copiar ${cv.codigo}"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                          <form class="em-linha" method="post" action="${url(ctx, { convite: null })}">
                            ${csrfCampo(ctx)}
                            <input type="hidden" name="acao" value="revogar_convite">
                            <input type="hidden" name="id" value="${N(cv.id)}">
                            <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="Revogar" aria-label="Revogar ${cv.codigo}"><i class="fa-solid fa-ban" aria-hidden="true"></i></button>
                          </form>` : ''}
                      </li>`;
                    })}
                  </ul>` : ''}
              </div>` : ''}
          </section>

        </div>`;

  return pagina(ctx, { titulo: 'Empresa', subtitulo: 'Dados do estabelecimento e da equipe', paginaAtual: 'empresa' }, conteudo);
};
