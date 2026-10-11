<?php

declare(strict_types=1);

namespace FileTransfer\Http\Controllers;

use FileTransfer\Http\Access;
use FileTransfer\Models\Item;
use FileTransfer\Models\Transfer;
use FileTransfer\Panel\Panel;
use FileTransfer\Remote\Endpoint;
use FileTransfer\Remote\RemoteException;
use FileTransfer\Settings;
use FileTransfer\Support\Paths;
use FileTransfer\Transfers\Starter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

final readonly class TransferController
{
    private const int MAX_SELECTION = 1000;

    public function __construct(private Access $access, private Settings $settings, private Panel $panel, private Starter $starter) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->any($user, $server), 403, 'You do not have permission to do that.');

        $transfers = Transfer::query()->involving($server->id)->with(['source', 'target', 'user'])->latest('id')->take(11)->get();
        $active = $transfers->first(static fn (Transfer $transfer): bool => $transfer->isActive());

        return new JsonResponse([
            'features' => $this->access->features($user, $server),
            'protocols' => array_values(array_map(static fn (string $value): array => ['value' => $value, 'label' => Settings::PROTOCOLS[$value]], $this->settings->protocols())),
            'limits' => ['remote' => $this->settings->maxRemoteBytes(), 'copy' => $this->settings->maxCopyBytes()],
            'can' => [
                'wipe' => $user->can('file.delete', $server),
                'archive' => $user->can('file.archive', $server),
                'read' => $this->panel->can($user, $server, Access::READ),
                'write' => $this->panel->can($user, $server, Access::WRITE),
            ],
            'active' => $active?->present(),
            'history' => $transfers->reject(static fn (Transfer $transfer): bool => $transfer->isActive())->take(10)->map(static fn (Transfer $transfer): array => $transfer->present())->values(),
        ]);
    }

    public function show(Request $request, Server $server, int $id): JsonResponse
    {
        abort_unless($this->access->any($request->user(), $server), 403, 'You do not have permission to do that.');
        $transfer = $this->find($server, $id);

        return new JsonResponse($transfer->present() + [
            'problems' => Item::query()->where('transfer_id', $transfer->id)->whereIn('status', ['failed', 'skipped'])->orderBy('id')->limit(500)->get()->map(static fn (Item $item): array => $item->present()),
        ]);
    }

    public function cancel(Request $request, Server $server, int $id): JsonResponse
    {
        $transfer = $this->find($server, $id);
        $user = $request->user();
        abort_unless($transfer->user_id === $user->id || $user->can("ext.file-transfer.{$transfer->type}", $server), 403, 'You do not have permission to do that.');

        Transfer::query()->whereKey($transfer->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelling']);

        return new JsonResponse($transfer->refresh()->present());
    }

    public function servers(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, $server, 'copy');
        $direction = $request->query('direction') === 'out' ? 'out' : 'in';
        $needs = $direction === 'in' ? Access::READ : Access::WRITE;

        $servers = array_filter($this->panel->servers($user, $server, mb_substr((string) $request->query('search', ''), 0, 100)), fn (Server $other): bool => $this->panel->can($user, $other, $needs));

        return new JsonResponse(['data' => array_values(array_map(static fn (Server $other): array => [
            'identifier' => Panel::identifier($other),
            'name' => $other->name,
            'node' => $other->node->name,
        ], $servers))]);
    }

    public function import(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, $server, 'import');

        $data = $request->validate(Endpoint::rules($this->settings->protocols()) + $this->selectionRules() + [
            'target' => ['required', 'string', 'max:4096'],
            'overwrite' => ['boolean'],
            'wipe' => ['boolean'],
            'archive' => ['boolean'],
            'confirm' => ['nullable', 'string', 'max:255'],
        ]);

        $wipe = (bool) ($data['wipe'] ?? false);
        $archive = $wipe && (bool) ($data['archive'] ?? false);

        if ($wipe) {
            abort_unless($user->can('file.delete', $server), 403, 'Wiping the folder needs the permission to delete files.');
            abort_unless(($data['confirm'] ?? null) === $server->name, 422, 'Type the server name to confirm wiping the folder.');
        }

        abort_if($archive && ! $user->can('file.archive', $server), 403, 'Saving an archive first needs the permission to create archives.');

        return $this->started(fn (): Transfer => $this->starter->import(
            $user,
            $server,
            Endpoint::fromInput($data),
            $this->path($data['root']),
            $this->selection($data),
            $this->path($data['target']),
            ['overwrite' => (bool) ($data['overwrite'] ?? false), 'wipe' => $wipe, 'archive' => $archive],
        ));
    }

    public function export(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, $server, 'export');

        $data = $request->validate(Endpoint::rules($this->settings->protocols()) + $this->selectionRules() + [
            'target' => ['required', 'string', 'max:4096'],
            'overwrite' => ['boolean'],
            'archive' => ['boolean'],
        ]);

        $archive = (bool) ($data['archive'] ?? false);
        abort_if($archive && ! $user->can('file.archive', $server), 403, 'Exporting as an archive needs the permission to create archives.');

        return $this->started(fn (): Transfer => $this->starter->export(
            $user,
            $server,
            Endpoint::fromInput($data),
            $this->path($data['root']),
            $this->selection($data),
            $this->path($data['target']),
            ['overwrite' => (bool) ($data['overwrite'] ?? false), 'archive' => $archive],
        ));
    }

    public function copy(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, $server, 'copy');

        $data = $request->validate($this->selectionRules() + [
            'direction' => ['required', 'in:in,out'],
            'server' => ['required', 'string', 'max:64'],
            'target' => ['required', 'string', 'max:4096'],
            'overwrite' => ['boolean'],
        ]);

        $other = $this->panel->findServer($data['server']);
        abort_if($other === null || $other->id === $server->id, 422, 'Pick another server.');

        [$source, $target] = $data['direction'] === 'in' ? [$other, $server] : [$server, $other];
        $this->needs($user, $source, Access::READ, 'read files on');
        $this->needs($user, $target, Access::WRITE, 'create and change files on');

        return $this->started(fn (): Transfer => $this->starter->copy(
            $user,
            $source,
            $target,
            $this->path($data['root']),
            $this->selection($data),
            $this->path($data['target']),
            (bool) ($data['overwrite'] ?? false),
        ));
    }

    /** @param callable(): Transfer $start */
    private function started(callable $start): JsonResponse
    {
        try {
            $transfer = $start();
        } catch (RemoteException $exception) {
            abort(422, $exception->getMessage());
        }

        return new JsonResponse($transfer->load(['source', 'target', 'user'])->present(), 201);
    }

    /** @param list<string> $permissions */
    private function needs(User $user, Server $server, array $permissions, string $action): void
    {
        abort_unless($this->panel->can($user, $server, $permissions), 403, "You are not allowed to {$action} {$server->name}.");
    }

    /** @return array<string, mixed> */
    private function selectionRules(): array
    {
        return [
            'root' => ['required', 'string', 'max:4096'],
            'selection' => ['present', 'array', 'max:'.self::MAX_SELECTION],
            'selection.*' => ['string', 'max:255'],
        ];
    }

    private function path(string $path): string
    {
        $normalised = Paths::normalise($path);
        abort_if($normalised === null, 422, 'That path is not allowed.');

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function selection(array $data): array
    {
        $names = Paths::names((array) $data['selection']);
        abort_if($names === null, 422, 'The selection contains a name that is not allowed.');

        return $names;
    }

    private function find(Server $server, int $id): Transfer
    {
        return Transfer::query()->involving($server->id)->with(['source', 'target', 'user'])->findOrFail($id);
    }
}
