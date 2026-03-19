<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class EmailOtpMail extends Mailable
{
    public function __construct(
        public string $code,
        public int $minutes
    ) {}

    public function build()
    {
        return $this->subject('Your verification code')
            ->view('emails.otp', [
                'code' => $this->code,
                'minutes' => $this->minutes,
            ]);
    }
}
