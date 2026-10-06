<?php

namespace App\Services;

use App\Jobs\SendWhatsAppNotification;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class WhatsAppQueueInspector
{
    // Null means we cannot prove that a job is absent: recovery must fail closed.
    public function pendingNotificationIds(): ?array
    {
        try {
            $connection = config('queue.default');
            $queue = Queue::connection($connection);
            if ($queue instanceof DatabaseQueue) {
                $payloads = DB::connection(config("queue.connections.{$connection}.connection"))
                    ->table(config("queue.connections.{$connection}.table", 'jobs'))
                    ->whereIn('queue', ['whatsapp', 'default'])
                    ->where('payload', 'like', '%SendWhatsAppNotification%')
                    ->limit(1001)->pluck('payload')->all();
            } elseif ($queue instanceof RedisQueue) {
                $redis = $queue->getConnection();
                $payloads = [];
                foreach (['whatsapp', 'default'] as $name) {
                    $key = $queue->getQueue($redis->isCluster() ? '{'.$name.'}' : $name);
                    // Snapshot all three states atomically: a job moving from
                    // delayed to ready must never look like a missing job.
                    $snapshot = $redis->eval(<<<'LUA'
local count = redis.call('LLEN', KEYS[1]) + redis.call('ZCARD', KEYS[2]) + redis.call('ZCARD', KEYS[3])
if count > 1000 then return {0} end
local payloads = redis.call('LRANGE', KEYS[1], 0, -1)
for i = 2, 3 do
    for _, payload in ipairs(redis.call('ZRANGE', KEYS[i], 0, -1)) do
        payloads[#payloads + 1] = payload
    end
end
return payloads
LUA, 3, $key, $key.':delayed', $key.':reserved');
                    if (! is_array($snapshot) || $snapshot === [0]) {
                        return null;
                    }
                    $payloads = array_merge($payloads, $snapshot);
                }
            } else {
                return null;
            }
            if (count($payloads) > 1000) {
                return null;
            }
            $ids = [];
            foreach ($payloads as $payload) {
                $data = json_decode($payload, true);
                if (! is_array($data)) {
                    return null;
                }
                if (data_get($data, 'data.commandName') !== SendWhatsAppNotification::class) {
                    continue;
                }
                // Inspect only this known scalar property; never unserialize a queue payload.
                if (! preg_match('/s:14:"notificationId";i:(\d+);/', data_get($data, 'data.command', ''), $match)) {
                    return null;
                }
                $ids[] = (int) $match[1];
            }

            return array_values(array_unique($ids));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
