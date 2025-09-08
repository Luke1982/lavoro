<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestGraphMail extends Command
{
    protected $signature = 'mail:test-graph {to : Destination email address}';

    protected $description = 'Send a test email using the Graph transport';

    public function handle(): int
    {
        $to = $this->argument('to');
        Mail::raw('Test message via Microsoft Graph transport', function ($m) use ($to) {
            $m->to($to)->subject('Graph Transport Test');
        });
        $this->info('Dispatched test email to ' . $to);
        return self::SUCCESS;
    }
}
