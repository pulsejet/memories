//
//  SetCredentialsUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 14.11.23.
//

import Foundation

class SetCredentialsUseCase {
    let secureStorage: SecureStorage
    let refreshCredentialsUseCase: RefereshCredentialsUseCase
    
    init(secureStorage: SecureStorage, refreshCredentialsUseCase: RefereshCredentialsUseCase) {
        self.secureStorage = secureStorage
        self.refreshCredentialsUseCase = refreshCredentialsUseCase
    }
    
    func invoke(credential: Credential) throws {
        do {
            try secureStorage.saveCredentials(credential: credential)
        } catch StorageError.duplicateKey {
            debugPrint("Duplicate Key. Skipping")
        }
        try refreshCredentialsUseCase.invoke()
    }
}
