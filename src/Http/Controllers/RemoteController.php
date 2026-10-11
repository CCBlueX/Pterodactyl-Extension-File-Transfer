<?php

declare(strict_types=1);

namespace FileTransfer\Http\Controllers;

use FileTransfer\Http\Access;
use FileTransfer\Remote\Clients;
use FileTransfer\Remote\Endpoint;
use FileTransfer\Remote\RemoteException;
use FileTransfer\Settings;
use FileTransfer\Support\Entry;
use FileTransfer\Support\Paths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Models\Server;

/**
 * Tests a login and browses the remote server. Nothing is stored between requests.
 */
final readonly class RemoteController
{
    public function __construct(private Clients $clients, private Settings $settings, private Access $access) {}

    public function test(Request $request, Server $server): JsonResponse
    {
        $endpoint = $this->endpoint($request, $server);

        return $this->connected($endpoint, static fn ($client): array => [
            'home' => Paths::normalise($client->home()) ?? '/',
            'fingerprint' => $client->fingerprint(),
        ]);
    }

    public function list(Request $request, Server $server): JsonResponse
    {
        $endpoint = $this->endpoint($request, $server);
        $path = Paths::normalise((string) $request->input('path', '/'));
        abort_if($path === null, 422, 'That path is not allowed.');

        return $this->connected($endpoint, static fn ($client): array => [
            'path' => $path,
            'entries' => array_map(static fn (Entry $entry): array => $entry->toArray(), $client->list($path)),
        ]);
    }

    private function endpoint(Request $request, Server $server): Endpoint
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, $server, 'import') || $this->access->allows($user, $server, 'export'), 403, 'You do not have permission to do that.');

        // Stops the panel from being used to probe other hosts.
        abort_unless(RateLimiter::attempt("ext-file-transfer:remote:{$user->id}", 60, static fn (): bool => true), 429, 'Too many requests to remote servers. Wait a minute.');

        return Endpoint::fromInput($request->validate(Endpoint::rules($this->settings->protocols())));
    }

    /** @param callable(\FileTransfer\Remote\Client): array<string, mixed> $use */
    private function connected(Endpoint $endpoint, callable $use): JsonResponse
    {
        try {
            $client = $this->clients->open($endpoint);
        } catch (RemoteException $exception) {
            abort(422, $exception->getMessage());
        }

        try {
            return new JsonResponse($use($client));
        } catch (RemoteException $exception) {
            abort(422, $exception->getMessage());
        } finally {
            $client->close();
        }
    }
}
