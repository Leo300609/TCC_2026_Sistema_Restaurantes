<?php
declare(strict_types=1);

/**
 * Base compartilhada pelas APIs de CRUD do dashboard.
 *
 * Cada API (clientes.php, cardapio.php, estoque.php, pedidos.php, ...) faz:
 *
 *     require_once __DIR__ . '/_comum.php';
 *     Api::iniciar(['listar'], ['criar', 'atualizar', 'excluir']);
 *     Api::executar(function () { ... Api::responder([...]); });
 *
 * - ações de leitura chegam por GET (?acao=listar);
 * - ações de escrita chegam por POST (JSON ou formulário) com o cabeçalho
 *   X-CSRF-Token igual ao token guardado na sessão;
 * - o usuário precisa estar logado e todo acesso ao banco usa prepared statements.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

final class Api
{
    public static mysqli $con;
    public static array $corpo = [];
    public static string $acao = '';
    public static string $metodo = 'GET';

    // ------------------------------------------------------------------
    // Resposta
    // ------------------------------------------------------------------

    public static function responder(array $dados, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        exit();
    }

    public static function falhar(string $mensagem, int $status = 422): never
    {
        self::responder(['error' => $mensagem], $status);
    }

    // ------------------------------------------------------------------
    // Inicialização: sessão, método, ação, CSRF e conexão
    // ------------------------------------------------------------------

    public static function iniciar(array $leitura, array $escrita): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            self::falhar('Sessão expirada. Entre novamente.', 401);
        }

        self::$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        self::$corpo = $_POST;

        if (self::$metodo === 'POST') {
            $json = json_decode(file_get_contents('php://input') ?: '', true);
            if (is_array($json)) {
                self::$corpo = $json;
            }
        }

        $acao = self::$metodo === 'GET' ? $_GET['acao'] ?? '' : self::$corpo['acao'] ?? '';
        if (!is_string($acao) || !in_array($acao, array_merge($leitura, $escrita), true)) {
            self::falhar('Ação não reconhecida.', 404);
        }
        self::$acao = $acao;

        $esperado = in_array($acao, $leitura, true) ? 'GET' : 'POST';
        if (self::$metodo !== $esperado) {
            self::falhar('Método não permitido para esta ação.', 405);
        }

        if (self::$metodo === 'POST') {
            $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $sessao = $_SESSION['csrf_token'] ?? '';
            if ($enviado === '' || $sessao === '' || !hash_equals($sessao, $enviado)) {
                self::falhar('Token de segurança inválido. Atualize a página e tente novamente.', 403);
            }
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            require_once __DIR__ . '/../conexao.php';
            $banco = new BancodeDados();
            $banco->conecta();
            self::$con = $banco->con;
            mysqli_set_charset(self::$con, 'utf8mb4');
        } catch (Throwable $erro) {
            error_log('API: falha ao conectar - ' . $erro->getMessage());
            self::falhar('Não foi possível conectar ao banco de dados.', 500);
        }
    }

    /** Executa a ação traduzindo erros do MySQL em mensagens amigáveis. */
    public static function executar(callable $acao): void
    {
        try {
            $acao();
            self::falhar('A ação não retornou resposta.', 500);
        } catch (mysqli_sql_exception $erro) {
            error_log('API ' . self::$acao . ': [' . $erro->getCode() . '] ' . $erro->getMessage());
            match ($erro->getCode()) {
                1062 => self::falhar('Já existe um registro com esses dados.', 409),
                1451 => self::falhar('Este registro está em uso e não pode ser removido.', 409),
                1452 => self::falhar('Um dos itens selecionados não existe mais.', 422),
                1146, 1054 => self::falhar(
                    'O banco de dados está desatualizado. Execute o arquivo backend/conexao/migracao_crud.sql.',
                    500,
                ),
                default => self::falhar('Não foi possível processar a solicitação.', 500),
            };
        } catch (Throwable $erro) {
            error_log('API ' . self::$acao . ': ' . $erro->getMessage());
            self::falhar('Não foi possível processar a solicitação.', 500);
        }
    }

    // ------------------------------------------------------------------
    // Permissões (mesmas regras usadas nas páginas)
    // ------------------------------------------------------------------

    public static function usuarioId(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    public static function ehAdmin(): bool
    {
        return ($_SESSION['usuario_nivel'] ?? '') === 'ADMIN';
    }

    public static function cargo(): string
    {
        return (string) ($_SESSION['usuario_cargo'] ?? '');
    }

    public static function ehGerente(): bool
    {
        return self::ehAdmin() || self::cargo() === 'Gerente';
    }

    public static function podeEstoque(): bool
    {
        return self::ehGerente() || self::cargo() === 'Cozinheiro';
    }

    public static function exigirGerente(): void
    {
        if (!self::ehGerente()) {
            self::falhar('Apenas administradores e gerentes podem fazer isso.', 403);
        }
    }

    public static function exigirEstoque(): void
    {
        if (!self::podeEstoque()) {
            self::falhar('Você não tem acesso ao estoque.', 403);
        }
    }

    // ------------------------------------------------------------------
    // Leitura e validação do que chegou do navegador
    // ------------------------------------------------------------------

    public static function texto(
        string $chave,
        int $max,
        bool $obrigatorio = true,
        ?string $rotulo = null,
    ): string {
        $rotulo ??= $chave;
        $valor = self::$corpo[$chave] ?? '';

        if (!is_scalar($valor)) {
            self::falhar("Preencha o campo {$rotulo} corretamente.");
        }

        $valor = trim((string) $valor);

        if ($valor === '' && $obrigatorio) {
            self::falhar("Preencha o campo {$rotulo}.");
        }
        if (self::tamanho($valor) > $max) {
            self::falhar("O campo {$rotulo} excede {$max} caracteres.");
        }

        return $valor;
    }

    public static function tamanho(string $valor): int
    {
        $total = preg_match_all('/./us', $valor);
        return $total === false ? strlen($valor) : $total;
    }

    public static function inteiro(
        string $chave,
        bool $obrigatorio = true,
        int $min = 1,
        ?int $max = null,
        ?string $rotulo = null,
    ): ?int {
        $rotulo ??= $chave;
        $bruto = self::$corpo[$chave] ?? null;

        if ($bruto === null || $bruto === '') {
            if ($obrigatorio) {
                self::falhar("Informe o campo {$rotulo}.");
            }
            return null;
        }

        $valor = filter_var($bruto, FILTER_VALIDATE_INT);
        if ($valor === false || $valor < $min || ($max !== null && $valor > $max)) {
            self::falhar("Valor inválido no campo {$rotulo}.");
        }

        return $valor;
    }

    public static function decimal(
        string $chave,
        float $min = 0.0,
        float $max = 99999999.99,
        bool $obrigatorio = true,
        ?string $rotulo = null,
    ): ?float {
        $rotulo ??= $chave;
        $bruto = self::$corpo[$chave] ?? null;

        if ($bruto === null || $bruto === '') {
            if ($obrigatorio) {
                self::falhar("Informe o campo {$rotulo}.");
            }
            return null;
        }

        if (is_string($bruto)) {
            $bruto = str_replace(',', '.', trim($bruto));
        }

        $valor = filter_var($bruto, FILTER_VALIDATE_FLOAT);
        if ($valor === false || $valor < $min || $valor > $max) {
            self::falhar("Valor inválido no campo {$rotulo}.");
        }

        return round((float) $valor, 2);
    }

    public static function opcao(
        string $chave,
        array $validos,
        ?string $padrao = null,
        ?string $rotulo = null,
    ): string {
        $valor = self::$corpo[$chave] ?? $padrao;

        if (!is_string($valor) || !in_array($valor, $validos, true)) {
            self::falhar('Valor inválido no campo ' . ($rotulo ?? $chave) . '.');
        }

        return $valor;
    }

    public static function booleano(string $chave, bool $padrao = false): bool
    {
        if (!array_key_exists($chave, self::$corpo)) {
            return $padrao;
        }

        return filter_var(self::$corpo[$chave], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $padrao;
    }

    public static function data(string $chave, bool $obrigatorio = true, ?string $rotulo = null): ?string
    {
        $rotulo ??= $chave;
        $valor = trim((string) (self::$corpo[$chave] ?? ''));

        if ($valor === '') {
            if ($obrigatorio) {
                self::falhar("Informe a data do campo {$rotulo}.");
            }
            return null;
        }

        if (
            !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) ||
            !checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])
        ) {
            self::falhar("Data inválida no campo {$rotulo}.");
        }

        return $valor;
    }

    public static function hora(string $chave, ?string $rotulo = null): string
    {
        $valor = trim((string) (self::$corpo[$chave] ?? ''));

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $valor, $partes)) {
            self::falhar('Horário inválido no campo ' . ($rotulo ?? $chave) . '.');
        }

        return $partes[1] . ':' . $partes[2] . ':00';
    }

    public static function digitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }

    /** Monta o padrão de um LIKE escapando % e _ digitados pelo usuário. */
    public static function like(string $busca): string
    {
        return '%' . addcslashes($busca, '%_\\') . '%';
    }

    /** Lê ?pagina= e ?por_pagina= e devolve [pagina, porPagina, deslocamento]. */
    public static function paginacao(int $padrao = 15, int $maximo = 100): array
    {
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina = (int) ($_GET['por_pagina'] ?? $padrao);
        $porPagina = min($maximo, max(1, $porPagina));

        return [$pagina, $porPagina, ($pagina - 1) * $porPagina];
    }

    public static function parametro(string $chave, string $padrao = ''): string
    {
        $valor = $_GET[$chave] ?? $padrao;
        return is_string($valor) ? trim($valor) : $padrao;
    }

    // ------------------------------------------------------------------
    // Banco de dados
    // ------------------------------------------------------------------

    public static function consulta(string $sql, string $tipos = '', array $valores = []): mysqli_stmt
    {
        $comando = self::$con->prepare($sql);

        if ($tipos !== '') {
            $comando->bind_param($tipos, ...$valores);
        }

        $comando->execute();
        return $comando;
    }

    /** @return array<int, array<string, mixed>> */
    public static function linhas(string $sql, string $tipos = '', array $valores = []): array
    {
        $comando = self::consulta($sql, $tipos, $valores);
        $linhas = $comando->get_result()->fetch_all(MYSQLI_ASSOC);
        $comando->close();

        return $linhas;
    }

    public static function linha(string $sql, string $tipos = '', array $valores = []): ?array
    {
        return self::linhas($sql, $tipos, $valores)[0] ?? null;
    }

    public static function valor(string $sql, string $tipos = '', array $valores = []): mixed
    {
        $linha = self::linha($sql, $tipos, $valores);
        return $linha === null ? null : reset($linha);
    }

    /** Executa INSERT/UPDATE/DELETE e devolve as linhas afetadas. */
    public static function comando(string $sql, string $tipos = '', array $valores = []): int
    {
        $comando = self::consulta($sql, $tipos, $valores);
        $afetadas = $comando->affected_rows;
        $comando->close();

        return (int) $afetadas;
    }

    public static function idInserido(): int
    {
        return (int) self::$con->insert_id;
    }

    public static function transacao(callable $trabalho): mixed
    {
        self::$con->begin_transaction();

        try {
            $resultado = $trabalho();
            self::$con->commit();
            return $resultado;
        } catch (Throwable $erro) {
            self::$con->rollback();
            throw $erro;
        }
    }

    /** Gera "?, ?, ?" para cláusulas IN (...). */
    public static function marcadores(int $quantidade): string
    {
        return implode(', ', array_fill(0, max(1, $quantidade), '?'));
    }

    // ------------------------------------------------------------------
    // Configurações (tabela CONFIGURACAO; USUARIO_ID 0 = valor global)
    // ------------------------------------------------------------------

    public static function configuracao(string $chave, string $padrao, int $usuario = 0): string
    {
        $valor = self::valor(
            'SELECT VALOR FROM CONFIGURACAO WHERE USUARIO_ID=? AND CHAVE=?',
            'is',
            [$usuario, $chave],
        );

        return $valor === null ? $padrao : (string) $valor;
    }

    public static function salvarConfiguracao(int $usuario, string $chave, string $valor): void
    {
        self::comando(
            'INSERT INTO CONFIGURACAO (USUARIO_ID, CHAVE, VALOR) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE VALOR=VALUES(VALOR)',
            'iss',
            [$usuario, $chave, $valor],
        );
    }

    public const PAGAMENTOS = ['PIX', 'CREDITO', 'DEBITO', 'DINHEIRO', 'BOLETO'];

    /** Configurações de operação lidas por Pedidos e Configurações. */
    public static function operacao(): array
    {
        $pagamentos = array_values(
            array_filter(
                explode(',', self::configuracao('pagamentos', 'PIX,CREDITO,DEBITO,DINHEIRO')),
                static fn(string $forma): bool => in_array($forma, self::PAGAMENTOS, true),
            ),
        );

        return [
            'aceita_delivery' => self::configuracao('aceita_delivery', '1') === '1',
            'tempo_preparo' => (int) self::configuracao('tempo_preparo', '20'),
            'pagamentos' => $pagamentos === [] ? ['DINHEIRO'] : $pagamentos,
        ];
    }
}
