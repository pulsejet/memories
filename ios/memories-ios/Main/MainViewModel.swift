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
}

protocol MainUiDelegate: AnyObject {
    
    func openAuthenticationModal(url: String)
    
    func loadWebPage(urlRequest: URLRequest)
    
    func loadFilePage(url: URL)
    
    func closeAuthenticationModal()
}

class MainViewModel: MainViewModelProtocol {
    weak var uiDelegate: MainUiDelegate?
    
    let authenticationUseCase: AuthenticationUseCase
    let loadCredentialsUseCase: LoadCredentialsUseCase
    let getWebViewRequestUseCase: GetWebViewRequestUseCase
    
    init(authenticationUseCase: AuthenticationUseCase, loadCredentialsUseCase: LoadCredentialsUseCase, getWebViewRequestUseCase: GetWebViewRequestUseCase) {
        self.authenticationUseCase = authenticationUseCase
        self.loadCredentialsUseCase = loadCredentialsUseCase
        self.getWebViewRequestUseCase = getWebViewRequestUseCase
    }
    
    func viewDidLoad() {
        Task {
            do {
                try loadCredentialsUseCase.invoke()
                let url = try getWebViewRequestUseCase.build()
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
}
