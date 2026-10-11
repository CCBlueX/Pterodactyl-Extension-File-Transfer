import type { ReactNode } from 'react';
import type { TransferStatus } from '../api';
import { STATUS_LABELS } from '../lib/format';

const TONES = {
    neutral: 'xfer:border-border xfer:bg-muted xfer:text-muted-foreground',
    success: 'xfer:border-success/25 xfer:bg-success/10 xfer:text-success',
    warning: 'xfer:border-warning/25 xfer:bg-warning/10 xfer:text-warning',
    danger: 'xfer:border-destructive/25 xfer:bg-destructive/10 xfer:text-destructive',
};

const STATUS_TONES: Record<TransferStatus, keyof typeof TONES> = {
    queued: 'neutral',
    running: 'warning',
    cancelling: 'warning',
    done: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

/** Styled like the panel's node status badge. */
export default function Pill({ tone = 'neutral', children }: { tone?: keyof typeof TONES; children: ReactNode }) {
    return (
        <span className={`xfer:inline-flex xfer:items-center xfer:gap-1.5 xfer:whitespace-nowrap xfer:rounded-sm xfer:border xfer:px-2 xfer:py-0.5 xfer:text-xs xfer:font-medium ${TONES[tone]}`}>
            <span aria-hidden className='xfer:h-1.5 xfer:w-1.5 xfer:rounded-full xfer:bg-current' />
            {children}
        </span>
    );
}

export function StatusPill({ status, failed = 0 }: { status: TransferStatus; failed?: number }) {
    if (status === 'done' && failed > 0) {
        return <Pill tone='warning'>Done with errors</Pill>;
    }

    return <Pill tone={STATUS_TONES[status]}>{STATUS_LABELS[status]}</Pill>;
}
