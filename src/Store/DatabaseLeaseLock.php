<?php

namespace Plugins\Jw\PowerCache\Store;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\DB;

final class DatabaseLeaseLock extends Lock
{
    public function acquire(): bool
    {
        return DB::transaction(function (): bool {
            $now = time();
            $key = $this->stateKey();

            DB::table('jw_power_cache_state')->insertOrIgnore([
                'state_key' => $key,
                'state_value' => $this->encode('', 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $raw = DB::table('jw_power_cache_state')
                ->where('state_key', $key)
                ->lockForUpdate()
                ->value('state_value');
            $state = $this->decode($raw);
            if ($state['owner'] !== '' && $state['expires_at'] > $now && $state['owner'] !== $this->owner) {
                return false;
            }

            DB::table('jw_power_cache_state')
                ->where('state_key', $key)
                ->update([
                    'state_value' => $this->encode($this->owner, $now + max(1, (int) $this->seconds)),
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    public function release(): bool
    {
        return DB::transaction(function (): bool {
            $query = DB::table('jw_power_cache_state')->where('state_key', $this->stateKey());
            $state = $this->decode($query->lockForUpdate()->value('state_value'));
            if ($state['owner'] === '' || ! hash_equals($state['owner'], (string) $this->owner)) {
                return false;
            }

            return $query->delete() === 1;
        });
    }

    protected function getCurrentOwner(): string
    {
        $state = $this->decode(DB::table('jw_power_cache_state')
            ->where('state_key', $this->stateKey())
            ->value('state_value'));

        return $state['owner'];
    }

    public function forceRelease(): void
    {
        DB::table('jw_power_cache_state')->where('state_key', $this->stateKey())->delete();
    }

    private function stateKey(): string
    {
        return 'lock:'.substr(hash('sha256', (string) $this->name), 0, 59);
    }

    private function encode(string $owner, int $expiresAt): string
    {
        return json_encode([
            'owner' => $owner,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{owner:string,expires_at:int} */
    private function decode(mixed $value): array
    {
        if (! is_string($value)) {
            return ['owner' => '', 'expires_at' => 0];
        }

        try {
            $state = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['owner' => '', 'expires_at' => 0];
        }

        return [
            'owner' => is_string($state['owner'] ?? null) ? $state['owner'] : '',
            'expires_at' => is_numeric($state['expires_at'] ?? null) ? (int) $state['expires_at'] : 0,
        ];
    }
}
