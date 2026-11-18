<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Enums;

/**
 * Moedas suportadas pelo AcapaPay.
 *
 * @since 1.3.0
 */
final class Currency
{
    /** Kwanza angolano — Multicaixa (REF/GPO/GPO_DEEPLINK/GPO_QR), E-Kwanza (EKZ) e WiPay (WIP). */
    public const AOA = 'AOA';

    /** Dólar americano — obrigatório para pagamentos em cripto via RedotPay (RDP). */
    public const USD = 'USD';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::AOA, self::USD];
    }

    public static function isValid(string $currency): bool
    {
        return in_array(strtoupper($currency), self::all(), true);
    }
}
