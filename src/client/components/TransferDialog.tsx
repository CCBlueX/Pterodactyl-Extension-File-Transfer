import { useQuery } from '@tanstack/react-query';
import { Button, Dialog, Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle, NamedIcon, Spinner, httpErrorToHuman, useCurrentServerRequired } from '@pterodactyl/sdk';
import { getTransfer, keys, type Transfer } from '../api';
import { describe } from '../lib/format';

export default function TransferDialog({ transfer, current, onClose }: { transfer: Transfer; current: string; onClose: () => void }) {
    const { identifier } = useCurrentServerRequired().attributes;
    const detail = useQuery({ queryKey: keys.transfer(identifier, transfer.id), queryFn: () => getTransfer(identifier, transfer.id) });
    const problems = detail.data?.problems ?? [];

    return (
        <Dialog open onClose={onClose} title={describe(transfer, current)}>
            <div className='xfer:grid xfer:gap-3 xfer:text-sm'>
                <dl className='xfer:grid xfer:grid-cols-[auto_1fr] xfer:gap-x-4 xfer:gap-y-1'>
                    <dt className='xfer:text-muted-foreground'>From</dt>
                    <dd className='xfer:break-all xfer:font-mono xfer:text-xs'>{transfer.source.path}</dd>
                    <dt className='xfer:text-muted-foreground'>To</dt>
                    <dd className='xfer:break-all xfer:font-mono xfer:text-xs'>{transfer.target.path}</dd>
                    {transfer.archive && (
                        <>
                            <dt className='xfer:text-muted-foreground'>Archive</dt>
                            <dd className='xfer:break-all xfer:font-mono xfer:text-xs'>{transfer.archive}</dd>
                        </>
                    )}
                    {(transfer.options.include ?? []).length > 0 && (
                        <>
                            <dt className='xfer:text-muted-foreground'>Only</dt>
                            <dd className='xfer:break-all xfer:font-mono xfer:text-xs'>{transfer.options.include!.join(', ')}</dd>
                        </>
                    )}
                    {(transfer.options.exclude ?? []).length > 0 && (
                        <>
                            <dt className='xfer:text-muted-foreground'>Skipped</dt>
                            <dd className='xfer:break-all xfer:font-mono xfer:text-xs'>{transfer.options.exclude!.join(', ')}</dd>
                        </>
                    )}
                </dl>
                {transfer.error && <p className='xfer:text-destructive'>{transfer.error}</p>}
                {detail.isLoading ? (
                    <Spinner centered />
                ) : detail.isError ? (
                    <p className='xfer:text-destructive'>{httpErrorToHuman(detail.error)}</p>
                ) : problems.length === 0 ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant='icon'>
                                <NamedIcon name={transfer.files.failed + transfer.files.skipped === 0 ? 'circle-check' : 'file-question'} />
                            </EmptyMedia>
                            <EmptyTitle>{transfer.files.failed + transfer.files.skipped === 0 ? 'Every file was copied' : 'No file details'}</EmptyTitle>
                            <EmptyDescription>
                                {transfer.files.failed + transfer.files.skipped === 0 ? 'Nothing failed or was skipped.' : 'The list of failed and skipped files was not kept.'}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ul className='xfer:max-h-72 xfer:divide-y xfer:divide-border xfer:overflow-y-auto xfer:rounded-sm xfer:bg-sunken'>
                        {problems.map((problem, index) => (
                            <li key={index} className='xfer:px-3 xfer:py-2'>
                                <p className='xfer:break-all xfer:font-mono xfer:text-xs xfer:text-foreground'>{problem.path}</p>
                                <p className={`xfer:text-xs ${problem.status === 'failed' ? 'xfer:text-destructive' : 'xfer:text-muted-foreground'}`}>
                                    {problem.status === 'failed' ? 'Failed' : 'Skipped'}: {problem.message}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <Dialog.Footer>
                <Button.Text className='xfer:w-full xfer:sm:w-auto' onClick={onClose}>
                    Close
                </Button.Text>
            </Dialog.Footer>
        </Dialog>
    );
}
