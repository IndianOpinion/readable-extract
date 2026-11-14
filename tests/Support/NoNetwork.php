<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Tests\Support;

/**
 * Replaces the http, https and ftp stream wrappers with one that records
 * and refuses every open, and installs a libxml entity loader that records
 * and refuses every external load. Proves code paths never reach the
 * network.
 */
final class NoNetwork
{
    /** @var list<string> */
    public static array $attempts = [];

    /** @var resource|null */
    public $context;

    private const SCHEMES = ['http', 'https', 'ftp'];

    /** @var list<string> */
    private static array $replaced = [];

    public static function install(): void
    {
        self::$attempts = [];
        self::$replaced = [];
        foreach (self::SCHEMES as $s) {
            if (in_array($s, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($s);
                self::$replaced[] = $s;
            }
            stream_wrapper_register($s, self::class);
        }
        libxml_set_external_entity_loader(static function (?string $public, string $system, array $context) {
            self::$attempts[] = 'entity:' . $system;
            return null;
        });
    }

    public static function uninstall(): void
    {
        foreach (self::SCHEMES as $s) {
            if (in_array($s, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($s);
            }
            if (in_array($s, self::$replaced, true)) {
                stream_wrapper_restore($s);
            }
        }
        libxml_set_external_entity_loader(null);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$attempts[] = $path;
        return false;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$attempts[] = $path;
        return false;
    }
}
