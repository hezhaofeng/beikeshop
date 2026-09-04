<?php

namespace Plugin\CustomMail\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomTemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly string $subjectLine, private readonly string $htmlBody)
    {
    }

    public function build(): static
    {
        return $this->subject($this->subjectLine)
            ->view('CustomMail::mail', ['body' => $this->htmlBody]);
    }
}
