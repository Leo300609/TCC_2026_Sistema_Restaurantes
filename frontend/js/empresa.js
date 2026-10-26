/* Empresa · dados do estabelecimento, horário de funcionamento e equipe */
(() => {
  const { api, modal, confirmar, toast, esc, iniciais, carregando, vazio } = XT;

  const DIAS = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
  const form = document.getElementById('form-empresa');
  const listaHorarios = document.getElementById('horarios');
  const listaEquipe = document.getElementById('membros');

  let dados = null;
  let horarios = [];

  async function carregar() {
    try {
      dados = await api('empresa', 'obter');
      preencher();
    } catch (e) {
      toast(e.message, 'erro');
      listaEquipe.innerHTML = vazio('fa-triangle-exclamation', 'Não foi possível carregar', e.message);
    }
  }

  function preencher() {
    const e = dados.empresa;
    Object.entries({
      nome_fantasia: e.nome_fantasia,
      razao_social: e.razao_social,
      cnpj: e.cnpj,
      telefone: XT.formatarTelefone(e.telefone),
      email_contato: e.email_contato,
      endereco: e.endereco,
      endereco_numero: e.endereco_numero,
      cidade: e.cidade,
    }).forEach(([nome, valor]) => (form.elements[nome].value = valor ?? ''));

    form.elements.estado.innerHTML = dados.estados.map((s) => `<option value="${esc(s.sigla)}"${s.sigla === e.estado ? ' selected' : ''}>${esc(s.nome)}</option>`).join('');

    horarios = dados.horarios.map((h) => ({ ...h }));
    desenharHorarios();
    desenharEquipe();

    // Quem não gerencia só visualiza
    const editavel = dados.pode_editar;
    form.querySelectorAll('input, select').forEach((campo) => (campo.disabled = !editavel));
    document.getElementById('barra-salvar').hidden = !editavel;
    document.getElementById('aviso-leitura').hidden = editavel;
  }

  function desenharHorarios() {
    listaHorarios.innerHTML = horarios
      .map((h, n) => {
        const nome = DIAS[h.dia - 1];
        return `
        <li class="horario${h.aberto ? '' : ' fechado'}">
          <label class="switch-rotulo">
            <span class="switch"><input type="checkbox" role="switch" data-dia="${n}"${h.aberto ? ' checked' : ''}${dados.pode_editar ? '' : ' disabled'}><span class="switch-trilho" aria-hidden="true"></span></span>
            <span class="horario-dia">${nome}</span>
          </label>
          ${
            h.aberto
              ? `<span class="horario-faixa">
                  <input type="time" class="input-dash" data-abre="${n}" value="${esc(h.abre)}" aria-label="${nome}, abre às"${dados.pode_editar ? '' : ' disabled'} required>
                  <span aria-hidden="true">às</span>
                  <input type="time" class="input-dash" data-fecha="${n}" value="${esc(h.fecha)}" aria-label="${nome}, fecha às"${dados.pode_editar ? '' : ' disabled'} required>
                </span>`
              : '<span class="horario-status">Fechado</span>'
          }
        </li>`;
      })
      .join('');

    listaHorarios.querySelectorAll('[data-dia]').forEach((c) =>
      c.addEventListener('change', () => {
        horarios[Number(c.dataset.dia)].aberto = c.checked;
        desenharHorarios();
      }),
    );
    listaHorarios.querySelectorAll('[data-abre]').forEach((c) => c.addEventListener('input', () => (horarios[Number(c.dataset.abre)].abre = c.value)));
    listaHorarios.querySelectorAll('[data-fecha]').forEach((c) => c.addEventListener('input', () => (horarios[Number(c.dataset.fecha)].fecha = c.value)));
  }

  function desenharEquipe() {
    const ativos = dados.equipe.filter((m) => m.ativo === 'ATIVO').length;
    document.getElementById('equipe-total').textContent = `${ativos} ${ativos === 1 ? 'pessoa' : 'pessoas'}`;

    listaEquipe.innerHTML = dados.equipe
      .map((m) => {
        const eu = m.id === dados.eu;
        const inativo = m.ativo === 'INATIVO';
        const rotuloCargo = m.cargo === 'FUNCIONARIO' ? (m.nivel === 'ADMIN' ? 'Administrador' : 'Funcionário') : m.cargo;
        const podeMexer = dados.pode_editar && !eu && (dados.sou_admin || m.nivel !== 'ADMIN');
        const presenca = inativo ? '<span class="presenca">Inativo</span>' : `<span class="presenca${m.online ? ' online' : ''}">${m.online ? 'Online' : esc(m.ultimo_login || 'Nunca entrou')}</span>`;

        return `
        <li class="membro${inativo ? ' membro-inativo' : ''}">
          <span class="avatar" aria-hidden="true">${esc(iniciais(m.nome))}</span>
          <span class="celula-dupla"><strong>${esc(m.nome)}</strong><small>${esc(rotuloCargo)}${eu ? ' · você' : ''}</small></span>
          <span class="membro-lado">
            ${presenca}
            ${podeMexer ? `<button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-membro="${m.id}" aria-label="Editar ${esc(m.nome)}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>` : ''}
          </span>
        </li>`;
      })
      .join('');

    listaEquipe.querySelectorAll('[data-membro]').forEach((b) => b.addEventListener('click', () => editarMembro(dados.equipe.find((m) => m.id == b.dataset.membro))));
  }

  function editarMembro(m) {
    modal({
      titulo: `Editar ${m.nome}`,
      rotuloSalvar: 'Salvar alterações',
      corpo: `
        <div class="form-grid">
          <div class="grupo-input largo">
            <label for="m-nome">Nome</label>
            <input type="text" id="m-nome" name="nome" class="input-dash" maxlength="255" required value="${esc(m.nome)}">
          </div>
          <div class="grupo-input largo">
            <label>E-mail de acesso</label>
            <input type="text" class="input-dash" value="${esc(m.email)}" disabled>
          </div>
          <div class="grupo-input">
            <label for="m-cargo">Cargo</label>
            <select id="m-cargo" name="cargo" class="input-dash">
              ${dados.cargos.map((c) => `<option value="${esc(c)}"${c === m.cargo ? ' selected' : ''}>${c === 'FUNCIONARIO' ? 'Sem cargo definido' : esc(c)}</option>`).join('')}
            </select>
          </div>
          <div class="grupo-input">
            <label for="m-nivel">Nível de acesso</label>
            <select id="m-nivel" name="nivel" class="input-dash"${dados.sou_admin ? '' : ' disabled'}>
              <option value="FUNCIONARIO"${m.nivel === 'FUNCIONARIO' ? ' selected' : ''}>Funcionário</option>
              <option value="ADMIN"${m.nivel === 'ADMIN' ? ' selected' : ''}>Administrador</option>
            </select>
          </div>
          <div class="grupo-input largo">
            <label for="m-ativo">Acesso ao sistema</label>
            <select id="m-ativo" name="ativo" class="input-dash">
              <option value="ATIVO"${m.ativo === 'ATIVO' ? ' selected' : ''}>Ativo</option>
              <option value="INATIVO"${m.ativo === 'INATIVO' ? ' selected' : ''}>Inativo (não consegue entrar)</option>
            </select>
          </div>
        </div>`,
      extras: [
        {
          rotulo: 'Remover',
          icone: 'fa-user-minus',
          classe: 'btn-perigo',
          aoClicar: async (ctx) => {
            const ok = await confirmar({
              titulo: 'Remover funcionário',
              mensagem: `Remover ${m.nome}? Se ele já tiver registros no sistema, apenas o acesso será desativado.`,
              rotulo: 'Remover',
              perigo: true,
            });
            if (!ok) return;
            toast((await api('empresa', 'equipe_remover', { dados: { id: m.id } })).message);
            ctx.fechar();
            carregar();
          },
        },
      ],
      aoEnviar: async (campos, ctx) => {
        const nivel = ctx.form.querySelector('[name=nivel]').value; // select desabilitado não entra no FormData
        toast((await api('empresa', 'equipe_atualizar', { dados: { ...campos, nivel, id: m.id } })).message);
        carregar();
      },
    });
  }

  form.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!form.checkValidity()) return form.reportValidity();

    const botao = form.querySelector('[type=submit]');
    botao.disabled = true;
    try {
      const carga = Object.fromEntries(new FormData(form).entries());
      const resposta = await api('empresa', 'salvar', { dados: { ...carga, horarios } });
      toast(resposta.message);
      await carregar();
    } catch (e) {
      toast(e.message, 'erro');
    } finally {
      botao.disabled = false;
    }
  });

  document.getElementById('btn-descartar').addEventListener('click', () => {
    preencher();
    toast('Alterações descartadas.');
  });

  XT.mascaras(form);
  carregar();
})();
