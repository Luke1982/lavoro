import UIKit
import WebKit
import Capacitor

/**
 * The iOS side of MainActivity.
 *
 * Every customer works on the same server and the login decides which company
 * they are in, so the server is fixed when the app is built (LavoroServerURL in
 * Info.plist) and nobody types an address.
 *
 * No errorPath is set: Capacitor would show the offline page for every failed
 * page load. LavoroNavigationDelegate takes that decision instead, the way
 * LavoroWebViewClient does on Android.
 */
class LavoroViewController: CAPBridgeViewController {

    /** Android's SPLASH_BACKGROUND, so the web view starts in the same colour. */
    private static let background = UIColor(red: 8 / 255, green: 16 / 255, blue: 32 / 255, alpha: 1)

    /** The web view holds its navigation delegate weakly; this keeps it alive. */
    private var navigation: LavoroNavigationDelegate?

    override func instanceDescriptor() -> InstanceDescriptor {
        let descriptor = super.instanceDescriptor()
        descriptor.serverURL = Bundle.main.object(forInfoDictionaryKey: "LavoroServerURL") as? String
        descriptor.backgroundColor = LavoroViewController.background
        return descriptor
    }

    override func capacitorDidLoad() {
        guard
            let webView = webView,
            let capacitor = webView.navigationDelegate as? WebViewDelegationHandler,
            let config = bridge?.config
        else {
            return
        }

        let navigation = LavoroNavigationDelegate(
            forwardingTo: capacitor,
            localURL: config.localURL,
            serverURL: config.serverURL
        )
        self.navigation = navigation
        webView.navigationDelegate = navigation
    }
}
