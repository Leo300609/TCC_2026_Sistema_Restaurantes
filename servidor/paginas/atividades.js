'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina, corAvatar } = require('../layout');
const {
  h, ler, entrada, entradaInt, url, paginacao, periodoFiltro, periodosRotulos, numero, dataBr, iniciais, sel,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const POR_PAGINA = 25;

// nome e ícone de cada área do sistema
const AREAS = {
  sessao: ['Acesso', 'fa-right-to-bracket'], pdv: ['PDV', 'fa-cash-register'], pedidos: ['Pedidos', 'fa-receipt'],
  mesas: ['Mesas', 'fa-chair'], cozinha: ['Cozinha', 'fa-fire-burner'], cardapios: ['Cardápio', 'fa-utensils'],
  estoque: ['Estoque', 'fa-boxes-stacked'], compras: ['Compras', 'fa-cart-flatbed'], fornecedores: ['Fornecedores', 'fa-truck-field'],
  clientes: ['Clientes', 'fa-users'], fluxo_de_caixa: ['Fluxo de caixa', 'fa-wallet'], empresa: ['Empresa e equipe', 'fa-store'],
  equipe: ['Equipe', 'fa-user-plus'], configs: ['Configurações', 'fa-gear'],
};
const area = (m) => AREAS[m] || [m.charAt(0).toUpperCase() + m.slice(1).replace(/_/g, ' '), 'fa-circle-info'];

module.exports = async function atividades(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.auditoria);

  const [de, ate, periodo] = periodoFiltro(ctx, '7dias');
  const usuarioFiltro = entradaInt(ctx, 'usuario');
  const modulo = entrada(ctx, 'modulo');
  const busca = entrada(ctx, 'q');
  let pg = Math.max(1, entradaInt(ctx, 'pagina', 1));

  const contagem = (await ler(ctx, () => bd.chamarUm('sp_atividade_contagem', [usuarioFiltro, modulo, busca, de, ate]), null)) || { total: 0, usuarios: 0, acessos: 0 };
  const paginasTotal = Math.max(1, Math.ceil(N(contagem.total) / POR_PAGINA));
  pg = Math.min(pg, paginasTotal);
  const [registros, usuarios, modulosBd] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_atividade_listar', [usuarioFiltro, modulo, busca, de, ate, POR_PAGINA, (pg - 1) * POR_PAGINA])),
    ler(ctx, () => bd.chamar('sp_equipe_listar')),
    ler(ctx, () => bd.chamar('sp_atividade_modulos')),
  ]);
  const modulos = modulosBd.map((m) => m.modulo);

  const conteudo = h`
        <section class="grid-cards tres" aria-label="Resumo">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span> Registros no período</span>
            <div class="card-valor">${numero(contagem.total)}</div>
            <span class="card-detalhe">${dataBr(de)} a ${dataBr(ate)}</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span> Pessoas ativas</span>
            <div class="card-valor">${numero(contagem.usuarios)}</div>
            <span class="card-detalhe">Fizeram ao menos uma ação</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone roxo"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i></span> Acessos</span>
            <div class="card-valor">${numero(contagem.acessos)}</div>
            <span class="card-detalhe">Entradas no sistema</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="atividades">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar na descrição</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Buscar ação ou pessoa" autocomplete="off">
            </label>
            <div class="grupo-input seletor">
              <label class="sr-only" for="usuario">Pessoa</label>
              <select id="usuario" name="usuario" class="input-dash">
                <option value="0">Toda a equipe</option>
                ${usuarios.map((x) => h`<option value="${N(x.id)}"${sel(usuarioFiltro === N(x.id))}>${x.nome}</option>`)}
              </select>
            </div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="modulo">Área</label>
              <select id="modulo" name="modulo" class="input-dash">
                <option value="">Todas as áreas</option>
                ${modulos.map((m) => h`<option value="${m}"${sel(modulo === m)}>${area(m)[0]}</option>`)}
              </select>
            </div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" name="periodo" class="input-dash">
                ${Object.entries(periodosRotulos()).filter(([v]) => v !== 'personalizado').map(([v, r]) => h`<option value="${v}"${sel(periodo === v)}>${r}</option>`)}
              </select>
            </div>
            <button type="submit" class="btn btn-secundario">Filtrar</button>
          </div>
        </form>

        <section class="painel-card" aria-label="Linha do tempo">
          ${!registros.length ? h`<p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>Nenhuma atividade encontrada com esses filtros.</p>` : h`
            <ul class="linha-tempo">
              ${registros.map((l) => {
                const [nomeArea, icone] = area(l.modulo);
                return h`<li>
                  <span class="hora"><strong>${l.hora}</strong>${l.data}</span>
                  <span class="avatar ${corAvatar(l.usuario || '')}" aria-hidden="true">${iniciais(l.usuario || '?')}</span>
                  <span class="celula-dupla"><strong>${l.descricao}</strong><small>${l.usuario || 'Sistema'}${l.ip ? ' · IP ' + l.ip : ''}</small></span>
                  <span class="selo"><i class="fa-solid ${icone}" aria-hidden="true"></i> ${nomeArea}</span>
                </li>`;
              })}
            </ul>
            ${paginacao(ctx, pg, paginasTotal, N(contagem.total), POR_PAGINA)}`}
        </section>`;

  return pagina(ctx, { titulo: 'Atividades', subtitulo: 'Histórico de quem fez o quê no sistema', paginaAtual: 'atividades' }, conteudo);
};
