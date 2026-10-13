<?php

declare(strict_types=1);

// Standalone unit-test bootstrap. The root composer.json requires magento/* packages
// (repo.magento.com auth), so tests run against lightweight stubs in tests/Stubs.
// Tooling (PHPUnit + Mockery) lives in tests/tools/vendor: `composer install -d tests/tools`.
$rootAutoload = __DIR__ . '/../vendor/autoload.php';
require file_exists($rootAutoload) ? $rootAutoload : __DIR__ . '/tools/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'ArtLounge\\BentoCore\\' => __DIR__ . '/../src/BentoCore/',
        'ArtLounge\\BentoEvents\\' => __DIR__ . '/../src/BentoEvents/',
        'ArtLounge\\BentoTracking\\' => __DIR__ . '/../src/BentoTracking/',
        'Tests\\' => __DIR__ . '/',
        'Magento\\' => __DIR__ . '/Stubs/Magento/',
        'Razorpay\\' => __DIR__ . '/Stubs/Razorpay/',
        'Psr\\Log\\' => __DIR__ . '/Stubs/Psr/Log/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/Stubs/translation.php';
require __DIR__ . '/Stubs/Zend_Db_Expr.php';

\Mockery::globalHelpers();
