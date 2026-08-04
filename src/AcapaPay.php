<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter;

use AcapaPay\CodeIgniter\Config\AcapaPay as AcapaPayConfig;
use CodeIgniter\HTTP\CURLRequest;
use Config\Services;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SDK oficial AcapaPay para CodeIgniter 4.
 *
 * Trata da autenticação OAuth2 (Client Credentials), criação de sessões
 * de checkout, consulta de faturas e validação de assinaturas de webhook.
 *
 * @author Augusto Kussema
 */
class AcapaPay
{
    private AcapaPayConfig $config;

    private CURLRequest $client;

    private LoggerInterface $logger;

    public function __construct(?AcapaPayConfig $config = null)
    {
        $this->config = $config ?? config('AcapaPay');
        $this->client = Services::curlrequest();
        $this->logger = Services::logger();
    }

    /**
     * Autentica via OAuth2 Client Credentials e devolve o access token.
     */
    public function autenticar(): string
    {
        $response = $this->client->request('POST', $this->config->host() . '/oauth/token', [
            'form_params' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
            ],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('AcapaPay SDK: falha na autenticação OAuth. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha na autenticação OAuth. Verifica as tuas credenciais.');
        }

        $data = json_decode($response->getBody(), true);

        return $data['access_token'] ?? throw new RuntimeException('AcapaPay SDK: Resposta de autenticação sem access_token.');
    }

    /**
     * Obtém o access token, reutilizando um token em cache quando disponível.
     *
     * Ao contrário de um cache em memória, este usa o serviço de cache do
     * CodeIgniter (file/redis/memcached conforme app/Config/Cache.php do
     * host), pelo que o token sobrevive entre requests.
     */
    public function obterTokenCached(int $cacheDuration = 3300): string
    {
        $cache    = Services::cache();
        $cacheKey = 'acapapay_token_' . md5((string) $this->config->clientId . $this->config->modo);

        $token = $cache->get($cacheKey);
        if ($token !== null) {
            return $token;
        }

        $token = $this->autenticar();
        $cache->save($cacheKey, $token, $cacheDuration);

        return $token;
    }

    /**
     * Cria uma sessão de pagamento (checkout) por valor, e devolve os
     * dados completos da resposta (incluindo `url` de redirecionamento).
     *
     * @param array{user_id: mixed, amount: int, currency?: string, success_url?: string, cancel_url?: string, metadata?: array} $dados
     */
    public function criarSessaoPagamento(array $dados): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/checkout/sessions', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => [
                'user_id'     => $dados['user_id'],
                'amount'      => $dados['amount'],
                'currency'    => $dados['currency'] ?? 'AOA',
                'success_url' => $dados['success_url'] ?? null,
                'cancel_url'  => $dados['cancel_url'] ?? null,
                'metadata'    => $dados['metadata'] ?? [],
            ],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao criar sessão de pagamento. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao comunicar com o servidor de pagamento para criar sessão.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * Cria uma sessão de checkout associada a um plano, e devolve o URL
     * de redirecionamento. Espelha a assinatura do SDK Laravel.
     */
    public function checkoutSession(
        mixed $userId,
        string $planReferenceCode,
        array $metadata = [],
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): string {
        $token = $this->obterTokenCached();

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/checkout/sessions', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => [
                'user_id'             => $userId,
                'plan_reference_code' => $planReferenceCode,
                'success_url'         => $successUrl,
                'cancel_url'          => $cancelUrl,
                'metadata'            => $metadata,
            ],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao criar sessão de checkout. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao comunicar com o servidor de pagamento para criar sessão.');
        }

        $data = json_decode($response->getBody(), true);

        return $data['url'] ?? throw new RuntimeException('AcapaPay SDK: Resposta de checkout sem url.');
    }

    /**
     * Consulta o estado atual de uma fatura.
     */
    public function consultarFatura(string $invoiceId): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('GET', $this->config->apiHost() . '/v1/billing/invoices/' . $invoiceId, [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao consultar fatura. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao comunicar com o servidor de pagamento para verificar a fatura.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * Valida a assinatura HMAC-SHA256 de um payload de webhook.
     */
    public function validarAssinaturaWebhook(string $payload, string $signature): bool
    {
        $secret = $this->config->webhookSecret;

        if (! $secret || ! $signature) {
            return false;
        }

        $esperado = hash_hmac('sha256', $payload, $secret);

        return hash_equals($esperado, $signature);
    }

    /**
     * Testa a ligação (OAuth2 + ping) com a infraestrutura AcapaPay.
     *
     * @return array{sucesso: bool, mensagem: string}
     */
    public function testarConexao(): array
    {
        try {
            $token = $this->autenticar();
        } catch (RuntimeException $e) {
            return ['sucesso' => false, 'mensagem' => $e->getMessage()];
        }

        $response = $this->client->request('GET', $this->config->apiHost() . '/v1/ping', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            return [
                'sucesso'  => false,
                'mensagem' => 'AcapaPay SDK: Ping falhou (HTTP ' . $response->getStatusCode() . ').',
            ];
        }

        return [
            'sucesso'  => true,
            'mensagem' => 'AcapaPay SDK: Ligação estabelecida e credenciais válidas (modo: ' . $this->config->modo . ').',
        ];
    }

    /**
     * Renderiza a view de iframe do checkout, sem depender da resolução
     * de `view()` por namespace do host.
     */
    public function renderIframe(string $checkoutUrl, array $opts = []): string
    {
        return Services::renderer(__DIR__ . '/Views')
            ->setVar('checkoutUrl', $checkoutUrl)
            ->setVar('height', $opts['height'] ?? null)
            ->setVar('width', $opts['width'] ?? null)
            ->render('iframe', ['saveData' => false]);
    }
}
