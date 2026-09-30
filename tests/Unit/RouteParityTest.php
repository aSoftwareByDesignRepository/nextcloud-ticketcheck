<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RouteParityTest extends TestCase
{
    public function testEveryRouteTargetsExistingControllerMethod(): void
    {
        $routesConfig = require __DIR__ . '/../../appinfo/routes.php';
        $routes = $routesConfig['routes'] ?? [];
        self::assertIsArray($routes);

        $missingTargets = [];
        foreach ($routes as $route) {
            $name = (string)($route['name'] ?? '');
            if ($name === '' || !str_contains($name, '#')) {
                $missingTargets[] = $name === '' ? '(empty route name)' : $name;
                continue;
            }

            [$controllerToken, $method] = explode('#', $name, 2);
            $controllerClassName = ucfirst($controllerToken) . 'Controller';
            $controllerPath = __DIR__ . '/../../lib/Controller/' . $controllerClassName . '.php';

            if (!is_file($controllerPath)) {
                $missingTargets[] = $name . ' (missing controller file)';
                continue;
            }

            $controllerCode = file_get_contents($controllerPath);
            if ($controllerCode === false) {
                $missingTargets[] = $name . ' (controller unreadable)';
                continue;
            }

            $quotedMethod = preg_quote($method, '/');
            if (!preg_match('/function\s+' . $quotedMethod . '\s*\(/', $controllerCode)) {
                $missingTargets[] = $name . ' (missing method)';
            }
        }

        self::assertSame([], $missingTargets, 'Route targets missing: ' . implode(', ', $missingTargets));
    }
}
