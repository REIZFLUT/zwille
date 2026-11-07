<?php

declare(strict_types=1);

namespace App\Security;

/**
 * What an API token may do. Personal tokens carry every scope (they act exactly like their
 * user); agent tokens carry only the scopes chosen when they were issued.
 */
final class ApiScope
{
    public const string DOMAINS_READ = 'domains:read';
    public const string LINKS_READ = 'links:read';
    public const string LINKS_WRITE = 'links:write';
    public const string LINKS_DELETE = 'links:delete';
    public const string STATS_READ = 'stats:read';

    public const array ALL = [
        self::DOMAINS_READ,
        self::LINKS_READ,
        self::LINKS_WRITE,
        self::LINKS_DELETE,
        self::STATS_READ,
    ];

    /** Pre-selected for a new agent token: look, but do not touch. */
    public const array AGENT_DEFAULT = [
        self::DOMAINS_READ,
        self::LINKS_READ,
        self::STATS_READ,
    ];

    /** Scopes that change data and therefore count against the write budget. */
    public const array WRITING = [
        self::LINKS_WRITE,
        self::LINKS_DELETE,
    ];

    private function __construct()
    {
    }
}
