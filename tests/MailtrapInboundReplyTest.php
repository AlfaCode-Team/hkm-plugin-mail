<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\Mailtrap\Inbound\Message as InboundMessage;
use Plugins\Mail\API\Mailtrap\MailtrapApi;
use Plugins\Mail\API\Mailtrap\MailtrapHttp;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Mailtrap\MailtrapPayload;
use Tests\Unit\Plugins\Mail\Fakes\FakeHttpClient;

/**
 * reply / replyAll / forward are the only management calls that SEND MAIL, and
 * the only ones that take a {@see Message}. They therefore share the delivery
 * payload builder, with one difference that is easy to get wrong: a reply needs
 * no From and no recipient, because Mailtrap takes both from the message being
 * answered.
 */
#[CoversClass(InboundMessage::class)]
#[CoversClass(MailtrapPayload::class)]
final class MailtrapInboundReplyTest extends TestCase
{
    private const INBOX = 11;

    private FakeHttpClient $http;
    private MailtrapApi $api;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->api  = new MailtrapApi(new MailtrapHttp($this->http, 'tok-123'));
    }

    public function test_a_reply_needs_neither_a_from_nor_a_recipient(): void
    {
        $this->http->willRespond(200, ['success' => true]);

        $this->api->inboundMessages(self::INBOX)->reply('m-1', Message::make()->text('Thanks!'));

        $sent = $this->http->last();
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://mailtrap.io/api/inbound/inboxes/11/messages/m-1/reply', $sent['url']);
        $this->assertSame(['text' => 'Thanks!'], $sent['body']);
    }

    public function test_reply_all_hits_its_own_endpoint(): void
    {
        $this->http->willRespond(200, ['success' => true]);

        $this->api->inboundMessages(self::INBOX)->replyAll('m-1', Message::make()->text('Thanks all!'));

        $this->assertSame(
            'https://mailtrap.io/api/inbound/inboxes/11/messages/m-1/reply_all',
            $this->http->last()['url'],
        );
    }

    public function test_a_reply_carries_the_full_message_surface(): void
    {
        $this->http->willRespond(200, ['success' => true]);

        $this->api->inboundMessages(self::INBOX)->reply(
            'm-1',
            Message::make()
                ->from('support@shop.test', 'Support')
                ->subject('Re: order')
                ->html('<p>Sorted.</p>')
                ->category('support')
                ->customVariable('ticket', 77)
                ->attachData("a,b\n1,2", 'invoice.csv', 'text/csv'),
        );

        $body = $this->http->last()['body'];
        $this->assertSame(['email' => 'support@shop.test', 'name' => 'Support'], $body['from']);
        $this->assertSame('Re: order', $body['subject']);
        $this->assertSame('support', $body['category']);
        $this->assertSame(['ticket' => '77'], $body['custom_variables']);
        $this->assertSame('invoice.csv', $body['attachments'][0]['filename']);
    }

    public function test_a_forward_without_a_recipient_is_refused_before_the_call(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('at least one "to" recipient');

        $this->api->inboundMessages(self::INBOX)->forward('m-1', Message::make()->text('fyi'));
    }

    public function test_a_forward_with_a_recipient_posts_to_the_forward_endpoint(): void
    {
        $this->http->willRespond(200, ['success' => true]);

        $this->api->inboundMessages(self::INBOX)->forward(
            'm-1',
            Message::make()->to('colleague@shop.test')->text('fyi'),
        );

        $sent = $this->http->last();
        $this->assertSame('https://mailtrap.io/api/inbound/inboxes/11/messages/m-1/forward', $sent['url']);
        $this->assertSame([['email' => 'colleague@shop.test']], $sent['body']['to']);
    }
}
