<?php
declare(strict_types=1);

/**
 * Configuração central do sistema.
 *
 * Os valores abaixo são os padrões do WampServer (MariaDB na porta 3307).
 * Para mudar sem editar este arquivo, crie backend/config/config.local.php
 * (ignorado pelo Git) devolvendo só as chaves que mudam, por exemplo:
 *
 *   <?php return ['db_porta' => 3306, 'db_senha' => 'minha-senha'];
 *
 * Variáveis de ambiente XTEC_DB_HOST, XTEC_DB_PORTA, XTEC_DB_NOME,
 * XTEC_DB_USUARIO e XTEC_DB_SENHA também são aceitas (úteis em servidor).
 */

function config(string $chave): mixed
{
    static $cfg = null;

    if ($cfg === null) {
        $cfg = [
            'app_nome' => 'XTEC',
            'app_versao' => '2.0.0',
            'db_host' => '127.0.0.1',
            'db_porta' => 3307,
            'db_nome' => 'tcc',
            'db_usuario' => 'root',
            'db_senha' => '',
            'fuso' => 'America/Sao_Paulo',
            'sessao_minutos' => 120,      // sessão expira após esse tempo sem uso
            'login_max_falhas' => 5,      // tentativas erradas antes do bloqueio (15 minutos)
        ];

        $local = __DIR__ . '/config.local.php';
        if (is_file($local)) {
            $extra = require $local;
            if (is_array($extra)) {
                $cfg = array_merge($cfg, $extra);
            }
        }

        foreach (['db_host', 'db_porta', 'db_nome', 'db_usuario', 'db_senha'] as $k) {
            $env = getenv('XTEC_' . strtoupper($k));
            if ($env !== false && $env !== '') {
                $cfg[$k] = $k === 'db_porta' ? (int) $env : $env;
            }
        }

        date_default_timezone_set((string) $cfg['fuso']);
    }

    return $cfg[$chave] ?? null;
}

// O fuso vale para todo o PHP assim que a configuração é carregada.
config('fuso');
