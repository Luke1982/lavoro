package nl.lavoro.fsm;

import android.Manifest;
import android.content.Intent;
import android.os.Build;

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

    private String pending_server_url;

    @PluginMethod
    public void start(PluginCall call) {
        pending_server_url = call.getString("serverUrl");
        if (pending_server_url == null) {
            call.reject("serverUrl is required");
            return;
        }

        if (getPermissionState("location") != PermissionState.GRANTED) {
            requestPermissionForAlias("location", call, "location_callback");
            return;
        }

        request_notifications_then_start(call);
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
        intent.putExtra("serverUrl", pending_server_url);
        ContextCompat.startForegroundService(getContext(), intent);
        call.resolve();
    }

    @PluginMethod
    public void stop(PluginCall call) {
        getContext().stopService(new Intent(getContext(), LocationForegroundService.class));
        call.resolve();
    }
}
