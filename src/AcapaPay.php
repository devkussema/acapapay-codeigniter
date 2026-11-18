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
     * Cria uma Invoice (fatura), representando a dívida/carrinho de compras.
     * Uma Invoice nunca expira e é imutável — deve ser criada uma única vez
     * por compra; retomas de pagamento devem reutilizar o `invoice_id`
     * devolvido aqui e criar apenas novas Charges (ver `criarCobranca()`).
     *
     * @param array<string, mixed> $dados {
     *     @type string      $customer_name             Obrigatório.
     *     @type string      $customer_email            Opcional.
     *     @type string      $customer_nif              Opcional.
     *     @type int|float   $amount                    Opcional quando $items é passado.
     *     @type string      $currency                  'AOA' (omissão) ou 'USD'.
     *     @type string      $description               Opcional.
     *     @type array       $items                     Lista de itens [['description', 'quantity', 'unit_price'], ...].
     *     @type string      $app_reference             Referência interna do pedido.
     *     @type string      $due_date                  Opcional.
     *     @type string      $success_url               URL de retorno em caso de sucesso.
     *     @type string      $cancel_url                URL de retorno em caso de cancelamento.
     *     @type array       $metadata                  Metadados devolvidos no webhook invoice.paid.
     *     @type string      $preferred_payment_method  REF|GPO|GPO_DEEPLINK|GPO_QR|EKZ|RDP|WIP.
     *     @type string|null $organization_id           Opcional.
     * }
     */
    public function criarFatura(array $dados): array
    {
        if (empty($dados['customer_name'])) {
            throw new InvalidArgumentException('AcapaPay SDK: "customer_name" é obrigatório para criar uma fatura.');
        }

        $currency = strtoupper((string) ($dados['currency'] ?? Currency::AOA));
        if (! Currency::isValid($currency)) {
            throw new InvalidArgumentException('AcapaPay SDK: moeda inválida. Use Currency::AOA ou Currency::USD.');
        }

        $token = $this->obterTokenCached();

        $metadata = $dados['metadata'] ?? [];
        if ($this->config->modo === 'sandbox') {
            $metadata['sandbox_mode'] = true;
        }

        $payload = array_filter([
            'customer_name'            => $dados['customer_name'],
            'customer_email'           => $dados['customer_email'] ?? null,
            'customer_nif'             => $dados['customer_nif'] ?? null,
            'amount'                   => $dados['amount'] ?? null,
            'currency'                 => $currency,
            'description'              => $dados['description'] ?? null,
            'items'                    => $dados['items'] ?? [],
            'app_reference'            => $dados['app_reference'] ?? null,
            'due_date'                 => $dados['due_date'] ?? null,
            'success_url'              => $dados['success_url'] ?? null,
            'cancel_url'               => $dados['cancel_url'] ?? null,
            'preferred_payment_method' => isset($dados['preferred_payment_method']) ? strtoupper((string) $dados['preferred_payment_method']) : null,
            'organization_id'          => $dados['organization_id'] ?? $this->config->organizationId,
            'metadata'                 => $metadata,
        ], static fn ($value) => $value !== null);

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/billing/invoices', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => $payload,
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao criar fatura. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao comunicar com o servidor de pagamento para criar fatura.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * Alias em inglês para `criarFatura()` (compatível com o SDK Laravel).
     */
    public function createInvoice(array $attributes): array
    {
        return $this->criarFatura($attributes);
    }

    /**
     * Cria uma Charge (tentativa de pagamento) para uma Invoice existente.
     * Suporta todos os métodos:
     * - `PaymentMethod::REF` (Referência Multicaixa)
     * - `PaymentMethod::GPO` (Multicaixa Express Push por Nº de telefone)
     * - `PaymentMethod::GPO_DEEPLINK` (Multicaixa Express Deeplink `mcxwallet://`)
     * - `PaymentMethod::GPO_QR` (Multicaixa Express Código QR EMIS em Base64)
     * - `PaymentMethod::WIP` (WiPay Checkout Hospedado)
     * - `PaymentMethod::RDP` (RedotPay Cripto/USDT em USD)
     * - `PaymentMethod::EKZ` (E-Kwanza)
     *
     * Devolve um `ChargeResult` que implementa `ArrayAccess` (podes continuar a usar
     * `$res['data']['...']` como array ou usar `$res->qrCodeImage()`, `$res->deeplink()`,
     * `$res->reference()`, `$res->payUrl()`, etc.).
     */
    public function criarCobranca(string $invoiceId, string $paymentMethod, ?string $customerPhone = null): ChargeResult
    {
        $paymentMethod = strtoupper($paymentMethod);

        if (! PaymentMethod::isValid($paymentMethod)) {
            throw new InvalidArgumentException(
                'AcapaPay SDK: método de pagamento inválido: ' . $paymentMethod
                . '. Use uma constante de ' . PaymentMethod::class . '.'
            );
        }

        if (PaymentMethod::requiresPhoneNumber($paymentMethod) && empty($customerPhone)) {
            throw new InvalidArgumentException(
                'AcapaPay SDK: o método ' . PaymentMethod::label($paymentMethod)
                . ' exige o número de telemóvel do cliente.'
            );
        }

        $token = $this->obterTokenCached();

        $payload = ['payment_method' => $paymentMethod];
        if ($customerPhone) {
            $payload['phone_number'] = $customerPhone;
        }

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/billing/invoices/' . $invoiceId . '/charges', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => $payload,
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
            'timeout'     => 60,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao criar cobrança. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao comunicar com o servidor de pagamento para criar cobrança.');
        }

        $result = json_decode($response->getBody(), true) ?? [];

        return ChargeResult::fromArray(array_merge(['invoice_id' => $invoiceId], $result));
    }

    /**
     * Alias em inglês para `criarCobranca()` (compatível com o SDK Laravel).
     */
    public function charge(string $invoiceId, string $paymentMethod, ?string $phoneNumber = null): ChargeResult
    {
        return $this->criarCobranca($invoiceId, $paymentMethod, $phoneNumber);
    }

    /**
     * Atalho para cobrança via Referência Multicaixa (`REF`, AOA).
     */
    public function cobrarPorReferencia(string $invoiceId): ChargeResult
    {
        return $this->criarCobranca($invoiceId, PaymentMethod::REF);
    }

    /**
     * Atalho para cobrança via Multicaixa Express Push por telefone (`GPO`, AOA).
     */
    public function cobrarPorExpress(string $invoiceId, string $phoneNumber): ChargeResult
    {
        return $this->criarCobranca($invoiceId, PaymentMethod::GPO, $phoneNumber);
    }

    /**
     * Atalho para cobrança via Multicaixa Express Deeplink (`GPO_DEEPLINK`, AOA).
     * Devolve `$result->deeplink()` (`mcxwallet://purchase?...`).
     */
    public function cobrarPorDeeplink(string $invoiceId): ChargeResult
    {
        return $this->criarCobranca($invoiceId, PaymentMethod::GPO_DEEPLINK);
    }

    public function chargeWithDeeplink(string $invoiceId): ChargeResult
    {
        return $this->cobrarPorDeeplink($invoiceId);
    }

    /**
     * Atalho para cobrança via Multicaixa Express Código QR EMIS (`GPO_QR`, AOA).
     * Devolve `$result->qrCodeImage()` (`data:image/png;base64,...`) pronto para `<img src="...">`.
     */
    public function cobrarPorQrCode(string $invoiceId): ChargeResult
    {
        return $this->criarCobranca($invoiceId, PaymentMethod::GPO_QR);
    }

    public function chargeWithQrCode(string $invoiceId): ChargeResult
    {
        return $this->cobrarPorQrCode($invoiceId);
    }

    /**
     * Atalho para cobrança via WiPay Checkout Hospedado (`WIP`, AOA).
     * Devolve `$result->payUrl()`.
     */
    public function cobrarComWipay(string $invoiceId, ?string $phoneNumber = null): ChargeResult
    {
        return $this->criarCobranca($invoiceId, PaymentMethod::WIP, $phoneNumber);
    }

    public function chargeWithWipay(string $invoiceId, ?string $phoneNumber = null): ChargeResult
    {
        return $this->cobrarComWipay($invoiceId, $phoneNumber);
    }

    /**
     * Atalho para cobrança em criptomoeda via RedotPay (`RDP`, USD).
     * Devolve `$result->payUrl()`, `$result->paymentMethods()` e `$result->qrCodeUrls()`.
     */
    public function cobrarComCripto(string $invoiceId): ChargeResult
    {
        return $this->criarCobranca($invoiceId, PaymentMethod::RDP);
    }

    public function chargeWithCrypto(string $invoiceId): ChargeResult
    {
        return $this->cobrarComCripto($invoiceId);
    }

    /**
     * Cria a fatura (AOA) e gera imediatamente o Código QR EMIS (`GPO_QR`) num só passo.
     */
    public function criarCobrancaQrCode(array $dadosFatura): ChargeResult
    {
        $dadosFatura['currency']                 = Currency::AOA;
        $dadosFatura['preferred_payment_method'] = PaymentMethod::GPO_QR;

        $invoice = $this->criarFatura($dadosFatura);

        return $this->cobrarPorQrCode((string) $invoice['invoice_id']);
    }

    public function createQrCharge(array $invoiceAttributes): ChargeResult
    {
        return $this->criarCobrancaQrCode($invoiceAttributes);
    }

    /**
     * Cria a fatura (AOA) e gera imediatamente o Deeplink Multicaixa Express (`GPO_DEEPLINK`) num só passo.
     */
    public function criarCobrancaDeeplink(array $dadosFatura): ChargeResult
    {
        $dadosFatura['currency']                 = Currency::AOA;
        $dadosFatura['preferred_payment_method'] = PaymentMethod::GPO_DEEPLINK;

        $invoice = $this->criarFatura($dadosFatura);

        return $this->cobrarPorDeeplink((string) $invoice['invoice_id']);
    }

    public function createDeeplinkCharge(array $invoiceAttributes): ChargeResult
    {
        return $this->criarCobrancaDeeplink($invoiceAttributes);
    }

    /**
     * Cria a fatura (USD) e gera imediatamente a cobrança Cripto RedotPay (`RDP`) num só passo.
     */
    public function criarCobrancaCripto(array $dadosFatura): ChargeResult
    {
        $dadosFatura['currency']                 = Currency::USD;
        $dadosFatura['preferred_payment_method'] = PaymentMethod::RDP;

        $invoice = $this->criarFatura($dadosFatura);

        return $this->cobrarComCripto((string) $invoice['invoice_id']);
    }

    public function createCryptoCharge(array $invoiceAttributes): ChargeResult
    {
        return $this->criarCobrancaCripto($invoiceAttributes);
    }

    /**
     * Consulta o estado atual de uma fatura para polling do frontend.
     * Devolve `invoice_status`: pending, paid, expired ou cancelled.
     */
    public function consultarStatusFatura(string $invoiceId): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('GET', $this->config->apiHost() . '/v1/billing/invoices/' . $invoiceId . '/status', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao consultar status da fatura. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao comunicar com o servidor de pagamento para consultar o status da fatura.');
        }

        $res = json_decode($response->getBody(), true) ?? [];

        return $res['data'] ?? $res;
    }

    /**
     * Verifica se a fatura já se encontra paga.
     */
    public function faturaPaga(string $invoiceId): bool
    {
        return ($this->consultarStatusFatura($invoiceId)['invoice_status'] ?? null) === 'paid';
    }

    public function isPaid(string $invoiceId): bool
    {
        return $this->faturaPaga($invoiceId);
    }

    /**
     * Marca uma fatura como paga instantaneamente no ambiente Sandbox,
     * para permitir testar o fluxo de pagamento sem transações reais.
     */
    public function simularPagamento(string $invoiceId): array
    {
        if ($this->config->modo !== 'sandbox') {
            throw new RuntimeException('AcapaPay SDK: simularPagamento() só está disponível quando modo === "sandbox".');
        }

        $token = $this->obterTokenCached();

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/billing/invoices/' . $invoiceId . '/simulate', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao simular pagamento. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao simular pagamento da fatura.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * Solicita o estorno/reembolso (total ou parcial) de uma fatura paga.
     */
    public function reembolsarFatura(string $invoiceId, ?float $amount = null, ?string $reason = null): array
    {
        $token = $this->obterTokenCached();

        $body = [];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/billing/invoices/' . $invoiceId . '/refund', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => $body,
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao reembolsar fatura. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao reembolsar fatura.');
        }

        $res = json_decode($response->getBody(), true) ?? [];

        if (isset($res['data']) && is_array($res['data'])) {
            return array_merge($res, $res['data']);
        }

        return $res;
    }

    public function refund(string $invoiceId, ?float $amount = null, ?string $reason = null): array
    {
        return $this->reembolsarFatura($invoiceId, $amount, $reason);
    }

    /**
     * Consulta o saldo disponível da aplicação, configurações bancárias e histórico de saques.
     */
    public function consultarSaques(int $page = 1): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('GET', $this->config->apiHost() . '/v1/billing/payouts?page=' . $page, [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao consultar saques. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao consultar saques.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    public function getPayouts(int $page = 1): array
    {
        return $this->consultarSaques($page);
    }

    /**
     * Solicita um saque manual para a aplicação autenticada.
     */
    public function solicitarSaque(float $amount): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/billing/payouts', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'json'        => ['amount' => $amount],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao solicitar saque. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao solicitar saque.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    public function requestPayout(float $amount): array
    {
        return $this->solicitarSaque($amount);
    }

    /**
     * Cancela uma solicitação de saque pendente.
     */
    public function cancelarSaque(int $payoutId): array
    {
        $token = $this->obterTokenCached();

        $response = $this->client->request('POST', $this->config->apiHost() . '/v1/billing/payouts/' . $payoutId . '/cancel', [
            'headers'     => ['Authorization' => 'Bearer ' . $token],
            'verify'      => $this->config->verifySsl,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() >= 300) {
            $this->logger->error('AcapaPay SDK: erro ao cancelar saque. ' . $response->getBody());

            throw new RuntimeException('AcapaPay SDK: Falha ao cancelar saque.');
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    public function cancelPayout(int $payoutId): array
    {
        return $this->cancelarSaque($payoutId);
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
