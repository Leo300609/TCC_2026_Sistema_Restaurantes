<?php
$pageTitle = 'Fluxo de Caixa';
$pageSubtitle = 'Contas a receber, contas a pagar e lançamentos avulsos';
$activePage = 'fluxo_de_caixa.php';

require __DIR__ . '/includes/page_shell.php';
?>
<div class="module-toolbar">
    <div>
        <h2>Movimentação financeira</h2>
        <p>
            Acompanhe entradas, saídas e pagamentos sem apagar o histórico de
            vendas ou compras.
        </p>
    </div>
    <div class="toolbar-actions">
        <input
            id="search"
            class="search-control"
            type="search"
            placeholder="Buscar contraparte, tipo ou status"
            aria-label="Buscar movimentação"
        />
        <select id="filter" class="select-control" aria-label="Filtrar tipo">
            <option value="TODOS">Todas as movimentações</option>
            <option value="ENTRADA">Entradas</option>
            <option value="SAIDA">Saídas</option>
        </select>
        <button class="btn-primary" id="add" type="button">
            <i class="fa-solid fa-plus"></i>
            Lançamento avulso
        </button>
    </div>
</div>

<div class="stat-row">
    <div class="stat-card">
        <span>Total recebido</span>
        <strong id="received">—</strong>
    </div>
    <div class="stat-card">
        <span>Total pago</span>
        <strong id="paid">—</strong>
    </div>
    <div class="stat-card">
        <span>Saldo realizado</span>
        <strong id="balance">—</strong>
    </div>
</div>

<section class="panel-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Origem / Nº</th>
                    <th>Contraparte</th>
                    <th>Vencimento</th>
                    <th>Pagamento</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="rows">
                <tr>
                    <td colspan="8" class="empty-state">
                        Carregando lançamentos…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<div class="modal-backdrop" id="modal">
    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
    >
        <div class="modal-header">
            <h2 id="modal-title">Novo lançamento avulso</h2>
            <button
                type="button"
                class="close-modal"
                data-close
                aria-label="Fechar"
            >
                &times;
            </button>
        </div>
        <form id="form">
            <input type="hidden" name="id" />
            <div class="form-grid">
                <div class="field">
                    <label for="flow-type">Tipo *</label>
                    <select
                        id="flow-type"
                        class="select-control"
                        name="tipo"
                        required
                    >
                        <option value="ENTRADA">Entrada</option>
                        <option value="SAIDA">Saída</option>
                    </select>
                </div>
                <div class="field">
                    <label for="flow-description">Descrição *</label>
                    <input
                        id="flow-description"
                        class="input-control"
                        name="descricao"
                        maxlength="255"
                        required
                    />
                </div>
                <div class="field">
                    <label for="flow-category">Categoria *</label>
                    <input
                        id="flow-category"
                        class="input-control"
                        name="categoria"
                        value="Geral"
                        maxlength="100"
                        required
                    />
                </div>
                <div class="field">
                    <label for="flow-value">Valor (R$) *</label>
                    <input
                        id="flow-value"
                        class="input-control"
                        name="valor"
                        type="number"
                        min="0.01"
                        step="0.01"
                        required
                    />
                </div>
                <div class="field">
                    <label for="flow-date">Data *</label>
                    <input
                        id="flow-date"
                        class="input-control"
                        name="data_movimentacao"
                        type="date"
                        required
                    />
                </div>
                <div class="field">
                    <label for="flow-status">Status</label>
                    <select
                        id="flow-status"
                        class="select-control"
                        name="status"
                    >
                        <option value="PENDENTE">Pendente</option>
                        <option value="PAGO">Pago</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn-secondary" type="button" data-close>
                    Cancelar
                </button>
                <button class="btn-primary" type="submit">
                    Salvar lançamento
                </button>
            </div>
        </form>
    </section>
</div>

