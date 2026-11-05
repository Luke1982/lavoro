package nl.lavoro.fsm;

import android.net.Uri;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;

import java.util.Arrays;
import java.util.List;

/**
 * Shows the offline page only when the server really cannot be reached.
 *
 * Capacitor's own client swaps in the error page for every failed page load,
 * a 404 or a 500 from Laravel included. Those pages explain themselves, so
 * here it happens only when there is no connection, or when the proxy in
 * front of the application answers that it is down or being maintained.
 *
 * Capacitor gives that page no bridge, so it cannot know the server's address.
 * It goes to RETRY_PATH instead, and this client loads the server from there.
 */
public class LavoroWebViewClient extends BridgeWebViewClient {

    public static final String OFFLINE_PAGE = "index.html";
    public static final String RETRY_PATH = "/opnieuw-verbinden";

    private static final List<Integer> UNREACHABLE_ERRORS = Arrays.asList(
        WebViewClient.ERROR_HOST_LOOKUP,
        WebViewClient.ERROR_CONNECT,
        WebViewClient.ERROR_TIMEOUT,
        WebViewClient.ERROR_IO,
        WebViewClient.ERROR_FAILED_SSL_HANDSHAKE
    );

    private static final List<Integer> UNREACHABLE_STATUSES = Arrays.asList(502, 503, 504);

    private final Bridge bridge;

    public LavoroWebViewClient(Bridge bridge) {
        super(bridge);
        this.bridge = bridge;
    }

    @Override
    public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
        Uri url = request.getUrl();

        if (bridge.getHost().equals(url.getHost()) && RETRY_PATH.equals(url.getPath())) {
            view.loadUrl(bridge.getAppUrl());
            return true;
        }

        return super.shouldOverrideUrlLoading(view, request);
    }

    @Override
    public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        if (UNREACHABLE_ERRORS.contains(error.getErrorCode())) {
            super.onReceivedError(view, request, error);
        }
    }

    @Override
    public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
        if (UNREACHABLE_STATUSES.contains(response.getStatusCode())) {
            super.onReceivedHttpError(view, request, response);
        }
    }
}
