<?php

namespace Mouseketeers\Messages\Channel;

use Mouseketeers\Messages\Message;
use SilverStripe\Core\Config\Configurable;

class PushMessageChannel implements MessageChannelInterface
{
    use Configurable;

    private static $firebase_project_id = '';

    private static $service_account_file = '';

    private static $notification_title = '';

    private static $notification_url_pattern = '/message/{id}';

    public function code(): string
    {
        return 'push_message';
    }

    public function label(): string
    {
        return 'Push Message';
    }

    public function canSend(Message $message): bool
    {
        $recipient = $message->Member();
        if (!$recipient->exists()) {
            return false;
        }

        if ($message->hasMethod('sendMessagePush')) {
            return true;
        }

        return $this->canSendViaFirebase($message);
    }

    public function send(Message $message): bool
    {
        $message->extend('beforeSendMessagePush');
        $results = (array) $message->extend('sendMessagePush', $message);

        foreach ($results as $result) {
            if ($result) {
                return true;
            }
        }

        return $this->sendViaFirebase($message);
    }

    protected function canSendViaFirebase(Message $message): bool
    {
        $recipient = $message->Member();
        if (!$recipient->exists() || !$recipient->hasField('PushNotificationToken')) {
            return false;
        }

        if (!trim((string) $recipient->PushNotificationToken)) {
            return false;
        }

        if (!$this->getServiceAccountFilePath()) {
            return false;
        }

        return trim((string) static::config()->get('firebase_project_id')) !== '';
    }

    protected function sendViaFirebase(Message $message): bool
    {
        if (!$this->canSendViaFirebase($message)) {
            return false;
        }

        $serviceAccountFile = $this->getServiceAccountFilePath();
        if (!$serviceAccountFile) {
            return false;
        }

        $accessToken = $this->getGoogleAccessToken($serviceAccountFile);
        if (!$accessToken) {
            return false;
        }

        $projectId = trim((string) static::config()->get('firebase_project_id'));
        $recipient = $message->Member();
        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
        $unreadMessages = Message::get()
            ->filter([
                'MemberID' => $recipient->ID,
                'IsRead' => false,
            ])
            ->count();

        $payload = json_encode([
            'message' => [
                'token' => $recipient->PushNotificationToken,
                'notification' => [
                    'title' => $this->getNotificationTitle($message),
                    'body' => $message->Title,
                ],
                'data' => [
                    'message_id' => (string) $message->ID,
                    'url' => $this->getNotificationUrl($message),
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'badge' => $unreadMessages,
                        ],
                    ],
                ],
            ],
        ]);

        if ($payload === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode >= 200 && $httpCode < 300;
    }

    protected function getNotificationUrl(Message $message): string
    {
        $pattern = trim((string) static::config()->get('notification_url_pattern'));
        if ($pattern === '') {
            $pattern = '/message/{id}';
        }

        return str_replace(['{id}', '{title}'], [(string) $message->ID, $message->Title], $pattern);
    }

    protected function getNotificationTitle(Message $message): string
    {
        $configuredTitle = trim((string) static::config()->get('notification_title'));
        if ($configuredTitle !== '') {
            return str_replace(['{id}', '{title}'], [(string) $message->ID, $message->Title], $configuredTitle);
        }

        return $message->Title;
    }

    protected function getServiceAccountFilePath(): ?string
    {
        $configuredPath = trim((string) static::config()->get('service_account_file'));
        if ($configuredPath === '') {
            return null;
        }

        $isAbsolutePath = (bool) preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $configuredPath);
        $path = $configuredPath;
        if (!$isAbsolutePath && defined('BASE_PATH')) {
            $path = rtrim((string) constant('BASE_PATH'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($configuredPath, DIRECTORY_SEPARATOR);
        }

        return is_readable($path) ? $path : null;
    }

    protected function getGoogleAccessToken(string $serviceAccountFile): ?string
    {
        $serviceAccountJson = @file_get_contents($serviceAccountFile);
        if ($serviceAccountJson === false) {
            return null;
        }

        $serviceAccount = json_decode($serviceAccountJson, true);
        if (!is_array($serviceAccount) || empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
            return null;
        }

        $jwtHeader = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $jwtClaimSet = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $base64UrlEncode = (static fn(array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '='));

        $header = $base64UrlEncode($jwtHeader);
        $claims = $base64UrlEncode($jwtClaimSet);
        $signed = openssl_sign($header . '.' . $claims, $signature, $serviceAccount['private_key'], 'SHA256');
        if (!$signed) {
            return null;
        }

        $jwt = $header . '.' . $claims . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $json = json_decode($result, true);
        return is_array($json) && !empty($json['access_token']) ? $json['access_token'] : null;
    }
}
