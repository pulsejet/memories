//
//  Container.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//
import Foundation
import Swinject
import SwinjectStoryboard
import UIKit

extension SwinjectStoryboard {
    @objc class func setup() {

        defaultContainer.storyboardInitCompleted(MainViewController.self) { r, viewController in
            viewController.mainViewModel = r.resolve(MainViewModelProtocol.self)
        }
        defaultContainer.register(NativeXRequestHandler.self) { r in
            NativeXRequestHandler(
                getDaysUseCase: r.resolve(GetDaysUseCase.self)!
            )
        }
        defaultContainer.register(MainViewModelProtocol.self) { r in
            MainViewModel(
                authenticationUseCase: r.resolve(AuthenticationUseCase.self)!,
                loadCredentialsUseCase: r.resolve(LoadCredentialsUseCase.self)!,
                buildWebViewRequestUseCase: r.resolve(BuildWebViewRequestUseCase.self)!,
                nativeXMessageHandler: r.resolve(NativeXMessageHandler.self)!,
                buildBusFunctionUseCase: r.resolve(BuildBusFunctionUseCase.self)!,
                themeStorage: r.resolve(ThemeStorage.self)!
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
                buildWebViewRequestUseCase: r.resolve(BuildWebViewRequestUseCase.self)!
            )
        }
        defaultContainer.register(HttpService.self) { _ in
            HttpService()
        }.inObjectScope(.container)
        defaultContainer.register(LoginDataSource.self) { r in
            LoginDataSource(httpService: r.resolve(HttpService.self)!)
        }
        defaultContainer.register(SecureCredentialStorage.self) { _ in
            SecureCredentialStorage()
        }
        defaultContainer.register(SetCredentialsUseCase.self) { r in
            SetCredentialsUseCase(secureStorage: r.resolve(SecureCredentialStorage.self)!, refreshCredentialsUseCase: r.resolve(LoadCredentialsUseCase.self)!)
        }
        defaultContainer.register(LoadCredentialsUseCase.self) { r in
            LoadCredentialsUseCase(httpService: r.resolve(HttpService.self)!, secureStorage: r.resolve(SecureCredentialStorage.self)!)
        }
        defaultContainer.register(BuildWebViewRequestUseCase.self) { r in
            BuildWebViewRequestUseCase(httpService: r.resolve(HttpService.self)!)
        }
        defaultContainer.register(NativeXMessageHandler.self) { r in
            NativeXMessageHandler(
                photoDataSource: r.resolve(PhotoDataSource.self)!,
                getLocalFolders: r.resolve(GetLocalFoldersUseCase.self)!,
                nativeXRequestHandler: r.resolve(NativeXRequestHandler.self)!,
                themeStorage: r.resolve(ThemeStorage.self)!
            )
        }
        defaultContainer.register(DatabaseService.self) { _ in
            let delegate = UIApplication.shared.delegate as! AppDelegate
            return delegate.databaseService
        }.inObjectScope(.container)
        defaultContainer.register(PhotoDataSource.self) { r in
            PhotoDataSource(databaseService: r.resolve(DatabaseService.self)!)
        }
        defaultContainer.register(BucketsDataSource.self) { _ in
            BucketsDataSource()
        }
        defaultContainer.register(GetDaysUseCase.self) { r in
            GetDaysUseCase(
                photoDataSource: r.resolve(PhotoDataSource.self)!,
                bucketsDataSource: r.resolve(BucketsDataSource.self)!
            )
        }
        defaultContainer.register(GetLocalFoldersUseCase.self) { r in
            GetLocalFoldersUseCase(photosDataSource: r.resolve(PhotoDataSource.self)!)
        }
        defaultContainer.register(BuildBusFunctionUseCase.self) { _ in
            BuildBusFunctionUseCase()
        }
        defaultContainer.register(ThemeStorage.self) { _ in
            ThemeStorage()
        }.inObjectScope(.container)
    }
}
