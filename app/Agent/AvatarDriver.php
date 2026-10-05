<?php

namespace App\Agent;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Forwards an avatar expression to VTube Studio or Warudo (config
 * agent.avatar). Only expressions named in config are accepted; each maps to
 * the driver's own value (a VTube Studio hotkey id, a Warudo name).
 */
class AvatarDriver
{
    /**
     * @return list<string>
     */
    public static function expressions(): array
    {
        return array_keys((array) config('agent.avatar.expressions'));
    }

    /**
     * @throws AvatarUnavailable when the avatar app does not accept it
     */
    public function express(string $expression): void
    {
        $config = (array) config('agent.avatar');
        $value = (string) ($config['expressions'][$expression] ?? throw new AvatarUnavailable("Unknown expression {$expression}."));

        match ($config['driver'] ?? 'log') {
            'vtube_studio' => $this->post($config, [
                'apiName' => 'VTubeStudioPublicAPI',
                'apiVersion' => '1.0',
                'requestID' => (string) Str::uuid(),
                'messageType' => 'HotkeyTriggerRequest',
                'data' => ['hotkeyID' => $value],
            ]),
            'warudo' => $this->post($config, ['action' => 'expression', 'name' => $value]),
            default => Log::info('Agent expression (agent.avatar.driver is log).', ['expression' => $expression]),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function post(array $config, array $payload): void
    {
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            throw new AvatarUnavailable('agent.avatar.url is not set.');
        }

        $request = Http::timeout((int) ($config['timeout_seconds'] ?? 3))->acceptJson();
        if (($config['token'] ?? null) !== null) {
            $request = $request->withToken((string) $config['token']);
        }

        $response = $request->post($url, $payload);

        if (! $response->successful()) {
            throw new AvatarUnavailable('The avatar app answered '.$response->status().'.');
        }
    }
}
