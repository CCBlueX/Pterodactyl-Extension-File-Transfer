interface Props<T extends string> {
    label: string;
    tabs: { id: T; label: string }[];
    active: T;
    onChange: (tab: T) => void;
}

/** The panel's underlined tab bar. */
export default function Tabs<T extends string>({ label, tabs, active, onChange }: Props<T>) {
    return (
        <div role='tablist' aria-label={label} className='xfer:mb-6 xfer:flex xfer:gap-2 xfer:overflow-x-auto xfer:border-b xfer:border-border xfer:text-sm'>
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type='button'
                    role='tab'
                    aria-selected={tab.id === active}
                    onClick={() => onChange(tab.id)}
                    className={
                        tab.id === active
                            ? 'xfer:shrink-0 xfer:px-3 xfer:py-2 xfer:text-foreground xfer:shadow-navigation-active'
                            : 'xfer:shrink-0 xfer:px-3 xfer:py-2 xfer:text-muted-foreground xfer:transition-colors xfer:hover:text-foreground'
                    }
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
