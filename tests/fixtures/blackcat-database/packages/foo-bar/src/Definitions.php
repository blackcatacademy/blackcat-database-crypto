<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\FooBar;

final class Definitions
{
    public static function table(): string { return 'foo_bar'; }
    /** @return list<string> */
    public static function columns(): array { return ['ID', 'Secret']; }
}

