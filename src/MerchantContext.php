<?php
declare(strict_types=1);
namespace Lunixi\Symfony;

use Lunixi\Sdk\Commerce\CheckoutService;
use Lunixi\Sdk\Webhook\WebhookEvent;
use Symfony\Component\HttpFoundation\Request;

final class MerchantContext
{
    public function __construct(private readonly string $merchantId, private readonly string $webhookSecret)
    {
        if (trim($merchantId) === '' || strlen($webhookSecret) < 16) { throw new \InvalidArgumentException('Merchant ID and a server-side webhook secret are required.'); }
    }
    public function merchantId(): string { return $this->merchantId; }
    public function verifyWebhook(Request $request, CheckoutService $checkout): WebhookEvent
    {
        if (!$request->isMethod('POST')) { throw new \InvalidArgumentException('Webhook accepts POST only.'); }
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            if (str_starts_with(strtolower($key), 'x-lunixi-')) {
                if (count($values) !== 1 || !is_string($values[0])) { throw new \InvalidArgumentException('Ambiguous webhook header.'); }
                $headers[$key] = $values[0];
            }
        }
        return $checkout->verifyWebhook($request->getContent(), $headers, $this->webhookSecret);
    }
    /** @return array<string,string> */
    public function __debugInfo(): array { return ['credentials' => '[REDACTED]']; }
}
