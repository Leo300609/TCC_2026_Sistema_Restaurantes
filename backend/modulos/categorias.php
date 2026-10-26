<?php
declare(strict_types=1);

/**
 * Categorias de produto (CARDAPIO ou INSUMO), usadas nas telas de Cardápio e Estoque.
 * Regras no banco: sp_categoria_listar / _obter / _salvar / _excluir.
 */

/** Trata o POST das categorias (acao = cat_salvar | cat_excluir). Só gerência. */
function tratar_categorias(string $tipo, bool $gerente): void
{
    if (!in_array(entrada('acao'), ['cat_salvar', 'cat_excluir'], true)) {
        return;
    }

    tratar_post(function () use ($tipo, $gerente) {
        if (!$gerente) {
            throw new ErroNegocio('Somente administradores e gerentes podem gerenciar categorias.');
        }
        $id = entrada_int('id');
        $volta = url(['cat' => null, 'cat_novo' => null, 'cat_excluir' => null]);

        if (entrada('acao') === 'cat_salvar') {
            chamar('sp_categoria_salvar', [$id, entrada('nome'), entrada('descricao'), entrada('status', 'ATIVO'), $tipo]);
            return [$id ? 'Categoria atualizada.' : 'Categoria criada.', $volta];
        }

        chamar('sp_categoria_excluir', [$id]);
        return ['Categoria excluída.', $volta];
    }, url());
}

/** Dados para a tela: lista, categoria em edição e categoria a excluir. */
function dados_categorias(string $tipo): array
{
    $lista = ler(fn() => chamar('sp_categoria_listar', [$tipo]));
    $editId = entrada_int('cat');
    $excId = entrada_int('cat_excluir');

    $achar = static function (int $id) use ($lista) {
        foreach ($lista as $c) {
            if ((int) $c['id'] === $id) {
                return $c;
            }
        }
        return null;
    };

    return [
        'lista' => $lista,
        'editar' => $editId ? $achar($editId) : null,
        'novo' => isset($_GET['cat_novo']),
        'excluir' => $excId ? $achar($excId) : null,
    ];
}
