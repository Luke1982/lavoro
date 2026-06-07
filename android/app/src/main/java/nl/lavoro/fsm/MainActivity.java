package nl.lavoro.fsm;

import android.content.Context;
import android.net.Uri;
import android.os.Bundle;

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
    }
}
