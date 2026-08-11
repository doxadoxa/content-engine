import {
    Badge,
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from 'avyo';

const ROWS = [
    { title: 'Ten keyword clusters worth targeting', locale: 'EN', status: 'Published', words: 1840 },
    { title: 'Como estruturar um calendário editorial', locale: 'PT', status: 'In review', words: 1520 },
    { title: 'Що таке topical authority', locale: 'UK', status: 'Draft', words: 980 },
    { title: 'Programmatic SEO without the sludge', locale: 'EN', status: 'Failed', words: 2110 },
];

const TONE = {
    Published: 'default',
    'In review': 'secondary',
    Draft: 'outline',
    Failed: 'destructive',
} as const;

export function ArticleQueue() {
    return (
        <Table>
            <TableCaption>Articles queued for this week.</TableCaption>
            <TableHeader>
                <TableRow>
                    <TableHead>Title</TableHead>
                    <TableHead>Locale</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Words</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {ROWS.map((row) => (
                    <TableRow key={row.title}>
                        <TableCell className="font-medium">
                            {row.title}
                        </TableCell>
                        <TableCell>{row.locale}</TableCell>
                        <TableCell>
                            <Badge variant={TONE[row.status as keyof typeof TONE]}>
                                {row.status}
                            </Badge>
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {row.words.toLocaleString('en-GB')}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
            <TableFooter>
                <TableRow>
                    <TableCell colSpan={3}>Total</TableCell>
                    <TableCell className="text-right tabular-nums">
                        6,450
                    </TableCell>
                </TableRow>
            </TableFooter>
        </Table>
    );
}

export function Compact() {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Locale</TableHead>
                    <TableHead className="text-right">Published</TableHead>
                    <TableHead className="text-right">In review</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {[
                    ['English', 64, 3],
                    ['Português', 31, 2],
                    ['Українська', 22, 1],
                    ['Русский', 11, 1],
                ].map(([locale, live, review]) => (
                    <TableRow key={locale as string}>
                        <TableCell className="font-medium">{locale}</TableCell>
                        <TableCell className="text-right tabular-nums">
                            {live}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {review}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
