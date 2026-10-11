<?php

declare(strict_types=1);

namespace FileTransfer\Support;

use Closure;

/**
 * Keeps user supplied hosts from pointing the panel at its own network. The address is
 * looked up once and the connection is made to that address, so DNS cannot change it later.
 */
final class Hosts
{
    /** Ranges that are not on the public internet, including cloud metadata addresses. */
    private const array BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** A host name or IP address as typed by a user, or null when it is not one. */
    public static function clean(string $host): ?string
    {
        $host = mb_strtolower(mb_trim(mb_trim($host), '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $valid = mb_strlen($host) <= 253 && preg_match('/^(?=.*[a-z])([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.?$/', $host) === 1;

        return $valid ? mb_rtrim($host, '.') : null;
    }

    /**
     * The address to connect to. Private, loopback, link-local and metadata addresses are
     * refused unless they are allowed.
     *
     * @param  (Closure(string): list<string>)|null  $lookup  Returns the A and AAAA records of a host.
     * @return array{address: string|null, problem: string|null}
     */
    public static function address(string $host, bool $allowPrivate, ?Closure $lookup = null): array
    {
        $clean = self::clean($host);

        if ($clean === null) {
            return ['address' => null, 'problem' => 'Enter a valid host name or IP address.'];
        }

        $addresses = filter_var($clean, FILTER_VALIDATE_IP) !== false ? [$clean] : ($lookup ?? self::lookup(...))($clean);

        if ($addresses === []) {
            return ['address' => null, 'problem' => "{$clean} could not be found."];
        }

        if (! $allowPrivate) {
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    return ['address' => null, 'problem' => "{$clean} points to a private address."];
                }
            }
        }

        // IPv4 first: many hosts publish AAAA records the panel's network cannot reach.
        usort($addresses, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return ['address' => $addresses[0], 'problem' => null];
    }

    public static function isPublic(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return false;
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $binary, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $network = (string) inet_pton($network);

        if (mb_strlen($network, '8bit') !== mb_strlen($binary, '8bit')) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($binary, $network, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
