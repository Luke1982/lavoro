import UIKit
import WebKit
import Capacitor

/**
 * The iOS side of LavoroWebViewClient: the offline page only when the server
 * really cannot be reached.
 *
 * Capacitor's own handler would show its error page for every failed page
 * load, including a download WebKit hands off and a navigation cut short by the
 * next one. Those are not an outage. Here it happens only when there is no
 * connection, or when the proxy in front of the application answers that it is
 * down or being maintained.
 *
 * The offline page has no way to know the server's address. It goes to
 * retryPath on its own origin, and this delegate loads the server from there.
 *
 * Everything else stays Capacitor's: what this class does not implement goes to
 * its handler untouched, and what it does implement is handed on as well.
 */
final class LavoroNavigationDelegate: NSObject, WKNavigationDelegate {

    static let offlinePage = "index.html"
    static let retryPath = "/opnieuw-verbinden"

    /** The same failures LavoroWebViewClient.UNREACHABLE_ERRORS lists on Android. */
    private static let unreachableErrors: Set<Int> = [
        NSURLErrorCannotFindHost,
        NSURLErrorDNSLookupFailed,
        NSURLErrorCannotConnectToHost,
        NSURLErrorTimedOut,
        NSURLErrorNetworkConnectionLost,
        NSURLErrorNotConnectedToInternet,
        NSURLErrorInternationalRoamingOff,
        NSURLErrorDataNotAllowed,
        NSURLErrorSecureConnectionFailed,
    ]

    /** LavoroWebViewClient.UNREACHABLE_STATUSES: the proxy says the application is down. */
    private static let unreachableStatuses: Set<Int> = [502, 503, 504]

    private let capacitor: WebViewDelegationHandler
    private let localURL: URL
    private let serverURL: URL

    init(forwardingTo capacitor: WebViewDelegationHandler, localURL: URL, serverURL: URL) {
        self.capacitor = capacitor
        self.localURL = localURL
        self.serverURL = serverURL
        super.init()
    }

    override func responds(to aSelector: Selector!) -> Bool {
        return super.responds(to: aSelector) || capacitor.responds(to: aSelector)
    }

    override func forwardingTarget(for aSelector: Selector!) -> Any? {
        return capacitor.responds(to: aSelector) ? capacitor : nil
    }

    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        if isRetry(navigationAction.request.url) {
            decisionHandler(.cancel)
            let server = serverURL
            DispatchQueue.main.async {
                webView.load(URLRequest(url: server))
            }
            return
        }

        capacitor.webView(webView, decidePolicyFor: navigationAction, decisionHandler: decisionHandler)
    }

    func webView(_ webView: WKWebView, decidePolicyFor navigationResponse: WKNavigationResponse, decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void) {
        guard
            navigationResponse.isForMainFrame,
            let response = navigationResponse.response as? HTTPURLResponse,
            response.url?.host == serverURL.host,
            LavoroNavigationDelegate.unreachableStatuses.contains(response.statusCode)
        else {
            decisionHandler(.allow)
            return
        }

        decisionHandler(.cancel)
        showOfflinePage(in: webView)
    }

    // swiftlint:disable:next implicitly_unwrapped_optional
    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        capacitor.webView(webView, didFailProvisionalNavigation: navigation, withError: error)

        if isUnreachable(error) {
            showOfflinePage(in: webView)
        }
    }

    // swiftlint:disable:next implicitly_unwrapped_optional
    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        capacitor.webView(webView, didFail: navigation, withError: error)

        if isUnreachable(error) {
            showOfflinePage(in: webView)
        }
    }

    private func isRetry(_ url: URL?) -> Bool {
        guard let url = url else {
            return false
        }

        return url.scheme == localURL.scheme
            && url.host == localURL.host
            && url.path == LavoroNavigationDelegate.retryPath
    }

    private func isUnreachable(_ error: Error) -> Bool {
        let error = error as NSError

        return error.domain == NSURLErrorDomain
            && LavoroNavigationDelegate.unreachableErrors.contains(error.code)
    }

    private func showOfflinePage(in webView: WKWebView) {
        let page = localURL.appendingPathComponent(LavoroNavigationDelegate.offlinePage)
        DispatchQueue.main.async {
            webView.load(URLRequest(url: page))
        }
    }
}
