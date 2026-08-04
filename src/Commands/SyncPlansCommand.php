<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Commands;

use AcapaPay\CodeIgniter\AcapaPay;
use AcapaPay\CodeIgniter\Contracts\PlanoProviderInterface;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Comando spark para sincronizar os planos locais do host com o AcapaPay.
 *
 * Requer que o host configure `acapapay.planoProvider` (ou
 * `app/Config/AcapaPay.php::$planoProvider`) apontando para uma classe
 * que implementa `PlanoProviderInterface`.
 *
 * @author Augusto Kussema
 */
class SyncPlansCommand extends BaseCommand
{
    protected $group       = 'AcapaPay';

    protected $name        = 'acapapay:sync-plans';

    protected $description = 'Sincroniza os planos locais (preços/produtos) com o AcapaPay.';

    public function run(array $params)
    {
        $config = config('AcapaPay');

        if (! $config->planoProvider) {
            CLI::error('Nenhum planoProvider configurado. Defina acapapay.planoProvider (ou $planoProvider em app/Config/AcapaPay.php) com o nome da classe que implementa AcapaPay\CodeIgniter\Contracts\PlanoProviderInterface.');

            return;
        }

        if (! class_exists($config->planoProvider)) {
            CLI::error("A classe de planoProvider '{$config->planoProvider}' não existe.");

            return;
        }

        $provider = new $config->planoProvider();

        if (! $provider instanceof PlanoProviderInterface) {
            CLI::error("A classe '{$config->planoProvider}' precisa implementar AcapaPay\\CodeIgniter\\Contracts\\PlanoProviderInterface.");

            return;
        }

        $planos = $provider->listarPlanos();

        if (empty($planos)) {
            CLI::write('Nenhum plano ativo encontrado localmente. Nada para sincronizar.', 'yellow');

            return;
        }

        CLI::write('Sincronizando ' . count($planos) . ' plano(s) com o AcapaPay...', 'yellow');

        try {
            $acapapay = new AcapaPay();
            $resposta = $acapapay->sincronizarPlanos($planos);
        } catch (\Throwable $e) {
            CLI::error('Falha ao sincronizar: ' . $e->getMessage());

            return;
        }

        $sincronizados = $resposta['plans'] ?? [];

        foreach ($sincronizados as $plano) {
            if (isset($plano['reference_code'], $plano['id'])) {
                $provider->marcarSincronizado($plano['reference_code'], (string) $plano['id']);
            }
        }

        CLI::write('✔ ' . count($sincronizados) . ' plano(s) sincronizado(s) com sucesso!', 'green');
    }
}
