export type SitePage = {
    id: string;
    title: string;
    url: string;
    canonical_url: string | null;
    locale: string | null;
    kind: string;
    tracked_at: string | null;
    content_item_id: string | null;
    snapshot_at: string | null;
    snapshot_count: number;
};
export type Snapshot = {
    id: string;
    captured_at: string;
    source_kind: string;
    source_url: string;
    revision: string;
    content_hash: string;
    fields: Record<string, string>;
    editable_fields: string[];
};
