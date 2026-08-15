/**
 * The three visual levels shared by Social.
 *
 * A surface groups a whole task, a card is one actionable unit, and an inset
 * is supporting context. Keeping those levels distinct prevents every nested
 * element from reaching for another outline to explain its hierarchy.
 */
export const socialSurfaceClass =
    'rounded-[1.5rem] bg-card/85 shadow-[0_1px_2px_rgba(23,53,47,0.03),0_16px_42px_rgba(23,53,47,0.05)]';

export const socialCardClass =
    'rounded-2xl bg-background/80 shadow-[0_1px_2px_rgba(23,53,47,0.04),0_8px_24px_rgba(23,53,47,0.05)]';

export const socialInsetClass = 'rounded-2xl bg-muted/45';
