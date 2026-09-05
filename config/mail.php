<?php

declare(strict_types=1);

/**
 * Mail configuration. The Provider reads this to build the transport, defaults
 * and DKIM signer. All values fall back to env() so nothing is hard-coded.
 */
return [
    // smtp | sendmail | mail | array | log
    'transport' => env('MAIL_TRANSPORT', 'smtp'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', ''),
        'name'    => env('MAIL_FROM_NAME', ''),
    ],

    'charset' => env('MAIL_CHARSET', 'UTF-8'),
    'queue'   => env('MAIL_QUEUE', 'mail'),

    /**
     * URGENT MAIL — delivered inline instead of queued.
     *
     * A verification or password-reset link is worthless later: the person is
     * sitting on the "check your inbox" screen right now, and queueing it makes
     * delivery depend on a worker being up. That is a much worse failure than a
     * second of latency on the request, because when the worker is NOT running
     * the mail is never sent at all and nothing says so.
     *
     * So mail rendered from one of these VIEWS is sent inline -- unless the
     * `max_inline` concurrency slots are already taken, in which case it falls
     * back to the queue rather than piling web workers up on the SMTP socket.
     *
     * Matched on the VIEW name, never the subject: the subject is translated
     * ("Verify your email address" / "Vérifiez votre adresse e-mail"), so
     * matching it would silently stop working on a French edition. The view
     * name is a stable identifier. A trailing '*' matches a prefix, so
     * 'auth::emails/*' covers a whole plugin's transactional mail.
     *
     * The default lists ONLY 'user::emails/verify', because that is the only
     * view that exists today. Naming views that ship later would look like
     * working configuration while matching nothing.
     */
    'urgent' => [
        'views' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_URGENT_VIEWS', 'user::emails/verify')),
        ))),

        // Concurrent inline sends allowed before urgent mail queues instead.
        // 0 disables inline delivery entirely (everything queues, the old
        // behaviour). Requires a CROSS-PROCESS CachePort to be meaningful --
        // with a per-process cache each PHP-FPM worker sees its own slots.
        'max_inline' => (int) env('MAIL_URGENT_MAX_INLINE', 3),

        // Seconds a slot is held if a process dies mid-send. Keep it just above
        // the SMTP timeout so a crash cannot wedge inline delivery for long.
        'lock_ttl' => (int) env('MAIL_URGENT_LOCK_TTL', 20),
    ],

    'smtp' => [
        // Comma-separated for failover, e.g. "smtp1.example.com,smtp2.example.com".
        'hosts'       => env('MAIL_SMTP_HOSTS', env('MAIL_HOST', 'localhost')),
        'port'        => (int) env('MAIL_PORT', 587),
        'encryption'  => env('MAIL_ENCRYPTION', 'tls'),        // tls | ssl | none
        'username'    => env('MAIL_USERNAME', ''),
        'password'    => env('MAIL_PASSWORD', ''),
        'auth_mode'   => env('MAIL_AUTH_MODE', 'auto'),        // auto|plain|login|cram-md5|xoauth2|none
        'oauth_token' => env('MAIL_OAUTH_TOKEN', ''),
        'helo_domain' => env('MAIL_HELO_DOMAIN', ''),
        'timeout'     => (int) env('MAIL_TIMEOUT', 30),
        'verify_peer' => filter_var(env('MAIL_VERIFY_PEER', 'true'), FILTER_VALIDATE_BOOL),
        'keep_alive'  => filter_var(env('MAIL_KEEP_ALIVE', 'false'), FILTER_VALIDATE_BOOL),
        // Security: NEVER auth over plaintext unless explicitly forced.
        'allow_insecure_auth' => filter_var(env('MAIL_ALLOW_INSECURE_AUTH', 'false'), FILTER_VALIDATE_BOOL),
    ],

    'sendmail' => [
        'binary' => env('MAIL_SENDMAIL_BINARY', '/usr/sbin/sendmail'),
    ],

    // DKIM signing — leave domain/selector/key empty to disable.
    'dkim' => [
        'domain'      => env('MAIL_DKIM_DOMAIN', ''),
        'selector'    => env('MAIL_DKIM_SELECTOR', ''),
        // PEM string OR a path to the private key file.
        'private_key' => env('MAIL_DKIM_KEY', ''),
    ],
];
