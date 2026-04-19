<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushNotificationService
{
    protected ?WebPush $webPush = null;

    public function __construct()
    {
        $public  = config('services.vapid.public');
        $private = config('services.vapid.private');
        $subject = config('services.vapid.subject');

        if ($public && $private) {
            try {
                $this->webPush = new WebPush([
                    'VAPID' => [
                        'subject'    => $subject,
                        'publicKey'  => $public,
                        'privateKey' => $private,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('VAPID init failed: ' . $e->getMessage());
                $this->webPush = null;
            }
        }
    }

    public function isReady(): bool
    {
        return $this->webPush !== null;
    }

    public function sendToUser(int $userId, string $title, string $body, array $opts = []): int
    {
        return $this->dispatch(
            PushSubscription::where('user_id', $userId)->get(),
            $title, $body, $opts
        );
    }

    public function sendToCompany(int $companyId, string $title, string $body, array $opts = [], ?string $scope = null): int
    {
        $q = PushSubscription::where('company_id', $companyId);
        if ($scope) {
            $q->where('scope', $scope);
        }
        return $this->dispatch($q->get(), $title, $body, $opts);
    }

    public function sendToScope(string $scope, string $title, string $body, array $opts = []): int
    {
        return $this->dispatch(
            PushSubscription::where('scope', $scope)->get(),
            $title, $body, $opts
        );
    }

    protected function dispatch($subs, string $title, string $body, array $opts): int
    {
        if (!$this->webPush || $subs->isEmpty()) {
            return 0;
        }

        $payload = json_encode(array_merge([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/tax-di/icon-192.png',
            'badge' => '/icons/tax-di/icon-192.png',
            'tag'   => 'taxnest',
        ], $opts));

        $sent = 0;
        foreach ($subs as $sub) {
            if (!$sub->endpoint || !$sub->p256dh || !$sub->auth_key) {
                continue;
            }
            try {
                $subscription = Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'publicKey'       => $sub->p256dh,
                    'authToken'       => $sub->auth_key,
                    'contentEncoding' => 'aesgcm',
                ]);
                $this->webPush->queueNotification($subscription, $payload);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Push queue failed: ' . $e->getMessage());
            }
        }

        try {
            foreach ($this->webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }
                try {
                    $endpoint = $report->getRequest()->getUri()->__toString();
                    $response = $report->getResponse();
                    $code = ($response && method_exists($response, 'getStatusCode'))
                        ? (int) $response->getStatusCode()
                        : 0;
                    if (in_array($code, [404, 410], true)) {
                        PushSubscription::where('endpoint', $endpoint)->delete();
                    }
                } catch (\Throwable $inner) {
                    Log::warning('Push report parse failed: ' . $inner->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Push flush failed: ' . $e->getMessage());
        }

        return $sent;
    }
}
