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
    /**
     * Answered before the front office starts: a server-to-server call needs
     * no visitor cookie, cart or statistics, and must work whatever the
     * maintenance mode or country restrictions.
     */
    public function init(): void
    {
        // A large call writes many products, each one running the shop's hooks
        @set_time_limit(300);

        $body = (string) file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');

        try {
            $outcomes = (new WebhookService())->handle($body, $signature, (int) $this->context->shop->id);
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Webhook received and validated',
                // How many entries were updated, unchanged or skipped, and why
                'outcomes' => array_count_values($outcomes),
            ]);
        } catch (WebhookRejectedException $e) {
            $this->sendResponse($e->getCode(), ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function sendResponse(int $statusCode, array $answer): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($answer);

        exit;
    }
}
