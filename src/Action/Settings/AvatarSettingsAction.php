<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Service\User\AvatarService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /settings/avatar and /settings/avatar/remove (spec.md §7.9
 * *Avatars*): upload or replace one's own avatar, or remove it.
 */
final readonly class AvatarSettingsAction
{
    public function __construct(
        private AvatarService $avatars,
        private SettingsPage $page,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $session = RequestContext::session($request);

        if (($args['action'] ?? '') === 'remove') {
            $this->avatars->remove($user);
            $session->flash('success', 'account.avatar.removed');

            return $this->redirect->toRoute('settings');
        }

        $file = $request->getUploadedFiles()['avatar'] ?? null;
        $file = $file instanceof UploadedFileInterface ? $file : null;
        $error = FileUpload::wasProvided($file) && $file !== null
            ? $this->avatars->upload($user, $file)
            : 'account.avatar.none_chosen';
        if ($error !== null) {
            $errors = new ValidationErrors();
            $errors->add('avatar', $error, ['name' => $file?->getClientFilename() ?? '', 'max' => AvatarService::MAX_MB]);

            return $this->page->render($request, $response, status: 422, avatarErrors: $errors);
        }
        $session->flash('success', 'account.avatar.saved');

        return $this->redirect->toRoute('settings');
    }
}
