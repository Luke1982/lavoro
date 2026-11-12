package nl.lavoro.fsm;

import android.Manifest;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.util.Log;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationChannelCompat;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;

import com.capacitorjs.plugins.pushnotifications.MessagingService;
import com.google.firebase.messaging.RemoteMessage;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.Map;

/**
 * Draws the notifications the server sends through Firebase.
 *
 * They arrive as data only, so Android hands every one of them here, also
 * while the app is shut, and the banner can carry buttons: one the system
 * draws by itself cannot. The fields are the ones the browser's service worker
 * reads: title, body, url, tag, channel, and the buttons as JSON in actions.
 *
 * Extends the push plugin's own service instead of sitting beside it. Android
 * hands Firebase messages to one service only, and the plugin's still has to
 * hear about new tokens and tell the web side what arrived.
 */
public class LavoroMessagingService extends MessagingService {

    private static final String TAG = "LavoroMessaging";

    /**
     * One channel per kind, so a person can silence ordinary news in Android's
     * settings without losing the reminder that sends them on their way.
     */
    private enum Channel {
        REMINDERS("reminders", R.string.channel_reminders, R.string.channel_reminders_description, true),
        URGENT("urgent", R.string.channel_urgent, R.string.channel_urgent_description, true),
        GENERAL("general", R.string.channel_general, R.string.channel_general_description, false);

        final String id;
        final int name;
        final int description;
        final boolean interrupts;

        Channel(String id, int name, int description, boolean interrupts) {
            this.id = id;
            this.name = name;
            this.description = description;
            this.interrupts = interrupts;
        }

        static Channel from(String id) {
            for (Channel channel : values()) {
                if (channel.id.equals(id)) return channel;
            }
            return GENERAL;
        }
    }

    @Override
    public void onMessageReceived(@NonNull RemoteMessage message) {
        super.onMessageReceived(message);

        Map<String, String> data = message.getData();

        if (!data.containsKey("title") || !may_notify()) return;

        NotificationManagerCompat manager = NotificationManagerCompat.from(this);
        Channel channel = Channel.from(data.get("channel"));
        create_channel(manager, channel);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, channel.id)
            .setSmallIcon(R.drawable.ic_stat_lavoro)
            .setColor(ContextCompat.getColor(this, R.color.notification_accent))
            .setContentTitle(data.get("title"))
            .setContentText(data.get("body"))
            .setStyle(new NotificationCompat.BigTextStyle().bigText(data.get("body")))
            .setContentIntent(open_in_app(data.get("url")))
            .setAutoCancel(true)
            .setCategory(channel == Channel.REMINDERS ? NotificationCompat.CATEGORY_REMINDER : NotificationCompat.CATEGORY_MESSAGE)
            /*
             * Before Android 8 there are no channels: priority and sound are set
             * on the notification itself, and without the defaults even a high
             * one arrives silently.
             */
            .setPriority(channel.interrupts ? NotificationCompat.PRIORITY_HIGH : NotificationCompat.PRIORITY_DEFAULT)
            .setDefaults(NotificationCompat.DEFAULT_ALL);

        add_actions(builder, data.get("actions"));

        /**
         * The tag names the record, so a later message about the same
         * appointment or storing takes the place of the earlier one.
         */
        manager.notify(data.get("tag"), 0, builder.build());
    }

    private boolean may_notify() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU
            || ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
    }

    /** Creating a channel that exists already changes nothing, so this runs every time. */
    private void create_channel(NotificationManagerCompat manager, Channel channel) {
        manager.createNotificationChannel(
            new NotificationChannelCompat.Builder(
                channel.id,
                channel.interrupts ? NotificationManagerCompat.IMPORTANCE_HIGH : NotificationManagerCompat.IMPORTANCE_DEFAULT
            )
                .setName(getString(channel.name))
                .setDescription(getString(channel.description))
                .build()
        );
    }

    /**
     * The path opens inside the app, on the server it was built for, the way a
     * tapped link to that server does. Anything but a path on it is ignored.
     */
    private PendingIntent open_in_app(String path) {
        String safe_path = path != null && path.startsWith("/") && !path.startsWith("//") ? path : "/";
        Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(BuildConfig.SERVER_URL + safe_path), this, MainActivity.class);

        return PendingIntent.getActivity(this, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /**
     * Route opens the address in Maps, Bellen puts the number in the dialer
     * without calling it. Only those two kinds of address get a button.
     */
    private void add_actions(NotificationCompat.Builder builder, String json) {
        if (json == null) return;

        try {
            JSONArray actions = new JSONArray(json);

            for (int i = 0; i < actions.length(); i++) {
                JSONObject action = actions.getJSONObject(i);
                Uri uri = Uri.parse(action.getString("url"));
                String scheme = uri.getScheme();

                if (!"https".equals(scheme) && !"tel".equals(scheme)) continue;

                Intent intent = new Intent("tel".equals(scheme) ? Intent.ACTION_DIAL : Intent.ACTION_VIEW, uri)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);

                builder.addAction(0, action.getString("title"), PendingIntent.getActivity(
                    this, i, intent, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
                ));
            }
        } catch (JSONException e) {
            Log.w(TAG, "Knoppen van een melding onleesbaar", e);
        }
    }
}
