<?php

namespace ST_system\Tests\Unit;

use ST_system\Debug;
use ST_system\Tests\TestCase;

final class DebugTest extends TestCase {

    private const JSONL = ['output_type' => 'json', 'pre' => false, 'backtrace' => false, 'time' => false];

    private function log(string $dir, string $file, $content, array $config = []): string {
        Debug::toFile($content, $config + ['dir' => $dir, 'file' => $file]);

        return (string)file_get_contents($dir.'/'.$file);
    }

    public function testAppendIsDefaultAcrossProcesses(): void {
        $dir = $this->tmpDir('debug');
        file_put_contents("{$dir}/old.log", "earlier process\n");

        $content = $this->log($dir, 'old.log', ['a' => 1], self::JSONL);

        $this->assertSame("earlier process\n{\"a\":1}\n", $content);
    }

    public function testExplicitAppendFalseTruncatesOnFirstWrite(): void {
        $dir = $this->tmpDir('debug');
        file_put_contents("{$dir}/fresh.log", "earlier process\n");

        $this->assertSame("{\"a\":1}\n", $this->log($dir, 'fresh.log', ['a' => 1], ['append' => false] + self::JSONL));
        $this->assertSame("{\"a\":1}\n{\"b\":2}\n", $this->log($dir, 'fresh.log', ['b' => 2], self::JSONL));
    }

    public function testBacktraceModes(): void {
        $dir  = $this->tmpDir('debug');
        $base = ['output_type' => 'json', 'pre' => false, 'time' => false];

        $this->assertSame(1, substr_count($this->log($dir, 'none.log', 1, ['backtrace' => false] + $base), "\n"));
        $this->assertStringContainsString(' on line ', $this->log($dir, 'short.log', 1, $base));
        $this->assertStringContainsString('↘ ', $this->log($dir, 'full.log', 1, ['backtrace' => true] + $base));
    }

    public function testTimeLineIsOptional(): void {
        $dir = $this->tmpDir('debug');

        $lines = explode("\n", trim($this->log($dir, 'time.log', 1, ['time' => true] + self::JSONL)));

        $this->assertCount(2, $lines);
        $this->assertMatchesRegularExpression('/^\d\d-\d\d-\d{4} \d\d:\d\d:\d\d$/', $lines[0]);
        $this->assertSame('1', $lines[1]);
    }

    public function testJsonlRotationKeepsWholeLines(): void {
        $dir = $this->tmpDir('debug');

        for ($i = 0; $i < 50; $i++)
            $content = $this->log($dir, 'rot.log', ['i' => $i, 'pad' => str_repeat('x', 20)], ['max_size' => 400, 'keep' => 0.5] + self::JSONL);

        $this->assertLessThanOrEqual(400, strlen($content));

        foreach (explode("\n", rtrim($content, "\n")) as $line)
            $this->assertIsArray(json_decode($line, true), "broken line: {$line}");

        $this->assertStringEndsWith('{"i":49,"pad":"'.str_repeat('x', 20).'"}'."\n", $content);
    }
}
