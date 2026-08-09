import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            className="flex flex-wrap items-center justify-between gap-3"
        >
            <p className="text-sm text-muted-foreground">
                {page.from}–{page.to} of {page.total}
            </p>
            <div className="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    asChild={page.prev_page_url !== null}
                    disabled={page.prev_page_url === null}
                >
                    {page.prev_page_url === null ? (
                        <span>
                            <ChevronLeft
                                className="size-4"
                                aria-hidden="true"
                            />
                            Previous
                        </span>
                    ) : (
                        <Link
                            href={page.prev_page_url}
                            preserveScroll
                            aria-label="Previous page"
                        >
                            <ChevronLeft
                                className="size-4"
                                aria-hidden="true"
                            />
                            Previous
                        </Link>
                    )}
                </Button>
                <span className="min-w-20 text-center text-sm text-muted-foreground">
                    Page {page.current_page} of {page.last_page}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    asChild={page.next_page_url !== null}
                    disabled={page.next_page_url === null}
                >
                    {page.next_page_url === null ? (
                        <span>
                            Next
                            <ChevronRight
                                className="size-4"
                                aria-hidden="true"
                            />
                        </span>
                    ) : (
                        <Link
                            href={page.next_page_url}
                            preserveScroll
                            aria-label="Next page"
                        >
                            Next
                            <ChevronRight
                                className="size-4"
                                aria-hidden="true"
                            />
                        </Link>
                    )}
                </Button>
            </div>
        </nav>
    );
}
