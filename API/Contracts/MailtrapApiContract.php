<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Contracts;

use Plugins\Mail\API\Mailtrap\General;
use Plugins\Mail\API\Mailtrap\Inbound;
use Plugins\Mail\API\Mailtrap\Sandbox;
use Plugins\Mail\API\Mailtrap\Sending;

/**
 * The Mailtrap MANAGEMENT API — everything the account has that is not the act
 * of sending a message.
 *
 * Delivery does not go through here. A message is sent with
 * {@see MailerContract} and `MAIL_TRANSPORT=mailtrap`; this is for the things
 * around it — verifying a sending domain, reading why a message bounced,
 * managing suppressions, driving a campaign, inspecting a sandbox inbox,
 * answering inbound mail.
 *
 * SCOPE: every method here is a resource CLIENT, not a call. Mailtrap's paths
 * are scoped to an account, an organization, a domain or an inbox, and the id
 * is captured once when the client is built rather than repeated on every
 * method. Start from {@see self::accounts()} when you do not know the id.
 *
 * ```php
 * $accountId = $mailtrap->accounts()->getList()[0]['id'];
 *
 * $mailtrap->sendingDomains($accountId)->getSendingDomains();
 * $mailtrap->suppressions($accountId)->getSuppressions('bounced@example.com');
 * $mailtrap->emailLogs($accountId)->getMessage($messageId);
 * ```
 *
 * REQUIRES an `HttpClientPort` in the request's dependency graph and a
 * configured `MAILTRAP_API_TOKEN`; resolving this contract without either
 * throws a {@see \Plugins\Mail\Domain\MailException} naming the fix.
 *
 * Every method returns decoded JSON as a PHP array. Faults — HTTP 4xx/5xx, a
 * body carrying `success: false` — are raised as `MailException`, so one
 * exception type covers delivery and management alike.
 */
interface MailtrapApiContract
{
    // ── general ──────────────────────────────────────────────────────────────

    /** Accounts this token can reach — where an account id comes from. */
    public function accounts(): General\Account;

    /** API tokens for an account. Create/reset return the value ONCE. */
    public function apiTokens(int $accountId): General\ApiToken;

    /** Current billing-cycle usage. */
    public function billing(int $accountId): General\Billing;

    /** Contacts, lists, fields, imports, events and exports. */
    public function contacts(int $accountId): General\Contact;

    /** Campaigns and their lifecycle (start, schedule, cancel, terminate, reset). */
    public function emailCampaigns(int $accountId): General\EmailCampaign;

    /** Stored templates — by numeric id here, by UUID when sending. */
    public function emailTemplates(int $accountId): General\EmailTemplate;

    /** An organization — the scope sub-accounts live in. */
    public function organizations(int $organizationId): General\Organization;

    /** Resource permissions for a user or token. */
    public function permissions(int $accountId): General\Permission;

    /** Sub-accounts within an organization. */
    public function subAccounts(int $organizationId): General\SubAccount;

    /** Account users, addressed by account-access id. */
    public function users(int $accountId): General\User;

    // ── sending (management, not delivery) ───────────────────────────────────

    /** Sending domains and their DNS verification. */
    public function sendingDomains(int $accountId): Sending\Domain;

    /** The physical sender identity on one sending DOMAIN. */
    public function companyInfo(int $domainId): Sending\CompanyInfo;

    /** What happened to sent mail — keyed by the ids a send returned. */
    public function emailLogs(int $accountId): Sending\EmailLogs;

    /** Aggregated statistics, grouped five ways. */
    public function stats(int $accountId): Sending\Stats;

    /** Addresses Mailtrap will not deliver to. */
    public function suppressions(int $accountId): Sending\Suppression;

    /** Addresses excluded from open/click tracking (still delivered). */
    public function trackingOptOuts(): Sending\TrackingOptOut;

    /** Webhook subscriptions — create returns the `signing_secret`. */
    public function webhooks(int $accountId): Sending\Webhook;

    // ── sandbox (email testing) ──────────────────────────────────────────────

    /** Sandbox projects. */
    public function sandboxProjects(int $accountId): Sandbox\Project;

    /** Sandbox inboxes — captures mail your application sends. */
    public function sandboxInboxes(int $accountId): Sandbox\Inbox;

    /** Captured messages: bodies, headers, spam score, HTML analysis. */
    public function sandboxMessages(int $accountId, int $inboxId): Sandbox\Message;

    /** Attachments on a captured message. */
    public function sandboxAttachments(int $accountId, int $inboxId): Sandbox\Attachment;

    // ── inbound (mail sent TO you) ───────────────────────────────────────────

    /** Inbound folders. */
    public function inboundFolders(): Inbound\Folder;

    /** Inbound inboxes — NOT the sandbox kind; opposite direction. */
    public function inboundInboxes(int $folderId): Inbound\Inbox;

    /** Received messages, and reply / replyAll / forward — which send real mail. */
    public function inboundMessages(int $inboxId): Inbound\Message;

    /** Conversation threads in an inbound inbox. */
    public function inboundThreads(int $inboxId): Inbound\Thread;
}
