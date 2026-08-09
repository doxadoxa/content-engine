export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    const titleClass =
        variant === 'small'
            ? 'mb-0.5 text-base font-medium'
            : 'text-xl font-semibold tracking-tight';

    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            {variant === 'small' ? (
                <h2 className={titleClass}>{title}</h2>
            ) : (
                <h1 className={titleClass}>{title}</h1>
            )}
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
