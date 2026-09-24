<?php

/**
 * Regression test for TheEventsCalendar::locateThemeTemplate().
 *
 * Exercises the fix for the WordPress 7.1.2 security release, which made
 * locate_template() reject theme-relative paths containing `..`. Acorn
 * relies on exactly that kind of path whenever a registered view directory
 * lives outside the active theme's own directory, which silently broke
 * Blade template overrides in that configuration. See:
 * @link https://discourse.roots.io/t/psa-wordpress-7-0-6-7-1-2-security-release-breaks-blade-views-that-live-outside-the-theme-directory/30428
 *
 * This script wires up the real Acorn ViewFinder/FileViewFinder classes
 * against a fixture theme/plugin on disk, stubbing only the handful of
 * WordPress functions the code path touches, so it can run without a full
 * WordPress install.
 *
 * Run with: php tests/TemplateResolutionTest.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Roots\Acorn\Filesystem\Filesystem;
use Roots\Acorn\Sage\ViewFinder;
use Roots\Acorn\View\FileViewFinder;
use Supermundano\Sage\TheEventsCalendar\TheEventsCalendar;

$fixtures = __DIR__ . '/fixtures';
$themeRoot = realpath("$fixtures/theme");
$themeViewsPath = "$themeRoot/resources/views";
$externalViewsPath = realpath("$fixtures/external-views");
$pluginTemplate = "$fixtures/plugin/src/views/v2/default-template.php";

function get_stylesheet_directory()
{
    global $themeRoot;
    return $themeRoot;
}

function get_template_directory()
{
    global $themeRoot;
    return $themeRoot;
}

define('TRIBE_EVENTS_FILE', "$fixtures/plugin/the-events-calendar.php");

$files = new Filesystem();
$app = new Container();

$failures = 0;

function assertResult(string $label, string $actual, string $expected): void
{
    global $failures;
    $pass = $actual === $expected;
    if (!$pass) {
        $failures++;
    }
    printf("[%s] %s\n    -> %s\n", $pass ? 'PASS' : 'FAIL', $label, $actual === '' ? '(empty)' : $actual);
}

function assertTrue(string $label, bool $pass, string $detail = ''): void
{
    global $failures;
    if (!$pass) {
        $failures++;
    }
    printf("[%s] %s%s\n", $pass ? 'PASS' : 'FAIL', $label, $detail !== '' ? "\n    -> {$detail}" : '');
}

// -----------------------------------------------------------------------
// Cases 1-3: the common Sage setup, where the registered view directory
// (resource_path('views')) lives INSIDE the theme. No `..` is ever
// involved here, so this configuration was never affected by the WP
// 7.1.2 change, but it must keep working.
// -----------------------------------------------------------------------
$fileFinder = new FileViewFinder($files, [$themeViewsPath]);
$sageFinder = new ViewFinder($fileFinder, $files, $themeRoot);
$tec = new TheEventsCalendar($sageFinder, $fileFinder, $app);

echo "== Case 1: plugin template WITH a theme override (views/ inside the theme) ==\n";
assertResult(
    'resolves to the theme Blade override',
    $tec->templateInclude($pluginTemplate),
    "$themeViewsPath/tribe/events/v2/default-template.blade.php"
);

echo "\n== Case 2: plugin template WITHOUT a theme override ==\n";
$pluginTemplateNoOverride = "$fixtures/plugin/src/views/v2/no-override.php";
assertResult(
    'falls back to the original plugin template',
    $tec->templateInclude($pluginTemplateNoOverride),
    $pluginTemplateNoOverride
);

echo "\n== Case 3: template that does not belong to TEC ==\n";
$unrelated = '/some/random/template.php';
assertResult(
    'is returned untouched',
    $tec->templateInclude($unrelated),
    $unrelated
);

// -----------------------------------------------------------------------
// Case 4: the actual bug being fixed. The registered view directory lives
// OUTSIDE the theme directory (e.g. a shared/whitelabel views root, or a
// package registering its own view location). This forces Acorn's
// ViewFinder to generate a candidate containing `..`, which
// locate_template() rejects on WP 7.1.2+. We assert both that such a
// candidate is genuinely produced (so this test isn't a no-op) and that
// it still resolves correctly now that locate_template() is bypassed.
// -----------------------------------------------------------------------
echo "\n== Case 4: theme override served from a views root OUTSIDE the theme directory ==\n";
$externalFileFinder = new FileViewFinder($files, [$externalViewsPath]);
$externalSageFinder = new ViewFinder($externalFileFinder, $files, $themeRoot);
$tecExternal = new TheEventsCalendar($externalSageFinder, $externalFileFinder, $app);

$candidates = $externalSageFinder->locate('tribe/events/v2' . str_replace($fixtures . '/plugin/src/views/v2', '', $pluginTemplate));
$hasTraversalCandidate = array_reduce($candidates, fn ($carry, $c) => $carry || str_contains($c, '..'), false);
assertTrue(
    'Acorn generates a `..`-containing candidate for this configuration (confirms the test reproduces the reported bug)',
    $hasTraversalCandidate,
    implode(', ', $candidates)
);

assertResult(
    'is still resolved correctly (this is exactly what locate_template() broke on WP 7.1.2+)',
    $tecExternal->templateInclude($pluginTemplate),
    "$externalViewsPath/tribe/events/v2/default-template.blade.php"
);

// -----------------------------------------------------------------------
// Case 5: security regression check. Now that locate_template() (and its
// own path-safety checks) is bypassed, the code must enforce containment
// itself: a candidate resolving outside every registered view path must
// be rejected, even though realpath() alone would happily resolve it.
// -----------------------------------------------------------------------
echo "\n== Case 5: path traversal outside any registered view path (security regression) ==\n";
class MaliciousViewFinder extends ViewFinder
{
    public function locate($file)
    {
        return ['../outside/secret.txt'];
    }
}
$maliciousFinder = new MaliciousViewFinder($fileFinder, $files, $themeRoot);
$tecMalicious = new TheEventsCalendar($maliciousFinder, $fileFinder, $app);

$secretPath = realpath("$fixtures/outside/secret.txt");
assertTrue(
    'the malicious candidate genuinely resolves to a real file via realpath() (confirms the containment check, not a missing file, is what blocks it)',
    realpath("$themeRoot/../outside/secret.txt") === $secretPath
);

assertResult(
    'is rejected by the containment check and falls back to the plugin template instead of leaking the file',
    $tecMalicious->templateInclude($pluginTemplate),
    $pluginTemplate
);

echo "\n";

if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s) did not pass.\n";
    exit(1);
}

echo "All assertions passed.\n";
exit(0);
