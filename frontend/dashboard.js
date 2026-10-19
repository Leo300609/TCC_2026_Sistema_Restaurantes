document.addEventListener('DOMContentLoaded', () => {
    fetch('../backend/dashboard_data.php')
        .then(res => res.json())
        .then(data => {
            if (data.erro) return alert(data.erro);

            // Atualiza Cards
            if (data.financeiro) document.getElementById('fat-hoje').innerText = `R$ ${data.financeiro.faturamento_hoje}`;
            document.getElementById('pedidos-hoje').innerText = data.pedidos_hoje;
            if (data.estoque) document.getElementById('alertas-estoque').innerText = data.estoque.total_alertas;

            // Gráfico Pizza (Lucro vs Despesas)
            if (data.financeiro) {
                new Chart(document.getElementById('graficoPizza'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Lucro', 'Despesas'],
                        datasets: [{ data: [data.financeiro.lucro, data.financeiro.despesas], backgroundColor: ['#4ade80', '#ef4444'], borderWidth: 0 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#f2e3c6' } } } }
                });

                // Gráfico Barra (Capital)
                new Chart(document.getElementById('graficoBarra'), {
                    type: 'bar',
                    data: {
                        labels: ['Capital Inicial', 'Capital Atual'],
                        datasets: [{ label: 'R$', data: [data.financeiro.capital_inicial, data.financeiro.capital_atual], backgroundColor: ['#ab6550', '#4ade80'], borderRadius: 6 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { color: '#f2e3c6' }, grid: { color: 'rgba(242, 227, 198, 0.1)' } }, x: { ticks: { color: '#f2e3c6' } } }, plugins: { legend: { display: false } } }
                });
            }

            // Gráfico Linha (Pedidos)
            new Chart(document.getElementById('graficoLinha'), {
                type: 'line',
                data: {
                    labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
                    datasets: [{ label: 'Pedidos', data: data.pedidos_semana, borderColor: '#b12e2f', backgroundColor: 'rgba(177, 46, 47, 0.1)', fill: true, tension: 0.4 }]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { color: '#f2e3c6' }, grid: { color: 'rgba(242, 227, 198, 0.1)' } }, x: { ticks: { color: '#f2e3c6' } } }, plugins: { legend: { display: false } } }
            });

            // Lista de Vencimento
            if (data.estoque && data.estoque.lista.length > 0) {
                const container = document.getElementById('lista-vencimento');
                container.innerHTML = data.estoque.lista.map(item => `
                    <div class="alerta-vencimento ${item.critico ? 'critico' : ''}">
                        <i class="fa-solid ${item.critico ? 'fa-circle-exclamation' : 'fa-clock'}"></i>
                        <div><strong>${item.nome}</strong><br><small>${item.motivo}</small></div>
                    </div>
                `).join('');
            } else if (data.estoque) {
                document.getElementById('lista-vencimento').innerHTML = '<p style="text-align:center; color:#4ade80;"><i class="fa-solid fa-check"></i> Estoque em dia</p>';
            }

            // Lista de Equipe
            const equipeContainer = document.getElementById('lista-equipe');
            if (data.equipe_online.length > 0) {
                equipeContainer.innerHTML = data.equipe_online.map(func => `
                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(242,227,198,0.15);">
                        <span>${func.nome}</span>
                        <span class="status-online"><small>${func.cargo}</small></span>
                    </div>
                `).join('');
            } else {
                equipeContainer.innerHTML = '<p style="text-align:center; opacity:0.6;">Nenhum funcionário online no momento.</p>';
            }
        })
        .catch(err => console.error('Erro ao carregar dashboard:', err));
});