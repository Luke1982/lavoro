package nl.lavoro.fsm;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
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

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLDecoder;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

public class LocationForegroundService extends Service {

    private static final String TAG = "LavoroLocation";
    private static final String CHANNEL_ID = "lavoro_location";
    private static final int NOTIFICATION_ID = 4711;
    private static final long INTERVAL_MS = 10 * 60 * 1000L;
    private static final String PREFS = "lavoro_location_prefs";
    private static final String QUEUE_KEY = "pending_pings";
    private static final int MAX_QUEUE = 200;

    private FusedLocationProviderClient client;
    private LocationCallback callback;
    private String server_url;

    @Override
    public void onCreate() {
        super.onCreate();
        client = LocationServices.getFusedLocationProviderClient(this);
        create_channel();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && intent.getStringExtra("serverUrl") != null) {
            server_url = intent.getStringExtra("serverUrl");
        }

        int type = Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q
            ? ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            : 0;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, build_notification(), type);
        } else {
            startForeground(NOTIFICATION_ID, build_notification());
        }

        request_updates();
        return START_STICKY;
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
        if (server_url == null) {
            return;
        }

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

    private boolean post(String json) {
        HttpURLConnection conn = null;
        try {
            URL url = new URL(server_url + "/api/location/pings");
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setConnectTimeout(15000);
            conn.setReadTimeout(15000);
            conn.setDoOutput(true);
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("Accept", "application/json");
            conn.setRequestProperty("X-Requested-With", "XMLHttpRequest");

            String cookies = CookieManager.getInstance().getCookie(server_url);
            if (cookies != null) {
                conn.setRequestProperty("Cookie", cookies);
                String xsrf = extract_xsrf(cookies);
                if (xsrf != null) {
                    conn.setRequestProperty("X-XSRF-TOKEN", xsrf);
                }
            }

            try (OutputStream os = conn.getOutputStream()) {
                os.write(json.getBytes("UTF-8"));
            }

            int code = conn.getResponseCode();
            Log.d(TAG, "POST /api/location/pings -> " + code);
            return code >= 200 && code < 300;
        } catch (Exception e) {
            Log.e(TAG, "POST failed", e);
            return false;
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }
    }

    @Nullable
    private String extract_xsrf(String cookies) {
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
            channel.setDescription("Lavoro volgt uw locatie voor servicebezoeken.");
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(channel);
            }
        }
    }

    private Notification build_notification() {
        return new NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Locatie actief")
            .setContentText("Lavoro volgt uw locatie voor servicebezoeken.")
            .setSmallIcon(R.mipmap.ic_launcher)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build();
    }

    @Override
    public void onDestroy() {
        if (callback != null) {
            client.removeLocationUpdates(callback);
            callback = null;
        }
        super.onDestroy();
    }

    @Nullable
    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
