<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Diagnostics\EncryptionPlanValidator;
use BlackCat\DatabaseCrypto\Diagnostics\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

final class EncryptionPlanValidatorTest extends TestCase
{
    public function testValidateOkWithManifestAndSchema(): void
    {
        $map = EncryptionMap::fromFile(__DIR__ . '/fixtures/encryption-map.json');
        $schema = SchemaSnapshot::fromTables([
            'users' => ['id', 'ssn', 'email_hash', 'notes'],
        ]);

        $report = EncryptionPlanValidator::validate(
            $map,
            ['core.vault', 'core.hmac.email'],
            $schema
        );

        self::assertFalse($report['hasWarnings']);
        self::assertSame(['ok'], $report['tables']['users']['status']);
        self::assertSame(['ok'], $report['tables']['users']['columns']['ssn']['status']);
        self::assertSame(['ok'], $report['tables']['users']['columns']['email_hash']['status']);
        self::assertSame(['ok'], $report['tables']['users']['columns']['notes']['status']);
    }

    public function testValidateDetectsUnknownContextAndMissingColumnInSchema(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'unknown.ctx'],
                    ],
                ],
            ],
        ]);

        $schema = SchemaSnapshot::fromTables([
            'users' => ['id'],
        ]);

        $report = EncryptionPlanValidator::validate(
            $map,
            ['core.vault'],
            $schema
        );

        self::assertTrue($report['hasWarnings']);
        self::assertSame(['ok'], $report['tables']['users']['status']);
        self::assertSame(
            ['missing-column-in-schema', 'unknown-context'],
            $report['tables']['users']['columns']['ssn']['status']
        );
    }

    public function testValidateDetectsMissingTableInSchema(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
                    ],
                ],
            ],
        ]);

        $schema = SchemaSnapshot::fromTables([
            'other' => ['id'],
        ]);

        $report = EncryptionPlanValidator::validate(
            $map,
            ['core.vault'],
            $schema
        );

        self::assertTrue($report['hasWarnings']);
        self::assertSame(['missing-table-in-schema'], $report['tables']['users']['status']);
        self::assertSame(
            ['missing-table-in-schema'],
            $report['tables']['users']['columns']['ssn']['status']
        );
    }
}

