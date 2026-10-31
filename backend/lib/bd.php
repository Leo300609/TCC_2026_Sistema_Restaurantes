<?php
declare(strict_types=1);

/**
 * Acesso ao banco: toda regra de dados mora em procedures/functions
 * (database/procedures_crud.sql e database/procedures_modulos.sql).
 * O PHP só chama `CALL sp_...(?, ?)` com prepared statements e mostra o resultado.
 */

require_once __DIR__ . '/../config/conexao.php';

/** Erro de regra de negócio (SIGNAL SQLSTATE '45000' nas procedures): a mensagem pode ser exibida. */
final class ErroNegocio extends RuntimeException
{
}

function bd(): mysqli
{
    static $con = null;
    if ($con === null) {
        $banco = new BancodeDados();
        $banco->conecta();
        $con = $banco->con;
    }
    return $con;
}

/**
 * Executa `CALL procedimento(?, ?, ...)` e devolve as linhas do primeiro resultado.
 * Procedures que só gravam (sem SELECT final) devolvem [].
 *
 * @return list<array<string, mixed>>
 */
function chamar(string $procedimento, array $args = []): array
{
    $marcas = implode(', ', array_fill(0, count($args), '?'));

    try {
        $stmt = bd()->prepare("CALL {$procedimento}({$marcas})");
        if ($args) {
            $tipos = '';
            foreach ($args as $valor) {
                $tipos .= is_int($valor) || is_bool($valor) ? 'i' : (is_float($valor) ? 'd' : 's');
            }
            $valores = array_map(static fn($v) => is_bool($v) ? (int) $v : $v, $args);
            $stmt->bind_param($tipos, ...$valores);
        }
        $stmt->execute();

        $linhas = [];
        $resultado = $stmt->get_result();
        if ($resultado instanceof mysqli_result) {
            $linhas = $resultado->fetch_all(MYSQLI_ASSOC);
            $resultado->free();
        }
        // consome o "OK" final que todo CALL devolve
        while ($stmt->more_results() && $stmt->next_result()) {
            $extra = $stmt->get_result();
            if ($extra instanceof mysqli_result) {
                $extra->free();
            }
        }
        $stmt->close();
        return $linhas;
    } catch (mysqli_sql_exception $e) {
        if ($e->getSqlState() === '45000') {
            throw new ErroNegocio($e->getMessage(), 0, $e);
        }
        error_log('[tcc] ' . $procedimento . ': ' . $e->getMessage());
        throw new RuntimeException('Não foi possível concluir a operação. Confira se o banco foi instalado (instalar.php).', 0, $e);
    }
}

/** Primeira linha do resultado (ou null). */
function chamar_um(string $procedimento, array $args = []): ?array
{
    return chamar($procedimento, $args)[0] ?? null;
}

/** Primeira coluna da primeira linha (ou null). */
function chamar_valor(string $procedimento, array $args = []): mixed
{
    $linha = chamar_um($procedimento, $args);
    return $linha === null ? null : reset($linha);
}

/** Roda o bloco dentro de uma transação; qualquer exceção desfaz tudo. */
function transacao(callable $bloco): mixed
{
    $con = bd();
    $con->begin_transaction();
    try {
        $r = $bloco();
        $con->commit();
        return $r;
    } catch (Throwable $e) {
        $con->rollback();
        throw $e;
    }
}
