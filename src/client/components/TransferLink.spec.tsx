import { afterEach, expect, test } from 'vitest';
import { cleanup, screen, waitFor } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { createExtensionTestHost, createTestServer, renderView } from '@pterodactyl/sdk/testing';
import TransferLink from './TransferLink';

let rendered: { unmount: () => void } | undefined;

afterEach(() => {
    rendered?.unmount();
    rendered = undefined;
    cleanup();
});

/** The panel's real file manager with our component in the toolbar slot, as the panel renders it. */
function renderToolbar(fixture: 'directory' | 'readOnly' = 'directory') {
    const host = createExtensionTestHost({ extensionId: 'file-transfer', prefix: 'xfer', server: createTestServer() });
    // The view renders the slot outside any server screen, so the test host provides the server context.
    const InSlot = (props: ComponentProps<typeof TransferLink>) => (
        <host.Wrapper>
            <TransferLink {...props} />
        </host.Wrapper>
    );
    const view = renderView('server.files.manager', { fixture, prefix: 'xfer', slots: { 'server.files.toolbar': InSlot } });
    rendered = { unmount: () => (view.unmount(), host.dispose()) };
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
    renderToolbar();

    const transfer = await findTransfer();
    const folder = screen.getByRole('button', { name: 'New folder' });
    const host = transfer.parentElement!;

    expect(host.getAttribute('data-file-toolbar-host')).toBe('10');
    expect(host.style.display).toBe('contents');
    expect(host.parentElement).toBe(folder.parentElement);
    expect(host.parentElement!.firstElementChild).toBe(host);
    expect(transfer.className).toContain('xfer:max-md:col-span-full!');

    rendered!.unmount();
    rendered = undefined;
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
    renderToolbar('readOnly');

    const transfer = await screen.findByRole('button', { name: 'Transfer' });

    expect(transfer.closest('[data-file-toolbar-host]')).toBeNull();
    expect(screen.queryByRole('button', { name: 'New folder' })).toBeNull();
    expect(document.querySelector('[data-file-toolbar-host]')).toBeNull();
});
