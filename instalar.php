<?php
declare(strict_types=1);

/**
 * Instalador do banco de dados.
 *
 * Abra http://localhost/TCC_2026_Sistema_Restaurantes/instalar.php no navegador
 * do próprio computador. Ele cria o banco "tcc" executando os scripts da pasta
 * database/ na ordem correta e, se você quiser, carrega dados de demonstração.
 * Depois de instalar, o arquivo backend/config/instalado.lock bloqueia uma
 * nova instalação (apague-o para reinstalar).
 */

require_once __DIR__ . '/backend/config/config.php';

ini_set('display_errors', '0');
set_time_limit(300);
session_name('XTECINSTALADOR');
session_start();

$trava = __DIR__ . '/backend/config/instalado.lock';
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$scripts = [
    'bancofeitonamoraltmjamovcs.sql' => 'Estrutura base e cadastros iniciais',
    'migracao_dashboard.sql' => 'Fluxo de caixa',
    'migracao_crud.sql' => 'Pedidos, empresa e configurações',
    'migracao_modulos.sql' => 'Mesas, ficha técnica, auditoria e segurança',
    'procedures_crud.sql' => 'Regras de negócio dos cadastros',
    'procedures_modulos.sql' => 'Regras de negócio dos módulos',
];
$demo = ['dados_demonstracao.sql' => 'Dados de demonstração (60 dias de vendas)'];

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(16));
}
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Remove o comentário "-- ..." do fim da linha, respeitando textos entre aspas. */
function sem_comentario(string $linha): string
{
    $aspa = null;
    $tam = strlen($linha);
    for ($i = 0; $i < $tam; $i++) {
        $c = $linha[$i];
        if ($aspa !== null) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === $aspa) {
                $aspa = null;
            }
        } elseif ($c === "'" || $c === '"' || $c === '`') {
            $aspa = $c;
        } elseif ($c === '-' && ($linha[$i + 1] ?? '') === '-' && in_array($linha[$i + 2] ?? ' ', [' ', "\t", "\r", "\n", ''], true)) {
            return rtrim(substr($linha, 0, $i));
        }
    }
    return rtrim($linha);
}

/** Executa um arquivo .sql com suporte a DELIMITER (como o cliente mysql). */
function executar_script(mysqli $con, string $arquivo): int
{
    $delimitador = ';';
    $buffer = '';
    $comandos = 0;
    foreach (file($arquivo) ?: [] as $linha) {
        $limpa = trim($linha);
        if ($buffer === '' && ($limpa === '' || str_starts_with($limpa, '--'))) {
            continue;
        }
        if (preg_match('/^DELIMITER\s+(\S+)/i', $limpa, $m)) {
            $delimitador = $m[1];
            continue;
        }
        $linha = sem_comentario($linha);
        if (trim($linha) === '') {
            continue;
        }
        $buffer .= $linha . "\n";
        if (str_ends_with(rtrim($buffer), $delimitador)) {
            $comando = substr(rtrim($buffer), 0, -strlen($delimitador));
            $buffer = '';
            if (trim($comando) === '') {
                continue;
            }
            if (!$con->multi_query($comando)) {
                throw new RuntimeException(basename($arquivo) . ': ' . $con->error . ' — em: ' . mb_substr(trim($comando), 0, 140));
            }
            do {
                if ($r = $con->store_result()) {
                    $r->free();
                }
            } while ($con->more_results() && $con->next_result());
            if ($con->errno) {
                throw new RuntimeException(basename($arquivo) . ': ' . $con->error);
            }
            $comandos++;
        }
    }
    return $comandos;
}

// ---------- diagnóstico ----------
$checagens = [
    ['PHP 8.1 ou mais novo', version_compare(PHP_VERSION, '8.1.0', '>='), 'Versão atual: ' . PHP_VERSION],
    ['Extensão mysqli', extension_loaded('mysqli'), 'Necessária para conectar ao MySQL/MariaDB'],
    ['Extensão mbstring', extension_loaded('mbstring'), 'Textos com acentos'],
    ['Bibliotecas do Composer (vendor/)', is_file(__DIR__ . '/vendor/autoload.php'), 'Usadas nas exportações em PDF, Word e Excel'],
];
$con = null;
$erroConexao = '';
$bancoExiste = false;
mysqli_report(MYSQLI_REPORT_OFF);
try {
    $con = @new mysqli((string) config('db_host'), (string) config('db_usuario'), (string) config('db_senha'), '', (int) config('db_porta'));
    if ($con->connect_errno) {
        $erroConexao = $con->connect_error;
        $con = null;
    } else {
        $con->set_charset('utf8mb4');
        $res = $con->query("SHOW DATABASES LIKE '" . $con->real_escape_string((string) config('db_nome')) . "'");
        $bancoExiste = $res && $res->num_rows > 0;
    }
} catch (Throwable $e) {
    $erroConexao = $e->getMessage();
}
$checagens[] = ['Conexão com o MySQL/MariaDB', $con !== null, $con ? 'Servidor ' . $con->server_info . ' em ' . config('db_host') . ':' . config('db_porta') : 'Erro: ' . $erroConexao];
$tudoOk = !in_array(false, array_column($checagens, 1), true);

