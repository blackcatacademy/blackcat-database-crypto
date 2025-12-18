<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\Baz;

final class Definitions
{
    public static function table(): string { return 'baz'; }
    /** @return list<string> */
    public static function columns(): array { return ['id']; }
}

