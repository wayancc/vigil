<?php

declare(strict_types=1);

namespace Vigil\Notify;

use Vigil\Settings;

/**
 * Alert channel types: the fields each one needs, and how to deliver to it.
 */
final class Channels
{
    /** type => [label, fields: name => [label, placeholder, required]] */
    public const TYPES = [
        'email' => ['Email', [
            'to' => ['Send to', 'ops@example.com, alice@example.com', true],
        ]],
        'telegram' => ['Telegram', [
            'bot_token' => ['Bot token', '123456:ABC-DEF… (from @BotFather)', true],
            'chat_id'   => ['Chat ID', '-1001234567890', true],
        ]],
        'slack' => ['Slack', [
            'webhook_url' => ['Incoming webhook URL', 'https://hooks.slack.com/services/…', true],
        ]],
        'discord' => ['Discord', [
            'webhook_url' => ['Webhook URL', 'https://discord.com/api/webhooks/…', true],
        ]],
        'ntfy' => ['ntfy (push to phone)', [
            'server' => ['Server', 'https://ntfy.sh', false],
            'topic'  => ['Topic', 'my-servers-a8f3', true],
            'token'  => ['Access token (optional)', 'tk_…', false],
        ]],
        'webhook' => ['Webhook (JSON)', [
            'url'    => ['URL', 'https://example.com/hooks/vigil', true],
            'secret' => ['Signing secret (optional)', 'Signs the body as X-Wyn-Signature', false],
        ]],
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array<string, string> field => error */
    public static function validate(string $type, array $config): array
    {
        $errors = [];
        foreach (self::TYPES[$type][1] ?? [] as $field => [$label, , $required]) {
            $value = trim((string) ($config[$field] ?? ''));
            if ($required && $value === '') {
                $errors[$field] = "Enter the {$label}.";
            } elseif ($value !== '' && (str_ends_with($field, 'url') || $field === 'server') && !preg_match('#^https?://#i', $value)) {
                $errors[$field] = 'Enter a full URL starting with https://';
            }
        }
        if ($type === 'email' && !$errors) {
            foreach (self::emails((string) $config['to']) as $address) {
                if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $errors['to'] = "“{$address}” isn’t a valid email address.";
                }
            }
        }

        return $errors;
    }

    /** Deliver one alert. Throws with a readable message when delivery fails. */
    public function send(string $type, array $config, Alert $alert): void
    {
        match ($type) {
            'email'    => $this->email($config, $alert),
            'telegram' => HttpPost::json(
                'https://api.telegram.org/bot' . rawurlencode(trim($config['bot_token'])) . '/sendMessage',
                ['chat_id' => trim($config['chat_id']), 'text' => $alert->text(), 'disable_web_page_preview' => true],
            ),
            'slack'    => HttpPost::json($config['webhook_url'], ['text' => $alert->text()]),
            'discord'  => HttpPost::json($config['webhook_url'], ['content' => mb_substr($alert->text(), 0, 1900)]),
            'ntfy'     => HttpPost::send(
                rtrim(trim($config['server'] ?? '') ?: 'https://ntfy.sh', '/') . '/' . rawurlencode(trim($config['topic'])),
                $alert->message,
                array_filter([
                    'Title: ' . self::header($alert->title),
                    'Tags: ' . match ($alert->event) { 'down' => 'red_circle', 'up' => 'green_circle', 'ssl' => 'warning', default => 'bell' },
                    'Priority: ' . ($alert->event === 'down' ? 'high' : 'default'),
                    $alert->link ? 'Click: ' . $alert->link : null,
                    !empty($config['token']) ? 'Authorization: Bearer ' . trim($config['token']) : null,
                    'Content-Type: text/plain; charset=utf-8',
                ]),
            ),
            'webhook'  => $this->webhook($config, $alert),
            default    => throw new \RuntimeException("Unknown channel type: {$type}"),
        };
    }

    private function webhook(array $config, Alert $alert): void
    {
        $body = json_encode($alert->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json'];
        if (!empty($config['secret'])) {
            $headers[] = 'X-Wyn-Signature: sha256=' . hash_hmac('sha256', $body, (string) $config['secret']);
        }
        HttpPost::send($config['url'], $body, $headers);
    }

    private function email(array $config, Alert $alert): void
    {
        $to = self::emails((string) $config['to']);
        $subject = $alert->emoji() . ' ' . $alert->title;
        $body = $alert->message . ($alert->link ? "\n\n" . $alert->link : '') . "\n\n— Sent by Vigil";
        $from = $this->settings->get('smtp_from') ?: $this->settings->get('smtp_user') ?: 'vigil@' . (gethostname() ?: 'localhost');

        if ($this->settings->get('smtp_host') !== '') {
            (new Smtp(
                host: $this->settings->get('smtp_host'),
                port: (int) $this->settings->get('smtp_port'),
                encryption: $this->settings->get('smtp_encryption'),
                username: $this->settings->get('smtp_user'),
                password: $this->settings->get('smtp_pass'),
            ))->send($from, $to, $subject, $body);

            return;
        }

        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
        if (!@mail(implode(', ', $to), '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers)) {
            throw new \RuntimeException('PHP mail() failed. Add SMTP settings under Settings → Email.');
        }
    }

    /** @return list<string> */
    private static function emails(string $list): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $list) ?: [])));
    }

    private static function header(string $value): string
    {
        // ntfy accepts UTF-8 titles when RFC 2047 encoded.
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }
}
