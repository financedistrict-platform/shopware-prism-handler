<?php

declare(strict_types=1);

/**
 * Test bootstrap. The pure `Core` suite touches no Shopware/SDK code, so it must run with only
 * PHP + an autoloader — there is no `vendor/` here (the manifest requires `shopware/agentic-commerce`,
 * a sibling plugin that is not resolvable from Packagist, so `composer install` cannot run).
 *
 * If a `vendor/autoload.php` happens to exist (a dev who assembled one), use it; otherwise register
 * a minimal framework-free PSR-4 autoloader for `Fd\PrismPayment\` (src) and `Fd\PrismPayment\Tests\`
 * (tests). This is what lets the Core suite run in CI and in the container with just phpunit.phar.
 */
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Fd\\PrismPayment\\Tests\\' => __DIR__ . '/',
        'Fd\\PrismPayment\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, \strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
