<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Contracts;

/**
 * Contrato que a aplicação host implementa para expor os seus planos
 * (produtos, modelos, assinaturas — qualquer catálogo com preço) ao
 * comando `spark acapapay:sync-plans`.
 *
 * Regista a classe concreta em `acapapay.planoProvider` (via
 * `app/Config/AcapaPay.php` do host, estendendo a config do pacote).
 *
 * @author Augusto Kussema
 */
interface PlanoProviderInterface
{
    /**
     * Devolve os planos ativos a sincronizar com o AcapaPay.
     *
     * Cada item deve conter pelo menos `reference_code`, `name` e `price`.
     * Campos opcionais aceites pelo AcapaPay: `description`, `currency`,
     * `billing_cycle`, `features`.
     *
     * @return list<array{reference_code: string, name: string, price: float, description?: string, currency?: string, billing_cycle?: string, features?: array}>
     */
    public function listarPlanos(): array;

    /**
     * Chamado após uma sincronização bem-sucedida, para que o host
     * persista o id atribuído pelo AcapaPay a cada plano localmente.
     */
    public function marcarSincronizado(string $referenceCode, string $acapapayPlanId): void;
}
