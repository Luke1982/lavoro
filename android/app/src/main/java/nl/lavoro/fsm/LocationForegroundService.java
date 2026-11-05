package nl.lavoro.fsm;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.util.Log;
import android.webkit.CookieManager;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;

import com.google.android.gms.location.FusedLocationProviderClient;
import com.google.android.gms.location.LocationCallback;
import com.google.android.gms.location.LocationRequest;
import com.google.android.gms.location.LocationResult;
import com.google.android.gms.location.LocationServices;
import com.google.android.gms.location.Priority;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.io.OutputStream;
import java.net.CookieHandler;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLDecoder;
import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.TimeZone;

/**
 * Collects the location every five minutes and posts it to the server,
 * independent of the WebView, so it goes on while the app is in the background.
 *
 * Only inside the company's tracking window: outside it the location is not
 * requested at all and the notification says so. The window is checked every
 * minute, so the service picks up again by itself when working hours begin.
 */
public class LocationForegroundService extends Service {

    private static final String TAG = "LavoroLocation";
    private static final String CHANNEL_ID = "lavoro_location";
    private static final int NOTIFICATION_ID = 4711;
    private static final long INTERVAL_MS = 5 * 60 * 1000L;
    private static final long WINDOW_CHECK_MS = 60 * 1000L;
    private static final String PREFS = "lavoro_location_prefs";
    private static final String QUEUE_KEY = "pending_pings";
    private static final int MAX_QUEUE = 200;

    private final String server_url = BuildConfig.SERVER_URL;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable window_check = new Runnable() {
        @Override
        public void run() {
            apply_window();
            handler.postDelayed(this, WINDOW_CHECK_MS);
        }
    };

    private FusedLocationProviderClient client;
    private LocationCallback callback;
    private TrackingWindow window = TrackingWindow.ALWAYS;
    private Boolean inside_window;

    @Override
    public void onCreate() {
        super.onCreate();
        client = LocationServices.getFusedLocationProviderClient(this);
        create_channel();
    }

