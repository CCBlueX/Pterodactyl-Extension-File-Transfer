import { useLayoutEffect, useState } from 'react';

/*
 * The panel has no slot inside the file manager's button group ("New folder", "Upload", "New file"),
 * so the toolbar button is portalled into it. All knowledge of the panel's markup lives in this file.
 * Trash Bin ships the same file: every host carries HOST with its order, so both line up as
 * Transfer, Trash, then the native buttons.
 */
const HOST = 'data-file-toolbar-host';
const ORDER = 10;

/** NewButton's look. Below md the group is a grid: our buttons share a row, or fill it when alone. */
export const toolbarButtonClass = (shared: boolean) =>
    [
        'xfer:inline-flex xfer:h-9 xfer:shrink-0 xfer:items-center xfer:justify-center xfer:gap-2 xfer:px-3 xfer:normal-case xfer:font-medium xfer:tracking-normal',
        shared ? 'xfer:max-md:col-span-1!' : 'xfer:max-md:col-span-full!',
    ].join(' ');

/** Native buttons start on a new row below ours; below sm the first one fills its row again, as it does without us. */
const HOST_CLASS = 'xfer:max-md:[&+*]:col-start-1 xfer:max-sm:[&+*]:col-span-2';

/** The slot renders right after the toolbar row (the first child of the same parent); the group is the row's last child. */
export function findFileActions(slot: Element): HTMLElement | null {
    const row = slot.parentElement?.firstElementChild;
    const group = row && row !== slot && row.childElementCount > 1 ? row.lastElementChild : null;

    return group instanceof HTMLElement && group.querySelector('button') ? group : null;
}

const isHost = (element: Element | null): element is HTMLElement => element?.hasAttribute(HOST) ?? false;
const orderOf = (element: Element) => Number(element.getAttribute(HOST));

/** Hosts sit at the start of the group, sorted by their order. */
function isPlaced(host: HTMLElement, group: HTMLElement): boolean {
    if (host.parentElement !== group) {
        return false;
    }

    for (let before = host.previousElementSibling; before; before = before.previousElementSibling) {
        if (!isHost(before) || orderOf(before) > ORDER) {
            return false;
        }
    }

    const next = host.nextElementSibling;

    return !(isHost(next) && orderOf(next) < ORDER);
}

function place(host: HTMLElement, group: HTMLElement): void {
    let next = group.firstElementChild;

    while (next && (next === host || (isHost(next) && orderOf(next) <= ORDER))) {
        next = next.nextElementSibling;
    }

    group.insertBefore(host, next);
}

export interface ToolbarSpot {
    /** Where to portal the button, or null to render it in the slot (users without the create permission have no group). */
    host: HTMLElement | null;
    /** Another extension's button is in the group too. */
    shared: boolean;
}

/** A `display: contents` node at the start of the native button group, kept in place while the panel re-renders. */
export function useToolbarSpot(slot: HTMLElement | null): ToolbarSpot {
    const [host] = useState(() => {
        const node = document.createElement('div');

        node.setAttribute(HOST, String(ORDER));
        node.className = HOST_CLASS;
        node.style.display = 'contents';

        return node;
    });
    const [spot, setSpot] = useState<ToolbarSpot>({ host: null, shared: false });

    // Before paint, so the button does not flash in the slot first.
    useLayoutEffect(() => {
        const container = slot?.parentElement;

        if (!slot || !container) {
            return;
        }

        const update = () => {
            const group = findFileActions(slot);

            if (!group) {
                host.remove();
                setSpot((current) => (current.host ? { host: null, shared: false } : current));

                return;
            }

            if (!isPlaced(host, group)) {
                place(host, group);
            }

            const shared = [...group.children].filter(isHost).length > 1;

            setSpot((current) => (current.host === host && current.shared === shared ? current : { host, shared }));
        };

        update();
        const observer = new MutationObserver(update);
        observer.observe(container, { childList: true, subtree: true });

        return () => {
            observer.disconnect();
            host.remove();
        };
    }, [slot, host]);

    return spot;
}
