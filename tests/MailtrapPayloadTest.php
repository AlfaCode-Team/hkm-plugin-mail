<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Domain\Priority;
use Plugins\Mail\Infrastructure\Mailtrap\MailtrapPayload;

#[CoversClass(MailtrapPayload::class)]
#[CoversClass(Message::class)]
final class MailtrapPayloadTest extends TestCase
{
    private MailtrapPayload $payload;

    protected function setUp(): void
    {
        $this->payload = new MailtrapPayload();
    }

    private function message(): Message
    {
        return Message::make()->from('no-reply@shop.test', 'Shop')->to('customer@example.com', 'Cust');
    }

    public function test_maps_addresses_and_bodies(): void
    {
        $built = $this->payload->build(
            $this->message()
                ->cc('boss@example.com')
                ->bcc('audit@example.com')
                ->replyTo('support@shop.test', 'Support')
                ->subject('Welcome')
                ->text('hi')
                ->html('<b>hi</b>'),
        );

        $this->assertSame(['email' => 'no-reply@shop.test', 'name' => 'Shop'], $built['from']);
        $this->assertSame([['email' => 'customer@example.com', 'name' => 'Cust']], $built['to']);
        $this->assertSame([['email' => 'boss@example.com']], $built['cc']);
        $this->assertSame([['email' => 'audit@example.com']], $built['bcc']);
        $this->assertSame(['email' => 'support@shop.test', 'name' => 'Support'], $built['reply_to']);
        $this->assertSame('Welcome', $built['subject']);
        $this->assertSame('hi', $built['text']);
        $this->assertSame('<b>hi</b>', $built['html']);
    }

    public function test_omits_empty_fields_rather_than_sending_them_blank(): void
    {
        $built = $this->payload->build($this->message()->subject('Hi')->text('there'));

        // The API validates PRESENCE: "html": "" is a present html body.
        $this->assertArrayNotHasKey('html', $built);
        $this->assertArrayNotHasKey('cc', $built);
        $this->assertArrayNotHasKey('bcc', $built);
        $this->assertArrayNotHasKey('reply_to', $built);
        $this->assertArrayNotHasKey('attachments', $built);
        $this->assertArrayNotHasKey('custom_variables', $built);
        $this->assertArrayNotHasKey('category', $built);
    }

    public function test_a_template_carries_its_uuid_and_variables_and_nothing_else(): void
    {
        $built = $this->payload->build(
            $this->message()->template('bfa432fd-0000-0000-0000-8493da283a69', [
                'user_name' => 'Jon',
                'company'   => ['name' => 'Best Company'],
                'isBool'    => true,
            ]),
        );

        $this->assertSame('bfa432fd-0000-0000-0000-8493da283a69', $built['template_uuid']);
        $this->assertSame(['name' => 'Best Company'], $built['template_variables']['company']);
        $this->assertTrue($built['template_variables']['isBool']);
        foreach (['subject', 'text', 'html', 'category'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $built);
        }
    }

    /**
     * Mailtrap's `/api/send` body is a `oneOf`, and the template variant has no
     * subject/text/html/category member at all. The payload builder could drop
     * them and send something valid — it refuses instead, because a send that
     * silently substitutes the stored template's subject for the one the caller
     * set looks exactly like a working send until somebody reads the mail.
     */
    #[DataProvider('templateConflictProvider')]
    public function test_a_template_refuses_to_be_combined_with_inline_content(
        string $setter,
        callable $apply,
    ): void {
        $message = $apply($this->message())->template('bfa432fd-0000-0000-0000-8493da283a69');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage($setter);

        $this->payload->build($message);
    }

    /** @return array<string,array{0:string,1:callable}> */
    public static function templateConflictProvider(): array
    {
        return [
            'subject'  => ['subject()',  static fn(Message $m): Message => $m->subject('mine')],
            'text'     => ['text()',     static fn(Message $m): Message => $m->text('mine')],
            'html'     => ['html()',     static fn(Message $m): Message => $m->html('<p>mine</p>')],
            'category' => ['category()', static fn(Message $m): Message => $m->category('mine')],
        ];
    }

    public function test_category_and_custom_variables_are_carried_beside_the_message(): void
    {
        $built = $this->payload->build(
            $this->message()->subject('x')->text('y')
                ->category('onboarding')
                ->customVariables(['order_id' => 42, 'vip' => true]),
        );

        $this->assertSame('onboarding', $built['category']);
        $this->assertSame(['order_id' => '42', 'vip' => 'true'], $built['custom_variables']);
    }

