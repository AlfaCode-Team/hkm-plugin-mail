<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\Contracts\MailtrapApiContract;
use Plugins\Mail\API\Mailtrap\DTO;
use Plugins\Mail\API\Mailtrap\MailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;
use Tests\Unit\Plugins\Mail\Fakes\FakeHttpClient;

/**
 * Every management endpoint: the verb, the URL and the body it puts on the wire.
 *
 * These are the facts a type-checker cannot see and a live call would only
 * reveal one at a time — a wrong path is a 404 at runtime, months later, on the
 * one code path nobody exercised. The expectations are read from the official
 * SDK's own request construction.
 */
#[CoversClass(MailtrapApi::class)]
#[CoversClass(MailtrapHttp::class)]
final class MailtrapApiTest extends TestCase
{
    private const ACCOUNT = 7;
    private const ORG     = 9;
    private const INBOX   = 11;
    private const DOMAIN  = 13;
    private const FOLDER  = 17;

    private FakeHttpClient $http;
    private MailtrapApiContract $api;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->api  = new MailtrapApi(new MailtrapHttp($this->http, 'tok-123'));
    }

    /**
     * Every call, as [callable, expected method, expected path, expected body].
     *
     * @return array<string,array{0:\Closure,1:string,2:string,3:array<string,mixed>}>
     */
    public static function endpoints(): array
    {
        $a = self::ACCOUNT;

        return [
            // ── general ──────────────────────────────────────────────────────
            'accounts.getList' => [
                fn(MailtrapApiContract $m) => $m->accounts()->getList(),
                'GET', '/api/accounts', [],
            ],
            'apiTokens.getApiTokens' => [
                fn(MailtrapApiContract $m) => $m->apiTokens($a)->getApiTokens(),
                'GET', "/api/accounts/{$a}/api_tokens", [],
            ],
            'apiTokens.getApiToken' => [
                fn(MailtrapApiContract $m) => $m->apiTokens($a)->getApiToken(5),
                'GET', "/api/accounts/{$a}/api_tokens/5", [],
            ],
            'apiTokens.createApiToken' => [
                fn(MailtrapApiContract $m) => $m->apiTokens($a)->createApiToken(
                    'ci',
                    new DTO\Permissions(new DTO\GrantPermission(3, 'inbox', 'admin')),
                    DTO\TokenExpiration::at('2027-01-01T00:00:00+00:00'),
                ),
                'POST', "/api/accounts/{$a}/api_tokens", [
                    'name'       => 'ci',
                    'resources'  => [['resource_id' => '3', 'resource_type' => 'inbox', 'access_level' => 'admin']],
                    'expires_at' => '2027-01-01T00:00:00+00:00',
                ],
            ],
            'apiTokens.createApiToken (never expires)' => [
                fn(MailtrapApiContract $m) => $m->apiTokens($a)->createApiToken(
                    'ci',
                    new DTO\Permissions(new DTO\RevokePermission(3, 'inbox')),
                    DTO\TokenExpiration::never(),
                ),
                'POST', "/api/accounts/{$a}/api_tokens", [
                    'name'       => 'ci',
                    'resources'  => [['resource_id' => '3', 'resource_type' => 'inbox', '_destroy' => true]],
                    'expires_at' => null,
                ],
            ],
            'apiTokens.deleteApiToken' => [
                fn(MailtrapApiContract $m) => $m->apiTokens($a)->deleteApiToken(5),
                'DELETE', "/api/accounts/{$a}/api_tokens/5", [],
            ],
            'apiTokens.resetApiToken' => [
                fn(MailtrapApiContract $m) => $m->apiTokens($a)->resetApiToken(5),
                'POST', "/api/accounts/{$a}/api_tokens/5/reset", [],
            ],
            'billing.getBillingUsage' => [
                fn(MailtrapApiContract $m) => $m->billing($a)->getBillingUsage(),
                'GET', "/api/accounts/{$a}/billing/usage", [],
            ],
            'permissions.getResources' => [
                fn(MailtrapApiContract $m) => $m->permissions($a)->getResources(),
                'GET', "/api/accounts/{$a}/permissions/resources", [],
            ],
            'permissions.update' => [
                fn(MailtrapApiContract $m) => $m->permissions($a)->update(
                    42,
                    new DTO\Permissions(new DTO\GrantPermission(3, 'inbox', 100)),
                ),
                'PUT', "/api/accounts/{$a}/account_accesses/42/permissions/bulk", [
                    'permissions' => [['resource_id' => '3', 'resource_type' => 'inbox', 'access_level' => '100']],
                ],
            ],
            'users.delete' => [
                fn(MailtrapApiContract $m) => $m->users($a)->delete(42),
                'DELETE', "/api/accounts/{$a}/account_accesses/42", [],
            ],
            'subAccounts.getSubAccounts' => [
                fn(MailtrapApiContract $m) => $m->subAccounts(self::ORG)->getSubAccounts(),
                'GET', '/api/organizations/' . self::ORG . '/sub_accounts', [],
            ],
            'subAccounts.createSubAccount' => [
                fn(MailtrapApiContract $m) => $m->subAccounts(self::ORG)->createSubAccount('Team B'),
                'POST', '/api/organizations/' . self::ORG . '/sub_accounts', ['account' => ['name' => 'Team B']],
            ],
            'organizations.subAccounts' => [
                fn(MailtrapApiContract $m) => $m->organizations(self::ORG)->subAccounts()->getSubAccounts(),
                'GET', '/api/organizations/' . self::ORG . '/sub_accounts', [],
            ],
            'emailTemplates.getAllEmailTemplates' => [
                fn(MailtrapApiContract $m) => $m->emailTemplates($a)->getAllEmailTemplates(),
                'GET', "/api/accounts/{$a}/email_templates", [],
            ],
            'emailTemplates.getEmailTemplate' => [
                fn(MailtrapApiContract $m) => $m->emailTemplates($a)->getEmailTemplate(3),
                'GET', "/api/accounts/{$a}/email_templates/3", [],
            ],
            'emailTemplates.createEmailTemplate' => [
                fn(MailtrapApiContract $m) => $m->emailTemplates($a)->createEmailTemplate(
                    new DTO\EmailTemplateData('Welcome', 'onboarding', 'Hi', 'text', '<b>html</b>'),
                ),
                'POST', "/api/accounts/{$a}/email_templates", [
                    'email_template' => [
                        'name' => 'Welcome', 'category' => 'onboarding', 'subject' => 'Hi',
                        'body_text' => 'text', 'body_html' => '<b>html</b>',
                    ],
                ],
            ],
            'emailTemplates.updateEmailTemplate' => [
                fn(MailtrapApiContract $m) => $m->emailTemplates($a)->updateEmailTemplate(
                    3,
                    new DTO\EmailTemplateData('Welcome', 'onboarding', 'Hi', 'text', '<b>html</b>'),
                ),
                'PATCH', "/api/accounts/{$a}/email_templates/3", [
                    'email_template' => [
                        'name' => 'Welcome', 'category' => 'onboarding', 'subject' => 'Hi',
                        'body_text' => 'text', 'body_html' => '<b>html</b>',
                    ],
                ],
            ],
            'emailTemplates.deleteEmailTemplate' => [
                fn(MailtrapApiContract $m) => $m->emailTemplates($a)->deleteEmailTemplate(3),
                'DELETE', "/api/accounts/{$a}/email_templates/3", [],
            ],

            // ── contacts ─────────────────────────────────────────────────────
            'contacts.getContactList' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->getContactList(2),
                'GET', "/api/accounts/{$a}/contacts/lists/2", [],
            ],
            'contacts.createContactList' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->createContactList('VIPs'),
                'POST', "/api/accounts/{$a}/contacts/lists", ['name' => 'VIPs'],
            ],
            'contacts.updateContactList' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->updateContactList(2, 'VIPs'),
                'PATCH', "/api/accounts/{$a}/contacts/lists/2", ['name' => 'VIPs'],
            ],
            'contacts.deleteContactList' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->deleteContactList(2),
                'DELETE', "/api/accounts/{$a}/contacts/lists/2", [],
            ],
            'contacts.createContact' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->createContact(
                    new DTO\CreateContact('jon@example.com', ['first_name' => 'Jon'], [1, 2]),
                ),
                'POST', "/api/accounts/{$a}/contacts", [
                    'contact' => ['email' => 'jon@example.com', 'fields' => ['first_name' => 'Jon'], 'list_ids' => [1, 2]],
                ],
            ],
            'contacts.updateContactByEmail' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->updateContactByEmail(
                    'jon+tag@example.com',
                    new DTO\UpdateContact('jon+tag@example.com', [], [3], [4], unsubscribed: false),
                ),
                'PATCH', "/api/accounts/{$a}/contacts/jon%2Btag%40example.com", [
                    'contact' => [
                        'email' => 'jon+tag@example.com', 'fields' => [],
                        'list_ids_included' => [3], 'list_ids_excluded' => [4], 'unsubscribed' => false,
                    ],
                ],
            ],
            'contacts.deleteContactById' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->deleteContactById('abc-1'),
                'DELETE', "/api/accounts/{$a}/contacts/abc-1", [],
            ],
            'contacts.createContactField' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->createContactField('First name', 'text', 'first_name'),
                'POST', "/api/accounts/{$a}/contacts/fields",
                ['name' => 'First name', 'data_type' => 'text', 'merge_tag' => 'first_name'],
            ],
            'contacts.updateContactField' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->updateContactField(4, 'First name', 'first_name'),
                'PATCH', "/api/accounts/{$a}/contacts/fields/4",
                ['name' => 'First name', 'merge_tag' => 'first_name'],
            ],
            'contacts.deleteContactField' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->deleteContactField(4),
                'DELETE', "/api/accounts/{$a}/contacts/fields/4", [],
            ],
            'contacts.importContacts' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->importContacts([
                    new DTO\ImportContact('jon@example.com', ['first_name' => 'Jon'], [1], [2]),
                ]),
                'POST', "/api/accounts/{$a}/contacts/imports", [
                    'contacts' => [[
                        'email' => 'jon@example.com', 'fields' => ['first_name' => 'Jon'],
                        'list_ids_included' => [1], 'list_ids_excluded' => [2],
                    ]],
                ],
            ],
            'contacts.getContactImport' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->getContactImport(8),
                'GET', "/api/accounts/{$a}/contacts/imports/8", [],
            ],
            'contacts.createContactEvent' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->createContactEvent(
                    'jon@example.com',
                    new DTO\CreateContactEvent('purchase', ['total' => 42]),
                ),
                'POST', "/api/accounts/{$a}/contacts/jon%40example.com/events",
                ['name' => 'purchase', 'params' => ['total' => 42]],
            ],
            'contacts.createContactExport' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->createContactExport([
                    new DTO\ContactExportFilter('list_id', 'eq', 1),
                ]),
                'POST', "/api/accounts/{$a}/contacts/exports",
                ['filters' => [['name' => 'list_id', 'operator' => 'eq', 'value' => 1]]],
            ],
            'contacts.getContactExport' => [
                fn(MailtrapApiContract $m) => $m->contacts($a)->getContactExport(8),
                'GET', "/api/accounts/{$a}/contacts/exports/8", [],
            ],

            // ── campaigns ────────────────────────────────────────────────────
            'emailCampaigns.getEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->getEmailCampaign(4),
                'GET', '/api/email_campaigns/4', [],
            ],
            'emailCampaigns.createEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->createEmailCampaign(
                    new DTO\CreateEmailCampaign(
                        name: 'Spring',
                        domainId: 13,
                        fromLocalPart: 'news',
                        templateAttributes: new DTO\TemplateAttributes(subject: 'Hi'),
                        replyTo: new DTO\CampaignReplyTo(localPart: 'reply', domain: 'shop.test'),
                    ),
                ),
                'POST', '/api/email_campaigns', [
                    'name' => 'Spring', 'domain_id' => 13, 'from_local_part' => 'news',
                    'reply_to' => ['local_part' => 'reply', 'domain' => 'shop.test'],
                    'template_attributes' => ['subject' => 'Hi'],
                ],
            ],
            'emailCampaigns.updateEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->updateEmailCampaign(
                    4,
                    new DTO\UpdateEmailCampaign(name: 'Summer'),
                ),
                'PATCH', '/api/email_campaigns/4', ['name' => 'Summer'],
            ],
            'emailCampaigns.deleteEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->deleteEmailCampaign(4),
                'DELETE', '/api/email_campaigns/4', [],
            ],
            'emailCampaigns.startEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->startEmailCampaign(4),
                'POST', '/api/email_campaigns/4/start', [],
            ],
            'emailCampaigns.scheduleEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->scheduleEmailCampaign(
                    4,
                    new \DateTimeImmutable('2027-03-01T09:00:00+00:00'),
                ),
                'POST', '/api/email_campaigns/4/schedule', ['datetime' => '2027-03-01T09:00:00+00:00'],
            ],
            'emailCampaigns.cancelEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->cancelEmailCampaign(4),
                'POST', '/api/email_campaigns/4/cancel', [],
            ],
            'emailCampaigns.terminateEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->terminateEmailCampaign(4),
                'POST', '/api/email_campaigns/4/terminate', [],
            ],
            'emailCampaigns.resetEmailCampaign' => [
                fn(MailtrapApiContract $m) => $m->emailCampaigns($a)->resetEmailCampaign(4),
                'POST', '/api/email_campaigns/4/reset', [],
            ],

            // ── sending management ───────────────────────────────────────────
            'sendingDomains.getSendingDomains' => [
                fn(MailtrapApiContract $m) => $m->sendingDomains($a)->getSendingDomains(),
                'GET', "/api/accounts/{$a}/sending_domains", [],
            ],
            'sendingDomains.createSendingDomain' => [
                fn(MailtrapApiContract $m) => $m->sendingDomains($a)->createSendingDomain('shop.test'),
                'POST', "/api/accounts/{$a}/sending_domains", ['sending_domain' => ['domain_name' => 'shop.test']],
            ],
            'sendingDomains.getDomainById' => [
                fn(MailtrapApiContract $m) => $m->sendingDomains($a)->getDomainById(self::DOMAIN),
                'GET', "/api/accounts/{$a}/sending_domains/" . self::DOMAIN, [],
            ],
            'sendingDomains.updateSendingDomain' => [
                fn(MailtrapApiContract $m) => $m->sendingDomains($a)->updateSendingDomain(
                    self::DOMAIN,
                    new DTO\UpdateDomain(clickTrackingEnabled: false),
                ),
                'PATCH', "/api/accounts/{$a}/sending_domains/" . self::DOMAIN,
                ['sending_domain' => ['click_tracking_enabled' => false]],
            ],
            'sendingDomains.deleteSendingDomain' => [
                fn(MailtrapApiContract $m) => $m->sendingDomains($a)->deleteSendingDomain(self::DOMAIN),
                'DELETE', "/api/accounts/{$a}/sending_domains/" . self::DOMAIN, [],
            ],
            'sendingDomains.sendDomainSetupInstructions' => [
                fn(MailtrapApiContract $m) => $m->sendingDomains($a)->sendDomainSetupInstructions(
                    self::DOMAIN,
                    'ops@shop.test',
                ),
                'POST', "/api/accounts/{$a}/sending_domains/" . self::DOMAIN . '/send_setup_instructions',
                ['email' => 'ops@shop.test'],
            ],
            'companyInfo.getCompanyInfo' => [
                fn(MailtrapApiContract $m) => $m->companyInfo(self::DOMAIN)->getCompanyInfo(),
                'GET', '/api/domains/' . self::DOMAIN . '/company_info', [],
            ],
            'companyInfo.createCompanyInfo' => [
                fn(MailtrapApiContract $m) => $m->companyInfo(self::DOMAIN)->createCompanyInfo(
                    new DTO\CreateCompanyInfo(
                        'Shop', '1 Road', 'Town', 'GB', 'AB1 2CD', 'https://shop.test',
                        infoLevel: DTO\CompanyInfoLevel::Business,
                    ),
                ),
                'POST', '/api/domains/' . self::DOMAIN . '/company_info', [
                    'company_info' => [
                        'name' => 'Shop', 'address' => '1 Road', 'city' => 'Town', 'country' => 'GB',
                        'zip_code' => 'AB1 2CD', 'website_url' => 'https://shop.test', 'info_level' => 'business',
                    ],
                ],
            ],
            'companyInfo.updateCompanyInfo' => [
                fn(MailtrapApiContract $m) => $m->companyInfo(self::DOMAIN)->updateCompanyInfo(
                    new DTO\UpdateCompanyInfo(city: 'City'),
                ),
                'PATCH', '/api/domains/' . self::DOMAIN . '/company_info', ['company_info' => ['city' => 'City']],
            ],
            'emailLogs.getMessage' => [
                fn(MailtrapApiContract $m) => $m->emailLogs($a)->getMessage('msg-1'),
                'GET', "/api/accounts/{$a}/email_logs/msg-1", [],
            ],
            'suppressions.createSuppression' => [
                fn(MailtrapApiContract $m) => $m->suppressions($a)->createSuppression(
                    new DTO\CreateSuppression(
                        'bounced@example.com',
                        self::DOMAIN,
                        DTO\SendingStream::Transactional,
                        DTO\SuppressionType::HardBounce,
                    ),
                ),
                'POST', "/api/accounts/{$a}/suppressions", [
                    'email' => 'bounced@example.com', 'domain_id' => self::DOMAIN,
                    'sending_stream' => 'transactional', 'type' => 'hard bounce',
                ],
            ],
            'suppressions.deleteSuppression' => [
                fn(MailtrapApiContract $m) => $m->suppressions($a)->deleteSuppression('sup-1'),
                'DELETE', "/api/accounts/{$a}/suppressions/sup-1", [],
            ],
            'trackingOptOuts.createTrackingOptOut' => [
                fn(MailtrapApiContract $m) => $m->trackingOptOuts()->createTrackingOptOut(
                    new DTO\CreateTrackingOptOut('quiet@example.com', self::DOMAIN),
                ),
                'POST', '/api/tracking_opt_outs', ['email' => 'quiet@example.com', 'domain_id' => self::DOMAIN],
            ],
            'trackingOptOuts.deleteTrackingOptOut' => [
                fn(MailtrapApiContract $m) => $m->trackingOptOuts()->deleteTrackingOptOut('opt-1'),
                'DELETE', '/api/tracking_opt_outs/opt-1', [],
            ],
            'webhooks.getWebhooks' => [
                fn(MailtrapApiContract $m) => $m->webhooks($a)->getWebhooks(),
                'GET', "/api/accounts/{$a}/webhooks", [],
            ],
            'webhooks.getWebhook' => [
                fn(MailtrapApiContract $m) => $m->webhooks($a)->getWebhook(6),
                'GET', "/api/accounts/{$a}/webhooks/6", [],
            ],
            'webhooks.createWebhook' => [
                fn(MailtrapApiContract $m) => $m->webhooks($a)->createWebhook(
                    new DTO\CreateWebhook(
                        url: 'https://shop.test/hooks/mail',
                        webhookType: DTO\WebhookType::EmailSending,
                        eventTypes: [DTO\WebhookEvent::Bounce, DTO\WebhookEvent::Delivery],
                        sendingStream: DTO\SendingStream::Transactional,
                        payloadFormat: DTO\WebhookPayloadFormat::Json,
                    ),
                ),
                'POST', "/api/accounts/{$a}/webhooks", [
                    'webhook' => [
                        'url' => 'https://shop.test/hooks/mail', 'webhook_type' => 'email_sending',
                        'event_types' => ['bounce', 'delivery'], 'payload_format' => 'json',
                        'sending_stream' => 'transactional',
                    ],
                ],
            ],
            'webhooks.updateWebhook' => [
                fn(MailtrapApiContract $m) => $m->webhooks($a)->updateWebhook(6, new DTO\UpdateWebhook(active: false)),
                'PATCH', "/api/accounts/{$a}/webhooks/6", ['webhook' => ['active' => false]],
            ],
            'webhooks.deleteWebhook' => [
                fn(MailtrapApiContract $m) => $m->webhooks($a)->deleteWebhook(6),
                'DELETE', "/api/accounts/{$a}/webhooks/6", [],
            ],

            // ── sandbox ──────────────────────────────────────────────────────
            'sandboxProjects.getList' => [
                fn(MailtrapApiContract $m) => $m->sandboxProjects($a)->getList(),
                'GET', "/api/accounts/{$a}/projects", [],
            ],
            'sandboxProjects.getById' => [
                fn(MailtrapApiContract $m) => $m->sandboxProjects($a)->getById(2),
                'GET', "/api/accounts/{$a}/projects/2", [],
            ],
            'sandboxProjects.create' => [
                fn(MailtrapApiContract $m) => $m->sandboxProjects($a)->create('CI'),
                'POST', "/api/accounts/{$a}/projects", ['project' => ['name' => 'CI']],
            ],
            'sandboxProjects.updateName' => [
                fn(MailtrapApiContract $m) => $m->sandboxProjects($a)->updateName(2, 'CI'),
                'PATCH', "/api/accounts/{$a}/projects/2", ['project' => ['name' => 'CI']],
            ],
            'sandboxProjects.delete' => [
                fn(MailtrapApiContract $m) => $m->sandboxProjects($a)->delete(2),
                'DELETE', "/api/accounts/{$a}/projects/2", [],
            ],
            'sandboxInboxes.getList' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->getList(),
                'GET', "/api/accounts/{$a}/inboxes", [],
            ],
            'sandboxInboxes.getInboxAttributes' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->getInboxAttributes(self::INBOX),
                'GET', "/api/accounts/{$a}/inboxes/" . self::INBOX, [],
            ],
            'sandboxInboxes.create' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->create(2, 'Feature X'),
                'POST', "/api/accounts/{$a}/projects/2/inboxes", ['inbox' => ['name' => 'Feature X']],
            ],
            'sandboxInboxes.update' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->update(
                    self::INBOX,
                    new DTO\SandboxInboxUpdate(name: 'Renamed'),
                ),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX, ['inbox' => ['name' => 'Renamed']],
            ],
            'sandboxInboxes.clean' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->clean(self::INBOX),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/clean', [],
            ],
            'sandboxInboxes.markAsRead' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->markAsRead(self::INBOX),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/all_read', [],
            ],
            'sandboxInboxes.resetSmtpCredentials' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->resetSmtpCredentials(self::INBOX),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/reset_credentials', [],
            ],
            'sandboxInboxes.toggleEmailAddress' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->toggleEmailAddress(self::INBOX),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/toggle_email_username', [],
            ],
            'sandboxInboxes.resetEmailAddress' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->resetEmailAddress(self::INBOX),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/reset_email_username', [],
            ],
            'sandboxInboxes.delete' => [
                fn(MailtrapApiContract $m) => $m->sandboxInboxes($a)->delete(self::INBOX),
                'DELETE', "/api/accounts/{$a}/inboxes/" . self::INBOX, [],
            ],
            'sandboxMessages.getById' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->getById(5),
                'GET', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5', [],
            ],
            'sandboxMessages.getSpamScore' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->getSpamScore(5),
                'GET', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5/spam_report', [],
            ],
            'sandboxMessages.getHtmlAnalysis' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->getHtmlAnalysis(5),
                'GET', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5/analyze', [],
            ],
            'sandboxMessages.getMailHeaders' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->getMailHeaders(5),
                'GET', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5/mail_headers', [],
            ],
            'sandboxMessages.markAsRead' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->markAsRead(5),
                'PATCH', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5',
                ['message' => ['is_read' => true]],
            ],
            'sandboxMessages.delete' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->delete(5),
                'DELETE', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5', [],
            ],
            'sandboxMessages.forward' => [
                fn(MailtrapApiContract $m) => $m->sandboxMessages($a, self::INBOX)->forward(5, 'dev@shop.test'),
                'POST', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5/forward',
                ['email' => 'dev@shop.test'],
            ],
            'sandboxAttachments.getMessageAttachment' => [
                fn(MailtrapApiContract $m) => $m->sandboxAttachments($a, self::INBOX)->getMessageAttachment(5, 9),
                'GET', "/api/accounts/{$a}/inboxes/" . self::INBOX . '/messages/5/attachments/9', [],
            ],

            // ── inbound ──────────────────────────────────────────────────────
            'inboundFolders.getList' => [
                fn(MailtrapApiContract $m) => $m->inboundFolders()->getList(),
                'GET', '/api/inbound/folders', [],
            ],
            'inboundFolders.getById' => [
                fn(MailtrapApiContract $m) => $m->inboundFolders()->getById(self::FOLDER),
                'GET', '/api/inbound/folders/' . self::FOLDER, [],
            ],
            'inboundFolders.create' => [
                fn(MailtrapApiContract $m) => $m->inboundFolders()->create(new DTO\CreateInboundFolder('Support')),
                'POST', '/api/inbound/folders', ['name' => 'Support'],
            ],
            'inboundFolders.update' => [
                fn(MailtrapApiContract $m) => $m->inboundFolders()->update(
                    self::FOLDER,
                    new DTO\UpdateInboundFolder('Support'),
                ),
                'PATCH', '/api/inbound/folders/' . self::FOLDER, ['name' => 'Support'],
            ],
            'inboundFolders.delete' => [
                fn(MailtrapApiContract $m) => $m->inboundFolders()->delete(self::FOLDER),
                'DELETE', '/api/inbound/folders/' . self::FOLDER, [],
            ],
            'inboundInboxes.getList' => [
                fn(MailtrapApiContract $m) => $m->inboundInboxes(self::FOLDER)->getList(),
                'GET', '/api/inbound/folders/' . self::FOLDER . '/inboxes', [],
            ],
            'inboundInboxes.create' => [
                fn(MailtrapApiContract $m) => $m->inboundInboxes(self::FOLDER)->create(
                    new DTO\CreateInboundInbox('Tickets', self::DOMAIN),
                ),
                'POST', '/api/inbound/folders/' . self::FOLDER . '/inboxes',
                ['name' => 'Tickets', 'domain_id' => self::DOMAIN],
            ],
            'inboundInboxes.update' => [
                fn(MailtrapApiContract $m) => $m->inboundInboxes(self::FOLDER)->update(
                    self::INBOX,
                    new DTO\UpdateInboundInbox('Tickets'),
                ),
                'PATCH', '/api/inbound/folders/' . self::FOLDER . '/inboxes/' . self::INBOX, ['name' => 'Tickets'],
            ],
            'inboundInboxes.getById' => [
                fn(MailtrapApiContract $m) => $m->inboundInboxes(self::FOLDER)->getById(self::INBOX),
                'GET', '/api/inbound/folders/' . self::FOLDER . '/inboxes/' . self::INBOX, [],
            ],
            'inboundInboxes.delete' => [
                fn(MailtrapApiContract $m) => $m->inboundInboxes(self::FOLDER)->delete(self::INBOX),
                'DELETE', '/api/inbound/folders/' . self::FOLDER . '/inboxes/' . self::INBOX, [],
            ],
            'inboundThreads.getById' => [
                fn(MailtrapApiContract $m) => $m->inboundThreads(self::INBOX)->getById('t-1'),
                'GET', '/api/inbound/inboxes/' . self::INBOX . '/threads/t-1', [],
            ],
            'inboundThreads.delete' => [
                fn(MailtrapApiContract $m) => $m->inboundThreads(self::INBOX)->delete('t-1'),
                'DELETE', '/api/inbound/inboxes/' . self::INBOX . '/threads/t-1', [],
            ],
            'inboundMessages.getById' => [
                fn(MailtrapApiContract $m) => $m->inboundMessages(self::INBOX)->getById('m-1'),
                'GET', '/api/inbound/inboxes/' . self::INBOX . '/messages/m-1', [],
            ],
            'inboundMessages.delete' => [
                fn(MailtrapApiContract $m) => $m->inboundMessages(self::INBOX)->delete('m-1'),
                'DELETE', '/api/inbound/inboxes/' . self::INBOX . '/messages/m-1', [],
            ],
        ];
    }

    /** @param \Closure(MailtrapApiContract):mixed $call @param array<string,mixed> $body */
    #[DataProvider('endpoints')]
    public function test_endpoint_hits_the_right_url_with_the_right_body(
        \Closure $call,
        string $method,
        string $path,
        array $body,
    ): void {
        $this->http->willRespond(200, ['ok' => true]);

        $call($this->api);

        $sent = $this->http->last();
        $this->assertSame($method, $sent['method']);
        $this->assertSame('https://mailtrap.io' . $path, $sent['url']);
        $this->assertSame($body, $sent['body']);
        $this->assertSame('Bearer tok-123', $sent['headers']['Authorization']);
    }

    // ── query-string construction ────────────────────────────────────────────

    public function test_list_queries_are_appended_as_a_query_string(): void
    {
        $this->http->willRespond(200, []);

        $this->api->emailCampaigns(self::ACCOUNT)->getEmailCampaigns(perPage: 10, search: 'spring', token: 2);

        $this->assertSame(
            'https://mailtrap.io/api/email_campaigns?per_page=10&search=spring&token=2',
            $this->http->last()['url'],
        );
    }

    public function test_array_query_parameters_lose_their_numeric_indices(): void
    {
        $this->http->willRespond(200, []);

        $this->api->users(self::ACCOUNT)->getList(inboxIds: [1, 2], projectIds: [3]);

        // Mailtrap rejects inbox_ids[0]=1; it wants inbox_ids[]=1.
        $url = $this->http->last()['url'];
        $this->assertStringContainsString('inbox_ids%5B%5D=1&inbox_ids%5B%5D=2', $url);
        $this->assertStringContainsString('project_ids%5B%5D=3', $url);
        $this->assertStringNotContainsString('%5B0%5D', $url);
    }

    public function test_stats_share_one_window_and_differ_only_by_grouping(): void
    {
        foreach ([
            'get'                    => '/stats',
            'byDomain'               => '/stats/domains',
            'byCategory'             => '/stats/categories',
            'byEmailServiceProvider' => '/stats/email_service_providers',
            // Singular, unlike every other grouping — the API's spelling.
            'byDate'                 => '/stats/date',
        ] as $method => $suffix) {
            $this->http->willRespond(200, []);
            $this->api->stats(self::ACCOUNT)->{$method}('2026-01-01', '2026-01-31', sendingDomainIds: [self::DOMAIN]);

            $url = $this->http->last()['url'];
            $this->assertStringStartsWith(
                'https://mailtrap.io/api/accounts/' . self::ACCOUNT . $suffix . '?',
                $url,
                "stats::{$method}",
            );
            $this->assertStringContainsString('start_date=2026-01-01&end_date=2026-01-31', $url);
            $this->assertStringContainsString('sending_domain_ids%5B%5D=' . self::DOMAIN, $url);
        }
    }

    public function test_email_log_filters_are_nested_under_a_filters_parameter(): void
    {
        $this->http->willRespond(200, []);

        $this->api->emailLogs(self::ACCOUNT)->getList(
            (new DTO\EmailLogsListFilters(sentAfter: '2026-01-01T00:00:00Z'))
                ->withCriterion('status', DTO\FilterCriterion::withValue(
                    DTO\EmailLogsOperator::Equal,
                    DTO\EmailLogsStatus::NotDelivered->value,
                )),
            searchAfter: 'cursor-1',
        );

        $url = urldecode($this->http->last()['url']);
        $this->assertStringContainsString('search_after=cursor-1', $url);
        $this->assertStringContainsString('filters[sent_after]=2026-01-01T00:00:00Z', $url);
        $this->assertStringContainsString('filters[status][operator]=equal', $url);
        $this->assertStringContainsString('filters[status][value]=not_delivered', $url);
    }

    // ── responses that are not JSON ──────────────────────────────────────────

    public function test_message_bodies_come_back_as_raw_text_not_a_decoded_array(): void
    {
        foreach ([
            'getText'   => 'body.txt',
            'getRaw'    => 'body.raw',
            'getHtml'   => 'body.html',
            'getEml'    => 'body.eml',
            'getSource' => 'body.htmlsource',
        ] as $method => $suffix) {
            $this->http->willRespondRaw(200, 'RAW BODY', 'text/plain');

            $body = $this->api->sandboxMessages(self::ACCOUNT, self::INBOX)->{$method}(5);

            $this->assertSame('RAW BODY', $body, $method);
            $this->assertStringEndsWith('/messages/5/' . $suffix, $this->http->last()['url']);
        }
    }

    // ── failures ─────────────────────────────────────────────────────────────

    public function test_an_api_error_carries_the_status_and_the_api_message(): void
    {
        $this->http->willRespond(422, ['errors' => ['Domain name is invalid']]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('HTTP 422');

        $this->api->sendingDomains(self::ACCOUNT)->createSendingDomain('nope');
    }

    public function test_a_rails_style_error_string_is_surfaced(): void
    {
        $this->http->willRespond(404, ['error' => 'Not found']);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Not found');

        $this->api->webhooks(self::ACCOUNT)->getWebhook(999);
    }

    public function test_the_token_never_appears_in_a_failure_message(): void
    {
        $this->http->willRespond(401, ['errors' => ['Incorrect API token']]);

        try {
            $this->api->accounts()->getList();
            $this->fail('expected a MailException');
        } catch (MailException $e) {
            $this->assertStringNotContainsString('tok-123', $e->getMessage());
        }
    }

    public function test_a_204_with_no_body_is_not_an_error(): void
    {
        $this->http->willRespondRaw(204, '', 'application/json');

        $this->assertSame([], $this->api->webhooks(self::ACCOUNT)->deleteWebhook(6));
    }

    public function test_an_empty_token_is_refused_when_the_client_is_built(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MAILTRAP_API_TOKEN');

        new MailtrapHttp($this->http, '   ');
    }
}
