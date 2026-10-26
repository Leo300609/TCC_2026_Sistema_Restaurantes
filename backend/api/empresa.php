<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

/**
 * Empresa: dados do estabelecimento, horário de funcionamento e equipe.
 * Ver: qualquer usuário logado. Alterar: administrador ou gerente.
 */
Api::iniciar(['obter'], ['salvar', 'equipe_atualizar', 'equipe_remover']);

const CARGOS = ['Atendente / Caixa', 'Cozinheiro', 'Entregador', 'Gerente', 'FUNCIONARIO'];

function formatarCnpj(string $digitos): string
{
    return $digitos === ''
        ? ''
        : sprintf(
            '%s.%s.%s/%s-%s',
            substr($digitos, 0, 2),
            substr($digitos, 2, 3),
            substr($digitos, 5, 3),
            substr($digitos, 8, 4),
            substr($digitos, 12, 2),
        );
}

function exigirMembro(int $id): array
{
    $membro = Api::linha('SELECT ID, NOME, NIVEL, ATIVO FROM USUARIOS WHERE ID=?', 'i', [$id]);

    if ($membro === null) {
        Api::falhar('Funcionário não encontrado.', 404);
    }
    if ($id === Api::usuarioId()) {
        Api::falhar('Altere os seus próprios dados em Configurações.', 409);
    }
    if ($membro['NIVEL'] === 'ADMIN' && !Api::ehAdmin()) {
        Api::falhar('Somente administradores podem alterar outro administrador.', 403);
    }

    return $membro;
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'obter':
            $empresa = Api::linha(
                'SELECT NOME_FANTASIA AS nome_fantasia, RAZAO_SOCIAL AS razao_social, CNPJ AS cnpj, TELEFONE AS telefone,
                        EMAIL AS email_contato, ENDERECO AS endereco, ENDERECO_NUMERO AS endereco_numero,
                        ESTADO AS estado, CIDADE AS cidade
                 FROM EMPRESA WHERE ID=1',
            ) ?? [
                'nome_fantasia' => '', 'razao_social' => '', 'cnpj' => '', 'telefone' => '', 'email_contato' => '',
                'endereco' => '', 'endereco_numero' => '', 'estado' => 'SP', 'cidade' => '',
            ];

            $horarios = Api::linhas(
                "SELECT DIA_SEMANA AS dia, ABERTO AS aberto, TIME_FORMAT(ABRE, '%H:%i') AS abre, TIME_FORMAT(FECHA, '%H:%i') AS fecha
                 FROM EMPRESA_HORARIO ORDER BY DIA_SEMANA",
            );
            foreach ($horarios as &$horario) {
                $horario['dia'] = (int) $horario['dia'];
                $horario['aberto'] = (bool) $horario['aberto'];
            }
            unset($horario);

            $equipe = Api::linhas(
                "SELECT ID AS id, NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo,
                        ATIVO AS ativo, DATE_FORMAT(ULTIMO_LOGIN, '%d/%m %H:%i') AS ultimo_login,
                        COALESCE(ULTIMO_LOGIN >= NOW() - INTERVAL 15 MINUTE, 0) AS online
                 FROM USUARIOS
                 ORDER BY (ATIVO='INATIVO'), NOME",
            );
            foreach ($equipe as &$membro) {
                $membro['id'] = (int) $membro['id'];
                $membro['online'] = (bool) $membro['online'];
            }
            unset($membro);

            Api::responder([
                'empresa' => $empresa,
                'horarios' => $horarios,
                'equipe' => $equipe,
                'estados' => Api::linhas("SELECT SIGLA AS sigla, NOME AS nome FROM ESTADO WHERE STATUS='ATIVO' ORDER BY NOME"),
                'cargos' => CARGOS,
                'eu' => Api::usuarioId(),
                'sou_admin' => Api::ehAdmin(),
                'pode_editar' => Api::ehGerente(),
            ]);

        case 'salvar':
            Api::exigirGerente();

            $cnpj = Api::digitos(Api::texto('cnpj', 18, false, 'CNPJ'));
            if ($cnpj !== '' && strlen($cnpj) !== 14) {
                Api::falhar('O CNPJ deve ter 14 números.');
            }

            $telefone = Api::digitos(Api::texto('telefone', 20, false, 'telefone'));
            if ($telefone !== '' && !in_array(strlen($telefone), [10, 11], true)) {
                Api::falhar('Informe o telefone com DDD (10 ou 11 números).');
            }

            $email = Api::texto('email_contato', 255, false, 'e-mail de contato');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Api::falhar('Informe um e-mail de contato válido.');
            }

            $estado = strtoupper(Api::texto('estado', 2, true, 'estado'));
            if (Api::linha('SELECT ID FROM ESTADO WHERE SIGLA=?', 's', [$estado]) === null) {
                Api::falhar('Estado inválido.');
            }

            $nomeFantasia = Api::texto('nome_fantasia', 150, true, 'nome fantasia');
            $razaoSocial = Api::texto('razao_social', 200, false, 'razão social');
            $endereco = Api::texto('endereco', 255, false, 'endereço');
            $numero = Api::texto('endereco_numero', 20, false, 'número');
            $cidade = Api::texto('cidade', 200, false, 'cidade');

            $brutos = Api::$corpo['horarios'] ?? null;
            if (!is_array($brutos) || count($brutos) !== 7) {
                Api::falhar('Informe o horário dos 7 dias da semana.');
            }

            $horarios = [];
            foreach ($brutos as $bruto) {
                if (!is_array($bruto)) {
                    Api::falhar('Horário inválido.');
                }
                $dia = filter_var($bruto['dia'] ?? null, FILTER_VALIDATE_INT);
                if ($dia === false || $dia < 1 || $dia > 7 || isset($horarios[$dia])) {
                    Api::falhar('Dia da semana inválido.');
                }

                $aberto = filter_var($bruto['aberto'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $horarios[$dia] = ['aberto' => $aberto ? 1 : 0, 'abre' => '18:00:00', 'fecha' => '23:00:00'];

                if ($aberto) {
                    $copia = Api::$corpo;
                    Api::$corpo = ['abre' => $bruto['abre'] ?? '', 'fecha' => $bruto['fecha'] ?? ''];
                    $horarios[$dia]['abre'] = Api::hora('abre', 'horário de abertura');
                    $horarios[$dia]['fecha'] = Api::hora('fecha', 'horário de fechamento');
                    Api::$corpo = $copia;
                }
            }

            Api::transacao(static function () use ($nomeFantasia, $razaoSocial, $cnpj, $telefone, $email, $endereco, $numero, $estado, $cidade, $horarios): void {
                Api::comando(
                    'INSERT INTO EMPRESA (ID, NOME_FANTASIA, RAZAO_SOCIAL, CNPJ, TELEFONE, EMAIL, ENDERECO, ENDERECO_NUMERO, ESTADO, CIDADE)
                     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE NOME_FANTASIA=VALUES(NOME_FANTASIA), RAZAO_SOCIAL=VALUES(RAZAO_SOCIAL), CNPJ=VALUES(CNPJ),
                        TELEFONE=VALUES(TELEFONE), EMAIL=VALUES(EMAIL), ENDERECO=VALUES(ENDERECO),
                        ENDERECO_NUMERO=VALUES(ENDERECO_NUMERO), ESTADO=VALUES(ESTADO), CIDADE=VALUES(CIDADE)',
                    'sssssssss',
                    [$nomeFantasia, $razaoSocial, formatarCnpj($cnpj), $telefone, $email, $endereco, $numero, $estado, $cidade],
                );

                foreach ($horarios as $dia => $h) {
                    Api::comando(
                        'INSERT INTO EMPRESA_HORARIO (DIA_SEMANA, ABERTO, ABRE, FECHA) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE ABERTO=VALUES(ABERTO), ABRE=VALUES(ABRE), FECHA=VALUES(FECHA)',
                        'iiss',
                        [$dia, $h['aberto'], $h['abre'], $h['fecha']],
                    );
                }
            });

            Api::responder(['ok' => true, 'message' => 'Dados da empresa salvos.']);

        case 'equipe_atualizar':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            $membro = exigirMembro($id);

            $nome = Api::texto('nome', 255, true, 'nome');
            $cargo = Api::opcao('cargo', CARGOS, null, 'cargo');
            $ativo = Api::opcao('ativo', ['ATIVO', 'INATIVO'], null, 'situação');
            $nivel = Api::opcao('nivel', ['ADMIN', 'FUNCIONARIO'], $membro['NIVEL'], 'nível');

            if ($nivel !== $membro['NIVEL'] && !Api::ehAdmin()) {
                Api::falhar('Somente administradores podem mudar o nível de acesso.', 403);
            }

            Api::comando('UPDATE USUARIOS SET NOME=?, CARGO=?, NIVEL=?, ATIVO=? WHERE ID=?', 'ssssi', [$nome, $cargo, $nivel, $ativo, $id]);
            Api::responder(['ok' => true, 'message' => 'Funcionário atualizado.']);

        case 'equipe_remover':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            exigirMembro($id);

            $historico = 0;
            foreach ([['VENDA', 'FUNCIONARIO_ID'], ['COMPRA', 'FUNCIONARIO_ID'], ['MOVIMENTACAO_ESTOQUE', 'USUARIO_ID'], ['MOVIMENTACAO_CAIXA', 'USUARIO_ID']] as [$tabela, $coluna]) {
                $historico += (int) Api::valor("SELECT COUNT(*) FROM {$tabela} WHERE {$coluna}=?", 'i', [$id]);
            }

            if ($historico > 0) {
                Api::comando("UPDATE USUARIOS SET ATIVO='INATIVO' WHERE ID=?", 'i', [$id]);
                Api::responder([
                    'ok' => true,
                    'inativado' => true,
                    'message' => 'O funcionário tem registros no histórico, então o acesso foi desativado em vez de excluir a conta.',
                ]);
            }

            Api::comando('DELETE FROM USUARIOS WHERE ID=?', 'i', [$id]);
            Api::responder(['ok' => true, 'message' => 'Funcionário removido.']);
    }
});
