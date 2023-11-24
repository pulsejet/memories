//
//  ViewController.swift
//  memories-ios
//
//  Created by m-oehme on 11.11.23.
//

import UIKit
import WebKit
import AuthenticationServices
import Combine

class MainViewController: UIViewController {
    var mainViewModel: MainViewModelProtocol!
    
    @IBOutlet weak var webView: WKWebView!
    
    var authSession: ASWebAuthenticationSession? = nil
    
    override func loadView() {
        super.loadView()
        mainViewModel.uiDelegate = self
        initializeWebView()
    }
    
    override func viewDidLoad() {
        super.viewDidLoad()
        mainViewModel.viewDidLoad()
    }
    
    override func viewDidAppear(_ animated: Bool) {
        mainViewModel.refreshTimeline()
    }
    
    private func initializeWebView() {
        let nativeXJS = loadJavaScript()
        let script = WKUserScript(source: nativeXJS, injectionTime: .atDocumentStart, forMainFrameOnly: false)
        
        let contentController = webView.configuration.userContentController
        contentController.addScriptMessageHandler(self, contentWorld: .page, name: "nativex")
        contentController.addUserScript(script)
        
        let webConfiguration = webView.configuration
        webConfiguration.setURLSchemeHandler(self, forURLScheme: Schema.URL_SCHEMA)
        
        webView.uiDelegate = self
        webView.customUserAgent = Constants.USER_AGENT
        webView.scrollView.contentInsetAdjustmentBehavior = UIScrollView.ContentInsetAdjustmentBehavior.never
    }
    
    func loadJavaScript() -> String {
        if let filepath = Bundle.main.path(forResource: "NativeX", ofType: "js", inDirectory: "web_asset") {
            do {
                return try String(contentsOfFile: filepath)
            } catch {
                debugPrint("Could not parse Javascript")
                return ""
            }
        } else {
            debugPrint("Could not load Javascript")
            return ""
        }
    }
}

extension MainViewController: MainUiDelegate {
    func loadWebPage(urlRequest: URLRequest) {
        DispatchQueue.main.async {
            self.webView.load(urlRequest)
        }
    }
    
    func loadFilePage(url: URL) {
        DispatchQueue.main.async {
            self.webView.loadFileURL(url, allowingReadAccessTo: url)
        }
    }
    
    
    func openAuthenticationModal(url: String) {
        DispatchQueue.main.async {
            guard let authURL = URL(string: url) else { return }
            let scheme = Schema.AUTH_RESULT
            self.authSession = ASWebAuthenticationSession(url: authURL, callbackURLScheme: scheme)
            { _, _ in }
            
            self.authSession?.presentationContextProvider = self
            self.authSession?.start()
        }
    }
    
    func closeAuthenticationModal() {
        DispatchQueue.main.async {
            self.authSession?.cancel()
            self.authSession = nil
        }
    }
    
    func evaluateJavascript(javascript: String) {
        DispatchQueue.main.async {
            debugPrint("Evaluate Javascript:", javascript)
            self.webView.evaluateJavaScript(javascript)
        }
    }
    
    func applyColorTheme(color: String?) {
        let uiColor: UIColor!
        if color != nil {
            uiColor = UIColor(hex: color!)
        } else {
            uiColor = UIColor.white
        }
        view.backgroundColor = uiColor
        webView.backgroundColor = uiColor
    }
}

extension MainViewController : WKUIDelegate {
    
    func webView(_ webView: WKWebView,
                 runJavaScriptAlertPanelWithMessage message: String,
                 initiatedByFrame frame: WKFrameInfo,
                 completionHandler: @escaping () -> Void) {
        
        // Set the message as the UIAlertController message
        let alert = UIAlertController(
            title: nil,
            message: message,
            preferredStyle: .alert
        )
        
        // Add a confirmation action “OK”
        let okAction = UIAlertAction(
            title: "OK",
            style: .default,
            handler: { _ in
                // Call completionHandler
                completionHandler()
            }
        )
        alert.addAction(okAction)
        
        // Display the NSAlert
        present(alert, animated: true, completion: nil)
    }
}

extension MainViewController: WKScriptMessageHandlerWithReply {
    
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) async -> (Any?, String?) {
        let result = mainViewModel.handleScriptMessage(body: message.body)
        debugPrint("Script Message Result", result ?? "nil")
        return (result, nil)
    }
}

extension MainViewController: WKURLSchemeHandler {
    func webView(_ webView: WKWebView, start urlSchemeTask: WKURLSchemeTask) {
        self.mainViewModel.handleScheme(url: urlSchemeTask.request.url)
    }
    
    func webView(_ webView: WKWebView, stop urlSchemeTask: WKURLSchemeTask) {
    }
}

extension MainViewController: ASWebAuthenticationPresentationContextProviding {
    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        return view.window!
    }
}
