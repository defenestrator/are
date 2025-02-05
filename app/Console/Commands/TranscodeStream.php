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
        // $uri = config(key: 'services.twitch.stream_url') . config(key: 'services.twitch.stream_key');
        Process::run('ffmpeg -f avfoundation -i ":0" -ac 2 -b:a 48k -f hls -hls_time 10 -hls_list_size 6 -segment_wrap 10 -start_number 1 public/output.m3u8');
    }
}
