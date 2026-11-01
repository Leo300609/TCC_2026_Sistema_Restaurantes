'use strict';
const bcrypt = require('bcryptjs');
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const { h, cru, ler, e, entrada, entradaInt, lista, csrfCampo, tratarPost, url, sel, chk, dis } = require('../web');

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const FORMAS = {
  PIX: ['fa-brands fa-pix', 'Pix'], CREDITO: ['fa-regular fa-credit-card', 'Crédito'], DEBITO: ['fa-solid fa-credit-card', 'Débito'],
  DINHEIRO: ['fa-solid fa-money-bill-wave', 'Dinheiro'], BOLETO: ['fa-solid fa-barcode', 'Boleto'],
};

/** Opções de um <select> que sempre incluem o valor salvo. */
function opcoesSelect(padrao, atual, unidade) {
  const valores = [...new Set([...padrao, parseInt(atual, 10) || 0])].sort((a, b) => a - b);
  return valores.map((v) => h`<option value="${v}"${sel(String(v) === atual)}>${v} ${unidade === 'dia' ? (v === 1 ? 'dia' : 'dias') : unidade}</option>`);
}

module.exports = async function configs(ctx) {
  const u = await sessao.autenticar(ctx);
  const podeOperacao = u.perm.financeiro; // administrador ou gerente

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const nome = entrada(ctx, 'nome');
    const email = entrada(ctx, 'email');
    if (!EMAIL.test(email)) throw new bd.ErroNegocio('Informe um e-mail válido.');

    // senha: só troca se a nova foi preenchida (a conferência usa o mesmo critério do login)
    let novoHash = '';
    const senhaNova = String(ctx.corpo.senha_nova || '');
    if (senhaNova !== '') {
      const atual = String(ctx.corpo.senha_atual || '');
      const guardada = String((await bd.chamarValor('sp_config_senha_atual', [u.id])) || '');
      const ok = atual !== '' && (/^\$2[aby]\$/.test(guardada) ? await bcrypt.compare(atual, guardada) : guardada === atual);
      if (!ok) throw new bd.ErroNegocio('A senha atual está incorreta.');
      if ([...senhaNova].length < 8) throw new bd.ErroNegocio('A nova senha precisa ter ao menos 8 caracteres.');
      novoHash = await bcrypt.hash(senhaNova, 10);
    }

    const dias = entradaInt(ctx, 'dias_validade', 3);
    if (dias < 1 || dias > 60) throw new bd.ErroNegocio('Informe de 1 a 60 dias de antecedência para a validade.');

    let pagamentos = null;
    if (podeOperacao) {
      const formas = [...new Set(lista(ctx, 'pagamentos'))];
      if (!formas.length || formas.some((f) => !(f in FORMAS))) throw new bd.ErroNegocio('Escolha ao menos uma forma de pagamento válida.');
      pagamentos = formas.join(',');
    }

    await bd.transacao(async (tx) => {
      await tx.chamar('sp_config_conta_salvar', [u.id, nome, email, novoHash]);
      for (const chave of ['notif_pedido', 'notif_minimo', 'notif_validade', 'notif_contas']) {
        await tx.chamar('sp_config_definir', [u.id, chave, chave in ctx.corpo ? '1' : '0']);
      }
      await tx.chamar('sp_config_definir', [u.id, 'dias_validade', String(dias)]);
      if (podeOperacao) await tx.chamar('sp_config_operacao_salvar', [('aceita_delivery' in ctx.corpo) ? 1 : 0, entradaInt(ctx, 'tempo_preparo', 20), pagamentos]);
    });

    ctx.sessao.usuario_nome = nome;
    return novoHash === '' ? 'Configurações salvas.' : 'Configurações salvas e senha alterada.';
  });

  // ---------- dados (GET) ----------
  const conta = (await ler(ctx, () => bd.chamarUm('sp_config_conta', [u.id]), null)) || { nome: ctx.sessao.usuario_nome || '', email: '' };
  const cfg = (await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), null)) || {
    notif_pedido: '1', notif_minimo: '1', notif_validade: '1', notif_contas: '0', dias_validade: '3',
    aceita_delivery: '1', tempo_preparo: '20', pagamentos: 'PIX,CREDITO,DEBITO,DINHEIRO',
  };
  const ligado = (k) => String(cfg[k]) === '1';
  const pagamentosAtivos = String(cfg.pagamentos || '').split(',');
  const desabilita = dis(!podeOperacao);

  const switchNotif = ([nomeCampo, idRotulo, rotulo, dica]) => h`
                  <li class="config-linha">
                    <div class="config-texto"><strong id="${idRotulo}">${rotulo}</strong><small>${dica}</small></div>
                    <label class="switch"><input type="checkbox" role="switch" name="${nomeCampo}" aria-labelledby="${idRotulo}"${chk(ligado(nomeCampo))}><span class="switch-trilho" aria-hidden="true"></span></label>
                  </li>`;

  const conteudo = h`
        <div class="config-grid">

          <nav class="config-indice" aria-label="Seções de configurações">
            <a href="#conta">Conta</a>
            <a href="#notificacoes">Notificações</a>
            <a href="#operacao">Operação</a>
            <a href="#aparencia">Aparência</a>
            <a href="#seguranca">Segurança</a>
          </nav>

          <form class="config-secoes" method="post" action="${url(ctx)}">
            ${csrfCampo(ctx)}

            <section class="painel-card" id="conta" aria-labelledby="titulo-conta">
              <div class="painel-header">
                <h2 id="titulo-conta">Conta</h2>
              </div>
              <div class="campos">
                <div class="grupo-input">
                  <label for="nome">Nome</label>
                  <input type="text" id="nome" name="nome" class="input-dash" maxlength="255" required autocomplete="name" value="${conta.nome}">
                </div>
                <div class="grupo-input">
                  <label for="email">E-mail</label>
                  <input type="email" id="email" name="email" class="input-dash" maxlength="255" required autocomplete="email" value="${conta.email}">
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
                ${[
                  ['notif_pedido', 'n-pedido', 'Novo pedido recebido', 'Avisa quando um pedido entra no quadro.'],
                  ['notif_minimo', 'n-minimo', 'Estoque abaixo do mínimo', 'Avisa quando um item chega no nível mínimo.'],
                ].map(switchNotif)}
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="n-validade">Produtos perto do vencimento</strong>
                    <small>Antecedência do aviso de validade.</small>
                  </div>
                  <div class="config-controle">
                    <label class="sr-only" for="dias_validade">Dias de antecedência</label>
                    <select id="dias_validade" name="dias_validade" class="input-dash">${opcoesSelect([1, 2, 3, 7], String(parseInt(cfg.dias_validade, 10) || 0), 'dia')}</select>
                    <label class="switch"><input type="checkbox" role="switch" name="notif_validade" aria-labelledby="n-validade"${chk(ligado('notif_validade'))}><span class="switch-trilho" aria-hidden="true"></span></label>
                  </div>
                </li>
                <li class="config-linha">
                  <div class="config-texto"><strong id="n-contas">Contas a pagar vencendo</strong><small>Avisa sobre contas perto do vencimento.</small></div>
                  <label class="switch"><input type="checkbox" role="switch" name="notif_contas" aria-labelledby="n-contas"${chk(ligado('notif_contas'))}><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
              </ul>
            </section>

            <section class="painel-card" id="operacao" aria-labelledby="titulo-operacao">
              <div class="painel-header">
                <h2 id="titulo-operacao">Operação</h2>
              </div>
              ${!podeOperacao ? h`<p class="painel-nota">Somente administradores e gerentes podem alterar a operação.</p>` : ''}
              <ul class="config-linhas">
                <li class="config-linha">
                  <div class="config-texto">
                    <strong id="o-delivery">Receber pedidos de delivery</strong>
                    <small>Desligue para pausar o delivery.</small>
                  </div>
                  <label class="switch"><input type="checkbox" role="switch" name="aceita_delivery" aria-labelledby="o-delivery"${chk(ligado('aceita_delivery'))}${desabilita}><span class="switch-trilho" aria-hidden="true"></span></label>
                </li>
                <li class="config-linha">
                  <div class="config-texto">
                    <strong><label for="tempo_preparo">Tempo médio de preparo</label></strong>
                    <small>Pedidos acima disso aparecem como atrasados no quadro.</small>
                  </div>
                  <select id="tempo_preparo" name="tempo_preparo" class="input-dash config-select"${desabilita}>${opcoesSelect([15, 20, 30, 45], String(parseInt(cfg.tempo_preparo, 10) || 0), 'minutos')}</select>
                </li>
                <li class="config-linha config-linha-coluna">
                  <div class="config-texto">
                    <strong id="o-pagamentos">Formas de pagamento aceitas</strong>
                    <small>Aparecem na hora de fechar a conta.</small>
                  </div>
                  <div class="opcoes" role="group" aria-labelledby="o-pagamentos">
                    ${Object.entries(FORMAS).map(([valor, [icone, rotulo]]) => h`<label class="opcao"><input type="checkbox" name="pagamentos[]" value="${valor}"${chk(pagamentosAtivos.includes(valor))}${desabilita}><span><i class="${icone}" aria-hidden="true"></i> ${rotulo}</span></label>`)}
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
                  <a href="/auth/logout" class="btn btn-perigo"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sair agora</a>
                </li>
              </ul>
            </section>

            <div class="barra-salvar">
              <a class="btn btn-fantasma" href="${url(ctx)}">Descartar alterações</a>
              <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Salvar alterações</button>
            </div>
          </form>

        </div>`;

  const rodapeExtra = cru(`<script>
  (() => {
    let atual = 'sistema';
    try { atual = localStorage.getItem('xtec-tema') || 'sistema'; } catch (e) { /* ignora */ }
    document.querySelectorAll('input[name=tema_visual]').forEach((r) => {
      r.checked = r.value === atual;
      r.addEventListener('change', () => window.xtecDefinirTema?.(r.value));
    });
  })();
</script>`);

  return pagina(ctx, { titulo: 'Configurações', subtitulo: 'Sua conta, notificações e a operação da loja', paginaAtual: 'configs', rodapeExtra }, conteudo);
};
