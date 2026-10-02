<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Tests\Core\Ucp;

use PHPUnit\Framework\TestCase;

final class LiteralGuardTest extends TestCase
{
    private const PATTERN = '~2026-01-23|2026-04-08|2026-08-25|ucp\.dev/\d{4}-\d{2}-\d{2}~';

    public function testProductionSourceHasNoUcpVersionLiterals(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (\is_string($contents) && 1 === preg_match(self::PATTERN, $contents, $match)) {
                $hits[] = $file->getPathname() . ': ' . $match[0];
            }
        }

        self::assertSame([], $hits);
    }
}
