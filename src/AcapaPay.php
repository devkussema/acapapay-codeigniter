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
        $url      = $this->config->host() . '/oauth/token';
        $response = $this->client->request('POST', $url, [
            'form_params' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
            ],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();
        $body       = $response->getBody();

        if ($statusCode !== 200) {
            $details = "Status HTTP: {$statusCode}\n"
                . "URL: {$url}\n"
                . "Resposta: " . substr($body, 0, 500);

            $this->logger->error('AcapaPay SDK: falha na autenticação OAuth.', ['details' => $details]);

            throw new RuntimeException("AcapaPay SDK: Falha na autenticação OAuth (HTTP {$statusCode}).\n{$details}");
        }

        $data = json_decode($body, true);

        if (! isset($data['access_token'])) {
            $details = "Resposta JSON sem access_token. Resposta: " . substr($body, 0, 500);
            $this->logger->error('AcapaPay SDK: resposta de autenticação inválida.', ['details' => $details]);

            throw new RuntimeException("AcapaPay SDK: Resposta de autenticação inválida.\n{$details}");
        }

        return $data['access_token'];
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
     * Cria uma sessão de pagamento (checkout) associada a um plano já
     * sincronizado (ver `sincronizarPlanos()`), e devolve os dados
     * completos da resposta (incluindo `url` de redirecionamento).
     *
     * O AcapaPay exige sempre um `plan_reference_code` válido — `amount`
     * é opcional e serve apenas para sobrepor o preço do plano nesta
     * sessão específica (ex: descontos pontuais).
     *
     * @param array{user_id: mixed, plan_reference_code: string, amount?: int, currency?: string, success_url?: string, cancel_url?: string, metadata?: array} $dados
     */
    public function criarSessaoPagamento(array $dados): array
    {
        $token = $this->obterTokenCached();

        $payload = [
            'user_id'             => $dados['user_id'],
            'plan_reference_code' => $dados['plan_reference_code'],
            'success_url'         => $dados['success_url'] ?? null,
            'cancel_url'          => $dados['cancel_url'] ?? null,
            'origin_domain'       => $dados['origin_domain'] ?? $this->origemDominio(),
            'metadata'            => $dados['metadata'] ?? [],
        ];

        if (isset($dados['amount'])) {
            $payload['amount'] = $dados['amount'];
            $payload['currency'] = $dados['currency'] ?? 'AOA';
        }

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/checkout/sessions', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => $payload,
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
                'origin_domain'       => $this->origemDominio(),
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
     * Sincroniza (cria/atualiza em lote) planos no AcapaPay.
     *
     * @param list<array{reference_code: string, name: string, price: float, description?: string, currency?: string, billing_cycle?: string, features?: array}> $planos
     *
     * @return array{plans?: list<array{reference_code: string, id: string}>} Resposta bruta da API.
     */
    public function sincronizarPlanos(array $planos): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('PUT', $this->config->apiHost() . '/v1/billing/plans', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => ['plans' => $planos],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao sincronizar planos. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao sincronizar planos com o servidor de pagamento.');
        }

        return json_decode($response->getBody(), true) ?? [];
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
        $config = [
            'host'     => $this->config->host(),
            'apiHost'  => $this->config->apiHost(),
            'modo'     => $this->config->modo,
            'clientId' => substr($this->config->clientId ?? 'VAZIO', 0, 10) . '***',
        ];

        try {
            $token = $this->autenticar();
        } catch (RuntimeException $e) {
            $msg = "Configuração:\n"
                . "  modo: {$config['modo']}\n"
                . "  host: {$config['host']}\n"
                . "  apiHost: {$config['apiHost']}\n"
                . "  clientId: {$config['clientId']}\n\n"
                . "Erro: " . $e->getMessage();

            return ['sucesso' => false, 'mensagem' => $msg];
        }

        $pingUrl  = $this->config->apiHost() . '/v1/ping';
        $response = $this->client->request('GET', $pingUrl, [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            return [
                'sucesso'  => false,
                'mensagem' => "AcapaPay SDK: Ping falhou (HTTP {$statusCode}).\n"
                    . "URL: {$pingUrl}\n"
                    . "Resposta: " . substr($response->getBody(), 0, 300),
            ];
        }

        return [
            'sucesso'  => true,
            'mensagem' => "✓ AcapaPay SDK: Ligação estabelecida com sucesso!\n"
                . "  modo: {$config['modo']}\n"
                . "  host: {$config['host']}\n"
                . "  apiHost: {$config['apiHost']}\n"
                . "  Credenciais: válidas",
        ];
    }

    /**
     * Resolve o domínio de origem do pedido atual (esquema + host), enviado
     * ao AcapaPay na criação da sessão para que o SSO autorize o checkout
     * a ser embutido num iframe a partir deste domínio.
     */
    private function origemDominio(): ?string
    {
        $request = Services::request();

        if (! method_exists($request, 'getUri')) {
            return null;
        }

        $uri = $request->getUri();

        return $uri->getScheme() . '://' . $uri->getAuthority();
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
