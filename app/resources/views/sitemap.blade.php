{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($entries as $entry)
    <url>
        <loc>{{ \App\Blog\XmlText::clean($entry['loc']) }}</loc>
@if ($entry['lastmod'] !== null)
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
@foreach ($entry['alternates'] as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ \App\Blog\XmlText::clean($alternate['hreflang']) }}" href="{{ \App\Blog\XmlText::clean($alternate['url']) }}"/>
@endforeach
    </url>
@endforeach
</urlset>
