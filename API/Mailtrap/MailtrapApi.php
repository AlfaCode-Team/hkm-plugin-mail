<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap;

use Plugins\Mail\API\Contracts\MailtrapApiContract;

/**
 * The management API, assembled over one {@see MailtrapHttp}.
 *
 * A thin factory on purpose: it holds no state beyond the HTTP client, so the
 * resource clients it returns are cheap to make and safe to discard. Nothing is
 * memoised — a client is a path plus an id, and caching them would only add a
 * way for one request's account id to reach another's under OpenSwoole.
 */
final class MailtrapApi implements MailtrapApiContract
{
    public function __construct(private readonly MailtrapHttp $http) {}

    // ── general ──────────────────────────────────────────────────────────────

    public function accounts(): General\Account
    {
        return new General\Account($this->http);
    }

    public function apiTokens(int $accountId): General\ApiToken
    {
        return new General\ApiToken($this->http, $accountId);
    }

    public function billing(int $accountId): General\Billing
    {
        return new General\Billing($this->http, $accountId);
    }

    public function contacts(int $accountId): General\Contact
    {
        return new General\Contact($this->http, $accountId);
    }

    public function emailCampaigns(int $accountId): General\EmailCampaign
    {
        return new General\EmailCampaign($this->http, $accountId);
    }

    public function emailTemplates(int $accountId): General\EmailTemplate
    {
        return new General\EmailTemplate($this->http, $accountId);
    }

    public function organizations(int $organizationId): General\Organization
    {
        return new General\Organization($this->http, $organizationId);
    }

    public function permissions(int $accountId): General\Permission
    {
        return new General\Permission($this->http, $accountId);
    }

    public function subAccounts(int $organizationId): General\SubAccount
    {
        return new General\SubAccount($this->http, $organizationId);
    }

    public function users(int $accountId): General\User
    {
        return new General\User($this->http, $accountId);
    }

    // ── sending ──────────────────────────────────────────────────────────────

    public function sendingDomains(int $accountId): Sending\Domain
    {
        return new Sending\Domain($this->http, $accountId);
    }

    public function companyInfo(int $domainId): Sending\CompanyInfo
    {
        return new Sending\CompanyInfo($this->http, $domainId);
    }

    public function emailLogs(int $accountId): Sending\EmailLogs
    {
        return new Sending\EmailLogs($this->http, $accountId);
    }

    public function stats(int $accountId): Sending\Stats
    {
        return new Sending\Stats($this->http, $accountId);
    }

    public function suppressions(int $accountId): Sending\Suppression
    {
        return new Sending\Suppression($this->http, $accountId);
    }

    public function trackingOptOuts(): Sending\TrackingOptOut
    {
        return new Sending\TrackingOptOut($this->http);
    }

    public function webhooks(int $accountId): Sending\Webhook
    {
        return new Sending\Webhook($this->http, $accountId);
    }

    // ── sandbox ──────────────────────────────────────────────────────────────

    public function sandboxProjects(int $accountId): Sandbox\Project
    {
        return new Sandbox\Project($this->http, $accountId);
    }

    public function sandboxInboxes(int $accountId): Sandbox\Inbox
    {
        return new Sandbox\Inbox($this->http, $accountId);
    }

    public function sandboxMessages(int $accountId, int $inboxId): Sandbox\Message
    {
        return new Sandbox\Message($this->http, $accountId, $inboxId);
    }

    public function sandboxAttachments(int $accountId, int $inboxId): Sandbox\Attachment
    {
        return new Sandbox\Attachment($this->http, $accountId, $inboxId);
    }

    // ── inbound ──────────────────────────────────────────────────────────────

    public function inboundFolders(): Inbound\Folder
    {
        return new Inbound\Folder($this->http);
    }

    public function inboundInboxes(int $folderId): Inbound\Inbox
    {
        return new Inbound\Inbox($this->http, $folderId);
    }

    public function inboundMessages(int $inboxId): Inbound\Message
    {
        return new Inbound\Message($this->http, $inboxId);
    }

    public function inboundThreads(int $inboxId): Inbound\Thread
    {
        return new Inbound\Thread($this->http, $inboxId);
    }
}
