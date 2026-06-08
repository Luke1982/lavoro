package nl.lavoro.fsm;

import android.app.DownloadManager;
import android.content.Context;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.widget.Toast;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.CapConfig;

public class MainActivity extends BridgeActivity {
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
            dm.enqueue(request);

            Toast.makeText(getApplicationContext(), "Bestand wordt gedownload…", Toast.LENGTH_SHORT).show();
        });
    }
}
