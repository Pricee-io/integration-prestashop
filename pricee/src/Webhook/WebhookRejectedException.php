<?php

declare(strict_types=1);

namespace PriceeIO\Webhook;

/**
 * The whole webhook call is refused: nothing was applied. The code is the
 * HTTP status to answer with.
 */
class WebhookRejectedException extends \RuntimeException {}
