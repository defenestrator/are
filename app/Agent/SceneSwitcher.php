<?php

namespace App\Agent;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cuts the stream to the intermission scene (config agent.obs). The kill
 * switch calls this; a failure is logged and reported, never thrown, because
 * the switch itself has already stopped everything by then.
 */
class SceneSwitcher
{
    /**
     * @return bool whether the scene was switched (or, for the log driver, logged)
     */
    public function cutToIntermission(): bool
    {
        $config = (array) config('agent.obs');
        $scene = (string) ($config['intermission_scene'] ?? 'Intermission');

        try {
            return match ($config['driver'] ?? 'log') {
                'obs_http' => $this->viaHttpBridge($config, $scene),
                default => $this->log($scene),
            };
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function log(string $scene): bool
    {
        Log::warning('Kill switch: cut to the intermission scene (agent.obs.driver is log, so OBS was not told).', ['scene' => $scene]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function viaHttpBridge(array $config, string $scene): bool
    {
        $url = rtrim((string) ($config['url'] ?? ''), '/');
        if ($url === '') {
            Log::error('Kill switch: agent.obs.url is not set, so OBS was not told to cut to intermission.');

            return false;
        }

        $request = Http::timeout((int) ($config['timeout_seconds'] ?? 2))->acceptJson();
        if (($config['token'] ?? null) !== null) {
            $request = $request->withHeaders(['Authorization' => (string) $config['token']]);
        }

        $response = $request->post($url.'/emit/SetCurrentProgramScene', ['sceneName' => $scene]);

        if (! $response->successful()) {
            Log::error('Kill switch: OBS did not switch to the intermission scene.', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}
