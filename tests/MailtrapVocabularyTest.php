<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\Mailtrap\DTO\CampaignDeliveryMode;
use Plugins\Mail\API\Mailtrap\DTO\CampaignState;
use Plugins\Mail\API\Mailtrap\DTO\CreateEmailCampaign;
use Plugins\Mail\API\Mailtrap\DTO\GrantPermission;
use Plugins\Mail\API\Mailtrap\DTO\PermissionResourceType;
use Plugins\Mail\API\Mailtrap\DTO\RevokePermission;
use Plugins\Mail\API\Mailtrap\DTO\TemplateAttributes;

/**
 * The closed vocabularies Mailtrap validates against.
 *
 * These are values a caller PASSES, and the API's rejection of a typo is not
 * always loud — a permission grant for resource type "inboxes" simply does not
 * take effect. The wire values are asserted literally here, because an enum
 * case renamed for readability must not change what goes over the wire.
 */
#[CoversClass(PermissionResourceType::class)]
#[CoversClass(CampaignDeliveryMode::class)]
#[CoversClass(CampaignState::class)]
#[CoversClass(GrantPermission::class)]
#[CoversClass(RevokePermission::class)]
#[CoversClass(CreateEmailCampaign::class)]
final class MailtrapVocabularyTest extends TestCase
{
    public function test_permission_resource_types_match_the_api_spelling(): void
    {
        $this->assertSame(
            ['account', 'billing', 'project', 'inbox', 'mailsend_domain'],
            array_column(PermissionResourceType::cases(), 'value'),
        );
    }

    public function test_campaign_delivery_modes_match_the_api_spelling(): void
    {
        $this->assertSame(['rapid', 'gradual'], array_column(CampaignDeliveryMode::cases(), 'value'));
    }

    public function test_campaign_states_match_the_api_spelling(): void
    {
        $this->assertSame([
            'draft', 'scheduled', 'started', 'queued', 'paused',
            'terminating', 'under_review', 'finished', 'failed', 'failed_immediately',
        ], array_column(CampaignState::cases(), 'value'));
    }

    public function test_a_terminal_campaign_state_is_one_that_will_not_change_again(): void
    {
        $this->assertTrue(CampaignState::Finished->isTerminal());
        $this->assertTrue(CampaignState::FailedImmediately->isTerminal());
        $this->assertFalse(CampaignState::Paused->isTerminal());
        $this->assertFalse(CampaignState::Terminating->isTerminal());
    }

    public function test_an_unknown_state_reads_as_null_rather_than_throwing(): void
    {
        // A caller rendering a status badge must not fatal because Mailtrap
        // added a state after this release.
        $this->assertNull(CampaignState::tryFrom('something_new'));
    }

    public function test_a_permission_accepts_the_enum_and_sends_its_wire_value(): void
    {
        $this->assertSame([
            'resource_id'   => '12',
            'resource_type' => 'mailsend_domain',
            'access_level'  => '100',
        ], (new GrantPermission(12, PermissionResourceType::MailsendDomain, 100))->toArray());

        $this->assertSame([
            'resource_id'   => '12',
            'resource_type' => 'inbox',
            '_destroy'      => true,
        ], (new RevokePermission(12, PermissionResourceType::Inbox))->toArray());
    }

    public function test_a_permission_still_accepts_a_raw_string_for_a_type_added_later(): void
    {
        $this->assertSame(
            'some_future_type',
            (new GrantPermission(1, 'some_future_type', 'admin'))->toArray()['resource_type'],
        );
    }

    public function test_a_campaign_accepts_the_delivery_mode_enum_and_a_raw_string(): void
    {
        $campaign = new CreateEmailCampaign(
            name: 'Spring',
            domainId: 5,
            fromLocalPart: 'news',
            templateAttributes: new TemplateAttributes(subject: 'Hi'),
            deliveryMode: CampaignDeliveryMode::Gradual,
        );

        $this->assertSame('gradual', $campaign->toArray()['delivery_mode']);

        $raw = new CreateEmailCampaign(
            name: 'Spring',
            domainId: 5,
            fromLocalPart: 'news',
            templateAttributes: new TemplateAttributes(subject: 'Hi'),
            deliveryMode: 'rapid',
        );

        $this->assertSame('rapid', $raw->toArray()['delivery_mode']);
    }

    public function test_a_campaign_without_a_delivery_mode_omits_the_key_entirely(): void
    {
        $campaign = new CreateEmailCampaign(
            name: 'Spring',
            domainId: 5,
            fromLocalPart: 'news',
            templateAttributes: new TemplateAttributes(subject: 'Hi'),
        );

        $this->assertArrayNotHasKey('delivery_mode', $campaign->toArray());
    }
}
