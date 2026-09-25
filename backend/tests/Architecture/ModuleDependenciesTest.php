<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ADR-018 / ARCHITECTURE.md §2.3: a module may only reference another module
 * listed in config/modules.php (the single source of the dependency graph),
 * and only its public namespaces. Shared must not reference any module.
 */
final class ModuleDependenciesTest extends TestCase
{
    private const string APP = __DIR__.'/../../app';

    /** @return array{dependencies: array<string, list<string>>, public_namespaces: list<string>} */
    private static function config(): array
    {
        return require __DIR__.'/../../config/modules.php';
    }

    public function test_every_module_directory_is_declared_and_has_a_provider(): void
    {
        $declared = array_keys(self::config()['dependencies']);
        $directories = array_map('basename', glob(self::APP.'/Modules/*', GLOB_ONLYDIR) ?: []);
        sort($declared);
        sort($directories);

        self::assertSame($declared, $directories);
        $providers = (string) file_get_contents(__DIR__.'/../../bootstrap/providers.php');
        foreach ($directories as $module) {
            self::assertFileExists(self::APP."/Modules/{$module}/Providers/{$module}ServiceProvider.php");
            self::assertStringContainsString("{$module}ServiceProvider::class", $providers);
        }
    }

    public function test_dependency_graph_is_acyclic(): void
    {
        $graph = self::config()['dependencies'];
        $state = [];
        $visit = function (string $node, array $path) use (&$visit, &$state, $graph): void {
            if (($state[$node] ?? null) === 'done') {
                return;
            }
            self::assertNotSame('visiting', $state[$node] ?? null, 'Cycle: '.implode(' -> ', [...$path, $node]));
            $state[$node] = 'visiting';
            foreach ($graph[$node] ?? [] as $next) {
                self::assertArrayHasKey($next, $graph, "Unknown module {$next}");
                $visit($next, [...$path, $node]);
            }
            $state[$node] = 'done';
        };

        foreach (array_keys($graph) as $module) {
            $visit($module, []);
        }
    }

    public function test_modules_only_import_allowed_modules_and_public_namespaces(): void
    {
        $config = self::config();
        $violations = [];

        foreach ($this->phpFiles(self::APP.'/Modules') as $file) {
            $relative = substr($file, strlen(realpath(self::APP).'/Modules/'));
            $module = explode('/', $relative)[0];
            $allowed = $config['dependencies'][$module] ?? [];

            foreach ($this->referencedModuleClasses($file) as [$target, $namespace, $class]) {
                if ($target === $module) {
                    continue;
                }
                if (! in_array($target, $allowed, true)) {
                    $violations[] = "{$relative}: {$module} must not depend on {$target} ({$class})";
                } elseif (! in_array($namespace, $config['public_namespaces'], true)) {
                    $violations[] = "{$relative}: {$class} is not in a public namespace of {$target}";
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_shared_kernel_does_not_depend_on_modules(): void
    {
        $violations = [];
        foreach ($this->phpFiles(self::APP.'/Shared') as $file) {
            foreach ($this->referencedModuleClasses($file) as [, , $class]) {
                $violations[] = basename($file).": {$class}";
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_models_declare_explicit_fillable_and_never_unguard(): void
    {
        foreach (glob(self::APP.'/Modules/*/Models/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            self::assertStringContainsString('protected $fillable', $code, basename($file));
            self::assertDoesNotMatchRegularExpression('/\$guarded\s*=\s*\[\s*\]/', $code, basename($file));
            self::assertStringNotContainsString('unguard(', $code, basename($file));
        }
    }

    /** @return list<array{string, string, string}> [module, sub-namespace, class] */
    private function referencedModuleClasses(string $file): array
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue; // references in comments are documentation, not dependencies
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        preg_match_all('/\\\\?App\\\\Modules\\\\(\w+)\\\\(\w+)((?:\\\\\w+)*)/', $code, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [$m[1], $m[2], ltrim($m[0], '\\')], $matches);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath($directory) ?: $directory)) as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
