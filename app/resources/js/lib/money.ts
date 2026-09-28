/**
 * A plan price as the product writes it everywhere: whole units where there
 * are no cents, and "US$" spelled out so a dollar is not mistaken for
 * somebody else's.
 */
export function formatPlanPrice(cents: number, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    })
        .formatToParts(cents / 100)
        .map((part) =>
            part.type === 'currency' && currency.toLowerCase() === 'usd'
                ? 'US$'
                : part.value,
        )
        .join('');
}
