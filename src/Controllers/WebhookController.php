<?php

declare(strict_types=1);

namespace AcapaPay\CodeIgniter\Controllers;

use AcapaPay\CodeIgniter\AcapaPay;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Controller;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Receptor de webhooks do AcapaPay.
 *
 * Valida a assinatura HMAC e dispara eventos CodeIgniter nomeados
 * (ex: acapapay:invoice.paid) para que o host os escute em
 * app/Config/Events.php.
 *
 * @author Augusto Kussema
 */
class WebhookController extends Controller
{
    use ResponseTrait;

    public function handle(): ResponseInterface
    {
        $request = service('request');
        $logger  = Services::logger();
        $acapapay = new AcapaPay();

        $signature = $request->getHeaderLine('X-AcapaDev-Signature');
        $payload   = $request->getBody();

        if (! $signature || ! $payload) {
            return $this->respond(['error' => 'Assinatura ou body ausente'], 401);
        }

        if (! $acapapay->validarAssinaturaWebhook($payload, $signature)) {
            $logger->warning('AcapaPay SDK: assinatura de webhook inválida.');

            return $this->respond(['error' => 'Assinatura Invalida'], 401);
        }

        $data      = json_decode($payload, true) ?? [];
        $eventType = $data['event'] ?? 'unknown';

        try {
            Events::trigger('acapapay:' . $eventType, $data);
            $logger->info("AcapaPay SDK: evento {$eventType} disparado com sucesso.");

            return $this->respond(['status' => 'success']);
        } catch (\Throwable $e) {
            $logger->error('AcapaPay SDK Webhook erro: ' . $e->getMessage());

            return $this->respond(['error' => 'Erro a processar evento interno SDK'], 500);
        }
    }
}
