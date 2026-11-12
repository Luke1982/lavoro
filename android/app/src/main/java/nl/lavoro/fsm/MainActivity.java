package nl.lavoro.fsm;

import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.os.SystemClock;
import android.view.animation.AccelerateInterpolator;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.Toast;

import androidx.core.content.ContextCompat;
import androidx.core.splashscreen.SplashScreen;
import androidx.core.splashscreen.SplashScreenViewProvider;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.CapConfig;
import com.getcapacitor.WebViewListener;

import java.util.HashMap;
import java.util.Map;

public class MainActivity extends BridgeActivity {

    private static final long MAX_SPLASH_MS = 10_000;
    private static final long SPLASH_EXIT_MS = 300;
    private static final float SPLASH_EXIT_LOGO_SCALE = 0.6f;
    private static final String SPLASH_BACKGROUND = "#081020";

    private final Map<Long, String> pending_downloads = new HashMap<>();
    private BroadcastReceiver download_receiver;
    private volatile boolean first_page_loaded = false;

    /**
     * Every customer works on the same server and the login decides which
     * company they are in, so the server is fixed when the app is built
     * (BuildConfig.SERVER_URL) and nobody types an address.
     *
     * The page in the web directory is only shown when that server cannot be
     * reached. Web debugging follows the build type: on in a debug build, off
     * in the one that goes to Google Play.
     *
     * The splash screen stays until the first page has loaded, so the app goes
     * from the logo straight to Lavoro instead of through a white WebView. A
     * slow server keeps it no longer than MAX_SPLASH_MS; the WebView behind it
     * has the same colour.
     */
    @Override
    @SuppressWarnings("deprecation")
    public void onCreate(Bundle savedInstanceState) {
        long started_at = SystemClock.uptimeMillis();
        SplashScreen splash_screen = SplashScreen.installSplashScreen(this);
        splash_screen.setKeepOnScreenCondition(
            () -> !first_page_loaded && SystemClock.uptimeMillis() - started_at < MAX_SPLASH_MS
        );
        splash_screen.setOnExitAnimationListener(this::leaveSplashScreen);

        registerPlugin(LocationTrackerPlugin.class);
        registerPlugin(PushSupportPlugin.class);

        String start_path = savedInstanceState == null ? launchPath(getIntent()) : null;
        setIntent(new Intent(this, MainActivity.class).setAction(Intent.ACTION_MAIN));

        this.config = new CapConfig.Builder(this)
            .setServerUrl(BuildConfig.SERVER_URL)
            .setStartPath(start_path)
            .setAllowNavigation(new String[] { Uri.parse(BuildConfig.SERVER_URL).getHost() })
            .setErrorPath(LavoroWebViewClient.OFFLINE_PAGE)
            .setBackgroundColor(SPLASH_BACKGROUND)
            .setPluginsConfiguration(CapConfig.loadDefault(this).getObject("plugins"))
            .create();

        super.onCreate(savedInstanceState);

        this.bridge.setWebViewClient(new LavoroWebViewClient(this.bridge));
        this.bridge.addWebViewListener(new WebViewListener() {
            @Override
            public void onPageLoaded(WebView webView) {
                first_page_loaded = true;
            }
        });

        this.bridge.getWebView().setDownloadListener((downloadUrl, userAgent, contentDisposition, mimetype, contentLength) -> {
            String cookies = CookieManager.getInstance().getCookie(downloadUrl);
            String fileName = URLUtil.guessFileName(downloadUrl, contentDisposition, mimetype);

            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(downloadUrl));
            request.addRequestHeader("Cookie", cookies);
            request.addRequestHeader("User-Agent", userAgent);
            request.setTitle(fileName);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);

            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            long download_id = dm.enqueue(request);
            pending_downloads.put(download_id, mimetype);

            Toast.makeText(getApplicationContext(), "Bestand wordt gedownload…", Toast.LENGTH_SHORT).show();
        });

        download_receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
                if (!pending_downloads.containsKey(id)) return;

                String mimetype = pending_downloads.remove(id);

                DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                DownloadManager.Query query = new DownloadManager.Query();
                query.setFilterById(id);
                Cursor cursor = dm.query(query);
                if (cursor == null || !cursor.moveToFirst()) return;

                int status = cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS));
                cursor.close();

                if (status == DownloadManager.STATUS_SUCCESSFUL) {
                    Uri file_uri = dm.getUriForDownloadedFile(id);
                    Intent open = new Intent(Intent.ACTION_VIEW);
                    open.setDataAndType(file_uri, mimetype);
                    open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_GRANT_READ_URI_PERMISSION);
                    try {
                        startActivity(open);
                    } catch (ActivityNotFoundException e) {
                        // No viewer available; notification is sufficient
                    }
                }
            }
        };

        IntentFilter filter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        ContextCompat.registerReceiver(this, download_receiver, filter, ContextCompat.RECEIVER_EXPORTED);
    }

    /**
     * Where a tapped notification or link asked to land, as a path on the server
     * this app is built for; null for anything else. The app starts right there,
     * and a running app hears about later taps through appUrlOpen, which
     * useDeepLinks follows.
     *
     * Only for a fresh tap: an app restored after Android cleared it from memory,
     * or reopened from the recent apps, still carries the intent it was first
     * started with, and would open yesterday's werkbon again. That intent is
     * swapped for a plain start once it has been read, because Capacitor replays
     * the launch intent as appUrlOpen on every start, which would open the path a
     * second time, or yesterday's after all.
     */
    private static String launchPath(Intent intent) {
        boolean fresh = intent != null && (intent.getFlags() & Intent.FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY) == 0;
        Uri target = fresh && Intent.ACTION_VIEW.equals(intent.getAction()) ? intent.getData() : null;
        Uri server = Uri.parse(BuildConfig.SERVER_URL);

        if (
            target == null
                || !server.getScheme().equals(target.getScheme())
                || !server.getHost().equals(target.getHost())
                || server.getPort() != target.getPort()
        ) {
            return null;
        }

        String path = target.getEncodedPath();
        String query = target.getEncodedQuery();

        if (path == null || !path.startsWith("/") || path.startsWith("//")) {
            return null;
        }

        return query == null ? path : path + "?" + query;
    }

    /**
     * Lets the logo finish building itself, then shrinks it while the splash
     * screen fades into the page behind it. Before Android 12 the logo does
     * not move and the library reports no animation, so the fade starts at
     * once. The wait never exceeds the animation itself, whatever clock the
     * reported start time turns out to use.
     */
    private void leaveSplashScreen(SplashScreenViewProvider splash) {
        long animation_ms = splash.getIconAnimationDurationMillis();
        long animation_left = splash.getIconAnimationStartMillis() + animation_ms - System.currentTimeMillis();
        long delay = Math.max(0, Math.min(animation_left, animation_ms));

        splash.getIconView().animate()
            .scaleX(SPLASH_EXIT_LOGO_SCALE)
            .scaleY(SPLASH_EXIT_LOGO_SCALE)
            .setStartDelay(delay)
            .setDuration(SPLASH_EXIT_MS)
            .setInterpolator(new AccelerateInterpolator());

        splash.getView().animate()
            .alpha(0f)
            .setStartDelay(delay)
            .setDuration(SPLASH_EXIT_MS)
            .setInterpolator(new AccelerateInterpolator())
            .withEndAction(splash::remove);
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        if (download_receiver != null) {
            unregisterReceiver(download_receiver);
        }
    }
}
