'use strict';
/**
 * Login (bloqueio após várias tentativas erradas, por e-mail e por IP, e senha
 * com bcrypt), logout e cadastro de funcionário com código de convite.
 */
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const bd = require('./bd');
const config = require('./config');
const sessao = require('./sessao');
const { Redirecionar } = require('./web');

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const consulta = (o) => '?' + new URLSearchParams(o).toString();
const HASH_FALSO = '$2a$10$f9eLz9kh/hbzcyR4Ss/2neO0n3KkmYb8qjHkRZ7q9Yq0b0rQe0q7y';

async function login(ctx) {
  const voltar = (erro, email = '') => { throw new Redirecionar(config.loginUrl + consulta(email ? { erro, email } : { erro })); };
  if (ctx.metodo !== 'POST') throw new Redirecionar(config.loginUrl);

  const email = String(ctx.corpo.email || '').trim().toLowerCase();
  const senha = String(ctx.corpo.senha || '');
  const ip = sessao.ipCliente(ctx.req);
  if (!email || !senha || !EMAIL.test(email)) voltar('dados', email);

  try {
    const falhas = (await bd.chamarUm('sp_login_falhas', [email, ip])) || { falhas_email: 0, falhas_ip: 0 };
    const max = config.loginMaxFalhas;
    if (Number(falhas.falhas_email) >= max || Number(falhas.falhas_ip) >= max * 4) {
      await sessao.registrarAtividade(ctx, 'sessao', 'bloqueio', `Login bloqueado temporariamente para ${email}`, 0, email);
      voltar('bloqueado', email);
    }

    const usuario = await bd.chamarUm('sp_login_buscar', [email]);
    const guardada = String((usuario && usuario.senha) || '');
    const hashValido = /^\$2[aby]\$/.test(guardada);
    // senhas legadas (texto puro no banco antigo) ainda entram, e são convertidas em seguida
    let confere = false;
    if (usuario) {
      confere = hashValido
        ? await bcrypt.compare(senha, guardada)
        : guardada.length === senha.length && crypto.timingSafeEqual(Buffer.from(guardada), Buffer.from(senha));
    } else {
      await bcrypt.compare(senha, HASH_FALSO); // gasta o mesmo tempo para não revelar se o e-mail existe
    }

    if (!confere) {
      await bd.chamar('sp_login_registrar', [email, ip, 0]);
      const restantes = Math.max(0, max - Number(falhas.falhas_email) - 1);
      voltar(restantes > 0 && restantes <= 2 ? 'restam' + restantes : 'credenciais', email);
    }
    if (usuario.ativo !== 'ATIVO') {
      await bd.chamar('sp_login_registrar', [email, ip, 0]);
      voltar('inativo', email);
    }

    const novoHash = hashValido ? '' : await bcrypt.hash(senha, 10);
    await bd.chamar('sp_login_sucesso', [Number(usuario.id), novoHash]);
    await bd.chamar('sp_login_registrar', [email, ip, 1]);

    // sessão nova antes de gravar a identidade (evita fixação de sessão)
    const agora = Math.floor(Date.now() / 1000);
    ctx.sessao = {
      usuario_id: Number(usuario.id), usuario_nome: usuario.nome, usuario_nivel: usuario.nivel, usuario_cargo: usuario.cargo,
      id_empresa: 1, csrf: crypto.randomBytes(32).toString('hex'), ultimo_uso: agora, conferido_em: agora,
    };
    await sessao.registrarAtividade(ctx, 'sessao', 'login', 'Entrou no sistema');
    // cozinheiro cai direto na tela da cozinha; os demais, na Visão Geral
    throw new Redirecionar('/painel/' + (usuario.cargo === 'Cozinheiro' ? 'cozinha' : 'dashboard'));
  } catch (err) {
    if (err instanceof Redirecionar) throw err;
    console.error('[xtec] login:', err.message);
    voltar('banco', email);
  }
}

async function logout(ctx) {
  if (ctx.sessao.usuario_id) await sessao.registrarAtividade(ctx, 'sessao', 'logout', 'Saiu do sistema');
  ctx.encerrarSessao = true;
  throw new Redirecionar('/frontend/html/login.html?ok=saiu');
}

async function cadastro(ctx) {
  const pagina = '/frontend/html/cadastro.html';
  if (ctx.metodo !== 'POST') throw new Redirecionar(pagina);
  const codigo = String(ctx.corpo.codigo_equipe || '').trim().toUpperCase();
  const nome = String(ctx.corpo.nome || '').trim();
  const email = String(ctx.corpo.email || '').trim().toLowerCase();
  const senha = String(ctx.corpo.senha || '');
  const confirmar = String(ctx.corpo.confirmar_senha || '');
  const voltar = (mensagem) => { throw new Redirecionar(pagina + consulta({ erro: mensagem, codigo, nome, email })); };

  if (!codigo || !nome || !email || !senha) voltar('Preencha todos os campos do formulário.');
  if (!EMAIL.test(email)) voltar('Informe um e-mail válido.');
  if ([...senha].length < 8 || !/\d/.test(senha) || !/\p{L}/u.test(senha)) voltar('A senha precisa ter ao menos 8 caracteres, com letras e números.');
  if (senha !== confirmar) voltar('A senha e a confirmação não coincidem.');

  try {
    const novo = await bd.chamarUm('sp_convite_usar', [codigo, nome, email, await bcrypt.hash(senha, 10)]);
    await sessao.registrarAtividade(ctx, 'equipe', 'cadastro', `Nova conta criada com o convite ${codigo} (${novo.cargo})`, Number(novo.id), nome);
    throw new Redirecionar('/frontend/html/login.html' + consulta({ ok: 'cadastro', email }));
  } catch (err) {
    if (err instanceof Redirecionar) throw err;
    if (err instanceof bd.ErroNegocio) voltar(err.message);
    console.error('[xtec] cadastro:', err.message);
    voltar('Não foi possível concluir o cadastro agora. Tente novamente em instantes.');
  }
}

module.exports = { login, logout, cadastro };
