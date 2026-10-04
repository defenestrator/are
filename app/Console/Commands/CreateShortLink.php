<?php

namespace App\Console\Commands;

use App\Models\ShortLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateShortLink extends Command
{
    /**
     * Usage: php artisan short-link:create /about#work-with-us --campaign=2026-10-04-orkestera [--source=twitch] [--medium=stream] [--content=chat] [--code=ork]
     */
    protected $signature = 'short-link:create
        {destination : Path (e.g. /about#work-with-us) or absolute URL to send viewers to}
        {--campaign= : utm_campaign, naming the stream (required)}
        {--source=twitch : utm_source, the channel (twitch, youtube)}
        {--medium=stream : utm_medium, the surface (stream, clip, vod)}
        {--content= : utm_content, where the link appeared (overlay, chat)}
        {--code= : A memorable code instead of a random one}';

    protected $description = 'Creates (or finds) a UTM-tagged short link served at /go/{code}.';

    public function handle(): int
    {
        $input = [
            'destination' => $this->argument('destination'),
            'campaign' => $this->option('campaign'),
            'source' => $this->option('source'),
            'medium' => $this->option('medium'),
            'content' => $this->option('content'),
            'code' => $this->option('code'),
        ];

        $validator = Validator::make($input, [
            'destination' => ['required', 'string', 'max:2048', 'regex:#^(/|https?://)#'],
            'campaign' => ['required', 'string', 'max:150'],
            'source' => ['required', 'string', 'max:100'],
            'medium' => ['required', 'string', 'max:100'],
            'content' => ['nullable', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:short_links,code'],
        ], [
            'destination.regex' => 'The destination must be a path starting with / or an http(s) URL.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $link = $input['code']
            ? ShortLink::create([
                'code' => $input['code'],
                'destination' => $input['destination'],
                'utm_source' => $input['source'],
                'utm_medium' => $input['medium'],
                'utm_campaign' => $input['campaign'],
                'utm_content' => $input['content'],
            ])
            : ShortLink::for($input['destination'], $input['source'], $input['medium'], $input['campaign'], $input['content']);

        $this->info("Short link: {$link->url()}");
        $this->line("Redirects to: {$link->destinationUrl()}");

        return self::SUCCESS;
    }
}