    /**
     * The window comes with the intent from the plugin and is kept, because
     * after Android restarts the service there is no intent to read it from.
     *
     * Android 12 and later can refuse to start a foreground service from the
     * background, which is what such a restart is. Then the service stops
     * quietly; the next time the app is opened it starts it again.
     */
    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        TrackingWindow given = TrackingWindow.from(intent);
        if (given != null) {
            given.save(getSharedPreferences(PREFS, MODE_PRIVATE));
        }
        window = given != null ? given : TrackingWindow.load(getSharedPreferences(PREFS, MODE_PRIVATE));
        inside_window = null;

        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                startForeground(NOTIFICATION_ID, build_notification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);
            } else {
                startForeground(NOTIFICATION_ID, build_notification());
            }
        } catch (Exception e) {
            Log.w(TAG, "Not allowed to start the location service now", e);
            stopSelf();
            return START_NOT_STICKY;
        }

        handler.removeCallbacks(window_check);
        handler.post(window_check);
        return START_STICKY;
    }

    private void apply_window() {
        boolean inside = window.contains(Calendar.getInstance());

        if (inside_window != null && inside_window == inside) {
            return;
        }
        inside_window = inside;

        if (inside) {
            request_updates();
        } else {
            remove_updates();
            new Thread(this::flush).start();
        }

        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.notify(NOTIFICATION_ID, build_notification());
        }
    }

    private void remove_updates() {
        if (callback != null) {
            client.removeLocationUpdates(callback);
            callback = null;
        }
    }

    private void request_updates() {
        if (callback != null) {
            return;
        }

        LocationRequest request = new LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, INTERVAL_MS)
            .setMinUpdateIntervalMillis(INTERVAL_MS)
            .setWaitForAccurateLocation(false)
            .build();

        callback = new LocationCallback() {
            @Override
            public void onLocationResult(@NonNull LocationResult result) {
                if (result.getLastLocation() == null) {
                    return;
                }
                handle_location(
                    result.getLastLocation().getLatitude(),
                    result.getLastLocation().getLongitude(),
                    result.getLastLocation().hasAccuracy() ? result.getLastLocation().getAccuracy() : null,
                    result.getLastLocation().hasSpeed() ? result.getLastLocation().getSpeed() : null,
                    result.getLastLocation().hasBearing() ? result.getLastLocation().getBearing() : null
                );
            }
        };

        try {
            client.requestLocationUpdates(request, callback, Looper.getMainLooper());
        } catch (SecurityException e) {
            Log.e(TAG, "Missing location permission", e);
            stopSelf();
        }
    }

    private void handle_location(double lat, double lng, Float accuracy, Float speed, Float heading) {
        if (!window.contains(Calendar.getInstance())) {
            return;
        }

        try {
            JSONObject ping = new JSONObject();
            ping.put("lat", lat);
            ping.put("lng", lng);
            ping.put("accuracy", accuracy == null ? JSONObject.NULL : accuracy);
            ping.put("speed", speed == null ? JSONObject.NULL : speed);
            ping.put("heading", heading == null ? JSONObject.NULL : heading);
            ping.put("recorded_at", iso_now());

            JSONArray queue = read_queue();
            queue.put(ping);
            while (queue.length() > MAX_QUEUE) {
                queue.remove(0);
            }
            write_queue(queue);
        } catch (Exception e) {
            Log.e(TAG, "Failed to queue ping", e);
            return;
        }

        new Thread(this::flush).start();
    }

    private synchronized void flush() {
        JSONArray queue = read_queue();
        if (queue.length() == 0) {
            return;
        }

        try {
            JSONObject body = new JSONObject();
            body.put("pings", queue);

            if (post(body.toString())) {
                write_queue(new JSONArray());
            }
        } catch (Exception e) {
            Log.e(TAG, "Flush failed, keeping queue", e);
        }
    }

    /**
     * After a night without pings the session has expired and the server
     * refuses with a 419. That refusal has already logged the user in again
     * through the remember-me cookie, so with a fresh CSRF token the same pings
     * go through on the second try.
     */
    private boolean post(String json) {
        int code = send_pings(json);

        if (code == 419 && refresh_csrf()) {
            code = send_pings(json);
        }

        return code >= 200 && code < 300;
    }

    private int send_pings(String json) {
        HttpURLConnection conn = null;
        try {
            conn = open("/api/location/pings");
            conn.setRequestMethod("POST");
            conn.setDoOutput(true);
            conn.setRequestProperty("Content-Type", "application/json");

            String xsrf = extract_xsrf(CookieManager.getInstance().getCookie(server_url));
            if (xsrf != null) {
                conn.setRequestProperty("X-XSRF-TOKEN", xsrf);
            }

            try (OutputStream os = conn.getOutputStream()) {
                os.write(json.getBytes("UTF-8"));
            }

            int code = conn.getResponseCode();
            store_cookies(conn);
            Log.d(TAG, "POST /api/location/pings -> " + code);
            return code;
        } catch (Exception e) {
            Log.e(TAG, "POST failed", e);
            return -1;
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }

    private boolean refresh_csrf() {
        HttpURLConnection conn = null;
        try {
            conn = open("/sanctum/csrf-cookie");
            int code = conn.getResponseCode();
            store_cookies(conn);
            Log.d(TAG, "GET /sanctum/csrf-cookie -> " + code);
            return code >= 200 && code < 300;
        } catch (Exception e) {
            Log.e(TAG, "CSRF refresh failed", e);
            return false;
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }

    /**
     * Sanctum only treats a request as its own front end's, and so only reads
     * the session, when Origin or Referer is the server. Once the bridge has
     * loaded, Capacitor's CookieHandler adds the WebView's cookies by itself; a
     * second Cookie header garbles the session cookie, so it is only set here
     * when that handler is absent, as after Android restarted the service alone.
     *
     * Redirects are not followed. A redirect never means the pings are stored,
     * and following one to an ordinary page with a 200 would empty the queue.
     */
    private HttpURLConnection open(String path) throws IOException {
        HttpURLConnection conn = (HttpURLConnection) new URL(server_url + path).openConnection();
        conn.setInstanceFollowRedirects(false);
        conn.setConnectTimeout(15000);
        conn.setReadTimeout(15000);
        conn.setRequestProperty("Accept", "application/json");
        conn.setRequestProperty("X-Requested-With", "XMLHttpRequest");
        conn.setRequestProperty("Origin", server_url);
        conn.setRequestProperty("Referer", server_url + "/");

        if (CookieHandler.getDefault() == null) {
            String cookies = CookieManager.getInstance().getCookie(server_url);
            if (cookies != null) {
                conn.setRequestProperty("Cookie", cookies);
            }
        }

        return conn;
    }

    /**
     * Into the WebView's cookie store, which the app and this service share.
     * With Capacitor's CookieHandler present this repeats what it already did.
     */
    private void store_cookies(HttpURLConnection conn) {
        CookieManager manager = CookieManager.getInstance();

        for (Map.Entry<String, List<String>> header : conn.getHeaderFields().entrySet()) {
            if (header.getKey() == null || !header.getKey().equalsIgnoreCase("Set-Cookie")) {
                continue;
            }
            for (String cookie : header.getValue()) {
                manager.setCookie(server_url, cookie);
            }
        }

        manager.flush();
    }

    @Nullable
    private String extract_xsrf(@Nullable String cookies) {
        if (cookies == null) {
            return null;
        }
        for (String part : cookies.split(";")) {
            String trimmed = part.trim();
            if (trimmed.startsWith("XSRF-TOKEN=")) {
                try {
                    return URLDecoder.decode(trimmed.substring("XSRF-TOKEN=".length()), "UTF-8");
                } catch (Exception e) {
                    return null;
                }
            }
        }
        return null;
    }

    private JSONArray read_queue() {
        String raw = getSharedPreferences(PREFS, MODE_PRIVATE).getString(QUEUE_KEY, "[]");
        try {
            return new JSONArray(raw);
        } catch (Exception e) {
            return new JSONArray();
        }
    }

    private void write_queue(JSONArray queue) {
        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putString(QUEUE_KEY, queue.toString()).apply();
    }

    private String iso_now() {
        SimpleDateFormat fmt = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US);
        fmt.setTimeZone(TimeZone.getTimeZone("UTC"));
        return fmt.format(new Date());
    }

    private void create_channel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                CHANNEL_ID,
                "Locatie",
                NotificationManager.IMPORTANCE_LOW
            );
            channel.setDescription(getString(R.string.location_channel_description));
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(channel);
            }
        }
    }

    private Notification build_notification() {
        boolean inside = inside_window == null ? window.contains(Calendar.getInstance()) : inside_window;

        Intent open = getPackageManager().getLaunchIntentForPackage(getPackageName());
        PendingIntent content = open == null ? null : PendingIntent.getActivity(
            this, 0, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );

        return new NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(inside ? R.string.location_notification_active : R.string.location_notification_paused))
            .setContentText(getString(R.string.location_notification_body, window.describe()))
            .setSmallIcon(R.drawable.ic_stat_location)
            .setContentIntent(content)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build();
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacks(window_check);
        remove_updates();
        super.onDestroy();
    }

    @Nullable
    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
