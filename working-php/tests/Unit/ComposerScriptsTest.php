<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every test class has its own `composer run test:<name>` script, with a description, listed in the README.
 */
class ComposerScriptsTest extends TestCase
{
    // Suite folders whose test classes each get a script
    private const SUITE_DIRS = ['Unit', 'Workflow', 'Http', 'Security', 'Concurrency', 'Benchmark'];

    // Folders whose classes are excluded by group in phpunit.xml and must name their group
    private const GROUPED_DIRS = ['Concurrency' => 'concurrency', 'Benchmark' => 'benchmark'];

    // Decoded composer.json
    private static function composer(): array
    {
        return json_decode(file_get_contents(BASE_PATH . '/composer.json'), true);
    }

    /**
     * Expected script name => [test file path relative to working-php, required group or null].
     */
    private static function expectedScripts(): array
    {
        $expected = [];
        foreach (self::SUITE_DIRS as $dir) {
            // One script per *Test.php class file in the suite folder
            foreach (glob(BASE_PATH . "/tests/{$dir}/*Test.php") as $file) {
                $class = basename($file, '.php');
                $name = 'test:' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', substr($class, 0, -4)));
                $expected[$name] = ["tests/{$dir}/{$class}.php", self::GROUPED_DIRS[$dir] ?? null];
            }
        }

        return $expected;
    }

    // Each test class has a script that runs exactly its file (and its group where phpunit.xml excludes it)
    public function test_every_test_class_has_a_script(): void
    {
        $scripts = self::composer()['scripts'];

        // Check every expected script against composer.json
        foreach (self::expectedScripts() as $name => [$path, $group]) {
            $this->assertArrayHasKey($name, $scripts, "Missing composer script {$name} for {$path}");
            $this->assertStringContainsString($path, $scripts[$name], "{$name} does not run {$path}");

            // Concurrency and benchmark classes are excluded by default, so their script must name the group
            if ($group !== null) {
                $this->assertStringContainsString("--group {$group}", $scripts[$name], "{$name} must pass --group {$group}");
            }
        }
    }

    // Every test script has a one-line description for `composer list`
    public function test_every_test_script_has_a_description(): void
    {
        $composer = self::composer();
        $descriptions = $composer['scripts-descriptions'] ?? [];

        foreach (array_keys($composer['scripts']) as $name) {
            // Only the test:* scripts are required to carry a description
            if (!str_starts_with($name, 'test:')) {
                continue;
            }
            $this->assertNotEmpty($descriptions[$name] ?? '', "Missing scripts-descriptions entry for {$name}");
        }
    }

    // The README lists every test script
    public function test_readme_lists_every_test_script(): void
    {
        $readme = file_get_contents(dirname(BASE_PATH) . '/README.md');

        // Each script name must appear in backticks somewhere in the README
        foreach (array_keys(self::expectedScripts()) as $name) {
            $this->assertStringContainsString("`{$name}`", $readme, "README does not list {$name}");
        }
    }
}
