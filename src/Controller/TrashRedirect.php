<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Where to go once a creation was put in the trash: back to the page of its card (the trash button of the cards
 * sends "back"), with its name and a link to restore it; else the trash.
 */
trait TrashRedirect
{
    private function afterTrash(Request $request, object $creation): RedirectResponse
    {
        $back = $request->request->get('back');
        // only a page of this site
        if (is_string($back) && str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\')) {
            $this->addFlash('trashed', $creation->getName());

            return $this->redirect($back);
        }
        $this->addFlash('trash', 'Mis à la corbeille.');

        return $this->redirectToRoute('trash');
    }
}
