<?php

namespace App\Notifications;

use App\Models\ContributionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContributionAddedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ContributionBatch $batch,
        public array $allocations = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        $periods = collect($this->allocations)
            ->pluck('period_key')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');

        return (new MailMessage)
            ->subject('Monthly Contribution Added')
            ->greeting('Hello ' . ($notifiable->name ?? 'Member') . ',')
            ->line('Your contribution has been recorded successfully.')
            ->line('Amount: ' . number_format((float) $this->batch->total_amount) . ' RWF')
            ->line('Paid Date: ' . $this->batch->paid_date)
            ->when($periods, function ($mail) use ($periods) {
                return $mail->line('Covered Period(s): ' . $periods);
            })
            ->action('View My Contributions', $appUrl . '/member/contributions')
            ->line('Thank you.');
    }
}