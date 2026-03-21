<?php

namespace App\Mail;

use App\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DemoRequestConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public DemoRequest $demoRequest;

    public function __construct(DemoRequest $demoRequest)
    {
        $this->demoRequest = $demoRequest;
    }

    public function build()
    {
        return $this->subject('Demo Request confirmation.')
            ->view('emails.demo_request_confirmation');
    }
}