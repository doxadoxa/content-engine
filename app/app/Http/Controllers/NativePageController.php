<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\PageProposal;
use App\Models\PagePublication;
use App\Models\PagePublicationOperation;
use App\Models\SitePage;
use App\Models\User;
use App\Publishing\Pages\EditablePages;
use App\Publishing\Pages\NativePublication;
use App\Publishing\Pages\PageOperationDispatcher;
use App\Publishing\Pages\UnsupportedPageChange;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZipArchive;

final class NativePageController extends Controller
{
    public function wordpressPlugin(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'avyo-receiver-');
        abort_if($path === false, 503, 'The plugin download could not be prepared.');
        $zip = new ZipArchive;
        try {
            abort_unless($zip->open($path, ZipArchive::OVERWRITE) === true, 503, 'The plugin download could not be prepared.');
            // Every file the plugin bootstrap requires, or activation fatals
            // on the missing include. Keep in step with packages/wordpress-receiver/package.sh.
            foreach (['avyo-receiver.php', 'receiver.php', 'articles.php'] as $file) {
                abort_unless($zip->addFile(base_path('packages/wordpress-receiver/plugin/'.$file), 'avyo-receiver/'.$file), 503);
            }
            abort_unless($zip->close(), 503);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->download($path, 'avyo-receiver.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    public function bind(Request $request, SitePage $page, EditablePages $pages): RedirectResponse
    {
        $data = $request->validate(['channel_id' => ['required', 'ulid'], 'object_id' => ['required', 'string', 'max:160'], 'object_type' => ['required', 'in:page,post,service,article']]);
        $channel = Channel::query()->findOrFail((string) $data['channel_id']);
        $this->safely(fn () => $pages->bind($page, $channel, $data['object_id'], $data['object_type']));

        return back();
    }

    public function capture(SitePage $page, EditablePages $pages): RedirectResponse
    {
        $this->safely(fn () => $pages->capture($page));

        return back();
    }

    public function publish(Request $request, PageProposal $proposal, NativePublication $publication): RedirectResponse
    {
        $data = $request->validate(['revision_id' => ['required', 'ulid']]);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->safely(fn () => $publication->authorize($proposal, $actor, $data['revision_id']));

        return back();
    }

    public function recover(Request $request, PagePublication $publication, NativePublication $native): RedirectResponse
    {
        $request->validate(['confirm_recovery' => ['required', 'accepted']]);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->safely(fn () => $native->recover($publication, $actor));

        return back();
    }

    public function reconcile(PagePublicationOperation $operation, PageOperationDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->attempt($operation, reconcileOnly: true, force: true);

        return back();
    }

    public function retry(Request $request, PagePublicationOperation $operation, PageOperationDispatcher $dispatcher): RedirectResponse
    {
        $request->validate(['confirm_retry' => ['required', 'accepted']]);
        $dispatcher->attempt($operation, force: true);

        return back();
    }

    private function safely(callable $action): void
    {
        try {
            $action();
        } catch (UnsupportedPageChange|UnsafePublicUrl|ConnectionException $exception) {
            throw ValidationException::withMessages(['cms' => $exception instanceof ConnectionException ? 'The receiver could not be reached. Check the connection and retry.' : $exception->getMessage()]);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            throw ValidationException::withMessages(['cms' => $exception->getMessage()]);
        }
    }
}
