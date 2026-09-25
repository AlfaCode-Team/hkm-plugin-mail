# Mail — Delivery (`solves: mail.delivery`)

> Namespace **`Plugins\Mail\`** · on-demand GDA module

A **native, dependency-free** mail stack — no PHPMailer, no Symfony Mailer — that
implements the kernel `MailPort` and adds a rich `MailerContract`. It covers the
feature surface you would expect from PHPMailer (attachments, inline images,
cc/bcc, DKIM, SMTP with TLS + auth) while staying entirely self-contained, so the
native distribution ships without `vendor/`.

---

## Part I — Requirements

### Module manifest

| Field | Value |
|---|---|
| `solves` | `mail.delivery` |
| `requires` | **none** — `[]` |
| `exposes` | `MailPort` (kernel port), `MailerContract`, `MailerFactoryContract` |
| `jobs` | `mail.send` → `SendMailJob`, queue `mail` |
| Routes | 5 demo `GET` routes (see Part IV — remove for production) |
| Activation | **on-demand** |

`requires: []` is deliberate: the plugin uses `ViewRendererContract` and
`QueuePort` **when present** and degrades gracefully when they are not. That
graceful degradation has one sharp edge — see the warning below.

### Kernel ports and collaborators

| Dependency | Used for | Required? |
|---|---|---|
| `QueuePort` | `queue()` / `enqueue()` background delivery | optional — without it, `enqueue()` **sends inline** and returns `''` |
| `ViewRendererContract` (`Plugins\View`) | rendering a view name into the HTML body | optional — see the warning |
| `HttpClientPort` (`plugins/HttpClient`, `http.client`) | the **Mailtrap API** transport | **required when `MAIL_TRANSPORT=mailtrap`**, unused otherwise — see [§10](#10-mailtrap-sending-api) |
| `CachePort` | bounding inline delivery of urgent mail | optional — without it urgent mail queues like everything else |
| `StoragePort` / `DatabasePort` | *not used* — Mail owns no tables and no files | — |

> ### ⚠️ The view-rendering trap
>
> `MailPort::send($to, $subject, $view, $data)` treats `$view` as a **template
> name** only when a `ViewRendererContract` is bound. When the View plugin is
> **not** loaded for that request, the string is treated as **raw HTML** — so the
> literal text `auth::password-otp` is silently mailed as the message body.
>
> Any route that sends a view-based mail must therefore declare **both**:
>
> ```jsonc
> { "method": "POST", "path": "/…", "handler": "…",
>   "requires": ["mail.delivery", "view.rendering"] }
> ```
>
> Do not rely on another module pulling them in transitively — that breaks the
> moment the other module's `requires[]` changes.

### Configuration

Everything lives in `config/mail.php`, every value falling back to `env()`.
Override per project by copying it to `config_path('mail.php')`.

| Env key | Default | Meaning |
|---|---|---|
| `MAIL_TRANSPORT` | `smtp` | `smtp` · `sendmail` · `mail` · `mailtrap` · `array` · `log` |
| `MAIL_FROM_ADDRESS` | `''` | default `From:` — a message with no `from()` and no default **throws** |
| `MAIL_FROM_NAME` | `''` | display name for the default sender |
| `MAIL_CHARSET` | `UTF-8` | body + header charset |
| `MAIL_QUEUE` | `mail` | queue name used by `enqueue()`/`queue()` — **run a worker on this queue**, `WORKER_QUEUE=mail` |
| `MAIL_URGENT_VIEWS` | `user::emails/verify` | comma-separated views sent **inline** instead of queued; trailing `*` = prefix |
| `MAIL_URGENT_MAX_INLINE` | `3` | concurrent inline sends before urgent mail queues instead; `0` disables inline delivery |
| `MAIL_URGENT_LOCK_TTL` | `20` | seconds an inline slot is held if a process dies mid-send |
| `MAIL_SMTP_HOSTS` | `MAIL_HOST` | **comma-separated** host list — failover, tried in order |
| `MAIL_HOST` | `localhost` | single host (fallback when `MAIL_SMTP_HOSTS` is unset) |
| `MAIL_PORT` | `587` | SMTP port |
| `MAIL_ENCRYPTION` | `tls` | `tls` (STARTTLS) · `ssl` (implicit) · `none` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | `''` | SMTP AUTH credentials |
| `MAIL_AUTH_MODE` | `auto` | `auto`·`plain`·`login`·`cram-md5`·`xoauth2`·`none` |
| `MAIL_OAUTH_TOKEN` | `''` | bearer token for `xoauth2` |
| `MAIL_HELO_DOMAIN` | `''` | EHLO name (defaults to the local hostname) |
| `MAIL_TIMEOUT` | `30` | socket timeout, seconds |
| `MAIL_VERIFY_PEER` | `true` | **TLS peer verification — leave on** |
| `MAIL_KEEP_ALIVE` | `false` | reuse one SMTP connection across sends |
| `MAIL_ALLOW_INSECURE_AUTH` | `false` | permit AUTH over plaintext — **do not enable** |
| `MAIL_SENDMAIL_BINARY` | `/usr/sbin/sendmail` | sendmail transport binary |
| `MAIL_DEMO_ALLOW_SEND` | *(unset)* | let the `/mail/demo/*` routes send **real** mail. Dev only — prefer removing the routes |
| `MAIL_DKIM_DOMAIN` | `''` | signing domain — empty disables DKIM |
| `MAIL_DKIM_SELECTOR` | `''` | DNS selector |
| `MAIL_DKIM_KEY` | `''` | PEM string **or** path to the private key file |
| `MAILTRAP_API_TOKEN` | `''` | Mailtrap API token — required when `MAIL_TRANSPORT=mailtrap` |
| `MAILTRAP_STREAM` | `transactional` | `transactional` · `bulk` · `sandbox` — the stream **is the host** |
| `MAILTRAP_INBOX_ID` | `0` | **required for `sandbox`** — it goes in the URL path, not the payload |
| `MAILTRAP_HOST` | `''` | host override (a proxy, a mock); empty = the stream's own host |
| `MAILTRAP_TIMEOUT` | `30` | HTTP timeout, seconds |
| `MAILTRAP_WEBHOOK_SECRET` | `''` | `signing_secret` for verifying inbound Mailtrap webhooks |

Read them with `env()` — **never `getenv()`**.

### Wiring checklist

1. Add `Plugins\Mail\Provider::class` to the project's `withModules([...])`.
2. Set at minimum `MAIL_FROM_ADDRESS` (a message with no sender throws).
3. For SMTP: `MAIL_HOST`/`MAIL_SMTP_HOSTS`, `MAIL_PORT`, `MAIL_ENCRYPTION`,
   credentials. Leave `MAIL_VERIFY_PEER=true`.
   For the **Mailtrap API**: `MAIL_TRANSPORT=mailtrap`, `MAILTRAP_API_TOKEN`,
   and an `HttpClientPort` in the request's graph — see [§10](#10-mailtrap-sending-api).
4. Want background delivery? Bind a `QueuePort` and run a worker — the
   `mail.send` job is registered by this plugin's `module.json`.
5. Sending a **view**? Ensure `view.rendering` is loaded on that route.
6. Production: delete the five `/mail/demo/*` entries from `routes[]`, or veto
   them from the project (`proj.json` → `routePolicy.disable`). The guard below
   makes an accident unlikely; removing the routes makes it impossible, and is
   the only one of the two that survives someone setting an env var to debug a
   production incident.

---

## Part II — The two APIs

| API | Shape | Use when |
|---|---|---|
| **`MailPort`** (kernel) | `send($to, $subject, $view, $data)` · `queue(...)` | any module — the portable, view-based shortcut |
| **`MailerContract`** (this plugin) | `message()` → fluent `Message` → `dispatch()` / `enqueue()` / `dispatchBatch()` / `preview()` | you need cc/bcc, attachments, inline images, headers, priority, or a provider template |
| **`MailerFactoryContract`** (this plugin) | `forSmtp(SmtpSettings)` · `forMailtrap(MailtrapSettings)` → `MailerContract` · `forMailtrapApi(MailtrapSettings)` → `MailtrapApiContract` | you need credentials THIS plugin's config does not know about — a tenant's own SMTP server or Mailtrap account |
| **`MailtrapApiContract`** (this plugin) | 25 resource clients, covering all 151 methods of the official SDK | everything Mailtrap does that is **not** sending a message — see [Part IV](#part-iv--the-mailtrap-management-api) |

`MailPort` and `MailerContract` are the same underlying `Mailer` instance
(the env-driven one), so they share transport, DKIM signer and defaults.
`MailerFactoryContract` builds an INDEPENDENT `Mailer` per call — see
[§9](#9-per-tenant-smtp--mailerfactorycontract).

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\API\Contracts\MailerFactoryContract;
use Plugins\Mail\API\DTOs\SmtpSettings;
use Plugins\Mail\API\DTOs\MailtrapSettings;
use Plugins\Mail\API\MailtrapWebhookSignature;
use Plugins\Mail\API\Contracts\MailtrapApiContract;
use Plugins\Mail\API\Mailtrap\DTO;          // request objects for the management API
```

Cross-plugin callers should type against `MailPort` (kernel port, no coupling) and
only reach for `MailerContract` when they genuinely need the rich surface.

### The pipeline

There are **two** pipelines, chosen by the kind of transport configured.

```
                             ┌─ MIME transports (smtp│sendmail│mail│array│log)
                             │
Message ──default From──►────┤  compile() ─► MimeBuilder ─► DkimSigner (opt.) ─► Transport::send()
                             │                  │
                             │            headers + body
                             │            multipart/mixed
                             │              └ related (inline CID)
                             │                └ alternative (text + html)
                             │
                             └─ MESSAGE transports (mailtrap)
                                MailtrapPayload ─► JSON ─► HttpClientPort ─► MessageTransport::sendMessage()
```

Both paths fill the default `From` first. The MIME path additionally computes the
envelope sender (`Return-Path` → `Sender` → `From`); on the API path the provider
derives it, builds the MIME and signs it, so `MAIL_DKIM_*` does not apply there.

---

## Part III — Usage

### 1. Simple, view-based (`MailPort`)

```php
$mail->send('customer@example.com', 'Welcome', 'user::emails/verify', ['url' => $url]);
$jobId = $mail->queue($to, 'Welcome', 'user::emails/verify', ['url' => $url]);
```

`$to` accepts a string, a list of addresses, or an `email => name` map. `$data`
becomes the template's variables. Remember the `view.rendering` requirement.

### 2. Rich message (`MailerContract`)

```php
$mailer->dispatch(
    $mailer->message()
        ->to('customer@example.com', 'Cust')
        ->cc('audit@shop.test')
        ->bcc('hidden@shop.test')            // delivered, never shown in headers
        ->replyTo('support@shop.test')
        ->subject('Your receipt ☕')          // non-ASCII → RFC 2047 encoded-word
        ->html('<h1>Thanks!</h1><img src="cid:logo">')
        ->embed('/path/logo.png', 'logo')    // inline image referenced by cid:
        ->attach('/path/receipt.pdf')
        ->priority(\Plugins\Mail\Domain\Priority::High),
);
```

### 3. The full `Message` builder

| Group | Methods |
|---|---|
| Sender | `from(email, name)` · `sender(email, name)` · `returnPath(email)` |
| Recipients | `to()` · `cc()` · `bcc()` · `replyTo()` — call repeatedly to add more |
| Content | `subject()` · `html()` · `text()` · `charset()` |
| Delivery hints | `priority(Priority::High\|Normal\|Low)` · `confirmReadingTo(email, name)` |
| Attachments | `attach(path, name, mime)` · `attachData(raw, name, mime)` |
| Inline | `embed(path, cid, name, mime)` · `embedData(raw, cid, name, mime)` |
| Extras | `header(name, value)` (custom) · `tag(key, value)` (metadata, not emitted) |
| Readers | `getFrom()` · `getTo()` · `getCc()` · `getBcc()` · `getSubject()` · `getHtml()` · `getAttachments()` · `getHeaders()` · `recipientEmails()` · … |

Every setter returns `$this`. Set only `html()` and a **plain-text alternative is
generated automatically**, so the mail is always `multipart/alternative`.

```php
$m = $mailer->message()
    ->to('a@x.test')->to('b@x.test')                 // repeat to add
    ->subject('Report')
    ->html('<p>See attached</p>')
    ->text('See attached')                            // explicit alternative
    ->attachData($csv, 'report.csv', 'text/csv')      // no temp file needed
    ->header('X-Campaign', 'july')
    ->tag('campaign', 'july');                        // metadata for your own code
```

### 4. Background delivery

```php
$jobId = $mailer->enqueue($message);      // → job id, or '' when no QueuePort is bound
```

The message is compiled to MIME **first**, then the `{from, recipients, mime}`
payload is pushed as the `mail.send` job on the `MAIL_QUEUE` queue. The worker
(`SendMailJob`) only re-opens the transport — it never re-renders, so a template
change between enqueue and delivery cannot alter a queued mail. A malformed
payload returns `JobResult::skipped()` rather than failing the worker.

> With **no** `QueuePort` bound, `enqueue()` silently sends inline and returns
> `''`. Check for `''` if the job id matters to you.

### Urgent mail — sent now, not queued

A verification or password-reset link is worthless later: the person is on the
"check your inbox" screen right now, and queueing makes delivery depend on a
worker being up. When it is not, the mail is never sent and nothing says so.

So mail rendered from a view in `MAIL_URGENT_VIEWS` is **delivered inline** by
`queue()` — callers keep using the kernel `MailPort` and need to know nothing
about it, which matters because the code that sends verification mail holds a
`MailPort`, and the port has no way to express priority.

Matching is on the **view name, never the subject**: subjects are translated
("Verify your email address" / "Vérifiez votre adresse e-mail"), so matching them
would silently stop working on a non-English edition.

Inline delivery is capped at `MAIL_URGENT_MAX_INLINE` concurrent sends, held as
named locks through the `CachePort` so the limit spans every PHP-FPM worker
rather than each one counting to it alone. Past the cap the message queues — a
burst of signups must not leave every web worker blocked on one SMTP socket.

| Situation | Result |
|---|---|
| urgent view, capacity free | sent inline, `queue()` returns `''` |
| urgent view, all slots busy | queued, returns the job id |
| urgent view, transport throws | **queued** (not lost, not rethrown) |
| no `CachePort` bound | queued — the cap cannot be enforced |
| any other view | queued, as before |

> The `CachePort` must be **cross-process** (`FileCache`, Redis) for the cap to
> mean anything. A per-process cache gives each PHP-FPM worker its own slots, so
> the effective limit is multiplied by the worker count.

`dispatchNow(Message $message): string` exposes the same decision directly for
callers holding the concrete `Mailer`.

### 5. Previewing — no send

```php
echo $mailer->preview($message);   // the exact MIME, DKIM-signed, that would go on the wire
```

Ideal for a golden-file test or for eyeballing header folding and encoding.

### 6. Testing

```php
$transport = new ArrayTransport();
$mailer    = new Mailer(
    transport: $transport,
    mime:      new MimeBuilder(),
    views:     $viewRenderer,          // omit to treat $view as raw HTML
    fromEmail: 'no-reply@example.com',
);

$mailer->send('user@example.com', 'Hi', 'auth::password-otp', ['otp' => '123456']);

$transport->count();      // 1
$transport->last();       // ['from' => …, 'recipients' => [...], 'mime' => …]
$transport->messages();   // every captured message
$transport->flush();
```

Or set `MAIL_TRANSPORT=array` (in-memory) / `MAIL_TRANSPORT=log` (full MIME to the
error log) and nothing leaves the machine.

### 7. DKIM

```dotenv
MAIL_DKIM_DOMAIN=example.com
MAIL_DKIM_SELECTOR=mail
MAIL_DKIM_KEY=/etc/ssl/private/dkim.pem     # PEM string also accepted
```

RSA-SHA256, relaxed/relaxed canonicalisation. Publish the public key at
`<selector>._domainkey.<domain>`. Leave `MAIL_DKIM_DOMAIN` empty to disable.

### 8. SMTP failover and connection reuse

```dotenv
MAIL_SMTP_HOSTS=smtp1.example.com,smtp2.example.com
MAIL_KEEP_ALIVE=true
```

Hosts are tried in order until one connects. With keep-alive, several sends in one
request or job reuse a single connection (`RSET` between messages). The queue
worker builds a fresh module scope per job, so reuse is *within* a job — batch a
run of messages into one job to benefit.

### 9. Per-tenant SMTP — MailerFactoryContract

`MailPort`/`MailerContract` always send through THIS plugin's own `mail.smtp`
config (`MAIL_HOST`, `MAIL_USERNAME`, …). A project that must send through a
**tenant's own SMTP server** — host/login stored per tenant, not in `.env` —
builds a separate mailer for it with `MailerFactoryContract`, without ever
importing this plugin's internal `Transport`/`SmtpTransport`/`MimeBuilder`
(they stay `bindInternal`; reaching into them from a project would violate GDA).

```php
use Plugins\Mail\API\Contracts\MailerFactoryContract;
use Plugins\Mail\API\DTOs\SmtpSettings;

final class TenantMailer
{
    public function __construct(private readonly MailerFactoryContract $factory) {}

    public function send(Tenant $tenant): void
    {
        $mailer = $this->factory->forSmtp(new SmtpSettings(
            hosts:      [$tenant->smtpHost],
            port:       $tenant->smtpPort,
            encryption: $tenant->smtpEncryption,   // 'tls' | 'ssl' | 'none'
            username:   $tenant->smtpUsername,
            password:   $tenant->smtpPassword,
            fromEmail:  $tenant->fromEmail,          // '' = the configured MAIL_FROM_ADDRESS
            fromName:   $tenant->fromName,
        ));

        $mailer->dispatch(
            $mailer->message()->to('customer@example.com')->subject('Your receipt')->html('<p>Thanks!</p>'),
        );
    }
}
```

`SmtpSettings` validates at construction (`Plugins\Mail\Domain\MailException` on
failure): at least one non-empty host, `port` in `1..65535`, `encryption` one of
`tls`/`ssl`/`none`. `$password` is never included in an exception message,
`__toString()`, `print_r()`/`var_dump()` output, or an uncaught exception's
stack trace (`#[\SensitiveParameter]`) — the one thing it does NOT guard is
`var_export()`, which has no redaction hook; do not `var_export()` it.

Every OTHER SMTP tunable a tenant does not own — `auth_mode`, `oauth_token`,
`helo_domain`, `timeout`, `verify_peer`, `keep_alive`, `allow_insecure_auth` —
plus `charset` and DKIM signing still come from this plugin's configured
`mail.*`, exactly as the env-driven mailer uses them, so the two paths cannot
drift (`Infrastructure\Transport\TransportFactory` is the one place that logic
lives).

**Delivery is always inline.** The mailer `forSmtp()` returns has no
`QueuePort`: `dispatch()` sends now, and `enqueue()`/`queue()` also deliver
inline and return `''` rather than being handed to a queue worker with no idea
which tenant's credentials to use. There is no urgent-mail inline-slot cap on
this mailer either — that cap exists to bound *queued* concurrency, which does
not apply when nothing is ever queued.

```php
$jobId = $mailer->enqueue($message);   // always '' — sent immediately, never queued
```

### 10. Mailtrap Sending API

`MAIL_TRANSPORT=mailtrap` delivers over Mailtrap's HTTPS Sending API instead of
SMTP. It is a port of the official [`mailtrap/mailtrap-php`](https://github.com/mailtrap/mailtrap-php)
SDK's sending surface onto this plugin's own `Message` — **no vendor package is
installed**, no Symfony Mime, no PSR-18 discovery.

```dotenv
MAIL_TRANSPORT=mailtrap
MAILTRAP_API_TOKEN=your-api-token
MAILTRAP_STREAM=transactional        # transactional | bulk | sandbox
MAIL_FROM_ADDRESS=no-reply@your-verified-domain.test
```

**It needs an `HttpClientPort`.** This plugin declares `requires: []`, so the
HttpClient plugin has to reach the request some other way — the consuming
module's (or route's) `requires: ["http.client"]`, `proj.json` `"essentials"`,
or a `withPorts([HttpClientPort::class => …])` binding. Without one, building
the transport throws a `MailException` that lists exactly those three options.

#### What the stream means

The stream is the **host**, not a field in the payload:

| `MAILTRAP_STREAM` | Host | Meaning |
|---|---|---|
| `transactional` | `send.api.mailtrap.io` | one-to-one mail a person triggered |
| `bulk` | `bulk.api.mailtrap.io` | one-to-many: campaigns, digests |
| `sandbox` | `sandbox.api.mailtrap.io` | captured in an inbox, **never delivered** |

`sandbox` also puts `MAILTRAP_INBOX_ID` in the URL path, so `MailtrapSettings`
refuses a sandbox stream without one — at construction, not at send time.

#### What the API path adds

```php
$mailer->dispatch(
    $mailer->message()
        ->to('customer@example.com', 'Cust')
        ->category('onboarding')                       // ONE label; the API rejects more
        ->customVariables(['order_id' => 4711])        // echoed back on the webhooks
        ->template('bfa432fd-0000-0000-0000-8493da283a69', [
            'user_name' => 'Jon',
            'company'   => ['name' => 'Best Company'],
            'products'  => [['name' => 'Product 1', 'price' => 100]],
        ]),
);
```

`template()` renders from a template **stored at Mailtrap**. The API's request
body is a `oneOf`, and its template variant has no `subject`/`text`/`html`/
`category` member at all — the stored template supplies them. Combining the two
therefore **throws**, naming the fields that clash:

```php
$mailer->message()->subject('Welcome')->template('bfa432fd-…');
// MailException: template() cannot be combined with subject() — …
```

It would be easy to drop those fields and send something valid instead. That is
deliberately not what happens: a send that silently substitutes the template's
own subject for the one you set is indistinguishable from a working send until
somebody reads the mail.

Batch sending puts many messages in **one** request:

```php
$ids = $mailer->dispatchBatch(
    messages: [$mailer->message()->to('a@example.com'), $mailer->message()->to('b@example.com')],
    base:     $mailer->message()->from('news@shop.test')->subject('Spring sale')->html($body),
);
```

The `base` holds everything the messages share — **including `from`** — and each
entry then needs nothing but its recipient. That is the shape the endpoint
exists for. Without a `base`, every entry must carry its own `from`.

A `base` may not carry `to`/`cc`/`bcc`; recipients belong on the entries, and a
base that names one is refused here rather than at the API.

`dispatchBatch()` is safe to call on any transport: one that has no batch
endpoint loops instead, so switching `MAIL_TRANSPORT` back to `smtp` does not
turn the call into a runtime error. The API caps a batch at **500**; going over
it throws rather than being split silently into calls you cannot correlate.

#### Failure semantics

Everything below is `Plugins\Mail\Domain\MailException`, carrying the HTTP
`status` it came from (`0` when it never reached a response):

| Situation | Result |
|---|---|
| HTTP 4xx | `status` set, `isPermanent()` **true** — the request is wrong and will be wrong next time |
| HTTP 5xx | `status` set, `isPermanent()` false — worth retrying |
| HTTP 200 with `"success": false` | a 2xx is **not** proof of acceptance |
| A batch entry that failed inside a 200 | names the index, **and** carries the ids that did send — see below |
| DNS/TLS/timeout | `status` `0`, `isPermanent()` false — so a blip is retried, not dropped |

`isPermanent()` answers only "is this KNOWN to recur", so an ambiguous failure
reports `false`. For mail that is the safe direction: a wasted retry costs one
request against a bounded budget, while a retry wrongly suppressed drops a real
message.

A **partially** failed batch is the one case worth handling explicitly. The
entries that succeeded are already delivered, so re-sending the batch mails
those people twice:

```php
try {
    $mailer->dispatchBatch($messages, $base);
} catch (MailException $e) {
    $e->context['sent'];            // [0 => ['id-a'], 2 => ['id-c']]  — already delivered
    $e->context['failed_indexes'];  // [1]                             — resend only these
}
```

The API token never appears in an exception message.

#### Queued mail

`enqueue()` puts the **serialised Message** on the queue for an API transport
(`{"message": …}`), not built MIME — the API takes a structured document, so the
request is composed in the worker. Attachment bytes are materialised at enqueue
time either way, so a path-backed attachment survives the file being cleaned up
before the worker runs. `SendMailJob` dispatches on which key is present, and a
payload queued for one transport kind and picked up by the other **throws**
(dead-lettering through the `LoggerPort`) rather than skipping — mail is not
silently discarded because `MAIL_TRANSPORT` changed mid-flight.

#### Per-tenant Mailtrap accounts

The mirror of [§9](#9-per-tenant-smtp--mailerfactorycontract), for an API token
instead of a login:

```php
$mailer = $factory->forMailtrap(new MailtrapSettings(
    apiToken: $tenant->mailtrapToken,
    stream:   MailtrapStream::Transactional,
    fromEmail: $tenant->fromEmail,        // '' = the configured MAIL_FROM_ADDRESS
));
```

Same guarantees as `forSmtp()`: delivery is always inline (no `QueuePort`), and
the token is redacted from `__debugInfo()`, `__toString()` and stack traces.
DKIM is deliberately **not** applied — Mailtrap signs with the keys registered
for the sending domain.

#### Verifying delivery webhooks

Mailtrap's delivery/bounce/spam webhooks are an unauthenticated public endpoint.
Check the signature before acting on one:

```php
use Plugins\Mail\API\MailtrapWebhookSignature;

if (!MailtrapWebhookSignature::verify(
        $request->getContent(),                          // the RAW body — never re-encoded JSON
        (string) $request->header('Mailtrap-Signature'),
        env('MAILTRAP_WEBHOOK_SECRET', ''),
)) {
    return Response::unauthorized();
}
```

It compares with `hash_equals()` and never throws — every input that can
plausibly arrive over the wire returns `false`.

#### The envelope is the provider's

`Message::sender()` and `Message::returnPath()` set the SMTP envelope, and the
Sending API has no field for either — Mailtrap builds the MIME and owns the
envelope. A message carrying one is **refused** on this path rather than sent
with it quietly dropped: a return path exists so bounces reach a mailbox that
processes them, and losing it is invisible — the mail sends, the bounces go
somewhere nobody reads, and the suppression list stops being updated. Remove
them, or deliver through SMTP, which honours both.

#### The rest of the API

Everything else Mailtrap does — domains, suppressions, logs, campaigns,
contacts, sandbox, inbound — is [Part IV](#part-iv--the-mailtrap-management-api).

---

## Part IV — The Mailtrap management API

`MailtrapApiContract` is a complete port of the official
[`mailtrap/mailtrap-php`](https://github.com/mailtrap/mailtrap-php) SDK's API
surface — **all 151 public methods across 25 resource clients**, rewritten onto
this plugin's own types. No vendor package, no Symfony Mime, no PSR-18
discovery; outbound HTTP is the kernel `HttpClientPort` like everything else.

This is not delivery. A message is sent with `MailerContract`; this is for
everything around it.

```php
use Plugins\Mail\API\Contracts\MailtrapApiContract;
use Plugins\Mail\API\Mailtrap\DTO;

final class MailOps
{
    public function __construct(private readonly MailtrapApiContract $mailtrap) {}

    public function report(): array
    {
        $accountId = $this->mailtrap->accounts()->getList()[0]['id'];

        return [
            'domains'      => $this->mailtrap->sendingDomains($accountId)->getSendingDomains(),
            'suppressions' => $this->mailtrap->suppressions($accountId)->getSuppressions(),
            'usage'        => $this->mailtrap->billing($accountId)->getBillingUsage(),
        ];
    }
}
```

**Requirements:** `MAILTRAP_API_TOKEN`, and an `HttpClientPort` in the request's
dependency graph (see [§10](#10-mailtrap-sending-api)). It is bound whenever a
token is configured, **independently of `MAIL_TRANSPORT`** — an application can
deliver over SMTP and still read its own bounce log.

**Shape:** every method on the contract returns a resource *client*, not a
result. Mailtrap's paths are scoped to an account, an organization, a domain or
an inbox, so the id is captured once rather than repeated on every call. Every
client method returns decoded JSON as a PHP array; faults raise
`Plugins\Mail\Domain\MailException`, the same type delivery raises.

### What is covered

| Client | Reach it with | Covers |
|---|---|---|
| `General\Account` | `accounts()` | the account list — where an account id comes from |
| `General\ApiToken` | `apiTokens($accountId)` | list, get, create, delete, reset. Create/reset return the value **once** |
| `General\Billing` | `billing($accountId)` | current billing-cycle usage |
| `General\Contact` | `contacts($accountId)` | contacts, lists, fields, imports, events, exports (22 methods) |
| `General\EmailCampaign` | `emailCampaigns($accountId)` | CRUD + start / schedule / cancel / terminate / reset / stats |
| `General\EmailTemplate` | `emailTemplates($accountId)` | stored-template CRUD |
| `General\Organization` | `organizations($orgId)` | reaches sub-accounts |
| `General\Permission` | `permissions($accountId)` | resource list, bulk grant/revoke |
| `General\SubAccount` | `subAccounts($orgId)` | list, create |
| `General\User` | `users($accountId)` | list (filterable by inbox/project), remove |
| `Sending\Domain` | `sendingDomains($accountId)` | CRUD + mail the DNS setup instructions |
| `Sending\CompanyInfo` | `companyInfo($domainId)` | the postal identity CAN-SPAM/GDPR require |
| `Sending\EmailLogs` | `emailLogs($accountId)` | filtered log, single message by sending id |
| `Sending\Stats` | `stats($accountId)` | totals, by domain / category / ESP / date |
| `Sending\Suppression` | `suppressions($accountId)` | list, add, remove |
| `Sending\TrackingOptOut` | `trackingOptOuts()` | list, add, remove |
| `Sending\Webhook` | `webhooks($accountId)` | CRUD — create returns the `signing_secret` |
| `Sandbox\Project` | `sandboxProjects($accountId)` | CRUD |
| `Sandbox\Inbox` | `sandboxInboxes($accountId)` | CRUD + clean, markAsRead, reset credentials, toggle/reset address |
| `Sandbox\Message` | `sandboxMessages($a, $inboxId)` | list, get, spam score, HTML analysis, headers, bodies, forward, delete |
| `Sandbox\Attachment` | `sandboxAttachments($a, $inboxId)` | list, get |
| `Inbound\Folder` | `inboundFolders()` | CRUD |
| `Inbound\Inbox` | `inboundInboxes($folderId)` | CRUD |
| `Inbound\Thread` | `inboundThreads($inboxId)` | list, get, delete |
| `Inbound\Message` | `inboundMessages($inboxId)` | list, get, delete + **reply / replyAll / forward** |

### Answering inbound mail

`reply`, `replyAll` and `forward` are the only management calls that **send real
mail**, and they take the plugin's own `Message` — so composing a reply is the
same job as composing anything else, attachments and templates included:

```php
$mailtrap->inboundMessages($inboxId)->reply(
    $messageId,
    $mailer->message()->html('<p>Sorted — ticket closed.</p>')->category('support'),
);
```

A reply needs **no From and no recipient**: Mailtrap takes both from the message
being answered. A `forward` needs at least one `to`, since there is nobody to
infer it from — and says so before the call rather than after a 422.

### Request objects

Anything with a body takes a validated readonly DTO from
`Plugins\Mail\API\Mailtrap\DTO`, with enums for the closed sets
(`WebhookType`, `WebhookEvent`, `SendingStream`, `SuppressionType`,
`CompanyInfoLevel`, `EmailLogsOperator`, `PermissionResourceType`,
`CampaignDeliveryMode`, `CampaignState`, …):

```php
$mailtrap->webhooks($accountId)->createWebhook(new DTO\CreateWebhook(
    url:           'https://shop.test/hooks/mail',
    webhookType:   DTO\WebhookType::EmailSending,
    eventTypes:    [DTO\WebhookEvent::Bounce, DTO\WebhookEvent::SpamComplaint],
    sendingStream: DTO\SendingStream::Transactional,
));

$mailtrap->suppressions($accountId)->createSuppression(new DTO\CreateSuppression(
    email:         'bounced@example.com',
    domainId:      $domainId,
    sendingStream: DTO\SendingStream::Transactional,
    type:          DTO\SuppressionType::HardBounce,
));

$mailtrap->permissions($accountId)->update($accountAccessId, new DTO\Permissions(
    new DTO\GrantPermission($domainId, DTO\PermissionResourceType::MailsendDomain, 'admin'),
    new DTO\RevokePermission($inboxId, DTO\PermissionResourceType::Inbox),
));
```

`PermissionResourceType` and `CampaignDeliveryMode` also accept a raw string, so
a value Mailtrap adds after this release is usable without waiting for a new
one. `CampaignState` is read-side only — use `tryFrom()` on a campaign's
`current_state` so an unrecognised state renders as unknown instead of throwing
inside a status badge.

They validate at construction, so a malformed request fails where you wrote it:

- an `email_sending` webhook without event types or a stream;
- an empty PATCH (`UpdateDomain`, `UpdateWebhook`, `UpdateCompanyInfo`,
  `UpdateEmailCampaign`, `SandboxInboxUpdate`) — the API would accept it and
  change nothing;
- an unknown email-log criterion, which Mailtrap **silently ignores**, returning
  a successful but unfiltered list;
- `empty` / `not_empty` given a value (`FilterCriterion::withoutValue()` exists
  for exactly that).

### Things worth knowing

- **Two hosts.** Management is `mailtrap.io`; sending is
  `send`/`bulk`/`sandbox.api.mailtrap.io`. Posting a send to the management host
  404s, which is why the stream is part of `MailtrapSettings` and ignored by
  `forMailtrapApi()`.
- **Five sandbox endpoints return the message, not JSON** — `getText()`,
  `getRaw()`, `getHtml()`, `getEml()`, `getSource()` are typed `string`.
- **`stats()->byDate()` hits `/stats/date`**, singular, unlike every other
  grouping. That is the API's spelling, not a typo here.
- **Array query parameters are sent as `ids[]=1&ids[]=2`**, never `ids[0]=1`,
  which Mailtrap rejects.
- **Deleting a suppression re-enables delivery.** Do it only when you know why
  the address was suppressed — removing a spam-complaint entry and mailing again
  is how a sending domain gets blocked.
- **A created API token's value is returned once.** So is a reset one's, and the
  previous value stops working immediately.

### Per-tenant accounts

`MailerFactoryContract::forMailtrapApi()` is the mirror of `forSmtp()` /
`forMailtrap()`, for an account this plugin's config knows nothing about:

```php
$tenantApi = $factory->forMailtrapApi(new MailtrapSettings(apiToken: $tenant->mailtrapToken));
$tenantApi->suppressions($tenant->mailtrapAccountId)->getSuppressions();
```

### Still not implemented

Nothing from the SDK's API surface. The parts of the SDK deliberately left out
are its **framework bridges** — the Laravel service provider and the Symfony
Mailer transport — which exist to wire it into those frameworks and have no
meaning here.

---

## Part V — Reference

### Transports (`MAIL_TRANSPORT`)

| Value | Notes |
|---|---|
| `smtp` (default) | Native SMTP. `tls` (STARTTLS) or `ssl` (implicit); AUTH `plain`/`login`/`cram-md5`/`xoauth2` (auto-negotiated); multi-host failover; optional keep-alive |
| `mailtrap` | Mailtrap Sending API over HTTPS — templates, category, custom variables, batch, per-message ids. Needs an `HttpClientPort`; see [§10](#10-mailtrap-sending-api) |
| `sendmail` | Pipes to the sendmail binary with a `-f` envelope sender |
| `mail` | PHP's `mail()` |
| `array` | Captures in memory — **tests** (`messages()`/`last()`/`count()`/`flush()`) |
| `log` | Writes the full MIME to the log — **dev** |

Transports come in two kinds:

- **MIME** — `Infrastructure\Transport\Transport`:
  `send(string $envelopeFrom, array $recipients, string $mime): void`. The Mailer
  builds and signs the MIME; the transport moves the bytes.
- **Message** — `Infrastructure\Transport\MessageTransport` (extends the above):
  `sendMessage(Message): array` + `sendBatch(array, ?Message): array`. For provider
  APIs that take a structured document and build the MIME themselves. `send()`
  throws on these — there is no raw-MIME endpoint to send it to.

Add your own provider by implementing whichever fits and binding it in the
container; the Mailer, the queue payload and `SendMailJob` all branch on
`instanceof MessageTransport`, so nothing else needs to change.

### Demo routes

Self-contained [`MailDemoController`](Infrastructure/Http/MailDemoController.php)
wired to five `GET` routes so you can exercise every path from a browser.
**For learning/testing — remove the `routes[]` entries or gate them behind `auth`
before production.**

| Route | Shows | Sends? |
|---|---|---|
| `GET /mail/demo` | overview — active transport + endpoint map | no |
| `GET /mail/demo/preview?to=…` | `preview()` — the raw MIME | no |
| `GET /mail/demo/send?to=…` | `dispatch()` — rich message (cc/bcc, inline image, attachment) | yes |
| `GET /mail/demo/queue?to=…` | `enqueue()` — returns the job id | yes |
| `GET /mail/demo/view?to=…` | `MailPort::send()` — the view-based shortcut | yes |

**Safety guard:** `send`/`queue`/`view` return **403** unless a non-sending
transport (`array`/`log`) is active **or** `MAIL_DEMO_ALLOW_SEND=true`.
`preview` never sends.

> The guard used to accept `APP_DEBUG=true`. It no longer does, and the
> difference matters: these routes are `GET`, carry no auth filter, and take the
> recipient from the query string — so whatever gates them is the only thing
> between a published URL and mail leaving your verified domain, passing SPF and
> DKIM because it genuinely is you. `APP_DEBUG` is about stack traces, it gets
> switched on in production to diagnose an incident, and nobody flipping it is
> thinking about mail. `MAIL_DEMO_ALLOW_SEND` means only this one thing, so it
> cannot be enabled as a side effect.
>
> It is declared **without a default**, so it seeds commented out and an
> untouched deployment has no opinion to get wrong. It is still not a substitute
> for removing the routes — see the note below.

```bash
export MAIL_TRANSPORT=log                                   # nothing leaves the box
curl "http://localhost:8000/mail/demo/preview?to=you@example.com"
curl "http://localhost:8000/mail/demo/send?to=you@example.com"
```

### Security (defaults are security-first)

- **Header-injection proof** — every field that reaches a header is rejected if
  it contains CR/LF/NUL: addresses and display names (`Address`), custom headers
  (`Message::header`), attachment filename, **content-id and mime type**
  (`Attachment`), the **charset** (`Message::charset`), the category and the
  template uuid. The subject is the one exception — free text a user typed, so
  it is stripped rather than rejected. An attacker cannot smuggle a `Bcc:`
  through any of them.
- **Queue payloads are re-validated** — `Message::fromArray()` puts every field
  back through the ordinary setters, so a payload sitting in a queue anyone can
  write to gets exactly the same guards as one built in PHP. (Signing the queue
  is still worth doing: see the kernel's `JOB_SIGNING_SECRET`.)
- **BCC never leaks** — recipients get the mail via the envelope; `Bcc:` is never
  emitted as a header.
- **Credentials are redacted from every debug surface** — the SMTP password
  (`SmtpSettings`) and the Mailtrap API token (`MailtrapSettings`,
  `MailtrapHttp`, and so every API client and transport holding one) are
  `••••••` in `print_r()`/`var_dump()` and absent from stack traces
  (`#[\SensitiveParameter]`). The one function not covered is `var_export()`,
  which has no redaction hook — do not `var_export()` them.
- **The Mailtrap host is validated at the choke point** — a bare hostname with
  an optional port, nothing else. The host is concatenated into the request URL
  and the bearer token travels with whatever that resolves to, so a
  `MAILTRAP_HOST` carrying a path, `@` userinfo, a scheme or whitespace would
  hand the account's credential to another origin. Refused in `MailtrapHttp`,
  which every path (including `MAILTRAP_HOST`) goes through.
- **Mailtrap traffic is HTTPS-only** — the scheme is hardcoded, and the
  HttpClient adapter verifies peer and hostname and does **not** follow
  redirects, so the `Authorization` header cannot be forwarded to another host.
- **Webhooks are verified in constant time** — `MailtrapWebhookSignature` uses
  `hash_equals` over the raw body and fails closed on anything malformed.
- **TLS peer verification ON by default** (`MAIL_VERIFY_PEER`).
- **Fail-closed STARTTLS** — if the server does not advertise STARTTLS the
  connection is refused, never downgraded to plaintext.
- **No cleartext credential leak** — SMTP AUTH is refused over an unencrypted
  channel unless `MAIL_ALLOW_INSECURE_AUTH=true` is explicitly set.
- **SMTP command injection** blocked (envelope/RCPT re-validated before the wire).
- **DKIM** RSA-SHA256, relaxed/relaxed.

### Robustness

- **RFC 5322 header folding** — no header line exceeds 998 chars (folded at
  whitespace), so long To/Cc lists and Subjects survive strict MTAs.
- **RFC 2047 encoded-words** — non-ASCII Subjects/names split into multiple
  ≤75-char encoded-words, never one oversized blob.
- **Auto plain-text alternative** generated from HTML.
- **Fast path preserved** — short ASCII headers skip MIME-encoding and folding
  entirely; encoding kicks in only when a value needs it.

### Errors

Everything the plugin throws is `Plugins\Mail\Domain\MailException`
(`\RuntimeException`): no `From` address, an address failing the CR/LF guard, an
unreadable attachment, an SMTP handshake/AUTH failure, a DKIM key that will not
load, or an invalid `SmtpSettings` (empty host list, out-of-range port, unknown
encryption). Callers that treat mail as non-critical (the Auth password flows,
for instance) catch `\Throwable` and carry on.

One type rather than a hierarchy, so a caller writes one `catch` across
building, SMTP and the API. The distinction a hierarchy would encode is carried
on the exception instead:

| Member | Meaning |
|---|---|
| `$status` | the HTTP status it came from; `0` when it never reached a response |
| `isPermanent()` | `true` only for a confirmed 4xx — see *Failure semantics* |
| `$context` | structured detail, e.g. a partial batch's delivered ids. Never message bodies |

### Layout

```
API/Contracts/MailerContract            message() · dispatch() · enqueue() · dispatchBatch() · preview()
API/Contracts/MailerFactoryContract     forSmtp(SmtpSettings) · forMailtrap(MailtrapSettings) → MailerContract
API/DTOs/SmtpSettings                   validated hosts/port/encryption/credentials/From; password never leaks
API/DTOs/MailtrapSettings               validated token/stream/inbox/From; token never leaks
API/DTOs/MailtrapStream                 transactional | bulk | sandbox — the stream IS the host
API/MailtrapWebhookSignature            HMAC-SHA256 verification for inbound delivery webhooks
API/Contracts/MailtrapApiContract       the management API — 25 resource clients, covering all 151 methods of the official SDK
API/Mailtrap/MailtrapApi                implements it; a thin factory over MailtrapHttp
API/Mailtrap/MailtrapHttp               the ONE place a Mailtrap HTTP call is made (token, host, errors)
API/Mailtrap/{General,Sending,Sandbox,Inbound}/  the resource clients
API/Mailtrap/DTO/                       validated request objects + enums for the closed sets
Application/Mailer                      MailPort + MailerContract; routes MIME vs structured delivery
Application/MailerFactory               builds a Mailer for SmtpSettings — no QueuePort, always inline
Application/Jobs/SendMailJob            background delivery (job name "mail.send")
Domain/                                 Message (builder) · Address (CRLF guard) · Attachment · Priority · MailException
Infrastructure/Mime/MimeBuilder         multipart mixed/related/alternative + QP/base64 encoders
Infrastructure/Security/DkimSigner      RSA-SHA256 relaxed/relaxed
Infrastructure/Mailtrap/MailtrapPayload    Message → the Mailtrap Send API JSON document
Infrastructure/Transport/               Transport + Smtp/Sendmail/Mail/Array/Log
Infrastructure/Transport/MessageTransport  structured delivery — sendMessage() / sendBatch()
Infrastructure/Transport/MailtrapTransport Mailtrap Sending API over HttpClientPort
Infrastructure/Transport/TransportFactory  Transport + DKIM from config — shared by Provider and MailerFactory
Infrastructure/Http/MailDemoController  demo routes (GET /mail/demo/*) — remove for prod
config/mail.php                         all MAIL_* configuration
```

### Rules

**Do** — type cross-plugin callers against `MailPort` · declare
`"requires": ["mail.delivery", "view.rendering"]` on any route that mails a view ·
keep `MAIL_VERIFY_PEER=true` · use `array`/`log` transports in tests and dev ·
verify `Mailtrap-Signature` on every webhook before acting on it ·
declare `"requires": ["http.client"]` on routes that use `MAIL_TRANSPORT=mailtrap` **or**
`MailtrapApiContract` · start from `accounts()->getList()` when you do not know the account id ·
`queue()` anything on a request path · treat mail failure as non-fatal in flows
that already committed their real work.

**Don't** — enable `MAIL_ALLOW_INSECURE_AUTH` · ship the `/mail/demo/*` routes ·
put user input into a header without going through `Message::header()` ·
`getenv()` a `MAIL_*` value · assume `enqueue()` returned a job id without a
`QueuePort` bound · expect `MAIL_DKIM_*` to apply on the Mailtrap API path
(the provider signs) · set `subject`/`html`/`category` alongside `template()` (it throws — the template supplies them) · re-send a whole batch after a partial failure without reading `$e->context['sent']` first ·
treat a Mailtrap HTTP 200 as delivery without checking `success` (the transport
does this for you — do not bypass it) · set `sender()`/`returnPath()` on the
Mailtrap API path (there is no envelope field; it throws) · delete a suppression
without knowing why the address was suppressed · pass view data as the renderer's second argument — that
parameter is render *options*; template variables go through `setData()`.
