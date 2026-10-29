<?php

declare(strict_types=1);

namespace Vigil\Notify;

/**
 * Minimal SMTP client: STARTTLS or implicit TLS, AUTH LOGIN, plain-text UTF-8 mail.
 * Enough for Gmail, Fastmail, Mailgun, SES, Postmark and most hosting providers.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',   // tls (STARTTLS) | ssl (implicit) | none
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeout = 15,
    ) {
    }

    /** @param list<string> $to */
    public function send(string $from, array $to, string $subject, string $body): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new \RuntimeException("Couldn’t connect to {$this->host}:{$this->port} ({$errstr})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect(220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->command($ehlo, 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                $this->command($ehlo, 250);
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode($this->password), 235, 'Login rejected. Check the SMTP username and password.');
            }

            $this->command('MAIL FROM:<' . self::address($from) . '>', 250);
            foreach ($to as $address) {
                $this->command('RCPT TO:<' . self::address($address) . '>', [250, 251]);
            }
            $this->command('DATA', 354);

            $headers = [
                'Date: ' . date('r'),
                'From: ' . $from,
                'To: ' . implode(', ', $to),
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', self::address($from))[1] ?? 'vigil') . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n.";
            $this->command($data, 250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function command(string $line, int|array $expect, ?string $failMessage = null): string
    {
        fwrite($this->socket, $line . "\r\n");

        return $this->expect($expect, $failMessage);
    }

    private function expect(int|array $codes, ?string $failMessage = null): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            throw new \RuntimeException($failMessage ?? ('SMTP error: ' . trim($response ?: 'no response')));
        }

        return $response;
    }

    private static function address(string $value): string
    {
        return preg_match('/<([^>]+)>/', $value, $m) ? $m[1] : trim($value);
    }
}
