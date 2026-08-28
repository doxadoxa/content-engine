@props(['url'])
{{--
    A wordmark and not an image. The product's mark is a React component
    (resources/js/components/app-logo-icon.tsx), so putting it here would mean
    hosting a PNG and referencing it absolutely — and mail clients block remote
    images by default, which makes the first thing most people see a broken
    frame where the brand should be. Text always renders.

    The framework's version swapped in laravel.com's logo whenever the slot
    said "Laravel". Removed: it never fired for this app, and a remote image
    URL pointing at somebody else's CDN is not something to leave in a
    template that sends mail as us.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">{!! $slot !!}</a>
</td>
</tr>
