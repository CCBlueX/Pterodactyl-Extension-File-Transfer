import type { ComponentProps } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { createComponentTestHost, createTestFileManager, createTestServer } from '@pterodactyl/sdk/testing';
import type { FileManagerSlotData } from '@pterodactyl/sdk';
import TransferLink from './TransferLink';

let dispose: (() => void) | undefined;

afterEach(() => {
    cleanup();
    dispose?.();
    dispose = undefined;
});

/** The panel's real file manager toolbar with our slot output right after it, as the panel renders it. */
function renderToolbar(permissions = { create: true, update: true, delete: true, archive: true }) {
    const model = createTestFileManager({ permissions });
    const component = createComponentTestHost('server.files.manager', { extensionId: 'file-transfer', prefix: 'xfer', model });
    const { Default, parts } = component.props;
    const data: FileManagerSlotData = { server: createTestServer(), directory: '/plugins', files: [], selectedFiles: [], isFetching: false, setSelectedFiles: vi.fn(), refresh: vi.fn() };
    const NativeToolbar = parts.toolbar;
    const toolbar = (props: ComponentProps<typeof NativeToolbar>) => (
        <>
            <NativeToolbar {...props} />
            <TransferLink data={data} />
        </>
    );
    dispose = component.dispose;

    return render(<Default parts={{ toolbar }} />, { wrapper: component.Wrapper });
}

const findTransfer = () => waitFor(() => screen.getByRole('button', { name: 'Transfer' }));

/** What another extension (Trash Bin) puts into the group. */
function otherHost(order: number) {
    const host = document.createElement('div');
    host.setAttribute('data-file-toolbar-host', String(order));
    host.style.display = 'contents';
    host.append(Object.assign(document.createElement('button'), { textContent: `Order ${order}` }));

    return host;
}

test('sits first in the native button group, before New folder', async () => {
    const { unmount } = renderToolbar();

    const transfer = await findTransfer();
    const folder = screen.getByRole('button', { name: 'New folder' });
    const host = transfer.parentElement!;

    expect(host.getAttribute('data-file-toolbar-host')).toBe('10');
    expect(host.style.display).toBe('contents');
    expect(host.parentElement).toBe(folder.parentElement);
    expect(host.parentElement!.firstElementChild).toBe(host);
    expect(transfer.className).toContain('xfer:max-md:col-span-full!');

    unmount();
    expect(document.querySelector('[data-file-toolbar-host]')).toBeNull();
});

test('lines up with the buttons of other extensions by their order', async () => {
    renderToolbar();
    const transfer = await findTransfer();
    const group = screen.getByRole('button', { name: 'New folder' }).parentElement!;

    const trash = otherHost(20);
    group.prepend(trash);
    await waitFor(() => expect(group.firstElementChild).toBe(transfer.parentElement));
    expect(transfer.parentElement!.nextElementSibling).toBe(trash);
    await waitFor(() => expect(transfer.className).toContain('xfer:max-md:col-span-1!'));

    const first = otherHost(5);
    trash.before(first);
    await waitFor(() => expect(group.firstElementChild).toBe(first));
    expect(first.nextElementSibling).toBe(transfer.parentElement);
});

test('stays in the slot when there is no button group', async () => {
    renderToolbar({ create: false, update: false, delete: true, archive: true });

    const transfer = await screen.findByRole('button', { name: 'Transfer' });

    expect(transfer.closest('[data-file-toolbar-host]')).toBeNull();
    expect(screen.queryByRole('button', { name: 'New folder' })).toBeNull();
    expect(document.querySelector('[data-file-toolbar-host]')).toBeNull();
});
