<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuração do SDK AcapaPay.
 *
 * O host pode sobrepor estes valores criando app/Config/AcapaPay.php
 * que estende esta classe, ou apenas definindo as variáveis de
 * ambiente correspondentes no .env (prefixo acapapay.*).
 *
 * @author Augusto Kussema
 */
class AcapaPay extends BaseConfig
{
    public ?string $clientId = null;

    public ?string $clientSecret = null;

    public ?string $webhookSecret = null;

    /**
     * ID da organização no SSO da AcapaDev, usado como `organization_id`
     * ao criar Invoices via `criarFatura()`.
     */
    public ?string $organizationId = null;

    /**
     * 'sandbox' ou 'producao'.
     * Usado apenas se $host e $apiHost estiverem null.
     */
    public string $modo = 'producao';

    /**
     * URL customizada do servidor de identidade (ex: para localhost/desenvolvimento).
     * Se definida, sobrepõe a escolha baseada em $modo.
     */
    public ?string $host = null;

    /**
     * URL customizada da API.
     * Se definida, sobrepõe a escolha baseada em $modo.
     */
    public ?string $apiHost = null;

    public bool $verifySsl = true;

    /**
     * Classe concreta (nome totalmente qualificado) que implementa
     * `AcapaPay\CodeIgniter\Contracts\PlanoProviderInterface`, usada pelo
     * comando `spark acapapay:sync-plans`. Definir no `app/Config/AcapaPay.php`
     * do host (que estende esta classe).
     */
    public ?string $planoProvider = null;

    public function host(): string
    {
        if ($this->host) {
            return $this->host;
        }

        return $this->modo === 'sandbox'
            ? 'https://sandbox.acapadev.com'
            : 'https://id.acapadev.com';
    }

    public function apiHost(): string
    {
        if ($this->apiHost) {
            return $this->apiHost;
        }

        return $this->modo === 'sandbox'
            ? 'https://sandbox-api.acapadev.com'
            : 'https://api.acapadev.com';
    }
}
