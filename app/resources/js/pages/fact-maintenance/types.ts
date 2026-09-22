export type Version = {
    id: string;
    business_fact_id: string;
    statement: string;
    status: string;
    source_url: string | null;
    source_note: string;
    confirmed_at: string | null;
    review_due_at: string | null;
};
export type Fact = {
    id: string;
    name: string;
    current: Version | null;
    usable: boolean;
};
export type Check = {
    id: string;
    site_page_id: string;
    status: string;
    reason: string | null;
    created_at: string;
    specification: {
        canonical_url: string;
        locale: string;
        fact_version_ids: string[];
    };
};
export type Review = {
    id: string;
    action: string;
    reason: string;
    created_at: string;
    evidence: {
        opportunity_id?: string;
        handoff?: {
            url: string;
            locale: string;
            before: string;
            after: string;
            instructions: string;
            preserve: string;
            verification: string;
        };
    };
};
export type Claim = {
    id: string;
    exact_quote: string;
    relation: string;
    reason: string;
    fact_version_id: string | null;
    start_codepoint: number;
    end_codepoint: number;
    source: { kind: string; locator: string; locale: string };
    correction_mode: 'proposal' | 'assisted';
    reviews: Review[];
};
