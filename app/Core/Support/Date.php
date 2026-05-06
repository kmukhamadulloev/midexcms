<?php

declare(strict_types=1);

namespace PlainCMS\Core\Support;

use DateTimeImmutable;

final class Date
{
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public static function nowTimestamp(): int
    {
        return self::now()->getTimestamp();
    }
}
