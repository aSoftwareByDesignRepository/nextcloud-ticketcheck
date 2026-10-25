<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the PostgreSQL 22P02 bug class: an entity field
 * declared addType('x','boolean') makes QBMapper bind PARAM_BOOL, which the
 * pgsql driver sends as 't'/'f'. Against an INTEGER column (kb pinned,
 * allow_comments; projects.active historically) that is
 * "invalid input syntax for type integer". The inverse — a non-boolean entity
 * type on a real BOOLEAN column — fails the same way.
 *
 * This statically replays every migration's addColumn/dropColumn sequence and
 * asserts each entity field's boolean-ness equals its column's declared
 * boolean-ness. Runs without a database; the workspace gate
 * scripts/check-entity-schema-drift.php covers the same invariant family-wide.
 */
final class EntitySchemaTypeParityTest extends TestCase
{
    public function testEntityBooleanFieldsMatchDeclaredColumnFamilies(): void
    {
        $root = dirname(__DIR__, 2);
        $colFamilies = $this->declaredColumnFamilies($root . '/lib/Migration');
        $findings = [];

        foreach (glob($root . '/lib/Db/*Mapper.php') ?: [] as $mapperFile) {
            $src = (string)file_get_contents($mapperFile);
            if (strpos($src, 'QBMapper') === false) {
                continue;
            }
            if (!preg_match('/parent::__construct\(\s*\$\w+,\s*[\'"]([a-z0-9_]+)[\'"]\s*,\s*(\w+)::class/', $src, $m)
                && !preg_match('/parent::__construct\(\s*\$\w+,\s*self::TABLE\s*,\s*(\w+)::class/', $src, $m2)) {
                continue;
            }
            if (isset($m[1])) {
                $table = $m[1];
                $entityClass = $m[2];
            } else {
                if (!preg_match('/const\s+TABLE\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $tm)) {
                    continue;
                }
                $table = $tm[1];
                $entityClass = $m2[1];
            }

            $entityFile = $root . '/lib/Db/' . $entityClass . '.php';
            if (!is_file($entityFile)) {
                continue;
            }
            $esrc = (string)file_get_contents($entityFile);
            preg_match_all(
                '/addType\(\s*[\'"](\w+)[\'"]\s*,\s*([\'"][a-zA-Z_]+[\'"]|[\w\\\\]+::\w+)/',
                $esrc,
                $addTypes,
                PREG_SET_ORDER
            );
            foreach ($addTypes as $a) {
                [$prop, $entityFam] = [$a[1], self::typeFamily($a[2])];
                $col = self::snake($prop);
                $colFam = $colFamilies[$table][$col] ?? null;
                if ($entityFam === null || $colFam === null) {
                    continue;
                }
                if (($entityFam === 'boolean') !== ($colFam === 'boolean')) {
                    $findings[] = sprintf(
                        '%s::$%s (%s) -> %s.%s (%s)',
                        $entityClass,
                        $prop,
                        $entityFam,
                        $table,
                        $col,
                        $colFam
                    );
                }
            }
        }

        self::assertSame(
            [],
            $findings,
            "Entity addType() boolean-ness must match the column's declared type family "
            . "(PARAM_BOOL binds 't'/'f' — fatal on PostgreSQL against int columns):\n"
            . implode("\n", $findings)
        );
    }

    /**
     * Replays migrations in version order: createTable/getTable sets the table
     * context; addColumn records the type family (last declaration wins);
     * dropColumn removes it — mirroring scripts/check-entity-schema-drift.php.
     *
     * @return array<string, array<string, string>>
     */
    private function declaredColumnFamilies(string $migrationDir): array
    {
        $colTypes = [];
        $files = glob($migrationDir . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $src = (string)file_get_contents($file);
            $consts = [];
            preg_match_all('/const\s+(\w+)\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $cm, PREG_SET_ORDER);
            foreach ($cm as $c) {
                $consts[$c[1]] = $c[2];
            }

            $tokens = [];
            preg_match_all(
                '/(createTable|getTable)\(\s*([\'"][a-z0-9_]+[\'"]|self::\w+|\w+)\s*\)/',
                $src,
                $tm,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );
            foreach ($tm as $t) {
                $tokens[] = ['op' => 'table', 'arg' => $t[2][0], 'pos' => $t[0][1]];
            }
            preg_match_all(
                '/(addColumn|dropColumn)\(\s*([\'"][a-z0-9_]+[\'"]|self::\w+)\s*(?:,\s*([\'"][a-zA-Z_]+[\'"]|[\w\\\\]+::\w+))?/',
                $src,
                $cm2,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );
            foreach ($cm2 as $t) {
                $tokens[] = ['op' => strtolower($t[1][0]), 'arg' => $t[2][0], 'type' => $t[3][0] ?? null, 'pos' => $t[0][1]];
            }
            usort($tokens, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

            $current = null;
            foreach ($tokens as $t) {
                $name = self::resolveName($t['arg'], $consts);
                if ($t['op'] === 'table') {
                    $current = $name;
                    continue;
                }
                if ($name === null || $current === null) {
                    continue;
                }
                if ($t['op'] === 'addcolumn') {
                    $fam = self::typeFamily($t['type']);
                    if ($fam !== null) {
                        $colTypes[$current][$name] = $fam;
                    }
                } else {
                    unset($colTypes[$current][$name]);
                }
            }
        }
        return $colTypes;
    }

    private static function resolveName(string $arg, array $consts): ?string
    {
        $arg = trim($arg);
        if (preg_match('/^[\'"]([a-z0-9_]+)[\'"]$/', $arg, $m)) {
            return $m[1];
        }
        $arg = preg_replace('/^self::/', '', (string)$arg);
        return $consts[$arg] ?? null;
    }

    private static function typeFamily(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }
        $t = strtolower(trim($token));
        if (str_contains($t, '::')) {
            $t = substr($t, strrpos($t, ':') + 1);
        }
        $t = trim($t, "'\"_");
        return match (true) {
            $t === 'boolean', $t === 'bool' => 'boolean',
            in_array($t, ['integer', 'int', 'smallint', 'bigint'], true) => 'integer',
            in_array($t, ['string', 'text'], true) => 'string',
            str_starts_with($t, 'datetime'), in_array($t, ['date', 'time'], true) => 'datetime',
            $t === 'json' => 'json',
            in_array($t, ['float', 'decimal'], true) => 'float',
            in_array($t, ['blob', 'binary'], true) => 'binary',
            default => $t,
        };
    }

    private static function snake(string $camel): string
    {
        return strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
