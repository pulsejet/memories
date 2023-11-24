//
//  MainViewModel.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//

import Foundation

protocol MainViewModelProtocol: AnyObject {
    var uiDelegate: MainUiDelegate? { get set }
    
    func viewDidLoad()
    func handleScheme(url: URL?)
    func handleScriptMessage(body: Any) async -> Any?
    func refreshTimeline()
}

protocol MainUiDelegate: AnyObject {
    
    func openAuthenticationModal(url: String)
    
    func loadWebPage(urlRequest: URLRequest)
    
    func loadFilePage(url: URL)
    
    func closeAuthenticationModal()
    
    func evaluateJavascript(javascript: String)
    
    func applyColorTheme(color: String?)
    
    func playTouchSound()
    
    func toast(message: String)
}

class MainViewModel: MainViewModelProtocol {
    weak var uiDelegate: MainUiDelegate? = nil
    
    let authenticationUseCase: AuthenticationUseCase
    let loadCredentialsUseCase: LoadCredentialsUseCase
    let buildWebViewRequestUseCase: BuildWebViewRequestUseCase
    let nativeXMessageHandler: NativeXMessageHandler
    let buildBusFunctionUseCase: BuildBusFunctionUseCase
    let themeStorage: ThemeStorage
    
    init(authenticationUseCase: AuthenticationUseCase, loadCredentialsUseCase: LoadCredentialsUseCase, buildWebViewRequestUseCase: BuildWebViewRequestUseCase, nativeXMessageHandler: NativeXMessageHandler, buildBusFunctionUseCase: BuildBusFunctionUseCase, themeStorage: ThemeStorage) {
        self.authenticationUseCase = authenticationUseCase
        self.loadCredentialsUseCase = loadCredentialsUseCase
        self.buildWebViewRequestUseCase = buildWebViewRequestUseCase
        self.nativeXMessageHandler = nativeXMessageHandler
        self.buildBusFunctionUseCase = buildBusFunctionUseCase
        self.themeStorage = themeStorage
    }
    
    func viewDidLoad() {
        themeStorage.observe { theme in
            self.uiDelegate?.applyColorTheme(color: theme)
        }
        Task {
            do {
                try loadCredentialsUseCase.invoke()
                let url = try buildWebViewRequestUseCase.build()
                self.uiDelegate?.loadWebPage(urlRequest: url)
            } catch(let error) {
                debugPrint("Default Url Error: ", error)
                self.uiDelegate?.loadFilePage(url: self.createWelcomePageUrl())
            }
        }
    }
    
    func handleScheme(url: URL?) {
        Task {
            print("Scheme:" + (url?.absoluteString ?? ""))
            guard let path = url?.path else { return }
            guard let baseUrl = path.split(separator: "/")[1].removingPercentEncoding else {
                return
            }
            
            guard let trustAll = url?.valueOf("trustAll") else { return }
            
            guard let successLoginPath = try await authenticationUseCase.login(
                url: baseUrl,
                trustAll: Bool(trustAll) ?? false,
                startLogin: { url in
                    self.uiDelegate?.openAuthenticationModal(url: url)
                },
                waitLogin: {
                    self.uiDelegate?.loadFilePage(url: self.createWaitPageUrl())
                }
            ) else { return }
            
            debugPrint("Success Login: ", successLoginPath)
            self.uiDelegate?.closeAuthenticationModal()
            self.uiDelegate?.loadWebPage(urlRequest: successLoginPath)
        }
    }
    
    private func createWelcomePageUrl() -> URL {
        return Bundle.main.url(forResource: "welcome", withExtension: "html", subdirectory: "web_asset")!
    }
    
    private func createWaitPageUrl() -> URL {
        return Bundle.main.url(forResource: "waiting", withExtension: "html", subdirectory: "web_asset")!
    }
    
    func handleScriptMessage(body: Any) async -> Any? {
        debugPrint("NativeX Script", body)
        let result =  await nativeXMessageHandler.handleMessage(body: body)
        
        switch result {
        case .returnResult(let result): return result
        case .playTouchSound: self.uiDelegate?.playTouchSound()
        case .toast(let toast): self.uiDelegate?.toast(message: toast.message)
        }
        
        return nil
    }
    
    func refreshTimeline() {
        self.uiDelegate?.evaluateJavascript(
            javascript: buildBusFunctionUseCase.invoke(event: "nativex:db:updated")
        )
        self.uiDelegate?.evaluateJavascript(
            javascript: buildBusFunctionUseCase.invoke(event: "memories:timeline:soft-refresh")
        )
    }
}
