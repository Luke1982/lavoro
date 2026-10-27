<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgetIntegrationSecretRequest;
use App\Http\Requests\UpdateIntegrationSettingsRequest;
use App\Mail\TestMail;
use App\Models\GeneralSetting;
use App\Services\ImapSentCopier;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TechnicalManagementController extends Controller
{
    /** Fields that may simply go back into the screen. */
    private const PLAIN_KEYS = [
        'mail_transport', 'mail_from_address', 'mail_from_name',
        'graph_azure_tenant_id', 'graph_client_id', 'graph_user_id',
        'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_scheme', 'mail_smtp_username',
    ];

    /** Fields the screen only gets to see as "is filled in". */
    private const SECRET_KEYS = [
        'graph_client_secret', 'mail_smtp_password',
        'snelstart_client_key', 'snelstart_subscription_key',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()?->isSuperAdmin() ?? false, 403);

        $settings = [];

        foreach (self::PLAIN_KEYS as $key) {
            $settings[$key] = GeneralSetting::get($key, '');
        }

        /** Whoever chose nothing mails through Microsoft 365; that is what was there. */
        $settings['mail_transport'] = $settings['mail_transport'] ?: 'graph';

        $stored = [];

        foreach (self::SECRET_KEYS as $key) {
            $stored[$key] = filled(GeneralSetting::get($key));
        }

        return inertia('TechnischBeheer/IndexPage', [
            'settings' => $settings,
            'storedSecrets' => $stored,
        ]);
    }

    public function updateIntegrations(UpdateIntegrationSettingsRequest $request)
    {
        $data = $request->validated();

        foreach (self::PLAIN_KEYS as $key) {
            GeneralSetting::set($key, $data[$key] ?? '');
        }

        /**
         * An empty secret means "leave it". Otherwise every visit to the screen
         * wiped the keys, because they are not in it.
         */
        foreach (self::SECRET_KEYS as $key) {
            if (filled($data[$key] ?? null)) {
                GeneralSetting::set($key, $data[$key]);
            }
        }

        return back()->with('success', 'Koppelingen opgeslagen.');
    }

    public function forgetSecret(ForgetIntegrationSecretRequest $request, string $key)
    {
        abort_unless(in_array($key, self::SECRET_KEYS, true), 404);

        GeneralSetting::set($key, '');

        return back()->with('success', 'Sleutel gewist.');
    }

    public function sendTestMail(Request $request)
    {
        abort_unless($request->user()?->isSuperAdmin() ?? false, 403);

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
            Mail::to($data['email'])->send(new TestMail);
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
