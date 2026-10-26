<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

/**
 * Regras de PRODUTO compartilhadas pelo Cardápio e pelo Estoque.
 * (Os dois módulos trabalham na mesma tabela; o TIPO da categoria decide
 * se o item aparece no cardápio ou é apenas um insumo de estoque.)
 */
final class Produto
{
    private const TIPOS = [
        'NOME' => 's',
        'DESCRICAO' => 's',
        'PRECO_UNITARIO' => 'd',
        'CATEGORIA_ID' => 'i',
        'STATUS' => 's',
        'CODIGO_BARRAS' => 's',
        'NCM' => 's',
        'UNIDADE_MEDIDA' => 's',
        'ESTOQUE' => 'i',
        'ESTOQUE_MINIMO' => 'i',
        'DATA_VALIDADE' => 's',
    ];

    /**
     * Lê e valida os campos enviados.
     *
     * @param bool $cardapio true = formulário do cardápio (sem campos de estoque)
     * @return array<string, mixed> coluna => valor
     */
    public static function lerCampos(bool $cardapio, ?int $idAtual): array
    {
        $campos = [];
        $campos['NOME'] = Api::texto('nome', 200, true, 'nome');
        $campos['DESCRICAO'] = Api::texto('descricao', 255, true, 'descrição');
        $campos['PRECO_UNITARIO'] = Api::decimal(
            'preco',
            $cardapio ? 0.01 : 0.0,
            99999999.99,
            true,
            'preço',
        );
        $campos['CATEGORIA_ID'] = Api::inteiro('categoria_id', true, 1, null, 'categoria');
        $campos['STATUS'] = Api::opcao('status', ['ATIVO', 'INATIVO'], 'ATIVO', 'situação');

        $codigo = Api::digitos(Api::texto('codigo_barras', 20, false, 'código de barras'));
        if (strlen($codigo) > 14) {
            Api::falhar('O código de barras deve ter até 14 números.');
        }
        $campos['CODIGO_BARRAS'] = $codigo === '' ? null : $codigo;

        $ncm = Api::digitos(Api::texto('ncm', 12, false, 'NCM'));
        if ($ncm !== '' && strlen($ncm) !== 8) {
            Api::falhar('O NCM deve ter 8 números.');
        }
        $campos['NCM'] = $ncm === '' ? null : $ncm;

        if (!$cardapio) {
            $unidade = mb_strtoupper(Api::texto('unidade', 6, false, 'unidade'));
            $campos['UNIDADE_MEDIDA'] = $unidade === '' ? 'UN' : $unidade;
            $campos['ESTOQUE_MINIMO'] = Api::inteiro('estoque_minimo', false, 0, 9999999, 'estoque mínimo') ?? 0;
            $campos['DATA_VALIDADE'] = Api::data('validade', false, 'validade');
        }

        self::validarCategoria((int) $campos['CATEGORIA_ID'], $cardapio);
        self::validarUnicos($campos, $idAtual);

        return $campos;
    }

    private static function validarCategoria(int $categoriaId, bool $cardapio): void
    {
        $categoria = Api::linha('SELECT TIPO FROM PRODUTO_CATEGORIA WHERE ID=?', 'i', [$categoriaId]);

        if ($categoria === null) {
            Api::falhar('Categoria não encontrada.');
        }
        if ($cardapio && $categoria['TIPO'] !== 'CARDAPIO') {
            Api::falhar('Escolha uma categoria do cardápio.');
        }
    }

    private static function validarUnicos(array $campos, ?int $idAtual): void
    {
        $ignorar = $idAtual ?? 0;

        if (Api::linha('SELECT ID FROM PRODUTO WHERE NOME=? AND ID<>?', 'si', [$campos['NOME'], $ignorar])) {
            Api::falhar('Já existe um item com esse nome.', 409);
        }

        if (
            $campos['CODIGO_BARRAS'] !== null &&
            Api::linha('SELECT ID FROM PRODUTO WHERE CODIGO_BARRAS=? AND ID<>?', 'si', [
                $campos['CODIGO_BARRAS'],
                $ignorar,
            ])
        ) {
            Api::falhar('Esse código de barras já está cadastrado em outro item.', 409);
        }
    }

    public static function inserir(array $campos): int
    {
        $colunas = array_keys($campos);
        $tipos = '';
        foreach ($colunas as $coluna) {
            $tipos .= self::TIPOS[$coluna];
        }

        Api::comando(
            'INSERT INTO PRODUTO (' . implode(', ', $colunas) . ') VALUES (' . Api::marcadores(count($colunas)) . ')',
            $tipos,
            array_values($campos),
        );

        return Api::idInserido();
    }

    public static function atualizar(int $id, array $campos): void
    {
        $tipos = '';
        $atribuicoes = [];
        foreach (array_keys($campos) as $coluna) {
            $tipos .= self::TIPOS[$coluna];
            $atribuicoes[] = "{$coluna}=?";
        }

        $valores = array_values($campos);
        $valores[] = $id;

        Api::comando('UPDATE PRODUTO SET ' . implode(', ', $atribuicoes) . ' WHERE ID=?', $tipos . 'i', $valores);
    }

    public static function existe(int $id): ?array
    {
        return Api::linha('SELECT ID, NOME, STATUS, ESTOQUE FROM PRODUTO WHERE ID=?', 'i', [$id]);
    }

    public static function exigir(int $id): array
    {
        return self::existe($id) ?? Api::falhar('Item não encontrado.', 404);
    }

    /** Quantidade de registros (vendas, compras, movimentos) que usam o produto. */
    public static function usos(int $id): int
    {
        $total = 0;
        foreach (
            [
                'VENDA_ITEM',
                'COMPRA_ITEM',
                'MOVIMENTACAO_ESTOQUE',
                'FORNECEDOR_PRODUTO',
            ] as $tabela
        ) {
            $total += (int) Api::valor("SELECT COUNT(*) FROM {$tabela} WHERE PRODUTO_ID=?", 'i', [$id]);
        }

        return $total;
    }

    /**
     * Exclui o item; se ele já tem histórico (vendas, compras ou movimentos)
     * apenas o inativa para não quebrar os relatórios.
     *
     * @return bool true quando foi inativado em vez de excluído
     */
    public static function excluirOuInativar(int $id): bool
    {
        self::exigir($id);

        if (self::usos($id) > 0) {
            Api::comando("UPDATE PRODUTO SET STATUS='INATIVO' WHERE ID=?", 'i', [$id]);
            return true;
        }

        Api::comando('DELETE FROM PRODUTO WHERE ID=?', 'i', [$id]);
        return false;
    }
}
