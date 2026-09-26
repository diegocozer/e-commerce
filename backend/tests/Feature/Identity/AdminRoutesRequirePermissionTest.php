<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/** SEC-AUTH-05 for the B-A modules: every admin.* route declares a permission (except auth and me). */
final class AdminRoutesRequirePermissionTest extends TestCase
{
    public function test_admin_routes_of_identity_customers_settings_audit_declare_a_permission(): void
    {
        $missing = [];
        $checked = 0;
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $name = (string) $route->getName();
            $action = (string) ($route->getAction('controller') ?? '');
            if (! str_starts_with($name, 'admin.') || preg_match('/Modules\\\\(Identity|Customers|Settings|Audit)\\\\/', $action) !== 1) {
                continue;
            }
            if (str_starts_with($name, 'admin.auth.') || str_starts_with($name, 'admin.me.')) {
                continue;
            }
            $checked++;
            $hasPermission = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:') && str_ends_with($m, ',admin'));
            if (! $hasPermission) {
                $missing[] = $name;
            }
        }

        self::assertGreaterThan(25, $checked);
        self::assertSame([], $missing);
    }
}
