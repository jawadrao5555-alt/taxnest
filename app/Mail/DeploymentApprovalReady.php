<?php

namespace App\Mail;

use App\Models\AdminUser;
use App\Models\OwnerDeploymentApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class DeploymentApprovalReady extends Mailable
{
    use Queueable, SerializesModels;

    public string $reviewUrl;

    public function __construct(
        public OwnerDeploymentApprovalRequest $approval,
        public AdminUser $recipient
    ) {
        $this->reviewUrl = URL::temporarySignedRoute(
            'saas.admin.deployment-approval.review',
            $approval->expires_at,
            ['requestId' => $approval->request_id, 'approver' => $recipient->id]
        );
    }

    public function build(): self
    {
        return $this->subject('TaxNest deployment approval — PR #'.$this->approval->pull_request_number)
            ->view('emails.deployment-approval-ready');
    }
}
