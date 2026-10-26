/* Configurações · conta, notificações, operação e segurança */
(() => {
  const { api, toast, esc } = XT;

  const form = document.getElementById('form-configs');
  let original = null;

  const marcar = (nome, valor) => {
    form.elements[nome].checked = Boolean(valor);
  };

  /** Garante que o <select> tenha a opção salva (ex.: 5 dias), mesmo que não esteja na lista padrão. */
  function selecionar(select, valor, sufixo) {
    const texto = String(valor);
    if (![...select.options].some((o) => o.value === texto)) {
      const opcao = document.createElement('option');
      opcao.value = texto;
      opcao.textContent = `${texto} ${sufixo}`;
      select.appendChild(opcao);
    }
    select.value = texto;
  }

  function preencher() {
    const { conta, notificacoes: n, operacao: o } = original;

    form.elements.nome.value = conta.nome;
    form.elements.email.value = conta.email;
    form.elements.senha_atual.value = '';
    form.elements.senha_nova.value = '';

    marcar('notif_pedido', n.notif_pedido);
    marcar('notif_minimo', n.notif_minimo);
    marcar('notif_validade', n.notif_validade);
    marcar('notif_contas', n.notif_contas);
    selecionar(form.elements.dias_validade, n.dias_validade, n.dias_validade === 1 ? 'dia' : 'dias');

    marcar('aceita_delivery', o.aceita_delivery);
    selecionar(form.elements.tempo_preparo, o.tempo_preparo, 'minutos');
    form.querySelectorAll('[name="pagamentos[]"]').forEach((c) => (c.checked = o.pagamentos.includes(c.value)));

    const podeOperacao = original.pode_editar_operacao;
    document.querySelectorAll('#operacao input, #operacao select').forEach((campo) => (campo.disabled = !podeOperacao));
    document.getElementById('aviso-operacao').hidden = podeOperacao;
  }

  async function carregar() {
    try {
      original = await api('configs', 'obter');
      preencher();
    } catch (e) {
      toast(e.message, 'erro');
    }
  }

  form.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!form.checkValidity()) return form.reportValidity();

    const f = form.elements;
    const carga = {
      nome: f.nome.value,
      email: f.email.value,
      senha_atual: f.senha_atual.value,
      senha_nova: f.senha_nova.value,
      notif_pedido: f.notif_pedido.checked,
      notif_minimo: f.notif_minimo.checked,
      notif_validade: f.notif_validade.checked,
      notif_contas: f.notif_contas.checked,
      dias_validade: f.dias_validade.value,
    };

    if (original.pode_editar_operacao) {
      carga.aceita_delivery = f.aceita_delivery.checked;
      carga.tempo_preparo = f.tempo_preparo.value;
      carga.pagamentos = [...form.querySelectorAll('[name="pagamentos[]"]:checked')].map((c) => c.value);
    }

    const botao = form.querySelector('[type=submit]');
    botao.disabled = true;
    try {
      const resposta = await api('configs', 'salvar', { dados: carga });
      toast(resposta.message);

      document.querySelectorAll('.nome-admin, .usuario-dados strong').forEach((el) => (el.textContent = carga.nome));
      await carregar();
    } catch (e) {
      toast(e.message, 'erro');
    } finally {
      botao.disabled = false;
    }
  });

  document.getElementById('btn-descartar').addEventListener('click', () => {
    if (original) preencher();
    toast('Alterações descartadas.');
  });

  carregar();
})();
