<?php

declare(strict_types=1);

namespace Vigil\Notify;

/**
 * One thing worth telling people about: a monitor went down, came back, or its certificate is expiring.
 */
final readonly class Alert
{
    public function __construct(
        public string $event,       // down | up | ssl | test
        public string $title,       // one line, used as email subject and chat headline
        public string $message,     // plain-text details
        public ?array $monitor = null,
        public ?string $link = null,
        public int $time = 0,
        public array $extra = [],
    ) {
    }

    public function emoji(): string
    {
        return match ($this->event) {
            'down'  => '🔴',
            'up'    => '🟢',
            'ssl'   => '🟠',
            default => '🔔',
        };
    }

    public function text(): string
    {
        return $this->emoji() . ' ' . $this->title . "\n" . $this->message . ($this->link ? "\n" . $this->link : '');
    }

    /** Payload for generic webhooks. */
    public function toArray(): array
    {
        $m = $this->monitor;

        return [
            'event'   => $this->event,
            'title'   => $this->title,
            'message' => $this->message,
            'time'    => gmdate('c', $this->time ?: time()),
            'link'    => $this->link,
            'monitor' => $m === null ? null : [
                'id'     => (int) $m['id'],
                'name'   => $m['name'],
                'type'   => $m['type'],
                'target' => $m['url'] ?? ($m['host'] ? $m['host'] . ':' . $m['port'] : null),
                'status' => $m['status'] ?? null,
            ],
        ] + $this->extra;
    }
}
