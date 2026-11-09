import { Input, Label } from '@pterodactyl/sdk';

export interface Filters {
    include: string;
    exclude: string;
}

export const noFilters: Filters = { include: '', exclude: '' };

/** Comma or line separated patterns as the API expects them. */
export const patterns = (value: string) =>
    value
        .split(/[,\n]/)
        .map((pattern) => pattern.trim())
        .filter((pattern) => pattern !== '');

export const filterInput = (filters: Filters) => ({ include: patterns(filters.include), exclude: patterns(filters.exclude) });

interface Props {
    id: string;
    value: Filters;
    onChange: (filters: Filters) => void;
}

export default function FiltersField({ id, value, onChange }: Props) {
    return (
        <div className='xfer:grid xfer:gap-3 xfer:sm:grid-cols-2'>
            <div>
                <Label htmlFor={`${id}-include`}>Only files matching</Label>
                <Input
                    id={`${id}-include`}
                    className='xfer:font-mono'
                    placeholder='*.yml, world/**'
                    value={value.include}
                    onChange={(event) => onChange({ ...value, include: event.currentTarget.value })}
                />
            </div>
            <div>
                <Label htmlFor={`${id}-exclude`}>Skip</Label>
                <Input
                    id={`${id}-exclude`}
                    className='xfer:font-mono'
                    placeholder='logs/, *.log, cache/'
                    value={value.exclude}
                    onChange={(event) => onChange({ ...value, exclude: event.currentTarget.value })}
                />
            </div>
            <p className='xfer:text-xs xfer:text-muted-foreground xfer:sm:col-span-2'>
                Separate patterns with commas. <span className='xfer:font-mono'>*</span> matches within a name, <span className='xfer:font-mono'>**</span> across folders, a trailing{' '}
                <span className='xfer:font-mono'>/</span> means folders only. Leave empty to take everything.
            </p>
        </div>
    );
}
