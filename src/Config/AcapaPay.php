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
