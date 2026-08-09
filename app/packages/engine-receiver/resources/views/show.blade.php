{{-- The minimal render §6.3 asks for: enough that a receiving site shows
     something correct on day one, and obviously meant to be replaced. --}}
<!DOCTYPE html>
<html lang="{{ $content->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $content->title }}</title>
    <meta name="description" content="{{ $content->summary }}">
    @foreach ($content->siblings()->get() as $sibling)
        <link rel="alternate" hreflang="{{ $sibling->locale }}" href="{{ url("/{$sibling->locale}/{$sibling->slug}") }}">
    @endforeach
    @if ($content->json_ld)
        <script type="application/ld+json">{!! json_encode($content->json_ld) !!}</script>
    @endif
    @if ($content->faq_json_ld)
        <script type="application/ld+json">{!! json_encode($content->faq_json_ld) !!}</script>
    @endif
</head>
<body>
<article>
    <h1>{{ $content->title }}</h1>
    {!! $content->html !!}
</article>
</body>
</html>
