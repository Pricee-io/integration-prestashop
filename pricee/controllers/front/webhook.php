<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use PriceeIO\Webhook\WebhookRejectedException;
use PriceeIO\Webhook\WebhookService;

/**
 * Price-update webhook endpoint: the logic lives in WebhookService, this
 * controller only reads the request and answers.
 */
class PriceeWebhookModuleFrontController extends ModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $body = (string) file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');

        try {
            (new WebhookService())->handle($body, $signature, (int) $this->context->shop->id);
        } catch (WebhookRejectedException $e) {
            $this->sendResponse($e->getCode(), false, $e->getMessage());
        }

        $this->sendResponse(200, true, 'Webhook received and validated');
    }

    /**
     * The webhook is a server-to-server call: it must keep working while the
     * shop is in maintenance mode.
     */
    protected function displayMaintenancePage() {}

    /**
     * The webhook is a server-to-server call: geolocation restrictions apply
     * to visitors, not to Pricee.
     */
    protected function displayRestrictedCountryPage() {}

    private function sendResponse(int $statusCode, bool $success, string $message): void
    {
        http_response_code($statusCode);
        echo json_encode(['success' => $success, 'message' => $message]);

        exit;
    }
}
