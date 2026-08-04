<?php

declare(strict_types=1);

use AcapaPay\CodeIgniter\Controllers\WebhookController;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->post('webhooks/acapapay', [WebhookController::class, 'handle']);
