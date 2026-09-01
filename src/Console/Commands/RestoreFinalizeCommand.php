<?php

namespace Plugins\Jw\PowerCache\Console\Commands;

use Illuminate\Console\Command;
use Plugins\Jw\PowerCache\Operations\RestoreFinalizer;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;

final class RestoreFinalizeCommand extends Command
{
    protected $signature = 'power-cache:restore-finalize
        {--yes : 복구 후 epoch 회전과 제어면 재구축 실행}
        {--json : JSON 형식으로 출력}';

    protected $description = 'DB·설정 복구 후 과거 캐시 재사용을 차단하고 제어면을 재구축합니다.';

    public function handle(PowerCacheSettings $settings, RestoreFinalizer $finalizer): int
    {
        if (! $this->option('yes')) {
            return $this->respond([
                'ok' => false,
                'error' => '--yes 없이 복구 제어면을 변경하지 않습니다.',
            ], self::INVALID);
        }

        if (! app()->isDownForMaintenance()) {
            return $this->respond([
                'ok' => false,
                'error' => '유지보수 모드에서만 복구를 마무리할 수 있습니다.',
            ], self::FAILURE);
        }

        if ($settings->mode() !== 'bypass') {
            return $this->respond([
                'ok' => false,
                'error' => '복구된 설정의 mode가 bypass일 때만 실행할 수 있습니다.',
            ], self::FAILURE);
        }

        $result = $finalizer->finalize();

        return $this->respond($result, $result['ok'] ? self::SUCCESS : self::FAILURE);
    }

    /** @param array<string, mixed> $result */
    private function respond(array $result, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return $exitCode;
        }

        if ($result['ok']) {
            $this->info(sprintf(
                'site=%s epoch=%s pending=%d',
                (string) $result['site_id'],
                (string) $result['runtime_epoch'],
                (int) $result['pending_outbox'],
            ));
        } else {
            $this->error((string) $result['error']);
        }

        return $exitCode;
    }
}
