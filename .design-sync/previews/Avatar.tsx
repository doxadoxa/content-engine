import { Avatar, AvatarFallback, AvatarImage } from 'avyo';

/**
 * AvatarImage needs a real image to render anything, and a preview card has no
 * network — so the source here is an inline SVG data URI. AvatarFallback only
 * appears once the image has actually failed, which is why the fallback cells
 * below omit the image entirely rather than pointing it at a broken URL.
 */
const PORTRAIT =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
            <rect width="64" height="64" fill="#17352f"/>
            <circle cx="32" cy="24" r="11" fill="#f3cf6a"/>
            <path d="M8 64c0-13 11-21 24-21s24 8 24 21z" fill="#f3cf6a"/>
        </svg>`.replace(/\s+/g, ' '),
    );

export function WithImage() {
    return (
        <div className="flex items-center gap-4">
            <Avatar>
                <AvatarImage src={PORTRAIT} alt="Taras Dovgal" />
                <AvatarFallback>TD</AvatarFallback>
            </Avatar>
            <div className="text-sm">
                <p className="font-medium">Taras Dovgal</p>
                <p className="text-muted-foreground">Editor</p>
            </div>
        </div>
    );
}

export function Initials() {
    return (
        <div className="flex items-center gap-3">
            <Avatar>
                <AvatarFallback>TD</AvatarFallback>
            </Avatar>
            <Avatar>
                <AvatarFallback>MK</AvatarFallback>
            </Avatar>
            <Avatar>
                <AvatarFallback>AV</AvatarFallback>
            </Avatar>
        </div>
    );
}

export function Sizes() {
    return (
        <div className="flex items-end gap-4">
            <Avatar className="size-6">
                <AvatarFallback className="text-[10px]">TD</AvatarFallback>
            </Avatar>
            <Avatar>
                <AvatarFallback>TD</AvatarFallback>
            </Avatar>
            <Avatar className="size-12">
                <AvatarFallback>TD</AvatarFallback>
            </Avatar>
            <Avatar className="size-16">
                <AvatarImage src={PORTRAIT} alt="Taras Dovgal" />
                <AvatarFallback>TD</AvatarFallback>
            </Avatar>
        </div>
    );
}
