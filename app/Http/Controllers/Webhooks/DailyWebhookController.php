<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Telehealth\Daily\DailyWebhookSignature;
use App\Domain\Telehealth\Daily\HandleDailyWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/daily — outside sign-in, tenancy, sessions and CSRF (routes/webhooks.php). Signature first: the
 * raw body is verified before anything is parsed or stored; an unsigned, mis-signed or stale request (or any
 * request while no webhook secret is configured) is a bare 401. Then 200 for everything handled, ignored or
 * already processed, and 503 only when Daily should deliver again. Responses carry no body.
 */
final class DailyWebhookController extends Controller
{
    public const MAX_BYTES = 262144;

    public function __invoke(Request $request, DailyWebhookSignature $signature, HandleDailyWebhook $handle): Response
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return response('', 413);
        }
        if (! $signature->valid($body, $request->header('X-Webhook-Signature'), $request->header('X-Webhook-Timestamp'))) {
            return response('', 401);
        }

        $event = json_decode($body, true, 32);

        return response('', is_array($event) ? $handle($event)->httpStatus() : 200);
    }
}
