<?php

declare(strict_types=1);

namespace FileTransfer\Tests\Unit;

use FileTransfer\Support\Hosts;
use PHPUnit\Framework\TestCase;

final class HostsTest extends TestCase
{
    public function test_private_loopback_link_local_and_metadata_addresses_are_not_public(): void
    {
        foreach ([
            '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.10', '169.254.169.254',
            '100.100.100.200', '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '192.0.0.192',
            '::1', '::', 'fe80::1', 'fd00:ec2::254', 'fc00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1',
            '64:ff9b::7f00:1', '2002:7f00:1::1', 'ff02::1', 'not-an-ip',
        ] as $address) {
            $this->assertFalse(Hosts::isPublic($address), $address);
        }

        foreach (['1.1.1.1', '51.38.117.51', '172.32.0.1', '100.128.0.1', '2606:4700:4700::1111', '2a01:4f8::1'] as $address) {
            $this->assertTrue(Hosts::isPublic($address), $address);
        }
    }

    public function test_hosts_are_looked_up_once_and_checked(): void
    {
        $dns = static fn (string $host): array => match ($host) {
            'files.example.com' => ['2606:4700::1', '93.184.216.34'],
            'rebind.example.com' => ['93.184.216.34', '127.0.0.1'],
            'internal.example.com' => ['10.0.0.5'],
            default => [],
        };

        $this->assertSame(['address' => '93.184.216.34', 'problem' => null], Hosts::address('files.example.com', false, $dns));
        $this->assertSame('rebind.example.com points to a private address.', Hosts::address('rebind.example.com', false, $dns)['problem']);
        $this->assertSame('internal.example.com points to a private address.', Hosts::address('internal.example.com', false, $dns)['problem']);
        $this->assertSame('10.0.0.5', Hosts::address('internal.example.com', true, $dns)['address']);
        $this->assertSame('missing.example.com could not be found.', Hosts::address('missing.example.com', false, $dns)['problem']);
        $this->assertNotNull(Hosts::address('[::1]', false, $dns)['problem']);
        $this->assertSame('::1', Hosts::address('[::1]', true, $dns)['address']);
        $this->assertSame('1.1.1.1', Hosts::address(' 1.1.1.1 ', false, $dns)['address']);
    }

    public function test_only_host_names_and_addresses_are_accepted(): void
    {
        $this->assertSame('files.example.com', Hosts::clean('Files.Example.com.'));
        $this->assertSame('2a01:4f8::1', Hosts::clean('[2a01:4f8::1]'));
        $this->assertNull(Hosts::clean('sftp://files.example.com'));
        $this->assertNull(Hosts::clean('files.example.com:22'));
        $this->assertNull(Hosts::clean('user@files.example.com'));
        $this->assertNull(Hosts::clean('-bad.example.com'));
        $this->assertNull(Hosts::clean(''));
        $this->assertNull(Hosts::clean('1234'));
    }
}