    public function test_inline_attachment_keeps_its_own_content_id(): void
    {
        $built = $this->payload->build(
            $this->message()->subject('x')->html('<img src="cid:logo">')
                ->embedData('PNG', 'logo', 'brand-mark.png', 'image/png')
                ->attachData("a,b\n1,2", 'report.csv', 'text/csv'),
        );

        [$inline, $file] = $built['attachments'];

        $this->assertSame('inline', $inline['disposition']);
        // The SDK reuses the filename as content_id; a real cid is what the
        // HTML references, and here they deliberately differ.
        $this->assertSame('logo', $inline['content_id']);
        $this->assertSame('brand-mark.png', $inline['filename']);
        $this->assertSame('PNG', base64_decode($inline['content']));

        $this->assertSame('attachment', $file['disposition']);
        $this->assertArrayNotHasKey('content_id', $file);
        $this->assertSame('text/csv', $file['type']);
    }

    public function test_priority_and_read_receipt_survive_the_api_path_as_headers(): void
    {
        $built = $this->payload->build(
            $this->message()->subject('x')->text('y')
                ->priority(Priority::High)
                ->confirmReadingTo('reads@shop.test')
                ->header('X-Campaign', 'spring'),
        );

        $this->assertSame('1 (High)', $built['headers']['X-Priority']);
        $this->assertSame('reads@shop.test', $built['headers']['Disposition-Notification-To']);
        $this->assertSame('spring', $built['headers']['X-Campaign']);
    }

    public function test_normal_priority_adds_no_header(): void
    {
        $built = $this->payload->build($this->message()->subject('x')->text('y'));

        $this->assertArrayNotHasKey('headers', $built);
    }

    public function test_several_reply_to_addresses_are_refused_rather_than_silently_dropped(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('single Reply-To');

        $this->payload->build(
            $this->message()->subject('x')->text('y')->replyTo('a@shop.test')->replyTo('b@shop.test'),
        );
    }

    public function test_a_message_without_recipients_is_refused(): void
    {
        $this->expectException(MailException::class);

        $this->payload->build(Message::make()->from('no-reply@shop.test')->subject('x')->text('y'));
    }

    public function test_batch_hoists_the_base_and_lists_one_request_per_recipient(): void
    {
        $base = Message::make()->from('no-reply@shop.test', 'Shop')
            ->template('bfa432fd-0000-0000-0000-8493da283a69');

        $body = $this->payload->batch([
            $this->message(),
            Message::make()->from('no-reply@shop.test')->to('second@example.com'),
        ], $base);

        $this->assertSame('bfa432fd-0000-0000-0000-8493da283a69', $body['base']['template_uuid']);
        $this->assertArrayNotHasKey('to', $body['base']);
        $this->assertCount(2, $body['requests']);
        $this->assertSame('second@example.com', $body['requests'][1]['to'][0]['email']);
    }

    /**
     * The shape Mailtrap's own documentation and the official SDK's
     * `testBatchSend` both use: the base carries from/subject/bodies, and each
     * request carries nothing but its recipient. The asserted array is that
     * SDK test's expected payload, verbatim.
     *
     * This is the case the endpoint exists for, and it used to throw — every
     * entry was run through the full single-send builder, which requires a From
     * that the base is precisely there to supply.
     */
    public function test_batch_entries_inherit_the_base_from_and_need_only_a_recipient(): void
    {
        $body = $this->payload->batch(
            [
                Message::make()->to('recipient1@example.com', 'Recipient 1'),
                Message::make()->to('recipient2@example.com', 'Recipient 2'),
            ],
            Message::make()->from('foo@example.com', 'Ms. Foo Bar')
                ->subject('Batch Email Subject')
                ->text('Batch email text')
                ->html('<p>Batch email text</p>'),
        );

        $this->assertSame([
            'base' => [
                'from'    => ['email' => 'foo@example.com', 'name' => 'Ms. Foo Bar'],
                'subject' => 'Batch Email Subject',
                'text'    => 'Batch email text',
                'html'    => '<p>Batch email text</p>',
            ],
            'requests' => [
                ['to' => [['email' => 'recipient1@example.com', 'name' => 'Recipient 1']]],
                ['to' => [['email' => 'recipient2@example.com', 'name' => 'Recipient 2']]],
            ],
        ], $body);
    }

    public function test_a_batch_entry_without_a_from_is_refused_when_no_base_supplies_one(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('needs a From address');

        $this->payload->batch([Message::make()->to('customer@example.com')]);
    }

    public function test_every_batch_entry_must_name_a_recipient(): void
    {
        // The base cannot carry recipients, so an entry without one has nowhere
        // to go — and would be accepted as a silent no-op if this did not throw.
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('at least one recipient');

        $this->payload->batch(
            [Message::make()->subject('x')->text('y')],
            Message::make()->from('no-reply@shop.test'),
        );
    }

    public function test_batch_base_may_not_carry_recipients(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('may not carry to/cc/bcc');

        $this->payload->batch([$this->message()], $this->message());
    }

    public function test_an_empty_batch_is_refused(): void
    {
        $this->expectException(MailException::class);

        $this->payload->batch([]);
    }
}
