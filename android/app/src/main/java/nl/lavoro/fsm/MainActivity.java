package nl.lavoro.fsm;

import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.widget.Toast;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.CapConfig;

import java.util.HashMap;
import java.util.Map;

public class MainActivity extends BridgeActivity {

    private final Map<Long, String> pending_downloads = new HashMap<>();
    private BroadcastReceiver download_receiver;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(LocationTrackerPlugin.class);
        registerPlugin(AppConfigPlugin.class);

        String url = getSharedPreferences(AppConfigPlugin.PREFS, Context.MODE_PRIVATE)
            .getString(AppConfigPlugin.URL_KEY, null);

        if (url != null) {
            String host = Uri.parse(url).getHost();
            this.config = new CapConfig.Builder(this)
                .setServerUrl(url)
                .setAllowNavigation(new String[] { host })
                .setWebContentsDebuggingEnabled(true)
                .create();
        }

        super.onCreate(savedInstanceState);

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
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(download_receiver, filter, RECEIVER_EXPORTED);
        } else {
            registerReceiver(download_receiver, filter);
        }
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        if (download_receiver != null) {
            unregisterReceiver(download_receiver);
        }
    }
}
