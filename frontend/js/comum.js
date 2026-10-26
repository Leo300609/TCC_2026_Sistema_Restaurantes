/* ==========================================================================
   XTEC · utilitários compartilhados pelas telas com CRUD
   (chamadas à API, avisos, diálogos, máscaras, filtros e paginação)
   Uso nas páginas:  const { api, modal, confirmar, toast, esc, brl } = XT;
   ========================================================================== */
(() => {
  'use strict';

  const meta = document.querySelector('meta[name="csrf-token"]');
  const CSRF = meta ? meta.content : '';

  // ------------------------------------------------------------------
  // Formatação
  // ------------------------------------------------------------------
  const esc = (valor) =>
    String(valor ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  const brl = (valor) => Number(valor || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const numero = (valor) => Number(valor || 0).toLocaleString('pt-BR');
  const digitos = (valor) => String(valor ?? '').replace(/\D+/g, '');

  const dataBr = (iso) => (iso && iso.length >= 10 ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : '');
  const hojeIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  function formatarDocumento(valor) {
    const d = digitos(valor);
    if (d.length <= 11) {
      return d
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
    }
    return d
      .slice(0, 14)
      .replace(/^(\d{2})(\d)/, '$1.$2')
      .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/^(\d{2})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3/$4')
      .replace(/^(\d{2})\.(\d{3})\.(\d{3})\/(\d{4})(\d)/, '$1.$2.$3/$4-$5');
  }

  function formatarTelefone(valor) {
    const d = digitos(valor).slice(0, 11);
    if (d.length <= 2) return d ? `(${d}` : '';
    if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  }

  const iniciais = (nome) =>
    String(nome || '?')
      .trim()
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p[0].toUpperCase())
      .join('') || '?';

  function debounce(fn, ms = 300) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  /** Liga máscaras a campos com data-mascara="telefone|documento|cnpj". */
  function mascaras(raiz = document) {
    raiz.querySelectorAll('[data-mascara]').forEach((campo) => {
      if (campo.dataset.mascaraPronta) return;
      campo.dataset.mascaraPronta = '1';
      const aplicar = () => {
        const tipo = campo.dataset.mascara;
        if (tipo === 'telefone') campo.value = formatarTelefone(campo.value);
        else if (tipo === 'cnpj') campo.value = formatarCnpj(campo.value);
        else campo.value = formatarDocumento(campo.value);
      };
      campo.addEventListener('input', aplicar);
      if (campo.value) aplicar();
    });
  }

  function formatarCnpj(valor) {
    return digitos(valor)
      .slice(0, 14)
      .replace(/^(\d{2})(\d)/, '$1.$2')
      .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/^(\d{2})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3/$4')
      .replace(/^(\d{2})\.(\d{3})\.(\d{3})\/(\d{4})(\d)/, '$1.$2.$3/$4-$5');
  }

  // ------------------------------------------------------------------
  // API
  // ------------------------------------------------------------------
  /**
   * api('clientes', 'listar', { params: { q: 'ana' } })      → GET
   * api('clientes', 'criar',  { dados: { nome: '...' } })    → POST (JSON + CSRF)
   * opcoes.chave troca o nome do parâmetro da ação (management.php usa "action").
   */
  async function api(arquivo, acao, { dados, params = {}, chave = 'acao' } = {}) {
    let url = `../backend/api/${arquivo}.php`;
    const opcoes = { credentials: 'same-origin', headers: { Accept: 'application/json' } };

    if (dados !== undefined) {
      opcoes.method = 'POST';
      opcoes.headers['Content-Type'] = 'application/json';
      opcoes.headers['X-CSRF-Token'] = CSRF;
      opcoes.body = JSON.stringify({ [chave]: acao, ...dados });
    } else {
      url += '?' + new URLSearchParams({ [chave]: acao, ...params }).toString();
    }

    let resposta;
    try {
      resposta = await fetch(url, opcoes);
    } catch (e) {
      throw new Error('Não foi possível falar com o servidor. Verifique sua conexão.');
    }

    let json = null;
    try {
      json = await resposta.json();
    } catch (e) {
      /* resposta sem JSON: tratada abaixo */
    }

    if (resposta.status === 401) {
      window.location.href = 'html/login.html';
      throw new Error('Sessão expirada. Entre novamente.');
    }
    if (!resposta.ok || !json || json.error) {
      throw new Error((json && json.error) || 'O servidor devolveu uma resposta inesperada.');
    }
    return json;
  }

  // ------------------------------------------------------------------
  // Avisos (toast)
  // ------------------------------------------------------------------
  function toast(mensagem, tipo = 'ok') {
    let area = document.getElementById('toasts');
    if (!area) {
      area = document.createElement('div');
      area.id = 'toasts';
      area.className = 'toasts';
      area.setAttribute('aria-live', 'polite');
      document.body.appendChild(area);
    }

    const el = document.createElement('div');
    el.className = `toast ${tipo}`;
    el.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
    el.innerHTML = `<i class="fa-solid ${tipo === 'erro' ? 'fa-circle-exclamation' : 'fa-circle-check'}" aria-hidden="true"></i><span>${esc(mensagem)}</span>`;
    area.appendChild(el);
    setTimeout(() => el.classList.add('saindo'), tipo === 'erro' ? 6000 : 3500);
    setTimeout(() => el.remove(), tipo === 'erro' ? 6400 : 3900);
  }

  // ------------------------------------------------------------------
  // Diálogos
  // ------------------------------------------------------------------
  /**
   * modal({ titulo, corpo, rotuloSalvar, rotuloFechar, extras, aoAbrir, aoEnviar, largo })
   *  - corpo: HTML com os campos (use name="..." nos inputs);
   *  - aoEnviar(dados, form): async; devolver false mantém o diálogo aberto;
   *  - extras: [{ rotulo, icone, classe, aoClicar(ctx) }] botões à esquerda do rodapé.
   */
  function modal({ titulo, corpo, rotuloSalvar = 'Salvar', rotuloFechar = 'Cancelar', extras = [], aoAbrir, aoEnviar, largo = false }) {
    const dlg = document.createElement('dialog');
    dlg.className = 'modal' + (largo ? ' largo' : '');
    const idTitulo = `modal-t-${Math.random().toString(36).slice(2, 8)}`;
    dlg.setAttribute('aria-labelledby', idTitulo);
    dlg.innerHTML = `
      <form class="modal-caixa" novalidate>
        <header class="modal-topo">
          <h2 id="${idTitulo}">${esc(titulo)}</h2>
          <button type="button" class="modal-x" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </header>
        <div class="modal-corpo">${corpo}</div>
        <p class="form-erro" role="alert" hidden></p>
        <footer class="modal-rodape">
          <span class="rodape-esquerda"></span>
          <button type="button" class="btn btn-fantasma" data-fechar>${esc(rotuloFechar)}</button>
          ${aoEnviar ? `<button type="submit" class="btn btn-primario" data-salvar>${esc(rotuloSalvar)}</button>` : ''}
        </footer>
      </form>`;

    const form = dlg.querySelector('form');
    const erro = dlg.querySelector('.form-erro');
    const botaoSalvar = dlg.querySelector('[data-salvar]');
    const esquerda = dlg.querySelector('.rodape-esquerda');

    const ctx = {
      el: dlg,
      form,
      fechar: () => dlg.close(),
      mostrarErro(msg) {
        erro.textContent = msg;
        erro.hidden = !msg;
      },
      ocupado(sim) {
        dlg.querySelectorAll('footer button').forEach((b) => (b.disabled = sim));
      },
      rotuloSalvar(texto) {
        if (botaoSalvar) botaoSalvar.textContent = texto;
      },
    };

    extras.forEach((extra) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = `btn ${extra.classe || 'btn-fantasma'}`;
      b.innerHTML = `${extra.icone ? `<i class="fa-solid ${extra.icone}" aria-hidden="true"></i> ` : ''}${esc(extra.rotulo)}`;
      b.addEventListener('click', async () => {
        ctx.mostrarErro('');
        ctx.ocupado(true);
        try {
          await extra.aoClicar(ctx);
        } catch (e) {
          ctx.mostrarErro(e.message);
        } finally {
          ctx.ocupado(false);
        }
      });
      esquerda.appendChild(b);
    });

    dlg.querySelectorAll('[data-fechar]').forEach((b) => b.addEventListener('click', () => dlg.close()));
    dlg.addEventListener('click', (e) => {
      if (e.target === dlg) dlg.close();
    });
    dlg.addEventListener('close', () => dlg.remove());

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!aoEnviar) return;
      ctx.mostrarErro('');

      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      ctx.ocupado(true);
      try {
        const dados = Object.fromEntries(new FormData(form).entries());
        const manter = await aoEnviar(dados, ctx);
        if (manter !== false) dlg.close();
      } catch (err) {
        ctx.mostrarErro(err.message);
      } finally {
        ctx.ocupado(false);
      }
    });

    document.body.appendChild(dlg);
    mascaras(dlg);
    dlg.showModal();
    if (aoAbrir) aoAbrir(ctx);

    const primeiro = dlg.querySelector('.modal-corpo input:not([type=hidden]), .modal-corpo select, .modal-corpo textarea');
    if (primeiro) primeiro.focus();

    return ctx;
  }

  function confirmar({ titulo = 'Confirmar', mensagem, rotulo = 'Confirmar', perigo = false }) {
    return new Promise((resolver) => {
      let resposta = false;
      const m = modal({
        titulo,
        corpo: `<p class="modal-texto">${esc(mensagem)}</p>`,
        rotuloSalvar: rotulo,
        aoEnviar: async () => {
          resposta = true;
        },
      });
      if (perigo) m.el.querySelector('[data-salvar]').classList.add('btn-perigo');
      m.el.addEventListener('close', () => resolver(resposta));
    });
  }

  // ------------------------------------------------------------------
  // Filtros (pílulas) e paginação
  // ------------------------------------------------------------------
  /** itens: [{ valor, rotulo, qtd }] */
  function filtros(container, itens, ativo, aoMudar) {
    container.innerHTML = itens
      .map(
        (i) =>
          `<button type="button" class="filtro${i.valor === ativo ? ' ativo' : ''}" data-valor="${esc(i.valor)}" aria-pressed="${i.valor === ativo}">${esc(i.rotulo)}${
            i.qtd === undefined ? '' : ` <span>${numero(i.qtd)}</span>`
          }</button>`,
      )
      .join('');
    container.querySelectorAll('.filtro').forEach((b) => b.addEventListener('click', () => aoMudar(b.dataset.valor)));
  }

  function paginacao(container, { pagina, paginas, total, por_pagina: porPagina }, rotulo, ir) {
    if (!total) {
      container.hidden = true;
      return;
    }
    container.hidden = false;
    const de = (pagina - 1) * porPagina + 1;
    const ate = Math.min(total, pagina * porPagina);
    container.innerHTML = `
      <span class="painel-meta">Mostrando ${numero(de)} a ${numero(ate)} de ${numero(total)} ${esc(rotulo)}</span>
      <div class="paginacao-botoes">
        <button type="button" class="btn btn-pequeno" data-ir="${pagina - 1}" ${pagina <= 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Anterior</button>
        <button type="button" class="btn btn-pequeno" data-ir="${pagina + 1}" ${pagina >= paginas ? 'disabled' : ''}>Próxima <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
      </div>`;
    container.querySelectorAll('[data-ir]').forEach((b) => b.addEventListener('click', () => ir(Number(b.dataset.ir))));
  }

  const carregando = (container, texto = 'Carregando...') => {
    container.innerHTML = `<p class="carregando">${esc(texto)}</p>`;
  };

  const vazio = (icone, titulo, texto = '') =>
    `<div class="estado-vazio"><i class="fa-solid ${icone}" aria-hidden="true"></i><strong>${esc(titulo)}</strong>${texto ? `<span>${esc(texto)}</span>` : ''}</div>`;

  // ------------------------------------------------------------------
  // Gerenciador de categorias (Cardápio e Estoque)
  // ------------------------------------------------------------------
  async function gerenciarCategorias({ tipo, aoMudar }) {
    const nomeTipo = tipo === 'CARDAPIO' ? 'do cardápio' : 'de estoque';
    let editando = null;
    let podeEditar = false;

    const m = modal({
      titulo: `Categorias ${nomeTipo}`,
      rotuloFechar: 'Fechar',
      rotuloSalvar: 'Adicionar categoria',
      corpo: `
        <ul class="lista-categorias" data-lista><li class="carregando">Carregando...</li></ul>
        <div class="form-grid" data-campos>
          <input type="hidden" name="id" value="">
          <div class="grupo-input">
            <label for="cat-nome">Nome</label>
            <input type="text" id="cat-nome" name="nome" class="input-dash" maxlength="200" required>
          </div>
          <div class="grupo-input">
            <label for="cat-descricao">Descrição (opcional)</label>
            <input type="text" id="cat-descricao" name="descricao" class="input-dash" maxlength="255">
          </div>
          <div class="grupo-input largo">
            <label class="switch-rotulo">
              <span class="switch"><input type="checkbox" role="switch" name="ativa" checked><span class="switch-trilho" aria-hidden="true"></span></span>
              <span>Categoria ativa</span>
            </label>
          </div>
        </div>`,
      aoEnviar: async (dados, ctx) => {
        const carga = { tipo, nome: dados.nome, descricao: dados.descricao || '', status: dados.ativa ? 'ATIVO' : 'INATIVO' };
        const resposta = editando
          ? await api('categorias', 'atualizar', { dados: { ...carga, id: editando } })
          : await api('categorias', 'criar', { dados: carga });
        toast(resposta.message);
        limpar(ctx);
        await recarregar();
        aoMudar && aoMudar();
        return false;
      },
    });

    const lista = m.el.querySelector('[data-lista]');
    const campos = m.el.querySelector('[data-campos]');

    function limpar(ctx) {
      editando = null;
      campos.querySelector('[name=id]').value = '';
      campos.querySelector('[name=nome]').value = '';
      campos.querySelector('[name=descricao]').value = '';
      campos.querySelector('[name=ativa]').checked = true;
      ctx.rotuloSalvar('Adicionar categoria');
    }

    async function recarregar() {
      const r = await api('categorias', 'listar', { params: { tipo } });
      podeEditar = r.pode_editar;
      campos.hidden = !podeEditar;
      m.el.querySelector('[data-salvar]').hidden = !podeEditar;

      lista.innerHTML = r.data.length
        ? r.data
            .map(
              (c) => `
          <li class="linha-categoria">
            <span class="celula-dupla"><strong>${esc(c.nome)}</strong><small>${numero(c.itens)} ${c.itens === 1 ? 'item' : 'itens'}${c.status === 'INATIVO' ? ' · inativa' : ''}</small></span>
            ${
              podeEditar
                ? `<span class="acoes">
                    <button type="button" class="btn btn-pequeno btn-fantasma" data-editar="${c.id}" aria-label="Editar ${esc(c.nome)}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-pequeno btn-fantasma" data-excluir="${c.id}" aria-label="Excluir ${esc(c.nome)}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                  </span>`
                : ''
            }
          </li>`,
            )
            .join('')
        : '<li class="carregando">Nenhuma categoria cadastrada.</li>';

      lista.querySelectorAll('[data-editar]').forEach((b) =>
        b.addEventListener('click', () => {
          const c = r.data.find((x) => x.id == b.dataset.editar);
          editando = Number(c.id);
          campos.querySelector('[name=nome]').value = c.nome;
          campos.querySelector('[name=descricao]').value = c.descricao === c.nome ? '' : c.descricao;
          campos.querySelector('[name=ativa]').checked = c.status === 'ATIVO';
          m.rotuloSalvar('Salvar categoria');
          campos.querySelector('[name=nome]').focus();
        }),
      );

      lista.querySelectorAll('[data-excluir]').forEach((b) =>
        b.addEventListener('click', async () => {
          const c = r.data.find((x) => x.id == b.dataset.excluir);
          if (!(await confirmar({ titulo: 'Excluir categoria', mensagem: `Excluir a categoria "${c.nome}"?`, rotulo: 'Excluir', perigo: true }))) return;
          try {
            toast((await api('categorias', 'excluir', { dados: { id: c.id } })).message);
            limpar(m);
            await recarregar();
            aoMudar && aoMudar();
          } catch (e) {
            toast(e.message, 'erro');
          }
        }),
      );
    }

    try {
      await recarregar();
    } catch (e) {
      m.mostrarErro(e.message);
    }
  }

  window.XT = {
    api,
    toast,
    modal,
    confirmar,
    filtros,
    paginacao,
    carregando,
    vazio,
    mascaras,
    gerenciarCategorias,
    esc,
    brl,
    numero,
    digitos,
    dataBr,
    hojeIso,
    formatarDocumento,
    formatarTelefone,
    formatarCnpj,
    iniciais,
    debounce,
  };
})();
