<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Support;

/**
 * Resultado de uma cobrança criada pela API de pagamento direta.
 *
 * Implementa ArrayAccess, pelo que é 100% retrocompatível com código que
 * trate o retorno de `criarCobranca()` como um array (`$res['data']['pay_url']`),
 * enquanto oferece helpers tipados (`$res->qrCodeImage()`, `$res->deeplink()`,
 * `$res->reference()`, `$res->payUrl()`, etc.).
 *
 * @since 1.3.0
 */
final class ChargeResult implements \ArrayAccess, \JsonSerializable
{
    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    public function invoiceId(): ?string
    {
        return $this->payload['invoice_id'] ?? null;
    }

    public function payUrl(): ?string
    {
        return $this->payload['data']['pay_url'] ?? $this->payload['pay_url'] ?? null;
    }

    public function paymentMethod(): ?string
    {
        return $this->payload['payment_method'] ?? null;
    }

    public function isMock(): bool
    {
        return (bool) ($this->payload['is_mock'] ?? false);
    }

    public function expiresAt(): ?string
    {
        return $this->payload['expires_at'] ?? null;
    }

    public function reference(): ?string
    {
        return $this->payload['data']['reference'] ?? null;
    }

    public function entity(): ?string
    {
        return $this->payload['data']['entity'] ?? null;
    }

    /**
     * URI de Deeplink para abrir diretamente a app Multicaixa Express (GPO_DEEPLINK).
     * Exemplo: mcxwallet://purchase?...
     */
    public function deeplink(): ?string
    {
        return $this->payload['data']['deeplink'] ?? null;
    }

    /**
     * String Base64 bruta do Código QR EMIS (GPO_QR).
     */
    public function qrCode(): ?string
    {
        return $this->payload['data']['qrCodeArr'] ?? $this->payload['data']['qr_code'] ?? null;
    }

    /**
     * Data URI completa pronta a usar no atributo `src` de uma imagem HTML:
     * `<img src="<?= esc($result->qrCodeImage()) ?>" alt="Código QR EMIS">`.
     */
    public function qrCodeImage(): ?string
    {
        $qr = $this->qrCode();
        if (! $qr) {
            return null;
        }

        if (str_starts_with($qr, 'data:image/')) {
            return $qr;
        }

        return 'data:image/png;base64,' . $qr;
    }

    public function hasQrCode(): bool
    {
        return ! empty($this->qrCode());
    }

    public function hasDeeplink(): bool
    {
        return ! empty($this->deeplink());
    }

    /**
     * Carteiras de criptomoeda suportadas pela RedotPay (método RDP).
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethods(): array
    {
        return $this->payload['data']['paymentMethods'] ?? [];
    }

    /**
     * Links de QR code por carteira (método RDP).
     *
     * @return array<string, array{name: ?string, logo: ?string, web: ?string, h5: ?string}>
     */
    public function qrCodeUrls(): array
    {
        $urls = [];

        foreach ($this->paymentMethods() as $method) {
            $id = $method['id'] ?? null;
            if (! $id) {
                continue;
            }

            $urls[$id] = [
                'name' => $method['name'] ?? null,
                'logo' => $method['logo'] ?? null,
                'web'  => $method['webQrCode'] ?? null,
                'h5'   => $method['h5QrCode'] ?? null,
            ];
        }

        return $urls;
    }

    public function appUrl(string $walletId): ?string
    {
        foreach ($this->paymentMethods() as $method) {
            if (($method['id'] ?? null) === $walletId) {
                return $method['appUrl'] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->payload['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function jsonSerialize(): mixed
    {
        return $this->payload;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->payload[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->payload[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->payload[] = $value;

            return;
        }

        $this->payload[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->payload[$offset]);
    }
}
