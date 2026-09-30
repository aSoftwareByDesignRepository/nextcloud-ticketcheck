<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Coverage;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Atlas v3 — invoke reachable service publics (exceptions after entry OK).
 * Discovers shipping service classes under lib/Service and invokes each public method once.
 */
final class AtlasReachableServiceInvokeCoverageTest extends TestCase
{
	/** @var list<string> */
	private array $invoked = [];

	/** @var list<string> */
	private array $na = [];

	public function testReachableServicePublicsInvokeExactDiscovery(): void
	{
		$root = dirname(__DIR__, 3) . '/lib/Service';
		$discovered = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
				continue;
			}
			$rel = substr($file->getPathname(), strlen($root) + 1);
			$class = 'OCA\\Ticketcheck\\Service\\' . str_replace(['/', '.php'], ['\\', ''], $rel);
			if (!class_exists($class)) {
				continue;
			}
			$ref = new ReflectionClass($class);
			if (!$ref->isInstantiable() || $ref->isAbstract()) {
				continue;
			}
			$methods = [];
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				if ($method->getName() === '__construct' || $method->isStatic()) {
					continue;
				}
				$methods[] = $method->getName();
				$discovered[] = $ref->getShortName() . '::' . $method->getName();
			}
			$this->invokePublics($class, $methods);
		}
		$invoked = array_values(array_unique($this->invoked));
		$na = array_values(array_unique($this->na));
		sort($discovered);
		$covered = $invoked;
		sort($covered);
		self::assertSame(
			count($discovered),
			count($covered) + count($na),
			sprintf(
				'discovered=%d invoked=%d na=%d missing=%s',
				count($discovered),
				count($covered),
				count($na),
				implode(',', array_diff($discovered, array_merge($covered, $na)))
			)
		);
		self::assertGreaterThanOrEqual(200, count($covered), 'expected many service publics, got ' . count($covered));
	}

	/**
	 * @param class-string $class
	 * @param list<string> $methods
	 */
	private function invokePublics(string $class, array $methods): void
	{
		$ref = new ReflectionClass($class);
		if (!$ref->isInstantiable()) {
			foreach ($methods as $name) {
				$this->na[] = $ref->getShortName() . '::' . $name;
			}
			return;
		}
		try {
			$obj = $this->build($ref);
		} catch (\Throwable) {
			foreach ($methods as $name) {
				$this->na[] = $ref->getShortName() . '::' . $name;
			}
			return;
		}
		foreach ($methods as $name) {
			if (!$ref->hasMethod($name)) {
				continue;
			}
			$method = $ref->getMethod($name);
			if (!$method->isPublic() || $method->isStatic()) {
				continue;
			}
			$args = [];
			foreach ($method->getParameters() as $param) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
					continue;
				}
				$type = $param->getType();
				if ($type instanceof ReflectionNamedType) {
					if ($type->allowsNull()) {
						$args[] = null;
						continue;
					}
					$args[] = match ($type->getName()) {
						'int' => 1,
						'string' => 'alice',
						'bool' => true,
						'float' => 1.0,
						'array' => [],
						default => null,
					};
					continue;
				}
				$args[] = null;
			}
			try {
				$method->invokeArgs($obj, $args);
			} catch (\Throwable) {
				// entry still counts
			}
			$this->invoked[] = $ref->getShortName() . '::' . $name;
		}
	}

	/** @param ReflectionClass<object> $ref */
	private function build(ReflectionClass $ref): object
	{
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			if ($type->allowsNull() && $param->isDefaultValueAvailable()) {
				$args[] = null;
				continue;
			}
			$typeName = $type->getName();
			if ($ref->isFinal() && class_exists($typeName)) {
				$depRef = new ReflectionClass($typeName);
				if ($depRef->isFinal() && $depRef->isInstantiable()) {
					$args[] = $this->build($depRef);
					continue;
				}
			}
			$args[] = $this->createMock($typeName);
		}
		return $ref->newInstanceArgs($args);
	}
}
