<?php

namespace App\Http\Controllers;

use App\Mail\TestMail;
use App\Services\ImapSentCopier;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TechnicalManagementController extends Controller
{
    public function index(Request $request)
    {
        if (!in_array('technical.management', $request->user()?->permissionNames() ?? [], true)) {
            abort(403);
        }

        return inertia('TechnischBeheer/IndexPage');
    }

    public function sendTestMail(Request $request)
    {
        if (!in_array('technical.management', $request->user()?->permissionNames() ?? [], true)) {
            abort(403);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return back()->with(
                'error',
                "Test e-mail niet verzonden: de mailserver is niet geconfigureerd (huidige driver: \"{$mailer}\")."
                . ' Stel MAIL_MAILER in op een echte transport (bijv. smtp).'
            );
        }

        $sentRaw = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sentRaw) {
            $sentRaw = $event->sent->toString();
        });

        try {
            Mail::to($data['email'])->send(new TestMail());
        } catch (Throwable $e) {
            $detail = $e->getMessage();
            if ($e->getPrevious()) {
                $detail .= ' | Oorzaak: ' . $e->getPrevious()->getMessage();
            }
            $detail .= ' (' . get_class($e) . ')';

            return back()->with('error', 'Test e-mail kon niet worden verzonden: ' . $detail);
        }

        $copier = app(ImapSentCopier::class);

        if (!$copier->isConfigured()) {
            return back()->with(
                'success',
                'Test e-mail verzonden naar ' . $data['email'] . '.'
                . ' Let op: IMAP niet geconfigureerd, geen kopie opgeslagen in .Sent map.'
            );
        }

        try {
            $copier->copy($sentRaw);
        } catch (Throwable $e) {
            return back()->with(
                'error',
                'Test e-mail verzonden naar ' . $data['email']
                . ', maar opslaan in IMAP .Sent map mislukt: ' . $e->getMessage()
            );
        }

        $folder = config('imap.sent_folder', '.Sent');

        return back()->with(
            'success',
            'Test e-mail verzonden naar ' . $data['email'] . '. Kopie opgeslagen in ' . $folder . '.'
        );
    }
}
