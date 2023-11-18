//
//  AuthenticationUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 12.11.23.
//

import Foundation

class AuthenticationUseCase {
    
    let getApiDescriptionUseCase: ApiDescriptionDataSource
    let loginDataSource: LoginDataSource
    let setCredentialsUseCase: SetCredentialsUseCase
    let getWebViewRequestUseCase: GetWebViewRequestUseCase
    
    
    init(getApiDescriptionUseCase: ApiDescriptionDataSource, loginDataSource: LoginDataSource, setCredentialsUseCase: SetCredentialsUseCase, getWebViewRequestUseCase: GetWebViewRequestUseCase) {
        self.getApiDescriptionUseCase = getApiDescriptionUseCase
        self.loginDataSource = loginDataSource
        self.setCredentialsUseCase = setCredentialsUseCase
        self.getWebViewRequestUseCase = getWebViewRequestUseCase
    }
    
    func login(
        url: String,
        trustAll: Bool,
        startLogin: @escaping (String) -> Void,
        waitLogin: @escaping () -> Void
    ) async throws -> URLRequest? {
        let apiDescription = try await getApiDescriptionUseCase.invoke(baseUrl: url)
        let loginDescription = try await loginDataSource.login(loginFlowUrl: apiDescription.loginFlowUrl)
        
        startLogin(loginDescription.login)
        
        waitLogin()
        guard let loginResult = try await loginDataSource.polling(poll: loginDescription.poll) else {
            throw AuthError.pollingFailed
        }
        
        debugPrint("PollingResult: ", loginResult)
        
        do {
            
            try setCredentialsUseCase.invoke(credential: Credential(
                url: apiDescription.baseUrl,
                trustAll: trustAll,
                username: loginResult.loginName,
                token: loginResult.appPassword
            ))
            
        } catch (let error) {
            debugPrint("Error: ", error)
        }
        debugPrint("Credentials Saved")
        
        return getWebViewRequestUseCase.build(subpath: "nxsetup")
    }
}

enum AuthError : Error {
    case pollingFailed
}
