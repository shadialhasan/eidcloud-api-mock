<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/ApiMockTest.php';

use EidCloud\ApiMock\Tests\ApiMockTest;

echo "\n============================================================\n";
echo "   EidCloud API Mock Server & Sandbox Engine - Test Suite   \n";
echo "============================================================\n";

try {
    $tester = new ApiMockTest();
    $tester->runAll();
    echo "\n\033[1;32mALL TESTS COMPLETED SUCCESSFULLY!\033[0m\n\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n\033[1;31mTEST SUITE FAILED:\033[0m " . $e->getMessage() . "\n\n";
    exit(1);
}
