{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
{{-- Everything the engine wrote goes through XmlText: one control
     character would make the whole feed unreadable. --}}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
    <channel>
        <title>Avyo Blog</title>
        <link>{{ $home }}</link>
        <description>Practical guides to reaching customers through search and AI answers, researched, written and published by Avyo.</description>
        <language>{{ $locale }}</language>
        <atom:link href="{{ $self }}" rel="self" type="application/rss+xml"/>
@if ($posts->isNotEmpty())
        <lastBuildDate>{{ $posts->max('updated_at')->toRssString() }}</lastBuildDate>
@endif
@foreach ($posts as $post)
        <item>
            <title>{{ \App\Blog\XmlText::clean($post->title) }}</title>
            <link>{{ $post->publicUrl() }}</link>
            <guid isPermaLink="true">{{ $post->publicUrl() }}</guid>
            <pubDate>{{ $post->published_at->toRssString() }}</pubDate>
@if (is_string($post->author['name'] ?? null))
            <dc:creator>{{ \App\Blog\XmlText::clean($post->author['name']) }}</dc:creator>
@endif
@if ($post->summary)
            <description>{{ \App\Blog\XmlText::clean($post->summary) }}</description>
@endif
            <content:encoded>{{ \App\Blog\XmlText::clean($post->html) }}</content:encoded>
        </item>
@endforeach
    </channel>
</rss>
