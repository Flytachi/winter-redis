<?php

declare(strict_types=1);

namespace Flytachi\Winter\Redis\Tests\Unit;

use Psr\Log\AbstractLogger;
use Stringable;

/** Keeps every record, so a test can assert on level and message. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /** @return list<string> */
    public function messagesAt(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }
        return $messages;
    }
}
