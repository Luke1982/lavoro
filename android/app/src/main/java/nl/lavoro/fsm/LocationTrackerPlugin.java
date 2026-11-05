package nl.lavoro.fsm;

import android.Manifest;
import android.content.Intent;
import android.os.Build;

import androidx.appcompat.app.AlertDialog;
import androidx.core.content.ContextCompat;

import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

@CapacitorPlugin(
    name = "LocationTracker",
    permissions = {
        @Permission(
            alias = "location",
            strings = { Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION }
        ),
        @Permission(
            alias = "notifications",
            strings = { Manifest.permission.POST_NOTIFICATIONS }
        )
    }
)
public class LocationTrackerPlugin extends Plugin {

    /**
     * "Niet nu" holds until the app is started again. The layout calls start()
     * on every full page load, and asking again on each of those would make the
     * question impossible to get rid of.
     */
    private static boolean declined_this_launch = false;

    private TrackingWindow window = TrackingWindow.ALWAYS;

    @PluginMethod
    public void start(PluginCall call) {
        window = TrackingWindow.from(call.getObject("window"));

        if (getPermissionState("location") == PermissionState.GRANTED) {
            request_notifications_then_start(call);
            return;
        }

        if (declined_this_launch) {
            call.reject("Location disclosure declined");
            return;
        }

        getActivity().runOnUiThread(() -> show_disclosure(call));
    }

    /**
     * Google Play wants the reason in the app itself, before Android asks for
     * the permission: what is collected, what for, that it goes on while the
     * app is closed, and who sees it. Only an explicit "Toestaan" leads to the
     * system question; anything else counts as no.
     */
    private void show_disclosure(PluginCall call) {
        new AlertDialog.Builder(getActivity())
            .setTitle(R.string.location_disclosure_title)
            .setMessage(getContext().getString(R.string.location_disclosure_body, window.describe()))
            .setCancelable(false)
            .setPositiveButton(R.string.location_disclosure_accept, (dialog, which) ->
                requestPermissionForAlias("location", call, "location_callback")
            )
            .setNegativeButton(R.string.location_disclosure_decline, (dialog, which) -> {
                declined_this_launch = true;
                call.reject("Location disclosure declined");
            })
            .show();
    }

    @PermissionCallback
    private void location_callback(PluginCall call) {
        if (getPermissionState("location") != PermissionState.GRANTED) {
            call.reject("Location permission denied");
            return;
        }
        request_notifications_then_start(call);
    }

    private void request_notifications_then_start(PluginCall call) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
            && getPermissionState("notifications") != PermissionState.GRANTED) {
            requestPermissionForAlias("notifications", call, "notifications_callback");
            return;
        }
        start_service(call);
    }

    @PermissionCallback
    private void notifications_callback(PluginCall call) {
        start_service(call);
    }

    private void start_service(PluginCall call) {
        Intent intent = new Intent(getContext(), LocationForegroundService.class);
        window.putInto(intent);
        ContextCompat.startForegroundService(getContext(), intent);
        call.resolve();
    }

    @PluginMethod
    public void stop(PluginCall call) {
        getContext().stopService(new Intent(getContext(), LocationForegroundService.class));
        call.resolve();
    }
}
