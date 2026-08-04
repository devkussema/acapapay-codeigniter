<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Commands;

use AcapaPay\CodeIgniter\AcapaPay;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Comando spark para testar a ligação M2M com o AcapaPay.
 *
 * @author Augusto Kussema
 */
class TestConnectionCommand extends BaseCommand
{
    protected $group       = 'AcapaPay';

    protected $name        = 'acapapay:test-connection';

    protected $description = 'Testa a ligação (OAuth2 e API) entre esta aplicação e o SSO central do AcapaPay.';

    public function run(array $params)
    {
        CLI::write('Iniciando Teste de Ligação M2M AcapaPay...', 'yellow');

        $acapapay  = new AcapaPay();
        $resultado = $acapapay->testarConexao();

        if ($resultado['sucesso']) {
            CLI::write('✔ ' . $resultado['mensagem'], 'green');

            return;
        }

        CLI::error('✘ FALHA: ' . $resultado['mensagem']);
    }
}
