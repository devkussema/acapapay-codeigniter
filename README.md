# AcapaPay CodeIgniter SDK

O **AcapaPay CodeIgniter SDK** é a biblioteca oficial para integrar de forma rápida e segura a gateway de pagamentos centralizada do ecossistema AcapaDev em qualquer projeto baseado no **CodeIgniter 4**.

Este pacote trata automaticamente da comunicação OAuth2 (Server-to-Server), com cache de token persistente, e expõe uma view pronta a usar para apresentar o checkout sem fricções (através de iFrames otimizados e bidirecionais).

> [!WARNING]
> **Compatibilidade Exclusiva:** Este pacote foi desenhado exclusivamente para o **CodeIgniter 4**.

## Funcionalidades Principais

* **Autenticação Automática:** Gestão transparente de Tokens M2M (OAuth2) via `Client Credentials`, com cache **persistente entre requests** usando o serviço de Cache do CodeIgniter (`file`, `redis`, `memcached`, conforme configurado em `app/Config/Cache.php`).
* **Modo Sandbox/Produção:** troca automática entre ambientes através da variável `acapapay.modo`.
* **View de iFrame:** um helper `renderIframe()` que devolve o HTML do checkout pronto a embutir, e que reage automaticamente quando a fatura é paga pelo utilizador.
* **Validação de Webhooks (HMAC):** receção segura das notificações de pagamento baseada numa chave secreta, disparando eventos nativos do CodeIgniter (`Events::trigger`).
* **CLI Diagnostic:** comando `spark` nativo para testar a saúde da conexão entre o teu servidor e o SSO central.
* **Rota de Webhook Auto-descoberta:** o pacote regista `POST webhooks/acapapay` automaticamente via Auto-Discovery do CodeIgniter (não precisas de editar `app/Config/Routes.php`).

---

## 1. Instalação

```bash
composer require devkussema/acapapay-codeigniter
```

### Estender a configuração (opcional)

Por padrão os valores vêm das variáveis de ambiente (ver secção 2). Se precisares de lógica extra, podes criar `app/Config/AcapaPay.php` na tua aplicação estendendo a config do pacote:

```php
<?php

namespace Config;

use AcapaPay\CodeIgniter\Config\AcapaPay as BaseAcapaPay;

class AcapaPay extends BaseAcapaPay
{
    // sobrepõe ou adiciona o que precisares
}
```

O helper `config('AcapaPay')` do CodeIgniter resolve automaticamente para a tua subclasse, se existir.

---

## 2. Configuração (Variáveis de Ambiente)

A tua aplicação precisa de se identificar perante o AcapaPay (SSO). Cria uma "OAuth App" no painel do SSO e adiciona as seguintes credenciais ao teu ficheiro `.env`:

```env
# O teu Client ID (App ID) gerado no Painel de Developer do SSO
acapapay.clientId = 9a8b7c6d-1234-5678-abcd...

# O Client Secret gerado para a tua App
acapapay.clientSecret = super_secret_string...

# (Opcional) A chave HMAC para assinar Webhooks.
# Essencial para garantir que os Webhooks vêm legitimamente do AcapaPay.
acapapay.webhookSecret = hmac_secret_aqui...

# (Opcional) 'sandbox' ou 'producao' — troca automaticamente os hosts de id/api.
# Ignorado se host/apiHost customizados estiverem definidos.
# acapapay.modo = producao

# (Opcional) URL customizada do servidor de identidade (sobrepõe modo).
# Útil para desenvolvimento local apontando para um SSO local.
# acapapay.host = https://id.sso_acapadev.me

# (Opcional) URL customizada da API (sobrepõe modo).
# Útil para desenvolvimento local apontando para um SSO local.
# acapapay.apiHost = https://api.sso_acapadev.me

# (Opcional) Desativa verificação SSL (útil para desenvolvimento local com certificados self-signed)
# acapapay.verifySsl = false
```

---

## 3. Teste de Diagnóstico e Conexão (spark)

Antes de escrever qualquer código, o pacote fornece um comando de diagnóstico que envia um *Ping* seguro à infraestrutura central. Isto valida se as credenciais `.env` estão corretas e se os firewalls não estão a bloquear a ligação.

```bash
php spark acapapay:test-connection
```

* Se tudo estiver correto, verás uma mensagem de sucesso a verde no terminal.
* Se algo falhar (ex: IP bloqueado ou segredo errado), será devolvido um relatório de erro a vermelho.

---

## 4. Iniciar um Pagamento (Checkout)

O SDK é usado por instância direta — não existem Facades em CodeIgniter 4.

### Exemplo no teu Controller (por plano):

```php
<?php

namespace App\Controllers;

use AcapaPay\CodeIgniter\AcapaPay;
use CodeIgniter\Controller;

class PagamentoController extends Controller
{
    public function comprarPlano()
    {
        $userId = session()->get('user_id');
        $plano  = 'PRO_YEARLY';

        try {
            $acapapay = new AcapaPay();

            $urlDePagamento = $acapapay->checkoutSession(
                $userId,
                $plano,
                ['minha_metadata' => '123'],
                site_url('pagamento/sucesso'),
                site_url('pagamento/cancelado')
            );

            return view('pagamento/checkout', ['urlDePagamento' => $urlDePagamento]);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
```

### Exemplo por valor (amount/currency):

```php
$dados = $acapapay->criarSessaoPagamento([
    'user_id'     => $userId,
    'amount'      => 15000, // cêntimos
    'currency'    => 'AOA',
    'success_url' => site_url('pagamento/sucesso'),
    'cancel_url'  => site_url('pagamento/cancelado'),
    'metadata'    => ['local_user_id' => $userId],
]);

$urlDePagamento = $dados['url'];
```

---

## 5. Integrar Interface (view de iFrame)

Para manter o utilizador dentro do teu site, usa o helper `renderIframe()`, que já escuta os eventos de sucesso/cancelamento transmitidos pelo SSO Central via `postMessage`:

```php
<?= (new \AcapaPay\CodeIgniter\AcapaPay())->renderIframe($urlDePagamento) ?>
```

> [!NOTE]
> O componente deteta magicamente eventos disparados pela página de sucesso no AcapaDev e relança-os como `CustomEvent` (`acapapay-success` / `acapapay-cancel`) no `window` — basta escutares esses eventos em JavaScript no teu lado.

---

## 6. Escutar Webhooks (Atualizar Encomendas)

Quando a transação for paga com sucesso (ou falhar), o servidor central envia um *Webhook POST* para a tua aplicação.

O SDK regista automaticamente a rota `POST webhooks/acapapay` (via Auto-Discovery do CodeIgniter — confirma que `discoverInComposer` está `true` em `app/Config/Modules.php`, que é o padrão numa instalação nova). O pacote valida a assinatura **HMAC** e dispara um evento nomeado do CodeIgniter para cada tipo de evento recebido, ex: `acapapay:invoice.paid`, `acapapay:invoice.failed`, `acapapay:invoice.canceled`.

Regista o listener em `app/Config/Events.php`:

```php
use CodeIgniter\Events\Events;

Events::on('acapapay:invoice.paid', function (array $data) {
    $metadata      = $data['data']['metadata'] ?? [];
    $localUserId   = $metadata['local_user_id'] ?? null;
    $invoiceId     = $data['data']['invoice_id'] ?? null;

    // Atualiza a tua Base de Dados local
});
```

> [!TIP]
> Se o teu projeto já tem uma rota de webhook própria noutro caminho, confirma que não há sobreposição antes de ativar ambos.

---

## Licença

Distribuído sob a licença **MIT**.
