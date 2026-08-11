import {
    Breadcrumb,
    BreadcrumbEllipsis,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from 'avyo';

/**
 * The collapsed-middle marker. On its own it is a 16px glyph and a screen
 * reader label, so the card shows it in the trail it belongs to — that is the
 * only place its size and alignment mean anything.
 */
export function InTrail() {
    return (
        <Breadcrumb>
            <BreadcrumbList>
                <BreadcrumbItem>
                    <BreadcrumbLink href="#">Projects</BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbEllipsis />
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbPage>Settings</BreadcrumbPage>
                </BreadcrumbItem>
            </BreadcrumbList>
        </Breadcrumb>
    );
}

export function Alone() {
    return (
        <div className="flex items-center gap-3 text-sm text-muted-foreground">
            <BreadcrumbEllipsis />
            <span>— the glyph at its natural size</span>
        </div>
    );
}
