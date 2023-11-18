//
//  RefreshCredentialsUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 14.11.23.
//

import Foundation


class RefereshCredentialsUseCase {
    
    let httpService: HttpService
    let secureStorage: SecureStorage
    
    init(httpService: HttpService, secureStorage: SecureStorage) {
        self.httpService = httpService
        self.secureStorage = secureStorage
    }
    
    func invoke() throws {
        let credential = try secureStorage.getCredentials()
        httpService.build(url: credential.url, trustAll: credential.trustAll)
        httpService.setAuthHeader(username: credential.username, token: credential.token)
    }
}
