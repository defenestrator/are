<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class TranscodeStream extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'twitch:transcode-stream';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transcodes rtmp stream to HLS format';

    /**
     * Execute the console command.
     */
    public function handle()
    {   
        $uri = config(key: 'services.twitch.stream_url') . config(key: 'services.twitch.stream_key');
        Process::run("ffmpeg -i $uri -c:v libx264 -crf 18 -preset veryfast -c:a aac -b:a 48k -f hls -hls_time 10 -hls_list_size 6 -hls_flags delete_segment -start_number 1 public/output.m3u8");
    }
}
