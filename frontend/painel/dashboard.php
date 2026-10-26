<?php
require __DIR__ . '/_partials/auth.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <title>Visão Geral · XTEC</title>
  <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../css/painel/dashboard.css">
  <link rel="stylesheet" href="../css/painel/visao_geral.css">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
  <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>

  <div class="dashboard-layout">

    <?php $paginaAtual = 'dashboard'; include __DIR__ . '/_partials/sidebar.php'; ?>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1>Visão Geral</h1>
            <p>Operação em tempo real</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja online"><i class="fa-solid fa-circle" aria-hidden="true"></i> Loja aberta</span>
            <span class="nome-admin"><?= $nomeUsuario ?></span>
          </div>
        </header>

        <?php if ($verFinanceiro): ?>
          <section class="export-actions" aria-label="Exportar relatórios">
            <a href="../../backend/relatorios/gerar_pdf.php" class="btn-export pdf"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Exportar PDF</a>
            <a href="../../backend/relatorios/gerar_word.php" class="btn-export word"><i class="fa-solid fa-file-word" aria-hidden="true"></i> Exportar Word</a>
            <a href="../../backend/relatorios/gerar_excel.php" class="btn-export excel"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Exportar Excel</a>
          </section>
        <?php endif; ?>

        <section class="grid-cards" aria-label="Indicadores do dia">
          <div class="card-metrica">
            <span class="card-titulo">Faturamento hoje</span>
            <div class="card-valor" id="fat-hoje">–</div>
            <span class="card-detalhe neutro" id="fat-detalhe">Calculando...</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Pedidos realizados</span>
            <div class="card-valor" id="pedidos-hoje">–</div>
            <span class="card-detalhe neutro" id="pedidos-detalhe">&nbsp;</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Ticket médio</span>
            <div class="card-valor" id="ticket-medio">–</div>
            <span class="card-detalhe neutro">Pedidos finalizados hoje</span>
          </div>

          <?php if ($verEstoque): ?>
            <div class="card-metrica alerta-box">
              <span class="card-titulo">Alertas de estoque</span>
              <div class="card-valor" id="alertas-estoque">0</div>
              <span class="card-detalhe alerta">Itens críticos ou vencendo</span>
            </div>
          <?php else: ?>
            <div class="card-metrica alerta-box">
              <span class="card-titulo">Em entrega</span>
              <div class="card-valor" id="em-entrega">–</div>
              <span class="card-detalhe alerta">Delivery a caminho</span>
            </div>
          <?php endif; ?>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card">
            <div class="painel-header">
              <h2>Últimos pedidos</h2>
              <a href="pedidos.php" class="btn-link">Ver todos</a>
            </div>
            <div class="tabela-container">
              <table class="tabela-pedidos">
                <thead>
                  <tr>
                    <th scope="col">Pedido</th>
                    <th scope="col">Cliente</th>
                    <th scope="col">Itens</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody id="tabela-ultimos">
                  <tr><td colspan="5" class="carregando">Carregando pedidos...</td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="painel-card">
            <div class="painel-header">
              <h2>Evolução de pedidos</h2>
              <span class="painel-meta">Últimos 7 dias</span>
            </div>
            <div class="chart-container alto">
              <canvas id="graficoLinha" role="img" aria-label="Gráfico de linha com os pedidos dos últimos 7 dias"></canvas>
            </div>
          </section>
        </div>

        <?php if ($verFinanceiro || $verEstoque): ?>
          <div class="dashboard-secoes secoes-graficos">

            <?php if ($verFinanceiro): ?>
              <section class="painel-card">
                <div class="painel-header">
                  <h2><i class="fa-solid fa-chart-pie" aria-hidden="true"></i> Lucro vs Despesas</h2>
                </div>
                <div class="chart-container"><canvas id="graficoPizza" role="img" aria-label="Gráfico de rosca comparando lucro e despesas"></canvas></div>
              </section>
              <section class="painel-card">
                <div class="painel-header">
                  <h2><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Capital inicial vs atual</h2>
                </div>
                <div class="chart-container"><canvas id="graficoBarra" role="img" aria-label="Gráfico de barras comparando capital inicial e atual"></canvas></div>
              </section>
            <?php endif; ?>

            <?php if ($verEstoque): ?>
              <section class="painel-card">
                <div class="painel-header">
                  <h2><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Alertas de validade</h2>
                </div>
                <div id="lista-vencimento" class="lista-rolavel" aria-live="polite">
                  <p class="carregando">Carregando dados...</p>
                </div>
              </section>
              <section class="painel-card">
                <div class="painel-header">
                  <h2><i class="fa-solid fa-users-viewfinder" aria-hidden="true"></i> Equipe online agora</h2>
                </div>
                <div id="lista-equipe" class="lista-rolavel" aria-live="polite">
                  <p class="carregando">Carregando dados...</p>
                </div>
              </section>
            <?php endif; ?>

          </div>
        <?php endif; ?>

        <section class="painel-card gestao-equipe" id="convidar">
          <div class="painel-header">
            <h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Convidar novo funcionário</h2>
          </div>

          <div class="form-gerar-codigo">
            <div class="grupo-input">
              <label for="funcao-funcionario">Função / Cargo</label>
              <select id="funcao-funcionario" class="input-dash">
                <option value="Atendente / Caixa">Atendente / Caixa</option>
                <option value="Cozinheiro">Cozinheiro</option>
                <option value="Entregador">Entregador</option>
                <option value="Gerente">Gerente</option>
              </select>
            </div>

            <div class="grupo-input">
              <label for="nivel-acesso">Nível de acesso no sistema</label>
              <select id="nivel-acesso" class="input-dash">
                <option value="1">Nível 1 (Apenas Pedidos)</option>
                <option value="2">Nível 2 (Caixa e Cozinha)</option>
                <option value="3">Nível 3 (Acesso Total / Gerencial)</option>
              </select>
            </div>

            <button type="button" onclick="gerarCodigoEquipe()" class="btn-gerar">
              <i class="fa-solid fa-key" aria-hidden="true"></i> Gerar código
            </button>
          </div>

          <div id="resultado-codigo" class="resultado-codigo hidden" aria-live="polite">
            <p>Código de cadastro exclusivo para a equipe:</p>
            <div class="box-codigo">
              <strong id="codigo-gerado">XTEC-00000</strong>
              <button type="button" onclick="copiarCodigo()" title="Copiar código" aria-label="Copiar código"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
            </div>
            <small>Forneça este código ao funcionário para que ele se registre na tela de cadastro.</small>
          </div>
        </section>

      </div>
    </main>
  </div>

  <!-- SCRIPT ORIGINAL MANTIDO -->
  <script>
    function gerarCodigoEquipe() {
      const funcao = document.getElementById('funcao-funcionario').value;
      const nivel = document.getElementById('nivel-acesso').value;
      const formData = new FormData();
      formData.append('funcao', funcao);
      formData.append('nivel_acesso', nivel);

      fetch('../../backend/auth/gerar_codigo_processa.php', { // Corrigido nome do arquivo conforme seu original
          method: 'POST',
          body: formData
        })
        .then(async response => {
          const text = await response.text();
          try {
            return JSON.parse(text);
          } catch (err) {
            throw new Error("O PHP retornou um erro inesperado: " + text);
          }
        })
        .then(data => {
          if (data.sucesso) {
            document.getElementById('codigo-gerado').innerText = data.codigo;
            document.getElementById('resultado-codigo').classList.remove('hidden');
          } else {
            alert('Erro do Sistema: ' + data.mensagem);
          }
        })
        .catch(error => {
          console.error('Erro na requisição:', error);
          alert(error.message);
        });
    }

    function copiarCodigo() {
      const codigo = document.getElementById('codigo-gerado').innerText;
      navigator.clipboard.writeText(codigo);
      alert('Código copiado para a área de transferência!');
    }
  </script>

  <!-- NOVO: SCRIPT PARA CARREGAR OS DADOS DO DASHBOARD E GRÁFICOS -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Se o Chart.js (CDN) não carregar, os números continuam aparecendo; só os gráficos ficam de fora
      const temChart = typeof Chart !== 'undefined';
      fetch('../../backend/api/dados_dashboard.php')
        .then(res => res.json())
        .then(data => {
          if (data.erro) return;

          // Atualiza cards se os dados vierem da API
          if (data.financeiro) {
            document.getElementById('fat-hoje').innerText = `R$ ${data.financeiro.faturamento_hoje}`;

            // Gráfico Pizza
            if (temChart) new Chart(document.getElementById('graficoPizza'), {
              type: 'doughnut',
              data: {
                labels: ['Lucro', 'Despesas'],
                datasets: [{
                  data: [data.financeiro.lucro, data.financeiro.despesas],
                  backgroundColor: ['rgba(27, 199, 27, 0.795)', '#fa4444'],
                  borderWidth: 0
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                  legend: {
                    position: 'bottom',
                    labels: {
                      color: '#f2e3c6'
                    }
                  }
                }
              }
            });

            // Gráfico Barra
            if (temChart) new Chart(document.getElementById('graficoBarra'), {
              type: 'bar',
              data: {
                labels: ['Capital Inicial', 'Capital Atual'],
                datasets: [{
                  data: [data.financeiro.capital_inicial, data.financeiro.capital_atual],
                  backgroundColor: ['#fa4444', 'rgba(27, 199, 27, 0.795)'],
                  borderRadius: 6
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                  y: {
                    ticks: {
                      color: '#f2e3c6'
                    },
                    grid: {
                      color: 'rgba(242, 227, 198, 0.1)'
                    }
                  },
                  x: {
                    ticks: {
                      color: '#f2e3c6'
                    }
                  }
                },
                plugins: {
                  legend: {
                    display: false
                  }
                }
              }
            });
          }

          document.getElementById('pedidos-hoje').innerText = data.pedidos_hoje;
          document.getElementById('pedidos-detalhe').innerText = `${data.pedidos_ultima_hora} na última hora`;
          document.getElementById('ticket-medio').innerText = data.ticket_medio === null ? '—' : data.ticket_medio.toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
          });
          const emEntrega = document.getElementById('em-entrega');
          if (emEntrega) emEntrega.innerText = data.em_entrega;

          // Faturamento: variação sobre ontem (só quem pode ver o financeiro)
          if (data.financeiro) {
            const hoje = data.financeiro.faturamento_hoje_valor;
            const ontem = data.financeiro.faturamento_ontem;
            const detalhe = document.getElementById('fat-detalhe');
            if (ontem > 0) {
              const variacao = Math.round(((hoje - ontem) / ontem) * 100);
              detalhe.innerText = `${variacao >= 0 ? '+' : ''}${variacao}% em relação a ontem`;
              detalhe.className = `card-detalhe ${variacao >= 0 ? 'positivo' : 'alerta'}`;
            } else {
              detalhe.innerText = 'Sem vendas ontem para comparar';
            }
          } else {
            document.getElementById('fat-hoje').innerText = '—';
            document.getElementById('fat-detalhe').innerText = 'Restrito à gerência';
          }

          // Últimos pedidos
          const nomesEtapa = {
            NOVO: ['novo', 'Novo'],
            PREPARO: ['em-preparo', 'Em preparo'],
            PRONTO: ['a-caminho', 'Pronto'],
            ENTREGUE: ['entregue', 'Entregue'],
            CANCELADO: ['cancelado', 'Cancelado']
          };
          const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
          })[c]);
          document.getElementById('tabela-ultimos').innerHTML = data.ultimos_pedidos.length ?
            data.ultimos_pedidos.map((p) => {
              const [classe, rotulo] = nomesEtapa[p.etapa] || ['', p.etapa];
              return `<tr>
                <td class="id-pedido">#${p.id}</td>
                <td class="cliente-nome">${escapar(p.cliente)}</td>
                <td>${escapar(p.itens)}</td>
                <td class="num valor-pedido">${p.total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</td>
                <td><span class="status ${classe}">${rotulo}</span></td>
              </tr>`;
            }).join('') :
            '<tr><td colspan="5" class="carregando">Nenhum pedido registrado ainda.</td></tr>';
          if (data.estoque) document.getElementById('alertas-estoque').innerText = data.estoque.total_alertas;

          // Gráfico Linha (Pedidos - Visível para todos)
          if (temChart) new Chart(document.getElementById('graficoLinha'), {
            type: 'line',
            data: {
              labels: data.pedidos_semana_labels,
              datasets: [{
                label: 'Pedidos',
                data: data.pedidos_semana,
                borderColor: '#b12e2f',
                backgroundColor: 'rgba(177, 46, 47, 0.1)',
                fill: true,
                tension: 0.4
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                y: {
                  ticks: {
                    color: '#f2e3c6'
                  },
                  grid: {
                    color: 'rgba(242, 227, 198, 0.1)'
                  }
                },
                x: {
                  ticks: {
                    color: '#f2e3c6'
                  }
                }
              },
              plugins: {
                legend: {
                  display: false
                }
              }
            }
          });

          // Lista de Vencimento
          if (data.estoque && data.estoque.lista.length > 0) {
            document.getElementById('lista-vencimento').innerHTML = data.estoque.lista.map(item => `
                        <div class="alerta-vencimento ${item.critico ? 'critico' : ''}">
                            <i class="fa-solid ${item.critico ? 'fa-circle-exclamation' : 'fa-clock'}"></i>
                            <div><strong>${item.nome}</strong><br><small>${item.motivo}</small></div>
                        </div>
                    `).join('');
          } else if (data.estoque) {
            document.getElementById('lista-vencimento').innerHTML = '<p style="text-align:center; color:#4ade80; padding:20px;"><i class="fa-solid fa-check"></i> Estoque em dia</p>';
          }

          // Lista de Equipe
          if (data.equipe_online && data.equipe_online.length > 0) {
            document.getElementById('lista-equipe').innerHTML = data.equipe_online.map(func => `
                        <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid rgba(242,227,198,0.15);">
                            <span>${func.nome}</span>
                            <span class="status-online" style="color:#4ade80;"><small>${func.cargo}</small></span>
                        </div>
                    `).join('');
          } else {
            document.getElementById('lista-equipe').innerHTML = '<p style="text-align:center; opacity:0.6; padding:20px;">Nenhum funcionário online no momento.</p>';
          }
        })
        .catch(err => console.error('Erro ao carregar dashboard:', err));
    });
  </script>
</body>

</html>