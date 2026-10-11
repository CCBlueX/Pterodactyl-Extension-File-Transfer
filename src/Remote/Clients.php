<?php

declare(strict_types=1);

namespace FileTransfer\Remote;

use FileTransfer\Settings;
use FileTransfer\Support\Hosts;

/**
 * Opens connections after checking the protocol and the host against the admin settings.
 */
final readonly class Clients
{
    public function __construct(private Settings $settings) {}

    public function open(Endpoint $endpoint): Client
    {
        if (! in_array($endpoint->protocol, $this->settings->protocols(), true)) {
            throw RemoteException::connection('This protocol is turned off by the administrator.');
        }

        ['address' => $address, 'problem' => $problem] = Hosts::address($endpoint->host, $this->settings->allowPrivateHosts());

        if ($address === null) {
            throw RemoteException::connection((string) $problem);
        }

        $timeout = $this->settings->connectTimeout();

        return $endpoint->protocol === 'sftp'
            ? SftpClient::connect($endpoint, $address, $timeout)
            : FtpClient::connect($endpoint, $address, $timeout);
    }
}
