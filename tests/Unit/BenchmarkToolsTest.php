<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BenchmarkToolsTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/jwpc-benchmark-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0775, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_comparison_requires_matching_response_checksums(): void
    {
        $bypass = $this->writeResult('bypass.json', 100.0, 100.0, 'same');
        $active = $this->writeResult('active.json', 70.0, 130.0, 'same');

        [$status, $result] = $this->runTool('compare-benchmarks.php', [$bypass, $active]);

        self::assertSame(0, $status);
        self::assertTrue($result['pass']);
        self::assertTrue($result['checks']['response_checksum']);

        $different = $this->writeResult('different.json', 70.0, 130.0, 'different');
        [$status, $result] = $this->runTool('compare-benchmarks.php', [$bypass, $different]);

        self::assertSame(1, $status);
        self::assertFalse($result['pass']);
        self::assertFalse($result['checks']['response_checksum']);
    }

    public function test_matrix_uses_three_run_median_instead_of_one_outlier(): void
    {
        foreach ([1 => [100.0, 130.0], 2 => [100.0, 130.0], 3 => [100.0, 105.0]] as $run => [$bypassRps, $activeRps]) {
            $this->writeResult("run-{$run}-bypass-c4.json", 100.0, $bypassRps, 'same');
            $activeP95 = $run === 3 ? 95.0 : 70.0;
            $this->writeResult("run-{$run}-active-c4.json", $activeP95, $activeRps, 'same');
        }

        [$status, $result] = $this->runTool('summarize-benchmark-matrix.php', [$this->temporaryDirectory]);

        self::assertSame(0, $status);
        self::assertTrue($result['pass']);
        self::assertSame(30, $result['results']['4']['p95_improvement_percent']);
        self::assertSame(30, $result['results']['4']['throughput_improvement_percent']);
        self::assertTrue($result['results']['4']['checks']['response_checksum']);
    }

    public function test_fault_campaign_requires_explicit_isolation_acknowledgement(): void
    {
        $previous = getenv('JWPC_BENCH_ISOLATED');
        putenv('JWPC_BENCH_ISOLATED');

        try {
            $command = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(dirname(__DIR__, 2).'/tool/run-fault-campaign.php').' 2>&1';
            exec($command, $output, $status);
        } finally {
            $previous === false
                ? putenv('JWPC_BENCH_ISOLATED')
                : putenv('JWPC_BENCH_ISOLATED='.$previous);
        }

        self::assertSame(2, $status);
        self::assertStringContainsString('JWPC_BENCH_ISOLATED=1 is required', implode("\n", $output));
    }

    public function test_remote_matrix_rejects_unsafe_ssh_target_before_connecting(): void
    {
        $command = escapeshellarg(dirname(__DIR__, 2).'/tool/run-remote-benchmark-matrix.sh').' '
            .escapeshellarg('host;touch /tmp/unsafe').' '
            .escapeshellarg('/srv/g7').' '
            .escapeshellarg('https://example.com').' 2>&1';
        exec($command, $output, $status);

        self::assertSame(2, $status);
        self::assertStringContainsString('Unsafe SSH target', implode("\n", $output));
    }

    private function writeResult(string $filename, float $p95, float $requestsPerSecond, string $checksum): string
    {
        $metrics = [
            'p95_ms' => $p95,
            'requests_per_second' => $requestsPerSecond,
            'error_rate' => 0.0,
            'body_checksum_counts' => [hash('sha256', $checksum) => 100],
        ];
        $document = [
            'schema_version' => 2,
            'target_requests_per_second' => null,
            'summary' => $metrics,
            'per_route' => ['board' => $metrics],
        ];
        $path = $this->temporaryDirectory.'/'.$filename;
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array{int,array<string,mixed>} */
    private function runTool(string $tool, array $arguments): array
    {
        $command = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg(dirname(__DIR__, 2).'/tool/'.$tool).' '
            .implode(' ', array_map('escapeshellarg', $arguments));
        exec($command, $output, $status);

        return [$status, json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR)];
    }
}
