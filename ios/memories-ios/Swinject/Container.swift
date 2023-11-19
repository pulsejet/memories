//
//  Container.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//
import Foundation
import SwinjectStoryboard

extension SwinjectStoryboard {
    @objc class func setup() {
        defaultContainer.storyboardInitCompleted(ViewController.self) { r, viewController in
            viewController.nativeX = r.resolve(NativeX.self)
            viewController.mainViewModel = r.resolve(MainViewModelProtocol.self)
        }
        defaultContainer.register(NativeX.self) { _ in NativeX() }
        defaultContainer.register(MainViewModelProtocol.self) { r in
            MainViewModel(
                authenticationUseCase: r.resolve(AuthenticationUseCase.self)!,
                loadCredentialsUseCase: r.resolve(LoadCredentialsUseCase.self)!,
                getWebViewRequestUseCase: r.resolve(GetWebViewRequestUseCase.self)!,
                nativeXMessageHandler: r.resolve(NativeXMessageHandler.self)!
            )
        }
        defaultContainer.register(ApiDescriptionDataSource.self) { r in
            ApiDescriptionDataSource(httpService: r.resolve(HttpService.self)!)
        }
        defaultContainer.register(AuthenticationUseCase.self) { r in
            AuthenticationUseCase(
                getApiDescriptionUseCase: r.resolve(ApiDescriptionDataSource.self)!,
                loginDataSource: r.resolve(LoginDataSource.self)!,
                setCredentialsUseCase: r.resolve(SetCredentialsUseCase.self)!,
                getWebViewRequestUseCase: r.resolve(GetWebViewRequestUseCase.self)!
            )
        }
        defaultContainer.register(HttpService.self) { _ in
            HttpService()
        }.inObjectScope(.container)
        defaultContainer.register(LoginDataSource.self) { r in
            LoginDataSource(httpService: r.resolve(HttpService.self)!)
        }
        defaultContainer.register(SecureStorage.self) { _ in
            SecureStorage()
        }
        defaultContainer.register(SetCredentialsUseCase.self) { r in
            SetCredentialsUseCase(secureStorage: r.resolve(SecureStorage.self)!, refreshCredentialsUseCase: r.resolve(LoadCredentialsUseCase.self)!)
        }
        defaultContainer.register(LoadCredentialsUseCase.self) { r in
            LoadCredentialsUseCase(httpService: r.resolve(HttpService.self)!, secureStorage: r.resolve(SecureStorage.self)!)
        }
        defaultContainer.register(GetWebViewRequestUseCase.self) { r in
            GetWebViewRequestUseCase(httpService: r.resolve(HttpService.self)!)
        }
        defaultContainer.register(NativeXMessageHandler.self) { _ in
            NativeXMessageHandler()
        }
    }
}
