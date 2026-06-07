package nl.lavoro.fsm;

import android.content.Context;
import android.content.Intent;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

@CapacitorPlugin(name = "AppConfig")
public class AppConfigPlugin extends Plugin {

    public static final String PREFS = "lavoro_app_config";
    public static final String URL_KEY = "server_url";

    @PluginMethod
    public void getServerUrl(PluginCall call) {
        String url = getContext()
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(URL_KEY, null);
        JSObject ret = new JSObject();
        ret.put("url", url);
        call.resolve(ret);
    }

    @PluginMethod
    public void setServerUrl(PluginCall call) {
        String url = call.getString("url");
        if (url == null) {
            call.reject("url is required");
            return;
        }
        // commit() (synchronous) — apply() can lose the write because restart()
        // kills the process before the async flush reaches disk.
        getContext()
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(URL_KEY, url)
            .commit();
        call.resolve();
        restart();
    }

    @PluginMethod
    public void clearServerUrl(PluginCall call) {
        getContext()
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .remove(URL_KEY)
            .commit();
        getContext().stopService(new Intent(getContext(), LocationForegroundService.class));
        call.resolve();
        restart();
    }

    private void restart() {
        Context ctx = getContext();
        Intent intent = ctx.getPackageManager().getLaunchIntentForPackage(ctx.getPackageName());
        if (intent != null) {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
            ctx.startActivity(intent);
        }
        Runtime.getRuntime().exit(0);
    }
}
