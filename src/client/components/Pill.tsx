import { Badge } from '@pterodactyl/sdk';
import type { TransferStatus } from '../api';
import { STATUS_LABELS } from '../lib/format';

const STATUS_TONES = {
    queued: 'neutral',
    running: 'warning',
    cancelling: 'warning',
    done: 'success',
    failed: 'danger',
    cancelled: 'neutral',
} as const satisfies Record<TransferStatus, string>;

export function StatusPill({ status, failed = 0 }: { status: TransferStatus; failed?: number }) {
    if (status === 'done' && failed > 0) {
        return <Badge tone='warning'>Done with errors</Badge>;
    }

    return <Badge tone={STATUS_TONES[status]}>{STATUS_LABELS[status]}</Badge>;
}