<script>
    'use strict';

    const tbody = document.getElementById('rows');
    const modal = document.getElementById('modal');
    const form = document.getElementById('form');
    const allowedStatusOptions = ['PENDENTE', 'PAGO', 'CANCELADO'];
    const statusLabels = {
        PENDENTE: 'Pendente',
        PAGO: 'Pago',
        CANCELADO: 'Cancelado',
        ATRASADO: 'Atrasado',
        NEGOCIANDO: 'Em negociação',
        AGENDADO: 'Agendado',
    };

    let accounts = [];

    function money(value) {
        const number = Number(value ?? 0);
        const safeNumber = Number.isFinite(number) ? number : 0;

        return `R$ ${safeNumber.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    }

    function formatDate(value) {
        if (!value) {
            return '—';
        }

        return new Date(`${value}T00:00:00`).toLocaleDateString('pt-BR');
    }

    function localDateValue() {
        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    function statusClass(status) {
        if (status === 'PAGO') {
            return 'success';
        }

        if (status === 'ATRASADO' || status === 'CANCELADO') {
            return 'danger';
        }

        return 'warning';
    }

    function statusLabel(status) {
        return statusLabels[status] ?? status;
    }

    function render() {
        const query = document
            .getElementById('search')
            .value.toLocaleLowerCase('pt-BR');
        const typeFilter = document.getElementById('filter').value;
        const filteredAccounts = accounts.filter((account) => {
            const matchesType =
                typeFilter === 'TODOS' || account.tipo === typeFilter;
            const matchesQuery = [
                account.contraparte,
                account.tipo,
                account.status,
                account.categoria,
                String(account.referencia),
            ].some((value) =>
                String(value ?? '')
                    .toLocaleLowerCase('pt-BR')
                    .includes(query),
            );

            return matchesType && matchesQuery;
        });

        if (filteredAccounts.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="empty-state">Nenhum lançamento encontrado.</td></tr>';
            return;
        }

        tbody.innerHTML = filteredAccounts
            .map((account) => {
                const isManual = account.origem === 'MANUAL';
                const typeLabel =
                    account.tipo === 'ENTRADA' ? 'Entrada' : 'Saída';
                const extraStatusOption = allowedStatusOptions.includes(
                    account.status,
                )
                    ? ''
                    : `<option value="${escapeHtml(account.status)}" selected disabled>${escapeHtml(statusLabel(account.status))}</option>`;
                const editButton = isManual && account.status !== 'CANCELADO'
                    ? `<button class="btn-small edit" type="button" data-id="${account.id}" title="Editar lançamento"><i class="fa-solid fa-pen"></i></button>`
                    : '';
                const disabledSelect =
                    account.status === 'CANCELADO' ? 'disabled' : '';

                return `
                    <tr>
                        <td><span class="badge ${account.tipo === 'ENTRADA' ? 'success' : 'warning'}">${typeLabel}</span></td>
                        <td>${isManual ? 'Avulso' : escapeHtml(account.origem)} #${escapeHtml(account.referencia)}</td>
                        <td>${escapeHtml(account.contraparte)}${account.categoria ? `<br><small class="muted">${escapeHtml(account.categoria)}</small>` : ''}</td>
                        <td>${formatDate(account.vencimento)}</td>
                        <td>${formatDate(account.pagamento)}</td>
                        <td>${money(account.total)}</td>
                        <td><span class="badge ${statusClass(account.status)}">${escapeHtml(statusLabel(account.status))}</span></td>
                        <td>
                            <div class="row-actions">
                                ${editButton}
                                <select
                                    class="select-control status-change"
                                    data-id="${account.id}"
                                    data-type="${account.tipo}"
                                    data-origin="${account.origem}"
                                    aria-label="Alterar status do lançamento ${escapeHtml(account.referencia)}"
                                    style="min-width: 120px"
                                    ${disabledSelect}
                                >
                                    ${extraStatusOption}
                                    <option value="PENDENTE" ${account.status === 'PENDENTE' ? 'selected' : ''}>Pendente</option>
                                    <option value="PAGO" ${account.status === 'PAGO' ? 'selected' : ''}>Pago</option>
                                    <option value="CANCELADO" ${account.status === 'CANCELADO' ? 'selected' : ''}>Cancelar</option>
                                </select>
                            </div>
                        </td>
                    </tr>
                `;
            })
            .join('');
    }

    async function load() {
        try {
            const response = await apiGet('cashflow');
            accounts = Array.isArray(response.data) ? response.data : [];

            const received = accounts
                .filter(
                    (account) =>
                        account.tipo === 'ENTRADA' && account.status === 'PAGO',
                )
                .reduce((sum, account) => sum + Number(account.total), 0);
            const paid = accounts
                .filter(
                    (account) =>
                        account.tipo === 'SAIDA' && account.status === 'PAGO',
                )
                .reduce((sum, account) => sum + Number(account.total), 0);

            document.getElementById('received').textContent = money(received);
            document.getElementById('paid').textContent = money(paid);
            document.getElementById('balance').textContent = money(
                received - paid,
            );
            render();
        } catch (error) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
            showMessage(error.message, true);
        }
    }

    function openForm(account = null) {
        form.reset();
        form.elements.namedItem('id').value = account?.id ?? '';
        form.elements.namedItem('tipo').value = account?.tipo ?? 'ENTRADA';
        form.elements.namedItem('descricao').value =
            account?.contraparte ?? '';
        form.elements.namedItem('categoria').value =
            account?.categoria ?? 'Geral';
        form.elements.namedItem('valor').value = account?.total ?? '';
        form.elements.namedItem('data_movimentacao').value =
            account?.vencimento ?? localDateValue();
        form.elements.namedItem('status').value =
            account?.status === 'PAGO' ? 'PAGO' : 'PENDENTE';
        document.getElementById('modal-title').textContent = account
            ? 'Editar lançamento avulso'
            : 'Novo lançamento avulso';
        modal.classList.add('open');
    }

    document.getElementById('add').addEventListener('click', () => openForm());
    document.getElementById('search').addEventListener('input', render);
    document.getElementById('filter').addEventListener('change', render);

    document.querySelectorAll('[data-close]').forEach((button) => {
        button.addEventListener('click', () => modal.classList.remove('open'));
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            modal.classList.remove('open');
        }
    });

    tbody.addEventListener('click', (event) => {
        const button = event.target.closest('.edit');

        if (!button) {
            return;
        }

        const account = accounts.find(
            (item) =>
                item.origem === 'MANUAL' && item.id == button.dataset.id,
        );

        if (account) {
            openForm(account);
        }
    });

    tbody.addEventListener('change', async (event) => {
        const select = event.target.closest('.status-change');

        if (!select) {
            return;
        }

        const account = accounts.find(
            (item) =>
                item.id == select.dataset.id &&
                item.tipo === select.dataset.type &&
                item.origem === select.dataset.origin,
        );

        if (!account) {
            return;
        }

        const nextStatus = select.value;

        if (!confirm(`Alterar o lançamento para ${statusLabel(nextStatus)}?`)) {
            select.value = account.status;
            return;
        }

        select.disabled = true;

        try {
            const response = await apiPost('pay_account', {
                id: Number(select.dataset.id),
                tipo: select.dataset.type,
                origem: select.dataset.origin,
                status: nextStatus,
            });

            showMessage(response.message);
            await load();
        } catch (error) {
            select.value = account.status;
            showMessage(error.message, true);
        } finally {
            select.disabled = account.status === 'CANCELADO';
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const data = Object.fromEntries(new FormData(form));
        data.id = Number(data.id) || 0;
        data.valor = Number(data.valor);

        if (!Number.isFinite(data.valor) || data.valor <= 0) {
            showMessage('Informe um valor maior que zero.', true);
            return;
        }

        const action = data.id
            ? 'update_manual_flow'
            : 'create_manual_flow';

        try {
            const response = await apiPost(action, data);
            modal.classList.remove('open');
            showMessage(response.message);
            await load();
        } catch (error) {
            showMessage(error.message, true);
        }
    });

    load();
</script>
</div>
</main>
</div>
</body>
</html>