// ---------- instalação ----------
$resultado = null;
$erro = '';
if ($local && !is_file($trava) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['token'], (string) ($_POST['token'] ?? ''))) {
        $erro = 'A página expirou. Recarregue e tente de novo.';
    } elseif (!$tudoOk) {
        $erro = 'Corrija os itens marcados em vermelho antes de instalar.';
    } elseif ($bancoExiste && !isset($_POST['substituir'])) {
        $erro = 'O banco já existe. Marque a opção para substituí-lo (os dados atuais serão apagados).';
    } else {
        try {
            $inicio = microtime(true);
            $nome = (string) config('db_nome');
            $con->query('DROP DATABASE IF EXISTS `' . $con->real_escape_string($nome) . '`');
            $con->query("SET time_zone = '" . date('P') . "'");
            $lista = $scripts + (isset($_POST['demo']) ? $demo : []);
            $resultado = [];
            foreach ($lista as $arquivo => $descricao) {
                $resultado[] = [$descricao, executar_script($con, __DIR__ . '/database/' . $arquivo)];
            }
            file_put_contents($trava, 'Instalado em ' . date('c') . PHP_EOL);
            $tempo = round(microtime(true) - $inicio, 1);
        } catch (Throwable $e) {
            $erro = 'A instalação parou: ' . $e->getMessage();
            $resultado = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instalação · XTEC</title>
  <link rel="icon" type="image/svg+xml" href="frontend/assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700..800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root { --fundo: #f2f3f1; --chapa: #fff; --fio: #e1e4df; --tinta: #1c1f24; --tinta-3: #6b7380; --brasa: #d7262e; --salvia: #237047; --salvia-fundo: #e5f3ea; --erro-fundo: #fdeceb; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { min-height: 100vh; background: var(--fundo); color: var(--tinta); font: 15px/1.55 "IBM Plex Sans", system-ui, sans-serif; padding: 48px 16px; }
    main { max-width: 680px; margin: 0 auto; }
    .marca { display: flex; align-items: center; gap: 12px; margin-bottom: 26px; }
    .marca span { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: var(--brasa); color: #fff; font-size: 18px; }
    h1 { font: 800 30px/1.1 "Bricolage Grotesque", sans-serif; letter-spacing: -0.02em; }
    h2 { font: 700 18px/1.2 "Bricolage Grotesque", sans-serif; margin-bottom: 12px; }
    .cartao { background: var(--chapa); border: 1px solid var(--fio); border-radius: 14px; padding: 22px 24px; margin-bottom: 16px; }
    ul { list-style: none; }
    li { display: flex; gap: 12px; padding: 9px 0; border-top: 1px solid var(--fio); }
    li:first-child { border-top: 0; }
    li i { margin-top: 4px; }
    li small { display: block; color: var(--tinta-3); }
    .ok { color: var(--salvia); } .falha { color: var(--brasa); }
    .aviso { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
    .aviso.erro { background: var(--erro-fundo); color: #b81f26; } .aviso.sucesso { background: var(--salvia-fundo); color: var(--salvia); }
    label.opcao { display: flex; gap: 10px; align-items: flex-start; padding: 8px 0; cursor: pointer; }
    label.opcao input { margin-top: 5px; accent-color: var(--brasa); width: 16px; height: 16px; }
    button, .botao { display: inline-flex; align-items: center; gap: 10px; height: 48px; padding: 0 22px; border: 0; border-radius: 11px; background: var(--brasa); color: #fff; font: 700 15px "IBM Plex Sans", sans-serif; cursor: pointer; text-decoration: none; margin-top: 10px; }
    button:disabled { opacity: .5; cursor: not-allowed; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    td { padding: 7px 0; border-top: 1px solid var(--fio); } td:last-child { text-align: right; color: var(--tinta-3); }
    code { background: var(--fundo); padding: 1px 6px; border-radius: 5px; font-size: 13.5px; }
    p + p { margin-top: 8px; }
  </style>
</head>

<body>
  <main>
    <div class="marca"><span><i class="fa-solid fa-utensils"></i></span><h1>Instalação do XTEC</h1></div>

    <?php if (!$local): ?>
      <div class="aviso erro">Por segurança, o instalador só funciona no próprio computador do servidor (localhost).</div>
    <?php elseif ($resultado !== null): ?>
      <div class="aviso sucesso"><i class="fa-solid fa-circle-check"></i> Banco instalado em <?= $h($tempo) ?> s. O instalador foi travado para não apagar os dados por engano.</div>
      <section class="cartao">
        <h2>O que foi executado</h2>
        <table>
          <?php foreach ($resultado as [$descricao, $qtd]): ?><tr><td><?= $h($descricao) ?></td><td><?= (int) $qtd ?> comandos</td></tr><?php endforeach; ?>
        </table>
      </section>
      <section class="cartao">
        <h2>Contas de acesso</h2>
        <table>
          <tr><td><strong>admin@tcc.com</strong> · Administrador</td><td>Admin@2026</td></tr>
          <tr><td><strong>func3@tcc.com</strong> · Gerente</td><td>Equipe@2026</td></tr>
          <tr><td><strong>func1@tcc.com</strong> · Atendente / Caixa</td><td>Equipe@2026</td></tr>
          <tr><td><strong>func4@tcc.com</strong> · Cozinheiro</td><td>Equipe@2026</td></tr>
          <tr><td><strong>func5@tcc.com</strong> · Entregador</td><td>Equipe@2026</td></tr>
        </table>
        <p style="margin-top:12px;color:var(--tinta-3)">Troque as senhas em Configurações antes de usar o sistema de verdade.</p>
        <a class="botao" href="frontend/html/login.html"><i class="fa-solid fa-right-to-bracket"></i> Ir para o login</a>
      </section>
    <?php elseif (is_file($trava)): ?>
      <section class="cartao">
        <h2>O sistema já está instalado</h2>
        <p>Para evitar que os dados sejam apagados por engano, uma nova instalação está bloqueada.</p>
        <p>Para reinstalar, apague o arquivo <code>backend/config/instalado.lock</code> e recarregue esta página.</p>
        <a class="botao" href="frontend/html/login.html"><i class="fa-solid fa-right-to-bracket"></i> Ir para o login</a>
      </section>
    <?php else: ?>
      <?php if ($erro): ?><div class="aviso erro"><i class="fa-solid fa-triangle-exclamation"></i> <?= $h($erro) ?></div><?php endif; ?>
      <section class="cartao">
        <h2>1. Verificação do ambiente</h2>
        <ul>
          <?php foreach ($checagens as [$nome, $ok, $detalhe]): ?>
            <li><i class="fa-solid <?= $ok ? 'fa-circle-check ok' : 'fa-circle-xmark falha' ?>"></i><span><?= $h($nome) ?><small><?= $h($detalhe) ?></small></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$con): ?>
          <p style="margin-top:12px">Ligue o WampServer (ícone verde) e confira a porta do MySQL/MariaDB. Os dados de acesso ficam em <code>backend/config/config.php</code>; para mudar sem editar o arquivo, crie <code>backend/config/config.local.php</code>.</p>
        <?php endif; ?>
      </section>
      <form class="cartao" method="post">
        <h2>2. Criar o banco “<?= $h(config('db_nome')) ?>”</h2>
        <input type="hidden" name="token" value="<?= $h($_SESSION['token']) ?>">
        <label class="opcao"><input type="checkbox" name="demo" value="1" checked><span>Carregar dados de demonstração<small style="display:block;color:var(--tinta-3)">60 dias de vendas, pedidos em andamento, mesas ocupadas, compras e despesas. Ideal para apresentar o sistema.</small></span></label>
        <?php if ($bancoExiste): ?>
          <label class="opcao"><input type="checkbox" name="substituir" value="1"><span><strong style="color:var(--brasa)">Substituir o banco existente</strong><small style="display:block;color:var(--tinta-3)">O banco “<?= $h(config('db_nome')) ?>” já existe e será apagado por completo.</small></span></label>
        <?php endif; ?>
        <button type="submit"<?= $tudoOk ? '' : ' disabled' ?>><i class="fa-solid fa-database"></i> Instalar agora</button>
      </form>
    <?php endif; ?>
  </main>
</body>

</html>
