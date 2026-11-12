package nl.lavoro.fsm;

import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.PowerManager;
import android.provider.Settings;

import androidx.activity.result.ActivityResult;
import androidx.appcompat.app.AlertDialog;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.ActivityCallback;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * What the web side needs to know about push on this phone, and the one
 * question that makes it arrive on time.
 */
@CapacitorPlugin(name = "PushSupport")
public class PushSupportPlugin extends Plugin {

    /**
     * "Niet nu" holds until the app is started again. The layout asks on every
     * full page load, and asking again on each of those would make the question
     * impossible to get rid of.
     */
    private static boolean declined_this_launch = false;

    /**
     * Firebase needs google-services.json at build time. Without it, registering
     * for push crashes the app natively, out of reach of any JavaScript catch,
     * so the web side asks this first.
     */
    @PluginMethod
    public void available(PluginCall call) {
        JSObject result = new JSObject();
        result.put("available", BuildConfig.PUSH_AVAILABLE);
        call.resolve(result);
    }

    /**
     * Android holds back messages to save battery, and a reminder held back
     * until the phone is picked up again arrives after the appointment began.
     * The app explains that first; only "Verder" leads to Android's own
     * question, which is the only thing that can lift it.
     */
    @PluginMethod
    public void requestBatteryExemption(PluginCall call) {
        if (is_exempt() || declined_this_launch) {
            resolve(call);
            return;
        }

        getActivity().runOnUiThread(() -> new AlertDialog.Builder(getActivity())
            .setTitle(R.string.battery_disclosure_title)
            .setMessage(R.string.battery_disclosure_body)
            .setCancelable(false)
            .setPositiveButton(R.string.battery_disclosure_accept, (dialog, which) -> ask_android(call))
            .setNegativeButton(R.string.battery_disclosure_decline, (dialog, which) -> {
                declined_this_launch = true;
                resolve(call);
            })
            .show());
    }

    /** Some manufacturers leave the screen for this question out altogether. */
    private void ask_android(PluginCall call) {
        Intent intent = new Intent(
            Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
            Uri.parse("package:" + getContext().getPackageName())
        );

        try {
            startActivityForResult(call, intent, "battery_callback");
        } catch (ActivityNotFoundException e) {
            declined_this_launch = true;
            resolve(call);
        }
    }

    @ActivityCallback
    private void battery_callback(PluginCall call, ActivityResult result) {
        declined_this_launch = !is_exempt();
        resolve(call);
    }

    private boolean is_exempt() {
        PowerManager power = (PowerManager) getContext().getSystemService(Context.POWER_SERVICE);

        return power != null && power.isIgnoringBatteryOptimizations(getContext().getPackageName());
    }

    private void resolve(PluginCall call) {
        JSObject result = new JSObject();
        result.put("exempt", is_exempt());
        call.resolve(result);
    }
}
