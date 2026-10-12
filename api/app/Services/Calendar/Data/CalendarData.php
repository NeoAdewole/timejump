<?php

namespace App\Services\Calendar\Data;

final readonly class CalendarData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description = null,
        public ?string $color = null,
        public ?string $timezone = null,
        public bool $isPrimary = false,
        public bool $isReadOnly = true,
    ) {}
}
