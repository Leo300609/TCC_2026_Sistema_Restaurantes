<?php
// ==========================================
// 1. LÓGICA DE SESSÃO E PERMISSÃO (NOVO)
// ==========================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
  header("Location: login.html");
  exit;
}

$cargo = $_SESSION['usuario_cargo'] ?? 'FUNCIONARIO';
$nivel = $_SESSION['usuario_nivel'] ?? 'FUNCIONARIO';
$nomeUsuario = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário');

// Define o que cada um pode ver
$verFinanceiro = ($nivel === 'ADMIN' || $cargo === 'Gerente');
$verEstoque = ($nivel === 'ADMIN' || $cargo === 'Gerente' || $cargo === 'Cozinheiro');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>XTEC - Painel Administrativo</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/dashboard.css"> 

 
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

  <div class="dashboard-layout">

    <!-- SIDEBAR FIXA -->
    <aside class="sidebar">
      <div>
        <div class="sidebar-header">
          <h2 class="nome-empresa">XTEC</h2>
        </div>
        <nav class="sidebar-nav">
          <a href="visao_geral.php" class="nav-item ">
            <i class="fa-solid fa-chart-line"></i>
            <span>Visão Geral</span>
          </a>
          <!-- NOVO: Menu condicional -->
          <?php if ($verFinanceiro): ?>
            <a href="#" class="nav-item active">
              <i class="fa-solid fa-wallet"></i>
              <span>Fluxo de Caixa</span>
            </a>
          <?php endif; ?>

          <?php if ($verEstoque): ?>
            <a href="estoque.php" class="nav-item">
              <i class="fa-solid fa-boxes-stacked"></i>
              <span>Estoque</span>
            </a>
          <?php endif; ?>

          <a href="pedidos.php" class="nav-item">
            <i class="fa-solid fa-receipt"></i>
            <span>Pedidos</span>
          </a>
          <a href="#" class="nav-item">
            <i class="fa-solid fa-utensils"></i>
            <span>Cardápio</span>
          </a>
          <a href="#" class="nav-item">
            <i class="fa-solid fa-users"></i>
            <span>Clientes</span>
          </a>
          <a href="#" class="nav-item">
            <i class="fa-solid fa-gear"></i>
            <span>Configurações</span>
          </a>
        </nav>
      </div>

      <div class="sidebar-footer">
        <!-- NOVO: Exibe o nome e cargo de quem está logado -->
        <span style="font-size:12px; color:var(--creme); opacity:0.7; margin-bottom:8px; display:block; padding: 0 10px;">
          <?= $nomeUsuario ?> <br> (<?= htmlspecialchars($cargo) ?>)
        </span>
        <a href="../backend/logout.php" class="btn-sair">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span>Sair</span>
        </a>
      </div>
    </aside>

    <!-- ÁREA PRINCIPAL COM ROLAGEM INDEPENDENTE -->
    <main class="main-content">

      <header class="topbar">
        <div class="boas-vindas">
          <h1>Visão Geral</h1>
          <p>Operação em tempo real</p>
        </div>
        <div class="usuario-perfil">
          <span class="status-loja online"><i class="fa-solid fa-circle"></i> Loja Aberta</span>
          <!-- NOVO: Nome dinâmico no topo -->
          <span class="nome-admin"><?= $nomeUsuario ?></span>
        </div>
      </header>

      <!-- NOVO: BOTÕES DE EXPORTAÇÃO (Apenas Admin/Gerente) -->
      <?php if ($verFinanceiro): ?>
        <section class="export-actions">
          <a href="../backend/relatorios/gerar_pdf.php" class="btn-export pdf"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</a>
          <a href="../backend/relatorios/gerar_word.php" class="btn-export word"><i class="fa-solid fa-file-word"></i> Exportar Word</a>
          <a href="../backend/relatorios/gerar_excel.php" class="btn-export excel"><i class="fa-solid fa-file-excel"></i> Exportar Excel</a>
        </section>
      <?php endif; ?>

      <!-- METRICAS (MANTIDO ORIGINAL) -->
      <section class="grid-cards">
        <div class="card-metrica">
          <span class="card-titulo">Faturamento Hoje</span>
          <div class="card-valor" id="fat-hoje">R$ 1.840,50</div>
          <span class="card-detalhe positivo">+12% em relação a ontem</span>
        </div>
        <div class="card-metrica">
          <span class="card-titulo">Pedidos Realizados</span>
          <div class="card-valor" id="pedidos-hoje">48</div>
          <span class="card-detalhe positivo">+5 na última hora</span>
        </div>
        <div class="card-metrica">
          <span class="card-titulo">Ticket Médio</span>
          <div class="card-valor">R$ 38,34</div>
          <span class="card-detalhe neutro">Dentro da média</span>
        </div>

        <!-- NOVO: Card de Alerta Condicional -->
        <?php if ($verEstoque): ?>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Alertas de Estoque</span>
            <div class="card-valor" id="alertas-estoque">0</div>
            <span class="card-detalhe alerta">Itens críticos ou vencendo</span>
          </div>
        <?php else: ?>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Em Entrega</span>
            <div class="card-valor">6</div>
            <span class="card-detalhe alerta">Aguardando confirmação</span>
          </div>
        <?php endif; ?>
      </section>

      <!-- ========================================== -->
      <!-- NOVO: SEÇÃO DE RELATÓRIOS E GRÁFICOS       -->
      <!-- ========================================== -->
      <?php if ($verFinanceiro || $verEstoque): ?>
        <div class="dashboard-secoes" style="margin-bottom: 32px;">

          <?php if ($verFinanceiro): ?>
            <section class="painel-card">
              <div class="painel-header">
                <h2><i class="fa-solid fa-chart-pie"></i> Lucro vs Despesas</h2>
              </div>
              <div class="chart-container"><canvas id="graficoPizza"></canvas></div>
            </section>
            <section class="painel-card">
              <div class="painel-header">
                <h2><i class="fa-solid fa-chart-column"></i> Capital Inicial vs Atual</h2>
              </div>
              <div class="chart-container"><canvas id="graficoBarra"></canvas></div>
            </section>
          <?php endif; ?>

          <?php if ($verEstoque): ?>
            <section class="painel-card">
              <div class="painel-header">
                <h2><i class="fa-solid fa-triangle-exclamation"></i> Alertas de Validade</h2>
              </div>
              <div id="lista-vencimento" style="max-height: 250px; overflow-y: auto;">
                <p style="text-align:center; opacity:0.6; padding: 20px;">Carregando dados...</p>
              </div>
            </section>
            <section class="painel-card">
              <div class="painel-header">
                <h2><i class="fa-solid fa-users-viewfinder"></i> Equipe Online Agora</h2>
              </div>
              <div id="lista-equipe" style="max-height: 250px; overflow-y: auto;">
                <p style="text-align:center; opacity:0.6; padding: 20px;">Carregando dados...</p>
              </div>
            </section>
          <?php endif; ?>

        </div>
      <?php endif; ?>
      <!-- FIM DA SEÇÃO NOVA -->


      <!-- GERAR CÓDIGO DA EQUIPE (MANTIDO ORIGINAL) -->
      <section class="painel-card gestao-equipe">
        <div class="painel-header">
          <h2><i class="fa-solid fa-user-plus"></i> Convidar Novo Funcionário</h2>
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
            <label for="nivel-acesso">Nível de Acesso no Sistema</label>
            <select id="nivel-acesso" class="input-dash">
              <option value="1">Nível 1 (Apenas Pedidos)</option>
              <option value="2">Nível 2 (Caixa e Cozinha)</option>
              <option value="3">Nível 3 (Acesso Total / Gerencial)</option>
            </select>
          </div>

          <button type="button" onclick="gerarCodigoEquipe()" class="btn-gerar">
            <i class="fa-solid fa-key"></i> Gerar Código
          </button>
        </div>

        <div id="resultado-codigo" class="resultado-codigo hidden">
          <p>Código de cadastro exclusivo para a equipe:</p>
          <div class="box-codigo">
            <strong id="codigo-gerado">XTEC-00000</strong>
            <button onclick="copiarCodigo()" title="Copiar Código"><i class="fa-regular fa-copy"></i></button>
          </div>
          <small>Forneça este código ao funcionário para que ele se registre na tela de cadastro.</small>
        </div>
      </section>

      <!-- TABELAS E DETALHES (MANTIDO ORIGINAL) -->
      <div class="dashboard-secoes">
        <section class="painel-card">
          <div class="painel-header">
            <h2>Últimos Pedidos</h2>
            <a href="#" class="btn-link">Ver todos</a>
          </div>
          <div class="tabela-container">
            <table class="tabela-pedidos">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Cliente</th>
                  <th>Itens</th>
                  <th>Total</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="id-pedido">#1048</td>
                  <td class="cliente-nome">Lucas Andrade</td>
                  <td>2x X-Tudo, 1x Coca 2L</td>
                  <td class="valor-pedido">R$ 68,00</td>
                  <td><span class="status em-preparo">Em preparo</span></td>
                </tr>
                <tr>
                  <td class="id-pedido">#1047</td>
                  <td class="cliente-nome">Mariana Costa</td>
                  <td>1x Combo Artesanal</td>
                  <td class="valor-pedido">R$ 42,50</td>
                  <td><span class="status a-caminho">A caminho</span></td>
                </tr>
                <tr>
                  <td class="id-pedido">#1046</td>
                  <td class="cliente-nome">Roberto Silva</td>
                  <td>3x Misto Quente</td>
                  <td class="valor-pedido">R$ 31,00</td>
                  <td><span class="status entregue">Entregue</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- NOVO: Gráfico de Linha de Pedidos (Substituindo ou complementando o Mais Vendidos) -->
        <section class="painel-card">
          <div class="painel-header">
            <h2><i class="fa-solid fa-chart-line"></i> Evolução de Pedidos (7 dias)</h2>
          </div>
          <div class="chart-container" style="height: 300px;">
            <canvas id="graficoLinha"></canvas>
          </div>
        </section>
      </div>

    </main>
  </div>

  <script>
    function gerarCodigoEquipe() {
      const funcao = document.getElementById('funcao-funcionario').value;
      const nivel = document.getElementById('nivel-acesso').value;
      const formData = new FormData();
      formData.append('funcao', funcao);
      formData.append('nivel_acesso', nivel);

      fetch('../backend/gerar_codigo_processa.php', { // Corrigido nome do arquivo conforme seu original
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

  
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      fetch('../backend/api/dados_dashboard.php')
        .then(res => res.json())
        .then(data => {
          if (data.erro) return;

          // Atualiza cards se os dados vierem da API
          if (data.financeiro) {
            document.getElementById('fat-hoje').innerText = `R$ ${data.financeiro.faturamento_hoje}`;

            // Gráfico Pizza
            new Chart(document.getElementById('graficoPizza'), {
              type: 'doughnut',
              data: {
                labels: ['Lucro', 'Despesas'],
                datasets: [{
                  data: [data.financeiro.lucro, data.financeiro.despesas],
                  backgroundColor: ['#4ade80', '#ef4444'],
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
            new Chart(document.getElementById('graficoBarra'), {
              type: 'bar',
              data: {
                labels: ['Capital Inicial', 'Capital Atual'],
                datasets: [{
                  data: [data.financeiro.capital_inicial, data.financeiro.capital_atual],
                  backgroundColor: ['#ab6550', '#4ade80'],
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
          if (data.estoque) document.getElementById('alertas-estoque').innerText = data.estoque.total_alertas;

          // Gráfico Linha (Pedidos - Visível para todos)
          new Chart(document.getElementById('graficoLinha'), {
            type: 'line',
            data: {
              labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
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