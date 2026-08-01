<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'production';

// rhtcircle.ca is HTTPS-only: Cloudflare enforces Always Use HTTPS at the edge.
// Deployed environments therefore require Secure cookies; local development over
// http://127.0.0.1 must not, or the browser would discard every cookie and the
// admin login would be untestable.
$httpsOnly = \in_array($environment, ['production', 'staging'], true);

return [
    // Debug mode. Controls error detail display, debug toolbar, and debug headers.
    // Override with APP_DEBUG env var. MUST be false in production.
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),

    // Minimum log level for the default log handler.
    // Override with LOG_LEVEL env var. Values: debug, info, notice, warning, error, critical, alert, emergency.
    'log_level' => getenv('LOG_LEVEL') ?: 'warning',

    // Application environment. Controls dev-only features (fallback account, CORS relaxation).
    // Override with APP_ENV env var. Values: local, dev, development, staging, production.
    'environment' => getenv('APP_ENV') ?: 'production',

    // SQLite database path. Null means "resolve in kernel":
    // WAASEYAA_DB env var -> {projectRoot}/storage/waaseyaa.sqlite fallback.
    // Set an explicit path here to override both.
    'database' => null,

    // Config sync directory. Override with WAASEYAA_CONFIG_DIR env var.
    'config_dir' => getenv('WAASEYAA_CONFIG_DIR') ?: __DIR__ . '/sync',

    // File storage root for LocalFileRepository (media package).
    'files_dir' => getenv('WAASEYAA_FILES_DIR') ?: __DIR__ . '/../storage/files',

    // Bearer auth settings for machine clients.
    // JWT uses HS256 with this shared secret.
    'jwt_secret' => getenv('WAASEYAA_JWT_SECRET') ?: '',
    // API key map: raw key => uid. Example: ['dev-machine-key' => 1].
    'api_keys' => [],
    // Dev-only fallback account for local built-in server workflows.
    // Must remain false outside local development.
    'auth' => [
        'dev_fallback_account' => filter_var(
            getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT') ?: false,
            FILTER_VALIDATE_BOOLEAN,
        ),
        // alpha.250 hardening: the reset/verify-token HMAC secret must be
        // configured in production (the framework rejects an empty/placeholder
        // value). Use a dedicated secret if set, otherwise reuse the existing
        // JWT secret (already vault-rendered into the env) so boot succeeds.
        'token_secret' => getenv('WAASEYAA_AUTH_TOKEN_SECRET') ?: (getenv('WAASEYAA_JWT_SECRET') ?: ''),
    ],

    // Upload validation (POST /api/media/upload).
    'upload_max_bytes' => 10 * 1024 * 1024, // 10 MiB
    'upload_allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'application/octet-stream',
    ],

    // Allowed CORS origins for the admin SPA.
    'cors_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000'],

    // Locale negotiation defaults used by public SSR path resolution.
    'i18n' => [
        'languages' => [
            ['id' => 'en', 'label' => 'English', 'is_default' => true],
        ],
    ],

    // Translation behaviour for content entities. (M-006 / FR-037, FR-041, C-004)
    //
    // - read_active_language (bool, default false): when true, read paths resolve the
    //   active language translation via EntityTranslationManager. Default false keeps
    //   the legacy behaviour (read the base entity row) so existing installs are
    //   unaffected until they opt in. Override with WAASEYAA_TRANSLATION_READ_ACTIVE_LANGUAGE.
    // - fallback_chain (?array, default null): null means "use the i18n default language
    //   list order as the fallback chain". Set an explicit list of language ids
    //   (e.g. ['oj', 'en']) to override per-site.
    'translation' => [
        'read_active_language' => filter_var(
            getenv('WAASEYAA_TRANSLATION_READ_ACTIVE_LANGUAGE') ?: false,
            FILTER_VALIDATE_BOOLEAN,
        ),
        'fallback_chain' => null,
    ],

    // SSR theme id discovered from Composer package metadata.
    // Theme packages expose extra.waaseyaa.theme in composer.json.
    'ssr' => [
        'theme' => getenv('WAASEYAA_SSR_THEME') ?: '',
        'cache_max_age' => (int) (getenv('WAASEYAA_SSR_CACHE_MAX_AGE') ?: 300),
    ],

    // AI embedding pipeline configuration.
    'ai' => [
        // 'ollama' or 'openai'. Empty disables embedding generation.
        'embedding_provider' => getenv('WAASEYAA_EMBEDDING_PROVIDER') ?: '',
        'ollama_endpoint' => getenv('WAASEYAA_OLLAMA_ENDPOINT') ?: 'http://127.0.0.1:11434/api/embeddings',
        'ollama_model' => getenv('WAASEYAA_OLLAMA_MODEL') ?: 'nomic-embed-text',
        'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
        'openai_embedding_model' => getenv('WAASEYAA_OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small',
        // Per-entity field selection used for embedding text extraction.
        'embedding_fields' => [
            'node' => ['title', 'body'],
        ],
    ],

    // Trusted reverse proxy (issue #13).
    //
    // Cloudflare terminates TLS at its edge and forwards plain HTTP through
    // cloudflared to Caddy, which speaks FastCGI to this app. The app therefore
    // never sees a TLS connection and $_SERVER['HTTPS'] is never 'on', so the
    // framework's fail-closed `secure => 'auto'` detection cannot prove HTTPS on
    // its own and correctly refuses to mark cookies Secure.
    //
    // 'REMOTE_ADDR' is Symfony's sentinel for "trust the single connecting peer,
    // resolved per request". It is deliberately NOT a CIDR range: the peer here
    // is always the Caddy container on the internal docker network, and this
    // container publishes no ports (9000/tcp is unmapped), so nothing outside
    // that network can reach php-fpm to forge X-Forwarded-*. Cloudflare sends
    // X-Forwarded-Proto: https, which is what makes Request::isSecure() true.
    //
    // Empty outside deployed environments, so local development keeps Symfony's
    // default of ignoring every X-Forwarded-* header.
    'trusted_proxies' => $httpsOnly ? ['REMOTE_ADDR'] : [],

    // Session handling.
    //
    // Anonymous GET/HEAD requests to these paths never start a PHP session, so
    // they set no PHPSESSID and (with no session to hold a token) no
    // XSRF-TOKEN either. That makes the public site shared-cache friendly and
    // stops every crawler hit from burning a server-side session file.
    //
    // This is an ALLOWLIST, and three framework guards bound it, so the
    // surfaces that need a session keep one without being named here:
    //   - only GET/HEAD is ever stateless (every form POST gets a session);
    //   - a request already carrying PHPSESSID resumes its session normally,
    //     so a signed-in admin keeps their identity while browsing the site;
    //   - anything not listed is unaffected: /admin and /admin/login (the
    //     login form must mint a CSRF token), /api/*, /mcp, /mcp/write.
    //
    // Prefix matching is exact-segment: '/news' covers /news and /news/x but
    // never /newsletter. '/' means the ROOT PATH ONLY (framework #2154) -- it
    // is deliberately not a prefix of everything.
    //
    // Safe to include even though they carry forms or act on a token, because
    // none of this app's own code reads the session (grep: no $_SESSION, no
    // _account outside the framework's admin auth):
    //   - /contact, /updates, /standard/records-request and the Sagamok poll
    //     and petition pages submit JSON via fetch(), which CsrfMiddleware
    //     exempts by content type, and no script reads the XSRF cookie;
    //   - /news/preview/{nid} is authorized by a short-lived HMAC grant, not a
    //     session, and already sends `private, no-store` + noindex;
    //   - /updates/remove and /petition/remove/{token} are GET one-click
    //     actions authorized by the token in the URL.
    // The Sagamok public-website monitor ships DISABLED. Enabling it means this
    // app starts making automated requests to another Nation's website on a
    // timer, which is a maintainer and Council decision rather than a
    // deployment side effect. Remove the entry below to enable it.
    'schedule' => [
        'disabled_entries' => [
            \App\Schedule\SagamokMonitorSchedule::class,
        ],
    ],

    'session' => [
        // Cookie policy (issue #13). PHPSESSID gets Secure explicitly rather
        // than via `secure => 'auto'` detection, so it stays Secure in a
        // deployed environment even if a proxy header is ever missing or
        // changed. 'auto' locally keeps http://127.0.0.1 development working.
        //
        // The companion XSRF-TOKEN cookie has no equivalent switch: CsrfMiddleware
        // builds it with `->withSecure($request->isSecure())`, so the only lever
        // for it is the `trusted_proxies` setting above. Both cookies are asserted
        // in tests/Integration/Http/SecureCookiePolicyTest.php.
        'cookie' => [
            'secure' => $httpsOnly ? true : 'auto',
        ],

        'stateless_paths' => [
            '/',                   // homepage (root path only)
            '/about',
            '/circle',
            '/communities',        // every community hub, incl. /communities/sagamok/*
            '/community-life',
            '/contact',
            '/get-involved',
            '/land',
            '/live',
            '/llms.txt',
            '/media',              // /media/uploads/* served images
            '/myth-versus-record',
            '/news',               // index, articles, and signed previews
            '/petition',           // token-authorized removal link
            '/public',
            '/resources',
            '/review',
            '/safety',
            '/sitemap.xml',
            '/standard',
            '/treaty',
            '/treaty-wide',
            '/updates',
        ],
    ],

    // MCP publishing surface (#2136): the article.* / asset.* tool set is
    // reachable only through /mcp/write under this capability allowlist,
    // authenticated by the RHTCIRCLE_MCP_PUBLISHER_TOKEN bearer binding
    // (PublishingServiceProvider). Rate limit protects agent misuse.
    'mcp' => [
        'write_tier' => [
            'capabilities' => ['publish rht articles'],
        ],
        'rate_limit' => [
            'max_requests' => 120,
            'window_seconds' => 60,
        ],
    ],
];
