<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Enums;

/**
 * Métodos de pagamento suportados pelo AcapaPay.
 *
 * @since 1.3.0
 */
final class PaymentMethod
{
    /** Referência Multicaixa (Angola, AOA). Validade: 3 dias. */
    public const REF = 'REF';

    /** Multicaixa Express Push por telefone (Angola, AOA). Validade: 60 segundos. */
    public const GPO = 'GPO';

    /** Multicaixa Express via Deeplink (Angola, AOA). Abre a app Multicaixa Express. Validade: 15 minutos. */
    public const GPO_DEEPLINK = 'GPO_DEEPLINK';

    /** Multicaixa Express via Código QR EMIS (Angola, AOA). Validade: 3 dias. */
    public const GPO_QR = 'GPO_QR';

    /** E-Kwanza (Angola, AOA). Validade: 5 minutos. */
    public const EKZ = 'EKZ';

    /** RedotPay — criptomoedas/stablecoins liquidadas em USD. Validade: 1 hora. */
    public const RDP = 'RDP';

    /** WiPay — checkout hospedado (Angola, AOA). Confirmação via webhook invoice.paid. */
    public const WIP = 'WIP';

    /**
     * Todos os métodos suportados.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::REF, self::GPO, self::GPO_DEEPLINK, self::GPO_QR, self::EKZ, self::RDP, self::WIP];
    }

    public static function isValid(string $method): bool
    {
        return in_array(strtoupper($method), self::all(), true);
    }

    /**
     * Métodos que exigem o número de telemóvel do cliente.
     * Nota: GPO_DEEPLINK e GPO_QR não necessitam de número de telemóvel prévio.
     */
    public static function requiresPhoneNumber(string $method): bool
    {
        return in_array(strtoupper($method), [self::GPO, self::EKZ], true);
    }

    /**
     * Métodos que exigem que a fatura esteja em USD.
     */
    public static function requiresUsd(string $method): bool
    {
        return strtoupper($method) === self::RDP;
    }

    /**
     * Nome legível para apresentar ao utilizador.
     */
    public static function label(string $method): string
    {
        return match (strtoupper($method)) {
            self::REF          => 'Referência Multicaixa',
            self::GPO          => 'Multicaixa Express (Telefone)',
            self::GPO_DEEPLINK => 'Multicaixa Express (Deeplink)',
            self::GPO_QR       => 'Multicaixa Express (Código QR EMIS)',
            self::EKZ          => 'E-Kwanza',
            self::RDP          => 'Criptomoeda (RedotPay)',
            self::WIP          => 'WiPay',
            default            => $method,
        };
    }

    /**
     * Indica se o método suporta estorno/reembolso programático direto via gateway.
     */
    public static function supportsRefund(string $method): bool
    {
        return in_array(strtoupper($method), [self::GPO, self::GPO_DEEPLINK, self::GPO_QR], true);
    }
}
